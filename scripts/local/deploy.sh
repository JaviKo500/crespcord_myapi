#!/bin/bash
#
# Local equivalent of scripts/deploy.sh. There is nothing to upload: the
# repository is mounted as sites/all/modules/myapi, so only the last step of
# the real deploy remains — pending updates and a cache clear (needed after
# touching hook_menu(), hook_schema() or the files[] list of myapi.info).

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

echo "Aplicando updates pendientes y limpiando caché en local..."
drush_local updb -y
drush_local cc all

echo "Deploy local completado: $LOCAL_BASE_URL"
