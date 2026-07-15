# 23 — Anular un pago (`PUT /api/v1/payments/%/cancel`)

- **Estado:** Implemented
- **Fecha:** 2026-07-14
- **Dependencias:**
  - `05-middleware-access-token-logout` (Implemented) — `myapi_auth_require_access_token()` que valida el Bearer access token y devuelve la fila (con `uid`), o corta con `401`.
  - `09-units-owner-occupant` (Implemented) — helper `myapi_unit_related_nids($uid)` para verificar que el usuario sea propietario u ocupante de la vivienda del pago.
  - `14-unit-payments-list` / `20-register-payment` (Implemented) — recurso `payment.resource.inc` que este endpoint amplía; reutiliza `field_estado_pago`, `field_vivienda`, `field_detalle` y la forma de respuesta del pago.
  - `22-verificar-pago-actualizar-saldos` (Implemented) — confirma que ningún saldo (vivienda/condominio) se mueve mientras el pago está en `"Pendiente de verificar"`; por eso anular en ese estado no requiere revertir saldos.
- **Objetivo:** Agregar `PUT /api/v1/payments/%/cancel`, un endpoint autenticado que anula un pago propio (propietario u ocupante de la vivienda asociada) reescribiendo `field_estado_pago` a `"Anulado"` **solo** cuando el estado actual es exactamente `"Pendiente de verificar"`, acepta opcionalmente un `reason` que se guarda en `field_detalle`, conserva el nodo y su archivo adjunto intactos, y responde `409 payment_not_pending` si el pago está en cualquier otro estado.

---

## Alcance

### Dentro de este spec

- **`resources/payment.resource.inc`** (modificar — recurso ya existente) — se agrega:
  - **Ruta de item nueva** `api/v1/payments/%/cancel`, con un dispatcher propio que enruta por método: solo `PUT` → `myapi_payment_cancel($payment_id)`; cualquier otro método → `myapi_error('method_not_allowed', 405)`.
  - **`myapi_payment_cancel($payment_id)`** — exige access token, carga el nodo `pagos`, valida que exista y sea tipo `pagos` (si no, `404`), valida que el usuario sea propietario u ocupante de la vivienda del pago (si no, `403`), valida que `field_estado_pago` sea exactamente `"Pendiente de verificar"` (si no, `409 payment_not_pending`), lee el `reason` opcional del body JSON, reescribe `field_estado_pago` a `"Anulado"` y `field_detalle` (si vino `reason`), guarda el nodo con `node_save()` y responde `200` con el pago actualizado.
  - **Helper de mapeo** reutilizado: `myapi_payment_build_created_item()` (ya existe, se usa igual para la respuesta del pago anulado).
- **`myapi.module`** (modificar) — registrar `api/v1/payments/%/cancel` en `hook_menu()` (`page arguments => [3]`, `access callback => TRUE`, `file => resources/payment.resource.inc`). La autenticación y las validaciones se resuelven dentro del recurso.
- **`includes/myapi.i18n.inc` / `docs/i18n.md`** (modificar) — nuevas claves: `payment_not_found`, `payment_not_pending`, `payment_cancelled` (mensaje de éxito), reutilizando `unit_access_denied` / `method_not_allowed` ya existentes.
- **`docs/payment.md`** (modificar) — agregar la sección `PUT /api/v1/payments/%/cancel` siguiendo la plantilla (auth requerida, headers JSON, body opcional `reason`, respuesta `200`, tabla de errores `401`/`403`/`404`/`405`/`409`).
- **`myapi.info`** — sin cambios: `resources/payment.resource.inc` ya está listado.

### Fuera de este spec

