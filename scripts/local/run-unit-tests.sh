#!/bin/bash
#
# Local equivalent of scripts/run-unit-tests.sh: runs PHPUnit inside the web
# container, i.e. on PHP 8.3 like CI and production, whatever PHP the host has.
# Extra arguments go to phpunit (e.g. --filter PingTest).

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

if [ ! -x "$REPO/vendor/bin/phpunit" ]; then
  echo "Falta vendor/. Ejecuta en el host: composer install" >&2
  exit 1
fi

echo "Corriendo tests unitarios (PHP del contenedor)..."
dc exec -T -u www-data -w /var/www/html/sites/all/modules/myapi web \
  php vendor/bin/phpunit "$@"
