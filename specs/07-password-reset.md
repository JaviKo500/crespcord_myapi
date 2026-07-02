# 07 — Reset de password vía token por email

- **Estado:** Implemented
- **Fecha:** 2026-07-01
- **Dependencias:**
  - `02-login-tokens` (Implemented) — tabla `my_api_tokens`, helpers de
    `includes/myapi.token.inc`.
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` y
    `myapi_error()`.
  - `04-refresh-token` (Implemented) — patrón de revocación de tokens en
    `my_api_tokens`.
  - `06-brute-force-protection` (Implemented) — helpers `myapi_flood_check()` /
    `myapi_flood_register()`.
- **Objetivo:** Exponer `POST /api/v1/auth/password/forgot` y
  `POST /api/v1/auth/password/reset` para que un usuario recupere el acceso a
  su cuenta mediante un token de un solo uso enviado por correo con un deep
  link a la app.

---

## Alcance

### Dentro de este spec

**API JSON (para la app Flutter):**
- **`includes/myapi.i18n.inc`** (modificar) — keys nuevas: `password_reset_requested`,
  `password_reset_success`, `password_reset_email_subject`,
  `password_reset_email_body`, `field_too_short`, más las keys de textos de la
  página web (`password_reset_page_title`, `password_reset_page_new_password_label`,
  `password_reset_page_submit_button`, `password_reset_page_success`).
- **`includes/myapi.request.inc`** (modificar) — `myapi_request_require_strings()`
  gana un parámetro opcional `$min_length` (default `1`, compatible con
  llamadas existentes).
- **`includes/myapi.token.inc`** (modificar) — `myapi_token_generate_reset()`,
  `myapi_password_reset_ttl()`, `myapi_password_reset_token_persist()`,
  `myapi_password_reset_token_invalidate_previous()`.
- **`includes/myapi.flood.inc`** (modificar) — refactor mínimo (ver Plan) +
  defaults para `myapi_forgot_ip`, `myapi_forgot_identifier`, `myapi_reset_ip`
  (este último evento se comparte entre el endpoint JSON y la página web
  fallback: un solo contador para ambas superficies).
- **`includes/myapi.mail.inc`** (nuevo) — `myapi_mail_format_password_reset()`,
  arma el correo con un único link web (`https://.../password/reset?token=xxx`,
  vía `url(..., ['absolute' => TRUE])`).
- **`myapi.module`** (modificar) — `hook_mail()` delgado, y en `hook_menu()`:
  dos rutas API (`api/v1/auth/password/forgot`, `api/v1/auth/password/reset`)
  + una ruta de página (`password/reset`, fuera de `api/v1`).
- **`myapi.install`** (modificar) — tabla nueva `myapi_password_reset_tokens` +
  `myapi_update_7002()`.
- **`resources/auth.resource.inc`** (modificar) — añade:
  - `myapi_auth_password_forgot_dispatch()` / `myapi_auth_password_forgot()`.
  - `myapi_auth_password_reset_execute($token, $new_password)` — lógica pura
    compartida (busca token, valida expiración/usuario/longitud de password,
    actualiza con `user_save()`, revoca sesiones); devuelve un array de
    resultado, sin tocar HTTP output.
  - `myapi_auth_password_reset_dispatch()` / `myapi_auth_password_reset()` —
    envuelve el flood check + `myapi_auth_password_reset_execute()` y responde
    en JSON.
  - `myapi_auth_password_reset_page()` — page callback GET/POST fuera de
    `api/v1`; en GET imprime HTML mínimo con el formulario (y el intento de
    redirección al deep link vía meta-refresh); en POST reutiliza el mismo
    flood check + `myapi_auth_password_reset_execute()` y responde en HTML.
- **`myapi.info`** (modificar) — registrar `files[] = includes/myapi.mail.inc`.
- **`docs/auth.md`** (modificar) — documentar los dos endpoints JSON. La página
  `password/reset` se documenta también, dejando explícito que no sigue el
  envelope JSON del resto de la API (es la única excepción, justificada porque
  es HTML servido al navegador, no una respuesta de API).

### Fuera de este spec

