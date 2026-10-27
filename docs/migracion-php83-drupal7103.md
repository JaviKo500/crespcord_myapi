# Migración de producción a Drupal 7.103 + PHP 8.3.36 (PHP-FPM)

Guía paso a paso para llevar el servidor de producción (`sistema.crespcord.com`,
`ubuntu@<IP_PROD>`) al mismo estado que staging (`crespcord.lamotora.com`). Cada paso
dice **qué hace**, **por qué**, el **comando**, el **resultado esperado** y **qué hacer si
falla**. Los valores entre `<...>` son datos del servidor que no se suben al repo.

Todo lo que aquí se describe se probó en staging entre el 2026-10-22 y el 2026-10-26, y
se verificó el 2026-10-27 contra la configuración real de staging y contra los tarballs
oficiales de Drupal 7.64 y 7.103. La versión resumida, en formato checklist, está en la
sección 8 de `docs/produccion-checklist.md`; la parte de servidor y los CVE, en
`docs/mitigacion-php-cve-2026-10.md`.

---

## 1. Qué se cambia y por qué

| Componente | Producción hoy | Destino (igual que staging) |
|---|---|---|
| Drupal | 7.64 | **7.103** (última versión de Drupal 7, 2024-12-04) |
| PHP | 5.6.40 (Sury) como `mod_php` | **8.3.36** (Sury) como **PHP-FPM**, socket `/run/php/php8.3-fpm.sock` |
| MPM de Apache | `prefork` | `event` + `proxy_fcgi` |
| Drush | 8.4.11 | **8.4.12** |
| Mitigaciones CVE temporales | activas | se retiran |
| Módulo `entityreference_count` | sin parche | parcheado para PHP 8 |
| PHP embebido en Rules | sin ajustar | reescrito con `isset()` / `(float)` |

**Por qué.** PHP 5.6 no recibe parche para los dos RCE críticos de octubre de 2026
(GHSA-w8hw-w9fx-j79w y GHSA-6wmf-4rv5-p3rp), cuyo escenario confirmado es justo
Apache con `mod_php`. La única corrección real es un PHP con parche (8.3.36 o superior)
y, para que Drupal 7 arranque en PHP 8, el core tiene que estar en 7.103, la única
versión que pasa los tests en PHP 8.3.

**Por qué en este orden.** Drupal 7.64 no arranca en PHP 8, pero Drupal 7.103 sí arranca
en PHP 5.6 (su mínimo declarado). Por eso primero se sube el core con el PHP viejo y
después se cambia PHP: en ningún momento hay una combinación que no funcione.

**Duración orientativa.** Entre 60 y 90 minutos de ventana con el sitio en mantenimiento,
más 24 horas de observación con el sitio abierto antes de purgar PHP 5.6.

**Regla que atraviesa toda la guía: `drush` siempre como `sudo -u www-data`.** Hasta que
se corrigen los permisos del módulo (paso 5.1), el usuario `ubuntu` no puede leer
`myapi/includes/`. Si ejecuta `drush`, deja falsos errores «Undefined constant MYAPI_…»
en el watchdog y, peor, el módulo `mailsystem` borra las 24 claves `myapi_*` de la
variable `mail_system` y rompe el correo saliente.

---

## 2. Preparación (días antes, sin tocar nada)

Nada de este bloque cambia el servidor. Se puede hacer con antelación.

### 2.1 Comprobar que el repositorio de PHP ya ofrece la 8.3.36

**Qué hace.** Confirma que `apt` puede instalar exactamente la misma versión de PHP que
staging. Producción instaló PHP 5.6.40 desde Sury, así que el PPA `ondrej/php` debería
existir ya.

```bash
ssh -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem ubuntu@<IP_PROD>
apt-cache policy php8.3-fpm | head -3
grep -rh "ondrej/php" /etc/apt/sources.list.d/
```

**Resultado esperado.** `Candidate: 8.3.36-1+ubuntu22.04.1+deb.sury.org+1` y una línea
`deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu/ jammy main`.

**Si falla.** Si el candidato no aparece o es otro, añadir el PPA y refrescar:
```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
```
Si el candidato es una versión **superior** a 8.3.36, es una 8.3.x posterior con parche
incluido: sirve igual, pero anotar la diferencia respecto a staging.

### 2.2 Lista de paquetes PHP de staging

**Qué hace.** Fija la lista exacta de paquetes a instalar, para no olvidar ninguna
extensión que el sitio usa (GD para redimensionar imágenes, `intl`, `soap`, `zip`…).

A 2026-10-27 staging tiene estos 13 paquetes, todos en 8.3.36:

```
php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-intl php8.3-mbstring
php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap php8.3-xml php8.3-zip
```

Las extensiones `fileinfo` (validación del tipo real de las subidas) y `openssl` (chat)
vienen dentro de `php8.3-common` y `php8.3-cli`; no son paquetes aparte.

Si han pasado semanas, sacar la lista fresca de staging:
```bash
ssh -i ~/.ssh/crespcord.pem ubuntu@crespcord.lamotora.com "dpkg -l 'php8.3*' | awk '/^ii/{print \$2}'"
```

### 2.3 Descargar los tarballs del core fuera del docroot

**Qué hace.** Deja en `/tmp` del servidor de producción la 7.103 (para instalarla) y la
7.64 (para saber qué tiene de personalizado el `.htaccess` actual y qué ficheros del
docroot no son core, pasos 2.5 y 4.1).

```bash
cd /tmp
curl -fLO https://ftp.drupal.org/files/projects/drupal-7.103.tar.gz && tar xzf drupal-7.103.tar.gz
curl -fLO https://ftp.drupal.org/files/projects/drupal-7.64.tar.gz  && tar xzf drupal-7.64.tar.gz
grep "define('VERSION'" drupal-7.103/includes/bootstrap.inc
```

**Resultado esperado.** `define('VERSION', '7.103');` y dos directorios
`/tmp/drupal-7.103` y `/tmp/drupal-7.64`.

**Si falla.** Si `curl` devuelve 404, descargarlos desde
`https://www.drupal.org/project/drupal/releases/7.103` y subirlos con `scp`.

### 2.4 Saber qué va a cambiar el core

**Qué hace.** Evita sorpresas: estas son las diferencias entre 7.64 y 7.103 que afectan
a la operación.

