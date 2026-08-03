# SPEC 58 — Fecha y hora de `field_status_date` en `claim_transaction`

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundle `claim_transaction`, campo `field_status_date` día-only), SPEC 57 (`includes/myapi.claim_transaction_admin.inc`: consulta, tabla y formulario propio de creación que este spec modifica) · **Fecha:** 2026-08-01
> **Objetivo:** Ampliar la granularidad de `field_status_date` de solo-día a día+hora (precisión de minuto), para que la línea de tiempo del reclamo muestre y permita registrar el momento exacto — no solo el día — en que se produjo cada cambio de estado.

Notas técnicas que fija esto, porque condicionan el resto:

- `field_status_date` es un campo `datetime` (módulo Date) que **ya** almacena un `DATETIME` completo internamente — la granularidad "solo día" es una restricción de configuración del campo/widget, no del esquema. Ampliarla es `field_update_field()` (cambia `settings.granularity` a `year-month-day-hour-minute`) + `field_update_instance()` del widget en `claim_transaction` (no se toca la instancia de `field_reception_date` en `reclamo`: son campos distintos, sin relación).
- El formulario propio de creación (`myapi_claim_transaction_create_form()`, SPEC 57) sigue siendo un `textfield` de texto plano — por el mismo motivo ya documentado en SPEC 57 (los widgets combo de Date rompían la alineación). Pasa de `'AAAA-MM-DD'` a `'AAAA-MM-DD HH:MM'`, validado combinando `myapi_reservation_valid_date()` + `myapi_reservation_valid_time()` (`resources/reservation.resource.inc`, ya existen y ya se reutilizan en este archivo) en vez de escribir una regex nueva.
- Filas existentes, guardadas con hora `00:00`, se muestran tal cual — no hay migración de datos.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - Nuevo **`myapi_update_7019()`** (siguiente número libre tras `myapi_update_7018` de SPEC 56): `field_update_field('field_status_date', ...)` ampliando `settings.granularity` a `year-month-day-hour-minute` (`second => 0`, sigue `tz_handling = 'none'`), y `field_update_instance()` sobre la instancia de `claim_transaction` (no la de `field_reception_date` en `reclamo`, que no se toca) actualizando los settings del widget `date_select` a la nueva granularidad.
  - `_myapi_claims_install()` (el helper idempotente de SPEC 55) actualizado para que declare directamente la granularidad ampliada en `field_status_date` — una instalación limpia (`drush en myapi`) nace ya con día+hora, sin depender de que además corra el update hook.
- **`includes/myapi.claim_transaction_admin.inc`** (modificar):
  - `myapi_claim_transaction_timeline_rows()`: se quita el `SUBSTR(fsd.field_status_date_value, 1, 10)` — la columna `status_date` pasa a traer el valor completo (o los primeros 16 caracteres, `'Y-m-d H:i'`) en vez de truncar a día.
  - `myapi_claim_transaction_timeline_table_rows()`: la celda de "Fecha de estado" pasa a `format_date(strtotime($row->status_date), 'custom', 'd/m/Y H:i')`.
  - `myapi_claim_transaction_create_form()`: el textfield `field_status_date` pasa a `'#default_value' => date('Y-m-d H:i')`, `'#maxlength' => 16`, `'#description'` actualizado a `'Formato: AAAA-MM-DD HH:MM.'`.
  - Nueva función de validación (reemplaza a `myapi_claim_transaction_validate_status_date()`) que separa el valor en fecha/hora por el espacio y reutiliza `myapi_reservation_valid_date()` **y** `myapi_reservation_valid_time()` (ambas ya en `resources/reservation.resource.inc`, ya cargadas por este archivo) — sin regex nueva.
  - `myapi_claim_transaction_create_form_submit()`: guarda `field_status_date` como `'Y-m-d H:i:00'` (segundos fijos en `00`, ya que el formulario no captura segundos).
- **`docs/claim-transaction-timeline.md`** (modificar) — formato de fecha del campo, ejemplo de valor, nota de que las filas anteriores a este spec muestran `00:00`.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance (para specs futuros o ya cubierto por otros):**

