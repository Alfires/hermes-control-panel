# Hermes Control Deck — tracked TODO

This is the canonical task list for the build. It is tracked in git so every
change of scope leaves a version-history trail.

## Standing requirement (owner, 2026-11-18)

- [x] **All changes made by agents must be recorded in git with semantic commit
      messages** (`feat:`, `fix:`, `style:`, `test:`, `docs:`, `chore:`,
      `refactor:`, `perf:`). No agent edit may be left uncommitted at the end of
      a turn, and no push may happen without a semantic message. The panel's UI
      editor writes append-only config revisions with a hash chain; source
      changes use git history. Both together are the version history.

## Remaining build tasks

- [ ] Nothing blocking. Re-run `php tests/suite.php` and the Deck integration
      script after any future change.

## Completed (with evidence)

- [x] Research reports and multipage implementation plan (`43cdb49`).
- [x] 802-case framework test suite: 782 pass + 20 opt-in live skips locally;
      802/802 with `RUN_LIVE=1` against the deployed Deck.
- [x] Config governance, registries, renderer, themes, search, STT, setup, self-test.
- [x] Deploy helper repaired for the existing production Hermes container.
- [x] First-run wizard verified in browser: requirements, assistant copy and
      completion; setup status flipped to complete.
- [x] All 13 dashboard views verified in browser with live DOM text: Overview,
      Sessions, Agents, Approvals, Security, Vault, Search, Themes, Voice,
      Self test, Hermes, Settings, Audit, Setup.
- [x] Theme presets verified: Brezel dark square applied (background
      `#101a26`, surface `#142130`) and restored to Brezel light rounded.
- [x] Config governance verified end to end: propose → approve → rollback,
      with append-only revision numbers and hash-chained history.
- [x] Fixed revision-number reuse after rollback (`3b93acb`) and added a
      regression case.
- [x] Deck integration suite: 16/16 pass, including real secret-leakage probe
      (`***CENSORED***` marker) and restart-persistence check.
- [x] Daily scheduler verified end to end: temporary `every 1m` schedule fired
      within 20s, wrote a passing `webui.selftest` report for 32 pages, then
      schedule restored to `daily 04:20`.
- [x] Second Forgejo repo published:
      `Alfires/hermes-control-panel-installer` (`9f8a355`); one-line raw URL
      returns the script and `install.sh status` reports the live compose stack.
- [x] Two-container runtime deployed without duplicating the existing Hermes
      gateway (gateway stays owned by `/opt/hermes` on 8642/9119; panel `webui`
      is the only recreated service).
- [x] Browser-verification procedure captured as toolbox tool
      `panel-browser-verify`.
- [x] Deep debugging pass: 12 confirmed defects fixed, semantic commits, 12
      regression cases, redeployed and verified (see `DEBUGGING-REPORT.md`).
- [x] GUI usability pass (`a72a6fe`): all 32 registered pages now render a
      titled view backed by a curated source or an honest explanation; sidebar
      is grouped, collapsible and searchable; Overview has a "Needs your
      attention" strip; 10s auto-refresh no longer erases typed input;
      Approvals shows decided history; empty vault disables the test form.
- [x] Governance correctness (`7204a4b`): proposal expiry enforced, rollback
      restricted to previously approved revisions, stale escalations expired
      by the minute tick.
- [x] Full documentation set written and published to GitHub
      `Alfires/hermes-control-panel` (13 documents, 96 tracked files).
- [x] Live verification after the GUI pass: 820/820 live suite cases and
      16/16 Deck integration checks passing.

## Known gaps versus the implementation plan (honest status)

These are recorded here rather than papered over. The roadmap's *feature
surface* is implemented, but the following plan items are partial:

- [ ] **Config-driven UI is partial.** `config/site.json` controls nav, themes
      and feature flags, but the browser views are hardcoded in `app.js`.
      The planned `src/lib/blocks/*.php` renderers and `src/templates/**`
      do not exist; `Render::page()` is used only as a self-test length check.
      An AI can re-compose navigation and tokens, not arbitrary blocks.
- [ ] **Local STT is wired but not functional.** The loopback proxy, mic UI and
      capability detection exist, but no whisper.cpp model is installed in the
      deployed image, so the Voice page correctly reports "engine not installed".
- [ ] **Three Hermes pages are honest stubs.** `hermes-pairing`,
      `hermes-docs` and `hermes-chat` explain where the capability lives and
      link elsewhere; they have no dedicated safe data source.
- [ ] **Approvals are advisory for two tools.** `host_apply` and `webui_edit`
      record approval rows but enforcement is the escalation/HMAC path, not
      the approvals table itself. `escalations.token_hash` is written, not read.
- [ ] **Self-test is shallow.** It checks rendered markup length and source
      registration, not the real browser UI; browser verification is a
      separate manual/toolbox procedure.
- [ ] **No backup tooling is shipped.** `OPERATIONS.md` documents a manual
      backup of the `/var/lib/panel` volume instead.
- [ ] **Curated critical-package rules are empty** (`{}` by default), and the
      action catalogue's `method` field is decorative — the API accepts either
      HTTP verb.
- [ ] **Dead frontend variables remain.** `window.HCP_CONFIG` is injected but
      unused, and `window.HCP_THEME_PRESETS` is read but never defined (the
      code falls back to `S.theme_presets`).
