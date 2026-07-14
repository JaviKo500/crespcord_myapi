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
installed there (used only to enable/disable `myapi_test`).

**Run:**

```bash
scripts/run-integration-tests.sh    # or .ps1 on Windows
```

This uploads `tests/integration/` to `sites/all/modules/myapi_test`, runs
`drush en myapi_test -y` followed by Drupal core's own SimpleTest runner, and
disables the module again at the end — **even if the test run itself fails** (a
trap/`finally` block guarantees the cleanup runs). If that cleanup also fails
(e.g. the SSH connection dropped), undo it by hand:

```bash
ssh -i scripts/crespcord.pem ubuntu@crespcord.lamotora.com \
  "cd /var/www/html && sudo -u www-data drush dis myapi_test -y"
```

**Design constraints — discovered while getting the suite green** (all against
the production server, Drush 8 / PHP 7.4):

- **The runner is `scripts/run-tests.sh`, not `drush test-run`.** Drush 8
  dropped the `test-run`/`test-clean` commands that Drush 5/6 shipped; Drupal
  core's native runner is the supported path:
  `php scripts/run-tests.sh --class MyapiAuthTestCase --url <site>`.
- **`run-tests.sh` scans *every* `.test` file on the site to discover
  classes.** A single contrib file that does not parse under PHP 7.4
  (`sites/all/modules/rules_forms/rules_forms.test`) fataled the whole run.
  The script moves that file aside for the duration of the run and restores it
  in the cleanup block. Safe because the module is disabled and `.test` files
  only load during test runs — even if the restore is skipped, production
  runtime is unaffected. If discovery starts fataling again, lint the site's
  `.test` files (`php -l`) to find the new offender.
- **The reset email needs the mail system forced back to `TestingMailSystem`
  in `setUp()`.** `myapi_enable()` (in `myapi.install`) maps the
  `myapi_password_reset` mail key to `MyapiHtmlMailSystem`, whose `mail()`
  really sends instead of storing the message in SimpleTest's collector — so
  `drupalGetMails()` would come back empty. `setUp()` rewrites the sandbox's
  `mail_system` variable for that key only; production code is never touched.
- **Raw `curlExec()` does not refresh the parent's variables.** Unlike
  `drupalGet()`, `curlExec()` skips `refreshVariables()`, so changes the
  request thread made to variables (notably `drupal_test_email_collector`,
  which backs `drupalGetMails()`) stay invisible to the test. `myapiRequest()`
  calls `refreshVariables()` after every request, exactly as `drupalGet()`
  does.
- **Expect ~14 harmless exceptions from the contrib `entity` module.**
  `entity_metadata_convert_schema()` emits PHP 7.4 notices/warnings during the
  test bootstrap. They come from contrib code, not from `myapi` or these tests
  (no assertion is involved), but SimpleTest counts them, so `run-tests.sh`
  exits non-zero even on a fully passing run. Judge the result by the
  **`N pases, 0 fallos`** line, not the exit code.

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
