# Brezel deep dive — research for the Hermes Control Deck rebuild

Research date: 2026-11-18. Research-only document: no application source was changed and no
secrets were read, printed, or stored. All source URLs are public.

## 0. Executive summary

- There are **two different things called Brezel**: (a) the 2026 commercial product at
  `brezel.io` / `console.brezel.cloud` — an AI-assistant workspace with people, roles,
  approvals and company knowledge, and (b) the older open-source ERP/framework whose docs
  live at `wiki.brezel.io` and whose code lives on `gitlab.kiwis-and-brownies.de` with a
  small public mirror on GitHub (`github.com/brezelio`). The two share lineage and
  vocabulary, but the marketing site is the visual reference for our rebuild.
- Brezel does **not** appear to have a design system or framework called "Lava". Its actual
  stack is **Laravel (PHP) + Vue 3 + ant-design-vue + SCSS**, with a JSON "Bakery" plan
  that declares modules, fields, workflows and layout (`tabs → rows → cols → components`)
  and a `recipes` expression language for conditional UI. The likely sources of the user's
  "using lava" phrase are `lava.so` — a *different* AI-agent product with an excellent
  approval-card UX — or a mishearing of **Laravel**. Both are documented below with sources.
- The strongest transferable patterns are: one shared workspace for people + agents,
  explicit role/permission cards, approval as a distinct state with a plain-language
  proposal, a right-side agent panel with progress and "show in app" links, calm
  off-white/navy/orange palette, big rounded cards, and declarative UI config the AI can
  edit safely.

## 1. Two Brezels — do not conflate them

| | Product Brezel (2026) | Open-source / docs Brezel |
|---|---|---|
| Site | `https://brezel.io/en/`, `https://console.brezel.cloud/en/` | `https://wiki.brezel.io/`, `https://gitlab.kiwis-and-brownies.de/kibro/brezel` |
| What it is | Cloud/on-prem AI assistants for businesses; €499/month per company, not per seat | ERP/business-app framework ("Bakery", modules, recipes, Layout SPA) |
| UI | Vue 3 SPA, ant-design-vue components, own brand theme | Same SPA lineage; docs describe `LayoutSpec`, `field_group`, antdv icons |
| Relevance to us | Visual + interaction reference | Architecture/config-schema reference |

The marketing site says "Brezel On Premise … local AI is planned once capability and
suitability allow. It is not available yet" — useful caution: do not present planned
features as real in our clone.

## 2. Public GitHub repositories (exact URLs)

From `https://api.github.com/orgs/brezelio/repos` (2026-11-18) and the docs:

All 12 public repos from `https://api.github.com/orgs/brezelio/repos` (2026-11-18):

| Repo | URL | Contains |
|---|---|---|
| `brezel` | https://github.com/brezelio/brezel | **Instance skeleton/template**, not the framework: `systems/example`, `src/App.vue`, `src/main.js`, `public/globalstyle.css` (empty `/* Styles go here */`), `bin/`, `docker/`, `terraform/`, `gitlab/`, large `AGENTS.md`. Clone commit `5fc9794`. |
| `brezel-js-client` | https://github.com/brezelio/brezel-js-client | TypeScript SDK `@brezel/api-client` v2.1.1 (MIT): `fetchModules`, `fetchEntities`, `fetchEntity`, `create/update/saveEntity`, filtered fetch, `fetchFile`; auth via API key/OAuth token + `system` + `uri`. Commit `99805c9`. |
| `api-client-php` | https://github.com/brezelio/api-client-php | PHP 8.2 + Guzzle SDK `brezel/api-client` v1.2.1 (MIT): `Client.php`, `Entity.php`, `ApiException.php`, PSR-4 `Brezel\Client\`. Commit `b4dc533`. |
| `recipe-parser-php` | https://github.com/brezelio/recipe-parser-php | ANTLR-generated parser for recipe expressions (`RecipeLexer`, `RecipeParser`, `RecipeVisitor`, `RecipeListener`). Commit `6632c04`. |
| `installer` | https://github.com/brezelio/installer | Installation tool (JS). |
| `charts` | https://github.com/brezelio/charts | Helm charts for deployment (Go template). |
| `antlr`, `antlr-php-runtime` | https://github.com/brezelio/antlr, https://github.com/brezelio/antlr-php-runtime | ANTLR tooling + forked PHP runtime (BSD-3-Clause). |
| `hyn-multi-tenant` | https://github.com/brezelio/hyn-multi-tenant | Fork of `hyn/multi-tenant` — Laravel multi-domain tenancy (MIT). |
| `semantic-release` | https://github.com/brezelio/semantic-release | Fork enforcing Conventional Commits/releases. |
| `tui.image-editor` | https://github.com/brezelio/tui.image-editor | Fork of TOAST UI canvas image editor, published as `@brezel/tui-image-editor`. |
| `token-list` | https://github.com/brezelio/token-list | Unrelated Solana token registry fork. |

Published npm packages: `@brezel/api-client`, `@brezel/installer`,
`@brezel/tui-image-editor`, `@brezel/vue-inbrowser-compiler`, `@brezel/semantic-release`.
**`@kibro/brezel-spa` and `@kibro/brezel-spa-types` are private (npm 404)** — served from
the vendor's GitLab package registry. The skeleton's `composer.json` requires
`brezel/api 3.55.10`; `package.json` requires `@kibro/brezel-spa 5.33.3`.
Canonical source: https://gitlab.kiwis-and-brownies.de/kibro/brezel (subprojects include
`services/export/export-core`, `brotcast-server`).

**Conclusion:** GitHub hosts only the skeleton, clients and tools. The framework itself is
private; public docs (`https://docs.brezel.io/`, older mirror `https://wiki.brezel.io/`)
are the best available architecture reference.

