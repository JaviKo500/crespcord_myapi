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

**Se ejecutó en producción el 2026-10-28** (ventana de 14:30 a 15:35 UTC). Lo que salió
distinto de lo previsto ya está corregido en los pasos de esta guía; el resumen, con el
porqué de cada cambio, está en la sección 9. El más importante: **los módulos contrib de
producción no eran los de staging** (paso 2.7 y 4.9).

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

Nada de este bloque cambia el servidor, salvo retirar del docroot los ficheros sensibles
que el paso 2.5 encuentre descargables. Se puede hacer con antelación, y conviene: el
paso 2.7 puede destapar trabajo (módulos contrib que alinear) que no cabe improvisar en
la ventana.

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
# core modificado: comm solo compara nombres; esto compara contenido
CORE_DIRS="includes misc modules profiles scripts themes"
CORE_FILES=".htaccess .editorconfig .gitignore web.config robots.txt \
  authorize.php cron.php index.php install.php update.php xmlrpc.php \
  CHANGELOG.txt COPYRIGHT.txt INSTALL.txt INSTALL.mysql.txt INSTALL.pgsql.txt \
  INSTALL.sqlite.txt LICENSE.txt MAINTAINERS.txt README.txt UPGRADE.txt"
for f in $CORE_DIRS $CORE_FILES; do sudo diff -rq "/tmp/drupal-7.64/$f" "/var/www/html/$f"; done 2>&1
```

**Resultado esperado.**
- `comm` lista lo que hay en el docroot y no en la 7.64. Nada de eso se borra en el paso
  4.1, porque allí se borra una lista explícita de ficheros del core. Aun así, anotarlo:
  es lo que hay que conservar si alguna vez se restaura el core desde cero.
- `comm` **no** detecta un fichero del core modificado (mismo nombre); para eso está el
  `diff -rq`. Un `Files ... differ` en un `.php` o un `.inc` es una modificación local:
  mirarla con `diff` y, si es un parche propio, copiarlo a `~/backups` y reaplicarlo tras
  el 4.1. En producción salieron `common.inc` y `request-sanitizer.inc` con un `is_int()`
  / `is_string()` de compatibilidad con PHP 7, que la 7.103 ya trae.
- `Only in /var/www/html/modules/...` con `.php` de nombre aleatorio (`acqumwek.php`) o
  de WordPress (`wp-login.php`, `admin-ajax.php`) son **restos de malware**. En producción
  eran 105 ficheros de 0 bytes y dos de 168 bytes con el texto «artifacts of previous
  malicious infection… dangerous code has been removed», neutralizados por el hosting
  anterior en 2024. Antes de seguir: abrirlos con `head -c 300 | cat -v` (sin ejecutarlos),
  buscar `eval`/`base64_decode`/`$_POST` ejecutable y peticiones en
  `/var/log/apache2/access.log*`. Si tienen código, **parar**: es un incidente, no una
  migración. Los de `modules/` desaparecen en el 4.1; los de `sites/default/files/` no, y
  hay que borrarlos a mano (paso 4.1).
- `diff: ... authorize.php: No such file or directory` (o `xmlrpc.php`): alguien los quitó.
  No reponerlos en el 4.1 (ver allí).
- `profiles/` solo con `README.txt minimal standard testing` (así está staging). Si hay
  un perfil propio, copiarlo a `~/backups` antes del paso 4.1 y reponerlo después.
- Propietario de `includes`, `index.php` y `sites`: anotarlo. En staging es
  `www-data:www-data`. El paso 4.1 lo respeta automáticamente.
- `php5.6.load` y `mpm_prefork.load`: son los módulos de Apache que se van a desactivar.
- `ls: cannot access '/var/www/html/private:'`: **no debe existir** ninguno de los dos
  directorios. Con FPM, si existen, `move_uploaded_file()` deja las subidas ahí, dentro
  del docroot, y la API responde `invalid_file_type` (incidencia de staging del
  2026-10-23). Si existe alguno, revisar qué contiene y borrarlo.

**Ficheros sensibles descargables.** Lo que el `comm` lista como no-core puede ser
accesible por HTTP. El `.htaccess` de Drupal bloquea `.sql`, pero **no** `.sql.gz`,
`.zip`, `.ini.bak` ni `error_log`:

```bash
for f in $(comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.64 | sort)); do
  [ -f "/var/www/html/$f" ] && printf '%-30s %s\n' "$f" "$(curl -s -o /dev/null -w '%{http_code}' "https://sistema.crespcord.com/$f")"
done
sudo find /var/www/html -xdev -name error_log -type f
```

Todo lo que dé `200` y no deba ser público (volcados, copias del sitio, `php.ini`,
`error_log`) se saca del docroot **ese mismo día**, a un directorio de `root`:
`sudo install -d -m 700 /root/incident/docroot-leftovers && sudo mv <fichero> /root/incident/docroot-leftovers/`.
En producción salieron un volcado completo de la base de datos (`pcordero_drupcon.sql.gz`),
una copia entera del sitio con `settings.php` (`condominios.zip`), `php.ini.bak` y cinco
`error_log` de cPanel. Si un volcado o un `settings.php` ha sido descargable, **la
contraseña de la base de datos se rota** (sección 7).

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

Con Drupal 7.64 y el Drush en `.phar`, cada comando imprime una veintena de avisos
`stat(): stat failed for phar:///usr/local/bin/drush/...  PharStreamWrapper.php:453`. Es
ruido del `misc/typo3` de la 7.64 y desaparece con la 7.103: ignorarlo. Por eso todos los
`drush` cuya salida se guarda en un fichero o en una variable llevan `2>/dev/null`.

### 2.7 Comparar los módulos contrib con staging y pasar el lint de PHP 8.3

**Qué hace.** Comprueba que `sites/all` de producción (módulos, librerías, tema) es el
mismo código que staging ya probó en PHP 8.3, fichero a fichero, y que todo compila en
PHP 8.3.

