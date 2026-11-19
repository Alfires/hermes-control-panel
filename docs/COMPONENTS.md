# Backend Components

This document is an exhaustive, code-derived reference for every backend PHP file in the Hermes Control Panel (`src/lib/*.php`, `src/worker.php`, and the three files under `src/public/`). For each file it records the purpose, the public API (classes and methods), key behaviours, invariants that must not be broken, its exact line count at the verified commit, and a notable call graph. It describes only what the code in this repository actually does; it does not propose features. Line counts are physical lines reported by `wc -l`.

**Last verified:** 2026-11-19 against commit `0137eff test: add regressions for deep-debug fixes`.

---

## File index

| File | Lines | Primary class(es) | Role |
|---|---:|---|---|
| `src/lib/auth.php` | 71 | `Auth` | Owner provisioning, login sessions, cookies |
| `src/lib/config.php` | 213 | `ConfigGovernance` | Versioned, proposal-driven UI configuration |
| `src/lib/db.php` | 247 | `DB` | SQLite connection, migrations, audit hash chain |
| `src/lib/engine.php` | 265 | `Engine` | Agent runtime: model calls, tool dispatch, turn loop |
| `src/lib/hermes.php` | 97 | `Hermes` | Scrubbed, allowlisted, cached proxy to Hermes |
| `src/lib/hostctl.php` | 110 | `HostCtl` | Client for the root-owned host-control broker |
| `src/lib/install.php` | 32 | `InstallChecks` | Static checks of compose file and installer script |
| `src/lib/prompt.php` | 35 | `Prompt` | Casual-language prompt normalisation |
| `src/lib/rbac.php` | 165 | `RBAC` | Tool catalogue, permission checks, escalation lifecycle |
| `src/lib/registry.php` | 129 | `PanelActions`, `HermesSources`, `PageRegistry`, `BlockRegistry` | Declarative capability registries |
| `src/lib/render.php` | 63 | `Render` | Server-side page/block HTML renderer |
| `src/lib/scheduler.php` | 50 | `Scheduler` | Schedule parsing, due calculation, self-test |
| `src/lib/schema.php` | 171 | `Schema` | Validation of AI-editable config |
| `src/lib/search.php` | 74 | `Search` | Index build and unified search |
| `src/lib/setup.php` | 67 | `Setup` | First-launch wizard state machine |
| `src/lib/stt.php` | 69 | `STT` | Loopback whisper.cpp proxy |
| `src/lib/theme.php` | 65 | `Theme` | Theme presets, token validation, CSS vars |
| `src/lib/util.php` | 144 | `Util` | JSON/body helpers, redaction, socket client, parsing |
| `src/lib/vault.php` | 74 | `Vault` | List/use-only client for root-owned secret broker |
| `src/worker.php` | 106 | (CLI script) | Scheduled internal jobs |
| `src/public/api.php` | 390 | (front controller) | All JSON actions |
| `src/public/index.php` | 102 | (HTML shell) | Serves the SPA shell and CSRF cookie |
| `src/public/health.php` | 6 | (health endpoint) | Unauthenticated liveness probe |

**Total: 2,745 lines** across the 23 files.

---

## `src/lib/auth.php` — 71 lines

**Purpose.** Provisions the owner account from the environment, issues and validates bearer-style session tokens stored as hashes in SQLite, and manages the `panel_session` cookie.

**Public API (`final class Auth`)**

| Method | Signature | Returns | Notes |
|---|---|---|---|
| `ensureOwner` | `static ensureOwner(): void` | — | Called at the top of both front controllers. Reads `PANEL_ADMIN_USER` (default `admin`) and `PANEL_ADMIN_PASSWORD` (default `''`). Empty password → HTTP 500 JSON error. If no row exists, or `password_verify` fails, writes a fresh `password_hash(..., PASSWORD_DEFAULT)` (UPDATE if the user row exists, INSERT otherwise) and emits audit action `owner-credentials-synced`. |
| `login` | `static login(string $user, string $pass): ?string` | raw token or `null` | Verifies credentials; on success creates a 32-random-byte hex token, stores `hash('sha256', $token)` in `logins`, with `created_at = time()` and `expires_at = time() + 12*3600`. Only the hash is persisted; the raw token is returned to the caller. |
| `current` | `static current(): ?array` | login row joined to user, or `null` | Reads `$_COOKIE['panel_session']`, hashes it, joins `logins` to `users`, requires `expires_at > time()`. |
| `require` | `static require(): array` | current login row | Calls `ensureOwner()` first, then `current()`; emits HTTP 401 JSON `unauthorized` if absent. |
| `setCookie` | `static setCookie(string $token): void` | — | `panel_session`, 12 h expiry, `path=/`, `httponly=true`, `samesite=Lax`, `secure` only when `$_SERVER['HTTPS']` is set and not `off`. |
| `logout` | `static logout(): void` | — | Deletes the `logins` row matching the cookie token hash and expires the cookie. |

**Key behaviours.** Owner credentials are "environment-authoritative": a change to `PANEL_ADMIN_PASSWORD` overwrites the stored hash on the next request (not only at install time). Session tokens are single-use secrets at rest — only SHA-256 digests are stored, so a database dump does not yield usable cookies. There is no per-user role model: all authenticated callers are the owner.

**Important invariants.**
- Never store or log the raw token; only `hash('sha256', $token)`.
- `require()` must call `ensureOwner()` so a fresh database still has an owner after a container rebuild.
- Cookie is HTTP-only (unlike the CSRF cookie); never set `httponly=false` here.

**Notable call graph.** `db.php`, `util.php` → `Auth` ← `api.php` (every action except `login`/`me` calls `Auth::require()`), `index.php` (`ensureOwner()`), `health.php` (does *not* call Auth). `Auth::login()` ← `api.php` `case 'login'`.

---

## `src/lib/config.php` — 213 lines

**Purpose.** Implements versioned, append-only UI configuration governance. An agent (or the owner) may *propose* JSON Patch-style changes; the active revision changes only on owner approval or rollback.

**Public API (`final class ConfigGovernance`)**

