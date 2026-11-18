# Hermes Control Deck — Framework Rebuild: Implementation Plan

**Version:** 1.0.0
**Date:** 2026-11-18
**Status:** PLAN → TESTS → IMPLEMENT (strict order)
**Repository:** `/home/timo/programming/hermes-control-panel`
**Second repository (planned):** `hermes-control-panel-installer`

This document is the authoritative build plan. Work proceeds in this order:

1. Finish research (three reports under `docs/research/`).
2. Write this plan.
3. Write **500+ automated tests** that describe the target behaviour. They must fail before implementation.
4. Implement the framework and features until the full test suite is green.
5. Deploy, verify in a real browser, and push semantic commits to both repositories.

No systemd services are added. The runtime is one `docker compose` file with exactly two containers: `hermes` and `webui`.

---

<!-- PAGE BREAK -->

## Page 1 — Non-negotiable requirements

| ID | Requirement | Acceptance evidence |
|---|---|---|
| R1 | Light **and** dark mode, switchable live | Theme test matrix + browser screenshots |
| R2 | Multiple themes by default: rounded and square-corner variants, each light/dark | 4+ bundled theme presets; CSS variable tests |
| R3 | Deeper Brezel-style layout and functions | Research doc + visual comparison; hero, cards, drawer, tables, wizard |
| R4 | All Hermes features reachable or represented | Feature-coverage matrix; page/block registry contains every Hermes area |
| R5 | Deep personalisation; every part modifiable | Config-driven pages/blocks/themes; AI proposal → approval → apply flow |
| R6 | Modular framework, easy for an AI to edit | Versioned JSON config + schema + registries + diff/rollback API |
| R7 | Tailwind CSS + animate.css, no CDN at runtime | Committed compiled CSS, vendored animate.css, no external asset requests |
| R8 | Local STT for audio input | Embedded whisper.cpp HTTP loopback + mic UI + offline test |
| R9 | Prompts work with casual, unprofessional language | Prompt normaliser + response-quality fixtures |
| R10 | First-launch setup guide | Gated wizard, idempotent, health checks |
| R11 | Second repo with one-line install, two containers, no system services | Installer repo, compose validation, no-secret-printing tests |
| R12 | Scheduled AI job that tests the whole webui daily | Cron-style internal scheduler + self-test agent + report |
| R13 | 500+ tests written before implementation | `tests/` layer count report ≥ 500 |
| R14 | Existing security guarantees preserved | RBAC, HMAC, vault redaction, approval gating all still enforced |

---

<!-- PAGE BREAK -->

## Page 2 — Research inputs

Three research documents are produced before code changes:

1. `docs/research/brezel-deep-dive.md` — Brezel GitHub, exact layout, components, functions, "lava" investigation.
2. `docs/research/hermes-feature-inventory.md` — our current panel vs the full Hermes surface.
3. `docs/research/stt-framework-install.md` — local STT, Tailwind/animate.css, wizard, AI config governance, installer pattern.

Key facts already established from research:

- Our panel currently calls OpenCode on `:4097` directly; it does **not** talk to Hermes on `:8642`/`:9119`. This must change through a narrow, scrubbed proxy.
- Hermes exposes 264 dashboard/API paths, 9 WebSocket endpoints, 25 gateway capability endpoints, 26 toolsets, 39 skills and 19 built-in pages. The panel must represent these through registries, not raw passthrough.
- The current `webui_edit` applies layout changes *before* logging approval. This is reversed in the rebuild: proposal → preview → approval → apply.
- Local STT must live inside the `webui` container because compose is limited to two services. Recommended engine: whisper.cpp (`small.en-q5_0`, Vulkan with CPU fallback) on loopback, proxied by PHP.
- Tailwind is compiled ahead of time with the standalone CLI; animate.css is vendored. No CDN, no npm on target.

---

<!-- PAGE BREAK -->

## Page 3 — Product vision and UX principles

The rebuilt panel is a **digital team console**: one place to see every agent, every Hermes capability, and every approval, with a workspaces feel similar to Brezel's product UI.

Principles:

