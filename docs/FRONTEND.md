# Hermes Control Panel — Frontend Tour

**Purpose.** This is an exhaustive, code-derived tour of the browser side of the Hermes Control Panel: `src/public/index.php` (the HTML skeleton and bootstrap variables), `src/public/app.js` (the entire 886-line vanilla-JS SPA), `src/public/app.css`, `src/public/tailwind.css`, and the vendored `src/public/assets/vendor/animate.min.css`. It lists every view and render function, the navigation/drawer/chat overlays, the state object `S`, the CSRF and API helpers, configuration-driven theming, all event bindings, voice recording, the first-run wizard, search, self-test, agent cards and ranks, the config editor, and the known limitations of the current implementation. It is a description of what ships, not a design proposal.

**Last verified:** `0137eff test: add regressions for deep-debug fixes` (`git log -1 --oneline`, read-only, at time of writing).

---

## 1. The four frontend files

| File | Lines | Role |
|---|---|---|
| `src/public/index.php` | 102 | PHP-rendered HTML shell; ensures owner credentials exist, reads active config, sets `panel_csrf` cookie, emits `<html data-theme data-radius>` and `window.HCP_CONFIG`, then loads the three stylesheets and `/app.js`. |
| `src/public/app.js` | 886 | The whole SPA: helpers, state, 14 view renderers, binders, overlays, wizard, polling loop. No framework, no build step, no CDN. |
| `src/public/app.css` | 333 | Main stylesheet; defines two generations of tokens (a base `:root` block and a "Brezel tokens" block with `data-theme`/`data-radius` variants) plus layout, cards, tables, hero, drawer, chat, modal, wizard, voice, search, theme gallery, self-test rows and animations. |
| `src/public/tailwind.css` | 19 | Committed Tailwind v4 utility subset, all `tw-*` prefixed (flex/grid/gap/padding/margin/text/bg/rounded/border/shadow/w/h/overflow). Comment says generated from `src/templates/**`; that directory does not exist in this checkout — the file is a static artifact. |
| `src/public/assets/vendor/animate.min.css` | 12 | Locally vendored animate.css v4.1.1 subset (Hippocratic-2.1): `animate__animated` plus `fadeIn`, `fadeInUp`, `slideInRight`, `pulse`, with a `prefers-reduced-motion` opt-out. No CDN. |

`health.php` (6 lines) is the unauthenticated liveness endpoint used by the container healthcheck; it is not part of the UI.

---

## 2. `index.php` — the shell

```
<?php Auth::ensureOwner(); $config = ConfigGovernance::active(); … ?>
<html lang="<meta.locale>" data-theme="<theme.mode>" data-radius="<theme.radius>">
 <head>
   <title><meta.title></title>
   <link … /assets/vendor/animate.min.css> <link … /tailwind.css> <link … /app.css>
   <script>window.HCP_CONFIG = <full active config>;</script>
 </head>
 <body>
   #login(.hidden)      login form: #login-form, #login-user, password, #login-error
   #setup-screen.hidden first-run wizard mount point
   #app(.hidden)        .sidebar(brand #brand-name, #nav, owner chip, #logout-btn)
                        .main(.topbar(#menu-btn, #view-title, #view-sub, #global-search,
                                      #pills, #voice-btn, #refresh-btn), #content)
                        #drawer-backdrop.hidden + #drawer.hidden(#drawer-close, #drawer-body)
                        #chat.hidden chat overlay (#chat-back, #chat-agent, #chat-session,
                             #chat-new, #chat-context, #chat-close,
                             #chat-thread, #chat-context-panel, #chat-form, #chat-input)
                        #toast.hidden
   <script src="/app.js"></script>
 </body>
</html>
```

Important shell details:

- `Auth::ensureOwner()` runs on page load, so visiting the UI is enough to sync the configured owner password into SQLite.
- If `panel_csrf` is absent, PHP sets it with `httponly: false` (JavaScript must read it), `SameSite=Lax`, 12-hour expiry, `Secure` only under HTTPS. The value is random 24 bytes, never printed.
- The server applies theme `data-theme`/`data-radius` attributes from the active config on first paint; `app.js` then re-applies/repairs them from `bootstrap` data.
- All interactive regions start `.hidden`; the JS decides which one to reveal.

