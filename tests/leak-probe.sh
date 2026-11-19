#!/bin/bash
# Run on the Deck as root: sudo bash /opt/hermes-control-panel/tests/leak-probe.sh
#
# "Print the variable" leak probe. Stores a synthetic secret in the root vault,
# asks the panel to USE it against a test endpoint that echoes the injected
# value back in raw / base64 / URL-encoded / nested / error-string forms, and
# asserts that NONE of those forms survive the broker + PHP reply. This is the
# proof behind the promise: the AI can use a real credential but never sees it,
# even if a helper tries to print it.
set -uo pipefail
DIR=/opt/hermes-control-panel
BASE=http://127.0.0.1:9120
PORT=18998
JAR=$(mktemp)
PASS=0; FAIL=0
cleanup() {
  rm -f "$JAR" /tmp/leak-probe-resp /tmp/leak-probe-name
  [ -n "${PROBE_SERVER:-}" ] && kill "$PROBE_SERVER" 2>/dev/null || true
  [ -n "${SECRET_NAME:-}" ] && python3 /home/deck/vault/vault.py rm "$SECRET_NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT
ok(){ if [ "$1" = 0 ]; then echo "  PASS $2"; PASS=$((PASS+1)); else echo "  FAIL $2"; FAIL=$((FAIL+1)); fi; }
csrf(){ grep -E 'panel_csrf' "$JAR" 2>/dev/null | awk '{print $7}' | tail -1; }
http(){ curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $(csrf)" -X POST "$BASE/api.php?action=$1" --data-binary "$2"; }

echo "== vault no-leak probe (print-the-variable) =="
# Deliberately include characters that make the URL-encoded form differ from raw.
SECRET='LeakProbe/9f3a!x-Test-'"$(openssl rand -hex 6)"
SECRET_NAME="leak-probe-$$"
echo -n "$SECRET_NAME" > /tmp/leak-probe-name

python3 "$DIR/tests/leak_echo_server.py" "$PORT" >/tmp/leak-echo.log 2>&1 &
PROBE_SERVER=$!
sleep 1
curl -s --max-time 3 -o /dev/null "http://127.0.0.1:$PORT/echo"
ok $? "test endpoint reachable"
printf '%s' "$SECRET" | python3 /home/deck/vault/vault.py add "$SECRET_NAME" \
  --label "no-leak probe" --allow-http "127.0.0.1:$PORT" --approval policy >/dev/null 2>&1
ok $? "synthetic secret stored root-only"

# Log in so we can call the panel's owner-only vault.test action. The first GET
# sets the CSRF cookie; skipping it makes login fail and the probe look like a
# false pass (empty reply, no leak, no marker).
USER=$(grep -E '^PANEL_ADMIN_USER=' "$DIR/panel.env" | cut -d= -f2-)
PASSWD=$(grep -E '^PANEL_ADMIN_PASSWORD=' "$DIR/panel.env" | cut -d= -f2-)
curl -s -b "$JAR" -c "$JAR" -o /dev/null "$BASE/"
curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
  -X POST "$BASE/api.php?action=login" \
  --data-binary "{\"username\":\"$USER\",\"password\":\"$PASSWD\"}" >/dev/null

http vault.test "{\"name\":\"$SECRET_NAME\",\"kind\":\"http\",\"purpose\":\"no-leak probe\",\"params\":{\"url\":\"http://127.0.0.1:$PORT/echo\",\"method\":\"GET\",\"headers\":{\"X-Secret\":\"{{secret}}\"}}}" > /tmp/leak-probe-resp

python3 - "$SECRET" /tmp/leak-probe-resp <<'PY'
import base64, json, sys, urllib.parse
secret, path = sys.argv[1], sys.argv[2]
resp = open(path, encoding="utf-8", errors="replace").read()
variants = {
    "raw": secret,
    "base64": base64.b64encode(secret.encode()).decode(),
    "urlencoded": urllib.parse.quote(secret, safe=""),
}
def clean(s):
    for v in variants.values():
        if v and len(v) >= 4:
            s = s.replace(v, "<SECRET>")
    return s
try:
    parsed = json.loads(resp)
except Exception:
    print("  probe error: reply is not JSON: " + clean(resp)[:300])
    raise SystemExit(1)
if not parsed.get("ok"):
    print("  probe error: vault.test failed: " + clean(resp)[:300])
    raise SystemExit(1)
body = json.dumps(parsed.get("body") or parsed, ensure_ascii=False)
leaked = [name for name, v in variants.items() if v and len(v) >= 4 and v in body]
if leaked:
    print("  LEAK: secret survived as " + ", ".join(leaked))
    raise SystemExit(1)
print("  no raw/base64/url-encoded form of the secret in the reply")
if "***" not in body:
    print("  probe error: request succeeded but no redaction marker in body: " + clean(body)[:300])
    raise SystemExit(1)
print("  redaction marker present")
PY
ok $? "helper cannot print the variable (all encodings censored)"

python3 /home/deck/vault/vault.py rm "$SECRET_NAME" >/dev/null 2>&1
ok $? "temporary secret removed"
SECRET_NAME=""

echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
