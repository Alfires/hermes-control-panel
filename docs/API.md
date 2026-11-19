# HTTP API Reference

This document is the complete, code-derived reference for the JSON API in `src/public/api.php`. It covers all 52 actions in the file's `switch`, the matching 52 entries in `PanelActions::catalog()` (the two are checked equal by `tests/unit/hygiene.php`), the exact guards applied before dispatch, every request field and response shape, side effects, and the UI view that consumes each action. It documents only behaviour present in this repository. The newer declarative-page (`ui.page.*`) and Forgejo project (`project.*`) families are documented in their source modules and were added after this reference was first written; consult `src/public/ui-pages.php` and `src/public/projects-api.php` for their fields.

**Last verified:** 2026-11-19 against commit `0137eff test: add regressions for deep-debug fixes`.

---

## Transport, parameter source, and common guards

Every call is an HTTP request to:

```
/api.php?action=<action>
```

`api.php` reads the action from `$_GET['action']`. Mutations read their fields from `Util::body()` (JSON object, form-encoded body or `$_POST`); read-only actions read query parameters. The action catalogue's `method` field is now **enforced**: `PanelActions::assertMethod()` rejects a wrong verb with HTTP 405, and `PanelActions::catalog()` is injected into the page as `window.API_METHODS` so the SPA's `api()` helper picks the declared verb. All calls use `credentials: 'same-origin'` and the `X-CSRF-Token: <panel_csrf cookie>` header; POST calls also send `Content-Type: application/json`. The catalogue deliberately marks `bootstrap` as POST because it installs RBAC defaults.

Before the `switch`, three guards run (in this order):

| Guard | Applies to | Behaviour |
|---|---|---|
| `Auth::ensureOwner()` | **every** request, including `login` and `me` | Provisions/updates the owner row from `PANEL_ADMIN_USER` / `PANEL_ADMIN_PASSWORD`; empty password → HTTP 500. |
| **Same-origin** | every action **except** `login`, `me` | If `HTTP_ORIGIN` is present, its host and (effective) port must match `HTTP_HOST`; otherwise HTTP 403 `cross-origin request refused`. An absent `Origin` header is not rejected. |
| **Double-submit CSRF** | every action **except** `login`, `me` | Cookie `panel_csrf` and header `X-CSRF-Token` must both be non-empty and pass `hash_equals()`; otherwise HTTP 403 `invalid CSRF token`. The cookie is issued by `index.php` (`httponly=false`, 12 h). |

After the guards, every action **except** `login` and `me` calls `Auth::require()` inside its `case`. An unrecognised action falls through to HTTP 404 `unknown action`. All responses are emitted by `Util::json()` with `Cache-Control: no-store` and `application/json; charset=utf-8`.

---

## The 39 `switch` actions (implemented behaviour)

UI view names refer to the `NAV`/`TITLES` lists and render functions in `src/public/app.js`. "via bootstrap" means the view reads the data from the single `bootstrap` payload rather than calling the dedicated action directly.

### Identity, agents, sessions, chat

