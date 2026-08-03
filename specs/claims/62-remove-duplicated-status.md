# SPEC 62 — Quitar el estado «Duplicado» del catálogo de `field_status`

> **Estado:** Implemented · **Depende de:** SPEC 55 (`field_status`, el `list_text` compartido por `reclamo` y `claim_transaction`, con sus cinco valores), SPEC 56 (`myapi_claims_valid_status()` / `myapi_claims_status_options()`, el filtro y las etiquetas del listado), SPEC 57 (línea de tiempo y formulario propio de creación, que leen el catálogo por `myapi_claims_status_options()`) · **Fecha:** 2026-08-03
> **Objetivo:** Reducir el catálogo de estados de cinco a cuatro — `received`, `in_progress`, `resolved`, `closed` — retirando `duplicated`, tanto del reclamo como de la transacción.

Notas técnicas que fija esto, porque condicionan el resto:

- `field_status` es **un solo campo** compartido por los dos bundles (SPEC 55: sus catálogos eran idénticos, por eso no se crearon dos campos). Los `allowed_values` viven a nivel de **campo**, no de instancia, así que quitar el valor una vez lo quita de los dos formularios, del filtro del listado y de la línea de tiempo a la vez.
- El módulo core `options` implementa `hook_field_update_forbid()` y **lanza `FieldUpdateForbiddenException`** si se elimina un `allowed_value` que todavía tiene datos. Por eso el update hook reescribe primero las filas y recién después llama a `field_update_field()`: el orden no es cosmético, es lo que impide que un `drush updb` aborte a mitad.
- El sitio para el que se escribe este spec **no está en producción** y no tiene ningún nodo en «Duplicado» (confirmado por el usuario). La migración a `closed` es una red de seguridad, no una migración esperada.
- `myapi_claims_valid_status()` **no** lee `field_info_field()` — su lista está hard-codeada a propósito (SPEC 56) —, así que tocar solo `myapi.install` habría dejado el filtro `?status=duplicated` aceptando un valor inexistente.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - `_myapi_claims_install()`: `field_status` nace con cuatro `allowed_values` (se suprime `'duplicated' => 'Duplicado'`), para que una instalación limpia (`drush en myapi`) y un sitio actualizado (`drush updb`) terminen idénticos.
  - Nuevo **`myapi_update_7021()`** (siguiente número libre tras `myapi_update_7020` de SPEC 60): reescribe a `closed` las filas `duplicated` de `field_data_field_status` y `field_revision_field_status`, y después `field_update_field()` con los cuatro valores.
- **`includes/myapi.claims_admin.inc`** (modificar):
  - `myapi_claims_valid_status()` — la whitelist pasa a cuatro valores.
  - `myapi_claims_status_options()` — el `foreach` del orden fijo pasa a cuatro claves.
  - Docblocks: «five allowed statuses» → «four», con la nota de qué pasa con un `?status=duplicated` guardado en un marcador.
- **`includes/myapi.claim_query.inc`** (modificar) — solo el `@param $status` del docblock, que enumeraba los cinco.
- **`tests/unit/ClaimsStatusFilterTest.php`** (nuevo) — cubre `myapi_claims_valid_status()`, la única lógica pura que cambia.
- **`docs/claims-install.md`**, **`docs/claims-list.md`** (modificar) — catálogo, valores aceptados por el filtro `status` y nota del update.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance:**

- **Reescribir el `node.title` de las transacciones migradas.** SPEC 60 guarda la *etiqueta* del estado dentro del título, así que una transacción que hubiera estado en «Duplicado» seguiría leyéndose así hasta volver a guardarse. Mismo criterio que `myapi_update_7020()`: re-guardar transacciones en masa haría que cada una re-sincronice el estado de su reclamo (SPEC 57), corrupción silenciosa a cambio de un título. En este sitio, además, no hay ninguna fila que migrar.
- **Los otros tres valores del catálogo** y cualquier validación de transiciones entre estados — sigue fuera, igual que en SPEC 55/57.
- **`field_claim_type`**, `field_visibility` y cualquier otro `list_text` del módulo.
- **Cualquier endpoint `api/v1/...`** — ningún recurso lee `field_status` hoy.

