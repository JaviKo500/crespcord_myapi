# 109 — Notificaciones al crear una solicitud de servicio

- **Estado:** Approved
- **Fecha:** 2026-08-28
- **Dependencias:**
  - `90-service-request-create` (Implemented) — dueña de `myapi_service_request_create()` en `resources/service_request.resource.inc`, el punto exacto donde se engancha el disparo. Su alcance dejó las notificaciones explícitamente fuera; este spec cierra esa deuda.
  - `77-services-content-types-install` (Implemented) — bundles `provider` / `service_request`, sus campos (`field_provider_users`, `field_categories`, `field_license_expiry`, `field_category`, `field_desired_start`, `field_condominium`, `field_unit`) y `myapi_services_provider_is_active()`.
  - `78-provider-role` (Implemented) — rol `proveedor`, `MYAPI_PROVIDER_USERS_FIELD` y el mapeo proveedor ↔ cuentas; este spec añade el sentido **inverso** (categoría → proveedores → cuentas), que hoy no existe.
  - `83-providers-list` (Implemented) — `myapi_provider_apply_active_conditions()`, la mitad SQL de «proveedor activo», que este spec reusa tal cual para no reescribir la regla por tercera vez.
  - `87-service-request-direct-status` (Implemented) — el estado `direct` y por qué solo se alcanza al nacer; es lo que separa el caso 1 del caso 2.
  - `25-notifications-inbox-boletin` (Implemented) — tabla `myapi_notifications`, `myapi_notification_create()` y la cola OneSignal (`includes/myapi.onesignal.inc`).
  - `26-notification-condominium-unit-context` (Implemented) — columnas `condominium_id` / `unit_id`; este spec añade `provider_id` junto a ellas con el mismo patrón de `hook_update_N`.
  - `68-claim-notifications` (Implemented) — `myapi_notification_role_uids('backend')` y el patrón «un ítem de cola por destinatario».
  - `80-payment-created-backend-email` (Implemented) — el precedente de forma completo del email al back office: disparo en el endpoint, params ya resueltos y escapados, shell HTML `myapi_mail_reservation_html()`, rama en `myapi_mail()` y alta en `myapi_html_mail_keys()`.
- **Objetivo:** Cuando un residente crea una solicitud de servicio desde `POST /api/v1/service-requests`, avisar a los proveedores por bandeja + push + email — a todos los proveedores activos de la categoría si la solicitud nace `open`, y solo al proveedor adjudicado si nace `direct` — y enviar además un email de detalle al rol `backend`, con el push llevando `audience` y `provider_id` para que la app distinga si es un aviso de residente o de proveedor y con qué proveedor entrar.

---

Tres notas que la cabecera fija:

