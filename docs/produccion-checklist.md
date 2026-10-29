# Pase a producción — checklist de `myapi`

Guía operativa para dejar el módulo `myapi` funcionando en
`https://crespcord.lamotora.com` con la app Flutter (v1.0.0+5).
Seguir **en orden**. Los valores entre `<...>` son secretos: nunca se suben al repo.

> Docs relacionadas: `docs/notifications-produccion.md` (push en detalle),
> `docs/claims-install.md`, `docs/reservations-install.md`,
> `docs/services-install.md`, `docs/chat.md`, `docs/bot.md`.

---

## 0. Antes de empezar

- [ ] **Backup de la base de datos y de `sites/default/files/` (y de la ruta privada).**
- [ ] **Ver qué updates se van a ejecutar.** `drush updb` solo ejecuta los hooks por
  encima del `schema_version` guardado (el prefijo de tablas es `dr_`):
  ```bash
  drush sqlq "SELECT schema_version FROM dr_system WHERE name='myapi'"
  drush updb --no    # lista las pendientes sin ejecutarlas
  ```
  El máximo es la **7050** (sube a 5 MB el límite de todos los campos de archivo). La **7014 no existe** (hueco intencionado).
  Updates destructivas o masivas, por si están pendientes:
  - 7015 **quita** el permiso `view the administration theme` al rol `administrador edificio`.
  - 7016 reescribe la configuración de los campos entityreference.
  - 7020 reescribe los títulos de `claim_transaction` (`node` y `node_revision`).
  - 7021 pasa los reclamos `duplicated` a `closed`.
  - 7023 mueve los ficheros de reclamos a `private://`. **Irreversible**, y falla si
    no existe la ruta privada (paso 2).
  - 7029 **borra** `field_photo` del proveedor.
  - 7032 hace obligatorio `field_unit` en `service_request`.
  - 7034 reescribe `file_managed.uri`.
  - 7046/7047 crean los campos del bot en `pagos` y rellenan `field_canal` en todos los pagos.
- [ ] Módulos habilitados: `entityreference`, `date`, `image`, `file`, `list`,
  `number`, `options`, `text`, `taxonomy`, `rules`, **`rules_scheduler`** y **`smtp`**.
  - Sin `rules_scheduler`, aprobar un pago da error: el flujo borra de su tabla
    sin comprobar que exista.
- [ ] El rol `backend` existe (recibe los avisos de admin). El módulo **no** lo crea.
- [ ] Regla heredada `rules_actualizar_saldo_pago`: activa y condicionada al estado
  **«Nuevo»**. Si no, el saldo se aplica dos veces (ver `docs/payment-workflow.md`).

---

## 1. Campos de contenido y datos (crear a mano en el backoffice)

El módulo **no crea** estos campos: los lee y los escribe. Sin ellos, los endpoints
devuelven datos vacíos o fallan al guardar. Los nombres de máquina deben ser **exactos**.

**Copiarlos de staging en vez de crearlos a mano.** `scripts/export-fields.php` saca
de staging la definición exacta del vocabulario `metodos_pago`, de los campos de esta
sección (incluidos los cuatro del bot en `pagos`) y de los valores permitidos de
`field_estado_pago` y `field_forma_de_pago`. `scripts/import-fields.php` los crea en
prod. El importador:
- lo valida todo antes de escribir; si algo falla, no crea nada;
- no toca lo que ya existe, salvo para **añadir** los valores permitidos que falten
  (nunca quita ni cambia etiquetas);
- rechaza un campo que exista con otro tipo;
- se puede repetir sin efecto (idempotente).

Exige `file_private_path` configurado (paso 2), porque `field_archivo` es privado.
Por eso va **después** de `settings.php`.

```bash
# En tu equipo: subir los scripts a los dos servidores (deploy.sh no sube scripts/)
scp -i ~/.ssh/crespcord.pem scripts/export-fields.php ubuntu@crespcord.lamotora.com:/tmp/
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem scripts/import-fields.php ubuntu@<IP_PROD>:/tmp/

# En staging: exportar y traer el JSON
cd /var/www/html && sudo -u www-data env MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /tmp/export-fields.php
scp -i ~/.ssh/crespcord.pem ubuntu@crespcord.lamotora.com:/tmp/myapi-fields.json .
scp -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem myapi-fields.json ubuntu@<IP_PROD>:/tmp/

# En prod: primero en seco (no escribe nada), revisar el plan, y luego de verdad
cd /var/www/html
sudo -u www-data env MYAPI_IMPORT_DRY_RUN=1 MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /tmp/import-fields.php
sudo -u www-data env MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /tmp/import-fields.php
```
Los términos de `metodos_pago` y `bancos` (los datos, no la estructura) se cargan
después con `scripts/seed-catalogs.php`.

### Alícuotas extras (`alicuota_extra`)
- [ ] `field_detalle`: texto largo. La API lo expone como `details`.

### Pagos (`pagos`)
- [ ] `field_detalle`: texto largo. Guarda el motivo de anulación.
- [ ] `field_archivo`: archivo, **método de subida privado**, extensiones `pdf jpg jpeg png`.
  La API guarda el comprobante en `private://comprobantes_pago`.
- [ ] `field_banco`: referencia a término del vocabulario **`bancos`**.
- [ ] `field_estado_pago`: añadir a los valores permitidos:
  ```
  Pendiente de verificar|Pendiente de verificar
  ```
  La API crea los pagos en ese estado. El flujo es
  `Pendiente de verificar` → `Nuevo` → `Completado`, o bien `Anulado`.
- [ ] `field_forma_de_pago`: los valores permitidos deben incluir las claves exactas
  - `efectivo`: la app lo usa para no pedir banco.
  - `Transferencia`: el bot guarda **todos** sus pagos con esa clave. Si falta,
    `POST /api/v1/bot/payments` responde 500.
- `field_canal`, `field_banco_emisor`, `field_banco_destino` y `field_comprobante_ocr`
  **no** se crean a mano en un sitio existente: los crea `drush updb` (7046).
  En un **sitio nuevo** sí hay que crearlos a mano (ver paso 4).

### Condominios (`condominio`)
- [ ] Rellenar `field_informacion_pago` en cada condominio. El bot lo recibe como
  `condominium_payment_info` en `bot/person` y `bot/units`.

### Usuarios
- [ ] `field_telefono` y `field_cedula` cargados: el bot identifica a la persona con ellos.
  Consultas de censo en `docs/bot.md`.

### Taxonomía `metodos_pago`
- [ ] Crear el campo **Tipo** (`field_tipo_pago`, texto) en el vocabulario.
- [ ] Rellenar nombre, descripción y tipo en cada término.
  - El valor de `field_tipo_pago` debe coincidir **exactamente** con una clave de
    los valores permitidos de `field_forma_de_pago` del pago. Si no coincide, la app
    recibe `invalid_payment_method`.
  - El tipo de efectivo debe ser `efectivo`. Es el único que no exige banco.
  - Los términos sin tipo **no aparecen** en la app.

### Taxonomía `bancos`
- [ ] Cargar los bancos. Alimentan `GET /api/v1/banks`.

---

## 2. `settings.php`

```bash
cd /var/www/html/sites/default
sudo chmod u+w settings.php
sudo nano settings.php
# ... añadir lo de abajo ...
sudo chmod 444 settings.php
```

