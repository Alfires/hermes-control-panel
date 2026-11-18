# Hermes Control Panel — Feature Inventory & Architecture Research

**Date:** 2026-11-18
**Scope:** Research only. No application source was modified. No secret values were read into this document.
**Sources:**
- Repo at `/home/timo/programming/hermes-control-panel` (HEAD `d4db947`, plus untracked `docs/`).
- Deployed copy at `/opt/hermes-control-panel` on `deck@192.168.2.157` (same HEAD; only `hostctl/*.py` executable-bit changes).
- Toolbox reference `run({ name: "hermes-deck" })`; read-only SSH inspection via `/home/timo/programming/openhost/deploy/deck.sh --sudo`.
- Live read-only API probes against Hermes dashboard `:9119` and gateway `:8642` (response *shapes only* — key names and counts, never values).

**Secret handling:** `panel.env`, `/opt/hermes/.env`, `/opt/hermes/data/.env` and all token/password values were excluded. Probes printed JSON key names and collection counts only. Credentials needed for authenticated probes were taken from the container environment inside a throwaway script, never echoed.

---

## 1. Executive summary

1. **Our panel does not talk to Hermes Agent at all.** Its model source is the local OpenCode-compatible wrapper at `host.docker.internal:4097` (`PANEL_LLM_URL`); the only textual references to "Hermes" in `src/` are the page title and an operator command hint. Ports `8642` and `9119` are never contacted by panel code. The name is aspirational, not architectural.
2. **Hermes Agent exposes 264 distinct dashboard/API routes, 9 WebSocket endpoints and 25 advertised gateway capability endpoints.** Our panel exposes **20 API actions** and 9 hard-coded navigation pages. The uncovered surface is roughly an order of magnitude larger.
3. **Biggest functional gaps:** sessions (rich 68-field session objects, fork/stream/model-lock), files + git tooling, analytics, model/provider management, cron jobs, skills, plugins, MCP, channels/messaging, webhooks, profiles/SOUL, config schema editor (925 fields), env/key management (392 key names), tools/toolsets (26), local-model management, audio, memory/learning, ops (backup/doctor/security audit), themes/fonts/i18n, PTY/terminal, and the whole gateway control plane.
4. **Biggest safety gaps in our own design:** `webui_edit` applies a layout change **before** recording it for review (post-hoc approval), `layout.save` accepts arbitrary JSON and arbitrary `custom_css`, `approvals` rows are advisory only (the broker never sees them), there is no CSRF token beyond an `Origin` check, no login rate limit, and the audit table is mutable by the container user.
5. **Recommendation:** replace the hard-coded `NAV`/`TITLES`/`render()` switch with a **versioned, schema-validated page/block registry** stored in SQLite; all AI edits become *proposals* validated against an allowlist and applied only after owner approval, with hash-linked revision history and audit rows. Hermes endpoints should be reached through a narrow, scrubbed panel proxy with per-feature capability flags.

---

## 2. Current architecture (implemented today)

### 2.1 Runtime / deployment

| Concern | Implementation |
|---|---|
| Container | `php:8.3-fpm-alpine`, nginx + PHP-FPM + crond under supervisord; single container `hermes-panel`, port `9120 -> 8080`. |
| State | SQLite `/var/lib/panel/panel.db` on host bind mount `./data` (WAL, foreign keys on, 5 s busy timeout). |
| Workers | PHP-FPM uid/gid `1000`; `clear_env=no`; 16 max children. |
| Model | `PANEL_LLM_URL` → `http://host.docker.internal:4097/v1/chat/completions`, key from `PANEL_LLM_KEY`. **Not Hermes.** |
| Brokers | Root-owned host-control broker `/run/panel-host.sock` and existing vault broker, exposed into the container through two root-owned relay sockets in `/opt/panel-sockets` (directory mount survives broker restarts). |
| HMAC key | `/opt/panel-secrets/hostctl.key` (root 0600), mounted read-only and copied to a uid-1000 0400 file inside the container by `entrypoint.sh`. |
| Scheduler | `worker.php tick` every minute; `agents` every 5 min; `security` at 03:15. |
| Installer | `deploy/install.sh` clones/resets from local Forgejo, generates owner password + HMAC key, writes root-only `panel.env`, installs two systemd units, force-recreates compose stack. |
| nginx security headers | `X-Content-Type-Options`, `X-Frame-Options DENY`, `Referrer-Policy no-referrer`; dotfiles denied; 2 MB body limit. |

### 2.2 Source inventory

| File | Role |
|---|---|
| `src/lib/auth.php` | Owner credential sync from env → `users`; login/logout; 12 h cookie (`HttpOnly`, `SameSite=Lax`, `Secure` only under HTTPS); SHA-256 token hashes in `logins`. |
| `src/lib/db.php` | SQLite bootstrap + 11 tables: `users`, `logins`, `agents`, `agent_sessions`, `escalations`, `approvals`, `messages`, `findings`, `layout`, `audit` (+ implicit migration entry point). |
| `src/lib/rbac.php` | 12-tool catalogue with risk levels (`none/read/write/privileged`), 4 seeded agents, `can()`, one-shot `escalations` (TTL clamped 30–3600 s), `expirePending()`. |
| `src/lib/engine.php` | Agent loop: text protocol `TOOL_CALL: {"name":…,"args":{…}}`, up to 7 steps, tool RBAC, scrubbing, 120-message context window, session creation. |
| `src/lib/vault.php` | Vault broker client: `list` / `use` only; kinds `http|file|exec`; recursive scrubbing; audit. |
| `src/lib/hostctl.php` | Allowlisted host actions, HMAC `sha256(action|json(args)|ts)`, scrub, audit. |
| `src/lib/util.php` | JSON response helper, body parsing, length clamping, aggressive redaction regexes, newline-delimited Unix-socket client. |
| `src/public/api.php` | 20 owner-gated actions (catalogue in §5.1). |
| `src/public/index.php` + `app.js` + `app.css` | Vanilla JS SPA: 9 hard-coded views, hero/KPI cards, modals, chat overlay, drawer, 10 s polling refresh. |
| `src/worker.php` | `tick`, `agents`, `security` cron modes; findings persistence; scheduled agent wake-up. |
| `hostctl/hostctl.py` | Root broker: 8 allowlisted actions, fixed argv, regex validation, HMAC verify with 120 s skew, OSV CVE lookup, output scrubbing, 0660 root:deck socket. |
| `hostctl/socket_relay.py` | Two stable relay sockets (`vault.sock`, `hostctl.sock`) reconnected per request. |
| `tests/` | `unit.php`, `tools.php`, `integration.sh`, `echo_server.py` (details in §8). |