- **Un aviso por proveedor, no un aviso por lote.** El `provider_id` es un dato de la fila, así que la abierta hace tantas llamadas a `myapi_notification_create()` como proveedores activos tenga la categoría, cada una con las cuentas de ese proveedor.
- **El proveedor no ve la vivienda ni al residente.** Ni el push, ni la fila de bandeja (`unit_id = NULL`), ni el email llevan vivienda, solicitante ni descripción. Ese detalle vive en el endpoint de detalle, que ya decide qué ve cada `viewer`.
- **`audience` es un cambio transversal.** `myapi_notification_create()` empieza a emitir `audience` en el `data` de **todos** los pushes del módulo; los siete triggers existentes pasan a mandar `"resident"` sin cambiar ninguna otra cosa.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.service_request_notification.inc`** (nuevo) — todo el dominio del disparo:
  - Constantes: `MYAPI_NOTIFICATION_SOURCE_SERVICE_REQUEST` (`'service_request'`), `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_OPEN` (`'service_request_open'`), `..._DIRECT` (`'service_request_direct'`), `MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER` (`'service_request_provider'`), `MYAPI_SERVICE_REQUEST_NOTIFY_ROLE` (`'backend'`), claves de correo `MYAPI_SERVICE_REQUEST_PROVIDER_MAIL_KEY` / `..._ADMIN_MAIL_KEY`, y `MYAPI_SERVICE_REQUEST_MAIL_EMPTY` (`'—'`).
  - `myapi_service_request_active_providers_for_category($category_id)` — **la consulta inversa que hoy no existe**: proveedores publicados y con licencia vigente que tienen esa categoría, reusando `myapi_provider_apply_active_conditions()`. Devuelve `[provider_nid => nombre comercial]`.
  - `myapi_service_request_provider_uids($provider_id)` — cuentas activas de `field_provider_users` de un proveedor.
  - `myapi_service_request_notify_created($node, $context)` — la orquestación: decide abierta vs directa, recorre los proveedores, llama a `myapi_notification_create()` una vez por proveedor y encola los emails; después encola el email al `backend`. Best-effort de principio a fin.
  - Constructores puros de texto: `myapi_service_request_push_title($is_direct)`, `myapi_service_request_push_body($title, $category, $condominium, $desired_start)`, `myapi_service_request_provider_mail_params(...)`, `myapi_service_request_admin_mail_params(...)`.
- **`includes/myapi.notification.inc`** (modificar) — `myapi_notification_create()` gana dos cosas y nada más: un parámetro opcional `provider_id` que se persiste en la columna nueva y viaja en el `data` del push, y la clave `audience` en ese mismo `data`, con valor `'resident'` por defecto y `'provider'` cuando el llamador lo pide. Los siete triggers existentes no se tocan: heredan `audience = 'resident'` y `provider_id = NULL`.
- **`myapi.install`** (modificar) — `provider_id` en el `hook_schema()` de `myapi_notifications`; `myapi_update_7036()` que la añade en sitios instalados (mismo patrón idempotente que `myapi_update_7005()`); `myapi_html_mail_keys()` gana `myapi_service_request_provider` y `myapi_service_request_admin`.
- **`resources/service_request.resource.inc`** (modificar) — `myapi_service_request_create()` llama a `myapi_service_request_notify_created()` después de `node_save()` y de los `file_usage_add()`, justo antes de construir el `201`. Ninguna otra función del archivo cambia.
- **`resources/notification.resource.inc`** (modificar) — las dos `db_select()` seleccionan `provider_id` y `myapi_notification_build_item()` lo expone como `deep_link.provider` (entero o `null`).
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_service_request_provider()` / `myapi_mail_service_request_provider_html()` y `myapi_mail_format_service_request_admin()` / `myapi_mail_service_request_admin_html()`, los cuatro reusando el shell `myapi_mail_reservation_html()`: el del proveedor **sin** botón, el del `backend` con botón a `node/{nid}`.
- **`myapi.module`** (modificar) — dos ramas nuevas en `myapi_mail()`: `service_request_provider` y `service_request_admin`.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.service_request_notification.inc`.
- **`docs/service-request-notifications.md`** (nuevo) — el contrato completo de los dos avisos, al estilo de `docs/claim-notifications.md` y `docs/reservation-notifications.md`.
- **`docs/notification.md`** (modificar) — los dos `type` nuevos, la clave `audience` del push, y `deep_link.provider` en el ítem de respuesta.
- **`docs/service-request.md`** (modificar) — nota en `POST /api/v1/service-requests` de que dispara los avisos, con enlace al doc anterior.
- **`tests/unit/ServiceRequestNotificationTest.php`** (nuevo) — títulos y cuerpos de push, params de los dos emails, y la selección de destinatarios (abierta vs directa, proveedor inactivo excluido).

### Fuera de este spec

- **Cualquier otro evento del marketplace.** Oferta creada (spec 100), oferta retirada o actualizada (105), adjudicación (106), cancelación (95), edición (96), cierre y valoración (108): ninguno notifica hoy y ninguno empieza aquí. Este spec cubre **solo la creación**.
- **Avisar al residente de su propia solicitud.** Acaba de crearla y recibe el `201` con el detalle completo.
- **Notificar solicitudes creadas fuera de la API** (back office, drush, importación). El disparo vive en el endpoint, no en `hook_node_insert()` — mismo criterio que pagos (spec 80) y reservas (spec 48).
- **Administradores de edificio en el email de back office.** Solo el rol `backend`, tal como se pidió; ampliar la audiencia como en reclamos es otro spec de una línea.
- **Exponer al proveedor la vivienda, el solicitante, la descripción o los adjuntos** en cualquiera de los tres canales. El detalle sigue siendo del endpoint de detalle.
- **Deduplicar avisos entre proveedores.** Una cuenta que opera dos proveedores activos de la categoría recibe dos filas y dos pushes, uno por proveedor (consecuencia aceptada de que cada aviso lleva su `provider_id`).
- **Backfill de `provider_id`** en las filas ya existentes de `myapi_notifications`: nacen y se quedan en `NULL`.
- **Cambiar el título, el cuerpo o el deep link de los siete triggers existentes.** Solo se les añade `audience: "resident"` en el `data` del push.
- **Traducir los textos vía `myapi_t()`.** Fijos en español, mismo criterio que todos los correos y pushes del módulo.
- **Envío síncrono de push o email.** Ambos salen por cola en el siguiente cron, como el resto.
- **Filtrar por «el proveedor tiene la app instalada»** o por suscripción OneSignal viva. Si la cuenta no tiene dispositivo, el push se pierde y quedan la bandeja y el email.
- **Rate limiting / flood control** sobre cuántos avisos puede generar un residente creando solicitudes.

---

## Modelo de datos

### 1. Columna nueva en `myapi_notifications`

Única modificación de esquema del spec.

| Columna | Tipo | Notas |
|---|---|---|
| `provider_id` | `int`, `unsigned`, `not null => FALSE` | FK lógica al nodo `provider` del que es este aviso. `NULL` en todo aviso que no sea de proveedor — es decir, en los siete triggers existentes y en cualquier fila ya guardada. |

Sin índice: nada filtra por esta columna (la bandeja se consulta siempre por `uid`), y un índice que ningún `WHERE` usa solo cuesta escrituras.

`myapi_update_7036()` la añade con `db_add_field()` bajo `if (!db_field_exists(...))`, calcado de `myapi_update_7005()`. No hay backfill.

### 2. `myapi_notification_create()` — dos claves nuevas en `$params`

| Clave | Tipo | Por defecto | Efecto |
|---|---|---|---|
| `provider_id` | int\|NULL | `NULL` | Se escribe en la columna nueva **y** viaja como `provider` en el `data` del push. |
| `audience` | `'resident'` \| `'provider'` | `'resident'` | Solo viaja en el `data` del push; **no** se persiste (se deduce del `type` y del `provider_id`). |

`data` del push queda así — las dos últimas claves son las nuevas:

```json
{
  "target": "service_request_provider",
  "id": 1420,
  "unit": null,
  "condominium": 87,
  "notification_type": "service_request_open",
  "audience": "provider",
  "provider": 553
}
```

Un push de boletín, alícuota, pago, reclamo o reserva pasa a llevar `"audience": "resident"` y `"provider": null`, sin ningún otro cambio.

`myapi_notification_build_item()` gana una clave dentro de `deep_link`:

```json
"deep_link": { "target": "service_request_provider", "id": 1420, "unit": null, "condominium": 87, "provider": 553 }
```

### 3. Resolución de destinatarios

**Proveedores de una solicitud abierta** — `myapi_service_request_active_providers_for_category($category_id)`:

```
SELECT n.nid, n.title
FROM node n
INNER JOIN field_data_field_categories fc
        ON fc.entity_type = 'node' AND fc.entity_id = n.nid AND fc.deleted = 0
