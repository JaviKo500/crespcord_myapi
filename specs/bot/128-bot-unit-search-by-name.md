# SPEC 128 — Búsqueda de unidad por condominio y nombre para el bot de WhatsApp

> **Estado:** Approved · **Depende de:** SPEC 03 (catálogo `myapi_t()`, `myapi_error()` / `myapi_respond()`), SPEC 08 (`myapi_unit_fetch_units()`, `myapi_unit_fetch_condominiums()` y la regla de descartar unidades cuyo condominio no es visible), SPEC 09 (`myapi_user_display_names()` en `includes/myapi.user.inc`), SPEC 119 (`myapi_text_fold()` en `includes/myapi.text.inc` — plegado de tildes y mayúsculas para comparar), SPEC 123 (`ModuleContractTest` / `EndpointContractTest` y el gate de cobertura), SPEC 127 (`resources/bot.resource.inc`, `myapi_bot_require_api_key()` en `includes/myapi.bot_auth.inc`, `includes/myapi.unit_query.inc` y el contrato de respuesta del bot) · **Fecha:** 2026-09-16
>
> **Objetivo:** Exponer `GET /api/v1/bot/units?condominium=…&unit=…`, autenticado con la misma API key de máquina que la SPEC 127, que resuelve el nombre de un condominio y el de una unidad —ambos obligatorios, escritos como los teclee una persona por WhatsApp: sin tildes, en minúsculas, con separadores distintos o con una letra equivocada— a un máximo de cinco unidades visibles con su propietario, para que el bot sepa a qué unidad imputar un comprobante de pago cuando el teléfono no identificó a nadie.

**El porqué de este endpoint, como continuación de la 127:** la SPEC 127 responde "¿quién es este teléfono?". Cuando responde `found: false` —`not_found`, `ambiguous` o `no_units`— el §8.2.2 del anexo manda al bot a preguntar por WhatsApp el edificio y la unidad. Este endpoint es lo que el bot llama con esas dos respuestas en la mano: el segundo camino hacia la misma `unit_id`, por nombre en vez de por teléfono. El flujo conversacional siempre pregunta las dos cosas, y por eso el endpoint exige las dos.

**Por qué los dos parámetros son obligatorios:** con 35.000 viviendas y 150 condominios, `?unit=3b` a secas recorre la tabla entera y "3B" existe en casi todos los edificios; cinco resultados de entre ciento y pico coincidencias reales son ruido, no una respuesta. El condominio es lo que acota la búsqueda a unas 230 filas y hace viable la pasada aproximada. Y un `?condominium=torre azul` sin unidad sería un listado del edificio entero recortado a cinco: tampoco responde a nada que el bot necesite.

**Lo que este endpoint no es:** no registra pagos ni escribe nada, igual que su hermano de la 127. Responde *qué unidades se parecen a lo que escribió la persona*, y con qué confianza.

---

## Alcance

### Dentro de este spec

**Código de producción**

- **`resources/bot.resource.inc`** (modificar) — el segundo endpoint del bot vive en el mismo archivo, que la SPEC 127 creó ya con esa intención ("un archivo por consumidor, un dispatcher por ruta"):
  - `myapi_bot_units_dispatch()` — enruta por método; solo `GET`, el resto `405`.
  - `myapi_bot_units_search()` — el handler.
  - `myapi_bot_search_normalize($value)` — plegado con `myapi_text_fold()` más la eliminación de espacios, guiones, puntos, comas, `#` y `/`. Pura; es la que sostiene la mitad de los tests.
  - `myapi_bot_search_tokens($value)` — parte el término en palabras plegadas, sin separadores internos. Pura. Es lo que hace que `"azul torre"` encuentre `"Edificio Torre Azul"`.
  - `myapi_bot_fuzzy_match($needle, $haystack)` — los umbrales acordados (`levenshtein` ≤ 1 para 4–6 caracteres, ≤ 2 para 7+, o `similar_text` ≥ 80%; nunca por debajo de 4). Pura.
  - `myapi_bot_match_rank($term, $value)` — devuelve la calidad de la coincidencia (`exact`, `prefix`, `contains`, `fuzzy`) o `NULL` si no coincide. Pura, y es la que define el orden del resultado.
  - `myapi_bot_match_condominiums($term, array $condominiums)` — la primera fase de la cascada, sobre los 150 títulos.
  - `myapi_bot_match_units($term, array $units)` — la segunda, sobre las unidades de los condominios ya resueltos.
  - `myapi_bot_build_search_results(...)` — arma los elementos, ordena y recorta a cinco.

- **`includes/myapi.unit_query.inc`** (modificar) — dos consultas nuevas, en el archivo que la SPEC 127 creó exactamente para esto:
  - `myapi_unit_fetch_all_condominiums()` — `nid` y `title` de todos los `condominio` publicados. Son 150: caben en memoria y se comparan en PHP, que es lo que permite plegar tildes y aplicar el aproximado sin depender de la colación de MySQL.
  - `myapi_unit_fetch_unit_nids_by_condominium(array $condominium_nids)` — los `nid` de las `vivienda` publicadas que cuelgan de esos condominios. El detalle de cada una lo sigue dando `myapi_unit_fetch_units()`, sin duplicar una línea de consulta.

- **`myapi.module`** (modificar) — registrar `api/v1/bot/units` en `hook_menu()`, junto a `api/v1/bot/person`, con `'access callback' => TRUE` y `'file' => 'resources/bot.resource.inc'`.