1. **One glance, one action.** The overview shows agents, sessions, approvals, findings, and Hermes health. Every card has one primary action.
2. **Code owns capability, config owns composition.** An AI may rearrange, rename, theme, add or remove registered pages and blocks. It may never inject code, HTML, arbitrary CSS, or unlisted data sources.
3. **Nothing privileged happens silently.** Any write, host action, secret use, or UI change is a proposal until the owner approves it.
4. **Casual input, good output.** The prompt layer accepts messy human phrasing and normalises it into a structured agent instruction without requiring the user to write like an engineer.
5. **Offline first.** The panel, themes, animation library, STT model, and model route stay on the Deck.
6. **Fail closed.** Unknown block/source/theme, invalid config, or failed smoke test rejects the whole change atomically.
7. **Reconstructible.** Every config revision is hash-linked, diffable, attributable, and rollback-able.

---

<!-- PAGE BREAK -->

## Page 3A — Exact Brezel design target (measured)

Research doc: `docs/research/brezel-deep-dive.md`. The public console stylesheet and product screenshot give exact tokens; we adopt these as our default `brezel-light-rounded` preset.

### Measured tokens

```css
--dash-ink:#172b40; --dash-ink-2:#4a5f75; --dash-muted:#7a8ca0;
--dash-line:#e6ebf1; --dash-surface:#fff; --dash-subtle:#f5f7fa;
--dash-accent:#ec8621; --dash-accent-soft: color-mix(in srgb, var(--dash-accent) 12%, #fff);
--dash-good:#1a9670; --dash-good-soft:#e5f4ee;
--dash-bad:#c4413a;  --dash-bad-soft:#fbeceb;
--dash-info:#3f78d8; --dash-info-soft:#e9f0fc;
--dash-plum:#a8508a; --dash-plum-soft:#f6eaf2;
--dash-radius:16px;
--dash-shadow: 0 1px 2px #172b400a, 0 8px 24px #172b400f;
```

### Layout anatomy to reproduce

| Element | Brezel measurement | Our implementation |
|---|---|---|
| App header | white, 64px, 24px padding, 1px `#f0f0f0` bottom border | topbar 64–70px, same borders |
| Sidebar rail | white, 1px `#dbe4ef`, radius 18px, 18px padding, 12px gap | 248px sidebar; active pill accent-soft |
| Right agent panel | 520px, full height, `border-top:3px solid accent`, 280ms slide-in, 14px resize handle | chat/drawer becomes 520px panel with orange top rule |
| Chat header | 61px, 38px avatar, 15px/700 title, 34px icon buttons | match |
| Chat bubbles | radius `6px 18px 18px 18px` (mirrored for own), 9px 13px padding, own = accent gradient/white text | match via theme tokens |
| Composer | radius 16px, focus ring 3px accent-soft, auto-grow ≤200px, 34px circular send | match |
| Staged first-run | left stage `min(680px,50vw)`, gradient `160deg,#f8f4ec,#eef2f5 55%,#e5ebf1`, right chat panel | wizard uses this split |
| Role/org cards | dark leadership card, white unit cards, teal agent cards, 7px pulsing live dot | agent cards adopt status dot and role styling |
| Responsive | ≤1100px chat becomes full-screen | Deck viewport handled explicitly |

### Function patterns to copy

- First-run staged setup chat with `@` agent mentions and `/` commands.
- Plain-language approval cards: orange border, exact summary, Approve / Decline / "Approve and stop asking for this run" (maps to our escalation TTL).
- Approval modes: Normal (high-risk only), Careful (every write), Auto (no pauses) — exposed in composer footer.
- Right-side agent panel with progress steps and "Show in app" links that jump to the relevant page.
- Semantic search across company knowledge → our unified search across pages/agents/sessions/findings/Hermes cache.
- Theme swatches (22+ colours) → our AI-editable token allowlist.

### "Lava" finding

No Brezel framework called Lava exists. Likely meant **Laravel** (Brezel backend) or **lava.so** (separate agent workspace with a strong approval-card UX). We copy the approval UX patterns, not an external gateway; no new external dependency is introduced.

---

<!-- PAGE BREAK -->

## Page 4 — Runtime architecture (two containers)

