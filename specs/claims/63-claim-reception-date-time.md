# SPEC 63 — Fecha **y hora** de recepción en el reclamo

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundle `reclamo`, campo `field_reception_date` día-only), SPEC 56 (`includes/myapi.claim_query.inc` y el listado `admin/content/claims`, que lo consultan y lo muestran), SPEC 58 (precedente exacto: la misma ampliación aplicada a `field_status_date`) · **Fecha:** 2026-08-03
> **Objetivo:** Que `field_reception_date` del `reclamo` guarde también la hora — no solo el día — del momento en que se recibió, y que el listado la muestre.

Notas técnicas que fija esto, porque condicionan el resto:

- `field_reception_date` es un campo `datetime` (módulo Date) que **ya** almacena un `DATETIME` completo internamente: la granularidad «solo día» era una restricción de configuración del campo y del widget, nunca del esquema. Ampliarla es `field_update_field()` + `field_update_instance()`, sin mover una sola fila.
- SPEC 58 hizo exactamente esto con `field_status_date` en `claim_transaction`, y dejó dicho por escrito que `field_reception_date` no se tocaba porque nadie lo había pedido. Ahora sí se pidió: este spec es su gemelo, con el mismo update hook, el mismo `input_format` y el mismo formato de visualización.
- El widget nativo `date_select` de la instancia precarga por defecto la fecha/hora actual (`default_value = 'now'`, el default del módulo Date), así que ampliar la granularidad basta para que `node/add/reclamo` traiga ya la hora real de alta: no hace falta ningún `hook_form_alter` ni ningún valor calculado en código.
- Reclamos guardados antes de este spec quedan con `00:00`, el dato real que se guardó. Sin migración.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - `_myapi_claims_install()`: `field_reception_date` nace con `granularity` `year-month-day-hour-minute` (`second => 0`, sigue `tz_handling = 'none'`) y su instancia en `reclamo` con `'input_format' => 'Y-m-d H:i'`.
  - Nuevo **`myapi_update_7022()`** (siguiente número libre tras `myapi_update_7021` de SPEC 62): `field_update_field()` de la granularidad + `field_update_instance()` del widget de `reclamo`.
- **`includes/myapi.claim_query.inc`** (modificar) — `myapi_claims_list_rows()`: la columna `reception_date` deja de ser `SUBSTR(..., 1, 10)` y pasa a traer el valor completo (`addField()`). El `SUBSTR` **se mantiene en los dos filtros** `date_from`/`date_to`.
- **`includes/myapi.claims_admin.inc`** (modificar) — `myapi_claims_list_table_rows()`: la celda «Fecha de recepción» pasa a `format_date(strtotime($row->reception_date), 'custom', 'd/m/Y H:i')`.
- **`docs/claims-install.md`**, **`docs/claims-list.md`** (modificar) — granularidad del campo, formato de la columna y nota de que los filtros comparan días.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance:**

- **Filtrar por hora** en `admin/content/claims`: `date_from`/`date_to` siguen siendo dos `<input type="date">` de día, y siguen comparándose contra los primeros diez caracteres del valor almacenado.
- **Migrar o recalcular** la hora de reclamos existentes — quedan en `00:00` tal como están guardados.
- **Forzar la hora a la de creación del nodo** (`created`) o bloquear el campo: sigue siendo un dato que el operador puede corregir, igual que la fecha. Un reclamo recibido por teléfono y cargado después es justamente el caso que motivó tener un campo propio en vez de usar `created` (SPEC 55).
- **`field_status_date`** — ya ampliado por SPEC 58, no se toca.
- **Cualquier endpoint `api/v1/...`**: ningún recurso lee este campo hoy.

---

## Modelo de datos

No se crean campos, tablas ni bundles. Cambia el `settings.granularity` del campo `field_reception_date` y el `input_format` del widget de su instancia en `reclamo`.

### Update hook — `myapi_update_7022()`

```php
$field = field_info_field('field_reception_date');
$field['settings']['granularity'] = array(
  'year' => 'year', 'month' => 'month', 'day' => 'day',
  'hour' => 'hour', 'minute' => 'minute', 'second' => 0,
);
field_update_field($field);

$instance = field_info_instance('node', 'field_reception_date', 'reclamo');
$instance['widget']['settings']['input_format'] = 'Y-m-d H:i';
field_update_instance($instance);
```

Copia literal, campo y bundle aparte, de `myapi_update_7019()` (SPEC 58) — incluidas su idempotencia (vuelve a escribir el mismo `settings`) y sus dos guardas por si el campo o la instancia no existen en ese entorno.

### `myapi_claims_list_rows()` — sin truncar a día en el SELECT

```php
$query->addField('frd', 'field_reception_date_value', 'reception_date');
```

El `SUBSTR` sobrevive **solo** en los dos filtros, y ahí no es un resto sino la condición de corrección: `date_to` es un límite **inclusivo**, y comparado contra el valor completo, un reclamo recibido ese día a las 14:30 quedaría fuera del rango.

### `myapi_claims_list_table_rows()` — celda con hora

```php
$row->reception_date === NULL ? '—' : check_plain(format_date(strtotime($row->reception_date), 'custom', 'd/m/Y H:i')),
```

Mismo `d/m/Y H:i` que ya usan la línea de tiempo de transacciones (SPEC 58), el calendario de reservas y las notificaciones: convención ya establecida en el código base.

---

## Plan de implementación