- **Anular un pago ya `"Completado"` o `"Nuevo"`** — cualquier estado que no sea exactamente `"Pendiente de verificar"` responde `409 payment_not_pending`; no hay reversión de saldos porque spec 22 ya garantiza que no se movieron mientras el pago estaba pendiente.
- **Borrar el nodo o el archivo adjunto** — el pago anulado conserva el nodo, el archivo (`field_archivo`) y su `file_usage` intactos (decisión: soft-cancel, no hard delete).
- **Reversar saldos de vivienda/condominio** — no aplica: nunca se aplicaron mientras el pago estaba `"Pendiente de verificar"`.
- **Cancelar tareas de `rules_scheduler`** — esas tareas (recordatorio/penalización) están atadas a la vivienda y se cancelan solo en la verificación exitosa (spec 22); anular un pago pendiente no las toca.
- **Reactivar / deshacer una anulación** — no hay endpoint para volver un pago `"Anulado"` a `"Pendiente de verificar"`; si hace falta, va en otro spec.
- **Validar que `"Anulado"` exista en los `allowed_values` del campo** — se asume ya configurado en Drupal (confirmado); este spec no toca la configuración del campo.
- **Anular pagos de otras viviendas por un administrador** — el control de acceso es solo propietario/ocupante, igual que el resto del recurso; un rol admin con permisos ampliados queda fuera.

---

## Modelo de datos

Este spec **no introduce tablas propias** (`myapi_*`), no hay `hook_schema()` ni cambios en `myapi.install`. Solo lee y escribe estructuras existentes de Drupal a través de la Field API / `node_load()` / `node_save()`.

### Entrada — body JSON (opcional)

| Campo | Oblig. | Tipo | Validación / regla |
|---|---|---|---|
| `reason` | No | string | Si viene: no vacío tras trim, `check_plain`, ≤ 255 (mismo límite que `field_detalle_value`, `varchar(255)`). Si excede 255 → `422 invalid_field` con `@field = reason`. Si no viene o es `""`/`null` → no se toca `field_detalle`. |

El `payment_id` (nid del pago) viaja por la ruta (`api/v1/payments/%/cancel`), no por el body.

### Nodo `pagos` (el nodo que se anula)

| Campo | Rol | Acción del endpoint |
|---|---|---|
| `nid` (ruta) | identificador | Debe existir y ser tipo `pagos`; si no → `404 payment_not_found`. |
| `field_vivienda` (entity ref → nodo) | precondición de acceso | Su `target_id` debe estar en `myapi_unit_related_nids($uid)` del usuario autenticado; si no → `403 unit_access_denied`. Si el pago no tiene `field_vivienda` → tratado como sin acceso (`403 unit_access_denied`), igual criterio que el resto del recurso. |
| `field_estado_pago` (list_text) | precondición de estado + resultado | Debe ser exactamente `"Pendiente de verificar"` para proceder; cualquier otro valor (incluida ausencia de fila) → `409 payment_not_pending`, sin modificar el nodo. Si pasa la validación, se reescribe a `"Anulado"`. |
| `field_detalle` (text, `varchar(255)`) | motivo (opcional) | Se reescribe con el `reason` saneado **solo si vino** en el body; si no vino, se conserva el valor existente sin tocarlo. |
| `field_archivo`, todos los demás campos | — | **Sin cambios.** El comprobante y el resto del pago quedan intactos (soft-cancel). |

- El nodo se guarda con `node_save($node)`; no se fuerza una nueva revisión explícita (no aplica el criterio de spec 22, que es sobre `vivienda`/`condominio`, no sobre el propio pago).

### Cambio compartido — `myapi_payment_build_created_item()`

Se agrega la clave `'detail'` a esta función (usada por `POST /api/v1/payments` y por este endpoint), leyendo `field_detalle` igual que `myapi_payment_build_item()` del listado:

```php
'detail' => isset($node->field_detalle[LANGUAGE_NONE][0]['value'])
  ? $node->field_detalle[LANGUAGE_NONE][0]['value']
  : NULL,
```

Esto también añade `detail` (siempre `null`, ya que la creación nunca lo setea) a la respuesta `201` de `POST /api/v1/payments`, quedando consistente con el listado.

### Forma de respuesta (`200`)