```
docker compose (one file, two services)
├── hermes
│   ├── image: nousresearch/hermes-agent:latest
│   ├── ports: 8642 (gateway API), 9119 (dashboard)
│   ├── volume: ./data/hermes -> /opt/data
│   └── env: from .env (keys only, never printed)
└── webui
    ├── image: built from ./webui/Dockerfile
    ├── ports: 9120 -> 80 (configurable via WEBUI_PORT)
    ├── volume: ./data/panel -> /var/lib/panel
    ├── volume: ./data/stt-models -> /opt/stt/models (read-only where possible)
    ├── supervises internally: nginx, php-fpm, scheduler tick, whisper-server
    └── reaches hermes via service name `hermes:8642` and `hermes:9119`
```

Notes:

- No `systemd` units, no third container. `restart: unless-stopped` is the only supervisor outside the webui image.
- The existing root-owned vault/host brokers remain host-side helpers attached by bind-mounted sockets; the installer treats them as optional capability flags. If missing, the panel still runs with `host.available=false`, `vault.available=false`.
- The webui container's internal process manager is `supervisord` inside the same container. It starts nginx, PHP-FPM, the scheduler loop, and the STT loopback server. This is not a system service.
- Health checks: `webui` uses `/health.php`; `hermes` uses `GET /health` on `:8642`.

---

<!-- PAGE BREAK -->

## Page 5 — Source tree after rebuild

```
hermes-control-panel/
├── docs/
│   ├── IMPLEMENTATION-PLAN.md                (this file)
│   └── research/                             (three research reports)
├── tests/
│   ├── bootstrap.php                          (harness, data providers, counters)
│   ├── suite.php                              (runs all layers, prints counts)
│   ├── unit/                                  (pure PHP unit cases)
│   ├── contract/                              (API + Hermes proxy shape cases)
│   ├── rbac/                                  (tool/agent/escalation matrix)
│   ├── config/                                (schema, proposal, approval, rollback)
│   ├── ui/                                    (page/block render smoke fixtures)
│   ├── install/                               (compose + installer repository checks)
│   └── live/                                  (optional Deck integration, opt-in)
├── src/
│   ├── lib/
│   │   ├── auth.php                           (existing, hardened)
│   │   ├── db.php                             (existing + new tables/migrations)
│   │   ├── util.php                           (existing + redaction helpers)
│   │   ├── registry.php                       (pages/blocks/sources/themes registries)
│   │   ├── config.php                         (versioned config, validation, proposals)
│   │   ├── schema.php                         (lightweight JSON-Schema validator)
│   │   ├── render.php                         (server-side page/block renderer)
│   │   ├── hermes.php                         (allowlisted Hermes proxy)
│   │   ├── search.php                         (unified search index)
│   │   ├── theme.php                          (theme presets and tokens)
│   │   ├── stt.php                            (loopback STT proxy + capability detect)
│   │   ├── prompt.php                         (casual-input normaliser)
│   │   ├── scheduler.php                      (internal cron + daily self-test)
│   │   ├── setup.php                          (first-launch wizard state machine)
│   │   ├── onboarding.php                     (sample agents/pages seed)
│   │   ├── rbac.php, engine.php, vault.php, hostctl.php   (existing, hardened)
│   │   └── blocks/                            (one renderer file per block type)
│   ├── public/
│   │   ├── index.php                          (shell; renders from config)
│   │   ├── setup.php                          (wizard entry)
│   │   ├── api.php                            (all owner API actions)
│   │   ├── health.php
│   │   ├── app.js                             (config-driven client)
│   │   ├── app.css                            (compiled Tailwind + tokens)
│   │   └── assets/vendor/animate.min.css
│   ├── templates/                             (PHP page/block templates scanned by Tailwind)
│   └── worker.php                             (scheduler entry point)
├── webui/Dockerfile
├── docker-compose.yml                         (exactly two services)
├── nginx.conf, supervisord.conf, entrypoint.sh
├── tools/build-css.sh                         (pinned Tailwind standalone CLI)
└── deploy/install.sh                          (legacy Deck deploy helper; kept)
```

---

<!-- PAGE BREAK -->

## Page 6 — Data model additions

New SQLite tables (all created idempotently in `db.php` migrations):

