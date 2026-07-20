# SPEC 31 — Filtro de boletines por condominio

> **Estado:** Approved · **Depende de:** SPEC 29, SPEC 08 · **Fecha:** 2026-07-20
> **Objetivo:** Agregar el query param opcional `condominium_id` a `GET /api/v1/bulletins` que, cuando se envía y el usuario pertenece a ese condominio, acota el resultado a los boletines **General** (visibles), los **Condominio** de ese condominio (visibles) y **todos** los **Personalizado** del usuario, reutilizando el contrato de paginación/orden/fechas del spec 29 y sin modificar la ruta ni la lógica de audiencia.

**Dependencias (detalle):**

- `29-bulletins-list` (Implemented) — endpoint base, condición de visibilidad inversa (`myapi_bulletin_visibility_condition()`), paginación y filtro de fechas. Este spec agrega un filtro sobre ese mismo endpoint sin tocar la firma de la query.
- `08-units-list` (Implemented) — `includes/myapi.unit_access.inc`; los sets de condominios del lector (`owner_condos`/`occupant_condos`) ya calculados por el spec 29 son la base del gate de pertenencia.

---

## Alcance

### Dentro de este spec

- **`resources/bulletin.resource.inc`** (modificar) — en `myapi_bulletin_list()`:
  1. Parsear el nuevo query param opcional `condominium_id` con un helper `myapi_bulletin_parse_condominium_id()`.
  2. Si viene presente pero malformado (no entero positivo) → `myapi_error('invalid_field', 422, ['@field' => 'condominium_id'])`.
  3. Si viene válido pero el usuario no pertenece al condominio (no está en `member_condos`) → `myapi_error('condominium_access_denied', 403)`.
  4. Si viene válido y el usuario pertenece → estrechar los sets de condominio (`owner_condos`/`occupant_condos`/`member_condos`) a su intersección con `{condominium_id}` antes de pasarlos a count/fetch.
  Las funciones `myapi_bulletin_count()`, `myapi_bulletin_fetch()` y `myapi_bulletin_visibility_condition()` **no se modifican** (reciben los sets ya estrechados).
- **`includes/myapi.i18n.inc`** (modificar) — agregar la clave `condominium_access_denied` a los catálogos EN y ES. `invalid_field` ya existe y se reutiliza.
- **`docs/bulletin.md`** (modificar) — documentar el query param `condominium_id`, su semántica (General + Condominio(id) + Personalizado del usuario), el gate de pertenencia y los errores `422`/`403`.

### Fuera de este spec

- **Nueva ruta o cambio en `hook_menu()`** — es un query param sobre `api/v1/bulletins`; la ruta no cambia. No se registra `/bulletins/%` (esa ruta sigue reservada para el detalle individual diferido del spec 29).
- **Detalle individual** `GET /api/v1/bulletins/%` — sigue diferido; este spec no lo implementa.
- **Filtrar los Personalizado por condominio** — se incluyen **todos** los del usuario sin importar `field_condominio` (decisión 6b); su `condominium_id` sigue siendo normalmente `NULL`.
- **Modificar la condición de audiencia** (`myapi_bulletin_visibility_condition()`) ni la paridad con el fan-out del spec 25 — el filtro se aplica estrechando los sets de entrada, no reescribiendo la regla.
- **Cambiar el comportamiento cuando `condominium_id` está ausente** — sin el param, el endpoint responde exactamente igual que el spec 29.
- **Validar que el condominio exista como nodo** — no se hace query extra: un id inexistente simplemente no está en `member_condos` y cae en el mismo `403` que un condominio ajeno (no se distingue, para no filtrar información).
- **Nuevos filtros combinables** (por `type`, `send_to`, etc.) — el único filtro nuevo es `condominium_id`; los ya existentes (`date_from`/`date_to`) se combinan con él sin cambios.

---

## Modelo de datos

No se crean tablas ni campos. Se agrega un query param y una clave de error; se reutilizan
las mismas tablas Field API del spec 29.

### Nuevo query param

| Param | Formato | Default | Regla |
|---|---|---|---|
| `condominium_id` | entero positivo (nid del condominio) | ausente = sin filtro (comportamiento spec 29) | Si viene, gatea y acota el resultado (ver más abajo). |

**Validación (en `myapi_bulletin_list()`, antes de contar/traer):**

