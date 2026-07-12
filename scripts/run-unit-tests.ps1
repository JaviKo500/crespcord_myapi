$src = Split-Path $PSScriptRoot -Parent

Set-Location $src

Write-Host "Instalando dependencias..."
composer install --no-interaction

Write-Host "Corriendo tests unitarios..."
vendor/bin/phpunit
