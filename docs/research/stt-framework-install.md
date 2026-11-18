# Research: local STT, Tailwind/animate.css bundling, setup wizard, safe AI-editable config, one-line installer

Scope: research only (no application source changes). Target: a modular PHP "Brezel-inspired" web control panel for the Steam Deck (AMD APU / RDNA2, 6 GB shared VRAM), deployed with **one `docker compose` containing exactly two containers: Hermes Agent + webui**, **no new systemd services**, browser microphone input, first-launch setup guide, AI-editable UI config, and a one-line installer living in a **second git repo**.

---

## 1. Local / offline speech-to-text

### 1.1 The binding constraint: two containers only

Because the compose file may contain **exactly two containers**, STT cannot be a third service. Two placements are viable:

| Placement | How | Pros | Cons |
|---|---|---|---|
| **A. Embed STT engine inside the `webui` container** (recommended) | A small local HTTP STT process supervised inside the webui image (s6-overlay or a tiny supervisor), PHP proxies `/api/stt` to it over `127.0.0.1` | Self-contained, matches "exactly two containers", no host service, works on a fresh Deck | Webui image gets a bit larger; needs CPU/GPU device tuning |
| B. Proxy to the Deck's existing host STT (`voice-daemon.service` / faster-whisper) from the webui container via `extra_hosts: host.docker.internal:host-gateway` | Zero new processes; reuse ~1.9 GiB-VRAM-resident faster-whisper already on the host | No new service, small webui image | Not self-contained; breaks on a Deck without that service; couples to host state |

**Recommendation: A**, with an optional fallback mode to B for power users who already run the host daemon. Do not add a systemd unit.

### 1.2 Web Speech API — why it is not the answer

- Chrome/Edge stream mic audio to **Google's cloud** by default; Safari to Apple's recognizer. Firefox keeps audio on device but SpeechRecognition support is uneven/behind flags. SpeechRecognition is still a W3C *Draft Community Group Report*.
- On-device mode exists: `SpeechRecognition.processLocally = true` (Chrome 139+, Aug 2025) plus a one-time language-pack download (`SpeechRecognition.available()` / `.install()`); Firefox 160+ is on-device by default. But a Deck in Desktop Mode typically runs Chrome/Chromium, and there is **no guarantee the pack is installed or that the browser is new enough**; if the pack is missing, `start()` throws `language-not-supported` / `service-not-allowed`.
- It is browser-dependent, non-self-hostable, and the default path leaks voice to third parties. **Reject as the primary path**; it is acceptable only as an opt-in progressive-enhancement fallback with `processLocally = true` and an explicit privacy notice.
- Sources: https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API/Using_the_Web_Speech_API ; https://webaudio.github.io/web-speech-api/ ; https://micdrop.dev/blog/web-speech-api

### 1.3 Engine comparison

| Engine | Runtime | AMD Deck GPU path | CPU path | Footprint | Verdict for embedding |
|---|---|---|---|---|---|
| **whisper.cpp** | C/C++, GGML quantized models | Vulkan works on Deck (RADV); `deck-dictate` proves ~2 s latency on back-button dictation, ~550 MB `ggml-large-v3-turbo-q5_0.bin`; 5–7 s under heavy game GPU load | Excellent; q5/k-quants, tiny binary, fast load (~0.08–0.26 s) | binary ~50 MB, base q5 model ~90 MB, small q5 ~160 MB | **Best for a self-contained container** |
| **faster-whisper** (CTranslate2) | Python | **No native ROCm/Vulkan** in upstream CTranslate2 — community ROCm forks exist but are huge (68 GB image in one project) and fragile | int8 on CPU is strong (base first segment ~0.41 s, small ~1.0 s on 8 vCPU) | Python+CT2 ~200 MB; base ~145 MB, small ~484 MB, large-v3-turbo ~1.6 GB int8 | Good CPU-only alternative; easy OpenAI-compatible HTTP via `speaches` |
| `speaches` (faster-whisper + Kokoro/Piper server) | Python FastAPI | CPU image `ghcr.io/speaches-ai/speaches:0.8.3-cpu`; OpenAI-compatible `/v1/audio/transcriptions`, SSE streaming + WebSocket live STT, dynamic model load/unload | Yes, int8 | ~750 MB CPU image; ~2 GB disk for models | Best ready-made HTTP server, but would be a 3rd container unless embedded |
| `hwdsl2/whisper-live-server` (WhisperLive) | Python | CPU only in default image | int8 | ~750 MB; WS :9090 + REST :8000 | Heavy for a control panel |

