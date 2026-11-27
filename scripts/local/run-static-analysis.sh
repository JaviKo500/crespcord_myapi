#!/bin/bash
#
# Local equivalent of scripts/run-static-analysis.sh: PHPStan inside the web
# container (PHP 8.3).

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

if [ ! -x "$REPO/vendor/bin/phpstan" ]; then
  echo "Falta vendor/. Ejecuta en el host: composer install" >&2
  exit 1
fi

# myapi.install is a single 200 KB file: its AST does not fit in PHP's default
# limit and PHPStan aborts with "reached configured PHP memory limit".
echo "Corriendo análisis estático (PHP del contenedor)..."
dc exec -T -u www-data -w /var/www/html/sites/all/modules/myapi web \
  php vendor/bin/phpstan analyse --memory-limit=1G "$@"