[myapi_provider_apply_active_conditions($query, 'n')]   -- join de licencia + status + vigencia
WHERE n.type = 'provider'
  AND fc.field_categories_tid = :category_id
```

**Proveedor de una solicitud directa:** exactamente `field_assigned_provider`, sin volver a comprobar que esté activo — `myapi_service_request_validate_provider()` (spec 90) ya lo validó dos pasos antes en la misma petición, y repetir la regla aquí sería restablecerla por tercera vez.

**Cuentas de un proveedor** — `myapi_service_request_provider_uids($provider_id)`: `field_data_field_provider_users` filtrado a `users.status = 1` y `uid > 0`. No se exige que la cuenta tenga el rol `proveedor`: la fuente de verdad de quién opera un proveedor es el campo, igual que en spec 78.

**Back office** — `myapi_notification_role_uids('backend')`, sin filtro de condominio.

Cualquiera de los tres conjuntos puede salir vacío: sin proveedores activos en la categoría no se crea ninguna fila ni se encola ningún push (no-op silencioso), y sin nadie en `backend` no sale el email. Ninguno de los dos casos es un error ni afecta al `201`.

### 4. Fila de bandeja / push al proveedor

Una llamada a `myapi_notification_create()` **por proveedor**:

| Clave | Valor |
|---|---|
| `source_type` | `service_request` |
| `source_nid` | nid de la solicitud |
| `type` | `service_request_open` \| `service_request_direct` |
| `title` | ver textos |
| `body` | ver textos |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid de la solicitud |
| `condominium_id` | `field_condominium` |
| `unit_id` | **siempre `NULL`** |
| `provider_id` | nid de este proveedor |
| `audience` | `provider` |
| `uids` | cuentas activas de ese proveedor |

**Textos** (fijos, español):

| Caso | `title` | `body` |
|---|---|---|
| Abierta | `Nueva solicitud de servicio` | `{asunto}`<br>`Categoría: {categoría}`<br>`Condominio: {condominio}`<br>`Inicio: {d/m/Y H:i}` |
| Directa | `Nueva solicitud directa para ti` | *idéntico* |

`{categoría}` = `name` del término de `field_category`; `{condominio}` = `title` del nodo de `field_condominium`; `{inicio}` = `format_date(field_desired_start, 'custom', 'd/m/Y H:i')`. Cualquiera que no se resuelva se imprime como `—`. El cuerpo son cuatro líneas separadas por `\n`; `myapi_onesignal_truncate_body()` ya lo recorta a 200 caracteres para el banner y la bandeja conserva el texto entero.

### 5. Email al proveedor — clave `service_request_provider`

Un ítem de cola **por cuenta** (`myapi_mail_queue_enqueue()`), a `users.mail`.

**Asunto:** `Nueva solicitud de servicio — {asunto}` (abierta) · `Nueva solicitud directa — {asunto}` (directa).

**Cuerpo:** los mismos cuatro datos y nada más.

| Línea | Origen |
|---|---|
| Asunto | `node.title` |
| Categoría | término de `field_category` |
| Fecha de inicio | `field_desired_start`, `d/m/Y H:i` |
| Condominio | `title` de `field_condominium` |

Cierre: `Revisa la solicitud en la app.` **Sin botón** — el proveedor no tiene acceso al back office.

### 6. Email al `backend` — clave `service_request_admin`

Un ítem de cola por destinatario.

**Asunto:** `Nueva solicitud de servicio #{nid} — {condominio}`.