```php
/**
 * Base URL — OBLIGATORIO.
 * Sin esto, Drupal 7 construye el enlace de reset de contraseña con la cabecera
 * Host que manda el cliente (un atacante puede desviar el token a su dominio).
 * Además, los correos enviados desde cron saldrían como http://default/...
 */
$base_url = 'https://crespcord.lamotora.com';

/**
 * URLs limpias — OBLIGATORIO. La app llama a /api/v1/... y nunca usa ?q=.
 * Requiere mod_rewrite y el .htaccess de Drupal activo (AllowOverride All).
 */
$conf['clean_url'] = 1;

/**
 * Ruta privada — OBLIGATORIA, fuera del docroot y escribible por www-data.
 * La usan comprobantes de pago, reclamos, galería de proveedor y solicitudes.
 * La update 7023 falla si no existe.
 */
$conf['file_private_path'] = '/var/www/private';

/**
 * Deep links de la app (esquema crespcord://). Los defaults del código (myapp://)
 * NO los abre la app.
 */
$conf['myapi_password_reset_deep_link_base'] = 'crespcord://restablecer-contrasena';
// Botón "Ver solicitud" de los correos → crespcord://solicitudes/<nid de la solicitud>.
// Solo lo abren las versiones posteriores a la 1.0.0+5; esa y las anteriores abren la app
// en su pantalla normal. Activarlo antes no rompe nada.
$conf['myapi_service_request_deep_link_base'] = 'crespcord://solicitudes';

/**
 * OneSignal (push).
 */
$conf['myapi_onesignal_app_id']       = '<ONESIGNAL_APP_ID>';   // el mismo que el .env de la app (fc37e7…)
$conf['myapi_onesignal_rest_api_key'] = '<ONESIGNAL_REST_API_KEY>';

/**
 * Firebase (chat). Service account del proyecto crespcord-app.
 * Requiere la extensión OpenSSL de PHP. Si falta, POST chat/token devuelve 503 chat_not_configured.
 * El código solo lee client_email y private_key; el resto es informativo.
 */
$conf['myapi_firebase_service_account'] = array(
  'project_id'     => 'crespcord-app',
  'private_key_id' => '<PRIVATE_KEY_ID>',
  'client_email'   => 'firebase-adminsdk-fbsvc@crespcord-app.iam.gserviceaccount.com',
  'private_key'    => "-----BEGIN PRIVATE KEY-----\n<KEY>\n-----END PRIVATE KEY-----\n",
);

/**
 * Bot de pagos (n8n). Generar con: openssl rand -hex 32
 * El mismo valor va en la cabecera X-Api-Key que envía n8n. Sin él, el bot recibe 401 en todo.
 */
$conf['myapi_bot_api_key'] = '<BOT_API_KEY>';

/**
 * Solo si hay proxy, balanceador o Cloudflare delante. Sin esto, todos los
 * usuarios comparten IP y unos pocos logins fallidos bloquean a todo el mundo.
 * Drupal 7 NO acepta rangos CIDR: hay que listar las IP una a una.
 */
// $conf['reverse_proxy'] = TRUE;
// $conf['reverse_proxy_addresses'] = array('<IP_DEL_PROXY>');

/**
 * Que el cron no se dispare en peticiones web (lo lanza el crontab, paso 5).
 */
$conf['cron_safe_threshold'] = 0;

/**
 * No mostrar errores PHP en las respuestas.
 */
$conf['error_level'] = 0;
```

Notas:
- `private_key` va entre **comillas dobles** para que `\n` se interprete como salto de línea.
- Variables opcionales con valores por defecto razonables: `myapi_token_access_ttl` (1800 s),
  `myapi_token_refresh_ttl` (30 días), `myapi_password_reset_ttl` (3600 s) y las
  `myapi_flood_*` (límites de intentos, ver `includes/myapi.flood.inc`).

---

## 3. Servidor (Apache, PHP, correo)

- [ ] **Que la cabecera `Authorization` llegue a PHP.** Con PHP-FPM o CGI, Apache
  la descarta y **toda la app recibe 401**. Añadir en el vhost:
  ```apache
  CGIPassAuth On
  ```
  Evitar la alternativa `SetEnvIf Authorization ...`: tras el rewrite de Drupal la
  variable puede llegar como `REDIRECT_HTTP_AUTHORIZATION`, y el código solo lee
  `HTTP_AUTHORIZATION`. Verificar con curl (paso 7).
- [ ] `mod_rewrite` activo y `AllowOverride All` en el docroot (URLs limpias).
- [ ] HTTPS forzado (redirect de 80 a 443) y HSTS.
- [ ] **Límites de subida en `php.ini`** (los de Ubuntu, 2M, no bastan). Todos los
  ficheros del módulo admiten hasta **5 MB** (comprobantes, imágenes y adjuntos de
  reclamos y solicitudes, galería, logo e icono). Una misma petición puede llevar
  varios: hasta 5 imágenes más un adjunto en reclamos y solicitudes (unos 30 MB):
  ```ini
  upload_max_filesize = 5M
  post_max_size = 40M
  max_file_uploads = 10
  ```
  Si hay proxy delante (nginx, Cloudflare), su límite de cuerpo también debe ser ≥ 40 MB.
  Reiniciar PHP-FPM o Apache después.
- [ ] Extensiones PHP: `openssl` (chat) y `fileinfo` (validación del tipo real de las subidas).
  Comprobar con `php -m`.
- [ ] **Sin directorios `private:` ni `public:` en el docroot.** Con PHP-FPM, si existe
  `/var/www/html/private:`, `move_uploaded_file()` deja las subidas ahí, en una ruta pública,
  en vez de en `/var/www/private`, y la API responde `invalid_file_type`. Pasó en staging el
  2026-10-23 (ver `docs/mitigacion-php-cve-2026-10.md`). Comprobar
  `ls -ld "/var/www/html/private:" "/var/www/html/public:"`: no debe existir ninguno. Después,
  subir un comprobante y confirmar que aparece en `/var/www/private/comprobantes_pago/`.
- [ ] **Drush 8 instalado.** `scripts/deploy.sh` ejecuta `drush updb` y `drush cc all`,
  y el cron (paso 5) también lo usa. Comprobar con `which drush`. Si no sale nada,
  instalarlo. Para Drupal 7 hace falta **Drush 8**: Drush 9 o superior no lo soporta.
  Drush 8 funciona en PHP 5.6, así que no hay que tocar el PHP del servidor.
  **Usar la 8.4.11, no la 8.4.12:** el `.phar` de la 8.4.12 trae un comprobador de
  requisitos con sintaxis PHP 7 y en PHP 5.6 da `Parse error ... .box/src/Checker.php`.
  ```bash
  ssh -i ~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem ubuntu@<IP_PROD>

  cd /tmp
  curl -fLO https://github.com/drush-ops/drush/releases/download/8.4.11/drush.phar
  php drush.phar --version          # debe decir Drush 8.4.11, sin errores
  cd /var/www/html && sudo -u www-data php /tmp/drush.phar status
  # ↑ antes de instalarlo: debe mostrar "Drupal version: 7.64" y "Database: Connected"
  sudo mv /tmp/drush.phar /usr/local/bin/drush
  sudo chmod +x /usr/local/bin/drush
  sudo rm -rf /tmp/drush-www-data   # caché de Drush con la ruta /tmp/drush.phar
  ```
  **Borrar la caché de Drush después de moverlo.** Drush guarda en su caché las rutas
  de sus propios ficheros (`/tmp/drush.phar/...`). Si no se borra, todo comando que
  carga Drupal entero (`php-eval`, `updb`, `cc all`, `en`) se corta con un
  `Drush command terminated abnormally` sin más detalle. El motivo solo aparece en
  `drush watchdog-show`: `PharStreamWrapper\Exception: Unexpected file extension in
  "phar:///tmp/drush.phar/..."`. Lo provoca el filtro `phar://` de Drupal 7.62+.
  Solo afecta a `www-data`, cuya caché va a `/tmp/drush-www-data` porque no puede
  escribir en su home (`/var/www`).
  Los avisos `stat failed for phar://.../sites/all/modules/*.drush.inc` son normales:
  solo indican que no se cargan los comandos drush de los módulos contrib, que el
  deploy no usa.
  Es un solo fichero y no instala dependencias. Para quitarlo:
  `sudo rm /usr/local/bin/drush`.