```sql
CREATE TABLE IF NOT EXISTS ui_config_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schema_version INTEGER NOT NULL,
  revision INTEGER NOT NULL UNIQUE,
  parent_revision INTEGER,
  data TEXT NOT NULL,
  hash TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'draft',       -- draft|proposed|active|rejected|rolled_back
  author TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  approval_id INTEGER,
  test_result TEXT,
  created_at INTEGER NOT NULL,
  activated_at INTEGER
);

CREATE TABLE IF NOT EXISTS ui_config_proposals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  revision_id INTEGER NOT NULL,
  diff TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',    -- pending|approved|denied|expired
  decided_by TEXT,
  decided_at INTEGER,
  expires_at INTEGER
);

CREATE TABLE IF NOT EXISTS hermes_cache (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  fetched_at INTEGER NOT NULL,
  ttl INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS stt_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  status TEXT NOT NULL,                      -- queued|done|error
  duration REAL,
  engine TEXT,
  error TEXT,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS search_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,                        -- page|agent|session|finding|audit|hermes
  ref_id TEXT NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS schedule_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job TEXT NOT NULL,
  status TEXT NOT NULL,
  detail TEXT,
  created_at INTEGER NOT NULL
);
```

Audit hardening: add `prev_hash`, `hash`, and an append-only trigger to `audit`. Every new audit row chains to the previous hash.

---

<!-- PAGE BREAK -->

## Page 7 — Registries

### Page registry

`src/lib/registry.php` defines built-in pages from Hermes areas plus existing panel pages. Each descriptor:

```php
[
  'id' => 'hermes-sessions',
  'route' => '/hermes/sessions',
  'nav' => ['label' => 'Hermes sessions', 'icon' => '▤', 'order' => 110, 'group' => 'Hermes'],
  'capabilities' => ['hermes.sessions.list'],
  'blocks' => [
    ['instance' => 'hermes-sessions-table', 'type' => 'table',
     'source' => 'hermes.sessions.list', 'props' => ['columns' => [...]]],
  ],
  'origin' => 'core',
]
```

Required page set (core + Hermes):

- Core: `overview`, `sessions`, `agents`, `approvals`, `security`, `vault`, `settings`, `audit`, `setup`, `search`.
- Hermes: `hermes-status`, `hermes-sessions`, `hermes-files`, `hermes-analytics`, `hermes-models`, `hermes-logs`, `hermes-cron`, `hermes-skills`, `hermes-plugins`, `hermes-mcp`, `hermes-pairing`, `hermes-channels`, `hermes-webhooks`, `hermes-profiles`, `hermes-config`, `hermes-env`, `hermes-system`, `hermes-docs`, `hermes-chat`, `voice`, `themes`, `self-test`.

### Block registry

Block types: `hero`, `kpi`, `cardGrid`, `table`, `form`, `jsonEditor`, `chat`, `timeline`, `chart`, `terminalView`, `configEditor`, `auditView`, `searchResults`, `wizardStep`, `voiceRecorder`, `themeGallery`, `statusStrip`.

Each block declares: `props_schema`, allowed `sources`, `risk`, `renderer`, fixture name, `ai_editable`.

### Source registry

Logical sources map to either panel actions or allowlisted Hermes endpoints, with: method, path, query/body allowlist, response field whitelist, scrub rules, cache TTL, required capability. Explicitly forbidden: `/api/env/reveal`, `/api/config/raw`, `/api/ops/debug-share`, `/api/files/ticket`, PTY attach tokens, OAuth callbacks.

---

<!-- PAGE BREAK -->

## Page 8 — Versioned config and AI editing

`config/site.json` (logical; persisted in SQLite revisions) shape:

```json
{
  "schema_version": 1,
  "revision": 1,
  "meta": {"title": "Hermes Control Deck", "locale": "en", "description": "..."},
  "theme": {
    "preset": "brezel-light-rounded",
    "mode": "light",
    "radius": "rounded",
    "tokens": {"--accent": "#ed8d30", "--navy": "#203448"}
  },
  "pages": [ /* ordered page descriptors from registry */ ],
  "feature_flags": {"hermes_proxy": true, "voice": true, "self_test": true},
  "prompts": {"style": "casual-ok", "normalise": true},
  "schedule": {"self_test": "daily 04:20"}
}
```

