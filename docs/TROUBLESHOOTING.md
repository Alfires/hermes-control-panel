# Troubleshooting — Hermes Control Deck

Purpose: a symptom → cause → fix → verification catalogue of the real operational failures found while building and auditing this panel, plus the general debug commands used on the openhost Deck. Every entry is grounded in code at commit `0137eff`; the fixes are locked in by `tests/unit/debugging-regressions.php` (see `docs/TESTING.md`) and narrated with commit evidence in `docs/DEBUGGING-REPORT.md`. No secret values are printed or stored here.

**Last verified:** main repo commit `0137eff` (`test: add regressions for deep-debug fixes`) via read-only `git log -1 --oneline`.

## A. Confirmed problems and fixes

### A1. Browser checks / verification proxy see a permanently blank UI

- **Symptom:** headless Firefox at the auth/cookie proxy shows only the hidden screens; `client.log` shows `FETCH setup.status` and `FETCH bootstrap` returning 403; UI never hydrates.
- **Cause:** `src/public/api.php` has a same-origin guard (lines 24–33): for every action except `login`/`me`, if an `Origin` header is present its host and port must equal the request's `Host`. A verification/auth proxy that forwards its **own** `Origin`/`Referer` (e.g. from `127.0.0.1:8899`) makes the panel reject the call as cross-origin. A second, unrelated trap is timing: headless `--virtual-time-budget` can expire before the Deck answers.
- **Fix:** the proxy must **strip `Origin` and `Referer`** before forwarding (the panel's own double-submit `X-CSRF-Token` + `panel_csrf` cookie protection is still enforced, so this does not disable a real control). Use a persistent `gui-debug` Xvfb session with `--action 'wait 15'` or longer rather than a fixed virtual-time budget.
- **Verification:** a clean load logs `PROBE-READY`, `FETCH me`, `FETCH setup.status`, then `FETCH bootstrap` (or the setup screen state) with no 403s; first-run shows "Your AI team, one deck." + requirements + "Finish setup". The full recipe is in the `panel-browser-verify` toolbox tool.

### A2. Broker sockets go dead after a broker restart

- **Symptom:** panel works until `vault-broker` or `panel-hostctl` restarts, then all broker calls return "broker unavailable" even though `systemctl is-active` says `active`.
- **Cause:** the Compose file originally bind-mounted the **socket files** (`/opt/panel-sockets/hostctl.sock` → container). A Unix socket file is an inode; when the broker recreates it, the container keeps talking to the stale inode.
- **Fix:** mount the **directory** `/opt/panel-sockets:/run/panel-sockets` and run `hostctl/socket_relay.py` as `panel-socket-relay.service`. The relay owns stable `vault.sock` and `hostctl.sock` and reconnects to the real upstream (`/run/vault-broker.sock`, `/run/panel-host.sock`) on every connection. Only the two socket files are exposed.
- **Verification:** `ls -l /opt/panel-sockets` shows both sockets (0660, root:deck); `systemctl restart panel-hostctl` then `curl` / an authenticated `bootstrap` still reports `host.available=true` and `vault.available=true`; `tests/integration.sh` passes "host broker active", "vault broker active" and the in-container broker checks.

### A3. Update starts a second Hermes gateway / destroys the production gateway

- **Symptom:** port conflict on 8642/9119 after `docker compose up -d`, or the production `hermes-agent` container disappears after an installer run.
- **Cause:** the Compose `hermes` service was started by a plain `up` and `webui` had a hard `depends_on: hermes`; separately, `deploy/install.sh` ran `docker rm -f hermes-agent` unconditionally, and the app's own compose also names its service `hermes-agent`.
- **Fix:** `hermes` is behind `profiles: ["standalone"]` and `depends_on` is removed, so a default up starts only `webui`. The installer starts `docker compose up -d --build --no-deps webui` and scopes stale-container cleanup to `docker ps -aq --filter 'name=^hermes-agent$' --filter 'label=com.docker.compose.project=hermes-control-panel'`. Production Hermes is reached via `HERMES_URL`/`HERMES_DASHBOARD_URL` on `host.docker.internal`.
- **Verification:** `docker ps` shows `hermes-panel` on 9120 and exactly one `hermes` owning 8642/9119; `docker compose config --services` still lists both services but `--profile standalone` is required to run the gateway; `debug.compose.gateway.is.profile.gated` and `debug.installer.does.not.kill.production.gateway` pass.

### A4. Daily security scan and agent wake-ups never ran

- **Symptom:** no new findings overnight; the "Exploit Watch" agent never woke; `worker.log` showed `usage: worker.php [tick|agents|security]` with exit 1 for the cron lines.
- **Cause:** `crontab` called `worker.php agents` and `worker.php security`, but `src/worker.php` only implemented `tick` (and a supervisor `worker.php loop` raced with cron's minute tick).
- **Fix:** implemented `security` (read-only broker scan → deduplicated findings → `host.security` schedule_runs row → wake the `security` agent) and `agents` (schedule-due inbox notes, skipping `security`); removed the supervisor `loop` program so cron is the single scheduler. `worker.php` now prints usage only for unknown commands.
- **Verification:** `docker exec hermes-panel php /var/www/html/worker.php security` → `security scan ok new=N` plus a `host.security` row; `grep` the three commands in `crontab`; `debug.worker.has.security.command`, `debug.worker.no.duplicate.loop`, `debug.cron.declares.all.jobs` pass.

### A5. Multi-key privileged actions refused as forged

- **Symptom:** `fix_service`, `fix_docker` or any action with more than one argument key failed at the broker with "bad authorization token", while single-key actions worked.
- **Cause:** PHP signed the canonical args in insertion order; the Python broker verifies with `json.dumps(args, sort_keys=True, separators=(",", ":"))`. Different key order ⇒ different HMAC.
- **Fix:** `HostCtl::call()` now recursively `ksort()`s args (`HostCtl::sortedArgs()`) before `json_encode` with `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`, matching the broker byte-for-byte. Empty args are cast to a JSON object `{}` on both sides.
- **Verification:** `debug.hostctl.args.are.sorted` passes (reflection on `sortedArgs()`); `tests/tools.php` and `integration.sh` confirm genuine signed calls work while forged/stale ones are refused.

### A6. Login locked out forever after 10 failures

- **Symptom:** after any burst of failed logins, even the correct password returned HTTP 429 indefinitely; only deleting a file or restarting helped.
- **Cause:** the rate limiter in `api.php` stored only a counter with no window timestamp, so it never reset.
- **Fix:** the lock file now stores `{start, count}`; a window of 900s (15 min) is enforced per hashed IP+username, and the counter resets when the window elapses. 10 attempts in the window → 429; a successful login deletes the file.
- **Verification:** live probe `login rate limit enforced` (12 attempts with a random user → 429); `debug.login.rate.window.expires` passes; after 15 minutes a correct login succeeds again.

### A7. Audit view and "Recent activity" permanently empty

- **Symptom:** the Audit page and Overview → Recent activity showed nothing even though actions were being logged.
- **Cause:** both views render `S.audit`, but the `bootstrap` API action never returned `audit`.
- **Fix:** `bootstrap` now returns the last 200 `audit` rows; `audit.list` exposes the same. Live check after redeploy showed `audit_rows=123` (previously 0).
- **Verification:** authenticated `api.php?action=bootstrap` returns a non-empty `audit` array; `audit.list` returns `ok:true`; `debug.bootstrap.includes.audit` passes.

### A8. One-line installer produced a healthy but unloggable container

- **Symptom:** `docker ps` showed `hermes-panel` healthy on 9120, but no login worked, no brokers were active and no sockets were mounted.
- **Cause:** the sibling installer wrote variables the PHP app never reads (`ADMIN_USER`, `ADMIN_PASSWORD_HASH`, `ADMIN_SECRET`, `PANEL_SECRET`, `MODEL_API_KEY`), so `PANEL_ADMIN_PASSWORD` was empty; it also skipped key generation, broker units and socket mounts.
- **Fix:** installer commit `a14a899` rewrites `panel.env` with the real `PANEL_*` keys, preserves an existing admin password, generates the root-only HMAC key, installs/enables both broker units, mounts the socket directory and key, and starts only `webui` with `--no-deps`. See `docs/DEPLOYMENT.md` §7 for the exact key list.
- **Verification:** `debug.installer.env.names.match.app` passes; `install.installer.*` safety cases pass; a fresh install logs in and `bootstrap` reports both brokers available.

### A9. Host recon available without HMAC

- **Symptom:** any process in the socket group could call convenience ops `status`, `scan`, `cve` and obtain a full host report (containers/ports, sshd config, firewall, listeners, pending updates).
- **Cause:** those ops were completely unauthenticated; only the `action` op verified HMAC.
- **Fix:** `hostctl.py` now applies the same signed, timestamped `verify("op:"+op, {}, …)` to `status`/`scan`/`cve`; only `ping` remains public. The PHP client always uses `op=action`, so this only closes the side door.
- **Verification:** `debug.hostctl.ops.require.hmac` passes; `tests/tools.php` forged-token probe is refused by the root broker.

### A10. `setup.status` leaked config unauthenticated

- **Symptom:** `GET /api.php?action=setup.status` returned the full active config and environment requirements to anyone who could reach the port.
- **Cause:** the case lacked `Auth::require()`.
- **Fix:** added `Auth::require()` at the top of the case (all setup/state endpoints are now authenticated; only `login`/`me` are public).
- **Verification:** unauthenticated call returns 401; `debug.setup.status.is.authenticated` passes.

### A11. Curated critical-package check was inert

- **Symptom:** the "critical packages" scan rule never produced a finding even when a rule was configured.
- **Cause:** the installer wrote `/opt/panel-secrets/critical-packages.json` but the broker read `/opt/hermes-control-panel/critical-packages.json`.
- **Fix:** broker reads `PANEL_CRITICAL_PACKAGES` (default `/opt/panel-secrets/critical-packages.json`); installer writes that path. Both files are chmod 600.
- **Verification:** `debug.hostctl.rules.path.aligned` passes; a rule with an unavailable package yields a `low` finding in `security_scan`.

### A12. Socket group hardcoded / "only the panel" overstated

- **Symptom:** socket ownership was pinned to uid 1000, and any process running as that uid could connect.
- **Cause:** both Python files hardcoded gid 1000, which is the whole `deck` group, not a dedicated service identity.
- **Fix:** `PANEL_SOCKET_GID` (default still 1000 on this Deck) now controls socket ownership in `hostctl.py` and `socket_relay.py`, and the installer exposes `SOCKET_GID`. Residual risk is documented in `docs/DEBUGGING-REPORT.md` §4: a dedicated service gid would be a tighter boundary on another host; HMAC now protects every meaningful op.
- **Verification:** `ls -l /opt/panel-sockets` shows root:gid 1000 mode 0660; changing `SOCKET_GID` on another host changes the owning group.

### A13. HMAC key missing in the container

- **Symptom:** entrypoint logs "no hostctl key mounted (host actions will be refused)"; privileged calls return "host control key unavailable".
- **Cause:** `/opt/panel-secrets/hostctl.key` was absent or the Compose mount `/opt/panel-secrets/hostctl.key:/run/hostctl-src/hostctl.key:ro` was wrong, so `entrypoint.sh` had nothing to install to `/run/secrets/hostctl.key`.
- **Fix:** re-run the installer to generate the key (root-only, 0600) and recreate the container; confirm the read-only mount exists and the installed copy is mode 0400 uid 1000.
- **Verification:** `docker exec hermes-panel ls -l /run/secrets/hostctl.key`; `tests/tools.php` signed calls succeed and the forged-token probe is refused.

### A14. Stale approvals / expired escalations linger in the UI

- **Symptom:** pending approvals or escalations remain visible after their purpose has passed.
- **Cause / behaviour:** approvals are set to `approved`/`denied` only by an explicit owner decision. Escalations are one-shot at use time (`uses_left`/`expires_at` are checked by `RBAC::activeEscalation()`), and `RBAC::expirePending()` can mark pending/expired rows — but note this helper is **not currently invoked by `worker.php tick`** at `0137eff`, so expiry is enforced at decision time rather than proactively. This is a known gap, not a silent data-loss bug.
- **Fix / mitigation:** use `escalation.decide` / `approval.decide` to clear the queues; if proactive expiry is desired, wire `RBAC::expirePending()` into the tick command and add a regression test.
- **Verification:** after a decision the row's `status` is no longer `pending`; `bootstrap` only lists escalations in `('pending','approved')` and approvals with `status='pending'`.

## B. General debug commands (run on the Deck as root)

```bash
# Services and containers
systemctl status panel-hostctl panel-socket-relay vault-broker --no-pager
docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}'
docker compose -f /opt/hermes-control-panel/docker-compose.yml ps
docker logs -f hermes-panel

# Sockets, keys (names/permissions only — never cat a key)
ls -l /run/panel-host.sock /run/vault-broker.sock /opt/panel-sockets
ls -l /opt/panel-secrets /run/secrets 2>/dev/null
ss -tlnp

# Broker unit logs and host log
journalctl -u panel-hostctl -u panel-socket-relay -f
tail -f /var/log/panel-hostctl.log

# In-container logs and health
docker exec hermes-panel sh -c 'tail -f /var/log/panel/worker.log'
docker exec hermes-panel sh -c 'tail -f /var/log/panel/nginx-error.log /var/log/panel/php-error.log'
curl -fsS http://127.0.0.1:9120/health.php

# Read-only API probes (password read silently into a variable; never echoed)
curl -s -b jar -c jar -H 'Content-Type: application/json' \
  -X POST http://127.0.0.1:9120/api.php?action=login --data-binary "{\"username\":\"$USER\",\"password\":\"$PASS\"}"
# then send X-CSRF-Token from the panel_csrf cookie for state-changing calls
curl -s -b jar -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:9120/api.php?action=bootstrap --data-binary '{}'

# Tests
sudo bash /opt/hermes-control-panel/tests/integration.sh
php /opt/hermes-control-panel/tests/suite.php     # local (source) copy
```

When reporting a failure, capture: `git -C /opt/hermes-control-panel log -1 --oneline`, the relevant `docker logs`/journal lines, HTTP status and `{"ok":false,"error":…}` bodies — but **never** `panel.env`, `hostctl.key`, logins, tokens or vault values.
