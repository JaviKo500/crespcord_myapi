# 119 — Búsqueda de categorías por nombre o descripción (`GET /api/v1/service-categories?search=`)

- **Estado:** Implemented — código y unit tests en verde (2893 tests, 12675 assertions); la verificación manual contra el sitio queda pendiente
- **Fecha:** 2026-09-01
- **Dependencias:**
  - `79-service-categories-list` (Implemented) — el endpoint, el orden alfabético, `?sort`, `?with_counts=1` y `myapi_text_to_plain()`, del que este spec reutiliza la descripción **ya aplanada** como campo buscable.
  - `118-service-categories-pagination` (Implemented) — `?page` / `?limit` y el bloque `pagination`, que este spec **apaga** cuando hay búsqueda.

**Objetivo:** Filtrar el catálogo por texto libre sobre `name` y `description`, sin distinguir mayúsculas ni acentos. Con `?search=` presente, la paginación se ignora por completo y se devuelven todas las coincidencias.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.text.inc`** (modificar) — `myapi_text_fold($value)` (nueva): minúsculas + acentos plegados, solo para comparar. Va a `includes/` y no al resource porque el buscador de proveedores tendrá exactamente el mismo problema (regla 3 de CLAUDE.md).
- **`resources/service_category.resource.inc`** (modificar) — lectura de `?search=`, `myapi_service_category_matches()` (nueva) y el filtro sobre los términos hidratados, antes de construir los ítems.
- **Pruebas unitarias** — `tests/unit/TextFoldTest.php` (nuevo) y 13 casos nuevos en `tests/unit/ServiceCategoryEndpointTest.php`. Más un stub de `drupal_strtolower()` en `tests/unit/bootstrap.php`.
- **`docs/service-category.md`** (modificar).

**Fuera de alcance:**

- **Buscar en SQL.** El endpoint ya carga el vocabulario entero para ordenarlo en PHP (SPEC 118, Motivación); filtrar en memoria sobre 64 cadenas cortas no cuesta nada y evita un `LIKE` sobre `field_data_field_*` que no podría mirar la descripción aplanada.
- **Buscar por `code`.** Ver decisión 3.
- **Búsqueda por palabras, prefijos o relevancia.** Es una coincidencia de subcadena, sin `AND` de tokens ni ranking: los ítems salen en el orden alfabético de siempre, no por lo bien que coinciden.
- **Longitud mínima del término.** Una letra busca; con 64 categorías devolver muchas coincidencias no es un problema.
- **Corrección ortográfica, sinónimos o plurales.** "plomerias" no encuentra "Plomería".
- **Replicarlo en `/api/v1/providers`** u otros listados. `myapi_text_fold()` queda disponible; aplicarlo es su spec.

---

## Contrato

| Param | Valores | Defecto | Notas |
|---|---|---|---|
| `search` | texto | *(off)* | Filtra por `name` **o** `description`, sin mayúsculas ni acentos. Vacío, solo espacios o un array = **no hay búsqueda**. Nunca `422`. |

- Con `?search=` con contenido: se devuelven **todas** las coincidencias, sin recorte y **sin clave `pagination`**, aunque viajen `?page` y `?limit`.
- Sin coincidencias: `200` con `{"service_categories": []}`.
- `?sort` y `?with_counts=1` siguen aplicando sobre el resultado filtrado.

---

## Decisiones

1. **La búsqueda manda sobre la paginación.** Lo pidió así el consumidor y además es lo correcto para la app: un resultado de búsqueda que llegara paginado obligaría a la pantalla de búsqueda a llevar estado de página mientras el usuario sigue escribiendo. Y para que el cliente no pueda confundir un resultado completo con la primera página de uno, el bloque `pagination` **no viaja**: su ausencia es la señal de "esto es todo".
2. **Plegado de acentos, no `LIKE`.** Los `code` son ASCII y los `name` llevan tilde. Sin plegar, "plomeria" no encuentra nada y "Plomer" sí, que es lo que un usuario lee como buscador roto. Se usa un `strtr()` con mapa propio y no `iconv('//TRANSLIT')` — que depende del locale del servidor y responde distinto en cada máquina — ni el módulo `transliteration`, que no es dependencia de este módulo.
3. **`code` no se busca.** Existe para que la app cuelgue sus iconos de algo estable, no como etiqueta. Lo único que hacía tentador buscarlo — escribir "plomeria" y encontrar "Plomería" — ya lo resuelve el plegado.
4. **Se filtra sobre los TÉRMINOS, no sobre los ítems ya construidos.** Así se compara contra el nombre que escribió el operador y no contra su `check_plain()`: una categoría "Cortes & instalaciones" es encontrable por "&", cosa que no sería contra el `&amp;` que viaja en la respuesta.
5. **La descripción se compara aplanada**, tal como se responde. Una palabra que solo existe en el marcado guardado (una clase CSS, un `href`) no debe hacer aparecer una categoría que el usuario no puede ver.
6. **Un buscador vaciado no es una búsqueda.** `?search=` y `?search=%20%20` responden el catálogo completo y **sí** paginan si se pidió: la app borra el texto y vuelve a la grilla sin tener que quitar el parámetro de la URL.
7. **Coincidencia de subcadena.** "eria" encuentra Plomería, Jardinería y Cerrajería. Con 64 cadenas cortas cuesta microsegundos y ninguna consulta.

---

## Verificación manual

```bash
# 1. Sin tildes encuentra con tildes.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?search=plomeria' | jq '.data.service_categories[].name'

# 2. Busca también en la descripción.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?search=cesped' | jq '.data.service_categories[].name'

# 3. La búsqueda ignora la paginación: no hay bloque pagination.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?search=eria&page=2&limit=1' | jq '.data | keys'

# 4. Un buscador vaciado sí pagina.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?search=&limit=10' | jq '.data.pagination'

# 5. Sin coincidencias: lista vacía y 200.
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://crespcord.lamotora.com/api/v1/service-categories?search=buceo' | jq '.'
```

No hay ruta nueva en `hook_menu()`; basta con desplegar el resource y el include.