### 2.3 Implemented feature list

**Owner & UI**
- Owner login/logout/session, `me` liveness, owner password synced from `panel.env`.
- Single-page UI with sidebar + drawer + topbar pills (vault/host/model).
- Layout personalisation keys: `title`, `accent`, `default_model`, `card_order`, `welcome`, `custom_css`; JSON editor in Settings; `layout.save` action.
- `last_run:<slug>` scheduling bookkeeping stored in the same `layout` table.

**Agents & conversations**
- Four seeded agents (Deck System, Web Interface, Chat & News, Exploit Watch), non-deletable; custom agents creatable/deletable.
- Editable fields: name, role, description, system prompt, model string, tools, vault grants, schedule (`daily HH:MM` or `every Nm/Nh`), enabled, `can_message`.
- Per-agent sessions with message history, new session, delete, open-in-chat, context inspector (model, tools, grants, prompt, schedule).
- Agent-to-agent messaging with opt-in delivery; inbox injected into system prompt; messages marked read after a turn.

**Tools / permissions**
- Tools: `chat`, `notes_read`, `notes_write`, `host_status`, `host_scan`, `cve_lookup`, `host_apply`, `webui_read`, `webui_edit`, `vault_list`, `vault_use`, `message_agent`, plus meta-tools `request_escalation`, `noop`.
- Risk ladder; privileged tools require an approved, unexpired, one-use escalation; vault use requires an explicit named grant or escalation.

**Security operations**
- Daily read-only host scan (packages, SSH, firewall, exposed listeners, privileged containers, world-writable files, curated critical-package rules) → `findings` table.
- Exploit Watch agent wakes with scan results and proposes fixes; manual "Run scan now".
- Host actions: `system_status`, `security_scan`, `cve_lookup`, `fix_updates`, `fix_service`, `fix_docker`, `fix_ssh`, `reboot` — all allowlisted, HMAC-signed when mutating.
- Vault: name/label/allowlist/approval display; owner dry-run `vault.test`; double scrubbing.
- Escalation approve/deny UI, recorded actions list, audit trail (last 200 events), restart persistence test.

**Pages (hard-coded):** `overview`, `sessions`, `agents`, `approvals`, `security`, `vault`, `settings`, `audit`, `setup`.

---

## 3. Hermes Agent inventory (live deployment)

### 3.1 What is deployed

- Image `nousresearch/hermes-agent:latest`, container `hermes`, command `gateway run` under s6-overlay; host install root `/opt/hermes`, persistent volume `/opt/hermes/data -> /opt/data` (uid 10000).
- Ports: dashboard/API `9119` (HTTP basic-auth + session cookies), OpenAI-compatible gateway `8642` (Bearer `API_SERVER_KEY`).
- Model wiring from `hermes-deck` + live config: `provider: custom`, `default: big-pickle`, `base_url: http://host.docker.internal:4097/v1`, key by env indirection; named provider `opencode` with model discovery and 200 K context. `hermesctl.sh model <id>` edits `config.yaml` and restarts; in-chat `/model custom:<id>` also works.
- Persisted state in `/opt/hermes/data`: `config.yaml`, `.env`, `SOUL.md`, `memories/`, `skills` snapshots, `cron/`, `kanban.db`, `projects.db`, `response_store.db`, `gateway_state.json`, `backups/`, `hooks/`, `pastes/`, `plans/`, `plugins/`, `platforms/`, `pairing/`, caches and logs.
- `hermesctl.sh` commands: `status | start | stop | restart | logs | model [id] | creds`.

### 3.2 Dashboard pages (built-in React routes in `web/src/App.tsx`)

`/sessions`, `/files`, `/analytics`, `/models`, `/logs`, `/cron`, `/skills`, `/plugins`, `/mcp`, `/pairing`, `/channels`, `/webhooks`, `/profiles`, `/profiles/new`, `/config`, `/env`, `/system`, `/docs`, `/chat` (persistent PTY-style chat host). Root redirects to `/sessions`. Unknown routes fall back to `/sessions`.

Dashboard extras: 8 themes (`default`, `midnight`, `ember`, `mono`, `cyberpunk`, `rose`, `nous-blue`, `default-large`), font switcher, theme/font endpoints, 17 i18n locales, profile switcher + scope banner, memory-pressure banner, shared-metrics consent banner, plugin page/slot system (`PluginPage`, `PluginSlot`, `usePlugins`), 2 installed dashboard plugins (kanban, hermes-achievements), sidebar status strip, auth widget.

### 3.3 API surface

**Live counts (2026-11-18):** 264 unique dashboard/API paths, 9 WebSocket paths, 25 gateway capability endpoints, 26 toolsets, 39 installed skills, 31 messaging platforms, 1 profile, 6 sessions, 101 config sections, 925 config-schema fields, 392 env key *names*. Counts are snapshots, not guarantees.

Grouped endpoint catalogue (representative; full raw list was collected during research):

