# 28 — Notificación de alícuota enviada (recibo y alícuota extra)

- **Estado:** Implemented
- **Fecha:** 2026-07-17
- **Dependencias:**
  - `25-notifications-inbox-boletin` (Implemented) — tabla `myapi_notifications`,
    `myapi_notification_create()`, endpoints de `resources/notification.resource.inc`.
  - `26-notification-condominium-unit-context` (Implemented) — columnas
    `condominium_id`/`unit_id` en `myapi_notifications` y su propagación por
    `myapi_notification_create()`; este spec las completa como segundo trigger real.
  - `27-payment-approved-notification` (Approved) — patrón de trigger por
    transición de estado en un `hook_node_*` + función `notify` en un include,
    que este spec replica para `recibo`/`alicuota_extra`.
  - `09-units-owner-occupant` (Implemented) — `myapi_unit_member_uids()` en
    `includes/myapi.unit_access.inc`, usado para resolver los ocupantes de la vivienda.
  - `11-unit-receipts-list` / `13-unit-extra-fees-list` (Implemented) — definen el
    mapeo de campos de `recibo` (`field_total_mes`, `field_estado`,
    `field_vivienda`) y `alicuota_extra` (`field_valor_extra`, `field_estado`,
    `field_vivienda`) que este spec lee.
- **Objetivo:** Cuando un nodo `recibo` o `alicuota_extra` transita a
  `field_estado = "Enviado"` al editarse, notificar vía `myapi_notification_create()`
  a los ocupantes de la vivienda (`field_vivienda`) con el asunto
  `"Nueva alícuota[ extra] generada"` y el cuerpo
  `"Nueva alícuota[ extra] registrada para {nombre de la vivienda}\nValor total: {valor}"`,
  usando `field_total_mes` (recibo) o `field_valor_extra` (alícuota extra),
  formateado a 2 decimales, y completando `unit_id`/`condominium_id` de contexto.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.fee_notification.inc`** (nuevo) — concentra la lógica del trigger:
  - Constantes de configuración por tipo de nodo (`recibo` / `alicuota_extra`):
    qué campo de valor leer, qué palabra ("extra") insertar en los textos, y el
    `type`/`source_type` de la notificación.
  - `myapi_fee_is_sent_transition($node)` — detecta la transición a `"Enviado"`
    al editar: hay `$node->original`, el estado previo **no** era `"Enviado"` y el
    entrante **es** `"Enviado"` (mismo patrón que `myapi_payment_is_rule_completion()`).
  - `myapi_fee_notify_issued($node)` — arma `title`/`body` según el tipo, resuelve
    `unit_id` (`field_vivienda`), `condominium_id` (`field_condominio` de la vivienda)
    y el nombre de la vivienda (`title` del nodo referenciado), resuelve los ocupantes
    vía `myapi_unit_member_uids([$unit_id], 'ocupantes')` y llama a
    `myapi_notification_create()`.
  - `myapi_fee_field_value($node, $field_name)` — lector genérico de
    `$node->{field}[LANGUAGE_NONE][0]['value']` (o `NULL`), local a este include.
- **`myapi.module`** (modificar) — `myapi_node_update()` gana una rama: si
  `$node->type` es `recibo` o `alicuota_extra`, carga este include y, si
  `myapi_fee_is_sent_transition($node)` es verdadero, llama a
  `myapi_fee_notify_issued($node)`. La rama existente de `pagos` no se toca.
- **`myapi.info`** (modificar) — agregar `files[] = includes/myapi.fee_notification.inc`.
- **`docs/notification.md`** (modificar) — documentar el nuevo `type`
  (`receipt_sent` / `extra_fee_sent`), su `title`/`body`, el destinatario (ocupantes
  de la vivienda) y que `deep_link.target` = `source_type` / `deep_link.id` = nid
  del nodo, además de `deep_link.unit`/`condominium`.

### Fuera de este spec

- **Notificar al crear el nodo directamente en `"Enviado"`** (sin edición previa).
  Decisión del usuario: solo se notifica en la **transición al editar**
  (`hook_node_update`); un `recibo`/`alicuota_extra` que nace ya en `"Enviado"`
  vía `node_save()` no dispara. No se implementa rama en `hook_node_insert()`.
- **Fallback a propietarios cuando la vivienda no tiene ocupantes.** Si
  `myapi_unit_member_uids($unit_id, 'ocupantes')` devuelve vacío, la lista de uids
  queda vacía y `myapi_notification_create()` es un no-op: no se crea notificación.
  No se cae de respaldo a propietarios ni al autor del nodo.
- **Endpoint de detalle de recibo/alícuota extra.** No existe endpoint de
  detalle para estos tipos (spec 11/13 son solo listas por unidad). El
  `deep_link.target`/`id` guarda la identidad del nodo origen (`source_type` +
  nid), pero la navegación a una pantalla de detalle por nodo va en otro spec.
- **Traducir el asunto/cuerpo vía catálogo i18n.** Texto fijo en español, no pasa
  por `myapi_t()` (mismo criterio que boletín y pago).
- **Notificar otras transiciones de estado** (a borrador, anulación, etc.) o
  re-notificar al editar un nodo que ya estaba en `"Enviado"`. Solo cubre la
  llegada a `"Enviado"` desde un estado distinto.
- **Nuevas columnas o índices en `myapi_notifications`.** Se reutiliza el schema
  que dejó listo spec 26 (`condominium_id`/`unit_id`).
- **Filtrar por usuario activo** antes de notificar. Mismo criterio best-effort
  que spec 27: `myapi_notification_create()` inserta la fila igual.

---

## Modelo de datos

Este spec **no agrega tablas ni columnas** (usa `condominium_id`/`unit_id` de
spec 26). Solo agrega constantes y arma el `$params` que ya consume
`myapi_notification_create()`.

### Constantes nuevas en `includes/myapi.fee_notification.inc`

```php
define('MYAPI_FEE_STATUS_SENT', 'Enviado');