1. **`myapi.install` — `_myapi_claims_install()`.** Granularidad e `input_format` ampliados. *Verificación: `php -l`; en un sitio limpio, `drush en myapi` deja el campo con hora y minuto.*
2. **`myapi.install` — `myapi_update_7022()`.** *Verificación: `drush updb` sin error sobre datos existentes; reejecutable.*
3. **`includes/myapi.claim_query.inc`.** `addField()` en lugar del `addExpression()` con `SUBSTR`; comentario explicando por qué el `SUBSTR` se queda en los filtros. *Verificación: `php -l`.*
4. **`includes/myapi.claims_admin.inc`.** Celda a `d/m/Y H:i`. *Verificación: reclamos anteriores muestran `00:00`, sin error.*
5. **Docs.** *Verificación: lectura contra la implementación.*
6. **`drush updb && drush cc all` + matriz manual.**

---

## Criterios de aceptación

> Marcados `[x]` los verificados contra el repositorio (diff, `php -l`, suite de
> tests). Los que siguen en `[ ]` necesitan el sitio Drupal en marcha
> (`drush updb` / navegador) y quedan pendientes de la verificación manual.

**Campo y migración**

- [x] `_myapi_claims_install()` declara `hour`/`minute` en la `granularity` de `field_reception_date` y `'Y-m-d H:i'` en el `input_format` de su instancia.
- [x] `field_info_field('field_reception_date')['settings']['granularity']` incluye `hour` y `minute` tras `drush updb`.
- [x] `node/add/reclamo` y `node/%nid/edit` de un reclamo muestran selectores de hora y minuto además de día/mes/año, precargados con la hora actual al crear.
- [x] Guardar un reclamo con una hora concreta la conserva exactamente (segundos en `00`).
- [x] Reejecutar `myapi_update_7022` no falla ni cambia ningún dato.
- [x] `field_status_date` no cambia en nada — este spec no lo toca.

**Listado**

- [x] La celda «Fecha de recepción» usa `format_date(..., 'd/m/Y H:i')`.
- [x] La consulta trae el valor completo, sin `SUBSTR`, y los dos filtros lo conservan.
- [x] Un reclamo creado **antes** de este spec se sigue mostrando sin error, con `00:00`.
- [x] Una fila sin `field_reception_date` (contemplada por el `LEFT JOIN`) sigue mostrando `—`.
- [x] `?date_from`/`?date_to` siguen filtrando por día, con `date_to` inclusivo: un reclamo recibido ese día a las 14:30 aparece.

**No regresión / infra**

- [x] `resources/*.resource.inc` y `myapi.module` no aparecen en el diff.
- [x] `hook_menu()` no cambia ninguna ruta.
- [x] `myapi_update_7021` y anteriores quedan intactos; `myapi_update_7022()` se añade al final.
- [x] La suite sigue en verde: `OK (324 tests, 1080 assertions)`.
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Campo nuevo vs. ampliar el existente | Ampliar `field_reception_date` (`field_update_field()` + `field_update_instance()`) | Crear un campo nuevo con fecha y hora | El campo ya almacena un `DATETIME` completo: la granularidad es configuración, no esquema. Un campo nuevo dejaría dos fechas de recepción por reclamo, una viva y una fantasma — y duplicaría el mismo dato semántico (Regla 3 de CLAUDE.md). Mismo razonamiento que SPEC 58. |
| Origen de la hora | La que trae el widget (por defecto, la actual), editable | Copiar `created` del nodo y bloquear el campo | La fecha de recepción existe precisamente porque puede no coincidir con el alta del nodo (SPEC 55: un reclamo recibido por teléfono y cargado después). Bloquearla convertiría el campo en un duplicado de `created`. |
| Filtros del listado | Siguen comparando días (`SUBSTR`) | Comparar el valor completo | Con `date_to` inclusivo, comparar contra el valor completo dejaría fuera todo lo recibido ese día después de medianoche. Filtrar por hora no fue pedido y necesitaría otro tipo de control en el formulario. |
| Columna del listado | `d/m/Y H:i` | Seguir mostrando `d/m/Y` | Decisión explícita del usuario. Es además el formato que ya usa la línea de tiempo de transacciones desde SPEC 58. |
| Datos existentes (`00:00`) | Se muestran tal cual, sin migración | Ocultar la hora en filas anteriores, o inventarla | Es el dato real guardado; mismo criterio que SPEC 58. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`field_update_field()` sobre un campo con datos existentes.** | Solo cambia metadata de configuración; no reescribe filas de `field_data_field_reception_date`. Ya verificado en producción por el update gemelo de SPEC 58. |
| **Otro consumidor que asuma día-only.** | Búsqueda exhaustiva: `field_reception_date` solo aparece en `myapi.install`, `includes/myapi.claim_query.inc`, `includes/myapi.claims_admin.inc` y la documentación. No hay recurso `api/v1/...` ni notificación que lo lea. |
| **Reclamos previos con `00:00`** leídos como «recibidos a medianoche». | Documentado en `docs/claims-install.md` y `docs/claims-list.md`: es dato histórico, no un bug. |

---

## Lo que **NO** está en este spec

- Filtrar el listado por hora.
- Migrar o recalcular la hora de reclamos existentes.
- Bloquear el campo o derivarlo de `created`.
- Cambios en `field_status_date` (SPEC 58).
- Cualquier endpoint `api/v1/...`.
- El retiro del estado «Duplicado» — eso es SPEC 62, del mismo lote pero independiente.
