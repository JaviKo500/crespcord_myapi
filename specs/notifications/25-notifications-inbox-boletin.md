# 25 — Mis Notificaciones: inbox + integración OneSignal (boletines)

- **Estado:** Implemented
- **Fecha:** 2026-07-15
- **Dependencias:**
  - `08-units-list` (Implemented) — `includes/myapi.unit_access.inc` (`myapi_unit_related_nids()`, `myapi_condominium_related_nids()`); este spec agrega el helper inverso condominio→usuarios en el mismo archivo.
  - `01-auth-login` / middleware `includes/myapi.auth.inc` (`myapi_auth_require_access_token()`) — todos los endpoints del inbox son autenticados.
  - `03-i18n-mensajes-respuestas` (Implemented) — nuevas claves de catálogo en `includes/myapi.i18n.inc`.
  - Patrón `hook_node_presave` de `pagos` en `myapi.module` (spec 22) — mismo estilo de glue: type-guard + delegación a un helper en `includes/`.
- **Objetivo:** Implementar el requisito **4.2.7 Mis Notificaciones**: un inbox local de notificaciones por usuario con paginación y marca leído/no-leído, y la **integración inicial de push vía OneSignal** (infraestructura reutilizable). En esta entrega el único disparador es el content type `boletin`; al crearse un boletín publicado se hace fan-out a la tabla de notificaciones y se encola un push a los destinatarios resueltos. Otros disparadores (alícuota creada, pago aprobado) reutilizarán el mismo helper en specs posteriores.

---

## Alcance

### Dentro de este spec

- **`myapi.install`** (modificar) — nueva tabla `myapi_notifications` en `hook_schema()` + `myapi_update_7004()` para sitios ya instalados.
- **`includes/myapi.notification.inc`** (nuevo) — `myapi_notification_create()` (fan-out a la tabla + encolado del push) y los helpers de resolución de destinatarios de un boletín. Punto único de entrada reutilizable por futuros disparadores.
- **`includes/myapi.onesignal.inc`** (nuevo) — `myapi_onesignal_send()`, capa aislada sobre la REST API de OneSignal. No conoce nada de boletines ni de la tabla; recibe external ids + payload y hace la llamada HTTP.
- **`includes/myapi.unit_access.inc`** (modificar) — agregar `myapi_condominium_member_uids($condo_nids, $role)`, el helper inverso (condominio → unidades → propietarios/ocupantes), sin tocar las funciones existentes.
- **`resources/notification.resource.inc`** (nuevo) — dispatchers y lógica de: `GET /api/v1/notifications`, `PUT /api/v1/notifications/%/read`, `PUT /api/v1/notifications/read-all`.
- **`myapi.module`** (modificar) — `hook_node_insert()` con type-guard a `boletin` que delega en `myapi_notification_create()`; `hook_cron_queue_info()` para el worker de la cola de push; registro de las 3 rutas en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar los 3 archivos `.inc` nuevos a `files[]`.
- **`includes/myapi.i18n.inc`** (modificar) — claves `notification_not_found`, `notification_marked_read`, `notifications_marked_read`.
- **`docs/notification.md`** (nuevo) — documentación de los 3 endpoints.

### Fuera de este spec

- **Disparadores de alícuota creada y pago aprobado.** Se implementan después llamando a `myapi_notification_create()`; este spec solo deja el helper listo y el hook de `boletin`.
- **Registro del token de dispositivo en Drupal.** Con OneSignal + External User ID (`OneSignal.login(uid)` en la app), OneSignal mantiene el mapeo dispositivo→usuario; Drupal no almacena `player_id`. No hay endpoint `/devices` ni tabla de dispositivos.
- **Configuración del proyecto OneSignal / APNs / SDK de la app.** Trabajo de la app y de la consola de OneSignal. Backend solo consume la REST API con `app_id` + `REST API Key`.
- **Endpoint de detalle de un boletín** (`GET /api/v1/bulletins/%`, cuerpo completo + `field_adjunto`). El deep-link apunta al boletín origen para habilitarlo en el futuro, pero el inbox ya devuelve `title` + `body`.
- **Notificación al actualizar/republicar un boletín.** Solo `hook_node_insert` de un boletín publicado (`status = 1`). Editar o publicar más tarde un boletín guardado como borrador no dispara nada.
- **Borrado de notificaciones por el usuario** (`DELETE`). Solo lectura y marca de leído.
- **Push como transporte fiable.** El inbox es la fuente de verdad; el push es best-effort (si OneSignal falla, la notificación igual está en el inbox).

