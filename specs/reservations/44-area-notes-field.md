# SPEC 44 — Campo de notas/instrucciones del área

> **Estado:** Approved · **Depende de:** SPEC 32 (install de content types y campos), SPEC 33 (listado de áreas de un condominio), SPEC 39 (detalle de área y `myapi_area_base_select()`) · **Fecha:** 2026-07-27
> **Objetivo:** Añadir el campo `field_area_notes` (`text_long`, formato por defecto `full_html`) al bundle `area` y exponer su valor crudo como clave `notes` en las dos lecturas de área ya existentes, pasando el item de 13 a 14 claves.

---

## Alcance

**Dentro:**

- Nuevo campo `field_area_notes` (`text_long`, `cardinality` 1) y su instance **solo** en el bundle `area`: `label` "Instrucciones o notas", `required` 0, widget `text_textarea`, `settings.text_processing` 1, `default_value` con `format` `full_html`.
- Alta del campo en `_myapi_reservations_install()` (bloque de fields y bloque (c) de instancias del bundle `area`) para que una instalación limpia lo cree.
- `myapi_update_7008()`: hook de actualización idempotente para los sitios ya instalados, con el mismo patrón que `myapi_update_7007()` (`_myapi_reservations_ensure_field()` + `_myapi_reservations_ensure_instance()`).
- `field_area_notes` añadido a la lista de fields de `_myapi_reservations_uninstall_destructive()`.
- `leftJoin` a `field_data_field_area_notes` (alias `fnot`) en `myapi_area_base_select()`, tras el join de `field_area_category`, con `addField` del `_value` como `notes`.
- Clave `notes` al final de `myapi_area_build_item()`, después de `category`. El item pasa de 13 a 14 claves.
- Actualización de los docblocks que citan "13-key item shape" / "all 13 mapped keys" a 14, y descripción del campo nuevo en el docblock de `myapi_area_build_item()`.
- `docs/area.md`: `notes` en los ejemplos JSON de los **dos** endpoints de lectura, fila nueva en la tabla de mapeo de campos y en la de joins.
- `drush updb` + `drush cc all` al final.

**Fuera de alcance:**

- Cualquier endpoint de escritura de áreas. Las notas se editan **solo** desde el admin de Drupal.
- Saneado o renderizado del HTML en el servidor: la API no llama a `check_markup()` ni a `filter_xss()`; entrega el `_value` tal cual se guardó y es el cliente Flutter quien decide cómo mostrarlo.
- Exponer la columna `field_area_notes_format`. El item se queda en 14 claves.
- Cualquier cambio en `GET /api/v1/areas/{id}/availability`: usa `myapi_area_fetch_one()`, pero su respuesta es `{date, busy}` y no incluye el item de área.
- Cualquier cambio en creación, cancelación, listado o detalle de reservas. `resources/reservation.resource.inc` no se toca.
- Filtrar u ordenar áreas por `notes`.
- Códigos de error o claves i18n nuevas: el campo es opcional y de solo lectura.
- `myapi.info` y `hook_menu()`: no hay archivo `.inc` nuevo ni ruta nueva.

---

## Modelo de datos

**Field (storage), una sola vez para todo el sitio:**

```php
_myapi_reservations_ensure_field('field_area_notes', [
  'field_name'  => 'field_area_notes',
  'type'        => 'text_long',
  'cardinality' => 1,
]);
```

**Instance, solo en el bundle `area`:**

```php
_myapi_reservations_ensure_instance('field_area_notes', 'area', [
  'field_name'    => 'field_area_notes',
  'entity_type'   => 'node',
  'bundle'        => 'area',
  'label'         => 'Instrucciones o notas',
  'required'      => 0,
  'description'   => 'Instrucciones, normas o detalles del área que la app muestra al residente antes de reservar. Admite HTML.',
  'settings'      => ['text_processing' => 1],
  'default_value' => [['value' => '', 'format' => 'full_html']],
  'widget'        => ['type' => 'text_textarea'],
]);
```

**Almacenamiento.** Field API crea `field_data_field_area_notes` y `field_revision_field_area_notes` con las columnas `field_area_notes_value` (longtext) y `field_area_notes_format` (varchar). La API **solo lee `_value`**; `_format` no se selecciona ni se expone.

