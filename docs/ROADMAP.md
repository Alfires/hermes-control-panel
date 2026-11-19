# Hermes Control Deck — complete system roadmap

_Last updated: 19 Nov 2026. This is the canonical plan. `docs/TODO.md` keeps the
short list of current code gaps; this document explains the whole system, why
each piece exists, what is real now, and what "done" means._

---

## 1. Decisions that shape everything (settled, not open questions)

| Topic | Decision | Why |
|---|---|---|
| Users | **One user — you.** No multi-user accounts now. | You answered “one user, just me, but a big redesign”. We still keep identity and project ownership in separate code so this is not painted into a corner. |
| Desktop | **Build on the real Hermes Desktop source** (Electron + React, MIT), pinned commit `7c7daa25c66262d504e3c2b29ade0ed5928b056e`. | You chose parity with Hermes over a hand-written shell. We inherit its chat, native panes, voice, projects and packaging. |
| Desktop mode | **Remote-only “light” variant** pointed at your server. | Your one Hermes gateway is the real service. The app must not start a second local agent. |
| Client ↔ server | **No server between the client and Hermes.** The desktop app and the web UI are two clients of the same Hermes API/instances; the PHP panel is the control plane for login, approvals, vault, git and settings — it is **not** a chat relay. | Your words: “you can directly communicate with my Hermes ... the website should just be a different client”. Chat, streaming and tool calls go straight to Hermes; the panel never proxies a conversation. |
| Vault for agents | An agent may **use** a real credential by logical name + `{{secret}}` placeholder; it can never read or print the value. The root broker replaces the raw, base64 and URL-encoded forms with `***` on every exit path, and `tests/leak-probe.sh` proves it against a real secret. | Your words: “give the AI access to my vault with security ... only put in the placeholders ... no password leak through a print of the var”. |
| Framework language | **PHP framework + JavaScript libraries. No TypeScript added by us, no compile step for our code.** | You answered “PHP, you can use JS libraries”. Keeps the panel editable on the Deck without npm. |
| Web UI feel | Chat docks on the **right**, fullscreen is opt-in, microphone lives **inside** the chat composer, every menu scrolls, Hermes is **one built-in area** rather than 19 sidebar sections. | Your direct UI feedback, implemented and committed as `Hermes Builder`. |
| Projects & git | **Use the existing Forgejo server.** Every project gets a git repo, with private/main-global choice, visible version, recent changes and changed files. | You chose Forgejo. Git is the memory of work with AI. |
| Agents | One Hermes runtime, many profiles/agents. Agents and you use the same tools, with the same approval rules; secret values injected by the root broker and never shown. | Keeps the system simple while matching Hermes' profile model. |
| Services | Exactly two containers (`hermes`, `webui`); no duplicate production gateway; no new host system service. | Original hard requirement, still valid. |
| Security | Zero permissions for new agents; owner approval for host changes and secret use; append-only audit and config revisions with a hash chain. | Non-negotiable from the original brief. |
| Deployment | Push to Forgejo **before** deploy; Deck pulls from Forgejo. GitHub mirror is documentation-friendly. | Verified deployment path. |

**Explicitly not doing now:** multi-user tenancy, a separate git server, a React/TypeScript rewrite of the PHP panel, a third container, or a local Hermes runtime inside the desktop app.

---

## 2. What exists today (evidence, not wishes)

### Real and working
- PHP control deck with login, CSRF, rate limiting, scoped agents, vault broker,
  escalation/HMAC approvals, security scanner, self-test, themes, search,
  proposal → preview → approval → atomic apply → audit → rollback, and a
  first-run wizard. Two containers: `hermes` and `webui`.
- 32 registered pages were simplified to a short sidebar: Overview, Chats,
  Agents, Approvals, Security, Vault, Search, Hermes (one integrated area),
  Themes, Self test, Settings, Audit, Setup. Every Hermes capability is an
  internal tab with a curated, scrubbed data source.
- Right-side chat popup with an explicit Fullscreen button; hold-to-talk
  microphone in the chat composer (one shared implementation with the legacy
  transcript page); scrollable sidebar, drawer, tab strips, content and chat.
