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
| Code | When |
|------|------|
| 422  | `username` or `password` missing, not a string, empty, or longer than 255 chars. The database is not touched. |
| 401  | Invalid credentials: wrong password, nonexistent user, or blocked user (`status = 0`). The same `Invalid credentials` body is returned in all three cases so account existence is never revealed. |
| 405  | Any HTTP method other than POST. |

Error envelope:
```json
{ "success": false, "error": "Invalid credentials" }
```

**Security notes**
- **HTTPS required in production.** Opaque tokens travel in the response body;
  over plain HTTP they could be intercepted.
- **No brute-force protection (known risk).** This endpoint accepts unlimited
  login attempts. Rate limiting / flood control (Drupal Flood API, per IP and
  per user) is out of scope for this endpoint and tracked for a future spec.
