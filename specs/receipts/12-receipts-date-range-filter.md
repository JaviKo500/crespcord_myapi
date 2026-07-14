# 12 — Filtro por rango de fechas en el listado de recibos

> **Estado:** Implemented · **Depende de:** SPEC 11 · **Fecha:** 2026-07-06
> **Objetivo:** Agregar a `GET /api/v1/units/<unit_id>/receipts` un filtro opcional por rango de fechas (`date_from`/`date_to`) sobre `period_start`, que solo se aplica cuando los params vienen y deja el endpoint idéntico cuando no.

---

## Alcance

**Dentro de este spec:**

- **`resources/receipt.resource.inc`** (modificar) — parsear `date_from`/`date_to` desde `$_GET` en `myapi_receipt_list()`, validarlos, y pasarlos como filtro a las consultas de conteo y de datos.
- **`myapi_receipt_count($unit_id, ...)`** (modificar) — aceptar el rango de fechas y aplicarlo al `COUNT(*)`, agregando el join a `field_data_field_periodo` solo cuando hay filtro.
- **`myapi_receipt_fetch($unit_id, $page, $limit, $sort, ...)`** (modificar) — aplicar el mismo filtro de rango a la consulta paginada (ya tiene el join a `field_periodo`).
- **`docs/receipt.md`** (modificar) — documentar los dos query params nuevos, su formato, su comportamiento opcional y los casos borde.

**Fuera de este spec (para futuros specs):**

- **Filtrar sobre `period_end`** o lógica de solapamiento de rangos — el filtro es solo sobre `period_start`.
- **Filtrar por `status`, `total` u otro campo del recibo** — solo rango de fechas.
- **Devolver `422` por params inválidos** — los valores mal formados se ignoran silenciosamente, igual que `page`/`limit`/`sort`.
- **Cambiar paginación, orden o forma de respuesta** — el filtro solo reduce el conjunto; `total`/`total_pages` reflejan el conjunto ya filtrado.
- **Un nuevo endpoint o cambio de ruta** — se modifica el endpoint existente, sin tocar `myapi.module` ni `myapi.info`.

---

## Modelo de datos

Este filtro **no introduce estructuras de datos nuevas**. Reutiliza `field_data_field_periodo` (columna `field_periodo_value`, `varchar(20)`) del SPEC 11. Lo que se define aquí es el **contrato de los dos query params** y la **forma de la comparación en SQL**.

