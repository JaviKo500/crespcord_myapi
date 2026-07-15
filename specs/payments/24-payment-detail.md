# 24 — Detalle de un pago (`GET /api/v1/payments/%`)

- **Estado:** Approved
- **Fecha:** 2026-07-14
- **Dependencias:**
  - `05-middleware-access-token-logout` (Implemented) — `myapi_auth_require_access_token()`.
  - `09-units-owner-occupant` (Implemented) — `myapi_unit_related_nids($uid)`.
  - `14-unit-payments-list` / `20-register-payment` / `23-anular-pago` (Implemented) — recurso `payment.resource.inc` que este endpoint amplía; reutiliza `myapi_payment_build_created_item()` y el criterio de exclusión `MYAPI_PAYMENT_EXCLUDED_STATUS`.
- **Objetivo:** Agregar `GET /api/v1/payments/%`, un endpoint autenticado que devuelve el detalle completo de un pago propio (propietario u ocupante de la vivienda asociada, cuyo estado no sea `"Nuevo"`), respondiendo `404 payment_not_found` si no existe, no es un pago, o está en `"Nuevo"`, y `403 unit_access_denied` si el usuario no es propietario ni ocupante de la vivienda del pago.

---

## Alcance

### Dentro de este spec

- **`resources/payment.resource.inc`** (modificar — recurso ya existente) — se agrega:
  - **Ruta de item nueva** `api/v1/payments/%`, con un dispatcher propio que enruta por método: solo `GET` → `myapi_payment_detail($payment_id)`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - **`myapi_payment_detail($payment_id)`** — exige access token, carga el nodo `pagos`, valida que exista y sea tipo `pagos` (si no, `404`), valida que su `field_estado_pago` no sea `"Nuevo"` (si lo es, `404`, mismo código que "no existe"), valida que el usuario sea propietario u ocupante de la vivienda del pago (si no, `403`), y responde `200` con el pago completo.
  - **Helper de mapeo reutilizado**: `myapi_payment_build_created_item()` (ya existe, mismo usado por `create`/`cancel`), recargando `$file`/`$bank_term` igual que hace `myapi_payment_cancel()`.
- **`myapi.module`** (modificar) — registrar `api/v1/payments/%` en `hook_menu()` (`page arguments => [2]`, `access callback => TRUE`, `file => resources/payment.resource.inc`). La autenticación y las validaciones se resuelven dentro del recurso.
- **`docs/payment.md`** (modificar) — agregar la sección `GET /api/v1/payments/%` siguiendo la plantilla (auth requerida, respuesta `200`, tabla de errores `401`/`403`/`404`/`405`).
- **`myapi.info`** — sin cambios: `resources/payment.resource.inc` ya está listado.

### Fuera de este spec

- **Modificar el pago** (`PUT`/`DELETE` distintos de `cancel`) — solo lectura de detalle.
- **Mostrar pagos en estado `"Nuevo"`** — igual criterio que el listado (spec 14): tratado como no encontrado (`404`), no se distingue de un `payment_id` inexistente.
- **Nuevas claves i18n** — se reutilizan `payment_not_found`, `unit_access_denied` y `method_not_allowed`, ya existentes desde spec 23; no se agrega ninguna clave nueva.
- **Mensaje de éxito (`message`)** — la respuesta `200` no incluye `message`, igual que el listado (spec 14) y consistente con un endpoint de solo lectura.
- **Descargar el archivo adjunto** — se expone `file_id`/`file_name` igual que en `create`/`cancel`, pero no hay endpoint de descarga aquí (sigue fuera de alcance, como en spec 20).
- **Acceso ampliado para administradores** — el control de acceso es solo propietario/ocupante, igual que el resto del recurso.

---

## Modelo de datos

Este spec **no introduce tablas propias** (`myapi_*`), no hay `hook_schema()` ni cambios en `myapi.install`. Solo lee estructuras existentes de Drupal a través de la Field API / `node_load()`.

### Entrada

| Campo | Origen | Validación / regla |
|---|---|---|
| `payment_id` | Ruta (`api/v1/payments/%`) | Debe ser un entero > 0 (`ctype_digit`); si no → `404 payment_not_found` (mismo código que "no existe", igual criterio que `cancel`). |

No hay body ni query params.

### Nodo `pagos` (el nodo consultado)

| Campo | Rol | Validación |
|---|---|---|
| `nid` (ruta) | identificador | Debe existir y ser tipo `pagos`; si no → `404 payment_not_found`. |
| `field_estado_pago` (list_text) | filtro de visibilidad | Si es `"Nuevo"` (o no tiene fila) → `404 payment_not_found`, mismo código que "no existe" (reutiliza `MYAPI_PAYMENT_EXCLUDED_STATUS` ya definida en este archivo). |
| `field_vivienda` (entity ref → nodo) | precondición de acceso | Su `target_id` debe estar en `myapi_unit_related_nids($uid)` del usuario autenticado; si no → `403 unit_access_denied`. Si el pago no tiene `field_vivienda` → tratado como sin acceso (`403`), igual criterio que el resto del recurso. |