| Action | Method / guards | Request fields | Response | Side effects | UI view |
|---|---|---|---|---|---|
| `login` | POST; no auth, no origin/CSRF guard | `username` (max 120), `password` | `{"ok":true}`; on failure `{"ok":false,"error":"invalid credentials"}` (401), rate-limited (429), or missing-password 500 | Per-IP+user rate-limit file `hcp-login-<sha256>` in the system temp dir (10 attempts / 900 s, window stored with counter); `Auth::login()` inserts a `logins` row; sets `panel_session` cookie; audit `login` | Login form (`#login-form`) |
| `logout` | POST; auth + origin + CSRF | none | `{"ok":true}` | Deletes the `logins` row for the cookie token; expires the cookie | Sidebar **Lock** button |
| `me` | POST; **no auth**, no origin/CSRF guard | none | `{"ok":true,"authenticated":bool}` | None (but `ensureOwner()` ran at file top) | Startup authentication check |
| `bootstrap` | POST; auth + origin + CSRF | none | `{"ok":true, agents, tools, sessions, approvals, escalations, findings, audit, messages, layout, config, theme_presets, setup_complete, setup_requirements, stt, vault:{available,secrets}, host:{available}, hermes:{available}}` | `RBAC::installDefaults()` may insert the 4 default agents; `Setup::requirements()` may create the data dir and probes Hermes/STT | Initial app load and **Refresh**; data source for nearly every view |
| `agent.create` | POST; auth + origin + CSRF | `slug`, `name`, `role`, `description`, `system_prompt`, `model`, `tools[]`, `vault_names[]`, `can_message`, `enabled`, `schedule` | `{"ok":true,"id":int}`; 400 on empty/duplicate slug | INSERT into `agents` (tools/vault names intersected/cast, model default `big-pickle`); audit `agent.create` | **Agents** view create/edit form |
| `agent.update` | POST; auth + origin + CSRF | `id`, plus any of `name`, `role`, `description`, `system_prompt`, `model`, `schedule`, `tools[]`, `vault_names[]`, `enabled`, `can_message` | `{"ok":true}`; 404 if no such agent | Dynamic `UPDATE agents` of supplied fields only; audit `agent.update` | **Agents** view edit form |
| `agent.delete` | POST; auth + origin + CSRF | `id` | `{"ok":true}`; 400 when the agent is one of `RBAC::DEFAULTS` | DELETE `agents` (SQLite cascades to sessions/escalations/approvals/messages); audit `agent.delete` | **Agents** view delete button |
| `sessions.list` | POST; auth + origin + CSRF | none | `{"ok":true,"sessions":[s.*, agent_name]}` ordered by `updated_at DESC` | None | Sessions data normally arrives via `bootstrap`; no direct call in `app.js` |
| `session.get` | POST; auth + origin + CSRF | `id` from **`$_GET`** (query string) | `{"ok":true,"session":{...,"messages":[...decoded]}}`; 404 if missing | None | Chat context panel (`renderContext`) |
| `session.delete` | POST; auth + origin + CSRF | `id` in body | `{"ok":true}` | DELETE `agent_sessions` | **Sessions** view delete button |
| `chat.send` | POST; auth + origin + CSRF | `agent_id`, `session_id` (optional), `message` | `{"ok":true,"session_id":int,"reply":string,"trace":[...]}`; 404 for unknown agent | Creates a session when needed; runs up to 7 model/tool steps; may write `layout`, `messages`, `approvals`, `agent_sessions`, audits; marks inbox read | Chat overlay and Overview quick-chat |
| `agent.message` *(not in catalog)* | POST; auth + origin + CSRF | `agent_id` (sender), `agent` (target slug), `body` | `{"ok":true}`; 400/404 on unavailable or non-opted-in target | INSERT into `messages` with scrubbed body; **no audit row** in this path | **Settings** agent-messaging form |

### Approvals, security, findings

| Action | Method / guards | Request fields | Response | Side effects | UI view |
|---|---|---|---|---|---|
| `escalation.decide` | POST; auth + origin + CSRF | `id`, `approve` (truthy) | `{"ok":bool}` (false when the escalation is no longer pending) | `RBAC::decide()`: on approve sets `token_hash`, `expires_at = now + ttl_seconds`, `uses_left=1`; on deny sets `status='denied'`; audit `escalation.approve`/`deny` | **Approvals** view Approve / Deny buttons |
| `approval.decide` | POST; auth + origin + CSRF | `id`, `approve` (truthy) | `{"ok":true}` | UPDATE `approvals.status` (`approved`/`denied`) + `decided_at`, pending rows only. **Advisory**: it does not gate `host_apply`/`webui_edit`, which have already executed (see SECURITY.md) | No direct `app.js` call at this commit; "Recorded actions" in **Approvals** is display-only |
| `security.scan` | POST; auth + origin + CSRF | none | The verbatim `HostCtl::call('security_scan', [], null)` result (`ok`, findings, …) | Read-only host-broker call; hostctl audit row. This API action itself persists no findings (the `worker.php security` job does) | **Security** view "Run scan now" — **safe live probe** |
| `findings.list` | POST; auth + origin + CSRF | none | `{"ok":true,"findings":[...]}` (all statuses, newest 200) | None | **Security** view normally uses `bootstrap.findings`; no direct call |