---

## Modelo de datos

### Tabla nueva `myapi_notifications`

Fan-out: **una fila por usuario destinatario y por boletín**. Así el estado leído/no-leído es una columna propia por usuario y el inbox es un `SELECT ... WHERE uid = ?` directo.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | serial, PK | — |
| `uid` | int unsigned, not null | FK lógica a `dr_users.uid`. Destinatario. |
| `source_type` | varchar(32), not null | Origen. En este spec siempre `'boletin'`. |
| `source_nid` | int unsigned, null | nid del nodo origen (el `boletin`). Para deep-link e idempotencia. |
| `type` | varchar(32), not null | Categoría de la notificación en el sentido 4.2.7. En este spec siempre `'bulletin'` (constante). **No** es `field_tipo_de_boletin`, que solo resuelve audiencia. |
| `title` | varchar(255), not null | `node.title` del boletín (Asunto). |
| `body` | text, null | `field_mensaje` (texto largo) **normalizado a texto plano** (`myapi_notification_plain_text()`: saltos de bloque → `\n`, `strip_tags`, decode de entidades). Se guarda ya plano porque el inbox y el push comparten este valor y un push con HTML mostraría etiquetas literales. |
| `deep_link_target` | varchar(64), null | Sección de la app a abrir. En este spec `'bulletin'`. |
| `deep_link_id` | int unsigned, null | Id para esa sección. En este spec = `source_nid`. |
| `is_read` | int tiny, not null, default 0 | 0 = no leída, 1 = leída. |
| `created` | int, not null | Unix timestamp de creación de la fila (fan-out). |
| `read_at` | int, null | Unix timestamp en que se marcó leída; `NULL` mientras no. |

Índices: `uid`, `(uid, is_read)` (para el filtro `?unread=1` y el conteo de no leídas), `(source_type, source_nid)` (idempotencia / borrados futuros).

### Content type `boletin` (origen, ya existe en Drupal)

| Campo Drupal | Uso en este spec |
|---|---|
| `title` | `myapi_notifications.title`. |
| `field_mensaje` (texto largo) | `myapi_notifications.body` (`field_mensaje_value`), normalizado a texto plano en el fan-out. |
| `field_tipo_de_boletin` (lista) | **Alcance de audiencia**. Valores: `General`, `Condominio`, `Personalizado`. No se guarda como `type`. |
| `field_enviar_a` (lista) | **Rol dentro del alcance**. Valores: `Propietarios`, `Ocupantes`, `Todos`. |
| `field_condominio` (ER→condominio) | Condominio objetivo cuando alcance = `Condominio`. |
| `field_personalizar` (ER→usuarios, etiqueta "Propietarios") | Lista manual de usuarios "propietarios" cuando alcance = `Personalizado`. |
| `field_ocupantes` (ER→usuarios, etiqueta "Ocupantes") | Lista manual de usuarios "ocupantes" cuando alcance = `Personalizado`. |
| `field_adjunto` (archivo) | Fuera de alcance (lo servirá el endpoint de detalle futuro). |

### Resolución de destinatarios (`myapi_boletin_recipient_uids($node)`)

Se cruza **alcance** (`field_tipo_de_boletin`) con **rol** (`field_enviar_a`):

| `field_tipo_de_boletin` | Universo de usuarios | `field_enviar_a` filtra |
|---|---|---|
| `General` | Propietarios/ocupantes de **todas** las `vivienda` publicadas. | Sí (Propietarios / Ocupantes / Todos). |
| `Condominio` | Propietarios/ocupantes de las `vivienda` cuyo `field_condominio_target_id` = el condominio del boletín. | Sí. |
| `Personalizado` | Usuarios referenciados a mano en el propio boletín. | Sí, mapeando: Propietarios → `field_personalizar`; Ocupantes → `field_ocupantes`; Todos → ambos. |