| Group | Endpoints (abridged) |
|---|---|
| Auth | `/api/auth/me`, `/api/auth/providers`, `/api/auth/ws-ticket`, `/auth/login`, `/auth/callback`, `/auth/logout`, `/auth/password-login`, `/auth/native/{authorize,token,refresh}` |
| Status / health | `/api/status`, `/api/health`, `/api/health/idle`, `/api/health/retirement`, `/api/host/identity`, `/api/system/stats`, `/api/ssh/ownership` |
| Sessions / chat | `/api/sessions` (+ `/{id}`, `/messages`, `/fork`, `/chat`, `/chat/stream`, `/model`, `/pause`, `/resume`, `/cancel`, `/runs`), `/api/chat/workspaces`, `/api/chat/{file,image}-upload`, `/api/events`, `/api/ws`, `/api/console`, `/api/pub` |
| Files / git | `/api/files` (+ read, stream, list, upload, upload-stream, mkdir, download, ticket), `/api/fs/*`, `/api/git/*` (branches, worktrees, diff, status, review, PR, push, revert, stage) |
| Models / providers | `/api/model/{set,info,options,auxiliary,moa,recommended-default}`, `/api/providers/{oauth,custom-endpoints,validate}`, `/api/local-models/*` (catalog, search, download, jobs, hardware, activate, eject, runtime/install, sideload, server), `/api/analytics/{models,usage}` |
| Config / keys | `/api/config`, `/api/config/raw`, `/api/config/schema`, `/api/config/defaults`, `/api/env`, `/api/env/reveal`, `/api/ui-preferences`, `/api/dashboard/{theme,themes,font,plugins,catalog,hub,rescan,plugin-providers,agent-plugins/*}` |
| Automation | `/api/cron/{jobs,blueprints,delivery-targets,fire,jobs/{id}/{pause,resume,trigger,runs,cancel}}`, `/api/webhooks`, `/api/skills`, `/api/mcp/*`, `/api/delegation/*`, `/api/curator`, `/api/learning/{graph,node}` |
| Integrations | `/api/messaging/*` (31 platforms, relay, Telegram/WhatsApp onboarding), `/api/pairing/*`, `/api/gateway-connections/*`, `/api/profiles/*` (incl. `/soul`, `/model`, `/export`, `/import`, `/description`, `/desktop-overlay`), `/api/ops/*` (backup, import, transfer, checkpoints, doctor, security-audit, dump, hooks, debug-share) |
| Tools / memory | `/api/tools/toolsets`, `/api/tools/toolsets/{name}/{config,env,model,models,provider,post-setup}`, `/api/tools/computer-use/*`, `/api/tools/terminal/*`, `/api/memory`, `/api/memory/provider`, `/api/memory/providers/{name}/{config,setup}`, `/api/memory/reset` |
| Audio / display | `/api/audio/{speak,transcribe,voice-config,voices,tts-lease,stt-lease,voice-live/*}` + websockets `speak-stream`, `transcribe-stream`; `/api/display/ws`, `/api/host-terminal`, `/api/pty` |
| Webapps / portal | `/api/webapp/*`, `/api/webapp-access/*`, `/api/portal`, `/api/cloud/agents`, `/api/shared-metrics/consent`, `/api/hermes/update*` |
| Gateway (`:8642`) | `GET /v1/models`, `POST /v1/chat/completions`, `POST /v1/responses`, `POST /v1/runs`, `GET /v1/runs/{id}`, `GET /v1/runs/{id}/events`, `POST /v1/runs/{id}/{approval,steer,stop,resolve-unknown}`, `GET /v1/{skills,toolsets}`, `/api/sessions/*` CRUD + chat/fork/model, `/health`, `/health/detailed` |

**Gateway capability flags actually advertised on the Deck:** `admin_config_rw=false`, `jobs_admin=false`, `memory_write_api=false`, `audio_api=false`, `realtime_voice=false`; chat/streaming, runs, SSE events, approvals, tool progress, session resources, model options, fork, model lock, reasoning streaming are `true`. A future panel proxy must respect these flags instead of assuming the endpoints are enabled.

**Live response shapes (key names only):**

- `/api/sessions` → `{sessions: list[6], total, limit, offset, storage}`; each session has **68 fields**, e.g. `id`, `source`, `session_key`, `chat_id`, `display_name`, `model`, `parent_session_id`, `started_at`, `ended_at`, `message_count`, `tool_call_count`, `input_tokens`, `output_tokens`, `cache_read_tokens`, `cache_write_tokens`, `runtime_revision`, `runtime_generation`, `origin_json`, `expiry_finalized`.
- `/api/model/options` → `{providers:[{slug,name,is_current,is_user_defined,models,total_models,source,api_url,native_catalog_empty,authenticated,capabilities,featured_models,aliases}],model,provider}`.
- `/api/tools/toolsets` → `list[26]` of `{name,label,description,platform,platform_label,enabled,available,configured,tools:[]}`.
- `/api/skills` → `list[39]` of `{name,description,category,locked,enabled,usage,provenance}`.
- `/api/config/schema` → `{fields:{…925 dotted field names…},category_order:[15]}`.
- `/api/config` → 101 top-level sections (model, providers, fallback, toolsets, database, runtime, session, attachments, agent, terminal, web, browser, worktrees, checkpoints, mcp, tool_output, compression, prompt_caching, …).
- `/api/env` → 392 key names (representative: provider keys and base URLs, OAuth settings, tool-specific keys). **Values were never read.**
- `/api/profiles` → `{profiles:[{name,path,is_default,model,provider,has_env,skill_count,gateway_running,description,description_auto,display_name,bot_title,ui_meta,distribution_name,distribution_version,has_alias}]}`.
- `/api/messaging/platforms` → `{env_path,gateway_start_command,platforms:[31 × {id,name,description,enabled,configured,gateway_running,state,error_code,env_vars,relay_fronted,ingress_url,…}]}`.
- `/api/analytics/usage` → `{daily,by_model,by_task,totals,period_days,skills,tools}`; `totals` has input/output/cache/reasoning tokens, estimated + actual cost, sessions, API calls.
- `/api/cron/jobs` → list (empty on this Deck); blueprints and delivery-targets endpoints exist.
- `/api/webhooks` → `{enabled,base_url,subscriptions:[]}`.
- `/api/dashboard/themes` → `{themes:[8 × {name,label,description}],active}`.
- `/api/dashboard/plugins` → `list[2]` of `{name,label,description,icon,version,tab,slots,entry,css,has_api,source}`.
- `/api/portal` → `{logged_in,portal_url,inference_url,provider,free_tier,account_tier,subscription_url,features:[7]}`.
- `GET /v1/capabilities` → `{object,platform,model,auth:{type,required},runtime:{mode,tool_execution,…},features:{…30},endpoints:{…25}}`.

### 3.4 Hermes native tools (not just dashboard)

Toolsets (26 registered names): `web`, `search`, `x_search`, `vision`, `video`, `image_gen`, `video_gen`, `computer_use`, `terminal`, `skills`, `browser`, `cronjob`, `file`, `tts`, `todo`, `memory`, `context_engine`, `session_search`, `connections`, `project`, `bot_room`, `desktop_ui`, `onboarding`, `catalog`, `clarify`, `code_execution`, `delegation`, `kanban`, plus platform bundles (`hermes-*`) and per-platform toolsets (`discord`, `feishu_*`, `safe`, `coding`, `debugging`, …).

