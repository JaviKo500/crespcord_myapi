# 27 — Notificación de pago aprobado

- **Estado:** Approved
- **Fecha:** 2026-07-16
- **Dependencias:**
  - `20-register-payment` (Implemented) — nodo `pagos`, campos `field_referencia`, `field_valor`, `field_vivienda`.
  - `22-verificar-pago-actualizar-saldos` (Implemented) — `hook_node_presave` / `myapi_payment_apply_verification()` en `includes/myapi.payment_workflow.inc`, que este spec extiende para disparar la notificación tras forzar el pago a `"Completado"`.
  - `23-anular-pago` (Implemented) — constantes de estado compartidas (`MYAPI_PAYMENT_STATUS_*`) en el mismo archivo.
  - `25-notifications-inbox-boletin` / `26-notification-condominium-unit-context` (Implemented) — `myapi_notification_create()`, tabla `myapi_notifications` con `condominium_id`/`unit_id`, que este spec usa como primer trigger real (la plomería que dejó lista spec 26).
  - **Rule legacy `rules_actualizar_saldo_pago`** (activa, no gestionada por este módulo) — reacciona a `ON node_insert--pagos` cuando `field_estado_pago == "Nuevo"` **en el momento de la creación** (no una transición desde `"Pendiente de verificar"`: el nodo ya nace con `"Nuevo"`, típicamente creado desde el administrador de Drupal). Si la vivienda y su condominio son del bundle esperado, descuenta/incrementa saldos igual que `myapi_payment_apply_verification()` y hace `data_set` de `field_estado_pago` a `"Completado"` sobre el nodo ya insertado. Rules autoguarda ese cambio al terminar de ejecutar la regla, lo cual dispara un **segundo `node_save()` (un update)** para el mismo pago, con `$node->original->field_estado_pago === "Nuevo"` y el valor entrante `"Completado"`. Este spec agrega un tercer punto de disparo para detectar exactamente esa transición.
