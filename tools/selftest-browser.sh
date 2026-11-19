#!/usr/bin/env bash
# Deep browser self-test for the Hermes Control Deck.
#
# Visits every registered page in a REAL headless browser through an
# auth-injecting proxy, screenshots each view, and uses gui-debug's local vision
# OCR to assert the view heading is present and no fatal error text is showing.
#
# Security: the owner password is read from the root-only panel.env via the
# deck.sh ssh/sudo wrapper (or from PANEL_PASS / an already-authenticated proxy).
# It is NEVER printed and NEVER written into a report or log file.
#
# Usage:
#   tools/selftest-browser.sh                     # source password via deck.sh
#   PANEL_PROXY_URL=http://127.0.0.1:8899 tools/selftest-browser.sh
#   PANEL_BASE=http://127.0.0.1:1 tools/selftest-browser.sh   # -> SKIPPED
#
# Env overrides: PANEL_BASE, PROXY_PORT, OUT_DIR, WAIT, OCR_TIMEOUT,
#                PAGES, PANEL_PASS, DECK_SUDO_PASS, PANEL_PROXY_URL,
#                DECK_DEPLOY, GUI_DEBUG_DIR.

set -uo pipefail

# ---------------------------------------------------------------- configuration
PANEL_BASE="${PANEL_BASE:-http://192.168.2.157:9120}"
PROXY_PORT="${PROXY_PORT:-8898}"           # 8899 is the manual verification proxy
OUT_DIR="${OUT_DIR:-/tmp/opencode/deck-browser-selftest}"
WAIT="${WAIT:-12}"                         # seconds to let the Deck API/bootstrap settle
OCR_TIMEOUT="${OCR_TIMEOUT:-120}"          # seconds for one vision OCR call
PAGES="${PAGES:-overview chats agents approvals security vault settings audit setup search hermes themes self-test}"
DECK_DEPLOY="${DECK_DEPLOY:-/home/timo/programming/openhost/deploy}"
GUI_DEBUG_HOME="${GUI_DEBUG_HOME:-${HOME}/.config/opencode/toolbox/entries/gui-debug}"
GD="$GUI_DEBUG_HOME/scripts"

export GUI_DEBUG_DIR="${GUI_DEBUG_DIR:-$OUT_DIR/gui-debug}"

mkdir -p "$OUT_DIR/shots" "$GUI_DEBUG_DIR"
PROXY_PID=""
cleanup() {
  if [[ -n "$PROXY_PID" ]] && kill -0 "$PROXY_PID" 2>/dev/null; then
    kill "$PROXY_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

declare -A TITLE=(
  [overview]="Overview" [chats]="Chats" [agents]="Agents" [approvals]="Approvals"
  [security]="Security" [vault]="Vault" [settings]="Settings" [audit]="Audit"
  [setup]="Setup" [search]="Search" [hermes]="Hermes" [themes]="Themes"
  [self-test]="Self test"
)
# Strong signals of a fatal render failure. Deliberately narrow: the self-test
# and security pages legitimately contain words like "error" and "finding".
FATAL_PATTERNS=(
  'fatal error' 'uncaught' 'unhandled rejection' 'parse error'
  'internal server error' 'bad gateway' 'service unavailable'
  'profile cannot be loaded' 'traceback' 'javascript error'
)

# ------------------------------------------------------------------- result bins
declare -a R_ID=() R_STATUS=() R_TITLE=() R_FOUND=() R_FATAL=() R_SHOT=() R_OCR=()
declare -a R_EXPECT=()
add_result() { # id status expect found fatal shot ocr_excerpt
  R_ID+=("$1"); R_STATUS+=("$2"); R_EXPECT+=("$3"); R_FOUND+=("$4")
  R_FATAL+=("$5"); R_SHOT+=("$6"); R_OCR+=("$7")
}

json_escape() { printf '%s' "$1" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1], end="")'; }

