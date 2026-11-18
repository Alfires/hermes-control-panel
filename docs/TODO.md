# Hermes Control Deck — tracked TODO

This is the canonical task list for the build. It is tracked in git so every
change of scope leaves a version-history trail.

## Standing requirement (owner, 2026-11-18)

- [ ] **All changes made by agents must be recorded in git with semantic commit
      messages** (`feat:`, `fix:`, `style:`, `test:`, `docs:`, `chore:`,
      `refactor:`, `perf:`). No agent edit may be left uncommitted at the end of
      a turn, and no push may happen without a semantic message. The panel's UI
      editor writes append-only config revisions with a hash chain; source
      changes use git history. Both together are the version history.

## Remaining build tasks

- [ ] Finish first-run wizard verification in a real browser (light theme).
- [ ] Verify dashboard, themes, search, voice, self-test, Hermes sources in browser (light + dark + square radius).
- [ ] Finish the two-container runtime on the Deck without duplicating the existing Hermes gateway (ports 8642/9119 already owned by `/opt/hermes`).
- [ ] Create and publish the second Forgejo repo `hermes-control-panel-installer` with the one-line installer.
- [ ] Run privileged-flow, secret-leakage and live integration tests after final deploy.
- [ ] Push all semantic commits to both Forgejo repos.

## Completed

- [x] Research reports and multipage implementation plan (`43cdb49`).
- [x] 801-case framework test suite, zero failures, 20 opt-in live skips.
- [x] Config governance, registries, renderer, themes, search, STT, setup, self-test.
- [x] Deploy helper repaired for the existing production Hermes container.
