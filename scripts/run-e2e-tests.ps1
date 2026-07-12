$src    = Split-Path $PSScriptRoot -Parent
$e2eDir = Join-Path $src "tests\e2e"
$envFile = if ($env:MYAPI_E2E_ENV) { $env:MYAPI_E2E_ENV } else { Join-Path $e2eDir "auth.postman_environment.json" }

if (-not (Test-Path $envFile)) {
    Write-Error "No se encontró $envFile. Copiá tests/e2e/auth.postman_environment.example.json a tests/e2e/auth.postman_environment.json y completá los valores reales (ver tests/README.md)."
    exit 1
}

if (-not (Test-Path (Join-Path $e2eDir ".env"))) {
    Write-Error "No se encontró $e2eDir\.env (credenciales IMAP para password-reset-roundtrip.js). Creálo con las mismas claves de auth.postman_environment.example.json (ver tests/README.md)."
    exit 1
}

Set-Location $e2eDir

Write-Host "Instalando dependencias..."
npm install

Write-Host "Corriendo la colección Postman (login -> refresh -> logout, negativos, smoke de /forgot)..."
npx newman run auth.postman_collection.json -e $envFile

Write-Host "Corriendo el roundtrip de password reset (forgot -> IMAP -> reset)..."
node password-reset-roundtrip.js
