# SPEC 127 — Identificación de la persona por teléfono para el bot de WhatsApp

> **Estado:** Approved · **Depende de:** SPEC 03 (catálogo `myapi_t()`, `myapi_error()` / `myapi_respond()`), SPEC 05 (`myapi_auth_require_access_token()` — **no se usa aquí**, pero este endpoint copia su forma: guardia al inicio del handler, rechazo antes de tocar ninguna tabla), SPEC 08 (`myapi_unit_fetch_units()`, `myapi_unit_fetch_condominiums()` y la regla de descartar unidades cuyo condominio no es visible), SPEC 09 (`myapi_unit_fetch_user_names()`), SPEC 29 (`myapi_user_owned_unit_nids()` / `myapi_user_occupied_unit_nids()` en `includes/myapi.unit_access.inc`), SPEC 123 (`ModuleContractTest` / `EndpointContractTest` y el gate de cobertura) · **Fecha:** 2026-09-16
>
> **Objetivo:** Exponer `GET /api/v1/bot/person?phone=…`, autenticado con una API key de máquina en el header `X-Api-Key`, que resuelve un número de teléfono a la persona registrada en Drupal y a todas sus unidades visibles —sea propietaria u ocupante— para que el bot de WhatsApp del módulo 8 del anexo sepa a qué unidad imputar un comprobante de pago.

**Por qué `person` y no `owner`:** quien envía el comprobante por WhatsApp no siempre es el propietario — un inquilino paga la alícuota igual de a menudo. Llamar `owner` al bloque de la persona obligaría a mentir en la mitad de los casos, y una misma persona puede ser propietaria de una unidad y ocupante de otra a la vez. El rol no es un atributo de la persona: es un atributo de *cada relación con una unidad*, y por eso vive en `relation` dentro de cada elemento de `units`.

**Por qué una credencial nueva y no el access token:** el bot no es un usuario. Vive en n8n, no tiene sesión, y el flujo del Bearer de la SPEC 02 obligaría a hacer login antes de cada llamada y a gestionar el refresh. `services_basic_auth` queda fuera por ser un módulo contribuido, prohibido por `CLAUDE.md`. Una API key estática en `variable_get('myapi_bot_api_key')`, comparada con `hash_equals()`, es la credencial mínima que resuelve el caso.

**Lo que este endpoint no es:** no registra pagos. El §8.2.4 del anexo (crear el pago en Drupal con el adjunto) usa el endpoint de pagos que ya existe; este solo responde *quién es* y *dónde vive*.

---

## Alcance

### Dentro de este spec

**Código de producción**

- **`includes/myapi.bot_auth.inc`** (nuevo) — `myapi_bot_require_api_key()`. La guardia de credencial, aislada del recurso por la misma razón que `myapi_auth_require_access_token()` vive en `includes/myapi.auth.inc` y no dentro de `auth.resource.inc`: el siguiente endpoint del bot la reutiliza sin copiar nada. Lee `variable_get('myapi_bot_api_key', '')`, compara con `hash_equals()`, y en caso de fallo escribe un `watchdog('myapi', …, WATCHDOG_WARNING)` y corta con `myapi_error('unauthorized', 401)`.

- **`resources/bot.resource.inc`** (nuevo) — todo lo demás:
  - `myapi_bot_person_dispatch()` — enruta por método; solo `GET`, el resto `405`.
  - `myapi_bot_person_lookup()` — el handler.
  - `myapi_bot_normalize_phone($value)` — `preg_replace('/\D/', '', $value)` y los últimos 9 dígitos. Pura, es la que carga la mayor parte de los tests.
  - `myapi_bot_phone_like_pattern($digits)` — construye `%9%8%7%5%3%5%6%4%5` desde los 9 dígitos. Pura.
  - `myapi_bot_find_uids_by_phone($digits)` — el pre-filtro SQL sobre `field_data_field_telefono` + el filtrado exacto en PHP. Devuelve la lista de uids que coinciden de verdad.
  - `myapi_bot_build_units($owned_nids, $occupied_nids)` — arma el array `units` reutilizando `myapi_unit_fetch_units()` y `myapi_unit_fetch_condominiums()`.

