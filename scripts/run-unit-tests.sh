#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"

cd "$SRC"

echo "Instalando dependencias..."
composer install --no-interaction

echo "Corriendo tests unitarios..."
vendor/bin/phpunit
