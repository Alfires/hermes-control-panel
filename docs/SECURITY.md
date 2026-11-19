# Security Model

This document describes the security architecture actually implemented in the Hermes Control Panel repository: the two root-owned brokers, the HMAC signing contract, the RBAC tool catalogue and risk levels, the escalation and approval lifecycles, vault list/use-only handling and double scrubbing, redaction patterns, the HTTP guards (same-origin + CSRF), login rate limiting, session handling, config governance validation, the Hermes source allowlist, and a candid list of residual risks. Every claim is derived from the code in this repository (`src/`, `hostctl/`, `docker-compose.yml`, `entrypoint.sh`) and the verified commit.

**Last verified:** 2026-11-19 against commit `0137eff test: add regressions for deep-debug fixes`.

---

## 1. Architecture: two root-owned brokers

The panel backend is the only component that can reach either privileged broker. The model never gets a shell, an HTTP client to the brokers, or a file path to their keys.

```
agent (text only) ──TOOL_CALL: line──> panel backend (PHP)
                                          │
                     RBAC + HMAC ─────────┼────> host-control broker (root, Unix socket)
                                          │       /run/panel-host.sock
                     logical secret name ─┴────> vault broker (root, Unix socket)
                                                  /run/vault-broker.sock
```

| Broker | PHP client | Socket (default) | What it permits |
|---|---|---|---|
| Host control | `src/lib/hostctl.php` (`HostCtl`) | `HOSTCTL_SOCKET` or `/run/panel-host.sock` | 8 fixed action IDs (see §3); fixed-argv subprocesses only, never `shell=True`. |
| Vault | `src/lib/vault.php` (`Vault`) | `VAULT_SOCKET` or `/run/vault-broker.sock` | `list` (names/policies only) and `use` (inject a value into one http/file/exec action). **No `get`/read operation exists.** |

The broker processes run as root outside the container. The compose file mounts the *directories* `/opt/panel-sockets → /run/panel-sockets` (so relays survive broker restarts) and the root-owned key `/opt/panel-secrets/hostctl.key → /run/hostctl-src/hostctl.key:ro`. The container entrypoint then copies the key with `install -o 1000 -g 1000 -m 0400 ... /run/secrets/hostctl.key` so only the PHP-FPM user can read it; it is never placed under the web root.

`HostCtl::ACTIONS` is deliberately small: `system_status`, `security_scan`, `cve_lookup` (read-only) and `fix_updates`, `fix_service`, `fix_docker`, `fix_ssh`, `reboot` (privileged). The Python broker has the identical `ALLOWED` set and validates service names (`^[A-Za-z0-9@._-]{1,80}$`), compose project names (`^[a-z0-9][a-z0-9_-]{0,60}$`) and package names (`^[A-Za-z0-9][A-Za-z0-9.+-]{0,80}$`) before acting.

---

## 2. HMAC canonicalisation (host broker)

Privileged host actions require an HMAC that only the root broker and the PHP backend can compute. Read-only actions do not.

**PHP side (`HostCtl::call`, non-`READ_ONLY` actions):**

```php
$argsObj = (object)$args;                    // {} rather than [] when empty
$payload = ['op'=>'action', 'action'=>$action, 'args'=>$argsObj,
            'requestor'=>'panel' . ($agent ? ':' . $agent['slug'] : '')];
$canonical = json_encode((object) self::sortedArgs($args),
                         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$msg   = $action . '|' . $canonical . '|' . $ts;
$token = hash_hmac('sha256', $msg, trim($key));   // key from PANEL_HOST_KEY or /run/secrets/hostctl.key
```

**Python side (`hostctl.py`):**

```python
msg = "%s|%s|%s" % (action,
      json.dumps(args, sort_keys=True, separators=(",", ":"), ensure_ascii=False), ts)
expected = hmac.new(key(), msg.encode(), hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, str(token or "")):
    return False, "bad authorization token"
```

Key details that must stay in sync:

| Concern | PHP | Python | Why it matters |
|---|---|---|---|
| Key order | `sortedArgs()` recursively `ksort(..., SORT_STRING)` | `sort_keys=True` | Multi-key actions sign identically on both sides. |
| Whitespace | `json_encode` (no spaces) | `separators=(",", ":")` | Both produce compact JSON. |
| Unicode / slashes | `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` | `ensure_ascii=False` | Escaped vs literal characters would change the digest. |
| Empty args | cast to `(object)` | `args` must be a `dict` | `{}` on both sides; `[]` would be rejected. |
| Timestamp | `ts = (string)time()` | `abs(time.time() - int(ts)) > SKEW` → reject | `SKEW = 120` seconds; stale requests are refused. |
| Comparison | `hash_hmac` | `hmac.compare_digest` | Constant-time on the broker side. |