| Línea | Origen |
|---|---|
| Asunto | `node.title` |
| Tipo | `Abierta` · `Directa a {nombre comercial del proveedor}` |
| Categoría | término de `field_category` |
| Fecha de inicio | `field_desired_start`, `d/m/Y H:i` |
| Vivienda | `title` de `field_unit` |
| Condominio | `title` de `field_condominium` |
| Solicitante | `field_nombre` + `field_apellidos` vía `myapi_user_fetch_profile_fields()`, username de respaldo |
| Email del solicitante | `mail` de la cuenta del `field_requester` |
| Descripción | `field_description` |
| Creada el | `node.created`, `d/m/Y H:i` |

**Botón:** `Ver solicitud` → `url('node/{nid}', ['absolute' => TRUE])`.

Todo valor no resoluble se dibuja como `—`, nunca como celda vacía. Los params se encolan **ya resueltos y escapados** (`check_plain()`), porque la cola drena en cron: un condominio renombrado o un proveedor borrado en el medio no cambia ni rompe el mensaje.

### 7. Qué recibe `myapi_service_request_notify_created()`

`myapi_service_request_create()` ya tiene en mano el nodo guardado y los ids validados, así que el disparador no vuelve a cargar nada que ya exista:

```php
myapi_service_request_notify_created($node, [
  'unit_id'              => $unit_id,
  'condominium_id'       => $condominium_id,
  'category_id'          => $category_id,
  'assigned_provider_id' => $assigned_provider_id,  // NULL si es abierta
  'requester_uid'        => $uid,
]);
```

Los nombres (categoría, condominio, vivienda, proveedor, residente) sí se resuelven dentro del include, una vez, y se comparten entre el push, el email al proveedor y el email al `backend`.

---

## Plan de implementación

1. **Esquema y claves de correo — `myapi.install`.** Añadir `provider_id` al `hook_schema()` de `myapi_notifications`; escribir `myapi_update_7036()` con el `if (!db_field_exists(...)) { db_add_field(...) }` calcado de `myapi_update_7005()`; añadir `myapi_service_request_provider` y `myapi_service_request_admin` a `myapi_html_mail_keys()` y reaplicar el mapeo desde el mismo update.
   *Verificación: `drush updb` corre limpio, la columna existe y es `NULL` en todas las filas previas; una segunda pasada del update no falla.*

2. **`includes/myapi.notification.inc` — `provider_id` + `audience`.** `myapi_notification_create()` lee las dos claves nuevas de `$params` con sus valores por defecto, añade `provider_id` a la lista de `fields()` y a cada `values()`, y mete `audience` y `provider` en el array `$data` del push. Nada más de este archivo cambia.
   *Verificación: un boletín publicado sigue creando sus filas idénticas, ahora con `provider_id = NULL`, y su ítem de cola lleva `"audience": "resident"`, `"provider": null`.*

3. **`resources/notification.resource.inc` — exponer el dato.** Añadir `provider_id` a las dos `db_select()->fields()` y `'provider' => ...` dentro de `deep_link` en `myapi_notification_build_item()`.
   *Verificación: `GET /api/v1/notifications` devuelve `deep_link.provider: null` en toda notificación existente, sin romper ninguna clave.*