## 3. Brezel stack, structure and naming (from public docs + console assets)

- **Backend**: Laravel (Composer), multi-tenant via a `tenancy` database (`brezel_meta`);
  CLI is `php bakery ...` (`init`, `system create`, `apply`, `load`); `mise run
  install|init|update|serve` wrappers. `.bake.json` files under `systems/<system>/`
  declare resources; `APP_ENV`, `VITE_APP_API_URL`, `VITE_APP_SYSTEM` in `.env`
  (note in docs: `VITE_*` is baked into client JS — never put sensitive values there).
- **Architecture** (https://docs.brezel.io/concepts/architecture/): `brezel/api` =
  Laravel core API; `brezel/spa` = Vue.js frontend; `brezel/export` = Laravel + Pandoc
  (PDF/format conversion); `brezel/recipes` = JS expression engine included in the SPA;
  `brezel/brotcast` = Laravel Echo / Pusher.js websocket client, with a Laravel
  `brotcast-server`; `brezel/bakery` = Vue.js system-configuration tool.
- **Frontend**: Vue 3 + TypeScript + `<script setup>` + Vite + Vuex + vue-router +
  vue-i18n + **Ant Design Vue** components/icons (`https://antdv.com/components/icon/`)
  + **Iconify** (`mdi:account-group`, `mdi:cog-outline`, …) + SCSS/LESS in components.
  The skeleton imports `@kibro/brezel-spa/dist-lib/style.css` — compiled private styles,
  **no public design-token file, palette CSS variables or theme config**. `tinycolor2`
  and per-client `color` fields suggest dynamic tenant colours internally. The live
  console HTML loads `/config.js` with
  `window.brezelConfig = {"apiUrl": "https://console-api.brezel.cloud", "system": "console"}`
  and hashed chunks (`index-aJKdH1YS.js`, `index-D8Wq2ipH.css`), i.e. a compiled SPA with
  no CDN. Animation: `loading-animation="pulse"` on `<BrezelApp>`; no other animation
  library evidenced in core. Tailwind appears only in a third-party consumer
  (`@kabtools/kabtools` uses Tailwind 3), **not proven in core Brezel**.
- **Declarative layout**: `tabs → rows → cols (span 1..24) → components`, with col
  options `borderless`, `vertical_labels`, `no_labels`, `no_padding`; top bar and
  "affix" (entity header with standard buttons) are layouts too. `hidden_from_frontend`
  can be a `recipes` expression, e.g.
  `"(Mahnungsstufe == 'final') ? false : true"`. Exact component-type enum from the
  public API docs: `field_group`, `buttons`, `resource_table`, `layout`, `collapse`,
  `widget`, `headline` (docs also show `text`/`calendar`). Exact widget-type union:
  `custom`, `donut`, `history`, `kanban`, `permissions`, `scan`, `bar`, `column`, `line`,
  `progress`, `radar`, `rule_builder`, `field_path_builder`.
- **Buttons**: styles `primary|dashed|danger|link|-`; standard keys `save`, `update`,
  `continue`, `delete`, `edit`, `create`, `excel`, `plans`; custom buttons trigger
  webhook identifiers; `forceTitle` controls icon-only vs label.
- **Fields**: `checkbox`, `choice`, `code`, `color`, `currency`, `date`, `dateRange`,
  `file`, `layout`, `link`, `list`, `multiselect`, `number`, `range`, `rating`, `select`,
  `signature`, `text`, `textarea`, `upload`, `wysiwyg`, … with options such as `rules`,
  `recipe`, `recipes`, `show_in_resource_table`, `enable_search`, `copyButton`,
  `validationState`.
- **Naming convention (wiki style guide)**: `snake_case` for file names, identifiers,
  fields and choice values; `kebab-case` for branches with `feat/`, `fix/` prefixes;
  Conventional Commits (`feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`,
  `chore`) with optional scope, e.g. `feat(invoices): show total amount for invoice`.
  PHP roughly PSR-12, camelCase variables, single quotes, 4 spaces; JS/TS Vue follows
  JavaScript Standard Style (no semicolons, single quotes, 2 spaces); JSON 4 spaces,
  no trailing commas.

## 4. The "Lava" question (with sources)

**Finding: no evidence that Brezel uses a framework or design system named Lava.**
Hard negative evidence (research note `/tmp/opencode/research/github-lava-findings.md`):

- `grep -ri lava` across 4 cloned repos (files, `composer.json`, `package.json`, git
  history) → **0 matches**; no file/folder named `*lava*`; no git commit mentioning lava.
- A crawl of **166 reachable pages** of `https://docs.brezel.io/` → "lava" 0 pages,
  "lavarel" 0, **"laravel" 15**, "livewire" 0.
- GitHub repo search `lava brezel` / `lava kibro` → 0 results; npm registry search → no
  Kibro/Lava package; multiple web searches → no Brezel/Lava connection.
- brezel.io HTML/CSS and the console JS bundle also contain no "lava".

What exists:

1. **Laravel** — Brezel's PHP backend (`brezel/api 3.55.10` in the skeleton
   `composer.json`, private Composer registry on GitLab). Docs instruct
   `composer install`, `php bakery init`; `brezelio/hyn-multi-tenant` is a Laravel tenant
   package; field rules use Laravel validation strings. If the user heard "lava" in a
   Brezel context, this is the most likely referent. Livewire is ruled out (0 mentions;
   the SPA is Vue 3 talking to a JSON API).
