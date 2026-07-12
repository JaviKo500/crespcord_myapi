# Tests

Three independent layers, all covering the 5 JSON endpoints of `/api/v1/auth`
documented in [`docs/auth.md`](../docs/auth.md). The HTML page
`GET/POST password/reset` is out of scope (see `specs/21-auth-testing.md`).

| Layer | Location | Framework | Runs against |
|---|---|---|---|
| Unit | `tests/unit/` | PHPUnit (standalone) | Nothing — pure functions only |
| Integration | `tests/integration/` | SimpleTest (`DrupalWebTestCase`) | SimpleTest's own sandbox install |
| E2E | `tests/e2e/` | Postman/Newman + Node | Production (`https://crespcord.lamotora.com`) |

---

## Unit tests

Covers only the functions that don't touch the database or Drupal APIs:
token generation/hashing (`includes/myapi.token.inc`), bearer header parsing
(`includes/myapi.auth.inc`), and the length-validation early-returns of
`myapi_auth_password_reset_execute()` (`resources/auth.resource.inc`).

**Prerequisites:** PHP 7.4, Composer.

**Run:**

```bash
scripts/run-unit-tests.sh    # or .ps1 on Windows
# equivalent to:
composer install --no-interaction && vendor/bin/phpunit
```

**Design constraint — `tests/unit/bootstrap.php`:** the production `.inc`
files are `require`d directly, outside Drupal, with no copies. The only
Drupal-level call any of them makes at file scope (not inside a function) is
`module_load_include()`, at the top of `resources/auth.resource.inc`.
`bootstrap.php` stubs it as a no-op so the `require` succeeds. **If a future
change adds another file-scope call to a Drupal function** in one of the
`.inc` files exercised here, this stub needs to grow to cover it too, or the
`require` will fatal.

---

## Integration tests

Covers all 5 endpoints end-to-end (success + every documented error) via real
HTTP requests against SimpleTest's own temporary Drupal install — never by
calling the resource's PHP functions in-process.

**Design constraint:** `myapi_respond()` / `myapi_error()` both end in
`drupal_exit()`, which would kill the test runner if called in-process. Every
test in `MyapiAuthTestCase.test` goes through `curlExec()` (raw JSON, not
`drupalPost()`, which targets Drupal's Form API) — the only way to exercise
this resource from outside.

The tests are packaged as a dev-only companion module, `myapi_test`
(`tests/integration/myapi_test.info` + `.module`), so a normal deploy via
`scripts/deploy.sh` never ships test code — it only uploads `myapi.info`,
`myapi.install`, `myapi.module`, `includes/` and `resources/`.

**Prerequisites:** SSH access to the server (`scripts/crespcord.pem`), `drush`
installed there.

**Run:**

```bash
scripts/run-integration-tests.sh    # or .ps1 on Windows
```

This uploads `tests/integration/` to `sites/all/modules/myapi_test`, runs
`drush en myapi_test -y && drush test-run MyapiAuthTestCase`, and disables the
module again at the end — **even if the test run itself fails** (a
trap/`finally` block guarantees the disable step runs). If that disable step
also fails (e.g. the SSH connection dropped), disable it by hand:

```bash
ssh -i scripts/crespcord.pem ubuntu@crespcord.lamotora.com \
  "cd /var/www/html && sudo -u www-data drush dis myapi_test -y"
```

---

## E2E tests

Covers the full `login → refresh → logout` flow, cheap non-destructive
negatives (`401`, `422`, `405`), a smoke check of `/forgot`, and — separately,
via a Node script — the full `/forgot → /reset` roundtrip against a real
mailbox over IMAP.

**Prerequisites:**
- Node.js 18+ (uses the global `fetch` API) and npm.
- A dedicated QA account on production and a mailbox with IMAP access,
  already provisioned (not created by this suite — see
  `specs/21-auth-testing.md`, "Decisiones tomadas y descartadas").

**Provisioning credentials** (never commit the real files — both are already
gitignored):

```bash
cp tests/e2e/auth.postman_environment.example.json tests/e2e/auth.postman_environment.json
# then fill in base_url, test_username, test_password, test_mail,
# imap_host, imap_port, imap_user, imap_password
```

```bash
# tests/e2e/.env — same keys, KEY=value lines, consumed by
# password-reset-roundtrip.js (it doesn't read the Postman environment file,
# since it runs outside Postman's own JS sandbox)
base_url=https://crespcord.lamotora.com
test_username=...
test_password=...
imap_host=...
imap_port=993
imap_user=...
imap_password=...
```

**Run:**

```bash
scripts/run-e2e-tests.sh    # or .ps1 on Windows
```

This runs `newman run tests/e2e/auth.postman_collection.json -e <env>`
followed by `node tests/e2e/password-reset-roundtrip.js`.

**Design constraints:**
- A reset token is never persisted in plaintext — only its SHA-256 hash, in
  `myapi_password_reset_tokens`. There is no way to read it back from the
  database; `password-reset-roundtrip.js` polls the real mailbox via IMAP
  (with retries/timeout) and extracts it from the reset link instead.
- `password-reset-roundtrip.js` resets the password to the **same value it
  already had**, so the account is left exactly as it was found and the run
  is repeatable without a second roundtrip to restore anything.
- Every negative case runs exactly once per run, far below the real flood
  thresholds (5 failed logins/h per username, 20/h per IP) — a run never
  risks locking out the test account or the runner's own IP for an hour.

---

## Scope note

`tests/` covers `auth` only. The pattern here (three layers, `myapi_test`
companion module for integration, Postman + Node for e2e) is meant to be
replicated for other resources (`unit`, `receipt`, `extra_fee`, `payment`,
`expense`) in future specs — see `specs/21-auth-testing.md`, "Fuera de este
spec".