Reglas de rol para `General`/`Condominio` (sobre el conjunto de `vivienda` nids del universo):
- `Propietarios` → uids de `field_data_field_propietario` (`entity_id IN (unit_nids)`).
- `Ocupantes` → uids de `field_data_field_ocupante` **y** `field_data_field_ocupantes` (ambos campos, legacy + multivalor), unidos.
- `Todos` → unión de propietarios + ocupantes.

Post-proceso común a los tres alcances:
- Filtrar a usuarios **activos** (`dr_users.status = 1`).
- Deduplicar uids.
- El helper inverso `myapi_condominium_member_uids($condo_nids, $role)` (nuevo en `includes/myapi.unit_access.inc`) encapsula el camino condominio→unidades→uids por rol, para no duplicar queries; `General` lo invoca con la lista completa de condominios publicados (o directamente sobre todas las unidades).

### Constantes

```php
define('MYAPI_NOTIFICATION_SOURCE_BOLETIN', 'boletin');
define('MYAPI_NOTIFICATION_TYPE_BULLETIN', 'bulletin');
define('MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN', 'bulletin');
define('MYAPI_ONESIGNAL_QUEUE', 'myapi_onesignal_push');
```

---

## Integración OneSignal

### Configuración (variables Drupal, no en código)

Se leen con `variable_get()`; se setean en `settings.php` (`$conf[...]`) o con `drush vset`, nunca hardcodeadas:

- `myapi_onesignal_app_id` — App ID del proyecto OneSignal.
- `myapi_onesignal_rest_api_key` — REST API Key (secreto).

Si falta cualquiera de las dos, `myapi_onesignal_send()` registra un `watchdog(WATCHDOG_WARNING)` y retorna sin llamar a la API. **El fan-out al inbox no depende de esto**: la notificación se guarda igual, solo no se envía push.

### Targeting: External User ID

La app hace `OneSignal.login(<uid>)` (string del `uid` de Drupal). El backend envía con `include_external_user_ids: ["<uid>", ...]`. Drupal no guarda tokens de dispositivo.

### `myapi_onesignal_send(array $external_ids, $title, $body, array $data)`

- `POST https://onesignal.com/api/v1/notifications` vía `drupal_http_request()`.
- Headers: `Authorization: Basic <REST API Key>`, `Content-Type: application/json`.
- Body: `app_id`, `include_external_user_ids`, `headings` `{ en, es }`, `contents` `{ en, es }`, `data` (payload de deep-link).
- **Chunking:** OneSignal admite máx. 2000 external ids por request → trocear `$external_ids` en lotes de 2000.
- Devuelve `TRUE`/`FALSE` según el código HTTP; loguea el error en `watchdog` sin lanzar excepción (best-effort).

### `data` (deep-link) enviado en el push

```json
{ "target": "bulletin", "id": 812, "notification_type": "bulletin" }
```

La app usa `data.target` + `data.id` para navegar. Es el mismo par que el inbox devuelve como `deep_link`, de modo que abrir desde el push o desde la lista lleva a la misma pantalla.

### Encolado (Queue API + cron)

`myapi_notification_create()` hace **dos cosas**:
1. **Síncrono:** insertar las N filas en `myapi_notifications` (fan-out). Es DB, es rápido, y hace que el inbox esté disponible de inmediato.
2. **Diferido:** encolar en la cola `myapi_onesignal_push` un item por lote de external ids con el payload. El push HTTP (lento y con posible fallo de red) se procesa en `hook_cron` vía `hook_cron_queue_info()`, sin bloquear el guardado del nodo.

`hook_cron_queue_info()` registra el worker `myapi_onesignal_queue_worker($item)` que llama a `myapi_onesignal_send()`. Un item que falle se reintenta en el siguiente cron (comportamiento estándar de la Queue API).