2. **lava.so — a separate product** (https://lava.so/index, https://lava.so/docs/introduction.md).
   It is an AI-agent workspace/gateway: "one integration for 285+ AI providers",
   model/provider routing, spend keys with limits, and Lava Desktop — live app tabs on
   the left, an agent panel on the right. It is **not** Brezel's design system, but it is
   an excellent interaction reference and, if the user literally wants "using lava", it
   could mean adopting its gateway or copying its approval UX.
3. Other unrelated "Lava" hits (NASA CFD framework, LAVA robotics research, Lava
   UI kits) are noise.

Lava's documented interaction patterns (source: https://lava.so/docs/desktop/working-with-agent.md
and https://lava.so/docs/desktop/how-it-works.md), all directly applicable to our panel:

- Right-side agent panel; `Cmd+\` / `Ctrl+\` toggles it. Empty state: "Ask your agent
  anything", suggestion buttons, history link. Composer placeholder: "Ask the agent.
  Enter to send. Type '/' for commands and Routines".
- Progress steps as the agent works; each app action carries a **"Show in [app]"** link
  that jumps to the relevant app tab; the active sidebar item updates automatically.
- **Approve action card** for high-stakes actions (send, delete, payment, external
  share). Anatomy: **orange border**, plain-language summary of exactly what will
  happen, **Approve** (orange button), **Decline** (stone button), and a third option
  "Approve and stop asking for this run".
- Approval modes in the composer footer, effective immediately even mid-run:
  **Normal** (pause only on high-stakes), **Careful** (pause before every write),
  **Auto** (no pauses). This maps almost 1:1 onto our owner-approval TTL model.
- If a reference is ambiguous ("two contacts named Alex"), the agent asks instead of
  guessing. Stop button ends the run; hiding the panel does not.
- `/` opens saved **Routines** (reusable steps, schedulable/event-triggered).
- Lava Gateway (`https://lava.so/docs/gateway/how-lava-gateway-works.md`) offers
  metered AI requests, spent-key restrictions and multi-provider fallback — relevant
  only if the user wants an external model gateway; our local OpenCode API remains the
  model source and no new external dependency is implied.

## 5. Colors measured from the live marketing site (cross-check)

`https://brezel.io/assets/site.css` (27 KB, downloaded 2026-11-18) uses
`font-family: Inter, Arial, Helvetica, sans-serif`. Dominant literal hex values:

- `#ffffff` (white cards), `#111319` (near-black ink), `#292c32` / `#292b30` (dark UI)
- `#ffda00` (signature yellow), `#ffe74f`, `#fff3a5`, `#f2dd79` (yellow tints)
- `#db8700` (deep amber/orange), `#fafbf8`, `#f6f6f2`, `#f5f5ed`, `#f4f4f0`, `#f3f3ec`,
  `#eeefe8`, `#e9ece3` (warm off-white surface ramp)
- `#b5b6ba`, `#d9dadd` (muted borders/text)

Production console CSS (`https://console.brezel.cloud/assets/index-D8Wq2ipH.css`,
558 KB) additionally contains `#172b40` / `#19324d` (navy), `#7a8ca0` / `#4a5f75`
(muted blue-gray), `#ec8621` / `#ef9300` (orange brand accents), plus Ant Design's
default `#1677ff`/`#1890ff` and neutrals `#fafafa`, `#e6ebf1`, `#d9d9d9`.

Marketing CSS layout primitives (frequency in `site.css`): `max-width: 850px` /
`540px` content columns; grids `1fr 1fr`, `repeat(3,1fr)`, `repeat(4,1fr)`; gaps mostly
8/12/16/20/24 px between sibling controls and 25/30/35/45/60/90 px between page
sections; vertical section padding 65/85/95 px; card padding 18/22/24 px.
Marketing CSS literal type/radius scale (frequency in `site.css`): 10/11/12/13/14/15/16 px
for UI text; 17/18/20/21/23/25/26/27 px for subheads; 30/35/36/40/43/44 px for section
heads; 76 px for the largest display number; heading letter-spacing down to `-2px` /
`-6px`; radii mostly 6/7/10 px for small controls and 12/14/16/18/20 px for cards/panels,
50% for avatars/circles.

Interpretation: marketing palette = warm off-white + near-black + yellow; product
palette = navy + orange + Ant Design neutral/blue. Our current panel already uses a
cool-gray + navy + orange variant (`src/public/app.css`), so the change needed is mainly
warmth/contrast and systematic tokens, not a wholesale rebrand.

### 5.1 Product workspace layout spec (measured where possible, labelled)

Every dimension below is **MEASURED** from the public console stylesheet
`https://console.brezel.cloud/assets/index-D8Wq2ipH.css` (558 KB) or from pixel sampling
of the only public product screenshot (`/tmp/opencode/research/product-workspace-de.png`,
1813 × 913). Items marked **INFERENCE** come from screenshot crop boundaries or a local
vision model and are high-confidence but not pixel-verified. Full detail in
`/tmp/opencode/research/ui-layout-findings.md`.

**Product tokens (verbatim CSS, MEASURED):**

```css
.bz-dash {                         /* dashboard / native surfaces */
  --dash-ink:#172b40; --dash-ink-2:#4a5f75; --dash-muted:#7a8ca0;
  --dash-line:#e6ebf1; --dash-surface:#fff; --dash-subtle:#f5f7fa;
  --dash-accent:var(--theme-primary-color, #ec8621);
  --dash-accent-soft: color-mix(in srgb, var(--dash-accent) 12%, #fff);
  --dash-good:#1a9670; --dash-good-soft:#e5f4ee;
  --dash-bad:#c4413a;  --dash-bad-soft:#fbeceb;
  --dash-info:#3f78d8; --dash-info-soft:#e9f0fc;
  --dash-plum:#a8508a; --dash-plum-soft:#f6eaf2;
  --dash-radius:16px;
  --dash-shadow: 0 1px 2px #172b400a, 0 8px 24px #172b400f;
  font-feature-settings:"tnum" 1;
}
.chat-workspace {
  --chat-width:520px;
  --chat-preview-width:min(940px, calc(100vw - var(--chat-width) - 28px));
  --chat-ink:#172b40; --chat-ink-2:#4a5f75; --chat-muted:#7a8ca0;
  --chat-line:#e6ebf1; --chat-accent:var(--theme-primary-color, #ec8621);
  --chat-accent-soft: color-mix(in srgb, var(--chat-accent) 11%, #fff);
  --chat-surface-muted:#f6f7f9;
  --chat-setup-width:min(680px, 50vw);
}
/* login / shell: --bc-ink:#172b40; --bc-line:#e6ebf1; --bc-accent:var(--theme-primary-color,#ec8621);
   --bc-accent-gradient:linear-gradient(120deg,#e8541b,var(--bc-accent));
   --bc-radius-card:20px; --bc-radius-control:12px; --bc-control-height:48px;
   --bc-shadow-card:0 1px 2px #172b400d, 0 18px 48px #172b401a; */
```

The accent is a **runtime theme variable** — orange `#ec8621` in Brezel-native surfaces,
Ant Design's `#1677ff` default in generic screens — so personalization is real: the chat
options menu ships 22 × 22 colour swatches plus an "auto" swatch. The semantic soft tints
(good/bad/info/plum) are a direct pattern for our status pills.

**App chrome / navigation (MEASURED):** `.app-header` white, `height:64px`, `padding:0 24px`,
`border-bottom:1px solid #f0f0f0`; 36 × 36 circular action icons (32 × 32 avatar), hover
`#f0f0f0`; content padding 16px. Dashboard rail `.sidebar` white, `1px solid #dbe4ef`,
radius 18px, padding 18px, gap 12px, `min/max-height:min(74vh,860px)`,
shadow `0 10px 24px #0f172a0d`; its 4-column tab strip radius 14px on `#f8fafc`, active tab
`#0f172a`/`#f8fafc`. Underlying layout model (wiki): `tabs → rows → cols (span of a
24-column grid) → components`, with `borderless / vertical_labels / no_labels / no_padding`
options, a `top_bar` layout and an `affix` title+buttons row. Our 248 px sidebar and 60 px
top bar are close enough; keep proportions.

**Chat workspace / right panel (MEASURED — the key pattern to copy):**
- Container `position:fixed; inset-block:0; right:0; z-index:1040; pointer-events:none`
  (children re-enable events). Panel width `var(--chat-width)` = **520px**,
  `max-width:calc(100vw - 320px)`, full height, white, `border-left:1px solid #e6ebf1`,
  **`border-top:3px solid var(--chat-accent)`**, shadow
  `-1px 0 2px #172b400a, -16px 0 40px #172b401a`; 280 ms `cubic-bezier(.2,0,0,1)`
  slide-in from 24px.
- 14px full-height resize handle (`cursor:col-resize`), 4 × 44px grabber that grows to 72px
  on hover; hidden ≤1100px. Topbar **61px** with radial accent wash, 38 × 38 avatar
  (radius 12px), 15px/700 title, 12px muted subtitle, 34 × 34 icon buttons.
- Messages area is a faint double radial accent over `#f6f7f9`, 16px padding, 12px message
  gap; bubbles 14px/1.5 with radius **`6px 18px 18px 18px`** (own messages mirrored
  `18px 18px 6px 18px`), 9px 13px padding, avatar gutter 28px, 11px footer; own bubbles are
  an orange gradient with white text. Composer: white box, radius **16px**, 4px 8px 8px
  padding, focus ring `0 0 0 3px` accent-soft; 14px auto-grow textarea (max 200px); 11px
  hint line; **34px circular** accent send button. `@`-mentions and `/` commands open a
  suggestion popover (radius 12px, shadow `0 14px 34px #0f172a29`; command purple, mention
  blue, field teal). Closed-state floating toggle 58 × 58 accent with 22px badge.
- Responsive: at `width ≤ 1100px` the panel becomes full-screen `100vw/100dvh`, stage
  hidden, no border/shadow — important for our Deck viewport.

**Staged first-run setup (MEASURED):** `.chat-workspace--staged` sets panel width
`--chat-setup-width = min(680px,50vw)` and pins `.chat-setup-stage` to the remaining left
area. The stage background is exactly
`linear-gradient(160deg,#f8f4ec 0%,#eef2f5 55%,#e5ebf1 100%)` with blurred aurora radial
blobs (`#f0b56b52`, `#785ce624`, `#48a8dc29`). Headline `clamp(26px,3.2vw,40px)`/850,
`#182b3e`; starter cards `minmax(240px,1fr)`, `background:#ffffffb3`, radius 14px,
`0 8px 22px #19324d0f`; step pills radius 999px. Assistant banner (classic wizard) is a
`linear-gradient(135deg,#ffd60d24,#ef93001f)`. Emulating this warm-to-cool staged split is
the single most visually distinctive Brezel pattern.

**Org chart / role cards (MEASURED):** `.org-structure` min-height 520px, radius 16px,
`linear-gradient(145deg,#fff,#f6f8f7)`, horizontally scrollable 780px canvas. Leadership
card dark `#24313a`; unit cards white, radius 13px, `1px solid #d7dee2`; person cards
`#fbfcfc`, agent cards `#f1f8f6` with teal avatar; live-status pill `#12685f` on `#e9f5f2`
with a 7px pulsing dot; drag-and-drop reassignment and per-person "run" chips. The animated
graph variant uses white cards radius **16px**, shadow `0 10px 26px #19324d17`, 520 ms
entrance — matches the screenshot.

**Dashboard / approvals / other components (MEASURED):** pills radius 999px, 11px/700;
`.bz-btn` radius 10px, 13px/650, `--approve` solid green `#1a9670`, `--reject` red
`#c4413a` (note: Brezel uses green for approve, Lava uses orange — see §4). Signal pipeline
board `#f6f8fa` with 24px grid lines, stage columns ≥250px, nodes radius 8px with a 3px
status-colour left border. Notification popper radius `12px 0 12px 12px`, width
`min(450px,100vw - 16px)`, backdrop-blur 20px. Login split layout, form max-width 440px,
radius 20px, 48px control height.

**The one product screenshot (MEASURED geometry, 1813 × 913):** German *first-run "basic
setup" in staged chat mode*. Pixel runs: setup stage x≈0–1138 (warm→cool gradient; sampled
`#f7e4cc` at x0,y10 → `#eeedef` at x600 → `#e4e1f0` at x900); grey seam x1139–1146
(**INFERENCE:** resizer/panel seam — CSS gives a 14px handle); chat panel x≈1147–1813 on
`#f6f7f9`; orange band y≈125–160 sampled `#ed9137` ≈ the `#ec8621` accent rule (25,059
orange px); right-aligned orange bubbles; white composer at bottom right. The panel measures
~666px, wider than the 520px CSS default — **INFERENCE:** dragged resizer or different DPR;
CSS is authoritative. OCR (local 7B vision model, treat small text as approximate) read:
`Bert`, `BRANCHE / Energievertrieb`, `STANDORT`, `ANGEBOT / Vermarktung von Energieverträgen`,
`KUNDENSCHAFT / Geschäftskunden`, `LEITUNG / Vertrieb / Filippo / Vertriebsleitung`,
`Bärbel / KI-Grafikerin`, `Grundlegende Einrichtung`, `Manuell einrichten`, `Fertig`,
`Organization aktualisiert`, `Seele gespeichert` ("soul"/personality saved). Chat turns at
15:08/15:09 end with `Perfekt, die Einrichtung ist abgeschlossen…`; composer hint
`Nachricht an Bert …` / `Shift+Enter für Zeilenumbruch - @ für Agenten - / für Befehle`.

