# Testing — Hermes Control Deck

Purpose: explain every test harness and directory in this repo, the 814-case suite and how it splits into a local run and an opt-in live run, the safe live probes in `tests/live/livechecks.php`, the on-Deck `tests/integration.sh` (container, CSRF, HMAC, RBAC, leakage and restart checks), the regression file that locks in the deep-debug fixes, and the exact commands and expected outputs for each. Nothing here prints or persists a secret: live credentials are passed via environment variables.

**Last verified:** main repo commit `0137eff` (`test: add regressions for deep-debug fixes`) via read-only `git log -1 --oneline`. Local suite run at verification: **814 cases registered, 794 passed, 0 failed, 20 skipped** (the 20 live-only cases).

## 1. Harness: `tests/bootstrap.php` + `tests/suite.php`

`tests/bootstrap.php` (116 lines) is a zero-dependency harness. Global state: `$GLOBALS['HCP_TESTS']` (registered cases), `HCP_RESULTS` (pass/fail/skip counters) and `HCP_FAILURES`. Helpers:

- `test(string $id, callable $fn)` registers one case; `skip_test($id, $reason)` registers a case that throws `SKIP:<reason>` and is counted as skipped.
- Assertions: `ok()`, `eq()`, `contains()`, `not_contains()`, `count_at_least()` — each throws on failure with a diagnostic.
- Fixtures: `lib($file)` loads `src/lib/<file>`, `repo_path($rel)` resolves repo paths, `fake_secret($n)` returns a synthetic `TEST-SECRET-DO-NOT-USE-…` marker, `fixture_config($overrides)` builds a schema-valid config.
- `run_tests()` iterates all cases, prints `FAIL  <id> :: <message>` per failure, then a `TOTAL: N cases, P passed, F failed, S skipped` line and up to 15 "First failures"; returns 1 if any case failed, else 0.

`tests/suite.php` (27 lines) requires the bootstrap, `glob()`s and `sort()`s every `*.php` under `tests/unit/`, `contract/`, `rbac/`, `config/`, `ui/`, `install/` and `live/`, requires each (skipping any stray `bootstrap.php`), and refuses to run if fewer than 500 cases are registered (`ERROR: need at least 500 cases…`, exit 2).

### Commands and expected output

From a checkout on the workstation (no Deck required):

```bash
cd /home/timo/programming/hermes-control-panel
php tests/suite.php
```

Expected (verified at `0137eff`):

```
Hermes Control Deck — framework test suite
Registered cases: 814

TOTAL: 814 cases, 794 passed, 0 failed, 20 skipped
```

Exit code 0. Per-directory case breakdown (all counted individually, including data-provider expansions):

