#!/bin/bash
#
# Drush inside the web container, as www-data and with the local site URL.
#   scripts/local/drush.sh status
#   scripts/local/drush.sh cc all

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

TTY_FLAG="-T"
[ -t 0 ] && [ -t 1 ] && TTY_FLAG=""
# shellcheck disable=SC2086
dc exec $TTY_FLAG -u www-data web drush --uri="$LOCAL_BASE_URL" "$@"
