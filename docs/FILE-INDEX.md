# File index — Hermes Control Panel + installer

Purpose: a complete inventory of every tracked file in the main repo (`hermes-control-panel`, at commit `0137eff`) and its sibling installer repo (`hermes-control-panel-installer`, at commit `a14a899`), grouped by area with each file's purpose and line count (`wc -l` at verification). It also lists the docs being added by parallel writers in this docs pass, with a one-line purpose each; those files are not yet tracked, so no line count is asserted for them. Enumerated with read-only `git ls-files`.

**Last verified:** main repo commit `0137eff` (`test: add regressions for deep-debug fixes`) via read-only `git log -1 --oneline`; installer repo commit `a14a899`.

## Files added after that verification (not line-counted here)

`desktop/README.md`, `desktop/bootstrap.sh`, `desktop/connection.json.example`,
`desktop/patches/deck-identity.patch`, `docs/ROADMAP.md`, `docs/FRAMEWORK.md`,
`tests/unit/desktop-fork.php`, `tests/unit/framework-render.php`, `composer.json`,
`composer.lock`, `vendor/` (committed pinned Slim/Twig packages), `tools/vendor-refresh.sh`,
`src/framework/{Kernel,Renderer,BlockCatalog}.php`, `src/framework/templates/layout.twig`,
`src/framework/templates/blocks/*.twig`, `src/public/deck.php`, and
`src/public/vendor/{htmx-2.0.11.min.js,alpine-3.17.5.min.js,+ licenses}`,
`desktop/CONNECTION.md`, `tests/leak_echo_server.py`, `tests/leak-probe.sh`,
`tests/unit/vault-noleak-contract.php`.

## 1. Build, packaging and scheduling (main repo root)

| Path | Lines | Purpose |
|---|---:|---|
| `Dockerfile` | 41 | `php:8.3-fpm-alpine` image; adds nginx/supervisor/curl/bash/sqlite-libs/tzdata, writes FPM pool config (uid/gid 1000, `clear_env=no`), copies configs and `src/`, sets EXPOSE 8080 and the entrypoint. |
| `entrypoint.sh` | 17 | `set -eu`; fixes `/var/lib/panel` ownership for WAL writes, installs the mounted root-only HMAC key to `/run/secrets/hostctl.key` (0400, uid 1000), then execs supervisord. |
| `nginx.conf` | 36 | Web server on 8080: `root /var/www/html/public`, PHP via FastCGI 127.0.0.1:9000, security headers, 2 MiB body limit, dotfile deny. |
| `supervisord.conf` | 37 | Supervises `php-fpm`, `nginx`, `stt` (optional whisper.cpp on 8713) and `crond` under `nodaemon=true`; all logs under `/var/log/panel`. |
| `docker-compose.yml` | 46 | Two services: `webui` (panel, port `${WEBUI_PORT:-9120}:8080`) and `hermes` (profile-gated gateway, 8642/9119); socket-directory and key mounts. |
| `crontab` | 6 | Installed to `/etc/crontabs/root`: `worker.php tick` every minute, `agents` every 5 min, `security` daily 03:15. |
| `.dockerignore` | 11 | Excludes `.git`, `data/`, `panel.env`, `*.key`, `*.log`, tests, deploy, broker units, README from the image build context. |
| `.gitignore` | 4 | Ignores `panel.db`, `*.log`, `.env`, `__pycache__/`. |
| `README.md` | 110 | Owner-facing overview: architecture, four agents, security model, deploy command, daily exploit watch, quick test commands. |

## 2. Deployment and host integration