- **`myapi.module`** (modificar) — registrar `api/v1/bot/person` en `hook_menu()`, con `'access callback' => TRUE` como todas las demás y `'file' => 'resources/bot.resource.inc'`.

- **`myapi.info`** (modificar) — `files[] = includes/myapi.bot_auth.inc` y `files[] = resources/bot.resource.inc`.

- **`includes/myapi.i18n.inc`** (modificar) — dos claves nuevas en los dos idiomas: `missing_phone` (el parámetro no viene o viene vacío) e `invalid_phone` (viene pero tiene menos de 9 dígitos). El 401 reutiliza `unauthorized`, que ya existe; el 405 reutiliza `method_not_allowed`.

**Documentación**

- **`docs/bot.md`** (nuevo) — plantilla estándar de `CLAUDE.md`, en el mismo commit que el código. `ModuleContractTest::testEveryEndpointIsDocumented()` lo exige.

**Tests**

- **`tests/unit/BotPersonTest.php`** (nuevo) — los diez casos acordados.
- **`tests/unit/EndpointContractTest.php`** (modificar) — la allowlist `API_KEY_ENDPOINTS` y sus dos tests: "sin `X-Api-Key` responde 401" y "una petición sin credencial no llega a ninguna tabla", que son el equivalente exacto de lo que el archivo ya garantiza para el Bearer.

**Operación**

- La clave se fija con `drush vset myapi_bot_api_key '<secreto>'`. Se documenta en `docs/bot.md`, no se instala ningún valor por defecto: sin variable, `variable_get()` devuelve `''` y **todas** las peticiones son 401. Un endpoint que se abre solo porque nadie configuró nada no es una opción.

### Fuera de este spec

- **Registrar el pago** (§8.2.4 del anexo) — lo hace el endpoint de pagos que ya existe. Este solo identifica.
- **El flujo conversacional de alta** cuando `found: false` (§8.2.2) — es lógica de n8n, no de Drupal. El endpoint dice "no lo encuentro"; qué preguntar por WhatsApp después no es asunto suyo.
- **UI de administración de la clave** — sin formulario en `admin/config`. Rotar es un `drush vset`.
- **Varias claves, con nombre y revocación individual** — ver Decisiones.
- **Rate limiting / Flood** — ver Decisiones.
- **Índice normalizado de teléfonos** (`hook_user_presave()` + `hook_update_N()`) — no hace falta con 6.000 usuarios; queda anotado en Riesgos como el camino si la base crece.
- **Búsqueda por otros identificadores** (cédula, email, nombre) — solo teléfono.
- **Escritura de cualquier tipo** — el endpoint es de solo lectura, `GET` y nada más.
- **Exponer saldo o información de pago** — `current_balance` y `payment_information` están al alcance de la consulta y aun así no viajan.
- **Ajuste del View del operador** (§8.2.6 del anexo) — es backend de Drupal, otro spec.
- **Paginación de `units`** — una persona tiene unas pocas unidades; mismo criterio que la SPEC 08.
- **Todo lo del §8.6 del anexo** (reconciliación bancaria, aprobación automática, transcripción de voz, multi-tenant) — excluido por contrato.

---

## Modelo de datos

**No se crea ninguna tabla.** El spec no introduce esquema: introduce una variable de Drupal, reordena dos funciones existentes y define un contrato de respuesta.

### Lo único persistente que se añade

| Qué | Dónde | Valor |
|---|---|---|
| `myapi_bot_api_key` | tabla `variable` (Drupal core) | El secreto compartido con n8n. Se fija con `drush vset`. Sin fijar, `variable_get()` devuelve `''` y el endpoint responde 401 a todo. |

### Tablas consultadas (todas existentes)

