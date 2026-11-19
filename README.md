# Hermes Control Deck

A purpose-built control panel for the Hermes agents on the openhost Steam Deck.
Every agent gets its own card, toolset, credential scope and session history,
and the owner approves every privileged host action one at a time.

**Panel:** `http://192.168.2.157:9120` (also on the Deck's Tailscale IP).
**Hermes API / dashboard:** `http://192.168.2.157:8642/v1` and `:9119`, owned by
the pre-existing `hermes` container from `/opt/hermes` — this project never
starts a second gateway.

## What it is

- **PHP 8.3 + nginx + PHP-FPM** in one `webui` container, plus cron for the
  scheduled jobs. The Compose file also defines a `hermes` service, but it is
  behind the `standalone` profile so a plain `docker compose up` cannot collide
  with the production gateway on ports 8642/9119.
- **Vanilla JS single-page UI** — no framework, no CDN, no npm on the target;
  Tailwind v4 output and animate.css are vendored into the image.
- **SQLite** state in `/var/lib/panel` (persistent volume); append-only config
  revisions with a hash chain for AI-editable UI changes.
- **The local OpenCode API** at `host.docker.internal:4097/v1/chat/completions`
  is the model source (default model `big-pickle`). The panel runs its own
  permission-gated agent loop; the Hermes chat bridge itself has tools disabled,
  so this is deliberate, not an accident.
- **Two root-owned brokers** (vault and host control) reached over group-
  restricted unix sockets through a directory-mounted relay; no host systemd
  unit is created by the panel itself beyond those two brokers.

## The four default agents

| Agent | Scope | Tools |
|---|---|---|
| Deck System | host overview + changes | `host_status`, `host_scan`, `host_apply`, messaging |
| Web Interface | this panel only | `webui_read`, `webui_edit`, messaging |
| Chat & News | conversation only | `chat`, `notes_read`, `notes_write` |
| Exploit Watch | daily scan + fixes on approval | `host_scan`, `cve_lookup`, `host_apply`, vault use, messaging |

Agents cannot be deleted. New agents start with exactly the tools you tick and
zero secret grants. Names, roles, ranks, system prompts, models and schedules
are all editable.

## Security model (the important part)

```
agent ──(conversation only)──► panel backend ──(RBAC + HMAC)──► host broker (root)
                                      │
                                      └──(logical name only)──► vault broker (root)
```

1. **The model has no tools.** It can only emit a `TOOL_CALL:` line. The panel
   parses it, checks RBAC, and decides whether anything runs.
2. **Secrets cannot be read.** The vault broker has `list` and `use`, no `get`.
   The value is injected by root into one HTTP request / file / registered
   helper, and the answer is redacted there and again in PHP. A secret-shaped
   string anywhere in a reply becomes `***CENSORED***`.
3. **Host actions are allowlisted.** No shell, no arbitrary command: only fixed
   functions for status, scan, CVE lookup, updates, service restart, compose
   recreate, SSH hardening and reboot. Service/compose/package names are
   regex-validated and checked for existence.
4. **Privileged actions need an owner approval** per use, with a short TTL.
   They also need an HMAC signed with a root-only key that the agent cannot read.
   Denied/replayed/stale requests are refused by the root broker itself.
5. **Approvals cannot be self-granted.** Decision files live in root-only
   directories; nothing running as the panel user can write them.
6. **Isolation.** Each agent has its own tools, secret grants, sessions and
   inbox. Messages are opt-in (`can_message`). New agents start with nothing.

Residual risks and the precise trust boundaries are documented in
[docs/SECURITY.md](docs/SECURITY.md).

## Quick start

```bash
# One-line install (root required — it provisions the two brokers)
curl -fsSL http://192.168.2.157:3001/Alfires/hermes-control-panel-installer/raw/branch/main/install.sh | sudo bash

# Or update an existing Deck checkout in place
sudo bash /opt/hermes-control-panel/deploy/install.sh
```

The installer clones/updates from the local Forgejo, creates `panel.env`
(root `0600`, admin password and model key), generates the root-only HMAC key
in `/opt/panel-secrets`, installs both broker units, and builds/starts **only**
the `webui` container with `--no-deps`. It never prints a secret. The admin
password is in `panel.env` on the target.

Add a secret (the value is read from stdin, never from the command line):

```bash
printf '%s' 'the-value' | sudo /home/deck/vault/vault.py add NAME \
  --label 'what it is' --allow-http host:port --approval always
```

## Daily exploit watch

`worker.php security` runs daily from cron. It performs a read-only scan of
pending updates, SSH settings, firewall, unexpected all-interface listeners,
privileged containers, world-writable files and curated critical-package rules.
Findings are deduplicated into the queue and the Exploit Watch agent is woken to
propose one fix per real issue. **Nothing is applied without a separate owner
approval.** The owner can also press **Run scan now** in the panel.

## Tests

```bash
cd /home/timo/programming/hermes-control-panel
php tests/suite.php                       # 814 cases (20 live probes opt-in)
HCP_LIVE_PASS=... RUN_LIVE=1 php tests/suite.php   # all 814 against the live panel

# On the Deck, full end-to-end integration:
sudo bash /opt/hermes-control-panel/tests/integration.sh   # 16 checks
```

The integration suite logs in over HTTP, checks both brokers, forces forged and
stale HMAC requests against the root broker, verifies an agent without a tool or
approval cannot use it, performs a real leakage probe (a temporary secret is
injected into an echo endpoint and the raw value must be absent from every
reply), and confirms sessions survive a container restart.

## Documentation

Every part of the system is documented; start with the map and go deeper:

| Document | What it covers |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Containers, trust zones, request/model/UI-edit flows, full page & source registries |
| [FRONTEND.md](docs/FRONTEND.md) | Every SPA view, state object, event bindings, voice, wizard, theming |
| [FRAMEWORK.md](docs/FRAMEWORK.md) | Slim + Twig + htmx + Alpine foundation, block catalog, safety invariants |
| [COMPONENTS.md](docs/COMPONENTS.md) | Every PHP module: purpose, public API, invariants, call graph |
| [API.md](docs/API.md) | Every `api.php` action and RBAC catalogue entry |
| [DATA-MODEL.md](docs/DATA-MODEL.md) | Every SQLite table, column, lifecycle and the hash chains |
| [SECURITY.md](docs/SECURITY.md) | Brokers, HMAC, RBAC, escalations, approvals, redaction, residual risks |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Dockerfile, compose, brokers, installer, ports, exact commands |
| [OPERATIONS.md](docs/OPERATIONS.md) | Schedules, findings, approvals, config revisions, backup/restore, logs |
| [TESTING.md](docs/TESTING.md) | Test harness, local/live/integration commands and expected output |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Symptom → cause → fix for the real problems found |
| [DEBUGGING-REPORT.md](docs/DEBUGGING-REPORT.md) | The full deep-audit record with fixes, commits and evidence |
| [FILE-INDEX.md](docs/FILE-INDEX.md) | Inventory of every tracked file in both repos |
| [TODO.md](docs/TODO.md) | Canonical checklist and verification evidence |
| [ROADMAP.md](docs/ROADMAP.md) | Complete redesign roadmap: decisions, phases, acceptance criteria, risk register |
| [desktop/README.md](desktop/README.md) | Pinned Hermes Desktop fork: build, login bridge plan, real vs planned features |

## Repository

- Source: `http://192.168.2.157:3001/Alfires/hermes-control-panel`
- One-line installer: `http://192.168.2.157:3001/Alfires/hermes-control-panel-installer`
- This repository mirrors to GitHub as `Alfires/hermes-control-panel`.

Commits use conventional semantic prefixes (`feat:`, `fix:`, `test:`, `docs:`,
`chore:`).