- **`includes/myapi.i18n.inc`** (modificar) — cuatro claves nuevas en los dos idiomas: `missing_condominium`, `missing_unit`, `invalid_condominium` e `invalid_unit`. El `401` reutiliza `unauthorized` y el `405` reutiliza `method_not_allowed`, ambos ya existentes.

- **`myapi.info`** — **no cambia**. Los dos archivos que se tocan ya están declarados desde la SPEC 127.

**Documentación**

- **`docs/bot.md`** (modificar) — una segunda sección `## GET /api/v1/bot/units` con la plantilla estándar, en el mismo commit que el código. `ModuleContractTest::testEveryEndpointIsDocumented()` lo exige.

**Tests**

- **`tests/unit/BotUnitsSearchTest.php`** (nuevo) — normalización, tokens, umbrales del aproximado, orden del ranking, recorte a cinco, cascada condominio→unidad, unidad y condominio despublicados, `405` y los cuatro `422`.
- **`tests/unit/EndpointContractTest.php`** (modificar) — añadir `'api/v1/bot/units [GET]'` a la allowlist `API_KEY_ENDPOINTS` que la SPEC 127 introdujo, con su justificación, para que los dos tests existentes ("sin `X-Api-Key` responde 401" y "una petición sin credencial no llega a ninguna tabla") lo cubran sin escribir ninguno nuevo.

**Operación**

- Ninguna. Reutiliza `myapi_bot_api_key` tal cual: la misma clave, el mismo `drush vset`, la misma rotación. Un segundo endpoint para el mismo consumidor no justifica una segunda credencial.

### Fuera de este spec

- **Registrar el pago** (§8.2.4 del anexo) — lo hace el endpoint de pagos que ya existe, igual que en la 127.
- **Buscar solo por condominio, o solo por unidad** — los dos parámetros son obligatorios. Ver Decisiones.
- **Paginación de resultados** — cinco como máximo y `total` para saber que hay más; quien necesite el sexto afina el término.
- **Búsqueda por propietario, teléfono, cédula o email** — el teléfono ya es la SPEC 127; el resto no lo pide el anexo.
- **Ocupantes de la unidad** — solo el propietario viaja. Duplicar la superficie de datos personales no compra nada en el §8.2.4.
- **Saldo (`current_balance`) e información de pago del condominio** — al alcance de la consulta y aun así no salen, por la misma decisión de la 127.
- **Índice persistente de nombres plegados** (columna normalizada, `FULLTEXT`, o `hook_node_presave()`) — no hace falta con la cascada acotada; queda en Riesgos como el camino si deja de bastar.
- **Caché persistente de los 150 títulos de condominio** (`cache_set()` / `cache_get()`) — caché estática por petición y nada más. Ver Decisiones.
- **Rate limiting / Flood** — mismo argumento que la 127: la clave es un secreto aleatorio, y lo que sí entra es el `watchdog` de cada rechazo, que ya escribe `myapi_bot_require_api_key()`.
- **Segunda API key, o claves con nombre y revocación individual** — sigue habiendo un solo consumidor.
- **Escritura de cualquier tipo** — `GET` y nada más.
- **El flujo conversacional de n8n** cuando hay cero resultados o cinco ambiguos — es lógica del bot, no de Drupal.

---

## Modelo de datos

**No se crea ninguna tabla ni ninguna variable.** El spec introduce dos consultas nuevas en un archivo que ya existe, un algoritmo de comparación y un contrato de respuesta. La credencial es la misma `myapi_bot_api_key` de la SPEC 127.

### Tablas consultadas (todas existentes)

| Tabla | Columnas | Uso |
|---|---|---|
| `node` (condominio) | `nid`, `title`, `type`, `status` | Los 150 títulos publicados, cargados enteros por petición. Es la primera fase de la cascada. |
| `node` (vivienda) + `field_data_field_condominio` | `nid`, `status`, `field_condominio_target_id` | Los `nid` de las viviendas publicadas de los condominios resueltos. Acota la segunda fase a ~230 filas por condominio en vez de a 35.000. |
| `field_data_field_nombre_vivienda` | `entity_id`, `field_nombre_vivienda_value` | El nombre de la unidad, vía `myapi_unit_fetch_units()`. |
| `field_data_field_propietario` | `entity_id`, `field_propietario_target_id` | El `uid` del propietario, que `myapi_unit_fetch_units()` ya devuelve como `owner_uid`. |
| `users`, `field_data_field_nombre`, `field_data_field_apellidos` | — | El nombre visible del propietario, vía `myapi_user_display_names()`, que ya cae a `users.name` si falta alguna parte. |

Ninguna consulta se escribe dos veces: el detalle de la unidad lo sigue dando `myapi_unit_fetch_units()` tal cual, y lo nuevo son solo las dos consultas que deciden **qué nids** pedirle.

### Normalización de los términos

Una sola definición, aplicada a los dos lados de cada comparación:

```
normalize(v) = preg_replace('/[\s\-._,#\/]+/', '', myapi_text_fold(v))
```

`myapi_text_fold()` (SPEC 119) ya quita tildes y baja a minúsculas; lo que añade este spec es la eliminación de separadores, que es lo que hace que `3b` encuentre `Dpto 3-B`.

| Escrito / guardado | Normalizado |
|---|---|
| `torre azul` | `torreazul` |
| `Edificio Torre Azul` | `edificiotorreazul` |
| `3B` | `3b` |
| `Dpto 3-B` | `dpto3b` |
| `Depto. #3 B` | `depto3b` |
| `Climatización` | `climatizacion` |