**Por qué.** Staging se clonó de producción, pero después se actualizaron en staging 21
módulos contrib y el tema `bootstrap` sin pasar los cambios a producción. Producción tenía
Views 7.x-3.20, que usa `$x{0}`: en PHP 8 es un **Fatal error** de compilación, que tumba
cualquier vista con un filtro de texto y el propio `drush cc all`. Se descubrió con el
sitio ya en PHP 8.3 (paso 5.3). Esta comprobación lo saca a la luz días antes.

En **cada** servidor (cambiar `prod` por `staging` en los nombres):

```bash
cd /var/www/html
sudo find sites/all -type f -print0 | sort -z | xargs -0 sha256sum > ~/backups/sitesall-prod.sha256
sudo find sites/all -type f \( -name '*.php' -o -name '*.inc' -o -name '*.module' -o -name '*.install' -o -name '*.theme' -o -name '*.engine' \) -print0 \
  | xargs -0 -n1 -P4 sh -c 'php8.3 -l "$0" 2>&1 | grep -E "Fatal error|Parse error"; true' | sort > ~/backups/lint-prod.txt
wc -l < ~/backups/lint-prod.txt
```

En producción, antes del paso 4.3, no hay `php8.3`: el lint se hace en staging sobre una
copia del `sites/all` de producción, o se repite en producción justo después del 4.3.
El `sh -c '...; true'` es imprescindible: `php -l` sale con 255 en un fatal y `xargs`
aborta en el primero.

Comparar en el portátil, agrupando por directorio:

```bash
python3 - <<'EOF'
import collections
def load(p): return dict(l.rstrip('\n').split('  ', 1)[::-1] for l in open(p))
P, S = load('sitesall-prod.sha256'), load('sitesall-staging.sha256')
d = collections.Counter()
for f in set(P) | set(S):
    if P.get(f) != S.get(f): d['/'.join(f.split('/')[:4])] += 1
for k in sorted(d): print(d[k], k)
EOF
```

**Resultado esperado.** Solo `sites/all/modules/myapi` (si el despliegue va por detrás) y
`myapi_test` (solo staging, no se copia). Cada otro directorio que salga se trae de
staging en el paso 4.9. El lint de staging es la referencia: los fallos que tenga staging
y no estén en uso se aceptan (a 2026-10-28, 19 ficheros de `libraries/PHPExcel` y
`libraries/dompdf/include/autoload.inc.php`, con `print_pdf` deshabilitado); cualquier
fallo de producción que **no** esté en el lint de staging tiene que desaparecer con el 4.9.

Comprobar también que los módulos con código que PHP 8 ya no ejecuta no están en uso:

```bash
sudo -u www-data drush sqlq "SELECT name, status FROM dr_system WHERE name IN ('views_php','print_pdf')" 2>/dev/null
```

`views_php` usa `create_function()`, eliminado en PHP 8: falla al ejecutarse, no al
compilar, así que el lint no lo ve. En producción y en staging está deshabilitado.

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
sudo -u www-data drush php-eval 'cache_clear_all("*", "cache_page", TRUE);' 2>/dev/null
curl -sI https://sistema.crespcord.com/ | grep -iE '^HTTP/|^location|x-drupal-cache'
curl -s -o /dev/null -w 'api: %{http_code}\n' https://sistema.crespcord.com/api/v1/catalogs
```

**Resultado esperado.** La API responde `503`. La portada responde `302` hacia
`?q=user/login` (el login sigue accesible en mantenimiento para los administradores) o
`503`; nunca `200` con `X-Drupal-Cache: HIT`.

**Por qué se vacía `cache_page`.** Drupal 7 sirve la caché de páginas anónimas **antes**
de comprobar el modo mantenimiento: sin vaciarla, la portada siguió saliendo con `200` y
`X-Drupal-Cache: HIT` en producción. Mientras dure el mantenimiento no se vuelve a llenar.

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
  if (!empty($d["port"])) { echo "port=" . $d["port"] . "\n"; }' 2>/dev/null >> ~/backups/mysql-prod.cnf
DB=$(sudo -u www-data drush php-eval '$d = Database::getConnectionInfo(); echo $d["default"]["database"];' 2>/dev/null)
echo "$DB"
sed 's/^password=.*/password=***/' ~/backups/mysql-prod.cnf    # solo [client], user, password, host (y port)
mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" -e "SELECT COUNT(*) FROM dr_rules_config"
```

El `2>/dev/null` evita que los avisos `stat failed for phar://` de Drush 8 con la 7.64 se
cuelen en el fichero o en `$DB`. **No cerrar la sesión SSH** hasta el final: `$DB`, `$TS`
y las variables del paso 4.1 viven en ella.

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

**`authorize.php` y `xmlrpc.php` no se reponen** si el paso 2.5 mostró que no existían.
Nadie los usa (la app va por `myapi`; `authorize.php` solo sirve para instalar módulos
desde la web) y `xmlrpc.php` es superficie de ataque. En producción no existían y
no se repusieron; quitarlos de `CORE_FILES` si es el caso.

El `rm -rf` va encadenado a tres comprobaciones (directorio, `settings.php` y propietario)
para que un `cd` fallido no borre nada fuera del docroot.

