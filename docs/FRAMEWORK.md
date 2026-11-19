# Modular website framework (PHP + JavaScript libraries)

Status: **foundation built, browser views not yet migrated**. This document is
the contract for Phase 2 of [ROADMAP.md](ROADMAP.md).

## Stack (owner decision: no TypeScript, no build step for our code)

| Layer | Choice | Why |
|---|---|---|
| HTTP router/middleware | **Slim 4** (`slim/slim`, `slim/psr7`) | Small, mature PSR-7 framework; routes are generated from the page registry. |
| Templates | **Twig 3** | Autoescaping by default; blocks are templates, not PHP strings. |
| Server interaction | **htmx 2.0.11** (vendored) | Partial page updates without an SPA build. |
| Local UI state | **Alpine.js 3.17.5** (vendored) | Small attribute-driven state; no TypeScript. |
| Runtime chat/audio | existing plain `app.js` | Sockets and MediaRecorder stay in plain JS modules. |

All dependencies are pinned in `composer.lock` and `vendor/` is committed, so
deploying on the Deck needs **no Composer, npm or CDN**. Licenses for the two JS
libraries sit next to their files in `src/public/vendor/`.

## Layout

```
composer.json / composer.lock         pinned PHP dependencies
vendor/                               committed autoloader + packages
src/framework/Kernel.php              Slim app; one route per registered page
src/framework/Renderer.php            config + registry -> Twig HTML
src/framework/BlockCatalog.php        block allowlist and typed prop schemas
src/framework/PageManifests.php       strict, AI-editable page manifest loader
src/framework/manifests/*.json        shipped declarative manifests (one per page)
src/framework/templates/layout.twig   shell used by framework-rendered pages
src/framework/templates/blocks/*.twig one template per allowed block kind
src/public/deck.php                   owner-only preview endpoint using the kernel
src/public/ui-pages.php               ui.page.* proposal/preview/approve/rollback API
tools/vendor-refresh.sh               reproduce the committed vendor directory
tests/unit/framework-render.php       render, escape, drop-unknown and route tests
tests/unit/ui-pages.php               manifest validation + approval-gated mutation tests
```

## Declarative page manifests and AI edits

`PageManifests.php` loads one JSON manifest per registered page from
`src/framework/manifests/`, validates it against the block allowlist and typed
props, and **drops** unknown block types or non-curated sources. A manifest can
carry no code, raw CSS, dynamic imports or credential-shaped strings. The
`ui.page.*` action family lets the owner preview a proposed manifest (rendered
HTML + structured diff) and, with an approved escalation, propose → approve →
rollback through the same hash-chained config revisions as `ui.config.*`.
Every write consumes a one-shot escalation grant; reads are harmless.

## Adding a page (data, not code)

1. Add an entry to `PageRegistry::builtin()` in `src/lib/registry.php` (or add a
   page object to the active config's `pages` array).
2. Give it `nav: {label, icon, group, order}` and a `blocks` array.
3. The kernel immediately serves `/<page-id>`; no router edit is needed.

## Adding a block kind

1. Add the kind and its prop schema to `BlockCatalog::SCHEMAS`.
2. Add `src/framework/templates/blocks/<kind>.twig` (optional — unknown
   templates fall back to `generic.twig`, but prop validation still applies).
3. If an AI may edit it, keep `BlockRegistry`'s `ai_editable` flag true;
   `terminalView` and `configEditor` remain human-only.

## Safety invariants

- Unknown block kinds are **dropped**, never rendered.
- Unknown or wrongly typed props are **discarded** by `BlockCatalog::validate`.
- Twig autoescaping is on; props cannot inject `<script>`, `onerror=` or raw CSS.
- CSP is set by the kernel: `default-src 'self'`, no remote scripts.
- `deck.php` requires an authenticated owner session; no secret values are
  passed into templates.
- Raw `custom_css` remains forbidden — AI edits only schema-validated tokens.

## Verify

```bash
php tests/suite.php                 # 926 cases, including framework + manifest tests
bash tools/vendor-refresh.sh        # only when intentionally upgrading pins
curl -b cookies -u ... /deck.php    # owner-only preview (local browser also works)
```

## What is NOT done yet

- The main SPA (`index.php` + `app.js`) still owns the production views.
  Migrating them block-by-block is the rest of Phase 2.
- `deck.php` currently passes empty curated data sets; it proves composition,
  not live data wiring.
- No AI proposal endpoint renders a block preview through `Renderer::block()`
  yet — that is Phase 3.