4. **`includes/myapi.service_request_notification.inc` — constantes y destinatarios.** Crear el archivo con las constantes de la sección de alcance, `myapi_service_request_active_providers_for_category()` (reusando `myapi_provider_apply_active_conditions()`) y `myapi_service_request_provider_uids()`. Añadir `files[] = includes/myapi.service_request_notification.inc` a `myapi.info` y `drush cc all`.
   *Verificación: llamadas a mano desde `drush php-eval` devuelven los proveedores esperados de una categoría, dejando fuera uno despublicado y otro con licencia vencida; y las cuentas activas de un proveedor, dejando fuera una bloqueada.*

5. **Constructores de texto puros — mismo archivo.** `myapi_service_request_push_title($is_direct)`, `myapi_service_request_push_body($title, $category, $condominium, $desired_start)`, `myapi_service_request_provider_mail_params(...)`, `myapi_service_request_admin_mail_params(...)`. Funciones sin base de datos: reciben strings ya resueltos y devuelven strings o arrays.
   *Verificación: cubiertas por los unit tests del paso 9, sin necesidad de sitio.*

6. **Los dos correos — `includes/myapi.mail.inc` y `myapi.module`.** `myapi_mail_format_service_request_provider()` / `myapi_mail_service_request_provider_html()` (sin botón) y `myapi_mail_format_service_request_admin()` / `myapi_mail_service_request_admin_html()` (botón `Ver solicitud` a `node/{nid}`), los cuatro sobre `myapi_mail_reservation_html()`; dos `case` nuevos en `myapi_mail()`.
   *Verificación: `drupal_mail('myapi', 'service_request_admin', ...)` en `drush php-eval` produce el asunto y el HTML esperados.*

7. **La orquestación — `myapi_service_request_notify_created($node, $context)`.** Resuelve los nombres una sola vez; decide abierta vs directa por `assigned_provider_id`; recorre los proveedores llamando a `myapi_notification_create()` (uno por proveedor) y a `myapi_mail_queue_enqueue()` (uno por cuenta); al final encola el email al `backend`. Toda la función es best-effort: no lanza, no revierte nada y no depende de que ninguno de los tres conjuntos tenga elementos.
   *Verificación: invocada a mano sobre una solicitud abierta existente, crea N filas de bandeja (una tanda por proveedor activo de la categoría), N ítems en `myapi_onesignal_push` y los ítems de correo correspondientes en `myapi_mail_send`.*

8. **Enganche en el endpoint — `resources/service_request.resource.inc`.** Llamada a `myapi_service_request_notify_created()` tras los `file_usage_add()` y antes de armar el `201`, precedida de su `module_load_include()`. `drush cc all`.
   *Verificación: `POST /api/v1/service-requests` sigue devolviendo el mismo `201` de spec 90, con las filas y los ítems de cola ya creados.*

9. **`tests/unit/ServiceRequestNotificationTest.php`.** Títulos y cuerpos de push (abierta y directa, y el caso de campos no resolubles → `—`), params de los dos emails, asunto de cada uno, y la selección de destinatarios sobre el fixture de consultas: proveedor inactivo excluido, directa que solo alcanza al asignado, cuenta bloqueada excluida.
   *Verificación: `./vendor/bin/phpunit` en verde, incluida toda la suite previa.*

10. **Documentación.** `docs/service-request-notifications.md` nuevo con el contrato completo; `docs/notification.md` con los dos `type`, `audience` y `deep_link.provider`; nota y enlace en `docs/service-request.md`.
    *Verificación: `docs/notification.md` describe el `data` del push tal como lo emite el código.*

11. **Prueba manual de extremo a extremo.** `drush updb && drush cc all`. Crear una solicitud abierta desde la app con una categoría que tenga dos proveedores activos → cada cuenta ve la notificación en su bandeja con `deep_link.provider` poblado, recibe el push con `"audience": "provider"` y, tras `drush cron`, el correo. Crear una directa → solo el proveedor adjudicado recibe los tres canales. En ambos casos, el `backend` recibe su email con el botón funcionando.

---

## Criterios de aceptación