```bash
cd /var/www/html
OWNER=$(stat -c %U:%G index.php); echo "OWNER=$OWNER"
CORE_DIRS="includes misc modules profiles scripts themes"
CORE_FILES=".htaccess .editorconfig .gitignore web.config robots.txt \
  authorize.php cron.php index.php install.php update.php xmlrpc.php \
  CHANGELOG.txt COPYRIGHT.txt INSTALL.txt INSTALL.mysql.txt INSTALL.pgsql.txt \
  INSTALL.sqlite.txt LICENSE.txt MAINTAINERS.txt README.txt UPGRADE.txt"
[ "$PWD" = /var/www/html ] && [ -f sites/default/settings.php ] && [ "$OWNER" = www-data:www-data ] \
  && sudo rm -rf $CORE_DIRS $CORE_FILES \
  && for f in $CORE_DIRS $CORE_FILES; do sudo cp -a "/tmp/drupal-7.103/$f" .; done \
  && sudo chown -R "$OWNER" $CORE_DIRS $CORE_FILES \
  && echo "== core sustituido =="
# mitigación CVE (docs/mitigacion-php-cve-2026-10.md, paso 4): el .htaccess de serie la reactiva
sudo sed -i '/^<IfModule mod_php5.c>$/,/^<\/IfModule>$/ s/^/# CVE-2026 mitigation: /' .htaccess
sed -n '30,41p' .htaccess
# restos de malware fuera de modules/ (paso 2.5), si los hubo
sudo find sites -xdev -path '*/files/*' -name '*.php'
comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.103 | sort)   # lo mismo que en 2.5
sudo -u www-data drush updb -n 2>&1 | grep -v -E 'stat failed for|PharStreamWrapper.php|^phar://'   # listar sin aplicar
sudo -u www-data drush updb -y
sudo -u www-data drush cc all
sudo -u www-data drush status | grep "Drupal version"
```

**Resultado esperado.** `== core sustituido ==`; si no sale, una de las comprobaciones
falló y no se ha borrado nada. Las nueve líneas del bloque `mod_php5.c` empiezan por
`# CVE-2026 mitigation: `: entre este paso y el 4.6 el sitio sigue en `mod_php` 5.6, y el
bloque de serie volvería a aplicarse. El `find` de `files/` no debe listar nada (en
producción salió `sites/default/files/kbpsvoai.php`, un resto neutralizado: borrarlo con
`sudo rm -f`). El `comm` final lista lo mismo que en el paso 2.5 salvo `sites`, que la
7.103 también trae. `drush updb -n` lista exactamente 8 updates (`system` 7082 a 7088,
`user` 7020); si sale alguno de otro módulo, parar y mirarlo antes del `-y`. Termina sin
errores y `Drupal version : 7.103`. Si en el paso 2.5 se guardó algún fichero modificado o
un perfil propio, reponerlo ahora desde `~/backups`.

**Si falla.**
- `updb` se corta con `Drush command terminated abnormally`: casi siempre es la caché de
  Drush de `www-data` con rutas al phar. `sudo rm -rf /tmp/drush-www-data` y repetir.
- Un update falla a mitad: `drush updb` es reanudable; corregir la causa y volver a
  lanzarlo. Si no hay forma, restaurar el snapshot (sección 8).

### 4.2 `trusted_host_patterns` (recomendado)

**Qué hace.** Impide que Drupal acepte peticiones con una cabecera `Host` falsa. No es
obligatorio, pero staging lo tiene y la 7.103 lo documenta. Antes, ver qué nombres sirve
Apache. `sites-enabled/` son enlaces simbólicos: `grep -r` (minúscula) **no** los sigue y
no devuelve nada; hace falta `-R`.

```bash
sudo grep -RhiE '^\s*(ServerName|ServerAlias)' /etc/apache2/sites-enabled/ | sort -u   # producción: solo sistema.crespcord.com
cd /var/www/html
sudo cp -a sites/default/settings.php ~/backups/settings.php.pre-trusted-host
printf '\n$conf['"'"'trusted_host_patterns'"'"'] = array('"'"'^sistema\\.crespcord\\.com$'"'"');\n' | sudo tee -a sites/default/settings.php >/dev/null
tail -2 sites/default/settings.php
sudo -u www-data php -l sites/default/settings.php
curl -s -o /dev/null -w 'host bueno: %{http_code}\n' https://sistema.crespcord.com/user/login                  # 200
curl -sk -o /dev/null -w 'host falso: %{http_code}\n' -H 'Host: evil.example.com' https://127.0.0.1/user/login  # 400
```

Si hay `ServerAlias` (por ejemplo `www.`), añadir un patrón por cada uno.

**Si falla.** Si después el sitio responde «The provided host name is not valid for this
server», el patrón no coincide con el dominio real: revisar el `ServerName` del vhost.

### 4.3 Instalar PHP 8.3 con PHP-FPM y cambiar el MPM de Apache

**Qué hace.** Instala los 13 paquetes de staging, desactiva `mod_php` y `prefork`, activa
`event` + `proxy_fcgi` y la configuración que trae el paquete `php8.3-fpm`, que redirige
los `.php` al socket de FPM. `mpm_prefork` hay que desactivarlo **antes** de activar
`mpm_event`, o `a2enmod` se niega.

No ejecutar `apt update` antes: el candidato ya se comprobó en el paso 2.1 y un `update`
podría traer otra 8.3.x distinta de la de staging. Primero se simula, y la instalación
solo sigue si `apt` no va a desinstalar nada. `NEEDRESTART_MODE=l` evita el diálogo
«Daemons using outdated libraries» de `needrestart`, que en producción ofreció reiniciar
Apache y MariaDB a mitad de la ventana (si aparece: `<Cancel>`, que no deshace la
instalación).

```bash
PKGS="php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap php8.3-xml php8.3-zip"
sudo apt-get install -s $PKGS | grep -E '^(Inst|Remv)' | tee ~/backups/apt-sim-php83.txt
grep -q '^Remv' ~/backups/apt-sim-php83.txt && echo "!!! apt quiere desinstalar algo: NO SIGAS" \
  || { sudo NEEDRESTART_MODE=l apt-get install -y $PKGS \
  && sudo a2dismod php5.6 mpm_prefork \
  && sudo a2enmod mpm_event proxy proxy_fcgi setenvif \
  && sudo a2enconf php8.3-fpm \
  && sudo update-alternatives --set php /usr/bin/php8.3 \
  && echo "== 4.3 aplicado =="; }
php -v | head -1
dpkg -l 'php8.3*' | awk '/^ii/{print $3}' | sort -u
```