Core tool names from `toolsets.py` include `web_search`, `web_extract`, `terminal`, `process_manage`, `read_file`, `write_file`, `patch`, `search_files`, `vision_analyze`, `image_generate`, `skills_list`, `skill_view`, `skill_manage`, `browser_exec`, `browser_cdp`, `browser_dialog`, `browser_vault`, `text_to_speech`, `todo_list`, `memory`, `session_search`, `clarify`, `execute_code`, `delegate_task`, `cronjob_manage`, `kanban_*`, `computer_use`, `manage_connections`. Plugins add model-provider, memory, delegation, context-engine, disk-cleanup, teams-pipeline, achievements, kanban, browser, image/video-gen, security-guidance and dashboard-auth surfaces.

### 3.5 Hermes auth & security constraints

- Dashboard: basic-auth password provider (`provider: "basic"`), session cookies (access + refresh), OAuth/PKCE flows, `/auth/native/*` broker flow, WS tickets, per-provider rate limiting on password login, audit events, provider-scoped session revocation.
- Public API allowlist (shared by both auth gates): `/api/health`, `/api/status`, `/api/config/defaults`, `/api/config/schema`, `/api/model/info`, `/api/dashboard/themes`, `/api/dashboard/plugins`, `/api/cron/fire` (the last carries its own short-lived JWT — the JWT, not the allowlist, is the boundary). Everything else requires a verified dashboard session.
- Gateway API: Bearer `API_SERVER_KEY`; capability flags gate admin/config/jobs/memory/audio features. Tool approvals offer `once | session | always | deny` and redact flagged commands in `approval.request` events.
- Profile scoping: profile-specific config/env/skills; `ProfileScopeBanner` and `api-request-scope` client helpers prevent cross-profile operations.

---

## 4. Feature inventory

**Column (a) = implemented in our panel; column (b) = available in Hermes but missing from our panel.**
"Partial" means a weaker local substitute exists (e.g. our own chat loop instead of Hermes sessions).

| Area | (a) Implemented in our panel | (b) Available in Hermes but missing from our panel |
|---|---|---|
| Navigation / pages | 9 hard-coded views: overview, sessions, agents, approvals, security, vault, settings, audit, setup | 19 built-in routes incl. Files, Analytics, Models, Logs, Cron, Skills, Plugins, MCP, Pairing, Channels, Webhooks, Profiles, Config, Env/Keys, System, Docs, Chat; plugin-provided pages/slots |
| Theming & personalisation | `title`, `accent`, `default_model`, `card_order`, `welcome`, raw `custom_css`; agent `webui_edit` | 8 curated themes + fonts, CSS-variable tokens, theme/font API, 17 locales, UI preferences, profile-scoped appearance, plugin slots; profile `SOUL.md` / bot title / UI meta |
| Sessions | `sessions.list`, `session.get`, `session.delete`, `chat.send`, new-session, per-agent history, tool trace | 68-field session objects, 6 live sessions, `/api/sessions/{id}/messages|fork|chat|chat/stream|model`, runs, pause/resume/cancel, continuity headers `X-Hermes-Session-Id` / `X-Hermes-Session-Key`, workspaces, import, search, pruning, token accounting |
| Chat / model calls | Own 7-step loop via `host.docker.internal:4097`, text `TOOL_CALL:` protocol, 120-msg window | `POST /v1/chat/completions`, `/v1/responses`, `/v1/runs` + SSE events + steer/stop/approval, session chat streaming, reasoning streaming, model lock, Continuity/Responses APIs |
| Agents | 4 seeded + custom agents, editable prompts/tools/schedules/grants | Profiles (default + custom) with model/provider overrides, `has_env`, skill counts, description auto-generation, export/import, desktop overlay, setup command, delegation/routing backends |
| Models / providers | Free-text `model` field per agent; `default_model` in layout; pill shows model | `/api/model/{options,info,set,auxiliary,moa,recommended-default}`, provider catalog + validation, OAuth flows, custom endpoints (activate/validate), credential pool, 12+ advertised models, local-model catalog/search/download/jobs/hardware/activate/eject/sideload/runtime-install |
| Tools | 12 catalogue tools driven by prompt text; RBAC + escalation | 26 toolsets + per-toolset config/env/model/provider/post-setup, computer-use grants, terminal backends, 39 skills (content, lock, toggle), tool discovery/search, approval event stream |
| Host control | 8 allowlisted actions via root broker + HMAC; read-only scan; findings | `/api/system/stats`, `/api/host/identity`, `/api/ssh/ownership`, `/api/gateway/{start,stop,restart,drain,migrate}`, Hermes update check/apply, ops doctor/checkpoints/backup/import/transfer/security-audit/dump/hooks/debug-share |
| Security | Daily exploit scan, findings table, host_apply proposals, vault name display, redaction, audit | `/api/ops/security-audit`, gateway approval events with `once/session/always/deny`, dashboard auth audit events, pairing/device approvals, webhook signature verification, curated `security-guidance` plugin |
| Vault / secrets | `vault_list`, `vault_use`, owner `vault.test`, list/use only, double scrub | `/api/env` + `/api/env/reveal` (392 key names, owner-gated values), credential pool strategies, browser vault logins, per-profile env scope, MCP OAuth flows. *(Hermes has no read-only-name equivalent exposed to agents here; our broker design is stricter.)* |
| Files / git | None | `/api/files/*`, `/api/fs/*`, `/api/git/*` (branches, worktrees, diff, status, review, commit, PR, push, revert, stage) |
| Analytics | KPI counts derived from local SQLite | `/api/analytics/{usage,models}`, daily/by_model/by_task/totals, cost estimates, tool/skill usage, shared-metrics consent |
| Logs | Audit trail only | `/api/logs` (log viewer), `/api/ops/{dump,debug-share}`, structured gateway events |
| Cron / schedules | `daily HH:MM`, `every Nm/Nh` strings, three crontab entries | `/api/cron/jobs` CRUD + pause/resume/trigger/runs/cancel, blueprints + instantiate, delivery targets, `/api/cron/fire` webhook |
| Plugins / skills | None (tool catalogue is static) | `/api/dashboard/plugins*`, hub/catalog/install/update/visibility, agent plugins activate/enable/disable/update-all, skills content/lock/toggle, 2 installed dashboard plugins |
| MCP | None | `/api/mcp/catalog`, install, servers CRUD, per-server auth/enabled/test, OAuth flows and callback |
| Messaging / channels | Agent-to-agent inbox only | 31 platforms (`/api/messaging/platforms`), platform test, relay links/accounts, Telegram/WhatsApp onboarding, gateway connections incl. SSH/OAuth/test/primary/launch-mode, `/api/pairing/*` |
| Webhooks | None | `/api/webhooks` + enable + `/{name}` + `/{name}/enabled`, signature tests, cron fire JWT |
| Memory / learning | Shared notes board (`notes_read/write`), audit log | `/api/memory`, provider setup/config/reset, `/api/learning/{graph,node}`, curator status/pause/run, memory provider plugins |
| Audio | None | `/api/audio/*` (TTS, STT, voices, leases, voice-live status/session) + 2 audio websockets |
| Terminal / PTY | None | `/api/pty`, `/api/host-terminal`, `/api/console`, `/api/display/ws`, terminal backend selection, terminal appearance settings, computer-use permissions grant |
| Ops / backup | Restart persistence only | Backup chunk/download, import/import-upload, transfer chunk/restore, checkpoints + prune, config migrate, doctor, debug share, prompt size, Hermes update check/apply |
| Docs / status | Setup page with 4 operator commands | `/api/docs`-backed Docs page, `/api/status` (34 fields), `/api/health*`, portal/cloud-agent status, `/api/portal` features |
| Tests | 3 suites, ~64 checks total | Extensive Vitest suite in `web/src` (dozens of `*.test.tsx`) + `tests-js/`, `evals/`, plugin isolation/owner-auth/webhook-auth eval packs |

