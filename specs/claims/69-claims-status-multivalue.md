# SPEC 69 — Filtro `?status` multivalor en `GET /api/v1/claims`

> **Estado:** Implemented · **Depende de:** SPEC 62 (catálogo de cuatro estados y whitelist hard-codeada), SPEC 64 (`GET /api/v1/claims`, `includes/myapi.claims_common.inc`, `myapi_claim_base_query()` y el parseo laxo del query string) · **Fecha:** 2026-08-04
> **Objetivo:** Permitir que el listado de reclamos de la app filtre por **más de un estado a la vez** — `?status=received,in_progress` —, sin romper el contrato de un solo valor ni duplicar la whitelist.

Notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- SPEC 64 dejó los **filtros multivalor explícitamente fuera de alcance**, nombrando este mismo ejemplo (`?status=received,in_progress`). Este spec es exactamente ese pendiente, y solo ese: `claim_type` sigue siendo de un valor.
- La whitelist de estados **está hard-codeada a propósito** (SPEC 56, reducida a cuatro por SPEC 62): no se lee de `field_info_field()`. Cualquier función nueva tiene que apoyarse en `myapi_claims_valid_status()` en vez de repetir la lista, o el próximo cambio de catálogo tendría dos sitios que tocar.
- El parseo del query string del endpoint es **laxo**: un valor inválido cae en su defecto en silencio y nunca devuelve `422`. La única excepción es `?condominium_id` ajeno (403), y este spec no la toca.
- `field_status` tiene **cardinalidad 1** y su tabla está indexada por `entity_id`: la condición pasa de `=` a `IN (...)`, sin `DISTINCT` ni join nuevo, así que el número de queries por request no cambia.

---

## Alcance

**Dentro:**

- **`includes/myapi.claims_common.inc`** (modificar) — nueva función `myapi_claims_valid_status_list($value)`: parte el valor por comas, valida **cada item con `myapi_claims_valid_status()`** y devuelve una lista de estados distintos, o `NULL` si no sobrevive ninguno. La whitelist no se reescribe.
- **`resources/claim.resource.inc`** (modificar):
  - `myapi_claim_list()` — `'status'` pasa a poblarse con `myapi_claims_valid_status_list($_GET['status'])`.
  - `myapi_claim_base_query()` — la condición de estado pasa a `->condition('fs.field_status_value', (array) $filters['status'], 'IN')`. El cast es lo que mantiene válido un `$filters['status']` string si algún día llega uno.
  - Docblocks: el del `@file` (el bloque de parseo), el `@param $filters` de `myapi_claim_base_query()` y el comentario del punto 4 de `myapi_claim_list()`.
- **`tests/unit/ClaimsStatusFilterTest.php`** (modificar) — segundo bloque de tests para la función nueva: valor único, varios, items desconocidos, lista sin nada válido, espacios y comas vacías, duplicados y valor no escalar. Va en este fichero y no en `ClaimListFilterTest.php` porque es **la misma whitelist**: los dos bloques se mueven juntos el día que cambie el catálogo.
- **`docs/claim.md`** (modificar) — fila `status` de la tabla de query params, nota de implementación y la forma **no** soportada (`?status[]=`).
- `drush cc all` al final (fichero `.inc` modificado, sin rutas nuevas).

**Fuera de alcance:**

- **`claim_type` multivalor.** Son dos valores: pedir los dos es no filtrar, así que la lista no compra nada. Si mañana el catálogo crece, este spec es la plantilla.
- **El listado del back office** (`admin/content/claims`). Su filtro es un `select` de un solo estado y sigue igual: convertirlo en múltiple es cambiar el form builder, el render del filtro activo y la URL que se comparte por correo, y nadie lo ha pedido. `myapi_claims_valid_status()` sigue existiendo tal cual, y es la que el back office llama.
- **`GET /api/v1/claims/%`** — el detalle no filtra por estado.
- **Sintaxis con corchetes** (`?status[]=received&status[]=closed`) y separadores alternativos (`|`, espacios, repetir el parámetro). Una sola forma documentada; el resto cae en "sin filtro" como cualquier valor no parseable.
- **Devolver `422`** ante una lista con items inválidos. Rompería el parseo laxo que declara SPEC 64 para todo el query string.

---

## Contrato

### `myapi_claims_valid_status_list($value)`

```php
function myapi_claims_valid_status_list($value) {
  if (!is_scalar($value)) {
    return NULL;
  }
  $statuses = array();
  foreach (explode(',', (string) $value) as $item) {
    $status = myapi_claims_valid_status(trim($item));
    if ($status !== NULL && !in_array($status, $statuses, TRUE)) {
      $statuses[] = $status;
    }
  }
  return $statuses ? $statuses : NULL;
}
```