**Resultado esperado.** La simulación solo trae líneas `Inst` (en producción, los 13
paquetes más `libonig5`). `== 4.3 aplicado ==`, `PHP 8.3.36 (cli)` y una única versión,
`8.3.36-1+ubuntu22.04.1+deb.sury.org+1`. Todavía **no** se reinicia Apache: faltan los
dos pasos siguientes.

**Desde aquí hasta el 4.7, no usar `drush`.** El `php` de la terminal ya es 8.3 y Drush
8.4.11 no está preparado para PHP 8.3.

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
sudo apache2ctl configtest && sudo systemctl restart php8.3-fpm apache2
systemctl is-active apache2 php8.3-fpm
curl -s -o /dev/null -w 'portada: %{http_code} -> %{redirect_url}\n' https://sistema.crespcord.com/
curl -s -o /dev/null -w 'login: %{http_code}\n' https://sistema.crespcord.com/user/login
curl -s -o /dev/null -w 'api: %{http_code}\n' https://sistema.crespcord.com/api/v1/catalogs
```

**Resultado esperado.**
- `Syntax OK`, los dos servicios `active`.
- La portada responde como en el paso 3.1 (en producción, `302` hacia `?q=user/login`), el
  login `200` y la API `503`. Que el login cargue significa que Drupal arranca en PHP 8.3
  bajo FPM y conecta con la base de datos. Un `502` o un `500` es un fallo (ver abajo).
- No se usa `drush status` aquí: sigue siendo la 8.4.11 (paso 4.7).

El login cargando **no** garantiza que todo el código compile en PHP 8: no usa Views. El
fatal de Views 3.20 apareció después, en el `cc all` del paso 5.3. Eso lo cubren el paso
2.7 y el 4.9.

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
cd /tmp && rm -f drush.phar && curl -fLO https://github.com/drush-ops/drush/releases/download/8.4.12/drush.phar
php /tmp/drush.phar --version | grep -q '8.4.12' \
  && sudo mv /tmp/drush.phar /usr/local/bin/drush && sudo chmod 755 /usr/local/bin/drush \
  && sudo rm -rf /tmp/drush-www-data && echo "== drush 8.4.12 instalado =="
cd /var/www/html && sudo -u www-data drush status | grep -E "Drupal version|PHP configuration"
```

**Resultado esperado.** `Drush Version : 8.4.12`, `Drupal version : 7.103`,
`PHP configuration : /etc/php/8.3/cli/php.ini`.

### 4.8 Retirar las mitigaciones temporales de los CVE

**Qué hace.** Las mitigaciones del 2026-10-22 reducían la superficie de ataque de un PHP
sin parche. Con PHP 8.3.36 sobran. De las cuatro, dos ya han desaparecido solas: el
bloque `mod_php5.c` del `.htaccess` sigue comentado desde el paso 4.1 pero con FPM no se
evalúa, y `mpm_prefork.conf` ya no se carga (paso 4.3). El `.ini` de
`/etc/php/5.6/apache2/conf.d/` se irá con el purge (sección 7). Queda el `conf` de Apache
con `LimitRequestBody`:

```bash
cat /etc/apache2/conf-available/cve-2026-mitigation.conf      # LimitRequestBody 41943040
sudo a2disconf cve-2026-mitigation && sudo rm /etc/apache2/conf-available/cve-2026-mitigation.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

### 4.9 Alinear los módulos contrib y el tema con staging

**Qué hace.** Trae de staging los directorios de `sites/all` que el paso 2.7 marcó como
distintos, con las versiones exactas que staging ya probó en PHP 8.3, y aplica sus
updates de base de datos. Va aquí, con PHP 8.3 y Drush 8.4.12 ya en marcha, porque es la
combinación que se probó; y **antes** de la sección 5, porque el `drush cc all` del paso
5.3 compila todo el código y con un Views viejo da *Fatal*.

**Por qué no se hizo en el plan original.** Se daba por hecho que staging y producción
tenían el mismo contrib. En producción hizo falta traer, a 2026-10-28, estos 21 módulos y
el tema: `admin_views ckeditor ctools date entity entityreference
entityreference_view_widget feeds field_collection flood_control htmlmail print rules
rules_conditional smtp token views views_aggregator views_bootstrap views_bulk_operations
views_data_export` y `themes/bootstrap`. `myapi`, `entityreference_count` (ya parcheado en
los dos), `views_php` y `libraries/` eran idénticos. `myapi_test` existe solo en staging y
**no** se copia.

**1. En staging**, empaquetar (la lista `D` es la que salga del paso 2.7):

```bash
cd /var/www/html
D="admin_views ckeditor ctools date entity entityreference entityreference_view_widget feeds field_collection flood_control htmlmail print rules rules_conditional smtp token views views_aggregator views_bootstrap views_bulk_operations views_data_export"
sudo tar czf ~/contrib-staging.tgz $(for m in $D; do echo sites/all/modules/$m; done) sites/all/themes/bootstrap
sudo chown ubuntu:ubuntu ~/contrib-staging.tgz && sha256sum ~/contrib-staging.tgz
```

**2. En el portátil**, pasarlo a producción junto con los hashes de staging de esos
directorios (sale de `sitesall-staging.sha256` del paso 2.7). `scp` moderno no expande
`{a,b}` en rutas remotas: un `scp` por fichero.

```bash
grep -E '  sites/all/(modules/(admin_views|ckeditor|...|views_data_export)|themes/bootstrap)/' sitesall-staging.sha256 > contrib-staging-subset.sha256
scp -i ~/.ssh/crespcord.pem ubuntu@crespcord.lamotora.com:contrib-staging.tgz .
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem contrib-staging.tgz ubuntu@<IP_PROD>:
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem contrib-staging-subset.sha256 ubuntu@<IP_PROD>:
```

**3. En producción**, apartar lo viejo (un `mv` en el mismo disco, reversible), poner lo
nuevo y verificar fichero a fichero:

```bash
cd /var/www/html
D="<la misma lista>"
OLD=$HOME/backups/contrib-old-$TS
[ "$PWD" = /var/www/html ] && [ ! -e "$OLD" ] && [ -s ~/contrib-staging.tgz ] \
  && mkdir -p "$OLD/modules" "$OLD/themes" \
  && for m in $D; do sudo mv "sites/all/modules/$m" "$OLD/modules/" || break; done \
  && sudo mv sites/all/themes/bootstrap "$OLD/themes/" \
  && sudo tar xzf ~/contrib-staging.tgz -C /var/www/html \
  && sudo chown -R www-data:www-data $(for m in $D; do echo sites/all/modules/$m; done) sites/all/themes/bootstrap \
  && echo "== contrib sustituido =="
