# 03 — i18n de mensajes y errores según `Accept-Language`

- **Estado:** Implemented
- **Fecha:** 2026-06-29
- **Dependencias:**
  - `01-bootstrap-modulo` (Implemented) — extiende los helpers `myapi_respond()` /
    `myapi_error()` y el patrón de `includes/`.
  - `02-login-tokens` (Implemented) — `auth.resource.inc` es el primer consumidor
    que se migra al catálogo de traducciones.
- **Objetivo:** Resolver el idioma de cada petición desde el header
  `Accept-Language` (permitidos `es`/`en`, default `es`) y traducir todos los
  mensajes de error y de éxito vía un catálogo `myapi_t()`, devolviendo en los
  errores un `error_code` estable en inglés junto al `error` en el idioma resuelto.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.i18n.inc`** (nuevo) — `myapi_get_lang()` (lee y cachea el
  idioma desde `Accept-Language`) y `myapi_t($key, $replacements = [], $lang = NULL)`
  con el catálogo `es`/`en`.
- **`includes/myapi.response.inc`** (modificar):
  - `myapi_error($key, $status, $replacements = [])` — ahora recibe una **key**,
    traduce el `error` al idioma resuelto y añade `error_code` (la key estable) al
    envelope.
  - `myapi_respond($data, $status = 200, $message_key = NULL, $replacements = [])`
    — opcionalmente añade un campo `message` traducido al envelope de éxito.
- **`includes/myapi.request.inc`** (modificar) — migrar los mensajes dinámicos a
  keys con placeholder: `missing_field`, `invalid_field`, `field_too_long`.
- **`resources/auth.resource.inc`** (modificar) — `'Invalid credentials'` →
  `invalid_credentials`, `'Method not allowed'` → `method_not_allowed`.
- **`resources/ping.resource.inc`** (modificar) — `'Method not allowed.'` →
  `method_not_allowed`.
- **`myapi.module`** (modificar) — `module_load_include()` del nuevo
  `myapi.i18n.inc` donde haga falta para que esté disponible globalmente.
- **`myapi.info`** — añadir `files[] = includes/myapi.i18n.inc`.
- **`CLAUDE.md`** — actualizar la sección "Response envelope (no exceptions)" para
  reflejar `error_code` y el `message` opcional de éxito.
- **`docs/i18n.md`** (nuevo) — documentar el sistema: header, idiomas, catálogo de
  keys, formato de envelope.
- **`docs/auth.md`** (modificar) — actualizar ejemplos de error al nuevo formato
  con `error_code`.

### Fuera de este spec

- **Más idiomas** que `es`/`en` (el catálogo queda listo para añadirlos, pero no se
  incluyen).
- **Negociación con factores `q`** del `Accept-Language` (se toma el primer subtag
  de 2 letras; ver decisiones).
- **Traducir contenido de datos** (nombres de roles, datos de usuario, etc.) — solo
  se traducen mensajes del sistema, no el `data`.
- **Persistir la preferencia de idioma** del usuario en BD o en el token — el idioma
  se resuelve por petición desde el header.
- **Parámetro `?lang=` en la query** como alternativa al header — solo
  `Accept-Language`.
- **Endpoints nuevos** (logout, refresh): solo se define la key `logout_success` en
  el catálogo para cuando exista, sin crear el endpoint.

---

## Modelo de datos

No hay tablas ni esquema nuevo. Las estructuras que introduce este spec son **en
código** (el catálogo) y la **forma de los envelopes**.

### Resolución del idioma — `myapi_get_lang()`

- Lee `$_SERVER['HTTP_ACCEPT_LANGUAGE']`.
- Toma los **2 primeros caracteres** del header (`substr(..., 0, 2)`), en
  minúsculas. Ej.: `es-ES,es;q=0.9,en` → `es`.
- Valida contra la lista blanca `['es', 'en']`. Si no está o el header no viene →
  **`es`** (default).
- El resultado se **cachea** en una variable `static` (se resuelve una vez por
  petición).

### Catálogo — `myapi_t($key, $replacements = [], $lang = NULL)`

- Si `$lang === NULL`, usa `myapi_get_lang()`.
- Estructura interna: `array['es'|'en'][key] => string`.
- Aplica `$replacements` con `strtr()` (placeholders estilo `@field`).
- Si la key no existe en el idioma, devuelve la **key tal cual** (fallback seguro,
  nunca rompe).

| Key | `en` | `es` |
|---|---|---|
| `invalid_credentials` | Invalid username or password. | Usuario o contraseña incorrectos. |
| `unauthorized` | Unauthorized. | No autorizado. |
| `token_expired` | Token has expired. | El token ha expirado. |
| `user_not_found` | User not found. | Usuario no encontrado. |
| `logout_success` | Logged out successfully. | Sesión cerrada correctamente. |
| `missing_token` | Token is required. | Token requerido. |
| `method_not_allowed` | Method not allowed. | Método no permitido. |
| `missing_field` | Missing required field: @field | Falta el campo requerido: @field |
| `invalid_field` | Invalid or missing field: @field | Campo inválido o ausente: @field |
| `field_too_long` | Field too long: @field | Campo demasiado largo: @field |
| `server_error` | Internal server error. | Error interno del servidor. |

### Envelope de error (nuevo formato)

```json
{
  "success": false,
  "error_code": "invalid_credentials",
  "error": "Usuario o contraseña incorrectos."
}
```

- `error_code` = la **key** (inglés, snake_case, estable — para lógica del cliente).
- `error` = mensaje traducido al idioma resuelto (con placeholders ya sustituidos).

### Envelope de éxito (con `message` opcional)

```json
// sin message_key (p.ej. login): igual que hoy
{ "success": true, "data": { } }