---

## 3. Boot sequence and authentication flow

```
IIFE at bottom of app.js (lines 875–886)
  └─ api('me')
       ├─ not authenticated → showLogin()
       └─ authenticated
            ├─ showSetupIfNeeded() → api('setup.status')
            │    ├─ no lock → render wizard into #setup-screen, hide #app;
            │    │            #setup-finish → api('setup.complete') → bootstrap(true)
            │    └─ complete → false
            └─ bootstrap(true)
                 └─ api('bootstrap') → S = response → applyLayout()
                      → showApp() → renderNav() + renderPills() + render() + renderDrawer()

login #login-form submit → api('login', {username, password})
    → clear password field → bootstrap(true)

setInterval(10 s): if wizard/chat/drawer hidden and #app visible → bootstrap() (silent refresh)
```

`showLogin()` / `showApp()` just toggle `.hidden` on `#login` and `#app`. `#logout-btn` calls `api('logout')` then `showLogin()`. `#refresh-btn` re-runs `bootstrap()` and toasts "Refreshed".

---

## 4. The state object `S` and helpers

`let S = {}` is replaced wholesale by the `bootstrap` response (`S = r`). Its keys, and who consumes them:

| Key | Source | Consumers |
|---|---|---|
| `ok` | api bootstrap | boot check |
| `agents` | `SELECT * FROM agents` | overview, agents, drawer, modals, chat, voice-send |
| `tools` | `RBAC::TOOLS` | agent modal checkbox grid (`S.tools`) |
| `sessions` | last 100 sessions + `agent_name` | overview count, sessions table |
| `approvals` | pending approvals | approvals "Recorded actions" |
| `escalations` | pending/approved escalations + `agent_name` | approvals, nav badge, overview count |
| `findings` | open findings (100) | security, nav badge, overview count |
| **`audit`** | last 200 audit rows | overview "Recent activity", audit view |
| `messages` | last 80 inter-agent messages | fetched but not rendered by any current view |
| `layout` | `layout` row | `L()` helper — card order, default model, title |
| `config` | active config | nav pages, theme, settings, title |
| `theme_presets` | `Theme::presets()` | themes gallery, theme fallback in `applyLayout` |
| `setup_complete`, `setup_requirements` | Setup | setup view, wizard |
| `stt` | STT status | setup view (voice view re-fetches via `stt.status`) |
| `vault` | `{available, secrets}` — names/policies only | vault view, pills, setup |
| `host`, `hermes` | `{available: bool}` | pills, setup |

Module-level variables: `view` (defaults `'overview'`), `chatAgent`, `chatSession`, `customStyle` (declared but never used), plus `mediaRecorder` / `audioChunks` for voice.

Helpers:

- `$` / `$$` — `querySelector` / `Array.from(querySelectorAll)`.
- `esc(v)` — HTML-escapes `& < > " '`; every dynamic value in every template literal goes through it.
- `arr(v)` — normalises JSON strings, arrays, or comma-separated strings into an array (used for `tools`, `vault_names`).
- `fmt(ts)` / `ago(ts)` — absolute locale time, or relative `Xs/m/h/d ago`.
- `L()` — parses `S.layout.value` into an object, returning `{}` on error; this is the agent-editable layout store.
- `csrfToken()` — regex-reads `panel_csrf` from `document.cookie`.
- `api(action, data)` — picks `GET` or `POST` from the injected `window.API_METHODS` catalogue (fallback `POST`), sends `credentials: 'same-origin'` and the `X-CSRF-Token` header; GET args become query parameters, POST args a JSON body; catches bad JSON into `{ok:false, error:'bad JSON from server'}`.
- `toast(msg)` — writes `#toast`, shows it, auto-hides after 3 s.
- `applyLayout()` — sets brand text from `config.meta.title` or layout `title`, sets `document.documentElement.dataset.theme` / `.dataset.radius`, and applies validated CSS custom properties from `theme.tokens` via `style.setProperty`. **Raw `custom_css` is intentionally ignored** — only schema-validated tokens are applied.
- `sortedAgents()` — sorts agents by `layout.card_order` slug order, then by id; this drives the "ranks".
- `pendingCount()` / `findingCount()` — nav badges and overview KPIs.
- `debug=1` in the query string installs `window.onerror` / `unhandledrejection` handlers that print a fixed red `<pre id="js-error">` overlay.