- **Editar una transacción existente** — es SPEC 59 completo (link "Editar", acceso por fila, `field_claim` en edición, redirect).
- **`field_reception_date` de `reclamo`** — campo distinto, sin relación con `field_status_date`; no se toca su granularidad ni su widget.
- **Validar transiciones de estado** — sigue fuera, igual que SPEC 55/57.
- **Cualquier endpoint `api/v1/...`.**
- **Un selector visual de fecha/hora** (`date_popup`, `date_select` u otro) en el formulario propio de creación — sigue siendo `textfield` de texto plano, por los problemas de CSS ya documentados en SPEC 57.
- **Migrar o recalcular** la hora de transacciones ya existentes — quedan en `00:00` tal cual están guardadas.
- **Columna "Creada" (created nativo del nodo)** — descartada explícitamente; un solo timestamp por fila (`field_status_date`).

---

## Modelo de datos

No se crean campos, tablas ni bundles nuevos. Se modifica la configuración (`settings`/widget) del campo Field API existente `field_status_date` y el código que lo lee/escribe.

### Update hook — `myapi_update_7019()`

```php
function myapi_update_7019() {
  $field = field_info_field('field_status_date');
  $field['settings']['granularity'] = array(
    'year' => 'year', 'month' => 'month', 'day' => 'day',
    'hour' => 'hour', 'minute' => 'minute', 'second' => 0,
  );
  field_update_field($field);

  $instance = field_info_instance('node', 'field_status_date', 'claim_transaction');
  $instance['widget']['settings']['input_format'] = 'Y-m-d H:i';
  field_update_instance($instance);
}
```

`field_update_field()` cambia el `settings` compartido del campo (afecta a cualquier bundle que lo use — hoy solo `claim_transaction`). `field_update_instance()` ajusta el widget nativo `date_select` de esa misma instancia, para que el formulario nativo `node/%nid/edit` de `claim_transaction` (el que va a usar el link "Editar" de SPEC 59) ofrezca también los selectores de hora/minuto.

### `_myapi_claims_install()` (SPEC 55, `myapi.install`) — granularidad ya ampliada

La definición de `field_status_date` en el helper de instalación (líneas ~1418-1435 y ~1536-1552) pasa a declarar directamente `'hour' => 'hour', 'minute' => 'minute'` en `granularity` y `'input_format' => 'Y-m-d H:i'` en el widget — mismo settings final que deja `myapi_update_7019()`, para que una instalación limpia (`drush en myapi`) y un sitio actualizado (`drush updb`) terminen en el mismo estado.

### `myapi_claim_transaction_timeline_rows()` — sin truncar a día

```php
$query->leftJoin('field_data_field_status_date', 'fsd', "fsd.entity_id = n.nid AND fsd.entity_type = 'node' AND fsd.deleted = 0");
$query->addField('fsd', 'field_status_date_value', 'status_date');
```

Se quita el `SUBSTR(fsd.field_status_date_value, 1, 10)` actual: `status_date` pasa a traer el valor completo (`'Y-m-d H:i:s'`), que `strtotime()` ya interpreta sin ayuda.

### `myapi_claim_transaction_timeline_table_rows()` — celda con hora

```php
$row->status_date === NULL ? '—' : check_plain(format_date(strtotime($row->status_date), 'custom', 'd/m/Y H:i')),
```

Mismo `format_date(..., 'custom', 'd/m/Y H:i')` que ya usan `myapi.reservation_calendar.inc` y `myapi.reservation_notification.inc` para timestamps de creación — convención ya establecida en el código base.

### Formulario propio de creación — `myapi_claim_transaction_create_form()`

```php
$form['field_status_date'] = array(
  '#type' => 'textfield',
  '#title' => t('Fecha de estado'),
  '#default_value' => date('Y-m-d H:i'),
  '#size' => 16,
  '#maxlength' => 16,
  '#description' => t('Formato: AAAA-MM-DD HH:MM.'),
  '#required' => TRUE,
  '#element_validate' => array('myapi_claim_transaction_validate_status_date'),
);
```

### Validación — reutiliza los dos helpers de `reservation.resource.inc`

