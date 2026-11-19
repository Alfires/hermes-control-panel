#!/usr/bin/env bash
# Hermes Control Deck desktop app — controlled fork of the real Hermes Desktop.
#
# We do NOT copy 59 MB of upstream code into this repo. We pin one upstream
# commit, apply our small identity patch, and build from that checkout. This
# keeps the genuine Hermes chat surface, native panes and remote-gateway auth
# while giving the app our own name, icon identity and update channel.
#
# Nothing here starts a Hermes gateway on this machine. The built app is the
# remote-only "light" variant: it connects to YOUR server after login.
set -euo pipefail

UPSTREAM_REPO="${DECK_HERMES_UPSTREAM:-https://github.com/NousResearch/hermes-agent.git}"
UPSTREAM_REF="${DECK_HERMES_REF:-7c7daa25c66262d504e3c2b29ade0ed5928b056e}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
BUILD="${DECK_DESKTOP_BUILD:-$HERE/.upstream}"
PNPM_VERSION=12.15.0

usage() {
  cat <<'EOF'
Usage: desktop/bootstrap.sh [check|build|dist-linux|platforms|dev]
  check      Verify the pinned upstream commit and patch metadata (no download).
  build      Clone/pin upstream, apply the Deck patch, install deps, build unpacked app.
  dist-linux Build Linux AppImage + deb (+ rpm if the host has rpmbuild).
  platforms  Print an honest build-status matrix (no downloads, no builds).
  dev        Same preparation, then run the remote-only Vite + Electron dev server.
Environment:
  DECK_HERMES_REF       override the pinned upstream commit
  DECK_HERMES_UPSTREAM  override the upstream repository URL
  DECK_DESKTOP_BUILD    override the build directory
  DECK_REMOTE_URL       optional default gateway URL for dev (never a token)
EOF
}

prepare() {
  if [[ ! -d "$BUILD/.git" ]]; then
    echo "Cloning pinned Hermes Desktop upstream into $BUILD"
    git clone --filter=blob:none "$UPSTREAM_REPO" "$BUILD"
  fi
  git -C "$BUILD" fetch --depth 1 origin "$UPSTREAM_REF" 2>/dev/null || true
  git -C "$BUILD" checkout --detach "$UPSTREAM_REF"
  # Re-running must be idempotent: checkout of the same commit keeps the patch
  # a prior run left in the worktree, so force it back to a pristine pin first.
  git -C "$BUILD" reset --hard "$UPSTREAM_REF"
  echo "Upstream commit: $(git -C "$BUILD" rev-parse HEAD)"

  # Refuse to build against an unexpected upstream (supply-chain safety).
  [[ "$(git -C "$BUILD" rev-parse HEAD)" == "$UPSTREAM_REF" ]] || {
    echo "Refusing to build: upstream is not the pinned commit" >&2; exit 1; }

  git -C "$BUILD" apply --check "$HERE/patches/deck-identity.patch"
  git -C "$BUILD" apply "$HERE/patches/deck-identity.patch"

  if ! command -v pnpm >/dev/null 2>&1; then
    echo "pnpm $PNPM_VERSION is required. Install it with: npm i -g pnpm@$PNPM_VERSION"
    exit 1
  fi
  (cd "$BUILD" && pnpm install --frozen-lockfile)
  # Upstream refuses to package unless the lock-pinned Electron binary has been
  # acquired by an explicit step (lazy installs are disabled for supply-chain
  # reasons). This runs Electron's own installer once; it is idempotent.
  (cd "$BUILD" && node scripts/build/electron-runtime.mjs --source "$BUILD")
}

# Report what is actually buildable here. This never downloads, patches or
# builds anything, and it does not pretend Windows/macOS targets exist on a
# Linux host. Evidence for each BLOCKED row lives in docs/DESKTOP-PLATFORMS.md.
platforms() {
  local release="$BUILD/apps/desktop/release"
  local appimage deb
  appimage="$release/HermesControlDeckLight-0.0.0-linux-x86_64.AppImage"
  deb="$(find "$release" -maxdepth 1 -name '*.deb' -print -quit 2>/dev/null || true)"

  echo "Hermes Control Deck desktop — platform status (host: $(uname -s)/$(uname -m))"
  echo "No build is started; see docs/DESKTOP-PLATFORMS.md for the evidence."
  echo
  if [[ -f "$appimage" ]]; then
    echo "Linux AppImage : BUILT   $appimage"
  else
    echo "Linux AppImage : NOT BUILT (run: bash $0 dist-linux)"
  fi
  if [[ -n "$deb" ]]; then
    echo "Linux deb      : BUILT   $deb"
  else
    echo "Linux deb      : NOT BUILT (run: bash $0 dist-linux)"
  fi
  if command -v rpmbuild >/dev/null 2>&1; then
    echo "Linux rpm      : READY   rpmbuild found; dist-linux also builds rpm"
  else
    echo "Linux rpm      : BLOCKED — no rpmbuild on PATH; pacman -S rpm-tools needs interactive root (no passwordless sudo). AppImage/deb builds are unaffected."
  fi
  if command -v wine >/dev/null 2>&1; then
    echo "Windows wine   : present at $(command -v wine), but upstream needs a real Windows host."
  else
    echo "Windows wine   : absent."
  fi
  echo "Windows MSIX   : BLOCKED — upstream build-hud-modifier-monitor.mjs:36 refuses non-win32; wine does not bypass it. No dist:win mode here."
  echo "macOS dmg/zip  : BLOCKED — cannot cross-build from Linux (macOS/Xcode actool, disk-image tooling, signing/notarization). No dist:mac mode here."
}

case "${1:-check}" in
  check)
    grep -q "$UPSTREAM_REF" "$HERE/README.md" || {
      echo "README does not document the pinned ref" >&2; exit 1; }
    git -C "$REPO" apply --check "$HERE/patches/deck-identity.patch" 2>/dev/null && {
      echo "Unexpected: patch applies to the Deck repo itself" >&2; exit 1; } || true
    echo "check OK — pinned $UPSTREAM_REF"
    ;;
  build)
    prepare
    (cd "$BUILD/apps/desktop" && HERMES_DESKTOP_VARIANT=light pnpm run pack)
    echo "Unpacked app packages are under $BUILD/apps/desktop/release"
    ;;
  dist-linux)
    prepare
    # Upstream's dist:linux asks for AppImage, deb and rpm. rpm needs a host
    # rpmbuild binary; on hosts without it the command still produces the
    # AppImage and deb before reporting the rpm failure.
    (cd "$BUILD/apps/desktop" && HERMES_DESKTOP_VARIANT=light pnpm run dist:linux)
    echo "Linux distributables are under $BUILD/apps/desktop/release"
    ;;
  platforms)
    platforms
    ;;
  dev)
    prepare
    export HERMES_DESKTOP_VARIANT=light
    [[ -n "${DECK_REMOTE_URL:-}" ]] && export HERMES_DESKTOP_REMOTE_URL="$DECK_REMOTE_URL"
    (cd "$BUILD/apps/desktop" && pnpm run dev)
    ;;
  *)
    usage; exit 2
    ;;
esac
