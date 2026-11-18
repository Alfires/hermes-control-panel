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
