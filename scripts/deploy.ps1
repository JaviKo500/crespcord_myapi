# Usage: .\deploy.ps1 [-Env staging|prod] [-Yes]
# Production host comes from $env:MYAPI_PROD_HOST; it is not committed.
param(
    [ValidateSet("staging", "prod")]
    [Alias("Env")]
    [string]$Environment = "staging",
    [switch]$Yes
)

$src = Split-Path $PSScriptRoot -Parent

if ($Environment -eq "prod") {
    if (-not $env:MYAPI_PROD_HOST) {
        Write-Error "Falta la variable de entorno MYAPI_PROD_HOST."
        exit 1
    }
    $key    = "$PSScriptRoot\LightsailDefaultKey-us-east-2-cresp.pem"
    $server = "ubuntu@$($env:MYAPI_PROD_HOST)"
} else {
    $key    = "$PSScriptRoot\crespcord.pem"
    $server = "ubuntu@crespcord.lamotora.com"
}
if ($env:MYAPI_DEPLOY_KEY) { $key = $env:MYAPI_DEPLOY_KEY }

Write-Host "Entorno: $Environment ($server)"

if ($Environment -eq "prod" -and -not $Yes) {
    $answer = Read-Host "Vas a desplegar en PRODUCCION. Escribe 'prod' para continuar"
    if ($answer -ne "prod") {
        Write-Host "Cancelado."
        exit 1
    }
}

$dest   = "/var/www/html/sites/all/modules/myapi"
$tmp    = "~/myapi_upload"

Write-Host "Subiendo archivos al servidor..."

ssh -i $key $server "mkdir -p $tmp"

scp -i $key "$src\myapi.info"    "${server}:${tmp}/"
scp -i $key "$src\myapi.install" "${server}:${tmp}/"
scp -i $key "$src\myapi.module"  "${server}:${tmp}/"
scp -i $key -r "$src\includes"   "${server}:${tmp}/"
scp -i $key -r "$src\resources"  "${server}:${tmp}/"
# Assets of the back-office reservation calendar (SPEC 47). They are loaded
# with drupal_add_css()/drupal_add_js() and not declared in myapi.info, so
# nothing fails at cache clear when they are missing: the page just renders
# unstyled.
scp -i $key -r "$src\css"        "${server}:${tmp}/"
scp -i $key -r "$src\js"         "${server}:${tmp}/"
# Static assets (module logo, SPEC 54). Same reasoning as css/js: read at
# runtime via drupal_get_path(), not declared in myapi.info.
scp -i $key -r "$src\assets"     "${server}:${tmp}/"

Write-Host "Copiando al directorio de Drupal y limpiando cache..."

$remote_commands = @"
sudo mkdir -p $dest
sudo cp $tmp/myapi.info    $dest/
sudo cp $tmp/myapi.install $dest/
sudo cp $tmp/myapi.module  $dest/
sudo cp -r $tmp/includes   $dest/
sudo cp -r $tmp/resources  $dest/
sudo cp -r $tmp/css        $dest/
sudo cp -r $tmp/js         $dest/
sudo cp -r $tmp/assets     $dest/
sudo chown -R www-data:www-data $dest
rm -rf $tmp
cd /var/www/html && sudo -u www-data drush updb -y && sudo -u www-data drush cc all
"@ -replace "`r`n", "`n"

ssh -i $key $server $remote_commands

Write-Host "Deploy completado en $Environment."
