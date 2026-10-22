# Actualización a PHP 8.3 — staging (crespcord.lamotora.com)

Fecha: 2026-10-22. Motivo: GHSA-w8hw-w9fx-j79w (CVE-2026-109618) y
GHSA-6wmf-4rv5-p3rp (CVE-2026-109619), dos RCE críticas en PHP sin parche para
5.6 ni 7.4. Las versiones corregidas son 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.

Plan de pruebas: `docs/plan-pruebas-php83.md`.

## Resumen

| | Antes | Después |
|---|---|---|
| PHP (web) | 7.4.33, `mod_php` | **8.3.36, PHP-FPM** |
| PHP (CLI, `php`) | 7.4.33 | **8.3.36** (7.4 sigue instalado) |
| Apache MPM | prefork | **event** + `proxy_fcgi` |
| Drupal core | 7.64 | **7.103** |
| Módulos contrib | 22 desactualizados | última versión de su rama |
| Módulo `myapi` | sin cambios | sin cambios |

## 1. Copias de seguridad

En `/home/ubuntu/backups/php83-20261022/`:

| Archivo | Contenido |
|---|---|
| `db-pre-php83.sql.gz` | volcado de `pcordero_drupcon` (228 MB) |
| `html-pre-php83.tar.gz` | `/var/www/html` completo (756 MB) |
| `etc-pre-php83.tar.gz` | `/etc/apache2` y `/etc/php` |
| `php-packages-pre.txt` | paquetes PHP instalados antes |

Además: `/etc/php/8.3/fpm/pool.d/www.conf.orig` y
`/etc/apache2/conf-available/drupal-docroot.conf.orig`.

## 2. Paquetes instalados

Del repositorio `ppa:ondrej/php` (ya configurado):

```
php8.3-fpm php8.3-cli php8.3-common php8.3-curl php8.3-gd php8.3-intl
php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap
php8.3-xml php8.3-zip
```

Mismas extensiones que tenía 7.4. Al instalarlo, `update-alternatives` dejó
`/usr/bin/php` → `php8.3`, así que **Drush y el cron ya corren con 8.3**.
PHP 7.4 y `libapache2-mod-php7.4` siguen instalados (para volver atrás).

## 3. Configuración de PHP 8.3

**Nuevo** `/etc/php/8.3/fpm/conf.d/99-crespcord.ini`:

```ini
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
```

- `memory_limit`, `max_execution_time` y `max_input_vars` son los que antes aplicaba
  el `.htaccess` (bloque `php7_module`, heredado de cPanel). Con FPM ese bloque ya
  no aplica, por eso se movieron aquí.
- **Cambio de comportamiento:** el `.htaccess` permitía 128M de subida; ahora son
  `upload_max_filesize = 10M` y `post_max_size = 40M`. Coincide con producción y con
  `docs/produccion-checklist.md`.
- `session.upload_progress.enabled = 0` sigue la recomendación de GHSA-6wmf.
  Drupal 7 no lo usa.

**Modificado** `/etc/php/8.3/fpm/pool.d/www.conf`:

```
pm.max_children = 8      (antes 5)
pm.max_requests = 500    (antes sin límite)
```

El servidor tiene 1,9 GB de RAM; 3 procesos ociosos ocupan unos 165 MB.

## 4. Apache

```bash
a2dismod php7.4 mpm_prefork
a2enmod  mpm_event proxy_fcgi setenvif     # activa también proxy
a2enconf php8.3-fpm
```

**Modificado** `/etc/apache2/conf-available/drupal-docroot.conf`: se añadió
`CGIPassAuth On` dentro de `<Directory /var/www/html>`. Sin esa línea la cabecera
`Authorization` no llega a PHP-FPM y **toda la API responde 401**
(`includes/myapi.auth.inc` lee `$_SERVER['HTTP_AUTHORIZATION']`).

Los vhosts (`000-default*.conf`) no se tocaron.

## 5. Drupal core 7.64 → 7.103

- `includes/`, `misc/`, `modules/`, `profiles/`, `scripts/` y `themes/` se
  sustituyeron por los de 7.103 (`rsync --delete`).
- Archivos de la raíz sustituidos: `.htaccess`, `index.php`, `cron.php`,
  `install.php`, `update.php`, `robots.txt`, `web.config` y los `*.txt` de core.
  También `sites/default/default.settings.php`.
- **No se restauraron** `authorize.php` ni `xmlrpc.php`: ya no estaban antes.
- `.htaccess`: es el de 7.103 sin cambios. El anterior solo añadía los bloques
  `php7_module` y `lsapi_module` de cPanel (ver punto 3).