define('MYAPI_NOTIFICATION_SOURCE_RECEIPT', 'receipt');
define('MYAPI_NOTIFICATION_SOURCE_EXTRA_FEE', 'extra_fee');
define('MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT', 'receipt_sent');
define('MYAPI_NOTIFICATION_TYPE_EXTRA_FEE_SENT', 'extra_fee_sent');
```

No se define constante de `deep_link` porque este trigger no lleva deep link.

### Tabla de configuración por tipo de nodo

`myapi_fee_notify_issued()` resuelve el comportamiento según `$node->type`:

| `$node->type`    | `source_type`  | `type`            | Campo de valor        | Palabra insertada |
|------------------|----------------|-------------------|-----------------------|-------------------|
| `recibo`         | `receipt`      | `receipt_sent`    | `field_total_mes`     | (ninguna)         |
| `alicuota_extra` | `extra_fee`    | `extra_fee_sent`  | `field_valor_extra`   | `extra `          |

### Textos generados

Sea `$w = ''` para `recibo` y `$w = 'extra '` para `alicuota_extra`:

- **title** = `"Nueva alícuota " . $w . "generada"`
- **body**  = `"Nueva alícuota " . $w . "registrada para " . $unit_title . "\nValor total: " . $amount`

Donde:
- `$unit_title` = `title` del nodo vivienda (`node_load($unit_id)->title`), o `''`
  si no se puede resolver.
- `$amount` = `number_format((float) myapi_fee_field_value($node, $value_field), 2)`
  — sin valor queda `"0.00"`.

### `$params` armado para `myapi_notification_create()`

| Clave             | Valor |
|-------------------|-------|
| `source_type`     | `receipt` / `extra_fee` según el tipo |
| `source_nid`      | `(int) $node->nid` |
| `type`            | `receipt_sent` / `extra_fee_sent` |
| `title`           | ver arriba |
| `body`            | ver arriba |
| `deep_link_target`| `receipt` / `extra_fee` según el tipo (= `source_type`) |
| `deep_link_id`    | `(int) $node->nid` |
| `condominium_id`  | nid del condominio de la vivienda, o `NULL` si no se resuelve |
| `unit_id`         | nid de la vivienda (`field_vivienda`), o `NULL` si no está presente |
| `uids`            | `myapi_unit_member_uids([$unit_id], 'ocupantes')` (o `[]` si `$unit_id` es `NULL`) |

### Resolución de `unit_id` / `condominium_id` / `unit_title`

- `$unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
   ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] : NULL`.
- Si `$unit_id !== NULL`: `$vivienda = node_load($unit_id)`;
  - `$unit_title = $vivienda ? $vivienda->title : ''`.
  - `$condominium_id = isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])
     ? (int) ... : NULL` (mismo patrón que spec 27).
- Si `$unit_id === NULL`: `uids = []` → `myapi_notification_create()` es no-op
  (no se crea notificación; sin vivienda no hay ocupantes a quién notificar).

### Detección de la transición — `myapi_fee_is_sent_transition($node)`

```php
function myapi_fee_is_sent_transition($node) {
  if (!isset($node->original)) {
    return FALSE;
  }
  $previous = myapi_fee_field_value($node->original, 'field_estado');
  $incoming = myapi_fee_field_value($node, 'field_estado');

  return $previous !== MYAPI_FEE_STATUS_SENT
    && $incoming === MYAPI_FEE_STATUS_SENT;
}
```

- Solo puede dar `TRUE` en un `node_update` (un nodo insertado no tiene
  `$node->original`), lo que alinea con "solo transición al editar".
- `$previous` puede ser cualquier valor distinto de `"Enviado"` (borrador, otro
  estado, o `NULL` si el nodo no tenía `field_estado`): cualquiera de esos
  cuenta como "aún no enviado" y la llegada a `"Enviado"` dispara una vez.

### Ejemplo de mensaje generado

`recibo` con vivienda "Casa 12" y `field_total_mes = 150`:

```json
{
  "type": "receipt_sent",
  "title": "Nueva alícuota generada",
  "body": "Nueva alícuota registrada para Casa 12\nValor total: 150.00"
}
```

`alicuota_extra` con vivienda "Casa 12" y `field_valor_extra = 25`:

```json
{
  "type": "extra_fee_sent",
  "title": "Nueva alícuota extra generada",
  "body": "Nueva alícuota extra registrada para Casa 12\nValor total: 25.00"
}
```

---

## Plan de implementación

1. **Crear `includes/myapi.fee_notification.inc`** con las constantes
   (`MYAPI_FEE_STATUS_SENT`, `MYAPI_NOTIFICATION_SOURCE_RECEIPT/EXTRA_FEE`,
   `MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT/EXTRA_FEE_SENT`) y el lector genérico:
   ```php
   function myapi_fee_field_value($node, $field_name) {
     return isset($node->{$field_name}[LANGUAGE_NONE][0]['value'])
       ? $node->{$field_name}[LANGUAGE_NONE][0]['value']
       : NULL;
   }
   ```
   *Verificación: `drush cc all` no arroja error de sintaxis; las constantes
   quedan definidas.*

2. **`myapi_fee_is_sent_transition($node)`** en el mismo include (según el modelo
   de datos). *Verificación: con un `$node` simulado cuyo `original->field_estado`
   sea `"Borrador"` y el entrante `"Enviado"` devuelve `TRUE`; con ambos en
   `"Enviado"`, o sin `$node->original`, devuelve `FALSE`.*

3. **`myapi_fee_notify_issued($node)`** en el mismo include:
   ```php
   function myapi_fee_notify_issued($node) {
     module_load_include('inc', 'myapi', 'includes/myapi.notification');
     module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

     $map = [
       'recibo' => [
         'source' => MYAPI_NOTIFICATION_SOURCE_RECEIPT,
         'type'   => MYAPI_NOTIFICATION_TYPE_RECEIPT_SENT,
         'field'  => 'field_total_mes',
         'word'   => '',
       ],
       'alicuota_extra' => [
         'source' => MYAPI_NOTIFICATION_SOURCE_EXTRA_FEE,
         'type'   => MYAPI_NOTIFICATION_TYPE_EXTRA_FEE_SENT,
         'field'  => 'field_valor_extra',
         'word'   => 'extra ',
       ],
     ];
     if (!isset($map[$node->type])) {
       return;
     }
     $cfg = $map[$node->type];

     $unit_id = isset($node->field_vivienda[LANGUAGE_NONE][0]['target_id'])
       ? (int) $node->field_vivienda[LANGUAGE_NONE][0]['target_id']
       : NULL;

     $unit_title = '';
     $condominium_id = NULL;
     if ($unit_id !== NULL) {
       $vivienda = node_load($unit_id);
       if ($vivienda) {
         $unit_title = $vivienda->title;
         if (isset($vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'])) {
           $condominium_id = (int) $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id'];
         }
       }
     }

     $amount = number_format((float) myapi_fee_field_value($node, $cfg['field']), 2);
     $uids = $unit_id !== NULL
       ? myapi_unit_member_uids([$unit_id], 'ocupantes')
       : [];

     myapi_notification_create([
       'source_type'      => $cfg['source'],
       'source_nid'       => (int) $node->nid,
       'type'             => $cfg['type'],
       'title'            => 'Nueva alícuota ' . $cfg['word'] . 'generada',
       'body'             => 'Nueva alícuota ' . $cfg['word'] . 'registrada para '
                             . $unit_title . "\nValor total: " . $amount,
       'deep_link_target' => NULL,
       'deep_link_id'     => NULL,
       'condominium_id'   => $condominium_id,
       'unit_id'          => $unit_id,
       'uids'             => $uids,
     ]);
   }
   ```
   *Verificación: llamar la función a mano sobre un `recibo` de prueba con
   ocupante(s) inserta una fila por ocupante en `myapi_notifications` con el
   `title`/`body` esperados; sin ocupantes no inserta nada (no-op).*

4. **`myapi.info`** — agregar `files[] = includes/myapi.fee_notification.inc`.

5. **Enganchar en `myapi.module`** — ampliar `myapi_node_update()` sin tocar la
   rama de `pagos`:
   ```php
   function myapi_node_update($node) {
     if ($node->type === 'pagos') {
       module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');
       if (myapi_payment_is_rule_completion($node)) {
         myapi_payment_notify_approved($node);
       }
       return;
     }
     if ($node->type === 'recibo' || $node->type === 'alicuota_extra') {
       module_load_include('inc', 'myapi', 'includes/myapi.fee_notification');
       if (myapi_fee_is_sent_transition($node)) {
         myapi_fee_notify_issued($node);
       }
     }
   }
   ```
   Tras esto, `drush cc all`. *Verificación: editar un `recibo` de `"Borrador"`
   a `"Enviado"` genera notificación a los ocupantes; editarlo de nuevo sin tocar
   el estado NO genera otra.*

6. **Documentar en `docs/notification.md`** — agregar los `type` `receipt_sent` /
   `extra_fee_sent`: cuándo disparan (transición a `"Enviado"` al editar un
   `recibo`/`alicuota_extra`), destinatario (ocupantes de la vivienda),
   `title`/`body` de cada uno, y que `deep_link.target` = `source_type` /
   `deep_link.id` = nid del nodo, además de `deep_link.unit`/`condominium`.

7. **Aplicar y verificar** — `drush cc all`. Probar ambos tipos:
   - `recibo`: crear en `"Borrador"`, editar a `"Enviado"` → 1 notificación
     `receipt_sent` por ocupante, con `title = "Nueva alícuota generada"`,
     `body` con nombre de vivienda y `total_fee` a 2 decimales.
   - `alicuota_extra`: mismo flujo → `extra_fee_sent`, textos con "extra" y
     `field_valor_extra`.
   Confirmar en BD y vía `GET /api/v1/notifications` (spec 25/26) que la fila
   trae `deep_link.unit`/`condominium` pobladas y `deep_link.target` =
   `source_type` / `deep_link.id` = nid del nodo. Confirmar además que un
   `recibo` re-guardado en el mismo request (Rule de recálculo) genera **una
   sola** notificación por ocupante (guard `drupal_static`).

---

## Criterios de aceptación

**Disparo correcto**
- [x] Editar un `recibo` cuyo `field_estado` pasa de un valor distinto de
  `"Enviado"` (borrador, otro estado, o sin `field_estado`) a `"Enviado"` genera
  una fila nueva en `myapi_notifications` por cada ocupante de la vivienda.
- [x] Editar un `alicuota_extra` con la misma transición genera una fila nueva
  por cada ocupante.
- [x] Para `recibo`: `type = "receipt_sent"`, `source_type = "receipt"`,
  `title = "Nueva alícuota generada"`,
  `body = "Nueva alícuota registrada para {nombre vivienda}\nValor total: {total}"`
  con `{total}` = `field_total_mes` a 2 decimales.
- [x] Para `alicuota_extra`: `type = "extra_fee_sent"`, `source_type = "extra_fee"`,
  `title = "Nueva alícuota extra generada"`,
  `body = "Nueva alícuota extra registrada para {nombre vivienda}\nValor total: {valor}"`
  con `{valor}` = `field_valor_extra` a 2 decimales.
- [x] `source_nid` = nid del nodo; `unit_id` = nid de `field_vivienda`;
  `condominium_id` = nid del condominio de la vivienda cuando se resuelve.
- [x] `deep_link.target` = `source_type` (`receipt`/`extra_fee`) y `deep_link.id`
  = nid del nodo.

**Destinatario**
- [x] El destinatario es el/los ocupante(s) de la vivienda
  (`field_ocupante`/`field_ocupantes`), nunca los propietarios ni el autor del nodo.
- [x] Si la vivienda no tiene ningún ocupante resoluble, no se crea ninguna
  notificación (no-op), sin error.
- [x] Si el nodo no tiene `field_vivienda`, no se crea ninguna notificación
  (no-op), sin error.

**No dispara / idempotencia**
- [x] Crear un `recibo`/`alicuota_extra` directamente en `"Enviado"` (sin edición
  posterior) **no** genera notificación (solo se cubre la transición al editar).
- [x] Editar un `recibo`/`alicuota_extra` que ya estaba en `"Enviado"` sin cambiar
  el estado **no** genera una notificación nueva.
- [x] Un `recibo`/`alicuota_extra` re-guardado dentro del mismo request (p. ej.
  una Rule de recálculo con `$node->original` obsoleto) genera **una sola**
  notificación por ocupante, no dos (guard `drupal_static` por nid).
- [x] Editar un nodo hacia un estado distinto de `"Enviado"` **no** genera
  notificación.
- [x] Editar un `pagos` sigue comportándose igual que en spec 27 (la rama de
  `pagos` en `myapi_node_update()` no cambia).

**Campos faltantes**
- [x] Un `recibo`/`alicuota_extra` sin el campo de valor
  (`field_total_mes`/`field_valor_extra`) genera la notificación con
  `"Valor total: 0.00"`, sin error.
- [x] Una vivienda sin `title` resoluble (o `node_load` que devuelve `FALSE`)
  genera la notificación con el nombre vacío (`"...registrada para \nValor total:..."`),
  sin error — siempre que haya al menos un ocupante.

**No regresión / infra**
- [x] Las notificaciones de boletín (spec 25/26) y de pago (spec 27) siguen
  funcionando idénticas.
- [x] `GET /api/v1/notifications` (spec 25) devuelve la notificación de alícuota
  con `deep_link.unit`/`deep_link.condominium` pobladas y `deep_link.target` =
  `source_type` / `deep_link.id` = nid del nodo.
- [x] `myapi.info` lista `includes/myapi.fee_notification.inc` y `drush cc all`
  no reporta errores.
- [x] `docs/notification.md` documenta los `type` `receipt_sent` / `extra_fee_sent`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Momento del disparo | Solo la transición a `"Enviado"` al **editar** el nodo (`hook_node_update`, comparando `$node->original` contra el valor entrante) | (1) Notificar también al crear el nodo directo en `"Enviado"` (`hook_node_insert`); (2) cualquier transición hacia `"Enviado"` incluida la creación | Elección del usuario: "se debe enviar cuando se pasa a estado Enviado". El flujo real es crear el recibo/alícuota extra en borrador y luego pasarlo a `"Enviado"`; la creación directa no se considera en este spec. |
| Destinatario | Solo ocupantes de la vivienda (`myapi_unit_member_uids($unit_id, 'ocupantes')`) | (1) Propietarios; (2) propietarios + ocupantes (`'todos'`); (3) autor del nodo | Elección del usuario. La alícuota la genera el administrador, así que el autor del nodo no es un residente útil; se notifica a quien ocupa la vivienda. |
| Sin ocupantes → sin fallback | No crear notificación (lista de uids vacía → no-op de `myapi_notification_create()`) | Caer de respaldo a propietarios o al autor del nodo | Coherente con "solo ocupantes": si no hay ocupante, no hay a quién notificar; no se inventa un destinatario. Documentado como borde conocido (ver Riesgos). |
| Deep link | `deep_link_target` = `source_type` (`receipt`/`extra_fee`) y `deep_link_id` = nid del nodo (mismo patrón que spec 27); más `unit_id`/`condominium_id` de contexto | (1) Dejarlo en `NULL` por no existir endpoint de detalle; (2) apuntar a la lista de la vivienda con el nid de la vivienda | Decisión del usuario durante la implementación: identifica el nodo origen igual que la notificación de pago aprobado, aunque el detalle por nodo aún no exista. |
| Alcance: un spec para ambos tipos | Una función parametrizada por `$node->type` (`recibo`/`alicuota_extra`) en un único include y doc | Dos specs/archivos separados | Es el mismo feature: los textos solo difieren en la palabra "extra" y el campo de valor; separar duplicaría lógica sin beneficio. |
| Formato de `{valor}` | `number_format($valor, 2)` sin símbolo de moneda | Valor crudo del campo / con símbolo `$` | Elección del usuario (mismo criterio que spec 27): consistente con cómo la API expone importes, sin asumir moneda. |
| Nombre de la vivienda | `title` del nodo `vivienda` referenciado (`node_load($unit_id)->title`) | Un campo específico de la vivienda | El pedido dice "nombre de la vivienda"; el título del nodo es el nombre natural y no requiere asumir un campo extra. |
| Idioma del texto | Fijo en español, sin `myapi_t()` | Traducir vía catálogo i18n según el destinatario | Mismo criterio que boletín y pago (spec 27): no hay `Accept-Language` disponible dentro de un hook de `node_save`, y el contenido se maneja tal cual. |
| Ubicación del código | Nuevo `includes/myapi.fee_notification.inc` (lógica) + glue en `myapi_node_update()` | Meterlo en `myapi.payment_workflow.inc` o en `resources/` | La lógica es de `recibo`/`alicuota_extra`, no de pagos; un include propio evita mezclar dominios y respeta "shared helpers en includes/". |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Vivienda sin ocupantes → notificación silenciosamente ausente.** Si la vivienda no tiene `field_ocupante`/`field_ocupantes`, nadie recibe la alícuota y no queda rastro de error. | Comportamiento aceptado explícitamente (ver Decisiones). Si más adelante se quiere garantizar entrega, se agrega un fallback a propietarios en su propio spec; hoy es un no-op consciente, no un fallo. |
| **Nombre de campo de estado (`field_estado`).** La detección de la transición depende de que el estado viva en `field_estado` con el valor exacto `"Enviado"` para ambos tipos. Un rename del campo o un cambio del texto del estado rompe el disparo sin aviso. | El valor `"Enviado"` y el campo `field_estado` son los que ya usan los endpoints de spec 11/13 (mismo supuesto de acceso directo a Field API); si cambian, es un ajuste de una constante (`MYAPI_FEE_STATUS_SENT`) y del lector. |
| **Cambio de forma en `myapi_node_update()`.** Se agrega la rama de `recibo`/`alicuota_extra` junto a la de `pagos`; un error de anidamiento podría afectar el disparo de pago aprobado (spec 27). | La rama de `pagos` conserva su `return` explícito y queda intacta; la rama nueva es independiente y se cubre con el criterio de no regresión (editar un `pagos` sigue igual). |
| **Doble notificación si un mismo save produce dos updates.** En la práctica se observó: al pasar un `recibo` a `"Enviado"`, otro mecanismo (una Rule de recálculo) re-guarda el mismo objeto `$node` dentro del request. Como `node_save()` no re-carga `$node->original` cuando ya está seteado, el segundo update ve el `original` obsoleto (previo aún `"Borrador"`) y `myapi_fee_is_sent_transition()` vuelve a dar `TRUE` → segunda notificación con el mismo `uid`. | La condición de transición no basta ante un re-save con `original` obsoleto. Se agrega un guard `drupal_static` por nid en `myapi_fee_notify_issued()` que garantiza **una sola notificación por nodo por request**, sin depender de la frescura de `$node->original`. |
| **`node_load()` por notificación.** `myapi_fee_notify_issued()` hace un `node_load()` de la vivienda más las queries de `myapi_unit_member_uids()`. | Costo aceptable: se ejecuta una vez por transición de estado de un recibo/alícuota, no en un listado ni en un loop masivo (mismo orden de magnitud que spec 27). |
| **Acoplamiento entre `myapi.fee_notification.inc` y `myapi.notification.inc`/`myapi.unit_access.inc`.** El include nuevo carga dos includes de otros dominios vía `module_load_include()`. | Es reutilización explícita, no duplicación de lógica; mismo patrón ya aceptado en spec 27 (`payment_workflow` carga `notification`). |
</content>
</invoke>