Reutiliza `myapi_payment_build_created_item($node, $file, $bank_term)`, recargando `$file`/`$bank_term` si el pago los tiene:

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 87,
      "title": "Pago 000123 - 2026-07-09",
      "unit_id": 12,
      "payment_date": "2026-07-09T14:30:00",
      "status": "Anulado",
      "payment_method": "Transferencia",
      "reference": "000123",
      "amount": 45.90,
      "bank_id": 7,
      "bank_name": "Banco Pichincha",
      "file_id": 55,
      "file_name": "000123.pdf",
      "detail": "Comprobante duplicado"
    }
  },
  "message": "Pago anulado correctamente."
}
```

---

## Plan de implementación

1. **Constantes de estado compartidas** en `includes/myapi.payment_workflow.inc` — agregar junto a las existentes:
   ```php
   define('MYAPI_PAYMENT_STATUS_CANCELLED', 'Anulado');
   ```
   Reutiliza `MYAPI_PAYMENT_STATUS_PENDING` (ya definida ahí) en vez de repetir el literal `"Pendiente de verificar"`. Tras esto, `drush cc all`. Estado funcional: sin cambios de comportamiento.

2. **Registrar la ruta en `myapi.module`** (`hook_menu()`):
   ```php
   $items['api/v1/payments/%/cancel'] = [
     'page callback'   => 'myapi_payment_cancel_dispatch',
     'page arguments'  => [3],
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/payment.resource.inc',
   ];
   ```
   La autenticación y las validaciones se resuelven dentro del recurso. Tras esto, `drush cc all`.

3. **`myapi_payment_cancel_dispatch($payment_id)`** en `payment.resource.inc`:
   - `myapi_request_method()`; si `PUT` → `myapi_payment_cancel($payment_id)`; si no → `myapi_error('method_not_allowed', 405)`.
   - Al inicio del archivo, agregar `module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');` para tener acceso a `MYAPI_PAYMENT_STATUS_PENDING`, `MYAPI_PAYMENT_STATUS_CANCELLED` y `myapi_payment_field_value()`.

4. **`myapi_payment_cancel($payment_id)`** — orquesta, en este orden (cada validación corta con su error antes de modificar nada):
   1. `$row = myapi_auth_require_access_token(); $uid = $row->uid;` (corta `401`).
   2. `$payment_id` de la ruta: si no es un entero > 0 → `404 payment_not_found` (mismo código que "no existe", para no filtrar si el nid es inválido vs. inexistente).
   3. `$node = node_load((int) $payment_id);` — si `!$node || $node->type !== 'pagos'` → `myapi_error('payment_not_found', 404)`.
   4. **Acceso**: `$vivienda_nid = $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] ?? NULL;` si es `NULL` o no está en `myapi_unit_related_nids($uid)` → `myapi_error('unit_access_denied', 403)`.
   5. **Estado**: `$estado = myapi_payment_field_value($node, 'field_estado_pago');` si `$estado !== MYAPI_PAYMENT_STATUS_PENDING` → `myapi_error('payment_not_pending', 409)`.
   6. **`reason` opcional**: leer del body JSON con el helper existente de lectura de JSON (`includes/myapi.request.inc`); si viene, `trim()` y, si queda no vacío, validar `strlen(...) <= 255` (si excede → `422 invalid_field` con `@field = 'reason'`) y `check_plain()`. Si no viene o queda vacío tras `trim()` → no tocar `field_detalle`.
   7. **Aplicar la anulación**:
      ```php
      $node->field_estado_pago[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_STATUS_CANCELLED;
      if ($reason !== NULL) {
        $node->field_detalle[LANGUAGE_NONE][0]['value'] = $reason;
      }
      node_save($node);
      ```
   8. **Recargar `$file`/`$bank_term` para la respuesta**: si `$node->field_archivo` tiene `fid`, `$file = file_load($fid);`; si `$node->field_banco` tiene `tid`, `$term = taxonomy_term_load($tid);`. (Igual patrón que ya usa `myapi_payment_build_created_item()`.)
   9. Responder `myapi_respond(['payment' => myapi_payment_build_created_item($node, $file, $term)], 200, 'payment_cancelled');`.

5. **Agregar `detail` a `myapi_payment_build_created_item()`** (afecta también el `201` de `POST /api/v1/payments`, que pasará a incluir `detail` siempre en `null`):
   ```php
   'detail' => isset($node->field_detalle[LANGUAGE_NONE][0]['value'])
     ? $node->field_detalle[LANGUAGE_NONE][0]['value']
     : NULL,
   ```

6. **Catálogo i18n** (`includes/myapi.i18n.inc` / `docs/i18n.md`): agregar `payment_not_found` (404), `payment_not_pending` (409), `payment_cancelled` (mensaje de éxito), en `es`/`en`.