1. **Ausente** (`!isset` o string vacío) → no se aplica filtro; el endpoint responde igual que el spec 29.
2. **Presente pero malformado** (no matchea `ctype_digit` o `<= 0`) → `422` con `invalid_field` y `@field = 'condominium_id'`.
3. **Presente y entero positivo, pero el usuario no pertenece** (el id no está en `member_condos`) → `403 condominium_access_denied`. Cubre tanto "condominio ajeno" como "condominio inexistente" (no se distingue, para no revelar existencia). No se hace query extra: si nadie lo tiene como condominio de una unidad propia/ocupada, no está en `member_condos`.
4. **Presente, entero positivo y el usuario pertenece** → se aplica el estrechamiento de sets.

### Estrechamiento de sets (cuando `condominium_id = C` es válido y pertenece)

Los tres sets de condominio que `myapi_bulletin_list()` pasa a count/fetch se reemplazan por su
intersección con `{C}`. Los **flags** (`is_owner`/`is_occupant`/`is_member`) **no cambian**:

| Set | Valor normal (spec 29) | Valor estrechado (con `condominium_id = C`) |
|---|---|---|
| `owner_condos` | todos los condominios donde el lector es propietario | `in_array(C, owner_condos) ? [C] : []` |
| `occupant_condos` | todos donde es ocupante | `in_array(C, occupant_condos) ? [C] : []` |
| `member_condos` | unión de ambos | `[C]` (el gate garantiza pertenencia) |
| `is_owner` / `is_occupant` / `is_member` | sin cambios | **sin cambios** |

### Efecto sobre cada rama de audiencia (sin tocar `myapi_bulletin_visibility_condition()`)

- **General** — usa los flags `is_owner`/`is_occupant`/`is_member`, que no se estrechan ⇒ el lector sigue viendo **todos** los boletines General que le corresponden por rol. No se acotan por condominio (los generales no tienen condominio).
- **Condominio** — usa los sets de condominio, ahora `[C]` o `[]` ⇒ solo aparecen los boletines de tipo Condominio cuyo `field_condominio = C` **y** cuyo `send_to` coincide con el rol que el lector tiene en C (Propietarios⇒dueño de C, Ocupantes⇒ocupante de C, Todos⇒cualquiera).
- **Personalizado** — usa sub-selects `EXISTS` sobre `$uid`, que no se estrechan ⇒ el lector sigue viendo **todos** sus boletines Personalizado (decisión 6b), sin importar `field_condominio`.

### Nueva clave de catálogo (`includes/myapi.i18n.inc`)

| Clave | EN | ES |
|---|---|---|
| `condominium_access_denied` | `You do not have access to this condominium.` | `No tienes acceso a este condominio.` |

`invalid_field` ya existe y se reutiliza para el caso malformado (mismo patrón que `unit_id` en pagos).

---

## Plan de implementación

1. **Helper `myapi_bulletin_parse_condominium_id()`** en `resources/bulletin.resource.inc`:
   lee `$_GET['condominium_id']`. Devuelve:
   - `NULL` (sentinel "ausente") si no está seteado o es string vacío;
   - `FALSE` (sentinel "malformado") si está presente pero no es entero positivo
     (`!ctype_digit((string) $value)` o `(int) $value <= 0`);
   - el `int` del id si es un entero positivo válido.
   *Verificación: función pura, sin efectos; testeable con los tres casos.*

2. **Agregar la clave `condominium_access_denied`** a `includes/myapi.i18n.inc` en los
   bloques EN (~L81, junto a `unit_access_denied`) y ES (~L131). Sin tocar las demás claves.
   *Verificación: `myapi_t('condominium_access_denied')` resuelve en `es` y `en`.*

3. **Integrar el parseo y el gate en `myapi_bulletin_list()`**, después de calcular los
   sets del lector (paso 2 actual del list) y antes de contar:
   - `$condo = myapi_bulletin_parse_condominium_id();`
   - Si `$condo === FALSE` → `myapi_error('invalid_field', 422, ['@field' => 'condominium_id']);`
   - Si `$condo !== NULL` (es un int válido):
     - Si `!in_array($condo, $sets['member_condos'])` → `myapi_error('condominium_access_denied', 403);`
     - Si pertenece → estrechar los sets:
       ```php
       $sets['owner_condos']    = in_array($condo, $sets['owner_condos']) ? [$condo] : [];
       $sets['occupant_condos'] = in_array($condo, $sets['occupant_condos']) ? [$condo] : [];
       $sets['member_condos']   = [$condo];
       ```
     - `is_owner`/`is_occupant`/`is_member` quedan intactos.
   - Si `$condo === NULL` → no se toca nada (comportamiento spec 29).
   *Verificación: `myapi_error()` corta la ejecución (llama a `drupal_exit()`), así que el gate
   protege el count/fetch.*