sudo sha256sum -c --quiet ~/contrib-staging-subset.sha256 && echo "hashes OK"
sudo rm -rf /tmp/drush-www-data
sudo -u www-data drush updb -n 2>&1 | tee ~/backups/contrib-updb-list.txt    # listar
sudo -u www-data drush updb -y 2>&1 | tee ~/backups/contrib-updb-applied.txt
sudo -u www-data drush cc all 2>&1 | tail -3
```

**Resultado esperado.** `== contrib sustituido ==` y `hashes OK`. En producción hubo 11
updates: `admin_views` 7100–7102 (el 7101 habilita `admin_views_system_display`),
`ckeditor` 7006, `date` 7006, 7007 y 7200, `field_collection` 7009, `print_pdf` 7206,
`smtp` 7104 (activa la verificación TLS estricta; el correo siguió saliendo) y
`views_bootstrap` 7101, que repite decenas de `Undefined array key "style_plugin"`: son
avisos, termina en `[ok]`. Tardaron 16 segundos. El `cc all` termina en
`'all' cache was cleared. [success]`, sin ningún *Fatal*.

**Vuelta atrás.** Los updates de contrib cambian la base de datos: devolver los
directorios de `$OLD` a su sitio no basta. La vuelta atrás es el snapshot del paso 3.3.

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

El sitio solo se abre si el lint completo de `sites/all` no tiene más fallos que los
aceptados en el paso 2.7 (a 2026-10-28, `libraries/PHPExcel` y `libraries/dompdf`):

```bash
cd /var/www/html
N=$(sudo find sites/all -type f \( -name '*.php' -o -name '*.inc' -o -name '*.module' -o -name '*.install' -o -name '*.theme' -o -name '*.engine' \) -print0 \
  | xargs -0 -n1 -P4 sh -c 'php -l "$0" 2>&1 | grep -E "Fatal error|Parse error"; true' | grep -vcE 'libraries/(PHPExcel|dompdf)/')
echo "fatales fuera de PHPExcel/dompdf: $N"
[ "$N" = 0 ] \
  && sudo -u www-data drush vset maintenance_mode 0 2>/dev/null \
  && sudo crontab -u www-data ~/backups/crontab-www-data.txt \
  && echo "== sitio abierto y cron restaurado =="
sudo crontab -u www-data -l | grep -v '^#' | grep .
curl -s -w '\n%{http_code}\n' https://sistema.crespcord.com/api/v1/ping
curl -s -w '\n%{http_code}\n' -H "Authorization: Bearer x" https://sistema.crespcord.com/api/v1/units
```

**Resultado esperado.** `fatales fuera de PHPExcel/dompdf: 0`, `== sitio abierto y cron
restaurado ==` y las tres líneas del cron (`queue-run myapi_onesignal_push`,
`queue-run myapi_mail_send`, `cron`). `ping` da `200` con `{"success":true,"data":{"pong":true}}`
y `units` con un token falso da **`401` con `invalid_token`**, no `missing_authorization`:
eso prueba de punta a punta que la cabecera `Authorization` llega a `myapi`. Al minuto
siguiente, `sudo -u www-data drush watchdog-show --type=php` solo tiene `Deprecated`.

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
   sudo ls -lt /var/www/private/comprobantes_pago/ | head -3   # sin sudo: Permission denied (es 750, de www-data)
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
sudo -u www-data drush watchdog-show --type=php | grep -v Deprecated   # solo continuaciones de Deprecated
sudo -u www-data drush watchdog-show --type=mail    # sin «Error sending e-mail»
sudo grep -c Fatal /var/log/php8.3-fpm-errors.log   # ninguno posterior a la apertura
sudo tail /var/log/apache2/error.log                # sin fatales de PHP
```

**Qué es normal en PHP 8.3.** `date` (`DateObject`, propiedades dinámicas y tipos de
retorno), `views` (propiedades dinámicas en `views_handler->init()`) y `entity`
(`callbacks.inc`, parámetros opcionales antes de obligatorios) dejan `Deprecated` en el
watchdog y en el log de FPM en casi cada página. No rompen nada. Lo que **no** es normal:
`Warning`, `Notice`, `Error` o cualquier `Fatal`.

**Las pruebas dejan datos reales.** El pago, el reclamo y el saldo aplicado del smoke test
quedan en la contabilidad del condominio de prueba: anularlos o corregirlos desde el
backoffice al terminar.

### 6.4 Ventana de 24 horas

**Qué hace.** PHP 8 convierte en warnings cosas que PHP 5.6 callaba, y solo aparecen al
usar cada pantalla. Un día de uso real del backoffice con el watchdog limpio es la
prueba de que no queda nada. La lista de pruebas de staging sirve de guion: reportes,
exportaciones a Excel, PDF de alícuotas, Feeds, CKEditor, VBO.