- **Ocho updates de base de datos**: `system_update_7082` a `7088` y `user_update_7020`.
  - **7086 cambia el formato de los IDs de sesión** (los guarda hasheados). Al terminar,
    **todos los usuarios del backoffice quedan desconectados** y tienen que volver a
    entrar. La app Flutter no se ve afectada: usa tokens de la tabla `myapi_tokens`, no
    sesiones de Drupal.
  - **7087 habilita el módulo `announcements_feed`** (avisos de drupal.org en el panel de
    administración). Es inofensivo; si molesta, después `drush dis announcements_feed`.
  - Las demás añaden ficheros JS a la librería jQuery, reconstruyen agregados y añaden la
    columna `changed` a `users`.
- **`.htaccess`**: la 7.103 solo añade endurecimiento (`web.config` en la lista de
  ficheros denegados, `RequestHeader unset Proxy`, `no-brotli` en los agregados). Conserva
  el bloque `<IfModule mod_php5.c>` y **no** trae bloques `php7_module` ni `lsapi_module`.
- **`robots.txt`**: idéntico entre 7.64 y 7.103.
- **`default.settings.php`**: solo añade claves comentadas. **No hay que tocar
  `settings.php`.**
- **`misc/typo3/`** se repone completo. Es lo que arregla el fatal recurrente
  `Interface 'TYPO3\PharStreamWrapper\Assertable' not found` que hay en `error.log`.

### 2.5 Inventario del docroot de producción

**Qué hace.** El paso 4.1 borra los ficheros y directorios del core (`profiles/`,
`misc/`, `modules/`, los `.php` y `.txt` de la raíz…). Hay que saber si el docroot tiene
algo que no sea core (verificaciones de dominio, `ads.txt`, scripts sueltos, perfiles
propios), y qué propietario tienen los ficheros para dejar el core nuevo igual.

```bash
cd /var/www/html
comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.64 | sort)
ls profiles/
ls -ld includes index.php sites
ls /etc/apache2/mods-enabled/ | grep -E "php|mpm"
ls -ld "/var/www/html/private:" "/var/www/html/public:"
```

**Resultado esperado.**
- `comm` lista lo que hay en el docroot y no en la 7.64. Nada de eso se borra en el paso
  4.1, porque allí se borra una lista explícita de ficheros del core. Aun así, anotarlo:
  es lo que hay que conservar si alguna vez se restaura el core desde cero. Si sale un
  `.php` o un `.txt` cuyo nombre coincide con uno del core, es una modificación local:
  copiarlo a `~/backups` y reaplicarlo tras el 4.1.
- `profiles/` solo con `README.txt minimal standard testing` (así está staging). Si hay
  un perfil propio, copiarlo a `~/backups` antes del paso 4.1 y reponerlo después.
- Propietario de `includes`, `index.php` y `sites`: anotarlo. En staging es
  `www-data:www-data`. El paso 4.1 lo respeta automáticamente.
- `php5.6.load` y `mpm_prefork.load`: son los módulos de Apache que se van a desactivar.
- `ls: cannot access '/var/www/html/private:'`: **no debe existir** ninguno de los dos
  directorios. Con FPM, si existen, `move_uploaded_file()` deja las subidas ahí, dentro
  del docroot, y la API responde `invalid_file_type` (incidencia de staging del
  2026-10-23). Si existe alguno, revisar qué contiene y borrarlo.

### 2.6 Anotar el estado actual

**Qué hace.** Guarda los valores con los que se compara al final.

```bash
cd /var/www/html
sudo -u www-data drush vget --exact mail_system
sudo -u www-data drush sqlq "SELECT schema_version FROM dr_system WHERE name='myapi'"
sudo -u www-data drush status | grep -E "Drupal version|PHP"
which drush
drush --version
```

**Resultado esperado.** `mail_system` con `default-system = HTMLMailSystem__SmtpMailSystem`
y 24 claves `myapi_*`; Drupal 7.64; Drush 8.4.11 en `/usr/local/bin/drush`. Si `which`
da otra ruta, usar esa en los pasos 3.5, 4.7 y en la vuelta atrás. Si `mail_system` ya está mal, arreglarlo
con `fix_mail_system.php` (paso 5.4) **antes** de la migración, para no confundir causas.

---

## 3. Copias de seguridad (al inicio de la ventana)

Todas las copias van a `~/backups` (`/home/ubuntu/backups`). Crearlo lo primero, porque
los pasos siguientes escriben ahí:

```bash
mkdir -p ~/backups && chmod 700 ~/backups
```

`/home/ubuntu` es `750` en Ubuntu 22.04: `www-data` no puede escribir en él. Por eso
ningún comando de esta sección escribe en `~/backups` como `www-data`.

### 3.1 Modo mantenimiento

**Qué hace.** Impide que los usuarios escriban en la base de datos mientras se migra, y
así el snapshot del paso siguiente queda consistente con lo que hay en disco.

```bash
cd /var/www/html && sudo -u www-data drush vset maintenance_mode 1
```

**Resultado esperado.** La web muestra la página de mantenimiento. La API responde 503 a
las peticiones de la app.

### 3.2 Pausar el cron de `www-data`

**Qué hace.** El crontab de `www-data` lanza `drush queue-run` cada minuto y `drush cron`
cada hora. Durante la ventana, con el core a medio copiar o PHP a medio cambiar, esos
`drush` fallarían y dejarían ruido en el watchdog. Se guarda el crontab, se quita, y se
restaura en el paso 6.2. El borrado va encadenado a que la copia exista y no esté vacía:
si la copia falla, el cron no se toca.

```bash
sudo crontab -u www-data -l > ~/backups/crontab-www-data.txt \
  && test -s ~/backups/crontab-www-data.txt \
  && sudo crontab -u www-data -r
cat ~/backups/crontab-www-data.txt
sudo crontab -u www-data -l          # no crontab for www-data
```

**Resultado esperado.** La copia muestra las tres líneas (`queue-run myapi_onesignal_push`,
`queue-run myapi_mail_send`, `cron`) y el `-l` final dice `no crontab for www-data`.

**Si falla.** Si `crontab -l` dice `no crontab for www-data`, el cron no está en ese
usuario: buscarlo en `/etc/cron.d/` y en `sudo crontab -l`, y pausarlo ahí.

### 3.3 Snapshot de la instancia en Lightsail

**Qué hace.** Es la vuelta atrás más rápida y completa: disco entero, incluida la base de
datos. Se hace **después** del modo mantenimiento para que no quede fuera ningún dato.

