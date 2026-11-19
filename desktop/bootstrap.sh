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
Usage: desktop/bootstrap.sh [check|build|dev]
  check  Verify the pinned upstream commit and that our patch applies (no download).
  build  Clone/pin upstream, apply the Deck patch, install deps, build app packages.
  dev    Same preparation, then run the remote-only Vite + Electron dev server.
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
  echo "Upstream commit: $(git -C "$BUILD" rev-parse HEAD)"

  # Refuse to build against an unexpected upstream (supply-chain safety).
  [[ "$(git -C "$BUILD" rev-parse HEAD)" == "$UPSTREAM_REF" ]] || {
    echo "Refusing to build: upstream is not the pinned commit" >&2; exit 1; }

  git -C "$BUILD" apply --check "$HERE/patches/deck-identity.patch"
  # Re-running bootstrap must be idempotent: reverse first if already applied.
  if git -C "$BUILD" apply --reverse --check "$HERE/patches/deck-identity.patch" 2>/dev/null; then
    echo "Deck identity patch already applied"
  else
    git -C "$BUILD" apply "$HERE/patches/deck-identity.patch"
  fi

  if ! command -v pnpm >/dev/null 2>&1; then
    echo "pnpm $PNPM_VERSION is required. Install it with: npm i -g pnpm@$PNPM_VERSION"
    exit 1
  fi
  (cd "$BUILD" && pnpm install --frozen-lockfile)
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
