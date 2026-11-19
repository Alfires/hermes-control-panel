# Desktop platforms: honest build matrix + login-bridge design

Evidence below was collected on **CachyOS Linux (Arch-like, x86_64) on
2026-11-19**. A `BLOCKED` row is a real, reproduced blocker — not a pending
TODO presented as a build. Platform order remains Linux → macOS → Windows
(`docs/ROADMAP.md`). For the short live status matrix run:

```bash
bash desktop/bootstrap.sh platforms
```

## Build status matrix

| Target | Status | Evidence / exact blocker | How to produce on a capable host |
|---|---|---|---|
| Linux AppImage | **BUILT** | `desktop/.upstream/apps/desktop/release/HermesControlDeckLight-0.0.0-linux-x86_64.AppImage` (138 MB), launched headless in the smoke test (`docs/DESKTOP-SMOKE-LINUX.md`). | `bash desktop/bootstrap.sh dist-linux` |
| Linux deb | **BUILT** | `desktop/.upstream/apps/desktop/release/HermesControlDeckLight-0.0.0-linux-amd64.deb` (119 MB). | same command |
| Linux rpm | **BLOCKED (host)** | No `rpmbuild` on PATH; `sudo -n pacman -S --noconfirm rpm-tools` → `sudo: a password is required`. The package exists (`rpm-tools 6.0.1-2.2`, `cachyos-extra-v3`), so a human with the sudo password can install it, but there is no non-interactive root path here. | same command; no script change needed once `rpmbuild` is on PATH |
| macOS DMG / zip | **BLOCKED (platform)** | macOS targets cannot be cross-built from Linux: upstream's electron-builder config needs macOS-only tooling (`actool` for the `.icon`, `dmgbuild`/disk images for DMG, `codesign`/notarization via `afterSign` on `process.platform === 'darwin'`). | on a Mac: `pnpm run dist:mac:dmg` and `pnpm run dist:mac:zip` |
| Windows MSIX | **BLOCKED (upstream guard)** | Wine 11.21 is installed, but upstream refuses the build before packaging: `Error: Build Windows packages on Windows so the HUD helper is included.` at `apps/desktop/scripts/build-hud-modifier-monitor.mjs:36`. Wine does not bypass that guard. | on Windows: `pnpm run dist:win:msix` |

No `dist:mac` or `dist:win` bootstrap mode was added: neither can work on this
host, and a mode that produces nothing would be a lie. The AppImage/deb path was
not changed.

## Exact blocker evidence

### rpm (missing toolchain, not a code failure)

```
$ command -v rpmbuild                          # no output, exit 1
$ sudo -n pacman -S --noconfirm rpm-tools
sudo: a password is required
$ pacman -Si rpm-tools
Repository      : cachyos-extra-v3
Name            : rpm-tools
Version         : 6.0.1-2.2
$ pacman -Sp rpm-tools
https://mirror5.krfoss.org/cachyos/repo/x86_64_v3/cachyos-extra-v3/rpm-tools-6.0.1-2.2-x86_64_v3.pkg.tar.zst
```

`fakeroot` is present, but that is not a substitute for `rpmbuild`. Unpacking
the package into a private prefix would be a host-specific, unreproducible
hack, so it is deliberately not done. `dist:linux` asks upstream for
`AppImage deb rpm`; upstream emits AppImage and deb **before** the rpm step
fails, so the working artifacts are unaffected. An rpm-capable host needs no
bootstrap change.

### Windows MSIX (upstream requires a Windows host)

Attempted with the pinned upstream checkout already prepared and the local
pnpm prefix on PATH:

```
$ PATH=/home/timo/.local/npm-global/node_modules/.bin:$PATH \
    HERMES_DESKTOP_VARIANT=light pnpm run dist:win:msix
$ node scripts/run-electron-builder.mjs --win msix
[stage-native-deps] staged node-pty (win32-x64) ...
Error: Build Windows packages on Windows so the HUD helper is included.
    at buildHudModifierMonitor (.../apps/desktop/scripts/build-hud-modifier-monitor.mjs:36:37)
    at withProduct.source.source (.../scripts/stage-native-deps.mjs:828:5)
Exit code: 1
```

The failure is in upstream native-dependency staging, before electron-builder
or MSIX signing. Wine (`wine-11.21`) exists but is irrelevant. Patching the
guard would drop the HUD helper and break the "genuine upstream fork" contract,
so it is not done. Build MSIX on a Windows host and record signing/certificate
status there.

### macOS DMG / zip (no cross-compilation)

Upstream's `apps/desktop/electron-builder.config.cjs` wires macOS-only build
steps: the icon is compiled with `actool` (`scripts/mac-icon.cjs`), `afterSign:
'scripts/notarize.mjs'` is added only when `process.platform === 'darwin'`,
and DMG packaging uses macOS disk-image tooling and our `.icns`/`.tiff`
artwork. Signing/notarization additionally needs Apple credentials. Neither
DMG nor zip can be honestly produced on Linux; a renamed AppImage is not a
macOS build.

## Desktop login bridge (design only — nothing here is implemented yet)

### Current path, read from source

- `src/public/api.php` action `hermes.deep_link` calls `Auth::require()` and
  then `Hermes::deepLink(page)` in `src/lib/hermes.php`, which only returns
  `HERMES_DASHBOARD_URL/<page>` for a page in `PageRegistry::ids()`. It is a
  **link**, with no token.