Desde la consola: Instancia → pestaña *Snapshots* → *Create snapshot*. O con la CLI de
AWS, si está configurada en el portátil:
```bash
aws lightsail create-instance-snapshot --instance-name <nombre> --instance-snapshot-name pre-php83-$(date +%Y%m%d)
```

**Resultado esperado.** Snapshot en estado *Available* antes de seguir (tarda unos
minutos).

### 3.4 Backup de base de datos y ficheros

**Qué hace.** Copia independiente del snapshot, más rápida de restaurar parcialmente.

Los dos volcados se hacen con `mysqldump`, no con `drush sql-dump`, por dos motivos:
- `drush sql-dump` corre como `www-data`, que no puede escribir en `~/backups`.
- `drush sql-dump --tables-list=X` **se ignora** en este Drush y vuelca la base entera
  (1,5 GB en dos minutos en staging).

`mysqldump` necesita credenciales, y en el servidor no hay `~/.my.cnf`. Se sacan de
`settings.php` a un fichero de opciones temporal con permisos `600`. Así la contraseña no
queda en el historial de la shell ni se ve en `ps`. El fichero se reutiliza en el paso
5.3 y en la vuelta atrás, y se borra en la sección 7.

```bash
install -m 600 /dev/null ~/backups/mysql-prod.cnf
cd /var/www/html
sudo -u www-data drush php-eval '$d = Database::getConnectionInfo(); $d = $d["default"];
  echo "[client]\nuser=" . $d["username"] . "\npassword=\"" . addcslashes($d["password"], "\"\\") . "\"\nhost=" . $d["host"] . "\n";
  if (!empty($d["port"])) { echo "port=" . $d["port"] . "\n"; }' >> ~/backups/mysql-prod.cnf
DB=$(sudo -u www-data drush php-eval '$d = Database::getConnectionInfo(); echo $d["default"]["database"];')
echo "$DB"
mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" -e "SELECT COUNT(*) FROM dr_rules_config"
```

**Resultado esperado.** `$DB` muestra el nombre de la base y la consulta devuelve un
número. La contraseña va entre comillas dobles y con `"` y `\` escapadas por
`addcslashes()`: los ficheros de opciones de MySQL interpretan secuencias de escape
(`\\`, `\n`, `\t`, `\s`…) incluso entre comillas, y sin escapar una barra invertida la
contraseña llegaría mal. El charlist de `addcslashes()` va entre comillas dobles de PHP
porque todo el código está dentro de comillas simples de bash. Si aun así falla con
`Access denied`, comparar el fichero con `settings.php`.

```bash
TS=$(date +%Y%m%d-%H%M)
mysqldump --defaults-extra-file=$HOME/backups/mysql-prod.cnf --single-transaction "$DB" | gzip > ~/backups/db-$TS.sql.gz
mysqldump --defaults-extra-file=$HOME/backups/mysql-prod.cnf --single-transaction "$DB" dr_rules_config > ~/backups/rules_config-$TS.sql
sudo tar czf ~/backups/files-$TS.tgz -C /var/www/html sites/default/files -C / var/www/private
ls -lh ~/backups/
gzip -t ~/backups/db-$TS.sql.gz && echo OK
```

**Resultado esperado.** Los tres ficheros con tamaño razonable (la base de staging pesa
unos 1,5 GB sin comprimir) y `OK`. `--defaults-extra-file` tiene que ser la primera
opción de `mysql` y `mysqldump`, o se ignora.

La copia de `dr_rules_config` es la vuelta atrás del paso 5.3 (las 13 reglas con PHP
embebido viven en esa tabla, no en ninguna feature).

### 3.5 Copias de lo que la migración sobrescribe o purga

```bash
sudo cp -a /var/www/html/.htaccess  ~/backups/htaccess.pre-7.103
sudo cp -a /var/www/html/robots.txt ~/backups/robots.txt.pre-7.103
sudo tar czf ~/backups/etc-php-5.6.tgz /etc/php/5.6
sudo tar czf ~/backups/etc-apache2.tgz /etc/apache2
sudo cp -a /usr/local/bin/drush ~/backups/drush-8.4.11
cp /var/www/html/sites/all/modules/entityreference_count/entityreference_count.module ~/backups/
```

La copia de Drush hace falta para volver a PHP 5.6: la 8.4.12 que se instala en el paso
4.7 no parsea en 5.6.

---

## 4. Core y PHP

### 4.1 Actualizar el core a Drupal 7.103 (todavía en PHP 5.6)

**Qué hace.** Sigue el procedimiento de `UPGRADE.txt`: quitar los ficheros del core
viejo **salvo `sites/`**, copiar los nuevos, ejecutar las actualizaciones de base de
datos. `sites/` contiene `settings.php`, `files/` y `sites/all/modules` (todo lo contrib y
`myapi`): no se toca.

Antes, comprobar qué tiene de propio el `.htaccess` actual, porque se va a sustituir por
el de serie:

```bash
diff /tmp/drupal-7.64/.htaccess  ~/backups/htaccess.pre-7.103
diff /tmp/drupal-7.64/robots.txt ~/backups/robots.txt.pre-7.103
```

**Resultado esperado.** En el `.htaccess` deben salir solo los bloques `php7_module` y
`lsapi_module` que dejó cPanel (líneas 150 a 175) y las líneas `# CVE-2026 mitigation:`.
Los tres sobran con FPM: el `.htaccess` de serie de la 7.103 vale tal cual (staging lo usa
sin modificar). `robots.txt` no debe dar diferencias. **Si sale algo más** (una
redirección propia, una regla de caché), apuntarlo para reaplicarlo sobre el nuevo.

Sustitución del core. Se borra una **lista explícita** de ficheros del core 7.64, no
`*.php` ni `*.txt`, para no llevarse ficheros propios del docroot (los que listó el
`comm` del paso 2.5). `.gitlab-ci*` de la 7.103 no se copia: no lo usa el sitio.

