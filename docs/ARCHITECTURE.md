# Hermes Control Panel — System Architecture

**Purpose.** This document describes how the Hermes Control Panel is actually assembled from the code in this repository: the container topology and external brokers, the HTTP request lifecycle and its security gates, the SQLite state, the config-driven page/block registry and its governance state machine, the permission-gated agent runtime loop, the search index, the scheduler/worker, the loopback speech-to-text path, the curated Hermes proxy, and the trust boundaries that keep model output away from root-owned capabilities. It also includes two end-to-end walkthroughs (a chat message and a UI edit) and the complete 32-page / 20-source registries. It is a description of the implementation, not a roadmap.

**Last verified:** `0137eff test: add regressions for deep-debug fixes` (`git log -1 --oneline`, read-only, at time of writing).

---

## 1. Topology at a glance

`docker-compose.yml` declares **two services**, but only one of them normally runs in production:

- `webui` (`hermes-panel`) — this panel. Always started; maps `${WEBUI_PORT:-9120}:8080`.
- `hermes` (`hermes-agent`) — a **standalone option** behind the compose profile `standalone`; a plain `docker compose up -d` does *not* start it because the production Deck already runs the gateway from `/opt/hermes` on ports 8642/9119. Starting both would collide on those ports.

So the README's phrase "single-container control panel" and the "two containers" wording describe the same deployment: one active panel container, plus an optional second compose service. Everything else below is **external** to compose.

```
        owner's browser (LAN / Tailscale)
                    │  HTTP :9120
                    ▼
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ container hermes-panel (webui) — php:8.3-fpm-alpine, uid/gid 1000             │
 │                                                                              │
 │  nginx :8080 ──fastcgi 127.0.0.1:9000──► PHP-FPM (dynamic, 16 max children)   │
 │   ├─ static: /assets/vendor/animate.min.css, /tailwind.css, /app.css, /app.js │
 │   ├─ / ─► index.php        (owner login shell + CSRF cookie + SPA skeleton)   │
 │   ├─ /api.php?action=…     (all state changes and data reads)                 │
 │   └─ /health.php           (unauthenticated liveness)                         │
 │                                                                              │
 │  supervisord: php-fpm (10), nginx (20), stt (15), crond (31)                  │
 │  cron: tick 1m · agents 5m · security daily 03:15  (worker.php)               │
 │  SQLite: /var/lib/panel/panel.db (WAL, host volume ./data)                     │
 └──────┬────────────┬──────────────────┬────────────────────┬────────────────────┘
        │            │                  │                    │
        │            │                  │                    └─► whisper.cpp, loopback
        │            │                  │                        127.0.0.1:8713 /inference
        │            │                  │                        (in-container, model :ro mount)
        │            │                  │
        │            │                  └─► two root-owned brokers via /opt/panel-sockets relay
        │            │                      vault.sock  → /run/vault-broker.sock   (external)
        │            │                      hostctl.sock → /run/panel-host.sock     (external, root:deck 0660)
        │            │
        │            └─► Hermes gateway, external: HERMES_URL (default http://hermes:8642)
        │                dashboard HERMES_DASHBOARD_URL (default http://hermes:9119)
        │
        └─► model /v1/chat/completions — PANEL_LLM_URL, default
            http://host.docker.internal:4097/v1/chat/completions
            (the local OpenCode API; PANEL_LLM_KEY as bearer token)
```

The webui container's mounts are the trust-relevant part of the topology:

| Mount | Direction | Why |
|---|---|---|
| `./data:/var/lib/panel` | rw | SQLite database + WAL files + `setup.lock`; host-side persistence |
| `./data/stt-models:/opt/stt/models:ro` | ro | whisper.cpp GGML models, never writable by the panel |
| `/opt/panel-sockets:/run/panel-sockets` | rw (directory) | stable relay sockets; directories survive broker restarts, socket files do not |
| `/opt/panel-secrets/hostctl.key:/run/hostctl-src/hostctl.key:ro` | ro | root-only HMAC key, re-installed by the entrypoint as uid 1000 mode `0400` |

