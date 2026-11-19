# Deep debugging report — 19 November 2026

Purpose: a reproducible record of the deep audit of the Hermes Control Deck,
every defect found, the fix applied, and the evidence that the fix works. This
is the companion to `docs/TROUBLESHOOTING.md` (symptom → fix) and
`docs/TODO.md` (canonical checklist).

**Last verified:** commit `0137eff` — live Deck deployment rebuilt from that
commit, 814/814 live suite cases and 16/16 integration checks passing.

## 1. Method

1. Baseline: `php tests/suite.php` → 802 cases, 782 pass / 20 opt-in live skips.
   `php -l` on every PHP file and `python3 -m py_compile` on both brokers: clean.
2. Static read of all PHP, Python, shell, Compose, nginx and supervisor files.
3. Three parallel read-only audit streams: backend/API, frontend/browser, and
   deploy/infrastructure (the latter two ran as independent subagents).
4. Fix each confirmed defect with a semantic commit, add a regression test.
5. Rebuild and redeploy the `webui` image on the Deck with `--no-deps` so the
   production Hermes gateway is untouched.
6. Re-run local, live, integration and targeted live probes.

Live environment: panel `http://192.168.2.157:9120`; containers `hermes-panel`
(panel) and `hermes` (production gateway, ports 8642/9119). No secret value was
printed at any point; the admin password was read into a shell variable only.

## 2. Confirmed defects and fixes

| # | Sev | Defect | Location (original) | Fix | Commit |
|---|-----|--------|---------------------|-----|--------|
| 1 | high | The daily 03:15 security scan and the 5-minute agent wake-up were cron commands that `worker.php` never implemented — they printed `usage` and exited 1. The advertised "Exploit Watch" never ran. | `crontab:5-6`, `src/worker.php` (42 lines, only `tick`/`loop`) | Implemented `security` (read-only scan → deduplicated findings → wake the `security` agent) and `agents` (schedule-due inbox notes). Removed the redundant supervisor `worker.php loop` that raced with cron's minute tick. | `0cee27c` |
| 2 | high | `deploy/install.sh` force-removed any container literally named `hermes-agent`. The app's own compose uses that name, so a host whose production gateway has the same name would be destroyed by an update. | `deploy/install.sh:99` | Cleanup is now filtered by `label=com.docker.compose.project=hermes-control-panel`, so only this project's stale container can be removed. | `24c9549` |
| 3 | high | Plain `docker compose up -d` would try to start a second gateway on 8642/9119, colliding with the production `hermes` container. `webui` also had a hard `depends_on: hermes`. | `docker-compose.yml:2-19,37-38` | The `hermes` service is behind `profiles: ["standalone"]` and `depends_on` was removed, so a default up starts only `webui`. | `24c9549` |
| 4 | high | The one-line installer wrote `ADMIN_USER`, `ADMIN_PASSWORD_HASH`, `ADMIN_SECRET`, `PANEL_SECRET`, `MODEL_API_KEY` — none of which the app reads. It produced a container that was healthy but impossible to log into, with no brokers, key or socket mounts. | installer repo `install.sh:36-47` | Rewritten to emit the real `PANEL_*` keys, generate the root-only HMAC key, install/enable both broker units, mount the socket directories, and start only `webui` with `--no-deps`. An existing admin password is preserved. | installer `a14a899` |
| 5 | medium | PHP signed host-action args in insertion order; the Python broker verifies with `json.dumps(sort_keys=True, separators=(",",":"))`. Any multi-key privileged action was rejected as forged. | `src/lib/hostctl.php:56` | Added recursive `sortedArgs()` (ksort) before `json_encode`, matching the broker's canonical JSON. | `e4b2b23` |
| 6 | medium | The root broker's convenience ops `status`, `scan`, `cve` needed no HMAC. Any process in the socket group could obtain a full host recon report (containers, sshd, firewall, listeners, pending updates). | `hostctl/hostctl.py:411-423` | Those ops now call the same signed, timestamped `verify()` as actions (only `ping` stays public). | `e4b2b23` |
| 7 | medium | The Audit page and Overview "Recent activity" both render `S.audit`, but `bootstrap` never returned it — both views were permanently empty. | `src/public/api.php:65-92`, `app.js:226,385` | `bootstrap` now returns the last 200 audit rows. Live check: 123 rows loaded. | `4668d31` |
| 8 | medium | `setup.status` returned the entire active config and environment requirements without `Auth::require()` — unauthenticated information disclosure. | `src/public/api.php:363` | Added `Auth::require()`. | `4668d31` |
| 9 | medium | The login rate limiter stored only a counter with no window timestamp: ten failed attempts locked an IP out forever instead of for 15 minutes. | `src/public/api.php:46-54` | Stores `{start,count}` and resets when the 900-second window elapses. | `4668d31` |
| 10 | low | The critical-package rules file was written to `/opt/panel-secrets/` but read from `/opt/hermes-control-panel/`, so the curated check was inert. | `hostctl/hostctl.py:234` | Broker reads `PANEL_CRITICAL_PACKAGES` (default: the secrets path). | `e4b2b23` |
| 11 | low | Socket ownership hardcoded uid 1000 in two Python files, which also broadened the "only the panel" claim to the whole `deck` group. | `hostctl/*.py` | Added `PANEL_SOCKET_GID` override (default still 1000 on this Deck). Residual risk noted in §4. | `e4b2b23` |
| 12 | low | Two independent tickers (`supervisord` loop and cron minute tick) raced on the same schedule. | `supervisord.conf:31-37` | Supervisor loop removed; cron is the single scheduler. | `0cee27c` |