**Tokens.** Además del valor normalizado completo, el término se parte en palabras (por los separadores originales) y cada una se normaliza por separado: `"azul torre"` → `["azul", "torre"]`. Coincide si **todas** las palabras aparecen en el valor normalizado, en cualquier orden. Es lo que hace que quien escribe deprisa por WhatsApp no tenga que acertar el orden.

### Calidad de la coincidencia

`myapi_bot_match_rank($term, $value)` devuelve uno de cuatro valores, o `NULL` si no coincide. Define a la vez qué entra y en qué orden sale:

| Rango | Cuándo | Peso |
|---|---|---|
| `exact` | `normalize(term) === normalize(value)` | 0 |
| `prefix` | `normalize(value)` empieza por `normalize(term)` | 1 |
| `contains` | `normalize(term)` aparece dentro de `normalize(value)`, **o** todos los tokens del término aparecen en él | 2 |
| `fuzzy` | Solo en la segunda pasada, y solo si la primera devolvió cero. Ver umbrales. | 3 |

### Los umbrales del aproximado

`myapi_bot_fuzzy_match($needle, $haystack)` acepta si, y solo si:

| Longitud de `normalize(needle)` | Condición |
|---|---|
| < 4 caracteres | **Nunca** coincide. Con menos de cuatro, una letra de diferencia es otra cosa, no un error de tecleo. |
| 4–6 caracteres | `levenshtein` ≤ 1 |
| 7 o más | `levenshtein` ≤ 2, **o** `similar_text` ≥ 80% |

La comparación se hace contra el valor completo y contra cada palabra del valor: `"torre asul"` tiene que encontrar `"Edificio Torre Azul"`, y la distancia contra la cadena entera es demasiado grande para eso.

**La guarda de las unidades cortas.** El aproximado se aplica al condominio sin restricción, y a la unidad **solo si el término tiene 4 o más caracteres tras normalizar**. `"dpto 3b"` (`dpto3b`, 6) entra; `"3b"` (2) no. El motivo es concreto: `3B` y `3C` están a distancia 1, y corregir el uno por el otro entrega la unidad del vecino con un comprobante de pago detrás.

### La cascada

Nunca se carga la tabla de viviendas entera. El orden es el que hace que esto funcione con 35.000 filas:

1. **Validar** los dos parámetros (presentes, y ≥ 2 caracteres tras normalizar).
2. **Resolver el condominio** sobre los 150 títulos, en memoria: primera pasada literal (`exact`/`prefix`/`contains`); si devuelve cero, segunda pasada aproximada. Si sigue en cero, la respuesta es vacía y **no se toca la tabla de viviendas**.
3. **Acotar**: los `nid` de las viviendas publicadas de esos condominios.
4. **Resolver la unidad** sobre ese conjunto: primera pasada literal; si cero, segunda aproximada con la guarda de longitud.
5. **Ordenar, contar y recortar**: `total` se calcula **antes** del recorte, y salen como mucho cinco.

### El tope de condominios

Un término como `"ed"` puede casar con los cien condominios que empiezan por "Edificio", y el paso 3 se convertiría en un escaneo de 23.000 viviendas. El paso 2 se queda con los **20 mejores** por rango y, a igualdad, por título alfabético (`MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT = 20`). `total` cuenta las coincidencias dentro de esos 20, y la documentación lo dice con esas palabras: es un buscador para afinar, no un informe.

### Orden de los resultados

Determinista, para que dos llamadas iguales devuelvan lo mismo:

1. Peso del rango de la **unidad** (0–3).
2. Peso del rango del **condominio** (0–3).
3. Título del condominio normalizado, ascendente.
4. Nombre de la unidad normalizado, ascendente.
5. `unit_id`, ascendente — el desempate final, para que nunca quede al azar de MySQL.

### Contrato de respuesta

**Con resultados** — 200:

```json
{
  "success": true,
  "data": {
    "found": true,
    "total": 2,
    "units": [
      {
        "unit_id": 456,
        "unit": "Dpto 3B",
        "condominium_id": 12,
        "condominium": "Edificio Torre Azul",
        "owner": { "uid": 123, "name": "Juan Pérez" },
        "match": "exact"
      },
      {
        "unit_id": 789,
        "unit": "Dpto 3B",
        "condominium_id": 31,
        "condominium": "Torre Azul II",
        "owner": null,
        "match": "fuzzy"
      }
    ]
  }
}
```

**Sin resultados** — 200, misma forma siempre, una sola rama en n8n:

```json
{ "success": true, "data": { "found": false, "total": 0, "units": [] } }
```

| Campo | Qué es |
|---|---|
| `found` | `true` cuando `units` no está vacío. Existe por simetría con la SPEC 127, no porque aporte información nueva. |
| `total` | Coincidencias reales antes del recorte, dentro de los 20 condominios examinados. `total > 5` significa "afina el término", y el bot puede decirlo en vez de mostrar cinco como si fueran todas. |
| `owner` | `{ uid, name }`, o `null` si la unidad no tiene propietario asignado o el `uid` ya no existe. Nunca el teléfono, la cédula ni el email. |
| `match` | `"exact"` cuando la unidad **y** el condominio se resolvieron sin aproximar (incluye `prefix` y `contains`: son coincidencias literales). `"fuzzy"` cuando cualquiera de los dos necesitó la segunda pasada. El bot pregunta "¿te refieres a…?" solo en el segundo caso. |

