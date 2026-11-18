#!/bin/bash
# End-to-end checks on the Deck. Run as root from the deployed repo:
#   sudo bash /opt/hermes-control-panel/tests/integration.sh
set -uo pipefail
DIR=/opt/hermes-control-panel
BASE=http://127.0.0.1:9120
JAR=$(mktemp)
PASS=0; FAIL=0
cleanup() { rm -f "$JAR" /tmp/panel-leak-secret; }
trap cleanup EXIT

ok(){ if [ "$1" = 0 ]; then echo "  PASS $2"; PASS=$((PASS+1)); else echo "  FAIL $2"; FAIL=$((FAIL+1)); fi; }
code(){ curl -s -o /tmp/panel-resp -w '%{http_code}' "$@"; }
csrf(){ grep -E 'panel_csrf' "$JAR" 2>/dev/null | awk '{print $7}' | tail -1; }
http(){ curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $(csrf)" \
  -X POST "$BASE/api.php?action=$1" --data-binary "$2"; }

echo "== container and brokers =="
[ "$(docker inspect -f '{{.State.Status}}' hermes-panel 2>/dev/null)" = running ]; ok $? "panel container running"
[ "$(systemctl is-active panel-hostctl)" = active ]; ok $? "host broker active"
[ "$(systemctl is-active vault-broker)" = active ]; ok $? "vault broker active"

echo "== web / auth =="
C=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' "$BASE/"); [ "$C" = 200 ]; ok $? "index page 200"
USER=$(grep -E '^PANEL_ADMIN_USER=' "$DIR/panel.env" | cut -d= -f2-)
PASSWD=$(grep -E '^PANEL_ADMIN_PASSWORD=' "$DIR/panel.env" | cut -d= -f2-)
C=$(curl -s -o /tmp/panel-resp -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' \
   -X POST "$BASE/api.php?action=login" \
   --data-binary "{\"username\":\"$USER\",\"password\":\"$PASSWD\"}" -w '%{http_code}')
[ "$C" = 200 ]; ok $? "owner login"
python3 -c 'import json;assert json.load(open("/tmp/panel-resp"))["ok"]' 2>/dev/null; ok $? "login response ok"

http bootstrap '{}' > /tmp/panel-resp
python3 -c 'import json;d=json.load(open("/tmp/panel-resp"));assert d["ok"] and len(d["agents"])>=4 and d["host"]["available"] and d["vault"]["available"]' 2>/dev/null
ok $? "bootstrap has agents + both brokers"

echo "== read-only API =="
http security.scan '{}' > /tmp/panel-resp
python3 -c 'import json;d=json.load(open("/tmp/panel-resp"));assert d["ok"] and "findings" in d' 2>/dev/null
ok $? "security scan via panel"
http audit.list '{}' > /tmp/panel-resp
python3 -c 'import json;assert json.load(open("/tmp/panel-resp"))["ok"]' 2>/dev/null
ok $? "audit trail readable"

echo "== inside-container permission and HMAC tests =="
docker cp "$DIR/tests/tools.php" hermes-panel:/tmp/tools.php >/dev/null
docker exec hermes-panel php /tmp/tools.php
ok $? "engine/HMAC/broker tests"
docker exec hermes-panel rm -f /tmp/tools.php

echo "== real secret leakage probe =="
SECRET="PANEL-LEAK-TEST-$(openssl rand -hex 8)"
python3 "$DIR/tests/echo_server.py" 18999 >/tmp/panel-echo.log 2>&1 &
ECHOPID=$!
sleep 1
printf '%s' "$SECRET" | python3 /home/deck/vault/vault.py add panel-leak-test \
  --label "integration test" --allow-http 127.0.0.1:18999 --approval policy >/dev/null 2>&1
ADDED=$?; ok $ADDED "temporary secret stored root-only"
if [ $ADDED -eq 0 ]; then
  http vault.test "{\"name\":\"panel-leak-test\",\"kind\":\"http\",\"purpose\":\"leak test\",\"params\":{\"url\":\"http://127.0.0.1:18999/echo\",\"method\":\"GET\",\"headers\":{\"X-Secret\":\"{{secret}}\"}}}" > /tmp/panel-resp
  if grep -q "$SECRET" /tmp/panel-resp; then
    echo "  LEAK: raw secret found in response"; ok 1 "broker redacts injected secret"
  else
    ok 0 "broker redacts injected secret"
  fi
  grep -q 'CENSORED\|\*\*\*' /tmp/panel-resp; ok $? "response carries explicit redaction marker"
  python3 /home/deck/vault/vault.py rm panel-leak-test >/dev/null 2>&1
  ok $? "temporary secret removed"
fi
kill $ECHOPID 2>/dev/null || true

echo "== restart persistence =="
docker restart hermes-panel >/dev/null
for i in $(seq 1 30); do
  C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api.php?action=me" || true)
  [ "$C" = 200 ] && break
  sleep 1
done
[ "$C" = 200 ]; ok $? "panel answers after restart"
curl -s -b "$JAR" -H 'Content-Type: application/json' -H "X-CSRF-Token: $(csrf)" -X POST "$BASE/api.php?action=bootstrap" --data-binary '{}' > /tmp/panel-resp
python3 -c 'import json;assert json.load(open("/tmp/panel-resp"))["ok"]' 2>/dev/null
ok $? "session survives restart"

echo ""
echo "$PASS passed, $FAIL failed"
exit $FAIL
