# Despliegue en producción — Notificaciones push (OneSignal)

Guía operativa para dejar funcionando el envío de notificaciones push del módulo
`myapi` en un servidor nuevo (producción). Usa la **Opción B: un cron dedicado**
que procesa **solo** la cola de push, sin ejecutar el cron general del sitio.

---

## Cómo funciona (resumen)

1. Un administrador crea un **boletín publicado** en el backend de Drupal.
2. `hook_node_insert` hace el **fan-out**: inserta una fila por destinatario en la
   tabla `myapi_notifications` (el inbox, síncrono e inmediato) y **encola** el push
   en la cola `myapi_onesignal_push`.
3. Un **cron dedicado** ejecuta `drush queue-run myapi_onesignal_push` cada minuto,
   toma los items pendientes y los envía a OneSignal.

> El inbox es la fuente de verdad; el push es *best-effort*. Si OneSignal falla, la
> notificación igual está en el inbox.

---

## El comando clave

Todo el envío automático se reduce a **este comando**, ejecutado periódicamente por
el cron del sistema:

```bash
drush queue-run myapi_onesignal_push
```

- **Qué hace:** procesa **únicamente** la cola `myapi_onesignal_push` (los pushes
  pendientes). No corre el `hook_cron` de otros módulos, ni indexado de búsqueda, ni
  limpieza de caché.
- **En Drush 9 o superior** el comando es `drush queue:run myapi_onesignal_push`
  (con dos puntos). Verifica tu versión con `drush version`.

---

## Pasos de despliegue (en orden)

Ejecuta todo desde la raíz de Drupal (p. ej. `/var/www/html`). Ajusta rutas y el
usuario del servidor web (`www-data`) a los de tu entorno.

### 1. Desplegar el código del módulo

Sube el código del módulo `myapi` (git/rsync). Deben quedar presentes los archivos:
`includes/myapi.notification.inc`, `includes/myapi.onesignal.inc`,
`resources/notification.resource.inc`, además de `myapi.module`, `myapi.install`,
`myapi.info` e `includes/myapi.i18n.inc` actualizados.

### 2. Verificar y corregir permisos de archivos

El usuario que ejecuta el cron (el mismo del web server, normalmente `www-data`)
**debe poder leer** los directorios del módulo. Un despliegue con permisos
restrictivos (700) rompe la carga de los `.inc` y el cron no encuentra el worker.

```bash
# Diagnóstico: ¿quién es dueño y con qué permisos?
ls -ld sites/all/modules/myapi sites/all/modules/myapi/includes sites/all/modules/myapi/resources

# Corrección estándar de Drupal: directorios 755, archivos 644
sudo find sites/all/modules/myapi -type d -exec chmod 755 {} \;
sudo find sites/all/modules/myapi -type f -exec chmod 644 {} \;
```

- **Para qué sirve:** garantizar que `www-data` pueda abrir `includes/` y
  `resources/` (los directorios necesitan el bit `x`; por eso 755).
- **Regla de oro:** el cron se corre **siempre como `www-data`** (ver paso 6). No lo
  corras como `ubuntu` (permission denied) ni como `root` (crearía archivos de root
  que luego el web no puede leer).

### 3. Crear la tabla de notificaciones (solo la primera vez)

```bash
drush updb
```

- **Qué hace:** ejecuta las actualizaciones pendientes del módulo. `myapi_update_7004`
  crea la tabla `myapi_notifications` si no existe.
- **Cómo usarlo:** revisa la lista de updates que muestra y confirma. En un sitio
  nuevo también basta con habilitar el módulo (`drush en myapi`), que crea la tabla
  desde `hook_schema()`.
- **Verificar:** `drush sql:query "SHOW TABLES LIKE 'myapi_notifications';"` debe
  devolver la tabla.

### 4. Configurar las credenciales de OneSignal

Son **variables de Drupal**, distintas por entorno. Decide si producción usa el
**mismo** proyecto OneSignal que pruebas o **uno distinto** (lo normal: uno distinto,
para no mezclar dispositivos de prueba con usuarios reales).

**Opción recomendada — en `settings.php`** (el secreto queda fuera de la BD y del
repo):

```php
$conf['myapi_onesignal_app_id'] = 'APP_ID_DE_PRODUCCION';
$conf['myapi_onesignal_rest_api_key'] = 'REST_API_KEY_DE_PRODUCCION';
```

**Alternativa — con drush:**

```bash
drush vset myapi_onesignal_app_id "APP_ID_DE_PRODUCCION"
drush vset myapi_onesignal_rest_api_key "REST_API_KEY_DE_PRODUCCION"
```

- **De dónde salen los valores:** consola de OneSignal → Settings → Keys & IDs.
- **Verificar:** `drush vget myapi_onesignal_app_id` y `drush vget myapi_onesignal_rest_api_key`.
- **Nota iOS:** para que lleguen a iPhone, el proyecto OneSignal debe tener
  configurado **APNs** (Settings → Platforms → Apple iOS). Es config de la consola de
  OneSignal, no del backend.

### 5. Limpiar caché

```bash
drush cc all
```

- **Qué hace:** reconstruye el registro de rutas y hooks. **Obligatorio** tras
  desplegar código nuevo o cambiar `hook_menu()` / archivos `.inc`.

### 6. Configurar el cron dedicado (Opción B)

Programa el comando clave como tarea recurrente del sistema, **bajo el usuario
`www-data`** (así evitas el problema de permisos):

```bash
sudo crontab -u www-data -e
```

Añade esta única línea (verifica antes la ruta real de drush con `which drush`):

```cron
* * * * * /usr/local/bin/drush -r /var/www/html queue-run myapi_onesignal_push >/dev/null 2>&1
```

Desglose de la línea:

