#!/bin/sh
#
# Starts PHP-FPM in the background and Apache in the foreground, the same pair
# production runs under systemd.
set -e

mkdir -p /run/php
rm -f /run/apache2/apache2.pid

# Directories Drupal writes to. The bind mounts arrive owned by the host user.
for dir in /var/www/html/sites/default/files /var/www/private; do
  if [ -d "$dir" ]; then
    chown www-data:www-data "$dir" 2>/dev/null || true
  fi
done

php-fpm8.3 --daemonize
exec apache2ctl -D FOREGROUND