Key measured numbers (be honest that hardware differs from the Deck):
- faster-whisper README (13-min clip, RTX 3070 Ti / i7-12700K): GPU large-v2 int8 **59 s** vs fp16 63 s; CPU small int8 **1m42s** vs reference Whisper 6m58s; CPU small fp32 2m37s. https://github.com/SYSTRAN/faster-whisper
- OpenVoiceIndex x86 CPU test (10.1 s clip, beam 5): base int8 first segment **0.41 s**, peak RAM 443 MB; small int8 **1.02 s**, 990 MB; whisper.cpp base fp16 first segment 0.79 s but loads in 0.26 s. https://openvoiceindex.com/compare/faster-whisper-vs-whisper-cpp/
- Model memory: tiny ~75 MB / ~290 MB RAM int8; base ~145 MB / ~443 MB; small ~484 MB / ~990 MB; large-v3-turbo ~1.6 GB int8. https://hyprwhspr.com/docs/backends/faster-whisper/
- CTranslate2 upstream has **no Vulkan/ROCm** support → on AMD GPUs faster-whisper falls back to CPU unless a community ROCm fork is used. https://hyprwhspr.com/docs/backends/faster-whisper/ and https://github.com/Donkey545/wyoming-faster-whisper-rocm

### 1.4 Existing Deck / local setup (observed)

- The production Deck already runs Hermes (`nousresearch/hermes-agent:latest`) at `/opt/hermes`, s6-supervising gateway + dashboard in **one** container, ports 8642 (OpenAI-compatible API) and 9119 (dashboard). `extra_hosts: host.docker.internal:host-gateway` is how it reaches the host's `opencode-api` wrapper on :4097. Source: `hermes-deck` toolbox reference (verified 2026-11-18).
- This machine's global instructions document a `voice-daemon.service` running **faster-whisper STT at ~1.9 GiB VRAM**, which the local-LLM router pauses while a whole-card llama profile is resident and resumes on unload. A performance note measured `stt_fwhisper.py` at 44% CPU / 2.2 GB RSS / 2.0 GB VRAM. Local voice loop in the `orchestra` project uses whisper.cpp for STT + piper for TTS (`~/.local/share/orchestra/.../whisper-cli`), entirely offline.
- A local `ggml-large-v3-turbo-q5_0.bin` (~574 MB) already exists at `/home/timo/.local/share/wayvr/whisper/` — a ready model source for a whisper.cpp-based embed.
- `mariojankovic/deck-dictate` (MIT) is the closest prior art: push-to-talk on Deck back buttons, **whisper.cpp built with Vulkan inside a distrobox container**, model stays loaded, ~2 s text latency, ~550 MB turbo q5 model, mic open only while held. It installs a `systemd --user` service — we will copy the architecture, **not** the service. https://github.com/mariojankovic/deck-dictate

### 1.5 Concrete recommended STT stack

**Embed whisper.cpp (Vulkan build) + a tiny HTTP wrapper inside the `webui` container.**