Revisar a las 24 horas. `SINCE` es la hora de apertura del paso 6.2, en UTC:
```bash
cd /var/www/html; SINCE=$(date -d '2026-10-28 15:35 UTC' +%s)
sudo -u www-data drush sqlq "SELECT 'php_desde_apertura', severity, COUNT(*) FROM dr_watchdog WHERE type='php' AND timestamp >= $SINCE GROUP BY severity"
sudo -u www-data drush watchdog-show --severity=error --count=15
sudo grep -c Fatal /var/log/php8.3-fpm-errors.log           # igual que antes de abrir
sudo grep -v Deprecated /var/log/php8.3-fpm-errors.log | tail -20
sudo grep -c "pm.max_children" /var/log/php8.3-fpm.log      # 0; si no, subir el pool (paso 4.5)
free -m | head -2; sudo dmesg -T | grep -ci 'out of memory' # 0
```

En Drupal 7 los `Deprecated` se registran con severidad 7, los *Notice* con 5, los
*Warning* con 4 y los errores con 3. La consulta solo debe devolver severidad 7, o nada:
con el `error_reporting` del paso 4.5 (`E_ALL & ~E_DEPRECATED & ~E_STRICT`) Drupal ni
siquiera registra los `Deprecated`. Una consulta sin filas no imprime nada en `sqlq`, ni
cabecera. No filtrar `watchdog-show` con `grep -v Deprecated`: drush parte cada mensaje
en varias líneas y los trozos sin la palabra se cuelan como si fueran otra cosa.

El log de FPM tampoco basta por sí solo: el manejador de errores de Drupal se queda los
*Warning* y *Notice* y no llegan a ese log. El log de FPM muestra la hora de
`America/Guayaquil` (UTC−5).

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

Desde aquí las filas 8.2 a 8.4 de la vuelta atrás dejan de valer: solo queda el snapshot.

### 7.1 Purgar PHP 5.6

Antes, comprobar que nada lo usa: ni `php5.6.load` en `mods-enabled`, ni una unidad
`php5.6-fpm`, y que la alternativa `php` apunta a la 8.3.

```bash
ls /etc/apache2/mods-enabled/ | grep -i php ; systemctl list-units --all 'php*' --no-legend
update-alternatives --display php | head -3
sudo NEEDRESTART_MODE=l apt-get purge -y 'php5.6*' libapache2-mod-php5.6 && sudo NEEDRESTART_MODE=l apt-get autoremove -y
dpkg -l 'php5.6*' 2>/dev/null | grep ^ii ; echo "dpkg-ii-5.6: $?"
sudo apache2ctl configtest && systemctl is-active apache2 php8.3-fpm
php -v | head -1
```

**Resultado esperado.** `dpkg-ii-5.6: 1`, `Syntax OK`, los dos servicios activos,
`PHP 8.3.36`. Los muchos `is not installed, so not removed` son normales: el comodín
abarca todo el repositorio. En producción se quitaron 15 paquetes.

dpkg avisa de que no borra `/etc/php/5.6/apache2/conf.d` ni `mods-available` porque no
están vacíos: dentro queda el `99-cve-2026-mitigation.ini`, que no es de ningún paquete.
Comprobar que está en la copia del paso 3.5 y borrar el directorio:

```bash
sudo find /etc/php/5.6 -type f -ls
tar tzf ~/backups/etc-php-5.6.tgz | grep -E 'mods-available/|apache2/conf.d/' | grep -v '/$'
sudo rm -rf /etc/php/5.6 && ls /etc/php/      # solo 8.3
```

El bloque `<IfModule mod_php5.c>` del `.htaccess` puede quedarse: con FPM no se evalúa.

### 7.2 Rotar la contraseña de la base de datos

Obligatorio si el paso 2.5 encontró un volcado o un `settings.php` descargable (en
producción, sí). La contraseña no se muestra nunca: va por ficheros `600` y por stdin, y
`mysql` en modo batch no escribe historial.

**Antes**, ver qué entradas de `mysql.user` hay que cambiar, que la contraseña aparece una
sola vez en `settings.php` y qué otros ficheros la contienen:

```bash
cd /var/www/html
sudo -u www-data drush php-eval 'global $databases; $d=Database::getConnectionInfo(); $d=$d["default"]; $s=file_get_contents(DRUPAL_ROOT."/sites/default/settings.php"); echo "conexiones=".implode(",",array_keys($databases))." user=".$d["username"]."@".$d["host"]." pwlen=".strlen($d["password"])." apariciones=".substr_count($s,"\x27".$d["password"]."\x27")."\n";'
sudo mysql -e "SELECT user, host, plugin FROM mysql.user ORDER BY user, host"
install -m 600 /dev/null ~/backups/.oldpw
sudo -u www-data drush php-eval '$d=Database::getConnectionInfo(); echo $d["default"]["password"];' > ~/backups/.oldpw
sudo grep -rlsF -f ~/backups/.oldpw --exclude-dir=files /etc /root /home /var/spool/cron /usr/local /opt /var/www 2>/dev/null
```

Debe salir `conexiones=default` y `apariciones=1`. En producción la contraseña estaba,
además de en lo esperado, en `/root/.mysql_history` (alguien la escribió en el cliente
`mysql` como root) y en una copia vieja `~/backups/settings.php.antes-myapi`. Si aparece un
script o un cron, hay que actualizarlo también.

**Rotar.** El usuario solo existía como `@localhost`; si hay más filas, un `ALTER USER`
por cada una. El hueco entre las dos órdenes es de milisegundos; el modo mantenimiento no
sirve aquí porque Drupal necesita la base de datos para saber si está en mantenimiento.