- [ ] **Correo saliente por SMTP.** El servidor no tiene sendmail. Configurar el módulo
  `smtp` y comprobar que el sistema de correo por defecto es
  `HTMLMailSystem__SmtpMailSystem`. Si cae en `DefaultMailSystem`, cada correo falla
  3 veces y se descarta (olvidé mi contraseña incluido). Para comprobarlo y dejarlo
  bien en un solo paso, con las 24 claves `myapi_*` registradas y un correo de prueba:
  ```bash
  scp scripts/php8/fix_mail_system.php ubuntu@<host>:/tmp/
  cd /var/www/html && sudo -u www-data drush php-script /tmp/fix_mail_system.php test
  ```
  **Trampa que lo deshace (vista en staging el 2026-10-26):** el módulo `mailsystem`
  comprueba en cada arranque que exista la clase de cada clave de `mail_system`, y
  borra las que no puede cargar. Si `drush` corre como un usuario que no puede leer
  `sites/all/modules/myapi/includes/` (ver sección 8, permisos), no ve
  `MyapiHtmlMailSystem`, borra todas las claves `myapi_*` y devuelve `default-system`
  a `DefaultMailSystem`. Regla: **`drush` siempre como `sudo -u www-data`**, y
  `drush vset mail_system` siempre con `--exact` (si no, pregunta entre `mail_system`
  y `smtp_previous_mail_system` y es fácil elegir mal).

---

## 4. Desplegar el código

```bash
drush vset maintenance_mode 1   # en el servidor, antes de copiar
./scripts/deploy.sh --env prod  # o scripts/deploy.ps1 -Env prod en Windows
drush vset maintenance_mode 0   # tras comprobar que updb terminó bien
```
El script sube el módulo, ejecuta `drush updb -y` y `drush cc all`.
- **Entorno.** Sin flag (o con `--env staging`) despliega en staging
  (`crespcord.lamotora.com`, `~/.ssh/crespcord.pem`). Con `--env prod` va a la
  instancia de Lightsail de Ohio con `~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem`
  y pide escribir `prod` para confirmar (`--yes` se lo salta).
  `scripts/deploy-manual.sh` acepta los mismos flags.
- La IP de producción no está en el repo. Copiar `scripts/deploy.local.env.example`
  a `scripts/deploy.local.env` (ignorado por git) y rellenar `MYAPI_PROD_HOST`, o
  exportar la variable. En Windows, `$env:MYAPI_PROD_HOST`. `MYAPI_DEPLOY_KEY`
  sigue sustituyendo la clave del entorno elegido.
- El script **no** tiene `set -e`: si falla un `scp`, sigue con `updb` igualmente.
  Revisar la salida antes de quitar el modo mantenimiento.
- El script usa `cp -r` y **no borra** en el servidor ficheros eliminados del repo.
  Si se eliminó algún `.inc`, borrarlo a mano.
- **Sitio nuevo:** antes, `drush en myapi -y`. Ojo: `hook_install` **no** crea los
  campos del bot de `pagos` (`field_canal`, `field_banco_emisor`, `field_banco_destino`,
  `field_comprobante_ocr`), y la 7046 no llega a ejecutarse porque el módulo ya queda
  en la 7050. Hay que crearlos a mano (definición en `myapi_update_7046()`), o
  `bot/payments` responde 500.
- Opcional: cargar las categorías de servicio. El script de deploy **no** sube
  `scripts/`, así que primero hay que copiarlo:
  ```bash
  scp scripts/seed-service-categories.php ubuntu@crespcord.lamotora.com:/tmp/
  drush php-script /tmp/seed-service-categories.php
  ```
  Los slugs (`plomeria`, `electricidad`…) son los que la app asocia a iconos.
- **Al migrar a PHP 8:** ver la sección 8, que recoge los ajustes probados en staging.

---

## 5. Cron (crontab de `www-data`)

```bash
sudo crontab -u www-data -e
```
```cron
* * * * * drush -r /var/www/html -l https://crespcord.lamotora.com queue-run myapi_onesignal_push
* * * * * drush -r /var/www/html -l https://crespcord.lamotora.com queue-run myapi_mail_send
0 * * * * drush -r /var/www/html -l https://crespcord.lamotora.com cron
```
- La cola de correo se llama **`myapi_mail_send`**, no `myapi_email`.
- El `cron` horario también procesa las dos colas; el `queue-run` por minuto solo
  acelera el envío. Además purga tokens y resets de contraseña caducados.
- `-l` evita enlaces `http://default/...` en los correos.
- Con Drush 9 o superior el comando es `queue:run`.

---

## 6. Configuración en el backoffice y servicios externos

### Reclamos
- [ ] Rules → **"validar condominio activo"**: seleccionar **solo** los roles que
  **no** deben pasar por esa validación.
- [ ] Menú de navegación:
  `https://crespcord.lamotora.com/admin/structure/block/manage/system/navigation/configure`
- [ ] Añadir la URL de reclamos al menú principal.

### Reservas
- [ ] Dar al rol `backend` permisos para crear y editar **reservas** y **áreas**.

### Proveedores / marketplace
- [ ] Dar permisos al rol `backend` sobre los tipos de contenido del marketplace.
- [ ] Revisar el menú de navegación (mismo enlace de arriba).

### Listados de back-office (SPEC 131)
- [ ] En `admin/structure/menu/manage/navigation`, añadir estos cuatro enlaces
  **debajo de *Solicitudes a proveedores***, en este orden:

  | Título | Ruta |
  |---|---|
  | Listado de reservas | `admin/content/reservations` |
  | Proveedores | `admin/content/providers` |
  | Ofertas de proveedores | `admin/content/service-offers` |
  | Calificaciones de servicio | `admin/content/service-ratings` |

  Drupal oculta el enlace a quien no puede entrar en la ruta: `administrator` y
  `backend` ven los cuatro, `administrador edificio` solo ve *Listado de
  reservas*. No hace falta ninguna visibilidad por rol.

  Lo mismo con un comando (cuelga los enlaces del mismo padre que *Solicitudes a
  proveedores*, con pesos justo detrás del suyo, y no duplica los que ya existan):

  ```bash
  drush php-eval "
  \$ref = db_query(\"SELECT mlid, plid, weight FROM {menu_links} WHERE menu_name = 'navigation' AND link_path = 'admin/content/service-requests'\")->fetchObject();
  if (!\$ref) { print 'No encuentro Solicitudes a proveedores en navigation' . PHP_EOL; return; }
  \$links = array(
    'admin/content/reservations' => 'Listado de reservas',
    'admin/content/providers' => 'Proveedores',
    'admin/content/service-offers' => 'Ofertas de proveedores',
    'admin/content/service-ratings' => 'Calificaciones de servicio',
  );
  \$w = \$ref->weight;
  foreach (\$links as \$path => \$title) {
    \$w++;
    if (db_query(\"SELECT 1 FROM {menu_links} WHERE menu_name = 'navigation' AND link_path = :p\", array(':p' => \$path))->fetchField()) { continue; }
    menu_link_save(array('menu_name' => 'navigation', 'link_path' => \$path, 'link_title' => \$title, 'plid' => \$ref->plid, 'weight' => \$w, 'module' => 'menu'));
  }
  menu_cache_clear_all();
  print 'OK' . PHP_EOL;
  " && drush cc all
  ```

  Si algún hermano posterior tiene el mismo peso que uno de los nuevos, Drupal
  ordena el empate por título: revisar el orden en la pantalla del menú y
  arrastrar si hace falta.