Ni `current_balance` ni `payment_information` viajan, igual que en la SPEC 127.

**Errores** — envelope estándar de `myapi_error()`:

| HTTP | `error_code` | Cuándo |
|---|---|---|
| 401 | `unauthorized` | `X-Api-Key` ausente, vacía o distinta de la configurada. También cuando la variable no está configurada. |
| 405 | `method_not_allowed` | Cualquier método que no sea `GET`. |
| 422 | `missing_condominium` | Falta `condominium` o viene vacío. |
| 422 | `missing_unit` | Falta `unit` o viene vacío. |
| 422 | `invalid_condominium` | `condominium` queda en menos de 2 caracteres tras normalizar. |
| 422 | `invalid_unit` | `unit` queda en menos de 2 caracteres tras normalizar. |

---

## Plan de implementación

Cada paso deja la suite en verde y el módulo funcional. Los pasos 1–3 no cambian ninguna respuesta de la API; el endpoint nace en el paso 4.

**1. Ampliar el catálogo i18n**

- `includes/myapi.i18n.inc`: `missing_condominium`, `missing_unit`, `invalid_condominium` e `invalid_unit`, en `'en'` y en `'es'`. Los mensajes en español son los que leerá quien depure el flujo de n8n, no el residente: el bot nunca muestra un `error` de estos por WhatsApp, porque todos son bugs del flujo.
- **Verificación:** el test de la SPEC 123 que comprueba que las dos ramas del catálogo tienen las mismas claves sigue en verde.

**2. Las dos consultas de acotación**

- `includes/myapi.unit_query.inc`, junto a las dos que ya viven ahí:
  - `myapi_unit_fetch_all_condominiums()` — `nid` y `title` de `node` con `type = 'condominio'` y `status = 1`. Sin argumentos y sin filtro: son 150 y se quieren todos. Devuelve un mapa `nid => title`.
  - `myapi_unit_fetch_unit_nids_by_condominium(array $condominium_nids)` — `db_select('node')` con `type = 'vivienda'`, `status = 1`, `innerJoin` a `field_data_field_condominio` con `entity_type = 'node'` y `deleted = 0`, y `field_condominio_target_id IN (:nids)`. Devuelve una lista plana de `nid`. Array vacío entra y array vacío sale, sin lanzar la consulta.
- **Verificación:** nadie las llama todavía; `UnitQueriesTest` sigue pasando y `GET /api/v1/units` no cambia. Tests propios de las dos funciones en el paso 3.

**3. Los helpers puros de comparación**

Todos en `resources/bot.resource.inc`, todos sin base de datos, y por eso todos testables de inmediato:

- `MYAPI_BOT_SEARCH_MIN_LENGTH = 2`, `MYAPI_BOT_SEARCH_MAX_RESULTS = 5`, `MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT = 20`, `MYAPI_BOT_SEARCH_FUZZY_MIN_LENGTH = 4` — las cuatro constantes del algoritmo, con nombre, para que cambiarlas sea cambiar una línea y no buscar un número suelto.
- `myapi_bot_search_normalize($value)` — `myapi_text_fold()` y luego `preg_replace('/[\s\-._,#\/]+/', '', …)`.
- `myapi_bot_search_tokens($value)` — parte por los separadores del valor original y normaliza cada trozo; descarta los vacíos.
- `myapi_bot_fuzzy_match($needle, $haystack)` — la tabla de umbrales, comparando contra el valor completo y contra cada palabra del valor.
- `myapi_bot_match_rank($term, $value, $allow_fuzzy)` — `exact` / `prefix` / `contains` / `fuzzy` / `NULL`. El `$allow_fuzzy` es lo que implementa las dos pasadas: la primera lo pasa `FALSE`, la segunda `TRUE`.
- `tests/unit/BotUnitsSearchTest.php` (primera mitad): normalización con tildes, guiones, puntos y `#`; tokens desordenados; los tres tramos de longitud del aproximado; la guarda de los términos de menos de 4; el rango devuelto en cada caso.
- **Verificación:** la suite pasa y el módulo se comporta exactamente igual que antes; nada llama todavía a estas funciones.

**4. El recurso, la ruta y la documentación (un solo commit)**

Es atómico por obligación: `ModuleContractTest::testEveryDispatcherIsRouted()` y `testEveryEndpointIsDocumented()` se ponen rojos si el dispatcher, la ruta y el `.md` no llegan juntos, y `EndpointContractTest` lo hace si la allowlist no viene en el mismo commit.

- `resources/bot.resource.inc`, después de los helpers del paso 3:
  - `myapi_bot_match_condominiums($term, array $condominiums)` — dos pasadas sobre el mapa `nid => title`; devuelve `nid => rango` con los `MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT` mejores, ordenados por rango y título.
  - `myapi_bot_match_units($term, array $units)` — dos pasadas sobre las filas de `myapi_unit_fetch_units()`, con la guarda de longitud en la segunda; devuelve `nid => rango`.
  - `myapi_bot_build_search_results(array $units, array $unit_ranks, array $condominium_ranks, array $condominium_titles, array $owner_names)` — arma los elementos con `owner` y `match`, ordena por los cinco criterios y devuelve la lista **completa**; el recorte lo hace el handler, que es quien necesita `total`.
  - `myapi_bot_units_search()` — `myapi_bot_require_api_key()`; los dos parámetros de `$_GET`; los cuatro `422`; la cascada; `myapi_user_display_names()` sobre los `owner_uid` no nulos **de los cinco que salen**, no de todos; `myapi_respond()`.
  - `myapi_bot_units_dispatch()` — `GET` al handler, el resto `myapi_error('method_not_allowed', 405)`.