- **Complejidad de password** más allá del mínimo de 8 caracteres.
- **Purga de filas expiradas/usadas** en `myapi_password_reset_tokens`
  (pendiente para `hook_cron()` futuro).
- **Registro/alta de nuevos usuarios.**
- **Configuración de transporte de correo** (SMTP externo, colas, proveedores).
- **Notificación adicional** al completar el reset (ej. correo "tu password
  cambió").
- **Registro real del custom URL scheme `myapp://`** en la app Flutter
  (Android App Links / iOS Universal Links) — eso vive en el proyecto Flutter,
  no en este módulo Drupal. Aquí solo se genera el link y se intenta la
  redirección desde la página web.
- **Theming/CSS de la página web fallback** — HTML mínimo sin estilos, no se
  integra con el theme de Drupal.

---

## Modelo de datos

### Tabla nueva: `myapi_password_reset_tokens`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | serial, unsigned, not null | Primary key. |
| `uid` | int, unsigned, not null | FK lógica a `dr_users.uid`. |
| `token_hash` | char(64), not null | SHA-256 hex del token (`myapi_token_hash()`, reutilizado de `myapi.token.inc`). |
| `expires_at` | int, not null | `REQUEST_TIME + myapi_password_reset_ttl()`. |
| `used` | int tiny, not null, default 0 | `0` = sin usar, `1` = usado (por reset exitoso o por invalidación al pedir un nuevo forgot). |
| `created` | int, not null | `REQUEST_TIME` de creación. |
| `ip_address` | varchar(45) | IP de la request `/forgot` que generó el token. |

Índices: `uid`, `token_hash`.

Se crea vía `hook_schema()` + `myapi_update_7002()` (mismo patrón que
`myapi_update_7001()` para `my_api_tokens`).

### Variables Drupal configurables

| Variable | Default | Significado |
|---|---|---|
| `myapi_password_reset_ttl` | `3600` | TTL del token de reset en segundos (1 h). |
| `myapi_flood_forgot_ip_limit` | `10` | Intentos de `/forgot` por IP antes de bloquear. |
| `myapi_flood_forgot_ip_window` | `3600` | Ventana en segundos (1 h). |
| `myapi_flood_forgot_identifier_limit` | `3` | Intentos de `/forgot` por el mismo `username`/`email` antes de bloquear. |
| `myapi_flood_forgot_identifier_window` | `3600` | Ventana en segundos (1 h). |
| `myapi_flood_reset_ip_limit` | `10` | Intentos de `/reset` (JSON + página web, mismo contador) por IP antes de bloquear. |
| `myapi_flood_reset_ip_window` | `900` | Ventana en segundos (15 min). |
| `myapi_password_reset_deep_link_base` | `myapp://reset-password` | Base del deep link al que la página web intenta redirigir. |

### Keys nuevas en el catálogo (`includes/myapi.i18n.inc`)

| Key | `es` | `en` |
|---|---|---|
| `password_reset_requested` | Si la cuenta existe, se envió un correo con instrucciones. | If the account exists, an email with instructions was sent. |
| `password_reset_success` | Contraseña actualizada correctamente. | Password updated successfully. |
| `field_too_short` | Campo demasiado corto: @field | Field too short: @field |
| `password_reset_email_subject` | Restablece tu contraseña | Reset your password |
| `password_reset_email_body` | Usa este enlace para restablecer tu contraseña: @link. Este enlace expira en @minutes minutos. Si no solicitaste este cambio, puedes ignorar este correo. | Use this link to reset your password: @link. This link expires in @minutes minutes. If you did not request this, you can ignore this email. |
| `password_reset_page_title` | Restablece tu contraseña | Reset your password |
| `password_reset_page_new_password_label` | Nueva contraseña | New password |
| `password_reset_page_submit_button` | Restablecer contraseña | Reset password |
| `password_reset_page_success` | Tu contraseña fue actualizada. Ya puedes iniciar sesión desde la app. | Your password has been updated. You can now log in from the app. |

### Endpoints — request/response

**`POST /api/v1/auth/password/forgot`**
```json
{ "username": "javier" }
```
o
```json
{ "email": "correo@correo.com" }
```
Al menos uno de los dos debe venir; si ambos vienen, se prueba primero
`username`. Respuesta siempre `200`, exista o no la cuenta:
```json
{ "success": true, "data": {}, "message": "Si la cuenta existe, se envió un correo con instrucciones." }
```

**`POST /api/v1/auth/password/reset`**
```json
{ "token": "<64 chars hex>", "new_password": "12345678" }
```
Éxito (`200`):
```json
{ "success": true, "data": {}, "message": "Contraseña actualizada correctamente." }
```

**`GET/POST password/reset?token=xxx`** (página web fallback, fuera de
`api/v1`, responde HTML)
- `GET`: imprime HTML mínimo con
  `<meta http-equiv="refresh" content="0;url=myapp://reset-password?token=xxx">`
  + un formulario con campo `new_password` y `token` oculto, que hace `POST` a
  la misma URL.
- `POST`: valida y ejecuta el mismo reset; en éxito muestra el mensaje
  `password_reset_page_success`; en error muestra el mensaje traducido
  correspondiente y vuelve a mostrar el formulario.

### Contrato de la función núcleo compartida

**`myapi_auth_password_reset_execute($token, $new_password)`** (en
`resources/auth.resource.inc`, privada al recurso)
- Asume que `$token` y `$new_password` ya son strings no vacíos (la presencia
  la valida cada caller).
- Valida longitud de `$new_password` (8–255) → `field_too_short` / `field_too_long`.
- Hashea el token, busca en `myapi_password_reset_tokens` con `used = 0` →
  `invalid_token` si no existe.
- Verifica `expires_at >= REQUEST_TIME` → `token_expired`.
- Carga el usuario (`user_load($row->uid)`), verifica `status = 1` →
  `invalid_token`.
- Marca la fila `used = 1`, actualiza el password con
  `user_save($account, ['pass' => $new_password])`, revoca todas las filas
  activas de `my_api_tokens` del usuario.
- Devuelve `['ok' => TRUE]` o `['ok' => FALSE, 'error_code' => '...', 'replacements' => [...]]`.
- Tanto `myapi_auth_password_reset()` (JSON) como `myapi_auth_password_reset_page()`
  (HTML) llaman a esta función y solo difieren en cómo presentan el resultado.

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`includes/myapi.i18n.inc` — añadir las keys nuevas al catálogo.** Insertar
   las 9 keys definidas en el modelo de datos en los arrays `es` y `en` de
   `myapi_t()`. En este punto existen pero nadie las usa.

2. **`includes/myapi.request.inc` — extender `myapi_request_require_strings()`.**
   Añadir parámetro opcional `$min_length = 1` (compatible con las 3 llamadas
   existentes, que no lo pasan). Si `strlen($body[$field]) < $min_length` →
   `myapi_error('field_too_short', 422, ['@field' => $field])`.

3. **`includes/myapi.token.inc` — helpers de reset.** Añadir
   `define('MYAPI_PASSWORD_RESET_TTL_DEFAULT', 3600)`, `myapi_password_reset_ttl()`,
   `myapi_token_generate_reset()` (64 hex chars, mismo patrón que
   `myapi_token_generate_access()`), `myapi_password_reset_token_persist($uid, $token, $ip)`,
   `myapi_password_reset_token_invalidate_previous($uid)`
   (`UPDATE ... SET used = 1 WHERE uid = $uid AND used = 0`).

4. **`myapi.install` — tabla nueva.** Añadir `myapi_password_reset_tokens` a
   `hook_schema()` (ver Modelo de datos) y `myapi_update_7002()` que la crea en
   sitios ya habilitados, con el mismo patrón que `myapi_update_7001()`.

5. **`includes/myapi.flood.inc` — refactor mínimo + eventos nuevos.**
   - Extraer la lógica de `flood_is_allowed()` de `myapi_flood_check()` a una
     función nueva `myapi_flood_is_allowed($event, $identifier, $limit_var, $window_var)`
     que solo devuelve `TRUE`/`FALSE` (sin tocar HTTP output). `myapi_flood_check()`
     pasa a delegar en ella y mantiene exactamente el mismo comportamiento
     para las 5 llamadas existentes.
   - Añadir a los arrays estáticos de defaults: `myapi_flood_forgot_ip_limit/window`,
     `myapi_flood_forgot_identifier_limit/window`, `myapi_flood_reset_ip_limit/window`.
   - `myapi_flood_is_allowed()` se reutilizará en el paso 12 para la página web
     (que no puede usar `myapi_flood_check()` porque este emite JSON en el
     límite).

6. **`includes/myapi.mail.inc` (nuevo archivo) — formateo del correo.**
   `myapi_mail_format_password_reset(&$message, $params)`: setea
   `$message['subject']` y `$message['body'][]` con
   `myapi_t('password_reset_email_subject'/'password_reset_email_body', ['@link' => $params['link'], '@minutes' => $params['minutes']], $params['language'])`.

7. **`myapi.info` — registrar el archivo nuevo.** Añadir
   `files[] = includes/myapi.mail.inc`.

8. **`myapi.module`:**
   - Implementar `hook_mail($key, &$message, $params)`: para
     `$key == 'password_reset'`, `module_load_include('inc', 'myapi', 'includes/myapi.mail')`
     y delega a `myapi_mail_format_password_reset()`.
   - Registrar en `hook_menu()`: `api/v1/auth/password/forgot` →
     `myapi_auth_password_forgot_dispatch`, `api/v1/auth/password/reset` →
     `myapi_auth_password_reset_dispatch`, y `password/reset` (fuera de
     `api/v1`) → `myapi_auth_password_reset_page`, las tres con
     `access callback: TRUE`, `MENU_CALLBACK`, `file: resources/auth.resource.inc`.

9. **`resources/auth.resource.inc` — `myapi_auth_password_forgot_dispatch()` /
   `myapi_auth_password_forgot()`:**
   1. `myapi_flood_check('myapi_forgot_ip', $ip, 'myapi_flood_forgot_ip_limit', 'myapi_flood_forgot_ip_window')`.
   2. Leer body; si no viene `username` ni `email` →
      `myapi_error('missing_field', 422, ['@field' => 'username_or_email'])`.
   3. Resolver `$identifier`: `username` si viene, si no `email`.
   4. `myapi_flood_check('myapi_forgot_identifier', $identifier, 'myapi_flood_forgot_identifier_limit', 'myapi_flood_forgot_identifier_window')`.
   5. Registrar ambos eventos **incondicionalmente** (`myapi_flood_register`) —
      antes de saber si la cuenta existe, por diseño.
   6. Buscar cuenta: `user_load_by_name($username)` si vino `username`, si no
      `user_load_by_mail($email)`.
   7. Si existe y `status == 1`: generar token (`myapi_token_generate_reset()`),
      invalidar tokens previos del uid, persistirlo, construir
      `$link = url('password/reset', ['query' => ['token' => $token], 'absolute' => TRUE])`,
      `$minutes = round(myapi_password_reset_ttl() / 60)`, y
      `drupal_mail('myapi', 'password_reset', $account->mail, myapi_get_lang(), ['link' => $link, 'minutes' => $minutes, 'language' => myapi_get_lang()])`.
   8. Responder siempre `myapi_respond([], 200, 'password_reset_requested')`,
      exista o no la cuenta.

10. **`resources/auth.resource.inc` — `myapi_auth_password_reset_execute($token, $new_password)`.**
    Lógica pura (ver contrato en Modelo de datos): valida longitud, busca
    token, verifica expiración, carga y valida usuario, marca token usado,
    `user_save()`, revoca `my_api_tokens` del uid. Devuelve array de resultado,
    sin emitir HTTP output.

11. **`resources/auth.resource.inc` — `myapi_auth_password_reset_dispatch()` /
    `myapi_auth_password_reset()` (JSON):**
    1. `myapi_flood_check('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_limit', 'myapi_flood_reset_ip_window')`.
    2. `myapi_request_require_fields($body, ['token', 'new_password'])`.
    3. `$result = myapi_auth_password_reset_execute($token, $new_password)`.
    4. Si falla: `myapi_flood_register('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_window')`
       → `myapi_error($result['error_code'], $status, $result['replacements'])`
       (401 para `invalid_token`/`token_expired`, 422 para `field_too_short`/`field_too_long`).
    5. Si éxito: `flood_clear_event('myapi_reset_ip', $ip)` →
       `myapi_respond([], 200, 'password_reset_success')`.

12. **`resources/auth.resource.inc` — `myapi_auth_password_reset_page()`
    (HTML, fuera del envelope JSON):**
    - `GET`: si `$_GET['token']` falta, imprime HTML mínimo de "link inválido";
      si viene, imprime HTML con
      `<meta http-equiv="refresh" content="0;url=<?php echo $deep_link ?>">`
      + formulario (`new_password`, `token` oculto) que hace `POST` a la misma
      URL. `Content-Type: text/html; charset=utf-8`, `print`, `drupal_exit()`
      — nunca usa `myapi_respond()`/`myapi_error()`.
    - `POST`:
      1. `myapi_flood_is_allowed('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_limit', 'myapi_flood_reset_ip_window')`
         (mismo evento que el JSON) → si es `FALSE`, imprime HTML con
         `myapi_t('too_many_attempts')`.
      2. Valida presencia básica de `token`/`new_password` en `$_POST`
         (isset/trim); si falta, re-imprime el formulario con
         `myapi_t('invalid_field', ['@field' => 'new_password'])`.
      3. `$result = myapi_auth_password_reset_execute($_POST['token'], $_POST['new_password'])`
         — misma función que usa el endpoint JSON.
      4. Si falla: `myapi_flood_register('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_window')`
         → re-imprime el formulario con `myapi_t($result['error_code'], $result['replacements'])`.
      5. Si éxito: `flood_clear_event('myapi_reset_ip', $ip)` → imprime HTML
         con `myapi_t('password_reset_page_success')`.

13. **`docs/auth.md` — documentar los endpoints.** Añadir las secciones
    `POST /api/v1/auth/password/forgot` y `POST /api/v1/auth/password/reset`
    siguiendo la plantilla estándar. Añadir una nota breve para
    `GET/POST password/reset`: es una página HTML de apoyo (no JSON, no vive
    bajo `api/v1`), única excepción documentada al envelope de respuestas del
    proyecto.

14. **Aplicar y verificar.** `drush cc all` y probar:
    - `/forgot` con username existente, con email existente, con identifier
      inexistente → los tres devuelven `200` genérico; solo el primero y
      segundo disparan el correo.
    - Flood de `/forgot` por IP (10) y por identifier (3).
    - `/reset` (JSON): token válido, expirado, ya usado, reutilizado tras un
      segundo `/forgot` (invalidado), `new_password` de 7 y 8 caracteres,
      usuario bloqueado.
    - Tras un reset exitoso: las sesiones previas del usuario en
      `my_api_tokens` quedan `revoked = 1`.
    - Página `password/reset`: `GET` sin token, `GET` con token válido
      (verificar el meta-refresh), `POST` exitoso, `POST` con token inválido,
      flood compartido entre el JSON y la página (agotar el contador desde
      uno y verificar que el otro también bloquea).
    - `Accept-Language: en` en el correo y en ambos endpoints JSON.

---

## Criterios de aceptación

- [x] `POST /api/v1/auth/password/forgot` con un `username` existente y activo
      → **HTTP 200** con `{"success":true,"data":{},"message":"..."}` y se
      envía un correo real (verificable en el log de mail o en un servidor
      SMTP de pruebas) con asunto y cuerpo traducidos según `Accept-Language`.
- [x] `POST /api/v1/auth/password/forgot` con un `email` existente y activo
      (sin `username`) → mismo comportamiento que arriba, resuelto por
      `dr_users.mail`.
- [x] `POST /api/v1/auth/password/forgot` con un `identifier` inexistente, o
      que corresponde a un usuario bloqueado (`status = 0`) → **HTTP 200** con
      el mismo body genérico, **sin** enviar correo.
- [x] `POST /api/v1/auth/password/forgot` sin `username` ni `email` en el body
      → **HTTP 422** con `error_code: "missing_field"`, sin tocar la BD ni el
      flood.
- [x] Si el body trae `username` y `email` a la vez, y solo `username` tiene
      match → se usa ese; si solo `email` tiene match, también se resuelve
      correctamente (prioridad `username` primero).
- [x] Pedir `/forgot` dos veces seguidas para el mismo usuario invalida
      (`used = 1`) el token generado por la primera petición; solo el token de
      la segunda petición es válido en `/reset`.
- [x] 10 peticiones a `/forgot` desde la misma IP (identifiers distintos) → la
      11.ª devuelve **HTTP 429** con `error_code: "too_many_attempts"`.
- [x] 3 peticiones a `/forgot` con el mismo `identifier` (IPs distintas) → la
      4.ª devuelve **HTTP 429**.
- [x] El contador de flood de `/forgot` avanza en cada petición válida, exista
      o no la cuenta (verificable llamando 3 veces con un identifier
      inexistente y viendo que la 4.ª ya da 429).
- [x] `POST /api/v1/auth/password/reset` con un token válido y `new_password`
      de 8+ caracteres → **HTTP 200** con
      `message: "Contraseña actualizada correctamente."`; el usuario puede
      loguearse con la nueva password (`POST /api/v1/auth/login`).
- [x] Tras un reset exitoso, todas las filas de `my_api_tokens` del usuario
      que estaban `revoked = 0` pasan a `revoked = 1` (sus sesiones previas
      quedan invalidadas).
- [x] Reutilizar el mismo token de reset una segunda vez → **HTTP 401** con
      `error_code: "invalid_token"`.
- [x] Un token de reset con `expires_at < now` → **HTTP 401** con
      `error_code: "token_expired"`.
- [x] Un token inexistente → **HTTP 401** con `error_code: "invalid_token"`.
- [x] `new_password` de menos de 8 caracteres → **HTTP 422** con
      `error_code: "field_too_short"`, sin tocar el token (sigue válido para
      un intento posterior).
- [x] Falta `token` o `new_password` en el body de `/reset` → **HTTP 422** con
      `error_code: "missing_field"`.
- [x] 10 intentos fallidos a `/reset` (JSON) desde la misma IP → el 11.º
      devuelve **HTTP 429**.
- [x] El contador de flood de `myapi_reset_ip` es compartido entre el endpoint
      JSON y la página web: agotarlo desde uno bloquea también al otro.
- [x] `GET password/reset?token=<válido>` devuelve HTML (no JSON) con un
      `<meta http-equiv="refresh">` apuntando a
      `myapp://reset-password?token=<mismo token>` y un formulario visible
      como fallback.
- [x] `GET password/reset` sin `token` en el query string → HTML con mensaje
      de link inválido, sin exponer detalles internos.
- [x] `POST password/reset` (formulario web) con token válido y password de
      8+ caracteres → HTML con el mensaje de éxito traducido; el resultado es
      idéntico en efecto al del endpoint JSON (usuario puede loguearse con la
      nueva password, sesiones previas revocadas).
- [x] `POST password/reset` con token inválido/expirado o password corta →
      HTML con el mensaje de error traducido correspondiente, sin exponer
      JSON crudo ni trazas de PHP.
- [x] `Accept-Language: en` en `/forgot`, `/reset` (JSON), el correo, y la
      página web devuelve todos los textos en inglés; sin header o `es` los
      devuelve en español.
- [x] `drush cc all` no produce errores tras los cambios (nuevas rutas, nueva
      tabla, nuevo archivo `.inc`).

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Almacenamiento del token de reset | Tabla nueva `myapi_password_reset_tokens` | Reutilizar `my_api_tokens` con columna `type` | Ciclo de vida distinto (un solo uso, TTL corto, no es sesión); mantiene `my_api_tokens` enfocada en sesiones. |
| Campos de identificación en `/forgot` | `username` y `email` como campos separados opcionales | Un solo campo `identifier` genérico | Consistente con que `/login` usa `username` como nombre de campo explícito; el cliente Flutter ya sabe cuál de los dos tiene disponible. |
| Prioridad si vienen ambos | `username` primero, si no hay match se prueba `email` | `email` primero / error si vienen ambos | Mismo criterio que login (autenticación es por username). |
| Respuesta cuando la cuenta no existe/está bloqueada | `HTTP 200` genérico idéntico al caso de éxito | Error específico `user_not_found` | Evita revelar existencia de cuentas, mismo principio que `invalid_credentials` en login. |
| Tokens de reset previos sin usar | Se invalidan (`used = 1`) al pedir un nuevo `/forgot` | Permitir varios tokens activos simultáneos | Un solo token válido a la vez reduce superficie de ataque. |
| TTL del token de reset | 1 hora (`myapi_password_reset_ttl`, default 3600) | 30 min / 24 h | Balance entre dar tiempo al usuario y no dejar el token vivo demasiado tiempo; configurable sin despliegue. |
| Flood protection en este spec | Incluida (IP + identifier en `/forgot`, IP en `/reset`) | Diferir a un spec futuro | Los helpers de flood ya existen (spec 06); dejar un endpoint de autenticación sin protección sería inconsistente. |
| Identificador de flood en `/forgot` | Doble contador: IP + identifier recibido | Solo IP | Evita email-bombing dirigido a una cuenta específica desde múltiples IPs. |
| Momento de registro de flood en `/forgot` | Se registra en **toda** petición válida, exista o no la cuenta | Solo cuando la cuenta existe | El contador de flood no debe ser un canal lateral que revele si una cuenta existe; además limita el spam de correos hacia cuentas reales. |
| Identificador de flood en `/reset` (JSON + página web) | Solo IP, contador compartido entre ambas superficies | IP + token, o contadores separados por superficie | El token es de un solo uso (no tiene sentido floodear por su valor); compartir el contador evita que un atacante evada el límite alternando entre el endpoint JSON y la página web. |
| Revocación de sesiones tras reset exitoso | Se revocan todas las filas activas de `my_api_tokens` del usuario | Dejar sesiones activas | Si la cuenta fue comprometida, el cambio de password debe cerrar también la sesión del atacante. |
| Validación de `new_password` | Mínimo 8, máximo 255 caracteres, sin reglas de complejidad | Sin mínimo (igual que login) / mínimo 8 + complejidad | Mejora mínima sobre el login actual sin ser invasivo; es la primera regla de longitud mínima del proyecto. |
| Confirmación de password en `/reset` | Un solo campo `new_password` | `new_password` + `password_confirmation` | La confirmación visual se maneja en la UI de la app, no en la API; mismo patrón que login. |
| Contenido del correo | Un único link web (`https://.../password/reset?token=xxx`) | Token en texto plano / deep link directo / ambos | Mejor UX de correo (un solo link); la página web maneja el intento de apertura de la app. |
| Fallback cuando el deep link no abre la app | Página HTML propia en este módulo, reutilizando el mismo token y la misma lógica núcleo (`myapi_auth_password_reset_execute()`) | Mecanismo nativo de Drupal core (`user/password`) | El core de Drupal usa su propio sistema de hash de one-time-login, totalmente independiente; mezclarlo hubiera creado dos sistemas de reset paralelos y redundantes. |
| Implementación de la página web | Page callback simple, sin Form API, sin theming | `hook_form()` + Drupal Form API | Consistente con que el resto del módulo no usa el theme layer ni Form API de Drupal; evita introducir un patrón nuevo para un caso aislado. |
| Ruta de la página web | `password/reset`, fuera de `api/v1` | `api/v1/auth/password/reset-page` | No es un endpoint JSON; separarla de `api/v1` deja claro que es la única excepción al envelope de respuestas. |
| Idioma del correo | `Accept-Language` del request `/forgot` (mismo mecanismo que `myapi_get_lang()`) | Idioma guardado en el perfil Drupal del usuario | Consistente con el resto de la API; no depende de configuración de idioma en el account. |
| Ubicación de `hook_mail()` | Implementación delgada en `myapi.module`, delega el armado del mensaje a `includes/myapi.mail.inc` | Toda la lógica de formateo dentro de `hook_mail()` | Requisito de Drupal (debe vivir en el `.module`), pero se mantiene mínima, igual que `hook_menu()`; consistente con la regla "sin lógica de negocio en `myapi.module`". |
| Deep link scheme | `myapp://reset-password?token=xxx`, configurable vía `myapi_password_reset_deep_link_base` | Scheme fijo hardcodeado | Placeholder ajustable sin tocar código cuando se defina el scheme final de la app Flutter. |
| Refactor de `includes/myapi.flood.inc` | Extraer `myapi_flood_is_allowed()` (solo boolean, sin output) de `myapi_flood_check()` | Duplicar la llamada a `flood_is_allowed()` en la página web | `myapi_flood_check()` está acoplada a `myapi_error()` (JSON); la página HTML necesita el mismo chequeo sin ese acoplamiento. El refactor no cambia el comportamiento de las 5 llamadas JSON existentes. |
| Lógica núcleo de `/reset` | Función pura `myapi_auth_password_reset_execute()` compartida entre el endpoint JSON y la página web | Duplicar la validación de token/password en cada entry point | Única fuente de verdad para las reglas de negocio; cada caller solo decide cómo presentar el resultado (JSON vs HTML). |

---

## Riesgos identificados

- **Canal lateral por tiempo de respuesta.** Aunque `/forgot` siempre devuelve
  `200` genérico, el camino "cuenta existe" hace más trabajo (persistir token,
  `drupal_mail()`) que el camino "cuenta no existe", lo que en teoría permite
  distinguir ambos casos por tiempo de respuesta. *Mitigación:* fuera de
  alcance de este spec; si se vuelve relevante, se puede igualar el trabajo
  con una operación dummy en el camino negativo.

- **Fallos silenciosos de entrega de correo.** Si `drupal_mail()` devuelve
  `FALSE` (mail server mal configurado, error de transporte), la respuesta
  HTTP sigue siendo `200` genérico por diseño (para no revelar existencia de
  cuentas), así que el cliente nunca se entera de que el correo no llegó.
  *Mitigación:* aceptado como trade-off consciente; queda fuera de alcance
  registrar/alertar sobre fallos de envío.

- **Deep link no configurado en la app Flutter.** Si el equipo Flutter no
  registra `myapp://` como App Link/Universal Link, el
  `<meta http-equiv="refresh">` de la página web simplemente no abrirá nada y
  el usuario se queda en el formulario web sin explicación. *Mitigación:*
  fuera de alcance de este módulo (vive en el proyecto Flutter); el fallback
  web sigue funcionando de todas formas.

- **XSS reflejado en la página HTML.** El token y los mensajes de error se
  imprimen en el HTML del formulario; si no se pasan por `check_plain()` (o
  equivalente) antes de imprimirse, un token manipulado en la URL podría
  inyectar HTML/JS. *Mitigación:* obligatorio sanear con `check_plain()` todo
  valor reflejado al implementar este paso; se deja como nota de
  implementación, no de diseño.

- **Sin token CSRF en el formulario web.** La página no usa Form API, así que
  no hay `form_token` de Drupal. *Mitigación:* el propio token de reset
  (secreto, de un solo uso, corta vida) cumple el rol de credencial
  anti-CSRF — un atacante que ya conoce un token de reset válido de la víctima
  no necesita CSRF para explotarlo, y sin conocerlo no puede forjar la
  petición.

- **Ventana de concurrencia en `/reset`.** Dos peticiones simultáneas con el
  mismo token (una vía JSON, otra vía la página web, dado que comparten la
  misma tabla) podrían superar la validación antes de que la primera marque
  `used = 1`. *Mitigación:* mismo argumento que en `04-refresh-token`: riesgo
  mínimo en PHP de proceso único con MySQL; no se añade locking explícito en
  este spec.

- **TTL vs latencia de entrega de correo.** Si el proveedor de correo tarda en
  entregar el mensaje más que el TTL (1 h por defecto), el usuario recibe un
  link ya expirado. *Mitigación:* `myapi_password_reset_ttl` es ajustable vía
  `variable_set()` sin despliegue.

- **Crecimiento de `myapi_password_reset_tokens`.** Cada `/forgot` inserta una
  fila nueva sin eliminar las invalidadas. *Mitigación pendiente:* misma tarea
  de purga vía `hook_cron()` mencionada para `my_api_tokens`; fuera de alcance
  aquí.