Governance pipeline (must be implemented exactly):

1. `ui.config.propose` — JSON Patch (`add|remove|replace`) against allowlisted paths; no mutation.
2. Server validates schema, registries, capability grants, CSS token allowlist, size limits, and runs render smoke tests on the resulting config.
3. Response is a preview: structured diff, resulting config hash, validation report.
4. Owner approves through `ui.config.approve` (or an escalation for agents). Approval id is recorded on the revision.
5. Apply atomically: write new active revision, checkpoint previous revision, append audit row with hash.
6. `ui.config.rollback` activates any previous active revision, also audited.
7. `ui.config.history` and `ui.config.diff` are AI-readable review endpoints.

CSS token values are regex-validated: colours `^#[0-9a-fA-F]{3,8}$`, lengths `^\d+(px|rem|em)$`, fonts from an allowlist. Reject `url(`, `@import`, `expression(`, `<`, `javascript:`, `</style`. No raw `custom_css` survives the migration.

---

<!-- PAGE BREAK -->

## Page 9 — Themes and design system

Bundled presets (all light/dark × rounded/square):

| Preset | Mode | Radius | Character |
|---|---|---|---|
| `brezel-light-rounded` | light | 16–18px | default; measured tokens `#172b40` ink, `#ec8621` accent, `#e6ebf1` hairline, `#f6f7f9` chat surface |
| `brezel-dark-rounded` | dark | 16–18px | navy-ink canvas, slate cards, same orange accent and semantic tints |
| `brezel-light-square` | light | 0px | same measured palette, sharp corners |
| `brezel-dark-square` | dark | 0px | dark palette, sharp corners |
| `midnight` | dark | 12px | deep blue, cyan accent |
| `ember` | light | 14px | warm cream, burnt-orange accent |
| `mono` | light | 0px | grayscale, accessibility focus |

Implementation:

- Tailwind v4 compiled CSS commits utility classes plus `@theme` custom properties.
- Theme switching sets `html[data-theme]` and `html[data-radius]`; tokens cascade through CSS variables.
- `theme.php` exposes presets and validates token overrides.
- Theme gallery page renders every preset live with a sample card.
- User choice persists through `ui.config.propose` (owner) or local preference for non-destructive display options.

---

<!-- PAGE BREAK -->

## Page 10 — First-launch setup wizard

Route `/setup.php`, gated by `data/setup.lock`. Every other route redirects here until complete.

Steps:

1. **Welcome & requirements** — PHP 8.3, SQLite, writable data dir, Docker reachability, Hermes health, STT model presence, broker availability. Render pass/warn/fail per requirement with remediation text.
2. **Environment** — base URL, port, Hermes endpoints (service names by default), language, theme, radius, STT profile. Generated secrets are written root-only; values are never displayed.
3. **Hermes connection** — probe `hermes:8642/health` and `/v1/models` with backend key; capability flags recorded. No secrets in the browser response.
4. **Owner account** — username/password; password written to `panel.env` equivalent; session started.
5. **Personalise** — choose theme, density, card order, optional sample agents/pages.
6. **Done** — write lock file, run first self-test, redirect to overview.

Idempotent: re-running detects completed steps and skips them; `--unattended` mode reads env vars for automated installs.

---

<!-- PAGE BREAK -->

## Page 11 — Local STT and voice page

Engine: whisper.cpp HTTP server on `127.0.0.1:8713` inside the `webui` container.

- Model: `ggml-small.en-q5_0` (~160 MB) default, `ggml-base.en-q5_0` for low-resource mode, optional `ggml-large-v3-turbo-q5_0` if already cached.
- GPU: `--device vulkan -ngl 99` when `/dev/dri` present; CPU fallback `-t 4`.
- Browser: `getUserMedia` + `MediaRecorder` (webm/opus) or PCM/WAV capture; push-to-talk only; release tracks on stop.
- PHP `/api/stt`: validates content type and size (≤ 10 MB), writes temp file, posts to loopback engine, returns `{text,duration,engine}`; deletes temp file in a `finally` block; logs duration only, never transcript.
- Voice page shows: mic button, live state, transcript preview, "send to agent" action, engine/model status, delete cached audio.
- Capability flag: if engine missing, page shows setup instructions and disables recording; no cloud fallback by default.