- [ ] Comprobar: con `backend` aparecen los cuatro; con `administrador edificio`
  solo *Listado de reservas*.

### Reporte de pagos (SPEC 132)
- [ ] En `admin/structure/menu/manage/navigation`, añadir este enlace **debajo
  de *Calificaciones de servicio***:

  | Título | Ruta |
  |---|---|
  | Reporte de pagos | `admin/content/payments` |

  Drupal oculta el enlace a quien no puede entrar en la ruta: lo ven
  `administrator` y `backend`, y **no** el `administrador edificio`. No hace
  falta ninguna visibilidad por rol.

  Lo mismo con un comando (cuelga el enlace del mismo padre que *Calificaciones
  de servicio*, con el peso justo detrás del suyo, y no lo duplica si ya existe):

  ```bash
  drush php-eval "
  \$ref = db_query(\"SELECT mlid, plid, weight FROM {menu_links} WHERE menu_name = 'navigation' AND link_path = 'admin/content/service-ratings'\")->fetchObject();
  if (!\$ref) { print 'No encuentro Calificaciones de servicio en navigation' . PHP_EOL; return; }
  if (db_query(\"SELECT 1 FROM {menu_links} WHERE menu_name = 'navigation' AND link_path = 'admin/content/payments'\")->fetchField()) { print 'Ya existe' . PHP_EOL; return; }
  menu_link_save(array('menu_name' => 'navigation', 'link_path' => 'admin/content/payments', 'link_title' => 'Reporte de pagos', 'plid' => \$ref->plid, 'weight' => \$ref->weight + 1, 'module' => 'menu'));
  menu_cache_clear_all();
  print 'OK' . PHP_EOL;
  " && drush cc all
  ```

  Si un hermano posterior tiene el mismo peso, Drupal ordena el empate por
  título: revisar el orden en la pantalla del menú y arrastrar si hace falta.
- [ ] Comprobar: con `backend` aparece *Reporte de pagos*; con `administrador
  edificio` no.

### Roles
- [ ] Revisar los permisos de `administrador edificio` y la advertencia en
  `admin/reports/status`.

### Firebase (chat)
- [ ] Realtime Database creada en modo bloqueado y **Firebase Authentication** activado.
- [ ] Publicar las reglas desde el repo de la app:
  ```bash
  firebase deploy --only database --project crespcord-app
  ```
  Sin ellas el chat no funciona aunque `chat/token` responda bien.

---

## 7. Verificación (smoke test)

```bash
drush cc all
drush queue-list                         # deben aparecer las dos colas
drush watchdog-show --type=myapi         # en Drush 9 o superior: watchdog:show
curl -s https://crespcord.lamotora.com/api/v1/ping          # URL limpia, sin ?q=
curl -s -H "Authorization: Bearer x" https://crespcord.lamotora.com/api/v1/units
# ↑ debe responder error_code "invalid_token", NO "missing_authorization" (eso indica que
#   Apache se come la cabecera: revisar el paso 3)
```
Desde la app (o con curl usando `Authorization: Bearer`):
- [ ] Login y luego `GET /api/v1/units`. **Si da 401, revisar el paso 3.**
- [ ] Registrar un pago con un comprobante de unos 4 MB. Se crea en "Pendiente de
  verificar" y el archivo queda en `private://comprobantes_pago`.
- [ ] Crear un reclamo con varias fotos de unos 4 MB (comprueba `post_max_size`).
- [ ] `GET /api/v1/payment-methods` muestra los métodos con su tipo.
- [ ] Aprobar el pago en el backoffice: **llega el push** al móvil y el saldo se
  aplica una sola vez.
- [ ] Olvidé mi contraseña: **llega el correo**, abre la página de reset y redirige a
  la app (`crespcord://restablecer-contrasena?token=...`).
- [ ] Chat: `POST /api/v1/chat/token` devuelve el token (no 503) y se puede enviar un mensaje.
- [ ] Bot: `curl -H "X-Api-Key: <BOT_API_KEY>" "https://crespcord.lamotora.com/api/v1/bot/person?phone=..."`
  devuelve `condominium_payment_info`.

---

## 8. Migración de producción a PHP 8.3 + Drupal 7.103

Todo lo de esta sección se probó en staging entre el 2026-10-22 y el 2026-10-26
(`docs/mitigacion-php-cve-2026-10.md` tiene el detalle de la parte de servidor). El
orden importa: primero el core, luego PHP, luego los ajustes, y al final la ventana de
24 horas con el watchdog vacío. Se puede hacer con la misma clave de Lightsail del paso 3.

> Versión extendida, con el porqué de cada paso, el resultado esperado y la vuelta atrás:
> `docs/migracion-php83-drupal7103.md`. **Producción se migró el 2026-10-28**; lo que salió
> distinto de lo previsto está en su sección 9 y ya recogido en esta checklist.

### 8.1 Antes de tocar nada

Lo de este bloque se puede hacer días antes de la ventana. Verificado el 2026-10-27 contra
staging (lectura, sin cambios) y contra los tarballs oficiales de la 7.64 y la 7.103.

- [ ] **Comprobar que el repositorio de Sury ya está en producción y ofrece la 8.3.36.**
  Producción instaló PHP 5.6.40 desde Sury, así que el PPA `ondrej/php` debería estar.
  Si el candidato no es `8.3.36-1+ubuntu22.04.1+deb.sury.org+1` (la versión exacta de
  staging), añadirlo antes de la ventana:
  ```bash
  apt-cache policy php8.3-fpm | head -3        # Candidate: 8.3.36-1+ubuntu22.04.1+deb.sury.org+1
  grep -rh "ondrej/php" /etc/apt/sources.list.d/   # deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu/ jammy main
  # solo si falta:
  sudo add-apt-repository ppa:ondrej/php && sudo apt update
  ```
- [ ] **Lista de paquetes PHP de staging**, para instalar exactamente lo mismo. A fecha
  2026-10-27 son estos 13 (todos en 8.3.36): `php8.3-cli php8.3-common php8.3-curl
  php8.3-fpm php8.3-gd php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache
  php8.3-readline php8.3-soap php8.3-xml php8.3-zip`. Si ha cambiado, sacar la lista fresca:
  ```bash
  ssh -i ~/.ssh/crespcord.pem ubuntu@crespcord.lamotora.com "dpkg -l 'php8.3*' | awk '/^ii/{print \$2}'"
  ```
  `fileinfo` y `openssl` (paso 3) vienen dentro de `php8.3-common` y `php8.3-cli`; no son
  paquetes aparte.