| Tabla | Columnas | Uso |
|---|---|---|
| `field_data_field_telefono` | `entity_id`, `field_telefono_value`, `entity_type`, `deleted` | El teléfono de la persona. Multi-valor: una persona puede tener varias filas con distinto `delta`, y **cualquiera** de ellas vale para el match. |
| `users` | `uid`, `status`, `name` | Descartar cuentas bloqueadas (`status = 0`). |
| `field_data_field_nombre`, `field_data_field_apellidos` | — | El nombre visible, vía `myapi_user_display_names()` (`includes/myapi.user.inc`), que ya cae a `users.name` si falta alguna de las dos partes. |
| `field_data_field_propietario` | `entity_id`, `field_propietario_target_id` | Unidades donde es propietaria, vía `myapi_user_owned_unit_nids()`. |
| `field_data_field_ocupante` / `field_data_field_ocupantes` | `entity_id`, `field_ocupante(s)_target_id` | Unidades donde es ocupante, vía `myapi_user_occupied_unit_nids()`, que ya fusiona el campo legacy y el multi-valor. |
| `node` + `field_data_field_nombre_vivienda` + `field_data_field_condominio` | — | Nombre de la unidad y su condominio, vía `myapi_unit_fetch_units()`. Filtra `type = 'vivienda'` y `status = 1`. |
| `node` (condominio) | `nid`, `title`, `status` | Título del condominio, vía `myapi_unit_fetch_condominiums()`. Filtra `status = 1`; las unidades cuyo condominio no sale del mapa se descartan. |

### La extracción a `includes/`

`myapi_unit_fetch_units()` y `myapi_unit_fetch_condominiums()` se mueven **sin tocar una línea de su cuerpo** de `resources/unit.resource.inc` a un **`includes/myapi.unit_query.inc`** nuevo. Un recurso no puede llamar a funciones internas de otro (regla 5 de `CLAUDE.md`) y duplicar la consulta está igual de prohibido. El nombre sigue el precedente que ya existe en el módulo: `myapi.provider_query.inc`, `myapi.service_request_query.inc`, `myapi.claim_query.inc`.

Arrastra tres cambios mecánicos: `files[]` en `myapi.info`, un `require_once` en `tests/unit/UnitQueriesTest.php`, y nada más — `GET /api/v1/units` sigue llamando a las mismas funciones con la misma firma, y sus tests siguen verdes sin reescribir ningún caso.

### Normalización del teléfono

Una sola definición, usada en los dos lados de la comparación:

```
normalize(v) = substr(preg_replace('/\D/', '', v), -9)
```

| Guardado en Drupal | Normalizado | ¿Coincide con `?phone=0987535645`? |
|---|---|---|
| `0987535645` | `987535645` | sí |
| `+593 98 753 5645` | `987535645` | sí |
| `(098) 753-5645` | `987535645` | sí |
| `098-753-5645` | `987535645` | sí |
| `0987535` | `0987535` (7 dígitos) | no |

El pre-filtro SQL es `field_telefono_value LIKE '%9%8%7%5%3%5%6%4%5'` (un `%` entre cada dígito y en los extremos). No produce falsos negativos — los dígitos de un teléfono nunca aparecen desordenados — y los falsos positivos los elimina el `normalize()` en PHP sobre cada fila candidata.

### Contrato de respuesta

**Encontrada** — 200:

```json
{
  "success": true,
  "data": {
    "found": true,
    "person": { "uid": 123, "name": "Juan Pérez" },
    "units": [
      { "unit_id": 456, "unit": "Dpto 3B", "condominium_id": 12, "condominium": "Torre Azul", "relation": "owner" },
      { "unit_id": 789, "unit": "Local 2",  "condominium_id": 12, "condominium": "Torre Azul", "relation": "occupant" }
    ]
  }
}
```

**No encontrada** — 200, misma forma siempre, para que n8n tenga una sola rama:

```json
{
  "success": true,
  "data": { "found": false, "person": null, "units": [], "reason": "not_found" }
}
```

`reason` toma uno de tres valores, estables y en inglés como `error_code`:

| `reason` | Cuándo |
|---|---|
| `not_found` | Ningún usuario **activo** tiene ese teléfono. Incluye el caso de la cuenta bloqueada: no se distingue, para no confirmarle a quien pregunta que la persona existe. |
| `ambiguous` | Más de un usuario activo coincide en los últimos 9 dígitos. Antes no responder que imputar el pago a la persona equivocada. |
| `no_units` | Coincide exactamente una persona, pero no tiene ninguna unidad visible (ni propia ni ocupada, o todas despublicadas, o su condominio despublicado). |

`person` es `null` en los tres casos, incluido `no_units`: si el bot no puede imputar el pago, el `uid` no le sirve de nada y sí sería un dato personal viajando sin necesidad.

**Errores** — envelope estándar de `myapi_error()`:

