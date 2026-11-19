# Deployment — Hermes Control Deck

Purpose: document exactly how the panel is built, containerised and installed on the openhost Steam Deck from the code in this repo and its sibling installer repo. It covers the image (`Dockerfile`, `entrypoint.sh`, `nginx.conf`, `supervisord.conf`, `crontab`), the two-service Compose topology with its `standalone` gateway profile, the two root-owned host brokers and their socket relay, the on-Deck installer (`deploy/install.sh`) and the one-line installer in `hermes-control-panel-installer`, including every environment variable name, the root requirement, what each step provisions and the complete port map. Secrets are described by name only; no value is recorded here.

**Last verified:** main repo commit `0137eff` (`test: add regressions for deep-debug fixes`) via read-only `git log -1 --oneline`; installer repo commit `a14a899`.

## 1. Port map (authoritative)

| Port | Bind / scope | Owner | Purpose |
|---|---|---|---|
| `9120` | host (configurable via `WEBUI_PORT`, host side) | `webui` container | Panel HTTP. Compose maps `"${WEBUI_PORT:-9120}:8080"`, nginx listens on container port 8080. Panel URL: `http://192.168.2.157:9120` (also reachable on the Deck's Tailscale IP). |
| `8080` | container loopback/network | nginx | nginx `default_server` inside `hermes-panel`. |
| `9000` | `127.0.0.1` inside container | PHP-FPM | FastCGI upstream for nginx (`fastcgi_pass 127.0.0.1:9000`). |
| `8713` | `127.0.0.1` inside container | whisper.cpp (optional supervisor `stt` program) | Loopback speech engine; never published to the host. |
| `8642` | host | **external/production Hermes gateway** (`hermes` container managed from `/opt/hermes`) | Hermes API. The Compose `hermes` service publishes it **only** under the `standalone` profile. |
| `9119` | host | **external/production Hermes gateway** | Hermes dashboard, also profile-gated in Compose. |
| `4097` | host (via `host.docker.internal`) | local OpenCode front door | Model endpoint: `PANEL_LLM_URL=http://host.docker.internal:4097/v1/chat/completions`. |

In production the panel reaches the gateway over the host gateway (`HERMES_URL=http://host.docker.internal:8642`, `HERMES_DASHBOARD_URL=http://host.docker.internal:9119`) and **does not start its own gateway**. Only the `webui` service is started by the installers (`--no-deps`).

## 2. The image

### `Dockerfile` (41 lines)

- Base: `php:8.3-fpm-alpine`; OCI labels "Hermes Control Panel" and the vault-backed-credential/per-agent-permission description.
- `apk add --no-cache nginx supervisor curl bash sqlite-libs tzdata`; creates `/run/nginx`, `/var/lib/panel`, `/var/log/panel`, `/var/www/html`.
- Overwrites the FPM pool config with `/usr/local/etc/php-fpm.d/zz-panel.conf`: `[www]` runs as uid/gid `1000` (the Deck's `deck` user, so it can reach brokers whose socket group is `1000`), listens on `127.0.0.1:9000` with owner/group `1000`, `pm = dynamic` (`max_children=16`, `start_servers=3`, `min_spare=2`, `max_spare=6`), `clear_env = no` (keeps `PANEL_*` variables visible to PHP), `error_log=/var/log/panel/php-error.log`, `log_errors=on`, `display_errors=off`. Removes `www.conf.default`.
- Copies `nginx.conf` → `/etc/nginx/nginx.conf`, `supervisord.conf` → `/etc/supervisord.conf`, `crontab` → `/etc/crontabs/root`, `entrypoint.sh` → `/usr/local/bin/entrypoint.sh`, `src/` → `/var/www/html/`.
- `chown -R 1000:1000` on web root, data and log dirs; `chmod 750 /var/lib/panel`; `chmod 755 entrypoint.sh`.
- `EXPOSE 8080`; `ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]`.

Note `.dockerignore` excludes `.git`, `.gitignore`, `data/`, `panel.env`, `*.key`, `*.log`, `__pycache__/`, `tests/`, `deploy/`, `hostctl/*.service` and `README.md` from the build context, so no secret and no broker source enters the image.

### `entrypoint.sh` (17 lines)

Runs with `set -eu`. It (1) re-owns/re-permissions the host-mounted `/var/lib/panel` so the FPM user can write SQLite WAL files, (2) creates `/run/secrets`, and (3) if `/run/hostctl-src/hostctl.key` is mounted, installs it as `/run/secrets/hostctl.key` with owner/group `1000:1000` and mode `0400`; if no key is mounted it logs a warning to stderr ("host actions will be refused"). It then `exec`s `/usr/bin/supervisord -c /etc/supervisord.conf`. The HMAC key is therefore present only as a container-local, FPM-readable file — never under the web root.

### `nginx.conf` (36 lines)

`worker_processes auto`; error log `/var/log/panel/nginx-error.log` (`warn`); PID `/run/nginx.pid`; 1024 worker connections; access log `/var/log/panel/nginx-access.log`; `sendfile on`, `keepalive_timeout 30`, `client_max_body_size 2m`, `server_tokens off`. Server block: `listen 8080 default_server`, `root /var/www/html/public`, `index index.php`, security headers added on every response (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`), `try_files $uri $uri/ /index.php?$query_string`, PHP via FastCGI with `SCRIPT_FILENAME` set and `fastcgi_read_timeout 300`, and `location ~ /\.(?!well-known) { deny all; }` to block dotfiles.

### `supervisord.conf` (37 lines)

`nodaemon=true`, logfile `/var/log/panel/supervisord.log`, pidfile `/run/supervisord.pid`, `user=root`. Four supervised programs, all `autostart=true` / `autorestart=true`:

| Program | Command | Priority | Logs |
|---|---|---|---|
| `php-fpm` | `/usr/local/sbin/php-fpm --nodaemonize` | 10 | `php-fpm.log`, `php-fpm-err.log` |
| `nginx` | `/usr/sbin/nginx -g "daemon off;"` | 20 | `nginx-out.log`, `nginx-err.log` |
| `stt` | `if [ -x /opt/stt/whisper-server ] && [ -f /opt/stt/models/ggml-small.en-q5_0.bin ]; then exec ... -m ... --host 127.0.0.1 --port 8713; else sleep 3600; fi` | 15 | `stt.log`, `stt-err.log` |
| `crond` | `/usr/sbin/crond -f -d 8` | 31 | `crond.log`, `crond-err.log` |

All log paths are under `/var/log/panel`. There is deliberately **no** supervisor `worker.php loop` program: cron is the single scheduler (see §4), so the minute tick cannot race a second ticker.

### `crontab` (6 lines)

Installed to `/etc/crontabs/root` by the Dockerfile, with `SHELL=/bin/sh` and a full `PATH`:

```cron
* * * * * cd /var/www/html && php worker.php tick >> /var/log/panel/worker.log 2>&1
*/5 * * * * cd /var/www/html && php worker.php agents >> /var/log/panel/worker.log 2>&1
15 3 * * * cd /var/www/html && php worker.php security >> /var/log/panel/worker.log 2>&1
```

## 3. Compose topology (`docker-compose.yml`, 46 lines)

Two services are declared; **both** use `env_file: ./panel.env` and `extra_hosts: ["host.docker.internal:host-gateway"]` and have `restart: unless-stopped`.

- **`hermes`** (`container_name: hermes-agent`) — the optional standalone gateway: `image: nousresearch/hermes-agent:latest`, `profiles: ["standalone"]`, ports `8642:8642` and `9119:9119`, volume `./data/hermes:/opt/data`, healthcheck `curl -fsS http://127.0.0.1:8642/health` (30s interval, 5s timeout, 5 retries, 40s start period). Because of the profile, a plain `docker compose up -d` **never** starts it, so a second 8642/9119 listener can never collide with production Hermes.
- **`webui`** (`container_name: hermes-panel`) — `build: .`, `image: hermes-control-panel:local`, port `"${WEBUI_PORT:-9120}:8080"`, volumes:
  - `./data:/var/lib/panel` (SQLite `panel.db` + `setup.lock`; survives container rebuilds),
  - `./data/stt-models:/opt/stt/models:ro`,
  - `/opt/panel-sockets:/run/panel-sockets` — a **directory** mount (not individual socket files) so the relay's sockets survive broker restarts,
  - `/opt/panel-secrets/hostctl.key:/run/hostctl-src/hostctl.key:ro` — the root-only HMAC key, mounted read-only to the path `entrypoint.sh` copies from.
  - Healthcheck: `curl -fsS http://127.0.0.1:8080/health.php` (30s/5s/3 retries/20s start).

There is **no `depends_on`** from `webui` to `hermes`. To start only the panel: `docker compose up -d --build --no-deps webui`. To run a throwaway gateway in a lab (never on the Deck): `docker compose --profile standalone up -d`.

## 4. Scheduled work inside the container

`src/worker.php` implements the three cron commands (`tick`, `agents`, `security`); it prints `usage: worker.php [tick|agents|security]` and exits 1 for anything else.

- `tick` (every minute): reads `schedule.self_test` from the active config (default `daily 04:20`), and when `Scheduler::due()` fires, runs `Scheduler::runSelfTest()`, writes a `webui.selftest` row to `schedule_runs`, opens a `medium` finding titled "Web UI self-test found broken views" when checks fail, and writes an audit row `selftest.daily`.
- `agents` (every 5 minutes): wakes enabled agents with a non-null `schedule` (excluding `security`) by dropping a plain-language note into their inbox when their schedule is due, recording `agent.wake.<id>` in `schedule_runs`.
- `security` (daily 03:15): calls the read-only `security_scan` broker action, stores new findings deduplicated by `(source=hostctl, title, status=open)`, records a `host.security` run, and messages the `security` agent to propose **one** `host_apply` fix per real issue. Nothing is applied here.

Scheduler expressions (from `src/lib/scheduler.php`): `daily HH:MM` (two-digit 24-hour UTC, e.g. `daily 04:20`) or `every Nm` (N ≥ 1, minutes only — `every 2h` and `every 0m` are invalid). The self-test expression is part of the AI-editable config and is schema-validated.

## 5. Root-owned brokers and socket relay

### `/opt/panel-secrets` (host, root-only, mode 0700)

Holds `hostctl.key` (a 32-byte hex HMAC key, mode 0600, root-only; generated by the installers if absent) and `critical-packages.json` (curated package-floor rules, written as `{}` if absent). The key is read by `hostctl.py` via `PANEL_HOST_KEY` (default `/opt/hermes-control-panel/hostctl.key`) and a container copy is mounted read-only into the panel. `critical-packages.json` is read via `PANEL_CRITICAL_PACKAGES` (default `/opt/panel-secrets/critical-packages.json`).

### `/opt/panel-sockets` (host, mode 0755)

Holds the two **stable** relay sockets `vault.sock` and `hostctl.sock`, owned root:deck (gid 1000 by default) mode 0660. The container mounts the whole directory at `/run/panel-sockets`; `VAULT_SOCKET=/run/panel-sockets/vault.sock` and `HOSTCTL_SOCKET=/run/panel-sockets/hostctl.sock` point the PHP clients at them.

### `hostctl/hostctl.py` (496 lines) + `panel-hostctl.service` (23 lines)

Runs as root, listening on `PANEL_HOST_SOCKET` (`/run/panel-host.sock` default), a 0660 root:deck Unix socket. It exposes only fixed, allowlisted operations:

- read-only: `system_status`, `security_scan`, `cve_lookup` (with optional offline-friendly OSV query);
- fixes (HMAC-signed, timestamped, one-shot-ready): `fix_updates` (apt update + dist-upgrade), `fix_service` (restart one regex-validated systemd unit), `fix_docker` (pull + recreate one validated compose project), `fix_ssh` (write `99-panel-hardening.conf` after `sshd -t`), `reboot` (delayed 3s).

There is no `exec`, no shell and no caller-built command line. Every reply passes through `scrub()` before leaving the process; the log is `PANEL_HOST_LOG` (`/var/log/panel-hostctl.log`). The even "convenience" ops `status`/`scan`/`cve` now require the same signed `verify("op:"+op, ...)`; only `ping` is public. The unit runs `User=root`, `UMask=0077`, `NoNewPrivileges=yes`, `ProtectSystem=full`, `ProtectHome=read-only`, `ReadWritePaths=/opt /run /var/log /etc/ssh/sshd_config.d`, `PrivateTmp=yes`, `Restart=always`, and is `WantedBy=multi-user.target`.

### `hostctl/socket_relay.py` (91 lines) + `panel-socket-relay.service` (17 lines)

Because a bind-mounted socket file points at an inode that changes when a broker restarts, the relay owns two stable listening sockets inside `RELAY_DIR` (`/opt/panel-sockets` default):

| Relay socket | Upstream target | Env override |
|---|---|---|
| `vault.sock` | `/run/vault-broker.sock` | `RELAY_VAULT` |
| `hostctl.sock` | `/run/panel-host.sock` | `RELAY_HOSTCTL` |

Each connection is pumped in both directions to a fresh upstream connection, so broker restarts are invisible to the panel. Sockets are chmod 0660 and chowned root:gid (`PANEL_SOCKET_GID`, default `1000`). The unit is `User=root`, `Group=root`, `UMask=0077`, `NoNewPrivileges=yes`, `Restart=always`, `After=panel-hostctl.service vault-broker.service`, `Wants=panel-hostctl.service`. Only the two socket files are exposed — no host filesystem is granted through the relay.

## 6. On-Deck installer: `deploy/install.sh` (110 lines)

Run as root **on the Deck**: `sudo bash /opt/hermes-control-panel/deploy/install.sh`. It uses `set -euo pipefail`, prints only `[panel] ...` status lines and never prints a secret. Input variables (all overridable via environment):

| Variable | Default | Meaning |
|---|---|---|
| `REPO_URL` | `http://127.0.0.1:3001/Alfires/hermes-control-panel.git` | Local Forgejo clone URL. |
| `DIR` | `/opt/hermes-control-panel` | Application checkout. |
| `SECRETS` | `/opt/panel-secrets` | Root-only secrets directory. |
| `LLM_ENV` | `/home/deck/opencode-api/api.env` | File from which `OPENCODE_API_TOKEN` is read for `PANEL_LLM_KEY`. |

Steps:

1. Creates `$SECRETS` (chmod 700) and `$DIR/data` (chown 1000:1000, chmod 770).
2. Clones from `REPO_URL` once (via a `mktemp -d /opt/.hcp-clone.XXXXXX` copy so existing root-only tokens/data survive), then on updates does `git fetch -q origin` + `git reset --hard -q origin/main`.
3. Generates `$SECRETS/hostctl.key` with `openssl rand -hex 32` (chmod 600) if missing; otherwise just re-chmods 600.
4. Reads `OPENCODE_API_TOKEN` from `$LLM_ENV` (fails if absent). On first install writes `$DIR/panel.env` (chmod 600) with the keys the app reads; on update it rewrites the two socket paths and appends the `HERMES_URL`/`HERMES_DASHBOARD_URL` lines if missing (see §7 for the full key list).
5. Creates `/opt/panel-sockets` (chmod 755), writes `critical-packages.json`, installs both systemd units from `$DIR/hostctl/`, `systemctl daemon-reload`, `enable --now` + `restart` both, waits up to 20×0.5s for `/run/panel-host.sock`, then for both relay sockets.
6. Stops `webui`, removes only a stale `hermes-agent` container labelled `com.docker.compose.project=hermes-control-panel`, then `docker compose up -d --build --no-deps webui` — never touching the production gateway.
7. Reports the panel URL and that the login password is stored only in `$DIR/panel.env`.

Useful verification commands:

```bash
sudo systemctl status panel-hostctl panel-socket-relay
docker ps --filter name=hermes-panel
docker compose -f /opt/hermes-control-panel/docker-compose.yml logs -f
curl -fsS http://127.0.0.1:9120/health.php
```

## 7. One-line installer (`hermes-control-panel-installer`, commit `a14a899`)

Invocation from the README:

```bash
curl -fsSL http://192.168.2.157:3001/Alfires/hermes-control-panel-installer/raw/branch/main/install.sh | sudo bash
```

Root is mandatory (`[[ $EUID -eq 0 ]]` in `preflight`): the panel is the only consumer of two root-owned brokers reached over group-restricted Unix sockets. `preflight` also requires `git`, `docker`, `openssl`, `curl` and the Docker Compose v2 plugin (`docker compose version`).

Input variables (defaults) in `install.sh` (165 lines):

| Variable | Default | Meaning |
|---|---|---|
| `APP_REPO` | `http://192.168.2.157:3001/Alfires/hermes-control-panel.git` | App clone URL. |
| `APP_REF` | `main` | Git ref to fetch/reset to. |
| `INSTALL_DIR` | `/opt/hermes-control-panel` | Checkout + Compose dir. |
| `PROTECTED_DIR` | `/opt/panel-secrets` | Root-only secrets (chmod 700). |
| `SOCKET_DIR` | `/opt/panel-sockets` | Stable relay sockets (chmod 755). |
| `WEBUI_PORT` | `9120` | Host port for the panel. |
| `SOCKET_GID` | `1000` | Socket group / data-dir owner (`deck`). |
| `LLM_ENV` | `/home/deck/opencode-api/api.env` | Source of `OPENCODE_API_TOKEN`. |

Subcommands: `install` (default — `preflight; fetch_app; provision_brokers; write_env; bring_up`), `status` (`docker compose ps`), `uninstall` (stops `webui`, preserves data and secrets). It uses `mktemp` + a cleanup `trap`, `set -euo pipefail`, no `set -x`, and never echoes a value.

What it provisions: clone/update of the app; root-only `hostctl.key` + `critical-packages.json`; both broker units installed/enabled/restarted with a socket-appearance wait; `panel.env` written with the **actual** variable names the PHP app reads; `data/` chowned to `SOCKET_GID` and chmod 770; then `docker compose build webui` + `docker compose up -d --no-deps webui` followed by a 60×2s health poll at `http://127.0.0.1:${WEBUI_PORT}/health.php`. An existing `PANEL_ADMIN_PASSWORD` is preserved; a missing model token is generated locally so the file is complete.

The full set of `panel.env` keys emitted by the installer (names only, never values) is:

```
PANEL_ADMIN_USER, PANEL_ADMIN_PASSWORD, PANEL_LLM_URL, PANEL_LLM_KEY,
HERMES_URL, HERMES_DASHBOARD_URL, VAULT_SOCKET, HOSTCTL_SOCKET,
PANEL_HOST_KEY, PANEL_SOCKET_GID, WEBUI_PORT, PANEL_DATA
```

with `PANEL_LLM_URL` defaulting to `http://host.docker.internal:4097/v1/chat/completions`, the two Hermes URLs pointing at `host.docker.internal:8642` / `:9119`, socket paths `/run/panel-sockets/*.sock`, `PANEL_HOST_KEY=/run/secrets/hostctl.key`, and `PANEL_DATA=/var/lib/panel`. Same names are documented in the installer's `.env.example` (values intentionally blank). `docker-compose.yml.tpl` in that repo is a **reference template only** — the installer uses the application repo's real Compose file; it documents the same two-service/profile shape.

The key-name mismatch is historically significant: an earlier installer wrote `ADMIN_USER`, `ADMIN_PASSWORD_HASH`, `ADMIN_SECRET`, `PANEL_SECRET`, `MODEL_API_KEY` — none of which the app reads — producing a healthy but unloggable container. See `docs/TROUBLESHOOTING.md`.
