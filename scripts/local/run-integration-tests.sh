#!/bin/bash
#
# Local equivalent of scripts/run-integration-tests.sh: same steps, run in the
# web container with `docker compose exec` instead of SSH.
#
# Nothing is uploaded: tests/integration/ is already inside the module mount
# (sites/all/modules/myapi/tests/integration), where Drupal discovers
# myapi_test on its own.

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

# rules_forms.test (a disabled contrib module) uses a static property default
# that is invalid since PHP 7.4, so it fatals when scripts/run-tests.sh scans
# every .test file to discover classes — blocking the whole run. It is moved
# aside for the duration of the run and restored in cleanup().
BROKEN_TEST="/var/www/html/sites/all/modules/rules_forms/rules_forms.test"

# Runs even if an earlier step (including the test run) fails, so myapi_test
# never stays enabled and rules_forms.test is always put back.
cleanup() {
  # A setUp() that dies half way leaves its sandbox behind. Core's cleaner
  # (run-tests.sh --clean) removes the files but finds no tables on this site:
  # it applies the dr_ prefix twice when it looks for them. They are dropped
  # here by name instead (dr_simpletest<digits>...).
  echo "Limpiando restos del sandbox de SimpleTest..."
  web_exec php scripts/run-tests.sh --clean >/dev/null 2>&1 || true
  db_root -N -B "$DB_NAME" -e "SELECT CONCAT('DROP TABLE \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name REGEXP '^dr_simpletest[0-9]+'" \
    | db_root "$DB_NAME" || true

  echo "Restaurando rules_forms.test..."
  if ! web_exec sh -c "[ -f '$BROKEN_TEST.off' ] && mv '$BROKEN_TEST.off' '$BROKEN_TEST' || true"; then
    echo "ADVERTENCIA: no se pudo restaurar rules_forms.test automáticamente." >&2
    echo "Restaurarlo a mano con:" >&2
    echo "  mv '$DOCROOT/sites/all/modules/rules_forms/rules_forms.test.off' '$DOCROOT/sites/all/modules/rules_forms/rules_forms.test'" >&2
  fi

  echo "Deshabilitando myapi_test..."
  # simpletest comes in as a dependency of myapi_test; it goes out with it
  # unless it was already enabled before this run.
  local to_disable="myapi_test"
  [ "$SIMPLETEST_WAS_ENABLED" = "0" ] && to_disable="myapi_test simpletest"
  # shellcheck disable=SC2086
  if ! { drush_local dis $to_disable -y && drush_local cc all; }; then
    echo "ADVERTENCIA: no se pudo deshabilitar myapi_test automáticamente." >&2
    echo "Deshabilitarlo a mano con:" >&2
    echo "  scripts/local/drush.sh dis myapi_test -y" >&2
  fi
}

SIMPLETEST_WAS_ENABLED="$(db_root -N -B "$DB_NAME" -e "SELECT status FROM dr_system WHERE name = 'simpletest'")"
SIMPLETEST_WAS_ENABLED="${SIMPLETEST_WAS_ENABLED:-0}"

trap cleanup EXIT

echo "Habilitando myapi_test..."
drush_local en myapi_test -y
drush_local cc all

echo "Apartando rules_forms.test (incompatible con PHP >= 7.4) para no romper el runner..."
web_exec sh -c "[ -f '$BROKEN_TEST' ] && mv '$BROKEN_TEST' '$BROKEN_TEST.off' || true"

echo "Corriendo MyapiAuthTestCase..."

# Drupal core's native SimpleTest runner. --class makes the trailing name a
# test class rather than a group; --url is the site as seen from INSIDE the
# container (port 80), because the sandbox's cURL requests start there.
web_exec php scripts/run-tests.sh --color --url http://localhost --class MyapiAuthTestCase

echo "Tests de integración completados."