4. **Contar y traer sin cambios.** `myapi_bulletin_count()` y `myapi_bulletin_fetch()` reciben
   `$sets` (estrechado o no) exactamente como hoy; `myapi_bulletin_visibility_condition()` no se
   modifica. El resto de `myapi_bulletin_list()` (paginación, fechas, respuesta) queda igual.
   *Verificación: sin `condominium_id`, la respuesta es byte-idéntica a la del spec 29.*

5. **Actualizar `docs/bulletin.md`** en el mismo commit:
   - Fila nueva en la tabla de query params para `condominium_id`.
   - Sección corta "Filtro por condominio" explicando: General (todos los visibles) +
     Condominio(id) (visibles, con rol en ese condominio) + Personalizado (todos los del usuario);
     el gate de pertenencia; y que un id ausente = sin filtro.
   - Dos filas nuevas en la tabla de errores: `422 invalid_field` (`@field=condominium_id`) y
     `403 condominium_access_denied`.
   - Ejemplo `curl` con `?condominium_id=1234`.

6. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación
   (con/sin param, propietario vs ocupante en el condominio, condominio ajeno, id malformado,
   combinación con `date_from`/`date_to` y paginación).

Nota: **no** hace falta tocar `myapi.module`, `myapi.info` ni `myapi.install` — no hay ruta, archivo ni esquema nuevo.

---

## Criterios de aceptación