---

<!-- PAGE BREAK -->

## Page 12 — Prompt normaliser for casual language

`prompt.php` implements a deterministic template that converts any user text into a structured instruction for the model:

```
ROLE: <agent role>
USER_INPUT: <raw text, unmodified>
INTERPRET_AS: a request from a non-technical owner. Infer intent; do not require exact commands.
STYLE: answer in plain language, short, concrete, no jargon unless asked.
SAFETY: never reveal secrets; describe privileged actions before asking for approval.
OUTPUT: first line = plain answer; then optional "Next steps" list.
```

Rules:

- Raw text is preserved verbatim inside `USER_INPUT`; no silent rewriting of intent.
- Users may type "make it darker", "show me what the deck is doing", "check if anything bad happened overnight" — the normaliser adds context, not constraints.
- Response-quality fixtures include casual, misspelled, and one-word prompts; tests assert the reply is non-empty, plain-language, and contains no secret markers.
- Per-agent `prompt_style` config: `plain` (default), `concise`, `detailed`.

---

<!-- PAGE BREAK -->

## Page 13 — Scheduled self-test of the webui

Requirement: a daily AI job that tests the entire webui.

- Internal scheduler loop in the `webui` container (`worker.php tick`) reads `schedule.self_test` from config (default `daily 04:20`).
- Job `webui.selftest`:
  1. Probes `/health.php`, `me`, `bootstrap`, every registered page's render smoke, every registered source's fixture.
  2. Launches the `webui` agent with a normalised prompt: "Check every page and report anything broken, in plain language."
  3. Writes results to `schedule_runs` and a `findings` row if failures exist.
  4. Never applies fixes; fixes always require owner approval.
- UI: `self-test` page shows last run, duration, pass/fail per page, and a "Run now" button.
- Tests assert: schedule parsing, idempotent run, report row created, no direct config mutation, findings only on failure.

---

<!-- PAGE BREAK -->

## Page 14 — Search

Unified search across:

- page titles/descriptions from the page registry,
- agents and their roles/prompts,
- session titles and recent messages,
- findings and audit events,
- Hermes cached sources,
- config keys and docs.

Implementation:

- `search.php::index()` rebuilds `search_docs` from all sources with ≤ 20 KB per document.
- `api action search.query` accepts `q`, optional `kinds`, returns ranked results with highlighted snippets; all snippets escaped and redacted with `Util::scrub()`.
- Client: topbar search field with `/` shortcut; results page is a registered block `searchResults`.
- Tests: injection strings, secret markers, empty query, rank ordering, max results, permissions filter.

---

<!-- PAGE BREAK -->

## Page 15 — Hermes integration proxy

`src/lib/hermes.php`:

- Reads `HERMES_URL` (default `http://hermes:8642`) and `HERMES_DASHBOARD_URL` (`http://hermes:9119`) from env.
- Uses backend-only key; browser never sees it.
- Every source call goes through: capability check → endpoint allowlist → method/body allowlist → response field whitelist → recursive scrub → cache.
- Gateway capability flags are fetched from `/v1/capabilities` and cached; unavailable features are hidden/disabled in the UI rather than erroring.
- Dashboard deep links are generated server-side into page blocks; single sign-on is explicitly out of scope for v1 but documented.

Source groups (curated): status, sessions, models, tools, skills, cron, analytics, themes, config schema, env names, messaging platforms, webhooks, gateway capabilities, logs, files/git metadata, profiles, plugins, MCP, ops status.

Forbidden surfaces are listed in the source registry and covered by tests.

---

<!-- PAGE BREAK -->

## Page 16 — Security model (unchanged guarantees + fixes)

Preserved:

- Owner-only sessions; state-changing calls check `Origin`.
- Agents have no shell; tool RBAC in PHP before any backend call.
- Vault exposes only `list` and `use`; no `get`; values injected by root broker; double scrubbing.
- Host actions are fixed IDs, regex-validated args, HMAC signed by root-only key, one-shot escalations.
- Default agents cannot be deleted; new agents start with zero permissions.