- Base image for webui: `php:8.3-fpm-alpine` (or `php:8.3-apache`) multi-stage build; install build deps in a builder stage, copy only the static `whisper-cli`/`whisper-server` binary into the runtime stage. Use whisper.cpp's own HTTP server mode (`whisper-server`, exposed on `127.0.0.1:8713` inside the container) or a ~40-line Python `http.server` wrapper around `whisper-cli -f <wav> -otxt` if a simpler contract is wanted.
- Model: start with **`ggml-small.en-q5_0.bin` (~160 MB)** for English, or `ggml-base.en-q5_0.bin` (~90 MB) if CPU-only Deck and fast response matter more; the already-cached `ggml-large-v3-turbo-q5_0.bin` (~574 MB) is an optional "high accuracy" profile selectable in setup. Multilingual `ggml-small-q5_0.bin` if non-English is required. Ship the model as a Docker build-time download (pinned SHA256) or a named volume seeded on first launch — never at runtime from an arbitrary URL.
- Devices/decoding: `--device vulkan` with `-ngl 99` when `/dev/dri` is available (Deck RDNA2 + RADV); fall back to CPU (`-t 4`) otherwise. Cap threads to 4 to leave headroom for Hermes + PHP + the Deck compositor. Do **not** compete with a resident local-LLM profile in VRAM — prefer CPU int8-style quant when the LLM router is loaded.
- Browser → server flow: `getUserMedia` → `MediaRecorder('audio/webm;codecs=opus')` (or `AudioContext` → 16 kHz mono PCM → WAV for deterministic decoding) → `POST /api/stt` on the PHP app with `multipart/form-data` (`file`, `language`, optional `model`) → PHP validates size/type, writes a temp file, invokes the local engine (or proxies to `127.0.0.1:8713`) → JSON `{"text": "...", "duration": ..., "engine": "whisper.cpp"}`. Keep the mic open only during push-to-talk; release tracks on stop. Never expose an API key to the browser; the engine is loopback-only. If live partial captions are wanted, add WebSocket streaming, but batch push-to-talk is simpler and matches a control panel.
- Latency expectation: 2–5 s of speech → roughly 0.5–2 s transcription with `base`/`small` q5 on Deck CPU; Vulkan turbo ~2 s observed under `deck-dictate`. Set UI expectation accordingly; show a "transcribing…" state.
- Privacy: audio never leaves the Deck; no cloud API, no keys, no telemetry. Bind STT loopback-only, log only durations/errors (never audio or transcripts by default). Add a first-launch disclosure and a "delete cached audio" control.
- If the team prefers Python and CPU-only simplicity: pin `ghcr.io/speaches-ai/speaches:0.8.3-cpu` **inside** the webui container's supervisor (not as a third service), preload `Systran/faster-whisper-small`, call `/v1/audio/transcriptions` with `language=en`; `WHISPER__COMPUTE_TYPE=int8`, `WHISPER__TTL=-1`. Source: https://github.com/speaches-ai/speaches , https://speaches.ai/ , https://peteroneilljr.com/blog/self-hosting-speech-stt-tts-one-container/

---

## 2. Tailwind CSS + animate.css in a PHP app, no npm on target

### 2.1 Recommendation

**Compile at development/CI time and commit the built CSS into the repo.** The Deck installer then only copies files — no Node, no npm, no build on target. Ship `animate.css` as a vendored local file (not a CDN) so the panel works fully offline.