- [ ] **Descargar y descomprimir los dos tarballs del core fuera del docroot.** El 7.64
  sirve para saber qué tiene de personalizado el `.htaccess` de producción (paso 8.3):
  ```bash
  cd /tmp
  curl -fLO https://ftp.drupal.org/files/projects/drupal-7.103.tar.gz && tar xzf drupal-7.103.tar.gz
  curl -fLO https://ftp.drupal.org/files/projects/drupal-7.64.tar.gz  && tar xzf drupal-7.64.tar.gz
  grep "define('VERSION'" drupal-7.103/includes/bootstrap.inc        # '7.103'
  ```
  La 7.103 (2024-12-04) es la última versión de Drupal 7 y la primera que pasa los tests en
  PHP 8.3. Su mínimo es PHP 5.6 (`INSTALL.txt`), así que arranca en el PHP actual de
  producción: por eso el core va antes que PHP.
- [ ] **Qué va a cambiar el core.** Entre la 7.64 y la 7.103 hay 8 updates pendientes:
  `system_update_7082` a `7088` y `user_update_7020`. Dos tienen efecto visible:
  - **7086 cambia el formato de los IDs de sesión** (los hashea): **todos los usuarios del
    backoffice quedan desconectados** al terminar. La app no se ve afectada: usa tokens
    de `myapi_tokens`, no sesiones de Drupal.
  - **7087 habilita el módulo `announcements_feed`** (avisos de drupal.org en el panel).
    Se puede deshabilitar después con `drush dis announcements_feed`.
  El `.htaccess` de la 7.103 solo añade endurecimiento (`web.config` a la lista de ficheros
  denegados, `RequestHeader unset Proxy`, `no-brotli`) y conserva el bloque
  `<IfModule mod_php5.c>`; no trae bloques `php7` ni `lsapi`. `robots.txt` no cambia.
  `default.settings.php` solo añade claves comentadas: **no hay que tocar `settings.php`**.
- [ ] **Comprobar qué hay en el docroot que no sea core.** El paso 8.3 borra una lista
  explícita de ficheros y directorios del core (`profiles/`, `misc/`, `modules/`, los
  `.php` y `.txt` de la raíz…). Si hay perfiles o ficheros propios, hay que conservarlos:
  ```bash
  cd /var/www/html
  comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.64 | sort)   # lo que no es core: anotarlo
  ls profiles/                       # solo debe haber README.txt minimal standard testing (así está staging)
  ls -ld includes index.php sites    # anotar propietario: el core nuevo debe quedar igual (staging: www-data:www-data)
  ls /etc/apache2/mods-enabled/ | grep -E "php|mpm"   # php5.6.load y mpm_prefork: son los que se desactivan
  which drush && drush --version     # /usr/local/bin/drush, 8.4.11: hace falta para la vuelta atrás
  ```
  `comm` solo compara nombres: para ver ficheros del core **modificados** o añadidos
  (restos de malware, parches locales), el `diff -rq` del paso 2.5 de la guía extendida.
- [ ] **Ficheros sensibles descargables.** Probar con `curl` cada fichero no-core del
  docroot (paso 2.5 de la guía). El `.htaccess` bloquea `.sql`, pero no `.sql.gz`, `.zip`,
  `.ini.bak` ni `error_log`. Lo que dé `200` y no deba ser público, fuera del docroot ese
  mismo día; si era un volcado o un `settings.php`, rotar la contraseña de la base de datos.
- [ ] **Comparar `sites/all` con staging y pasar el lint de PHP 8.3** (paso 2.7 de la guía):
  `sha256sum` de todo `sites/all` en los dos servidores y `php -l` de cada fichero con
  `xargs ... sh -c 'php -l "$0" ...; true'` (sin el `true`, `xargs` aborta en el primer
  fallo). Todo directorio que difiera se trae de staging en la ventana (paso 4.9 de la
  guía). En producción difirieron 21 módulos y el tema `bootstrap`; Views 3.20 da *Fatal*
  en PHP 8. `views_php` y `print_pdf` deben estar deshabilitados.
- [ ] Comprobar que **no existen** `/var/www/html/private:` ni `/var/www/html/public:`
  (paso 3). Con FPM, si existen, las subidas acaban ahí.
- [ ] Anotar el `mail_system` actual: `sudo -u www-data drush vget --exact mail_system`.
  Debe tener `default-system = HTMLMailSystem__SmtpMailSystem` y 24 claves `myapi_*`.

### 8.2 Copias de seguridad (al inicio de la ventana)

- [ ] **Carpeta de copias, lo primero.** Todos los pasos siguientes escriben ahí.
  `/home/ubuntu` es `750` en Ubuntu 22.04: `www-data` no puede escribir en `~/backups`,
  así que ninguna copia se hace como `www-data`.
  ```bash
  mkdir -p ~/backups && chmod 700 ~/backups
  ```
- [ ] **Modo mantenimiento**, vaciando la caché de páginas: Drupal 7 la sirve antes de
  comprobar el mantenimiento, y sin esto la portada sigue dando `200`:
  ```bash
  cd /var/www/html && sudo -u www-data drush vset maintenance_mode 1
  sudo -u www-data drush php-eval 'cache_clear_all("*", "cache_page", TRUE);' 2>/dev/null
  curl -s -o /dev/null -w '%{http_code}\n' https://sistema.crespcord.com/api/v1/catalogs   # 503
  ```
- [ ] **Pausar el cron de `www-data`.** Lanza `drush` cada minuto y fallaría con el core a
  medio copiar. El borrado solo ocurre si la copia existe y no está vacía:
  ```bash
  sudo crontab -u www-data -l > ~/backups/crontab-www-data.txt \
    && test -s ~/backups/crontab-www-data.txt \
    && sudo crontab -u www-data -r
  cat ~/backups/crontab-www-data.txt      # las tres líneas del paso 5
  ```
- [ ] **Snapshot de Lightsail**, después del mantenimiento para que no quede fuera ningún
  dato: consola (Instancia → Snapshots → *Create snapshot*) o
  `aws lightsail create-instance-snapshot --instance-name <nombre> --instance-snapshot-name pre-php83-$(date +%Y%m%d)`.
  Esperar a que esté *Available*. Es la vuelta atrás completa, y la única fiable si falla
  el core (ver «Vuelta atrás» en `docs/migracion-php83-drupal7103.md`).
