$key    = "$PSScriptRoot\crespcord.pem"
$src    = $PSScriptRoot
$server = "ubuntu@crespcord.lamotora.com"
$dest   = "/var/www/html/sites/all/modules/myapi"
$tmp    = "~/myapi_upload"

Write-Host "Subiendo archivos al servidor..."

scp -i $key "$src\myapi.info"    "${server}:${tmp}/"
scp -i $key "$src\myapi.install" "${server}:${tmp}/"
scp -i $key "$src\myapi.module"  "${server}:${tmp}/"
scp -i $key -r "$src\includes"   "${server}:${tmp}/"
scp -i $key -r "$src\resources"  "${server}:${tmp}/"

Write-Host "Copiando al directorio de Drupal y limpiando cache..."

ssh -i $key $server @"
  sudo mkdir -p $dest
  sudo cp $tmp/myapi.info    $dest/
  sudo cp $tmp/myapi.install $dest/
  sudo cp $tmp/myapi.module  $dest/
  sudo cp -r $tmp/includes   $dest/
  sudo cp -r $tmp/resources  $dest/
  sudo chown -R www-data:www-data $dest
  rm -rf $tmp
  cd /var/www/html && sudo -u www-data drush cc all
"@

Write-Host "Deploy completado."