- **Objetivo:** Cuando un pago llega a `field_estado_pago = "Completado"` —por la transición de verificación `"Pendiente de verificar"` → `"Completado"` (spec 22), por creación directa del nodo ya en `"Completado"`, o por la transición `"Nuevo"` → `"Completado"` que produce el autoguardado de la Rule legacy `rules_actualizar_saldo_pago`— notificar vía `myapi_notification_create()` con asunto `"Pago aprobado — Ref. {{ref}}"` y cuerpo `"Tu pago de {{amount}} ha sido aprobado.\nReferencia: {{ref}}\nGracias."`, usando `field_referencia`/`field_valor` (2 decimales) y completando `unit_id`/`condominium_id` cuando se puedan resolver. El destinatario normal es el autor del pago (`node->uid`); cuando ese autor tiene el rol de Drupal `administrator` (el pago se cargó desde el back office en nombre de otra persona), se notifica en cambio al ocupante de la vivienda (`field_vivienda`), con `node->uid` como respaldo si la unidad no tiene ningún ocupante resoluble.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.payment_workflow.inc`** (modificar) — nueva función `myapi_payment_notify_approved($node)`: arma el asunto/cuerpo con `field_referencia`/`field_valor`, resuelve `unit_id`/`condominium_id` desde `field_vivienda` (y `field_condominio` de esa vivienda si existe), resuelve el/los destinatario(s) vía la nueva `myapi_payment_notify_recipients($node, $unit_id)` y llama a `myapi_notification_create()` con `uids = myapi_payment_notify_recipients($node, $unit_id)`. Nueva función `myapi_payment_notify_recipients($node, $unit_id)`: si el autor (`user_load($node->uid)`) tiene el rol `administrator`, resuelve los ocupantes de `$unit_id` vía `myapi_unit_member_uids([$unit_id], 'ocupantes')` (spec 09, `includes/myapi.unit_access.inc`); si esa lista no está vacía la devuelve, si no (o si el autor no es admin, o `$unit_id` es `NULL`) devuelve `[(int) $node->uid]`. También nueva función `myapi_payment_is_rule_completion($node)`: detecta la transición `"Nuevo"` → `"Completado"` (mismo patrón que `myapi_payment_is_verification_transition()`, comparando `$node->original` contra el valor entrante). `myapi_payment_notify_approved()` se invoca desde tres puntos:
  - Al final de `myapi_payment_apply_verification()`, tras forzar `field_estado_pago` a `"Completado"` (caso transición `"Pendiente de verificar"` → `"Nuevo"`, spec 22; `$node->nid` ya existe por ser un update).
  - Desde un nuevo hook para el caso de creación directa ya en `"Completado"` (ver siguiente punto).
  - Desde un nuevo `hook_node_update()` para el caso de la transición `"Nuevo"` → `"Completado"` que produce el autoguardado de la Rule legacy `rules_actualizar_saldo_pago` (ver siguiente punto). Es un punto de observación puro: no repite el trabajo de saldos (la Rule ya lo hizo), solo detecta la transición ya persistida y notifica.
- **`myapi.module`** (modificar) — `myapi_node_insert($node)` gana una rama para `$node->type === 'pagos'`: si `field_estado_pago` es exactamente `"Completado"`, delega en `myapi_payment_notify_approved($node)`. La rama existente de `boletin` no se toca. Nueva implementación de `hook_node_update()`: si `$node->type === 'pagos'` y `myapi_payment_is_rule_completion($node)` es verdadero, delega en `myapi_payment_notify_approved($node)`.
- **`myapi.info`** — sin cambios: `includes/myapi.payment_workflow.inc` ya está listado.
- Nuevas constantes en `includes/myapi.notification.inc`: `type` = `payment_approved`, `source_type` = `payment`, `deep_link_target` = `payment` (apunta a `GET /api/v1/payments/%`, spec 24).

### Fuera de este spec

- **Notificar a propietarios/ocupantes además de quien registró el pago** — decisión: el destinatario es solo `node->uid`; ampliar a todos los miembros de la vivienda queda para otro spec si hace falta.
- **Notificar otras transiciones de estado** (pago anulado, pago rechazado) — este spec solo cubre la llegada a `"Completado"`.
- **Traducir el asunto/cuerpo vía catálogo i18n** — texto fijo en español, no pasa por `myapi_t()` (mismo criterio que el body de boletín).
- **Revertir o corregir la notificación si el pago se anula después de estar `"Completado"`** — no existe ese flujo hoy (spec 23 solo anula desde `"Pendiente de verificar"`); si se agrega, se trata en otro spec.
- **Precondiciones nuevas para la creación directa en `"Completado"`** — si el nodo creado directo no tiene `field_referencia`/`field_valor`/`field_vivienda`, la notificación se arma igual con lo disponible (referencia vacía, monto `"0.00"`, `unit_id`/`condominium_id` en `NULL`); no se bloquea la notificación ni la creación del nodo por campos faltantes.
- **Filtrar por usuario activo** — a diferencia del fan-out de boletín, aquí el destinatario es un único uid conocido (`node->uid`); no se agrega chequeo de `status` activo (si el usuario está bloqueado, `myapi_notification_create()` igual inserta la fila; queda en su bandeja si se reactiva).

---

## Modelo de datos

Este spec **no agrega tablas ni columnas nuevas** (`myapi_notifications` ya tiene `condominium_id`/`unit_id` desde spec 26). Solo agrega constantes y arma el `$params` que ya consume `myapi_notification_create()`.

### Constantes nuevas en `includes/myapi.notification.inc`

```php
define('MYAPI_NOTIFICATION_SOURCE_PAYMENT', 'payment');
define('MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED', 'payment_approved');
define('MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT', 'payment');
```

### `myapi_payment_notify_approved($node)` — en `includes/myapi.payment_workflow.inc`

Arma el `$params` de `myapi_notification_create()`:

| Clave | Valor |
|---|---|
| `source_type` | `MYAPI_NOTIFICATION_SOURCE_PAYMENT` |
| `source_nid` | `(int) $node->nid` |
| `type` | `MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED` |
| `title` | `"Pago aprobado — Ref. " . $reference` |
| `body` | `"Tu pago de " . $amount_formatted . " ha sido aprobado.\nReferencia: " . $reference . "\nGracias."` |
| `deep_link_target` | `MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT` |
| `deep_link_id` | `(int) $node->nid` |
| `condominium_id` | nid del condominio de la vivienda, o `NULL` si no se puede resolver |
| `unit_id` | nid de la vivienda (`field_vivienda`), o `NULL` si no está presente |
| `uids` | `myapi_payment_notify_recipients($node, $unit_id)` |

Donde:
- `$reference = myapi_payment_field_value($node, 'field_referencia')`, o `''` si no hay valor.
- `$amount_formatted = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2)` — `myapi_payment_field_value()` devuelve `NULL` si no hay valor, y `(float) NULL === 0.0`, así que sin monto queda `"0.00"`.
- `$unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id']) ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] : NULL`.
- `$condominium_id`: si `$unit_id` no es `NULL`, `node_load($unit_id)` y leer `field_condominio` de esa vivienda (mismo patrón que `myapi_payment_apply_verification()`); si la vivienda no existe o no tiene condominio, `NULL`. No se valida bundle/publicado — es solo para completar contexto, no una precondición de negocio.

