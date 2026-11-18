FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="Hermes Control Panel"
LABEL org.opencontainers.image.description="Agent control panel with vault-backed credential injection and per-agent permissions"

RUN apk add --no-cache nginx supervisor curl bash sqlite-libs tzdata \
 && mkdir -p /run/nginx /var/lib/panel /var/log/panel /var/www/html

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
  > /usr/local/etc/php-fpm.d/zz-panel.conf \
 && rm -f /usr/local/etc/php-fpm.d/www.conf.default

COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisord.conf
COPY crontab /etc/crontabs/root
COPY src/ /var/www/html/

RUN chown -R 1000:1000 /var/www/html /var/lib/panel /var/log/panel \
 && chmod 750 /var/lib/panel

EXPOSE 8080
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
