$key    = "$PSScriptRoot\crespcord.pem"
$src    = Split-Path $PSScriptRoot -Parent
$server = "ubuntu@crespcord.lamotora.com"
$dest   = "/var/www/html/sites/all/modules/myapi"
$tmp    = "~/myapi_upload"

Write-Host "Subiendo archivos al servidor..."

ssh -i $key $server "mkdir -p $tmp"

scp -i $key "$src\myapi.info"    "${server}:${tmp}/"
scp -i $key "$src\myapi.install" "${server}:${tmp}/"
scp -i $key "$src\myapi.module"  "${server}:${tmp}/"
scp -i $key -r "$src\includes"   "${server}:${tmp}/"
scp -i $key -r "$src\resources"  "${server}:${tmp}/"

Write-Host "Copiando al directorio de Drupal y limpiando cache..."

$remote_commands = @"
sudo mkdir -p $dest
sudo cp $tmp/myapi.info    $dest/
sudo cp $tmp/myapi.install $dest/
sudo cp $tmp/myapi.module  $dest/
sudo cp -r $tmp/includes   $dest/
sudo cp -r $tmp/resources  $dest/
sudo chown -R www-data:www-data $dest
rm -rf $tmp
cd /var/www/html && sudo -u www-data drush cc all
"@ -replace "`r`n", "`n"

ssh -i $key $server $remote_commands

Write-Host "Deploy completado."
