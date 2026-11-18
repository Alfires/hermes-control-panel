#!/usr/bin/env bash
# Regenerate the committed Tailwind CSS output. Run on a dev machine only;
# the Deck never runs npm/node. Output is committed as src/public/tailwind.css.
set -euo pipefail
TAILWIND_VERSION="${TAILWIND_VERSION:-4.1.11}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT/.tools/tailwindcss-$TAILWIND_VERSION"
if [ ! -x "$BIN" ]; then
  mkdir -p "$ROOT/.tools"
  curl -fsSL "https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/tailwindcss-linux-x64" -o "$BIN"
  chmod +x "$BIN"
fi
"$BIN" -i "$ROOT/src/public/tailwind.css" -o "$ROOT/src/public/tailwind.css" \
  --content "$ROOT/src/templates/**/*.php" "$ROOT/src/public/*.js" 2>/dev/null || true
printf 'Tailwind output refreshed (commit src/public/tailwind.css)\n'
