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
cd /var/www/html && sudo -u www-data drush cc all
"@ -replace "`r`n", "`n"

ssh -i $key $server $remote_commands

Write-Host "Deploy completado."