- [ ] **Credenciales de MySQL.** No hay `~/.my.cnf`. Se sacan de `settings.php` a un
  fichero de opciones `600`, para que la contraseña no quede en el historial ni en `ps`:
  ```bash
  install -m 600 /dev/null ~/backups/mysql-prod.cnf
  cd /var/www/html
  sudo -u www-data drush php-eval '$d = Database::getConnectionInfo(); $d = $d["default"];
    echo "[client]\nuser=" . $d["username"] . "\npassword=\"" . addcslashes($d["password"], "\"\\") . "\"\nhost=" . $d["host"] . "\n";
    if (!empty($d["port"])) { echo "port=" . $d["port"] . "\n"; }' >> ~/backups/mysql-prod.cnf
  DB=$(sudo -u www-data drush php-eval '$d = Database::getConnectionInfo(); echo $d["default"]["database"];')
  mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" -e "SELECT COUNT(*) FROM dr_rules_config"
  ```
  `addcslashes()` escapa `"` y `\` en la contraseña: los ficheros de opciones de MySQL
  interpretan secuencias de escape (`\\`, `\n`, `\s`…) incluso entre comillas, y una barra
  invertida sin escapar da `Access denied`.
- [ ] **Backup de base de datos y ficheros.** Con `mysqldump`, no con `drush sql-dump`:
  este corre como `www-data`, y además `--tables-list=X` se ignora en ese Drush y vuelca la
  base entera (1,5 GB en dos minutos en staging). `--defaults-extra-file` tiene que ser la
  primera opción.
  ```bash
  TS=$(date +%Y%m%d-%H%M)
  mysqldump --defaults-extra-file=$HOME/backups/mysql-prod.cnf --single-transaction "$DB" | gzip > ~/backups/db-$TS.sql.gz
  mysqldump --defaults-extra-file=$HOME/backups/mysql-prod.cnf --single-transaction "$DB" dr_rules_config > ~/backups/rules_config-$TS.sql
  sudo tar czf ~/backups/files-$TS.tgz -C /var/www/html sites/default/files -C / var/www/private
  gzip -t ~/backups/db-$TS.sql.gz && echo OK
  ```
- [ ] Copias de lo que la migración sobrescribe o purga:
  ```bash
  sudo cp -a /var/www/html/.htaccess  ~/backups/htaccess.pre-7.103
  sudo cp -a /var/www/html/robots.txt ~/backups/robots.txt.pre-7.103
  sudo tar czf ~/backups/etc-php-5.6.tgz /etc/php/5.6
  sudo tar czf ~/backups/etc-apache2.tgz /etc/apache2
  sudo cp -a /usr/local/bin/drush ~/backups/drush-8.4.11      # la 8.4.12 no parsea en PHP 5.6
  cp /var/www/html/sites/all/modules/entityreference_count/entityreference_count.module ~/backups/
  ```
  El dump de `dr_rules_config` es la vuelta atrás del ajuste de Rules (paso 8.4): las 13
  reglas con PHP embebido viven en esa tabla, no en ninguna feature.

### 8.3 Core y PHP (ventana de mantenimiento)

- [ ] **Actualizar el core a Drupal 7.103 todavía en PHP 5.6**: la 7.64 no arranca en
  PHP 8. Es el procedimiento de `UPGRADE.txt` (quitar el core viejo salvo `sites/`, copiar
  el nuevo, `updb`). Primero ver qué tiene de propio el `.htaccess` de producción:
  ```bash
  diff /tmp/drupal-7.64/.htaccess  ~/backups/htaccess.pre-7.103
  diff /tmp/drupal-7.64/robots.txt ~/backups/robots.txt.pre-7.103   # sin salida = de serie
  ```
  Deben salir solo los bloques `php7_module` y `lsapi_module` de cPanel y las líneas
  `# CVE-2026 mitigation:`. Los tres sobran en FPM, así que el `.htaccess` de serie de la
  7.103 vale tal cual (staging lo usa sin modificar, md5 `054cda93…`). Si saliera algo
  más (una redirección propia, por ejemplo), reaplicarlo sobre el nuevo. Lo mismo con
  `robots.txt` si tuviera cambios.
  Se borra una **lista explícita** de ficheros del core 7.64, no `*.php` ni `*.txt`, para
  no llevarse ficheros propios del docroot:
  ```bash
  cd /var/www/html
  OWNER=$(stat -c %U:%G index.php)      # propietario actual del core (staging: www-data:www-data)
  CORE_DIRS="includes misc modules profiles scripts themes"
  CORE_FILES=".htaccess .editorconfig .gitignore web.config robots.txt \
    authorize.php cron.php index.php install.php update.php xmlrpc.php \
    CHANGELOG.txt COPYRIGHT.txt INSTALL.txt INSTALL.mysql.txt INSTALL.pgsql.txt \
    INSTALL.sqlite.txt LICENSE.txt MAINTAINERS.txt README.txt UPGRADE.txt"
  [ "$PWD" = /var/www/html ] && [ -f sites/default/settings.php ] && [ "$OWNER" = www-data:www-data ] \
    && sudo rm -rf $CORE_DIRS $CORE_FILES \
    && for f in $CORE_DIRS $CORE_FILES; do sudo cp -a "/tmp/drupal-7.103/$f" .; done \
    && sudo chown -R "$OWNER" $CORE_DIRS $CORE_FILES && echo "== core sustituido =="
  # el .htaccess de serie reactiva la mitigación CVE: volver a comentarla hasta el cambio a FPM
  sudo sed -i '/^<IfModule mod_php5.c>$/,/^<\/IfModule>$/ s/^/# CVE-2026 mitigation: /' .htaccess
  comm -23 <(ls -A /var/www/html | sort) <(ls -A /tmp/drupal-7.103 | sort)   # lo mismo que en 8.1
  sudo -u www-data drush updb -n            # listar: 8 updates, system 7082-7088 y user 7020
  sudo -u www-data drush updb -y
  sudo -u www-data drush cc all
  sudo -u www-data drush status | grep "Drupal version"    # 7.103
  ```
  Si `authorize.php` o `xmlrpc.php` no existían (paso 8.1), quitarlos de `CORE_FILES`: no
  se usan y `xmlrpc.php` es superficie de ataque. En producción no se repusieron.
  **No se toca `sites/`** (ahí están `settings.php`, `files/` y `sites/all/modules`).
  Esto repone también `misc/typo3/` completo, que es lo que falla con el fatal
  `Interface 'TYPO3\PharStreamWrapper\Assertable' not found` de `error.log`.
- [ ] **`trusted_host_patterns`.** No es obligatorio, pero staging lo tiene y la 7.103 lo
  documenta en `default.settings.php`. Añadir al final de `sites/default/settings.php`:
  ```php
  $conf['trusted_host_patterns'] = array('^sistema\.crespcord\.com$');
  ```
  Un patrón por cada `ServerName`/`ServerAlias`. Para verlos, `grep -R` (mayúscula):
  `sites-enabled/` son enlaces simbólicos y `grep -r` no los sigue.
- [ ] **Instalar PHP 8.3 (Sury) con PHP-FPM** y pasar Apache a `mpm_event` + `proxy_fcgi`,
  como en staging. `mpm_prefork` hay que desactivarlo antes de activar `mpm_event`, o
  `a2enmod` se niega. Sin `apt update` (el candidato ya se comprobó), simulando antes
  (`apt-get install -s`: ninguna línea `Remv`) y con `NEEDRESTART_MODE=l` para que el
  diálogo de `needrestart` no ofrezca reiniciar Apache y MariaDB a mitad de la ventana.
  Hasta el paso de Drush 8.4.12, no usar `drush`: la 8.4.11 no va en PHP 8.3.
  ```bash
  sudo NEEDRESTART_MODE=l apt-get install -y php8.3-cli php8.3-common php8.3-curl php8.3-fpm php8.3-gd php8.3-intl \
    php8.3-mbstring php8.3-mysql php8.3-opcache php8.3-readline php8.3-soap php8.3-xml php8.3-zip
  sudo a2dismod php5.6 mpm_prefork
  sudo a2enmod mpm_event proxy proxy_fcgi setenvif
  sudo a2enconf php8.3-fpm                  # lo trae el paquete php8.3-fpm: SetHandler proxy:unix:/run/php/php8.3-fpm.sock
  sudo update-alternatives --set php /usr/bin/php8.3
  php -v                                    # PHP 8.3.36 (cli)
  ```
  El MPM `event` ignora `mpm_prefork.conf`, así que los ajustes de `MaxRequestWorkers 30`
  y `MaxConnectionsPerChild 1` de la mitigación CVE dejan de aplicarse solos. Staging
  usa `mpm_event.conf` de serie (`MaxRequestWorkers 150`, 25 hilos por proceso); con FPM
  el límite real de procesos PHP es `pm.max_children`, no el de Apache.