**Component anatomy to reproduce in our tokens (MEASURED from our `app.css`, already in the
right family):** card radius 18px / login 22px / drawer 0; shadow
`0 8px 26px rgba(32,52,72,.07)`, large `0 22px 60px rgba(32,52,72,.16)`; inputs radius
11px with 4px focus ring; buttons radius 10px; nav items radius 11px; pills/badges fully
rounded (99px) with 10px uppercase 800-weight text; KPI numbers 34px/850 with `-0.04em`
tracking; card titles 16px; body 13–15px/1.55; section heads 19–20px; hero 30–44px with
`-0.045em` tracking. Typeface Inter (marketing CSS confirms Inter; fallback stack already
in our CSS). Marketing grid for reference: wrap `min(1240px, calc(100% - 96px))`, sections
112px vertical padding, h1 `clamp(50px,6vw,86px)`/750, h2 `clamp(38px,4.4vw,66px)`, body
18px/1.65, radius 20px, breakpoints 1050/800/480px.

**Interaction inventory** from the marketing/governance text, console CSS and screenshot:
first-run company creation → package choice → provisioning → invite team; company profile
and org chart with agents as roles (drag/drop, "run"); setup chat with `@` mentions and `/`
commands; knowledge base with role-scoped documents; ticket-style task/case interface;
approvals (proposal → approve/reject/undo, scoped one-off permission); personal vs
independent assistants; integrated credential use; semantic search; theme personalization;
notification centre; 2FA and offline/PDF/print. Caveat: the only product *pixels* are the
first-run screen; list/table/approval anatomy comes from CSS class rules — high confidence,
not pixel-verified. Marketing "Brezel OS", telephony and local AI are labelled future
vision and excluded from this spec.