- Desktop **fork skeleton**: pinned upstream commit, a 58-line identity patch
  (product name, app IDs, update channel disabled), idempotent build script,
  safe connection template, and tests. Verified: patch applies cleanly to the
  pinned upstream; `desktop/bootstrap.sh check` passes.
- Tests: local suite **925 registered / 905 passed / 0 failed / 20 skipped**;
  earlier full live run **820/820** and Deck integration **16/16**. Any new
  phase must keep these green.

### Honest gaps (carried from `docs/TODO.md`)
- The production browser shell (`index.php` + `app.js`) is still hardcoded even
  though the framework can now render every registered page declaratively from
  a manifest (`src/framework/PageManifests.php`, `/deck.php`). Migrating the
  live render path block-by-block is the remaining Phase 3 step.
- STT engine + model are baked into the image in source but the deployed
  container has not been rebuilt, so `stt.status` still reports unavailable on
  the Deck until that rebuild happens (Phase 5).
- `hermes-pairing`, `hermes-docs`, `hermes-chat` are honest stubs with no safe
  dedicated data source.
- `host_apply`/`webui_edit` approval rows are advisory; enforcement is the
  broker action/HMAC allowlist, not those rows. Page-edit (`ui.page.*`) mutations
  are now bound to approved escalations (Phase 5).
- PHP self-test checks markup/source registration; the new browser self-test
  (`tools/selftest-browser.sh`) adds a real headless pass over every page.
- Backup tooling (`tools/backup.sh`) is shipped; curated critical-package rules
  are installed by both installers instead of `{}`.
- The action catalogue was reconciled with `api.php` (52 actions) and its HTTP
  verbs are now enforced; dead `window.HCP_*` globals were removed.
- The Linux desktop app is built and smoke-tested
  (`docs/DESKTOP-SMOKE-LINUX.md`): first launch, setup skip, main chat surface,
  composer voice controls, right sidebar, runtime identity. AppImage and deb are
  built/type-validated and the AppImage launched headless; rpm needs host
  `rpmbuild`. Real-token chat, approval cards, project list, macOS and Windows
  are not yet verified.

---

## 3. Target architecture in plain language

```
        your PC / Mac / Linux                browser (any device)
     ┌───────────────────────────┐        ┌───────────────────────────┐
     │ Hermes Control Deck app   │        │ webui SPA (PHP-served UI)  │
     │ (fork of Hermes Desktop)  │        │ = a DIFFERENT CLIENT       │
     └────────────┬──────────────┘        └────────────┬──────────────┘
                  │ direct HTTPS to Hermes API          │ direct HTTPS to Hermes API
                  │ (chat, streaming, tools)             │ (no chat relay in between)
                  └────────────────┬──────────────────────┘
                                   ▼
                    Hermes gateway (9119/8642) — ONE container
                    profiles = agents, sessions, tools
                       │                         │
                       ▼                         ▼
              root vault broker            Forgejo: one repo per project,
              (use-only, value-redacted)   private or main/global, git history
                       ▲
                       │ control plane only: vault CRUD, approvals,
        PHP panel ─────┘ settings, themes, UI config, git presentation
        (webui container, 9120) — NOT in the chat path
```

- **Presentation** = two independent clients of the *same* Hermes API: the
  desktop fork and the web SPA. Neither proxies a conversation through PHP.
- **Execution plane** = the one Hermes gateway with profiles (your agents).
  Chat, streaming and tool calls land here directly from either client.
- **Control plane** = PHP panel: vault management, approvals, UI config,
  settings and git presentation. It holds the owner login and hands clients a
  short-lived Hermes connection; it is never in the message path.
- **Secrets** = agents send only a logical name and `{{secret}}`; the root broker
  injects the real value into the one action and scrubs every reply.
- **Memory** = git in every project plus append-only panel config revisions;
  Hermes keeps its own sessions/skills/memory per profile.

---

## 4. Phases, in the order they will be built

### Phase 0 — UI rescue and desktop pin ✅ done
**Goal:** make the current panel easy to use and lock the desktop upstream.

Delivered:
- right-side chat, opt-in fullscreen, mic in chat, scrollable menus, one
  integrated Hermes area (commit `d667f39`, author `Hermes Builder`);
- pinned Hermes Desktop fork, patch, bootstrap, connection template, tests;
- this roadmap.