- `myapi.module`: `$items['api/v1/bot/units']` con `'page callback' => 'myapi_bot_units_dispatch'`, `'access callback' => TRUE`, `MENU_CALLBACK` y `'file' => 'resources/bot.resource.inc'`, junto a la ruta de la SPEC 127 y bajo el mismo comentario.
- `tests/unit/EndpointContractTest.php`: añadir `'api/v1/bot/units [GET]'` a `API_KEY_ENDPOINTS` con su justificación.
- `docs/bot.md`: segunda sección con la plantilla estándar, incluida la nota de que `total` cuenta dentro de los 20 condominios examinados y de que `match: "fuzzy"` significa que el bot debería confirmar antes de imputar.
- **Verificación:** `drush cc all`, un `curl` con la key correcta y otro sin ella, y `ModuleContractTest` entero.

**5. Los tests del handler**

- `tests/unit/BotUnitsSearchTest.php` (segunda mitad): `"torre azul"` + `"3B"` encuentra `Dpto 3-B` de `Edificio Torre Azul`; `"azul torre"` lo encuentra igual; `"torre asul"` lo encuentra con `match: "fuzzy"`; `"3b"` **no** se aproxima a `3C`; `"dpto 3b"` sí se aproxima a `Dpto 3-C`; unidad despublicada y condominio despublicado quedan fuera; más de cinco coincidencias devuelven cinco y el `total` real; el orden es el de los cinco criterios; `owner` es `null` sin propietario; `405` en `POST`/`PUT`/`DELETE`; los cuatro `422`; y que un término de condominio que no casa con nada **no lanza la consulta de viviendas**.
- **Verificación:** suite completa en verde en PHP 7.4 y el gate de cobertura de la SPEC 123 sin bajar.

**6. Puesta en marcha**

- `drush cc all`. No hay `drush vset`: la clave es la misma de la SPEC 127.
- Verificación contra datos reales: un condominio con tilde en el nombre buscado sin tilde, una unidad con guion buscada sin él, y un nombre de edificio con una letra cambiada.
- Medir el tiempo de la llamada con el término de condominio más común de la base. Si el peor caso se acerca al segundo, el camino está en Riesgos.

---

## Criterios de aceptación

Cada línea es verificable con un `curl`, un test o una consulta.

**Autenticación y método**

- [ ] `GET /api/v1/bot/units?condominium=torre%20azul&unit=3B` **sin** header `X-Api-Key` responde `401` con `error_code: "unauthorized"`.
- [ ] Con una `X-Api-Key` distinta de la configurada responde `401` y deja una entrada `WATCHDOG_WARNING` con la ruta y la IP.
- [ ] Una petición sin credencial válida no ejecuta ninguna consulta: `EndpointContractTest` lo comprueba contando las consultas del stub, con la ruta ya en `API_KEY_ENDPOINTS`.
- [ ] `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` y `OPTIONS` sobre la ruta responden `405` con `error_code: "method_not_allowed"`.
- [ ] La clave que autentica es la misma `myapi_bot_api_key` de la SPEC 127: no se introduce ninguna variable nueva.

**Parámetros**

- [ ] Sin `condominium`, o con `condominium=`, responde `422` con `error_code: "missing_condominium"`.
- [ ] Sin `unit`, o con `unit=`, responde `422` con `error_code: "missing_unit"`.
- [ ] Con `condominium=-` (queda en 0 caracteres tras normalizar) o `condominium=a` responde `422` con `error_code: "invalid_condominium"`.
- [ ] Con `unit=3` responde `422` con `error_code: "invalid_unit"`.
- [ ] Cuando falten los dos parámetros, la respuesta es un único `422`: el primero que se valida es `condominium`.

**Normalización**

- [ ] Para un condominio guardado como `Edificio Torre Azul` y una unidad guardada como `Dpto 3-B`, la consulta `?condominium=torre%20azul&unit=3B` la encuentra.
- [ ] `?condominium=TORRE%20AZUL`, `?condominium=Torre%20Azul` y `?condominium=torre-azul` devuelven el mismo resultado.
- [ ] Un condominio guardado como `Climatización` se encuentra con `?condominium=climatizacion`, sin tilde.
- [ ] `?unit=3b`, `?unit=3-B`, `?unit=3%20B` y `?unit=%23%203B` encuentran todas `Dpto 3-B`.
- [ ] `?condominium=azul%20torre` encuentra `Edificio Torre Azul`: los tokens coinciden en cualquier orden.

**Aproximado**

- [ ] `?condominium=torre%20asul` encuentra `Edificio Torre Azul`, y el elemento devuelto lleva `match: "fuzzy"`.
- [ ] `?condominium=torrre%20azul` (letra doble) lo encuentra igual.
- [ ] La pasada aproximada **solo** se ejecuta cuando la literal devolvió cero: si `?condominium=torre` casa literalmente con tres edificios, no aparece ninguno por similitud.
- [ ] `?unit=3b` **no** devuelve `Dpto 3C`: con menos de 4 caracteres tras normalizar no hay aproximado.
- [ ] `?unit=dpto%203b` sí puede devolver `Dpto 3-C`, con `match: "fuzzy"`.
- [ ] Un resultado en el que condominio y unidad coincidieron literalmente —aunque sea por `prefix` o por `contains`— lleva `match: "exact"`.