## 6. Declarative UI and workflow patterns worth copying from the docs

Brezel's older framework is a useful *architecture* reference even if we don't adopt PHP
Laravel: its UI and processes are data, not code.

- **Recipes** (https://wiki.brezel.io/reference/recipes/) are a small ECMAScript-like
  expression language for computed values and conditional visibility: `net * 1.19`,
  `first_name + " " + last_name`, `category == "Food" ? 0.07 : 0.19`,
  `sum(positions[*].position_total)`, `user().hasAbility(...)`, `hasRole("ROLE_ADMIN")`.
  Implication for us: an AI can edit a *string expression* without editing executable
  PHP/JS, provided it is parsed by a restricted interpreter (our theme values should be
  even simpler — plain data, no expressions).
- **Workflow patterns** (https://wiki.brezel.io/guides/workflows/):
  - Name workflows after business outcomes (`CreateProjectTask`, `PublishProject`).
  - Triggers: button, field change, create/update/delete event, cron, policy, workflow.
  - UI responses stay synchronous; long jobs become `async: true` and report
    `cast/progress` with `title`, `total`, `progress`, `send_to_user`, then notify.
  - `action/modal` with `type: confirm`, `checkpoint: true`, `autosave_key` for
    confirm-before-change; `action/notify` toasts have `type`, `message`, `description`.
  - `event/policy` + `query/where` constrain access by role/operation — the config
    pattern behind "read access is not write access".
  - Explicit checklist: intentional empty/failure branches; no customer/domain/infra
    details in labels; tests where outcomes matter.
- **Widgets** (https://wiki.brezel.io/guides/adding_a_custom_widget/) come in two
  flavours: *integrated* Vue components (fast, typed, preferred) and *editable* widgets
  stored as code in the database (admin-editable but restricted API). For our PHP app the
  equivalent is: server-rendered partials for built-in modules, and a strict data-only
  theme/config file for anything an AI or admin may change — never executable config.

## 7. First-run setup and product function inventory (marketing + governance pages)

From https://brezel.io/en/ and https://www.brezel.io/en/technology/governance/:

**First-run flow (4 steps, literal page content):** 01 "Create your company — Create an
account and name your company. No technical background needed." → 02 "Choose your
package — Review the service and terms. Registration alone is not a paid contract." →
03 "Let setup happen — Your workspace is automatically provisioned after your booking is
confirmed." → 04 "Get your team involved — Invite employees, add approved knowledge and
tackle your first tasks." Pricing is a single company licence (€499/month excl. VAT, no
per-seat fees); setup and monthly AI usage included.

**Product capabilities claimed:** personal assistant per employee with the same access
as its human; independent assistants assigned tasks with defined scope; task tickets as
the single interface (routing, orchestration, tool execution handled by Brezel); shared
workspace combining people, tasks and approved knowledge; integrations to existing
software; approved documents/guidelines as role-scoped knowledge; stored corrections
carry experience forward; integrated password management for authorised runtime use;
sandboxed code/tool execution; "heartbeat" scheduler wakes agents on an interval with a
structured memory snapshot as fresh context.

**Governance model (four questions before any action):** 01 Identity → 02 Access → 03
Assignment → 04 Approval. Read access ≠ write access; a task assignment does not expand
authority. Management sets policy, business owners define approvals, and "a prompt
instruction alone is not a substitute for effective permission settings". Personal
assistants never gain authority over colleagues; independent agents need a named
requester and review owner. Hierarchy/line-of-authority matters: crossing departments
needs authorised assignment and, where required, approval. Knowledge inheritance is
deliberately narrow (a reviewed general lesson may spread; raw HR conversations must
not). Approval views need trigger, intended action, evidence and open questions;
"proposal, approval and execution are different states". One approval does not authorise
unrelated follow-ups. Logs may contain sensitive data; admin access is not permission to
read personal content.

**Open-source framework feature inventory** (https://docs.brezel.io/, older
`wiki.brezel.io`) for context: multi-tenancy with hostname-based systems and a meta DB;
deny-by-default RBAC with module- *and field-level* grants, role seeds with
`sync`/`detach` policies, WebAuthn/2FA; declarative modules (28 field types) and 24-column
layouts; a visual workflow engine (`event/webhook`, `event/cron`, `source/entities`,
`flow/each`, `flow/if`, `op/set`, `op/recipe`, `action/save`, `action/response`,
`action/run`, policies, async jobs with progress); recipe expressions with separate
backend/frontend interpreters and an ANTLR grammar; notifications, translations
(vue-i18n), uploads, Twig views/templates, PDF export via Pandoc, offline PWA (Workbox),
WebSockets (brotcast), and an Astro-based static-site builder (PageDepot). A public
consumer package's generated types show the breadth: CRM, appointments, e-commerce,
invoicing, newsletters, surveys, courses, plans, page builder, widgets and wizards.

This maps closely onto our existing security model (`README.md`): model emits tool calls
only; panel checks RBAC; root-owned brokers; vault has `list`/`use` but no `get`;
privileged actions need per-use owner approval with short TTL and HMAC signed by a
root-only key. In our doc we can honestly say we already implement the governance
principles Brezel describes, and only the *presentation* needs upgrading.

## 8. Current app baseline (what the rebuild must preserve)

`src/public/index.php` + `app.js` already implement: owner login; fixed left sidebar +
top bar; overview hero with agent mini-cards and KPI cards; agent roster with ranks and
capability tags; sessions table; approvals table with Approve/Deny; exploit-watch findings
with "Run scan now"; vault secret list + a "use secret" test; audit trail; a settings page
with an **agent-editable layout JSON** (`title`, `accent`, `default_model`, `card_order`,
`welcome`, `custom_css`); a full-menu right drawer; and a full-screen chat overlay with
context panel. `app.css` already defines tokens (`--bg`, `--panel`, `--line`, `--text`,
`--muted`, `--accent`, `--radius`, `--shadow`) and a Brezel-like hero.

Security invariants from `README.md` that must survive the visual rewrite: model has no
tools (only `TOOL_CALL:` lines); panel performs RBAC; host actions are allowlisted with
regex-validated names; root-owned brokers; vault exposes `list`/`use` but never `get`;
secret-shaped strings are censored; privileged actions need per-use owner approval with
short TTL + root-only HMAC; default four agents cannot be deleted; new agents start with
zero tools and zero secret grants.

**Security warning found in the existing editable config:** the layout JSON accepts a raw
`custom_css` string from an agent (with owner approval). Arbitrary CSS can leak data via
`url()`/`@import` and break the UI. The rebuild's AI-editable schema should expose
*tokens only* (colour, radius, spacing, font choices from fixed lists) and drop `custom_css`.

## 9. Vendoring Tailwind CSS + animate.css with no CDN

Versions verified 2026-11-18 (full detail and all URLs in the companion research note
`/tmp/opencode/research/vendor-theme-findings.md`):

- **Tailwind CSS v4.3.5** (released 2026-11-06). The CLI is a separate package:
  `npm install --save-dev tailwindcss@4.3.5 @tailwindcss/cli@4.3.5`, then
  `npx @tailwindcss/cli -i resources/css/input.css -o public/assets/app.css --minify`.
  The old `tailwindcss build input.css -o …` form is **wrong in v4**: positional inputs
  are silently ignored; always pass `-i/--input`. Official standalone binary
  `tailwindcss-linux-x64` (~104 MiB, from the v4.3.5 GitHub release, verify with the
  published `sha256sums.txt`) allows a build with no Node at all.
- v4 is **CSS-first**: `@import "tailwindcss";`, `@theme { --color-*: initial; --color-brand-500: …; }`,
  `@source "../../src";`, `@source inline('…')` for safelisting, and
  `@custom-variant dark (&:where(.dark, .dark *))`. No `tailwind.config.js`, no
  `@tailwind base/components/utilities`, no separate autoprefixer/postcss-import.
  Do not build class names dynamically in PHP (`text-{{ $color }}-500`) — the scanner
  only sees literal strings.
- `--minify` uses Lightning CSS; v4's browser floor is Safari 16.4 / Chrome 111 /
  Firefox 128. Cache-bust via content-hashed filenames + a `manifest.json`; nginx:
  `location /assets/ { try_files $uri =404; expires 1y; add_header Cache-Control "public, immutable" always; }`.
- **animate.css v4.1.1** is licensed **Hippocratic License 2.1** (Copyright 2021 Daniel
  Eden), **not MIT** — v3 was MIT. Tailwind is MIT. Ship both full license texts in
  `LICENSES/` and serve them read-only. Measured `animate.min.css`: 71,750 B raw /
  5,091 B gzip; a custom build with only 2–4 animations is a few KiB (custom builds
  require cloning the animate.css repo and running `npm start`; they cannot be made from
  the npm package).
- animate.css usage: `class="animate__animated animate__fadeIn"`, CSS variables
  `--animate-duration` / `--animate-delay` / `--animate-repeat`. Its shipped
  `@media print, (prefers-reduced-motion: reduce)` block **must be preserved** — never
  disable reduced-motion support.
- No-CDN enforcement: never load `cdn.tailwindcss.com`, cdnjs/jsdelivr or Google Fonts.
  Add CSP `default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:;
  font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none';
  frame-ancestors 'none'`. SRI on same-origin files is redundant when CSP + hashed
  filenames are used; if you use it, regenerate hashes on every build.
- Build strategy: multi-stage Docker (Node 20 build stage → `php:8.3-fpm-alpine` final)
  or build on the dev machine and **commit generated CSS**; the runtime image then has
  no Node. Package scripts: `validate:theme` (ajv), `generate:theme` (PHP),
  `build:css` (all + tailwind CLI + fingerprint), `check:css` (`git diff --exit-code` to
  prove reproducibility). `.gitignore` must not exclude the PHP templates Tailwind scans
  nor the committed `public/assets/*.css`.

## 10. AI-editable theme/config schema (safe by construction)

- **JSON only.** Never let an AI write a PHP config file (`include` executes arbitrary
  code). Parse with `json_decode(..., JSON_THROW_ON_ERROR)`, bounded depth/size.
- Only `config/theme.json` is AI-writable. `theme.schema.json`, `input.css`, generator,
  validator, Dockerfile, nginx.conf and templates are human-owned. Otherwise the AI can
  simply rewrite the allowlist.
- JSON Schema draft 2020-12 with `additionalProperties: false` at every level, `const: 1`
  schemaVersion, `pattern "^[a-z][a-z0-9-]{0,30}$"` for token names, colors
  `"^#[0-9A-Fa-f]{6}$"`, lengths `"^(0|[0-9]{1,3}(\\.[0-9]{1,2})?)(px|rem|em|%)$"`,
  fonts an `enum` of 3 pre-approved stacks, shadows an `enum` of `none|sm|md|lg|xl`
  resolved via a fixed PHP map, animation names an allowlist of 11 animate.css names,
  and `reducedMotion: { "const": true }` (reject false rather than honouring it).
- Two validation layers: `npx ajv validate --spec=draft2020 --strict=true --all-errors
  -s config/theme.schema.json -d config/theme.json` at build time, and a pure-PHP
  runtime guard (no Composer in production) called from `entrypoint.sh` before nginx and
  from the generator before emitting CSS.
- Generator writes atomically (`temp file + rename`) into `resources/css/theme.generated.css`;
  final emitted-CSS check rejects `url(`, `@import`, `expression(`, `javascript:`, `data:`
  and backslashes. Cap file size (64 KiB), depth (8) and token count (200).
- **Remove the existing `custom_css` key** from the panel's editable layout JSON. If raw
  CSS is ever accepted it can leak via `url()`/`@import` and break the panel; tokens only.
- AI-edit workflow: patch only `theme.json` → show `git diff` preview → validate →
  generate/compile → `check:css` → owner approval → commit → rollback with a single
  `git checkout` / `git revert` if needed. Grep diffs for `PANEL_`, `password`, `token`,
  `BEGIN PRIVATE KEY`; reject remote `$ref`/`$schema` URLs in data.

## 11. Decisions for our rebuild

1. **Borrow the Brezel *product* visual language, not the ERP's.** Build a calm, dense
   control workspace: white cards on warm off-white canvas, navy type, one warm accent,
   generous radii (16–20 px), very light shadows, small uppercase labels, big numeric KPIs.
   Keep the existing panels/screens (overview, agents, sessions, approvals, security,
   vault, audit, settings, setup, chat) — they already mirror Brezel's role/approval model.
2. **Adopt the Brezel/Lava governance story explicitly in the UI.** Every agent card shows
   role, tools, secrets and schedule; every privileged action is a *proposal* with a
   plain-language summary and Approve/Deny, distinct from execution; use Lava's proven
   card anatomy (orange border, summary, Approve orange / Decline neutral, plus a scoped
   "approve for this run only" option) and consider a Normal/Careful/Auto-style pause
   mode per agent. Never let an approval imply authority for unrelated follow-ups.
3. **Right-side agent panel + context drawer, not a page swap.** Follow Lava's pattern:
   live workspace on the left, agent conversation on the right, progress steps with
   "show in [view]" links, `/` command picker, and history. Our existing chat overlay can
   be restyled into this pattern without changing the engine.
4. **Declarative layout and tokens, with a strict boundary.** Brezel's `tabs → rows →
   cols → components` and JSON Bakery plans show how far data-driven UI can go. For our
   PHP app: server-rendered partials for real screens; a JSON `theme.json` for *design
   tokens only*; schema + dual validation + diff/rollback for AI edits. Delete raw
   `custom_css` from the editable config. Keep all security code, brokers and RBAC
   outside the AI-editable surface.
5. **Self-contained assets.** Compile Tailwind v4 CSS at build time and commit it; vendor
   animate.css v4.1.1 with its full Hippocratic-2.1 notice; no CDN of any kind; CSP
   `style-src 'self'`; content-hashed filenames; reduced-motion support preserved.
6. **Respect the "familiar chat, connected workspace" copy pattern** in onboarding: four
   short steps ("create your company → choose plan → setup happens → invite your team")
   maps to our first-run ("unlock → review the four agents → connect brokers/model →
   start a chat"). Be explicit that our model runs locally on the Deck and no external
   AI/telephony features are implied.
7. **Use Brezel naming conventions for new files/identifiers** (`snake_case` for PHP
   files/JSON keys, `kebab-case` branches, Conventional Commits with scope such as
   `feat(approvals): …`), consistent with the repo's existing commit convention.
8. **Do not claim we use Lava.** If asked, say: Brezel does not use a design system by
   that name; our inspiration is Brezel's workspace/governance UX plus Lava's approval
   UX; our runtime remains PHP 8.3 + nginx + SQLite + local OpenCode API.

## 12. Sources (all consulted 2026-11-18)

**Brezel product / marketing**
- https://brezel.io/en/ , https://www.brezel.io/en/ (canonical = www; identical CSS/HTML)
- https://brezel.io/whybrezel → redirects to https://www.brezel.io/en/solutions/
- https://brezel.io/aiagents → redirects to https://www.brezel.io/en/technology/ai-agents/
- https://brezel.io/brezel-origins → redirects to https://www.brezel.io/en/future/
- https://www.brezel.io/en/technology/governance/ (identity → access → assignment → approval)
- https://www.brezel.io/en/technology/ , /en/future/ , /en/future/brezel-os/ , /en/on-premise/
- https://console.brezel.cloud/en/ (live SPA), https://console.brezel.cloud/config.js
- https://brezel.io/assets/site.css (Inter, hex palette; measured root tokens and breakpoints)
- https://brezel.io/assets/brezel-logo.svg (brand mark 1024×1024; fills `#ffd60d`, `#ef9300`,
  `#6d0101`, `#c40808`, `#ef0617`, `#3f0001`)
- https://brezel.io/assets/product-workspace-de.png (the only product screenshot: 1813×913,
  German first-run setup; alt "Brezel product interface in German: company profile,
  organisation chart and setup chat")
- https://console.brezel.cloud/assets/index-D8Wq2ipH.css (558 KB — the real product design
  system: chat, setup, org chart, dashboard, notifications, login; navy/orange tokens)