```bash
cd /var/www/html
OWNER=$(stat -c %U:%G index.php)
CORE_DIRS="includes misc modules profiles scripts themes"
CORE_FILES=".htaccess .editorconfig .gitignore web.config robots.txt \
  authorize.php cron.php index.php install.php update.php xmlrpc.php \
  CHANGELOG.txt COPYRIGHT.txt INSTALL.txt INSTALL.mysql.txt INSTALL.pgsql.txt \
  INSTALL.sqlite.txt LICENSE.txt MAINTAINERS.txt README.txt UPGRADE.txt"
sudo rm -rf $CORE_DIRS $CORE_FILES
for f in $CORE_DIRS $CORE_FILES; do sudo cp -a "/tmp/drupal-7.103/$f" .; done
sudo chown -R "$OWNER" $CORE_DIRS $CORE_FILES
comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.103 | sort)   # lo mismo que en 2.5
sudo -u www-data drush updb -y
sudo -u www-data drush cc all
sudo -u www-data drush status | grep "Drupal version"
```

**Resultado esperado.** El `comm` final lista lo mismo que en el paso 2.5: nada propio se
ha perdido. `drush updb` lista 8 updates (`system` 7082 a 7088, `user` 7020) y termina sin
errores. `Drupal version : 7.103`. Si en el paso 2.5 se guardó algún fichero modificado o
un perfil propio, reponerlo ahora desde `~/backups`.

**Si falla.**
- `updb` se corta con `Drush command terminated abnormally`: casi siempre es la caché de
  Drush de `www-data` con rutas al phar. `sudo rm -rf /tmp/drush-www-data` y repetir.
- Un update falla a mitad: `drush updb` es reanudable; corregir la causa y volver a
  lanzarlo. Si no hay forma, restaurar el snapshot (sección 8).

### 4.2 `trusted_host_patterns` (recomendado)

**Qué hace.** Impide que Drupal acepte peticiones con una cabecera `Host` falsa. No es
obligatorio, pero staging lo tiene y la 7.103 lo documenta. Añadir al final de
`sites/default/settings.php`:

```php
$conf['trusted_host_patterns'] = array('^sistema\.crespcord\.com$');
```

**Si falla.** Si después el sitio responde «The provided host name is not valid for this
server», el patrón no coincide con el dominio real: revisar el `ServerName` del vhost.

### 4.3 Instalar PHP 8.3 con PHP-FPM y cambiar el MPM de Apache

**Qué hace.** Instala los 13 paquetes de staging, desactiva `mod_php` y `prefork`, activa
`event` + `proxy_fcgi` y la configuración que trae el paquete `php8.3-fpm`, que redirige
los `.php` al socket de FPM. `mpm_prefork` hay que desactivarlo **antes** de activar
`mpm_event`, o `a2enmod` se niega.

```bash
sudo apt install -y php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-intl \
  php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap php8.3-xml php8.3-zip
sudo a2dismod php5.6 mpm_prefork
sudo a2enmod mpm_event proxy proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
sudo update-alternatives --set php /usr/bin/php8.3
php -v
```

**Resultado esperado.** `PHP 8.3.36 (cli)`. Todavía **no** se reinicia Apache: faltan los
dos pasos siguientes.

**Qué pasa con la configuración antigua.** El MPM `event` ignora `mpm_prefork.conf`, así
que los ajustes `MaxRequestWorkers 30` y `MaxConnectionsPerChild 1` de la mitigación CVE
dejan de aplicarse solos. Staging usa `mpm_event.conf` de serie (`MaxRequestWorkers 150`,
25 hilos por proceso). Con FPM, el límite real de procesos PHP ya no lo pone Apache sino
`pm.max_children` (paso 4.5).

### 4.4 `CGIPassAuth On`

**Qué hace.** Con `mod_php`, PHP recibe la cabecera `Authorization` directamente. Con FPM
(CGI), Apache la **descarta** por defecto, `$_SERVER['HTTP_AUTHORIZATION']` llega vacío y
`myapi_auth` responde `missing_authorization`: **toda la app da 401**. `CGIPassAuth On`
obliga a Apache a pasarla. En staging vive en un `conf-available` propio con el
`<Directory>` del docroot; se replica igual:

```bash
sudo tee /etc/apache2/conf-available/drupal-docroot.conf >/dev/null <<'EOF'
<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
    CGIPassAuth On
</Directory>
EOF
sudo a2enconf drupal-docroot
```

Si el vhost actual ya tiene un `<Directory /var/www/html>` con `AllowOverride All`,
basta con añadirle la línea `CGIPassAuth On`; no duplicar el bloque. No usar la
alternativa `SetEnvIf Authorization`: tras el rewrite de Drupal la variable puede llegar
como `REDIRECT_HTTP_AUTHORIZATION`, y el código solo lee `HTTP_AUTHORIZATION`.

### 4.5 Valores de PHP para FPM