### `myapi_payment_notify_recipients($node, $unit_id)` — en `includes/myapi.payment_workflow.inc`

Resuelve el/los uid(s) destinatario(s) de la notificación:

```php
function myapi_payment_notify_recipients($node, $unit_id) {
  $author = user_load($node->uid);
  if ($author && !empty($author->roles) && in_array('administrator', $author->roles)) {
    if ($unit_id !== NULL) {
      module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
      $occupant_uids = myapi_unit_member_uids([$unit_id], 'ocupantes');
      if (!empty($occupant_uids)) {
        return $occupant_uids;
      }
    }
  }

  return [(int) $node->uid];
}
```

Reglas:
- **Autor sin rol `administrator`** (caso normal: el pago lo registró el propio residente vía la app, spec 20) → destinatario `[(int) $node->uid]`, igual que antes de este cambio.
- **Autor con rol `administrator`** (el pago se cargó desde el back office de Drupal en nombre de otra persona) → destinatario los ocupantes de `$unit_id` (`field_ocupante` legacy + `field_ocupantes` multivalor, vía `myapi_unit_member_uids()`, spec 09/`includes/myapi.unit_access.inc`). Si la unidad no tiene ningún ocupante resoluble, o `$unit_id` es `NULL` (sin `field_vivienda`), **respaldo**: `[(int) $node->uid]` — nunca se pierde la notificación.
- No se filtra por propietarios ni por usuario activo en este camino: mismo criterio "best effort" que el resto del spec.

### `myapi_payment_is_rule_completion($node)` — en `includes/myapi.payment_workflow.inc`

Mismo patrón que `myapi_payment_is_verification_transition()`, pero para el par de valores que produce el autoguardado de la Rule legacy `rules_actualizar_saldo_pago`:

```php
function myapi_payment_is_rule_completion($node) {
  if (!isset($node->original)) {
    return FALSE;
  }

  $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
  $incoming = myapi_payment_field_value($node, 'field_estado_pago');

  return $previous === MYAPI_PAYMENT_STATUS_TRIGGER
    && $incoming === MYAPI_PAYMENT_STATUS_COMPLETED;
}
```

`MYAPI_PAYMENT_STATUS_TRIGGER` ya es la constante `'Nuevo'` (usada por `myapi_payment_is_verification_transition()`); no se agrega una constante nueva. Un nodo insertado directo en `"Nuevo"` no tiene `$node->original`, así que esta función solo puede dar `TRUE` en el update que dispara la Rule (o cualquier otro update manual que lleve un pago de `"Nuevo"` a `"Completado"`; el spec no distingue el origen del update, solo la transición de valores).

### Ejemplo de mensaje generado

Con `field_referencia = "000123"` y `field_valor = 45.9`:

```json
{
  "title": "Pago aprobado — Ref. 000123",
  "body": "Tu pago de 45.90 ha sido aprobado.\nReferencia: 000123\nGracias."
}
```

### Puntos de invocación

| Caso | Dónde | Detalle |
|---|---|---|
| Transición `"Pendiente de verificar"` → `"Completado"` | Al final de `myapi_payment_apply_verification()` (después de forzar el estado y antes/después de cancelar tareas `rules_scheduler`, sin depender de ese orden) | `$node->nid` ya existe (es un update). Solo se llega aquí si pasaron las precondiciones de spec 22 (vivienda/condominio/monto válidos). |
| Creación directa con `field_estado_pago = "Completado"` | `hook_node_insert()` en `myapi.module`, rama nueva para `pagos` | `$node->nid` recién asignado por `node_save()`. Sin precondiciones adicionales: se notifica con lo que el nodo tenga. |
| Transición `"Nuevo"` → `"Completado"` (autoguardado de la Rule legacy `rules_actualizar_saldo_pago` sobre un pago creado directo en `"Nuevo"`) | `hook_node_update()` nuevo en `myapi.module`, rama para `pagos` que llama a `myapi_payment_is_rule_completion($node)` | Punto de observación puro: la Rule ya hizo el trabajo de saldos y ya persistió `"Completado"` antes de que este hook corra; este código solo detecta la transición y notifica, sin repetir ni validar precondiciones de saldo. |