| Entrada | Salida | Efecto en el listado |
|---|---|---|
| `'received'` | `['received']` | Igual que antes de este spec |
| `'received,in_progress'` | `['received', 'in_progress']` | Los de cualquiera de los dos estados |
| `'received, closed'` | `['received', 'closed']` | El `trim()` absorbe el separador `', '` |
| `'received,,closed,'` | `['received', 'closed']` | Comas vacías ignoradas |
| `'received,received'` | `['received']` | Duplicados colapsados: una sola condición |
| `'received,inventado'` | `['received']` | Item desconocido descartado, el válido sigue filtrando |
| `'received,duplicated'` | `['received']` | Marcador viejo de SPEC 62: mismo caso |
| `'inventado,duplicated'` | `NULL` | Ningún item válido → sin filtro, todos los estados |
| `''`, `','`, `'0,1'` | `NULL` | Sin filtro |
| `array('received')` | `NULL` | `?status[]=` no es la forma documentada |

Las tres decisiones que no son obvias:

- **Item a item, no todo o nada.** `?status=received,inventado` filtra por `received` en vez de ignorar el filtro entero. Es la lectura literal de "de estos, los que existan", y es coherente con lo que ya hacía un valor único: la basura se descarta, no aborta la request. Solo cuando **no queda nada** el filtro entero cae en "todos los estados", que es exactamente la respuesta que `?status=inventado` da desde SPEC 62 — el contrato viejo sigue siendo un caso particular del nuevo.
- **Sin whitelist nueva.** La función no menciona ningún estado: llama a `myapi_claims_valid_status()` una vez por item. El catálogo sigue viviendo en una sola línea del repo (Regla 3 de `CLAUDE.md`), y el back office, que sigue llamando a la función de siempre, no puede desincronizarse.
- **Duplicados colapsados.** No cambian el resultado de un `IN`, pero sí el tamaño del SQL: `?status=received` repetido veinte veces por un bucle del cliente genera una condición, no veinte placeholders.

### Query

```php
if (!empty($filters['status'])) {
  $query->condition('fs.field_status_value', (array) $filters['status'], 'IN');
}
```

`IN` con un solo elemento es lo mismo que `=` para MySQL, así que el caso de un estado no paga nada por el cambio, y el de cuatro cuesta igual que el de uno: `field_data_field_status` ya está en el `LEFT JOIN` por `entity_id` y no se añade ni un join ni un `DISTINCT` (cardinalidad 1 ⇒ una fila por reclamo). El conteo y la página comparten esta misma función, así que la paginación describe el conjunto filtrado sin tocar nada más.

`(array)` sobre un string produce `['received']`: la condición sigue siendo correcta si un llamador futuro pasa un estado suelto en `$filters`.

---

## Plan de implementación

1. **`includes/myapi.claims_common.inc` — `myapi_claims_valid_status_list()`**, con su docblock (por qué no repite la whitelist y por qué el parseo es item a item). *Verificación: `php -l`.*
2. **`tests/unit/ClaimsStatusFilterTest.php`** — el bloque nuevo, incluido el test que fija que un valor único devuelve `['received']` y no `'received'`, que es el contrato del que cuelga el paso 3. *Verificación: `./vendor/bin/phpunit`.*
3. **`resources/claim.resource.inc`** — `myapi_claim_list()`, `myapi_claim_base_query()` y los tres docblocks. *Verificación: `php -l`; suite en verde.*
4. **`docs/claim.md`** — tabla de params, nota de implementación, forma no soportada, cabecera del documento. *Verificación: lectura contra la implementación.*
5. **`drush cc all` + matriz manual** contra el sitio.

---

## Criterios de aceptación

> Marcados `[x]` los verificados contra el repositorio (diff, `php -l`, suite de
> tests). Los que siguen en `[ ]` necesitan el sitio Drupal en marcha y quedan
> pendientes de la verificación manual.

**Función**

- [x] `myapi_claims_valid_status_list('received')` devuelve `array('received')`.
- [x] `myapi_claims_valid_status_list('received,in_progress')` devuelve los dos, en el orden en que llegaron.
- [x] `'received, closed'`, `'received,,closed,'` y `'  received  '` se parsean sin sorpresas.
- [x] `'received,received'` devuelve un solo item.
- [x] `'received,inventado'` y `'received,duplicated'` devuelven `array('received')`.
- [x] `'inventado,duplicated'`, `''`, `','`, `'0,1'`, `array('received')` y `NULL` devuelven `NULL`.
- [x] El **cuerpo** de la función no contiene ningún literal de estado: `array('received', 'in_progress', 'resolved', 'closed')` sigue apareciendo una sola vez en el fichero (el resto de menciones son docblock).