| Method | Signature | Notes |
|---|---|---|
| `defaultConfig` | `static defaultConfig(): array` | Builds the initial config: `schema_version = Schema::VERSION`, `revision = 1`, `meta`, `theme` (from `Theme::presets()['brezel-light-rounded']['tokens']`), `pages` (from `PageRegistry::builtin()`), `feature_flags`, `prompts`, `schedule`. Gives `pages[0]['blocks'][0]['props']['metrics'] = ['agents','sessions']` so AI patches have an array item to target. |
| `active` | `static active(): array` | Returns the `data` JSON of the highest-revision row with `state='active'`; if none, inserts revision 1 (`state='active'`, `author='system'`, `reason='initial configuration'`) and returns the default. |
| `currentRevision` | `static currentRevision(): int` | `(int)(active()['revision'] ?? 1)`. |
| `nextRevision` | `static nextRevision(): int` | `MAX(revision)` from `ui_config_revisions` and current revision, `+1`. Never reuses a rolled-back number. |
| `propose` | `static propose(array $patches, string $author): array` | 1–100 patches; applies each with `applyPatch`; validates with `Schema::validateConfig`; stamps `revision`, `hash`, `diff`; inserts a `draft` revision row and a `pending` proposal row (`expires_at = now + 86400`); audits `ui.config.propose`. Returns `ok, revision, hash, diff, state='draft', proposal_id` or `ok=false, errors`. |
| `approve` | `static approve(int $proposalId, string $actor): array` | Only `status='pending'` proposals whose revision is still `draft`. Sets the old active revision to `superseded`, activates the target (`approval_id`, `activated_at`), marks proposal `approved` with `decided_by/decided_at`, audits `ui.config.approve`. |
| `rollback` | `static rollback(int $revisionNumber, string $actor): array` | Marks active `rolled_back`, activates the requested revision, audits `ui.config.rollback`. The revision number stays in history. |
| `history` | `static history(): array` | All revisions ordered by revision ascending, with latest proposal id per revision. |
| `proposal` / `revision` | `static proposal(int $id): ?array`, `static revision(int $id): ?array` | Single-row lookups. |
| `diff` | `static diff(array $before, array $after): array` | Human-readable `- key: value`, `~ key: old -> new`, `+ key: value` lines from flattened configs; values run through `Util::scrub()` and `short()` (80 chars). |

Private helpers: `flatten()` (dotted keys, booleans as `true`/`false`), `short()`, `hash()` (SHA-256 of `json_encode` with `revision` removed and `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`), `e()` (`mb_substr` to 120 chars, used for author/actor fields), and `applyPatch()`.

**`applyPatch` rules (important invariants).**
- `op` must be one of `add`, `replace`, `remove`; `path` must start with `/`.
- 1–12 path segments; first segment must be one of `ALLOWED_ROOTS = ['meta','theme','pages','feature_flags','prompts','schedule']` — `schema_version` and `revision` are *not* agent-editable through patches.
- Each segment must match `/^[A-Za-z0-9_-]+$/` and be non-empty; `remove` cannot target a top-level section.
- `add` with a final `-` appends to an array; traversal through a scalar is rejected.
- Every accepted candidate still passes full `Schema::validateConfig()` before it is stored.

**Notable call graph.** Requires `db.php`, `schema.php`, `registry.php`, `render.php` (Render is used indirectly by `Scheduler::runSelfTest`). Called by `api.php` (`bootstrap`, `state.diff`, all `ui.config.*`, `theme.set`, `setup.status`), `index.php` (`active()`), `search.php` (`active()` unused at runtime but imported), `setup.php` (`active()`, `currentRevision()`), `scheduler.php`/`worker.php` (`active()`), and `health.php` (no).

---

## `src/lib/db.php` — 247 lines

**Purpose.** Owns the SQLite PDO connection, creates/migrates the 16-table schema, and provides the append-only SHA-256 hash-chained audit log.

**Public API (`final class DB`)**

| Method | Signature | Notes |
|---|---|---|
| `path` | `static path(): string` | `PANEL_DB`, else `PANEL_DATA/panel.db`, else `/var/lib/panel/panel.db`. |
| `init` | `static init(): self` | Drops the cached PDO and reconnects (tests with a fresh `PANEL_DB`, installer relocation). |
| `pdo` | `static pdo(): PDO` | Lazily creates the connection: `@mkdir(dirname($path), 0770, true)`, PDO with `ERRMODE_EXCEPTION` and `FETCH_ASSOC`, then `PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;` and runs `migrate()`. |
| `run` / `one` / `all` | `static run(string $sql, array $args = []): PDOStatement`, `static one(...): ?array`, `static all(...): array` | Thin prepared-statement wrappers. |
| `audit` | `static audit(string $actor, string $action, string $detail = ''): void` | Scrubs and truncates `detail` to 2000 chars, reads the last non-null `audit.hash` as `prev`, computes `hash('sha256', prev.'|'.actor.'|'.action.'|'.detail.'|'.now)`, inserts `(actor, action, detail, created_at, prev_hash, hash)`. |
| `verifyAuditChain` | `static verifyAuditChain(): bool` | Walks `audit` ASC with a running `prev`; fails if any row's `prev_hash` differs or `hash_equals(expected, row.hash)` fails. |

Private: `migrate(PDO)` (creates the 16 tables + `CREATE UNIQUE INDEX idx_search_doc ON search_docs(kind, ref_id)`, then `ensureColumn($p,'audit','prev_hash','TEXT')` and `... 'hash','TEXT')`, then backfills legacy rows by recomputing the chain in id order), and `ensureColumn()` (`PRAGMA table_info` + `ALTER TABLE ... ADD COLUMN` when missing).

**Key behaviours.** The migration is idempotent. Legacy `audit` tables without hash columns are upgraded in place and backfilled so the chain stays verifiable from the beginning of time. Foreign keys are on for the connection lifetime, so `ON DELETE CASCADE` is enforced by SQLite (see DATA-MODEL.md).

**Important invariants.**
- Audit rows are *append-only*: no code path updates or deletes them; `verifyAuditChain()` is the integrity check.
- The hash preimage is exactly `prev_hash|actor|action|detail|created_at` with literal `|` separators; changing the order or separators breaks verification.
- `Util::scrub()` is applied to `detail` before hashing, so the chain commits to the redacted text, not to a secret.