7. **Documentar en `docs/payment.md`**: nueva sección `PUT /api/v1/payments/%/cancel` (auth requerida, `Content-Type: application/json`, body opcional `{ "reason": "..." }`, respuesta `200`, tabla de errores `401`/`403`/`404`/`405`/`409`/`422`); y actualizar la sección existente de `POST /api/v1/payments` para reflejar el nuevo campo `detail` en la respuesta `201`.

8. **Aplicar y verificar** — `drush cc all` + pruebas manuales: registrar un pago (spec 20, queda `"Pendiente de verificar"`), anularlo con y sin `reason`, verificar en BD que quedó `"Anulado"` y (si vino `reason`) `field_detalle` actualizado; luego intentar anular el mismo pago de nuevo (`409`), anular un pago ajeno (`403`), y un `payment_id` inexistente (`404`).

---

## Criterios de aceptación

**Éxito**
- [x] `PUT /api/v1/payments/{id}/cancel` con token válido, pago propio en estado `"Pendiente de verificar"`, **sin** `reason` en el body devuelve `200` con `{ "success": true, "data": { "payment": {...} }, "message": ... }`; `payment.status` = `"Anulado"` y `payment.detail` queda como estaba antes (probablemente `null`). *(Verificado por revisión de código: `myapi_payment_cancel()` deja `field_detalle` intacto cuando `reason` está ausente. Pendiente confirmar con una llamada HTTP real.)*
- [x] El mismo caso **con** `{ "reason": "Comprobante duplicado" }` devuelve `200`, `payment.status` = `"Anulado"` y `payment.detail` = `"Comprobante duplicado"`. *(Verificado por revisión de código; pendiente confirmar con una llamada HTTP real.)*
- [x] Tras anular, el nodo en BD tiene `field_estado_pago` = `"Anulado"`; `field_archivo` (si había) y el resto de campos quedan intactos. *(Verificado por revisión de código: ningún otro campo se escribe. Pendiente confirmar contra la BD real.)*
- [x] El archivo adjunto (si existía) conserva su fila en `file_usage`; no se borra ni se desvincula. *(Verificado por revisión de código: no hay llamada a `file_usage_delete()`/`file_delete()` en `myapi_payment_cancel()`. Pendiente confirmar contra la BD real.)*
- [x] `GET /api/v1/units/%/payments` (spec 14) muestra el pago anulado con `status = "Anulado"` (no queda oculto, ya que el filtro de esa lista excluye solo `"Nuevo"`). *(Verificado por revisión de código: `MYAPI_PAYMENT_EXCLUDED_STATUS` sigue siendo `'Nuevo'`, no modificado por este spec. Pendiente confirmar con una llamada HTTP real.)*

**`reason` inválido**
- [x] `reason` de más de 255 caracteres → `422 invalid_field` con `@field = "reason"`; el pago **no** se anula (conserva su estado y `field_detalle` previos). *(Verificado por revisión de código: el chequeo de longitud corre antes de tocar `field_estado_pago`/`node_save()`.)*
- [x] `reason` vacío (`""`) o solo espacios → se trata como ausente: el pago se anula igual pero `field_detalle` no se toca. *(Verificado por revisión de código: `trim()` + comparación `!== ''`.)*

**Autenticación y acceso**
- [x] Sin header `Authorization` → `401 missing_authorization`; con token inválido/expirado → `401 invalid_token`. *(Verificado por revisión de código: reutiliza `myapi_auth_require_access_token()` sin modificar, ya validado por spec 05.)*
- [x] `payment_id` de un pago cuya vivienda el usuario autenticado **no** posee ni ocupa → `403 unit_access_denied`, y el pago no se modifica. *(Verificado por revisión de código: el chequeo de acceso corre antes de cualquier escritura.)*

**Estado y existencia**
- [x] `payment_id` inexistente, o de un nodo que no es tipo `pagos` → `404 payment_not_found`. *(Verificado por revisión de código: `ctype_digit`/`node_load`/chequeo de `type` corren antes de acceso y estado, ambos casos con el mismo código de error.)*
- [x] Un pago en `"Nuevo"`, `"Completado"` o ya `"Anulado"` → `409 payment_not_pending`, sin modificar el nodo (idempotencia: anular dos veces el mismo pago falla la segunda vez). *(Verificado por revisión de código: la comparación exige `=== MYAPI_PAYMENT_STATUS_PENDING` antes de escribir; una segunda llamada ve `"Anulado"` y corta.)*