**Qué hace.** El `php.ini` de 8.3 es el de serie de Ubuntu (2 MB por fichero, 8 MB por
petición, 128 MB de memoria) y **no hereda nada** del 5.6. Los valores del sitio van en
un fichero aparte en `conf.d/`, como en staging, para que una actualización del paquete
no los pise. `10M` por fichero cubre los 5 MB que admite `myapi`; `40M` por petición
cubre los 5 adjuntos más un fichero de un reclamo. El fichero también define el log de
errores de PHP que se revisa en la sección 6.

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-crespcord.ini >/dev/null <<'EOF'
; Crespcord: valores del sitio (antes en .htaccess, bloque php7_module) y endurecimiento.
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
max_input_vars = 10000
post_max_size = 40M
upload_max_filesize = 10M
max_file_uploads = 10
session.gc_maxlifetime = 1440
session.upload_progress.enabled = 0
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php8.3-fpm-errors.log
expose_php = Off
zlib.output_compression = Off
EOF
sudo touch /var/log/php8.3-fpm-errors.log && sudo chown www-data:adm /var/log/php8.3-fpm-errors.log
```

**Pool de FPM** (`/etc/php/8.3/fpm/pool.d/www.conf`). El fichero de serie trae
`pm.max_children = 5` y `pm.max_requests` comentado. Staging corre con
`pm.max_children = 8` y `pm.max_requests = 500`, que recicla cada proceso tras 500
peticiones y contiene posibles fugas de memoria. Aplicar lo mismo:

```bash
F=/etc/php/8.3/fpm/pool.d/www.conf
sudo cp -a $F $F.orig
sudo sed -i -E 's/^pm\.max_children\s*=.*/pm.max_children = 8/' $F
sudo sed -i -E 's/^;?pm\.max_requests\s*=.*/pm.max_requests = 500/' $F
grep -E "^(pm|pm\.[a-z_]+)\s*=" $F
```

**Resultado esperado.** `pm = dynamic`, `pm.max_children = 8`, `pm.start_servers = 2`,
`pm.min_spare_servers = 1`, `pm.max_spare_servers = 3`, `pm.max_requests = 500`.

**Por qué `memory_limit = 512M` con 8 procesos.** `memory_limit` es un **techo por
proceso**, no lo que cada proceso consume. La medida real en producción es de unos 56 MB
de RSS por proceso de PHP (`docs/mitigacion-php-cve-2026-10.md`), y staging probó con
512M los PDF de alícuotas, las exportaciones a Excel y Feeds, que son las tareas que más
memoria piden. Se queda en 512M para no romper esas tareas.

**El riesgo que hay que vigilar.** 8 × 512M son 4 GB, más que los 3,8 GB de RAM de
producción, que además comparte con MariaDB. Hace falta que varios procesos lleguen al
techo a la vez, algo que no se ha visto, pero si alguna vez aparece un `Out of memory`
en `dmesg`, el *OOM killer* mata a MariaDB o el servidor empieza a usar swap: **bajar
primero `pm.max_children`** (a 6, por ejemplo), no `memory_limit`. Bajar
`memory_limit` rompe los PDF y las exportaciones; bajar `pm.max_children` solo hace que
las peticiones esperen en cola.

Para subir `pm.max_children`, en cambio, la señal es otra: que `/var/log/php8.3-fpm.log`
(el log del *master* de FPM, legible solo por root) avise de
`server reached pm.max_children`.

### 4.6 Reiniciar y comprobar

```bash
sudo apache2ctl configtest
sudo systemctl restart php8.3-fpm apache2
systemctl is-active apache2 php8.3-fpm
curl -sI https://sistema.crespcord.com/ | head -1
sudo -u www-data drush status | grep -E "Drupal version|Database"
```

**Resultado esperado.**
- `Syntax OK`, los dos servicios `active`.
- La portada responde **`503`**: es la página de mantenimiento de Drupal, y que salga
  significa que Drupal arranca en PHP 8.3 bajo FPM. Un `502` o un `500` es un fallo (ver
  abajo).
- `Drupal version : 7.103` y `Database : Connected`.

**La cabecera `Authorization` no se puede comprobar con la API en este momento.** Con el
sitio en mantenimiento, Drupal responde 503 a **toda** petición anónima, incluidas
`/api/v1/ping` y `/api/v1/units`: `myapi` no tiene ningún bypass de mantenimiento. Pero
si `CGIPassAuth` falla, toda la app da 401, y no se vería hasta abrir el sitio en el paso
6.2. Por eso se comprueba aquí con un script temporal fuera de Drupal, con la misma
técnica que la verificación de la mitigación CVE: nombre aleatorio, solo responde a
`127.0.0.1`, se llama desde el propio servidor y se borra al terminar. Al ser un fichero
que existe, Apache lo sirve sin pasar por Drupal ni por el modo mantenimiento.

```bash
N=authcheck-$(openssl rand -hex 8).php
sudo tee /var/www/html/$N >/dev/null <<'EOF'
<?php
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') { http_response_code(404); exit; }
header('Content-Type: text/plain');
echo 'HTTP_AUTHORIZATION=', isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '(missing)', "\n";
echo 'PHP=', PHP_VERSION, ' SAPI=', PHP_SAPI, "\n";
echo 'upload_max_filesize=', ini_get('upload_max_filesize'), ' post_max_size=', ini_get('post_max_size'), ' memory_limit=', ini_get('memory_limit'), "\n";
EOF
curl -s --resolve sistema.crespcord.com:443:127.0.0.1 -H "Authorization: Bearer x" https://sistema.crespcord.com/$N
sudo rm /var/www/html/$N
ls /var/www/html/authcheck-* 2>/dev/null       # vacío: el script ya no existe
```

**Resultado esperado.**
```
HTTP_AUTHORIZATION=Bearer x
PHP=8.3.36 SAPI=fpm-fcgi
upload_max_filesize=10M post_max_size=40M memory_limit=512M
```
- `HTTP_AUTHORIZATION=(missing)`: Apache se come la cabecera. Revisar el paso 4.4 **antes
  de seguir**.
- `SAPI=apache2handler` o `PHP=5.6.40`: sigue cargado `mod_php`. Revisar el paso 4.3.
- Límites de 2M/8M/128M: no se lee el `99-crespcord.ini`. Revisar el paso 4.5.

Este mismo script resuelve también la comprobación de límites del paso 6.1.

**Si falla.**
- `configtest` se queja de `php_value`/`php_flag`: hay un bloque de `.htaccess` o del
  vhost con directivas de `mod_php` fuera de un `<IfModule>`. Localizarlo con
  `grep -rn php_value /etc/apache2 /var/www/html/.htaccess` y envolverlo o quitarlo.
- 502 en todas las páginas: FPM no escucha en el socket. `systemctl status php8.3-fpm` y
  `ls -l /run/php/php8.3-fpm.sock`.
- Página en blanco o 500: `sudo tail /var/log/php8.3-fpm-errors.log` y
  `sudo tail /var/log/apache2/error.log`.

### 4.7 Drush a la 8.4.12

**Qué hace.** La 8.4.11 se eligió en su día porque el `.phar` de la 8.4.12 trae sintaxis
PHP 7 y no parsea en 5.6. En PHP 8.3 eso ya no es problema, y staging corre la **8.4.12**
sin un solo aviso. Al sustituir el phar hay que borrar la caché de Drush de `www-data`,
que guarda rutas internas al fichero anterior; si no, todo comando que arranca Drupal
entero se corta con `Drush command terminated abnormally`.

```bash
cd /tmp && curl -fLO https://github.com/drush-ops/drush/releases/download/8.4.12/drush.phar
php drush.phar --version
sudo mv drush.phar /usr/local/bin/drush && sudo chmod +x /usr/local/bin/drush
sudo rm -rf /tmp/drush-www-data
cd /var/www/html && sudo -u www-data drush status | grep -E "Drupal version|PHP configuration"
```

**Resultado esperado.** `Drush Version : 8.4.12`, `Drupal version : 7.103`,
`PHP configuration : /etc/php/8.3/cli/php.ini`.

### 4.8 Retirar las mitigaciones temporales de los CVE

**Qué hace.** Las mitigaciones del 2026-10-22 reducían la superficie de ataque de un PHP
sin parche. Con PHP 8.3.36 sobran. De las cuatro, dos ya han desaparecido solas: el
`.htaccess` quedó limpio al actualizar el core (paso 4.1) y `mpm_prefork.conf` ya no se
carga (paso 4.3). El `.ini` de `/etc/php/5.6/apache2/conf.d/` se irá con el purge
(sección 7). Queda el `conf` de Apache con `LimitRequestBody`:

```bash
sudo a2disconf cve-2026-mitigation && sudo rm /etc/apache2/conf-available/cve-2026-mitigation.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