| Directory / file | Cases | What it covers |
|---|---:|---|
| `tests/unit/debugging-regressions.php` | 12 | Source-level guards for each deep-debug fix (see §4). |
| `tests/unit/parsing.php` | 35 | Tool-call parser (5 valid / 10 invalid), slug validation (10), schedule parsing (10). |
| `tests/unit/redaction.php` | 70 | String redaction across 10 credential shapes × 5 wrappers (50), plus 20 deep-structure cases. |
| `tests/unit/schema.php` | 50 | Config validation: 20 valid, 20 invalid mutators, 10 structural-limit cases. |
| `tests/unit/theme.php` | 27 | 7 presets, 10 valid token sets, 10 invalid token sets. |
| `tests/contract/hermes_sources.php` | 50 | 20 curated Hermes sources × {shape, scrub} (40) + 10 forbidden endpoint cases. |
| `tests/contract/panel_actions.php` | 135 | 35 declared actions × {exists, shape, auth} (105) + 30 "no secret-bearing response field" cases. |
| `tests/rbac/matrix.php` | 184 | RBAC matrix (4 default agents × 12 tools × 3 escalation states = 144 real `RBAC::can()` decisions) + 20 vault-grant + 20 message-opt-in cases. *(The file's own comment says "168 matrix cases"; the code registers 144 — see Ambiguities.)* |
| `tests/config/governance.php` | 71 | 20 valid proposals, 20 invalid, 15 approvals, 15 rollbacks + history, 1 append-after-rollback case. |
| `tests/ui/render.php` | 120 | 27 pages × {empty, populated, error} (81) + 13 block types × 3 modes (39) via `Render::page()`/`Render::block()`. |
| `tests/install/compose.php` | 40 | 20 static checks of `docker-compose.yml` + 20 safety checks of the sibling installer `install.sh` (path overridable with `INSTALL_REPO_DIR`). |
| `tests/live/deck.php` | 20 | Opt-in live checks against a running Deck; skipped unless `RUN_LIVE=1`. |
| **Total** | **814** | 794 local + 20 live; verified counts above. |

Other test entry points (not part of `suite.php`): `tests/unit.php` (82 lines, 30 fast checks with its own PASS/FAIL printer; `php tests/unit.php`) and `tests/tools.php` (59 lines, copied into the container by `integration.sh`).

## 2. Live suite: `tests/live/deck.php` + `livechecks.php`

`tests/live/deck.php` registers 20 cases named `live.N.<slug>`. When `RUN_LIVE` is not `1` each is registered via `skip_test(..., 'set RUN_LIVE=1')`, which is why a local run reports 20 skips. With `RUN_LIVE=1` they run for real and the total becomes 814/814 pass against the redeployed Deck.

`tests/live/livechecks.php` (199 lines) is the safe-probe implementation. It never prints credentials:

- Base URL from `HCP_LIVE_URL` (default `http://192.168.2.157:9120`). Cookies in a `tempnam()` jar removed by a shutdown function. `init()` does an unauthenticated GET of `/` to obtain the `panel_csrf` cookie, then every state-changing API call sends `X-CSRF-Token`.
- Login uses `HCP_LIVE_USER` (default `admin`) and `HCP_LIVE_PASS` from the environment; if the password is empty, live checks that need auth simply fail closed. Wrong-password and rate-limit checks use random fake usernames.
- All probes are read-only or self-reverting. The config-cycle probe proposes a replace of `/meta/description` with a random `live-check-<hex>` marker, approves it, then **rolls back to the revision captured before the proposal** and asserts the marker is gone. No host action, secret use or gateway mutation is performed.

The 20 probe descriptions (exact strings) are:

1. `health.php answers 200`
2. `login rejects wrong password`
3. `login accepts owner password`
4. `bootstrap contains 4 default agents`
5. `search.query returns results`
6. `hermes.status source answers`
7. `hermes.sessions source shape safe` (asserts no api_key/password/access_token appears in the raw response)
8. `ui.config.propose is dry-run`
9. `ui.config.approve applies revision`
10. `ui.config.rollback restores revision` (8–10 share the self-reverting `configCycle()`)
11. `theme presets endpoint works` (≥7 presets)
12. `voice status reports engine`
13. `selftest.run creates a report`
14. `state.diff returns versioned delta`
15. `CSRF token enforced` (unauthenticated bootstrap returns 403 / not ok)
16. `login rate limit enforced` (12 attempts with a random user yields HTTP 429)
17. `vault broker list answers`
18. `host broker status answers`
19. `forged HMAC refused` (`panel.host.probe`)
20. `secret redaction marker present` (`panel.redaction.probe` yields `CENSORED`)

Commands (password read silently from the root-only file; never echo it):

```bash
# local only
php tests/suite.php

# live against the Deck — 814/814 expected
HCP_LIVE_PASS="$LIVEPASS" RUN_LIVE=1 php tests/suite.php
# optional: HCP_LIVE_URL / HCP_LIVE_USER overrides
```

Where `$LIVEPASS` is populated without printing, e.g. on the operator workstation:

```bash
LIVEPASS="$(DECK_SUDO_PASS="$PASS" bash /home/timo/programming/openhost/deploy/deck.sh \
  --sudo "sed -n 's/^PANEL_ADMIN_PASSWORD=//p' /opt/hermes-control-panel/panel.env | head -1" 2>/dev/null | tr -d '\r\n')"
```

## 3. `tests/integration.sh` — on-Deck end-to-end (87 lines)

Run as root from the deployed repo:

```bash
sudo bash /opt/hermes-control-panel/tests/integration.sh
# or from the workstation, without printing the sudo password:
DECK_SUDO_PASS="$PASS" bash /home/timo/programming/openhost/deploy/deck.sh \
  --sudo 'bash /opt/hermes-control-panel/tests/integration.sh'
```

It uses `set -uo pipefail`, a `mktemp` cookie jar (cleaned by a trap), `BASE=http://127.0.0.1:9120`, `DIR=/opt/hermes-control-panel`, and helper functions `ok`, `code`, `csrf`, `http` (the latter sends `Content-Type: application/json` and `X-CSRF-Token` read from the jar). It reads `PANEL_ADMIN_USER`/`PANEL_ADMIN_PASSWORD` from `panel.env` into shell variables (never prints them). Expected result: **16 passed, 0 failed** (exit 0).

Checks, in order:

| Group | Checks |
|---|---|
| Container & brokers | `hermes-panel` running; `panel-hostctl` active; `vault-broker` active. |
| Web / auth | index page returns 200; owner login returns 200 and `{"ok":true}`; `bootstrap` contains ≥4 agents and both brokers available. |
| Read-only API | `security.scan` returns `ok` and `findings`; `audit.list` returns `ok`. |
| In-container tool tests | copies `tests/tools.php` into the container via `docker cp`, runs `php /tmp/tools.php`, removes it. |
| Real secret leakage probe | starts `tests/echo_server.py` on 127.0.0.1:18999; adds a temporary secret `panel-leak-test` to the root vault with an HTTP allow for that endpoint and `policy` approval; calls `vault.test` with `{{secret}}` injected into a header; asserts the raw secret is **absent** from the response, that an explicit `CENSORED`/`***` redaction marker is present, then removes the temporary secret and kills the echo server. |
| Restart persistence | `docker restart hermes-panel`, polls `api.php?action=me` until 200, asserts the panel answers and the session/CSRF cookie still works for `bootstrap`. |

`tests/tools.php` (59 lines) runs **inside** the container against the real brokers with `PANEL_DB=/tmp/panel-tools-test.db`: it checks `host_status` and `security_scan` from the root broker and `vault list`; then sends a deliberately forged `fix_service` request (bad token) and a stale (900s-old) HMAC request directly to the host socket and requires both to be refused; then checks RBAC at the engine level — `host_apply` is denied both without the tool and without an approval even when the tool is granted. It prints `PASS`/`FAIL` per check and ends with `ALL TOOL TESTS PASSED` (exit 0) or `N TOOL TESTS FAILED` (exit 1).

`tests/echo_server.py` (23 lines) is a test-only HTTP server that echoes request headers as JSON, so any leaked secret would be visible; it suppresses access logging.

## 4. Regression file: `tests/unit/debugging-regressions.php` (106 lines)

Registered in the main suite as 12 cases; each guards a real, already-fixed defect by reading source text (mostly) rather than spinning up a Deck:

1. `debug.worker.has.security.command` — `worker.php` implements `security` and `agents` and calls `HostCtl::call('security_scan'…)`.
2. `debug.worker.no.duplicate.loop` — `supervisord.conf` has no `worker.php loop`.
3. `debug.cron.declares.all.jobs` — `crontab` declares all three worker commands.
4. `debug.bootstrap.includes.audit` — `bootstrap` returns `audit => DB::all(...)`.
5. `debug.setup.status.is.authenticated` — `setup.status` calls `Auth::require()`.
6. `debug.login.rate.window.expires` — rate limiter stores `start` and expires after the window.
7. `debug.hostctl.args.are.sorted` — reflection on `HostCtl::sortedArgs()` proves recursive ksort canonical JSON.
8. `debug.installer.does.not.kill.production.gateway` — `deploy/install.sh` never `docker rm -f hermes-agent` unscoped; uses the compose-project label.
9. `debug.compose.gateway.is.profile.gated` — `profiles: ["standalone"]` present, no `depends_on:`.
10. `debug.hostctl.ops.require.hmac` — broker signs convenience ops and calls `verify("op:" + op…)`.
11. `debug.hostctl.rules.path.aligned` — broker and installer agree on `/opt/panel-secrets/critical-packages.json`.
12. `debug.installer.env.names.match.app` — sibling installer emits `PANEL_ADMIN_USER`, `PANEL_ADMIN_PASSWORD`, `PANEL_LLM_KEY`, `VAULT_SOCKET`, `HOSTCTL_SOCKET`, `PANEL_HOST_KEY` and no legacy `ADMIN_PASSWORD_HASH`.

Run just this file through the harness with `php -r` (it expects bootstrap globals), or simply run the full suite — the normal path.

## 5. Syntax and static checks

Useful before committing (all read-only):

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile hostctl/hostctl.py hostctl/socket_relay.py tests/echo_server.py
php -r 'echo PHP_VERSION_ID, "\n";'   # need >= 80300
bash -n deploy/install.sh tests/integration.sh tools/build-css.sh
```

At `0137eff` these were all clean (see `docs/DEBUGGING-REPORT.md`). The CSS build tool (`tools/build-css.sh`) is a dev-machine-only step that regenerates the committed `src/public/tailwind.css`; the Deck never runs npm/node.
