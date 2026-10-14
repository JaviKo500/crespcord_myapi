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
- [ ] **Correo saliente por SMTP.** El servidor no tiene sendmail. Configurar el módulo
  `smtp` y comprobar que el sistema de correo por defecto es
  `HTMLMailSystem__SmtpMailSystem`. Si cae en `DefaultMailSystem`, cada correo falla
  3 veces y se descarta (olvidé mi contraseña incluido).

---

## 4. Desplegar el código

```bash
drush vset maintenance_mode 1   # en el servidor, antes de copiar
./scripts/deploy.sh             # o scripts/deploy.ps1 en Windows
drush vset maintenance_mode 0   # tras comprobar que updb terminó bien
```
El script sube el módulo, ejecuta `drush updb -y` y `drush cc all`.
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
