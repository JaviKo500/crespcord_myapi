# 27 — Notificación de pago aprobado

- **Estado:** Approved
- **Fecha:** 2026-07-16
- **Dependencias:**
  - `20-register-payment` (Implemented) — nodo `pagos`, campos `field_referencia`, `field_valor`, `field_vivienda`.
  - `22-verificar-pago-actualizar-saldos` (Implemented) — `hook_node_presave` / `myapi_payment_apply_verification()` en `includes/myapi.payment_workflow.inc`, que este spec extiende para disparar la notificación tras forzar el pago a `"Completado"`.
  - `23-anular-pago` (Implemented) — constantes de estado compartidas (`MYAPI_PAYMENT_STATUS_*`) en el mismo archivo.
  - `25-notifications-inbox-boletin` / `26-notification-condominium-unit-context` (Implemented) — `myapi_notification_create()`, tabla `myapi_notifications` con `condominium_id`/`unit_id`, que este spec usa como primer trigger real (la plomería que dejó lista spec 26).
- **Objetivo:** Cuando un pago llega a `field_estado_pago = "Completado"` —por la transición de verificación `"Pendiente de verificar"` → `"Completado"` (spec 22) o por creación directa del nodo ya en `"Completado"`— notificar al autor del pago (`node->uid`) vía `myapi_notification_create()` con asunto `"Pago aprobado — Ref. {{ref}}"` y cuerpo `"Tu pago de {{amount}} ha sido aprobado.\nReferencia: {{ref}}\nGracias."`, usando `field_referencia`/`field_valor` (2 decimales) y completando `unit_id`/`condominium_id` cuando se puedan resolver.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.payment_workflow.inc`** (modificar) — nueva función `myapi_payment_notify_approved($node)`: arma el asunto/cuerpo con `field_referencia`/`field_valor`, resuelve `unit_id`/`condominium_id` desde `field_vivienda` (y `field_condominio` de esa vivienda si existe) y llama a `myapi_notification_create()` con `uids = [(int) $node->uid]`. Se invoca desde dos puntos:
  - Al final de `myapi_payment_apply_verification()`, tras forzar `field_estado_pago` a `"Completado"` (caso transición, `$node->nid` ya existe por ser un update).
  - Desde un nuevo hook para el caso de creación directa (ver siguiente punto).
- **`myapi.module`** (modificar) — `myapi_node_insert($node)` gana una rama para `$node->type === 'pagos'`: si `field_estado_pago` es exactamente `"Completado"`, delega en `myapi_payment_notify_approved($node)`. La rama existente de `boletin` no se toca.
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
| `uids` | `[(int) $node->uid]` |

Donde:
- `$reference = myapi_payment_field_value($node, 'field_referencia')`, o `''` si no hay valor.
- `$amount_formatted = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2)` — `myapi_payment_field_value()` devuelve `NULL` si no hay valor, y `(float) NULL === 0.0`, así que sin monto queda `"0.00"`.
- `$unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id']) ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] : NULL`.
- `$condominium_id`: si `$unit_id` no es `NULL`, `node_load($unit_id)` y leer `field_condominio` de esa vivienda (mismo patrón que `myapi_payment_apply_verification()`); si la vivienda no existe o no tiene condominio, `NULL`. No se valida bundle/publicado — es solo para completar contexto, no una precondición de negocio.

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
       'uids'             => [(int) $node->uid],
     ]);
   }
   ```
   *Verificación: llamar la función a mano (o vía debug) sobre un nodo `pagos` de prueba inserta una fila en `myapi_notifications` con el `title`/`body` esperados.*

3. **Enganchar el caso de transición** — en `myapi_payment_apply_verification()` (mismo archivo), agregar la llamada al final, después de forzar `field_estado_pago` a `MYAPI_PAYMENT_STATUS_COMPLETED` y de `myapi_payment_cancel_scheduled_tasks()`:
   ```php
   myapi_payment_notify_approved($node);
   ```
   *Verificación: repetir la prueba manual de spec 22 (crear pago, editarlo de `"Pendiente de verificar"` a `"Nuevo"`) y confirmar que, además de mover saldos, aparece una notificación nueva para el `uid` autor del pago con el `deep_link` apuntando al pago.*

4. **Enganchar el caso de creación directa** — en `myapi.module`, ampliar `myapi_node_insert()`:
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
   Tras esto, `drush cc all`. *Verificación: crear un nodo `pagos` directo con `field_estado_pago = "Completado"` (vía admin de Drupal o `node_save()` de prueba) y confirmar que se inserta la notificación; crear uno en `"Pendiente de verificar"` y confirmar que NO se inserta nada.*

5. **Documentar en `docs/payment-workflow.md`** (creado por spec 22) — agregar una sección "Notificación al aprobar" describiendo el disparador (transición o creación directa a `"Completado"`), el destinatario (`node->uid`), el `title`/`body` generado y que `unit_id`/`condominium_id` pueden quedar en `NULL` si no se resuelven.

6. **Aplicar y verificar** — `drush cc all`. Probar los dos caminos de disparo (transición vía verificación y creación directa) y confirmar en BD (`myapi_notifications`) y vía `GET /api/v1/notifications` (spec 25) que la fila aparece con `type = "payment_approved"`, `title`/`body` correctos y `deep_link.target = "payment"`, `deep_link.id` = nid del pago.

---

## Criterios de aceptación

**Disparo correcto**
- [ ] Un pago que transita de `"Pendiente de verificar"` a `"Nuevo"` (y queda forzado a `"Completado"` por spec 22) genera una fila nueva en `myapi_notifications` para el `uid` autor del pago.
- [ ] Un nodo `pagos` creado directamente con `field_estado_pago = "Completado"` genera una fila nueva en `myapi_notifications` para el `uid` autor del pago.
- [ ] El `title` de la notificación es exactamente `"Pago aprobado — Ref. {reference}"` con la referencia real del pago.
- [ ] El `body` de la notificación es exactamente `"Tu pago de {amount} ha sido aprobado.\nReferencia: {reference}\nGracias."`, con `{amount}` formateado a 2 decimales (ej. `"45.90"`).
- [ ] `type` = `"payment_approved"`, `source_type` = `"payment"`, `source_nid` = nid del pago, `deep_link.target` = `"payment"`, `deep_link.id` = nid del pago.
- [ ] `unit_id`/`condominium_id` de la fila quedan poblados con el nid de la vivienda y del condominio cuando se pueden resolver.

**No dispara / idempotencia**
- [ ] Un pago creado con `field_estado_pago = "Pendiente de verificar"` (flujo normal de spec 20) **no** genera notificación.
- [ ] Editar un pago ya `"Completado"` (cambiando otros campos, sin tocar el estado) **no** genera una notificación nueva.
- [ ] Un pago cuyas precondiciones de spec 22 fallan (vivienda inválida, monto ≤ 0) y por lo tanto **no** llega a `"Completado"` **no** genera notificación.
- [ ] Anular un pago (`"Pendiente de verificar"` → `"Anulado"`, spec 23) **no** genera notificación.

**Campos faltantes en creación directa**
- [ ] Un nodo `pagos` creado directo en `"Completado"` sin `field_referencia` genera la notificación con referencia vacía (`"Pago aprobado — Ref. "`), sin error.
- [ ] Un nodo `pagos` creado directo en `"Completado"` sin `field_valor` genera la notificación con `"Tu pago de 0.00 ha sido aprobado..."`, sin error.
- [ ] Un nodo `pagos` creado directo en `"Completado"` sin `field_vivienda` genera la notificación igual, con `unit_id`/`condominium_id` en `NULL`.

**No regresión / infra**
- [ ] Las notificaciones de boletín (spec 25/26) siguen funcionando idénticas; `myapi_node_insert()` sigue notificando boletines publicados sin cambios de comportamiento.
- [ ] El flujo de saldos de spec 22 (descuento en vivienda, incremento en caja del condominio, cancelación de `rules_scheduler`) sigue funcionando idéntico.
- [ ] `GET /api/v1/notifications` (spec 25) devuelve la notificación de pago aprobado con `deep_link.unit`/`deep_link.condominium` pobladas cuando corresponde (spec 26).
- [ ] `drush cc all` no reporta errores tras los cambios.
- [ ] `docs/payment-workflow.md` documenta el nuevo comportamiento de notificación.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Destinatario | Solo `node->uid` (autor del pago) | Todos los propietarios/ocupantes de la vivienda | Elección del usuario: coincide con el texto del pedido ("tu pago"), dirigido a quien lo registró, no a un fan-out de la vivienda. |
| Formato de `{{amount}}` | `number_format($valor, 2)` sin símbolo de moneda | Valor crudo del campo / con símbolo `$` | Elección del usuario: consistente con cómo se expone `amount` en el resto de la API (float), sin asumir una moneda que el modelo no define. |
| Idioma del texto | Fijo en español, sin pasar por `myapi_t()` | Traducir vía catálogo i18n según el idioma del destinatario | Elección del usuario: mismo criterio que el `body` de boletín (contenido tal cual, no traducido); además no hay un `Accept-Language` de request disponible dentro de un hook de `node_save`. |
| Puntos de disparo | Dos: fin de `myapi_payment_apply_verification()` (transición) + rama nueva en `hook_node_insert()` (creación directa) | Un único punto centralizado (p. ej. solo `hook_node_update`/`hook_node_insert` genérico revisando `field_estado_pago`) | El caso de transición ya tiene `$node->nid` disponible dentro de la misma función que aplica el cambio de estado (evita repetir la comparación `original` vs `incoming`); el caso de creación directa necesita `hook_node_insert` porque el `nid` no existe todavía en `hook_node_presave`. |
| Alcance del disparador | Solo los dos casos exactos descritos (transición `"Pendiente de verificar"` → `"Completado"`, o creación directa en `"Completado"`) | Cualquier transición hacia `"Completado"` desde cualquier estado anterior | Elección del usuario: "solo cuando pasa de pendiente verificación o si se crea y se pasa directo a Completado". Otras transiciones futuras (si aparecieran) se evalúan en su propio spec. |
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
| **Fuente de `node->uid` en creación directa.** Si un admin crea el nodo a nombre de otro usuario (`node->uid` distinto de quien realmente "hizo" el pago), la notificación llega a ese uid, no a un tercero. | Comportamiento esperado: `node->uid` es la única fuente de "destinatario" definida en este spec (ver Decisiones); no hay otro campo que identifique al pagador. |
| **Acoplamiento entre `myapi.payment_workflow.inc` y `myapi.notification.inc`.** La nueva función carga un include pensado para notificaciones desde el archivo de flujo de pagos. | Mismo patrón ya aceptado en el módulo (`payment.resource.inc` carga `myapi.payment_workflow.inc` en spec 23); es reutilización explícita vía `module_load_include()`, no lógica duplicada. |

---

## Lo que **no** entra en este spec

- Notificar a propietarios/ocupantes además de quien registró el pago.
- Notificar otras transiciones de estado (anulación, rechazo).
- Traducir el asunto/cuerpo vía catálogo i18n.
- Revertir o corregir la notificación si el pago se anula después de estar `"Completado"`.
- Filtrar por usuario activo antes de notificar.

Cada una, si aparece, va en su propio spec.