**Done when:** `php tests/suite.php` green, patch applies to the pinned commit,
`desktop/bootstrap.sh check` passes. ✅

---

### Phase 1 — Desktop app first (next work item)
**Goal:** a real app that feels like Hermes and talks **directly** to your one
Hermes API — no server in the chat path. Prepare everything here; build on the
target machine (your choice). Platform order: **Linux → macOS → Windows**.

Work:
1. **Direct connection setup.** `desktop/CONNECTION.md` documents how to point
   the remote-only "light" build at your Hermes gateway URL and enter a
   short-lived gateway token in Settings → Connections. Electron `safeStorage`
   holds the token; nothing is written to a committed file. No PHP endpoint
   relays chat. If a token-mint bridge is added later, it is a separate,
   owner-approved capability — never a conversation proxy.
2. **Connection preseed.** `desktop/connection.json.example` shows the config
   shape with placeholders only; the domain is filled in at install time, the
   token is typed in the app, never injected by a script.
3. **Renderer decision (made from the real build).** No renderer patch: the
   pinned upstream already has a right sidebar/preview panes, composer voice
   controls and layout/HUD controls. The web UI keeps its right-docked popup +
   Fullscreen button; the desktop keeps native full-window chat. See
   `docs/DESKTOP-SMOKE-LINUX.md`.
4. **Branding.** Add our icon set to `desktop/assets`, keep the MIT attribution
   file, and disable upstream's updater feed (already in the identity patch).
5. **Build gates.** `bootstrap.sh build` (unpacked) and `dist:linux` produced
   AppImage + deb and a headless launch proof; rpm blocked on host `rpmbuild`.
   Still to do: direct connection with a real token, one chat message, approval
   card, project list, then macOS DMG/zip and Windows MSIX.

**Acceptance criteria**
- A fresh profile connects straight to the Hermes gateway; no token in git/logs;
  no PHP request appears in the network tab while chatting.
- Chat opens on the right; Fullscreen works; mic appears in the composer and
  is disabled until a model is installed.
- No `hermes serve` process is started by the app on the desktop.
- Two containers still; `hermes` continues to own 8642/9119.
- Linux AppImage/deb built and launched in a clean profile (done, see evidence
  doc); macOS then Windows compiled and reported honestly (unsigned builds noted
  if needed). rpm is optional and blocked on missing `rpmbuild`.

**Commands**
```bash
bash desktop/bootstrap.sh check
bash desktop/bootstrap.sh dev       # with DECK_REMOTE_URL set
bash desktop/bootstrap.sh build
php tests/suite.php
```

---

### Phase 2 — PHP modular website framework
**Goal:** a good existing framework where pages and blocks are composed from a
registry, so adding a page is data, not copy-paste.

**Foundation delivered (Nov 2026):** `composer.json`/`composer.lock` with
committed `vendor/`, Slim 4 kernel, Twig renderer, `BlockCatalog` allowlist and
typed prop validation, 14 block templates, vendored htmx 2.0.11 + Alpine
3.17.5 with licenses, owner-only preview endpoint `/deck.php`, and five
framework tests (831 suite cases total). See `docs/FRAMEWORK.md`. The SPA views
are **not** migrated yet, so this is a foundation, not a finished rewrite.

Chosen shape (PHP + JS libraries, no TypeScript):
- **Slim 4** for routing and middleware (small, mature, dependency injection,
  PSR-7) and **Twig** for templates; both vendored with Composer so the Deck
  needs no build step at deploy time. If Composer cannot be installed in the
  build environment, keep the current PSR-style router and document the
  exception — do not silently invent a framework.
- **HTMX** for server-driven partial swaps and **Alpine.js** for local UI state
  (both vendored, no npm/CDN at runtime). Plain JS modules remain for the chat
  socket and audio.
- Directory layout under `src/framework/`: `Kernel.php`, `Router.php`,
  `Middleware/`, `Registry/`, `Render/`, `templates/`; existing `src/lib`
  services (auth, config, RBAC, audit, vault) are injected, not rewritten.
- One source of truth: `PageRegistry` + `BlockRegistry` already exist in
  `src/lib/registry.php`; the framework consumes them.

Work:
1. ✅ Install/vendor Slim + Twig + htmx + Alpine with pinned versions and
   `composer.lock` committed; `tools/vendor-refresh.sh` reproduces them.