- [ ] Sin `condominium_id`, `GET /api/v1/bulletins` responde **byte-idéntico** al spec 29 (misma lista, misma paginación) — no hay regresión.
- [ ] Con `condominium_id=C` válido y el usuario perteneciente a `C`, la respuesta incluye: todos los boletines **General** visibles para el usuario, los **Condominio** con `field_condominio = C` visibles según su rol en `C`, y **todos** los **Personalizado** donde el usuario está referenciado.
- [ ] Con `condominium_id=C`, **ningún** boletín **Condominio** de un condominio distinto de `C` aparece en la respuesta.
- [ ] Un boletín **Condominio** de `C` con `send_to=Propietarios` solo aparece si el usuario es **propietario** en `C`; con `send_to=Ocupantes` solo si es **ocupante** en `C`; con `send_to=Todos` si es propietario u ocupante en `C`.
- [ ] Los boletines **General** que aparecen con el filtro son exactamente los mismos que aparecerían sin el filtro (el filtro no recorta generales).
- [ ] Los boletines **Personalizado** que aparecen con el filtro son exactamente los mismos que sin el filtro (no se recortan por condominio).
- [ ] `condominium_id` malformado (`abc`, `0`, `-3`, `1.5`) → `422` con `error_code = invalid_field` y mensaje que referencia `condominium_id`.
- [ ] `condominium_id` de un condominio al que el usuario **no** pertenece → `403 condominium_access_denied`.
- [ ] `condominium_id` de un condominio **inexistente** → `403 condominium_access_denied` (mismo trato que ajeno; no `404`).
- [ ] `condominium_id` ausente o string vacío → sin filtro, sin error.
- [ ] El filtro se combina correctamente con `date_from`/`date_to`, `page`, `limit` y `sort`; `pagination.total`/`total_pages` reflejan el conjunto ya filtrado por condominio.
- [ ] Los errores `401` (sin token / token inválido) y `405` (método distinto de `GET`) siguen igual que en el spec 29, y se evalúan **antes** que el gate de condominio (auth primero).
- [ ] La clave `condominium_access_denied` está en los catálogos EN y ES de `includes/myapi.i18n.inc` y `myapi_t()` la resuelve en ambos idiomas.
- [ ] `myapi_bulletin_visibility_condition()`, `myapi_bulletin_count()` y `myapi_bulletin_fetch()` no fueron modificadas (verificable por diff).
- [ ] `docs/bulletin.md` documenta el query param `condominium_id`, su semántica, el gate y los errores `422`/`403`.
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Forma de la interfaz | Query param `?condominium_id=1234` sobre `GET /api/v1/bulletins` | Path `/api/v1/bulletins/%` o sub-ruta `/condominiums/%/bulletins` | El path `/bulletins/%` choca con el detalle individual diferido del spec 29 y rompe la semántica REST (item = id del boletín). Un query param es un filtro más, no toca `hook_menu()` y reutiliza todo el contrato del spec 29. |
| Mecánica del filtro | Estrechar los sets de condominio (`owner/occupant/member_condos`) a `{C}` antes de la query | Modificar `myapi_bulletin_visibility_condition()` para recibir el condominio y reescribir la rama Condominio | Estrechar los sets deja la condición de audiencia intacta y preserva la paridad con el fan-out (spec 25). La rama Condominio ya filtra por esos sets, así que el efecto es automático. Menos superficie de cambio, menos riesgo de deriva. |
| Alcance de General | Todos los General visibles, sin acotar por condominio | Filtrar también los General por `C` | Los boletines General no tienen condominio; acotarlos no tiene sentido y contradice el pedido ("trae todos los generales"). Se logra "gratis" al no estrechar los flags. |
| Alcance de Personalizado | Todos los del usuario, sin acotar por condominio (decisión 6b) | Filtrar los Personalizado por `field_condominio = C` (6a) | Los Personalizado se dirigen a la persona, no al condominio, y su `field_condominio` normalmente es `NULL`; filtrarlos los eliminaría casi siempre. Coherente con que el spec 29 ignora `field_condominio` en esa rama. |
| Validación del id malformado | `422 invalid_field` con `@field='condominium_id'` | Clave dedicada `invalid_condominium_id`, o ignorarlo como ausente | Reutiliza el patrón ya usado para `unit_id` en `payment.resource.inc`; no infla el catálogo. Ignorarlo escondería un error del cliente (el param es un filtro con gate, no un parámetro laxo como `page`). |
| Condominio ajeno vs. inexistente | Ambos → `403 condominium_access_denied` (no se distinguen) | `404` para inexistente y `403` para ajeno | No revelar si un condominio existe; y evitar una query extra de existencia. El chequeo `in_array($C, member_condos)` cubre ambos casos de una. |
| Nueva clave de error | `condominium_access_denied` (paralela a `unit_access_denied`) | Reusar `unit_access_denied` | Es un recurso distinto (condominio, no unidad); una clave propia da un mensaje correcto y lógica de cliente estable. |
| Gate 403 vs. lista parcial | `403` cuando no pertenece (bloquea toda la respuesta) | Devolver `200` con solo los General/Personalizado, omitiendo la rama Condominio | Decisión 4a del usuario: pedir un condominio ajeno es un acceso denegado explícito, no un resultado parcial silencioso. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Regresión en el camino sin filtro.** El cambio vive dentro de `myapi_bulletin_list()`; un error en el parseo podría alterar el comportamiento cuando `condominium_id` está ausente. | El helper devuelve un sentinel `NULL` explícito para "ausente" y el estrechamiento solo corre en la rama `$condo !== NULL`. Criterio de aceptación de respuesta byte-idéntica sin el param. |
| **`IN ()` con set vacío.** Si el usuario pertenece a `C` solo como ocupante, `owner_condos` estrechado queda `[]`; pasar `IN ()` es SQL inválido en D7. | `myapi_bulletin_visibility_condition()` ya agrega cada sub-condición solo cuando su set no está vacío (comportamiento existente, sin cambios). Un set estrechado a `[]` simplemente no incluye esa opción de rol. |
| **Deriva con el fan-out (spec 25).** Al ser un filtro sobre la misma regla de audiencia, cualquier cambio futuro en la visibilidad debe seguir contemplando el estrechamiento. | El filtro **no** duplica la regla: la reusa vía los sets. No agrega un segundo lugar donde codificar audiencia. Documentado aquí y en `docs/bulletin.md`. |
| **Confusión de semántica en el cliente.** Un consumidor podría esperar que `/bulletins/1234` (path) fuera el detalle del boletín 1234, y toparse con el filtro por query param. | Se eligió el query param justamente para no ocupar `/bulletins/%`; el detalle sigue reservado para su propio spec. Documentado en decisiones y en `docs/bulletin.md`. |
| **`member_condos` desactualizado.** El gate depende de que los helpers de `myapi.unit_access.inc` reflejen la pertenencia real; un cambio de unidad no propagado daría un `403` falso. | Mismo origen de verdad que el resto del endpoint (spec 29/08); no introduce una fuente nueva de pertenencia. |
