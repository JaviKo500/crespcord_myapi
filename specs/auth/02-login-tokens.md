# 02 — Endpoint de login con tokens opacos

- **Estado:** Implemented
- **Fecha:** 2026-06-29
- **Dependencias:** `01-bootstrap-modulo` (Implemented) — usa los helpers
  `myapi_respond()` / `myapi_error()` y `myapi_request_*()`, y el patrón
  recurso+dispatcher.
- **Objetivo:** Exponer `POST /api/v1/auth/login` que autentica por
  `username`+`password` contra `dr_users`, y al validar emite un access
  token opaco (30 min) y un refresh token opaco (30 días) cuyos hashes
  SHA-256 se persisten en una nueva tabla `my_api_tokens`, devolviendo los
  tokens y los datos básicos del usuario.

---

## Alcance

### Dentro de este spec
- **`myapi.install`** — definir `my_api_tokens` en `hook_schema()` y añadir
  `hook_update_7001()` para crearla en instalaciones ya activas.
- **`resources/auth.resource.inc`** — `myapi_auth_dispatch()` (enruta por
  método; solo `POST`) y `myapi_auth_login()` con toda la lógica del login.
- **`includes/myapi.token.inc`** — helpers compartidos de tokens: generación
  (`random_bytes`), hashing SHA-256 y persistencia en `my_api_tokens`. Aquí
  para que refresh/logout futuros lo reutilicen sin duplicar.
- **`myapi.module`** — registrar la ruta `api/v1/auth/login` en
  `hook_menu()`, acceso público.
- **`myapi.info`** — añadir `files[]` para `resources/auth.resource.inc` y
  `includes/myapi.token.inc`.
- **`docs/auth.md`** — documentación del endpoint de login.

### Fuera de este spec
- **`POST /api/v1/auth/refresh`** y la rotación de refresh tokens (spec
  propio; la tabla y los helpers ya quedan listos para soportarlo).
- **`POST /api/v1/auth/logout`** / revocación de tokens.
- **Middleware de validación de access token** en otros endpoints.
- **Login por email** (solo `username` en este spec).
- **Rate limiting / flood control** contra fuerza bruta (se documenta como
  riesgo).
- **Resolver `picture`** (fid → URL): se devuelve `null`.
- **Sesiones únicas**: no se revocan sesiones previas al hacer login (se
  permiten múltiples dispositivos).

---

## Modelo de datos

### Tabla nueva `my_api_tokens` (Drupal 7 Schema API, en `hook_schema()`)

| Columna | Tipo Schema API | Notas |
|---|---|---|
| `id` | `serial`, not null | PK autoincremental |
| `uid` | `int`, not null | FK lógica a `dr_users.uid` |
| `access_token_hash` | `char` length 64, not null | SHA-256 hex del access token |
| `refresh_token_hash` | `char` length 64, not null | SHA-256 hex del refresh token |
| `access_expires_at` | `int`, not null | timestamp Unix de expiración del access |
| `refresh_expires_at` | `int`, not null | timestamp Unix de expiración del refresh |
| `revoked` | `int` size `tiny`, default 0 | 0 = activo, 1 = revocado |
| `created` | `int`, not null | timestamp de emisión |
| `last_used` | `int`, **null** | última vez que se usó el access token |
| `user_agent` | `varchar` length 255 | del header `User-Agent` |
| `ip_address` | `varchar` length 45 | soporta IPv6 |

- **Primary key:** `id`
- **Indexes:** `uid`, `access_token_hash`, `refresh_token_hash`

### Tokens (nunca se guardan en claro)
- `access` = `bin2hex(random_bytes(32))` → 64 chars hex; TTL por defecto
  **1800 s** (30 min), configurable.
- `refresh` = `bin2hex(random_bytes(64))` → 128 chars hex; TTL por defecto
  **2592000 s** (30 días), configurable.
- En BD solo se persiste `hash('sha256', $token)` (64 chars). Para verificar
  en el futuro se hashea el token entrante y se busca por el índice
  correspondiente.

### Configuración de TTL (variables Drupal)
Los tiempos de validez son **configurables sin tocar código** vía el sistema
de variables de Drupal 7 (tabla `dr_variable`), con defaults en constantes:

