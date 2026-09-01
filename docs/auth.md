## POST /api/v1/auth/login

Authenticates a user by **username or email address** + `password` against
`dr_users`. On success it issues an opaque access token (default 30 min) and an
opaque refresh token (default 30 days), persists their SHA-256 hashes in
`my_api_tokens`, and returns both tokens together with the basic user data.

**Authentication:** public

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
```json
{ "username": "javier", "password": "1234" }
```

Or, with the same field, an email address:
```json
{ "username": "javier@lamotora.com", "password": "1234" }
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| username | string | yes | Non-empty, max 255 chars. **Username or email address** — the field carries either form, so a client that already sends `username` needs no change. See the resolution notes below. |
| password | string | yes | Non-empty, max 255 chars. |

**How the identifier is resolved**
- The **username is tried first** (`users.name`); the email column
  (`users.mail`) is only queried when the name matched nothing **and** the
  value contains an `@`. So an account whose *username* happens to be an email
  string keeps logging in with it, even if that same string is another
  account's address.
- Both lookups are case-insensitive and the value is trimmed, so
  `Javier`, `javier` and ` JAVIER ` all reach the same account. (The
  insensitivity comes from the database collation, not from the module.)
- There is no separate `email` field on this endpoint. `POST
  /api/v1/auth/password/forgot` is the one that takes `username` *or* `email`
  as two distinct keys.

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
      "first_name": "Javier",
      "last_name": "Contreras",
      "dni": "12345678",
      "phone": "04121234567",
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
- `first_name`, `last_name`, `dni` and `phone` come from the user's profile
  fields (`field_nombre`, `field_apellidos`, `field_cedula`, `field_telefono`
  respectively). Any of them is `null` when the field has no value set.
- `picture` is always `null` in this version (fid → URL resolution is out of
  scope).
- Each `roles` entry is `{ name, uid }` where `name` is `dr_role.name` and
  `uid` is the **role id** (`dr_role.rid`), not the user id. The `authenticated
  user` role is included.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 422  | `missing_field` / `invalid_field` / `field_too_long` | `username` or `password` missing, not a string, empty, or longer than 255 chars. The database is not touched. |
| 401  | `invalid_credentials` | Invalid credentials: wrong password, nonexistent user or email, or blocked user (`status = 0`). The same `invalid_credentials` body is returned in every case so account existence is never revealed — by username or by address. |
| 429  | `too_many_attempts` | Flood limit reached: 5 failed attempts for the same account (window: 1 h) or 20 failed attempts from the same IP (window: 1 h). Thresholds are configurable via `myapi_flood_login_user_limit` / `myapi_flood_login_ip_limit` (and their `_window` variants). |
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
  allows 20 failed attempts (1 h window); the per-account counter allows 5
  (1 h window). A successful login clears both counters.
- **The five attempts are per account, not per identifier.** A failed login
  sent with an email address is charged to the address *and* to the username
  behind it, so switching between the two forms does not buy a second
  allowance. The counter subject is trimmed and lowercased, so capitalisation
  does not either.
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
      "first_name": "Javier",
      "last_name": "Contreras",
      "dni": "12345678",
      "phone": "04121234567",
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
- `first_name`, `last_name`, `dni` and `phone` come from the user's profile
  fields; see the notes under `POST /api/v1/auth/login`. Any of them is `null`
  when the field has no value set.
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
- The reset link points to `password/reset?token=<token>&lang=<es|en>` (the
  HTML fallback page below), and is sent via `drupal_mail()` with
  subject/body translated according to the `Accept-Language` header of this
  request. The `lang` on the link is that same resolved language, so the web
  page renders in it regardless of the browser's own `Accept-Language` — see
  the notes under `GET/POST password/reset` below.
- The email is **HTML**, not plain text: CrespCord branding (brown/sand
  palette matching the `password/reset` web page), a header showing the
  CrespCord logo image (`myapi_mail_logo_url()`, SPEC 54 — an absolute URL
  built from `$base_url` + the module's own path, so the PNG shipped in
  `assets/crespcord-icon.png` needs no install step beyond a normal deploy), a
  greeting with the account's username, a button linking to the reset page,
  and a "Saludos, Grupo CrespCord" sign-off. All CSS is inline and the layout
  uses tables, for compatibility with email clients that strip `<style>`
  blocks or ignore CSS classes — see `myapi_mail_password_reset_html()` in
  `includes/myapi.mail.inc`.
- Drupal 7's default mail system converts HTML bodies to plain text before
  sending. To keep the markup, the `myapi_password_reset` mail key is mapped
  to a custom `MyapiHtmlMailSystem` class (`includes/myapi.mailsystem.inc`),
  registered via the `mail_system` variable in `myapi_install()` /
  `myapi_update_7003()`. Every other mail on the site (core, other modules)
  keeps the default plain-text behavior.
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

- **`GET password/reset?token=<token>&lang=<es|en>`** — prints the
  CrespCord-styled HTML page with
  `<meta http-equiv="refresh" content="0;url=myapp://reset-password?token=<token>">`
  (attempting to hand off to the app) plus a form (`new_password` field, hidden
  `token` field) as fallback, submitting via `POST` to the same URL (which
  carries `lang` forward in its query string). Without a `token` query
  parameter, prints a generic "invalid link" message instead.
- **`POST password/reset`** — validates and executes the same reset logic as
  `POST /api/v1/auth/password/reset` (`myapi_auth_password_reset_execute()`).
  On success, prints a translated success message. On error (invalid/expired
  token, password too short, flood limit reached), re-prints the form with the
  translated error message.

Design:
- The page follows the CrespCord brand (brown/sand palette, rounded card, inline
  logo SVG). All CSS/SVG/JS are inline in `resources/auth.resource.inc` — no
  external assets, no `drupal_add_css()`/`drupal_add_js()`, consistent with the
  page not using Drupal's theme layer.
- The form also includes a **confirm password** field and a **live password
  requirements checklist** (8+ chars, uppercase, number, symbol). These are
  client-side progressive enhancement only: the server still enforces just the
  8-255 character rule from `myapi_auth_password_reset_execute()`, and the form
  still submits `new_password` + `token` correctly with JavaScript disabled.
- There is no "back to login" link on this page — it is a standalone recovery
  flow, not a navigation entry point back into the app.

Notes:
- **Language matches the email, not the browser.** `myapi_get_lang()` (used
  everywhere in the API) checks a `lang` query parameter before falling back
  to `Accept-Language`. The `/forgot` endpoint stamps the reset link with
  `lang=<language resolved for that request>`, so opening the link always
  renders the page in the same language the email was sent in — regardless of
  the browser's own `Accept-Language` header. The form's `action` carries
  `lang` forward, so the re-rendered form (validation error) and the final
  success/error screen after `POST` stay in that same language too.
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

---

## A password change ends every session

`myapi_user_update()` (hook_user_update, in `myapi.module`) revokes **every**
live access and refresh token of an account whose password just changed —
wherever the change came from:

| Path | Covered |
|------|---------|
| `POST /api/v1/auth/password/reset` | Yes (and the endpoint also revokes explicitly — that is its documented contract, not something that may depend on a hook firing) |
| `/user/N/edit`, by the account holder | Yes |
| `/user/N/edit`, by an administrator on someone else's account | Yes |
| `drush upwd` | Yes |

Only the reset endpoint was covered before. A password changed from the web
left every token alive for up to 30 days — exactly backwards, since the reason
a person changes their password from the web is usually that they believe the
old one is compromised, and the reason an administrator changes someone else's
is usually that they know it is.

Two signals are read, because `user_save()` is called in two shapes:
`$edit['pass']` (the profile form, `drush upwd`, this module's own reset — the
key is unset when the field was left blank, so its presence really does mean
the password changed), and a difference between `$account->pass` and
`$account->original->pass` for callers that set the hash straight on the
object.

A profile saved for any other reason — email, phone, role, condominium
assignment — matches neither and revokes nothing. Blocking an account needs no
help from here: `myapi_auth_require_access_token()` re-reads `status` on every
request.

The revocation is written to `watchdog` at NOTICE level, because it is a logout
the person did not explicitly ask for: when a resident calls support saying the
app stopped working right after they changed their password, that line is the
answer.

---

## Token retention (cron purge)

Both token tables used to be append-only: a login writes a row, a refresh
writes another and revokes the first, a reset writes one and marks it used, and
nothing ever deleted. `myapi_cron()` (glue in `myapi.module`, logic in
`includes/myapi.token.inc`) now drains them on every cron run.

A row is deletable when it can no longer authenticate anything **and** the
grace period has passed since it stopped being a credential:

| Table | Deleted when |
|-------|--------------|
| `my_api_tokens` | `refresh_expires_at` is older than the grace period (the refresh expiry is the outer bound of the pair — the access token always dies first), **or** the row is `revoked = 1` and its `created` is older than the grace period |
| `myapi_password_reset_tokens` | `expires_at` is older than the grace period, **or** the row is `used = 1` and its `created` is older than the grace period |

The second condition of each pair is not redundant: a refresh rotation revokes
the previous row instantly, months before its `refresh_expires_at`, and an
active session rotates one every 30 minutes — waiting for the expiry would
leave the bulk of the table behind for a month.

**Grace period.** 7 days by default, so a dead credential still answers "when
did this device last log in, and from where" for a week — the question support
actually asks. Configurable:

```bash
drush vset myapi_token_purge_grace 259200   # 3 days
```

**Bounded per run.** Each table drains at most `MYAPI_TOKEN_PURGE_LIMIT` (5000)
rows per cron run, chosen by a `SELECT ... LIMIT` and deleted by primary key,
because `db_delete()` has no `->range()` in Drupal 7. The first run after
deployment meets every row ever written, and an unbounded `DELETE` inside cron
is how a cron run starts timing out. The module sets no interval of its own —
the purge runs once per pass of whatever cron the site has (`drush vget
cron_last` / `cron_safe_threshold`, or `admin/config/system/cron`) — so the
drain rate is that interval times this ceiling. With hourly cron, for example,
a large backlog clears on its own in a few days, with no manual step. In the
steady state the cap is never reached: one row per login plus one per refresh
rotation is a few thousand a day for a few hundred residents, which even daily
cron absorbs comfortably.

The purge is silent in the steady state — nothing to delete issues no `DELETE`
at all. Only an actual deletion is written to `watchdog`.

---

## Response headers

Every JSON response of this API — success and error alike, from every resource,
not just `auth` — is sent by `myapi_response_headers()` in
`includes/myapi.response.inc`:

| Header | Value |
|--------|-------|
| Content-Type | `application/json` |
| Cache-Control | `private, no-store, no-cache, must-revalidate` |
| Pragma | `no-cache` |
| Expires | `0` |
| X-Content-Type-Options | `nosniff` |
| Status | the endpoint's HTTP status code |

The cache headers are load-bearing. `myapi_respond()` / `myapi_error()` print
their body and end the request with `drupal_exit()`, which never reaches
Drupal's page delivery layer — the one that would otherwise have sent the
default `Cache-Control` of `drupal_page_header()`. Without them the responses
leave with no cache directive at all, and every one of them is personal data:
a CDN, a corporate proxy or a carrier proxy is then entitled to store a `200`
and hand it to the next caller of the same URL, which for this API means
handing one resident's receipts to another. Drupal's own page cache is not the
risk — the same `drupal_exit()` that skips the delivery layer also skips
`drupal_page_set_cache()` — every cache between the app and Drupal is.

`GET /api/v1/claims/%/files/%` and its siblings are the one exception to the
envelope and send their own headers (already `Cache-Control: private,
no-store`); they do not go through these helpers.
