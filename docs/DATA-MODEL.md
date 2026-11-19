# Data Model

This document describes every SQLite table created by the migration in `src/lib/db.php` (247 lines at the verified commit), including exact columns, types, defaults and constraints, relationships, row lifecycle, and which module reads and writes each table. It also explains the append-only audit hash chain and the UI config revision model. All DDL is `CREATE TABLE IF NOT EXISTS ...` executed once per connection in `DB::migrate()`, followed by in-place compatibility upgrades for legacy databases.

**Last verified:** 2026-11-19 against commit `0137eff test: add regressions for deep-debug fixes`.

## Storage and connection invariants

- Database path: `PANEL_DB`, else `PANEL_DATA/panel.db`, else `/var/lib/panel/panel.db`. The parent directory is created with mode `0770` if absent.
- PRAGMAs set on every connection: `journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout=5000`. Foreign-key cascades are therefore real, not documentary.
- `DB::migrate()` additionally adds `audit.prev_hash` and `audit.hash` with `ensureColumn()` (because `CREATE TABLE IF NOT EXISTS` cannot alter an existing legacy table), then backfills legacy audit rows by recomputing the chain in `id` order.
- One unique index is created: `CREATE UNIQUE INDEX IF NOT EXISTS idx_search_doc ON search_docs(kind, ref_id)`.

---

## Table reference

### `users` — owner accounts

| Column | Type | Constraints |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `username` | TEXT | NOT NULL, UNIQUE |
| `password_hash` | TEXT | NOT NULL (`password_hash(..., PASSWORD_DEFAULT)`) |
| `created_at` | INTEGER | NOT NULL (Unix seconds) |

**Lifecycle.** Provisioned by `Auth::ensureOwner()` from `PANEL_ADMIN_USER`/`PANEL_ADMIN_PASSWORD`: inserted when absent, and its `password_hash` overwritten whenever `password_verify()` fails (i.e. the environment password changed). No other module creates or deletes users. Default username is `admin`.

**Reads:** `Auth::ensureOwner()`, `Auth::login()`, `Auth::current()` (JOIN), `Setup::ownerExists()`. **Writes:** `Auth::ensureOwner()`.

### `logins` — active bearer sessions

| Column | Type | Constraints |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `user_id` | INTEGER | NOT NULL, `REFERENCES users(id) ON DELETE CASCADE` |
| `token_hash` | TEXT | NOT NULL, UNIQUE — SHA-256 of the raw cookie token |
| `created_at` | INTEGER | NOT NULL |
| `expires_at` | INTEGER | NOT NULL (`created_at + 12*3600`) |

**Lifecycle.** `Auth::login()` inserts a row with a 12 h expiry; `Auth::current()` selects where `token_hash = sha256(cookie)` and `expires_at > now`; `Auth::logout()` deletes the row. Expired rows are not proactively purged. Raw tokens are never stored.

**Reads/writes:** `Auth` only.

### `agents` — agent definitions

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `slug` | TEXT | NOT NULL, UNIQUE; created from lowercased `[a-z0-9_-]` in `api.php`, or from `RBAC::DEFAULTS` |
| `name` | TEXT | NOT NULL |
| `role` | TEXT | NOT NULL |
| `description` | TEXT | NOT NULL DEFAULT `''` |
| `system_prompt` | TEXT | NOT NULL DEFAULT `''` |
| `model` | TEXT | NOT NULL DEFAULT `'big-pickle'` |
| `tools` | TEXT | NOT NULL DEFAULT `'[]'` — JSON array of `RBAC::TOOLS` keys |
| `vault_names` | TEXT | NOT NULL DEFAULT `'[]'` — JSON array of logical secret names this agent may use |
| `can_message` | INTEGER | NOT NULL DEFAULT `1` — inbound messaging opt-in |
| `enabled` | INTEGER | NOT NULL DEFAULT `1` |
| `schedule` | TEXT | nullable; `daily HH:MM` or `every Nm` |
| `created_at` | INTEGER | NOT NULL |

**Lifecycle.** `RBAC::installDefaults()` seeds the four default agents (`system`, `webui`, `chat`, `security`) when their slug is absent, with `vault_names=[]`. Owner creates/updates/deletes through `agent.create`, `agent.update`, `agent.delete`; the latter refuses default slugs. Deleting an agent cascades to its sessions, escalations, approvals and messages.

**Reads:** `RBAC::agent()`, `Engine` (via `api.php chat.send`), `Search::index()`, `worker.php` (scheduled agents), `api.php` (`bootstrap`, `agent.message`, `session.get` JOINs). **Writes:** `RBAC::installDefaults()`, `api.php` (`agent.create/update/delete`).

