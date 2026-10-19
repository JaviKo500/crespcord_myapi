#!/bin/bash
#
# Deploy target selection, sourced by deploy.sh and deploy-manual.sh.
#
#   --env staging   (default) crespcord.lamotora.com, ~/.ssh/crespcord.pem
#   --env prod      Lightsail instance in us-east-2,
#                   ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem
#   --yes           skip the production confirmation prompt
#
# The production host is not committed: set MYAPI_PROD_HOST, or put it in
# scripts/deploy.local.env (gitignored), e.g.
#
#   MYAPI_PROD_HOST=3.x.x.x
#   MYAPI_PROD_URL=https://example.com
#
# MYAPI_DEPLOY_KEY overrides the key of whichever target is selected.
#
# Sets: DEPLOY_ENV, KEY, SERVER, SITE_URL.

DEPLOY_ENV="staging"
DEPLOY_ASSUME_YES=0

while [ $# -gt 0 ]; do
  case "$1" in
    --env)    DEPLOY_ENV="${2:-}"; shift 2 ;;
    --env=*)  DEPLOY_ENV="${1#--env=}"; shift ;;
    --prod)   DEPLOY_ENV="prod"; shift ;;
    --yes|-y) DEPLOY_ASSUME_YES=1; shift ;;
    -h|--help)
      echo "Uso: $(basename "$0") [--env staging|prod] [--yes]"
      exit 0 ;;
    *)
      echo "Opción desconocida: $1" >&2
      echo "Uso: $(basename "$0") [--env staging|prod] [--yes]" >&2
      exit 1 ;;
  esac
done

LOCAL_ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/deploy.local.env"
if [ -f "$LOCAL_ENV_FILE" ]; then
  # Empty values in the file must not wipe variables already exported.
  exported_prod_host="${MYAPI_PROD_HOST:-}"
  exported_prod_url="${MYAPI_PROD_URL:-}"
  # shellcheck source=/dev/null
  . "$LOCAL_ENV_FILE"
  MYAPI_PROD_HOST="${MYAPI_PROD_HOST:-$exported_prod_host}"
  MYAPI_PROD_URL="${MYAPI_PROD_URL:-$exported_prod_url}"
fi

case "$DEPLOY_ENV" in
  staging)
    KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/crespcord.pem}"
    SERVER="ubuntu@crespcord.lamotora.com"
    SITE_URL="https://crespcord.lamotora.com"
    ;;
  prod|production)
    DEPLOY_ENV="prod"
    if [ -z "${MYAPI_PROD_HOST:-}" ]; then
      echo "Falta MYAPI_PROD_HOST (variable de entorno o scripts/deploy.local.env)." >&2
      exit 1
    fi
    KEY="${MYAPI_DEPLOY_KEY:-$HOME/.ssh/LightsailDefaultKey-us-east-2-cresp.pem}"
    SERVER="ubuntu@${MYAPI_PROD_HOST}"
    SITE_URL="${MYAPI_PROD_URL:-http://${MYAPI_PROD_HOST}}"
    ;;
  *)
    echo "Entorno desconocido: '$DEPLOY_ENV' (usa staging o prod)." >&2
    exit 1
    ;;
esac

if [ ! -f "$KEY" ]; then
  echo "No existe la clave SSH: $KEY" >&2
  exit 1
fi

echo "Entorno: $DEPLOY_ENV ($SERVER, clave $(basename "$KEY"))"

if [ "$DEPLOY_ENV" = "prod" ] && [ "$DEPLOY_ASSUME_YES" -ne 1 ]; then
  read -r -p "Vas a desplegar en PRODUCCIÓN. Escribe 'prod' para continuar: " answer
  if [ "$answer" != "prod" ]; then
    echo "Cancelado."
    exit 1
  fi
fi