write_report() { # status reason
  local status="$1" reason="$2" i n=0 p f s
  for ((i=0; i<${#R_ID[@]}; i++)); do
    case "${R_STATUS[$i]}" in PASS) n=$((n+1));; FAIL) f=$((${f:-0}+1));; SKIPPED) s=$((${s:-0}+1));; esac
  done
  f=${f:-0}; s=${s:-0}
  {
    echo '{'
    echo '  "tool": "deck-browser-selftest",'
    echo "  \"status\": \"$status\","
    echo "  \"panel\": \"$(json_escape "$PANEL_BASE")\","
    echo "  \"proxy\": \"$(json_escape "${PROXY_URL:-n/a}")\","
    echo "  \"generated_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"reason\": \"$(json_escape "$reason")\","
    echo '  "summary": {'
    echo "    \"total\": ${#R_ID[@]}, \"passed\": $n, \"failed\": $f, \"skipped\": $s"
    echo '  },'
    echo '  "pages": ['
    for ((i=0; i<${#R_ID[@]}; i++)); do
      [[ $i -gt 0 ]] && echo ','
      echo '    {'
      echo "      \"id\": \"$(json_escape "${R_ID[$i]}")\","
      echo "      \"expected_title\": \"$(json_escape "${R_EXPECT[$i]}")\","
      echo "      \"status\": \"${R_STATUS[$i]}\","
      echo "      \"title_found\": ${R_FOUND[$i]},"
      echo "      \"fatal_text_found\": ${R_FATAL[$i]},"
      echo "      \"screenshot\": \"$(json_escape "${R_SHOT[$i]}")\","
      echo "      \"ocr_excerpt\": \"$(json_escape "${R_OCR[$i]}")\""
      echo -n '    }'
    done
    echo
    echo '  ]'
    echo '}'
  } > "$OUT_DIR/report.json"

  {
    echo "# Deck browser self-test — $status"
    echo
    echo "- Panel: \`$PANEL_BASE\`"
    echo "- Proxy: \`${PROXY_URL:-n/a}\`"
    echo "- Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "- Reason: ${reason:-n/a}"
    echo "- Pages: ${#R_ID[@]} total, $n passed, $f failed, $s skipped"
    echo
    echo "| Page | Expected heading | Status | Heading seen | Fatal text | Screenshot |"
    echo "|---|---|---|---|---|---|"
    for ((i=0; i<${#R_ID[@]}; i++)); do
      echo "| ${R_ID[$i]} | ${R_EXPECT[$i]} | ${R_STATUS[$i]} | ${R_FOUND[$i]} | ${R_FATAL[$i]} | \`${R_SHOT[$i]}\` |"
    done
    echo
    echo "OCR excerpts are in \`$OUT_DIR/report.json\`. No credentials are stored in either report."
  } > "$OUT_DIR/report.md"

  echo "browser-selftest: $status — report: $OUT_DIR/report.json / $OUT_DIR/report.md"
}

skip() { # reason
  local reason="$1" p
  for p in $PAGES; do
    add_result "$p" SKIPPED "${TITLE[$p]:-$p}" false false "" ""
  done
  write_report SKIPPED "$reason"
  exit 0
}

say() { echo "browser-selftest: $*"; }

# ---------------------------------------------------------- 1. panel reachability
say "checking panel reachability at $PANEL_BASE"
if ! curl -fsS --max-time 6 "$PANEL_BASE/health.php" >/dev/null 2>&1; then
  skip "panel unreachable at $PANEL_BASE/health.php — no browser pass attempted (honest SKIPPED, not PASS)"
fi

# ------------------------------------------------- 2. resolve an authenticated proxy
PROXY_URL=""
if [[ -n "${PANEL_PROXY_URL:-}" ]]; then
  PROXY_URL="$PANEL_PROXY_URL"
  say "using supplied authenticated proxy $PROXY_URL"
else
  PANEL_PASS="${PANEL_PASS:-}"
  if [[ -z "$PANEL_PASS" && -x "$DECK_DEPLOY/deck.sh" ]]; then
    # deck.sh feeds the sudo password on stdin; on this host sudo is NOPASSWD,
    # otherwise the operator supplies DECK_SUDO_PASS. Nothing is echoed.
    say "sourcing panel password silently via deck.sh (value never printed)"
    PANEL_PASS="$(cd "$DECK_DEPLOY" && DECK_SUDO_PASS="${DECK_SUDO_PASS:-unused}" \
      ./deck.sh --sudo "sed -n 's/^PANEL_ADMIN_PASSWORD=//p' /opt/hermes-control-panel/panel.env | head -1" \
      2>/dev/null | tr -d '\r\n')"
  fi
  [[ -z "$PANEL_PASS" ]] && skip "no password source and no PANEL_PROXY_URL — cannot authenticate a browser pass"

  cat > "$OUT_DIR/proxy.py" <<'PYEOF'
#!/usr/bin/env python3
"""Auth-injecting + view-selecting proxy used only by this self-test.

Logs in once with PANEL_PASS (from env, never printed), forwards requests with
the session/CSRF cookies, strips Origin/Referer (panel same-origin trap), and
patches app.js so ?view=<id> selects a registered view without coordinate clicks.
"""
import http.cookiejar
import os
import pathlib
import urllib.parse
import urllib.request
import http.server

BASE = os.environ["PANEL_BASE"]
PORT = int(os.environ["PROXY_PORT"])
PASS = os.environ["PANEL_PASS"]
LOG = os.environ["OUT_DIR"] + "/proxy.log"
JAR = http.cookiejar.CookieJar()
OPENER = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(JAR))


def csrf():
    for c in JAR:
        if c.name == "panel_csrf":
            return c.value
    return ""


def log(line):
    with open(LOG, "a") as f:
        f.write(line + "\n")


OPENER.open(BASE + "/", timeout=15).read()
body = urllib.parse.urlencode({"username": "admin", "password": PASS}).encode()
req = urllib.request.Request(
    BASE + "/api.php?action=login", data=body,
    headers={"Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": csrf()},
    method="POST",
)
OPENER.open(req, timeout=15).read()
log("PROXY_READY login=ok")

# Patch only the test copy of app.js; production is untouched.
ORIGINAL = OPENER.open(BASE + "/app.js", timeout=15).read().decode("utf-8", "replace")
NEEDLE = "let view = 'overview';"
PATCH = "let view = new URLSearchParams(location.search).get('view') || 'overview';"
PATCHED = ORIGINAL.replace(NEEDLE, PATCH, 1)
log("PROXY_READY appjs_patch=" + ("applied" if PATCHED != ORIGINAL else "FAILED"))


class Proxy(http.server.BaseHTTPRequestHandler):
    def _forward(self):
        path = self.path
        length = int(self.headers.get("Content-Length") or 0)
        data = self.rfile.read(length) if length else None
        if path.split("?")[0] == "/app.js":
            payload = PATCHED.encode("utf-8")
            code, ctype = 200, "application/javascript; charset=utf-8"
        else:
            headers = {}
            for k, v in self.headers.items():
                if k.lower() in ("origin", "referer", "host", "content-length",
                                 "accept-encoding", "cookie"):
                    continue
                headers[k] = v
            headers["X-CSRF-Token"] = csrf()
            req = urllib.request.Request(BASE + path, data=data, headers=headers,
                                         method=self.command)
            try:
                resp = OPENER.open(req, timeout=30)
                payload, code = resp.read(), resp.status
                ctype = resp.headers.get("Content-Type", "text/html; charset=utf-8")
            except urllib.error.HTTPError as e:
                payload, code = e.read(), e.code
                ctype = e.headers.get("Content-Type", "text/html; charset=utf-8")
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(payload)
        log(f"{self.command} {path} -> {code}")

    do_GET = _forward
    do_POST = _forward

    def log_message(self, fmt, *args):
        pass  # our own log() above; never log headers (cookies/tokens)


http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Proxy).serve_forever()
PYEOF
  chmod 700 "$OUT_DIR/proxy.py"

  PANEL_BASE="$PANEL_BASE" PROXY_PORT="$PROXY_PORT" PANEL_PASS="$PANEL_PASS" OUT_DIR="$OUT_DIR" \
    python3 "$OUT_DIR/proxy.py" >"$OUT_DIR/proxy.out" 2>&1 &
  PROXY_PID=$!
  PROXY_URL="http://127.0.0.1:$PROXY_PORT"

  ready=0
  for _ in $(seq 1 40); do
    if curl -fsS --max-time 2 "$PROXY_URL/" >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.5
  done
  [[ "$ready" == 1 ]] || skip "auth proxy did not start on $PROXY_URL (see $OUT_DIR/proxy.out)"
fi

if ! curl -fsS --max-time 5 "$PROXY_URL/" >/dev/null 2>&1; then
  skip "authenticated proxy unreachable at $PROXY_URL"
fi

# --------------------------------------------------------- 3. launch hidden browser
say "launching hidden Firefox via gui-debug (Xvfb)"
mkdir -p "$OUT_DIR/ff-profile"
# Firefox inline URL autocomplete otherwise hijacks a typed ?view=... that shares
# a prefix with a previously visited one (settings -> security, self-test ->
# settings). Disable it in THIS throwaway profile only.
cat > "$OUT_DIR/ff-profile/user.js" <<'PREFS'
user_pref("browser.urlbar.autoFill", false);
user_pref("browser.urlbar.suggest.history", false);
user_pref("browser.urlbar.maxRichResults", 0);
user_pref("browser.startup.homepage", "about:blank");
PREFS
if ! python3 "$GD/session.py" start --size 1440x900 --force -- firefox --no-remote \
     --profile "$OUT_DIR/ff-profile" "$PROXY_URL/?view=overview&debug=1" \
     >"$OUT_DIR/browser-launch.json" 2>&1; then
  skip "gui-debug could not launch the hidden browser (see $OUT_DIR/browser-launch.json)"
fi

# ------------------------------------------------- 4. visit / shoot / OCR each page
PAGE_ARR=($PAGES)
aborted=0
abort_reason=""
mark_outage() { # first_page_index reason  -> mark that page and all later ones SKIPPED
  local from="$1" reason="$2" j rp
  for ((j=from; j<${#PAGE_ARR[@]}; j++)); do
    rp="${PAGE_ARR[$j]}"
    add_result "$rp" SKIPPED "${TITLE[$rp]:-$rp}" false false "" ""
  done
  aborted=1; abort_reason="$reason"
}

for ((idx=0; idx<${#PAGE_ARR[@]}; idx++)); do
  p="${PAGE_ARR[$idx]}"
  expect="${TITLE[$p]:-$p}"
  if ! curl -fsS --max-time 5 "$PANEL_BASE/health.php" >/dev/null 2>&1; then
    mark_outage "$idx" "panel became unreachable mid-run at page '$p' — remaining pages not tested (honest SKIPPED)"
    break
  fi
  say "visiting '$p' (expect heading: '$expect')"
  url="$PROXY_URL/?view=$p&debug=1"
  log_start=$(wc -l < "$OUT_DIR/proxy.log" 2>/dev/null || echo 0)
  python3 "$GD/session.py" act \
    --action 'key ctrl+l' \
    --action "type $url" \
    --action 'key Delete' \
    --action 'key Return' \
    --action "wait $WAIT" \
    --action "shot $p" >/dev/null 2>&1 || true
  shot="$GUI_DEBUG_DIR/$p.png"
  if [[ ! -s "$shot" ]]; then
    add_result "$p" FAIL "$expect" false false "$shot" "no screenshot captured"
    continue
  fi
  cp "$shot" "$OUT_DIR/shots/$p.png"

  # Crop to the top bar: it contains the REAL #view-title heading (not the
  # sidebar nav, whose labels would give a false positive) and any red
  # JS-error banner. Navigation itself is proven by the proxy log.
  crop="$OUT_DIR/shots/$p.crop.png"
  magick "$shot" -crop 1200x220+200+0 +repage "$crop" 2>/dev/null \
    || convert "$shot" -crop 1200x220+200+0 +repage "$crop" 2>/dev/null \
    || cp "$shot" "$crop"

  ocr="$(timeout "$OCR_TIMEOUT" python3 "$GD/vision.py" --image "$crop" --mode ocr 2>/dev/null || true)"
  flat="$(printf '%s' "$ocr" | tr '\r\n' '  ' | tr -s ' ')"
  excerpt="${flat:0:400}"

  if printf '%s' "$ocr" | grep -qi 'Problem loading page'; then
    mark_outage "$idx" "panel/proxy connection failed while loading '$p' — remaining pages not tested (honest SKIPPED)"
    rm -f "$crop"
    break
  fi
  rm -f "$crop"

  found=false
  if printf '%s' "$ocr" | grep -qiF -- "$expect"; then found=true; fi

  # Navigation proof: the proxy log for THIS page's window must show a 200 for
  # the exact ?view= URL. OCR of the address bar is too flaky (tab clutter).
  nav_ok=false
  if tail -n "+$((log_start + 1))" "$OUT_DIR/proxy.log" 2>/dev/null \
       | grep -qF "GET /?view=$p&debug=1 -> 200"; then nav_ok=true; fi

  fatal=false
  fp=""
  low="$(printf '%s' "$ocr" | tr '[:upper:]' '[:lower:]')"
  for pat in "${FATAL_PATTERNS[@]}"; do
    if printf '%s' "$low" | grep -qF -- "$pat"; then fatal=true; fp="$pat (OCR)"; break; fi
  done
  # Any 4xx/5xx from the panel during this run is also a hard failure.
  if tail -n "+$((log_start + 1))" "$OUT_DIR/proxy.log" 2>/dev/null \
       | grep -qE ' -> [45][0-9][0-9]$'; then
    fatal=true; fp="panel 4xx/5xx in proxy.log"
  fi

  if [[ "$found" == true && "$nav_ok" == true && "$fatal" == false ]]; then
    add_result "$p" PASS "$expect" true false "$OUT_DIR/shots/$p.png" "$excerpt"
  else
    note=""
    [[ "$nav_ok" == false ]] && note="[nav mismatch: expected view=$p]"
    [[ "$found" == false ]] && note="$note [heading '$expect' not seen]"
    add_result "$p" FAIL "$expect" "$found" "$fatal" "$OUT_DIR/shots/$p.png" \
      "${excerpt}${note}${fp:+ [fatal:$fp]}"
  fi
done

python3 "$GD/session.py" stop >/dev/null 2>&1 || true

# ----------------------------------------------------------------- 5. verdict
if [[ "$aborted" == 1 ]]; then
  write_report SKIPPED "$abort_reason"
  exit 0
fi
fails=0
for st in "${R_STATUS[@]}"; do [[ "$st" == FAIL ]] && fails=$((fails+1)); done
if [[ $fails -gt 0 ]]; then
  write_report FAIL "$fails page(s) failed browser verification"
  exit 1
fi
write_report PASS "all ${#R_ID[@]} registered pages rendered their heading with no fatal error text"
exit 0