---

## Plan de implementación

1. **Constantes en `includes/myapi.notification.inc`** — agregar, junto a las constantes existentes (`MYAPI_NOTIFICATION_SOURCE_BOLETIN`, etc.):
   ```php
   define('MYAPI_NOTIFICATION_SOURCE_PAYMENT', 'payment');
   define('MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED', 'payment_approved');
   define('MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT', 'payment');
   ```
   Tras esto, `drush cc all`. Estado funcional: sin cambio de comportamiento, solo constantes nuevas.

2. **`myapi_payment_notify_approved($node)`** en `includes/myapi.payment_workflow.inc`:
   ```php
   function myapi_payment_notify_approved($node) {
     module_load_include('inc', 'myapi', 'includes/myapi.notification');

     $reference = myapi_payment_field_value($node, 'field_referencia');
     $reference = $reference !== NULL ? $reference : '';
     $amount = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2);

     $unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
       ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id']
       : NULL;

     $condominium_id = NULL;
     if ($unit_id !== NULL) {
       $vivienda = node_load($unit_id);
       if ($vivienda && isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
         $condominium_id = (int) $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
       }
     }

     myapi_notification_create([
       'source_type'      => MYAPI_NOTIFICATION_SOURCE_PAYMENT,
       'source_nid'       => (int) $node->nid,
       'type'             => MYAPI_NOTIFICATION_TYPE_PAYMENT_APPROVED,
       'title'            => 'Pago aprobado — Ref. ' . $reference,
       'body'             => 'Tu pago de ' . $amount . " ha sido aprobado.\nReferencia: " . $reference . "\nGracias.",
       'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT,
       'deep_link_id'     => (int) $node->nid,
       'condominium_id'   => $condominium_id,
       'unit_id'          => $unit_id,
       'uids'             => myapi_payment_notify_recipients($node, $unit_id),
     ]);
   }
   ```
   *Verificación: llamar la función a mano (o vía debug) sobre un nodo `pagos` de prueba inserta una fila en `myapi_notifications` con el `title`/`body` esperados.*

3. **`myapi_payment_notify_recipients($node, $unit_id)`** en `includes/myapi.payment_workflow.inc` (junto a `myapi_payment_notify_approved()`):
   ```php
   function myapi_payment_notify_recipients($node, $unit_id) {
     $author = user_load($node->uid);
     if ($author && !empty($author->roles) && in_array('administrator', $author->roles)) {
       if ($unit_id !== NULL) {
         module_load_include('inc', 'myapi', 'includes/myapi.unit_access');
         $occupant_uids = myapi_unit_member_uids([$unit_id], 'ocupantes');
         if (!empty($occupant_uids)) {
           return $occupant_uids;
         }
       }
     }

     return [(int) $node->uid];
   }
   ```
   *Verificación: llamar la función a mano con un `$node` cuyo `uid` tenga el rol `administrator` y una `$unit_id` con ocupante(s) cargado(s) → debe devolver los uids de esos ocupantes, no el uid admin. Con una `$unit_id` sin ocupantes, o `$unit_id = NULL`, o un `$node->uid` sin el rol `administrator` → debe devolver `[(int) $node->uid]`.*

4. **Enganchar el caso de transición** — en `myapi_payment_apply_verification()` (mismo archivo), agregar la llamada al final, después de forzar `field_estado_pago` a `MYAPI_PAYMENT_STATUS_COMPLETED` y de `myapi_payment_cancel_scheduled_tasks()`:
   ```php
   myapi_payment_notify_approved($node);
   ```
   *Verificación: repetir la prueba manual de spec 22 (crear pago, editarlo de `"Pendiente de verificar"` a `"Nuevo"`) y confirmar que, además de mover saldos, aparece una notificación nueva para el destinatario correcto (autor, u ocupante si el autor es admin) con el `deep_link` apuntando al pago.*