**Proyección en la query base.** `myapi_area_base_select()` gana un `leftJoin` con alias `fnot`, en la misma forma que los demás campos mapeados:

```php
$query->leftJoin('field_data_field_area_notes', 'fnot', "fnot.entity_id = n.nid AND fnot.entity_type = 'node' AND fnot.deleted = 0");
$query->addField('fnot', 'field_area_notes_value', 'notes');
```

**Item de respuesta.** El item pasa de 13 a 14 claves. La nueva va al final, después de `category`:

```json
{
  "...": "13 claves previas, sin cambios",
  "category": "pool",
  "notes": "Aforo máximo 20 personas. Prohibido el vidrio."
}
```

**Semántica del valor:**

| Situación en el nodo | Valor de `notes` |
|---|---|
| Sin fila en `field_data_field_area_notes` | `null` |
| Fila con texto | El `_value` tal cual, sin transformación |
| Fila con cadena vacía | `""` (no se normaliza a `null`) |

Mismo trato que `open_time`, `close_time`, `status`, `who_can_reserve` y `category`: pasan como están, `NULL` cuando el nodo no tiene fila. No hay conversión de tipo, `trim()`, `check_markup()` ni `filter_xss()`.

**Sin datos nuevos fuera de eso.** No hay tablas `myapi_*` nuevas, ni cambios en `hook_schema()`, ni en el bundle `reservation`.

Dos apuntes que van implícitos en el modelo:

- El `leftJoin` no lleva condición sobre `delta`, igual que el resto de joins de campos del recurso. Con `cardinality` 1 no puede haber más de una fila por nodo, así que no duplica resultados.
- `_myapi_reservations_ensure_field()` es idempotente vía `field_info_field()`, así que `myapi_update_7008()` es seguro de reejecutar y no choca con una instalación limpia que ya creó el campo.

---

## Plan de implementación

1. **`myapi.install` — instalación limpia.** Añadir `_myapi_reservations_ensure_field('field_area_notes', ...)` al bloque de fields, junto a `field_area_category`, y `_myapi_reservations_ensure_instance('field_area_notes', 'area', ...)` al final del bloque (c) de instancias del bundle `area`. *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — uninstall.** Añadir `'field_area_notes'` al array `$fields` de `_myapi_reservations_uninstall_destructive()`, después de `'field_area_category'`. *Verificación: `php -l`; no se ejecuta salvo con `MYAPI_RESERVATIONS_DESTRUCTIVE_UNINSTALL` en `TRUE`.*

3. **`myapi_update_7008()`.** Hook nuevo tras `myapi_update_7007()`, con la misma estructura: llamadas a los dos helpers idempotentes con las definiciones exactas del paso 1. Docblock explicando que solo toca el bundle `area`, que reutiliza los sub-helpers para no duplicar ni lanzar `FieldException` donde ya existan, y que no añade endpoint ni lógica de negocio. *Verificación: `drush updb` en un sitio ya instalado; el formulario `node/add/area` muestra "Instrucciones o notas" con el textarea y "Full HTML" preseleccionado.*

4. **`myapi_area_base_select()`.** `leftJoin` de `field_data_field_area_notes` con alias `fnot` y `addField` del `_value` como `notes`, colocado justo después del bloque de `field_area_category`. *Verificación: `php -l resources/area.resource.inc`; el listado sigue devolviendo los mismos items (el paso deja el sistema funcional: la columna se selecciona pero aún no se mapea).*

5. **`myapi_area_build_item()`.** Añadir `'notes' => $area->notes` como última clave, después de `'category'`. *Verificación: `GET /api/v1/areas/{id}` devuelve 14 claves.*

6. **Docblocks.** Cambiar "13-key item shape" (~línea 153) y "all 13 mapped keys" (~línea 417) a 14, y añadir `notes` a la enumeración de campos de texto del docblock de `myapi_area_build_item()` ("pasa tal cual, `NULL` cuando el nodo no tiene fila"). *Verificación: lectura.*