**Notable call graph.** Requires `util.php`. Called by essentially every module (`auth.php`, `config.php`, `engine.php`, `hostctl.php`, `rbac.php`, `vault.php`, `search.php`, `setup.php`, `stt.php`, `worker.php`, `api.php`, `index.php` via Auth).

---

## `src/lib/engine.php` — 265 lines

**Purpose.** The agent runtime. The model has no native tools: it emits a `TOOL_CALL:` line which this class parses, checks against RBAC, dispatches to a backend, scrubs the result, and feeds back as `TOOL_RESULT:`.

**Public API (`final class Engine`)**

| Method | Signature | Notes |
|---|---|---|
| `modelEndpoint` | `static modelEndpoint(): string` | `PANEL_LLM_URL` or `http://host.docker.internal:4097/v1/chat/completions`. |
| `modelKey` | `static modelKey(): string` | `PANEL_LLM_KEY` or `''`. |
| `callModel` | `static callModel(array $messages, string $model, int $maxTokens = 1400): array` | cURL POST of `{model, messages, max_tokens, temperature: 0.3, stream: false}` with `Authorization: Bearer <modelKey>`; 180 s total / 10 s connect timeout; returns `ok=false,error` on transport or non-2xx (body truncated to 300 chars) and `ok=true,text` (scrubbed) on success. |
| `parseToolCall` | `static parseToolCall(string $text): ?array` | Accepts a single `TOOL_CALL: {"name":...,"args":{...}}` line, a fenced ````json` block with a `tool` key, or whole-text JSON with `tool`; normalises to `['name'=>…, 'args'=>…]`. |
| `executeTool` | `static executeTool(array $agent, string $name, array $args): array` | Lowercases/trims the name. Handles `request_escalation` and `noop` as meta-tools; otherwise `RBAC::can()`, then a `match` dispatch (see below). On a successful privileged call, calls `RBAC::consumeEscalation()` (`vault_use` consumes `vault:<name>`). Audits `tool.denied` or `tool.call` with actor `agent:<id>`. |
| `chat` | `static chat(array $agent, array &$session, string $input): array` | One agent turn. Up to 7 model steps; appends the scrubbed user input (max 6000 chars), parses tool calls, executes them, appends a scrubbed tool trace (each result JSON truncated to 2000 chars) and a `TOOL_RESULT:` user message. Trims the transcript to the last 120 messages, persists it to `agent_sessions`, marks the agent's inbox read, and returns `['reply'=>…, 'trace'=>…]`. |
| `newSession` | `static newSession(array $agent, string $title = 'New session'): array` | Inserts an `agent_sessions` row and returns it. |

`executeTool` dispatch table (`match`): `chat` → `['ok'=>true,'message'=>'continue']`; `notes_read` → `layoutGet('notes')`; `notes_write` → `notesWrite()`; `host_status`/`host_scan`/`cve_lookup` → `HostCtl::call()`; `host_apply` → `hostApply()`; `webui_read` → `layoutGet('layout')`; `webui_edit` → `webuiEdit()`; `vault_list` → `Vault::listSecrets()`; `vault_use` → `Vault::use()`; `message_agent` → `messageAgent()`; default → `tool <name> has no implementation`.

Private helpers: `systemPrompt()` (lists only the agent's RBAC tools plus `request_escalation`/`noop`, injects the newest unread inbox message scrubbed, and instructs: *"Never invent a result. Never output, guess, repeat or summarise a password, token or secret value."*), `hostApply()` (validates action against `HostCtl::ACTIONS`, inserts a `host_apply` approval row *for review*, then calls the broker), `layoutGet`/`layoutSet` (JSON values in `layout`), `notesWrite()` (append scrubbed 1000-char note, keep last 100), `webuiEdit()` (key regex `/^[a-z0-9_.-]+$/`, inserts a `webui_edit` approval row, then writes `layout`), `messageAgent()` (by slug, enabled, no self-message, `can_message` opt-in, scrubbed 1500-char body).

**Important invariants.**
- `RBAC::can()` is the only gate to a backend; it must be called before any tool implementation.
- Privileged escalations are consumed only when `!empty($result['ok'])`.
- All model text, tool output, notes, and messages pass through `Util::scrub()` before re-entering the conversation or being persisted.
- The 7-step and 120-message caps prevent unbounded model loops/context growth.

**Notable call graph.** Requires `db.php`, `util.php`, `rbac.php`, `vault.php`, `hostctl.php`. `api.php` `case 'chat.send'` → `Engine::newSession()` / `Engine::chat()` → `callModel()` → `parseToolCall()` → `executeTool()` → `RBAC::can()` → `HostCtl::call()` / `Vault::use()` / `layoutGet()` / `messageAgent()`. Note `Util::parseToolCall()` is a *stricter, separate* parser (exactly one call, `args` must be an array) used by tests; the engine uses its own implementation.

---

## `src/lib/hermes.php` — 97 lines

**Purpose.** Narrow, scrubbed proxy to the Hermes gateway. The browser never sees the backend key and only curated sources with field whitelists may be reached.

**Public API (`final class Hermes`)**

| Method | Signature | Notes |
|---|---|---|
| `baseUrl` | `static baseUrl(): string` | `HERMES_URL` or `http://hermes:8642`. |
| `dashboardUrl` | `static dashboardUrl(): string` | `HERMES_DASHBOARD_URL` or `http://hermes:9119`. |
| `available` | `static available(): bool` | `baseUrl() !== ''` (a configuration check, not a network probe). |
| `fetch` | `static fetch(string $sourceId, array $params = []): array` | Resolves `HermesSources::get()`, refuses unknown/forbidden paths, checks `hermes_cache` (`sourceId:md5(json_encode(params))`, valid when `fetched_at + ttl > now`), substitutes `{param}` placeholders using `rawurlencode(Util::str($v,80))` for keys matching `/^[A-Za-z0-9_-]+$/`, errors on any remaining `{`, performs the whitelisted HTTP method with `Accept: application/json` and optional `Authorization: Bearer <HERMES_API_KEY>` (timeout 6 s), then whitelists fields, `Util::scrubDeep()`s the result, upserts the cache and returns `ok,data,cached`. On transport failure returns `ok=false,offline=true,data=[]`. |
| `capabilities` | `static capabilities(): array` | `fetch('hermes.gateway.capabilities')['data']['capabilities'] ?? []`. |
| `deepLink` | `static deepLink(string $page): string` | Returns `dashboardUrl()/page` only if `$page` is in `PageRegistry::ids()`; otherwise the bare dashboard URL. |
| `clearCache` | `static clearCache(): void` | `DELETE FROM hermes_cache`. |