2. Move the shell (sidebar, topbar, chat dock, drawer) into Twig layouts
   (preview layout exists; production shell migration remains).
3. Convert the bespoke `render*()` functions one by one into registered blocks:
   `hero`, `kpi`, `cardGrid`, `table`, `form`, `chat`, `timeline`, `auditView`,
   `searchResults`, `themeGallery`, `statusStrip`, `voiceRecorder`.
4. Keep `app.js` as a thin controller: API calls, sockets, audio, keyboard.
5. Add a `framework.page.renders` test that boots a page through the framework
   and checks HTML, titles, no `<script>` injection and no secret leakage.

**Acceptance criteria**
- A new page can be added by one registry entry plus one Twig template; no
  changes to the sidebar/router code.
- All existing pages render identical or better UI; browser screenshots for
  every sidebar item are captured with `panel-browser-verify`.
- `php tests/suite.php` green; no CDN request in the browser network log.

---

### Phase 3 — Real AI-editable UI (block-level config)
**Goal:** make “the AI can change the interface” literally true, safely.

Work:
1. Manifest format: `{id, version, title, icon, route, blocks:[{instance,type,source,props}]}`.
   Validate against `BlockRegistry` + JSON Schema; unknown block kinds are
   dropped, never rendered. No `eval`, no arbitrary CSS, no dynamic imports.
2. Proposal flow: agent proposes patch ops (`add`, `move`, `remove`, `replace`
   props) → server validates → preview/diff → owner approves → atomic apply →
   append-only revision with hash chain → one-click rollback.
3. Bind approval rows: `host_apply` and `webui_edit` proposals must reference
   the escalation ID that authorised them; recorded decision is checked at
   execution time, not just displayed. Read `escalations.token_hash` or delete
   it deliberately.
4. Browser renders from config, not hardcoded `TITLES`/`render()` maps. The
   fallback “honest stub” view stays for sources with no data.
5. Self-test uses a real headless browser pass (via `gui-debug`) on every
   registered page, not just markup length.

**Acceptance criteria**
- An AI proposes “add a KPI block to Overview”; the diff shows exactly that;
  applying it changes the browser; rollback restores the previous view.
- An AI cannot inject a script, raw CSS, an unknown block or a secret-bearing
  data source — rejected by schema with a clear message.
- An approval denial actually prevents the privileged call (test proves it).

---

### Phase 4 — Projects, git and Forgejo
**Goal:** every project has git, visible history, and syncs across devices.

Work:
1. Project model in PHP: `id`, `name`, `visibility` (`private` / `main`),
   `forgejo_repo`, `local_path`, `owner`, `created_at`. Single-user now, but
   `owner` exists so more users can be added without a rewrite.
2. “New project” wizard asks one plain question — **private or main?** — then
   creates a Forgejo repo through its API using a vault-backed credential,
   clones it under the Hermes workspace, and writes the first commit.
3. Git surface in the UI: current branch/commit, clean/dirty, last five
   commits, changed files with diffs, and “Open in Forgejo”. Data comes from a
   curated broker action (`git.status`, `git.log`, `git.diff`), no shell access
   for agents.
4. Sync: private projects sync only to your Forgejo account; “main” projects
   are readable by every device you log in from. Document that Hermes
   `state.db` must never live on a network filesystem (SQLite WAL corruption).
5. Backup tool: `tools/backup.sh` produces a restorable archive of
   `/var/lib/panel`, config revisions and project list (never secret values).

**Acceptance criteria**
- Creating a project creates a Forgejo repo and a first commit; UI shows the
  commit hash and changed files.
- Two devices can clone the same main project; private projects do not appear
  on a second device without an explicit login.
- Backup restores into a fresh container without committing secrets.

---

### Phase 5 — Agents as peers, voice and local PC access
**Goal:** you and agents use the same tools under the same rules; chat is the
home surface; optional local execution after login.

Work:
1. Agent roster with avatar, role, rank, “needs you” badge, pinned/recent
   ordering, and one chat per agent. Start new agents with zero permissions.
2. Inline approval cards in the transcript: exact command, why it was flagged,
   Allow / Refuse / Always allow, with state changing from “awaiting approval”
   to result. Gestures never grant approval.
