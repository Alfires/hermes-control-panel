# Operations — Hermes Control Deck

Purpose: the day-to-day runbook for a deployed panel. It documents the three cron-driven `worker.php` jobs and the self-test schedule, the lifecycle of security findings, the escalation and approval flows, the append-only SHA-256 audit chain, the propose → approve → rollback config-revision workflow, theme switching, scheduler expression syntax, backup/restore of the persistent `/var/lib/panel` data directory, and where every log lives. All of this is derived from the code in `src/lib/` and `src/public/`; no secret value is recorded.

**Last verified:** main repo commit `0137eff` (`test: add regressions for deep-debug fixes`) via read-only `git log -1 --oneline`.

## 1. Scheduled jobs (`crontab` → `src/worker.php`)

Cron (inside the container, installed as `/etc/crontabs/root`) is the **single** scheduler — there is no duplicate supervisor ticker. All jobs `cd /var/www/html`, run `php worker.php <cmd>`, and append stdout/stderr to `/var/log/panel/worker.log`.

| Command | Frequency | What it does |
|---|---|---|
| `worker.php tick` | every minute | Checks whether the active config's `schedule.self_test` is due; if so runs the read-only web-UI self-test and records the result. |
| `worker.php agents` | every 5 minutes | Wakes enabled, scheduled agents (except `security`) by inserting a plain-language note into their inbox when their schedule is due. |
| `worker.php security` | daily 03:15 | Runs the read-only host scan, stores new findings, records a `host.security` run and wakes the `security` agent to propose fixes. |

Any other argument prints `usage: worker.php [tick|agents|security]` to stderr and exits 1. Each completed job writes a row to `schedule_runs(job, status, detail, created_at)` and an audit row (actor `scheduler`): `selftest.daily`, `agent.wake`, or `security.scan`.

Manual runs, from inside the container (read-only for `security`):

```bash
docker exec hermes-panel sh -c 'cd /var/www/html && php worker.php tick'
docker exec hermes-panel sh -c 'cd /var/www/html && php worker.php agents'
docker exec hermes-panel sh -c 'cd /var/www/html && php worker.php security'
```

### Self-test schedule and report

`Scheduler::runSelfTest()` renders every configured page in `populated` mode (checking each produces >20 chars of markup) and confirms each curated Hermes source in `HermesSources::catalog()` is registered. It is read-only — it never changes config. A failed run records a `webui.selftest` row with status `fail`, opens a `medium` finding (source `webui.selftest`, title "Web UI self-test found broken views", detail = number of failing checks), and audits `selftest.daily/fail`. The same report can be triggered on demand via `api.php?action=selftest.run` (audits as `owner`) and read back via `api.php?action=selftest.report` (latest `webui.selftest` run, with decoded detail). It does not drive a real browser; the browser procedure is in the `panel-browser-verify` toolbox tool.

Note: the self-test only checks server-rendered markup and source registration; a full browser-fidelity pass is a separate verification activity.

## 2. Scheduler syntax (`src/lib/scheduler.php`)

`Scheduler::parse()` accepts exactly two expression forms; anything else returns `null` and is rejected by config validation (so a bad schedule cannot become active):