Private: `whitelist()` (handles both a flat object and a list of objects), `pick()` (keeps only named fields).

**Important invariants.** Source paths come only from `HermesSources::catalog()`; `HermesSources::isForbidden()` is checked again at request time; field whitelisting and deep scrubbing happen before caching and before the response leaves PHP. Cache keys include a hash of the params so different queries cannot collide.

**Notable call graph.** Requires `db.php`, `registry.php`, `util.php`. `api.php` `case 'hermes.source'` → `Hermes::fetch()`; `case 'hermes.deep_link'` → `Hermes::deepLink()`; `bootstrap` → `Hermes::available()`; `setup.php::requirements()` → `Hermes::baseUrl()`. `search.php` imports `hermes.php` and indexes `HermesSources` keys.

---

## `src/lib/hostctl.php` — 110 lines

**Purpose.** Client for the root-owned host-control broker. Only fixed action IDs cross the Unix-socket boundary; privileged actions are HMAC-signed with a root-only key.

**Public API (`final class HostCtl`)**

| Member | Notes |
|---|---|
| `const ACTIONS` | 8 allowlisted IDs with descriptions: `system_status`, `security_scan`, `cve_lookup`, `fix_updates`, `fix_service`, `fix_docker`, `fix_ssh`, `reboot`. |
| `const READ_ONLY` | `['system_status','security_scan','cve_lookup']` — these three need no HMAC. |
| `socket()` (private) | `HOSTCTL_SOCKET` or `/run/panel-host.sock`. |
| `keyPath()` (private) | `PANEL_HOST_KEY` or `/run/secrets/hostctl.key`. |
| `available(): bool` | `file_exists(socket())`. |
| `call(string $action, array $args = [], ?array $agent = null): array` | Rejects unknown actions; sends `op=action`, `action`, `args` cast to object, and `requestor = 'panel' . ($agent ? ':' . slug : '')` over `Util::sock()` (240 s timeout). For non-read-only actions reads the key, builds `ts = (string)time()`, canonicalises recursively sorted args with `json_encode((object)sortedArgs($args), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)`, and sets `token = hash_hmac('sha256', "$action|$canonical|$ts", trim($key))`. Recursively scrubs the reply, audits `host.<action>` with actor `agent:<id or 0>` and args truncated to 500 chars, and returns the reply. |
| `probeForged(): array` | **Safe live probe.** If broker unavailable returns `ok=false,error`. Otherwise sends a privileged `fix_service` request with `service='live-probe'`, `requestor='panel:live-probe'`, current `ts` and the literal token `'bad-token'`; returns `ok=true, refused=empty($reply['ok']), detail=$reply['error'] ?? 'no reply'`. No state is changed because the token is invalid. |
| `scrub()` (private) | Recursive `Util::scrub()` over strings. |

`sortedArgs()` recursively `ksort(..., SORT_STRING)` so PHP's canonical JSON matches Python's `json.dumps(sort_keys=True, separators=(',', ':'), ensure_ascii=False)` in `hostctl.py`.

**Important invariants.**
- The action ID must exist in `ACTIONS`; agents never supply a command string or binary.
- HMAC is computed over the **sorted** args object so multi-key actions sign identically on both sides.
- Args are cast to `(object)` so an empty set is `{}` rather than `[]` on both sides of the HMAC.
- Timestamps are current Unix seconds; the broker rejects requests outside a ±120 s skew.

**Notable call graph.** Requires `util.php`, `db.php`. Called by `Engine::executeTool()` (`host_status`, `host_scan`, `cve_lookup`, `host_apply`), `worker.php` (`security_scan`), `api.php` (`security.scan`, `bootstrap` availability, `panel.host.probe` → `probeForged()`).

---

## `src/lib/install.php` — 32 lines

**Purpose.** Static (string-level) checks used by the test suite for the two-container compose file and the one-line installer script. No parsing library and no execution.

**Public API (`final class InstallChecks`)**

| Method | Notes |
|---|---|
| `composeServices(string $yaml): array` | Line scanner: after a top-level `services:` line, collects indented two-space keys matching `/^  ([A-Za-z0-9_-]+)\s*:\s*(?:#.*)?$/` until the first non-indented line. |
| `installerSafe(string $script): bool` | Requires `set -euo pipefail`, a trailing `main "$@"`, presence of `mktemp`, `trap`, `openssl rand`, `chmod 600`, absence of `set -x`, absence of `echo/printf` of a variable whose name contains `SECRET|TOKEN|PASSWORD|KEY`, and `curl -fsSL` if any `curl -s` appears. |

**Notable call graph.** Called by `tests/install/compose.php` (tests only); no runtime callers.

---

## `src/lib/prompt.php` — 35 lines

**Purpose.** Wraps messy human input in a small prompt envelope so a non-technical owner need not phrase requests like an engineer.

**Public API (`final class Prompt`)**

| Method | Notes |
|---|---|
| `normalise(string $raw, array $agent = [], array $config = []): string` | Trims and truncates input to 8000 chars; emits labelled lines `ROLE` (agent role, 120 chars), `TOOLS_AVAILABLE`, `USER_INPUT`, `INTERPRET_AS: a request from a non-technical owner…`, a `STYLE` line derived from `config['prompts']['style']` (`concise` → ≤4 lines, `detailed` → thorough plain answer, else plain), `SAFETY: never reveal secrets; describe privileged actions before asking for approval`, and an `OUTPUT` line. |
| `hint(): string` | Static one-line composer hint shown under the input box. |

**Key behaviour.** The user's words are kept verbatim inside `USER_INPUT`; only context is added. Invariants: input truncation to 8000, the safety line is always present.

**Notable call graph.** Requires `util.php`. Imported by `api.php`; no call site in the switch at this commit.

---

## `src/lib/rbac.php` — 165 lines

**Purpose.** The permission engine and tool catalogue. No tool reaches a backend except through `RBAC::can()`, and every call is audited by the caller (`Engine`).

**Public API (`final class RBAC`)**