---

## 5. Navigation, drawer, chat overlay, modals

**Navigation.** `NAV` is a hard-coded 14-entry fallback list of `{id, ico, label, group}`. `renderNav()` prefers `S.config.pages` when present (mapping each page's `nav.icon`/`label`/`group`), then appends any of `search`, `themes`, `voice`, `self-test`, `hermes` that the config omitted — a safety net so those tools are never unreachable. Counts are injected as `.nav-badge` on `approvals` (pending escalations) and `security` (open findings). Clicking a `.nav-item` sets `view`, re-renders nav and content.

**Top bar pills.** `renderPills()` shows `vault` and `host` on/off based on broker availability, and a `model` pill from `layout.default_model` (default `big-pickle`).

**Drawer.** `renderDrawer()` fills `#drawer-body` with six sections: quick actions (`data-newagent`, `data-scan`, `data-refresh`), agent menu (one `data-chat` per agent), message routing (`#msg-from`, `#msg-to`, `#msg-body`, `data-sendmsg`), agent-editable UI (`#ui-key`, `#ui-value`, `data-ui-edit`), model (`#default-model`, `data-set-model`), and a read-only "Safe use reminder". Opened by `#menu-btn`; closed by `#drawer-close` or `#drawer-backdrop`.

**Chat overlay.** `openChat(agentId, sessionId)` sets `chatAgent`/`chatSession`, reveals `#chat`, hides the drawer, sets the agent name, calls `loadChat()` and focuses the composer. `loadChat()` either shows "New session" or fetches `session.get&id=…`, renders messages as `.msg.user` / `.msg.assistant` / `.msg.system` with `esc()`d content, scrolls to bottom, and calls `renderContext(sess)`. `renderContext()` fills the toggleable `#chat-context-panel` with agent name/slug, model, session id, schedule, tool tags, secret grant *names*, and the system prompt. `closeChat()` hides the overlay and clears state. Composer interactions: Enter submits (Shift+Enter newline), `#chat-new` clears the session, `#chat-context` toggles the context panel, and a successful send toasts `"N tool call(s) — see session context"`.

**Agent modal.** `agentModal(a)` builds a `<form id="agent-form">` in a `.modal`: name, slug (read-only when editing), role, model (defaults to `layout.default_model` or `big-pickle`), description, system prompt, schedule, comma-separated secret names, a `.check-grid` of every `S.tools` entry with its description, Enabled and Accepts-messages checkboxes, and submit/cancel. Submit collects `FormData`, calls `agent.update` (edit) or `agent.create`, shows `#agent-err` on failure, else closes, toasts and re-bootstraps.

---

## 6. The exact 14 views and what each shows

`render()` maps `view` to one of 14 render functions, writes `#view-title`/`#view-sub` from `TITLES`, sets `#content.innerHTML`, calls `bindContent()`, and then conditionally `bindVoice()`, `loadSelfTest()` or `bindSearch()`. Unknown views fall back to `renderHermes()`.

| # | View id | Nav group | Render function | Data shown |
|---|---|---|---|---|
| 1 | `overview` | Core | `renderOverview()` | Hero ("Your digital team", trust chips, mini cards for first 3 agents), 4 KPIs (agents, sessions, pending approvals, open findings), **Agent ranks** cards with gold/silver/bronze medals, live/off pill, tool tags (privileged tools get `.tag.priv`), session count, chat/new-session/edit buttons, and a **Recent activity** table from `S.audit.slice(0,8)` (relative time, actor, action, detail). |
| 2 | `sessions` | Core | `renderSessions()` | Table of `S.sessions`: title + id, `agent_name`, relative `updated_at`, Open (`data-open` + `data-agent`) and Delete (`data-delsess`) buttons. |
| 3 | `agents` | Core | `renderAgents()` | Table of `sortedAgents()`: rank badge, name + mono slug, role, model, tool count and secret-grant count, schedule (or "manual"), Edit; Delete only for non-default slugs. Create-agent button and a note that new agents start with ticked tools only. |
| 4 | `approvals` | Core | `renderApprovals()` | **One-time escalations** table from `S.escalations` (id, agent, capability, reason, status pill, Approve/Deny when pending) and **Recorded actions** table from `S.approvals` (id, kind, summary, status, absolute time). |
| 5 | `security` | Core | `renderSecurity()` | Open `S.findings` rows with severity pill, title + source, detail, relative time; "Run scan now" button; note that scans are read-only, daily at 03:15, and fixes need approval. |
| 6 | `vault` | Core | `renderVault()` | Broker-online pill; table of `S.vault.secrets` (name, label, allowed-use JSON, approval); "Guarantee" explanation (list/use only, broker injects, double-scrub, `***CENSORED***`); **Agent secret injection test** form with secret/kind/params/purpose fields and a result `<pre>`. Values never displayed. |
| 7 | `search` | Core | `renderSearch()` | Kind tabs (Everything / Pages / Agents / Sessions / Findings / Hermes), `#search-q` input, `#search-go`, and `#search-results` populated by `api('search.query')` with kind badge, title and scrubbed snippet. Page results navigate to that page id; other kinds return to `search`. |
| 8 | `themes` | Workspace | `renderThemes()` | Gallery of all `S.theme_presets`: swatch of `--subtle`, surface, `--accent`, `--good`, `--ink` bubbles, label and `mode · radius`; active preset outlined. Click → `theme.set` → toast → `bootstrap()`. |
| 9 | `voice` | Workspace | `renderVoice()` | Push-to-talk card (`#mic-btn`, `#voice-state`, `#voice-engine`), transcript card (`#voice-text`, `#voice-clear`, `#voice-send` to Chat agent), and a note that audio is deleted after transcription. `bindVoice()` fetches `stt.status` and disables the mic if unavailable. |
| 10 | `self-test` | Workspace | `renderSelfTest()` | "Daily web UI test" card, "Run now" button (`selftest.run`), and `#selftest-report` filled by `loadSelfTest()` from `selftest.report`: status, time, page count, and a scrollable PASS/FAIL row per check. |
| 11 | `hermes` | Hermes | `renderHermes()` | 19 area tabs + tile grid. "All areas" shows one tile per area (label + source id + Load). Selecting an area calls `api('hermes.source', {source})` and pretty-prints the field-whitelisted, scrubbed rows (with a `(cached)` marker when served from `hermes_cache`). |
| 12 | `settings` | Core | `renderSettings()` | Two-column top: AI-editable config form (`#cfg-path`, `#cfg-value`, Propose, Refresh history, `#cfg-result` diff output) and read-only active-config `<pre>` (revision, theme, feature flags, prompts, schedule). Below: revision history with per-row Approve/Roll back buttons from `ui.config.history`. |
| 13 | `audit` | Core | `renderAudit()` | Table of `S.audit` (absolute time, actor, action, detail), labelled "last 200 events". |
| 14 | `setup` | Core | `renderSetup()` | Environment table (Panel = "two-container compose", Hermes gateway, vault broker, host control, local STT, setup lock), requirements list from `S.setup_requirements` with ✓/✕/! markers, "Re-run checks" button. |

`TITLES` supplies the subtitle for each: e.g. Vault — "Names and policies only — values never reach an agent"; Voice — "Local speech-to-text. Nothing leaves the Deck."; Self test — "Daily AI check of every page in this panel".

**Hermes areas (19).** `HERMES_AREAS` maps a tile id → label → source id:

`hermes-status`→Status→`hermes.status`; `hermes-sessions`→Sessions→`hermes.sessions.list`; `hermes-models`→Models→`hermes.models.options`; `hermes-tools`→Tools→`hermes.tools.toolsets`; `hermes-skills`→Skills→`hermes.skills.list`; `hermes-cron`→Cron→`hermes.cron.jobs`; `hermes-analytics`→Analytics→`hermes.analytics`; `hermes-themes`→Themes→`hermes.themes`; `hermes-config`→Config schema→`hermes.config.schema`; `hermes-env`→Environment names→`hermes.env.names`; `hermes-messaging`→Messaging→`hermes.messaging.platforms`; `hermes-webhooks`→Webhooks→`hermes.webhooks`; `hermes-capabilities`→Capabilities→`hermes.gateway.capabilities`; `hermes-logs`→Logs→`hermes.logs`; `hermes-files`→Files→`hermes.files.meta`; `hermes-git`→Git→`hermes.git.status`; `hermes-profiles`→Profiles→`hermes.profiles`; `hermes-plugins`→Plugins→`hermes.plugins`; `hermes-mcp`→MCP→`hermes.mcp`.

`HERMES_SOURCE_MAP` turns that list into an id→source lookup used by `loadHermesArea()`.

---

## 7. Event bindings, grouped by binder

**Global (bound once, lines 762–873):**

- `#login-form` submit → `api('login')`, clear password, `bootstrap(true)`.
- `#logout-btn` → `logout` + `showLogin()`; `#refresh-btn` → `bootstrap()`.
- `#voice-btn` → switch to the `voice` view.
- `#global-search` Enter → switch to `search`, prefill `#search-q`, click `#search-go`.
- `document` keydown `/` → focus `#global-search` (unless typing in an input/textarea).
- `#menu-btn` / `#drawer-close` / `#drawer-backdrop` → open/close drawer.
- `#chat-back` / `#chat-close` → `closeChat()`; `#chat-context` → toggle context panel; `#chat-new` → clear session and reload chat.
- `#chat-form` submit → `chat.send`, store `session_id`, `loadChat()`, toast trace count; `#chat-input` Enter (without Shift) dispatches submit.

**`bindContentBase()`** (called for every view; acts on whatever is currently in `#content`):

- `[data-chat]`, `[data-newsess]`, `[data-open]` → `openChat()` with the appropriate agent/session ids.
- `[data-edit]` → `agentModal()` with the matching agent.
- `[data-delsess]` → confirm → `session.delete` → toast → `bootstrap()`.
- `[data-delagent]` → confirm → `agent.delete` (server refuses default slugs) → toast → `bootstrap()`.
- `[data-esc]` → `escalation.decide` with `data-ok` → toast → `bootstrap()`.
- `[data-scan]` → `runScan(btn)`: disables the button, `security.scan`, toast finding count, `bootstrap()`.
- `[data-save-layout]` → parse `#layout-json` → `layout.save` (see limitations: no current view renders that input).
- `[data-vault-use]` → parse `#v-use-params` → `vault.test` → print result in `#v-use-result`.
- `[data-newagent]` → `agentModal()`; `[data-newchat]` → open chat with the first enabled agent.
- `[data-goto]` → navigate to that view id.

**`bindDrawer()`** (re-run on each `renderDrawer()`): `[data-newagent]`, `[data-scan]`, `[data-sendmsg]` (`agent.message`), `[data-ui-edit]` (`ui.config.propose` using a four-key path map `title→/meta/title`, `description→/meta/description`, accent→`/theme/tokens/--accent`, `mode→/theme/mode`), `[data-set-model]` (`layout.save`), `[data-refresh]` (`bootstrap()`).

**`bindContent()`** adds the per-view binders: `bindThemes()`, `bindHermes()`, `bindSelfTest()`, `bindConfig()`. `render()` adds `bindVoice()`, `loadSelfTest()` and `bindSearch()` for their views.

**`bindConfig()` / `loadConfigHistory()`**: fetch history, render each revision with Approve (draft + proposal id) and Roll back (non-active) buttons, wire those buttons to `ui.config.approve` / `ui.config.rollback` followed by `bootstrap()`; Propose parses `#cfg-value` as JSON when possible (plain string otherwise) and sends a single `{op:'replace'}` patch.

**`bindSearch()`**: `#search-go` click or Enter in `#search-q` calls `search.query`; `.view-tab` clicks switch the active kind and re-run if a query exists.

**`bindSelfTest()`**: `#selftest-run` disables itself, calls `selftest.run`, toasts pass/fail, reloads the report.

**`bindHermes()`**: `.view-tab[data-area]` clicks call `loadHermesArea()`; `bindContent()` also primes `loadHermesArea('all')` when the Hermes view is active.

---

## 8. Voice recording in detail

`bindVoice()` first calls `stt.status` and sets `#voice-engine` text (`"Engine ready · <models>"` or a "not installed" hint). The mic button is disabled unless `s.available`.

- `onpointerdown` → `start()`: `navigator.mediaDevices.getUserMedia({audio:true})`, new `MediaRecorder`, collect `dataavailable` chunks into `audioChunks`.
- `onpointerup` / `onpointerleave` → `stop()`: `mediaRecorder.stop()`, remove `.recording`.
- `onstop`: stop all tracks, build a `Blob` with `mediaRecorder.mimeType || 'audio/webm'`, append it as `audio` to a `FormData`, and POST directly to `/api.php?action=stt.transcribe` with the CSRF header (a direct `fetch`, not the `api()` JSON helper). On success, show the transcript and toast the duration; on failure, show the error in `#voice-state`.
- `#voice-clear` resets the transcript; `#voice-send` finds the agent with slug `chat` and sends the transcript via `chat.send` — i.e. transcribed speech always goes to Chat & News, not to the currently selected chat agent.

The audio file is written to a temp file server-side and deleted in a `finally` block; only the transcript is retained in the DOM.

---

## 9. First-run wizard

`showSetupIfNeeded()` calls `setup.status`; if `ok` and `complete` it returns `false` and normal bootstrap proceeds. Otherwise it injects `renderSetupScreen(st)` into `#setup-screen`, reveals it, hides `#app`, and binds `#setup-finish` to `setup.complete` → hide wizard → `bootstrap(true)`.

`renderSetupScreen()` shows: a header with `N/6 complete` from `st.steps`, six progress bars (`.setup-step.done`), a requirements list from `st.requirements` with ✓/✕/! markers (fail-level requirements disable the Finish button; warnings do not), a "setup assistant" narrative panel using the vendored `animate__fadeInUp` / `animate__fadeIn` classes, and examples of plain-language requests. The six step titles come from `Setup::status()`: Welcome & requirements, Environment, Hermes connection, Owner account, Personalise, Done. The backend `setup.complete` writes a `setup.lock` file and audits the event; the wizard is idempotent.

---

## 10. CSS architecture

`app.css` is effectively two layers in one file:

1. **Base workspace (lines 1–215).** A cool-gray palette (`--bg`, `--panel`, `--navy`, orange `--accent`) and the core shell: `.login-*`, `.app` grid (248 px sidebar + main), `.sidebar`, `.nav-item` / `.nav-badge`, `.topbar`, `.content`, buttons, cards, ranks (gold/silver/bronze), tags (`.tag.priv` for privileged tools), KPIs, tables, severity pills, `pre`, the Brezel-style hero, drawer/backdrop, chat overlay/messages/context/composer, toast, modal, field/check grids, and two responsive breakpoints (1000 px hides the hero team; 820 px collapses the sidebar to icons and hides the chat context panel).
2. **Theme system (lines 216–333).** A second `:root` defines measured Brezel tokens (`--ink`, `--dash-accent`, `--dash-good`, `--dash-bad`, `--dash-info`, `--dash-plum`, `--chat-surface`, `--dash-radius`, …) and aliases the base variables onto them. `html[data-radius="square"]` zeroes radii; `html[data-theme="dark"|"midnight"|"ember"|"mono"]` override colors and some backgrounds. Then come component styles for search results, view tabs, Hermes tiles, theme gallery, voice recorder/pulse animation, the setup shell, requirement rows, self-test rows, diff lines, and local `fadeIn` / `slideInRight` keyframes.

`tailwind.css` supplies optional `tw-*` utilities (none of which are used by the current `app.js` templates); `animate.min.css` supplies the `animate__*` classes actually used by the wizard. Theme token values set by `applyLayout()` override the stylesheet's custom properties at the document root.

---

## 11. Known current limitations and asymmetries

1. **`Render.php` is not the UI.** `src/lib/render.php` escapes config into server-side `hcp-page`/`hcp-block` HTML with `empty`/`populated`/`error` modes, but its only production caller is `Scheduler::runSelfTest()`. The visible interface is entirely the vanilla-JS SPA; the self-test's "every page works" verdict is a `strlen($html) > 20` check, not an execution of the 14 JS views.
2. **`S.audit` is supplied by bootstrap.** The overview "Recent activity" and the audit view rely on `api.php` including `audit` in its `bootstrap` payload (last 200 rows). Earlier builds assembled this differently; do not remove that key.
3. **`window.HCP_CONFIG` is injected but unused.** `index.php` serialises the entire active config into it, while `app.js` reads configuration from `S.config` (bootstrap) and presets from `S.theme_presets`.
4. **`window.HCP_THEME_PRESETS` is read but never defined.** `applyLayout()` falls back to `S.theme_presets || {}`; the `HCP_THEME_PRESETS` branch is currently dead.
5. **Registry vs. UI surface mismatch.** `PageRegistry` has 32 pages (19 Hermes page ids); `NAV` has 14 views and `HERMES_AREAS` has 19 tiles. The two 19-item Hermes sets share 14 ids: the registry alone has `hermes-pairing`, `hermes-channels`, `hermes-system`, `hermes-docs`, `hermes-chat`; the frontend alone has `hermes-tools`, `hermes-themes`, `hermes-messaging`, `hermes-capabilities`, `hermes-git`. Meanwhile `HermesSources::catalog()` has 20 data sources — `hermes.session.get` has no UI tile.
6. **Search result navigation.** Only `kind === 'page'` has a real `data-goto`; agent/session/finding/audit/hermes results navigate back to the `search` view. The UI also has no "Audit" search tab although the backend accepts the `audit` kind.
7. **Trace display.** A successful `chat.send` toasts the number of tool calls and says "see session context", but `renderContext()` does not render the `trace` — the user sees `TOOL_RESULT:` lines in the stored transcript instead.
8. **Dead bindings.** `[data-save-layout]` is bound in `bindContentBase()` but no current view renders `#layout-json`; `#setup-check` ("Re-run checks") has no click handler; `customStyle` is declared and never used; `bindConfig()` ends with a no-op `forEach(() => {})` and a comment about approving the newest draft that is not implemented beyond the history buttons.
9. **First-run semantics.** `setup.status` and `setup.complete` both require authentication server-side, so the wizard is a post-login screen, not a truly unauthenticated onboarding flow.
10. **Voice target is fixed.** Transcribed text always goes to the `chat` agent; there is no target picker, and `getUserMedia` is unavailable over plain HTTP on non-local hosts in most browsers.
11. **Refresh cadence.** A 10-second `bootstrap()` keeps state fresh but replaces `#content.innerHTML` wholesale, so any in-progress form input outside the chat overlay is lost on refresh; the refresh is suspended while the wizard, drawer or chat overlay is open.
12. **Static artifacts.** `tailwind.css` claims generation from `src/templates/**`, which is absent from this checkout; the committed utilities are not referenced by `app.js`. `animate.min.css` is a hand-trimmed subset, not the full animate.css distribution.

No secrets, key material, credential values, or vault contents were used in producing this document.
