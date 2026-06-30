# 05 — Middleware de access token + endpoint de logout

- **Estado:** Implemented
- **Fecha:** 2026-06-29
- **Dependencias:**
  - `02-login-tokens` (Implemented) — tabla `my_api_tokens` y helpers de
    `includes/myapi.token.inc` (hashing, persistencia).
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` y
    `myapi_error()` con `error_code`.
  - `04-refresh-token` (Implemented) — establece el patrón de validación
    de tokens y rotación; reutiliza sus keys `invalid_token` y `token_expired`.
- **Objetivo:** Crear el middleware de autenticación por access token en
  `includes/myapi.auth.inc` y exponer `POST /api/v1/auth/logout` que valida
  el par access+refresh de la misma sesión y revoca la fila correspondiente.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.auth.inc`** (nuevo) — `myapi_auth_parse_bearer()` (extrae
  el token del header `Authorization: Bearer <token>`) y
  `myapi_auth_require_access_token()` (valida existencia, no revocado, no
  expirado, usuario activo; devuelve la fila de `my_api_tokens` o detiene
  con error).
- **`includes/myapi.i18n.inc`** (modificar) — añadir las keys
  `missing_authorization` y `logout_success` al catálogo `es`/`en`.
- **`resources/auth.resource.inc`** (modificar) — añadir
  `myapi_auth_logout_dispatch()` y `myapi_auth_logout()`.
- **`myapi.module`** (modificar) — registrar la ruta `api/v1/auth/logout`
  en `hook_menu()`.
- **`myapi.info`** (modificar) — añadir `files[] = includes/myapi.auth.inc`.
- **`docs/auth.md`** (modificar) — documentar el nuevo endpoint.

### Fuera de este spec

- **Logout de todos los dispositivos** (revocar todas las filas del usuario).
- **Uso del middleware en otros endpoints** — este spec solo lo aplica en
  logout; cada endpoint protegido futuro lo adoptará en su propio spec.
- **Rate limiting** sobre el endpoint de logout.
- **Purga de filas revocadas** en `my_api_tokens` (`hook_cron()`).
- **Blacklist de access tokens** — el access token queda técnicamente válido
  hasta su expiración si el cliente lo reutiliza; mitigación es el TTL corto
  definido en `02-login-tokens`.

---

## Modelo de datos

No hay tablas nuevas. Este spec opera sobre `my_api_tokens` existente.

### Keys nuevas en el catálogo (`includes/myapi.i18n.inc`)

| Key                     | `es`                               | `en`                          |
|-------------------------|------------------------------------|-------------------------------|
| `missing_authorization` | No se proporcionó token de acceso. | No access token provided.     |
| `logout_success`        | Sesión cerrada correctamente.      | Logged out successfully.      |

Las keys `invalid_token` y `token_expired` ya existen desde `04-refresh-token`
y se reutilizan sin cambios.

### Contrato de `myapi_auth_require_access_token()`

Entrada: ningún parámetro — lee directamente el header `Authorization`.

Flujo interno:
1. Llamar a `myapi_auth_parse_bearer()`; si devuelve `NULL` →
   `myapi_error('missing_authorization', 401)`.
2. Calcular `$hash = myapi_token_hash($access_token)`.
3. Buscar en `my_api_tokens` por `access_token_hash = $hash` AND `revoked = 0`.
   Sin resultado → `myapi_error('invalid_token', 401)`.
4. Si `access_expires_at < REQUEST_TIME` → `myapi_error('invalid_token', 401)`.
5. Cargar usuario con `user_load($row->uid)`; si `FALSE` o `status != 1` →
   `myapi_error('invalid_token', 401)`.
6. Devolver la fila (`$row`) al llamador.

Salida: objeto `$row` de `my_api_tokens` (garantizado válido).

### Flujo de logout

1. Llamar a `myapi_auth_require_access_token()` → obtener `$row`.
2. Leer body; exigir `refresh_token` con `myapi_request_require_fields()` →
   422 si falta.
3. Calcular `$refresh_hash = myapi_token_hash($refresh_token)`.
4. Verificar que `$refresh_hash === $row->refresh_token_hash`; si no →
   `myapi_error('invalid_token', 401)`.
5. `db_update('my_api_tokens')` con `revoked = 1` donde `id = $row->id`.
6. `myapi_respond([], 200, 'logout_success')`.

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`includes/myapi.i18n.inc` — añadir keys al catálogo.** Insertar
   `missing_authorization` y `logout_success` en los arrays `es` y `en`
   de `myapi_t()`. En este punto las keys existen pero nadie las usa aún.

2. **`includes/myapi.auth.inc` — crear el archivo.** Implementar:
   - `myapi_auth_parse_bearer()` — lee `$_SERVER['HTTP_AUTHORIZATION']`,
     extrae el token del patrón `Bearer <token>` con una regex; devuelve
     el token como string o `NULL` si el header falta o tiene formato
     incorrecto.
   - `myapi_auth_require_access_token()` — ejecuta el flujo de validación
     descrito en el modelo de datos; devuelve `$row` o detiene la petición
     con `myapi_error()`.

3. **`myapi.info` — registrar el archivo nuevo.** Añadir
   `files[] = includes/myapi.auth.inc` para que Drupal lo incluya en el
   autoload del módulo.

4. **`resources/auth.resource.inc` — `myapi_auth_logout_dispatch()`.** Enruta
   por `myapi_request_method()`: solo `POST` → `myapi_auth_logout()`; cualquier
   otro método → `myapi_error('method_not_allowed', 405)`.

5. **`resources/auth.resource.inc` — `myapi_auth_logout()`:** ejecuta el flujo
   de logout descrito en el modelo de datos.