The broker also closes a side door: the convenience ops `status`, `scan` and `cve` require a signature over `op:<name>` even though the PHP client never uses them (an in-code comment records that they were previously unauthenticated and exposed full host recon to any process in the socket group).

**Live verification.** `HostCtl::probeForged()` (exposed as `panel.host.probe`) sends a privileged request with the literal token `bad-token`; `refused=true` proves the broker rejected it. The root-only key path means an agent cannot mint a valid token.

---

## 3. RBAC tool catalogue and risk levels

`RBAC::TOOLS` in `src/lib/rbac.php` is the complete tool catalogue. The model sees this list in its system prompt (`Engine::systemPrompt()`) but asking for a tool does not run it: `Engine::parseToolCall()` extracts a `TOOL_CALL:` line, `Engine::executeTool()` lowercases the name, and `RBAC::can()` decides.

| Tool | Risk | Capability granted by | Backend reached |
|---|---|---|---|
| `chat` | `none` | tool in agent's `tools` | none (continues the conversation) |
| `notes_read` | `read` | tool in `tools` | `layout['notes']` |
| `host_status` | `read` | tool in `tools` | `HostCtl::call('system_status')` |
| `host_scan` | `read` | tool in `tools` | `HostCtl::call('security_scan')` |
| `cve_lookup` | `read` | tool in `tools` | `HostCtl::call('cve_lookup')` |
| `webui_read` | `read` | tool in `tools` | `layout['layout']` |
| `vault_list` | `read` | tool in `tools` | `Vault::listSecrets()` (names only) |
| `notes_write` | `write` | tool in `tools` | `layout['notes']` (scrubbed, last 100 kept) |
| `message_agent` | `write` | tool in `tools`; target `can_message=1`, no self-message | `messages` table |
| `host_apply` | `privileged` | tool in `tools` **and** an approved, unexpired `host_apply` escalation | inserts `approvals` row, then `HostCtl::call(<allowlisted fix>)` |
| `webui_edit` | `privileged` | tool in `tools` **and** an approved, unexpired `webui_edit` escalation | inserts `approvals` row, then `layout` upsert |
| `vault_use` | `privileged` | tool in `tools`, **and** the secret name in `vault_names` or an approved escalation for `vault:<name>` | `Vault::use()` → root broker |

Two meta-tools bypass RBAC by design: `request_escalation` (queues a request, the agent keeps working) and `noop`. `RBAC::DEFAULTS` seeds four agents with *explicit, minimal* tool lists and empty `vault_names`; new agents start with no permissions beyond what the owner assigns.

Every accepted or denied tool call is audited by `Engine::executeTool()` as `agent:<id> / tool.call` or `agent:<id> / tool.denied`.

---

## 4. Escalation lifecycle

```
agent request_escalation(capability, reason, ttl)
        │  RBAC::requestEscalation(): ttl clamped 30..3600 s
        ▼
escalations row: status=pending, uses_left=1
        │
        ├── owner approves (escalation.decide → RBAC::decide)
        │       status=approved, token_hash=sha256(24 random bytes),
        │       expires_at=now+ttl_seconds, uses_left=1
        │
        ├── owner denies ───────────────> status=denied
        └── unanswered >300 s ──────────> status=expired + timeout inbox note
                │
                ▼
        RBAC::activeEscalation() requires approved + uses_left>0 + expires_at>now
                │
                ▼
        successful privileged tool call → RBAC::consumeEscalation() (uses_left--)
        (a failed call does not consume the escalation)
```

`RBAC::expirePending()` also expires *approved* rows once `expires_at <= now`. The owner-facing queue is the **Approvals** view, which shows capability, reason, agent and status. Decisions are audited as `owner / escalation.approve|deny`.

---

## 5. Approval queue

`Engine::hostApply()` and `Engine::webuiEdit()` insert a row into `approvals` (`kind`, `summary`, JSON `payload`, `status='pending'`) **before** executing the action. The `approval.decide` action changes that row's status to `approved`/`denied` with `decided_at`.

