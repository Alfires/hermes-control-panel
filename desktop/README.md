# Hermes Control Deck — desktop app

The desktop app is a **controlled fork of the real Hermes Desktop**, not a
rewritten client. We keep Nous Research's Electron shell, React chat surface,
native panes, remote-gateway authentication, and platform packaging, and layer
a tiny identity patch on top. User decision (Nov 2026): desktop first, built on
Hermes Desktop source, one user, no TypeScript added by us.

## What is pinned

- Upstream: `https://github.com/NousResearch/hermes-agent.git`
- Commit: `7c7daa25c66262d504e3c2b29ade0ed5928b056e`
- License: upstream MIT; our patch and scripts follow the same license.
- Variant: `light` (remote-only). The app connects to **your** Hermes server; it
  does not install or start a second local agent on the desktop.

The upstream checkout is deliberately **not** committed here (59 MB). The build
script pins the commit and applies `patches/deck-identity.patch`, which:

- renames the product to “Hermes Control Deck”;
- uses our own app IDs (`com.alfires.hermes-control-deck*`) so Deck installs do
  not collide with a stock Hermes install;
- sets the update channel to `null` so a fork never pulls Nous Research release
  builds over our own app.

## Build

On a machine with Node >= 26 and pnpm 12.15.0:

```bash
bash desktop/bootstrap.sh check    # no download: verify pin + patch metadata
bash desktop/bootstrap.sh dev      # prepare upstream, run remote-only dev app
bash desktop/bootstrap.sh build    # prepare upstream, build Linux/macOS/Windows packages
```

`DECK_REMOTE_URL=https://your-domain bash desktop/bootstrap.sh dev` starts the
dev app pointed at your server. **Never put a token in an environment variable
or a committed file.** Sign in through Settings → Connections; Electron's
`safeStorage` keeps the token. `connection.json.example` only documents the
shape of the native config file, with a placeholder value.

## What is real today, and what is not

Real upstream features we inherit: chat with streaming tool output, approval
cards, right-side preview panes, file browser, voice, command palette, projects,
worktrees, per-profile connections, encrypted token storage, DMs between bot
profiles, and Linux AppImage/deb/rpm, macOS DMG/zip and Windows MSIX packaging.

Still to integrate (tracked in `docs/ROADMAP.md`):

1. A Deck login bridge: the app must exchange your panel login for a short-lived
   Hermes gateway token (the stock app expects a gateway token or OAuth).
2. A branded icon set and updater feed under our control.
3. The right-side chat popup and in-chat microphone from the web UI need to be
   carried into the renderer (upstream already docks preview panes on the right).
4. Build and smoke-test on Linux, macOS and Windows. Only the patch-application
   check runs in CI today; no packaged binary is claimed as verified yet.

## Why this shape

Hermes' own docs say one Hermes instance acts as the machine owner for every
login, and that separate OS users/containers are required for real multi-user
isolation. This deployment is single-user, so one remote gateway is correct.
If more people are added later, add one Hermes workspace per person in front of
this client; the desktop app already stores one connection per profile.