```bash
sudo cp -a sites/default/settings.php ~/backups/settings.php.pre-dbrotate
install -m 600 /dev/null ~/backups/.newpw && openssl rand -hex 20 | tr -d '\n' > ~/backups/.newpw
printf "ALTER USER 'pcordero_drupcon'@'localhost' IDENTIFIED BY '%s';\n" "$(cat ~/backups/.newpw)" | sudo mysql && echo "ALTER USER OK" \
&& sudo php -r '$f="/var/www/html/sites/default/settings.php"; $s=file_get_contents($f); $o="\x27".file_get_contents("/home/ubuntu/backups/.oldpw")."\x27"; $n="\x27".file_get_contents("/home/ubuntu/backups/.newpw")."\x27"; if (substr_count($s,$o)!==1) { fwrite(STDERR,"ABORT: apariciones != 1\n"); exit(1); } file_put_contents($f, str_replace($o,$n,$s)); echo "settings.php actualizado\n";'
sudo php -l sites/default/settings.php; sudo -u www-data drush status --fields=db-status
curl -s -o /dev/null -w 'ping %{http_code}\n' https://sistema.crespcord.com/api/v1/ping
```

**Resultado esperado.** `ALTER USER OK`, `settings.php actualizado`, `No syntax errors`,
`Connected`, `ping 200`. `file_put_contents` conserva el propietario y los permisos.
opcache vuelve a leer `settings.php` en unos 2 segundos (no se cambió
`validate_timestamps`).

**Si falla** después de `ALTER USER OK`, deshacer las dos cosas:

```bash
printf "ALTER USER 'pcordero_drupcon'@'localhost' IDENTIFIED BY '%s';\n" "$(cat ~/backups/.oldpw)" | sudo mysql
sudo cp -a ~/backups/settings.php.pre-dbrotate /var/www/html/sites/default/settings.php
```

**Destruir las copias de la contraseña antigua.** Primero repetir la búsqueda con
`.oldpw` y comprobar que solo salen los ficheros que se van a borrar. El fichero del paso
3.4 ya no hace falta: si hay que restaurar un volcado, `sudo mysql pcordero_drupcon`
entra como root por socket.

```bash
sudo shred -fu ~/backups/settings.php.pre-* ~/backups/settings.php.antes-myapi ~/backups/mysql-prod.cnf /root/.mysql_history
shred -u ~/backups/.oldpw ~/backups/.newpw
```

### 7.3 Borrar los restos de la migración

Los volcados y el zip con datos personales de `/root/incident` se pueden borrar solo si el
snapshot del paso 2.5 se tomó **antes** de moverlos allí (así los conserva) y si el
paquete de evidencias del portátil tiene el hash anotado:

```bash
# en el portátil
shasum -a 256 ~/crespcord-incident/evidence-*.tgz
aws lightsail get-instance-snapshot --instance-snapshot-name incident-evidence-20261028 \
  --profile crespcord --region us-east-2 --query 'instanceSnapshot.[state,createdAt]' --output text
```

```bash
# en el servidor
sudo rm -rf ~/backups/contrib-old-*   # los ficheros son de www-data (se movieron desde el docroot)
rm -rf ~/contrib-staging.tgz ~/contrib-staging-subset.sha256
cd /tmp && rm -rf drupal-7.64* drupal-7.103* && cd ~
sudo rm -rf /root/incident
```

En `/tmp` dejar `.htaccess`, `drush-www-data/` y `views_plugin_display/`: son de Drupal
(usa `/tmp` como directorio temporal). Los scripts del paso 5 que se subieron a `/tmp` ya
están en `scripts/php8/`. En producción había además un volcado completo anterior,
`~/backups/prod-antes-myapi-2026-10-19.sql.gz`, superado por el de la ventana. Se
recuperaron unos 5 GB.

### 7.4 Reiniciar la instancia

La instalación del paso 4.3 dejó reinicios aplazados; en producción `needrestart` listaba
17 servicios, entre ellos `mariadb`, `ssh`, `dbus` y `systemd-manager`. Un reboot los
resuelve todos y prueba que el servidor arranca solo. **De verdad fuera de horas**: en
producción se hizo a las 10:07 de Guayaquil y un usuario se encontró un 503 en
`admin/content`.

**Antes**, comprobar el arranque automático y `fstab`, y hacer un snapshot nuevo: el del
paso 3.3 ya no recoge los datos reales del día de observación, la purga ni la contraseña
nueva.

```bash
sudo needrestart -b | grep -E '^NEEDRESTART-(KCUR|KEXP|KSTA|SVC)'
systemctl is-enabled apache2 php8.3-fpm mariadb cron      # 4 x enabled
sudo findmnt --verify ; echo "fstab rc=$?"                 # el aviso de /swapfile es normal
```

```bash
# en el portátil; esperar a available (en producción, 90 segundos)
aws lightsail create-instance-snapshot --instance-name CrespCord-Drupal-4Gb-OK \
  --instance-snapshot-name pre-reboot-$(date +%Y%m%d) --profile crespcord --region us-east-2
```

**Que Apache arranque después de FPM.** Sin esto, Apache acepta peticiones un segundo
antes de que exista `/run/php/php8.3-fpm.sock` y responde 503 (`AH01079`). En producción
se vio en el primer reboot y se añadió después. Ponerlo antes del reboot:

```bash
sudo mkdir -p /etc/systemd/system/apache2.service.d
printf '[Unit]\nAfter=php8.3-fpm.service\nWants=php8.3-fpm.service\n' | sudo tee /etc/systemd/system/apache2.service.d/after-php-fpm.conf
sudo systemctl daemon-reload
systemctl show apache2 -p After | grep -o 'php8.3-fpm.service'
```

Reiniciar con `sudo reboot` y, al volver a conectar (la shell empieza en el home: sin
`cd`, `drush status` sale vacío):