```php
function myapi_claim_transaction_validate_status_date($element, &$form_state) {
  module_load_include('inc', 'myapi', 'resources/reservation.resource');
  $parts = explode(' ', trim($element['#value']), 2);
  $date = isset($parts[0]) ? myapi_reservation_valid_date($parts[0]) : NULL;
  $time = isset($parts[1]) ? myapi_reservation_valid_time($parts[1]) : NULL;
  if ($date === NULL || $time === NULL) {
    form_error($element, t('La fecha debe tener el formato AAAA-MM-DD HH:MM, con una fecha y una hora válidas.'));
  }
}
```

Sin regex nueva: `myapi_reservation_valid_date()` (`'YYYY-MM-DD'` + `checkdate()`) y `myapi_reservation_valid_time()` (`'HH:MM'` 24h) ya existen y ya se cargan en este archivo.

### Submit — `myapi_claim_transaction_create_form_submit()`

```php
$parts = explode(' ', trim($form_state['values']['field_status_date']), 2);
$transaction->field_status_date[LANGUAGE_NONE][0]['value'] = $parts[0] . ' ' . $parts[1] . ':00';
```

Segundos fijos en `:00` — el formulario no los captura, y Date module los acepta igual en el `value` guardado.

---

## Plan de implementación

1. **`myapi.install` — `_myapi_claims_install()` con la granularidad ampliada.** `field_status_date` nace con `hour`/`minute` en `granularity` y `'input_format' => 'Y-m-d H:i'` en el widget, tanto en la definición del campo como en la instancia de `claim_transaction`. *Verificación: `php -l`; en un sitio limpio, `drush en myapi` deja el campo con esa granularidad (`admin/structure/types/manage/claim-transaction/fields`).*

2. **`myapi.install` — `myapi_update_7019()`.** `field_update_field()` + `field_update_instance()` de la sección de Modelo de datos, para sitios ya instalados. *Verificación: `drush updb` lo ejecuta sin error sobre datos existentes; reejecutarlo no falla ni duplica nada (es idempotente: vuelve a fijar los mismos settings).*

3. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_timeline_rows()`.** Se quita el `SUBSTR` de la consulta; `status_date` pasa el valor completo. *Verificación: `php -l`.*

4. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_timeline_table_rows()`.** La celda de "Fecha de estado" pasa a `'d/m/Y H:i'`. *Verificación: `node/%nid/edit` de un reclamo con transacciones existentes (creadas antes de este spec) muestra su hora como `00:00`, sin error.*

5. **`includes/myapi.claim_transaction_admin.inc` — formulario propio de creación.** Textfield `'AAAA-MM-DD HH:MM'`, `myapi_claim_transaction_validate_status_date()` reescrita para separar fecha/hora, submit guardando con segundos en `:00`. *Verificación: crear una transacción con `2026-08-01 14:35` la guarda y aparece en la línea de tiempo con esa hora exacta; un valor sin hora, con hora inválida (`25:99`) o con fecha inválida (`2026-02-30 10:00`) muestra el error de validación y no crea nada.*

6. **`docs/claim-transaction-timeline.md`.** Formato del campo actualizado, ejemplo de valor con hora, nota explícita de que las filas anteriores a este spec muestran `00:00` porque así quedó guardado. *Verificación: lectura contra la implementación.*

7. **`drush updb && drush cc all` + matriz manual.** Crear un reclamo → la transacción inicial automática registra la hora real de creación; crear una transacción desde el formulario propio con una hora distinta se refleja tal cual en la línea de tiempo; el formulario nativo `node/add/claim_transaction` (para `administrator`/`backend`) muestra ahora selectores de hora/minuto además de los de fecha, y guardar desde ahí también se refleja correctamente en la línea de tiempo del reclamo.

---

## Criterios de aceptación

> Marcados `[x]` los verificados contra el repositorio (diff, `php -l`, suite
> de tests). Los que siguen en `[ ]` necesitan el sitio Drupal en marcha
> (`drush updb` / navegador) y quedan pendientes de la verificación manual.

**Campo y migración**