| Variable | Default (constante) | Significado |
|---|---|---|
| `myapi_token_access_ttl` | `1800` (`MYAPI_TOKEN_ACCESS_TTL_DEFAULT`) | segundos de validez del access token |
| `myapi_token_refresh_ttl` | `2592000` (`MYAPI_TOKEN_REFRESH_TTL_DEFAULT`) | segundos de validez del refresh token |

Cambio en caliente (sin redeploy ni reinstalar):
```bash
drush vset myapi_token_access_ttl 3600     # access a 1 hora
drush vset myapi_token_refresh_ttl 1209600 # refresh a 14 días
drush vget myapi_token_access_ttl          # consultar valor actual
```
El `expires_in` de la respuesta refleja el valor **vigente** de
`myapi_token_access_ttl`, no un número fijo.

### Request
```json
POST /api/v1/auth/login
Content-Type: application/json

{ "username": "javier", "password": "1234" }
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
        { "name": "administrator", "uid": 3 },
        { "name": "authenticated user", "uid": 2 }
      ]
    }
  }
}
```

> **Nota sobre `roles`:** es un array de objetos `{ "name", "uid" }`, donde
> `name` es `dr_role.name` y `uid` es el **rid** del rol (`dr_role.rid`). La
> clave se llama `uid` por requerimiento del cliente, pero su valor es el id
> del rol, no el del usuario.

### Response — error (401)
```json
{ "success": false, "error": "Invalid credentials" }
```

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`myapi.install` — `hook_schema()`.** Definir el array de esquema de
   `my_api_tokens` con columnas, PK e índices descritos en el modelo de
   datos. En una reinstalación del módulo la tabla se crea sola.

2. **`myapi.install` — `hook_update_7001()`.** Crear la tabla en sitios donde
   el módulo ya está activo: si `!db_table_exists('my_api_tokens')`, llamar a
   `db_create_table('my_api_tokens', <esquema>)`. Reutiliza la misma
   definición del paso 1. Se aplica con `drush updb`.

3. **`includes/myapi.token.inc` — helpers compartidos:**
   - `myapi_token_generate_access()` → `bin2hex(random_bytes(32))`.
   - `myapi_token_generate_refresh()` → `bin2hex(random_bytes(64))`.
   - `myapi_token_hash($token)` → `hash('sha256', $token)`.
   - `myapi_token_access_ttl()` → `variable_get('myapi_token_access_ttl', MYAPI_TOKEN_ACCESS_TTL_DEFAULT)`.
   - `myapi_token_refresh_ttl()` → `variable_get('myapi_token_refresh_ttl', MYAPI_TOKEN_REFRESH_TTL_DEFAULT)`.
   - `myapi_token_persist($uid, $access, $refresh)` → inserta una fila en
     `my_api_tokens` con los hashes,
     `access_expires_at = REQUEST_TIME + myapi_token_access_ttl()`,
     `refresh_expires_at = REQUEST_TIME + myapi_token_refresh_ttl()`,
     `created = REQUEST_TIME`, `revoked = 0`, y captura `user_agent`
     (`$_SERVER['HTTP_USER_AGENT']`) e `ip_address` (`ip_address()` de Drupal).
     Devuelve el `id` insertado.

4. **`includes/myapi.token.inc` — defaults de TTL en constantes**
   (`MYAPI_TOKEN_ACCESS_TTL_DEFAULT = 1800`,
   `MYAPI_TOKEN_REFRESH_TTL_DEFAULT = 2592000`). Son solo los valores por
   defecto: el valor efectivo lo resuelven los helpers
   `myapi_token_access_ttl()` / `myapi_token_refresh_ttl()` vía
   `variable_get()`, configurables con `drush vset` sin tocar código.

5. **`resources/auth.resource.inc` — `myapi_auth_dispatch()`.** Enruta por
   `myapi_request_method()`: solo `POST` → `myapi_auth_login()`; cualquier
   otro método → `myapi_error('Method not allowed', 405)`.