### `agent_sessions` — conversation transcripts

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `agent_id` | INTEGER | NOT NULL, `REFERENCES agents(id) ON DELETE CASCADE` |
| `title` | TEXT | NOT NULL DEFAULT `'New session'` |
| `messages` | TEXT | NOT NULL DEFAULT `'[]'` — JSON array of `{role, content}` |
| `created_at` | INTEGER | NOT NULL |
| `updated_at` | INTEGER | NOT NULL |

**Lifecycle.** `Engine::newSession()` inserts; `Engine::chat()` appends the user turn and tool trace and persists the transcript (trimmed to the last 120 messages) with a fresh `updated_at`; `api.php` `session.delete` deletes. The transcript is a JSON blob, not a normalized message table — `messages` here is unrelated to the `messages` (inbox) table.

**Reads:** `api.php` (`bootstrap`, `sessions.list`, `session.get`, `chat.send`), `Search::index()` (last 500, last 20 messages each). **Writes:** `Engine::newSession()`, `Engine::chat()`, `api.php` `session.delete`.

### `escalations` — one-time capability grants

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `agent_id` | INTEGER | NOT NULL, `REFERENCES agents(id) ON DELETE CASCADE` |
| `capability` | TEXT | NOT NULL — tool name (e.g. `host_apply`) or `vault:<secret name>` |
| `reason` | TEXT | NOT NULL DEFAULT `''` (≤300 chars at request time) |
| `status` | TEXT | NOT NULL DEFAULT `'pending'` — `pending`, `approved`, `denied`, `expired` |
| `ttl_seconds` | INTEGER | NOT NULL DEFAULT `300` (clamped to 30–3600 when requested) |
| `decided_at` | INTEGER | nullable |
| `expires_at` | INTEGER | nullable; set on approval to `now + ttl_seconds` |
| `token_hash` | TEXT | nullable; SHA-256 of a 24-byte hex token generated on approval |
| `uses_left` | INTEGER | NOT NULL DEFAULT `1` |
| `created_at` | INTEGER | NOT NULL |

**Lifecycle.**
1. `Engine::executeTool()` on `request_escalation` → `RBAC::requestEscalation()` inserts `pending`, audits `escalation.request`.
2. Owner decides via `escalation.decide` → `RBAC::decide()`: approve sets `token_hash`, `expires_at`, `uses_left=1`, status `approved`; deny sets status `denied`. Decision is audited.
3. `RBAC::activeEscalation()` requires `status='approved'`, `uses_left>0`, `expires_at > now`. `RBAC::consumeEscalation()` decrements `uses_left` after a successful privileged tool call.
4. `RBAC::expirePending()` (invoked by the scheduler path) turns pending requests older than 300 s into `expired` and drops a timeout notice into the agent's inbox; it also expires approved rows whose `expires_at <= now`.

**Notable:** `token_hash` is written on approval but **no code reads or verifies it** at this commit — enforcement is entirely via status/`uses_left`/`expires_at`.

**Reads/writes:** `RBAC` (all transitions), `api.php` `bootstrap` (JOIN to agents, selects `pending` and `approved`), `tests/rbac/matrix.php`.

### `approvals` — recorded actions

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `agent_id` | INTEGER | nullable, `REFERENCES agents(id) ON DELETE CASCADE` |
| `kind` | TEXT | NOT NULL — e.g. `host_apply`, `webui_edit` |
| `summary` | TEXT | NOT NULL |
| `payload` | TEXT | NOT NULL DEFAULT `'{}'` — JSON of the proposed action/value |
| `status` | TEXT | NOT NULL DEFAULT `'pending'` |
| `created_at` | INTEGER | NOT NULL |
| `decided_at` | INTEGER | nullable |

**Lifecycle.** `Engine::hostApply()` and `Engine::webuiEdit()` insert rows before performing the action, "recorded for review". `approval.decide` updates `status`/`decided_at` for pending rows. Crucially, the recorded decision is **not** a gate: by the time the row exists, `hostApply()`/`webuiEdit()` have already (or are about to) run under the *escalation* gate. This table is an audit/visibility surface, not an enforcement point.

**Reads:** `api.php` `bootstrap` (pending rows), **Approvals** view "Recorded actions". **Writes:** `Engine::hostApply()`, `Engine::webuiEdit()`, `api.php` `approval.decide`.