---

## 5. API action catalogue & data shapes

### 5.1 Panel `api.php` actions (all owner-gated; `login`/`me` exempt from Origin check)

| Action | Method / input | Response shape | Notes |
|---|---|---|---|
| `login` | `{username,password}` | `{ok}` + cookie | 12 h session token, SHA-256 hash stored |
| `logout` | — | `{ok}` | deletes login row |
| `me` | — | `{ok,authenticated}` | used by healthcheck + SPA boot |
| `bootstrap` | — | `{ok,agents[],tools{},sessions[],approvals[],escalations[],findings[],messages[],layout:{value},vault:{available,secrets[]},host:{available}}` | installs default agents on first call; whole-state poll every 10 s |
| `agent.create` | `{slug,name,role,description,system_prompt,model,tools[],vault_names[],can_message,enabled,schedule}` | `{ok,id}` | slug `[a-z0-9_-]`; tools intersected with catalogue |
| `agent.update` | `{id,…same fields}` | `{ok}` | model string is unvalidated free text |
| `agent.delete` | `{id}` | `{ok}` | default slugs refused |
| `sessions.list` | — | `{ok,sessions[]}` | session row includes full `messages` JSON |
| `session.get` | query `id` | `{ok,session:{messages:[]}}` | messages decoded |
| `session.delete` | `{id}` | `{ok}` | no agent ownership check |
| `chat.send` | `{agent_id,session_id?,message}` | `{ok,session_id,reply,trace[]}` | synchronous model call, up to 180 s |
| `escalation.decide` | `{id,approve}` | `{ok}` | sets approved/denied, TTL, one use |
| `approval.decide` | `{id,approve}` | `{ok}` | updates advisory `approvals` row only |
| `security.scan` | — | broker `{ok,findings[],checked_at}` | calls root broker read-only |
| `findings.list` | — | `{ok,findings[]}` | last 200 |
| `layout.save` | `{value}` | `{ok}` | arbitrary JSON → `layout` key `layout`; arbitrary `custom_css` |
| `agent.message` | `{agent_id,agent,body}` | `{ok}` | target must exist, enabled, `can_message` |
| `vault.test` | `{name,kind,purpose,params}` | broker reply, scrubbed | owner-only dry use; broker still approves |
| `vault.list` | — | `{ok,secrets[],error}` | names/labels/allow/approval only |
| `audit.list` | — | `{ok,audit[]}` | last 200 |

**Core data shapes**

- Agent row: `id,slug,name,role,description,system_prompt,model,tools (JSON string),vault_names (JSON string),can_message,enabled,schedule,created_at`.
- Session row: `id,agent_id,title,messages (JSON array of {role,content}),created_at,updated_at`.
- Escalation row: `id,agent_id,capability,reason,status,ttl_seconds,decided_at,expires_at,token_hash,uses_left,created_at`.
- Approval row: `id,agent_id,kind,summary,payload (JSON),status,created_at,decided_at`.
- Tool-call line: `TOOL_CALL: {"name":"host_status","args":{}}` (also accepts a fenced JSON block or bare `{"tool":…,"args":…}`).
- Host broker request: `{op:"action",action,args:{},requestor,ts,token}`; HMAC message `action + "|" + canonical_json(args) + "|" + ts`; root broker enforces ±120 s skew.
- Vault request: `{op:"list"}` or `{op:"use",name,kind∈{http,file,exec},purpose,requestor,params}`.

### 5.2 Hermes API mapping recommendations

Expose only a curated subset through the panel proxy, with field allowlists:

| Panel block data source | Hermes endpoint | Field filter / constraint |
|---|---|---|
| `hermes.status` | `GET /api/status` | public shape safe; whitelist 34 keys |
| `hermes.sessions.list` | `GET /api/sessions` | drop `origin_json`, raw env-ish fields; keep id/title/model/tokens/counts/dates |
| `hermes.session.get` | `GET /api/sessions/{id}` + `/messages` | owner-only; scrub message content |
| `hermes.models.options` | `GET /api/model/options` | keep ids/capabilities; drop `api_url` raw if credential-bearing |
| `hermes.tools.toolsets` | `GET /api/tools/toolsets` | name/label/enabled/available/tools only |
| `hermes.skills.list` | `GET /api/skills` | name/category/enabled/usage/provenance |
| `hermes.cron.jobs` | `GET /api/cron/jobs` | owner-only; mutations gated by `jobs_admin` flag |
| `hermes.analytics` | `GET /api/analytics/usage` | all aggregate fields safe after consent check |
| `hermes.themes` | `GET /api/dashboard/themes` | public |
| `hermes.config.schema` | `GET /api/config/schema` | public schema; **never** `/api/config/raw` through the UI |
| `hermes.env.names` | `GET /api/env` | names only; **never** `/api/env/reveal` through the UI |
| `hermes.messaging.platforms` | `GET /api/messaging/platforms` | drop `env_vars` values, keep names/state |
| `hermes.webhooks` | `GET /api/webhooks` | owner-only; signatures stay server-side |
| `hermes.gateway.capabilities` | `GET /v1/capabilities` | Bearer key stays in backend env; feature flags respected |