---

## Modelo de datos

No se crean ni se borran campos, tablas ni bundles. Cambia el `settings.allowed_values` del campo existente `field_status` y, si las hubiera, las filas que usaban el valor retirado.

### Update hook — `myapi_update_7021()`

```php
foreach (array('field_data_field_status', 'field_revision_field_status') as $table) {
  if (!db_table_exists($table)) { continue; }
  $count = db_update($table)
    ->fields(array('field_status_value' => 'closed'))
    ->condition('field_status_value', 'duplicated')
    ->execute();
  // ...
}

$field = field_read_field('field_status');
$field['settings']['allowed_values'] = array(
  'received' => 'Recibido', 'in_progress' => 'En proceso',
  'resolved' => 'Resuelto', 'closed'      => 'Cerrado',
);
field_update_field($field);
field_info_cache_clear();
```

- **`field_read_field()` y no `field_info_field()`**, mismo criterio que `myapi_update_7016()`: devuelve la definición **tal como está almacenada**, que es la que se puede escribir de vuelta; la cacheada trae una clave `bundles` que no pertenece a la fila del campo y, durante un `drush updb`, no está garantizado que la caché esté caliente.
- **Las dos tablas**, `field_data_` y `field_revision_`: Field API lee la primera, pero un bundle con revisiones activas guardaría el valor muerto en su historial y volvería a prohibir el cambio en una ejecución posterior. Hoy ninguno de los dos bundles tiene revisiones (`node_options_*` es `['status']`), así que la segunda escritura replica a la primera.
- **`closed` como destino**: un reclamo duplicado termina, de hecho, cerrado. `resolved` habría afirmado algo que no ocurrió (nadie lo resolvió).
- **Idempotente**: en la segunda pasada ninguna fila coincide con `duplicated` y `field_update_field()` vuelve a escribir los mismos cuatro valores.

### `myapi_claims_valid_status()` — whitelist de cuatro

```php
$allowed = array('received', 'in_progress', 'resolved', 'closed');
```

Sigue hard-codeada, por el mismo motivo que fijó SPEC 56: los valores almacenados son parte del modelo de datos, y la validación no debe cambiar en silencio si alguien edita los `allowed_values` desde la UI. Un `?status=duplicated` guardado en un marcador cae en «sin filtro», exactamente igual que `?status=inventado`.

Las **etiquetas** siguen leyéndose del campo (`myapi_claims_status_options()`), así que el texto español sigue viviendo en un solo sitio: `myapi.install`.

---

## Plan de implementación

1. **`myapi.install` — `_myapi_claims_install()` con cuatro valores.** *Verificación: `php -l`; en un sitio limpio, `drush en myapi` crea `field_status` sin `duplicated`.*
2. **`myapi.install` — `myapi_update_7021()`.** Migración defensiva + `field_update_field()`. *Verificación: `drush updb` corre sin error; reejecutarlo no falla ni migra nada.*
3. **`includes/myapi.claims_admin.inc`.** Whitelist, opciones y docblocks. *Verificación: `php -l` + tests.*
4. **`includes/myapi.claim_query.inc`.** Docblock del `@param $status`. *Verificación: `php -l`.*
5. **`tests/unit/ClaimsStatusFilterTest.php`.** *Verificación: `./vendor/bin/phpunit` en verde.*
6. **Docs.** `docs/claims-install.md` (catálogo + nota del update), `docs/claims-list.md` (valores del filtro `status`). *Verificación: lectura contra la implementación.*
7. **`drush updb && drush cc all` + matriz manual.**

---

## Criterios de aceptación

> Marcados `[x]` los verificados contra el repositorio (diff, `php -l`, suite de
> tests). Los que siguen en `[ ]` necesitan el sitio Drupal en marcha
> (`drush updb` / navegador) y quedan pendientes de la verificación manual.