### `messages` — agent inbox

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `from_agent` | INTEGER | nullable, `REFERENCES agents(id) ON DELETE CASCADE`; `NULL` for system/watcher notices |
| `to_agent` | INTEGER | NOT NULL, `REFERENCES agents(id) ON DELETE CASCADE` |
| `body` | TEXT | NOT NULL (scrubbed) |
| `created_at` | INTEGER | NOT NULL |
| `read_at` | INTEGER | nullable |

**Lifecycle.** Producers: `Engine::messageAgent()` (agent→agent, no self-message, target `can_message=1`), `api.php` `agent.message` (owner-initiated), `RBAC::expirePending()` (timeout notice), `worker.php` (`security` and `agents` wake-ups). Consumers: `Engine::systemPrompt()` injects the newest unread message; `Engine::chat()` marks all of that agent's messages read after a turn. `NULL from_agent` rows are system notices.

**Reads:** `Engine::systemPrompt()`, `api.php` `bootstrap`. **Writes:** `Engine::messageAgent()`, `api.php` `agent.message`, `RBAC::expirePending()`, `worker.php`.

### `findings` — security / self-test findings

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `source` | TEXT | NOT NULL — e.g. `hostctl`, `webui.selftest` |
| `severity` | TEXT | NOT NULL — e.g. `medium` |
| `title` | TEXT | NOT NULL |
| `detail` | TEXT | NOT NULL DEFAULT `''` |
| `status` | TEXT | NOT NULL DEFAULT `'open'` |
| `created_at` | INTEGER | NOT NULL |

**Lifecycle.** Inserted by `worker.php security` (dedup by `source`+`title`+`status='open'`), `worker.php tick` and `api.php selftest.run` (on a failing self-test). Read (open only) by `bootstrap`; read (all statuses, newest 200) by `findings.list`; indexed by `Search::index()`. No code closes a finding at this commit — status remains `open` once created.

**Reads/writes:** `worker.php`, `api.php` (`bootstrap`, `findings.list`, `selftest.run`), `Search::index()`.

### `layout` — key/value layout store

| Column | Type | Constraints / meaning |
|---|---|---|
| `key` | TEXT | PRIMARY KEY (e.g. `layout`, `notes`, or an agent-edited key) |
| `value` | TEXT | NOT NULL — JSON blob |
| `updated_by` | TEXT | NOT NULL DEFAULT `'owner'` (e.g. `agent`, `agent:<slug>`, `owner`) |
| `updated_at` | INTEGER | NOT NULL |

**Lifecycle.** Upserted via `INSERT ... ON CONFLICT(key) DO UPDATE`. `Engine::layoutSet()` writes on `notes_write` (key `notes`, last 100 entries) and `webui_edit` (validated key `/^[a-z0-9_.-]+$/`, also creates an approval row). `api.php` `layout.save` writes key `layout` with `updated_by='owner'` and **no `Schema` validation**. Read by `Engine::layoutGet()`, `bootstrap` (`SELECT value WHERE key='layout'`), and the SPA's `L()` helper.

**Reads/writes:** `Engine`, `api.php` (`bootstrap`, `layout.save`), `app.js` via bootstrap.

### `audit` — append-only hash-chained event log

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `actor` | TEXT | NOT NULL — `owner`, `system`, `scheduler`, `agent:<id>`, arbitrary proposal author |
| `action` | TEXT | NOT NULL — e.g. `login`, `tool.call`, `escalation.approve`, `ui.config.propose`, `host.fix_service`, `vault.use` |
| `detail` | TEXT | NOT NULL DEFAULT `''` — scrubbed, truncated to 2000 chars |
| `created_at` | INTEGER | NOT NULL |
| `prev_hash` | TEXT | nullable (backfilled) — hash of the preceding row |
| `hash` | TEXT | nullable (backfilled) — `sha256(prev_hash|actor|action|detail|created_at)` |

**Lifecycle.** Only `DB::audit()` inserts; there is no update/delete path. `DB::verifyAuditChain()` validates the chain. Read by `bootstrap` (newest 200), `audit.list` (newest 200), `Search::index()` (newest 500).

#### The audit hash chain

```
row_n.prev_hash = row_(n-1).hash                         ('' for the first row)
row_n.hash      = hash('sha256',
                    row_n.prev_hash . '|' . actor . '|' . action . '|'
                    . detail . '|' . created_at)
```

- The `detail` string is passed through `Util::scrub()` **before** hashing, so the committed text contains no secret.
- `DB::audit()` fetches the last non-null `hash` as `prev`, computes the digest, and inserts both columns in the same statement.
- `verifyAuditChain()` walks rows in `id ASC`, requiring `prev_hash` to equal the running previous hash and `hash_equals(expected, row.hash)` to hold; any mismatch returns `false`.
- Legacy databases created before the chain existed are upgraded by `ensureColumn()` and backfilled in `db.php::migrate()` using the same formula, so verification covers the full history.

