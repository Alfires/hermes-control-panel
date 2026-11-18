# Hermes Control Deck

A single-container control panel for the Hermes agents on the openhost Steam Deck.
It gives every agent its own card, toolset, credential scope and session history,
and it lets the owner approve privileged actions one at a time.

Panel: `http://192.168.2.157:9120` (also on the Deck's Tailscale IP).

## What it is

- **PHP 8.3 + nginx + PHP-FPM in one container** (plus cron for scheduled runs).
- **Vanilla JS single-page UI** — no framework, no CDN, no build step.
- **SQLite** state in `/var/lib/panel` (persistent volume).
- **The local OpenCode API** at `host.docker.internal:4097` is the model source.
  The panel runs its own permission-gated agent loop; the chat bridge itself has
  tools disabled, so this is deliberate, not an accident.

## The four default agents

| Agent | Scope | Tools |
|---|---|---|
| Deck System | host overview + changes | `host_status`, `host_scan`, `host_apply`, messaging |
| Web Interface | this panel only | `webui_read`, `webui_edit`, messaging |
| Chat & News | conversation only | `chat`, `notes_read`, `notes_write` |
| Exploit Watch | daily scan + fixes on approval | `host_scan`, `cve_lookup`, `host_apply`, vault use, messaging |

Agents cannot be deleted. New agents start with exactly the tools you tick and
zero secret grants. Names, roles, ranks, system prompts, models and schedules are all editable.

## Security model (the important part)

There are two independent root-owned brokers and the agent never reaches either directly:

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

## Deployment

On the Deck, as root:

```bash
sudo bash /opt/hermes-control-panel/deploy/install.sh
```

The installer clones/updates from the local Forgejo, creates `panel.env`
(root `0600`, contains the owner password and model key), generates the HMAC key
in `/opt/panel-secrets`, installs `panel-hostctl.service`, and builds/starts the
single container. It never prints a secret.

Useful commands:

```bash
sudo systemctl status panel-hostctl
sudo /opt/hermes/hermesctl.sh status
sudo /home/deck/vault/vault.py list
docker compose -f /opt/hermes-control-panel/docker-compose.yml logs -f
```

Add a secret (value goes on stdin, never on the command line):

```bash
printf '%s' 'the-value' | sudo /home/deck/vault/vault.py add NAME \
  --label 'what it is' --allow-http host:port --approval always
```

## Daily exploit watch

`03:15` — a read-only scan checks pending updates, SSH settings, firewall,
unexpected all-interface listeners, privileged containers, world-writable files
and curated critical-package rules. Findings are stored, and the Exploit Watch
agent is woken to propose one fix per real issue. Nothing is applied without an
approval. Owner can also press **Run scan now** in the panel.

## Tests

```bash
php tests/unit.php                                   # 30 checks, no Deck needed
sudo bash /opt/hermes-control-panel/tests/integration.sh   # on the Deck
```

The integration suite logs in over HTTP, checks both brokers, forces forged and
stale HMAC requests against the root broker, verifies an agent without a tool or
approval cannot use it, and performs a real leakage probe: a temporary secret is
injected into an echo endpoint and the raw value must be absent from every reply.

## Repository

`http://192.168.2.157:3001/Alfires/hermes-control-panel`
Commits use conventional semantic prefixes (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