### Query params nuevos

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` (ISO) | ausente = sin límite inferior | Si es válido, filtra `period_start >= date_from`. |
| `date_to` | `YYYY-MM-DD` (ISO) | ausente = sin límite superior | Si es válido, filtra `period_start <= date_to`. |

### Validación (cae a "ignorar" sin `422`)

- Un valor se considera **válido** solo si matchea `YYYY-MM-DD` y es una fecha de calendario real (se valida con `checkdate()` tras separar año/mes/día). Cualquier otra cosa → se ignora ese límite, como si no hubiera venido.
- Cada límite es **independiente**: puede venir solo `date_from`, solo `date_to`, ambos o ninguno.
- Si vienen ambos y **`date_from > date_to`** (rango invertido), el filtro se ignora por completo y el endpoint responde como sin filtro (sin `422`).

### Forma de la comparación en SQL (punto de correctitud)

`field_periodo_value` es `varchar(20)` y podría estar almacenado como `2026-06-01` **o** como `2026-06-01T00:00:00`. Para que el límite superior incluya correctamente el último día sin importar el sufijo de hora, la comparación se hace sobre los **primeros 10 caracteres**:

```sql
-- solo cuando el límite correspondiente es válido
SUBSTR(fper.field_periodo_value, 1, 10) >= :date_from
SUBSTR(fper.field_periodo_value, 1, 10) <= :date_to
```

Como el formato `YYYY-MM-DD` ordena lexicográficamente igual que cronológicamente, `BETWEEN` sobre strings da el resultado correcto. Un recibo **sin fila** en `field_periodo` (`period_start = NULL`) queda **excluido** cuando hay filtro activo, porque no puede pertenecer a ningún rango de fechas.

---

## Plan de implementación

1. **Helper de parseo del rango en `receipt.resource.inc`.** Agregar una función `myapi_receipt_parse_date_range()` que lea `$_GET['date_from']` y `$_GET['date_to']`, valide cada uno con regex `YYYY-MM-DD` + `checkdate()`, descarte el rango invertido (`from > to`), y devuelva un array `['from' => string|NULL, 'to' => string|NULL]`. *Verificación: testeable a mano vía distintos `$_GET`; sin filtro devuelve `['from' => NULL, 'to' => NULL]`.*

2. **Aplicar el rango en `myapi_receipt_list()`.** Llamar al helper del paso 1 y pasar `$from`/`$to` tanto a `myapi_receipt_count()` como a `myapi_receipt_fetch()`. Sin cambios en la forma de respuesta. *Verificación: sin params, respuesta idéntica al SPEC 11.*

3. **Filtrar en `myapi_receipt_count()`.** Cambiar la firma a `myapi_receipt_count($unit_id, $from = NULL, $to = NULL)`. Cuando haya al menos un límite, agregar el `innerJoin` a `field_data_field_periodo` y las condiciones `SUBSTR(...,1,10) >= :from` / `<= :to` según corresponda. *Verificación: `total` refleja el conjunto filtrado.*

4. **Filtrar en `myapi_receipt_fetch()`.** Cambiar la firma a `myapi_receipt_fetch($unit_id, $page, $limit, $sort, $from = NULL, $to = NULL)`. Reutilizar el `leftJoin` existente a `field_periodo` agregando las mismas condiciones `SUBSTR(...)` cuando haya límites (con filtro activo, el `leftJoin` se comporta como filtro porque la condición sobre su columna excluye los `NULL`). *Verificación: la página devuelta solo trae recibos dentro del rango.*

5. **Actualizar `docs/receipt.md`.** Documentar `date_from`/`date_to`: formato ISO, opcionalidad, independencia de cada límite, filtrado sobre `period_start`, exclusión de recibos sin periodo, e ignorado silencioso de valores inválidos o rango invertido. *Verificación: doc coincide con el comportamiento implementado.*

6. **Aplicar y verificar.** `drush cc all` y `curl` sobre los casos de la sección de criterios de aceptación. *No requiere cambios en `myapi.module` ni `myapi.info` (no hay archivos ni rutas nuevas).*

---

## Criterios de aceptación

- [x] Sin `date_from` ni `date_to`, la respuesta es idéntica a la del SPEC 11 (mismos recibos, misma `pagination`).
- [x] Con `date_from=2026-06-01&date_to=2026-06-30`, solo se devuelven recibos cuyo `period_start` (primeros 10 caracteres) esté entre `2026-06-01` y `2026-06-30` inclusive.
- [x] Un recibo con `period_start = 2026-06-30` (o `2026-06-30T00:00:00`) queda **incluido** con `date_to=2026-06-30` (el límite superior no lo excluye por el sufijo de hora).
- [x] Solo `date_from=2026-06-01` (sin `date_to`) devuelve todos los recibos con `period_start >= 2026-06-01`.
- [x] Solo `date_to=2026-06-30` (sin `date_from`) devuelve todos los recibos con `period_start <= 2026-06-30`.
- [x] `pagination.total` y `total_pages` reflejan el conjunto **ya filtrado**, no el total de la unidad.
- [x] El filtro se combina correctamente con `page`, `limit` y `sort` (se filtra primero, luego se ordena y pagina).
- [x] `date_from` o `date_to` con formato inválido (p. ej. `2026-13-40`, `01-06-2026`, `hoy`) se ignora ese límite y el endpoint responde como si no viniera, sin `422`.
- [x] `date_from > date_to` (rango invertido) ignora el filtro completo y responde como sin filtro, sin `422`.
- [x] Recibos sin fila en `field_periodo` (`period_start = NULL`) quedan excluidos cuando hay al menos un límite activo.
- [x] El control de acceso (`403 unit_access_denied`), la autenticación (`401`) y el método (`405`) se comportan igual que en el SPEC 11.
- [x] `docs/receipt.md` documenta ambos params, su formato, opcionalidad y casos borde.
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombres de los params | `date_from` / `date_to` | `from`/`to`, `period_from`/`period_to` | Descriptivos y en snake_case como el resto del proyecto; evitan ambigüedad sobre qué se filtra. |
| Formato de entrada | ISO `YYYY-MM-DD` | `DD-MM-YYYY` (como el ejemplo del pedido) | Comparación directa contra la columna sin reparsear; menos superficie de error y ordena igual que cronológicamente. |
| Columna filtrada | Solo `period_start` (`field_periodo_value`) | Solapamiento con `period_end`, o filtrar por `period_end` | Es la columna de orden; para recibos mensuales `01-06`→`30-06` "trae todo junio"; más simple de razonar. |
| Límites independientes | Cada límite aplica por separado | Exigir ambos para filtrar | Más flexible (rango abierto por un lado) y consistente con el criterio laxo del endpoint. |
| Params inválidos | Ignorar silenciosamente (sin `422`) | Rechazar con `422` | Consistente con `page`/`limit`/`sort` del SPEC 11; los `422` se reservan para validación de body. |
| Rango invertido (`from > to`) | Ignorar el filtro completo | `422`, o devolver lista vacía | Mismo criterio laxo; evita un error por un input que probablemente es un descuido del cliente. |
| Forma de comparación SQL | `SUBSTR(field_periodo_value, 1, 10)` | Comparar la columna cruda | El campo es `varchar(20)` y puede o no tener sufijo `T00:00:00`; comparar los 10 primeros chars incluye correctamente el último día sin importar el formato almacenado. |
| Recibos sin periodo bajo filtro | Excluidos | Incluidos | Un recibo sin `period_start` no puede pertenecer a un rango de fechas; incluirlos contradiría el filtro. |
| Alcance del cambio | Modificar el endpoint existente | Endpoint nuevo `/receipts/filter` | El pedido es "que filtre si vienen los params, si no funciona como está"; es el mismo recurso, no uno nuevo. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Formato real de `field_periodo_value` desconocido.** `schema.sql` solo trae estructura (`varchar(20)`), no datos; no se confirmó si guarda `2026-06-01` o `2026-06-01T00:00:00`. | La comparación por `SUBSTR(...,1,10)` es correcta en ambos casos. Verificar en el paso 6 con datos reales que el borde `date_to = último día` incluye ese recibo. |
| **`SUBSTR` sobre la columna impide usar índice** en la condición de fecha. | Volumen acotado: la query ya filtra antes por `unit_id` (recibos de una sola unidad), así que el rango se evalúa sobre un conjunto pequeño; sin impacto práctico. |
| **`total`/`total_pages` deben usar el mismo filtro que la página.** Si el filtro se aplica solo en `fetch` y no en `count`, la paginación queda inconsistente. | Los pasos 3 y 4 aplican exactamente las mismas condiciones; criterio de aceptación explícito para `pagination.total` sobre el conjunto filtrado. |

---

## Lo que **no** entra en este spec

- Filtrar sobre `period_end` o solapamiento de rangos.
- Filtrar por `status`, `total` u otro campo.
- Devolver `422` por params inválidos o rango invertido.
- Cualquier cambio en paginación, orden, forma de respuesta o rutas.

Cada uno, si llega, va en su propio spec.
