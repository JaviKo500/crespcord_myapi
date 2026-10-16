# Back-office manual access (SPEC 133)

The back-office user manual (`manual_backoffice/`) is static HTML, but it is
**not** public. It lives outside the document root and Drupal serves it only to
back-office accounts. The app and WhatsApp bot manuals under `/manual/` stay
public and are still served by Apache straight from disk (see
[user-manual.md](user-manual.md)).

This is an HTML route for a browser, not an `api/v1` endpoint: no JSON, no
response envelope, no Bearer token. It uses the Drupal session cookie.

---

## GET /manual/backoffice/...

Serves one file of the built manual.

**Authentication:** Drupal session with the role `administrator`, `backend` or
`administrador edificio` (or uid 1).

| Request | File served (inside `myapi_backoffice_manual_dir`) |
|---------|------|
| `/manual/backoffice/` | `index.html` |
| `/manual/backoffice/<slug>/` | `<slug>/index.html` (`<slug>` = `[a-z0-9]+(-[a-z0-9]+)*`, not `img`) |
| `/manual/backoffice/img/<name>.png` | `img/<name>.png` (`<name>` = `[a-z0-9][a-z0-9_-]*`) |

Any other shape is a 404. The query string (`?v=<hash>` on screenshots) is
ignored.

**Success response (200)**

The file itself, with:

| Header | `.html` | `.png` |
|--------|---------|--------|
| Content-Type | `text/html; charset=utf-8` | `image/png` |
| Cache-Control | `private, no-cache` | `private, max-age=31536000, immutable` |
| X-Content-Type-Options | `nosniff` | `nosniff` |
| X-Robots-Tag | `noindex, nofollow` | `noindex, nofollow` |

**Other responses**

| Code | When |
|------|------|
| 302 | Anonymous visitor: redirected to the login form (`/?q=user/login&destination=manual/backoffice/...`). After logging in, the browser returns to the page it asked for. |
| 301 | A page asked for anywhere but its canonical path: without the trailing slash (`/manual/backoffice/pagos`), or through `?q=` (`/?q=manual/backoffice/pagos/`, which is where Drupal sends the browser after the login form on this site). Redirected to `/manual/backoffice/pagos/`. The manual's links are relative and only work from there. |
| 403 | Logged in without a back-office role (e.g. a resident). Drupal's "Acceso denegado" page. |
| 404 | A path that matches none of the shapes above, or a file that does not exist in the directory (including a symlink that resolves outside it). |

---

## Configuration

| Variable | Default | Meaning |
|----------|---------|---------|
| `myapi_backoffice_manual_dir` | `/var/www/manual-backoffice` | Directory with the built manual. Must be outside the document root. |

For local development, point it at the build output in `settings.php`:

```php
$conf['myapi_backoffice_manual_dir'] = '/path/to/myapi/build/manual-backoffice';
```

## Deployment

1. Deploy the module (`scripts/deploy.sh`). It runs `drush cc all`, which
   rebuilds the router and registers `manual/backoffice`.
2. `./scripts/deploy-manual.sh`. It publishes `build/manual/` to
   `/var/www/html/manual/` and `build/manual-backoffice/` to
   `/var/www/manual-backoffice/`, both owned by `root` and read-only (644/755).
   It fails if `/var/www/html/manual/backoffice/` still exists afterwards.

If the manual is deployed before the module, `/manual/backoffice/` answers 404
until the module arrives: it fails closed.

## Checks after deploying

```bash
# Anonymous: redirect to login, never the page.
curl -sI https://crespcord.lamotora.com/manual/backoffice/pagos/ | head -3

# Path traversal and other extensions: 404 (or the login redirect while anonymous).
curl -sI "https://crespcord.lamotora.com/manual/backoffice/img/..%2F..%2Fsettings.php" | head -1

# The public manuals are still public.
curl -sI https://crespcord.lamotora.com/manual/ | head -1
```

Then open `/manual/backoffice/` in a browser logged in as a `backend` user
(page and screenshots load), and as a resident (Acceso denegado).

## Files

| File | Role |
|------|------|
| `includes/myapi.backoffice_manual.inc` | Page callback, access decision, path validation, headers. |
| `myapi.module` | The `manual/backoffice` route (routing only). |
| `tests/unit/BackofficeManualTest.php` | Path shapes (rejected first), roles, trailing slash, headers, symlinks. |
| `scripts/build-manual.sh` | Builds `build/manual-backoffice/`, outside the public tree. |
| `scripts/deploy-manual.sh` | Publishes it to `/var/www/manual-backoffice/`. |