| Member | Notes |
|---|---|
| `const TOOLS` | 12 tools with `risk` (`none`, `read`, `write`, `privileged`) and `desc`: `chat`(none), `notes_read`/`host_status`/`host_scan`/`cve_lookup`/`webui_read`/`vault_list`(read), `notes_write`/`message_agent`(write), `host_apply`/`webui_edit`/`vault_use`(privileged). |
| `const DEFAULTS` | 4 seeded agents: `system` (chat, host_status, host_scan, host_apply, message_agent), `webui` (chat, webui_read, webui_edit, message_agent), `chat` (chat, notes_read, notes_write), `security` (chat, host_scan, cve_lookup, host_apply, vault_list, vault_use, message_agent; schedule `daily 03:15`). All have `vault => []`, `can_message => 1`, model `big-pickle`. |
| `installDefaults` | `static installDefaults(): void` | Inserts any default agent whose slug is absent. |
| `agent` | `static agent(int $id): ?array` | Enabled agents only; decodes `tools` and `vault_names` JSON to arrays. |
| `can` | `static can(array $agent, string $tool, array $args = []): array` | Returns `[bool, reason]`. Lowercases the tool; rejects unknown tools and tools absent from the agent's list. Special cases: `vault_use` needs `name` in `vault_names` **or** an active escalation for `vault:<name>`; `message_agent` rejects self-address and `can_message=0`. `privileged` tools require `activeEscalation($agentId, $tool)`. |
| `activeEscalation` | `static activeEscalation(int $agentId, string $capability): bool` | Row with `status='approved'`, `uses_left>0`, `expires_at>time()`, newest first. |
| `consumeEscalation` | `static consumeEscalation(int $agentId, string $capability): void` | Decrements `uses_left` on the newest matching approved escalation. |
| `requestEscalation` | `static requestEscalation(int $agentId, string $capability, string $reason, int $ttl): int` | Clamps TTL to 30–3600 s, inserts a `pending` escalation, audits `escalation.request`. |
| `decide` | `static decide(int $id, bool $approve): bool` | Pending rows only. Approve generates a 24-random-byte hex token, stores only `hash('sha256',$token)` in `token_hash`, sets `expires_at = now + ttl_seconds`, `uses_left=1`; deny sets status `denied`. Audits `escalation.approve`/`escalation.deny` as `owner`. |
| `expirePending` | `static expirePending(): void` | Pending requests older than 300 s become `expired` and get a timeout notice in the agent's inbox (`from_agent=NULL`); approved rows with `expires_at <= now` also become `expired`. |

**Key behaviours.** New agents start with an explicit tool list and zero vault grants. Escalations are one-use, short-TTL, per-capability-string grants. The escalation token hash is stored but is *not* re-presented at use time — `activeEscalation()` relies on status/uses/expiry columns (see SECURITY.md residual risks).

**Notable call graph.** Requires `db.php`. `Engine::executeTool()` → `can()`, `requestEscalation()`, `consumeEscalation()`; `api.php` `bootstrap` → `installDefaults()`, `agent.*` → `agent()`, `escalation.decide` → `decide()`; `tests/rbac/matrix.php` exercises `can()` directly.

---

## `src/lib/registry.php` — 129 lines

**Purpose.** Declarative registries. Capability lives in code; configuration may only compose what is registered here.

**Classes and API**

- `PanelActions::catalog(): array` — 35 actions. `login` and `me` are the only public entries (`owner_only=false`). Method is `GET` when the name matches the read regex (`me`, `bootstrap`, `state.diff`, `*.list`, `*.get`, `*.history`, `*.diff`, `*.status`, `*.report`, `theme.presets`, `search.query`, `vault.list`, `audit.list`, `setup.status`), otherwise `POST`. Each entry has `capability = 'panel.' . str_replace('.', '_', $name)`, `response = ['ok'=>'boolean','data'=>'object']`, `owner_only`, and a 1-based `id`.
- `HermesSources::catalog(): array` — 20 whitelisted sources built by a `def()` closure: `method=GET`, `path`, `fields` (response whitelist), `capability`, `ttl`, `scrub=true`. Paths include `/health`, `/v1/sessions{/{id}}`, `/v1/models`, `/v1/toolsets`, `/v1/skills`, `/v1/cron/jobs`, `/v1/analytics`, `/api/themes`, `/api/config/schema`, `/api/env/names`, `/api/messaging/platforms`, `/api/webhooks`, `/v1/capabilities`, `/api/logs`, `/api/files/meta`, `/api/git/status`, `/api/profiles`, `/api/plugins`, `/api/mcp`.
  - `FORBIDDEN_FRAGMENTS` (private): `/env/reveal`, `/config/raw`, `/debug-share`, `/files/ticket`, `/pty`, `/host-terminal`, `/auth/native/token`, `/ws`, `/audio/transcribe-stream`, `/plugin-providers`.
  - `isForbidden(string $path): bool` — true for empty path or any forbidden substring; `get(string $id): ?array` — catalog lookup.
- `PageRegistry::builtin(): array` — 32 pages in three groups: Core (10: overview, sessions, agents, approvals, security, vault, settings, audit, setup, search), Hermes (19: hermes-status … hermes-chat), Workspace (3: voice, themes, self-test). Each page has `id`, `route` (`/` for overview, else `/<id>`), `nav` (label/icon/order/group), `capabilities=['panel.bootstrap']`, one `table` block sourcing `panel.bootstrap`, and `origin='core'`. `ids(): array` returns the keys.
- `BlockRegistry::catalog(): array` — 17 block types; `ai_editable=false` and `risk='privileged'` for `terminalView` and `configEditor`, all others `ai_editable=true`, `risk='none'`.

**Notable call graph.** Pure declarations, required by `config.php`, `render.php`, `hermes.php`, `schema.php`, `search.php`, `tests/contract/*`.

---

## `src/lib/render.php` — 63 lines

**Purpose.** Server-side page/block renderer. All interpolated values are escaped; no raw HTML from config is emitted. The client hydrates blocks with API data.

**Public API (`final class Render`)**