### Layout, config governance, themes, audit

| Action | Method / guards | Request fields | Response | Side effects | UI view |
|---|---|---|---|---|---|
| `layout.save` *(not in catalog)* | POST; auth + origin + CSRF | `value` (arbitrary JSON) | `{"ok":true}` | Upsert `layout` key `layout` with `updated_by='owner'`; audit `layout.save`. **Not** validated by `Schema` | **Settings** layout save / default-model save |
| `state.diff` | POST; auth + origin + CSRF | none | `{"ok":true,"version":int,"counts":{agents,sessions,approvals,findings}}` | None | No direct `app.js` call at this commit |
| `ui.config.propose` | POST; auth + origin + CSRF | `patches[]`: each `{op: add\|replace\|remove, path, value}` | `ConfigGovernance::propose()` result: `{"ok":true,revision,hash,diff,state:'draft',proposal_id}` or `{"ok":false,errors:[...]}` | Applies patches, validates schema, inserts a `draft` revision + `pending` proposal, audit `ui.config.propose` | **Settings** config editor |
| `ui.config.approve` | POST; auth + origin + CSRF | `id` (proposal id) | `ConfigGovernance::approve()` result | Supersedes the old active revision, activates the target, marks proposal approved, audit `ui.config.approve` | **Settings** history "Approve" |
| `ui.config.rollback` | POST; auth + origin + CSRF | `revision` (revision number) | `ConfigGovernance::rollback()` result | Marks active `rolled_back`, activates the target revision, audit `ui.config.rollback` | **Settings** history "Rollback" |
| `ui.config.history` | POST; auth + origin + CSRF | none | `{"ok":true,"history":[...]}` (all revisions ASC with proposal ids) | None | **Settings** history panel |
| `ui.config.diff` | POST; auth + origin + CSRF | `from`, `to` (revision **ids**) | `{"ok":true,"diff":[strings]}`; 404 if either id unknown | None | No direct `app.js` call; diff logic lives in `ConfigGovernance::diff()` |
| `theme.presets` | POST; auth + origin + CSRF | none | `{"ok":true,"presets":{...}}` | None | **Themes** view reads `bootstrap.theme_presets`; no direct call |
| `theme.set` | POST; auth + origin + CSRF | `preset` (key of `Theme::presets()`) | Result of `ConfigGovernance::approve()`; 400 on unknown preset | Builds four `replace` patches (`/theme/preset`, `/theme/mode`, `/theme/radius`, `/theme/tokens`), proposes **and immediately approves** them as `owner`; audits both | **Themes** preset buttons |
| `audit.list` | POST; auth + origin + CSRF | none | `{"ok":true,"audit":[...]}` (newest 200) | None | **Audit** view normally uses `bootstrap.audit`; no direct call |

### Vault, Hermes, speech, self-test, setup, probes