Fixed in rebuild:

- `webui_edit` no longer applies directly; it proposes a config patch requiring approval.
- `layout.save` is replaced by schema-validated `ui.config.propose/approve`.
- CSRF double-submit token on all POST actions; login rate limit by IP+user.
- Model string validated against cached `/v1/models` allowlist.
- Audit table append-only with hash chain.
- `state.diff` replaces whole-state polling where possible; full bootstrap remains for first load.
- All Hermes proxy responses are field-whitelisted; secret-bearing endpoints are never proxied.

---

<!-- PAGE BREAK -->

## Page 17 — Installer repository design

Second repo `hermes-control-panel-installer` contains only:

```
install.sh
docker-compose.yml.tpl
.env.example
README.md
```

One-line install:

```sh
curl -fsSL https://<forgejo>/Alfires/hermes-control-panel-installer/raw/branch/main/install.sh | bash
```

Installer rules:

- `set -euo pipefail`, `main "$@"` as last line, `mktemp -d` + cleanup trap.
- Checks Docker + compose plugin; explains remediation; never installs packages silently.
- Clones pinned app repo/tag into `~/.local/hermes-control-panel` (or `$INSTALL_DIR`).
- Generates `.env` only if absent; distinct `openssl rand -hex 32` secrets; `umask 077`, `chmod 600`; logs key names only.
- Renders compose from template with `${VAR:?...}` requirements; validates with `docker compose config`.
- Brings up two services, waits for health, prints URL and "secrets written to … (chmod 600)".
- Re-run is idempotent; `--status`, `--upgrade`, `--uninstall` flags.
- No systemd unit, no third container, no secret printed.

---

<!-- PAGE BREAK -->

## Page 18 — Test strategy: 500+ cases before code

Tests are written first with a custom zero-dependency PHP harness (`tests/bootstrap.php`) that supports data providers and counts cases. Layers:

| Layer | Directory | Target cases | What it proves |
|---|---|---:|---|
| L0 | `unit/` | 120 | redaction, parsing, theme tokens, config schema primitives |
| L1 | `contract/` | 100 | panel actions + Hermes source shapes |
| L2 | `rbac/` | 140 | tools × agents × escalation states, vault grants |
| L3 | `config/` | 70 | proposals, approvals, rollback, AI-edit rejection |
| L4 | `ui/` | 90 | page/block render smoke for all pages/themes |
| L5 | `install/` | 40 | compose two-service rule, installer safety, no secrets |
| L6 | `live/` | 20 | optional Deck checks (skipped by default) |
| **Total** | | **≥ 560** | |

Rules:

- No test may require the Deck or network by default; live tests are opt-in via `RUN_LIVE=1`.
- Tests use synthetic secrets such as `TEST-TOKEN-DO-NOT-USE`; no real credentials.
- Every test has a stable ID and assertion message.
- `tests/suite.php` prints `TOTAL: n cases, p passed, f failed` and exits non-zero on failure.
- Implementation is not accepted until `f = 0` and `n ≥ 500`.

---

<!-- PAGE BREAK -->

## Page 19 — Implementation phases

### Phase A — Foundation (tests already written)
- Add registries, config schema, config revisions, proposal/approval/rollback, audit hash chain.
- Add CSRF, rate limit, state.diff.
- Acceptance: L0–L3 green.

### Phase B — Framework UI
- Config-driven nav/pages/blocks, server-side renderers, Tailwind build, animate.css, theme presets, search.
- Acceptance: L4 green; browser screenshot of overview in all 4 primary themes.

### Phase C — Hermes proxy
- Implement allowlisted sources, capability flags, caching, Hermes pages.
- Acceptance: L1 Hermes contract cases green; no forbidden endpoints reachable.

### Phase D — Voice, wizard, scheduler
- STT loopback, voice page, setup wizard, self-test scheduler, prompt normaliser.
- Acceptance: L5/L6 green; wizard completes on a clean data dir; self-test produces a report.