| HTTP | `error_code` | Cuándo |
|---|---|---|
| 401 | `unauthorized` | `X-Api-Key` ausente, vacía, o distinta de la configurada. También cuando la variable no está configurada. |
| 405 | `method_not_allowed` | Cualquier método que no sea `GET`. |
| 422 | `missing_phone` | El parámetro `phone` no viene o viene vacío. |
| 422 | `invalid_phone` | `phone` viene pero tiene menos de 9 dígitos tras normalizar. |

---

## Plan de implementación

Cada paso deja la suite en verde y el módulo funcional. Los pasos 1–3 no cambian ninguna respuesta de la API; el endpoint nace en el paso 4.

**1. Extraer las consultas de unidad a `includes/`**

- Crear `includes/myapi.unit_query.inc` y mover ahí `myapi_unit_fetch_units()` y `myapi_unit_fetch_condominiums()` tal cual, con sus docblocks.
- `myapi.info`: `files[] = includes/myapi.unit_query.inc`.
- `tests/unit/UnitQueriesTest.php`: añadir el `require_once` del archivo nuevo.
- **Verificación:** `GET /api/v1/units` responde exactamente igual y `UnitQueriesTest` / `UnitBuildPropertiesTest` pasan sin tocar un solo caso.

**2. Ampliar el catálogo i18n**

- `includes/myapi.i18n.inc`: `missing_phone` e `invalid_phone` en `'en'` y en `'es'`.
- **Verificación:** el test de la SPEC 123 que comprueba que las dos ramas del catálogo tienen las mismas claves sigue en verde.

**3. La guardia de API key**

- Crear `includes/myapi.bot_auth.inc` con `myapi_bot_require_api_key()`:
  - Lee `$_SERVER['HTTP_X_API_KEY']` (Drupal 7 no normaliza headers; es la forma en que llega `X-Api-Key`).
  - Lee `variable_get('myapi_bot_api_key', '')`.
  - Si la configurada está vacía, o la recibida está vacía, o `!hash_equals($configured, $received)`: `watchdog('myapi', 'Bot API key rejected for @path from @ip.', [...], WATCHDOG_WARNING)` y `myapi_error('unauthorized', 401)`.
  - No consulta ninguna tabla. Esa es la invariante que el paso 4 convierte en test.
- `myapi.info`: `files[] = includes/myapi.bot_auth.inc`.
- **Verificación:** nada la llama todavía; el módulo se comporta igual.

**4. El recurso, la ruta y la documentación (un solo commit)**

Es atómico por obligación: `ModuleContractTest::testEveryDispatcherIsRouted()` y `testEveryEndpointIsDocumented()` se ponen rojos si el dispatcher, la ruta y el `.md` no llegan juntos, y `EndpointContractTest` lo hace si la allowlist no viene en el mismo commit.

- `resources/bot.resource.inc`, en este orden:
  - `myapi_bot_normalize_phone($value)` — pura.
  - `myapi_bot_phone_like_pattern($digits)` — pura.
  - `myapi_bot_find_uids_by_phone($digits)` — `db_select('field_data_field_telefono')` con `entity_type = 'user'`, `deleted = 0` y el `LIKE`; `innerJoin` a `users` con `status = 1`; filtrado exacto en PHP con `myapi_bot_normalize_phone()`; devuelve uids únicos.
  - `myapi_bot_build_units($owned_nids, $occupied_nids)` — una sola llamada a `myapi_unit_fetch_units()` con la unión de ambas listas, una a `myapi_unit_fetch_condominiums()`, y el armado con `relation` resuelta por pertenencia a `$owned_nids` (propietaria gana si la persona es ambas cosas en la misma unidad). Descarta las unidades cuyo condominio no esté en el mapa.
  - `myapi_bot_person_lookup()` — `myapi_bot_require_api_key()`; `phone` de `$_GET`; `missing_phone` / `invalid_phone`; `myapi_bot_find_uids_by_phone()`; `not_found` si 0, `ambiguous` si >1; `myapi_user_owned_unit_nids()` + `myapi_user_occupied_unit_nids()`; `no_units` si el array final queda vacío; si no, `myapi_user_display_names()` y `myapi_respond()`.
  - `myapi_bot_person_dispatch()` — `GET` al handler, todo lo demás `myapi_error('method_not_allowed', 405)`.