**Método y no regresión**
- [x] Cualquier método distinto de `PUT` sobre `/api/v1/payments/{id}/cancel` (`GET`, `POST`, `DELETE`) → `405 method_not_allowed`. *(Verificado por revisión de código: `myapi_payment_cancel_dispatch()` solo acepta `PUT`.)*
- [x] `POST /api/v1/payments` (spec 20) sigue funcionando igual; su respuesta `201` ahora incluye también la clave `detail` (siempre `null`, ya que la creación nunca la setea). *(Verificado por revisión de código: `myapi_payment_create()` no se tocó; solo se agregó la clave `detail` en `myapi_payment_build_created_item()`, compartida por ambos endpoints.)*
- [x] `GET /api/v1/units/%/payments` (spec 14) y el `hook_node_presave` de verificación (spec 22) siguen funcionando idénticos; no se modifica ninguna de sus rutas ni lógica. *(Verificado por revisión de código: ningún archivo de esos flujos fue tocado excepto la constante nueva agregada en `myapi.payment_workflow.inc`, que no altera las existentes.)*
- [x] Todas las claves de error/éxito nuevas (`payment_not_found`, `payment_not_pending`, `payment_cancelled`, y `invalid_field` reutilizada con `@field = reason`) están en el catálogo i18n y traducen en `es`/`en`. *(Verificado por revisión de código: las tres claves nuevas están en `includes/myapi.i18n.inc` en ambos idiomas; `invalid_field` ya existía.)*
- [ ] `docs/payment.md` incluye la sección `PUT /api/v1/payments/%/cancel` completa y refleja el nuevo `detail` en la respuesta `201` de creación; `drush cc all` no reporta errores. *(Parcial: la doc está escrita y verificada. `drush cc all` **no** se pudo ejecutar en este entorno — `drush` no está disponible aquí. Pendiente correrlo en tu entorno con Drupal.)*

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Método y ruta HTTP | `PUT /api/v1/payments/%/cancel` | `DELETE /api/v1/payments/%` / `PUT /api/v1/payments/%` genérico | Elección del usuario: ruta de acción explícita, autodescriptiva, y deja `DELETE` libre para un futuro borrado real sin ambigüedad semántica. |
| Efecto sobre el nodo | Soft-cancel: reescribir `field_estado_pago` a `"Anulado"`, conservando el nodo | `node_delete()` del pago | Elección del usuario: consistente con el patrón de máquina de estados de la spec 22 y conserva auditoría/historial del comprobante. |
| Estado final | `"Anulado"` (allowed_value ya configurado en Drupal, confirmado por el usuario) | `"Rechazado"` / `"Cancelado"` | Coincide literalmente con el verbo "anular" del pedido original. |
| Reversión de saldos | Ninguna: no se tocan `field_saldo_actual`/`field_saldo_caja` | Revertir algún movimiento | La spec 22 solo aplica saldos en la transición de **verificación**; un pago `"Pendiente de verificar"` nunca los movió, así que no hay nada que revertir. |
| Tareas `rules_scheduler` | No se tocan | Cancelar recordatorios/penalizaciones de la vivienda | Esas tareas se cancelan solo en la verificación exitosa (spec 22); anular un pago pendiente no interactúa con ellas. |
| Pertenencia del pago | Propietario u ocupante de la vivienda asociada (`myapi_unit_related_nids`) | Solo el autor del nodo (`node->uid`) | Elección del usuario: mismo criterio de acceso que `create`/`list` de pagos, permitiendo que cualquier propietario/ocupante de la unidad anule, no solo quien lo registró. |
| Archivo adjunto | Se conserva intacto (`field_archivo`, `file_usage`) | Borrarlo al anular | Elección del usuario: consistente con el soft-cancel; el comprobante enviado queda como evidencia. |
| Motivo de anulación | Campo opcional `reason` en el body, persistido en `field_detalle` | Sin motivo / motivo no persistido (solo eco en la respuesta) | Elección del usuario: reutiliza `field_detalle`, ya existente en el bundle `pagos` (spec 20 lo marcaba como ignorado en creación), evitando un campo nuevo. |
| Exposición de `detail` en la respuesta | Se agrega a `myapi_payment_build_created_item()`, compartida con el `201` de creación | Forma de respuesta separada solo para `cancel` | Elección del usuario: evita duplicar el mapeo del pago en dos funciones casi idénticas; el costo es que `POST /api/v1/payments` ahora también expone `detail` (siempre `null` en creación). |
| Código de error por estado inválido | `409 payment_not_pending` | `422 invalid_payment_status` | Elección del usuario: `409` es semánticamente conflicto de estado del recurso, mismo criterio que `409 duplicate_reference` de spec 20. |
| `payment_id` inexistente o de otro tipo | `404 payment_not_found` (mismo código en ambos casos) | Distinguir "no existe" de "no es un pago" | No hay necesidad de esa granularidad; ambos casos son "no hay un pago con ese id" desde la perspectiva del cliente. |
| Orden de validaciones | auth (401) → existencia/tipo (404) → acceso (403) → estado (409) → `reason` (422) → aplicar | Validar `reason` antes que estado/acceso | Sigue el mismo orden que el resto del módulo: primero se determina si el recurso es visible/accesible, después si la operación es válida en su estado, y por último se valida el input adicional. |
| Ubicación del código | Mismo `resources/payment.resource.inc` (dispatcher nuevo) | Nuevo archivo de recurso | Un recurso = un archivo (CLAUDE.md); cancelar un pago es parte del recurso `payment`. |
| Constantes de estado | Reutilizar/ampliar `includes/myapi.payment_workflow.inc` (`MYAPI_PAYMENT_STATUS_PENDING`, nueva `MYAPI_PAYMENT_STATUS_CANCELLED`) | Redefinir el literal `"Pendiente de verificar"` en `payment.resource.inc` | Evita duplicar el string mágico; ese archivo ya es la fuente de verdad de los valores de `field_estado_pago`. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`"Anulado"` no configurado realmente en `allowed_values` de `field_estado_pago`.** El usuario confirmó que ya existe, pero si el Field API rechaza el valor en `node_save()`, la anulación fallaría con un error de Drupal no controlado. | Documentar en `docs/payment.md` que `"Anulado"` es una precondición de despliegue. Si `node_save()` lanza una excepción de validación de campo, se deja como riesgo residual aceptado (no se envuelve en un `try/catch` genérico que oculte el error real). |
| **Condición de carrera en doble anulación.** Dos `PUT /cancel` casi simultáneos sobre el mismo pago podrían leer `"Pendiente de verificar"` ambos antes de que el primero guarde. | Aceptado, mismo criterio que la deduplicación de referencia en spec 20 y la guarda de spec 22: sin lock a nivel BD; riesgo residual mínimo (ambas peticiones anularían el pago con el mismo resultado final, sin inconsistencia de datos, solo una posible sobrescritura de `reason` si difieren). |
| **`field_detalle` sobrescrito pierde contenido previo.** Si en el futuro `field_detalle` llegara a usarse para otra cosa antes de anular, el `reason` lo pisaría sin aviso. | Aceptado: hoy `field_detalle` no se setea en ningún flujo existente (spec 20 lo ignora explícitamente en creación), así que en la práctica siempre está vacío al anular. Si eso cambia, se revisita en el spec que introduzca ese uso. |
| **Dependencia cruzada entre `payment.resource.inc` e `includes/myapi.payment_workflow.inc`.** El recurso ahora hace `module_load_include()` de un archivo pensado originalmente para el hook de verificación. | Es carga explícita de constantes/helpers ya existentes (`MYAPI_PAYMENT_STATUS_PENDING`, `myapi_payment_field_value()`), no lógica de negocio duplicada; coherente con "shared helpers reused everywhere" de `CLAUDE.md`. Si el acoplamiento crece, esas constantes se pueden mover a un archivo de constantes compartido en un ajuste puntual. |
| **`detail` nuevo en la respuesta `201` de `POST /api/v1/payments`.** Un cliente Flutter existente que valide estrictamente el shape de la respuesta podría no esperar la clave nueva. | Añadir una clave nueva es un cambio aditivo y no rompe clientes que ignoren claves desconocidas (patrón JSON estándar); se documenta en `docs/payment.md` en el mismo commit. |
| **Drupal 7 EOL — input no confiable.** `reason` viaja en el body JSON y se persiste. | `check_plain()` sobre el valor, límite de 255 validado antes de escribir, sin SQL crudo (Field API / `node_save()`). |