**Resolución y contrato**

- [ ] Coincidencia única → `200`, `found: true`, `total: 1`, un elemento con `unit_id`, `unit`, `condominium_id`, `condominium`, `owner` y `match`.
- [ ] Sin ninguna coincidencia → `200` con `{ "found": false, "total": 0, "units": [] }`. Ningún caso devuelve `404`.
- [ ] Con más de cinco coincidencias, `units` trae exactamente cinco y `total` trae el número real.
- [ ] Dos llamadas idénticas devuelven los mismos cinco elementos en el mismo orden.
- [ ] El orden respeta los cinco criterios: rango de unidad, rango de condominio, título de condominio, nombre de unidad, `unit_id`.
- [ ] Una unidad despublicada (`node.status = 0`) no aparece nunca.
- [ ] Una unidad cuyo condominio está despublicado no aparece nunca, ni siquiera buscando el nombre exacto del condominio.
- [ ] Una unidad sin propietario asignado aparece con `owner: null`, no se omite.
- [ ] Ningún elemento contiene `current_balance` ni `payment_information`, y la respuesta no expone el teléfono, la cédula ni el email del propietario.
- [ ] Todas las claves del JSON están en inglés y en `snake_case`, iguales en nombre y tipo a las de `GET /api/v1/bot/person`.

**Rendimiento y estructura**

- [ ] Un término de condominio que no casa con nada **no lanza** la consulta de viviendas: el test lo comprueba contando las consultas del stub.
- [ ] Un término de condominio que casa con más de 20 edificios examina exactamente 20.
- [ ] `resources/bot.resource.inc` no llama a ninguna función definida en otro archivo de `resources/`.
- [ ] `myapi.info` no cambia: los dos archivos tocados ya estaban declarados.
- [ ] `GET /api/v1/units` y `GET /api/v1/bot/person` devuelven exactamente lo mismo que antes de esta spec.
- [ ] `docs/bot.md` documenta el endpoint con la plantilla estándar, incluida la advertencia sobre `total` y sobre `match: "fuzzy"`.
- [ ] `ModuleContractTest` pasa entero y la suite completa (`vendor/bin/phpunit`) pasa en PHP 7.4 sin bajar el gate de cobertura de la SPEC 123.

---

## Decisiones tomadas y descartadas

**Los dos parámetros obligatorios** — descartado `condominium` obligatorio y `unit` opcional, que era la propuesta inicial. Un `?condominium=torre%20azul` sin unidad es el listado del edificio recortado a cinco, y ninguna rama del §8.2.2 lo necesita: el flujo conversacional pregunta las dos cosas. Descartado también permitir `?unit=3b` a secas — con 35.000 viviendas recorre la tabla entera, y `3B` existe en casi todos los 150 edificios: cinco de entre ciento y pico coincidencias reales son ruido, no una respuesta.

**`units` en plural y `snake_case`** — la propuesta original llamaba `unit` a un array y usaba `unitId` / `condominiumId`. Se descarta: la SPEC 127 ya publicó `unit_id`, `unit`, `condominium_id`, `condominium` dentro de `units`, y dos formas distintas de nombrar lo mismo obligan a n8n a mantener dos mapeos de los dos endpoints del mismo bot.

**El segundo endpoint en el mismo `resources/bot.resource.inc`** — la SPEC 127 creó ese archivo diciendo explícitamente que el bot iba a necesitar más endpoints, con un dispatcher por ruta. Este es el segundo, y no justifica un `bot_units.resource.inc`.

**La misma API key, sin credencial nueva** — sigue habiendo exactamente un consumidor, n8n. `myapi_bot_require_api_key()` se reutiliza sin tocar una línea.

**Comparar en PHP sobre los 150 títulos, no con `LIKE` en SQL** — 150 filas caben en memoria, y traerlas enteras permite plegar tildes, partir en tokens y aplicar distancia sin depender de la colación de la base. Descartado el `LIKE` con comodín entre carácter y carácter que usa la SPEC 127 para el teléfono: allí el conjunto era un campo de usuario y aquí es una tabla de 150 filas, donde el truco solo añade una dependencia de la colación a cambio de nada. Descartado también `utf8_general_ci` como fuente de la insensibilidad a tildes: funciona, pero deja el comportamiento del endpoint escrito en la configuración del servidor en vez de en el código, y deja de funcionar el día que alguien migre a `utf8mb4_bin`.

**Eliminar separadores en los dos lados, no solo plegar** — `myapi_text_fold()` de la SPEC 119 resuelve tildes y mayúsculas, pero `3b` no encuentra `Dpto 3-B` sin quitar el guion, que era el ejemplo concreto del requisito. Los separadores que se eliminan son espacio, `-`, `.`, `,`, `#` y `/`, que es lo que la gente mete en el nombre de una vivienda.

**Tokens en `AND`, en cualquier orden** — `"azul torre"` encuentra `"Edificio Torre Azul"`. Descartado exigir el orden escrito: quien escribe a un bot por WhatsApp no lo respeta. Descartado el `OR` entre tokens, que haría que `"torre"` sola arrastrara la mitad de los edificios con cualquier segunda palabra.

**Aproximado solo como segunda pasada, cuando la primera dio cero** — descartado mezclar exactos y aproximados en un mismo ranking. El camino normal no paga el coste del cálculo, y una búsqueda que ya funcionaba no empieza a devolver vecinos parecidos. El precio es que `?condominium=torre` no ofrece `Torres del Río` si `Torre Azul` casa literalmente, y es un precio que se paga con gusto.

