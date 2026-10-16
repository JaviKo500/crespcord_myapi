#!/bin/bash
#
# Builds the user manual and publishes it as static files at
# https://crespcord.lamotora.com/manual/ (/var/www/html/manual on the server).
#
# Independent of scripts/deploy.sh: the manual is not part of the myapi module
# and needs no drush. The new tree replaces the old one in a single mv, so
# visitors never see a half-uploaded manual and screenshots removed from
# manual_app/ disappear from the server too.
#
# The back-office manual (build/manual-backoffice/) is NOT published as static
# files (SPEC 133). It goes to $BO_DEST, outside the document root, and Drupal
# serves it at /manual/backoffice/ to back-office accounts only
# (includes/myapi.backoffice_manual.inc). Replacing $DEST whole also removes any
# /manual/backoffice/ left there by an earlier deploy.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
SERVER="ubuntu@crespcord.lamotora.com"
DEST="/var/www/html/manual"
TMP="manual_upload"
BO_DEST="/var/www/manual-backoffice"
BO_TMP="manual_backoffice_upload"

"$SCRIPT_DIR/build-manual.sh"

echo "Subiendo el manual al servidor..."

ssh -i "$KEY" "$SERVER" "rm -rf ~/$TMP ~/$BO_TMP"
scp -i "$KEY" -q -r "$SRC/build/manual" "${SERVER}:~/$TMP"
scp -i "$KEY" -q -r "$SRC/build/manual-backoffice" "${SERVER}:~/$BO_TMP"

echo "Publicando en $DEST y $BO_DEST..."

# Same atomic swap for both trees: build a .new copy, then one mv each.
ssh -i "$KEY" "$SERVER" "
  set -e
  publish() { # <uploaded dir> <destination>
    sudo rm -rf \$2.new \$2.old
    sudo cp -r \$1 \$2.new
    # Owned by root and read-only for Apache: unlike the module, nothing here
    # needs to be written by PHP, so a compromised www-data cannot alter it.
    sudo chown -R root:root \$2.new
    sudo find \$2.new -type d -exec chmod 755 {} +
    sudo find \$2.new -type f -exec chmod 644 {} +
    if [ -d \$2 ]; then sudo mv \$2 \$2.old; fi
    sudo mv \$2.new \$2
    sudo rm -rf \$2.old \$1
  }
  publish ~/$TMP $DEST
  publish ~/$BO_TMP $BO_DEST
  # SPEC 133: the back-office manual must never be reachable as static files.
  test ! -e $DEST/backoffice
"

echo "Manual publicado: https://crespcord.lamotora.com/manual/"
echo "Manual del back office (requiere sesión de back office): https://crespcord.lamotora.com/manual/backoffice/"