**Important and honest distinction:** for these two tools the approval row is *recorded for review*, not an enforcement gate. The enforceable gate is the one-time escalation checked by `RBAC::can()` (plus the broker's HMAC check for host actions). At this commit no code consults `approvals.status` before performing `host_apply` or `webui_edit`. The vault broker is different: it has its own owner-approval policy (`approval` field per secret) enforced by root.

---

## 6. Vault: list/use only and double scrubbing

- **`list`** (`Vault::listSecrets()`): the reply is reduced to `name`, `label`, `allow`, `approval`. Values are never requested, never returned, and label/error strings are scrubbed.
- **`use`** (`Vault::use()`): the caller supplies only a logical `name`, a `kind` restricted to `http|file|exec`, a `purpose` and a `params` object. The agent never supplies a value and never receives one; root injects the secret into the single registered action and the reply is scrubbed.
- **Double scrubbing:** the root broker redacts its own output (`hostctl.py` has an equivalent `scrub()` for host replies), and PHP scrubs again with `Vault::scrubTree()` → `Util::scrub()`. A buggy helper that echoes the value therefore still cannot leak it to the conversation. Every use is audited with `agent:<id> / vault.use` including name, kind, purpose and result (`ok`/`denied`).
- `vault.test` is an owner-only dry run using a synthetic agent `{id:0, slug:'owner-test'}`; the root broker still applies its own approval policy.

---

## 7. Redaction patterns

`Util::scrub()` (in `src/lib/util.php`) is the last line of defence for model chatter, tool output, logs and broker replies. It replaces matches with `***CENSORED***`:

| Pattern family | Examples |
|---|---|
| Authorization headers / bearer tokens | `Authorization: Bearer …`, `bearer <8+ token chars>` |
| Credential-named fields | `password`, `passwd`, `pwd`, `secret`, `token`, `api[_-]?key`, `access[_-]?key`, `private[_-]?key`, `credential`, `authorization`, `auth[_-]?header`, `session[_-]?key` — both `"name": "value"` and `name=value` forms |
| Vault placeholder | `{{secret}}` |
| Private keys | PEM `-----BEGIN … PRIVATE KEY----- … -----END … PRIVATE KEY-----` blocks, including URL-encoded newlines |
| Provider shapes | AWS `AKIA[0-9A-Z]{16}`, OpenAI `sk-[A-Za-z0-9_-]{20,}`, GitHub `ghp_[A-Za-z0-9]{30,}`, the synthetic `TEST-SECRET-DO-NOT-USE-…` leak marker |
| Generic high-entropy | base64-like strings of 32+ characters |

`Util::scrubDeep()` applies it recursively. It is applied before persistence (`DB::audit()`, notes, messages, search docs), before caching (`Hermes::fetch()`), before returning model/tool text (`Engine`), and on all broker replies (`HostCtl::scrub()`, `Vault::scrubTree()`). The policy is deliberately aggressive: a false-positive redaction is preferred to a leaked credential. `panel.redaction.probe` verifies the placeholder is censored on a live deployment.

---

## 8. HTTP guards: same-origin + CSRF

Both guards live at the top of `api.php` and are skipped **only** for `login` and `me` (see API.md for the full table).

**Same-origin:** when `HTTP_ORIGIN` is present, `parse_url()` host and effective port must match `HTTP_HOST`. A mismatch returns HTTP 403 `cross-origin request refused`.

**CSRF:** the SPA shell (`index.php`) sets cookie `panel_csrf` to 24 random bytes when absent (`httponly=false` so JavaScript can read it, `samesite=Lax`, `secure` only under HTTPS). Every guarded action requires that cookie and header `X-CSRF-Token` to be non-empty and `hash_equals($csrfCookie, $csrfHeader)`; otherwise HTTP 403 `invalid CSRF token`. The session cookie (`panel_session`) is separate and always HTTP-only.

The two guards are complementary: CSRF protects state-changing calls even if `Origin` is stripped by a proxy; the origin check rejects a cross-site request that somehow obtained the CSRF cookie.

---

## 9. Login rate limiting

The `login` case implements a per-IP **and** per-username counter:

- Key: `sha256(REMOTE_ADDR . '|' . username)`, file `sys_get_temp_dir()/hcp-login-<key>`.
- Payload: `{"start": <unix>, "count": <n>}` — the window start is stored with the counter, so a lockout expires after `window = 900` s instead of lasting forever.
- 10 attempts in the window → HTTP 429 `too many attempts; try again later`. A successful login unlinks the rate file.

---

## 10. Authentication and session handling

`Auth::ensureOwner()` runs on **every** `api.php` request (before the action switch) and on `index.php`. It provisions the owner from `PANEL_ADMIN_USER`/`PANEL_ADMIN_PASSWORD` and re-hashes the stored password whenever the environment value fails `password_verify()`. An empty configured password is a hard error (HTTP 500).

`Auth::login()` uses `password_verify()`, generates a 32-random-byte token, stores only `hash('sha256', $token)` in `logins`, and returns the raw token; `Auth::setCookie()` issues it for 12 h (`httponly`, `samesite=Lax`, `secure` under HTTPS). `Auth::current()` hashes the presented cookie and requires an unexpired row joined to a user. `Auth::require()` returns HTTP 401 when absent. `logout` deletes the row and expires the cookie. There is no role hierarchy: an authenticated session is the owner. `me` intentionally reports only `{ok:true, authenticated:bool}` and needs no login.

---

## 11. Config governance validation

Nothing an agent changes touches the active revision before owner approval (`ConfigGovernance`).

1. **Patch allowlist:** `applyPatch()` accepts only `add|replace|remove` on paths beginning with `/`, 1–12 segments, rooted at `meta`, `theme`, `pages`, `feature_flags`, `prompts` or `schedule`. `schema_version` and `revision` cannot be patched. Segments must match `/^[A-Za-z0-9_-]+$/`; top-level sections cannot be removed; traversal through a scalar is rejected; `add` with a final `-` appends to an array.
2. **Full revalidation:** after all patches apply, `Schema::validateConfig()` checks the whole candidate — top-key whitelist, `schema_version=1`, positive `revision`, meta key/type/length/locale and `BAD_MARKERS` (`<script`, `onerror=`, `onload=`, `javascript:`, `</style`, `@import`, `url(`), theme preset/mode/radius/font plus `Theme::validateTokens()` (no `url(`, `@import`, `expression(`, `<`, `javascript:`, `</style`, `;`), 1–100 pages with unique valid slugs and safe routes, ≤50 blocks with allowed types/sources/props, five boolean feature flags, prompt style, and a parseable `schedule.self_test`.
3. **Source/capability guards:** `Schema::validSource()` rejects names containing `secret|reveal|raw|debug|ticket|token|pty|terminal`, any `/api/` prefix, and only allows the seven `panel.*` sources plus registered Hermes source keys. `Schema::validCapability()` rejects `secret|reveal|raw|debug|admin` and requires a narrow slug-like pattern.
4. **Append-only revisions:** proposals are stored as `draft` snapshots with a content hash (excluding `revision`) and a scrubbed human-readable diff; `approve()` supersedes the old active revision and records `approval_id`; `rollback()` reactivates an older revision without reusing a revision number. `Render::page()` escapes all interpolated values, so config never emits raw HTML.

`layout.save` is a deliberate exception: it is an owner-only, CSRF-protected write to the `layout` table, but its `value` is **not** run through `Schema`.

---

## 12. Hermes source allowlist

`Hermes::fetch()` may only request paths registered in `HermesSources::catalog()` (20 GET endpoints). `HermesSources::isForbidden()` independently rejects an empty path or any of:

```
/env/reveal  /config/raw  /debug-share  /files/ticket  /pty
/host-terminal  /auth/native/token  /ws  /audio/transcribe-stream  /plugin-providers
```

Response handling is field-whitelisted per source (`fields`), deep-scrubbed with `Util::scrubDeep()`, and cached in `hermes_cache` only after scrubbing. Path placeholders are substituted with `rawurlencode(Util::str($v, 80))` and any unresolved `{` aborts the request. The browser never receives `HERMES_API_KEY`; PHP attaches it as a Bearer header. `Hermes::deepLink()` accepts only page ids from `PageRegistry::ids()`.

---

## 13. Known residual risks (documented honestly)

These are limitations that remain in the code at this commit. They are not hypothetical product claims; each is traceable to the cited code.

1. **Socket group sharing / unauthenticated read actions.** The root broker accepts `system_status`, `security_scan` and `cve_lookup` under `op=action` with **no HMAC**. Any process that can open `/run/panel-host.sock` — e.g. another process in the shared socket group, or anything with access to the bind-mounted `/run/panel-sockets` directory — can obtain full host recon (services, ports, sshd config, firewall, listeners, pending updates). The broker's own code comment acknowledges this ("any process running as the socket group could get a full host recon report"); the convenience-op signatures only partially close the side door.
2. **HMAC key is readable by the container's FPM user.** `entrypoint.sh` installs the key mode `0400` owned by uid 1000 at `/run/secrets/hostctl.key` so PHP can sign. Any code execution inside the panel process therefore yields the key. The broker checks only allowlist + HMAC + timestamp — it has no knowledge of panel escalations or approvals — so a stolen key can mint valid privileged requests without any escalation. The HMAC therefore protects the *socket boundary*, not the *panel's RBAC decision*.
3. **Escalation capability granularity.** A `request_escalation` capability is an arbitrary string chosen by the agent. The owner sees a label and a free-text reason; nothing binds the approved capability to the concrete action taken. One approved `host_apply` escalation authorises **any** action in `HostCtl::ACTIONS` with any arguments that pass the broker's regexes (the specific `action`/`args` in the `approvals` row are advisory). `RBAC::can()` does not compare the requested action to the capability beyond string equality with the tool name. Similarly, `vault_use` is per-secret-name, not per-endpoint or per-request.
4. **Escalation token is never verified.** `RBAC::decide()` stores `token_hash`, but no code reads `escalations.token_hash`; enforcement relies solely on `status`, `uses_left` and `expires_at`. The stored hash is currently a write-only field.
5. **Approval decisions for `host_apply`/`webui_edit` are advisory** (see §5): the action runs under the escalation gate, and a later `deny` on the recorded approval row does not undo it.
6. **Proposal expiry is not enforced.** `ui_config_proposals.expires_at` is set to `now + 86400` but never checked; a stale pending proposal can still be approved.
7. **`layout.save` bypasses config schema validation.** It is owner-only and CSRF-protected, but a crafted value is stored as arbitrary JSON. The client's `applyLayout()` ignores `custom_css` and regex-filters token keys/values, which mitigates direct CSS injection, but the stored value itself is ungoverned by `Schema`.
8. **Inline config embedding.** `index.php` writes the full active config into `window.HCP_CONFIG` via `json_encode(..., JSON_UNESCAPED_SLASHES)` in an inline `<script>`. `Schema::BAD_MARKERS` blocks `<script` but not the literal sequence `</script>`; an allowed config string containing it could terminate the script element. Config changes are governance-gated and owner-approved, which limits exposure, but the escaping is not defense-in-depth.
9. **No runtime audit-chain verifier call.** `DB::verifyAuditChain()` exists and is correct, but no API action or CLI job invokes it at this commit; integrity must be checked out of band.
10. **Origin guard is conditional.** If a client omits `Origin` entirely, the same-origin check is skipped. CSRF still guards state-changing actions, so this is defense-in-depth rather than a primary control; non-browser clients are intentionally able to use the API with a CSRF token.
11. **Rate limiting is local and username-varied.** The login counter file lives in the container's temp dir (no cross-replica enforcement) and is keyed by IP **and** username, so an attacker can reset the budget by changing the username. It also persists no cleanup beyond overwriting expired windows.
12. **Untrusted model output is a permanent input.** All model text is scrubbed and RBAC-gated, but the model can still request any tool it is granted; the safety guarantees rest on `RBAC::can()`, the broker allowlists, and redaction, not on model compliance. Prompt-injected instructions to "print the secret" are stopped at the scrubbing layer, not prevented from being attempted.
13. **Expired login rows are not purged.** There is no cleanup job for `logins.expires_at`; rows accumulate until a logout or manual cleanup.

---

## 14. Positive controls worth preserving

- The model has no tool execution path of its own; `TOOL_CALL:` is parsed and re-validated server-side.
- Two independent root-owned brokers; no read/get operation on the vault; values injected only by root.
- Recursive, aggressive redaction on both sides of every broker boundary.
- Allowlisted Hermes endpoints with per-source field whitelists, path/param encoding, and scrub-before-cache.
- Deterministic, timestamped HMAC canonicalisation shared by PHP and Python.
- One-use, short-TTL escalations; four least-privilege default agents; opt-in agent messaging.
- CSRF + same-origin guards, hashed session tokens, HTTP-only session cookie, bounded login attempts.
- Append-only SHA-256 hash-chained audit log with legacy backfill; versioned, validated, owner-approved config revisions.
- Static regression suites (`tests/rbac/matrix.php`, `tests/contract/panel_actions.php`, `tests/unit/redaction.php`, `tests/live/livechecks.php`, `tests/integration.sh`) that exercise the real code paths, including forged HMAC refusal and a real secret-leakage probe.
