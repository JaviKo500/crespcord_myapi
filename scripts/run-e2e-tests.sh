#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"
E2E_DIR="$SRC/tests/e2e"
ENV_FILE="${MYAPI_E2E_ENV:-$E2E_DIR/auth.postman_environment.json}"

if [ ! -f "$ENV_FILE" ]; then
  echo "No se encontró $ENV_FILE." >&2
  echo "Copiá tests/e2e/auth.postman_environment.example.json a tests/e2e/auth.postman_environment.json y completá los valores reales (ver tests/README.md)." >&2
  exit 1
fi

if [ ! -f "$E2E_DIR/.env" ]; then
  echo "No se encontró $E2E_DIR/.env (credenciales IMAP para password-reset-roundtrip.js)." >&2
  echo "Creálo con las mismas claves de auth.postman_environment.example.json (ver tests/README.md)." >&2
  exit 1
fi

cd "$E2E_DIR"

echo "Instalando dependencias..."
npm install

echo "Corriendo la colección Postman (login -> refresh -> logout, negativos, smoke de /forgot)..."
npx newman run auth.postman_collection.json -e "$ENV_FILE"

echo "Corriendo el roundtrip de password reset (forgot -> IMAP -> reset)..."
node password-reset-roundtrip.js