---

## 5. Ajustes que PHP 8 exige

Los tres fallaron en staging. Son silenciosos en PHP 5.6 y ruidosos o fatales en PHP 8.

### 5.1 Permisos del módulo `myapi`

**Qué hace.** `scp -r` deja `includes/`, `resources/` y `assets/` como `700`. Ningún
usuario distinto de `www-data` puede leerlos. Eso hace fallar `drush cc all` con
`opendir ... Failed to open directory` y, lo grave, hace que `mailsystem` no encuentre la
clase `MyapiHtmlMailSystem` y borre todas las claves `myapi_*` de `mail_system`.
`scripts/deploy.sh` ya corrige los permisos desde el 2026-10-26, pero el servidor de
producción se desplegó antes.

```bash
sudo find /var/www/html/sites/all/modules/myapi -type d -exec chmod 755 {} +
sudo find /var/www/html/sites/all/modules/myapi -type f -exec chmod 644 {} +
ls -ld /var/www/html/sites/all/modules/myapi/includes
```

**Resultado esperado.** `drwxr-xr-x`.

### 5.2 Parche a `entityreference_count`

**Qué hace.** El campo `field_payphone` del condominio guarda su configuración como el
array crudo de los checkboxes del formulario, con los no marcados a `0`. El módulo
`entityreference_count` 7.x-1.0-beta1 hace `field_info_field(0)` y lee `storage` de un
`null`. En PHP 5.6 era silencioso; en PHP 8 son 16 warnings por cada pago editado. No
existe parche en drupal.org (la cola del módulo no tiene ningún ticket de PHP 8), así
que se aplica el del repo.

```bash
# desde el portátil
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem scripts/php8/entityreference_count-php8-null-field.patch ubuntu@<IP_PROD>:/tmp/
# en el servidor
cd /var/www/html && sudo patch -p1 < /tmp/entityreference_count-php8-null-field.patch
M=sites/all/modules/entityreference_count
sudo chown --reference=$M/entityreference_count.info $M/entityreference_count.module
sudo chmod --reference=$M/entityreference_count.info $M/entityreference_count.module
ls -l $M/entityreference_count.module $M/entityreference_count.info
php -l $M/entityreference_count.module
```

`patch` escribe un fichero nuevo, que queda con `root` como propietario. Funciona igual,
pero los `chown`/`chmod` lo devuelven al propietario y permisos del resto del módulo.

**Resultado esperado.** `patching file sites/all/modules/entityreference_count/entityreference_count.module`,
los dos ficheros del `ls` con el mismo propietario y permisos, y
`No syntax errors detected`.

**Si falla.** `Hunk FAILED`: la versión del módulo en producción no es la beta1. Comparar
con `~/backups/entityreference_count.module` y aplicar el cambio a mano. El parche toca
dos líneas de la función que cuenta referencias (hacia la línea 320): el `foreach` pasa a
recorrer `array_filter($field_names)`, para saltar los `0` de los checkboxes sin marcar,
y la comparación de `$field['storage']['type']` queda protegida con `!empty()`.

### 5.3 PHP embebido en Rules

**Qué hace.** Las plantillas de correo y los cálculos de alícuota de las reglas hacen
`$node->field_x['und'][0]['value']` sobre campos vacíos y `round(null, 4)`. En PHP 8 son
«Undefined array key», «Trying to access array offset on null» y la deprecación de
`round()` con `null`. El script `fix_rules_php.php` reescribe esos accesos con `isset()`
/ `!empty()`, castea a `(float)` antes de `round()`, pasa `php -l` a cada fragmento y,
sin `apply`, **solo simula**. En staging cambió 13 reglas; en producción puede haber más
o menos. **Leer la simulación antes de aplicar.**

```bash
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem scripts/php8/fix_rules_php.php ubuntu@<IP_PROD>:/tmp/
cd /var/www/html
sudo -u www-data drush php-script /tmp/fix_rules_php.php          # simulación: lista reglas y cambios
sudo -u www-data drush php-script /tmp/fix_rules_php.php apply    # escribe en dr_rules_config
sudo -u www-data drush cc all
```

**Resultado esperado.** La simulación imprime una línea `RULE <nombre> (<etiqueta>)` por
regla afectada y, debajo, cada elemento modificado con el recuento de sustituciones por
tipo (`value_num`, `value_echo`, `notempty`, `round`…). Termina con
`DRY RUN: N rules would change`. **No debe aparecer ninguna línea `!! LINT FAILED`**: es
el `php -l` de cada fragmento reescrito. Con `apply`, cada regla añade `saved` y el
resumen pasa a `APPLIED: N rules would change`.

**Si falla.** Si aparece `!! LINT FAILED` en la simulación, **no aplicar**: copiar el
nombre de la regla y corregirla a mano en `admin/config/workflow/rules` después de
migrar. Si ya se aplicó y una regla falla en ejecución, restaurar la tabla con el dump
del paso 3.4 y vaciar la caché, porque Rules guarda las reglas compiladas en caché:
```bash
mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" < ~/backups/rules_config-<fecha>.sql
cd /var/www/html && sudo -u www-data drush cc all
```
`$DB` es la variable del paso 3.4. Si la sesión de shell es nueva, volver a definirla con
la línea `DB=$(...)` de ese paso.

### 5.4 Correo saliente

**Qué hace.** Comprueba que el sistema de correo sigue siendo
`HTMLMailSystem__SmtpMailSystem` con las 24 claves `myapi_*`, lo repara si no, y envía un
correo de prueba. **Siempre después del paso 5.1**: si los permisos siguen mal, el
siguiente `drush` lo deshace.

```bash
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem scripts/php8/fix_mail_system.php ubuntu@<IP_PROD>:/tmp/
cd /var/www/html && sudo -u www-data drush php-script /tmp/fix_mail_system.php test
```

