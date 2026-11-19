# Connecting the desktop app directly to Hermes

**There is no server in the chat path.** The desktop app and the web UI are two
clients of the same Hermes API. Chat, streaming, tool calls and approval cards
go straight from this app to your Hermes gateway. The PHP panel (port 9120) is
the *control plane* — it manages the vault, approvals, settings and git UI; it
does **not** relay conversations. You can verify this in the network tab: while
chatting you should see requests to the Hermes gateway URL only.

## What the app needs

Two things, both entered in the app's **Settings → Connections** screen:

1. **Gateway URL** — the address of your Hermes gateway (the `8642` API /
   `9119` dashboard host). Example shape: `https://hermes.example.com`.
2. **Gateway token** — a short-lived Hermes token. Enter it in the app; Electron
   stores it with `safeStorage` (OS keychain / DPAPI / libsecret). It is never
   written to a file we ship, never printed in logs, and never committed.

`connection.json.example` documents the native config shape with a placeholder
token. Copy it to the Electron `userData` directory only if you must preseed the
URL; **type the token in the app**, not into a file.

## Dev / smoke test

```bash
# No token in the command line: only the URL is set by the environment.
DECK_REMOTE_URL=https://hermes.example.com bash desktop/bootstrap.sh dev
```

Add the token in the app UI after launch. Then verify:

- a chat message streams back directly from the gateway;
- the right-side chat panel and its Fullscreen button work;
- the microphone button appears in the composer (disabled until a whisper.cpp
  model is installed in the gateway/image);
- no `hermes serve` process starts on this machine — the app is the remote-only
  "light" variant;
- the two Deck containers are unchanged (`hermes` still owns 8642/9119).

## Multi-user note (future)

Hermes' own docs say one instance acts as the machine owner for every login;
real tenant isolation needs one workspace/OS user per person. Today there is one
user, so one gateway is correct. If people are added later, add one Hermes
workspace per person in front of this client — the desktop app already keeps one
connection per profile, so no client rewrite is needed.

## Secrets used by agents

Agents never receive a credential value. They call the root vault broker with a
logical name and a `{{secret}}` placeholder; the broker injects the real value
into the one action and redacts the raw, base64 and URL-encoded forms from the
reply. `tests/leak-probe.sh` proves this against a real synthetic secret. See
`docs/SECURITY.md`.
