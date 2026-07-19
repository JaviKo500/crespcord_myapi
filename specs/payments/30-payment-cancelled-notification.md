# 30 — Notificación de pago anulado

- **Estado:** Implemented
- **Fecha:** 2026-07-17
- **Dependencias:**
  - `23-anular-pago` (Implemented) — endpoint `PUT /api/v1/payments/%/cancel` que reescribe `field_estado_pago` a `"Anulado"` vía `node_save()`, y constante `MYAPI_PAYMENT_STATUS_CANCELLED` en `includes/myapi.payment_workflow.inc`. Este spec **modifica** ese endpoint para que marque el nodo y **no** dispare notificación (la anulación del propio residente no se notifica a sí mismo).
  - `27-payment-approved-notification` (Implemented) — patrón espejo del que este spec copia: `myapi_payment_notify_recipients()` (reutilizada tal cual), la resolución de `unit_id`/`condominium_id`, las constantes `MYAPI_NOTIFICATION_SOURCE_PAYMENT` / `MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT` (reutilizadas), y el `hook_node_update()` con rama `pagos` que este spec extiende.
  - `25-notifications-inbox-boletin` / `26-notification-condominium-unit-context` (Implemented) — `myapi_notification_create()` y la tabla `myapi_notifications` con `condominium_id`/`unit_id`.
