$key    = "$PSScriptRoot\crespcord.pem"
$src    = Split-Path $PSScriptRoot -Parent
$server = "ubuntu@crespcord.lamotora.com"
$dest   = "/var/www/html/sites/all/modules/myapi_test"
$tmp    = "~/myapi_test_upload"

# Runs even if an earlier step (including drush test-run) fails, so
# myapi_test never stays enabled in production after a broken run. If this
# also fails (e.g. the SSH connection itself dropped), disable it by hand
# with the same command printed below.
function Disable-MyapiTest {
    Write-Host "Deshabilitando myapi_test en el servidor..."
    ssh -i $key $server "cd /var/www/html && sudo -u www-data drush dis myapi_test -y && sudo -u www-data drush cc all"
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "No se pudo deshabilitar myapi_test automáticamente."
        Write-Warning "Deshabilitarlo a mano con: ssh -i $key $server `"cd /var/www/html && sudo -u www-data drush dis myapi_test -y`""
    }
}

try {
    Write-Host "Subiendo tests de integración al servidor..."

    ssh -i $key $server "mkdir -p $tmp"

    scp -i $key "$src\tests\integration\myapi_test.info"        "${server}:${tmp}/"
    scp -i $key "$src\tests\integration\myapi_test.module"      "${server}:${tmp}/"
    scp -i $key "$src\tests\integration\MyapiAuthTestCase.test" "${server}:${tmp}/"

    Write-Host "Copiando al directorio de Drupal y habilitando myapi_test..."

    $enable_commands = @"
sudo mkdir -p $dest
sudo cp $tmp/myapi_test.info          $dest/
sudo cp $tmp/myapi_test.module        $dest/
sudo cp $tmp/MyapiAuthTestCase.test   $dest/
sudo chown -R www-data:www-data $dest
rm -rf $tmp
cd /var/www/html && sudo -u www-data drush en myapi_test -y && sudo -u www-data drush cc all
"@ -replace "`r`n", "`n"

    ssh -i $key $server $enable_commands

    Write-Host "Corriendo MyapiAuthTestCase..."

    ssh -i $key $server "cd /var/www/html && sudo -u www-data drush test-run MyapiAuthTestCase"

    Write-Host "Tests de integración completados."
}
finally {
    Disable-MyapiTest
}
