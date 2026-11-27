#!/bin/bash
#
# Local equivalent of scripts/deploy-manual.sh: builds the user manual and
# publishes it inside the web container, in the same two places as on the
# server:
#
#   build/manual             -> /var/www/html/manual          (static, public)
#   build/manual-backoffice  -> /var/www/manual-backoffice    (outside the
#                               docroot; Drupal serves it at /manual/backoffice/
#                               to back-office accounts only, SPEC 133)
#
# Same steps as the real one, with `docker compose exec` instead of SSH and no
# upload: the build output is already visible in the container through the
# module mount.

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

MODULE="/var/www/html/sites/all/modules/myapi"
DEST="/var/www/html/manual"
BO_DEST="/var/www/manual-backoffice"

"$REPO/scripts/build-manual.sh"

echo "Publicando en $DEST y $BO_DEST (contenedor web)..."

dc exec -T -u root web bash -s <<REMOTE
set -e
# Root-owned and read-only for the web server, like on the server.
harden() {
  chown -R root:root "\$1" 2>/dev/null || true
  find "\$1" -type d -exec chmod 755 {} +
  find "\$1" -type f -exec chmod 644 {} +
}
# Atomic swap: build a .new copy, then one mv.
publish() { # <built dir> <destination>
  rm -rf "\$2.new" "\$2.old"
  cp -r "\$1" "\$2.new"
  harden "\$2.new"
  if [ -d "\$2" ]; then mv "\$2" "\$2.old"; fi
  mv "\$2.new" "\$2"
  rm -rf "\$2.old"
}
# $BO_DEST is a mount point here, so it cannot be renamed: its contents are
# replaced instead.
publish_into_mount() { # <built dir> <destination>
  find "\$2" -mindepth 1 -delete
  cp -r "\$1"/. "\$2"/
  harden "\$2"
}
publish "$MODULE/build/manual" "$DEST"
publish_into_mount "$MODULE/build/manual-backoffice" "$BO_DEST"
# SPEC 133: the back-office manual must never be reachable as static files.
test ! -e "$DEST/backoffice"
REMOTE

echo "Manual publicado: http://localhost:$WEB_PORT/manual/"
echo "Manual del back office (requiere sesión de back office): http://localhost:$WEB_PORT/manual/backoffice/"