- https://console.brezel.cloud/globalstyle.css (empty placeholder)
- Crawled all 14 en/de marketing pages: no other product screenshots exist on the site.

**Brezel docs / source**
- https://docs.brezel.io/ (current handbook; 166 pages crawled), https://wiki.brezel.io/ (older mirror)
- https://docs.brezel.io/concepts/architecture/ , /concepts/permissions/ , /concepts/notifications/
- https://docs.brezel.io/layout/overview/ , /layout/components/ , /reference/fields/ , /reference/recipes/
- https://docs.brezel.io/guides/workflows/ , /guides/adding_a_custom_widget/ , /guides/offline_mode/
- https://docs.brezel.io/api/layout/types/enumerations/componenttypes/ , /type-aliases/widgettype/
- https://docs.brezel.io/contribute/style_guide/
- https://gitlab.kiwis-and-brownies.de/kibro/brezel (canonical, private)
- https://api.github.com/orgs/brezelio/repos and the 12 repo URLs in §2
- Clones: `/tmp/opencode/research/brezel-src/{brezel,brezel-js-client,api-client-php,recipe-parser-php}`
- https://registry.npmjs.org/-/v1/search?text=brezel ; `@kibro/brezel-spa` → 404 (private)

**Lava (separate product — not Brezel)**
- https://lava.so/index , https://lava.so/docs/introduction.md , https://lava.so/docs/llms.txt
- https://lava.so/docs/desktop/overview.md , /desktop/how-it-works.md , /desktop/working-with-agent.md
- https://lava.so/docs/gateway/how-lava-gateway-works.md , /guides/ai-spend-keys.md

