# 118 — Paginación opt-in de las categorías de servicio (`GET /api/v1/service-categories`)

- **Estado:** Implemented — código y unit tests en verde (2867 tests, 12565 assertions); la verificación manual contra el sitio queda pendiente
- **Fecha:** 2026-09-01
- **Dependencias:**
  - `79-service-categories-list` (Implemented) — crea el endpoint, el orden alfabético por `name` sobre el catálogo completo, la regla laxa de `?sort` y el `?with_counts=1` con su **única** consulta agrupada. Este spec **modifica** esa respuesta: le añade una clave opcional y una condición para recortarla.
  - `83-providers-list` (Implemented) — de ahí sale, tal cual, la convención de paginación del módulo: `page` 1-based, `limit` acotado a `[1, 50]` con defecto `20`, valores inválidos que caen al defecto **en silencio** (nunca `422`), bloque `pagination: {total, page, limit, total_pages}` y `total_pages = 0` cuando `total` es `0`. Este spec no inventa forma nueva: la copia.
  - `77-services-content-types-install` (Implemented) — el vocabulario `service_category` y `MYAPI_SERVICES_CATEGORY_VOCABULARY`. No se toca ni un campo.

**Objetivo:** Permitir que la app pida el catálogo de categorías por páginas, **sin romper el contrato que SPEC 79 ya publicó**: sin `?page` y sin `?limit` la respuesta es exactamente la de hoy — todas las categorías y ninguna clave `pagination`.

---

## Motivación

El catálogo pasó de 8 a 64 términos al cargarse el catálogo completo con `scripts/seed-service-categories.php`. Una grilla de 64 iconos es un problema de la app, pero la app pidió poder pedir páginas.

**Lo que la paginación NO arregla, y conviene tener escrito:** `myapi_service_category_list()` carga el vocabulario entero con `taxonomy_get_tree()` + `entity_load()` y ordena en PHP con `usort()`. Una página es un `array_slice()` **después** de eso. No se ahorra ni una consulta de taxonomía; se ahorra payload (~12 KB con 64 términos) y, eso sí, la consulta de conteo pasa a cubrir solo los tids de la página. Quien lea esto buscando rendimiento de base de datos está en el spec equivocado: eso exigiría paginar en SQL sobre `taxonomy_term_data`, y entonces el orden alfabético dejaría de poder aplicarse en PHP.

---

## Alcance

**Dentro del alcance:**

- **`resources/service_category.resource.inc`** (modificar):
  - Lectura de `?page` y `?limit` **antes** de cargar el vocabulario, para que las salidas degradadas también respondan con el bloque.
  - `myapi_service_category_response()` (nueva) — ensambla el cuerpo con o sin `pagination`. Cuatro llamadas: vocabulario ausente, vocabulario vacío, y el final del camino normal.
  - El conteo de `?with_counts=1` se paga **después** del recorte, sobre los tids de la página.
- **Pruebas unitarias** — 15 casos nuevos en `tests/unit/ServiceCategoryEndpointTest.php`.
- **`docs/service-category.md`** (modificar) — los dos parámetros, el bloque, y la regla de compatibilidad.

**Fuera de alcance:**

- **Paginar en SQL.** Ver Motivación.
- **Filtro por texto (`?search=`) o por lista de códigos (`?codes=`).** Son otra decisión de producto; hoy la app filtra en local sobre el catálogo que ya tiene.
- **Cambiar el defecto.** Sin parámetros se sigue devolviendo todo, y esto **no** es una etapa hacia paginar siempre: la grilla necesita el catálogo entero para pintarse.
- **`?limit=-1` como "todo".** No hace falta: no pedir nada ya es todo.
- **Caché de la respuesta.** Igual que en SPEC 79.
- **Replicar esto en `/api/v1/banks` o `/api/v1/payment-methods`.** Cuando alguno crezca, será su propio spec de una línea.

---

## Contrato

| Param | Valores | Defecto | Notas |
|---|---|---|---|
| `page` | entero ≥ 1 | `1` | Cualquier otro valor (`0`, `-1`, `abc`, vacío, array) cae a `1` en silencio. |
| `limit` | entero 1–50 | `20` | Por encima de 50 se **recorta a 50**. Inválido → `20`. |

**La paginación se activa por la PRESENCIA de cualquiera de los dos, no por su validez.** `?page=abc` devuelve la página 1 de 20 con su bloque, no el catálogo completo: un cliente que pidió página siempre recibe página. Es la única forma de que el cliente no tenga que ramificar su parser.

```json
{
  "success": true,
  "data": {
    "service_categories": [ { "id": 31, "code": "electricidad", "…": "…" } ],
    "pagination": { "total": 64, "page": 2, "limit": 20, "total_pages": 4 }
  }
}
```

- `total` — el catálogo entero, nunca la página.
- `page` — **el que se pidió**, incluso más allá de la última página. Reescribirlo a la última escondería el bug del cliente.
- `limit` — el aplicado, ya recortado.
- `total_pages` — `ceil(total / limit)`, y **`0`** cuando `total` es `0`.

---

## Decisiones

1. **Opt-in y no defecto.** Si `limit` valiera 20 por defecto, toda app instalada dejaría de ver 44 de las 64 categorías sin cambiar una línea de código. El endpoint lleva meses publicado con la promesa "se devuelve todo, siempre"; romperla en silencio es la peor de las opciones disponibles.
2. **El bloque también en las salidas degradadas.** Vocabulario inexistente o vacío + `?page` → `pagination` con `total: 0` y `total_pages: 0`. Las dos degradaciones siguen respondiendo bytes idénticos entre sí, como exigía SPEC 79.
3. **El recorte va después del orden.** La página 2 es la segunda página *alfabética*, no el segundo trozo del orden de tids. Con `?sort=desc` es la segunda página del catálogo invertido.
4. **El conteo cubre la página.** Sigue siendo **una** consulta agrupada, pero su `IN` lista los tids respondidos. Efecto lateral asumido: el orden de esa lista `IN` ya no es el del árbol sino el alfabético — el test que lo fijaba pasa a comparar el conjunto, porque el orden de un `IN` nunca fue contrato.
5. **Una página vacía no consulta.** Página más allá de la última + `?with_counts=1` → cero consultas de conteo (`myapi_service_category_provider_counts([])` responde `[]` sin tocar la base).
6. **El ítem se reconstruye, no se le añade la clave.** Tras el recorte, los ítems con conteo vuelven a pasar por `myapi_service_category_build_item($term, $count)`: `providers_count` sigue siendo la séptima clave y las otras seis conservan su orden, sin una segunda copia de esa lógica en el listado.
7. **Nada devuelve `422`.** Coherente con `?sort` y `?with_counts` en este mismo endpoint y con `/api/v1/providers`.

---

## Verificación manual

```bash
# 1. Compatibilidad: sin parámetros, las 64 y ningún bloque.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories' | jq '.data | keys, (.service_categories | length)'

# 2. Página 2 de 20.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?page=2&limit=20' | jq '.data.pagination'

# 3. Más allá de la última: lista vacía, 200, page tal cual.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?page=99&limit=20' | jq '.data'

# 4. limit recortado a 50 y conteos solo de la página.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?limit=500&with_counts=1' | jq '.data.pagination'
```

No hay ruta nueva en `hook_menu()`, así que `drush cc all` no es obligatorio; basta con desplegar el resource.