- [ ] **`CGIPassAuth On`** (paso 3). En staging vive en un `conf-available` propio con el
  `<Directory>` del docroot. Crearlo igual en producción, porque con FPM sin él
  `$_SERVER['HTTP_AUTHORIZATION']` llega vacío y **toda la app da 401**:
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
  basta con añadirle la línea `CGIPassAuth On`; no duplicar el bloque.
- [ ] **Valores de PHP para FPM.** No se heredan del 5.6, y **no van en `php.ini`**: staging
  los tiene en un fichero aparte en `conf.d/`, que sobrevive a las actualizaciones del
  paquete. Copiarlo literal (es el de staging a 2026-10-27; `10M` por fichero cubre los
  5 MB del paso 3):
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
  Este fichero es el que define el `/var/log/php8.3-fpm-errors.log` del paso 8.5. El otro
  log, `/var/log/php8.3-fpm.log`, es el del *master* de FPM (arranques de hijos y avisos de
  `pm.max_children`) y solo lo lee root.
- [ ] **Pool de FPM.** El `www.conf` de serie trae `pm.max_children = 5` y
  `pm.max_requests` comentado. Dejarlo como staging:
  ```bash
  F=/etc/php/8.3/fpm/pool.d/www.conf
  sudo cp -a $F $F.orig
  sudo sed -i -E 's/^pm\.max_children\s*=.*/pm.max_children = 8/' $F
  sudo sed -i -E 's/^;?pm\.max_requests\s*=.*/pm.max_requests = 500/' $F
  grep -E "^(pm|pm\.[a-z_]+)\s*=" $F      # dynamic, 8, 2, 1, 3, 500
  ```
  **`memory_limit = 512M` con 8 procesos, a propósito.** Es un techo por proceso, no un
  consumo: la medida real en producción es de unos 56 MB de RSS por proceso, y staging probó
  con 512M los PDF, las exportaciones a Excel y Feeds. Pero 8 × 512M son 4 GB, más que los
  3,8 GB de producción con MariaDB dentro. Si alguna vez aparece un OOM en `dmesg` o muere
  MariaDB, **bajar primero `pm.max_children`**, no `memory_limit`: lo segundo rompe los PDF
  y las exportaciones, lo primero solo pone peticiones en cola. Para subir
  `pm.max_children`, la señal es `server reached pm.max_children` en
  `/var/log/php8.3-fpm.log`.
- [ ] **Reiniciar y comprobar.** Con el sitio en mantenimiento, Drupal responde 503 a
  **toda** petición anónima, también a `/api/v1/ping`: `myapi` no tiene bypass de
  mantenimiento. Un 503 aquí es la buena señal (Drupal arranca en PHP 8.3); un 502 o un
  500, no.
  ```bash
  sudo apache2ctl configtest && sudo systemctl restart php8.3-fpm apache2
  systemctl is-active apache2 php8.3-fpm
  curl -s -o /dev/null -w '%{http_code}\n' https://sistema.crespcord.com/user/login     # 200: Drupal arranca
  curl -s -o /dev/null -w '%{http_code}\n' https://sistema.crespcord.com/api/v1/catalogs # 503
  ```
  Que el login cargue no garantiza que todo compile: no usa Views.