- `includes/common.inc` e `includes/request-sanitizer.inc` tenían parches a mano
  (`is_int($key)` / `is_string($key)`). **7.103 ya los incluye.**
- Sin tocar: `sites/default/settings.php`, `sites/default/files/`, `private:`,
  `payphone/`, `manual/` y los archivos sueltos de la raíz.

`drush updb` ejecutó:
- **system 7082–7088**:
  - la 7086 pasa las sesiones a ids con hash, así que **todos los usuarios del
    backoffice web tienen que volver a iniciar sesión**; los tokens de la API
    (`myapi_tokens`) no se ven afectados;
  - la 7087 activa el módulo de core **Announcements** (`announcements_feed`).
- **user 7020**.
- Actualizaciones de: `admin_views` (activa `admin_views_system_display`),
  `ckeditor`, `date`, `field_collection`, `print_pdf`, `smtp`, `views_bootstrap`.

Solo hubo avisos `Undefined array key "style_plugin"` en
`views_bootstrap_update_7101`, que son inofensivos; no hubo errores. Al final,
`updatedb-status`: *No database updates required*.

## 6. Módulos contrib actualizados

| Proyecto | Antes | Después |
|---|---|---|
| admin_views | 7.x-1.6 | 7.x-1.8 |
| bootstrap (tema) | 7.x-3.15 | 7.x-3.27 |
| ckeditor | 7.x-1.18 | 7.x-1.23 |
| ctools | 7.x-1.15 | 7.x-1.21 |
| date | 7.x-2.10 | 7.x-2.14 |
| entity | 7.x-1.9 | 7.x-1.11 |
| entityreference | 7.x-1.5 | 7.x-1.9 |
| entityreference_view_widget | 7.x-2.0-rc7 | 7.x-2.1 |
| feeds | 7.x-2.0-beta4 | 7.x-2.0 |
| field_collection | 7.x-1.0-beta12 | 7.x-1.2 |
| flood_control | 7.x-1.0 | 7.x-1.1 |
| htmlmail | 7.x-2.71 | 7.x-2.72 |
| print | 7.x-2.2 | 7.x-2.5 |
| rules | 7.x-2.12 | 7.x-2.14 |
| rules_conditional | 7.x-1.0-beta2+2-dev | 7.x-1.0 |
| smtp | 7.x-1.7 | 7.x-1.9 |
| token | 7.x-1.7 | 7.x-1.9 |
| views | 7.x-3.20 | 7.x-3.30 |
| views_aggregator | 7.x-1.4 | 7.x-1.5 |
| views_bootstrap | 7.x-3.2 | 7.x-3.5 |
| views_bulk_operations | 7.x-3.5 | 7.x-3.7 |
| views_data_export | 7.x-3.2 | 7.x-3.3 |

- Antes de sustituirlos, cada módulo se comparó con el tarball oficial de su
  versión. **Ninguno tenía parches locales.**
- Se eligió siempre la última versión estable **de la misma rama**.
  - No se pasó a `date` 3.x (alfa), `jquery_update` 3/4, `imce` 2 ni `mailsystem` 3
    (beta).
  - Esos módulos siguen igual, igual que los que no tienen versión más nueva:
    `auto_entitylabel`, `bundle_copy`, `computed_field`, `conditional_fields`,
    `css_injector`, `entityreference_count`, `field_extrawidgets`,
    `field_readonly`, `hide_submit`, `imce`, `job_scheduler`, `jquery_update`,
    `libraries`, `mailsystem`, `rules_forms`, `views_conditional`,
    `views_data_export_phpexcel`, `views_field_view`, `views_rules` y
    `views_simplechart`.
  - Todos ellos, más el propio `alicuotas`, pasan `php8.3 -l` sin errores.
- Librerías sin tocar: `sites/all/libraries/PHPExcel` y `dompdf`. PHPExcel está
  abandonada; ver "Pendientes".

## 7. Limpieza de restos de una infección antigua

Al comparar el core con el tarball limpio de 7.64 aparecieron archivos ajenos a
Drupal, **iguales en staging y en producción**:

- 105 `.php` con nombres aleatorios en `modules/translation/tests/`, entre ellos
  `wp-login.php`, `admin-ajax.php`, `admin.php`, `themes.php` y `profile.php`.
  Están **vacíos (0 bytes)** y fechados entre el 13-07-2024 y el 10-11-2024.