// con message_key (p.ej. futuro logout):
{ "success": true, "data": { }, "message": "Sesión cerrada correctamente." }
```

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`includes/myapi.i18n.inc` — `myapi_get_lang()`.** Lee `Accept-Language`, toma
   los 2 primeros chars en minúsculas, valida contra `['es', 'en']`, default `es`.
   Cachea en `static`. En este punto el helper existe pero aún nadie lo usa: el
   sistema sigue funcionando igual.

2. **`includes/myapi.i18n.inc` — `myapi_t($key, $replacements = [], $lang = NULL)`.**
   Define el array del catálogo (tabla del modelo de datos), resuelve `$lang` con
   `myapi_get_lang()` si es `NULL`, aplica `strtr()` con `$replacements`, y devuelve
   la key como fallback si no existe.

3. **`myapi.info` + carga.** Añadir `files[] = includes/myapi.i18n.inc`. Asegurar
   que se carga (vía `module_load_include()` en los recursos/helpers que lo
   necesiten, igual que el patrón actual).

4. **`includes/myapi.response.inc` — `myapi_error($key, $status, $replacements = [])`.**
   Traduce con `myapi_t($key, $replacements)` para el campo `error`, y añade
   `error_code => $key` al envelope. Mantiene `drupal_add_http_header()` +
   `drupal_json_encode()` + `drupal_exit()` como hoy.

5. **`includes/myapi.response.inc` — `myapi_respond($data, $status = 200, $message_key = NULL, $replacements = [])`.**
   Si `$message_key !== NULL`, añade `message => myapi_t($message_key, $replacements)`
   al envelope de éxito. Si es `NULL`, el envelope no cambia respecto a hoy.

6. **`includes/myapi.request.inc` — migrar mensajes dinámicos a keys.**
   - `myapi_request_require_fields()`: `myapi_error('missing_field', 422, ['@field' => $field])`.
   - `myapi_request_require_strings()`: `myapi_error('invalid_field', 422, ['@field' => $field])`
     y `myapi_error('field_too_long', 422, ['@field' => $field])`.

7. **`resources/auth.resource.inc` — migrar.** `'Invalid credentials'` →
   `myapi_error('invalid_credentials', 401)`; `'Method not allowed'` →
   `myapi_error('method_not_allowed', 405)`. Cargar `myapi.i18n.inc` con
   `module_load_include()`.

8. **`resources/ping.resource.inc` — migrar.** `'Method not allowed.'` →
   `myapi_error('method_not_allowed', 405)`. Cargar `myapi.i18n.inc`.

9. **`CLAUDE.md` — actualizar "Response envelope".** Reflejar `error_code` en el
   error y el `message` opcional en el éxito, para que la nueva forma deje de ser
   una "excepción" a la regla dura.

10. **`docs/i18n.md` (nuevo).** Documentar: header `Accept-Language`, idiomas
    permitidos y default, tabla del catálogo de keys, formato de ambos envelopes, y
    ejemplo `curl` con cada idioma.

11. **`docs/auth.md` — actualizar.** Cambiar los ejemplos de error al nuevo formato
    con `error_code` y mencionar que el `error` se traduce según `Accept-Language`.

12. **Aplicar y verificar.** `drush cc all` y probar con `curl` enviando
    `Accept-Language: es`, `Accept-Language: en` y sin header (espera `es`).

---

## Criterios de aceptación

- [x] `POST /api/v1/auth/login` con credenciales inválidas y `Accept-Language: es`
      → **401** con
      `{"success":false,"error_code":"invalid_credentials","error":"Usuario o contraseña incorrectos."}`.
- [x] El mismo caso con `Accept-Language: en` → **401** con `error` =
      `"Invalid username or password."` y el **mismo** `error_code` =
      `"invalid_credentials"`.
- [x] El mismo caso **sin** header `Accept-Language` → responde en **español**
      (default `es`).
- [x] `Accept-Language: es-ES,es;q=0.9,en;q=0.8` se resuelve como `es` (se toman los
      2 primeros chars).
- [x] Un idioma no soportado (`Accept-Language: fr`) cae al default `es`.
- [x] Falta `username` en el body con `Accept-Language: en` → **422** con
      `error_code` = `"missing_field"` (estable, sin el nombre) y `error` =
      `"Missing required field: username"` (con el campo interpolado).
- [x] El mismo caso con `Accept-Language: es` → `error` =
      `"Falta el campo requerido: username"`.
- [x] `GET`/`PUT`/`DELETE` sobre `api/v1/auth/login` y método no-GET sobre
      `api/v1/ping` → **405** con `error_code` = `"method_not_allowed"` traducido
      según el header.
- [x] El envelope de **éxito** de login sigue siendo `{"success":true,"data":{...}}`
      **sin** campo `message` (no se pasó `message_key`).
- [x] Llamar a `myapi_respond($data, 200, 'logout_success')` produce
      `{"success":true,"data":{...},"message":"<traducido>"}`.
- [x] `myapi_t()` con una key inexistente devuelve la key tal cual (no rompe ni emite
      warning).
- [x] `includes/myapi.i18n.inc` está listado en `files[]` de `myapi.info` (sin
      "undefined function `myapi_t`").
- [x] El `CLAUDE.md` refleja el envelope con `error_code` y el `message` opcional,
      sin contradecir el código.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| `error_code` en el envelope | Añadir `error_code` (key en inglés) junto al `error` traducido | Mantener solo `error` | El cliente Flutter necesita un código estable para su lógica, independiente del idioma |
| Firma de `myapi_error()` | Primer arg = **key**; traduce y rellena `error_code` solo | Función nueva aparte / pasar `error_code` a mano | Un único punto de salida ya centralizado; mínima fricción en los recursos |
| Mensajes de éxito | `message_key` **opcional** en `myapi_respond()` | Siempre incluir `message` / no traducir éxitos | No rompe respuestas actuales (login sigue sin `message`); listo para logout |
| Resolución del idioma | 2 primeros chars del header, lista blanca `es`/`en` | Negociación completa con factores `q` | Over-engineering para 2 idiomas; simple y predecible |
| Idioma por defecto | `es` | `en` | Decisión explícita del usuario |
| `error_code` en mensajes dinámicos | Key estable sin el campo (`missing_field`); el nombre solo va en `error` | Incluir el campo en el `error_code` | `error_code` estable y enumerable por el cliente; el detalle viaja en el texto |
| Placeholders | `strtr()` con marcadores `@field` | `sprintf()` / concatenación | Estilo Drupal, legible y sin riesgo de orden de argumentos |
| Fallback de key inexistente | Devolver la key tal cual | Lanzar error / cadena vacía | Nunca rompe la respuesta; facilita detectar keys faltantes en pruebas |
| Ubicación | `includes/myapi.i18n.inc` | `includes/myapi.lang.inc` | Decisión explícita del usuario |
| Migración | Migrar **todo** lo existente (auth, ping, request helpers) | Solo montar el sistema y migrar después | Evita dos formatos de error conviviendo; consistencia inmediata |
| Idiomas soportados | Solo `es`/`en` | Catálogo multi-idioma desde ya | Alcance acotado; el catálogo admite añadir idiomas sin tocar la lógica |
| Persistencia del idioma | Por petición vía header | Guardar preferencia en BD/token | El header es suficiente; sin estado que mantener |

---

## Riesgos identificados

- **Cambio de contrato del envelope de error.** Añadir `error_code` modifica la
  forma de **todas** las respuestas de error y una regla dura del `CLAUDE.md`. Un
  cliente que parsee estrictamente podría romperse. *Mitigación:* `error_code` y
  `error` son **aditivos** (el `success`/`error` siguen ahí); se actualiza
  `CLAUDE.md` y `docs/` en el mismo trabajo para que el contrato quede explícito.

- **Keys del catálogo desincronizadas.** Si un recurso pasa una key que no existe en
  el catálogo, el `error`/`message` saldría como la propia key (texto crudo tipo
  `invalid_credentials`). *Mitigación:* el fallback es seguro (no rompe) y los
  criterios de aceptación verifican los textos reales; revisar el catálogo al añadir
  cada recurso.

- **Header `Accept-Language` manipulable / ausente.** Es un header de cliente, no
  fiable y opcional. *Mitigación:* lista blanca estricta + default `es`; nunca se usa
  el valor crudo para nada salvo elegir entre `es`/`en`, así que no hay superficie de
  inyección.

- **Traducción solo de mensajes del sistema.** El `data` (nombres de roles, `mail`,
  etc.) **no** se traduce. Un consumidor podría esperar que todo respetara el idioma.
  *Mitigación:* documentado explícitamente como fuera de alcance en `docs/i18n.md`.

- **Migración incompleta a futuro.** Nuevos recursos podrían volver a meter strings
  en inglés a mano en `myapi_error()`. *Mitigación:* tras este spec, `myapi_error()`
  espera una **key**; pasar texto libre simplemente produciría un `error_code` raro y
  un `error` = ese texto, lo que se detecta rápido en pruebas. Reforzar la convención
  en `CLAUDE.md`.