**Umbrales `levenshtein` ≤ 1 (4–6), ≤ 2 (7+) o `similar_text` ≥ 80%** — cerrados con esos números para que el comportamiento sea discutible con datos y no con opiniones. Están en constantes con nombre precisamente porque son lo primero que se va a querer tocar. Descartado `SOUNDEX` / `METAPHONE` de MySQL: están diseñados para fonética inglesa y en español producen basura. Descartado un índice `FULLTEXT`: exige cambiar el esquema de tablas de Field API, que Drupal 7 gestiona, y no tolera el error de una letra, que es justo lo que había que cubrir.

**El aproximado de la unidad solo con 4+ caracteres** — es la única asimetría del algoritmo y es deliberada. `3B` y `3C` están a distancia 1: corregir el uno por el otro entrega la unidad del vecino con un comprobante de pago detrás. En el condominio equivocarse cuesta una pregunta de más; en la unidad cuesta dinero. `"dpto 3b"` (6 caracteres) sí entra, porque ahí la distancia mide el prefijo escrito, no el número.

**Tope de 20 condominios, no `422 ambiguous_condominium`** — un término de dos letras puede casar con los cien edificios que empiezan por "Edificio", y sin tope el paso siguiente escanea 23.000 viviendas. Descartado responder `422` cuando el término casa con más de 20: es más honesto, pero añade una quinta clave de error y una rama más en n8n para un caso que el flujo conversacional ya resuelve pidiendo un nombre más largo. El precio es que `total` cuenta dentro de los 20 examinados y no de los 150, y la documentación lo dice con esas palabras.

**Cinco resultados y `total`, sin paginación** — descartado devolver solo cinco sin decir cuántos hay: el bot no podría distinguir "estas son todas" de "hay 40, afina el nombre", y mostraría cinco como si fueran la respuesta completa. Descartada la paginación: quien necesita el sexto resultado necesita escribir mejor el nombre, no pedir otra página.

**`match: "exact"` incluye `prefix` y `contains`** — "exact" significa *sin aproximar*, no *idéntico*. Descartado exponer los cuatro rangos internos (`exact`/`prefix`/`contains`/`fuzzy`): n8n solo tiene dos comportamientos —imputar, o confirmar antes de imputar— y una tercera etiqueta sería un campo que nadie lee. Los cuatro rangos siguen existiendo dentro, porque son los que definen el orden.

**`found` se queda, aunque aquí no aporte nada** — es redundante con `units` no vacío, a diferencia de la SPEC 127, donde distinguía tres razones. Se mantiene por simetría: los dos endpoints del bot se leen igual, y n8n comprueba el mismo campo en los dos.

**Siempre 200, nunca 404** — mismo argumento que la 127: un 404 se confunde con una ruta mal escrita, y n8n no distinguiría "ese edificio no existe" de "cambiaron la URL".

**422 para el parámetro mal formado, no una respuesta vacía** — un `?unit=` vacío es un bug del flujo de n8n. Devolverlo como "no encontré nada" lo escondería, y el bot seguiría preguntando por WhatsApp a alguien que escribió bien.

**Mínimo de 2 caracteres por parámetro** — con un carácter, "contiene" devuelve medio condominio y el tope de 20 se convierte en el criterio real de selección, que es decir "los primeros 20 alfabéticamente". Dos es el mínimo por debajo del cual la búsqueda deja de significar algo.

**`owner` con solo `uid` y `name`** — el bot lo necesita para confirmar por WhatsApp ("¿eres Juan Pérez, de Dpto 3B?"). Descartado incluir a los ocupantes: duplica la superficie de datos personales y el §8.2.4 no lo pide. Descartados `current_balance` y `payment_information`, que están al alcance de la consulta y aun así no salen, por la misma decisión de la SPEC 127. `owner` es `null` —y la unidad sigue apareciendo— cuando no hay propietario asignado: el bot puede imputar el pago igual.

**Los nombres del propietario se resuelven después del recorte** — `myapi_user_display_names()` se llama sobre los `uid` de los cinco que salen, no sobre los de todas las coincidencias. Con `total` de 40 es la diferencia entre una consulta de cinco filas y una de cuarenta, por un dato que nunca se iba a devolver.

**Caché estática por petición, sin `cache_set()`** — los 150 títulos se cargan una vez por petición y punto. Descartada la caché persistente: son 150 filas de dos columnas, el coste es despreciable, y una caché persistente introduce el problema de invalidarla cuando alguien renombra o despublica un condominio desde el back-office — un `hook_node_update()` más para ahorrar una consulta trivial.

**Las consultas nuevas van a `includes/myapi.unit_query.inc`** — no a `bot.resource.inc`. Son consultas de unidad y condominio, el archivo existe desde la SPEC 127 exactamente para eso, y el día que `GET /api/v1/units` necesite listar por condominio ya estará escrito. El detalle de cada vivienda lo sigue dando `myapi_unit_fetch_units()` sin tocarla: lo nuevo decide **qué nids** pedir, no qué campos traer.

**Definición cerrada sin muestrear la base** — la spec se cerró sobre el código existente y sobre dos cifras de producción (35.000 viviendas, 150 condominios), sin mirar antes cómo están escritos realmente los nombres de vivienda ni cuántos condominios comparten palabras. Ver Riesgos.

---

## Riesgos identificados