| Path | Lines | Purpose |
|---|---:|---|
| `deploy/install.sh` | 110 | Root-on-Deck install/update: clone from Forgejo, generate root-only HMAC key, write `panel.env` with the app's real keys, install/enable both broker units, start only `webui` with `--no-deps`. |
| `hostctl/hostctl.py` | 496 | Root-owned host broker on `/run/panel-host.sock`: fixed read-only ops + HMAC-authorised allowlisted fixes, timestamp/replay checks, recursive credential scrubbing. |
| `hostctl/socket_relay.py` | 91 | Stable relay sockets in `/opt/panel-sockets` (`vault.sock`, `hostctl.sock`) forwarding to live brokers so restarts are invisible to the container. |
| `hostctl/panel-hostctl.service` | 23 | systemd unit for the root host broker (hardening options, `/var/log/panel-hostctl.log`, `WantedBy=multi-user.target`). |
| `hostctl/panel-socket-relay.service` | 17 | systemd unit for the socket relay (`After=panel-hostctl.service vault-broker.service`, `Wants=panel-hostctl.service`). |

## 3. PHP backend — `src/lib/`

| Path | Lines | Purpose |
|---|---:|---|
| `src/lib/db.php` | 247 | SQLite PDO wrapper: schema migrations for all tables, WAL/foreign-key/busy-timeout pragmas, `audit()` with SHA-256 hash chain, `verifyAuditChain()`, legacy hash backfill. |
| `src/lib/auth.php` | 71 | Owner credential sync from `PANEL_ADMIN_*`, 12-hour session cookies (`panel_session`, HttpOnly/SameSite=Lax), login/logout/current. |
| `src/lib/rbac.php` | 165 | Tool catalogue (12 tools, risk levels), four default agents, `can()` permission engine, escalation request/approve/consume/expire and message opt-in rules. |
| `src/lib/engine.php` | 265 | Agent loop: model call, strict tool-call parsing, RBAC-gated tool execution, host/vault/layout/message bridges, one-shot escalation consumption, scrubbed trace. |
| `src/lib/config.php` | 213 | `ConfigGovernance`: default config, propose → draft → approve → rollback, append-only revisions, diffs, hashes and edits restricted to six config roots. |
| `src/lib/schema.php` | 171 | Dependency-free validation of AI-editable config: top keys, theme tokens, pages/blocks, capabilities, sources, feature flags, prompts, schedule and structural limits. |
| `src/lib/registry.php` | 129 | Declarative catalogues: `PanelActions` (35 API actions), `HermesSources` (20 curated, 10 forbidden endpoints), `PageRegistry` (built-in pages), `BlockRegistry` (17 block types). |
| `src/lib/render.php` | 63 | Server-side page/block renderer; escapes all output, emits no raw config HTML; client hydrates via API. |
| `src/lib/theme.php` | 65 | Seven theme presets, CSS-variable emission and strict token validation (colours/lengths/rgba/fonts only). |
| `src/lib/scheduler.php` | 50 | Parses `daily HH:MM` / `every Nm`, computes due-ness from `schedule_runs`, runs the read-only UI self-test. |
| `src/lib/hostctl.php` | 110 | PHP client for the root broker: allowlisted actions, recursive key sorting for byte-identical HMAC, reply scrubbing, `probeForged()` safe probe. |
| `src/lib/vault.php` | 74 | Vault broker client: list secret names/policies and one-shot `use` by logical name; no read/get; replies scrubbed twice. |
| `src/lib/hermes.php` | 97 | Narrow scrubbed Hermes proxy: curated sources only, field whitelists, TTL cache in `hermes_cache`, optional `HERMES_API_KEY`, safe deep links. |
| `src/lib/search.php` | 74 | Rebuilds `search_docs` from pages, agents, sessions, findings, audit and Hermes sources; scrubbed LIKE search. |
| `src/lib/setup.php` | 67 | First-run wizard state machine: requirements checks, step status, `setup.lock` under `PANEL_DATA`, completion audit. |
| `src/lib/stt.php` | 69 | Loopback whisper.cpp proxy on 8713: status discovery, multipart transcribe, `stt_jobs` logging, 10 MiB upload cap. |
| `src/lib/util.php` | 144 | Core helpers: JSON/body handling, aggressive `scrub()`/`scrubDeep()`, tool-call parser, slug validation, Unix-socket client. |
| `src/lib/prompt.php` | 35 | Casual-language normaliser and composer hint that wraps plain owner input with role/tools/style/safety context. |
| `src/lib/install.php` | 32 | Static checks for the Compose file (exactly two services) and the sibling installer (no set -x, no echoed secrets, required safety primitives). |