**Solicitud abierta**
- [ ] Crear una solicitud `open` vía `POST /api/v1/service-requests` genera una tanda de filas en `myapi_notifications` **por cada proveedor activo de la categoría**, con `uid` de cada cuenta de `field_provider_users` de ese proveedor.
- [ ] Cada fila lleva `source_type = "service_request"`, `source_nid` = nid, `type = "service_request_open"`, `deep_link_target = "service_request_provider"`, `deep_link_id` = nid, `condominium_id` = `field_condominium`, `unit_id = NULL` y `provider_id` = el nid de **ese** proveedor.
- [ ] `title = "Nueva solicitud de servicio"` y `body` con las cuatro líneas: asunto, `Categoría:`, `Condominio:`, `Inicio:` en `d/m/Y H:i`.
- [ ] Se encola un ítem en `myapi_onesignal_push` por tanda, cuyo `data` lleva `"audience": "provider"` y `"provider"` con el nid del proveedor.
- [ ] Se encola un ítem en `myapi_mail_send` por cuenta, clave `service_request_provider`, con asunto `Nueva solicitud de servicio — {asunto}`.
- [ ] Un proveedor **despublicado** o con `field_license_expiry` vencida no recibe nada por ninguno de los tres canales.
- [ ] Una cuenta bloqueada (`users.status = 0`) de un proveedor activo no recibe nada.
- [ ] Una cuenta que opera dos proveedores activos de la categoría recibe **dos** filas y **dos** pushes, con `provider_id` distinto en cada uno.
- [ ] Una categoría sin ningún proveedor activo no crea filas ni encola nada, y el `201` sale igual.

**Solicitud directa**
- [ ] Crear una solicitud con `assigned_provider_id` válido notifica **solo** a las cuentas del proveedor adjudicado.
- [ ] Sus filas llevan `type = "service_request_direct"` y `title = "Nueva solicitud directa para ti"`; el resto de columnas igual que en la abierta, con `provider_id` = el proveedor adjudicado.
- [ ] Ningún otro proveedor de la categoría recibe fila, push ni email.
- [ ] El email al proveedor lleva asunto `Nueva solicitud directa — {asunto}`.

**Contenido para el proveedor**
- [ ] Ni el `body` del push, ni la fila de bandeja, ni el email contienen vivienda, nombre del solicitante, su email o la descripción.
- [ ] `unit_id` es `NULL` en toda fila de proveedor, y el `data` del push lleva `"unit": null`.
- [ ] El email al proveedor tiene exactamente cuatro líneas de datos (asunto, categoría, fecha de inicio, condominio), cierre `Revisa la solicitud en la app.` y **ningún botón**.
- [ ] Un campo no resoluble (categoría borrada, condominio sin título) aparece como `—`, tanto en el push como en el email, sin error.

**Email al back office**
- [ ] Se encola un ítem `service_request_admin` por cada usuario **activo** con rol `backend`, y por ninguno más (los administradores de edificio no reciben).
- [ ] Asunto `Nueva solicitud de servicio #{nid} — {condominio}`.
- [ ] El cuerpo dibuja las diez líneas de la sección 6 en ese orden, con `Tipo` = `Abierta` o `Directa a {nombre comercial}` según el caso.
- [ ] El botón `Ver solicitud` apunta a `node/{nid}` en absoluto.
- [ ] Sin nadie en el rol `backend` no se encola nada y el `201` sale igual.

**Esquema y compatibilidad**
- [ ] `drush updb` añade `provider_id` a `myapi_notifications`; ejecutarlo dos veces no falla.
- [ ] Las filas anteriores al update conservan todos sus valores y quedan con `provider_id = NULL` (no hay backfill).
- [ ] `GET /api/v1/notifications` y `GET /api/v1/notifications/%` devuelven `deep_link.provider` (entero o `null`) sin alterar ninguna otra clave de la respuesta.
- [ ] Los siete triggers existentes (boletín, pago aprobado, pago anulado, recibo, alícuota extra, reclamos, reservas) siguen creando filas idénticas, ahora con `provider_id = NULL`, y sus pushes llevan `"audience": "resident"`, `"provider": null`.