- `myapi.module`: `$items['api/v1/bot/person']` con `'page callback' => 'myapi_bot_person_dispatch'`, `'access callback' => TRUE`, `MENU_CALLBACK`, `'file' => 'resources/bot.resource.inc'`.
- `myapi.info`: `files[] = resources/bot.resource.inc`.
- `tests/unit/EndpointContractTest.php`: constante `API_KEY_ENDPOINTS` con `'api/v1/bot/person [GET]' => 'why'`, exclusión de esa ruta en los tests que exigen Bearer, y dos tests nuevos — responde 401 sin `X-Api-Key`, y una petición sin credencial no llega a ninguna tabla.
- `docs/bot.md` con la plantilla estándar, incluida la nota de operación (`drush vset myapi_bot_api_key`).
- **Verificación:** `drush cc all` y un `curl` con la key correcta y otro sin ella.

**5. Los tests unitarios del recurso**

- `tests/unit/BotPersonTest.php`: normalización (espacios, `+593`, paréntesis, guiones), patrón `LIKE`, `ambiguous`, cuenta bloqueada → `not_found`, unidad despublicada descartada, condominio despublicado descartado, `relation` correcta en cada unidad, 401 sin key, 422 con teléfono corto y con `phone` ausente, 405 en `POST`/`PUT`/`DELETE`.
- **Verificación:** suite completa en verde y el gate de cobertura de la SPEC 123 satisfecho.

**6. Puesta en marcha**

- `drush vset myapi_bot_api_key '<secreto generado>'` en producción.
- `drush cc all`.
- Verificación contra un teléfono real de la base, en sus tres formatos, y contra uno inexistente.
- Entregar la clave a n8n por canal fuera de banda; nunca en el repositorio.

---

## Criterios de aceptación

Cada línea es verificable con un `curl`, un test o una consulta.

**Autenticación**

- [ ] `GET /api/v1/bot/person?phone=0987535645` **sin** header `X-Api-Key` responde `401` con `error_code: "unauthorized"`.
- [ ] Con una `X-Api-Key` distinta de la configurada responde `401`, y queda una entrada `WATCHDOG_WARNING` en `watchdog` con la ruta y la IP.
- [ ] Con `myapi_bot_api_key` sin configurar (variable ausente), **toda** petición responde `401`, incluso una con un header `X-Api-Key` vacío.
- [ ] Una petición sin credencial válida no ejecuta ninguna consulta: `EndpointContractTest` lo comprueba contando las consultas del stub.

**Método y parámetro**

- [ ] `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` y `OPTIONS` sobre la ruta responden `405` con `error_code: "method_not_allowed"`.
- [ ] Sin el parámetro `phone`, o con `phone=`, responde `422` con `error_code: "missing_phone"`.
- [ ] Con `phone=555` (menos de 9 dígitos tras normalizar) responde `422` con `error_code: "invalid_phone"`.

**Normalización**

- [ ] Para una persona cuyo teléfono está guardado como `0987535645`, las cuatro consultas `?phone=0987535645`, `?phone=+593987535645`, `?phone=%2B593%2098%20753%205645` y `?phone=(098)%20753-5645` devuelven **el mismo** `uid`.
- [ ] Recíprocamente, con el mismo `?phone=0987535645` se encuentra a la persona esté guardado su número como `0987535645`, `+593 98 753 5645`, `(098) 753-5645` o `098-753-5645`.

**Resolución**

- [ ] Persona activa, con una unidad propia publicada en condominio publicado → `200`, `found: true`, `person.uid` y `person.name` correctos, `units` con un elemento de `relation: "owner"`.
- [ ] Persona con dos unidades, una como propietaria y otra como ocupante → `units` trae las dos, con `relation` distinta en cada una.
- [ ] Persona con unidades en dos condominios distintos → los dos aparecen, con su `condominium_id` y `condominium` correctos.
- [ ] Ningún usuario activo coincide → `200`, `{ "found": false, "person": null, "units": [], "reason": "not_found" }`.
- [ ] El único usuario que coincide tiene `users.status = 0` → `reason: "not_found"` (no `no_units`, no `found: true`).
- [ ] Dos usuarios activos coinciden en los últimos 9 dígitos → `reason: "ambiguous"`, y `person` es `null`.
- [ ] Persona activa que existe pero no tiene ninguna unidad → `reason: "no_units"`, `person` es `null`.
- [ ] Persona cuya única unidad está despublicada (`node.status = 0`) → `reason: "no_units"`.
- [ ] Persona cuya única unidad cuelga de un condominio despublicado → `reason: "no_units"`.