## 4. PHP application entry points and assets — `src/public/`, `src/`

| Path | Lines | Purpose |
|---|---:|---|
| `src/public/api.php` | 390 | Owner-authenticated JSON API: same-origin + double-submit CSRF guards, login rate limit, all agent/config/theme/vault/audit/selftest/setup actions (35 in registry). |
| `src/public/index.php` | 102 | Server-rendered SPA shell: owner sync, active theme injection, `panel_csrf` cookie, nav/blocks markup. |
| `src/public/health.php` | 6 | Unauthenticated JSON health probe (`{"ok":true,"service":"hermes-control-deck",…}`) used by Compose and installers. |
| `src/worker.php` | 106 | Cron command implementation: `tick` (self-test), `agents` (scheduled wake-ups), `security` (read-only scan → findings → wake). |
| `src/public/app.js` | 886 | Vanilla-JS SPA: view routing for all pages, API client with CSRF, chat, approvals, config/theme UI, live updates, `?debug=1` error overlay. |
| `src/public/app.css` | 333 | Hand-written Brezel-inspired light workspace styles (cards, nav, chat, tables, responsive layout). |
| `src/public/tailwind.css` | 19 | Committed Tailwind CSS output regenerated by `tools/build-css.sh` on a dev machine. |
| `src/public/assets/vendor/animate.min.css` | 12 | Minified vendor animation stylesheet used by the UI. |

## 5. Tests (main repo)

| Path | Lines | Purpose |
|---|---:|---|
| `tests/bootstrap.php` | 116 | Zero-dependency harness: `test()`/`skip_test()`, assertions, fixtures and `run_tests()` result reporting. |
| `tests/suite.php` | 27 | Main runner: globs all test dirs, requires ≥500 cases, prints registered count and TOTAL line, exits on failure. |
| `tests/unit.php` | 82 | Standalone fast unit run: storage, redaction, RBAC escalation one-shot semantics, tool-call parsing. |
| `tests/tools.php` | 59 | In-container tool test copied by `integration.sh`: live brokers, forged/stale HMAC refusal, engine-level RBAC denial. |
| `tests/integration.sh` | 87 | On-Deck end-to-end: container/brokers, login/CSRF, read-only APIs, in-container tests, real secret-leak probe, restart persistence (16 checks). |
| `tests/echo_server.py` | 23 | Test-only header-echo HTTP server used by the leak probe on 127.0.0.1:18999. |
| `tests/live/deck.php` | 37 | Registers 20 live checks; skips them unless `RUN_LIVE=1`. |
| `tests/live/livechecks.php` | 199 | Safe live probes against a running panel: health, login/rate-limit, bootstrap, Hermes sources, self-reverting config cycle, CSRF, brokers, HMAC, redaction. |
| `tests/unit/debugging-regressions.php` | 106 | 12 source-level regressions for the deep-debug fixes (worker commands, audit bootstrap, setup auth, rate window, sorted HMAC, gateway profile, installer keys, …). |
| `tests/unit/parsing.php` | 71 | Tool-call parser (valid/invalid), slug validation and schedule parsing (35 cases). |
| `tests/unit/redaction.php` | 55 | String (50) and deep-structure (20) redaction cases — the fake secret must never survive. |
| `tests/unit/schema.php` | 68 | 20 valid + 20 invalid + 10 limit config-validation cases. |
| `tests/unit/theme.php` | 65 | 7 presets + 10 valid + 10 invalid token-set cases. |
| `tests/contract/hermes_sources.php` | 50 | 20 curated sources × shape/scrub + 10 forbidden-endpoint cases. |
| `tests/contract/panel_actions.php` | 50 | Declares 35 API actions and generates 105 contract + 30 no-secret-field cases. |
| `tests/rbac/matrix.php` | 82 | Real `RBAC::can()` decisions across agents × tools × escalation states, plus vault grants and message opt-ins (184 cases). |
| `tests/config/governance.php` | 126 | 20 valid + 20 invalid proposals, 15 approvals, 15 rollbacks/history, 1 append-after-rollback case. |
| `tests/ui/render.php` | 41 | Renders 27 pages + 13 block types in empty/populated/error modes (120 cases); asserts no script tags or leaked fakes. |
| `tests/install/compose.php` | 49 | 20 Compose shape checks + 20 installer safety checks (use `INSTALL_REPO_DIR` to locate the sibling repo). |