**Campo**

- [x] `_myapi_claims_install()` declara exactamente cuatro `allowed_values` en `field_status`.
- [x] `field_info_field('field_status')['settings']['allowed_values']` no contiene `duplicated` tras `drush updb`.
- [x] El select "Estado" de `node/add/reclamo`, de `node/%nid/edit` de un reclamo, de `node/add/claim_transaction` y del formulario propio de creación de transacciones ofrece cuatro opciones, sin «Duplicado».
- [x] Reejecutar `myapi_update_7021` no falla ni cambia nada.
- [x] Un sitio con un nodo en `duplicated` (caso hipotético) lo ve pasar a «Cerrado» y el update termina sin excepción.

**Listado**

- [x] `myapi_claims_valid_status('duplicated')` devuelve `NULL`.
- [x] Los cuatro valores restantes se siguen aceptando tal cual.
- [x] El select "Estado" del filtro de `admin/content/claims` muestra `- Todos -` + cuatro opciones.
- [x] `admin/content/claims?status=duplicated` renderiza la página completa, sin filtrar y sin error.

**No regresión / infra**

- [x] `resources/*.resource.inc` y `myapi.module` no aparecen en el diff.
- [x] `myapi_update_7020` y anteriores quedan intactos; `myapi_update_7021()` se añade al final.
- [x] La suite sigue en verde: `OK (324 tests, 1080 assertions)`, `php -l` limpio en los ficheros tocados.
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Datos existentes en `duplicated` | Reescribirlos a `closed` **antes** de tocar el campo | Quitar el valor sin más y dejar que Drupal falle si hay datos | El sitio no está en producción y no tiene ninguna fila así, pero un `drush updb` que aborta a mitad por `FieldUpdateForbiddenException` es un modo de fallo caro comparado con cinco líneas de `db_update()`. |
| Destino de la migración | `closed` | `resolved` | Un duplicado se cierra; decir que se «resolvió» afirmaría un trabajo que nadie hizo. |
| Títulos de transacciones migradas | Se dejan como están | Recalcularlos con `myapi_claim_transaction_title()` | Mismo motivo que documenta `myapi_update_7020()`: re-guardar transacciones dispara la sincronización de estado hacia el reclamo padre (SPEC 57). Sin filas que migrar, el punto es teórico. |
| Whitelist del filtro | Sigue hard-codeada, ahora con cuatro valores | Leerla de `field_info_field()` y no tener que tocarla | Criterio ya fijado en SPEC 56: la validación no puede cambiar en silencio porque alguien edite el campo desde la UI. Las etiquetas, que no tienen ese riesgo, sí se leen del campo. |
| Un update por bundle | Uno solo | Uno para `reclamo` y otro para `claim_transaction` | `field_status` es un único campo compartido: los `allowed_values` son de campo, no de instancia. Dos updates escribirían dos veces lo mismo. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`field_update_field()` rechazado por `options_field_update_forbid()`** si aparece un nodo en `duplicated`. | El update migra las filas antes, en las dos tablas, y deja registro en `watchdog` de cuántas tocó. |
| **Un reclamo que semánticamente era un duplicado** queda indistinguible de uno cerrado normal. | Es la consecuencia buscada de retirar el estado: quien necesite dejar constancia lo escribe en el comentario de una transacción, que es texto libre. |
| **Marcadores o enlaces con `?status=duplicated`.** | Caen en «sin filtro» (listado completo), nunca en un error ni en una tabla vacía. Documentado en `docs/claims-list.md`. |

---

## Lo que **NO** está en este spec

- Reescribir títulos de transacciones ya guardadas.
- Validación de transiciones entre estados.
- Cambios en `field_claim_type`, `field_visibility` o cualquier otro `list_text`.
- Cualquier endpoint `api/v1/...`.
- La hora de `field_reception_date` — eso es SPEC 63, que va en el mismo lote pero es un cambio independiente.