3. Simple/Advanced mode: Simple hides technical traces, status bars and raw
   JSON; Advanced folds them into “Thought for 8s / Worked for 46s” details.
4. Command palette (`Ctrl+K`) finds pages, settings fields, sessions and
   actions — one action, one home (keyboard, palette, visible control).
5. STT: install a small whisper.cpp model in the `webui` image, verify the mic
   button enables and a held note becomes text in the composer; delete audio
   immediately. Keep TTS optional and local.
6. Local PC worker (later in this phase): an outbound-only process you run on
   your PC after login, with allow/ask/deny tool rules, one-use credentials and
   no inbound port. Model it on OpenCode/Claude Code remote patterns. It is
   opt-in, never required for the web/desktop chat to work.

**Acceptance criteria**
- A fresh agent has zero tools; granting one requires owner approval and is
  audited.
- Voice transcript appears in the chat input, not a separate page; audio file
  is gone after transcription.
- Local worker cannot be reached from the internet and cannot use a secret
  whose name it was not granted; denial is visible in the transcript.

---

### Phase 6 — Documentation, tests, deployment hygiene
**Goal:** every part documented and every claim testable.

Work:
- Keep `README.md`, `ARCHITECTURE.md`, `FRONTEND.md`, `COMPONENTS.md`, `API.md`,
  `DATA-MODEL.md`, `SECURITY.md`, `DEPLOYMENT.md`, `OPERATIONS.md`, `TESTING.md`,
  `TROUBLESHOOTING.md`, `DEBUGGING-REPORT.md`, `FILE-INDEX.md`, `TODO.md`,
  `ROADMAP.md` and the new `desktop/README.md` current with each phase.
- ~~Reconcile the action catalogue (35 entries) with `api.php` (39 actions)~~
  **Done**: catalogue and switch both have 52 actions and are checked equal by
  `tests/unit/hygiene.php`; the declared verbs are now enforced (405 on mismatch).
- ~~Fix dead frontend variables~~ **Done**: no `window.HCP_*` globals remain;
  presets come from bootstrap `S.theme_presets`. ~~Default critical package
  rules~~ **Done**: both installers write a reviewed 11-package floor into the
  root-only broker rules file (never `{}`) and preserve owner edits.
- ~~Update `panel-browser-verify`’s live count~~ **Done**: 835 live count and a
  dedicated dock/fullscreen/mic pass; plus a new `deck-browser-selftest` tool
  that walks every page in a real headless browser (`docs/SELFTEST-BROWSER.md`).
- Every phase ends with: local suite → live suite → Deck integration → push to
  Forgejo → `deploy/install.sh` → GitHub mirror → docs update. Never deploy
  before the Forgejo push.

**Acceptance criteria**
- A new session can understand the whole system from `README.md` +
  `docs/ROADMAP.md` + `docs/ARCHITECTURE.md` alone.
- No documented command silently depends on a secret being printed.

---

## 5. Risk register

| Risk | Mitigation |
|---|---|
| Upstream Hermes Desktop changes and breaks our patch | Commit is pinned; patch is 58 lines; `bootstrap.sh check` refuses unpinned builds. Upgrades are a deliberate ref + patch review. |
| Fork accidentally consumes Nous updates | Identity patch sets `channel: null`; app IDs are ours. |
| Two gateways fight over 8642/9119 | Compose `hermes` service stays profile-gated; desktop is remote-only; integration test checks one listener. |
| AI changes UI into something unsafe | Data-only manifests, schema validation, diff + approval, atomic apply + rollback, no eval/raw CSS. |
| Mic button promises a feature that is not installed | `stt.status` disables it with an honest tooltip until a model exists. |
| Git history becomes the only backup | `tools/backup.sh` plus documented restore drill. |
| Single-user code later needs tenancy | Keep `owner` on projects and tokens scoped to a profile; no UI work for multi-user today. |

---

## 6. Quick status glossary

- **Done** = code committed, tests green, evidence in this repo.
- **Built, not verified** = code exists but no browser/package smoke test yet
  (desktop app today).
- **Planned** = described here, not written yet.
- **Stub** = honest screen that explains why no safe data source exists.

Current phase: **Phase 1 — Desktop app first.** Everything after it is ordered
but intentionally unstarted so the system stays simple to reason about.