6. **`myapi.module` — `hook_menu()`.** Registrar `api/v1/auth/logout` →
   `page callback: myapi_auth_logout_dispatch`, `access callback: TRUE`,
   `MENU_CALLBACK`, `file => resources/auth.resource.inc`.

7. **`docs/auth.md` — documentar el endpoint.** Añadir la sección
   `POST /api/v1/auth/logout` con headers, body, respuesta 200 y tabla de
   errores (401 `missing_authorization`, 401 `invalid_token`, 422
   `missing_field`, 405 `method_not_allowed`).

8. **Aplicar y verificar.** `drush cc all` y probar con `curl` los casos:
   logout exitoso, header `Authorization` ausente, access token inválido,
   access token expirado, usuario bloqueado, `refresh_token` ausente en body,
   par de tokens de sesiones distintas, token ya revocado, y método `GET`.

---

## Criterios de aceptación

- [x] `POST /api/v1/auth/logout` con access token válido en `Authorization`
      y refresh token válido de la misma sesión en el body devuelve **HTTP 200**
      con `{"success":true,"data":{},"message":"Sesión cerrada correctamente."}`.
- [x] Tras un logout exitoso, la fila correspondiente en `my_api_tokens` tiene
      `revoked = 1`.
- [x] Header `Authorization` ausente → **HTTP 401** con
      `error_code: "missing_authorization"`.
- [x] Header `Authorization` con formato incorrecto (sin `Bearer`, token vacío)
      → **HTTP 401** con `error_code: "missing_authorization"`.
- [x] Access token inexistente en BD → **HTTP 401** con
      `error_code: "invalid_token"`.
- [x] Access token con `revoked = 1` → **HTTP 401** con
      `error_code: "invalid_token"`.
- [x] Access token con `access_expires_at < now` → **HTTP 401** con
      `error_code: "invalid_token"`.
- [x] Access token válido pero usuario con `status = 0` → **HTTP 401** con
      `error_code: "invalid_token"`.
- [x] Access token válido + `refresh_token` ausente en body → **HTTP 422** con
      `error_code: "missing_field"` sin modificar la BD.
- [ ] Access token válido + refresh token de otra sesión del mismo usuario →
      **HTTP 401** con `error_code: "invalid_token"` sin modificar la BD.
- [x] `GET`/`PUT`/`DELETE` sobre `api/v1/auth/logout` → **HTTP 405** con
      `error_code: "method_not_allowed"`.
- [x] `Accept-Language: en` devuelve textos en inglés; sin header o
      `Accept-Language: es` los devuelve en español.
- [x] `myapi_auth_require_access_token()` es invocable desde cualquier recurso
      sin duplicar lógica.
- [x] `drush cc all` registra la nueva ruta sin errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Autenticación del logout | `Authorization: Bearer <access_token>` + `refresh_token` en body | Solo `refresh_token` en body | El access token autentica al llamador; el refresh token identifica la sesión exacta a revocar |
| Validación del par de tokens | Ambos deben pertenecer a la misma fila en `my_api_tokens` | Access token autentica; refresh token revoca cualquier sesión del usuario | Evita que un token válido de dispositivo A revoque la sesión del dispositivo B |
| Error para token mismatch | `invalid_token` (401) | `token_mismatch` como key propia | No revelar la causa al atacante; el cliente legítimo no debería llegar a este estado |
| Error para access token ausente | `missing_authorization` (401) separado de `invalid_token` | Un único `invalid_token` para todo | El cliente necesita distinguir "olvidé el header" de "token inválido" para reaccionar correctamente |
| Ubicación del middleware | `includes/myapi.auth.inc` nuevo | Añadir a `includes/myapi.token.inc` | Responsabilidades distintas: `token.inc` gestiona ciclo de vida del token; `auth.inc` autentica requests HTTP |
| Alcance de la revocación | Solo la sesión del par enviado | Todas las sesiones del usuario | Logout de todos los dispositivos es una funcionalidad distinta con su propio spec |
| Blacklist de access tokens | No implementada | Marcar `access_token_hash` como revocado también | El TTL corto del access token es la mitigación suficiente; la blacklist añade complejidad sin beneficio proporcional en este contexto |
| Mensaje de éxito | Key `logout_success` vía `myapi_respond()` | Sin mensaje en la respuesta | Consistencia con el contrato del envelope; el cliente Flutter puede mostrar feedback al usuario |

---

## Riesgos identificados

- **Ventana de revocación con access token expirado.** Si el access token ya
  expiró pero el refresh token sigue válido, el cliente no puede hacer logout
  a través de este endpoint (recibiría `invalid_token`). *Mitigación:* el
  cliente debe llamar a `/auth/refresh` primero para obtener un nuevo par, y
  luego hacer logout. Este comportamiento es intencionado y queda documentado.

- **Sin protección contra fuerza bruta.** El endpoint acepta intentos
  ilimitados con tokens aleatorios. *Mitigación pendiente:* mismo patrón que
  los riesgos análogos en `02-login-tokens` y `04-refresh-token`; spec propio
  con la Flood API cuando el tráfico lo justifique.

- **`$_SERVER['HTTP_AUTHORIZATION']` puede estar ausente en algunos servidores
  Apache.** Si `mod_rewrite` no pasa el header `Authorization`, la función
  `myapi_auth_parse_bearer()` siempre devolverá `NULL`. *Mitigación:* añadir
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` al `.htaccess`
  del sitio si se detecta el problema en el entorno de producción. Queda fuera
  del alcance de este spec pero documentado aquí.

- **Crecimiento de `my_api_tokens`.** Las filas revocadas por logout se
  acumulan igual que las del refresh. *Mitigación pendiente:* purga vía
  `hook_cron()` mencionada desde `02-login-tokens`.
