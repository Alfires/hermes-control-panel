# Linux desktop build + smoke test (evidence, 2026-11-19)

This is the first **real** build of the desktop client, done on the build machine
with the pinned toolchain. It records what was actually observed, not what is
planned. Platform order remains Linux → macOS → Windows; only Linux is covered
here.

## Build

| Item | Observed |
| --- | --- |
| Upstream commit | `7c7daa25c66262d504e3c2b29ade0ed5928b056e` (enforced by `bootstrap.sh`) |
| Toolchain | Node `v26.13.0`, pnpm `12.15.0`, Electron `44.10.2` (lock-pinned) |
| Command | `bash desktop/bootstrap.sh build` → **exit 0** |
| Renderer | Vite build completed (electron main, preload, preview preload) |
| Package | `apps/desktop/release/linux-unpacked/` with `resources/app.asar` (8.8 MB) |
| Distributables | `HermesControlDeckLight-0.0.0-linux-x86_64.AppImage` (138 MB) and `…-amd64.deb` (119 MB) built and type-validated; `pnpm run dist:linux` exits 1 only on rpm |
| rpm gap | `dist:linux` also requests rpm, which needs host `rpmbuild` (not installed here). AppImage and deb are unaffected and are the supported Linux artifacts for now. |
| App binary | `Hermes Control Deck Light` — the identity patch is in the shipped binary |
| Package identity | `com.alfires.hermes-control-deck-light`; no Nous update feed in `app.asar` |
| Build stamp | `commit 7c7daa25c662…`, `dirty: true` — expected: our identity patch is applied on top of the pin |
| Post-pack check | upstream's `after-pack` verified the backend readiness parser inside `app.asar` |

Two bootstrap defects were found and fixed during this build, both with
regressions in `tests/unit/desktop-fork.php`:

1. Upstream refuses to package unless the lock-pinned Electron binary has been
   acquired by an explicit step (lazy installs are disabled by design).
   `bootstrap.sh` now runs `scripts/build/electron-runtime.mjs --source …`.
2. A re-run left the identity patch in the worktree, and `checkout --detach` to
   the *same* commit does not discard local changes, so the unconditional
   `git apply --check` failed. `bootstrap.sh` now does `git reset --hard` back
   to the pin before applying the patch, which makes re-runs idempotent.

## Smoke test

Headless Xvfb at 1280×860, launched first from `release/linux-unpacked` and then
from the built AppImage (both runs reached the same window):

```
… 'Hermes Control Deck Light' --no-sandbox --ozone-platform=x11 \
  --remote-debugging-port=9222 --remote-allow-origins='*'
… HermesControlDeckLight-0.0.0-linux-x86_64.AppImage [same flags]
```

`--ozone-platform=x11` is a **test-harness flag**: Electron defaults to Wayland
and aborts under Xvfb (`Failed to connect to Wayland display`). It is not an app
defect. Chrome DevTools Protocol was used to read the real DOM instead of
guessing pixels.

| Step | Observed result |
| --- | --- |
| AppImage launch | Window opens; CDP `document.title === "Hermes Control Deck"`; no fatal log errors |
| First launch | `CONNECTING` boot overlay, then first-run setup dialog |
| Runtime identity | `document.title === "Hermes Control Deck"` |
| Skip setup | Main chat surface rendered: session list, New session, composer, model selector, Backend ready |
| Composer voice | Buttons present: `Voice dictation`, `Start voice conversation`, `Voice chat engine` |
| Right rail | `Show right sidebar` toggles to `Hide right sidebar`; layout editor, HUD mode and swap-sides also present |
| Stability | No fatal errors; only SwiftShader WebGL fallback warnings, expected in a headless Xvfb/ no-GPU run |

### Renderer decision (evidence-based)

We did **not** patch the renderer. The upstream pin already ships the three
interaction pieces the requirement cares about: right-side sidebar/panes,
voice controls inside the composer, and window/layout controls (HUD mode,
sidebar swap). The web UI keeps its right-docked chat popup + explicit
Fullscreen button; the desktop app uses the native full-window chat with a
toggleable right sidebar. That is a deliberate platform difference, not a
missing feature. Adding TypeScript to upstream's renderer would break the
"no TypeScript added by us" decision for no measured gain.

## Not exercised (honest gaps)

- **Real gateway connection.** No token was configured, so a chat message,
  streaming, approval cards and the project list were not driven end to end.
  `docs/CONNECTION.md`-style setup (Settings → Connections, Electron
  `safeStorage`) still needs a live-run proof on the target.
- **Other platforms.** AppImage and deb are built and type-validated; rpm is
  blocked on missing host `rpmbuild`. macOS DMG/zip and Windows MSIX are separate
  build targets, not yet attempted.
- **A branded icon set and our own updater feed.** Still pending; the identity
  patch only disables the upstream feed.

## Reproduce

```bash
bash desktop/bootstrap.sh check
bash desktop/bootstrap.sh build       # needs Node >= 26, pnpm 12.15.0
php tests/unit/desktop-fork.php        # bootstrap regressions
```