**Endpoint**

- [x] `GET /api/v1/claims?status=received,in_progress` devuelve solo reclamos en esos dos estados, y `pagination.total` cuenta ese mismo conjunto.
- [x] `?status=received` devuelve **exactamente** lo mismo que antes de este spec.
- [x] `?status=received,inventado` devuelve lo mismo que `?status=received`.
- [x] `?status=inventado,duplicated` devuelve el listado completo, `200`, sin error.
- [x] `?status[]=received&status[]=closed` devuelve el listado completo, `200`, sin error de PHP.
- [x] La lista combinada con `condominium_id`, `date_from`/`date_to`, `claim_type`, `sort`, `limit=-1` e `include=transactions` sigue comportándose igual.
- [x] Siguen siendo tres queries por request (más las de transacciones e imágenes cuando aplican).

**No regresión**

- [x] `admin/content/claims` no aparece en el diff: `includes/myapi.claims_admin.inc` intacto.
- [x] `myapi_claims_valid_status()` no cambia ni una línea.
- [x] `GET /api/v1/claims/%`, la creación (SPEC 66), la edición (SPEC 67) y las notificaciones (SPEC 68) no aparecen en el diff.
- [x] `myapi.info`, `myapi.module` y `myapi.install` no cambian: ni fichero nuevo, ni ruta nueva, ni esquema.
- [x] La suite sigue en verde: `OK (370 tests, 1207 assertions)`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Sintaxis | `?status=received,in_progress` (coma) | (a) `?status[]=received&status[]=closed`; (b) repetir `?status=` sin corchetes | (a) obliga al cliente Flutter a construir la query a mano y no es la forma que usa ningún otro filtro del módulo; (b) PHP se queda solo con la última ocurrencia, así que fallaría en silencio. La coma es una sola cadena, legible en un log y en un marcador. |
| Compatibilidad | Un valor es una lista de uno | Mantener `status` string y añadir un `status_in` aparte | Dos parámetros para el mismo concepto es contrato duplicado; el cliente tendría que elegir cuál mandar. Con la lista, `?status=received` sigue significando lo mismo y el back-end tiene un solo camino. |
| Items inválidos dentro de la lista | Se descartan, los válidos siguen filtrando | (a) Invalidar el filtro entero; (b) devolver `422` | (a) haría que un marcador con `?status=received,duplicated` devolviera **más** reclamos de los pedidos, que es el peor de los dos silencios; (b) rompe el parseo laxo que SPEC 64 fijó para todo el query string. |
| Dónde vive la función | `includes/myapi.claims_common.inc`, llamando a `myapi_claims_valid_status()` | (a) En el recurso; (b) reescribir la whitelist dentro de la función nueva | (a) es lo que prohíbe la Regla 3 de `CLAUDE.md` y lo que SPEC 64 acaba de deshacer; (b) dejaría dos listas de estados que hay que cambiar a la vez — el problema exacto que SPEC 62 pagó cuando la whitelist no se leía del campo. |
| Back office | Se queda con un solo estado | Hacerlo múltiple a la vez | No lo pidió nadie, y cuesta form builder, filtro activo y URL compartible. La whitelist compartida hace que sea un cambio aditivo el día que se pida. |
| SQL | `IN` siempre, incluso con un item | Ramificar `=` / `IN` según el tamaño | Dos caminos para el mismo resultado; MySQL resuelve `IN` de un elemento como una igualdad. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Un cliente que ya manda `?status=received` deja de funcionar.** | El caso de un valor es una lista de uno y produce el mismo `IN` de un elemento; hay un test que lo fija (`testSingleStatusBecomesAListOfOne`). |
| **Un cliente manda `?status[]=`** creyendo que funciona y ve el listado completo. | Documentado explícitamente en `docs/claim.md` como forma no soportada, y cubierto por test. Es el mismo silencio que cualquier otro valor no parseable del endpoint. |
| **Lista muy larga** enviada por un cliente en bucle. | Los duplicados colapsan y la whitelist acota el resultado a cuatro items como máximo, sea cual sea el tamaño de la entrada. |

---

## Lo que **NO** está en este spec

- `claim_type` multivalor ni ningún otro filtro multivalor.
- El filtro `status` del listado de back office.
- Filtro por solicitante, por texto libre o por rango de horas.
- Cualquier cambio de esquema, de rutas o del catálogo de estados.