- `modules/shortcut/admin.php` y `sites/default/files/kbpsvoai.php`: 168 bytes
  cada uno, solo con el comentario *"This file may contain artifacts of previous
  malicious infection. However, the dangerous code has been removed"*. Es el que
  deja el antivirus del hosting anterior (cPanel/A2).

**Lectura:** infección de 2024 en el hosting anterior, ya neutralizada. No es
actual. El escaneo de patrones (`eval(base64_decode…`, `assert($_POST…`,
`preg_replace /e`, `auto_prepend_file`) no encontró nada en ningún servidor.
Tampoco hay `.php` modificados en los últimos 120 días salvo `settings.php` (y en
prod `limits-check.php`, creado por root el 19-10).

En staging se eliminaron todos: los de `modules/` al sustituir el core y
`kbpsvoai.php` a mano. Siguen en el tarball de la copia de seguridad.
**En producción siguen ahí.**

## 8. Verificaciones hechas

| Prueba | Resultado |
|---|---|
| `GET /api/v1/ping` | 200 `{"pong":true}` |
| API sin token / con token falso | `missing_authorization` / `invalid_token` (la cabecera llega a PHP) |
| `/` y `/user/login` | 200 |
| 50 páginas de admin y vistas con display de página, como administrador | 45 bien; **5 con error** (ver Pendientes) |
| `.php` subido a `sites/default/files/` | **no se ejecuta**: se sirve como texto |
| `drush queue-run myapi_onesignal_push` (el cron) | OK |
| Watchdog desde el cambio | 0 errores PHP |
| `php8.3-fpm-errors.log` y `apache2/error.log` | vacíos |
| Tests unitarios de `myapi` (local, PHP 8.4.1) | OK (4229 tests, 22146 aserciones) |
| `X-Powered-By` | ya no aparece |

Para la prueba de administración se usó `drush uli` (inicio de sesión de un solo
uso del usuario 1). Queda registrado en el watchdog.

## 9. Pendientes

1. **Cinco vistas muestran "Se ha detectado una opción ilegal" y no devuelven
   filas:**
   - `gestion-viviendas`
   - `reporte-viviendas-sin-condominio`
   - `exportar-format-pagos`
   - `exportar-formato-archivo-lecturas`
   - `exportar-formato-archivo-viviendas`

   El filtro expuesto `field_condominio_target_id` guarda como valor por defecto
   una opción que no existe: `all`. En `reporte_viviendas_sin_condominio` es
   `All`, con el filtro marcado como obligatorio. Con la combinación nueva el
   formulario la rechaza; antes del cambio no había ninguna entrada así en el
   watchdog.

   Hay que decidir qué debe mostrar cada vista por defecto (todos los
   condominios o uno) y volver a guardar el filtro en Views UI. Lo mismo habrá que
   hacer en producción.
2. **`views_data_export_phpexcel` + PHPExcel:** la librería está abandonada.
   Probar a fondo las exportaciones a Excel (plan de pruebas, sección B).
3. **Announcements** (`announcements_feed`) quedó activado por la actualización
   de core. Consulta drupal.org desde el panel de administración. Se puede
   desactivar si no interesa.
4. **Repositorio:** `CLAUDE.md` y el job `php56` del CI siguen exigiendo PHP 5.6.
   Habrá que cambiarlos cuando producción migre.
5. **Producción** sigue en PHP 5.6 + mod_php, con los restos de la infección,
   `payphone/error_log` público y `/var/www/html` con permisos 777.

## 10. Volver atrás

Rápido, solo PHP, sin tocar código ni base de datos. **No sirve después de
`updb`:** el core 7.103 sí funciona con 7.4, pero esta vía no restaura el
código anterior.

```bash
sudo a2disconf php8.3-fpm
sudo a2dismod mpm_event proxy_fcgi
sudo a2enmod mpm_prefork php7.4
sudo update-alternatives --set php /usr/bin/php7.4
sudo systemctl restart apache2
```

Completo, a como estaba antes. **Se pierden los datos creados desde el cambio.**

```bash
B=/home/ubuntu/backups/php83-20261022
# 1. PHP/Apache, como arriba
# 2. código
sudo mv /var/www/html /var/www/html.php83
sudo tar -C /var/www -xzf $B/html-pre-php83.tar.gz
# 3. base de datos
gunzip -c $B/db-pre-php83.sql.gz | sudo -u www-data php7.4 /usr/local/bin/drush -r /var/www/html sqlc
# 4. configuración
sudo cp -p /etc/apache2/conf-available/drupal-docroot.conf.orig /etc/apache2/conf-available/drupal-docroot.conf
sudo systemctl restart apache2
```
