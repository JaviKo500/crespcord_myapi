#!/bin/bash
#
# Local equivalent of scripts/run-e2e-tests.sh: the same Postman collection
# and the same password reset roundtrip, against the local site.
#
# Two things differ from the real one, both because there is nothing real to
# point at: the environment file is generated (a dedicated local account,
# created here if missing), and the reset e-mail is read from Mailpit's HTTP
# API instead of an IMAP mailbox (scripts/local/password-reset-roundtrip.js).

set -euo pipefail

. "$(dirname "$0")/lib.sh"
require_services

E2E_DIR="$REPO/tests/e2e"
E2E_USER="e2e_local"
E2E_MAIL="e2e_local@example.test"
E2E_PASSWORD="${LOCAL_USER_PASSWORD:-Local.1234}"
BASE_URL="http://localhost:$WEB_PORT"
MAILPIT_URL="http://localhost:$MAILPIT_PORT"
ENV_FILE="$LOCAL_DIR/e2e/local.postman_environment.json"

echo "Preparando la cuenta de pruebas $E2E_USER..."
dc exec -T -u www-data -e E2E_USER="$E2E_USER" -e E2E_MAIL="$E2E_MAIL" -e E2E_PASSWORD="$E2E_PASSWORD" web \
  drush --uri="$LOCAL_BASE_URL" php-script \
  /var/www/html/sites/all/modules/myapi/scripts/local/ensure-e2e-account.php

mkdir -p "$(dirname "$ENV_FILE")"
cat > "$ENV_FILE" <<JSON
{
  "values": [
    { "key": "base_url", "value": "$BASE_URL" },
    { "key": "test_username", "value": "$E2E_USER" },
    { "key": "test_password", "value": "$E2E_PASSWORD" },
    { "key": "test_mail", "value": "$E2E_MAIL" }
  ]
}
JSON

cd "$E2E_DIR"

echo "Instalando dependencias..."
npm install

echo "Corriendo la colección Postman (login -> refresh -> logout, negativos, smoke de /forgot)..."
npx newman run auth.postman_collection.json -e "$ENV_FILE"

echo "Corriendo el roundtrip de password reset (forgot -> Mailpit -> reset)..."
E2E_BASE_URL="$BASE_URL" E2E_MAILPIT_URL="$MAILPIT_URL" \
E2E_USERNAME="$E2E_USER" E2E_PASSWORD="$E2E_PASSWORD" E2E_MAIL="$E2E_MAIL" \
  node "$LOCAL_SCRIPTS_DIR/password-reset-roundtrip.js"