### `ui_config_revisions` — immutable config snapshots

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT (the **revision id**) |
| `schema_version` | INTEGER | NOT NULL (`Schema::VERSION`, currently 1) |
| `revision` | INTEGER | NOT NULL, UNIQUE (the human-facing monotonic revision **number**) |
| `parent_revision` | INTEGER | nullable — revision number this proposal was based on |
| `data` | TEXT | NOT NULL — full config JSON snapshot |
| `hash` | TEXT | NOT NULL — SHA-256 of the snapshot with `revision` removed |
| `state` | TEXT | NOT NULL DEFAULT `'draft'` — `draft`, `active`, `superseded`, `rolled_back` |
| `author` | TEXT | NOT NULL — `system`, `owner`, or agent id/name; truncated to 120 chars |
| `reason` | TEXT | NOT NULL DEFAULT `''` |
| `approval_id` | INTEGER | nullable — the `ui_config_proposals.id` that activated this revision |
| `test_result` | TEXT | nullable (unused at this commit) |
| `created_at` | INTEGER | NOT NULL |
| `activated_at` | INTEGER | nullable |

**Lifecycle.** `ConfigGovernance::active()` inserts revision 1 as `active` when the table is empty. `propose()` appends a `draft` row with a new `revision` number. `approve()` sets the current `active` row to `superseded`, activates the draft, and records `approval_id`. `rollback()` sets the current `active` row to `rolled_back` and reactivates an older row — **without** changing its revision number or `data`. `nextRevision()` uses `MAX(revision)+1`, so numbers are never reused after a rollback.

#### Config revision model

```
default(rev 1, active)
   └─ propose(rev N, draft) ──approve──> rev N active; old active -> superseded
                                        (proposal status approved, approval_id set)
   └─ rollback(rev K) ────────────────> rev K active again; old active -> rolled_back
                                        (rev N stays in history, may be reactivated)
```

- `hash = sha256(json_encode(config_without_revision, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))`. Because `revision` is excluded, reactivating an older snapshot keeps its original hash.
- `parent_revision` records the base of each proposal; `history()` exposes it along with the latest proposal id via a correlated subquery.
- `ConfigGovernance::diff()` flattens two snapshots to dotted keys and emits `-`/`~`/`+` lines whose values are scrubbed and truncated to 80 chars.

### `ui_config_proposals` — pending config proposals

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `revision_id` | INTEGER | NOT NULL — the `ui_config_revisions.id` this proposal creates (no FK declared) |
| `diff` | TEXT | NOT NULL — JSON array of human-readable diff lines |
| `status` | TEXT | NOT NULL DEFAULT `'pending'` — `pending`, `approved` |
| `decided_by` | TEXT | nullable |
| `decided_at` | INTEGER | nullable |
| `expires_at` | INTEGER | nullable (`created_at + 86400` at proposal time) |

**Lifecycle.** `ConfigGovernance::propose()` inserts `pending`; `approve()` selects `status='pending'`, then sets `status='approved'` with `decided_by`/`decided_at`. `ConfigGovernance::history()` reads the latest proposal id per revision.

**Notable gap:** `expires_at` is written but **never read or enforced** anywhere in the code at this commit — `approve()` does not check it, and no job marks proposals expired. A 24-hour-old pending proposal can still be approved.

### `hermes_cache` — cached Hermes responses

| Column | Type | Constraints / meaning |
|---|---|---|
| `key` | TEXT | PRIMARY KEY — `<sourceId>:<md5(json_encode(params))>` |
| `value` | TEXT | NOT NULL — JSON of the whitelisted, scrubbed data |
| `fetched_at` | INTEGER | NOT NULL |
| `ttl` | INTEGER | NOT NULL — per-source TTL in seconds (15–600) |

**Lifecycle.** `Hermes::fetch()` checks `fetched_at + ttl > time()` for a cache hit; on a miss (or stale row) it fetches, whitelists, scrubs, and upserts. `Hermes::clearCache()` deletes all rows. Only scrubbed, field-whitelisted data is ever stored.

**Reads/writes:** `Hermes::fetch()` and `Hermes::clearCache()`.

### `stt_jobs` — local speech job log

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `status` | TEXT | NOT NULL — `error` or `done` |
| `duration` | REAL | nullable (currently always `NULL` on insert) |
| `engine` | TEXT | nullable (`whisper.cpp`) |
| `error` | TEXT | nullable (truncated to 200 chars) |
| `created_at` | INTEGER | NOT NULL |