| Action | Method / guards | Request fields | Response | Side effects | UI view |
|---|---|---|---|---|---|
| `vault.test` | POST; auth + origin + CSRF | `name`, `kind` (`http`\|`file`\|`exec`), `purpose`, `params` | Verbatim `Vault::use()` reply, scrubbed | Broker `op=use` with synthetic agent `{id:0, slug:'owner-test'}`; broker still requires owner approval; audit `vault.use` | **Vault** "Agent secret injection (test)" form |
| `vault.list` | POST; auth + origin + CSRF | none | `Vault::listSecrets()` → `{"ok":bool,"secrets":[{name,label,allow,approval}],"error":string}` — **no values** | Broker `op=list` | **Vault** view normally uses `bootstrap.vault.secrets`; no direct call |
| `search.query` | POST; auth + origin + CSRF | `q`, `kinds[]` (optional) | `{"ok":true,"query":string,"results":[{kind,ref_id,title,snippet}]}` | **Rebuilds the whole `search_docs` index on every call** (`Search::index()`) | **Search** view |
| `hermes.source` | POST; auth + origin + CSRF | `source` (body or `$_GET`), `params` (optional object) | `Hermes::fetch()` → `{"ok":true,"data":…,"cached":bool}` or `{"ok":false,"error":…,"offline":bool}` | Outbound request to an allowlisted Hermes path; upsert into `hermes_cache` | **Hermes** view area tiles |
| `hermes.deep_link` | POST; auth + origin + CSRF | `page` | `{"ok":true,"url":string}` | None (URL built only from `PageRegistry::ids()`, else bare dashboard URL) | No direct `app.js` call at this commit |
| `stt.status` | POST; auth + origin + CSRF | none | `STT::status()` → `{"ok":true,"available":bool,"engine":bool,"endpoint":"loopback only","models":[...],"max_bytes":10485760}` | None | **Voice** view (also in `bootstrap`) |
| `stt.transcribe` | POST (multipart); auth + origin + CSRF | file field **`audio`**; required `UPLOAD_ERR_OK`, size ≤ 10 MiB | `STT::transcribe()` → `{"ok":true,"text":…,"duration":…,"engine":"whisper.cpp (local)"}` or `{"ok":false,"error":…}` (400 for bad upload) | Temporary file (removed in `finally`); loopback POST to `/inference`; INSERT `stt_jobs` | **Voice** recorder |
| `selftest.run` | POST; auth + origin + CSRF | none | `{"ok":true} + Scheduler::runSelfTest()` → `ok,pages,checks,failed,ran_at` | INSERT `schedule_runs`; opens a `medium` finding on failure; audit `selftest.run` | **Self test** "Run now" |
| `selftest.report` | POST; auth + origin + CSRF | none | `{"ok":true,"run":{id,status,detail,created_at}\|null}` | None | **Self test** report load |
| `panel.host.probe` *(not in catalog)* | POST; auth + origin + CSRF | none | `HostCtl::probeForged()` → `{"ok":true,"refused":bool,"detail":string}`; `{"ok":false,"error":"broker unavailable"}` when socket absent | Sends a deliberately forged privileged request (`token='bad-token'`); broker logs the refusal; **no state change** | No direct `app.js` call; used by live checks — **safe live probe** |
| `panel.redaction.probe` *(not in catalog)* | POST; auth + origin + CSRF | none | `{"ok":bool,"sample":string}` | Scrubs the fixed string `vault value: {{secret}}`; no external call, no state change | No direct `app.js` call; used by live checks — **safe live probe** |
| `setup.status` | POST; auth + origin + CSRF | none | `Setup::status()` → `{"ok":true,"complete":bool,"steps":[...],"requirements":[...],"config":<full active config>}` | `requirements()` may create the data dir and probes Hermes/STT | **Setup** view |
| `setup.complete` | POST; auth + origin + CSRF | none | `Setup::finish('owner')` → `{"ok":true}` or `{"ok":true,"already":true}` | Writes `setup.lock` JSON (`completed_at`, `actor`, `revision`); audit `setup.complete` | **Setup** view finish step |

---

## Safe live-probe actions

Three actions are explicitly safe to invoke against a live deployment to verify the security plumbing without changing application state. They are the ones the live test suite (`tests/live/livechecks.php`) uses for this purpose:

| Action | What it proves | Why it is safe |
|---|---|---|
| `panel.host.probe` | The root broker refuses a forged privileged request (`refused=true`). | `HostCtl::probeForged()` sends `fix_service` with the literal token `bad-token`. The broker rejects it before any fix runs; the only effect is a refusal line in the broker log. |
| `panel.redaction.probe` | The redactor replaces a secret placeholder (`sample` contains `CENSORED`). | It scrubs the hard-coded string `vault value: {{secret}}` in-process. No socket, no database, no config change. |
| `security.scan` | The read-only host-broker path responds. | `security_scan` is in `HostCtl::READ_ONLY`; the broker performs reconnaissance only and PHP does not persist findings on this path. (The separate `worker.php security` job is what stores findings.) |

Also non-destructive, but **not** classified as pure probes here: `selftest.run` (writes `schedule_runs`, may open a finding, writes an audit row), `selftest.report`, `stt.status`, `state.diff`, and all `*.list`/`*.history`/`*.diff`/`audit.list` readers.

---

## `PanelActions::catalog()` — the declared catalogue

