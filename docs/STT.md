# Local speech-to-text (whisper.cpp)

The Voice view talks to a **single loopback HTTP engine inside the panel image**.
There is no cloud STT provider, no API key and no third container: the two
services remain `hermes` (standalone profile) and `webui` (the panel image).

## What is installed at image-build time

| Artefact | Path | Origin |
|---|---|---|
| whisper.cpp HTTP server | `/opt/stt/whisper-server` | v1.7.6 source tag, built only in the discarded `stt-build` stage |
| ggml-small.en q5_0 model | `/opt/stt/baked/ggml-small.en-q5_0.bin` | pinned Hugging Face F16 model, quantised locally with whisper.cpp's `quantize` |
| Documented model path | `/opt/stt/models/ggml-small.en-q5_0.bin` | relative symlink to the baked file above |
| ffmpeg | `/usr/bin/ffmpeg` (Alpine package) | lets `--convert` turn browser webm/opus into 16 kHz mono WAV |
| HTTP bind | `127.0.0.1:8713` | `/health` and `/inference`; never exposed by Docker |

Determinism and checksums:

- Source: `WHISPER_VERSION=v1.7.6` (GitHub tag) plus `WHISPER_SRC_SHA256`, a digest
  **we computed locally**. GitHub does not publish a checksum for generated
  source archives, so this pin is a reproducibility guard, not an upstream
  signature.
- Model: Hugging Face revision `5359861c739e955e79d9a303bcbc70fb988958b1`,
  file `ggml-small.en.bin`, verified against the **published LFS sha256**
  `c6138d6d58ecc8322097e0f987c32f1be8bb0a18532a3f88f734d1bbf9c41e5d`
  (487,614,201 bytes). The 465 MiB F16 download stays in the builder stage.
- Upstream does **not** publish `ggml-small.en-q5_0.bin` (only q5_1 and q8_0 for
  `small.en`), so the build produces it from the verified F16 model with
  `quantize ... q5_0`. There is no published checksum for a locally produced
  quant; its reproducibility rests on the pinned source, pinned model and
  deterministic quantiser.
- F16 is a build-time input only. The runtime image keeps the q5_0 model
  (~150 MiB), the server binary and ffmpeg; compilers, cmake, sources and the
  F16 file are discarded with the builder stage.

**Licence.** whisper.cpp is MIT licensed. The model is a quantisation of
OpenAI's Whisper `small.en` weights, which upstream distributes under the MIT
licence of the Whisper project. The image ships no separate licence file for
the weights; the MIT terms of the upstream projects apply.

## How to verify

Inside the running panel container (or through the authenticated API):

```sh
# Engine reaches "available": true once the model is resident.
curl -fsS -X POST -H "X-CSRF-Token: <token>" \
  'https://<panel>/api.php?action=stt.status'
# Expected shape (unchanged):
# {"ok":true,"available":true,"engine":true,"endpoint":"loopback only",
#  "models":["ggml-small.en-q5_0.bin"],"max_bytes":10485760}

# Direct in-container probe of the raw engine:
docker exec hermes-panel sh -c \
  'curl -fsS http://127.0.0.1:8713/health; echo'
# {"status":"ok"}  (503 {"status":"loading model"} while loading)

# Guard / supervision state:
docker exec hermes-panel sh -c 'tail -n 20 /var/log/panel/stt-err.log'
```

`stt.status` deliberately reports `engine: false` until `/health` answers HTTP
200, because whisper.cpp returns 503 with a body while still loading the model.
`available` is true only when a readable model **and** a ready engine exist.

## Browser microphone path

`src/public/app.js` already implements the capture side: `bindVoice()` fetches
`stt.status` and enables `#mic-btn` only when `available` is true. On stop it
sends the `MediaRecorder` blob as multipart field `audio` to
`/api.php?action=stt.transcribe` with the CSRF header; `api.php` caps it at
10 MiB, moves it to a temp file, and `STT::transcribe()` POSTs it to `/inference`.
`whisper-server --convert` (with ffmpeg) accepts the resulting webm/opus. Audio
is deleted after the request; only a scrubbed status row is kept in `stt_jobs`.

`getUserMedia` requires HTTPS or localhost. The public Tailscale hostname is
HTTPS, so the browser mic should be available there; a plain-HTTP LAN address
will be treated as an insecure context by the browser.

## What remains

- **No deploy was performed.** These are working-tree changes only; the image
  on the Deck has to be rebuilt and the `webui` container recreated before
  `stt.status` can report `available: true` there.
- **Browser capture is not exercised here.** The microphone path depends on a
  real browser, a real audio device and user permission; this change only makes
  the server side ready. An end-to-end push-to-talk check is still outstanding.
- The Deck's CPU has no GPU acceleration; small-en q5_0 is accurate but a
  multi-minute upload can take longer than a second. The inference timeout is
  120 s and the PHP-FPM request limit should be reviewed if longer clips are
  allowed.

## Honest caveats

- `docker-compose.yml` bind-mounts `./data/stt-models:/opt/stt/models:ro`. That
  mount hides anything baked under `/opt/stt/models`, which is why the real
  model lives in `/opt/stt/baked` and `/opt/stt/models` is a symlink. The
  supervisor checks both paths and starts with whichever is readable; a
  host-provided model in the mounted directory wins. Do not remove that
  fallback without also changing the compose mount.
- `@file_get_contents()` is used with a 2 s status timeout, 1 s connect timeout
  and a 120 s inference timeout. A hung engine fails closed (`ok:false`) rather
  than hanging the panel indefinitely.
- Error text written to `stt_jobs` is passed through `Util::scrub()`, reduced to
  one line and capped at 200 characters. Raw engine response bodies are never
  logged, and no secret values or filenames with credentials are stored.
- The guard is intentionally honest: if the binary or both model paths are
  missing, `[program:stt]` sleeps and `stt.status` reports `available:false`;
  the Voice view disables the mic instead of promising a feature that is not
  installed.
- Tests: `tests/unit/stt.php` (source contracts + pure PHP behaviour) and the
  full `tests/suite.php`. They do not need the whisper binary; they cannot prove
  runtime transcription without a rebuild and a real audio sample.
