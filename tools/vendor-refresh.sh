#!/usr/bin/env bash
# Reproduce the committed PHP vendor directory from composer.lock.
# Only run when intentionally upgrading pins; the Deck never runs Composer.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if ! command -v composer >/dev/null 2>&1; then
  echo "composer is required to refresh vendor/ (install it on your build machine)" >&2
  exit 1
fi
composer install --no-interaction --no-dev --optimize-autoloader
echo "vendor/ refreshed from composer.lock"