**Contrato de respuesta**

- [ ] En todos los casos de éxito y de no-encontrado el código HTTP es `200` y el cuerpo es `{ "success": true, "data": { ... } }`. Ningún caso devuelve `404`.
- [ ] `data` contiene siempre las claves `found` y `units`; `units` es siempre un array, vacío cuando `found` es `false`.
- [ ] Ningún elemento de `units` contiene `current_balance` ni `payment_information`, ni la respuesta expone el teléfono, la cédula o el email de la persona.
- [ ] Todas las claves del JSON están en inglés.

**Estructura y regresión**

- [ ] `myapi_unit_fetch_units()` y `myapi_unit_fetch_condominiums()` ya no están en `resources/bot.resource.inc` ni en `resources/unit.resource.inc`, sino en `includes/myapi.unit_query.inc`.
- [ ] `GET /api/v1/units` devuelve exactamente la misma respuesta que antes del paso 1, con los mismos condominios, unidades y campos.
- [ ] `resources/bot.resource.inc` no llama a ninguna función definida en otro archivo de `resources/`.
- [ ] `myapi.info` declara los tres archivos nuevos y `ModuleContractTest` pasa entero.
- [ ] `docs/bot.md` existe, documenta el endpoint con la plantilla estándar e incluye la instrucción `drush vset myapi_bot_api_key`.
- [ ] La suite completa (`vendor/bin/phpunit`) pasa en PHP 7.4 y el gate de cobertura de la SPEC 123 no baja.

---

## Decisiones tomadas y descartadas

**La ruta lleva `v1`** — `api/v1/bot/person`, no `api/bot/owner`. La propuesta original se salía del prefijo versionado que cumplen las 114 rutas del módulo, y `ModuleContractTest::testEveryEndpointIsUnderTheVersionOnePrefix()` lo habría rechazado. Añadir una excepción a esa allowlist por el primer endpoint de máquina habría abierto la puerta a que el siguiente también la pidiera.

**`person`, no `owner`** — descartado `owner` porque quien envía el comprobante es a menudo el inquilino. El rol no es un atributo de la persona sino de cada relación con una unidad, y por eso vive en `relation` dentro de `units`. Descartado también devolver dos listas separadas (`owned_units` / `occupied_units`): obliga a n8n a recorrer dos arrays para lo mismo.

**API key en `X-Api-Key`, no Basic auth** — `services_basic_auth` es un módulo contribuido y `CLAUDE.md` los prohíbe para exposición de API. Descartado el Bearer de la SPEC 02 porque obliga a hacer login antes de cada llamada en n8n. Descartado `Authorization: Bearer <key>` como portador de la key porque reutilizar el header del access token para una credencial que no lo es confundiría a cualquiera que lea `myapi_auth_parse_bearer()` después.

**Una sola clave en `variable_get()`, no una tabla `myapi_bot_keys`** — hay exactamente un consumidor, n8n. Una tabla con varias claves, nombre y revocación individual es infraestructura para un problema que no existe todavía; rotar es `drush vset`. Si algún día hay un segundo consumidor, la tabla es un spec propio y `myapi_bot_require_api_key()` es el único sitio que cambia.

**Sin valor por defecto para la clave** — `variable_get('myapi_bot_api_key', '')` con cadena vacía, y la cadena vacía nunca autentica. Descartada cualquier semilla en `hook_install()`: un endpoint que queda abierto porque nadie configuró nada es peor que uno que no funciona hasta que lo configuras.

**401, no 403** — falta la credencial o es inválida. El 403 queda reservado para "autenticado pero sin permiso", que en este endpoint no ocurre.

