#!/bin/bash
#
# Shared helpers for the local development scripts (sourced, never executed).
# Sets: LOCAL_DIR, REPO, DOCROOT, DUMPS, and the values of .env.

LOCAL_SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LOCAL_SCRIPTS_DIR/../.." && pwd)"
LOCAL_DIR="$REPO/.local"
DOCROOT="$LOCAL_DIR/docroot"
DUMPS="$LOCAL_DIR/dumps"

if [ ! -f "$REPO/.env" ]; then
  echo "Falta $REPO/.env. Crealo con: cp .env.example .env" >&2
  exit 1
fi
set -a
# shellcheck source=/dev/null
. "$REPO/.env"
set +a

WEB_PORT="${WEB_PORT:-8080}"
MAILPIT_PORT="${MAILPIT_PORT:-8025}"
DB_NAME="${DB_NAME:-crespcord}"
DB_USER="${DB_USER:-crespcord}"
LOCAL_BASE_URL="${LOCAL_BASE_URL:-http://localhost:$WEB_PORT}"

mkdir -p "$DOCROOT" "$DUMPS" "$LOCAL_DIR/private" "$LOCAL_DIR/manual-backoffice"

# docker compose, always against this repository's compose file.
dc() {
  docker compose --project-directory "$REPO" "$@"
}

# Runs a command in the web container as www-data, the user the web server
# and Drush must share (see docker/web/drush-wrapper.sh).
web_exec() {
  dc exec -T -u www-data web "$@"
}

drush_local() {
  web_exec drush --uri="$LOCAL_BASE_URL" "$@"
}

# mariadb client inside the db container, as root, reading SQL from stdin.
db_root() {
  dc exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot "$@"
}

# $1: service that must be running (default: web).
require_services() {
  local service="${1:-web}"
  if [ -z "$(dc ps -q --status running "$service" 2>/dev/null)" ]; then
    echo "El contenedor '$service' no está levantado. Ejecuta: make up" >&2
    exit 1
  fi
}

# Production target: reuses scripts/deploy.local.env, the same file
# scripts/deploy-target.sh reads. Sets PROD_KEY and PROD_SERVER.
load_prod_target() {
  local env_file="$REPO/scripts/deploy.local.env"
  if [ -f "$env_file" ]; then
    # shellcheck source=/dev/null
    . "$env_file"
  fi
  if [ -z "${MYAPI_PROD_HOST:-}" ]; then
    echo "Falta MYAPI_PROD_HOST (variable de entorno o scripts/deploy.local.env)." >&2
    exit 1
  fi
  PROD_KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/LightsailDefaultKey-us-east-2-cresp.pem}"
  PROD_SERVER="ubuntu@${MYAPI_PROD_HOST}"
  if [ ! -f "$PROD_KEY" ]; then
    echo "No existe la clave SSH: $PROD_KEY" >&2
    exit 1
  fi
}

# Production is read-only for these scripts, and every connection is confirmed.
# $1: what is about to happen. Skipped with --yes / MYAPI_LOCAL_ASSUME_YES=1.
confirm_prod() {
  if [ "${MYAPI_LOCAL_ASSUME_YES:-0}" = "1" ]; then
    return 0
  fi
  echo "$1"
  read -r -p "Conexión de SOLO LECTURA a PRODUCCIÓN ($PROD_SERVER). Escribe 'prod' para continuar: " answer
  if [ "$answer" != "prod" ]; then
    echo "Cancelado."
    exit 1
  fi
}

# Megabytes free on the volume that holds the repository.
free_mb() {
  df -m "$REPO" | awk 'NR==2 {print $4}'
}

require_free_mb() {
  local needed="$1" available
  available="$(free_mb)"
  if [ "$available" -lt "$needed" ]; then
    echo "Espacio insuficiente: hay ${available} MB libres y hacen falta al menos ${needed} MB." >&2
    exit 1
  fi
}