## 3. Evidence after the fixes

- Local suite: **814 cases, 794 pass, 0 fail, 20 live skips**.
- Live suite (`RUN_LIVE=1`) against the redeployed Deck: **814/814 pass**.
- Deck integration: **16 passed, 0 failed** — login, both brokers, forged and
  stale HMAC refusal, RBAC denial, real secret-leak probe, restart persistence.
- `docker exec hermes-panel php /var/www/html/worker.php security` →
  `security scan ok new=1`, and a `host.security` row is written to
  `schedule_runs`.
- Authenticated `bootstrap` after redeploy → `audit_rows=123`, `agents=4`,
  `findings=6` (previously `audit_rows=0`).
- Health: `{"ok":true,"service":"hermes-control-deck",...}`.
- `docker ps`: `hermes-panel` healthy on 9120; `hermes` still owns 8642/9119;
  no duplicate gateway was created.
- Regression tests added in `tests/unit/debugging-regressions.php` lock in all
  of the above so none of them can silently return.

## 4. Residual risks (not changed, documented deliberately)

- The relay and broker sockets are group `deck` (gid 1000) with mode 0660. Any
  process running as that uid can connect; HMAC now protects every meaningful
  op, but a dedicated service gid would be a tighter boundary on a different
  host.
- An owner approval for the generic capability `host_apply` is currently not
  bound to a specific action ID at escalation time. The action is recorded in
  the approvals queue at execution. Binding the capability to
  `host_apply:<action>` would make the approval text more precise.
- `Scheduler::runSelfTest()` checks that each server-rendered page produces
  markup and that each curated Hermes source is registered; it does not drive
  the real browser UI. The browser verification procedure lives in the
  `panel-browser-verify` toolbox tool.
- AI-editable config revisions are append-only with content hashes and a
  `parent_revision` link, but each hash covers only its own config snapshot
  rather than the parent hash. The audit table itself has a true SHA-256 chain.

## 5. Reproduction commands

```bash
# source (local)
cd /home/timo/programming/hermes-control-panel
php tests/suite.php

# live suite — read the password from the root-only file silently, never echo it
HCP_LIVE_PASS="$LIVEPASS" RUN_LIVE=1 php tests/suite.php

# deploy (from the operator workstation)
DECK_SUDO_PASS="$PASS" bash /home/timo/programming/openhost/deploy/deck.sh \
  --sudo 'bash /opt/hermes-control-panel/deploy/install.sh'

# Deck integration
DECK_SUDO_PASS="$PASS" bash /home/timo/programming/openhost/deploy/deck.sh \
  --sudo 'bash /opt/hermes-control-panel/tests/integration.sh'
```
