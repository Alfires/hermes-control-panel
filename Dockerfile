# ---------------------------------------------------------------------------
# Build-only stage: whisper.cpp v1.7.6 HTTP server + ggml-small.en q5_0 model.
# The compiler, cmake, source tree and the 465 MiB F16 download stay in this
# stage; only /opt/stt reaches the runtime image. No third container is used.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS stt-build

ARG WHISPER_VERSION=v1.7.6
# Digest we computed for the pinned GitHub tag tarball. GitHub does not publish
# a checksum for generated archives; the MODEL checksum below is a genuinely
# published one (Hugging Face LFS oid) and is the important supply-chain pin.
ARG WHISPER_SRC_SHA256=166140e9a6d8a36f787a2bd77f8f44dd64874f12dd8359ff7c1f4f9acb86202e
ARG WHISPER_MODEL_REV=5359861c739e955e79d9a303bcbc70fb988958b1
ARG WHISPER_MODEL_SHA256=c6138d6d58ecc8322097e0f987c32f1be8bb0a18532a3f88f734d1bbf9c41e5d

# ggml/CMakeLists.txt calls find_program(GIT_EXE) unconditionally, so git must
# be installed at configure time even though we build from a tarball, not a clone.
# BUILD_SHARED_LIBS=OFF is required: the default shared build leaves
# whisper-server linked against libwhisper.so.1 / libggml.so, which do not
# survive COPY into the runtime image (supervisord then reports symbol errors
# and STT stays down). Verified with ldd on the Deck, 2026-11-19.
RUN apk add --no-cache build-base cmake curl tar git \
 && mkdir -p /tmp/whisper-src /opt/stt/models /opt/stt/baked /opt/stt/public \
 && curl -fsSL --retry 3 "https://github.com/ggml-org/whisper.cpp/archive/refs/tags/${WHISPER_VERSION}.tar.gz" -o /tmp/whisper-src.tar.gz \
 && echo "${WHISPER_SRC_SHA256}  /tmp/whisper-src.tar.gz" | sha256sum -c - \
 && tar xzf /tmp/whisper-src.tar.gz -C /tmp/whisper-src --strip-components=1 \
 && cmake -S /tmp/whisper-src -B /tmp/whisper-src/build -DCMAKE_BUILD_TYPE=Release -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_SERVER=ON -DBUILD_SHARED_LIBS=OFF \
 && cmake --build /tmp/whisper-src/build --target whisper-server quantize -j"$(nproc)" \
 && install -m 0755 /tmp/whisper-src/build/bin/whisper-server /opt/stt/whisper-server \
 && curl -fL --retry 3 "https://huggingface.co/ggerganov/whisper.cpp/resolve/${WHISPER_MODEL_REV}/ggml-small.en.bin" -o /tmp/ggml-small.en.bin \
 && echo "${WHISPER_MODEL_SHA256}  /tmp/ggml-small.en.bin" | sha256sum -c - \
 && /tmp/whisper-src/build/bin/quantize /tmp/ggml-small.en.bin /opt/stt/baked/ggml-small.en-q5_0.bin q5_0 \
 && ln -s ../baked/ggml-small.en-q5_0.bin /opt/stt/models/ggml-small.en-q5_0.bin \
 && rm -f /tmp/ggml-small.en.bin /tmp/whisper-src.tar.gz \
 && rm -rf /tmp/whisper-src

# ---------------------------------------------------------------------------
# Runtime image: the original panel image plus the STT artefacts.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="Hermes Control Panel"
LABEL org.opencontainers.image.description="Agent control panel with vault-backed credential injection and per-agent permissions"

# ffmpeg lets whisper-server --convert turn browser webm/opus uploads into the
# 16 kHz mono WAV it expects. libstdc++ is the whisper-server C++ runtime.
RUN apk add --no-cache nginx supervisor curl bash sqlite-libs tzdata ffmpeg libstdc++ \
  && mkdir -p /run/nginx /var/lib/panel /var/log/panel /var/www/html

# The model bytes live in /opt/stt/baked and /opt/stt/models holds a symlink:
# docker-compose bind-mounts a read-only host dir over /opt/stt/models, which
# would otherwise hide an image-baked model. The symlink keeps the documented
# STT::modelDir() path working; supervisord falls back to /opt/stt/baked.
COPY --from=stt-build /opt/stt/ /opt/stt/

# PHP-FPM runs as the Deck's uid/gid so it can reach root-owned brokers whose
# socket group is deck (1000). clear_env keeps PANEL_* variables available.
RUN printf '%s\n' \
  '[www]' \
  'user = 1000' \
  'group = 1000' \
  'listen = 127.0.0.1:9000' \
  'listen.owner = 1000' \
  'listen.group = 1000' \
  'pm = dynamic' \
  'pm.max_children = 16' \
  'pm.start_servers = 3' \
  'pm.min_spare_servers = 2' \
  'pm.max_spare_servers = 6' \
  'clear_env = no' \
  'php_admin_value[error_log] = /var/log/panel/php-error.log' \
  'php_admin_flag[log_errors] = on' \
  'php_admin_flag[display_errors] = off' \
  > /usr/local/etc/php-fpm.d/zz-panel.conf \
  && rm -f /usr/local/etc/php-fpm.d/www.conf.default

COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
COPY crontab /etc/crontabs/root
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY src/ /var/www/html/

RUN chown -R 1000:1000 /var/www/html /var/lib/panel /var/log/panel \
  && chmod 750 /var/lib/panel \
  && chmod 755 /usr/local/bin/entrypoint.sh

# Only the existing panel port. whisper-server binds 127.0.0.1:8713 in-container.
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