5. **Enganchar el caso de creación directa** — en `myapi.module`, ampliar `myapi_node_insert()`:
   ```php
   function myapi_node_insert($node) {
     if ($node->type === 'pagos') {
       module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');
       if (myapi_payment_field_value($node, 'field_estado_pago') === MYAPI_PAYMENT_STATUS_COMPLETED) {
         myapi_payment_notify_approved($node);
       }
       return;
     }
     if ($node->type !== 'boletin' || $node->status != 1) {
       return;
     }
     module_load_include('inc', 'myapi', 'includes/myapi.notification');
     myapi_notification_create_from_boletin($node);
   }
   ```
   Tras esto, `drush cc all`. *Verificación: crear un nodo `pagos` directo con `field_estado_pago = "Completado"` (vía admin de Drupal o `node_save()` de prueba) y confirmar que se inserta la notificación —al ocupante de la vivienda si el `uid` autor tiene el rol `administrator`, o al propio autor si no—; crear uno en `"Pendiente de verificar"` y confirmar que NO se inserta nada.*

6. **`myapi_payment_is_rule_completion($node)`** en `includes/myapi.payment_workflow.inc` (mismo archivo, junto a `myapi_payment_is_verification_transition()`):
   ```php
   function myapi_payment_is_rule_completion($node) {
     if (!isset($node->original)) {
       return FALSE;
     }

     $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
     $incoming = myapi_payment_field_value($node, 'field_estado_pago');

     return $previous === MYAPI_PAYMENT_STATUS_TRIGGER
       && $incoming === MYAPI_PAYMENT_STATUS_COMPLETED;
   }
   ```

7. **Enganchar el caso de la Rule legacy** — en `myapi.module`, nueva implementación de `hook_node_update()`:
   ```php
   function myapi_node_update($node) {
     if ($node->type !== 'pagos') {
       return;
     }
     module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');
     if (myapi_payment_is_rule_completion($node)) {
       myapi_payment_notify_approved($node);
     }
   }
   ```
   Tras esto, `drush cc all`. *Verificación: crear un nodo `pagos` directo con `field_estado_pago = "Nuevo"` referenciando una vivienda/condominio válidos (mismas condiciones que exige la Rule), autorado por un usuario con rol `administrator` (el caso real: creado desde el back office) y confirmar que, tras el autoguardado de `rules_actualizar_saldo_pago`, el pago queda en `"Completado"` y aparece una única notificación nueva para el/los ocupante(s) de la vivienda, no para el admin. Editar después ese mismo pago (ya `"Completado"`) sin tocar el estado y confirmar que NO se genera una notificación adicional.*

8. **Documentar en `docs/payment-workflow.md`** (creado por spec 22) — agregar una sección "Notificación al aprobar" describiendo los tres disparadores (transición de verificación, creación directa a `"Completado"`, y transición `"Nuevo"` → `"Completado"` vía la Rule legacy), la resolución de destinatario (autor, u ocupante de la vivienda si el autor es `administrator`), el `title`/`body` generado y que `unit_id`/`condominium_id` pueden quedar en `NULL` si no se resuelven.

9. **Aplicar y verificar** — `drush cc all`. Probar los tres caminos de disparo (transición vía verificación, creación directa, y creación directa en `"Nuevo"` completada por la Rule legacy), cada uno tanto con autor sin rol `administrator` (destinatario = autor) como con autor `administrator` (destinatario = ocupante de la unidad), y confirmar en BD (`myapi_notifications`) y vía `GET /api/v1/notifications` (spec 25) que la fila aparece con `type = "payment_approved"`, `title`/`body` correctos, `deep_link.target = "payment"`, `deep_link.id` = nid del pago, y el `uid` correcto según quién la creó.

---

## Criterios de aceptación