## 6. Tooling and documentation (tracked)

| Path | Lines | Purpose |
|---|---:|---|
| `tools/build-css.sh` | 15 | Dev-only script that downloads a pinned Tailwind binary and regenerates `src/public/tailwind.css`; never run on the Deck. |
| `docs/IMPLEMENTATION-PLAN.md` | 698 | Original build plan and design decisions for the framework rebuild. |
| `docs/TODO.md` | 50 | Canonical checklist of remaining work / verified acceptance criteria. |
| `docs/research/brezel-deep-dive.md` | 610 | Research notes on the Brezel UI look and behaviour that inspired the panel. |
| `docs/research/hermes-feature-inventory.md` | 478 | Inventory of Hermes features/sources considered for curated proxying. |
| `docs/research/stt-framework-install.md` | 167 | Notes on installing the local whisper.cpp speech engine inside the panel image. |

## 7. Documentation added in this pass (not yet tracked)

Each of these has a one-line purpose; they are being written in parallel and at verification only `DEBUGGING-REPORT.md` was present on disk.

| Path | Purpose |
|---|---|
| `docs/DEPLOYMENT.md` | Build/container/Compose/broker/installer reference with the authoritative port map and env-var names. |
| `docs/OPERATIONS.md` | Daily/periodic jobs, findings lifecycle, escalations/approvals, audit chain, config revisions, themes, scheduler syntax, backup/restore and logs. |
| `docs/TESTING.md` | Harness, all test directories, 814-case local/live commands, live probes, Deck integration checks and expected outputs. |
| `docs/TROUBLESHOOTING.md` | Symptom → cause → fix → verification for the real defects found, plus general Deck debug commands. |
| `docs/FILE-INDEX.md` | This complete inventory of both repos. |
| `docs/ARCHITECTURE.md` | Intended high-level architecture and component boundaries of the panel and its brokers. |
| `docs/FRONTEND.md` | Intended guide to the SPA shell, views, client-side state and styling. |
| `docs/COMPONENTS.md` | Intended catalogue of UI blocks/pages and their renderer contracts. |
| `docs/API.md` | Intended reference for the `api.php` action surface, request/response shapes and auth rules. |
| `docs/DATA-MODEL.md` | Intended description of the SQLite tables, relationships and config schema. |
| `docs/SECURITY.md` | Intended security model: RBAC, HMAC, vault non-disclosure, CSRF/origin guards and isolation. |
| `docs/DEBUGGING-REPORT.md` | Reproducible record of the 19 Nov 2026 deep audit: 12 confirmed defects, fixes, evidence and residual risks (present, 96 lines). |

## 8. Sibling installer repo — all tracked files

Repo: `/home/timo/programming/hermes-control-panel-installer`, commit `a14a899`.

| Path | Lines | Purpose |
|---|---:|---|
| `install.sh` | 165 | One-line installer: root preflight, clone/update app, provision brokers/keys, write `panel.env` with real keys, build/start only `webui`; also `status` and `uninstall` subcommands. |
| `.env.example` | 13 | Names-only template of all 12 supported `panel.env` variables (values intentionally blank). |
| `docker-compose.yml.tpl` | 40 | Reference-only two-service Compose template documenting the `standalone` gateway profile and socket/key mounts. |
| `README.md` | 37 | One-line install command, root rationale, the five provisioning steps and the three subcommands. |

## Notes

- Both repos use conventional commit prefixes (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
- Line counts are from `git ls-files | xargs wc -l` at the stated commits; they change whenever a file is edited.
- Secret-bearing files (`panel.env`, `hostctl.key`, `setup.lock` at runtime) are **not tracked** in either repo and are intentionally absent from this index.
