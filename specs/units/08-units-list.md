# 08 — Listado de unidades por condominio para el usuario autenticado

- **Estado:** Implemented
- **Fecha:** 2026-07-02
- **Dependencias:**
  - `02-login-tokens` (Implemented) — tabla `my_api_tokens`.
  - `05-middleware-access-token-logout` (Implemented) — `myapi_auth_require_access_token()`
    en `includes/myapi.auth.inc`, reutilizado tal cual para exigir el access token.
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` y
    `myapi_error()`.
- **Objetivo:** Exponer `GET /api/v1/units`, protegido por access token, que
  devuelve para el usuario autenticado todos los condominios donde tiene al
  menos una unidad (como propietario u ocupante) con sus unidades anidadas.

---

## Alcance

### Dentro de este spec

**API JSON (para la app Flutter):**
- **`resources/unit.resource.inc`** (nuevo) — `myapi_unit_dispatch()` (enruta
  por método HTTP, solo `GET`) y `myapi_unit_list()` (lógica de la consulta y
  armado de la respuesta).
- **`myapi.module`** (modificar) — registrar `api/v1/units` en `hook_menu()`.
- **`myapi.info`** (modificar) — `files[] = resources/unit.resource.inc`.
- **`docs/unit.md`** (nuevo) — documentación del endpoint siguiendo la
  plantilla estándar.

### Fuera de este spec

- **Alícuota** por unidad/categoría — se deja para un spec futuro de
  facturación/cuotas.
- **Endpoint de detalle** (`GET /api/v1/units/%`) para una unidad individual —
  solo se pide la vista de listado agrupado por condominio.
- **Escritura** (crear/editar/eliminar unidades, cambiar propietario u
  ocupante) — solo lectura en este spec.
- **Filtros o paginación** (por condominio, por categoría, etc.) — el volumen
  esperado por usuario es pequeño (unas pocas unidades), no se pagina.
- **Rate limiting (Flood API)** — es un endpoint de lectura autenticado (ya
  exige un access token válido), no un endpoint de autenticación público;
  consistente con `06-brute-force-protection`, que solo protege endpoints de
  auth.
- **Múltiples ocupantes por unidad devueltos individualmente** — la
  respuesta no expone la lista de ocupantes, solo si el usuario autenticado
  tiene relación con la unidad.

---

## Modelo de datos

No hay tablas nuevas — este spec solo lee estructuras ya existentes del Field
API de Drupal 7.

### Tablas / campos involucrados

| Tabla | Columnas relevantes | Uso |
|---|---|---|
| `node` | `nid`, `type`, `title`, `status` | Nodo `vivienda` (unidad) y nodo `condominio` (propiedad). |
| `field_data_field_condominio` | `entity_id` (nid vivienda), `field_condominio_target_id` (nid condominio) | Relación unidad → condominio. |
| `field_data_field_nombre_vivienda` | `entity_id`, `field_nombre_vivienda_value` | Nombre de la unidad (ej. "Depto. 4B"). |
| `field_data_field_categoria` | `entity_id`, `field_categoria_tid` | Categoría de la unidad (referencia a taxonomía). |
| `taxonomy_term_data` | `tid`, `name` | Nombre de la categoría, usado tal cual en `category`. |
| `field_data_field_total_m2` | `entity_id`, `field_total_m2_value` | Área en m² de la unidad (`area_m2`). |
| `field_data_field_propietario` | `entity_id` (nid vivienda), `field_propietario_target_id` (uid) | Propietario de la unidad (single-value). |
| `field_data_field_ocupante` | `entity_id`, `field_ocupante_target_id` (uid) | Ocupante legacy (single-value). |
| `field_data_field_ocupantes` | `entity_id`, `field_ocupantes_target_id` (uid) | Ocupante actual (multi-value). |
| `field_data_field_nombre` | `entity_id` (uid), `field_nombre_value` | Nombre del propietario (entity_type `user`). |
| `field_data_field_apellidos` | `entity_id` (uid), `field_apellidos_value` | Apellidos del propietario (entity_type `user`). |
| `users` | `uid`, `name`, `status` | Fallback de `owner_name`; validación de usuario autenticado. |

Todas las tablas `field_data_field_*` se filtran siempre por `deleted = 0` y,
cuando aplica, `entity_type = 'node'` / `bundle = 'vivienda'` (o
`entity_type = 'user'` para nombre/apellidos).

### Contrato de `myapi_unit_list()`

1. Obtener `$row = myapi_auth_require_access_token()` → `$uid = $row->uid`
   (401 automático si el token falta/es inválido, vía el middleware
   existente).
2. **Nids de unidades relacionadas al usuario** — un `db_select` sobre
   `field_data_field_propietario` con tres subconsultas independientes
   fusionadas en PHP contra `field_data_field_ocupante` y
   `field_data_field_ocupantes`, cada una filtrando
   `<campo>_target_id = $uid`, `deleted = 0`. Resultado: array de nids de
   `vivienda` (sin duplicados).
3. Si el array está vacío → responder `myapi_respond(['properties' => []], 200)`
   de inmediato (sin más queries).
4. **Datos de las unidades** — un `db_select('node', 'n')` con
   `bundle`/`type = 'vivienda'`, `status = 1`, `nid IN (...)`, con
   `LEFT JOIN` a `field_data_field_nombre_vivienda`,
   `field_data_field_categoria` + `taxonomy_term_data`,
   `field_data_field_total_m2`, `field_data_field_condominio`,
   `field_data_field_propietario`. Devuelve, por unidad: `nid`, `name`
   (`field_nombre_vivienda_value`), `category` (`taxonomy_term_data.name`),
   `area_m2` (`field_total_m2_value`), `condominio_nid`, `owner_uid`
   (`field_propietario_target_id`, puede ser `NULL` si la unidad no tiene
   propietario asignado).
5. **Datos de los condominios** — `db_select('node', 'n')` con
   `type = 'condominio'`, `status = 1`, `nid IN (<condominio_nid distintos
   del paso 4>)`. Devuelve `nid` y `title`. Unidades cuyo condominio no
   aparece aquí (nodo condominio inexistente o `status = 0`) se descartan de
   la respuesta.
6. **Nombres de propietarios** — `db_select('users', 'u')` con
   `uid IN (<owner_uid distintos, no nulos>)`, `LEFT JOIN` a
   `field_data_field_nombre` y `field_data_field_apellidos`
   (`entity_type = 'user'`). Por cada uid: si `field_nombre_value` y
   `field_apellidos_value` vienen ambos no vacíos → `"$nombre $apellidos"`;
   si falta cualquiera de los dos → `users.name` completo como fallback.
7. **Armado en PHP** — agrupar las unidades del paso 4 (ya filtradas por
   condominios válidos del paso 5) por `condominio_nid`; cada unidad resuelve
   su `owner_name` con el mapa del paso 6 (`NULL` si la unidad no tiene
   `owner_uid`); construir:
   ```json
   {
     "properties": [
       {
         "id": 12,
         "name": "Edificio El Sáuco",
         "units": [
           { "id": 45, "name": "Depto. 4B", "category": "departamento", "area_m2": 92.0, "owner_name": "Priscila Cordero" }
         ]
       }
     ]
   }
   ```
8. `myapi_respond(['properties' => $properties], 200)`.

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`resources/unit.resource.inc` (nuevo) — `myapi_unit_dispatch()`.**
   ```php
   module_load_include('inc', 'myapi', 'includes/myapi.request');
   module_load_include('inc', 'myapi', 'includes/myapi.response');
   module_load_include('inc', 'myapi', 'includes/myapi.i18n');
   module_load_include('inc', 'myapi', 'includes/myapi.auth');
   ```
   Enruta por `myapi_request_method()`: solo `GET` → `myapi_unit_list()`;
   cualquier otro método → `myapi_error('method_not_allowed', 405)`.

2. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 1.** Llamar a
   `$row = myapi_auth_require_access_token()` (detiene la petición con 401
   `missing_authorization`/`invalid_token` si el token falta o es inválido) y
   extraer `$uid = $row->uid`.

3. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 2.** Construir
   la query de nids de `vivienda` relacionados al `$uid` (propietario OR
   ocupante OR ocupantes), vía tres `db_select()` independientes sobre
   `field_data_field_propietario` / `field_data_field_ocupante` /
   `field_data_field_ocupantes`, fusionando los nids resultantes en un array
   único en PHP (evita depender de sintaxis `UNION` de `db_select()`, más
   simple de leer). Si el array queda vacío →
   `myapi_respond(['properties' => []], 200)` y termina aquí.

4. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 3.** Query
   sobre `node` (bundle `vivienda`, `status = 1`, `nid IN (...)`) con los
   `LEFT JOIN` descritos en el modelo de datos, para obtener por unidad:
   `nid`, `name`, `category`, `area_m2`, `condominio_nid`, `owner_uid`.

5. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 4.** Query
   sobre `node` (bundle `condominio`, `status = 1`, `nid IN (<condominio_nid
   distintos>)`) para obtener `nid` + `title`. Construir un mapa
   `condominio_nid => title` solo con los que existen y están publicados.

6. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 5.** Query
   sobre `users` + `field_data_field_nombre` + `field_data_field_apellidos`
   (`entity_type = 'user'`) para los `owner_uid` distintos y no nulos.
   Construir un mapa `uid => owner_name` aplicando el fallback a `users.name`
   descrito en el modelo de datos.

7. **`resources/unit.resource.inc` — `myapi_unit_list()`, paso 6 (armado
   final).** Filtrar las unidades del paso 4 a las que su `condominio_nid`
   exista en el mapa del paso 5; agrupar por `condominio_nid`; resolver
   `owner_name` de cada unidad con el mapa del paso 6 (`NULL` si no hay
   `owner_uid`); construir el array `properties` con la forma documentada y
   responder `myapi_respond(['properties' => $properties], 200)`.

8. **`myapi.module` — `hook_menu()`.** Registrar `api/v1/units` →
   `page callback: myapi_unit_dispatch`, `access callback: TRUE`,
   `MENU_CALLBACK`, `file: resources/unit.resource.inc` (el control de acceso
   real ocurre dentro del callback vía `myapi_auth_require_access_token()`,
   mismo patrón que el resto del módulo).

9. **`myapi.info` — registrar el archivo nuevo.** Añadir
   `files[] = resources/unit.resource.inc`.

10. **`docs/unit.md` (nuevo) — documentar el endpoint.** Siguiendo la
    plantilla estándar: método, autenticación requerida, headers, forma de
    respuesta de éxito, y tabla de errores (401 `missing_authorization`, 401
    `invalid_token`, 405 `method_not_allowed`).

11. **Aplicar y verificar.** `drush cc all` y probar con `curl`:
    - Usuario con unidades como propietario en un condominio → aparece con
      sus unidades, `owner_name` correcto.
    - Usuario con unidades como ocupante (vía `field_ocupante` y vía
      `field_ocupantes`) → aparece igual que el propietario.
    - Usuario con unidades como propietario en un condominio y ocupante en
      otro → ambos condominios aparecen, cada uno con sus propias unidades.
    - Usuario sin ninguna unidad relacionada → `200` con
      `{"properties": []}`.
    - Unidad cuyo condominio está `status = 0` → la unidad no aparece en la
      respuesta.
    - Unidad sin propietario asignado (`field_propietario` vacío) →
      `owner_name: null`.
    - Propietario con `field_nombre`/`field_apellidos` vacíos → `owner_name`
      cae a `users.name`.
    - Sin header `Authorization` → `401` `missing_authorization`.
    - Access token inválido/expirado/revocado → `401` `invalid_token`.
    - `POST`/`PUT`/`DELETE` sobre `api/v1/units` → `405`
      `method_not_allowed`.

---

## Criterios de aceptación

- [x] `GET /api/v1/units` con un access token válido de un usuario que es
      **propietario** de al menos una unidad → **HTTP 200** con
      `{"success":true,"data":{"properties":[...]}}`, incluyendo el
      condominio correspondiente y la unidad con `id`, `name`, `category`,
      `area_m2` y `owner_name` correctos.
- [x] `GET /api/v1/units` con un usuario que es **ocupante** de una unidad vía
      `field_ocupante` (single-value) → la unidad aparece igual que si fuera
      propietario.
- [x] `GET /api/v1/units` con un usuario que es **ocupante** de una unidad vía
      `field_ocupantes` (multi-value) → la unidad aparece igual.
- [x] Un usuario que es propietario en un condominio y ocupante en otro →
      ambos condominios aparecen en `properties`, cada uno con únicamente sus
      propias unidades anidadas.
- [x] Un usuario sin ninguna unidad relacionada (ni propietario ni ocupante) →
      **HTTP 200** con `{"success":true,"data":{"properties":[]}}`.
- [x] Una unidad cuyo nodo `condominio` padre tiene `status = 0` (no
      publicado) → esa unidad **no aparece** en la respuesta.
- [x] Una unidad con `status = 0` (no publicada) → no aparece en la
      respuesta, aunque el usuario sea su propietario u ocupante.
- [x] Una unidad sin `field_propietario` asignado → aparece con
      `owner_name: null`, sin error.
- [x] Un propietario cuyos `field_nombre`/`field_apellidos` están vacíos →
      `owner_name` usa `users.name` completo como fallback.
- [x] `area_m2` en la respuesta coincide siempre con `field_total_m2_value`
      de la unidad, sin importar su categoría.
- [x] La respuesta **no incluye** ningún campo de alícuota.
- [x] Header `Authorization` ausente → **HTTP 401** con
      `error_code: "missing_authorization"`.
- [x] Access token inválido, expirado, revocado, o de un usuario con
      `status = 0` → **HTTP 401** con `error_code: "invalid_token"`.
- [x] `POST`/`PUT`/`DELETE` sobre `api/v1/units` → **HTTP 405** con
      `error_code: "method_not_allowed"`.
- [x] `drush cc all` registra la nueva ruta sin errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Autenticación | `myapi_auth_require_access_token()` (middleware existente de `05-middleware-access-token-logout`) | Nuevo mecanismo de auth propio | Reutiliza el patrón ya validado; sin token válido nunca se llega a tocar la BD de unidades. |
| `area_m2` | Siempre `field_total_m2_value` | Campo específico por categoría (`field_departamento_m2`, etc.) | Un solo campo, sin tabla de mapeo categoría→campo que mantener; más simple y consistente entre categorías. |
| Alícuota | Fuera de alcance de este endpoint | Incluirla en la respuesta de unidades | La forma de respuesta pedida no la incluye; se deja para un spec de facturación/cuotas futuro. |
| Relación ocupante | OR entre `field_ocupante` (legacy, single-value) y `field_ocupantes` (multi-value) | Solo uno de los dos campos | Evita perder unidades si conviven datos antiguos en `field_ocupante` con datos nuevos en `field_ocupantes`. |
| Nombre del condominio | `node.title` del nodo `condominio` | Campo custom (`field_nombre_condominio`) | No existe tal campo en el schema; `title` es el campo estándar de Drupal para el nombre de un nodo. |
| Categoría | `taxonomy_term_data.name` tal cual, sin transformación | Slug/lowercase de la categoría | Se expone el nombre del término tal cual está cargado en la taxonomía; no se asume una convención de slug que no está confirmada. |
| Filtro de publicación | Solo se listan unidades y condominios con `status = 1` | Incluir también nodos no publicados | Un nodo no publicado no debería ser visible para el usuario final vía la API pública. |
| `owner_name` con datos vacíos | Fallback completo a `users.name` si `field_nombre` **o** `field_apellidos` falta | Concatenar `users.name` solo con la parte disponible | Evita nombres híbridos ambiguos (ej. mezclar username con apellido); el fallback es predecible y consistente. |
| Unidad sin propietario | `owner_name: null` | Omitir el campo o usar string vacío | `null` es explícito en JSON y distingue "sin propietario" de "propietario con nombre vacío". |
| Rate limiting (Flood API) | No aplicado en este spec | Aplicar mismo patrón que endpoints de auth | Es un endpoint de lectura ya protegido por access token válido; `06-brute-force-protection` solo cubre endpoints de autenticación pública. |
| Endpoint de detalle (`/units/%`) | Fuera de alcance | Implementarlo en este mismo spec | No fue pedido; la vista "Cambiar unidad" solo necesita el listado agrupado. |
| Paginación | No implementada | Paginar `properties`/`units` | El volumen esperado por usuario (unas pocas unidades) no lo justifica. |
| Construcción de la query de nids relacionados | 3 `db_select()` independientes fusionados en PHP | Un único `UNION` vía `db_select()` | Más legible y depurable paso a paso; consistente con el estilo del resto del módulo (sin queries complejas de una sola pieza). |

---

## Riesgos identificados

- **Fragilidad ante cambios de schema.** Al usar SQL directo sobre tablas
  `field_data_field_*` en vez de Field API, cualquier cambio futuro en los
  campos (renombrar, cambiar de single a multi-value, mover a otra bundle)
  rompe silenciosamente esta query sin que Drupal avise en tiempo de
  actualización. *Mitigación:* documentado en `docs/unit.md` qué campos y
  bundles asume el endpoint, para revisar manualmente si cambian.

- **Ambigüedad legacy en el campo ocupante.** No hay certeza de que
  `field_ocupante` siga usándose activamente frente a `field_ocupantes`; se
  optó por revisar ambos (OR) como medida conservadora, pero si en la
  práctica `field_ocupante` ya no se usa, la query hace trabajo innecesario
  sin beneficio. *Mitigación:* aceptado como trade-off; de confirmarse que
  `field_ocupante` está deprecado, se puede simplificar en un spec posterior.

- **Unidad "desaparece" si el condominio padre no está publicado.** Un
  usuario que sí tiene una unidad asignada no verá ningún indicio de por qué
  falta en la respuesta (no hay mensaje de error, simplemente no aparece).
  *Mitigación:* aceptado por diseño (filtro de publicación), documentado como
  comportamiento esperado en los criterios de aceptación.

- **N+1 conceptual con varias queries encadenadas.** El plan usa 4-5 queries
  secuenciales (nids relacionados, unidades, condominios, propietarios) en
  vez de una sola consulta con múltiples `JOIN`. Para el volumen esperado por
  usuario (pocas unidades) el costo es marginal. *Mitigación:* si el volumen
  crece significativamente, se puede optimizar a una única query con joins
  en un spec de rendimiento futuro.