- **Objetivo:** Cuando un pago existente transita a `field_estado_pago = "Anulado"` en un update hecho **desde el back office de Drupal** (desde cualquier estado anterior distinto de `"Anulado"`), notificar al destinatario del pago vía `myapi_notification_create()` con `type = "payment_cancelled"`; las anulaciones vía el endpoint `PUT /api/v1/payments/%/cancel` (spec 23) se marcan para **no** notificar.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.notification.inc`** (modificar) — nueva constante `MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED = 'payment_cancelled'`, junto a las de pago ya existentes. `MYAPI_NOTIFICATION_SOURCE_PAYMENT` y `MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT` se reutilizan sin cambios.

- **`includes/myapi.payment_workflow.inc`** (modificar) — dos funciones nuevas:
  - `myapi_payment_is_cancellation_transition($node)` — devuelve `TRUE` solo si el update no está marcado con la bandera de opt-out, hay `$node->original`, el valor entrante de `field_estado_pago` es `"Anulado"` y el previo es distinto de `"Anulado"`. Mismo patrón que `myapi_payment_is_rule_completion()`.
  - `myapi_payment_notify_cancelled($node)` — arma el `title`/`body` de anulación (monto, motivo si existe, referencia), resuelve `unit_id`/`condominium_id` y llama a `myapi_notification_create()`. **Reutiliza** `myapi_payment_notify_recipients($node, $unit_id)` de spec 27 sin modificarla.

- **`myapi.module`** (modificar) — extender la rama `pagos` del `hook_node_update()` existente (`myapi_node_update()`): además de `myapi_payment_is_rule_completion()` → `notify_approved()`, agregar `elseif (myapi_payment_is_cancellation_transition($node))` → `myapi_payment_notify_cancelled($node)`. Las ramas de `recibo`/`alicuota_extra` y el `return` de `pagos` no se tocan.

- **`resources/payment.resource.inc`** (modificar — de spec 23) — en `myapi_payment_cancel()`, marcar `$node->myapi_skip_cancel_notification = TRUE;` antes del `node_save()`, para que la anulación vía endpoint no dispare la notificación.

- **`docs/payment-workflow.md`** (modificar) — nueva sección "Notificación al anular" describiendo el disparador (update a `"Anulado"` desde el back office), el opt-out del endpoint, el destinatario y el `title`/`body`.

### Fuera de este spec

- **Notificar la anulación hecha vía el endpoint `PUT /api/v1/payments/%/cancel`** — decisión explícita: el residente que anula su propio pago no se notifica a sí mismo; el endpoint marca el nodo para saltar la notificación.
- **Notificar creación directa de un nodo `pagos` ya en `"Anulado"`** — solo dispara en update (`hook_node_update`), nunca en insert; no hay rama en `hook_node_insert()`.
- **Revertir saldos al anular** — spec 23 ya garantiza que anular no mueve saldos (el pago pendiente nunca los aplicó); este spec solo notifica.
- **Traducir `title`/`body` vía catálogo i18n** — texto fijo en español, igual que spec 27 (no pasa por `myapi_t()`).
- **Nuevas claves i18n de error/éxito** — este spec no agrega endpoints ni respuestas de API; no toca `includes/myapi.i18n.inc`.
- **Deduplicación de notificaciones** — mismo criterio best-effort que spec 27; sin locks ni chequeo de duplicados.

---

## Modelo de datos

Este spec **no agrega tablas ni columnas** (`myapi_notifications` ya tiene `condominium_id`/`unit_id` desde spec 26). Solo agrega una constante, una bandera transitoria en el nodo y arma el `$params` que ya consume `myapi_notification_create()`.

### Constante nueva en `includes/myapi.notification.inc`

```php
define('MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED', 'payment_cancelled');
```

### Bandera transitoria de opt-out (en el nodo `pagos`)

`$node->myapi_skip_cancel_notification` — propiedad transitoria (no es un campo, no se persiste) que el endpoint de spec 23 pone en `TRUE` antes de su `node_save()`. Sobrevive en el mismo objeto `$node` hasta `hook_node_update()`, donde `myapi_payment_is_cancellation_transition()` la lee. Ausente (o `FALSE`) en cualquier otra ruta de guardado.

### `myapi_payment_is_cancellation_transition($node)` — detección

Devuelve `TRUE` solo cuando **todas** se cumplen:

| Condición | Regla |
|---|---|
| No es opt-out | `empty($node->myapi_skip_cancel_notification)` |
| Es un update | `isset($node->original)` (un insert no tiene `original`) |
| Valor entrante | `field_estado_pago` del nodo entrante `=== "Anulado"` |
| Valor previo | `field_estado_pago` de `$node->original` `!== "Anulado"` (cualquier estado anterior sirve; evita re-disparo si ya estaba anulado) |

### `myapi_payment_notify_cancelled($node)` — `$params` de `myapi_notification_create()`

| Clave | Valor |
|---|---|
| `source_type` | `MYAPI_NOTIFICATION_SOURCE_PAYMENT` (`"payment"`) |
| `source_nid` | `(int) $node->nid` |
| `type` | `MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED` (`"payment_cancelled"`) |
| `title` | `"Pago anulado — Ref. " . $reference` |
| `body` | ver construcción abajo |
| `deep_link_target` | `MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT` (`"payment"`) |
| `deep_link_id` | `(int) $node->nid` |
| `condominium_id` | nid del condominio de la vivienda, o `NULL` si no se resuelve |
| `unit_id` | nid de la vivienda (`field_vivienda`), o `NULL` |
| `uids` | `myapi_payment_notify_recipients($node, $unit_id)` (reutilizada de spec 27) |

Donde:
- `$reference = myapi_payment_field_value($node, 'field_referencia')`, o `''` si es `NULL`.
- `$amount = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2)` → `"0.00"` si no hay monto.
- `$reason = myapi_payment_field_value($node, 'field_detalle')` — se incluye en el cuerpo solo si no es `NULL` ni vacío tras `trim()`.
- `$unit_id` / `$condominium_id`: misma resolución exacta que `myapi_payment_notify_approved()` (leer `field_vivienda`, y `field_condominio` de esa vivienda vía `node_load()`); `NULL` si no se resuelven.

### Construcción del `body`

```
Tu pago de {amount} ha sido anulado.
Motivo: {reason}        ← solo si field_detalle tiene valor
Referencia: {reference}
```

- **Con** motivo: `"Tu pago de 45.90 ha sido anulado.\nMotivo: Comprobante duplicado\nReferencia: 000123"`
- **Sin** motivo: `"Tu pago de 45.90 ha sido anulado.\nReferencia: 000123"`

### Nota sobre duplicación de la resolución de contexto

`myapi_payment_notify_cancelled()` repite el bloque de resolución de `unit_id`/`condominium_id` que ya tiene `myapi_payment_notify_approved()` (~8 líneas). Se elige **duplicar** el bloque (funciones hermanas en el mismo archivo, sin tocar código Implemented de spec 27); si el patrón aparece en un tercer evento de pago, se extrae un helper compartido en ese momento (ver Decisiones).

---

## Plan de implementación

1. **Constante en `includes/myapi.notification.inc`** — agregar junto a las de pago existentes:
   ```php
   define('MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED', 'payment_cancelled');
   ```
   `drush cc all`. Estado funcional: sin cambio de comportamiento, solo una constante nueva.

2. **`myapi_payment_is_cancellation_transition($node)`** en `includes/myapi.payment_workflow.inc` (junto a `myapi_payment_is_rule_completion()`):
   ```php
   function myapi_payment_is_cancellation_transition($node) {
     if (!empty($node->myapi_skip_cancel_notification)) {
       return FALSE;
     }
     if (!isset($node->original)) {
       return FALSE;
     }
     $previous = myapi_payment_field_value($node->original, 'field_estado_pago');
     $incoming = myapi_payment_field_value($node, 'field_estado_pago');

     return $incoming === MYAPI_PAYMENT_STATUS_CANCELLED
       && $previous !== MYAPI_PAYMENT_STATUS_CANCELLED;
   }
   ```
   *Verificación: llamar a mano con un `$node` con `original` en `"Pendiente de verificar"` e incoming `"Anulado"` → `TRUE`; con la bandera de opt-out puesta → `FALSE`; con ambos en `"Anulado"` → `FALSE`; sin `original` (insert) → `FALSE`.*

3. **`myapi_payment_notify_cancelled($node)`** en el mismo archivo (junto a `myapi_payment_notify_approved()`):
   ```php
   function myapi_payment_notify_cancelled($node) {
     module_load_include('inc', 'myapi', 'includes/myapi.notification');

     $reference = myapi_payment_field_value($node, 'field_referencia');
     $reference = $reference !== NULL ? $reference : '';
     $amount = number_format((float) myapi_payment_field_value($node, 'field_valor'), 2);

     $reason = myapi_payment_field_value($node, 'field_detalle');
     $reason = ($reason !== NULL && trim($reason) !== '') ? trim($reason) : NULL;

     $body = 'Tu pago de ' . $amount . ' ha sido anulado.';
     if ($reason !== NULL) {
       $body .= "\nMotivo: " . $reason;
     }
     $body .= "\nReferencia: " . $reference;

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
       'type'             => MYAPI_NOTIFICATION_TYPE_PAYMENT_CANCELLED,
       'title'            => 'Pago anulado — Ref. ' . $reference,
       'body'             => $body,
       'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_PAYMENT,
       'deep_link_id'     => (int) $node->nid,
       'condominium_id'   => $condominium_id,
       'unit_id'          => $unit_id,
       'uids'             => myapi_payment_notify_recipients($node, $unit_id),
     ]);
   }
   ```
   *Verificación: llamar a mano sobre un nodo `pagos` de prueba inserta una fila en `myapi_notifications` con el `title`/`body` esperados (con y sin motivo).*

4. **Enganchar en `hook_node_update()`** — en `myapi.module`, ampliar la rama `pagos` existente de `myapi_node_update()`:
   ```php
   if ($node->type === 'pagos') {
     module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');
     if (myapi_payment_is_rule_completion($node)) {
       myapi_payment_notify_approved($node);
     }
     elseif (myapi_payment_is_cancellation_transition($node)) {
       myapi_payment_notify_cancelled($node);
     }
     return;
   }
   ```
   `drush cc all`. *Verificación: desde el back office, editar un pago `"Pendiente de verificar"` a `"Anulado"` y confirmar una fila nueva en `myapi_notifications` con `type = "payment_cancelled"`.*

5. **Opt-out en el endpoint** — en `resources/payment.resource.inc`, dentro de `myapi_payment_cancel()`, antes del `node_save($node)`:
   ```php
   $node->myapi_skip_cancel_notification = TRUE;
   ```
   *Verificación: `PUT /api/v1/payments/%/cancel` sobre un pago propio pendiente → el pago queda `"Anulado"` y **no** aparece notificación nueva.*

6. **Documentar en `docs/payment-workflow.md`** — sección "Notificación al anular": disparador (update a `"Anulado"` desde el back office, cualquier estado previo), opt-out del endpoint vía la bandera, destinatario (reutiliza la lógica de spec 27), `title`/`body` con y sin motivo, y que `unit_id`/`condominium_id` pueden quedar `NULL`.

7. **Aplicar y verificar** — `drush cc all` + probar: (a) anular desde el back office → notifica al destinatario correcto; (b) anular vía endpoint → no notifica; (c) editar un pago ya `"Anulado"` sin cambiar estado → no notifica; (d) crear un nodo directo en `"Anulado"` → no notifica.

---

## Criterios de aceptación

**Disparo correcto (back office)**
- [x] Editar desde el back office un pago de `"Pendiente de verificar"` a `"Anulado"` genera una fila nueva en `myapi_notifications` con `type = "payment_cancelled"`.
- [x] Editar desde el back office un pago de `"Completado"` (u otro estado ≠ `"Anulado"`) a `"Anulado"` también genera la notificación (cualquier estado previo dispara).
- [x] El `title` es exactamente `"Pago anulado — Ref. {reference}"` con la referencia real.
- [x] Con `field_detalle` presente, el `body` es exactamente `"Tu pago de {amount} ha sido anulado.\nMotivo: {detalle}\nReferencia: {reference}"`.
- [x] Sin `field_detalle`, el `body` es exactamente `"Tu pago de {amount} ha sido anulado.\nReferencia: {reference}"` (sin línea de motivo).
- [x] `{amount}` va formateado a 2 decimales (ej. `"45.90"`).
- [x] `source_type = "payment"`, `source_nid` = nid del pago, `deep_link.target = "payment"`, `deep_link.id` = nid del pago.
- [x] `unit_id`/`condominium_id` quedan poblados con el nid de la vivienda y del condominio cuando se pueden resolver.

**Destinatario (reutiliza spec 27)**
- [x] Si el autor del pago (`node->uid`) **no** tiene rol `administrator`, el destinatario es ese `uid`.
- [x] Si el autor tiene rol `administrator` y la vivienda tiene ocupante(s) resoluble(s), el destinatario es el/los ocupante(s), no el admin.
- [x] Si el autor es `administrator` pero la vivienda no tiene ocupante resoluble (o no hay `field_vivienda`), cae de vuelta al `uid` autor.

**No dispara**
- [x] Anular vía `PUT /api/v1/payments/%/cancel` (spec 23) **no** genera notificación (bandera de opt-out).
- [x] Crear un nodo `pagos` directamente en `"Anulado"` **no** genera notificación (solo update, no insert).
- [x] Editar un pago ya `"Anulado"` (cambiando otros campos, sin tocar el estado) **no** genera notificación nueva.
- [x] Las transiciones que NO llegan a `"Anulado"` (verificación → `"Completado"`, etc.) no generan notificación de anulación.

**No regresión / infra**
- [x] La notificación de pago aprobado (spec 27) sigue funcionando idéntica; la rama `pagos` de `myapi_node_update()` sigue notificando aprobados sin cambios.
- [x] Las notificaciones de recibo/alícuota (spec 28) y de boletín (spec 25/26) siguen funcionando idénticas.
- [x] El endpoint de anular (spec 23) sigue devolviendo `200` con el pago anulado igual que antes; solo se le agregó la bandera.
- [x] `GET /api/v1/notifications` (spec 25) devuelve la notificación de anulación con `deep_link.unit`/`deep_link.condominium` pobladas cuando corresponde (spec 26).
- [x] `drush cc all` no reporta errores.
- [x] `docs/payment-workflow.md` documenta el nuevo comportamiento.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Anulación vía endpoint | **No** notifica; el endpoint marca el nodo (`myapi_skip_cancel_notification`) | Notificar también las anulaciones del endpoint | Elección del usuario: el residente que anula su propio pago vía la app no necesita notificarse a sí mismo; la notificación tiene sentido cuando la anula un tercero desde el panel administrativo. |
| Mecanismo de opt-out | Bandera transitoria en el objeto `$node`, seteada antes de `node_save()` | Detectar por rol del usuario actual / por estado previo | La bandera es explícita y robusta: el endpoint declara "esto ya lo manejo yo". Detectar por rol es frágil (un admin también podría usar la API en el futuro) y el estado previo no distingue (un admin puede anular desde `"Pendiente de verificar"` en el back office). |
| Estados previos que disparan | Cualquier estado ≠ `"Anulado"` → `"Anulado"` | Solo `"Pendiente de verificar"` → `"Anulado"` | Elección del usuario: "pasa a Anulado" sin importar el origen; robusto ante anulaciones desde `"Completado"` u otros que el back office permita. El chequeo `previo !== "Anulado"` evita re-disparo. |
| Solo en update, no en insert | Solo `hook_node_update()`; sin rama en `hook_node_insert()` | Notificar también creación directa en `"Anulado"` | Elección del usuario ("solo cuando se actualiza"): un pago no nace anulado en el flujo real; crear uno directo en `"Anulado"` es un caso administrativo sin destinatario que avisar. |
| Destinatario | Reutiliza `myapi_payment_notify_recipients()` de spec 27 (autor; ocupante si el autor es `administrator`; respaldo al autor) | Lógica de destinatario propia | Consistencia total con la notificación de aprobado; misma función, cero duplicación. |
| Motivo en el cuerpo | Incluir `field_detalle` como línea `"Motivo: ..."` solo si tiene valor | Omitir siempre el motivo / incluirlo siempre aunque esté vacío | Elección del usuario: el motivo (el `reason` que persiste spec 23) es información útil para el residente; se omite la línea si el campo está vacío para no mostrar `"Motivo:"` en blanco. |
| Tipo / constantes | `type = "payment_cancelled"`; reutiliza `source_type`/`deep_link_target` = `"payment"` | Nuevo `source_type`/`deep_link_target` propios | El origen y el deep link son el mismo pago que el de aprobado (apunta a `GET /api/v1/payments/%`); solo el `type` distingue el evento. |
| Resolución de `unit_id`/`condominium_id` | Duplicar el bloque de `notify_approved()` en `notify_cancelled()` (funciones hermanas, mismo archivo) | Extraer un helper compartido y refactorizar `notify_approved()` | Menor riesgo: no toca código Implemented de spec 27. Si el patrón se repite en un tercer evento de pago, se extrae el helper en ese momento. |
| Idioma del texto | Fijo en español, sin `myapi_t()` | Traducir vía catálogo i18n | Mismo criterio que spec 27 y el body de boletín; no hay `Accept-Language` dentro de un hook de `node_save`. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **La bandera de opt-out no llega al hook.** Si `$node->myapi_skip_cancel_notification` no sobrevive al ciclo de `node_save()`, el endpoint (spec 23) notificaría por error. | En Drupal 7 los hooks de `node_save()` (`presave`/`update`) reciben el mismo objeto `$node` que el llamador modificó, así que una propiedad seteada antes de `node_save()` es visible en `hook_node_update()`. Es un patrón estándar del core. Se verifica explícitamente en el criterio "anular vía endpoint no notifica". |
| **Otro flujo futuro que anule un pago sin marcar la bandera.** Si más adelante se agrega otra ruta programática de anulación (drush, otro endpoint) que no debería notificar, disparará la notificación por defecto. | Aceptado y explícito: el default es "notificar toda anulación desde fuera del endpoint actual". Cualquier ruta nueva que quiera silenciarse solo setea la misma bandera; es una línea. Se documenta en `docs/payment-workflow.md`. |
| **Doble notificación si un update pasa por `is_rule_completion` y `is_cancellation` a la vez.** No puede ocurrir (un update no puede terminar en `"Completado"` y `"Anulado"` simultáneamente), pero el `elseif` lo blinda igual. | El `elseif` garantiza exclusión mutua; además los valores entrantes son distintos por construcción. |
| **Anulación desde el back office sin `field_referencia`/`field_valor`/`field_vivienda`.** Un pago editado a mano podría no tener esos campos. | Contemplado: referencia vacía, monto `"0.00"`, `unit_id`/`condominium_id` en `NULL`, sin abortar ni lanzar error (mismo best-effort que spec 27). |
| **Dependencia del literal `"Anulado"`.** La detección compara contra `MYAPI_PAYMENT_STATUS_CANCELLED`; si el `allowed_value` del campo cambiara de texto, dejaría de disparar. | La comparación usa la constante compartida (`includes/myapi.payment_workflow.inc`), fuente única de verdad de los valores de `field_estado_pago`, ya usada por spec 22/23/27. |
| **Duplicación del bloque de resolución de contexto** entre `notify_approved()` y `notify_cancelled()`. | Aceptado por decisión (menor riesgo que refactorizar código Implemented); si aparece un tercer evento de pago se extrae el helper. |

---

## Lo que **no** entra en este spec

- Notificar la anulación hecha vía el endpoint `PUT /api/v1/payments/%/cancel` (el residente no se notifica a sí mismo).
- Notificar la creación directa de un nodo `pagos` ya en `"Anulado"` (solo update, nunca insert).
- Revertir saldos al anular.
- Traducir `title`/`body` vía catálogo i18n.
- Deduplicación de notificaciones.

Cada una, si aparece, va en su propio spec.