**1. No sabemos cómo están escritos realmente los nombres de vivienda.** Es el riesgo principal, y es la misma suposición sin verificar que la SPEC 127 hizo con los teléfonos. Toda la spec da por hecho que el nombre de una unidad es algo como `Dpto 3B` o `Local 2`. Si en esta base la mayoría son solo `1`, `2`, `3`, el mínimo de 2 caracteres rechaza con `422 invalid_unit` búsquedas perfectamente legítimas, y el endpoint queda inservible para ese condominio.

*Mitigación, antes de escribir código* — un censo de formatos sobre producción (el prefijo de tablas es `dr_`):

```sql
SELECT CHAR_LENGTH(field_nombre_vivienda_value) AS largo, COUNT(*) AS filas
FROM dr_field_data_field_nombre_vivienda
WHERE entity_type = 'node' AND deleted = 0
GROUP BY largo ORDER BY filas DESC LIMIT 20;
```

Si hay un número relevante de filas con 1 carácter, `MYAPI_BOT_SEARCH_MIN_LENGTH` baja a 1 **para `unit`** (no para `condominium`) y `invalid_unit` pasa a cubrir solo el término que queda vacío tras normalizar. Es un cambio de una constante y una línea de documentación, pero hay que decidirlo con el dato delante.

**2. Condominios con nombres casi iguales.** `Torre Azul`, `Torre Azul II` y `Torres Azules` conviven sin problema en una base de 150 edificios, y `?condominium=torre%20azul` casa literalmente con los tres. El bot recibe tres unidades `3B` distintas, con `match: "exact"` en todas, y ninguna señal de cuál es.

*Mitigación* — el censo hermano del anterior: agrupar los 150 títulos por su valor normalizado y mirar cuántos grupos tienen más de un `nid`, y cuántos títulos son prefijo de otro. El número va en `docs/bot.md` como línea base. Y una regla para n8n, escrita en la documentación: **cuando `total > 1`, confirmar por WhatsApp antes de imputar**, independientemente de `match`. El endpoint da los datos para decidir; decidir por el residente no le toca.

**3. El endpoint es un oráculo de datos personales, y más ancho que el de la SPEC 127.** Allí hacía falta conocer el teléfono; aquí basta con recorrer el alfabeto: dos letras por parámetro y la clave devuelven el nombre del propietario de cualquier unidad de cualquier edificio.

*Mitigación* — HTTPS obligatorio (ya es requisito del proyecto), la clave nunca en el repositorio ni en la URL, entrega a n8n fuera de banda, y el `watchdog` WARNING de cada rechazo que ya escribe `myapi_bot_require_api_key()`. Si el riesgo se materializa, la palanca está identificada y es barata: quitar `owner` de la respuesta y que el bot lo pida con una segunda llamada a `/bot/person` una vez la unidad está confirmada. Cambia un bloque de `myapi_bot_build_search_results()` y nada más.

**4. El coste del peor caso no está medido.** Veinte condominios por ~230 viviendas son 4.600 filas traídas y comparadas en PHP, y si la pasada literal da cero se recorren otra vez calculando `levenshtein` contra cada palabra de cada nombre. Con el bot llamando por cada mensaje de WhatsApp, eso puede dejar de ser gratis.

*Mitigación* — el paso 6 del plan lo mide con el término de condominio más común de la base, antes de dar el endpoint por entregado. Si el peor caso se acerca al segundo, hay dos palancas por orden de coste: bajar `MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT` de 20 a 5, y —si eso no basta— una tabla `myapi_unit_search_index` con el nombre plegado, mantenida por `hook_node_presave()` y rellenada por un `hook_update_N()`. Lo segundo es un spec propio y solo cambiaría `myapi_bot_match_units()`.

**5. `total` puede mentir por lo bajo.** Cuenta dentro de los 20 condominios examinados, no de los 150. Con un término muy corto, el bot puede decir "hay 12" cuando en realidad hay 60.

*Riesgo aceptado*, con la alternativa escrita en Decisiones. La consecuencia práctica es la misma en los dos casos —el residente tiene que afinar el nombre— y la documentación lo dice con esas palabras para que nadie construya una cuenta encima de ese número.

**6. Que n8n ignore `match: "fuzzy"`.** El seguro contra entregar la unidad del vecino está repartido entre dos sistemas: Drupal marca la coincidencia como aproximada, y n8n tiene que preguntar antes de imputar. La mitad de n8n no la controla este repositorio, y un flujo que trate los cinco resultados por igual convierte el aproximado en un generador silencioso de pagos mal imputados.

*Mitigación* — la guarda de los 4 caracteres, que es la única defensa que sí vive en Drupal y que cubre el caso peor (`3B` → `3C`); y la advertencia en `docs/bot.md` junto al ejemplo de respuesta, no en un párrafo suelto al final. Si en la primera semana aparece un pago mal imputado con `match: "fuzzy"` detrás, la decisión de devolver aproximados se reabre.

**7. `myapi_text_fold()` pasa a tener dos dueños.** Nació en la SPEC 119 para el buscador de categorías de servicio y ahora sostiene también este endpoint. Quien la toque por un caso del marketplace cambiará, sin verlo, el comportamiento de la búsqueda del bot.

*Mitigación* — `BotUnitsSearchTest` fija el comportamiento que este endpoint necesita (tildes españolas, `ñ`, mayúsculas acentuadas) con casos propios, en vez de confiar en los de `TextFoldTest`. Si alguien rompe el plegado, se enteran los dos specs a la vez.