Do **not** proxy: `/api/env/reveal`, `/api/ops/debug-share`, `/api/config/raw`, `/api/files/ticket`, `/api/pty`/PTY attach tokens, or any OAuth callback surface until a dedicated threat model exists.

---

## 6. Security constraints & current gaps

### 6.1 Constraints that must be preserved

- **Two independent root-owned brokers.** Agent ↔ panel conversation only; panel ↔ host broker via HMAC + allowlist; panel ↔ vault broker via logical names only. No `get` on the vault; no shell in the host broker.
- **Model has no tools.** RBAC is enforced in PHP before any backend call; privileged tools require a one-use, TTL-bound escalation.
- **HMAC key is root-only.** Never placed in the web root or DB; agent cannot mint a token; stale/forged requests refused by the broker itself.
- **Double redaction.** Broker scrubs before egress; PHP scrubs again (and recursively for vault/host replies); integration test proves a real temporary secret never appears in responses.
- **Default agents cannot be deleted.** New agents start with exactly the ticked tools and zero secret grants; messaging is opt-in.
- **Owner-only panel.** Session cookie is HttpOnly/SameSite; state-changing calls check `Origin`.
- **Hermes side:** dashboard session auth (basic password provider + OAuth/PKCE), public API allowlist, Bearer-protected gateway, profile scoping, tool approval choices, gateway capability flags.

### 6.2 Gaps to fix before exposing more power

| # | Gap | Impact | Fix |
|---|---|---|---|
| G1 | `webui_edit` writes `layout` immediately, then inserts an `approvals` row (`engine.php::webuiEdit`) | "Needs approval" is false; an agent can change UI right now | Write to `ui_config_proposals` with `status=pending`; apply only in `ui.config.approve` by owner (plus escalation consumption) |
| G2 | `layout.save` accepts arbitrary JSON + arbitrary `custom_css` | CSS can overlay/phish the whole UI; config can corrupt renderers | Schema validation + CSS property allowlist/theme tokens only; no JS/HTML in config |
| G3 | `approval.decide` only flips a row; host broker never sees the decision | Advisory approval can drift from what actually ran | Carry a signed decision token into the broker request, or treat `approvals` as record-only and document that enforcement lives in escalation + HMAC |
| G4 | Origin check only; no CSRF token, no login rate limit | CSRF via same-origin XSS or brute force | Double-submit CSRF token tied to session; rate-limit login by IP/user; `Secure` cookie behind trusted proxy |
| G5 | Audit table is ordinary SQLite writable by container uid | Audit can be edited if PHP is compromised | Append-only trigger + hash chain (`prev_hash`, `hash`) and/or mirror audit to a root-owned socket/log |
| G6 | `session.delete`, `session.get` take caller-supplied id without checking agent/session ownership | Any owner can read/delete anything — acceptable for owner-only, but becomes dangerous once role tiers are added | Add role checks when introducing non-owner roles |
| G7 | `agent.update` allows any model string | Typo/downgrade to a model with different safety properties | Validate against `PANEL_LLM_URL /v1/models` allowlist, cached daily |
| G8 | Whole-state `bootstrap` poll every 10 s | Leaks message bodies/audit into every response; wasteful, race-prone | Replace with versioned deltas (`GET /api.php?action=state.diff&since=<rev>`) |
| G9 | No Hermes integration; no link to `:9119` | Users must run two disconnected UIs with two auth systems | Narrow proxy + deep links, single sign-on decision documented |
| G10 | Secrets surface of Hermes (`/api/env/reveal`, config raw, PTY tickets) if naively proxied | Catastrophic leak | Explicit endpoint allowlist and field filters as in §5.2 |

---

## 7. Recommended modular page / block architecture

### 7.1 Principles

1. **Code owns capabilities; config owns composition.** AI may reorder, add, remove and configure pages/blocks only from registered registries. It may never add executable code, HTML, inline JS, raw URLs outside the allowlist, or arbitrary CSS.
2. **Every change is a versioned proposal.** No direct writes to the active config by an agent; owner approval is the only path to activation.
3. **Every revision is reconstructible.** Hash-linked history with before/after diffs, actor, reason, approval id and test result.
4. **Registry-driven rendering.** `NAV`, `TITLES` and the `render()` switch become generated from config; adding a page = adding a config entry, not editing `app.js`.
5. **Fail closed.** Unknown block type, unknown data source, invalid schema, failed render smoke → reject the whole proposal atomically.

### 7.2 Three registries

**Page registry** (`src/lib/registry_pages.php`, mirrored to JS via a public manifest endpoint):

```php
final class Pages {
  public const BUILTIN = [
    'overview' => ['route'=>'/', 'title'=>'Overview', 'icon'=>'◈', 'order'=>10,
                   'capabilities'=>['panel.bootstrap'], 'blocks'=>['kpi.agents','agent.grid','audit.recent']],
    // … one entry per Hermes page plus existing panel pages …
  ];
}
```

Each page descriptor: `id`, `route`, `nav:{label,icon,order,group}`, `required_role`, `feature_flags[]`, `blocks[]` (ordered `{instance,type,props,source}`), `theme_overrides{}`, `origin: core|config|plugin`.

**Block registry** (`src/lib/registry_blocks.php`):

```php
'kpi'      => ['props_schema'=>…, 'sources'=>['panel.bootstrap','hermes.status'], 'risk'=>'none'],
'table'    => ['props_schema'=>…, 'sources'=>['*allowlisted*'], 'risk'=>'none'],
'cardGrid' => …, 'form' => …, 'jsonEditor' => …, 'chat' => …,
'timeline' => …, 'chart' => …, 'terminalView' => ['risk'=>'privileged'],
'configEditor' => ['risk'=>'privileged','source'=>'hermes.config.schema'],
'auditView' => ['risk'=>'read']
```

Each block: server-side props schema, allowed data sources, required capability, renderer key, fixture name for render smoke tests, and an `ai_editable` flag.