`entrypoint.sh` fixes ownership of the data volume for the FPM user, copies the HMAC key into `/run/secrets/hostctl.key` (never under the web root), then executes supervisord. `Dockerfile` runs FPM as uid/gid 1000 with `clear_env = no` so `PANEL_*` variables stay available, and nginx sets `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `server_tokens off`, a 2 MiB body limit, and denies all dotfiles except `.well-known`.

---

## 2. Request lifecycle: browser → nginx → PHP-FPM → API → RBAC → broker/model

Every UI read and write goes through a single front controller, `src/public/api.php`. It is intentionally small: a switch on `$_GET['action']`, with all policy in libraries.

```
  app.js api(action, data)
     │  GET or POST /api.php?action=…  (verb from window.API_METHODS;
     │  GET args in query, POST args as JSON body; credentials: same-origin,
     │  X-CSRF-Token from the non-HttpOnly panel_csrf cookie)
     ▼
  nginx :8080  →  fastcgi 127.0.0.1:9000  →  PHP-FPM  →  api.php
     │
     ├─ 1. Auth::ensureOwner()          sync PANEL_ADMIN_USER/PASSWORD into users;
     │                                  fails 500 if no admin password configured
     ├─ 2. Origin guard                 any non-login/me request with an Origin header
     │                                  must match Host (scheme-independent) → 403
     ├─ 3. Double-submit CSRF           panel_csrf cookie === X-CSRF-Token, hash_equals
     │                                  (all actions except login/me) → 403
     ├─ 4. Auth::require() per case     token cookie → hashed lookup in logins (12 h TTL)
     ├─ 5. Input coercion              Util::body()/Util::str()/allowlists
     ├─ 6. Domain library               Engine / ConfigGovernance / Vault / HostCtl /
     │                                  Hermes / Search / STT / Setup / Scheduler
     └─ 7. Util::json()                 application/json, Cache-Control: no-store, exit