**Pre-filtro `LIKE` con comodín entre dígitos + normalización en PHP** — descartado el `LIKE '%987535645'` directo, que falla con `099 753 5645`, exactamente el caso que había que cubrir. Descartada también la columna normalizada mantenida por `hook_user_presave()`: con 6.000 usuarios el escaneo cuesta nada y esa columna introduce un estado que hay que mantener sincronizado y rellenar con un `hook_update_N()`. Queda anotada en Riesgos como el camino si la base crece un orden de magnitud.

**Ambigüedad → `reason: "ambiguous"`, no "el primer `uid`"** — dos personas con los mismos últimos 9 dígitos y un comprobante de por medio es una imputación de pago a la unidad equivocada. Descartado también devolver un array `persons` con las dos: el bot no tiene forma de elegir, y el flujo conversacional del §8.2.2 ya sabe pedir edificio y unidad por WhatsApp.

**Propietaria u ocupante, con `relation`** — descartado limitarlo a propietaria. Los inquilinos pagan la alícuota igual de a menudo, y `myapi_user_owned_unit_nids()` / `myapi_user_occupied_unit_nids()` ya resuelven ambos lados sin escribir una consulta nueva. Cuando una persona es las dos cosas en la misma unidad, gana `"owner"`.

**Persona sin unidades visibles → `found: false`** — descartado `found: true` con `units: []`. Para el bot, "identificado pero sin dónde imputar" y "no identificado" desembocan en la misma acción: preguntar por WhatsApp. Una rama en n8n, no dos. El `reason: "no_units"` conserva la distinción para el log sin duplicar el flujo.

**`person: null` también en `no_units`** — si el bot no puede imputar el pago, el `uid` y el nombre no le sirven, y son datos personales viajando sin necesidad.

**Cuenta bloqueada → `not_found`, no un `reason` propio** — un `reason: "blocked"` le confirmaría a quien pregunta que esa persona existe en el sistema. No hay ninguna acción que el bot pudiera tomar con esa distinción.

**Siempre 200 en la resolución, nunca 404** — un 404 se confunde con una URL mal escrita, y n8n no distinguiría "este teléfono no está" de "cambiaron la ruta".

**422 para el parámetro mal formado, no `found: false`** — descartada la rama única. `?phone=` vacío es un bug del flujo de n8n, no un teléfono desconocido; devolverlo como `found: false` lo escondería y el bot pediría datos por WhatsApp a alguien que sí estaba registrado.

**Sin rate limiting** — la clave es un secreto aleatorio, no un password adivinable: no hay fuerza bruta realista que frenar, y un límite de peticiones no mitiga el riesgo real, que es la fuga de la clave. Lo que sí entra es el `watchdog` de nivel WARNING en cada rechazo, que hace visible esa fuga y cuesta una línea.

**Ni saldo ni información de pago en la respuesta** — `current_balance` y `payment_information` están al alcance de la consulta y aun así no viajan. El bot no los necesita para el §8.2.4, y son datos financieros que no tienen por qué salir de Drupal hacia n8n.

**Las consultas de unidad se mueven a `includes/myapi.unit_query.inc`** — un recurso no puede llamar a funciones internas de otro (regla 5 de `CLAUDE.md`) y duplicar la consulta está igual de prohibido. El nombre sigue el precedente de `myapi.provider_query.inc` y `myapi.service_request_query.inc`. Se mueven sin modificar su cuerpo, para que la regresión sobre `GET /api/v1/units` sea verificable por inspección.

**Un `resources/bot.resource.inc`, no `bot_person.resource.inc`** — el bot va a necesitar más endpoints (el registro del pago del §8.2.4 acabará queriendo su propia forma). Un archivo por consumidor, con un dispatcher por ruta, es el patrón que `myapi.module` ya usa en `providers`.

**Definición rápida sin prototipo** — la spec se cerró sobre el anexo y el código existente, sin validar antes contra la base de producción cómo están guardados realmente los teléfonos. Ver Riesgos.

---

## Riesgos identificados

**1. No sabemos cómo están guardados realmente los teléfonos.** Es el riesgo principal y es una suposición sin verificar: toda la spec se apoya en que `field_telefono_value` contiene **un** número. Si alguna fila contiene dos (`0987535645 / 022345678`, o `098 753 5645 - casa 2234567`), `normalize()` concatena todos los dígitos y se queda con los últimos 9 del **segundo** número. La persona no aparece, o peor, aparece otra.