| Method | Notes |
|---|---|
| `page(string $id, array $config, array $opts = []): string` | Builds a `<section class="hcp-page" data-page=… data-mode=…>` with header (title from page config or `PageRegistry::builtin()`), group badge, optional error notice, one `block()` per configured block, and a mode-dependent footer (`populated` → two sample rows; `empty` → "Nothing here yet"; `error` → alert). Unknown pages render an "Unknown page" notice rather than failing. |
| `block(string $type, array $props, array $opts = []): string` | Emits a `<div class="hcp-block hcp-block-<type>" data-block=… data-instance=… data-mode=…>` with a title and either empty text, an error paragraph, or two static sample list items. |

Private `e()` uses `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

**Invariants.** Every dynamic value passes through `e()`. `mode` is one of `empty`, `populated`, `error` (default `empty`). No raw HTML from config is emitted.

**Notable call graph.** Requires `registry.php`. Called by `Scheduler::runSelfTest()` (`Render::page(..., ['mode'=>'populated'])`) and `tests/ui/render.php`.

---

## `src/lib/scheduler.php` — 50 lines

**Purpose.** Schedule-expression parsing, due-date calculation, and the internal read-only self-test.

**Public API (`final class Scheduler`)**

| Method | Notes |
|---|---|
| `parse(mixed $expr): ?array` | Accepts `daily HH:MM` (exact two digits, hour ≤ 23, minute ≤ 59) → `['kind'=>'daily','hour'=>…,'minute'=>…]`; `every Nm` (N ≥ 1) → `['kind'=>'every','minutes'=>…]`; anything else → `null`. |
| `due(?array $parsed, int $now, int $lastRun): bool` | `every` → `now - lastRun >= minutes*60`; `daily` → today's UTC target via `strtotime(...)`, due when `now >= target && lastRun < target`. |
| `runSelfTest(array $config): array` | Renders every configured page via `Render::page(...,'populated')` (check ok when HTML length > 20) and adds a trivial check per `HermesSources::catalog()` key. Returns `ok, pages, checks, failed, ran_at`. Does not change config. |

**Notable call graph.** Requires nothing directly (uses `class_exists()` guards for `Render`/`HermesSources`). Called by `worker.php` (`tick`) and `api.php` (`selftest.run`); `schema.php` uses `Scheduler::parse()` to validate `schedule.self_test`.

---

## `src/lib/schema.php` — 171 lines

**Purpose.** Dependency-free validation of the AI-editable site config. Everything an agent may change is checked here before a proposal is stored.

**Public API (`final class Schema`)**

| Member | Notes |
|---|---|
| `const VERSION = 1` | Only accepted `schema_version`. |
| `TOP_KEYS` (private) | `schema_version, revision, meta, theme, pages, feature_flags, prompts, schedule` — any other top-level key is an error. |
| `BLOCK_TYPES` (private) | The same 17 types as `BlockRegistry`. |
| `BAD_MARKERS` (private) | `<script`, `onerror=`, `onload=`, `javascript:`, `</style`, `@import`, `url(`. |
| `validateConfig(mixed $cfg): array` | Returns `['ok'=>bool,'errors'=>string[]]`. Checks: top-key whitelist; `schema_version===1`; positive-int `revision`; `meta` keys/type/length (title 1–200, description ≤4000, locale `en|de|fr|es`) and BAD_MARKERS in title/description; `theme` keys with preset in `Theme::presets()`, `mode light|dark`, `radius rounded|square`, `font system|sans|mono|serif`, and `Theme::validateTokens()`; `pages` 1–100 with valid/unique slugs, routes starting `/` with safe chars, nav object free of BAD_MARKERS, valid capabilities, ≤50 blocks each with valid instance/type/source/props (props JSON scanned for BAD_MARKERS); `feature_flags` limited to 5 boolean keys; `prompts.style` in plain|concise|detailed|casual-ok and boolean `normalise`; `schedule.self_test` parseable by `Scheduler::parse()`. |
| `validSource(string $source): bool` | Rejects names matching `/(secret|reveal|raw|debug|ticket|token|pty|terminal)/i` and any `/api/` prefix; allows `panel.bootstrap, panel.agents, panel.sessions, panel.findings, panel.audit, panel.approvals, panel.config` plus `HermesSources::catalog()` keys. |
| `validCapability(string $cap): bool` | Rejects `/(secret|reveal|raw|debug|admin)/i`; requires `/^[a-z][a-z0-9_.]{1,60}$/`. |

**Notable call graph.** Requires `util.php`, `theme.php`, `scheduler.php`, `registry.php`. `ConfigGovernance::propose()` → `Schema::validateConfig()`; `tests/unit/schema.php` exercises it directly.

---

## `src/lib/search.php` — 74 lines

**Purpose.** Unified search across pages, agents, sessions, findings, audit, and Hermes source names.

**Public API (`final class Search`)**

| Method | Notes |
|---|---|
| `index(): int` | Clears `search_docs`, then inserts scrubbed documents with `updated_at=now`: pages (from `PageRegistry::builtin()`, title and group+route+capabilities), agents (title/body from slug, role, description, system_prompt), sessions (last 500 by `updated_at`, last 20 messages concatenated), findings, audit (last 500), and one item per `HermesSources::catalog()` key. Titles truncated to 200, bodies to 20,000 chars. Returns the final row count. |
| `query(string $q, array $kinds = [], int $limit = 20): array` | Scrubs and truncates `q` to 120; empty query → `['ok'=>true,'results'=>[]]`. `LIKE %q%` on title/body; optional kind filter intersected with `page|agent|session|finding|audit|hermes`; limit clamped 1–50; orders by title-match first then `updated_at DESC`; results are `kind, ref_id, title, snippet` (snippet 180 chars, scrubbed). |

**Notable call graph.** Requires `db.php`, `registry.php`, `hermes.php`, `config.php`, `util.php`. `api.php` `case 'search.query'` calls `Search::index()` then `Search::query()` on every request (the index is rebuilt per query).

---

## `src/lib/setup.php` — 67 lines

**Purpose.** Idempotent first-launch wizard state machine driven by a lock file.

**Public API (`final class Setup`)**

| Method | Notes |
|---|---|
| `lockPath(): string` | `(PANEL_DATA ?: '/var/lib/panel') . '/setup.lock'`. |
| `complete(): bool` | `is_file(lockPath())`. |
| `requirements(): array` | Six checks with `id`, `label`, `ok`, `level` (`fail` or `warn`): PHP ≥ 8.3, writable data dir, SQLite PDO driver, Hermes `/health` reachable (3 s timeout, warn), STT `status()['available']` (warn), `/run/panel-sockets` directory present (warn). |
| `status(): array` | `ok=true`, `complete`, six numbered steps (steps 3–6 gated on `complete()`; step 4 also on `ownerExists()`), `requirements()`, and the full active config (`ConfigGovernance::active()`). |
| `finish(string $actor = 'owner'): array` | Idempotent (`already=true` when complete). Writes lock JSON `{completed_at, actor (scrubbed), revision (currentRevision())}`, audits `setup.complete`. |

Private `ownerExists()` checks for any `users` row.

**Notable call graph.** Requires `db.php`, `hermes.php`, `stt.php`, `config.php`, `util.php`. `api.php` (`bootstrap`, `setup.status`, `setup.complete`), `index.php` (`ensureOwner` via Auth, plus config), `worker.php` (required).

---

## `src/lib/stt.php` — 69 lines

**Purpose.** Loopback-only whisper.cpp proxy; no cloud service and no extra container.

**Public API (`final class STT`)**

| Member | Notes |
|---|---|
| `endpoint(): string` | `STT_ENDPOINT` or `http://127.0.0.1:8713`. |
| `modelDir(): string` | `STT_MODEL_DIR` or `/opt/stt/models`. |
| `status(): array` | Checks for `ggml-small.en-q5_0.bin`, `ggml-base.en-q5_0.bin`, `ggml-large-v3-turbo-q5_0.bin`; pings `/health` (1 s). Returns `ok=true, available=(engine && models), engine, endpoint='loopback only', models, max_bytes=10*1024*1024`. |
| `transcribe(string $tmpFile, string $filename = 'audio.webm'): array` | Refuses when not `available` (logs `error`). Builds a multipart POST to `/inference` (timeout 60 s) with a sanitised basename, extracts `text` (max 4000 chars), logs `done` with duration, and returns `ok,text,duration,engine='whisper.cpp (local)'`. |

Private `log()` inserts an `stt_jobs` row with status and truncated (200-char) error.

**Notable call graph.** Requires `db.php`, `util.php`. `api.php` (`bootstrap`, `stt.status`, `stt.transcribe`); `setup.php::requirements()`.

---

## `src/lib/theme.php` — 65 lines

**Purpose.** Theme presets, CSS token validation, and CSS variable emission for the config-driven UI.

**Public API (`final class Theme`)**

| Method | Notes |
|---|---|
| `presets(): array` | Seven presets: `brezel-light-rounded` (default), `brezel-dark-rounded`, `brezel-light-square`, `brezel-dark-square`, `midnight`, `ember`, `mono`. Each has `mode`, `radius`, `label`, `tokens`. Base constants: `INK=#172b40`, `ACCENT=#ec8621`. |
| `validateTokens(array $tokens): bool` | ≤ 40 tokens; keys match `/^--[a-z0-9-]{1,40}$/`; string values ≤ 120 chars and free of `url(`, `@import`, `expression(`, `<`, `javascript:`, `</style`, `;`; value must be a hex colour, a number with optional unit, a shadow triple, `rgb()/rgba()`, or one of `system|sans|mono|serif|auto`. |
| `cssVars(?string $presetId, array $overrides = []): string` | Merges preset tokens with validated overrides and emits only tokens that individually pass `validateTokens`, joined by `;`. |

**Notable call graph.** Pure. Used by `ConfigGovernance::defaultConfig()`, `Schema::validateConfig()`, `api.php` (`theme.presets`, `theme.set`, `bootstrap`), `index.php` (preset/mode/radius selection).

---

## `src/lib/util.php` — 144 lines

**Purpose.** Shared helpers: HTTP I/O, input coercion, the last-line-of-defence redactor, tool-call parsing, slug validation, and Unix-socket client.

**Public API (`final class Util`)**

| Method | Notes |
|---|---|
| `json($value, int $status = 200): void` | Sets HTTP status, `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, echoes `json_encode(..., JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)` and exits. |
| `body(): array` | Reads `php://input`; decodes JSON to array, else `parse_str()` form body, else `$_POST`. |
| `str($v, int $max = 4000): string` | Scalar-only cast, trim, `substr` to `$max`. |
| `scrub(?string $text): string` | Redaction: `Authorization` header/bearer tokens, credential-named `key=value` and JSON fields, `{{secret}}`, PEM private-key blocks, AWS `AKIA…`, OpenAI `sk-…`, GitHub `ghp_…`, the synthetic `TEST-SECRET-DO-NOT-USE-…` marker, `Bearer+…`, and long base64-like strings (32+ chars). |
| `scrubDeep(mixed $value): mixed` | Recursive `scrub()` over strings and arrays. |
| `parseToolCall(string $text): ?array` | Stricter parser than `Engine::parseToolCall()`: rejects empty/>8000-char text, accepts multiple `TOOL_CALL:` lines but requires **exactly one** valid call with a string `name` and an array `args`; also accepts a fenced JSON block or whole-text JSON. |
| `validSlug(string $value): bool` | `/^[a-z0-9][a-z0-9_-]{0,63}$/`. |
| `sock(string $socket, array $payload, int $timeout = 30): array` | Connects to `unix://<socket>` (5 s connect), writes `json_encode(payload, JSON_UNESCAPED_SLASHES) . "\n"`, reads until newline, decodes JSON; returns structured `ok=false,error` on every failure mode. |
| `now(): int` | `time()`. |

**Invariants.** `scrub()` is deliberately aggressive (a redaction is safer than a leak); every externally sourced string should pass through `scrub()`/`scrubDeep()` before display or persistence. `json()` always terminates the request with `exit`.

**Notable call graph.** Required by nearly every file; `HostCtl`, `Vault`, `Engine`, `DB::audit`, `ConfigGovernance::short()`, `api.php` all depend on it.

---

## `src/lib/vault.php` — 74 lines

**Purpose.** Thin client for the Deck's existing root-owned vault broker. The panel can only **list** and **use** secrets; there is no read/get operation.

**Public API (`final class Vault`)**

| Method | Notes |
|---|---|
| `socket()` (private) | `VAULT_SOCKET` or `/run/vault-broker.sock`. |
| `available(): bool` | `file_exists(socket())`. |
| `listSecrets(): array` | Sends `op=list`; keeps only `name`, `label`, `allow`, `approval` per secret — **no values** — and scrubs label and error. Returns `ok, secrets, error`. |
| `use(array $agent, array $args): array` | Accepts only `kind` in `http|file|exec` (else error), plus `name` (120), `purpose` (300), and an array `params`. Sends `op=use` with `requestor='panel agent: <slug>'` and a 120 s timeout; the agent never supplies or receives a value. Scrubs the whole reply with `scrubTree()`, audits `vault.use` with `name/kind/purpose/result`, and returns the reply. |

Private `scrubTree()` recursively applies `Util::scrub()`.

**Invariants.** No value ever enters an argument or a response; double scrubbing (broker + PHP) is mandatory; `kind` is constrained to the three registered injection modes.

**Notable call graph.** Requires `util.php`, `db.php`. `Engine::executeTool()` (`vault_list`, `vault_use`), `api.php` (`bootstrap`, `vault.list`, `vault.test`).

---

## `src/worker.php` — 106 lines

**Purpose.** CLI entry point for internal scheduler jobs; runs inside the webui container, with no host systemd units.

**Public API (CLI).** `$cmd = $argv[1] ?? 'tick'`. Unknown commands print `usage: worker.php [tick|agents|security]` to STDERR and exit 1.

| Command | Behaviour |
|---|---|
| `tick` (default) | Loads `ConfigGovernance::active()`, parses `schedule.self_test` (default `daily 04:20`), finds the last `schedule_runs` row for `webui.selftest`, and when `Scheduler::due()` runs `Scheduler::runSelfTest()`. Inserts a `schedule_runs` row (`pass`/`fail`), opens a `medium` `finding` on failure, audits `selftest.daily`, prints `self-test PASS|FAIL`, exits 0. |
| `security` | Calls `HostCtl::call('security_scan', [], null)` (read-only). Stores each new `hostctl` finding (dedup by source+title+status open), inserts a `host.security` run row, drops an inbox message into the `security` agent telling it to propose **one** `host_apply` fix per issue, audits `security.scan`. Nothing is applied here. |
| `agents` | For each enabled scheduled agent except `security`, parses its schedule, checks the last `agent.wake.<id>` run with `Scheduler::due()`, inserts a plain-language wake-up message (`from_agent=NULL`), records a `schedule_runs` row, audits `agent.wake`. |

**Invariants.** The header comment records that `agents` and `security` were previously missing and silently printed usage/exit 1 — both commands must stay present. The security job can never modify the host because it only calls a READ_ONLY broker action; fixes still need a one-time owner escalation.

**Notable call graph.** Requires `db.php`, `config.php`, `scheduler.php`, `setup.php`, `hostctl.php`, `util.php`. Invoked by the three `crontab` entries.

---

## `src/public/api.php` — 390 lines

**Purpose.** The single JSON front controller. It authenticates, applies the same-origin and CSRF guards, and dispatches 39 `case` actions (see API.md for the full per-action reference).

**Key behaviours (before the switch).**
1. Requires all lib files and calls `Auth::ensureOwner()` at line 18 — before any action, including `login`.
2. `$action = $_GET['action'] ?? ''; $b = Util::body();` — parameters are read from both the query string and the request body.
3. **Same-origin guard** (lines 23–33): for every action except `login` and `me`, if `HTTP_ORIGIN` is present its host and port must equal `HTTP_HOST`'s; mismatch → 403 `cross-origin request refused`.
4. **Double-submit CSRF guard** (lines 35–42): for every action except `login` and `me`, cookie `panel_csrf` must be non-empty, header `X-CSRF-Token` must be non-empty, and `hash_equals()` must hold; otherwise 403 `invalid CSRF token`.
5. All actions except `login` and `me` call `Auth::require()` in their case body; unknown action → 404 `unknown action`.

**Notable call graph.** Imports 13 lib files. Calls into `Auth`, `RBAC`, `Engine`, `Vault`, `HostCtl`, `ConfigGovernance`, `Theme`, `Hermes`, `Search`, `STT`, `Prompt`, `Setup`, `Scheduler`, `DB`, and `Util`. It is the only HTTP caller of most of them.

**Ambiguity to note.** Four switch cases — `layout.save`, `agent.message`, `panel.host.probe`, `panel.redaction.probe` — are **not** listed in `PanelActions::catalog()`; conversely, every catalog entry has a switch case. See API.md § "Catalog vs switch reconciliation".

---

## `src/public/index.php` — 102 lines

**Purpose.** Serves the single-page application shell, establishes the theme from the active config, and issues the JavaScript-readable CSRF cookie.

**Key behaviours.**
- Calls `Auth::ensureOwner()`, loads `ConfigGovernance::active()`, and resolves `theme.preset`/`mode`/`radius` with a fallback to `brezel-light-rounded`.
- If `panel_csrf` is absent, sets it with a 24-random-byte hex value: 12 h expiry, `path=/`, **`httponly=false`** (JavaScript must echo it in `X-CSRF-Token`), `samesite=Lax`, `secure` only under HTTPS.
- Emits the HTML shell: login form, setup screen, app (sidebar/nav, topbar with search & voice buttons, content section), drawer, chat overlay, and toast — all initially `hidden` and revealed by `app.js`.
- Embeds the entire active config as `window.HCP_CONFIG = <?= json_encode(...) ?>` in an inline `<script>`.
- Loads `/app.js` as the only script.

**Invariants.** The CSRF cookie is intentionally readable by JS; the session cookie is not. Config-driven attributes in HTML are passed through `htmlspecialchars()`.

**Notable call graph.** Requires `auth.php`, `config.php`, `setup.php`; uses `Theme::presets()`. Does not call `Auth::require()` — the SPA decides whether to show the login form based on `me`/`bootstrap`.

---

## `src/public/health.php` — 6 lines

**Purpose.** Unauthenticated, dependency-free liveness endpoint used by the Docker healthcheck.

**Behaviour.** Sets `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-store`, returns HTTP 200, and echoes `{"ok":true,"service":"hermes-control-deck","time":<unix>}`. It requires no file and touches no database, so it stays available even if SQLite is broken (matching the compose healthcheck at `/health.php`).