**Disparo correcto**
- [x] Un pago que transita de `"Pendiente de verificar"` a `"Nuevo"` (y queda forzado a `"Completado"` por spec 22) genera una fila nueva en `myapi_notifications` para el destinatario correcto (ver criterios de "Destinatario" abajo).
- [x] Un nodo `pagos` creado directamente con `field_estado_pago = "Completado"` genera una fila nueva en `myapi_notifications` para el destinatario correcto.
- [x] Un nodo `pagos` creado directo con `field_estado_pago = "Nuevo"` (vivienda/condominio válidos) que la Rule legacy `rules_actualizar_saldo_pago` completa a `"Completado"` genera una única fila nueva en `myapi_notifications` para el destinatario correcto.
- [x] El `title` de la notificación es exactamente `"Pago aprobado — Ref. {reference}"` con la referencia real del pago.
- [x] El `body` de la notificación es exactamente `"Tu pago de {amount} ha sido aprobado.\nReferencia: {reference}\nGracias."`, con `{amount}` formateado a 2 decimales (ej. `"45.90"`).
- [x] `type` = `"payment_approved"`, `source_type` = `"payment"`, `source_nid` = nid del pago, `deep_link.target` = `"payment"`, `deep_link.id` = nid del pago.
- [x] `unit_id`/`condominium_id` de la fila quedan poblados con el nid de la vivienda y del condominio cuando se pueden resolver.

**Destinatario (autor vs. ocupante)**
- [x] Si el `uid` autor del pago **no** tiene el rol `administrator`, el destinatario es ese mismo `uid` (comportamiento original, sin cambios).
- [x] Si el `uid` autor del pago **tiene** el rol `administrator` y la vivienda (`field_vivienda`) tiene al menos un ocupante resoluble (`field_ocupante`/`field_ocupantes`), el destinatario es el/los ocupante(s) de la vivienda, no el admin.
- [x] Si el `uid` autor tiene el rol `administrator` pero la vivienda no tiene ningún ocupante resoluble, o el pago no tiene `field_vivienda`, el destinatario cae de vuelta al `uid` autor (nunca se pierde la notificación).

**No dispara / idempotencia**
- [x] Un pago creado con `field_estado_pago = "Pendiente de verificar"` (flujo normal de spec 20) **no** genera notificación.
- [x] Editar un pago ya `"Completado"` (cambiando otros campos, sin tocar el estado) **no** genera una notificación nueva.
- [x] Un pago cuyas precondiciones de spec 22 fallan (vivienda inválida, monto ≤ 0) y por lo tanto **no** llega a `"Completado"` **no** genera notificación.
- [x] Anular un pago (`"Pendiente de verificar"` → `"Anulado"`, spec 23) **no** genera notificación.
- [x] Un nodo `pagos` creado directo en `"Nuevo"` cuyas condiciones de la Rule legacy fallan (vivienda/condominio de bundle inválido) y por lo tanto queda en `"Nuevo"` (la Rule nunca lo completa) **no** genera notificación.

**Campos faltantes en creación directa**
- [x] Un nodo `pagos` creado directo en `"Completado"` sin `field_referencia` genera la notificación con referencia vacía (`"Pago aprobado — Ref. "`), sin error.
- [x] Un nodo `pagos` creado directo en `"Completado"` sin `field_valor` genera la notificación con `"Tu pago de 0.00 ha sido aprobado..."`, sin error.
- [x] Un nodo `pagos` creado directo en `"Completado"` sin `field_vivienda` genera la notificación igual, con `unit_id`/`condominium_id` en `NULL`.