- El nodo **no se modifica**: no hay `node_save()`, es una lectura pura.

### Forma de respuesta (`200`)

Reutiliza `myapi_payment_build_created_item($node, $file, $bank_term)`, recargando `$file`/`$bank_term` igual que hace `myapi_payment_cancel()`:

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 87,
      "title": "Pago 000123 - 2026-07-09",
      "unit_id": 12,
      "payment_date": "2026-07-09T14:30:00",
      "status": "Pendiente de verificar",
      "payment_method": "Transferencia",
      "reference": "000123",
      "amount": 45.90,
      "bank_id": 7,
      "bank_name": "Banco Pichincha",
      "file_id": 55,
      "file_name": "000123.pdf",
      "detail": null
    }
  }
}
```

Sin `message` (respuesta de solo lectura).

---

## Plan de implementación

1. **Registrar la ruta en `myapi.module`** (`hook_menu()`):
   ```php
   $items['api/v1/payments/%'] = [
     'page callback'   => 'myapi_payment_detail_dispatch',
     'page arguments'  => [2],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/payment.resource.inc',
   ];
   ```
   La autenticación y las validaciones se resuelven dentro del recurso. Tras esto, `drush cc all`.

2. **`myapi_payment_detail_dispatch($payment_id)`** en `payment.resource.inc`:
   - `myapi_request_method()`; si `GET` → `myapi_payment_detail($payment_id)`; si no → `myapi_error('method_not_allowed', 405)`.

3. **`myapi_payment_detail($payment_id)`** — orquesta, en este orden:
   1. `$row = myapi_auth_require_access_token(); $uid = $row->uid;` (corta `401`).
   2. `$payment_id` de la ruta: si no es un entero > 0 (`ctype_digit`) → `myapi_error('payment_not_found', 404)`.
   3. `$node = node_load((int) $payment_id);` — si `!$node || $node->type !== 'pagos'` → `myapi_error('payment_not_found', 404)`.
   4. **Estado**: `$estado = myapi_payment_field_value($node, 'field_estado_pago');` si `$estado === MYAPI_PAYMENT_EXCLUDED_STATUS` (o no hay fila) → `myapi_error('payment_not_found', 404)`. (Aplica **antes** que el chequeo de acceso, mismo orden que el listado: la visibilidad por estado se resuelve primero.)
   5. **Acceso**: `$vivienda_nid = $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] ?? NULL;` si es `NULL` o no está en `myapi_unit_related_nids($uid)` → `myapi_error('unit_access_denied', 403)`.
   6. **Recargar `$file`/`$bank_term` para la respuesta**: si `$node->field_archivo` tiene `fid`, `$file = file_load($fid);`; si `$node->field_banco` tiene `tid`, `$term = taxonomy_term_load($tid);`. (Mismo patrón que `myapi_payment_cancel()`.)
   7. Responder `myapi_respond(['payment' => myapi_payment_build_created_item($node, $file, $term)], 200);` (sin `message_key`).

4. **Documentar en `docs/payment.md`**: nueva sección `GET /api/v1/payments/%` (auth requerida, sin headers de body, respuesta `200`, tabla de errores `401`/`403`/`404`/`405`, nota de que un pago en `"Nuevo"` responde `404` igual que uno inexistente).

5. **Aplicar y verificar** — `drush cc all` + pruebas manuales: consultar el detalle de un pago propio en distintos estados (`"Pendiente de verificar"`, `"Completado"`, `"Anulado"`) → `200`; consultar un pago en `"Nuevo"` → `404`; un pago ajeno → `403`; un `payment_id` inexistente → `404`; un método distinto de `GET` → `405`.

---

## Criterios de aceptación

**Éxito**
- [ ] `GET /api/v1/payments/{id}` con token válido y pago propio (propietario u ocupante de la vivienda) en cualquier estado distinto de `"Nuevo"` devuelve `200` con `{ "success": true, "data": { "payment": {...} } }`, sin `message`.
- [ ] La respuesta incluye exactamente las mismas claves que `myapi_payment_build_created_item()`: `id`, `title`, `unit_id`, `payment_date`, `status`, `payment_method`, `reference`, `amount`, `bank_id`, `bank_name`, `file_id`, `file_name`, `detail`.
- [ ] Un pago con archivo adjunto y banco devuelve `file_id`/`file_name` y `bank_id`/`bank_name` no nulos; uno en Efectivo sin banco devuelve `bank_id`/`bank_name` en `null` juntos; uno sin archivo devuelve `file_id`/`file_name` en `null` juntos.

**Estado y existencia**
- [ ] `payment_id` inexistente, o de un nodo que no es tipo `pagos` → `404 payment_not_found`.
- [ ] Un pago en estado `"Nuevo"` (o sin fila de estado) → `404 payment_not_found`, indistinguible de uno inexistente.

**Autenticación y acceso**
- [ ] Sin header `Authorization` → `401 missing_authorization`; con token inválido/expirado → `401 invalid_token`.
- [ ] `payment_id` de un pago cuya vivienda el usuario autenticado **no** posee ni ocupa → `403 unit_access_denied`.

**Método y no regresión**
- [ ] Cualquier método distinto de `GET` sobre `/api/v1/payments/{id}` (`POST`, `PUT`, `DELETE`) → `405 method_not_allowed`.
- [ ] `GET /api/v1/units/%/payments` (spec 14), `POST /api/v1/payments` (spec 20) y `PUT /api/v1/payments/%/cancel` (spec 23) siguen funcionando idénticos; no se modifica ninguna de sus rutas ni lógica.
- [ ] `docs/payment.md` incluye la sección `GET /api/v1/payments/%` completa; `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Ruta HTTP | `GET /api/v1/payments/%` (plana) | `GET /api/v1/units/%/payments/%` (anidada) | Elección del usuario: mismo patrón que `POST /api/v1/payments` (spec 20) y `PUT /api/v1/payments/%/cancel` (spec 23); no requiere que el cliente conozca el `unit_id` de antemano. |
| Pagos en estado `"Nuevo"` | Ocultos: responden `404 payment_not_found`, igual que uno inexistente | Mostrarlos igual que cualquier otro estado | Elección del usuario: consistente con el criterio de visibilidad del listado (spec 14), que ya excluye `"Nuevo"` con el mismo `MYAPI_PAYMENT_EXCLUDED_STATUS`. |
| Forma de respuesta | Reutiliza `myapi_payment_build_created_item()` (incluye `file_name`) | `myapi_payment_build_item()` (forma del listado, sin `file_name`) | Elección del usuario: una vista de detalle se beneficia de más campos; ya es la forma que usan `create`/`cancel`, evita mapear el nodo dos veces. |
| Criterio de pertenencia | Propietario u ocupante de la vivienda (`myapi_unit_related_nids`) | Solo el autor del pago (`node->uid`) | Elección del usuario: mismo criterio de acceso que `list`/`create`/`cancel`, cualquier propietario/ocupante de la unidad puede ver el detalle. |
| Orden de validaciones | auth (401) → existencia/tipo (404) → estado/visibilidad (404) → acceso (403) | Acceso antes que estado | Se valida primero si el recurso es "visible" (existe y no está en `"Nuevo"`) y después si el usuario tiene acceso a él; evita filtrar con un `403` la existencia de un pago que de todas formas estaría oculto por estado. |
| `payment_id` inexistente o de otro tipo o en `"Nuevo"` | `404 payment_not_found` (mismo código en los tres casos) | Distinguir cada caso con códigos separados | No hay necesidad de esa granularidad; los tres casos son "no hay un pago visible con ese id" desde la perspectiva del cliente, mismo criterio que `cancel` (spec 23) para "no existe"/"tipo incorrecto". |
| Mensaje de éxito (`message`) | Ninguno | Mensaje traducido tipo `payment_detail` | Endpoint de solo lectura; consistente con el listado (spec 14), que tampoco incluye `message`. |
| Claves i18n | Reutiliza `payment_not_found`, `unit_access_denied`, `method_not_allowed` (ya existentes desde spec 23) | Agregar claves nuevas específicas de detalle | No hay mensaje ni error nuevo que no esté ya cubierto por el catálogo existente. |
| Ubicación del código | Mismo `resources/payment.resource.inc` (dispatcher nuevo) | Nuevo archivo de recurso | Un recurso = un archivo (CLAUDE.md); el detalle es parte del recurso `payment`. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Colisión de rutas entre `api/v1/payments/%` y `api/v1/payments/%/cancel`.** Drupal 7 resuelve por especificidad de segmentos en `hook_menu()`, así que la ruta de 3 segmentos (`.../cancel`) no debería ser capturada por la de 2 segmentos, pero es un punto a verificar tras `drush cc all`. | Verificar en el paso de aplicación que `PUT /api/v1/payments/{id}/cancel` sigue respondiendo por su propio dispatcher y no por `myapi_payment_detail_dispatch()`; si Drupal los confundiera, el método (`PUT` vs `GET`) igual cortaría con `405` en el dispatcher equivocado, evitando una ejecución incorrecta silenciosa. |
| **`payment_id` numérico pero de otro tipo de contenido que también use `field_vivienda`.** Si un nid coincide con un nodo de otro bundle, el chequeo de tipo (`$node->type !== 'pagos'`) ya lo descarta antes de leer campos específicos de pago. | Ya cubierto por el orden de validaciones (tipo antes que estado/acceso), mismo criterio que `cancel` (spec 23). |
| **Exposición de más campos que el listado.** El detalle expone `file_name` (nombre real del archivo guardado), que el listado (spec 14) no expone. Un cliente que espere el mismo shape en ambos endpoints podría confundirse. | Documentado explícitamente en `docs/payment.md`: el detalle y el listado tienen formas de respuesta distintas (12 claves en el listado vs. 13 en el detalle, con `file_name` adicional), igual diferencia que ya existe entre el listado y `create`/`cancel`. |