- [ ] **Comprobar `CGIPassAuth` antes de abrir el sitio.** Si falla, toda la app da 401,
  y con la API en 503 no se vería hasta el paso 8.5. Script temporal fuera de Drupal, con
  la misma técnica que la verificación de la mitigación CVE: nombre aleatorio, solo
  responde a `127.0.0.1`, se borra al terminar. Comprueba de paso los límites de FPM.
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
  ```
  Debe dar `HTTP_AUTHORIZATION=Bearer x`, `PHP=8.3.36 SAPI=fpm-fcgi` y
  `upload_max_filesize=10M post_max_size=40M memory_limit=512M`. Con `(missing)`, revisar
  `CGIPassAuth` **antes de seguir**.
- [ ] **Drush a la 8.4.12.** La 8.4.11 del paso 3 se eligió porque la 8.4.12 no parsea en
  PHP 5.6. En PHP 8.3 ya no hay ese problema y staging corre la **8.4.12** sin ningún aviso
  (`drush status` limpio). Sustituir el phar y borrar la caché de Drush de `www-data`
  (paso 3), que guarda rutas al phar:
  ```bash
  cd /tmp && curl -fLO https://github.com/drush-ops/drush/releases/download/8.4.12/drush.phar
  php drush.phar --version                  # Drush Version : 8.4.12
  sudo mv drush.phar /usr/local/bin/drush && sudo chmod +x /usr/local/bin/drush
  sudo rm -rf /tmp/drush-www-data
  cd /var/www/html && sudo -u www-data drush status | grep -E "Drupal version|PHP configuration"   # 7.103 y /etc/php/8.3/cli/php.ini
  ```
- [ ] Quitar las mitigaciones temporales de los CVE (`docs/mitigacion-php-cve-2026-10.md`):
  ya no hacen falta con PHP parcheado. El `.htaccess` quedó limpio al actualizar el core;
  el `.ini` de 5.6 desaparece con el purge; queda el `conf` de Apache:
  ```bash
  sudo a2disconf cve-2026-mitigation && sudo rm /etc/apache2/conf-available/cve-2026-mitigation.conf
  sudo apache2ctl configtest && sudo systemctl reload apache2
  ```
- [ ] **Traer de staging los módulos contrib y el tema que difieran** (paso 8.1) y aplicar
  sus updates, **antes** de los ajustes de 8.4: el `drush cc all` de allí compila todo, y
  con Views 3.20 da *Fatal*. Procedimiento completo en el paso 4.9 de la guía: empaquetar
  en staging, apartar los directorios viejos a `~/backups/contrib-old-$TS`, extraer,
  `sha256sum -c` contra los hashes de staging, `rm -rf /tmp/drush-www-data`,
  `drush updb -n`, `updb -y` y `cc all` (debe terminar en `'all' cache was cleared`).
  En producción fueron 11 updates de contrib, 16 segundos. Desde aquí, la única vuelta
  atrás fiable es el snapshot.
- [ ] **Purgar PHP 5.6 solo al final**, tras la ventana de 24 horas del paso 8.5 (ya hay
  copia de `/etc/php/5.6` en `~/backups`). Igual que se hizo en staging con el 7.4, y con
  el mismo comando: `apt-get`, porque `apt` 2.x no acepta comodines como `php5.6*`.
  ```bash
  sudo NEEDRESTART_MODE=l apt-get purge -y 'php5.6*' libapache2-mod-php5.6 && sudo NEEDRESTART_MODE=l apt-get autoremove -y
  dpkg -l 'php5.6*' | grep ^ii             # vacío
  sudo apache2ctl configtest && systemctl is-active apache2 php8.3-fpm
  shred -u ~/backups/mysql-prod.cnf        # credenciales del paso 8.2: ya no hacen falta
  ```
  El bloque `<IfModule mod_php5.c>` del `.htaccess` puede quedarse: con FPM no se aplica.
  Borrar también `/etc/php/5.6`, que dpkg deja con el `.ini` de la mitigación CVE.
  Procedimiento completo en la sección 7 de la guía.
- [ ] **Rotar la contraseña de la base de datos** si hubo ficheros sensibles descargables
  (paso 8.1), sin mostrarla, y destruir todas las copias de la antigua: en producción
  también estaba en `/root/.mysql_history` (paso 7.2 de la guía).
- [ ] **Reiniciar la instancia fuera de horas** (resuelve los reinicios que `needrestart`
  dejó aplazados y prueba que todo arranca solo). Antes: snapshot nuevo y el *drop-in*
  `apache2.service.d/after-php-fpm.conf` para que Apache espere a FPM; sin él, el primer
  segundo tras el arranque da 503 (paso 7.4 de la guía). Cuando todo lleve unos días
  estable, borrar los snapshots de la migración.

### 8.4 Ajustes que PHP 8 exige (los tres fallaron en staging)

- [ ] **Permisos del módulo.** `scp -r` deja `includes/`, `resources/` y `assets/` como
  `700`. Ningún usuario distinto de `www-data` puede leerlos, y eso rompe el correo
  (sección 3) y hace fallar `drush cc all` con `opendir ... Failed to open directory`.
  `scripts/deploy.sh` ya los deja en 755/644 desde el 2026-10-26; en un servidor
  desplegado antes, corregirlo a mano:
  ```bash
  sudo find /var/www/html/sites/all/modules/myapi -type d -exec chmod 755 {} +
  sudo find /var/www/html/sites/all/modules/myapi -type f -exec chmod 644 {} +
  ```
- [ ] **Parche a `entityreference_count` 7.x-1.0-beta1.** El campo `field_payphone`
  del condominio guarda su configuración como el array crudo de checkboxes, con los no
  marcados a `0`; el módulo hace `field_info_field(0)` y lee `storage` de un `null`.
  En PHP 5.6 era silencioso; en PHP 8 son 16 warnings por cada pago editado. No hay
  parche en drupal.org (la cola del módulo no tiene ningún ticket de PHP 8).
  ```bash
  scp scripts/php8/entityreference_count-php8-null-field.patch ubuntu@<host>:/tmp/
  cd /var/www/html && sudo patch -p1 < /tmp/entityreference_count-php8-null-field.patch
  M=sites/all/modules/entityreference_count
  sudo chown --reference=$M/entityreference_count.info $M/entityreference_count.module   # patch lo deja como root
  sudo chmod --reference=$M/entityreference_count.info $M/entityreference_count.module
  php -l $M/entityreference_count.module
  ```
- [ ] **PHP embebido en Rules.** Las plantillas de correo y los cálculos de alícuota
  hacen `$node->field_x['und'][0]['value']` sobre campos vacíos y `round(null, 4)`:
  en PHP 8 son «Undefined array key», «array offset on null» y la deprecación de
  `round()`. El script reescribe esos accesos con `isset()` / `!empty()` y castea a
  `(float)` antes de `round()`, pasa `php -l` a cada fragmento y sin `apply` solo simula.
  En staging cambió 13 reglas; en producción puede haber más o menos: leer la
  simulación antes de aplicar.
  ```bash
  scp scripts/php8/fix_rules_php.php ubuntu@<host>:/tmp/
  cd /var/www/html
  sudo -u www-data drush php-script /tmp/fix_rules_php.php          # simulación
  sudo -u www-data drush php-script /tmp/fix_rules_php.php apply    # escribe
  sudo -u www-data drush cc all
  ```
  Si la simulación muestra `!! LINT FAILED`, no aplicar. Para volver atrás tras aplicar,
  restaurar la tabla y vaciar la caché, porque Rules guarda las reglas compiladas:
  ```bash
  mysql --defaults-extra-file=$HOME/backups/mysql-prod.cnf "$DB" < ~/backups/rules_config-<fecha>.sql
  sudo -u www-data drush cc all
  ```
- [ ] **Correo.** Ejecutar `fix_mail_system.php test` como en la sección 3 y confirmar
  que llega el correo de prueba. Hacerlo **después** de corregir los permisos; si no,
  el siguiente `drush` lo deshace.

### 8.5 Verificación

- [ ] **Límites de PHP reales en FPM.** Ya se vieron con el script temporal del paso 8.3.
  `drush` lee `/etc/php/8.3/cli/php.ini`, no el de FPM, así que `drush php-eval "ini_get(...)"`
  **no sirve** para comprobarlos (en staging devuelve los 2M/8M de serie aunque FPM corra
  con 10M/40M). Si hace falta repetirlo: ese script, `admin/reports/status` → *Información
  de PHP*, o subir un comprobante de más de 2 MB.
- [ ] Salir de mantenimiento y restaurar el cron, **solo si el lint completo de
  `sites/all` no tiene más fallos que `libraries/PHPExcel` y `libraries/dompdf`** (los
  mismos que staging; bloque exacto en el paso 6.2 de la guía):
  ```bash
  cd /var/www/html && sudo -u www-data drush vset maintenance_mode 0
  sudo crontab -u www-data ~/backups/crontab-www-data.txt
  sudo crontab -u www-data -l              # las tres líneas
  curl -s -H "Authorization: Bearer x" https://sistema.crespcord.com/api/v1/units   # 401 invalid_token, no missing_authorization
  ```
- [ ] El smoke test del paso 7 completo. Los usuarios del backoffice tendrán que volver a
  iniciar sesión (update 7086).
- [ ] Subir un comprobante desde la app y confirmar que aparece en
  `/var/www/private/comprobantes_pago/` y que **no** ha nacido ningún
  `/var/www/html/private:` (incidencia de staging del 2026-10-23).
- [ ] Editar un pago, aprobarlo y generar un recibo con alícuota extra. Luego:
  ```bash
  sudo -u www-data drush watchdog-show --type=php     # solo Deprecated
  sudo -u www-data drush watchdog-show --type=mail    # sin «Error sending e-mail»
  sudo grep -v Deprecated /var/log/php8.3-fpm-errors.log | tail   # sin Fatal posteriores a la apertura
  ```
  En PHP 8.3, `date`, `views` y `entity` dejan `Deprecated` en casi cada página: son
  normales. Lo que no: `Warning`, `Notice`, `Error` o `Fatal`. El pago, el reclamo y el
  saldo de las pruebas quedan en la contabilidad: anularlos al terminar.
- [ ] Ventana de **24 horas** con `watchdog-show --type=php` vacío y uso real del
  backoffice (reportes, exportaciones a Excel, PDF de alícuotas, Feeds, CKEditor, VBO).
  La lista de pruebas de staging sirve de guion.
- [ ] Al crear un pago desde la app llega el correo `payment_created_admin` a cada
  usuario del rol `backend` con dirección real.

**Para todo lo anterior: `drush` siempre como `sudo -u www-data`.** Como `ubuntu` no
lee `myapi/includes` hasta corregir los permisos, deja un falso «Undefined constant
MYAPI_…» en el watchdog y, peor, borra las claves de correo (sección 3).

**Vuelta atrás.** Está en la sección 8 de `docs/migracion-php83-drupal7103.md`. Lo
esencial:
- **Si falla el core, la vuelta atrás es el snapshot**, o el dump de base de datos más el
  core 7.64. Volver a copiar solo el core 7.64 no basta: el update 7086 cambió la tabla de
  sesiones y el 7087 dejó habilitado un módulo que la 7.64 no tiene.
- **El snapshot arranca en mantenimiento y sin cron.** Hay que hacer
  `drush vset maintenance_mode 0` y restaurar `~/backups/crontab-www-data.txt`.
- **Volver a PHP 5.6** no es solo Apache. También hay que parar y deshabilitar
  `php8.3-fpm` (`systemctl stop` y `systemctl disable`, o vuelve a arrancar en cada
  reinicio), restaurar `update-alternatives` a `php5.6`, Drush 8.4.11 desde `~/backups`, y
  la mitigación CVE: el `conf` de Apache y el bloque comentado del `.htaccess`.