- `daily HH:MM` — two-digit hour (00–23) and minute (00–59), evaluated in **UTC**. Example: `daily 04:20` (the config default for self-test), `daily 03:15` (the security agent's default schedule).
- `every Nm` — N is a positive integer number of minutes (e.g. `every 30m`, `every 45m`). `every 0m`, `every 2h`, `hourly`, `daily 4:20` (single-digit hour) are all invalid.

`Scheduler::due()` treats `every Nm` as "at least N minutes since the last recorded run for that job" and `daily HH:MM` as "now ≥ today's target time and the last run was before it". Per-job last-run timestamps are looked up from `schedule_runs` by `job` name (`webui.selftest`, `agent.wake.<id>`, `host.security`).

## 3. Findings lifecycle

`findings(id, source, severity, title, detail, status='open', created_at)` is the shared findings table.

1. **Creation.** The daily `security` worker calls the read-only broker `security_scan`, which checks pending apt updates, SSH hardening, firewall state, unexpected all-interface listeners, privileged containers, world-writable files in sensitive dirs and curated critical-package rules. Each result is stored with `source='hostctl'`. Separately, a failed self-test opens a `source='webui.selftest'` finding.
2. **Deduplication.** The `security` worker skips a finding when an **open** row with the same `source` and `title` already exists, so repeated daily scans do not pile up duplicates.
3. **Wake-up.** The worker writes a message to the `security` agent ("Daily scan complete: N new finding(s) recorded… propose ONE host_apply fix per real issue… Do not apply anything yourself") and audits `security.scan new=N`.
4. **Proposal, not application.** The Exploit Watch agent may propose a `host_apply` fix, but the worker itself never changes the host. Every fix still needs a one-time owner escalation and an HMAC signed with the root-only key; the root broker executes only allowlisted action IDs.
5. **Review.** The owner sees open findings via `api.php?action=findings.list` (last 200, newest first) and as the `panel.findings` source on the Security view; `bootstrap` also returns the last 100 open findings. There is no automatic "resolve" path in the code — status transitions are data operations on the table, not a scheduled job.

`api.php?action=security.scan` runs the same read-only scan on demand (owner-only).

## 4. Escalations, approvals and the audit chain

### Escalations (tool permission)

`escalations(id, agent_id, capability, reason, status, ttl_seconds, decided_at, expires_at, token_hash, uses_left, created_at)`:

- An agent asking for a privileged tool calls `request_escalation` → `RBAC::requestEscalation()` inserts a `pending` row with TTL clamped to 30–3600s (default 300). Pending is **not** authority.
- Owner decides via `api.php?action=escalation.decide` → `RBAC::decide()`: approve generates a token, sets `expires_at=now+ttl`, `uses_left=1`, stores its SHA-256 hash; deny sets `status='denied'`. Both audit `escalation.approve`/`escalation.deny` with actor `owner`.
- `RBAC::activeEscalation()` checks `status=approved AND uses_left>0 AND expires_at>now`; after a successful privileged call `consumeEscalation()` decrements `uses_left` — approvals are **one-shot**.
- Expiry: `RBAC::expirePending()` in `src/lib/rbac.php` would mark pending asks older than 300s as `expired`, drop a timeout note into the agent's inbox, and expire approved rows past `expires_at`. Note that in this commit `worker.php tick` only performs the self-test — it does **not** call `expirePending()`, so this helper is currently unused by the cron path. The effective one-shot/TTL guard is the `activeEscalation()` check (`status=approved AND uses_left>0 AND expires_at>now`) performed at tool-call time.

### Approvals (action review queue)

`approvals(id, agent_id, kind, summary, payload, status='pending', created_at, decided_at)` records privileged actions for owner review:

- `host_apply` — `Engine::hostApply()` inserts an approval with the proposed `action` + `args` and a reason/summary, then calls the broker. The escalation permits the call; the approval queue is where the owner sees what was proposed.
- `webui_edit` — `Engine::webuiEdit()` records the layout key/value before applying it.
- Owner decides via `api.php?action=approval.decide` (`approved`/`denied` + `decided_at`). `bootstrap` returns pending approvals.

### Audit chain

`audit(id, actor, action, detail, created_at, prev_hash, hash)` is append-only. `DB::audit()` scrubs and truncates the detail to 2000 chars, reads the previous row's `hash`, and stores `hash = sha256(prev_hash|actor|action|detail|created_at)`. The migration in `db.php` adds `prev_hash`/`hash` to legacy tables and backfills each row so the chain stays verifiable. `DB::verifyAuditChain()` walks all rows asserting `prev_hash` linkage and recomputed hashes.

The Audit view and the Overview "Recent activity" list both render `S.audit`, which the `bootstrap` action now supplies as the last 200 rows (previously missing, which left both views permanently empty). `api.php?action=audit.list` also returns the last 200 rows. Typical actions recorded: `login`, `owner-credentials-synced`, `tool.call`, `tool.denied`, `host.*`, `vault.use`, `escalation.*`, `ui.config.*`, `selftest.*`, `scheduler.*`, `layout.save`, `setup.complete`.

## 5. Config revisions: propose → approve → rollback

`ConfigGovernance` (`src/lib/config.php`) implements versioned, proposal-driven configuration. The default revision 1 is created on first `active()` call and inserted as `state='active'`.

1. **Propose.** `ConfigGovernance::propose($patches, $author)` applies 1–100 JSON-patch-like operations (`add`, `replace`, `remove`; paths must start with `/`, be ≤12 segments, and root at one of `meta`, `theme`, `pages`, `feature_flags`, `prompts`, `schedule`). It validates the candidate with `Schema::validateConfig()`, assigns `revision = max(ever-written)+1` (append-only even after rollback), hashes the snapshot (excluding `revision`), writes a `draft` row to `ui_config_revisions` with a `parent_revision`, writes a `ui_config_proposals` row (`status='pending'`, 24-hour expiry) with a flattened `+/-/~` diff, and audits `ui.config.propose`. **The active config is untouched.**
2. **Approve.** `ConfigGovernance::approve($proposalId, $actor)` marks the old active row `superseded`, flips the draft to `active` with `approval_id` and `activated_at`, marks the proposal `approved` with `decided_by`/`decided_at`, and audits `ui.config.approve`.
3. **Rollback.** `ConfigGovernance::rollback($revisionNumber, $actor)` marks the active row `rolled_back` and re-activates the named older revision, with a `ui.config.rollback` audit. Revision numbers are never reused: the next proposal is greater than every revision ever written.
4. **Inspect.** `ui.config.history` lists all revisions with state/author/hash/proposal id; `ui.config.diff` diffs two revisions; `state.diff` returns the current revision plus entity counts.

API endpoints (all owner-only): `ui.config.propose`, `ui.config.approve`, `ui.config.rollback`, `ui.config.history`, `ui.config.diff`. Config validation rejects unknown top-level keys, unsafe CSS/Markers (`<script`, `onerror=`, `url(`, `@import`, `javascript:` …), invalid sources/capabilities, bad schedules and structural limits (1–100 pages, ≤50 blocks/page).

## 6. Theme switching

`Theme::presets()` defines seven presets: `brezel-light-rounded` (default), `brezel-dark-rounded`, `brezel-light-square`, `brezel-dark-square`, `midnight`, `ember`, `mono`. Each carries `mode` (`light`/`dark`), `radius` (`rounded`/`square`), a label and CSS custom-property `tokens`. `Theme::validateTokens()` allows ≤40 `--`-prefixed keys with colour/length/rgba/font values only and rejects `url(`, `@import`, `expression(`, `<`, `javascript:`, `</style` and `;`.

`api.php?action=theme.presets` lists them. `api.php?action=theme.set` is a convenience wrapper that, in a single call, proposes four patches (`/theme/preset`, `/theme/mode`, `/theme/radius`, `/theme/tokens`) as `owner` and immediately approves the resulting proposal — so a theme switch is itself a normal, audited config revision. `index.php` injects the active preset's mode/radius into the page and `Theme::cssVars()` emits the token CSS; `app.css`/`tailwind.css` provide the static styles. Restore the default with `theme.set preset=brezel-light-rounded`.

## 7. Backups and restore of `/var/lib/panel`

There is **no backup subcommand shipped in either repo**; the panel's persistent state is simply the Compose volume `./data:/var/lib/panel` under `/opt/hermes-control-panel/data` on the Deck. That directory contains `panel.db` (SQLite, opened with `PRAGMA journal_mode=WAL`) and `setup.lock` (writes the first-run wizard lock). Everything else — config revisions, findings, approvals, escalations, audit chain, agents, sessions, search docs, Hermes cache — lives inside `panel.db`; the HMAC key and panel.env live in the separate root-only `/opt/panel-secrets` and the checkout respectively.

A safe manual backup therefore snapshots the volume while the container is stopped (so the WAL is checkpointed), plus the root-only files separately (never print their contents):

```bash
# On the Deck, as root — stop only the panel, never the production gateway
docker compose -f /opt/hermes-control-panel/docker-compose.yml stop webui
sudo tar -C /opt/hermes-control-panel -czf /opt/panel-backup-$(date +%F).tar.gz data
sudo tar -C / -czf /opt/panel-secrets-backup-$(date +%F).tar.gz opt/panel-secrets opt/hermes-control-panel/panel.env
docker compose -f /opt/hermes-control-panel/docker-compose.yml up -d --no-deps webui
```

Restore in reverse: stop `webui`, unpack `data/` to `/opt/hermes-control-panel/data` (preserving uid/gid 1000 ownership and directory mode 770), restore the secrets only if their keys were lost, then `docker compose up -d --no-deps webui`. After restore, verify with `curl -fsS http://127.0.0.1:9120/health.php`, an authenticated `api.php?action=bootstrap`, and — if `panel.db` came from an older deck — `DB::verifyAuditChain()` semantics (the migration backfills missing hash columns). Do not restore an old `panel.db` over a newer one without understanding that revisions and audit history will roll back with it.

## 8. Log locations

| Path (inside container unless noted) | Producer | Notes |
|---|---|---|
| `/var/log/panel/nginx-access.log`, `nginx-error.log` | nginx | Access log + `warn`-level errors. |
| `/var/log/panel/nginx-out.log`, `nginx-err.log` | supervisor `nginx` | Process stdout/stderr. |
| `/var/log/panel/php-error.log` | PHP-FPM pool | PHP errors (`display_errors=off`). |
| `/var/log/panel/php-fpm.log`, `php-fpm-err.log` | supervisor `php-fpm` | Process stdout/stderr. |
| `/var/log/panel/crond.log`, `crond-err.log` | supervisor `crond` | Cron daemon. |
| `/var/log/panel/worker.log` | cron jobs | All three `worker.php` commands append here. |
| `/var/log/panel/supervisord.log` | supervisord | Supervisor's own log. |
| `/var/log/panel/stt.log`, `stt-err.log` | supervisor `stt` | whisper.cpp (or the `sleep 3600` placeholder). |
| `/var/log/panel-hostctl.log` (**host**) | `hostctl.py` | Authorised/refused actions and errors; `PANEL_HOST_LOG`. |

Container runtime logs are also available with `docker logs -f hermes-panel` (or `docker compose -f /opt/hermes-control-panel/docker-compose.yml logs -f webui`). Broker unit logs: `sudo journalctl -u panel-hostctl -u panel-socket-relay -f`.