**Tailwind / animate.css / tooling**
- https://tailwindcss.com/docs/installation/tailwind-cli , /docs/functions-and-directives ,
  /docs/theme , /docs/detecting-classes-in-source-files , /docs/dark-mode , /docs/upgrade-guide
- https://github.com/tailwindlabs/tailwindcss/releases/latest (v4.3.5 + sha256sums.txt)
- https://raw.githubusercontent.com/tailwindlabs/tailwindcss/main/LICENSE (MIT)
- https://animate.style/ , https://github.com/animate-css/animate.css ,
  https://raw.githubusercontent.com/animate-css/animate.css/main/LICENSE (Hippocratic 2.1),
  /main/docsSource/sections/03-best-practices.md , /07-custom-builds.md
- https://ajv.js.org/guide/getting-started.html , https://github.com/ajv-validator/ajv-cli
- https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP ,
  /en-US/docs/Web/Security/Subresource_Integrity

Full companion research notes (with code snippets, measured file sizes, crawls and exact
file paths): `/tmp/opencode/research/github-lava-findings.md`,
`/tmp/opencode/research/vendor-theme-findings.md`, and
`/tmp/opencode/research/ui-layout-findings.md` (precise UI/UX spec with measured tokens,
component anatomy, screenshot geometry, breakpoints and labelled inferences).
