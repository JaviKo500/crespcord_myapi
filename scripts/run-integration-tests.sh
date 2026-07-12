#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
SERVER="ubuntu@crespcord.lamotora.com"
DEST="/var/www/html/sites/all/modules/myapi_test"
TMP="~/myapi_test_upload"

# Runs even if an earlier step (including drush test-run) fails, so myapi_test
# never stays enabled in production after a broken run. If this also fails
# (e.g. the SSH connection itself dropped), disable it by hand with the same
# command printed below.
disable_myapi_test() {
  echo "Deshabilitando myapi_test en el servidor..."
  if ! ssh -i "$KEY" "$SERVER" "cd /var/www/html && sudo -u www-data drush dis myapi_test -y && sudo -u www-data drush cc all"; then
    echo "ADVERTENCIA: no se pudo deshabilitar myapi_test automáticamente." >&2
    echo "Deshabilitarlo a mano con:" >&2
    echo "  ssh -i \"$KEY\" \"$SERVER\" \"cd /var/www/html && sudo -u www-data drush dis myapi_test -y\"" >&2
  fi
}
trap disable_myapi_test EXIT

echo "Subiendo tests de integración al servidor..."

ssh -i "$KEY" "$SERVER" "mkdir -p $TMP"

scp -i "$KEY" "$SRC/tests/integration/myapi_test.info"        "${SERVER}:${TMP}/"
scp -i "$KEY" "$SRC/tests/integration/myapi_test.module"      "${SERVER}:${TMP}/"
scp -i "$KEY" "$SRC/tests/integration/MyapiAuthTestCase.test" "${SERVER}:${TMP}/"

echo "Copiando al directorio de Drupal y habilitando myapi_test..."

ssh -i "$KEY" "$SERVER" "
  sudo mkdir -p $DEST
  sudo cp $TMP/myapi_test.info          $DEST/
  sudo cp $TMP/myapi_test.module        $DEST/
  sudo cp $TMP/MyapiAuthTestCase.test   $DEST/
  sudo chown -R www-data:www-data $DEST
  rm -rf $TMP
  cd /var/www/html && sudo -u www-data drush en myapi_test -y && sudo -u www-data drush cc all
"

echo "Corriendo MyapiAuthTestCase..."

ssh -i "$KEY" "$SERVER" "cd /var/www/html && sudo -u www-data drush test-run MyapiAuthTestCase"

echo "Tests de integración completados."