### Phase E — Installer repo and deployment
- Create second Forgejo repo, one-line installer, two-service compose, deploy, browser verify.
- Acceptance: compose has exactly two services; installer re-run is idempotent; live smoke passes.

---

<!-- PAGE BREAK -->

## Page 20 — File-by-file change list

| File | Change |
|---|---|
| `docs/IMPLEMENTATION-PLAN.md` | This plan |
| `docs/research/*.md` | Research reports |
| `tests/bootstrap.php`, `tests/suite.php` | New harness + runner |
| `tests/unit/*`, `tests/contract/*`, `tests/rbac/*`, `tests/config/*`, `tests/ui/*`, `tests/install/*`, `tests/live/*` | 500+ cases |
| `src/lib/db.php` | New tables + audit hash chain |
| `src/lib/registry.php`, `config.php`, `schema.php`, `render.php`, `hermes.php`, `search.php`, `theme.php`, `stt.php`, `prompt.php`, `scheduler.php`, `setup.php`, `onboarding.php` | New framework modules |
| `src/lib/blocks/*.php` | Block renderers |
| `src/public/index.php`, `setup.php`, `api.php`, `health.php`, `app.js`, `app.css` | Config-driven shell, wizard, new APIs, compiled CSS |
| `src/public/assets/vendor/animate.min.css` | Vendored animation library |
| `src/templates/**` | Tailwind-scanned templates |
| `webui/Dockerfile`, `supervisord.conf`, `entrypoint.sh`, `nginx.conf`, `docker-compose.yml` | Two-container runtime + embedded STT |
| `tools/build-css.sh` | Pinned Tailwind standalone build |
| `deploy/install.sh` | Kept for legacy Deck deploy; adapted if needed |
| Second repo `hermes-control-panel-installer` | One-line installer + compose template |

---

<!-- PAGE BREAK -->

## Page 21 — Risks and mitigations

| Risk | Mitigation |
|---|---|
| 500+ tests become shallow | Data providers generate real matrix cases; each layer asserts distinct behaviour; implementation must make all green |
| Two-container limit breaks STT | Embed whisper.cpp inside webui; no third service |
| Config-driven UI becomes unsafe | Schema + registries + allowlists + preview/approval/audit + rollback |
| Hermes proxy leaks secrets | Field whitelists, forbidden endpoint list, recursive scrub, contract snapshots |
| Tailwind CDN forbidden | Compile and commit CSS; vendor animate.css |
| Daily self-test mutates live state | Read-only checks; fixes only as proposals |
| Installer prints secrets | Generate silently, chmod 600, log key names only; installer tests assert no value output |
| Existing deployment disrupted | Keep legacy deploy helper; new compose validated before switching; live checks opt-in |

---

<!-- PAGE BREAK -->

## Appendix — Commands

```sh
# tests (before and after implementation)
cd /home/timo/programming/hermes-control-panel
php tests/suite.php

# CSS build (dev machine; committed output, no npm on target)
bash tools/build-css.sh

# deploy to the existing Deck (legacy helper)
DECK_SUDO_PASS=... bash deploy/deck.sh --sudo 'bash /opt/hermes-control-panel/deploy/install.sh'

# second repo install (after publication)
curl -fsSL <installer-raw-url>/install.sh | bash

# verify runtime
docker compose ps
curl -fsS http://127.0.0.1:9120/health.php
curl -fsS http://127.0.0.1:8642/health
```

---

<!-- PAGE BREAK -->

## Page 22 — Git version history for agent changes (standing rule)

Every change made by an agent — code, config, docs, UI proposals that become
active, tests, and deploy scripts — must leave a version-history trail:

1. Source changes are committed to the Forgejo repo with **semantic commit
   messages** only (`feat:`, `fix:`, `style:`, `test:`, `docs:`, `chore:`,
   `refactor:`, `perf:`). No agent turn ends with uncommitted source edits.
2. Pushes happen only after the semantic commit is written; the commit message
   must name the behaviour changed, not just “update” or “wip”.
3. AI-editable UI changes additionally create a versioned config revision with
   parent revision, hash, approval record and rollback path (see Page 10).
4. The canonical checklist lives in `docs/TODO.md`; it is itself versioned in
   git, so scope changes are auditable.

