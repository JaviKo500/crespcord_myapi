# 04 — Endpoint de refresh token

- **Estado:** Approved
- **Fecha:** 2026-06-29
- **Dependencias:**
  - `02-login-tokens` (Implemented) — tabla `my_api_tokens` y helpers de
    `includes/myapi.token.inc` (generación, hashing, persistencia).
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` y
    `myapi_error()` con `error_code`.
- **Objetivo:** Exponer `POST /api/v1/auth/refresh` que valida un refresh
  token opaco, lo revoca, emite un nuevo par access+refresh y devuelve los
  tokens y los datos básicos del usuario.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.i18n.inc`** (modificar) — añadir dos keys al catálogo:
  `invalid_token` y `token_expired`.
- **`resources/auth.resource.inc`** (modificar) — añadir
  `myapi_auth_refresh_dispatch()` (enruta por método; solo `POST`) y
  `myapi_auth_refresh()` con toda la lógica de validación, rotación y respuesta.
- **`myapi.module`** (modificar) — registrar la ruta `api/v1/auth/refresh` en
  `hook_menu()` apuntando a `myapi_auth_refresh_dispatch`.
- **`docs/auth.md`** (modificar) — documentar el nuevo endpoint siguiendo la
  plantilla del `CLAUDE.md`.

### Fuera de este spec

- **`POST /api/v1/auth/logout`** / revocación explícita de tokens.
- **Middleware de validación de access token** en otros endpoints.
- **Rate limiting** contra replay attacks o fuerza bruta sobre el endpoint.
- **Purga de filas expiradas/revocadas** en `my_api_tokens` (`hook_cron()`).
- **Login por email** u otras formas de autenticación.

---

## Modelo de datos

No hay tablas nuevas. Este spec opera sobre la tabla `my_api_tokens` ya
definida en `02-login-tokens` y reutiliza todos los helpers de
`includes/myapi.token.inc`.

### Keys nuevas en el catálogo (`includes/myapi.i18n.inc`)

| Key | `en` | `es` |
|---|---|---|
| `invalid_token` | Invalid token. | Token inválido. |
| `token_expired` | Token has expired. | El token ha expirado. |

### Flujo de validación y rotación

1. Leer `refresh_token` del body; si falta → `missing_field` 422.
2. Calcular `$hash = myapi_token_hash($refresh_token)`.
3. Buscar en `my_api_tokens` por `refresh_token_hash = $hash` AND `revoked = 0`.
   - Sin resultado → `invalid_token` 401.
4. Si `refresh_expires_at < REQUEST_TIME` → `token_expired` 401.
5. Cargar usuario por `$row->uid`; si no existe o `status != 1` → `invalid_token` 401.
6. Marcar la fila antigua como `revoked = 1`.
7. Generar nuevo par con `myapi_token_generate_access()` /
   `myapi_token_generate_refresh()` y persistir con `myapi_token_persist()`.
8. Devolver nuevo `access_token`, `refresh_token`, `expires_in` y objeto `user`
   (misma estructura que el login).

### Request

```json
POST /api/v1/auth/refresh
Content-Type: application/json

{ "refresh_token": "<128 chars hex>" }
```

### Response — éxito (200)

```json
{
  "success": true,
  "data": {
    "access_token": "<64 chars hex>",
    "refresh_token": "<128 chars hex>",
    "expires_in": 1800,
    "user": {
      "uid": 123,
      "name": "javier",
      "mail": "correo@correo.com",
      "picture": null,
      "roles": [
        { "name": "administrator", "uid": 3 }
      ]
    }
  }
}
```

### Response — error (401)

```json
{ "success": false, "error_code": "invalid_token", "error": "Token inválido." }
```

```json
{ "success": false, "error_code": "token_expired", "error": "El token ha expirado." }
```

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`includes/myapi.i18n.inc` — añadir keys al catálogo.** Insertar
   `invalid_token` y `token_expired` en los arrays `es` y `en` de `myapi_t()`.
   En este punto las keys existen pero nadie las usa aún.

2. **`resources/auth.resource.inc` — `myapi_auth_refresh_dispatch()`.** Enruta
   por `myapi_request_method()`: solo `POST` → `myapi_auth_refresh()`; cualquier
   otro método → `myapi_error('method_not_allowed', 405)`.

3. **`resources/auth.resource.inc` — `myapi_auth_refresh()`:**
   1. Leer body con `myapi_request_body()`; exigir `refresh_token` con
      `myapi_request_require_fields()` (422 si falta).
   2. Calcular `$hash = myapi_token_hash($refresh_token)`.
   3. Buscar fila en `my_api_tokens` con `db_select()` donde
      `refresh_token_hash = $hash` AND `revoked = 0`. Sin resultado →
      `myapi_error('invalid_token', 401)`.
   4. Si `$row->refresh_expires_at < REQUEST_TIME` →
      `myapi_error('token_expired', 401)`.
   5. Cargar usuario con `user_load($row->uid)`. Si devuelve `FALSE` o
      `$account->status != 1` → `myapi_error('invalid_token', 401)`.
   6. Revocar la fila antigua: `db_update('my_api_tokens')` con
      `revoked = 1` donde `id = $row->id`.
   7. Generar nuevo par y persistir con `myapi_token_persist($uid, $access, $refresh)`.
   8. Construir objeto `user` (igual que en `myapi_auth_login()`): `uid`, `name`,
      `mail`, `picture => null`, `roles` mapeando `$account->roles` a lista de
      objetos `['name' => $name, 'uid' => (int) $rid]`.
   9. `myapi_respond([...], 200)` con `access_token`, `refresh_token`,
      `expires_in => myapi_token_access_ttl()`, `user`.