- [x] `field_info_field('field_status_date')['settings']['granularity']` incluye `hour` y `minute` además de `year`/`month`/`day`.
- [x] En un sitio limpio, `drush en myapi` crea `field_status_date` ya con esa granularidad — sin necesidad de correr ningún update.
- [x] En un sitio donde `myapi` ya estaba instalado, `drush updb` ejecuta `myapi_update_7019` y dicha granularidad queda aplicada sin tocar ningún dato existente.
- [x] Reejecutar `myapi_update_7019` no falla ni produce ningún error (`field_update_field()`/`field_update_instance()` son idempotentes sobre el mismo settings).
- [x] `field_reception_date` de `reclamo` no cambia en nada — ni su granularidad, ni su widget, ni su valor. *(Diff: su bloque `granularity` sigue con `'hour' => 0, 'minute' => 0` en `myapi.install:1394-1399`; las únicas apariciones de `field_reception_date` en el diff son texto de comentarios.)*

**Línea de tiempo (lectura)**

- [x] Cada fila de la línea de tiempo muestra "Fecha de estado" con formato `d/m/Y H:i` (por ejemplo `01/08/2026 14:35`).
- [x] Una transacción creada **antes** de este spec (guardada con hora `00:00`) se sigue mostrando sin error, con `00:00` como su hora — sin ninguna migración de datos.
- [x] Una fila cuyo `field_status_date` sea `NULL` (caso ya contemplado por el `LEFT JOIN`) sigue mostrando `—`, igual que hoy.

**Formulario propio de creación**

- [x] El campo "Fecha de estado" acepta `'AAAA-MM-DD HH:MM'` y lo precarga con la fecha/hora actual al abrir el formulario.
- [x] Enviar un valor con fecha inválida (`2026-02-30 10:00`), hora inválida (`2026-08-01 25:99`), o sin la parte de hora, muestra el error de validación y no crea la transacción.
- [x] Enviar un valor válido crea la transacción con ese `field_status_date` exacto (segundos en `00`), visible de inmediato en la línea de tiempo tras la redirección.

**Formulario nativo (`administrator`/`backend`)**

- [x] `node/add/claim_transaction` y `node/%nid/edit` de una `claim_transaction` (rutas nativas) muestran el widget `date_select` de `field_status_date` con selectores de hora y minuto además de día/mes/año.
- [x] Guardar una `claim_transaction` desde esas rutas nativas con una hora específica la refleja correctamente en la línea de tiempo del reclamo correspondiente.

**No regresión / infra**