```bash
cd /var/www/html
systemctl is-active apache2 php8.3-fpm mariadb cron; systemctl --failed --no-legend
sudo needrestart -b | grep -E '^NEEDRESTART-(KSTA|SVC)'   # KSTA: 1 y ninguna SVC
sudo -u www-data drush status --fields=db-status
curl -s -o /dev/null -w 'ping %{http_code}\n' https://sistema.crespcord.com/api/v1/ping
sudo grep -c Fatal /var/log/php8.3-fpm-errors.log          # sin cambios
journalctl -u cron -b --no-pager | grep -i www-data | tail -3   # tras unos minutos
```

El arranque vacía `/tmp` (`tmpfiles.d`): Drupal y drush recrean lo que necesitan.

Cuando el sitio lleve unos días estable, borrar los snapshots `pre-php83-*` y
`pre-reboot-*` (tienen coste mensual) y, con ellos, el volcado y el tar de ficheros de
`~/backups`, que llevan datos personales. El de evidencias se conserva hasta cerrar la
revisión de seguridad.

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

**Solo antes del paso 4.9.** Después, los módulos contrib son los de staging y la base de
datos tiene sus updates: volver a PHP 5.6 dejaría esa combinación, que nadie ha probado,
en producción. A partir del 4.9, la vuelta atrás es el snapshot (8.1).

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

---

## 9. Ejecución en producción (2026-10-28 y 29): qué salió distinto

Ventana de 14:30 a 15:35 UTC del 28, más la preparación de la sección 2 esa misma mañana; la
revisión de 24 horas y la sección 7 se hicieron el 29. Resultado: Drupal 7.103, PHP 8.3.36
con FPM, Drush 8.4.12, contrib alineado con staging, las 11 pruebas del smoke test
correctas, ningún *Fatal* ni *Warning* desde la apertura, PHP 5.6 purgado, contraseña de la
base de datos rotada y la instancia reiniciada sin intervención. Lo que no estaba
previsto, en orden de aparición, y dónde ha quedado recogido en esta guía:

| Qué pasó | Consecuencia | Dónde se corrige |
|---|---|---|
| El docroot tenía 105 `.php` vacíos y 2 esqueletos neutralizados de una infección de 2024 en el hosting anterior (cPanel de A2 Hosting), copiados al migrar al servidor actual el 2026-10-14 | Parecía un compromiso activo. Se hizo un snapshot forense y un paquete de evidencias antes de seguir; el análisis descartó actividad (sin código, sin accesos en los logs, sin persistencia) | Paso 2.5 |
| `comm` no detecta ficheros del core modificados | `common.inc` y `request-sanitizer.inc` tenían un parche de PHP 7 que no se habría visto | Paso 2.5 (`diff -rq`) |
| Un volcado completo de la base de datos, una copia del sitio con `settings.php`, `php.ini.bak` y cinco `error_log` se podían descargar por HTTP | Se retiraron a `/root/incident/docroot-leftovers/`. Los logs (desde el 2026-10-13) no muestran descargas, pero la contraseña de la base de datos se rota | Paso 2.5 y sección 7 |
| La portada siguió dando `200` en mantenimiento | La caché de páginas se sirve antes de comprobar el modo mantenimiento | Paso 3.1 |
| `grep -r` en `sites-enabled/` no devolvió nada | Son enlaces simbólicos; hace falta `-R` | Paso 4.2 |
| El diálogo de `needrestart` apareció a mitad de la instalación de PHP 8.3 | Se canceló: Apache y MariaDB no se reiniciaron. Los reinicios aplazados se resuelven reiniciando la instancia | Paso 4.3 y sección 7 |
| El `.htaccess` de serie de la 7.103 reactivaba el bloque `mod_php5.c` que la mitigación CVE había comentado | Se volvió a comentar en el mismo paso: entre el 4.1 y el 4.6 el sitio sigue en `mod_php` | Paso 4.1 |
| `authorize.php` y `xmlrpc.php` no existían en producción | No se repusieron | Paso 4.1 |
| **Views 7.x-3.20 da *Fatal* en PHP 8** (`$x{0}`) y apareció en el `cc all` del paso 5.3, con el sitio ya en PHP 8.3 | Producción tenía 21 módulos y el tema `bootstrap` más viejos que staging. Se trajeron de staging verificando hashes y se aplicaron 11 updates de contrib; unos 25 minutos más de ventana | Pasos 2.7 y 4.9 |
| El primer lint completo abortó en el primer fallo | `xargs` corta cuando `php -l` sale con 255 | Paso 2.7 (`sh -c '...; true'`) |
| `PHPExcel` (19 ficheros) y `dompdf` no compilan en PHP 8, **también en staging** | Se aceptan: `print_pdf` está deshabilitado y staging funciona igual. Queda pendiente ver quién usa PHPExcel | Paso 2.7 |
| A las 24 horas, `watchdog-show \| grep -v Deprecated` sacó líneas sospechosas | Eran trozos de mensajes `Deprecated` que drush parte en varias líneas. Se comprobó por SQL: 0 entradas `php` desde la apertura | Paso 6.4 (consulta por severidad) |
| La purga de 5.6 dejó `/etc/php/5.6` con el `.ini` de la mitigación CVE | No es de ningún paquete; se comprobó que estaba en la copia y se borró el directorio | Paso 7.1 |
| La contraseña de la base de datos estaba también en `/root/.mysql_history` y en una copia vieja de `settings.php` | Se rotó sin mostrarla y se destruyeron todas las copias de la antigua | Paso 7.2 |
| `rm -rf ~/backups/contrib-old-*` dio cientos de `Permission denied` | Los ficheros conservan el propietario `www-data` del docroot | Paso 7.3 (`sudo`) |
| El reboot se hizo en horario de uso y Apache arrancó un segundo antes que FPM | Un usuario recibió un 503. Se añadió el *drop-in* de systemd para que Apache espere a FPM | Paso 7.4 |