- Tailwind v4 standalone CLI (single static binary, no Node): download `tailwindcss-linux-x64` from a **pinned** GitHub release, `chmod +x`. `src/input.css`: `@import "tailwindcss"; @source "../templates/**/*.php";` (v4 auto-detects sources; `@source` narrows it). Build: `./tailwindcss -i src/input.css -o public/assets/app.css --minify`. https://tailwindcss.com/blog/standalone-cli , https://tailwindcss.com/docs/installation/tailwind-cli , https://github.com/tailwindlabs/tailwindcss/discussions/15855
- Pure-PHP alternative worth considering because the stack is already PHP and Composer-based: **`tailwindphp/tailwindphp`** (`composer require tailwindphp/tailwindphp`), `./vendor/bin/tailwindphp -i src/app.css -o public/assets/app.css -m`; tracks Tailwind 4.3.3, scans `php/html/twig/blade.php`, no Node at all, PHP 8.2+. https://tailwindphp.inline0.dev/docs/cli/
- Caveat: the standalone CLI cannot load external PostCSS plugins; TailwindPHP cannot use JS plugins (ported forms/typography only). For a control panel this is fine.
- `animate.css`: `composer require animate-css/animate.css` (or vendor `animate.min.css` from https://animate.style/ into `public/assets/vendor/`). Reference it locally: `<link rel="stylesheet" href="/assets/vendor/animate.min.css">` before/after the Tailwind build. Pin version + checksum.

### 2.2 Build workflow (no npm on target)

```
dev/CI machine (has the pinned binary or Composer):
  tools/tailwindcss -i src/input.css -o public/assets/app.css --minify
  (animate.css already committed in public/assets/vendor/)
  -> commit public/assets/app.css
Deck/target:
  installer copies repo; PHP serves public/assets/* with long cache headers.
  Optional on-target rebuild only if config changes theme tokens:
    php tools/build-css.php   # calls TailwindPHP library, or ships a pinned standalone binary
```

Themes: implement light/dark and rounded/square as CSS custom properties on `html[data-theme]` / `data-radius` classes generated by Tailwind's `@theme`, toggled by a tiny JS snippet that persists the choice in the config. Because both variants are compiled up front, no runtime CDN is needed. Source for the pattern: https://www.mironsoft.de/en/blog/tailwind-standalone-cli-without-nodejs-npm , https://www.geeksforGeeks.com/php/installation-setup-guide-of-tailwind-css-with-php/ (Method 3).

Do **not** use the Tailwind Play CDN in production — Tailwind's docs explicitly say it is for development only.

---

## 3. First-launch setup wizard + safe AI-editable UI configuration

### 3.1 Setup wizard pattern

Follow the well-established PHP wizard shape: an `/install` (or `/setup`) route gated by a lock file / `SETUP_COMPLETE` flag, with middleware redirecting every other route until done. Steps (adapted from Craft CMS, Laravel installer packages, and Lerd):

1. **Welcome + requirements checklist** — PHP version/extensions, writable dirs, Docker reachable, STT model present, ports free.
2. **Environment / secrets** — write `.env` with generated secrets (never print them), base URL, STT mode (embedded engine vs reuse host), language, theme, rounded corners.
3. **Services sanity check** — confirm Hermes container API answers (`/v1/models`) using the API key from `.env`; no secrets in browser output.
4. **Create first admin user** (or accept Hermes dashboard basic-auth creds).
5. **Optional seed/sample tiles**, then **Done** → write the lock file and redirect to the dashboard.

Make every step idempotent (`--if-not-exists`, skip if already configured) and support a non-interactive/unattended mode driven by env vars. Sources: https://craftcms.com/knowledge-base/first-time-setup , https://github.com/zisunal/laravel-installer , https://github.com/mlanin/laravel-setup-wizard , https://github.com/nowo-tech/SiteBackupBundle/blob/main/docs/SETUP-WIZARD.md , https://lerd.sh/features/project-setup , https://github.com/enlivenapp/CodeIgniter4-Flexible-Web-App-Installer

### 3.2 Safe AI-editable config

Use a **transaction + governance pipeline** rather than letting the AI write config directly. Concrete pattern (from `guarded-local-config-editor`, `cfgov-cli`, Geneclaw, safe-migrations-mcp):

```
AI proposes JSON transaction (set/delete/append on explicit JSON Pointer paths)
  -> server-side PREVIEW: diff + resulting config + SHA-256, NO mutation
  -> validate against JSON Schema + path/op allowlist + deny list + value-size caps
  -> human approval (explicit phrase / button / ticket) OR role gate; AI can never invent approval
  -> checkpoint current config (atomic write with backup), apply atomically
  -> append-only audit log (who, what hash, when, outcome) — fingerprints only, never secret values
  -> versioned history + one-command rollback
```

Rules worth copying verbatim:
- **Allowlist by default**: only keys such as `theme`, `radius`, `tile_order`, `labels`, `stt.model` are editable; deny `.env`, `secrets/`, `*.key/*.pem`, DB files, migrations, auth rules. Keep secrets and schema definitions out of AI config entirely.
- **Dry-run is the default state**; `apply` requires an explicit flag. Risk tiers: R0 reads, R1 ordinary writes, R2 destructive, R3 governance-control changes, with escalation in protected contexts. `cfgov-cli` notes OS-identity-based audit and that AI and human under the same OS account still need an external approval mechanism.
- Named **profiles** rather than loose knobs (e.g. `profile: dark-rounded-compact`) make a change reviewable as "release a behavior" instead of "flip a value".
- For the panel: store config as `config/site.json` + `config/site.schema.json` (draft 2020-12), keep `config/history/<timestamp>.json`, expose `/api/config/preview`, `/api/config/apply` (approval required), `/api/config/rollback`, and render the diff in the UI.

Sources: https://github.com/YURII-YURII86/guarded-local-config-editor , https://github.com/JiangHe12/cfgov-cli , https://geneclaw.ai/safety , https://github.com/possibly6/safe-migrations-mcp , https://www.featbit.co/blogs/ai-config-management , https://docs.preloop.ai/guide/concepts/policy-as-code/

---

## 4. One-line installer in a second git repo (exactly two containers)

### 4.1 Pattern

Publish `https://raw.githubusercontent.com/<org>/<installer-repo>/<TAG>/install.sh` and document:

```
curl -fsSL https://raw.githubusercontent.com/<org>/hermes-panel-installer/v1.0.0/install.sh | bash
# review-first variant, documented equally prominently:
curl -fsSL .../install.sh -o install.sh && less install.sh && bash install.sh
# args: curl ... | bash -s -- --port 8080 --yes
```

The installer's honest job is a thin wrapper over Docker Compose: detect Docker + compose plugin (abort with clear remediation, do not bootstrap another package manager), clone the app repo or fetch a pinned release tarball, generate `.env`, write/refresh `docker-compose.yml`, `docker compose up -d`, wait for health, print the URL and an uninstall/re-run hint. **The compose file it writes must contain exactly two services: `hermes` and `webui`.** https://hub.decision.ai/skills/microsoft/one-line-installer-patterns

### 4.2 Safety / idempotency / no-secret-printing checklist

- `set -euo pipefail`, `main "$@"` called on the last line (truncated download cannot run half an install), `mktemp -d` + `trap cleanup EXIT`. https://humanly.is/ideas/curl-sh-done-carefully
- Use `curl -fsSL` (`-f` stops a 404 HTML page being executed), HTTPS only, pin the script URL to a tag, offer a checksum/signature as available; document that `curl|bash` cannot provide strong supply-chain guarantees. https://stackharden.com/guides/curl-bash-wrong-default/ , https://osec.one/articles/pin-install-script-sha256-instead-of-curl-pipe-shell
- **Idempotent**: if `.env` exists, keep it (only append newly required keys); re-running must not rotate secrets. Refresh compose/code but preserve data volumes. Provide explicit `--upgrade`/`--reconfigure`/`--status` flags rather than a wizard inside the pipe. https://github.com/agent37-platform/deploy-openclaw , https://github.com/praxi-labs/phylax-subnet/blob/1973d7d7/scripts/install.sh , https://docs.getgeolens.com/guides/quickstart/
- **Env generation**: `openssl rand -hex 32` (fallback `od -vN 32 -An -tx1 /dev/urandom | tr -d ' \n'`) for each independent secret — `HERMES_API_KEY`, `HERMES_ADMIN_PASSWORD`, `HERMES_SESSION_SECRET`, any webui session key. Set distinct values; write `.env` with `umask 077` / `chmod 600`; never `echo` secret values, never include them in `set -x` output, URLs, or logs — log **key names only**. Print a single line "secrets written to <path> (chmod 600)" and how to view credentials deliberately. https://github.com/HeyPuter/puter/blob/main/doc/self-hosting.md , https://rakazo.com/docs/self-host-secrets/
- Detect the Deck's quirks: Docker socket needs root/`docker` group (the `deck` user cannot talk to it on the production Deck), so either run with sudo or add the user to `docker` deliberately; set `extra_hosts: ["host.docker.internal:host-gateway"]` if webui must reach host services; keep data dirs owned by the image's uid (Hermes uses uid 10000). Never create a systemd unit — `restart: unless-stopped` in compose is the only supervisor needed.
- Use `${VAR:?Set VAR in .env}` in compose so missing secrets fail closed at validate time. Verify at the end with health checks (`docker compose ps`, `curl -fsS http://127.0.0.1:<port>/health`) and print "ready" plus the next command, not the credentials.
- The second repo should contain only `install.sh`, a pinned `docker-compose.yml` template, `.env.example`, and a README — no app code, no secrets.

---

## 5. Sources index

- STT: https://github.com/SYSTRAN/faster-whisper , https://openvoiceindex.com/compare/faster-whisper-vs-whisper-cpp/ , https://hyprwhspr.com/docs/backends/faster-whisper/ , https://github.com/speaches-ai/speaches , https://speaches.ai/ , https://peteroneilljr.com/blog/self-hosting-speech-stt-tts-one-container/ , https://github.com/hwdsl2/docker-whisper-live/ , https://github.com/mariojankovic/deck-dictate , https://github.com/Donkey545/wyoming-faster-whisper-rocm , https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API/Using_the_Web_Speech_API , https://webaudio.github.io/web-speech-api/ , https://micdrop.dev/blog/web-speech-api
- CSS: https://tailwindcss.com/blog/standalone-cli , https://tailwindcss.com/docs/installation/tailwind-cli , https://github.com/tailwindlabs/tailwindcss/discussions/15855 , https://tailwindphp.inline0.dev/docs/cli/ , https://animate.style/
- Wizard/config: https://craftcms.com/knowledge-base/first-time-setup , https://github.com/zisunal/laravel-installer , https://github.com/mlanin/laravel-setup-wizard , https://github.com/nowo-tech/SiteBackupBundle/blob/main/docs/SETUP-WIZARD.md , https://lerd.sh/features/project-setup , https://github.com/YURII-YURII86/guarded-local-config-editor , https://github.com/JiangHe12/cfgov-cli , https://geneclaw.ai/safety , https://github.com/possibly6/safe-migrations-mcp , https://www.featbit.co/blogs/ai-config-management
- Installers: https://github.com/HeyPuter/puter/blob/main/doc/self-hosting.md , https://github.com/agent37-platform/deploy-openclaw , https://docs.getgeolens.com/guides/quickstart/ , https://github.com/praxi-labs/phylax-subnet/blob/1973d7d7/scripts/install.sh , https://humanly.is/ideas/curl-sh-done-carefully , https://stackharden.com/guides/curl-bash-wrong-default/ , https://osec.one/articles/pin-install-script-sha256-instead-of-curl-pipe-shell , https://bettercli.org/design/distribution/self-executing-installer/ , https://rakazo.com/docs/self-host-secrets/

Local sources consulted: `hermes-deck` toolbox reference; `local-llm` toolbox entry; `/home/timo/.config/opencode/AGENTS.md`; `/home/timo/.config/opencode/memory/pc-perf-now.md`; `/home/timo/.config/opencode/orchestra/src/voice.rs` and `README.md`; existing model at `/home/timo/.local/share/wayvr/whisper/ggml-large-v3-turbo-q5_0.bin`.

**Bottom line:** whisper.cpp (Vulkan/CPU, `small.en-q5_0`, HTTP on loopback inside the webui container) + committed Tailwind v4 standalone build with a vendored animate.css + gated step wizard + JSON-Schema/allowlist/preview/approval/audit config transactions + a pinned `curl -fsSL ... | bash` installer from a second repo that keeps exactly two compose services and never prints secrets.