4. **`myapi.module` — `hook_menu()`.** Registrar `api/v1/auth/refresh` →
   `page callback: myapi_auth_refresh_dispatch`, `access callback: TRUE`,
   `MENU_CALLBACK`, `file => resources/auth.resource.inc`.

5. **`docs/auth.md` — documentar el endpoint.** Añadir la sección
   `POST /api/v1/auth/refresh` con body, respuesta 200, y tabla de errores
   (401 `invalid_token`, 401 `token_expired`, 422 `missing_field`, 405
   `method_not_allowed`).

6. **Aplicar y verificar.** `drush cc all` y probar con `curl` los casos:
   token válido, token inexistente, token expirado, token ya revocado, usuario
   bloqueado, campo `refresh_token` ausente, y método `GET`.

---

## Criterios de aceptación

- [ ] `POST /api/v1/auth/refresh` con un refresh token válido devuelve **HTTP 200**
      con `{"success":true,"data":{...}}` conteniendo `access_token` (64 chars hex),
      `refresh_token` (128 chars hex), `expires_in` (TTL de access vigente) y
      `user{uid,name,mail,picture:null,roles[]}`.
- [ ] Tras un refresh exitoso, la fila antigua en `my_api_tokens` tiene `revoked=1`
      y existe una fila nueva con `revoked=0` y los nuevos hashes.
- [ ] El refresh token devuelto es distinto al enviado (rotación real).
- [ ] Un refresh token inexistente en BD → **HTTP 401** con
      `error_code: "invalid_token"`.
- [ ] Un refresh token ya revocado (`revoked=1`) → **HTTP 401** con
      `error_code: "invalid_token"`.
- [ ] Un refresh token con `refresh_expires_at < now` → **HTTP 401** con
      `error_code: "token_expired"`.
- [ ] Un refresh token válido cuyo usuario tiene `status=0` → **HTTP 401** con
      `error_code: "invalid_token"`.
- [ ] Usar el mismo refresh token por segunda vez (ya revocado tras el primer
      refresh) → **HTTP 401** con `error_code: "invalid_token"`.
- [ ] Falta `refresh_token` en el body → **HTTP 422** con
      `error_code: "missing_field"` sin tocar la BD.
- [ ] `GET`/`PUT`/`DELETE` sobre `api/v1/auth/refresh` → **HTTP 405** con
      `error_code: "method_not_allowed"`.
- [ ] `Accept-Language: en` en cualquier error devuelve el texto en inglés;
      sin header o `Accept-Language: es` lo devuelve en español.
- [ ] `drush cc all` registra la nueva ruta sin errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Rotación de tokens | Revocar fila antigua (`revoked=1`) e insertar fila nueva | Actualizar la fila existente con los nuevos hashes | Más auditable; el historial de sesiones queda intacto en BD |
| Error para token expirado | `token_expired` (401) distinto de `invalid_token` | Un único `invalid_token` para todo | El cliente Flutter puede distinguir "sesión caducada" de "token robado/inválido" y actuar diferente |
| Error para token inexistente/revocado | `invalid_token` (401) sin distinguir causa | Codes separados para "no existe" vs. "revocado" | No revelar el estado interno del token a un posible atacante |
| Verificación del usuario | Comprobar `status=1` tras encontrar el token | No verificar (confiar en el token) | Un usuario bloqueado no debe poder renovar su sesión |
| Usuario bloqueado | `invalid_token` (401) | Error específico `user_blocked` | No revelar la causa; mismo comportamiento que token inválido |
| Dispatcher | Función separada `myapi_auth_refresh_dispatch()` | Extender `myapi_auth_dispatch()` para manejar sub-rutas | Cada ruta de `hook_menu()` tiene su propio callback; más limpio y alineado con el patrón del proyecto |
| Ubicación | Mismo `auth.resource.inc` | Archivo `auth-refresh.resource.inc` nuevo | El refresh es parte del recurso auth; no justifica un archivo nuevo |
| Objeto `user` en la respuesta | Mismo formato que login | Devolver solo `uid` | Consistencia; el cliente ya espera el mismo objeto |
| Keys i18n nuevas | Añadir `invalid_token` y `token_expired` al catálogo existente | Strings en duro en el recurso | Mantiene el contrato de `myapi_error()` con keys; evita texto crudo como `error_code` |

---

## Riesgos identificados

- **Ventana de replay concurrente.** Si dos peticiones llegan simultáneamente
  con el mismo refresh token válido, ambas podrían superar la validación antes
  de que la primera revoque la fila. *Mitigación:* el riesgo es mínimo en un
  servidor PHP de proceso único con MySQL y transacciones implícitas; no se
  añade locking explícito en este spec, pero queda documentado para un
  spec futuro si el tráfico lo justifica.

- **Sin protección contra fuerza bruta.** El endpoint acepta intentos
  ilimitados de refresh tokens aleatorios. *Mitigación pendiente:* mismo patrón
  que el riesgo análogo en `02-login-tokens`; spec propio con la Flood API.

- **Crecimiento de `my_api_tokens`.** Cada refresh exitoso inserta una fila
  nueva sin eliminar la revocada. *Mitigación pendiente:* tarea de purga vía
  `hook_cron()` mencionada en `02-login-tokens`; fuera de alcance aquí.

- **`user_load()` hace una query por petición.** Si el volumen de refreshes
  es alto, cargar el objeto completo del usuario en cada llamada puede ser
  costoso. *Mitigación:* Drupal 7 cachea `user_load()` en memoria estática
  dentro de la misma petición; en este contexto de API stateless no hay impacto.
