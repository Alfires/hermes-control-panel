# Deep browser self-test — Hermes Control Deck

`tools/selftest-browser.sh` (toolbox wrapper: `deck-browser-selftest`) closes the
"self-test is shallow" gap: instead of counting rendered markup, it drives the
real UI in a hidden Firefox and checks what the owner would actually see on
every registered page.

## Run it

Preferred (toolbox tool):

```
run({ name: "deck-browser-selftest" })
run({ name: "deck-browser-selftest", args: "http://192.168.2.157:9120 /tmp/opencode/my-run 10" })
```

Directly:

```sh
cd /home/timo/programming/hermes-control-panel
tools/selftest-browser.sh
PANEL_BASE=http://127.0.0.1:1 OUT_DIR=/tmp/opencode/probe tools/selftest-browser.sh
```

Arguments / environment (all optional):

| Variable | Default | Purpose |
|---|---|---|
| `PANEL_BASE` | `http://192.168.2.157:9120` | Panel under test. |
| `OUT_DIR` | `/tmp/opencode/deck-browser-selftest` | Reports, screenshots, throwaway proxy + browser profile. |
| `WAIT` | `12` | Seconds to let bootstrap/API settle on each page. |
| `OCR_TIMEOUT` | `120` | Per-page timeout for the local vision OCR. |
| `PAGES` | all 13 registered ids | Override the page list. |
| `PROXY_PORT` | `8898` | Local auth proxy port (8899 is the manual verification proxy). |
| `PANEL_PROXY_URL` | unset | Use an already-authenticated proxy instead of sourcing the password. See caveat below. |
| `PANEL_PASS` / `DECK_SUDO_PASS` | unset | Explicit password source. Normally the script calls `deck.sh --sudo` to read the root-only `PANEL_ADMIN_PASSWORD` from `/opt/hermes-control-panel/panel.env`. |

The password is read through the `deck.sh` ssh/sudo wrapper, is never printed,
and is never written to a report or log. It is passed to the throwaway proxy
only through its environment.

## What it does

1. Probes `$PANEL_BASE/health.php`. If the panel is down it writes a `SKIPPED`
   report and exits 0 — it never fabricates a pass.
2. Starts a local auth-injecting proxy that logs in once, keeps the session +
   CSRF cookies, strips `Origin`/`Referer` (the panel's same-origin trap), and
   patches its **test copy** of `app.js` so `?view=<id>` selects a registered
   view. Production source is untouched.
3. Launches Firefox inside a hidden Xvfb session via `gui-debug`. The throwaway
   profile disables URL-bar autocomplete so `?view=settings` cannot be hijacked
   into a previously visited `?view=security`.
4. For each registered page (`overview, chats, agents, approvals, security,
   vault, settings, audit, setup, search, hermes, themes, self-test`) it
   navigates, screenshots, and crops the top bar. `gui-debug vision.py --mode
   ocr` reads the **real `#view-title` heading** from that crop — not the
   sidebar label, which would give a false positive — and checks for fatal
   error text. Navigation is proven by a 200 for that exact `?view=` URL in the
   proxy log, and any 4xx/5xx in the page's request window fails the page.
5. Writes `report.json` and `report.md` (page, expected heading, status,
   heading-seen, fatal-text-seen, screenshot path, OCR excerpt), then exits 0 on
   all-pass, 1 if any page failed. A panel outage mid-run marks the unvisited
   pages `SKIPPED` rather than failed.

## What it proves

- Every registered page loads through a real authenticated browser, returns
  HTTP 200, and renders its own titled view (not a blank screen, login screen,
  or generic fallback).
- No fatal JS/PHP error banner or fatal error text is visible in the header /
  content region of those pages.
- The auth-injection path (session + CSRF cookies, `Origin` stripped) works end
  to end in a browser.
- Evidence is retained: one screenshot per page plus OCR excerpts.

## What it deliberately does not prove

- Not pixel-perfect visual fidelity. It reads the heading and scans for fatal
  text; it does not compare colours, spacing, fonts or theme presets.
- Not deep functionality. It does not submit forms, propose/approve config
  revisions, run agents, send chat messages, or exercise the vault test form.
  Those remain manual/`panel-browser-verify` procedures.
- Not full-page error scanning. OCR reads the top bar / first content rows; a
  fatal message rendered only far down a long page could be missed.
- Not a backend contract test. API verbs, schema validation and governance
  invariants belong to `tests/suite.php` and the Deck integration suite.
- `PANEL_PROXY_URL` is accepted, but an externally supplied proxy must itself
  support `?view=<id>` selection; otherwise heading assertions fail. The
  built-in proxy path is the one that is verified.

## How it complements the PHP self-test

The PHP self-test (`Scheduler::runSelfTest()` / `Selftest`) checks that every
registered page has a renderable source and non-trivial markup. That is a fast,
server-side, scheduler-friendly integrity check. This browser pass checks the
other half — that the page actually appears, titled and error-free, in a real
browser session. Keep both: the PHP test can pass while the SPA is blank, and a
browser pass can pass while a source file is thin. Neither replaces the other.

This deep pass is intentionally opt-in (it takes several minutes because OCR is
serial). Run it before a release or after UI/nav/renderer changes, not on the
minute tick.

## Verified results (2026-11-19)

- Live Deck: **13 passed, 0 failed, 0 skipped** — all registered pages showed
  their heading with no fatal error text. Report:
  `/tmp/opencode/deck-browser-selftest/report.{json,md}`.
- Unreachable panel (`PANEL_BASE=http://127.0.0.1:1`): `SKIPPED`, 13 skipped,
  exit 0 — no fake pass.
- No password found in any generated report or log.