| Parte | Significado |
|---|---|
| `* * * * *` | Frecuencia: **cada minuto** (min, hora, día-mes, mes, día-semana). Usa `*/5 * * * *` para cada 5 min. |
| `/usr/local/bin/drush` | Ruta absoluta a drush (la que dé `which drush`). |
| `-r /var/www/html` | Raíz de la instalación de Drupal. |
| `queue-run myapi_onesignal_push` | Procesa solo la cola de push (Drush 9+: `queue:run`). |
| `>/dev/null 2>&1` | Descarta la salida. Para depurar, cámbialo por `>> /var/log/myapi-push.log 2>&1` (el archivo debe ser escribible por `www-data`). |

- **Por qué `www-data`:** es el dueño de los archivos y el usuario del web server;
  puede leer `includes/` y no genera archivos de otro propietario.
- **Es por servidor:** el crontab es config del sistema operativo, **no** viaja con el
  deploy. Repite este paso en cada entorno (pruebas, producción).

> **Sobre el cron general del sitio:** este cron dedicado NO cubre las demás tareas de
> Drupal (limpieza de caché, sesiones, búsqueda, etc.). Si el sitio no tiene ya un
> `drush cron` programado, conviene añadir uno aparte y menos frecuente:
> `0 * * * * /usr/local/bin/drush -r /var/www/html cron >/dev/null 2>&1` (cada hora).

---

## Verificación end-to-end

```bash
# 1) ¿La cola está registrada y cuántos items pendientes tiene?
drush queue-list

# 2) Prueba directa de envío a un uid suscrito (sustituye 76760).
#    Sirve para confirmar credenciales + conectividad con OneSignal sin crear boletín.
drush php-eval '
$key=variable_get("myapi_onesignal_rest_api_key","");
$app=variable_get("myapi_onesignal_app_id","");
$payload=["app_id"=>$app,"include_external_user_ids"=>["76760"],"headings"=>["en"=>"Prueba","es"=>"Prueba"],"contents"=>["en"=>"Cuerpo","es"=>"Cuerpo"]];
$r=drupal_http_request("https://onesignal.com/api/v1/notifications",["method"=>"POST","headers"=>["Content-Type"=>"application/json; charset=utf-8","Authorization"=>"Basic ".$key],"data"=>drupal_json_encode($payload)]);
echo "CODE: ".$r->code."\nBODY: ".$r->data."\n";
'

# 3) Crear un boletín publicado, anotar su nid, y ver a quién se le encoló:
drush sql:query "SELECT uid, is_read FROM myapi_notifications WHERE source_nid = <NID>;"

# 4) Forzar el procesamiento de la cola (lo que hará el cron cada minuto):
sudo -u www-data drush queue-run myapi_onesignal_push

# 5) Revisar el log del módulo:
drush watchdog:show --type=myapi --count=15
```

Interpretación del log (paso 5):

| Mensaje en watchdog | Significado |
|---|---|
| `OneSignal push sent: recipients N, notification id ...` | Enviado correctamente a N dispositivos. |
| `OneSignal accepted the request but reported errors: All included players are not subscribed (recipients: 0 ...)` | OneSignal aceptó, pero ningún destinatario tiene dispositivo suscrito. |
| `OneSignal push failed: HTTP 401/403 ...` | Credenciales incorrectas. |
| `OneSignal push skipped: ... are not set` | Faltan las variables de OneSignal (paso 4). |
| (sin líneas de `myapi` tras el cron) | El cron no corrió, o el worker no cargó (revisar permisos, paso 2). |

---

## Comandos de referencia (operación y diagnóstico)

| Comando | Para qué sirve |
|---|---|
| `drush queue-run myapi_onesignal_push` | **El comando clave.** Envía los pushes pendientes (solo esa cola). |
| `drush queue-list` | Lista las colas registradas y cuántos items pendientes tienen. |
| `drush cron` | Cron general del sitio (todos los módulos + todas las colas). |
| `drush cc all` | Limpia toda la caché de Drupal (tras desplegar código). |
| `drush updb` | Ejecuta actualizaciones de esquema pendientes (crea la tabla). |
| `drush vget myapi_onesignal_app_id` | Muestra el valor de una variable. |
| `drush watchdog:show --type=myapi --count=20` | Muestra los últimos logs del módulo. |
| `sudo crontab -u www-data -l` | Lista las tareas cron del usuario www-data. |
| `which drush` / `drush version` | Ruta y versión de drush (para el crontab). |

---

## Problemas comunes

- **`opendir(... /includes): failed to open dir: Permission denied`** y
  `function 'myapi_onesignal_queue_worker' not found`
  → Permisos: el usuario del cron no puede leer los archivos. Aplica el paso 2 y corre
  el cron como `www-data`.

- **`{"errors":["All included players are not subscribed"]}`** (HTTP 200)
  → El backend envió bien, pero no hay dispositivos suscritos para esos uids. La app
  debe llamar a `OneSignal.login("<uid>")` (mismo uid de Drupal) y el usuario aceptar
  el permiso de notificaciones. Verifica en OneSignal → Audience → Subscriptions.

- **`warnings: ["You must configure iOS notifications ..."]`**
  → Falta configurar **APNs** en el proyecto OneSignal para que lleguen a iPhone.

- **Se crea la notificación en el inbox pero no llega el push**
  → El push es diferido: espera al cron dedicado (hasta ~1 min). Si nunca llega,
  revisa el log del módulo (sección de verificación).

- **El boletín no encoló a nadie** (`SELECT ... WHERE source_nid` vacío)
  → La audiencia del boletín (`field_tipo_de_boletin` × `field_enviar_a`) no alcanzó a
  ningún usuario activo. Revisa el alcance/rol del boletín.