**Resultado esperado.** El script imprime el `mail_system` antes y después
(`after (db): default-system=HTMLMailSystem__SmtpMailSystem, htmlmail=..., myapi keys=24`),
la clase por la que sale `payment_created_admin`, los datos SMTP configurados y, con
`test`, `test mail to <dirección>: sent (result=true)`. La dirección de prueba está fija
en el script; cambiarla ahí si hace falta. Comprobar que el correo llega de verdad.

**Si falla.** `FAILED (result=false)`: revisar `sudo -u www-data drush watchdog-show --type=smtp`
y las credenciales en `admin/config/system/smtp`. Si `myapi keys` es menor que 24 o
`default-system` vuelve a `DefaultMailSystem` en una ejecución posterior, algún `drush`
se ejecutó como `ubuntu` con los permisos aún mal: repetir 5.1 y 5.4.

---

## 6. Verificación y apertura

### 6.1 Límites de PHP reales en FPM

**Qué hace.** Confirma que el `99-crespcord.ini` se está aplicando. Ya se vio con el
script temporal del paso 4.6; aquí solo se repite si en medio se ha tocado la
configuración de PHP. **Ojo:** `drush` lee `/etc/php/8.3/cli/php.ini`, no el de FPM, así
que `drush php-eval "ini_get(...)"` **no sirve**: en staging devuelve los 2M/8M de serie
aunque FPM corra con 10M/40M. Hay que mirarlo desde una petición web: el script del paso
4.6, `admin/reports/status` → *Información de PHP* (buscar `upload_max_filesize`,
`post_max_size`, `memory_limit`), o subir un comprobante de más de 2 MB en el paso 6.3.

### 6.2 Abrir el sitio y restaurar el cron

```bash
cd /var/www/html && sudo -u www-data drush vset maintenance_mode 0
sudo crontab -u www-data ~/backups/crontab-www-data.txt
sudo crontab -u www-data -l
```

**Resultado esperado.** Las tres líneas del cron (`queue-run myapi_onesignal_push`,
`queue-run myapi_mail_send`, `cron`) de vuelta. Al minuto siguiente,
`sudo -u www-data drush watchdog-show --type=php` sigue vacío.

### 6.3 Smoke test

Es el de la sección 7 de `docs/produccion-checklist.md`, con dos añadidos propios de esta
migración (marcados con ★).

```bash
cd /var/www/html
sudo -u www-data drush queue-list                  # las dos colas
sudo -u www-data drush watchdog-show --type=myapi
curl -s https://sistema.crespcord.com/api/v1/ping
curl -s -H "Authorization: Bearer x" https://sistema.crespcord.com/api/v1/units   # invalid_token
```

Desde la app:

1. ★ **Los usuarios del backoffice tienen que volver a iniciar sesión** (update 7086).
   Entrar con un administrador y comprobar que el panel carga.
2. Login en la app y `GET /api/v1/units`. Si da 401, revisar el paso 4.4.
3. Registrar un pago con un comprobante de **unos 4 MB** (más de los 2 MB de serie:
   comprueba `upload_max_filesize`). Se crea en «Pendiente de verificar».
4. ★ Confirmar en el servidor que el fichero está donde debe y que FPM no ha creado el
   directorio trampa:
   ```bash
   ls -lt /var/www/private/comprobantes_pago/ | head -3
   ls -ld "/var/www/html/private:"            # debe seguir sin existir
   ```
5. Crear un reclamo con varias fotos de unos 4 MB (comprueba `post_max_size`).
6. `GET /api/v1/payment-methods` muestra los métodos con su tipo.
7. Aprobar el pago en el backoffice: llega el push y el saldo se aplica una sola vez.
8. Editar un pago, aprobarlo y generar un recibo con alícuota extra (ejercita el parche
   de `entityreference_count` y las reglas reescritas).
9. Olvidé mi contraseña: llega el correo, abre la página de reset y redirige a la app.
10. Chat: `POST /api/v1/chat/token` devuelve el token (no 503) y se puede enviar un mensaje.
11. Bot: `curl -H "X-Api-Key: <BOT_API_KEY>" "https://sistema.crespcord.com/api/v1/bot/person?phone=..."`
    devuelve `condominium_payment_info`.
12. Crear un pago desde la app: llega el correo `payment_created_admin` a cada usuario del
    rol `backend` con dirección real.

Después de todo lo anterior:

```bash
sudo -u www-data drush watchdog-show --type=php     # vacío
sudo -u www-data drush watchdog-show --type=mail    # sin «Error sending e-mail»
sudo tail /var/log/php8.3-fpm-errors.log            # vacío
sudo tail /var/log/apache2/error.log                # sin fatales de PHP
```

### 6.4 Ventana de 24 horas

**Qué hace.** PHP 8 convierte en warnings cosas que PHP 5.6 callaba, y solo aparecen al
usar cada pantalla. Un día de uso real del backoffice con el watchdog limpio es la
prueba de que no queda nada. La lista de pruebas de staging sirve de guion: reportes,
exportaciones a Excel, PDF de alícuotas, Feeds, CKEditor, VBO.

Revisar a las 24 horas:
```bash
sudo -u www-data drush watchdog-show --type=php --count=50
sudo tail -50 /var/log/php8.3-fpm-errors.log
sudo grep -c "pm.max_children" /var/log/php8.3-fpm.log     # 0; si no, subir el pool (paso 4.5)
```

Si aparece un warning nuevo de un módulo contrib, anotarlo con su ruta y fichero, y
tratarlo como el de `entityreference_count` (parche en `scripts/php8/`).

---

## 7. Cierre: purgar PHP 5.6

**Qué hace.** Solo tras la ventana de 24 horas. Elimina los paquetes de PHP 5.6 y el
módulo de Apache para que nadie pueda reactivarlos con `a2enmod php5.6`. Se lleva
también el `99-cve-2026-mitigation.ini` de `/etc/php/5.6/apache2/conf.d/`. La copia de
`/etc/php/5.6` está en `~/backups` desde el paso 3.5. Es lo mismo que se hizo en staging
con el 7.4.

Se usa `apt-get` y no `apt`. Es el mismo comando que se usó en staging con el 7.4. En apt
2.x (producción tiene Ubuntu 22.04, apt 2.4), `apt` ya no acepta comodines estilo
`php5.6*` en los nombres de paquete, solo patrones `?name(...)`; `apt-get` sí los acepta.