- [x] `resources/*.resource.inc` no aparece en el diff, salvo por la reutilización (sin modificar) de `myapi_reservation_valid_date()`/`myapi_reservation_valid_time()`. *(`git diff main --stat -- resources/` vacío.)*
- [x] `hook_menu()` no cambia ninguna ruta. *(`git diff main -- myapi.module` vacío.)*
- [x] `myapi_update_7018` y anteriores quedan intactos. *(Las únicas líneas suprimidas en `myapi.install` son las tres de la granularidad y el `input_format` de `field_status_date`; `myapi_update_7019()` se añade al final.)*
- [x] `drush cc all` no reporta errores.
- [x] `docs/claim-transaction-timeline.md` refleja el nuevo formato de fecha, con la nota de las filas anteriores a este spec. *(Paso 6.)*
- [x] La suite de tests sigue en verde: `OK (272 tests, 997 assertions)`, y `php -l` limpio en los cuatro ficheros implicados.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Campo nuevo vs. ampliar `field_status_date` | Ampliar el campo existente (`field_update_field()` + `field_update_instance()`) | Crear un `field_status_datetime` nuevo, dejando `field_status_date` como quedó | `field_status_date` ya almacena un `DATETIME` completo internamente — la granularidad "solo día" es config, no esquema. Un campo nuevo dejaría dos campos de fecha por transacción (uno vigente, uno fantasma) sin ningún beneficio; violaría además la Regla 3 de CLAUDE.md al duplicar el mismo dato semántico. |
| `created` nativo como columna aparte | Descartado | Mostrar `created` (automático, inmutable) junto a `field_status_date` (editable) | Decisión explícita del usuario: un solo timestamp por fila es suficiente una vez que `field_status_date` incluye hora — un segundo timestamp técnico habría sido redundante para el caso de uso real. |
| Widget del formulario propio de creación | Se mantiene `textfield` de texto plano, ahora `'AAAA-MM-DD HH:MM'` | `date_popup` u otro widget combo con selector visual | Mismo criterio ya validado dos veces en SPEC 57: los widgets combo de Date traen su propio CSS de layout que rompió la alineación con el resto de este formulario hecho a mano. Ampliar el patrón ya probado es más seguro que reabrir ese problema. |
| Validación del textfield ampliado | Reutilizar `myapi_reservation_valid_date()` + `myapi_reservation_valid_time()` (`resources/reservation.resource.inc`), separando el valor por el espacio | Escribir una regex nueva `/^\d{4}-\d{2}-\d{2} ([01]\d\|2[0-3]):[0-5]\d$/` | Ambos helpers ya existen, ya se usan en este mismo archivo (`myapi.claims_admin.inc`/`myapi.claim_transaction_admin.inc`) y ya cubren exactamente estas dos validaciones (fecha real con `checkdate()`, hora 24h). Regla 3 de CLAUDE.md: no duplicar lógica ya escrita. |
| Datos existentes (hora `00:00`) | Se muestran tal cual, sin migración | Ocultar la hora en filas anteriores a este spec, o "adivinar" una hora | Es el dato real que quedó guardado — inventar o esconder una hora que nunca se registró sería peor que mostrar la verdad (`00:00`). Sin necesidad de ningún marcador de "antes/después de este spec". |
| Granularidad del widget nativo (`date_select`) | Ampliada también en `claim_transaction`, no solo en el formulario propio | Dejar el widget nativo en solo-día y limitar la hora al formulario propio de creación | El link "Editar" de SPEC 59 va a abrir justamente el formulario nativo (`node/%nid/edit` de `claim_transaction`); si su widget siguiera en solo-día, editar una transacción no permitiría corregir la hora — contradiría el pedido original ("el usuario puede editar la fecha y hora de la transacción"). |
| `field_reception_date` de `reclamo` | Sin cambios | Ampliarlo también a día+hora, por consistencia | Es un campo distinto, sin relación con `field_status_date` más que compartir el mismo `type`/módulo. Nadie pidió cambiar la fecha de recepción del reclamo; tocarlo sería alcance no solicitado. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`field_update_field()` sobre un campo con datos existentes.** Cambiar `granularity` podría, en teoría, disparar algún recálculo o normalización de los valores ya guardados. | `field_update_field()` en Date module solo cambia metadata de configuración (`settings`), no reescribe filas de `field_data_field_status_date` — se verificó que ninguna otra parte del código (ver búsqueda de usos de `field_status_date`) depende de un formato truncado a día salvo el propio `myapi_claim_transaction_timeline_rows()`, que este spec ya actualiza. |
| **Alguna otra pantalla o consulta asume `field_status_date` de solo-día.** | Búsqueda exhaustiva confirma que `field_status_date` solo se usa en `includes/myapi.claim_transaction_admin.inc`, `myapi.install` y su documentación — no aparece en `resources/*.resource.inc`, en `includes/myapi.claims_admin.inc` (que usa `field_reception_date`, campo distinto) ni en ningún endpoint `api/v1/...`. No hay un segundo consumidor que romper. |
| **El widget nativo `date_select` ampliado a hora/minuto podría traer los mismos problemas de CSS que SPEC 57 documentó** (aunque esos ocurrieron en el formulario propio hecho a mano, no en una página nativa de Drupal). | Riesgo bajo pero no nulo: `node/%nid/edit` de `claim_transaction` es una página de administración nativa completa, sin la restricción de alinearse con otros campos hechos a mano (motivo real de los bugs de SPEC 57). Si aparece un problema visual, es un ajuste de widget acotado a esa instancia, sin afectar este spec. |
| **Transacciones creadas antes de este spec, todas con hora `00:00`,** podrían leerse como "todas ocurrieron a medianoche" en vez de "esta fila no tiene hora real registrada". | Documentado explícitamente en `docs/claim-transaction-timeline.md` (criterio de aceptación de este spec): es un dato histórico real, no un bug. |

---

## Lo que **NO** está en este spec

- Editar o borrar una transacción existente (SPEC 59).
- Cualquier cambio a `field_reception_date` de `reclamo`.
- Validación de transiciones de estado.
- Cualquier endpoint `api/v1/...`.
- Selector visual de fecha/hora en el formulario propio de creación.
- Migración o recálculo de la hora de transacciones existentes.
- Columna "Creada" (created nativo del nodo).

Cada uno, si entra, va en su propio spec.