**No regresión / infra**
- [x] Las notificaciones de boletín (spec 25/26) siguen funcionando idénticas; `myapi_node_insert()` sigue notificando boletines publicados sin cambios de comportamiento.
- [x] El flujo de saldos de spec 22 (descuento en vivienda, incremento en caja del condominio, cancelación de `rules_scheduler`) sigue funcionando idéntico.
- [x] `GET /api/v1/notifications` (spec 25) devuelve la notificación de pago aprobado con `deep_link.unit`/`deep_link.condominium` pobladas cuando corresponde (spec 26).
- [x] `drush cc all` no reporta errores tras los cambios.
- [x] `docs/payment-workflow.md` documenta el nuevo comportamiento de notificación.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Destinatario | `node->uid` (autor del pago) por defecto; si el autor tiene el rol `administrator`, el/los ocupante(s) de la vivienda (`myapi_unit_member_uids($unit_id, 'ocupantes')`), con `node->uid` como respaldo si no hay ninguno resoluble | (1) Siempre solo `node->uid`, sin importar quién lo creó; (2) siempre resolver el ocupante de la unidad, ignorando `node->uid`; (3) detectar "creado por admin" comparando si `node->uid` no es miembro de la unidad, en vez de por rol | Elección del usuario: coincide con el texto del pedido ("tu pago") cuando lo registra el propio residente. Pero cuando el pago se carga desde el back office de Drupal, `node->uid` es el admin, no el residente — ese caso se detectó durante la implementación (ver Riesgos) y el usuario decidió resolverlo por **rol de Drupal** (`administrator`) del autor, notificando solo a **ocupantes** (no propietarios) de la unidad, con `node->uid` de respaldo si la unidad no tiene ningún ocupante cargado. |
| Formato de `{{amount}}` | `number_format($valor, 2)` sin símbolo de moneda | Valor crudo del campo / con símbolo `$` | Elección del usuario: consistente con cómo se expone `amount` en el resto de la API (float), sin asumir una moneda que el modelo no define. |
| Idioma del texto | Fijo en español, sin pasar por `myapi_t()` | Traducir vía catálogo i18n según el idioma del destinatario | Elección del usuario: mismo criterio que el `body` de boletín (contenido tal cual, no traducido); además no hay un `Accept-Language` de request disponible dentro de un hook de `node_save`. |
| Puntos de disparo | Tres: fin de `myapi_payment_apply_verification()` (transición de verificación) + rama nueva en `hook_node_insert()` (creación directa ya `"Completado"`) + `hook_node_update()` nuevo (transición `"Nuevo"` → `"Completado"` producida por el autoguardado de la Rule legacy) | Un único punto centralizado (p. ej. solo `hook_node_update`/`hook_node_insert` genérico revisando `field_estado_pago`) | El caso de transición de verificación ya tiene `$node->nid` disponible dentro de la misma función que aplica el cambio de estado (evita repetir la comparación `original` vs `incoming`); el caso de creación directa necesita `hook_node_insert` porque el `nid` no existe todavía en `hook_node_presave`; el caso de la Rule legacy necesita `hook_node_update` porque es un segundo `node_save()` (update) disparado por Rules después del insert original, fuera del control de este módulo. |
| Alcance del disparador | Los tres casos exactos descritos: transición `"Pendiente de verificar"` → `"Completado"` (spec 22), creación directa en `"Completado"`, o transición `"Nuevo"` → `"Completado"` (autoguardado de `rules_actualizar_saldo_pago`) | Cualquier transición hacia `"Completado"` desde cualquier estado anterior | Elección del usuario: "solo cuando pasa de pendiente verificación o si se crea y se pasa directo a Completado" + el caso real descubierto durante la implementación (creación desde el administrador en `"Nuevo"`, completada por la Rule legacy todavía activa). Otras transiciones futuras (si aparecieran) se evalúan en su propio spec. |
| Precondiciones para creación directa | Ninguna: se notifica con lo que el nodo tenga, referencia/monto ausentes se muestran vacíos/0.00 | Bloquear la notificación si faltan `field_referencia`/`field_valor`/`field_vivienda` | Ese camino ya no pasa por las precondiciones de spec 22 (que son propias de la transición de verificación); no se quiere silenciar una notificación de un pago real solo porque le falta un campo secundario. |
| Filtro de usuario activo | No se agrega | Verificar `status` del usuario antes de notificar (como hace el fan-out de boletín) | El destinatario es un único uid ya conocido y específico (quien registró el pago), no un fan-out; `myapi_notification_create()` ya trata sin destinatarios como no-op, y un usuario bloqueado luego reactivado igual encuentra la notificación en su bandeja. |
| `condominium_id` sin resolver | `NULL`, sin abortar la notificación | Abortar si no se puede resolver el condominio | Mismo criterio "best effort" que dejó spec 26: son columnas de contexto nullable, no una precondición de negocio para notificar. |
| Ubicación del código | `includes/myapi.payment_workflow.inc` (nueva función) + `myapi.module` (glue en `hook_node_insert`) | Un archivo nuevo dedicado a "payment notifications" | Reutiliza el archivo que ya concentra la lógica de estado de pagos (spec 22/23); evita fragmentar la lógica de `pagos` en un tercer archivo para un solo hook adicional. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Cambio de forma en `hook_node_insert()`.** Se reestructura la función para agregar la rama de `pagos` antes de la de `boletin`; un error de anidamiento podría romper el fan-out de boletín existente. | La rama de `pagos` termina en `return` explícito, dejando la rama de `boletin` intacta y con la misma guarda (`type !== 'boletin' || status != 1`) que ya tenía. Se verifica explícitamente en el criterio de no regresión (creación de boletín sigue notificando igual). |
| **Doble notificación por doble guardado.** Si `myapi_payment_apply_verification()` se re-ejecutara sobre el mismo pago (no debería, spec 22 ya lo protege con la comparación contra `$node->original`), se insertaría una notificación por cada ejecución. | Mismo riesgo residual que spec 22 documenta para el descuento de saldo (condición de carrera de dos guardados casi simultáneos); no se agrega deduplicación extra en este spec. |
| **Creación directa en `"Completado"` sin `field_vivienda`/`field_referencia`/`field_valor`.** Un nodo armado a mano (fuera del formulario normal) podría no tener esos campos. | Contemplado explícitamente en el modelo de datos: referencia vacía, monto `"0.00"`, `unit_id`/`condominium_id` en `NULL`, sin abortar ni lanzar error. |
| **Fuente de `node->uid` en creación directa.** Si un admin crea el nodo a nombre de otro usuario, `node->uid` es el admin, no quien realmente "hizo" el pago. | Mitigado por `myapi_payment_notify_recipients()`: cuando el autor tiene el rol `administrator` se resuelve el ocupante de la vivienda en su lugar (ver Decisiones). Si el admin no tiene ese rol exacto, o la vivienda no tiene ocupante cargado, la notificación sigue llegando al admin como respaldo — no se intenta adivinar el pagador real por ningún otro medio. |
| **Detección de "admin" por rol de Drupal.** Depende de que el rol se llame exactamente `administrator` y de que los usuarios administrativos reales tengan ese rol asignado. Un admin sin ese rol exacto (p. ej. un rol custom) se trata como residente normal y la notificación le llega a él. | Comportamiento aceptado explícitamente por el usuario (ver Decisiones); si el sitio usa otro nombre de rol, es un ajuste de una sola constante en `myapi_payment_notify_recipients()`, no un cambio de diseño. |
| **`user_load()` en cada notificación.** Cada llamada a `myapi_payment_notify_recipients()` hace un `user_load()` del autor (y, si aplica, un `myapi_unit_member_uids()` con sus propias queries). Costo aceptable: se ejecuta una vez por transición de pago, no en un listado ni en un loop masivo. | Sin mitigación adicional; mismo orden de magnitud que los `node_load()` ya existentes en `myapi_payment_notify_approved()` y `myapi_payment_apply_verification()`. |
| **Acoplamiento entre `myapi.payment_workflow.inc` y `myapi.notification.inc`.** La nueva función carga un include pensado para notificaciones desde el archivo de flujo de pagos. | Mismo patrón ya aceptado en el módulo (`payment.resource.inc` carga `myapi.payment_workflow.inc` en spec 23); es reutilización explícita vía `module_load_include()`, no lógica duplicada. |
| **Dependencia de una Rule externa a este módulo.** El tercer disparador depende de que la Rule `rules_actualizar_saldo_pago` siga activa, configurada exactamente como hoy (mismo `IF`/`DO`) y con el "auto save" de Rules resaveando el nodo tras el `data_set` de `field_estado_pago`. Si alguien deshabilita o modifica esa Rule (por ejemplo, para migrarla a código como ya se hizo con la transición de verificación en spec 22), este disparador deja de firmar sin que este spec lo note. | Se documenta explícitamente la dependencia (ver Dependencias) y la detección se hace por el **valor final persistido** (`"Nuevo"` → `"Completado"`), no por lógica interna de Rules: cualquier otro mecanismo futuro que produzca ese mismo update seguiría disparando la notificación igual. |
| **Doble disparo si además de la Rule alguien reintrodujera una transición `"Pendiente de verificar"` → `"Nuevo"` sobre el mismo pago ya completado por la Rule.** No debería ocurrir (un pago ya en `"Completado"` no vuelve a `"Pendiente de verificar"` en el flujo normal), pero si ocurriera, ambos disparadores son independientes y cada uno notificaría una vez por su propia transición. | Riesgo aceptado, mismo criterio que el resto de la tabla: no se agrega deduplicación adicional a nivel de `myapi_notification_create()` en este spec. |

---

## Lo que **no** entra en este spec

- Notificar a propietarios/ocupantes además de quien registró el pago.
- Notificar otras transiciones de estado (anulación, rechazo).
- Traducir el asunto/cuerpo vía catálogo i18n.
- Revertir o corregir la notificación si el pago se anula después de estar `"Completado"`.
- Filtrar por usuario activo antes de notificar.

Cada una, si aparece, va en su propio spec.
