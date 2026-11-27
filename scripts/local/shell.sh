#!/bin/bash
#
# Opens a shell in a container. Default: web, as www-data, in the docroot.
#   scripts/local/shell.sh            web as www-data
#   scripts/local/shell.sh root       web as root
#   scripts/local/shell.sh db         mariadb client on the local database

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

case "${1:-web}" in
  web)  dc exec -u www-data web bash ;;
  root) dc exec -u root web bash ;;
  db)   dc exec -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot "$DB_NAME" ;;
  *)    echo "Uso: $(basename "$0") [web|root|db]" >&2; exit 1 ;;
esac