**Data-source registry** (`src/lib/registry_sources.php`): maps a logical name → panel action or Hermes endpoint, method, query params, response whitelist, scrub rules, cache TTL and capability requirement. §5.2 is the starting set.

### 7.3 Versioned config schema (`ui_config`)

Tables to add (names illustrative):

```sql
CREATE TABLE ui_config_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schema_version INTEGER NOT NULL,
  revision INTEGER NOT NULL UNIQUE,
  parent_revision INTEGER,
  data TEXT NOT NULL,               -- validated JSON
  hash TEXT NOT NULL,               -- sha256(canonical_json(data))
  state TEXT NOT NULL,              -- draft|proposed|active|rejected|rolled_back
  author TEXT NOT NULL,             -- owner | agent:<slug> | ai:<session>
  reason TEXT NOT NULL DEFAULT '',
  approval_id INTEGER,
  test_result TEXT,                 -- render-smoke / schema result
  created_at INTEGER NOT NULL,
  activated_at INTEGER
);
CREATE TABLE ui_config_proposals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  revision_id INTEGER NOT NULL,
  diff TEXT NOT NULL,               -- structured JSON patch
  status TEXT NOT NULL DEFAULT 'pending',
  decided_by TEXT, decided_at INTEGER,
  expires_at INTEGER
);
```

Config document (v1 sketch):

```json
{
  "schema_version": 1,
  "revision": 42,
  "meta": {"title": "HERMES", "locale": "en", "description": "owner-authored"},
  "theme": {"tokens": {"--accent": "#ed8d30", "--radius": "18px"}, "font": "system", "theme": "ember"},
  "pages": [
    {"id":"overview","route":"/","nav":{"label":"Overview","icon":"◈","order":10},
     "capabilities":["panel.bootstrap"],
     "blocks":[{"instance":"kpi-1","type":"kpi","source":"panel.bootstrap","props":{"metrics":["agents","sessions","approvals"]}}]},
    {"id":"hermes-sessions","route":"/hermes/sessions","nav":{"label":"Hermes sessions","icon":"▤","order":110},
     "capabilities":["hermes.sessions.list"],
     "blocks":[{"instance":"sessions-table","type":"table","source":"hermes.sessions.list","props":{"columns":["id","display_name","model","message_count","started_at"]}}]}
  ],
  "feature_flags": {"hermes_proxy": true, "chat": true}
}
```

JSON Schema validation (server-side, before persistence):
- `schema_version` must equal current; unknown keys rejected (`additionalProperties:false`).
- Page `id` unique, `route` matches `^/[a-z0-9/-]*$`, nav icon from an approved glyph set.
- Every `type` exists in block registry; every `source` exists in source registry; `required_role`/capabilities granted to the proposing agent.
- `theme.tokens` keys restricted to a CSS custom-property allowlist; values must be color/length tokens (`^#[0-9a-fA-F]{3,8}$`, `^\d+px$`, …). No `url(`, `@import`, `expression(`, `<`, `javascript:`, `</style`.
- No string field may contain executable code; HTML is escaped by renderers, never trusted.
- Config size limits (e.g. 256 KB, ≤100 pages, ≤50 blocks/page).

### 7.4 Proposal / approval / audit flow

1. `ui.config.propose` — owner or an agent with `webui_edit`. Input: JSON patch (`add/remove/replace`) + reason. Server validates schema, capability checks, source allowlist and runs render smoke tests in a headless renderer. Stores a `draft` revision + structured diff. **Nothing changes on screen.**
2. `escalation.decide` (for agents) or direct owner approval — one-shot, TTL-bound; the approval id is written onto the revision.
3. `ui.config.approve` — owner-only. Rechecks hash, schema and smoke result, writes `state=active` atomically, emits `DB::audit('owner','ui.config.activate','rev=42 hash=…')`, invalidates the client manifest cache.
4. `ui.config.rollback` — activates a prior `active` revision, audited.
5. `ui.config.diff` / `ui.config.history` — AI-readable review endpoints returning structured patches, not raw DB dumps.
6. Agent proposals use the existing escalation mechanism but **pre-apply enforcement**: RBAC happens at proposal time and again at approval time.

### 7.5 Migration path

- **Phase 0:** Generate `ui_config` v1 from the current `NAV`, `TITLES`, `layout` blob and card order; keep the hard-coded switch as a fallback behind `feature_flags.config_driven_ui=false`.
- **Phase 1:** Render nav/title from config; blocks still hard-coded behind stable instance ids. Verify with UI render smoke tests against existing screenshots.
- **Phase 2:** Move block bodies into registered renderers; add `ui.config.*` API and proposal/approval flow; retire direct `layout.save`/`webui_edit` writes.
- **Phase 3:** Add Hermes proxy sources one page at a time (status → sessions → models → tools → cron → skills → plugins → …), each with its own capability flag and contract test.
- **Phase 4:** Add theme tokens, i18n and profile scoping; deep-link to `:9119` where a full Hermes feature is better left to Hermes.

---

## 8. Test architecture & scaling to 500+ tests

### 8.1 Current state

| Suite | Runs where | Checks | Covers |
|---|---|---|---|
| `tests/unit.php` | laptop, no Deck | ~30 | SQLite migrations, default agents, 8 redaction cases, RBAC matrix subset, escalation one-shot, tool-call parsing |
| `tests/tools.php` | inside panel container (copied by `docker cp`) | 12 | live host status/scan, vault list, forged HMAC refused, stale HMAC refused, engine-level RBAC |
| `tests/integration.sh` | Deck, root | 22 | container/broker services, login, bootstrap, scan, audit, HMAC tests, real temporary-secret leak probe, restart persistence |
| `tests/echo_server.py` | Deck helper | — | echoes injected secret header for the leak probe |

No JUnit output, no CI pipeline, no fixtures framework, no UI tests, no API-contract snapshots, no install-repo checks. Integration mutates the live vault (creates and removes `panel-leak-test`) and restarts the container.

### 8.2 Target layering (≈700 cases)

