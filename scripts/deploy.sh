#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
SERVER="ubuntu@crespcord.lamotora.com"
DEST="/var/www/html/sites/all/modules/myapi"
TMP="~/myapi_upload"

echo "Subiendo archivos al servidor..."

ssh -i "$KEY" "$SERVER" "mkdir -p $TMP"

scp -i "$KEY" "$SRC/myapi.info"    "${SERVER}:${TMP}/"
scp -i "$KEY" "$SRC/myapi.install" "${SERVER}:${TMP}/"
scp -i "$KEY" "$SRC/myapi.module"  "${SERVER}:${TMP}/"
scp -i "$KEY" -r "$SRC/includes"   "${SERVER}:${TMP}/"
scp -i "$KEY" -r "$SRC/resources"  "${SERVER}:${TMP}/"

echo "Copiando al directorio de Drupal y limpiando cache..."

ssh -i "$KEY" "$SERVER" "
  sudo mkdir -p $DEST
  sudo cp $TMP/myapi.info    $DEST/
  sudo cp $TMP/myapi.install $DEST/
  sudo cp $TMP/myapi.module  $DEST/
  sudo cp -r $TMP/includes   $DEST/
  sudo cp -r $TMP/resources  $DEST/
  sudo chown -R www-data:www-data $DEST
  rm -rf $TMP
  cd /var/www/html && sudo -u www-data drush updb -y && sudo -u www-data drush cc all
"

echo "Deploy completado."
