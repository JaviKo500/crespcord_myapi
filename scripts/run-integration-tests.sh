#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
SERVER="ubuntu@crespcord.lamotora.com"
DEST="/var/www/html/sites/all/modules/myapi_test"
TMP="~/myapi_test_upload"

# rules_forms.test (a disabled contrib module) uses a static property default
# that is invalid under PHP 7.4, so it fatals when scripts/run-tests.sh scans
# every .test file to discover classes — blocking the whole run. It is moved
# aside for the duration of the run and restored in cleanup(). Safe because the
# module is disabled and .test files only load during test runs: even if the
# restore never happens, production runtime is unaffected.
BROKEN_TEST="/var/www/html/sites/all/modules/rules_forms/rules_forms.test"

# Runs even if an earlier step (including the test run) fails, so myapi_test
# never stays enabled and rules_forms.test is always put back. If SSH itself
# dropped and this could not run, undo by hand with the commands printed below.
cleanup() {
  echo "Restaurando rules_forms.test..."
  if ! ssh -i "$KEY" "$SERVER" "[ -f '$BROKEN_TEST.off' ] && sudo mv '$BROKEN_TEST.off' '$BROKEN_TEST' || true"; then
    echo "ADVERTENCIA: no se pudo restaurar rules_forms.test automáticamente." >&2
    echo "Restaurarlo a mano con:" >&2
    echo "  ssh -i \"$KEY\" \"$SERVER\" \"sudo mv '$BROKEN_TEST.off' '$BROKEN_TEST'\"" >&2
  fi

  echo "Deshabilitando myapi_test en el servidor..."
  if ! ssh -i "$KEY" "$SERVER" "cd /var/www/html && sudo -u www-data drush dis myapi_test -y && sudo -u www-data drush cc all"; then
    echo "ADVERTENCIA: no se pudo deshabilitar myapi_test automáticamente." >&2
    echo "Deshabilitarlo a mano con:" >&2
    echo "  ssh -i \"$KEY\" \"$SERVER\" \"cd /var/www/html && sudo -u www-data drush dis myapi_test -y\"" >&2
  fi
}
trap cleanup EXIT

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

echo "Apartando rules_forms.test (incompatible con PHP 7.4) para no romper el runner..."
ssh -i "$KEY" "$SERVER" "[ -f '$BROKEN_TEST' ] && sudo mv '$BROKEN_TEST' '$BROKEN_TEST.off' || true"

echo "Corriendo MyapiAuthTestCase..."

# Drush 8 dropped the old `drush test-run` command; Drupal core's native
# SimpleTest runner (scripts/run-tests.sh) is the supported path. --class makes
# the trailing name a test class rather than a group; --url is required so the
# sandbox's cURL requests resolve against the real site.
ssh -i "$KEY" "$SERVER" "cd /var/www/html && sudo -u www-data php scripts/run-tests.sh --color --url https://crespcord.lamotora.com --class MyapiAuthTestCase"

echo "Tests de integración completados."
