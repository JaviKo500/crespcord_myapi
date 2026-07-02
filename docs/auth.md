## POST /api/v1/auth/login

Authenticates a user by `username` + `password` against `dr_users`. On success
it issues an opaque access token (default 30 min) and an opaque refresh token
(default 30 days), persists their SHA-256 hashes in `my_api_tokens`, and returns
both tokens together with the basic user data.

**Authentication:** public

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
```json
{ "username": "javier", "password": "1234" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| username | string | yes | Non-empty, max 255 chars. Login is by username only (not email). |
| password | string | yes | Non-empty, max 255 chars. |

**Success response (200)**
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

Notes:
- `access_token` is 64 hex chars; `refresh_token` is 128 hex chars. Only their
  SHA-256 hashes are stored; the plaintext tokens are never persisted.
- `expires_in` reflects the **current** value of the `myapi_token_access_ttl`
  Drupal variable (default `1800`), configurable with
  `drush vset myapi_token_access_ttl <seconds>` — no code change or reinstall.
- `picture` is always `null` in this version (fid → URL resolution is out of
  scope).
- Each `roles` entry is `{ name, uid }` where `name` is `dr_role.name` and
  `uid` is the **role id** (`dr_role.rid`), not the user id. The `authenticated
  user` role is included.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 422  | `missing_field` / `invalid_field` / `field_too_long` | `username` or `password` missing, not a string, empty, or longer than 255 chars. The database is not touched. |
| 401  | `invalid_credentials` | Invalid credentials: wrong password, nonexistent user, or blocked user (`status = 0`). The same `invalid_credentials` body is returned in all three cases so account existence is never revealed. |
| 429  | `too_many_attempts` | Flood limit reached: 5 failed attempts for the same `username` (window: 1 h) or 20 failed attempts from the same IP (window: 1 h). Thresholds are configurable via `myapi_flood_login_user_limit` / `myapi_flood_login_ip_limit` (and their `_window` variants). |
| 405  | `method_not_allowed` | Any HTTP method other than POST. |

Error envelope:
```json
{
  "success": false,
  "error_code": "invalid_credentials",
  "error": "Usuario o contraseña incorrectos."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Security notes**
- **HTTPS required in production.** Opaque tokens travel in the response body;
  over plain HTTP they could be intercepted.
- **Brute-force protection** is active via Drupal Flood API. The IP counter
  allows 20 failed attempts (1 h window); the per-username counter allows 5
  (1 h window). A successful login clears both counters.
- IP thresholds are generous to accommodate NAT environments; they can be raised
  via `variable_set()` without code changes.

---

## POST /api/v1/auth/refresh

Validates an opaque refresh token, revokes it, issues a new access + refresh
token pair, and returns the new tokens together with the basic user data.
Each successful refresh rotates the refresh token — the old one is immediately
invalidated so it cannot be reused.

**Authentication:** public (the refresh token itself is the credential)

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
```json
{ "refresh_token": "<128 chars hex>" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| refresh_token | string | yes | 128 hex chars issued by `POST /api/v1/auth/login` or a previous refresh. |

**Success response (200)**
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

Notes:
- The `refresh_token` returned is always different from the one sent (token
  rotation). The old token is marked `revoked = 1` in `my_api_tokens`.
- `expires_in` is the TTL of the **new access token** in seconds (same variable
  as login: `myapi_token_access_ttl`, default `1800`).
- `picture` is always `null` in this version.
- Each `roles` entry is `{ name, uid }` where `uid` is the role id (`rid`).

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 422  | `missing_field` | `refresh_token` is absent from the request body. |
| 401  | `invalid_token` | Token not found in the database, already revoked, or the associated user does not exist or is blocked (`status = 0`). |
| 401  | `token_expired` | Token exists and is not revoked but its `refresh_expires_at` is in the past. |
| 429  | `too_many_attempts` | Flood limit reached: 10 failed attempts from the same IP (window: 15 min). Threshold configurable via `myapi_flood_refresh_ip_limit` / `myapi_flood_refresh_ip_window`. |
| 405  | `method_not_allowed` | Any HTTP method other than POST. |

Error envelope:
```json
{
  "success": false,
  "error_code": "invalid_token",
  "error": "Token inválido."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Security notes**
- **Token rotation on every refresh.** The old refresh token is revoked
  immediately. Reusing a revoked token returns `invalid_token` 401.
- The same `invalid_token` error is returned whether the token does not exist
  or belongs to a blocked user — the response never reveals internal state.
- A successful refresh clears the IP flood counter so a legitimate user is not
  blocked after a transient error.

---

## POST /api/v1/auth/logout

Revokes the current session. Both the access token (via `Authorization` header)
and the refresh token (via request body) must belong to the same row in
`my_api_tokens` — this prevents a valid token from one device revoking a
different device's session.

**Authentication:** required (Bearer access token)

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |
| Authorization | Bearer `<access_token>` |

**Request body**
```json
{ "refresh_token": "<128 chars hex>" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| refresh_token | string | yes | The refresh token issued alongside the access token being used. |

**Success response (200)**
```json
{
  "success": true,
  "data": {},
  "message": "Sesión cerrada correctamente."
}
```

After a successful logout the corresponding row in `my_api_tokens` has
`revoked = 1`. Any further attempt to use either token returns `invalid_token`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401  | `missing_authorization` | `Authorization` header is absent or does not match the `Bearer <token>` pattern. |
| 401  | `invalid_token` | Access token not found in the database, already revoked, expired, associated user does not exist or is blocked, or the refresh token does not belong to the same session. |
| 422  | `missing_field` | `refresh_token` is absent from the request body. The database is not modified. |
| 429  | `too_many_attempts` | Flood limit reached: 20 failed attempts from the same IP (window: 15 min). Threshold configurable via `myapi_flood_logout_ip_limit` / `myapi_flood_logout_ip_window`. |
| 405  | `method_not_allowed` | Any HTTP method other than POST. |

Error envelope:
```json
{
  "success": false,
  "error_code": "missing_authorization",
  "error": "No se proporcionó token de acceso."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Security notes**
- The same `invalid_token` error is returned for an expired access token, an
  unknown token, a revoked token, a blocked user, and a refresh/access token
  mismatch — the response never reveals which condition triggered it.
- **If the access token is already expired**, the client must call
  `POST /api/v1/auth/refresh` first to obtain a new pair, then logout. This is
  intentional: logout requires a valid authenticated caller.
- A successful logout clears the IP flood counter so a legitimate user is not
  blocked after a transient error.

---

## POST /api/v1/auth/password/forgot

Requests a password reset. Always responds with a generic `200`, whether or
not the account exists, so the response never reveals account existence. If a
matching, active account is found, issues a single-use reset token (1 h TTL by
default) and emails a link to it.

**Authentication:** public

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
```json
{ "username": "javier" }
```
or
```json
{ "email": "correo@correo.com" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| username | string | one of `username`/`email` required | Tried first if both are present. |
| email | string | one of `username`/`email` required | Used if `username` is absent, or was present but did not match any account. |

**Success response (200)**
```json
{
  "success": true,
  "data": {},
  "message": "Si la cuenta existe, se envió un correo con instrucciones."
}
```

Notes:
- The reset link points to `password/reset?token=<token>` (the HTML fallback
  page below), and is sent via `drupal_mail()` with subject/body translated
  according to the `Accept-Language` header of this request.
- Requesting a new reset invalidates (`used = 1`) any previously unused token
  for the same user — only the most recently requested token is valid.
- If `drupal_mail()` fails to deliver (misconfigured mail transport), the
  response is still the generic `200` above by design; delivery failures are
  not surfaced to the client.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 422  | `missing_field` | Neither `username` nor `email` is present in the request body. The database is not touched. |
| 429  | `too_many_attempts` | Flood limit reached: 10 attempts from the same IP (window: 1 h) or 3 attempts for the same `username`/`email` (window: 1 h). Thresholds configurable via `myapi_flood_forgot_ip_limit` / `myapi_flood_forgot_identifier_limit` (and their `_window` variants). |
| 405  | `method_not_allowed` | Any HTTP method other than POST. |

Error envelope:
```json
{
  "success": false,
  "error_code": "missing_field",
  "error": "Falta el campo requerido: username_or_email"
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Security notes**
- Both flood counters (IP and identifier) are registered on **every** valid
  request, whether or not the account exists — the counter is never a side
  channel that reveals account existence, and it also limits mail spam toward
  real accounts.
- The identifier flood counter (per `username`/`email`) prevents email-bombing
  a specific account from multiple IPs.

---

## POST /api/v1/auth/password/reset

Completes a password reset using a single-use token. On success, the new
password is set and all active sessions in `my_api_tokens` for that user are
revoked.

**Authentication:** public (the reset token itself is the credential)

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
```json
{ "token": "<64 chars hex>", "new_password": "12345678" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| token | string | yes | 64 hex chars, from the `/forgot` email link. |
| new_password | string | yes | 8–255 chars, no complexity rules. |

**Success response (200)**
```json
{
  "success": true,
  "data": {},
  "message": "Contraseña actualizada correctamente."
}
```

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 422  | `missing_field` | `token` or `new_password` is absent from the request body. |
| 422  | `field_too_short` | `new_password` is shorter than 8 chars. The token remains valid for a subsequent attempt. |
| 422  | `field_too_long` | `new_password` is longer than 255 chars. |
| 401  | `invalid_token` | Token not found, already used, or the associated user does not exist or is blocked (`status = 0`). |
| 401  | `token_expired` | Token exists and is unused but its `expires_at` is in the past. |
| 429  | `too_many_attempts` | Flood limit reached: 10 failed attempts from the same IP (window: 15 min). This counter is shared with `GET/POST password/reset` below. Threshold configurable via `myapi_flood_reset_ip_limit` / `myapi_flood_reset_ip_window`. |
| 405  | `method_not_allowed` | Any HTTP method other than POST. |

Error envelope:
```json
{
  "success": false,
  "error_code": "invalid_token",
  "error": "Token inválido."
}
```

`error_code` is a stable, language-independent key; `error` is translated
according to the `Accept-Language` header (`es`/`en`, default `es`). See
[i18n.md](i18n.md).

**Security notes**
- Tokens are single-use: a successful reset marks the row `used = 1`, so
  replaying the same token returns `invalid_token`.
- A successful reset revokes every active row in `my_api_tokens` for the user,
  closing out any session an attacker may have had if the account was
  compromised.
- No `password_confirmation` field: confirmation is handled by the client UI,
  same pattern as login.

---

## GET/POST password/reset

**This is the only endpoint in the API that does not follow the JSON response
envelope.** It is an HTML page served to the browser, meant as a fallback when
the deep link in the password reset email (`myapp://reset-password?token=...`)
does not open the app — for example, when the OS has no app registered for the
custom scheme yet. It lives at `password/reset`, outside `api/v1`, precisely to
signal that it is not a JSON API endpoint.

**Authentication:** public (the reset token itself is the credential)

- **`GET password/reset?token=<token>`** — prints minimal, unstyled HTML with
  `<meta http-equiv="refresh" content="0;url=myapp://reset-password?token=<token>">`
  (attempting to hand off to the app) plus a form (`new_password` field, hidden
  `token` field) as fallback, submitting via `POST` to the same URL. Without a
  `token` query parameter, prints a generic "invalid link" message instead.
- **`POST password/reset`** — validates and executes the same reset logic as
  `POST /api/v1/auth/password/reset` (`myapi_auth_password_reset_execute()`).
  On success, prints a translated success message. On error (invalid/expired
  token, password too short, flood limit reached), re-prints the form with the
  translated error message.

Notes:
- The `myapi_reset_ip` flood counter is **shared** between this page and
  `POST /api/v1/auth/password/reset`: exhausting the limit from one blocks the
  other too.
- All reflected values (the token, error messages) are sanitized with
  `check_plain()` before being printed, to prevent reflected XSS via a
  manipulated `token` query parameter.
- The deep link base (`myapp://reset-password`) is configurable via the
  `myapi_password_reset_deep_link_base` Drupal variable.
- No CSRF token is used (this page does not use Drupal's Form API): the reset
  token itself — secret, single-use, short-lived — serves as the anti-CSRF
  credential.
