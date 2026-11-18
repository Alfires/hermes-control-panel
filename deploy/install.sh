#!/bin/bash
# Install / update the Hermes Control Panel on the openhost Steam Deck.
# Run as root ON THE DECK. Secrets never leave the Deck and are never printed.
set -euo pipefail

REPO_URL="${REPO_URL:-http://127.0.0.1:3001/Alfires/hermes-control-panel.git}"
DIR=/opt/hermes-control-panel
SECRETS=/opt/panel-secrets
LLM_ENV=/home/deck/opencode-api/api.env

say() { echo "[panel] $*"; }

mkdir -p "$SECRETS" "$DIR/data"
chmod 700 "$SECRETS"
# The container's PHP workers run as uid 1000 (deck); SQLite lives here.
chown 1000:1000 "$DIR/data"
chmod 770 "$DIR/data"

# 1. source (clone once, then update)
if [ -d "$DIR/.git" ]; then
  say "updating source"
  git -C "$DIR" fetch -q origin
  git -C "$DIR" reset --hard -q origin/main
else
  say "cloning source from local Forgejo"
  TMP=$(mktemp -d /opt/.hcp-clone.XXXXXX)
  git clone -q "$REPO_URL" "$TMP"
  # The directory may already hold the root-only Forgejo token or panel data.
  shopt -s dotglob
  cp -a "$TMP"/. "$DIR"/
  shopt -u dotglob
  rm -rf "$TMP"
fi

# 2. root-only HMAC key for host actions
if [ ! -s "$SECRETS/hostctl.key" ]; then
  umask 077
  openssl rand -hex 32 > "$SECRETS/hostctl.key"
  chmod 600 "$SECRETS/hostctl.key"
  say "generated host-control HMAC key"
else
  chmod 600 "$SECRETS/hostctl.key"
fi

# 3. panel environment (owner login + local model front door)
TOKEN=$(grep -E '^OPENCODE_API_TOKEN=' "$LLM_ENV" | head -1 | cut -d= -f2- | tr -d "\"'\r")
[ -n "$TOKEN" ] || { echo "could not read OPENCODE_API_TOKEN from $LLM_ENV" >&2; exit 1; }
if [ ! -s "$DIR/panel.env" ]; then
  ADMIN_PASS=$(openssl rand -hex 12)
  umask 077
  cat > "$DIR/panel.env" <<EOF
PANEL_ADMIN_USER=admin
PANEL_ADMIN_PASSWORD=$ADMIN_PASS
PANEL_LLM_URL=http://host.docker.internal:4097/v1/chat/completions
PANEL_LLM_KEY=$TOKEN
VAULT_SOCKET=/run/vault-broker.sock
HOSTCTL_SOCKET=/run/panel-host.sock
PANEL_HOST_KEY=/run/secrets/hostctl.key
EOF
  chmod 600 "$DIR/panel.env"
  say "created panel.env (owner password is stored only there)"
else
  chmod 600 "$DIR/panel.env"
fi

# 4. root-owned host-control broker
chmod 755 "$DIR/hostctl/hostctl.py"
install -o root -g root -m 0644 "$DIR/hostctl/panel-hostctl.service" /etc/systemd/system/panel-hostctl.service
echo '{}' > "$SECRETS/critical-packages.json"
systemctl daemon-reload
systemctl enable --now panel-hostctl >/dev/null
for i in $(seq 1 20); do
  [ -S /run/panel-host.sock ] && break
  sleep 0.5
done
[ -S /run/panel-host.sock ] || { echo "host broker socket did not appear" >&2; exit 1; }
say "host-control broker: $(systemctl is-active panel-hostctl)"

# 5. single container - force recreate so the socket mounts are the real files
cd "$DIR"
docker compose up -d --build --force-recreate >/dev/null
say "container: $(docker inspect -f '{{.State.Status}}' hermes-panel)"

say "panel:  http://192.168.2.157:9120  (also on the Tailscale IP)"
say "login:  admin / (password in $DIR/panel.env)"
say "done"