**No regresión y robustez**
- [ ] El `201` de `POST /api/v1/service-requests` conserva las diecinueve claves de spec 90, byte por byte.
- [ ] Una solicitud creada desde el back office (formulario de nodo, drush) **no** dispara ningún aviso.
- [ ] Un fallo al encolar (cola caída, dirección inválida) queda en `watchdog` y no impide el `201` ni deshace el nodo.
- [ ] `./vendor/bin/phpunit` en verde, incluida toda la suite previa.
- [ ] `myapi.info` lista el include nuevo y `drush cc all` no reporta errores.
- [ ] Existe `docs/service-request-notifications.md` y `docs/notification.md` documenta `audience`, `deep_link.provider` y los dos `type` nuevos.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Punto de disparo | En `myapi_service_request_create()`, tras `node_save()` y los `file_usage_add()` | `hook_node_insert()` sobre el bundle `service_request` | Elección del usuario. Mismo criterio que pagos (spec 80) y reservas (spec 48): un operador que teclea una solicitud en el back office no necesita que se le avise de ella, y el hook además dispararía en importaciones y en `drush`. |
| Quién es proveedor notificable | Solo proveedores **activos**: publicados y con `field_license_expiry` vigente | Cualquier proveedor publicado con la categoría, activo o no — que es lo que hoy *ve* la lista de solicitudes (spec 87/98) | Elección del usuario. Un proveedor con licencia vencida no puede ofertar; avisarle es ruido. Se acepta la asimetría entre «lo que ve en su listado» y «de lo que se le avisa». |
| Canales para el proveedor | Bandeja + push + email | Solo push + email, sin fila en `myapi_notifications` | Elección del usuario. `myapi_notification_create()` ya hace bandeja y encola el push en una sola llamada; separarlos obligaría a encolar el push a mano y a duplicar la lógica de lotes de OneSignal. |
| Cómo distingue la app | Clave `audience` explícita (`resident` / `provider`) en el `data` del push, **más** `target` y `notification_type` propios | (1) Solo un `notification_type` distinto; (2) solo un `target` distinto | Elección del usuario. Un booleano explícito no obliga a la app a mantener una lista de tipos que crece con cada spec; el `target` sigue sirviendo para la navegación y el `type` para el icono. |
| `audience` se persiste o no | Solo viaja en el push; **no** hay columna | Columna `audience` en `myapi_notifications` | Es derivable de `type` + `provider_id` en la fila; una columna más sería un dato duplicado que puede desincronizarse. En el push sí hace falta porque ahí no hay fila que consultar. |
| Dónde vive el `provider_id` | Columna nueva en `myapi_notifications`, expuesta en `deep_link.provider` y en el `data` del push | Solo en el `data` del push, vía un parámetro suelto sin tocar el esquema | Elección del usuario: la app de proveedor necesita el dato también al abrir la bandeja, no solo al tocar el push. |
| Granularidad de la abierta | Una llamada a `myapi_notification_create()` **por proveedor**, cada una con su `provider_id` | Una sola llamada a todas las cuentas juntas con `provider_id = NULL` | Elección del usuario. Es lo único que hace que el `provider_id` sea un dato real en la abierta; el coste es N ítems de cola en vez de uno, con N = proveedores activos de la categoría. |
| Cuenta con dos proveedores | Recibe dos avisos, uno por proveedor | Deduplicar a uno, con el `provider_id` del primero | Consecuencia coherente de la decisión anterior: si cada aviso es *de un proveedor*, colapsarlos obligaría a elegir arbitrariamente cuál de los dos se pierde. |
| Qué ve el proveedor | Asunto, categoría, fecha de inicio y condominio. Nada más, en los tres canales | Añadir descripción, o vivienda y solicitante | Elección del usuario. La vivienda y la identidad del residente son dato privado mientras el proveedor no esté adjudicado; el endpoint de detalle ya decide qué ve cada `viewer`. Por eso `unit_id` va a `NULL`: el `data` del push arrastra `unit` y un valor poblado la filtraría. |
| Botón en el email al proveedor | Ninguno; cierre `Revisa la solicitud en la app.` | Botón a `node/{nid}` | El proveedor no tiene acceso al back office de Drupal: un botón le llevaría a un *access denied*. |
| Audiencia del email de back office | Solo el rol `backend` | `backend` + administradores de edificio del condominio, como en reclamos (spec 68/70) | Elección del usuario, literal en el pedido. Ampliarla más adelante es añadir `myapi_notification_building_admin_uids()` a una unión, un cambio de una línea. |
| Botón en el email al `backend` | `Ver solicitud` → `node/{nid}` | `node/{nid}/edit`, como el email de pago (spec 80) | A diferencia del pago, aquí el operador no tiene que cambiar ningún estado al recibir el aviso: solo mirar. |
| No se avisa al residente | La creación no genera aviso para quien la creó | Un push o email de confirmación al residente | Acaba de crearla y recibe el `201` con el detalle completo; un aviso inmediato de un acto propio es ruido. |
| Reutilización de «proveedor activo» | Se reusa `myapi_provider_apply_active_conditions()` de spec 83 | Reescribir el `WHERE` de licencia y publicación en la consulta nueva | Regla 3 de `CLAUDE.md`. Esa función ya documenta ser la mitad SQL de la regla y advierte que hay que tocar las dos mitades a la vez; enunciarla por tercera vez garantizaría que se desincronicen. |
| La directa no revalida al proveedor | Se toma `field_assigned_provider` tal cual | Volver a comprobar publicado + licencia antes de notificar | `myapi_service_request_validate_provider()` (spec 90) lo validó dos pasos antes **en la misma petición**; repetirlo sería restablecer la regla por tercera vez sin ninguna ventana en la que pudiera haber cambiado. |
| Ubicación del código | Include propio `includes/myapi.service_request_notification.inc` | Meterlo en `myapi.notification.inc` o en el propio `service_request.resource.inc` | Regla 1 y 3 de `CLAUDE.md`: `myapi.notification.inc` es infraestructura genérica y no debe saber de proveedores; el recurso no debe cargar lógica de correo. Mismo patrón que `myapi.claim_notification.inc` y `myapi.reservation_notification.inc`. |
| Idioma de los textos | Fijos en español, sin `myapi_t()` | Traducir push y email vía el catálogo i18n | Mismo criterio que los otros siete triggers: no hay `Accept-Language` en un cron ni en un `node_save()`, y el destinatario del push no es quien hizo la petición. |
| Entrega | Push y email por cola, en el siguiente cron | Envío síncrono dentro del `POST` | N destinatarios son N viajes de red; hacerlos dentro de la petición añadiría latencia al `201` y un SMTP colgado bloquearía el endpoint. Se acepta que el aviso salga en el siguiente cron. |
| Backfill de `provider_id` | Ninguno: las filas viejas quedan en `NULL` | Rellenar retroactivamente | Ninguna fila anterior es de un proveedor; no hay nada que rellenar. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Volumen de la abierta.** Una categoría popular con 40 proveedores activos y 2 cuentas cada uno produce 40 llamadas a `myapi_notification_create()`, 80 filas, 40 ítems de push y 80 de correo dentro de la misma petición del `POST`. La inserción es síncrona. | El coste real es la escritura de N filas y N ítems de cola, no N viajes de red: el push y el correo salen en cron. Aun así es la parte del spec que más crece con el catálogo; si un día una categoría alcanza cientos de proveedores, el paso natural es mover el fan-out entero a la cola (un ítem «notificar solicitud X» que se expande en cron), sin cambiar ningún contrato de esta especificación. |
| **`audience` toca el `data` de todos los pushes del módulo.** `myapi_notification_create()` es el único punto por el que pasan los siete triggers existentes; un error ahí los rompe todos a la vez. | El cambio es aditivo: dos claves nuevas con valores por defecto, sin tocar las cinco existentes ni el orden de nada. Está cubierto por el criterio de no regresión (un boletín sigue produciendo su fila y su ítem idénticos) y por la suite de tests previa. |
| **Un proveedor que se activa después de la creación no se entera.** La lista de destinatarios se congela en el instante del `POST`: un proveedor que renueva su licencia media hora más tarde ve la solicitud en su listado pero nunca recibió el aviso. | Aceptado. La notificación describe un hecho puntual, no un estado; el listado de solicitudes (spec 98) sigue siendo la fuente de verdad de qué puede ofertar cada proveedor hoy. |
| **Lectura directa de tablas de Field API.** `field_data_field_categories`, `field_data_field_provider_users` y `field_data_field_license_expiry` se consultan por nombre de columna; un rename o un cambio de cardinalidad rompe la resolución **en silencio**: cero destinatarios, ningún error. | Es el mismo compromiso documentado desde specs 09/10/11 y el que ya asume `myapi_provider_role_provider_ids()`. Está acotado a un solo archivo y cubierto por los tests de selección de destinatarios. |
| **Cero destinatarios es indistinguible de un fallo.** Una categoría sin proveedores activos y una consulta rota producen exactamente lo mismo: nada. | Los tres conjuntos vacíos son estados legítimos y así están escritos en los criterios de aceptación. `myapi_mail_queue_enqueue()` ya registra en `watchdog` la dirección inválida; si en producción hiciera falta distinguir los dos casos, un `watchdog` de nivel INFO con el conteo de destinatarios es un añadido posterior de una línea. |
| **La fila de bandeja del proveedor la lee el endpoint del residente.** `GET /api/v1/notifications` filtra por `uid` y no por audiencia: una cuenta que es a la vez residente y proveedor verá los dos tipos mezclados en la misma bandeja. | Es correcto — son notificaciones suyas — y la app puede separarlas por `type` o por `deep_link.provider`. Añadir un filtro `?audience=` al endpoint es otro spec, no un arreglo de este. |
| **Push perdido sin rastro.** Si la cuenta del proveedor nunca abrió la app o no aceptó notificaciones, OneSignal acepta el envío y nadie ve nada. | Es exactamente por lo que el aviso va por tres canales: la bandeja queda persistida y el email sale igual. |
| **El `provider_id` de una directa apunta a un proveedor que después se despublica.** La fila conserva el nid; la app podría abrir una ficha que ya no existe. | La columna es una FK **lógica**, como `condominium_id` y `unit_id` desde spec 26; el endpoint de detalle del proveedor ya responde 404 y la app trata ese caso. Ninguna notificación se borra en cascada, aquí ni en los triggers anteriores. |