`src/lib/registry.php` (129 lines) declares 35 actions. For every entry, `capability = 'panel.' . str_replace('.', '_', $name)` and `response = {"ok":"boolean","data":"object"}`. Only `login` and `me` have `owner_only=false`. The `id` is the 1-based position in the source array.

| # | Action | Catalog method | Capability | Owner only |
|---:|---|---|---|---|
| 1 | `login` | POST | `panel.login` | no |
| 2 | `logout` | POST | `panel.logout` | yes |
| 3 | `me` | GET | `panel.me` | no |
| 4 | `bootstrap` | GET | `panel.bootstrap` | yes |
| 5 | `state.diff` | GET | `panel.state_diff` | yes |
| 6 | `agent.create` | POST | `panel.agent_create` | yes |
| 7 | `agent.update` | POST | `panel.agent_update` | yes |
| 8 | `agent.delete` | POST | `panel.agent_delete` | yes |
| 9 | `sessions.list` | GET | `panel.sessions_list` | yes |
| 10 | `session.get` | GET | `panel.session_get` | yes |
| 11 | `session.delete` | POST | `panel.session_delete` | yes |
| 12 | `chat.send` | POST | `panel.chat_send` | yes |
| 13 | `escalation.decide` | POST | `panel.escalation_decide` | yes |
| 14 | `approval.decide` | POST | `panel.approval_decide` | yes |
| 15 | `security.scan` | POST | `panel.security_scan` | yes |
| 16 | `findings.list` | GET | `panel.findings_list` | yes |
| 17 | `search.query` | GET | `panel.search_query` | yes |
| 18 | `ui.config.propose` | POST | `panel.ui_config_propose` | yes |
| 19 | `ui.config.approve` | POST | `panel.ui_config_approve` | yes |
| 20 | `ui.config.rollback` | POST | `panel.ui_config_rollback` | yes |
| 21 | `ui.config.history` | GET | `panel.ui_config_history` | yes |
| 22 | `ui.config.diff` | GET | `panel.ui_config_diff` | yes |
| 23 | `hermes.source` | POST | `panel.hermes_source` | yes |
| 24 | `stt.transcribe` | POST | `panel.stt_transcribe` | yes |
| 25 | `stt.status` | GET | `panel.stt_status` | yes |
| 26 | `selftest.run` | POST | `panel.selftest_run` | yes |
| 27 | `selftest.report` | GET | `panel.selftest_report` | yes |
| 28 | `theme.presets` | GET | `panel.theme_presets` | yes |
| 29 | `theme.set` | POST | `panel.theme_set` | yes |
| 30 | `vault.test` | POST | `panel.vault_test` | yes |
| 31 | `vault.list` | GET | `panel.vault_list` | yes |
| 32 | `audit.list` | GET | `panel.audit_list` | yes |
| 33 | `setup.status` | GET | `panel.setup_status` | yes |
| 34 | `setup.complete` | POST | `panel.setup_complete` | yes |
| 35 | `hermes.deep_link` | POST | `panel.hermes_deep_link` | yes |

The method is chosen by the regex `^(me|bootstrap|state\.diff|.*\.list|.*\.get|.*\.history|.*\.diff|.*\.status|.*\.report|theme\.presets|search\.query|vault\.list|audit\.list|setup\.status)$`; everything else is POST. This metadata is **not** enforced by `api.php` — the switch does not inspect `$_SERVER['REQUEST_METHOD']`, and the SPA POSTs every call.

---

## Catalog vs switch reconciliation

- Every one of the 35 catalogue entries has a matching `case` in `api.php`.
- Four implemented switch cases have **no catalogue entry**:

| Switch-only action | Why it matters |
|---|---|
| `layout.save` | Writes arbitrary JSON to the `layout` table without `Schema` validation; owner-only and CSRF-protected, but not capability-tagged. |
| `agent.message` | Owner-initiated agent-to-agent message; no audit row in this path. |
| `panel.host.probe` | Forged-HMAC live probe; intentionally kept out of the capability catalogue. |
| `panel.redaction.probe` | Redaction live probe; intentionally kept out of the capability catalogue. |

Any client that derives its allowlist from `PanelActions::catalog()` alone will not see these four actions. This is a real discrepancy at the verified commit, not a documentation omission.