```bash
sudo apt-get purge -y 'php5.6*' libapache2-mod-php5.6 && sudo apt-get autoremove -y
dpkg -l 'php5.6*' | grep ^ii
sudo apache2ctl configtest && systemctl is-active apache2 php8.3-fpm
php -v
```

**Resultado esperado.** La lista de `dpkg` vacía, `Syntax OK`, los dos servicios activos,
`PHP 8.3.36`. El bloque `<IfModule mod_php5.c>` del `.htaccess` puede quedarse: con FPM
no se evalúa.

Borrar el fichero de credenciales del paso 3.4, que ya no hace falta:

```bash
shred -u ~/backups/mysql-prod.cnf
```

Cuando el sitio lleve unos días estable, borrar el snapshot de Lightsail del paso 3.3
(tiene coste mensual).

---

## 8. Vuelta atrás

Elegir la fila según el último paso que se completó. Todas asumen que PHP 5.6 sigue
instalado, es decir, que no se ha llegado a la sección 7.

### 8.1 Restaurar el snapshot (vuelta atrás completa)

Para cualquier fallo durante la ventana que no haya tiempo de diagnosticar, y la **única
vuelta atrás fiable del core** (ver 8.2). Restaurar el snapshot del paso 3.3 sobre una
instancia nueva desde la consola de Lightsail y mover a ella la IP estática. Como el
sitio estaba en mantenimiento desde antes del snapshot, no se pierde ningún dato.

La instancia restaurada arranca **tal como estaba al hacer el snapshot**: en modo
mantenimiento y sin crontab de `www-data` (pasos 3.1 y 3.2). Hay que deshacer las dos
cosas:

```bash
cd /var/www/html && sudo -u www-data drush vset maintenance_mode 0
sudo crontab -u www-data ~/backups/crontab-www-data.txt
sudo crontab -u www-data -l          # las tres líneas
drush --version                      # 8.4.11
curl -s -H "Authorization: Bearer x" https://sistema.crespcord.com/api/v1/units   # invalid_token
```

### 8.2 El core 7.103 falla (paso 4.1)

**Volver a copiar el core 7.64 encima no basta.** Los updates ya han cambiado la base de
datos de forma que la 7.64 no entiende:
- 7086 cambió el esquema y los datos de la tabla de sesiones.
- 7087 dejó `announcements_feed` habilitado en `dr_system`, y la 7.64 no tiene ese
  módulo: daría avisos de módulo que falta en cada petición.

Opciones, de mejor a peor:
1. Restaurar el snapshot (8.1).
2. Restaurar la base de datos del paso 3.4 **y** el core 7.64, con el sitio en
   mantenimiento:
   ```bash
   gunzip -c ~/backups/db-<fecha>.sql.gz | mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB"
   cd /var/www/html
   sudo rm -rf $CORE_DIRS $CORE_FILES
   for f in $CORE_DIRS $CORE_FILES; do sudo cp -a "/tmp/drupal-7.64/$f" .; done
   sudo chown -R "$OWNER" $CORE_DIRS $CORE_FILES
   sudo cp -a ~/backups/htaccess.pre-7.103 .htaccess
   sudo cp -a ~/backups/robots.txt.pre-7.103 robots.txt
   sudo -u www-data drush cc all
   sudo -u www-data drush status | grep "Drupal version"     # 7.64
   ```
   `$CORE_DIRS`, `$CORE_FILES` y `$OWNER` son los del paso 4.1, y `$DB` el del 3.4. En una
   shell nueva, volver a definirlos. Después, salir de mantenimiento y restaurar el cron
   como en 8.1.

### 8.3 Apache, FPM o PHP 8.3 fallan (pasos 4.3 a 4.8)

Deja el servidor como antes del paso 4.3, con el core 7.103 (que funciona en PHP 5.6):

```bash
# Apache otra vez con mod_php 5.6 y prefork
sudo a2dismod mpm_event proxy_fcgi
sudo a2disconf php8.3-fpm drupal-docroot
sudo a2enmod mpm_prefork php5.6
sudo systemctl stop php8.3-fpm
sudo systemctl disable php8.3-fpm        # si no, vuelve a arrancar en cada reinicio aunque Apache no lo use

# PHP de la terminal y Drush
sudo update-alternatives --set php /usr/bin/php5.6
sudo cp -a ~/backups/drush-8.4.11 /usr/local/bin/drush
sudo rm -rf /tmp/drush-www-data
php -v                                   # PHP 5.6.40
drush --version                          # 8.4.11

# Mitigación CVE: hace falta otra vez con PHP 5.6
sudo tar xzf ~/backups/etc-apache2.tgz -C / etc/apache2/conf-available/cve-2026-mitigation.conf
sudo a2enconf cve-2026-mitigation
grep -E "MaxRequestWorkers|MaxConnectionsPerChild" /etc/apache2/mods-available/mpm_prefork.conf
# ↑ debe seguir en 30 y 1: el paso 4.3 no tocó el fichero, solo dejó de cargarlo

sudo apache2ctl configtest && sudo systemctl restart apache2
```

Falta reaplicar el **paso 4 de la mitigación** (comentar el bloque `<IfModule mod_php5.c>`
del `.htaccess`). El `.htaccess` de la 7.103 lo trae activo, y con `mod_php` vuelve a
aplicarse. Usar el comando de `docs/mitigacion-php-cve-2026-10.md`, comprobando antes
con `grep -n "IfModule mod_php5" /var/www/html/.htaccess` que el bloque sigue en las
líneas 32 a 40. El `.ini` de `/etc/php/5.6/apache2/conf.d/` no se tocó, así que sigue
activo.

Si en el paso 4.2 se añadió `trusted_host_patterns`, puede quedarse: no depende de la
versión de PHP. Salir de mantenimiento y restaurar el cron como en 8.1.

### 8.4 Fallos puntuales de la sección 5

| Qué ha fallado | Cómo volver |
|---|---|
| Las reglas de Rules (paso 5.3) | `mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" < ~/backups/rules_config-<fecha>.sql` y después `sudo -u www-data drush cc all`. |
| El parche de `entityreference_count` (paso 5.2) | `sudo cp -a ~/backups/entityreference_count.module sites/all/modules/entityreference_count/` y `drush cc all`. |
| El correo deja de salir (paso 5.4) | Repetir 5.1 y después `fix_mail_system.php test`. |