```

Notes on the gates:

- `login` is rate-limited per IP **and** username with a 15-minute window stored alongside the counter (10 attempts). Success removes the counter; a lockout therefore expires instead of lasting forever.
- Session cookies are `HttpOnly`, `SameSite=Lax`, 12 hours, and `Secure` only when HTTPS is detected. The CSRF cookie is deliberately **not** HttpOnly because `app.js` must echo it into a header.
- `me` is the only unauthenticated read (`{authenticated: bool}`); `health.php` is also public. `setup.status` and `setup.complete` call `Auth::require()` despite comments in the UI suggesting a first-run flow — the first-run screen is only shown *after* login.
- `Auth::ensureOwner()` re-hashes the configured owner password into `users` on mismatch and writes an audit event; no credential value is ever logged.
- `PanelActions::catalog()` in `registry.php` declares 35 actions with inferred GET/POST methods, capability names, `owner_only`, and an id. It is a capability catalogue/contract, **not** the router: the live switch in `api.php` also implements `layout.save`, `agent.message`, `panel.host.probe`, and `panel.redaction.probe`, which are absent from that catalogue. Treat `api.php` as authoritative.

**`bootstrap`** is the single hydration call. On success it returns agents, `RBAC::TOOLS`, sessions (100), pending approvals, escalations, open findings (100), **audit (200)**, messages (80), the layout row, active config, theme presets, setup status/requirements, STT status, vault availability + secret *names*, host broker availability, and Hermes availability. `S.audit` in the frontend is supplied here — it is no longer assembled client-side.

---

## 3. State: SQLite schema and the append-only audit chain

`src/lib/db.php` owns a single PDO SQLite connection (`PRAGMA journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout=5000`) and migrates on first open. Database path: `PANEL_DB` → `PANEL_DATA/panel.db` → `/var/lib/panel/panel.db`.

| Table | Role |
|---|---|
| `users`, `logins` | owner account and hashed session tokens |
| `agents`, `agent_sessions` | tool grants, vault name grants, model, schedule, conversation JSON |
| `escalations` | one-time privileged grants: capability, TTL, `uses_left`, `token_hash` |
| `approvals` | recorded `host_apply` / `webui_edit` proposals with payload JSON |
| `messages` | inter-agent inbox, opt-in via `can_message`, with `read_at` |
| `findings`, `layout` | security scan results; owner/agent-editable key/value layout |
| `audit` | append-only log with `prev_hash` + `hash` |
| `ui_config_revisions`, `ui_config_proposals` | versioned config governance state machine (§5) |
| `hermes_cache` | TTL cache of curated Hermes data |
| `stt_jobs`, `search_docs`, `schedule_runs` | transcription log, inverted search corpus, job history |

**Audit hash chain.** `DB::audit()` scrubs and truncates the detail, reads the last row's `hash` as `prev`, and stores `sha256(prev|actor|action|detail|created_at)`. `verifyAuditChain()` walks the whole table and re-computes each link. `migrate()` adds the two columns in place for legacy decks and backfills hashes so the chain remains verifiable. Config revisions have a separate content hash: `ConfigGovernance::hash()` is `sha256(json_encode(config))` with the `revision` key removed, so identical content hashes identically regardless of revision number.

---

## 4. Config-driven pages and blocks

`src/lib/registry.php` is the declaration layer: "code owns capability; configuration only composes what is registered here."

- **`PageRegistry::builtin()`** returns **32 pages** in three groups. Each page has `id`, `route` (`/` for overview, `/<id>` otherwise), `nav` (label via `ucwords(str_replace('-', ' ', $id))`, icon, order in steps of 10, group), `capabilities: ['panel.bootstrap']`, one default `table` block sourced from `panel.bootstrap`, and `origin: 'core'`.
- **`BlockRegistry::catalog()`** declares **17 block types**: `hero`, `kpi`, `cardGrid`, `table`, `form`, `jsonEditor`, `chat`, `timeline`, `chart`, `terminalView`, `configEditor`, `auditView`, `searchResults`, `wizardStep`, `voiceRecorder`, `themeGallery`, `statusStrip`. All are `ai_editable` except `terminalView` and `configEditor`, which are `risk: privileged`.
- **`Schema::validateConfig()`** enforces `schema_version = 1`, the eight top-level keys (`schema_version`, `revision`, `meta`, `theme`, `pages`, `feature_flags`, `prompts`, `schedule`), slug/route safety, block types and sources, five feature flags, prompt styles, schedule syntax, and injectable-marker rejection (`<script`, `onerror=`, `javascript:`, `@import`, `url(`, …). `Theme::validateTokens()` additionally regex-limits token names/values. Block sources are restricted to `panel.bootstrap|agents|sessions|findings|audit|approvals|config` plus curated Hermes source ids; `Schema::BLOCK_TYPES` mirrors `BlockRegistry`.

### The 32 registered pages

| # | Page id | Route | Group | Purpose (from nav label / UI area) |
|---|---|---|---|---|
| 1 | `overview` | `/` | Core | Landing dashboard: agent ranks, KPIs, recent activity |
| 2 | `sessions` | `/sessions` | Core | All agent sessions with full context/chat |
| 3 | `agents` | `/agents` | Core | Agent roster, permissions, ranks, scopes |
| 4 | `approvals` | `/approvals` | Core | One-time escalations and recorded privileged actions |
| 5 | `security` | `/security` | Core | Exploit watch, findings, proposed fixes |
| 6 | `vault` | `/vault` | Core | Secret names and policies (never values) |
| 7 | `settings` | `/settings` | Core | AI-editable config, proposals, history, rollback |
| 8 | `audit` | `/audit` | Core | Append-only decision/action trail |
| 9 | `setup` | `/setup` | Core | Endpoints, brokers, requirements, operator commands |
| 10 | `search` | `/search` | Core | Unified search over pages/agents/sessions/findings/audit/Hermes |
| 11 | `hermes-status` | `/hermes-status` | Hermes | Gateway health/version/uptime |
| 12 | `hermes-sessions` | `/hermes-sessions` | Hermes | Hermes-side sessions |
| 13 | `hermes-files` | `/hermes-files` | Hermes | File metadata (path/size/modified) |
| 14 | `hermes-analytics` | `/hermes-analytics` | Hermes | Requests/tokens/errors |
| 15 | `hermes-models` | `/hermes-models` | Hermes | Model options and context lengths |
| 16 | `hermes-logs` | `/hermes-logs` | Hermes | Log level/message/timestamp |
| 17 | `hermes-cron` | `/hermes-cron` | Hermes | Cron jobs, schedule, enabled |
| 18 | `hermes-skills` | `/hermes-skills` | Hermes | Registered skills |
| 19 | `hermes-plugins` | `/hermes-plugins` | Hermes | Installed plugins and enabled state |
| 20 | `hermes-mcp` | `/hermes-mcp` | Hermes | MCP servers and connection state |
| 21 | `hermes-pairing` | `/hermes-pairing` | Hermes | Pairing surface |
| 22 | `hermes-channels` | `/hermes-channels` | Hermes | Messaging channels |
| 23 | `hermes-webhooks` | `/hermes-webhooks` | Hermes | Webhook registrations |
| 24 | `hermes-profiles` | `/hermes-profiles` | Hermes | Profiles |
| 25 | `hermes-config` | `/hermes-config` | Hermes | Hermes config schema |
| 26 | `hermes-env` | `/hermes-env` | Hermes | Environment variable *names* |
| 27 | `hermes-system` | `/hermes-system` | Hermes | System/capability information |
| 28 | `hermes-docs` | `/hermes-docs` | Hermes | Documentation surface |
| 29 | `hermes-chat` | `/hermes-chat` | Hermes | Hermes chat surface |
| 30 | `voice` | `/voice` | Workspace | Local push-to-talk transcription |
| 31 | `themes` | `/themes` | Workspace | Theme preset gallery |
| 32 | `self-test` | `/self-test` | Workspace | Daily page/source self-test |

The browser does **not** render one view per registered page. `app.js` has 14 SPA views; its single `hermes` view presents the Hermes pages as a tabbed area grid (see `docs/FRONTEND.md`). The registry's 19 Hermes page ids and the frontend's 19 Hermes area tiles overlap in 14 ids; the registry additionally lists `hermes-pairing`, `hermes-channels`, `hermes-system`, `hermes-docs`, `hermes-chat`, while the frontend adds `hermes-tools`, `hermes-themes`, `hermes-messaging`, `hermes-capabilities`, `hermes-git`.

Server-side `Render::page()` escapes all config output and can emit `empty`/`populated`/`error` modes, but in the running product it is used only by the self-test — the visible UI is the vanilla-JS SPA.

---

## 5. Governance: proposal → approval → activation → rollback

Only the owner may activate a change; agents and the owner can *propose* through the same path. Implemented in `src/lib/config.php` (`ConfigGovernance`) with `src/lib/schema.php` as validator.

```
  propose(patches, author)                 api: ui.config.propose / theme.set / drawer ui-edit
     │  1-100 JSON patches {op: add|replace|remove, path, value}
     │  path must start '/', ≤12 segments, root ∈ {meta,theme,pages,feature_flags,prompts,schedule},
     │  segments /^[A-Za-z0-9_-]+$/, no removing a top-level section
     ▼
  apply patches to active config → Schema::validateConfig() → nextRevision() → sha256 content hash
     │
     ├─ INSERT ui_config_revisions(..., state='draft', parent_revision=current)
     └─ INSERT ui_config_proposals(revision_id, diff, status='pending', expires_at=now+86400)
     ▼
  approve(proposalId, actor)               api: ui.config.approve
     │  pending proposal + draft revision required
     │  UPDATE revisions SET state='superseded' WHERE state='active'
     │  UPDATE this revision → state='active', approval_id, activated_at
     │  UPDATE proposal → status='approved', decided_by, decided_at
     ▼
  active config                            api: bootstrap, state.diff, theme application
     │
     └─ rollback(revisionNumber, actor)    api: ui.config.rollback
        UPDATE current active → 'rolled_back'; target revision → 'active'
        (revision numbers are append-only; nextRevision() is max(active, MAX(ever))+1)
```

Every transition writes an `audit` row through `DB::audit()`, so the governance trail feeds the same SHA-256 hash chain as tool calls and logins. Diffs are produced by flattening both configs to dotted keys and emitting `- key: value`, `~ key: old -> new`, `+ key: value` lines, with values scrubbed and capped at 80 characters.

`theme.set` is sugar over this pipeline: it proposes four `replace` patches (`/theme/preset`, `/theme/mode`, `/theme/radius`, `/theme/tokens`) and immediately approves its own proposal as owner. There are seven presets (`brezel-light-rounded`, `brezel-dark-rounded`, `brezel-light-square`, `brezel-dark-square`, `midnight`, `ember`, `mono`); the default is `brezel-light-rounded`.

A parallel, older governance path exists for host changes: `Engine::hostApply()` inserts an `approvals` row and the broker still enforces its HMAC + allowlist, so an approval alone never changes the host.

---

## 6. Agent runtime loop (model emits `TOOL_CALL`, engine parses/checks/scrubs)

The model has no callable tools. It receives a system prompt listing only the tools this agent is *allowed to ask for*, plus `request_escalation` and `noop`, and the exact protocol line `TOOL_CALL: {"name":"…","args":{…}}`. `Engine::chat()` runs at most seven model turns.

```
     user message (scrubbed, ≤6000 chars) appended to session messages
                    │
        ┌───────────▼──────────────────────────────────────────────────┐
        │  callModel(messages, agent.model, max_tokens=1400, temp=0.3) │
        │  PANEL_LLM_URL / Bearer PANEL_LLM_KEY, 180 s timeout          │
        │  reply text is passed through Util::scrub()                  │
        └───────────┬──────────────────────────────────────────────────┘
                    ▼
        parseToolCall(reply)   ← TOOL_CALL line, ```json fenced JSON, or bare JSON
                    │
          no call ──┴── call
             │            ▼
             │      executeTool(agent, name, args)
             │        ├─ name lowercased/trimmed
             │        ├─ request_escalation → RBAC::requestEscalation (capability, reason, ttl 30–3600)
             │        ├─ noop → ok
             │        ├─ RBAC::can(agent, tool, args): known tool? granted in agent.tools?
             │        │     vault_use → named grant OR active escalation for 'vault:<name>'
             │        │     message_agent → not self, can_message set
             │        │     risk=privileged → active one-time escalation required
             │        │     on deny → audit tool.denied, return error (never touches backend)
             │        ├─ dispatch: chat / notes_read|write / host_status / host_scan /
             │        │            host_apply / cve_lookup / webui_read / webui_edit /
             │        │            vault_list / vault_use / message_agent
             │        ├─ privileged success → RBAC::consumeEscalation() (uses_left--)
             │        └─ audit tool.call
             │            ▼
             │      append assistant reply + 'TOOL_RESULT: …' to messages;
             │      append scrubbed trace row (tool, ok, result ≤2000 chars)
             │            │
             └────────────┘  loop (continue), up to 7 steps
                    ▼
        final reply scrubbed; trim messages to last 120; UPDATE agent_sessions;
        mark agent's unread inbox read; return {reply, trace}
```

Key details:

- **Tool catalogue** (`RBAC::TOOLS`): `chat` (none), `notes_read`, `host_status`, `host_scan`, `cve_lookup`, `webui_read`, `vault_list` (read), `notes_write`, `message_agent` (write), `host_apply`, `webui_edit`, `vault_use` (privileged).
- **Four default agents** (`RBAC::DEFAULTS`): `system` (host status/scan/apply, messaging), `webui` (webui read/edit, messaging), `chat` (chat + notes only), `security` (scan, CVE, apply, vault list/use, messaging, schedule `daily 03:15`). Default slugs cannot be deleted; new agents start with only checked tools and zero secret grants.
- **Escalations** are single-use tokens with TTL. `RBAC::decide()` on approval stores `expires_at = now + ttl_seconds` and `uses_left = 1`. The minute tick expires unanswered asks after 300 s and expired approvals after `expires_at`, dropping a note into the agent's inbox.
- **Scrubbing** is defense in depth: the model reply, every tool result, the trace row, hostctl replies, vault replies, Hermes data, and search snippets all pass `Util::scrub()`/`scrubDeep()`. It censors authorization headers, bearer tokens, credential-shaped keys, `{{secret}}`, PEM blocks, provider-specific key shapes, and long base64-looking strings to `***CENSORED***`.

---

## 7. Curated Hermes proxy

`src/lib/hermes.php` is a narrow, scrubbed client. The browser never receives `HERMES_API_KEY`; it asks `api.php?action=hermes.source` for a source id. `HermesSources::catalog()` declares **20 sources** (see below), each with method `GET`, path, field whitelist, capability, TTL and `scrub: true`.

- `Hermes::fetch()` rejects unknown ids and any path containing a forbidden fragment (`/env/reveal`, `/config/raw`, `/debug-share`, `/files/ticket`, `/pty`, `/host-terminal`, `/auth/native/token`, `/ws`, `/audio/transcribe-stream`, `/plugin-providers`).
- Path templates are filled only from whitelisted parameter names (`^[A-Za-z0-9_-]+$`), URL-encoded, and a remaining `{` is an error.
- Responses are cached in `hermes_cache` keyed by `sourceId:md5(params)` until TTL, then field-filtered (flat object or list of objects) and `scrubDeep()`ed before storage and return. Failures report `offline: true`.
- `Hermes::deepLink()` only permits paths from `PageRegistry::ids()`, falling back to the dashboard URL.

| Source id | Hermes path | Fields | Capability | TTL |
|---|---|---|---|---|
| `hermes.status` | `/health` | status, version, uptime | hermes.status | 15 |
| `hermes.sessions.list` | `/v1/sessions` | id, title, created_at, updated_at | hermes.sessions.list | 30 |
| `hermes.session.get` | `/v1/sessions/{id}` | id, title, messages | hermes.sessions.list | 30 |
| `hermes.models.options` | `/v1/models` | id, name, context_length | hermes.models.list | 300 |
| `hermes.tools.toolsets` | `/v1/toolsets` | id, name, description | hermes.tools.list | 300 |
| `hermes.skills.list` | `/v1/skills` | id, name, description | hermes.skills.list | 300 |
| `hermes.cron.jobs` | `/v1/cron/jobs` | id, name, schedule, enabled | hermes.cron.list | 60 |
| `hermes.analytics` | `/v1/analytics` | requests, tokens, errors | hermes.analytics.read | 120 |
| `hermes.themes` | `/api/themes` | id, name | hermes.themes.list | 600 |
| `hermes.config.schema` | `/api/config/schema` | fields, version | hermes.config.read | 600 |
| `hermes.env.names` | `/api/env/names` | names | hermes.env.names | 600 |
| `hermes.messaging.platforms` | `/api/messaging/platforms` | id, name, connected | hermes.messaging.read | 120 |
| `hermes.webhooks` | `/api/webhooks` | id, url, event | hermes.webhooks.list | 120 |
| `hermes.gateway.capabilities` | `/v1/capabilities` | capabilities, version | hermes.status | 120 |
| `hermes.logs` | `/api/logs` | level, message, created_at | hermes.logs.read | 30 |
| `hermes.files.meta` | `/api/files/meta` | path, size, modified | hermes.files.read | 60 |
| `hermes.git.status` | `/api/git/status` | branch, clean, ahead | hermes.files.read | 60 |
| `hermes.profiles` | `/api/profiles` | id, name, description | hermes.profiles.list | 300 |
| `hermes.plugins` | `/api/plugins` | id, name, enabled | hermes.plugins.list | 300 |
| `hermes.mcp` | `/api/mcp` | id, name, connected | hermes.mcp.list | 300 |

The repository's contract test explicitly expects **20** sources (`tests/contract/hermes_sources.php`), while `app.js` exposes **19** area tiles — the UI has no tile for `hermes.session.get`.

---

## 8. Search index

`src/lib/search.php` maintains a rebuild-from-scratch corpus in `search_docs`:

1. `index()` deletes all rows, then inserts scrubbed documents for all 32 pages (group + route + capabilities), all agents (slug/role/description/system prompt), the 500 most recent sessions (last 20 messages concatenated), all findings, the 500 most recent audit rows, and one stub per Hermes source id.
2. `query()` trims to 120 characters, matches `title LIKE %q% OR body LIKE %q%`, optionally filters kinds to `page|agent|session|finding|audit|hermes`, ranks title matches first then `updated_at DESC`, caps at 1–50 results, and returns scrubbed 180-character snippets.
3. `api.php action=search.query` calls `index()` before every query, so the index is always current but is rebuilt on each search rather than maintained incrementally.

---

## 9. Scheduler and worker

`src/worker.php` runs under the in-container `crond` (`crontab`) with three commands:

| Schedule | Command | Behaviour |
|---|---|---|
| `* * * * *` | `worker.php tick` | Parses `config.schedule.self_test`, compares with the last `webui.selftest` run, calls `Scheduler::runSelfTest()`, stores `schedule_runs`, opens a finding on failure, audits. |
| `*/5 * * * *` | `worker.php agents` | For every enabled scheduled agent except `security`, if due, drops a plain-language wake note into its inbox and records `agent.wake.<id>`. |
| `15 3 * * *` | `worker.php security` | Read-only `HostCtl::call('security_scan')`, deduplicates findings into the DB, wakes the `security` agent asking it to propose one `host_apply` per issue, stores `host.security` run. |

`Scheduler::parse()` understands only `daily HH:MM` (UTC) and `every Nm`. `Scheduler::due()` handles both. `Scheduler::runSelfTest()` is a **read-only structural check**: it calls `Render::page($id, $config, ['mode' => 'populated'])` for every configured page and considers any HTML string longer than 20 characters a pass, plus a presence check for each Hermes source. It never executes the JavaScript views and never mutates config. `api.php action=selftest.run` runs the same check synchronously and records it as `webui.selftest`.

The daily scan can never change the host by itself: `host_apply` still needs an owner escalation and an HMAC-signed broker call.

---

## 10. STT loopback

`src/lib/stt.php` proxies to a whisper.cpp HTTP engine supervised *inside* the webui container (`supervisord.conf` program `stt`, bound to `127.0.0.1:8713`, started only when `/opt/stt/whisper-server` and the `ggml-small.en-q5_0.bin` model exist; otherwise it sleeps). No cloud service, no third container.

- `status()` pings `/health` and checks for `ggml-small.en-q5_0.bin`, `ggml-base.en-q5_0.bin`, `ggml-large-v3-turbo-q5_0.bin` under `/opt/stt/models`; reports `endpoint: 'loopback only'` and `max_bytes: 10485760`.
- `api.php action=stt.transcribe` accepts a multipart `audio` upload ≤10 MiB, moves it to a temp file, POSTs a hand-built multipart body to `/inference`, parses `text`, truncates to 4000 chars, logs an `stt_jobs` row, and deletes the temp file in a `finally` block.
- The UI's Voice view disables the mic button when the engine is unavailable.

---

## 11. Security boundaries and trust zones

| Zone | Runs as / where | May reach | Guard |
|---|---|---|---|
| Browser | owner's machine | nginx :9120 only | login form, CSRF cookie readable by JS |
| nginx / static | container, www-data-ish via nginx config | PHP-FPM, static assets | security headers, dotfile deny, 2 MiB body cap |
| PHP-FPM / API | container uid 1000 | SQLite, model API, Hermes gateway, both relay sockets, loopback STT | ensureOwner, Origin check, CSRF, `Auth::require()` per action, `Util::str()` coercion |
| Model | external OpenCode API | conversation text only | no tool execution; TOOL_CALL is just text until the engine parses it |
| Agent runtime | `Engine` + `RBAC` | only its granted tools | per-tool allowlist, one-time expiring escalations, scrubbed results, audit on every call/denial |
| Hermes gateway | external service | curated GET paths only | source-id allowlist, field whitelist, forbidden-fragment blacklist, TTL cache, scrubbing, key never sent to browser |
| Vault broker | root, host, unix socket | inject a named secret into one http/file/exec action | no read op; owner approval at broker; scrub in broker and again in PHP; only names exposed to UI |
| Host-control broker | root, host, `0660 root:deck` socket | eight fixed actions | action allowlist, regex-validated arguments, HMAC over `action|canonical args|ts` with root-only key, ±120 s skew, scrubbed output, no shell/exec |
| STT engine | loopback in container | inbound audio only | not exposed outside the container; audio deleted after transcription |

The two host brokers are reached through `hostctl/socket_relay.py`, which exposes stable `vault.sock` and `hostctl.sock` files in `/opt/panel-sockets` and reconnects to the real brokers on every request, so broker restarts are invisible and no host filesystem is granted to the container.

`HostCtl::probeForged()` is a live, state-neutral self-check: it sends a deliberately invalid token for `fix_service` and verifies the broker refuses it. `Vault::listSecrets()` returns only `name`, labelled/scrubbed `label`, `allow` and `approval`. `vault.test` uses a synthetic `owner-test` agent so the owner can dry-run a named secret; the root broker still approves it.

---

## 12. Walkthrough: how a chat message flows

1. **Compose.** The user opens an agent chat; `openChat(agentId, sessionId)` loads `session.get` (or shows "New session"). Typing Enter submits `#chat-form`, which disables the input and calls `api('chat.send', {agent_id, session_id, message})`.
2. **Transport & gates.** `api()` POSTs JSON to `/api.php?action=chat.send` with the CSRF header. nginx fastcgi-forwards to FPM; `api.php` runs `ensureOwner()`, origin check, CSRF check, then `Auth::require()`.
3. **Session resolution.** `chat.send` resolves the agent via `RBAC::agent()` (enabled only). If no valid session id, `Engine::newSession()` inserts an `agent_sessions` row and the first message becomes the title (≤80 chars).
4. **Prompt assembly.** `Engine::chat()` appends the scrubbed user message (≤6000) and builds the system prompt from the agent's own prompt, its allowed tool descriptions, the escalation/noop meta-tools, and the newest unread inbox note (scrubbed).
5. **Model turn.** `callModel()` posts to `PANEL_LLM_URL` with a bearer key, 1400 max tokens, temperature 0.3, no streaming. The reply text is scrubbed.
6. **Tool decision.** `parseToolCall()` accepts a strict `TOOL_CALL:` JSON line, a fenced JSON block, or bare JSON. With no call, the reply is final. With a call, `executeTool()` lowercases the name and runs `RBAC::can()`: unknown tool, tool not in the agent's grant, missing secret grant, self-message, or missing one-time escalation all return an error **without** touching a backend and write a `tool.denied` audit row.
7. **Execution & scrub.** Allowed calls dispatch to notes/layout helpers, `HostCtl`, `Vault`, or the message bus. Privileged successes consume one escalation use. The result is scrubbed, truncated to 2000 chars in the trace, and appended as `TOOL_RESULT:` so the model can continue. Up to seven turns run.
8. **Persist & return.** Final reply is scrubbed, the transcript is trimmed to 120 messages, `agent_sessions` is updated, the agent's inbox is marked read, and `{ok, session_id, reply, trace}` is returned.
9. **Display.** `app.js` stores `session_id`, reloads `session.get`, renders each message with `esc()`, scrolls to the bottom, and toasts the number of tool calls.

---

## 13. Walkthrough: how a UI edit flows

There are two routes to the same governance pipeline.

**Owner route (Settings).** `#cfg-propose` sends `ui.config.propose` with `{op:'replace', path:'/meta/title', value}`. `ConfigGovernance::propose()` applies the patch to the active config, validates it against `Schema`, assigns the next revision, hashes it, inserts a `draft` revision + `pending` proposal with a 24-hour expiry, and returns the revision, hash and human-readable diff. The history list then offers **Approve** (→ `ui.config.approve`) or **Roll back** (→ `ui.config.rollback`); either calls `bootstrap()` again so the whole UI re-reads the new active config. `theme.set` is the automatic variant: propose four theme patches, approve them immediately as owner, re-hydrate.

**Agent route (`webui_edit`).** The Web Interface agent is granted `webui_edit` (privileged). It must first use `webui_read` and, because the tool is privileged, obtain an owner-approved one-time escalation (`request_escalation` → Approvals view → `RBAC::decide()`). `Engine::webuiEdit()` then validates the layout key (`^[a-z0-9_.-]+$`), inserts an `approvals` row of kind `webui_edit` for owner review, upserts the `layout` row with `updated_by = 'agent:<slug>'`, and returns a confirmation. The drawer's "Agent-editable UI" form in the browser instead proposes a schema-validated config patch (a restricted key→path map), so it lands in the same draft/approval queue rather than writing layout directly.

**Why this is safe:** the agent never edits code or CSS, only allowlisted JSON paths or a validated layout key; every path/root is constrained; bad markers and unsafe tokens are rejected before storage; nothing becomes active without an owner decision; revision numbers are monotonic even after rollback; and each step appends to the SHA-256 audit chain. The one gap worth stating plainly: `webui_edit` writes the layout immediately and records the approval row *for review*, whereas config patches are inert until approved — the stronger guarantee applies to config, not to the legacy layout table.

---

## 14. Implementation notes / asymmetries discovered while reading

- The README calls the deployment "single-container"; compose declares two services, with `hermes` gated behind the `standalone` profile. Both statements are consistent in production but differ in wording.
- `PanelActions::catalog()` has 35 declared actions; `api.php` implements four more (`layout.save`, `agent.message`, `panel.host.probe`, `panel.redaction.probe`). The catalogue is documentation of capabilities, not the router.
- `HermesSources::catalog()` has 20 curated sources; `app.js` exposes 19 area tiles (no `hermes.session.get` tile).
- The self-test's notion of "every page works" is a server-rendered HTML length check, not an execution of the JavaScript views; see `docs/FRONTEND.md` for the UI-side limitations.
- `Prompt::normalise()` and `Prompt::hint()` exist and define the casual-language wrapper, but `Engine::chat()` builds its own system prompt directly and does not call `normalise()`; the hint text is not used by the current UI.
- `Util::parseToolCall()` is a stricter, single-call parser that is not the one used by `Engine` (which has its own `parseToolCall()` accepting the fenced/bare forms). The unit tests cover the strict variant.

No secrets, key material, credential values, or vault contents were used in producing this document.