**Lifecycle.** `STT::log()` inserts one row per failure or completed transcription from `STT::transcribe()`. No read path exists in the PHP code at this commit.

### `search_docs` — rebuilt search index

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `kind` | TEXT | NOT NULL — `page`, `agent`, `session`, `finding`, `audit`, `hermes` |
| `ref_id` | TEXT | NOT NULL — id/key of the source object |
| `title` | TEXT | NOT NULL (scrubbed, ≤200 chars) |
| `body` | TEXT | NOT NULL (scrubbed, ≤20,000 chars) |
| `updated_at` | INTEGER | NOT NULL |

**Constraint:** `UNIQUE (kind, ref_id)` via `idx_search_doc`.

**Lifecycle.** `Search::index()` deletes **all** rows and re-inserts from pages, agents, the last 500 sessions, findings, the last 500 audit rows, and Hermes source names (using `INSERT OR REPLACE`). `api.php` `search.query` calls `index()` on **every** request before `Search::query()`, so this table is a disposable derived index, not durable data.

### `schedule_runs` — internal job history

| Column | Type | Constraints / meaning |
|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `job` | TEXT | NOT NULL — `webui.selftest`, `host.security`, `agent.wake.<id>` |
| `status` | TEXT | NOT NULL — `pass`/`fail` (or broker-derived status for `host.security`) |
| `detail` | TEXT | nullable — JSON report/summary |
| `created_at` | INTEGER | NOT NULL |

**Lifecycle.** Written by `worker.php` (`tick`, `security`, `agents`) and `api.php` `selftest.run`. The latest `created_at` per `job` is the "last run" marker used by `Scheduler::due()` to decide whether the next `tick`/`agents` run is due. Read by `selftest.report` (latest `webui.selftest`) and by `worker.php`.

---

## Module read/write matrix

| Table | Written by | Read by |
|---|---|---|
| `users` | `Auth::ensureOwner` | `Auth`, `Setup` |
| `logins` | `Auth::login/logout` | `Auth::current` |
| `agents` | `RBAC::installDefaults`, `api.php agent.*` | `RBAC`, `Engine`, `Search`, `worker.php`, `api.php` |
| `agent_sessions` | `Engine::newSession/chat`, `api.php session.delete` | `api.php`, `Search` |
| `escalations` | `RBAC` (request/decide/consume/expire) | `RBAC`, `api.php bootstrap` |
| `approvals` | `Engine::hostApply/webuiEdit`, `api.php approval.decide` | `api.php bootstrap` (Approvals view) |
| `messages` | `Engine::messageAgent`, `api.php agent.message`, `RBAC`, `worker.php` | `Engine::systemPrompt`, `api.php bootstrap` |
| `findings` | `worker.php`, `api.php selftest.run` | `api.php bootstrap/findings.list`, `Search` |
| `layout` | `Engine::layoutSet`, `api.php layout.save` | `Engine::layoutGet`, `api.php bootstrap`, browser |
| `audit` | `DB::audit` (all modules) | `api.php bootstrap/audit.list`, `Search`, `DB::verifyAuditChain` |
| `ui_config_revisions` | `ConfigGovernance` | `ConfigGovernance`, `api.php bootstrap` |
| `ui_config_proposals` | `ConfigGovernance` | `ConfigGovernance` |
| `hermes_cache` | `Hermes::fetch/clearCache` | `Hermes::fetch` |
| `stt_jobs` | `STT::log` | (no PHP reader at this commit) |
| `search_docs` | `Search::index` | `Search::query` |
| `schedule_runs` | `worker.php`, `api.php selftest.run` | `worker.php`, `api.php selftest.report` |

## Relationship summary

```
users 1───* logins          (ON DELETE CASCADE)
agents 1───* agent_sessions (ON DELETE CASCADE)
agents 1───* escalations    (ON DELETE CASCADE)
agents 0..1──* approvals    (ON DELETE CASCADE; agent_id nullable)
agents 0..1──* messages     (from_agent, ON DELETE CASCADE; NULL = system)
agents 1───* messages       (to_agent, ON DELETE CASCADE)

ui_config_revisions 1 ──< ui_config_proposals   (logical: revision_id -> revisions.id, no FK)
ui_config_revisions.approval_id ──> ui_config_proposals.id (logical, no FK)
```

`layout`, `audit`, `findings`, `hermes_cache`, `stt_jobs`, `search_docs` and `schedule_runs` are standalone; no foreign keys are declared for them.