| Layer | Tooling | Target count | Example cases |
|---|---|---:|---|
| L0 Pure unit | PHPUnit 11 (or extended custom harness + data providers) | 120 | redaction fuzz (40): bearer/key/PEM/base64/unicode/multiline; RBAC primitives (40); tool-call parser (20); DB migration idempotence (20) |
| L1 API contract | PHPUnit + JSON Schema snapshots | 140 | 20 panel actions × {unauth, owner, malformed, happy} = 80; 60 curated Hermes endpoints × {shape schema, field whitelist} = 60 |
| L2 RBAC matrix | data provider | 180 | 12 tools × 4 agents × escalation states {none, pending, approved, expired, denied}, deduplicated to meaningful pairs; vault grant/no-grant; message opt-in |
| L3 HMAC / broker | PHPUnit + root broker harness | 40 | forged token, stale (±120 s boundaries), replay, tampered args, wrong action, read-only without token, unknown action, regex rejection for service/project/package, oversized payload |
| L4 UI render smoke | Vitest + jsdom (JS) + `gui-debug` screenshot for key pages | 85 | 19 pages × {empty, populated, error} = 57; 26 block types × one fixture = 26; login/logout 2 |
| L5 Config/AI-edit safety | PHPUnit + schema validator | 60 | unknown page/block/source, duplicate route, inline JS, `url()` CSS, oversized doc, wrong schema_version, diff replay, rollback, approval-less agent edit rejected |
| L6 Install-repo checks | shell + container build | 30 | fresh clone, secret files absent from image, file modes (key 0400, db dir 0770), migration on empty DB, broker socket perms, compose healthcheck, no secrets in logs |
| L7 Live Deck integration | existing `integration.sh`, split into idempotent scenarios | 25 | current 22 + auth CSRF, rate limit, state-diff endpoint, Hermes proxy health |
| **Total** | | **≈700** | |

### 8.3 Concrete scaling steps

1. **Adopt a runner with data providers.** Keep `tests/unit.php` as a quick smoke, add PHPUnit with `tests/unit/`, `tests/contract/`, `tests/rbac/`, `tests/hmac/`, `tests/config/`. Emit JUnit XML for Forgejo Actions.
2. **Fixtures, not live state.** Synthetic agents/sessions/escalations in fixture JSON; synthetic secret strings that are never real credentials. Hermes endpoint responses mocked from recorded shapes (§3.3) with values replaced by `<redacted>`.
3. **Parametrise everything.** A single `testToolMatrix` data provider generating 12 × 4 × 5 rows delivers 240 RBAC tests with ~20 lines of code. Same pattern for redaction, HMAC boundary values and API auth states.
4. **Property/fuzz tests.** Random strings around credential-ish patterns must never survive `Util::scrub()`; random JSON must never pass config schema unless explicitly valid.
5. **Contract snapshots.** Store canonical response-shape JSON per action/endpoint; CI fails when a key is added/removed without an intentional snapshot update. This is what prevents a proxy from accidentally exposing a secret-bearing field.
6. **UI without a browser farm.** Vitest + jsdom for all 19 pages and 26 blocks; reserve `gui-debug` Xvfb screenshots for 3–5 visual-regression anchors (overview, settings, chat).
7. **CI split.** Forgejo Actions on every push: L0–L6 in a built container (no Deck needed). Nightly/manual job on the Deck: L7 with cleanup traps; never run the live leak test on every commit.
8. **Determinism.** Freeze time for TTL tests; inject fake socket clients for broker tests; use separate `PANEL_DB` per test process (already done in `unit.php`).
9. **Coverage gates.** Aim for ≥90 % line coverage on `src/lib/`, 100 % of RBAC branches, 100 % of config-schema rejection paths.
10. **AI-editable tests.** When an AI adds a page/block, require it to add a fixture + render smoke case in the same proposal; the apply step runs that test and refuses activation on failure.

---

## 9. Prioritised gaps (top 10)

1. **No Hermes integration at all** — panel talks to OpenCode directly; no proxy, no deep links, no shared auth model.
2. **Agent UI edits are applied before approval** (`engine.php::webuiEdit`) — breaks the core security promise.
3. **No versioned config / page registry** — 9 views hard-coded in `app.js`; AI edits are raw JSON + arbitrary CSS.
4. **Approvals table is advisory only** — broker enforcement relies solely on escalation + HMAC; decision records are not cryptographically bound.
5. **Missing 19 Hermes dashboard pages** and their 264 API routes; especially sessions, files/git, models/providers, cron, skills, plugins, MCP, channels, profiles, config/env.
6. **No hermes toolset/skill surface** — our 12 prompt-driven tools vs Hermes' 26 toolsets and 39 skills.
7. **No analytics, logs, or cost/token accounting** in the panel.
8. **Weak web hardening** — no CSRF token, no login rate limit, whole-state 10 s polling, unvalidated model string.
9. **Audit trail is mutable** and lacks hash chaining; no config-change diff history.
10. **Test suite is ~64 checks with no CI** — nowhere near the coverage needed for a config-driven, AI-editable UI.

### Suggested sequence

`G1/G2 (safety) → config v1 + registries + proposal/approval → L0–L5 test layers → Hermes proxy status/sessions/models → remaining pages by feature flag → deep link or SSO to :9119 → L7 nightly expansion.`

---

## Appendix A — Evidence collected (no secrets)

- `run({name:"hermes-deck"})`: deployment paths, ports, model wiring, model ids, verification and gotchas.
- `docker inspect hermes`: single mount `/opt/hermes/data -> /opt/data`.
- `grep` of `@router.get/post/...` in `/opt/hermes/hermes_cli/web_routers` and `dashboard_auth/routes.py`: 264 unique paths.
- `grep` of `@router.websocket`: `/api/audio/speak-stream`, `/api/audio/transcribe-stream`, `/api/console`, `/api/display/ws`, `/api/events`, `/api/host-terminal`, `/api/pty`, `/api/pub`, `/api/ws`.
- `web/src/App.tsx`: built-in route table and nav items; `web/src/themes/presets.ts`: 8 theme names.
- `toolsets.py`: 26 toolset names + core tool list.
- Read-only authenticated probes: JSON shapes printed as key names/counts only (scripts were deleted from Deck and container after use).
- Deployed panel: HEAD `d4db947`; only executable-bit changes to `hostctl/*.py`; `panel.env` key names only; all three brokers active; `/api.php?action=me` returns `{"ok":true,"authenticated":false}`.

## Appendix B — Files deliberately not inspected in content

`panel.env`, `/opt/hermes/.env`, `/opt/hermes/data/.env`, vault secret values, HMAC key material, dashboard session cookies, API Bearer tokens, dashboard basic-auth password values. Their *existence, key names and file modes* are documented; values are not.
