#!/bin/bash
#
# Builds the user manual and publishes it as static files at
# https://crespcord.lamotora.com/manual/ (/var/www/html/manual on the server).
#
# Independent of scripts/deploy.sh: the manual is not part of the myapi module
# and needs no drush. The new tree replaces the old one in a single mv, so
# visitors never see a half-uploaded manual and screenshots removed from
# manual_app/ disappear from the server too.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
SERVER="ubuntu@crespcord.lamotora.com"
DEST="/var/www/html/manual"
TMP="manual_upload"

"$SCRIPT_DIR/build-manual.sh"

echo "Subiendo el manual al servidor..."

ssh -i "$KEY" "$SERVER" "rm -rf ~/$TMP"
scp -i "$KEY" -q -r "$SRC/build/manual" "${SERVER}:~/$TMP"

echo "Publicando en $DEST..."

ssh -i "$KEY" "$SERVER" "
  set -e
  sudo rm -rf $DEST.new $DEST.old
  sudo cp -r ~/$TMP $DEST.new
  # Owned by root and read-only for Apache: unlike the module, nothing here
  # needs to be written by PHP, so a compromised www-data cannot alter it.
  sudo chown -R root:root $DEST.new
  sudo find $DEST.new -type d -exec chmod 755 {} +
  sudo find $DEST.new -type f -exec chmod 644 {} +
  if [ -d $DEST ]; then sudo mv $DEST $DEST.old; fi
  sudo mv $DEST.new $DEST
  sudo rm -rf $DEST.old ~/$TMP
"

echo "Manual publicado: https://crespcord.lamotora.com/manual/"