*Mitigación, antes de escribir código* — un censo de formatos sobre producción (el prefijo de tablas es `dr_`):

```sql
SELECT LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
         field_telefono_value,' ',''),'-',''),'(',''),')',''),'+','')) AS digitos,
       COUNT(*) AS filas
FROM dr_field_data_field_telefono
WHERE entity_type = 'user' AND deleted = 0
GROUP BY digitos ORDER BY filas DESC;
```

Todo lo que no caiga en 9, 10 (`0987535645`) o 12 (`593987535645`) es un formato que la normalización no contempla. Si hay un número relevante de filas con más de 13 dígitos, hay campos con dos números dentro y esta spec necesita una decisión extra antes de implementarse.

**2. Cuánta gente tiene teléfono, y cuánta lo comparte.** Si la mitad de los 6.000 usuarios tiene el campo vacío, el bot dará `not_found` la mitad de las veces y parecerá roto cuando lo que falta son datos. Y si hay cuentas duplicadas de la misma persona, `ambiguous` se disparará.

*Mitigación* — dos consultas en la misma sesión que la anterior: cuántos usuarios activos tienen fila en `field_data_field_telefono`, y cuántos grupos de últimos-9-dígitos tienen más de un `uid` activo. Los dos números van en `docs/bot.md` como línea base. Si `ambiguous` sale alto, la decisión de devolver `ambiguous` hay que reabrirla — pero con datos, no antes.

**3. Fuga de la clave.** Quien tenga `myapi_bot_api_key` puede consultar cualquier teléfono y obtener nombre, `uid`, unidades y condominios. Es un oráculo de enumeración de datos personales.

*Mitigación* — HTTPS obligatorio (ya es requisito del proyecto), la clave nunca en el repositorio ni en la URL, entrega a n8n fuera de banda, y el `watchdog` WARNING en cada rechazo para que un intento de adivinarla deje rastro. Rotar es `drush vset` y un cambio en n8n; conviene dejarlo escrito en `docs/bot.md` como procedimiento, no descubrirlo el día que haga falta.

**4. El escaneo crece con la base.** Hoy son ~6.000 filas por llamada y es irrelevante. A 100.000 usuarios, con el bot llamando por cada mensaje de WhatsApp, deja de serlo.

*Mitigación* — el camino ya está identificado: una columna o tabla `myapi_user_phone_index` con los 9 dígitos normalizados, mantenida por `hook_user_presave()` y rellenada por un `hook_update_N()`. Es un spec propio y solo cambia `myapi_bot_find_uids_by_phone()`. No se hace ahora porque sería infraestructura para un problema que no existe.

**5. La extracción del paso 1 toca un endpoint en producción.** `GET /api/v1/units` lo consume la app Flutter. Mover sus dos consultas de archivo es mecánico, pero es el único cambio de esta spec que puede romper algo que hoy funciona.

*Mitigación* — las funciones se mueven **sin editar su cuerpo**, de modo que la revisión se reduce a comparar que el bloque es idéntico. `UnitQueriesTest` y `UnitBuildPropertiesTest` las cubren y deben pasar sin reescribir ni un caso; si algún caso necesita cambiar, es que el movimiento no fue un movimiento.

**6. El header `X-Api-Key` puede no llegar.** Drupal 7 lee headers personalizados de `$_SERVER['HTTP_X_API_KEY']`, pero algunas configuraciones de Apache con FastCGI, y algunos proxies delante, descartan headers no estándar.

*Mitigación* — verificarlo en el paso 6 con un `curl` real contra producción antes de dar el endpoint por entregado. Si el header no llega, la alternativa es `Authorization: ApiKey <clave>`, que sí sobrevive a esas configuraciones, y solo cambia `myapi_bot_require_api_key()`.

**7. Colisión de últimos 9 dígitos entre países.** Un `+593 98 753 5645` y un `+57 98 753 5645` son la misma persona para este endpoint. Es un riesgo aceptado: el condominio es ecuatoriano y la probabilidad es despreciable frente al coste de exigir el prefijo, que rompería el requisito de que dé igual cómo esté guardado el número. Si ocurre, el resultado es `ambiguous`, que es el fallo seguro, no una imputación errónea.