6. **`resources/auth.resource.inc` — `myapi_auth_login()`:**
   1. Leer body con `myapi_request_body()`; exigir `username` y `password`
      con `myapi_request_require_fields()` (422 si faltan).
   2. Cargar usuario con `user_load_by_name($username)`.
   3. Validar credenciales: si no existe, `uid == 0`, `status == 0`, o
      `user_check_password($password, $account)` es falso →
      `myapi_error('Invalid credentials', 401)`. **Mismo mensaje y código** en
      todos los casos (no revelar si el usuario existe).
   4. Generar access y refresh; persistir con `myapi_token_persist()`.
   5. Construir el objeto `user` de la respuesta: `uid`, `name`, `mail`,
      `picture => null`, y `roles` mapeando `$account->roles` (array
      `rid => name`) a una lista de objetos
      `['name' => $name, 'uid' => (int) $rid]`.
   6. `myapi_respond([...], 200)` con `access_token`, `refresh_token`,
      `expires_in => myapi_token_access_ttl()`, `user`.

7. **`myapi.module` — `hook_menu()`.** Registrar `api/v1/auth/login` →
   `page callback` `myapi_auth_dispatch`, `access callback TRUE`,
   `MENU_CALLBACK`, `file => resources/auth.resource.inc`.

8. **`myapi.info`.** Añadir `files[] = resources/auth.resource.inc` y
   `files[] = includes/myapi.token.inc`.

9. **`docs/auth.md`.** Documentar el endpoint siguiendo la plantilla del
   `CLAUDE.md` (método, auth pública, body, respuesta 200, tabla de errores
   401/422/405).

10. **Aplicar y verificar.** `drush updb` (crea la tabla), `drush cc all`
    (registra la ruta) y probar con `curl` casos OK y de error.

---

## Criterios de aceptación

- [x] `drush updb` crea la tabla `my_api_tokens` con todas las columnas, PK e
      índices (`uid`, `access_token_hash`, `refresh_token_hash`).
- [x] Reinstalar el módulo (`drush dis myapi && drush en myapi`) también crea
      la tabla vía `hook_schema()`.
- [x] `POST /api/v1/auth/login` con `username`+`password` válidos de un
      usuario con `status=1` devuelve **HTTP 200** y el envelope
      `{"success":true,"data":{...}}` con `access_token`, `refresh_token`,
      `expires_in` (= TTL de access vigente, por defecto `1800`) y
      `user{uid,name,mail,picture:null,roles[]}`, donde cada elemento de
      `roles` es `{name, uid}` con `uid` = rid del rol.
- [x] Tras un login exitoso existe **una fila nueva** en `my_api_tokens` con
      `revoked=0`, los dos hashes (64 chars cada uno),
      `access_expires_at = created + TTL access`,
      `refresh_expires_at = created + TTL refresh`, y `user_agent`/`ip_address`
      poblados.
- [x] Con `drush vset myapi_token_access_ttl 3600`, un login posterior
      devuelve `expires_in:3600` y persiste `access_expires_at = created+3600`,
      **sin** cambios de código ni reinstalar. Sin la variable definida, el
      default sigue siendo `1800` (y `2592000` para el refresh).
- [x] En BD **no** aparece ningún token en claro: solo los hashes SHA-256.
- [x] `access_token` devuelto mide 64 chars hex; `refresh_token` mide 128
      chars hex.
- [x] Password incorrecta → **HTTP 401** y
      `{"success":false,"error":"Invalid credentials"}`.
- [x] Usuario inexistente → **HTTP 401** con el **mismo** cuerpo
      `Invalid credentials` (no se distingue de password incorrecta).
- [x] Usuario bloqueado (`status=0`) con password correcta → **HTTP 401**
      `Invalid credentials`.
- [x] Falta `username` o `password` en el body → **HTTP 422** sin llegar a
      tocar la BD.
