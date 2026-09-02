#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"

cd "$SRC"

echo "Instalando dependencias..."
composer install --no-interaction

# myapi.install es un solo archivo de 200 KB: su AST no cabe en el limite por
# defecto de PHP, y PHPStan aborta con "reached configured PHP memory limit".
echo "Corriendo analisis estatico..."
vendor/bin/phpstan analyse --memory-limit=1G