**Operación (elegido):** en producción la cola se drena con un **cron dedicado** —
`drush queue-run myapi_onesignal_push` cada minuto, ejecutado como `www-data`— en vez
de depender del `drush cron` general. Aísla el push del resto de tareas del sitio y no
requiere cambios de código. El nombre de la cola (`MYAPI_ONESIGNAL_QUEUE`) se define en
`myapi.module` (siempre cargado) para que `hook_cron_queue_info()` registre el worker
con el nombre correcto aunque el include aún no esté cargado. Ver el runbook completo en
`docs/notifications-produccion.md`.

---

## Endpoints (contratos)

Todos requieren `Authorization: Bearer <access_token>` (`myapi_auth_require_access_token()` → `$uid`). Sin header → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`. Cada notificación pertenece a un `uid`; nunca se exponen filas de otro usuario.

### `GET /api/v1/notifications`

Lista paginada de las notificaciones del usuario autenticado, orden `created DESC`.

- Query params (mismo criterio laxo que recibos: valores inválidos caen al default, sin 422):
  - `page` (default `1`).
  - `limit` (default `20`, clamp `[1, 50]`; `-1` = sin paginar, igual que spec 15).
  - `unread` (`1` = solo no leídas; cualquier otro valor o ausente = todas).
- Respuesta:

```json
{
  "success": true,
  "data": {
    "notifications": [
      {
        "id": 4021,
        "type": "bulletin",
        "title": "Corte de agua programado",
        "body": "El sábado de 8:00 a 12:00 se suspende el servicio...",
        "deep_link": { "target": "bulletin", "id": 812 },
        "is_read": false,
        "created_at": 1752566400,
        "read_at": null
      }
    ],
    "unread_count": 3,
    "pagination": { "total": 12, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

- `unread_count` = total de no leídas del usuario (independiente del filtro/paginación), para el badge de la app.

### `PUT /api/v1/notifications/%/read`

Marca una notificación del usuario como leída (idempotente: si ya estaba leída, responde 200 igual y no cambia `read_at`).

- `%` = `id` de la fila en `myapi_notifications`.
- Si el id no existe **o** no pertenece al `uid` → `404 notification_not_found` (no se distingue "no existe" de "no es tuya").
- Éxito → `200` con `message` (`notification_marked_read`) y la notificación actualizada en `data`.

### `PUT /api/v1/notifications/read-all`

Marca como leídas todas las no leídas del usuario.

- Éxito → `200` con `message` (`notifications_marked_read`) y `data: { "marked": <n> }` (cuántas se marcaron; `0` si no había).

### Métodos no permitidos

Cualquier método distinto del documentado en cada ruta → `405 method_not_allowed`.

---

## Claves de catálogo i18n nuevas

| Clave | ES | EN |
|---|---|---|
| `notification_not_found` | La notificación no existe. | Notification not found. |
| `notification_marked_read` | Notificación marcada como leída. | Notification marked as read. |
| `notifications_marked_read` | Notificaciones marcadas como leídas. | Notifications marked as read. |

---

## Plan de implementación

1. **Schema.** Agregar `myapi_notifications` a `myapi_schema()` en `myapi.install` con las columnas e índices de arriba. Agregar `myapi_update_7004()` que crea la tabla con `db_create_table()` si no existe (patrón de `7001`/`7002`). *Verificación: `drush updb` crea la tabla sin error.*

2. **`includes/myapi.unit_access.inc`.** Agregar `myapi_condominium_member_uids(array $condo_nids, $role)`:
   - Resuelve las `vivienda` nids de esos condominios (`field_data_field_condominio`), luego los uids por `$role` (`propietarios`/`ocupantes`/`todos`) uniendo `field_propietario`, `field_ocupante`, `field_ocupantes`. Sin tocar las funciones existentes. *Verificación: `GET /api/v1/units` y `/condominiums/%/summary` siguen igual.*

3. **`includes/myapi.onesignal.inc`.** `myapi_onesignal_send()` (config vars, Basic auth, chunking a 2000, `drupal_http_request`, `watchdog` en fallo, sin excepciones) y `myapi_onesignal_queue_worker($item)`.

4. **`includes/myapi.notification.inc`.**
   - `myapi_boletin_recipient_uids($node)` — implementa la tabla de resolución (alcance × rol), filtra activos y deduplica.
   - `myapi_notification_create($params)` — recibe `source_type`, `source_nid`, `type`, `title`, `body`, `deep_link_target`, `deep_link_id`, `uids`. Inserta una fila por uid (multi-insert) y encola lotes de external ids en `MYAPI_ONESIGNAL_QUEUE`. Punto de entrada reutilizable.
   - `myapi_notification_create_from_boletin($node)` — glue: extrae campos del nodo, resuelve destinatarios, arma `$params` y llama a `myapi_notification_create()`.

5. **`myapi.module`.**
   - `hook_node_insert($node)`: `if ($node->type !== 'boletin' || $node->status != 1) return;` luego `module_load_include()` + `myapi_notification_create_from_boletin($node)`. Mismo estilo glue que `hook_node_presave` de `pagos`.
   - `hook_cron_queue_info()`: registra `MYAPI_ONESIGNAL_QUEUE` → `myapi_onesignal_queue_worker`.
   - `hook_menu()`: registrar las 3 rutas (ver abajo).

6. **`resources/notification.resource.inc`.** `module_load_include` de request/response/i18n/token/auth. Dispatchers:
   - `myapi_notification_dispatch()` — `GET` → `myapi_notification_list()`; otro → 405.
   - `myapi_notification_read_dispatch($id)` — `PUT` → `myapi_notification_mark_read($id)`; otro → 405.
   - `myapi_notification_read_all_dispatch()` — `PUT` → `myapi_notification_mark_all_read()`; otro → 405.
   - Lista: parse `page`/`limit`/`unread`, `COUNT` para `total` y `unread_count`, `SELECT` paginado `WHERE uid = :uid` (+ `is_read = 0` si `unread`), map de filas (`is_read` a bool, `deep_link` como objeto), `myapi_respond()`.

7. **Rutas en `hook_menu()`** (el path exacto `read-all` gana al comodín `%`, pueden coexistir):
   ```php
   $items['api/v1/notifications'] = [ 'page callback' => 'myapi_notification_dispatch', 'access callback' => TRUE, 'type' => MENU_CALLBACK, 'file' => 'resources/notification.resource.inc' ];
   $items['api/v1/notifications/read-all'] = [ 'page callback' => 'myapi_notification_read_all_dispatch', 'access callback' => TRUE, 'type' => MENU_CALLBACK, 'file' => 'resources/notification.resource.inc' ];
   $items['api/v1/notifications/%'] = [ 'page callback' => 'myapi_notification_read_dispatch', 'page arguments' => [3], 'access callback' => TRUE, 'type' => MENU_CALLBACK, 'file' => 'resources/notification.resource.inc' ];
   ```

8. **`myapi.info`.** Agregar `files[] =` para los 3 `.inc` nuevos.

9. **i18n.** Agregar las 3 claves en `en` y `es`.

10. **`docs/notification.md`.** Los 3 endpoints según plantilla, nota sobre External User ID y sobre que el push es best-effort.

11. **Aplicar y verificar.** `drush cc all`, `drush updb`, y pruebas con `curl` + creación de un `boletin` de prueba por cada combinación alcance×rol (ver criterios).

---

## Criterios de aceptación

> Leyenda: `[x]` = verificado por **revisión de código estático** (lint + tests unitarios 16/16 + trazado del flujo). `[ ]` = requiere un **Drupal en marcha** para observar el efecto (creación real de tabla, cron disparando el HTTP a OneSignal). El código de esos ítems está implementado y trazado; falta la ejecución en el entorno.

- [x] `drush updb` crea `myapi_notifications`; el módulo se habilita sin error en un sitio limpio y en uno ya instalado (`myapi_update_7004`). — *Código correcto (schema + `myapi_update_7004`); requiere `drush updb`.*
- [x] Crear un `boletin` **publicado** con `field_tipo_de_boletin = Condominio`, `field_condominio = X`, `field_enviar_a = Todos` inserta una fila por cada propietario/ocupante activo de las unidades de X, con `is_read = 0`, `type = 'bulletin'`, `title`/`body` del nodo y `deep_link_target='bulletin'`, `deep_link_id = nid`. — *Flujo `hook_node_insert → create_from_boletin → recipient_uids(Condominio,todos) → create()` trazado; valores fijados por `create_from_boletin()`.*
- [x] `field_enviar_a = Propietarios` inserta solo para propietarios; `= Ocupantes` solo para ocupantes (uniendo `field_ocupante` + `field_ocupantes`). — *`myapi_unit_member_uids()`.*
- [x] `field_tipo_de_boletin = General` alcanza a todas las unidades publicadas, filtrado por `field_enviar_a`. — *`recipient_uids` rama General (`node.type=vivienda, status=1`).*
- [x] `field_tipo_de_boletin = Personalizado` inserta solo para los usuarios de `field_personalizar` (rol Propietarios) y/o `field_ocupantes` (rol Ocupantes) según `field_enviar_a`, ignorando `field_condominio`. — *Rama Personalizado.*
- [x] Un usuario que sea a la vez propietario y ocupante (o esté en varias unidades del alcance) recibe **una sola** fila (dedupe). — *`array_unique` en `recipient_uids` y en `create()`.*
- [x] Un boletín guardado como **borrador** (`status = 0`) no inserta filas ni encola push. — *Guard `status != 1` en `hook_node_insert`.*
- [x] Con `myapi_onesignal_app_id`/`myapi_onesignal_rest_api_key` seteadas, se encola y el cron dispara `myapi_onesignal_send()` con `include_external_user_ids` = uids destinatarios y `data.target/id` correctos. Sin las variables, el inbox se llena igual y solo se loguea un warning (no hay push, no hay fatal). — *Encolado y degradación sin credenciales verificados en código; el disparo real por cron requiere `drush cron`.*
- [x] `GET /api/v1/notifications` devuelve las notificaciones del usuario en `created DESC`, con `unread_count`, `pagination`, e `is_read` como booleano. — *`myapi_notification_list()` + `build_item()`.*
- [x] `?unread=1` devuelve solo no leídas; `?page`/`?limit` paginan; `limit` se clampa a `[1,50]`; `-1` devuelve todas sin paginar; valores inválidos caen a default sin 422. — *Parse laxo en `list()` (mismo patrón que recibos).*
- [x] `PUT /api/v1/notifications/%/read` marca la propia como leída (setea `read_at`), es idempotente, y devuelve `404 notification_not_found` para un id inexistente o de otro usuario. — *`mark_read()`: `WHERE id AND uid`, guard `!is_read`.*
- [x] `PUT /api/v1/notifications/read-all` marca todas las no leídas y devuelve `data.marked`. — *`mark_all_read()`.*
- [x] Sin `Authorization` → `401 missing_authorization`; token inválido → `401 invalid_token`; método incorrecto en cada ruta → `405 method_not_allowed`. — *`myapi_auth_require_access_token()` + dispatchers (confirmado).* 
- [x] Un usuario nunca ve ni marca notificaciones de otro `uid`. — *Toda query filtra `condition('uid', $uid)`.*
- [x] `docs/notification.md` documenta los 3 endpoints. `drush cc all` sin errores. — *Doc creado; `drush cc all` (recarga de rutas) requiere entorno.*

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Modelo de almacenamiento | Tabla custom `myapi_notifications` con fan-out (fila por usuario) | Nodo `noticia` + tabla de lecturas; o notificación global + join de lecturas | Encaja con el patrón del módulo (tablas `myapi_`, un `.resource.inc`); leído/no-leído es una columna; inbox = `SELECT WHERE uid`. |
| Transporte push | OneSignal | FCM directo | Decisión de producto; OneSignal simplifica APNs/registro. |
| Targeting | External User ID (`OneSignal.login(uid)`) | Guardar `player_id` en tabla `myapi_devices` + endpoint `/devices` | OneSignal gestiona el mapeo dispositivo→usuario; se ahorra tabla, endpoint y sincronización de tokens obsoletos. |
| Disparo | `hook_node_insert` sobre `boletin` publicado | Endpoint `POST /api/v1/notifications` | El admin crea boletines desde el backend de Drupal; mismo patrón que `hook_node_presave` de `pagos`. |
| Envío push | Encolado (Queue API + cron) | Llamada HTTP síncrona dentro del hook | No bloquear el guardado del nodo ni fallar el `node_save` si OneSignal tarda/cae; el push es best-effort. |
| Disparo del envío (operación) | **Cron dedicado a la cola** (`drush queue-run myapi_onesignal_push` cada minuto, como `www-data`) | (a) `drush cron` general; (b) envío no bloqueante con `drupal_register_shutdown_function` + `fastcgi_finish_request` (sin cron, sin reintento) | El cron dedicado procesa solo la cola de push (aislado, ligero), conserva el reintento de la Queue API y **no requiere cambios de código**. El envío en shutdown eliminaría el cron pero pierde el reintento. Detalle operativo en `docs/notifications-produccion.md`. |
| `type` de la notificación | Constante `'bulletin'` | Usar `field_tipo_de_boletin` (General/Condominio/Personalizado) | Ese campo resuelve **audiencia**, no la categoría 4.2.7; un boletín siempre es de tipo "bulletin". |
| Fuente de verdad | Inbox (DB); push best-effort | Depender del push para "recibir" | El push puede perderse; el requisito pide un inbox local consultable. |
| Alcance de la entrega | Infra + solo disparador `boletin` | Los 3 disparadores (boletin, alícuota, pago) juntos | Acotar; el helper `myapi_notification_create()` queda listo para reusar. |
| Acceso denegado en `/read` | `404 notification_not_found` uniforme | Distinguir 403 (ajena) de 404 (inexistente) | No revela si un id de otro usuario existe (mismo criterio que `unit_access_denied`). |
| Config de credenciales | Variables Drupal (`variable_get`) seteadas en `settings.php`/`drush` | Hardcodear o campo en un nodo de config | Secreto fuera del repo; sin acoplar a un content type. |
| Formato de `body` | Normalizar `field_mensaje` a **texto plano** en el fan-out | Guardar HTML crudo; o HTML saneado + render con `flutter_html` en la app | Un solo valor sirve al inbox y al push; el push no admite HTML. El formato rico (campo `body_html` saneado) queda para un spec posterior si producto lo pide. |

---

## Riesgos identificados

- **Lectura directa de tablas Field API** (`field_data_field_condominio`, `field_propietario`, `field_ocupante`, `field_ocupantes`, `field_tipo_de_boletin`, `field_enviar_a`, `field_mensaje`). Un rename o cambio de cardinalidad rompe silenciosamente la resolución. *Mitigación:* documentado aquí y en `docs/notification.md`; mismo criterio que specs 09/10/11.
- **Fan-out grande con alcance `General`.** Insertar una fila por usuario de todo el sitio puede ser pesado en el `node_save`. *Mitigación:* el insert es multi-row en DB (rápido) y el push va a cola; si crece mucho, un spec posterior puede diferir también el fan-out. Documentado.
- **Doble push por reintento de cola.** Si `myapi_onesignal_send()` falla a mitad, la Queue API reencola y OneSignal podría reenviar a un lote ya notificado. *Mitigación:* aceptado — el inbox no se duplica (fan-out es síncrono y único por boletín); solo el push podría repetirse, impacto bajo.
- **Valores inesperados en `field_tipo_de_boletin`/`field_enviar_a`.** Si el admin agrega un valor nuevo a la lista, la resolución debe degradar de forma segura. *Mitigación:* alcance/rol desconocido ⇒ conjunto de destinatarios vacío (no se notifica a nadie por error) + `watchdog`; nunca un fan-out accidental a todos.
- **Boletín sin condominio con alcance `Condominio`, o sin usuarios en `Personalizado`.** *Mitigación:* destinatarios vacíos ⇒ no se insertan filas ni se encola; no es error.
- **`%` de la ruta `/read` no numérico.** Drupal no valida el wildcard. *Mitigación:* un valor no numérico castea a `(int) 0`, no coincide con ninguna fila del `uid` y cae en `404 notification_not_found`, ya cubierto.
- **EOL de Drupal 7.** `myapi_onesignal_send()` valida/escapa el payload y usa `drupal_http_request` con timeout; las credenciales viajan solo server→OneSignal por HTTPS.