- [x] `GET`/`PUT`/`DELETE` sobre `api/v1/auth/login` → **HTTP 405**.
- [x] `resources/auth.resource.inc` e `includes/myapi.token.inc` están
      listados en `files[]` de `myapi.info` (sin errores "undefined function").

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Alcance | Solo `login` + tabla | Incluir refresh/logout | Spec enfocado; la tabla y los helpers quedan listos para refresh/logout futuros |
| Identificador de login | Solo `username` | username + email | Simplicidad; `user_load_by_name()` directo. Email queda para otro spec |
| Verificación de password | `user_check_password()` | `user_authenticate()` | Evita hooks de login y actualizar `dr_users.login/access`; solo valida credenciales |
| Clave del envelope de error | `"error"` | `"message"` (del prompt) | Respeta `myapi_error()` y la regla "sin excepciones" del envelope en `CLAUDE.md` |
| Nombre de la tabla | `my_api_tokens` | `myapi_tokens` (convención) | Decisión explícita del usuario; excepción consciente al prefijo `myapi_` |
| Creación de la tabla | `hook_schema()` + `hook_update_7001()` | Solo `hook_install()` | El módulo ya está instalado; `updb` la crea sin reinstalar |
| Lógica de tokens | Helper compartido `includes/myapi.token.inc` | Inline en `auth.resource.inc` | Reuso por refresh/logout sin duplicar (regla 3 y 5 del `CLAUDE.md`) |
| Código de éxito | `200` | `201 Created` | Es autenticación, no creación de recurso REST |
| TTL de los tokens | Configurables vía `variable_get()` con defaults en constantes | Constantes fijas en código | Cambiar tiempos con `drush vset` sin redeploy; escalable y por entorno |
| Usuarios no válidos | Rechazar `status=0` y `uid=0` | Solo validar password | Seguridad: bloqueados y anónimo no inician sesión |
| Mensaje en fallos | Mismo `Invalid credentials` (401) para todos | Distinguir "no existe" vs "password mala" | No revelar existencia de cuentas |
| Campo `picture` | Siempre `null` | Resolver fid → URL | Refleja el ejemplo; resolución de archivo queda fuera de alcance |
| Campo `roles` | Array de objetos `{name, uid}` con `uid` = `dr_role.rid` (incl. `authenticated user`) | Array plano de nombres | Formato pedido por el cliente; expone el id del rol junto al nombre |
| Sesiones | Múltiples por usuario | Sesión única (revocar previas) | La tabla no fuerza unicidad por `uid`; soporta multi-dispositivo |
| Rate limiting | Fuera de alcance | Flood API en este spec | Mantiene el spec enfocado; se registra como riesgo |

---

## Riesgos identificados

- **Sin protección contra fuerza bruta.** El endpoint acepta intentos
  ilimitados. En Drupal 7 (EOL, sin parches) esto es explotable. *Mitigación
  pendiente:* spec propio con la Flood API (`flood_is_allowed()` /
  `flood_register_event()`) limitando por IP y por usuario. Documentarlo
  también en `docs/auth.md`.

- **`random_bytes()` requiere PHP ≥ 7.0.** El entorno es PHP 7.4, así que está
  disponible; pero si alguna vez se ejecuta bajo PHP 5.x el login lanzaría
  fatal. *Mitigación:* el `CLAUDE.md` fija PHP 7.4 como restricción no
  negociable; no añadir fallback con `mt_rand()` (inseguro).

- **`php://input` ya consumido.** Si algo lee el stream antes que
  `myapi_request_body()`, el body llega vacío y el login responde 422.
  *Mitigación:* el helper ya cachea el resultado en variable estática
  (heredado del spec 01).

- **Tokens en tránsito sin HTTPS.** Los tokens opacos viajan en claro en la
  respuesta; sobre HTTP serían interceptables. *Mitigación:* HTTPS
  obligatorio en producción (restricción del `CLAUDE.md`).

- **Crecimiento ilimitado de `my_api_tokens`.** Sin purga, las filas
  expiradas/revocadas se acumulan (cada login inserta una). *Mitigación:*
  tarea de limpieza (p.ej. `hook_cron()` que borre tokens con
  `refresh_expires_at < REQUEST_TIME`) en un spec futuro; fuera de alcance
  aquí.

- **Colisión de hash en la búsqueda futura.** Al verificar tokens
  (refresh/middleware) se buscará por `*_token_hash`; un índice no único
  permite teóricamente duplicados. *Mitigación:* el espacio de 256 bits hace
  la colisión despreciable; el refresh deberá además comparar `uid` y
  `revoked=0`.