7. **`docs/area.md`.** Añadir `notes` a los ejemplos de respuesta JSON de `GET /api/v1/condominiums/{id}/areas` y `GET /api/v1/areas/{id}`; fila nueva en la tabla de mapeo (`field_area_notes_value` → `notes`, string, `null` si no hay fila) y en la tabla de joins (`field_data_field_area_notes`, left join, alias `fnot`); nota de que el valor puede contener HTML y llega sin sanear. *Verificación: el doc casa clave por clave con el item implementado.*

8. **`drush updb && drush cc all`.** `updb` ejecuta `myapi_update_7008()`; el cache clear es obligatorio para que Field API vea la definición nueva y para recoger el `.inc` modificado. *Verificación: los dos `curl` de la sección de verificación manual.*

---

## Criterios de aceptación

- [ ] Tras `drush updb`, `field_info_field('field_area_notes')` existe y `field_info_instance('node', 'field_area_notes', 'area')` devuelve el instance; el bundle `reservation` **no** tiene instance de ese campo.
- [ ] El formulario `node/add/area` muestra "Instrucciones o notas" como textarea, no obligatorio, con el selector de formato en "Full HTML".
- [ ] Reejecutar `myapi_update_7008()` no lanza `FieldException` ni duplica el campo o el instance.
- [ ] Una instalación limpia (`drush en myapi` sobre BD virgen) crea el campo y su instance sin necesidad de `updb`.
- [ ] `GET /api/v1/condominiums/{id}/areas` devuelve items de **14 claves**, con `notes` en último lugar, después de `category`.
- [ ] `GET /api/v1/areas/{id}` devuelve el mismo item de 14 claves envuelto como `{"area": ...}`.
- [ ] Un área **con** notas devuelve el texto exactamente como se guardó, incluido el HTML si lo tiene, sin escapar ni filtrar.
- [ ] Un área **sin** fila en `field_data_field_area_notes` devuelve `"notes": null` y sigue apareciendo en el listado y en el detalle igual que antes del cambio.
- [ ] El número de áreas devueltas y el bloque `pagination` del listado son idénticos a los de antes del cambio (el `leftJoin` no filtra nada).
- [ ] `GET /api/v1/areas/{id}/availability` devuelve exactamente la misma respuesta `{date, busy}` que antes, sin clave `notes`.
- [ ] El payload de reservas (`GET /api/v1/units/{id}/reservations`, detalle y creación) no cambia en ninguna clave.
- [ ] No hay claves i18n ni `error_code` nuevos.
- [ ] `myapi.info` y `hook_menu()` sin cambios en el diff.
- [ ] `docs/area.md` documenta `notes` en los dos endpoints, en las dos tablas, y avisa de que el valor puede contener HTML sin sanear.

---

## Verificación manual

Comandos tras aplicar los cambios:

```bash
drush updb     # ejecuta myapi_update_7008()
drush cc all   # obligatorio: Field API debe ver la definición nueva
```

Área **con** notas (listado):

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
     -H "Accept-Language: es" \
     "https://<host>/api/v1/condominiums/12/areas?limit=1"
```

```json
{
  "success": true,
  "data": {
    "areas": [
      {
        "id": 34,
        "name": "Piscina",
        "...": "resto de claves",
        "category": "pool",
        "notes": "<p>Aforo máximo 20 personas.</p><p>Prohibido el vidrio.</p>"
      }
    ],
    "pagination": { "...": "sin cambios" }
  }
}
```

Área **sin** notas (detalle):

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
     "https://<host>/api/v1/areas/35"
```

```json
{
  "success": true,
  "data": {
    "area": {
      "id": 35,
      "name": "Gimnasio",
      "...": "resto de claves",
      "category": "gym",
      "notes": null
    }
  }
}
```

---

## Decisiones

