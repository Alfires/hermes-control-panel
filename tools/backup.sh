#!/usr/bin/env bash
# Restorable backup for the Hermes Control Panel data directory.
#
# Usage:
#   sudo tools/backup.sh --output /var/backups/panel-$(date +%F).tar.gz
#   tools/backup.sh --verify /var/backups/panel-2026-11-19.tar.gz
#
# The archive contains ONLY curated, non-secret state from /var/lib/panel:
#   panel.db   - SQLite DB: config revisions, agents, audit and the project list
#   setup.lock - first-run wizard state (if present)
# No .env files, key material, credential values, vault contents or hostctl
# secrets are ever staged. A post-build name check refuses to keep an archive
# that contains a secret-shaped or .env entry.
#
# Restore (stop the panel first):
#   sudo tar -xzf /var/backups/panel-YYYY-MM-DD.tar.gz -C /var/lib/panel
#
# PANEL_DATA overrides the source directory. Exit codes: 0 ok, 2 usage,
# 3 bad input/archive, 4 forbidden entry found.
set -euo pipefail
umask 077

SOURCE_DIR="${PANEL_DATA:-/var/lib/panel}"
OUTPUT=""
VERIFY=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --output)  OUTPUT="${2:-}"; shift 2 ;;
    --output=*) OUTPUT="${1#*=}"; shift ;;
    --verify)  VERIFY="${2:-}"; shift 2 ;;
    --verify=*) VERIFY="${1#*=}"; shift ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "backup.sh: unknown option: $1" >&2; exit 2 ;;
  esac
done

# Archive entry names that must never appear (.env, key material, secret dirs).
FORBIDDEN_RE='(^|/)\.env($|\.)|(^|/)secrets?(/|$)|(^|/)credentials?(/|$)|(^|/)[^/]*\.(key|pem|p12|pfx|jks)$|(^|/)hostctl\.key$'
check_entries() {
  local archive="$1"
  if tar -tzf "$archive" 2>/dev/null | grep -Eqi "$FORBIDDEN_RE"; then
    echo "backup.sh: REFUSED: archive contains a .env or secret-shaped entry" >&2
    return 4
  fi
  return 0
}

if [ -n "$VERIFY" ]; then
  [ -r "$VERIFY" ] || { echo "backup.sh: cannot read archive: $VERIFY" >&2; exit 3; }
  echo "Contents of $VERIFY:"
  tar -tzf "$VERIFY"
  check_entries "$VERIFY"
  if ! tar -tzf "$VERIFY" 2>/dev/null | grep -qx './panel.db'; then
    echo "backup.sh: archive is missing panel.db" >&2; exit 3
  fi
  echo "OK: restorable panel archive, no .env or secret-shaped entries"
  exit 0
fi

if [ -z "$OUTPUT" ]; then
  echo "backup.sh: --output PATH (or --verify ARCHIVE) is required" >&2
  exit 2
fi
case "$OUTPUT" in
  */|'') echo "backup.sh: invalid output path" >&2; exit 2 ;;
esac
OUT_DIR="$(dirname -- "$OUTPUT")"
[ -d "$OUT_DIR" ] || { echo "backup.sh: output directory does not exist: $OUT_DIR" >&2; exit 3; }
[ -d "$SOURCE_DIR" ] || { echo "backup.sh: source directory does not exist: $SOURCE_DIR" >&2; exit 3; }
DB="$SOURCE_DIR/panel.db"
[ -r "$DB" ] || { echo "backup.sh: cannot read $DB" >&2; exit 3; }

STAGE="$(mktemp -d /tmp/panel-backup.XXXXXX)"
trap 'rm -rf -- "$STAGE"' EXIT

# Prefer a consistent SQLite snapshot; fall back to a plain copy of the DB
# files (the panel uses WAL mode, so copy the sidecars too).
if command -v sqlite3 >/dev/null 2>&1; then
  sqlite3 "$DB" ".backup '$STAGE/panel.db'"
else
  cp -p -- "$DB" "$STAGE/panel.db"
  for suffix in -wal -shm; do
    [ -f "$DB$suffix" ] && cp -p -- "$DB$suffix" "$STAGE/panel${suffix}"
  done
fi
[ -f "$SOURCE_DIR/setup.lock" ] && cp -p -- "$SOURCE_DIR/setup.lock" "$STAGE/setup.lock"

tar -czf "$OUTPUT" -C "$STAGE" .
if ! check_entries "$OUTPUT"; then
  rm -f -- "$OUTPUT"
  exit 4
fi
echo "OK: wrote $OUTPUT ($(tar -tzf "$OUTPUT" | wc -l) entries, no .env/secrets)"
