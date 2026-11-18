#!/bin/sh
# The host HMAC key is root-only on the Deck. Copy it into a container-local
# location owned by the PHP-FPM user (uid 1000) so the backend can sign
# privileged host actions, while never exposing it through the web root.
set -eu
mkdir -p /run/secrets
if [ -f /run/hostctl-src/hostctl.key ]; then
  install -o 1000 -g 1000 -m 0400 /run/hostctl-src/hostctl.key /run/secrets/hostctl.key
else
  echo "entrypoint: no hostctl key mounted (host actions will be refused)" >&2
fi
exec /usr/bin/supervisord -c /etc/supervisord.conf