- **Sí:** campo aditivo y de **solo lectura** por API. Las notas se editan desde el admin de Drupal; no se abre ningún endpoint de escritura de áreas, así que no hacen falta validaciones, permisos ni `error_code` nuevos.
- **Sí:** `text_processing = 1` con `default_value` en `full_html`. Unas instrucciones de uso reales llevan listas y saltos de párrafo; un textarea plano obligaría al admin a escribir un bloque sin estructura.
- **No:** `text_processing = 0`. Habría dado un contrato "texto plano" más simple, pero a costa de que el admin no pueda dar ningún formato al texto.
- **Sí:** devolver `field_area_notes_value` crudo, sin `check_markup()`. Un `check_markup()` en una lectura de API mete la capa de renderizado de Drupal dentro del contrato JSON, y su salida depende del formato guardado y de los permisos del usuario que resuelve la petición. El resto de campos de texto del item ya pasan tal cual; `notes` no es una excepción.
- **No:** exponer `field_area_notes_format`. El item se queda en 14 claves. El contrato ya dice "puede contener HTML"; una clave más obligaría al cliente a implementar una tabla de formatos de Drupal que no controla.
- **Sí:** `""` se devuelve tal cual y solo hay `null` cuando no existe fila. Normalizar añadiría una rama que ningún otro campo del item tiene.
- **Sí:** `myapi_update_7008()` nuevo con los dos helpers idempotentes, siguiendo el patrón de `myapi_update_7007()`, en vez de reejecutar `_myapi_reservations_install()` entero como hizo `myapi_update_7006()`. El hook dice explícitamente qué toca y el diff es auditable.
- **Sí:** el join vive en `myapi_area_base_select()`, no en una query aparte. Listado y detalle comparten proyección desde SPEC 39; duplicarla sería la forma exacta de que los dos endpoints devolvieran shapes distintos.
- **Sí:** `leftJoin`, nunca `innerJoin`. Un área sin notas no puede desaparecer del listado ni del detalle.
- **Sí:** `field_area_notes` entra en `_myapi_reservations_uninstall_destructive()`. Es opt-in manual, pero si algún día se ejecuta no debe dejar el campo huérfano.
- **No:** exponer `notes` en el payload de reservas. `resources/reservation.resource.inc` no se toca; si la app necesita las notas en la pantalla de una reserva, ya tiene el `area_id` para pedir el detalle.
- **No:** filtrar u ordenar áreas por `notes`. Es un texto libre y largo; ordenar o buscar por él no responde a ninguna necesidad planteada.
- **No:** tests unitarios. `myapi_area_build_item()` gana un mapeo directo sin ramas nuevas y no hay parseo que probar; la verificación es el `curl` de los dos endpoints.
- **No:** reescribir las SPEC 33 y 39. Son el registro de lo que se hizo entonces con 13 claves; esta spec documenta el paso a 14.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El HTML llega crudo al cliente; si la app lo renderiza en un WebView, un admin podría inyectar markup ejecutable | `full_html` requiere el permiso "use text format full_html", que solo tienen roles administrativos de confianza. El contrato documentado dice explícitamente que el valor no está saneado, así que la decisión de renderizarlo (y con qué sanitizador) es del cliente. |
| El rol que edita áreas no tiene permiso sobre `full_html` y el `default_value` no aplica | `default_value` solo **preselecciona**; Drupal cae al primer formato permitido para ese rol. No rompe nada: el campo se guarda igual y la API devuelve el `_value` que haya. |
| El formato `full_html` no existe en el sitio (renombrado o borrado) | Mismo comportamiento: el widget cae al primer formato disponible. El campo y su instance se crean igual; `myapi_update_7008()` no falla. |
| Un cliente que valide el item con un esquema cerrado rompe al recibir 14 claves | Añadir una clave es aditivo y ningún endpoint deja de devolver lo de antes. El doc registra el paso de 13 a 14 para que la app lo incorpore. |
| Textos largos inflando el payload del listado | `cardinality` 1 y paginación ya existente acotan el tamaño; si un día molesta, se resuelve con un `fields=` en un spec propio, no recortando el valor en servidor. |
| Olvidar `drush cc all` tras `updb`: Field API no ve la definición y el formulario del nodo no muestra el campo | El cache clear es un paso explícito del plan (paso 8) y un criterio de aceptación. |

## Lo que **NO** está en este spec

- Endpoints de escritura de áreas (alta, edición, borrado). Las notas se editan solo desde el admin de Drupal.
- Saneado o renderizado del HTML en el servidor (`check_markup()`, `filter_xss()`).
- La clave `field_area_notes_format` en el JSON.
- Cambios en `GET /api/v1/areas/{id}/availability` y en cualquier endpoint de reservas.
- Filtros u orden por `notes`.

Cada uno de ellos, si algún día entra, va en su propio spec.