- `Auth::login()` stores only `sha256(token)` in `logins` and sets an HttpOnly,
  SameSite=Lax `panel_session` cookie (12 h). State-changing actions are
  guarded by a same-origin `Origin` check plus a double-submit CSRF check
  (`X-CSRF-Token` vs the `panel_csrf` cookie).
- `Hermes::fetch()` is a narrow, scrubbed **control-plane** proxy: curated
  sources, field whitelists, caching, server-side `HERMES_API_KEY`. It has no
  chat/stream routes.
- The desktop today asks the owner to type a Hermes token into Settings →
  Connections; Electron keeps it in `safeStorage` (see `desktop/CONNECTION.md`
  and `desktop/connection.json.example`). There is **no** panel-issued token
  action yet. `src/public/api.php` was **not** changed by this work.

### Proposed one-time exchange

1. The desktop opens the panel origin over HTTPS in a locked-down
   BrowserWindow (context isolation on, node integration off, navigation
   allowlisted to the panel origin). The owner signs in through the existing
   `login` action with the existing CSRF guard.
2. A new owner-only panel action `hermes.gateway_token.issue` verifies the
   session/CSRF/origin, applies a per-owner rate limit, and asks the Hermes
   gateway (server-to-server, internal `HERMES_API_KEY`) to mint a **short-lived
   Hermes gateway token**. The panel keeps no token: it stores only a SHA-256
   hash of a one-time authorization code in a `gateway_token_grants` table
   (`hash`, `user_id`, `purpose='desktop-gateway'`, `created_at`, `expires_at`
   ≤ 60 s, `used_at`) and returns the code **once** with
   `Cache-Control: no-store`.
3. The owner pastes that code into the desktop's Settings → Connections screen
   (or Electron reads it from a message posted by the same allowlisted panel
   window). The desktop sends it once in a POST body (never a URL, query or
   fragment) to a new `hermes.gateway_token.exchange` action. The panel
   consumes it atomically — `... WHERE hash=? AND used_at IS NULL AND
   expires_at > ?` with an affected-row check — and returns the gateway token
   in the response body only; errors are generic and value-free.
4. Electron main receives the token over one preload/IPC hop, immediately
   calls `safeStorage.encryptString()` and writes it under `userData` with
   restrictive permissions. Plaintext stays in memory; the renderer receives
   only a masked status and the expiry time. Token TTL ≤ 15 min; expiry means
   the owner signs in again — no long-lived refresh token in JavaScript.
5. The desktop connects **directly** to the validated HTTPS/WSS gateway origin
   with that token. Refuse downgrades and unexpected hosts.

### Security invariants (non-negotiable)

- **owner-only**: the issuing and exchanging actions require `Auth::require()`,
  a user id equal to the configured owner, the same-origin check, CSRF, and a
  rate limit; no other role can mint a grant.
- **one-use**: the authorization code is stored as a hash, consumed exactly
  once in an atomic update/delete, bound to `user_id` and the
  `desktop-gateway` purpose, and rejected on reuse with a generic error.
  Audit rows record event/time/code id, never a value.
- **short TTL**: the code expires in ≤ 60 s and the gateway token in ≤ 15 min;
  expired grants are purged. There is no silent, indefinite renewal.
- **no token in git/logs/URLs**: token and code appear only in a POST body and
  process memory, then encrypted in the OS keychain via Electron
  `safeStorage`. Nothing in requests, response headers, console output, app
  logs, CI logs, `connection.json.example`, environment variables or git may
  carry a real token. `Cache-Control: no-store` on both responses.
- **no conversation relay**: the PHP panel issues and consumes the code and
  nothing else. Chat, streaming, tool calls and approval cards go straight
  from Electron to the Hermes gateway; no chat proxy action is added to
  `api.php`. `Hermes::fetch()` stays a control-plane-only whitelist.
- Least privilege: if the gateway supports scopes, mint chat/stream-only
  tokens (no admin or vault scope); confirm this against the pinned gateway
  API before implementation. Revoke on panel logout / connection removal.

### What remains to implement

1. **Panel**: add the two actions to `src/public/api.php` and to
   `PanelActions::catalog()` in `src/lib/registry.php`; add the grants table +
   migration, owner check, rate limit, `no-store` responses and value-free
   audit events. (Not done here — `api.php` is owned by another worker.)
2. **Gateway**: confirm/mint short-lived scoped tokens; if the pinned gateway
   cannot, add a minting endpoint there. Keep manual token entry as the
   documented fallback.
3. **Electron**: the allowlisted login window, the single main-process IPC
   channel, `safeStorage` write/read/delete, origin validation, masked UI and
   expiry/re-auth prompt.
4. **Tests**: owner-only/CSRF, one-use, expiry, rate-limit, leak probe (logs,
   URLs, git) and a network-path check that chat traffic never reaches
   `api.php`; then real-token smoke tests on Linux, and on-target Mac/Windows
   builds once their blockers are cleared.

## Verification

```bash
bash desktop/bootstrap.sh check       # pin + patch metadata
bash desktop/bootstrap.sh platforms   # honest status matrix
php tests/unit/desktop-fork.php       # fork, platform and secret regressions
php tests/suite.php                   # whole suite (other workers' failures possible)
```
