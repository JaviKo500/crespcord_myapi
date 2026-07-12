# 21 — Tests unitarios, de integración y e2e para `/api/v1/auth`

- **Estado:** Approved
- **Fecha:** 2026-07-11
- **Dependencias:**
  - `02-login-tokens` (Implemented)
  - `04-refresh-token` (Implemented)
  - `05-middleware-access-token-logout` (Implemented)
  - `06-brute-force-protection` (Implemented)
  - `07-password-reset` (Implemented)
  - Esta spec no modifica ninguno de los endpoints anteriores — solo agrega cobertura de tests sobre su comportamiento ya documentado en `docs/auth.md`.
- **Objetivo:** Agregar una suite de tests en tres capas (unitarios con PHPUnit, integración con SimpleTest, e2e con Postman/Newman) que cubra los 5 endpoints JSON de `/api/v1/auth` documentados en `docs/auth.md`, dejando fuera la página HTML `password/reset`.

---

## Scope

**Dentro de este spec:**

- **`tests/unit/`** (PHPUnit standalone, nuevo) — `TokenTest.php` (`myapi_token_hash`, `myapi_token_generate_access/refresh/reset`), `AuthBearerTest.php` (`myapi_auth_parse_bearer`), `PasswordResetExecuteTest.php` (solo los early-return de `myapi_auth_password_reset_execute()` por longitud de `new_password`, sin tocar BD).
- **`tests/integration/`** (SimpleTest, nuevo) — módulo companion `myapi_test` (`myapi_test.info` + `myapi_test.module`, dev-only, nunca desplegado por `scripts/deploy.sh`) + `MyapiAuthTestCase.test` (`DrupalWebTestCase`) cubriendo los 5 endpoints JSON vía HTTP real (`curlExec()`) contra el sandbox propio de SimpleTest: éxito + cada error documentado en `docs/auth.md` (`missing_field`, `invalid_field`, `field_too_long`/`field_too_short`, `invalid_credentials`, `invalid_token`, `token_expired`, `missing_authorization`, `too_many_attempts`, `method_not_allowed`).
- **`tests/e2e/`** (Postman/Newman + un script Node, nuevo):
  - `auth.postman_collection.json` — flujo `login → refresh → logout` completo contra `https://crespcord.lamotora.com`, más un smoke check de `/forgot` (solo el `200` genérico).
  - `password-reset-roundtrip.js` — script Node que hace `forgot` → poll IMAP a la casilla de la cuenta de prueba → `reset` usando el mismo password actual como `new_password` (no rompe la cuenta para la siguiente corrida).
  - `auth.postman_environment.example.json` (committeado, placeholders) y `.gitignore` para el archivo real con credenciales.
  - `package.json` (deps: `newman`, cliente IMAP tipo `imapflow`).
- **`scripts/run-unit-tests.sh` / `.ps1`, `run-integration-tests.sh` / `.ps1`, `run-e2e-tests.sh` / `.ps1`** (nuevo) — mismo patrón dual que `deploy.sh`/`deploy.ps1` ya existente en el repo.
- **`composer.json`** en la raíz (nuevo) — solo `require-dev: phpunit/phpunit`, sin afectar el módulo Drupal en sí.
- **`tests/README.md`** (nuevo) — cómo correr cada capa, cómo configurar el environment file de Postman y las credenciales IMAP.

**Fuera de este spec (para specs futuros):**

- Tests de la página HTML `GET/POST password/reset` — no sigue el envelope JSON, se probaría distinto (parseo de HTML, deep link). Spec aparte si se decide cubrirla.
- Pipeline de CI (GitHub Actions) — por ahora todo se corre manual (local vía SSH para integración, local para e2e/unit).
- Tests de cualquier otro recurso (`unit`, `receipt`, `extra_fee`, `payment`, `expense`) — este spec es solo para `auth`. El patrón queda documentado para replicarlo después.
- Refactor de `auth.resource.inc`/`includes/*` para "hacerlo más testeable" — se testea el código tal como está hoy, sin cambiar producción.
- Creación de la cuenta de prueba en producción y de su casilla de correo con acceso IMAP — se asume que ya existen (indicadas por el usuario), este spec solo las consume vía el environment file.
- Ejercitar en e2e los límites de flood a su escala real (20/h por IP, etc.) — solo se prueban en integración (sandbox), donde no hay riesgo de bloquear la cuenta/IP real por una hora.

---

## Modelo de datos

Este spec no introduce nuevas tablas ni columnas en Drupal — reutiliza `dr_users`, `my_api_tokens` y `myapi_password_reset_tokens` tal como existen hoy.

Lo único "nuevo" son estructuras de configuración para los tests:

**`myapi_test.info`** (companion module, dev-only):
```ini
name = My API Tests
description = SimpleTest integration tests for the myapi auth resource. Dev-only, never deployed to production.
core = 7.x
package = Custom
dependencies[] = myapi
dependencies[] = simpletest
files[] = MyapiAuthTestCase.test
```

**`tests/e2e/auth.postman_environment.example.json`** (placeholders; el real, con valores, no se commitea):
```json
{
  "values": [
    { "key": "base_url", "value": "https://crespcord.lamotora.com" },
    { "key": "test_username", "value": "<qa test account username>" },
    { "key": "test_password", "value": "<qa test account password>" },
    { "key": "test_mail", "value": "<qa test account mailbox>" },
    { "key": "imap_host", "value": "<imap host>" },
    { "key": "imap_port", "value": "993" },
    { "key": "imap_user", "value": "<imap login>" },
    { "key": "imap_password", "value": "<imap password>" }
  ]
}
```

`password-reset-roundtrip.js` lee estas mismas claves desde un `.env` local (no commiteado) en vez del JSON de Postman, ya que corre fuera del sandbox de Postman.

---

## Plan de implementación

1. **`composer.json`** (raíz, nuevo) — `require-dev: phpunit/phpunit` (versión compatible con PHP 7.4, ej. `^9.5`). **`phpunit.xml`** — `bootstrap="tests/unit/bootstrap.php"`, testsuite `Unit` → `tests/unit`.

2. **`tests/unit/bootstrap.php`** — define un stub no-op de `module_load_include($type, $module, $name)` (las únicas llamadas a Drupal que hay a nivel de archivo en `resources/auth.resource.inc`), para poder incluir los `.inc`/`.resource.inc` **de producción, sin copias**, fuera de Drupal.

3. **`tests/unit/TokenTest.php`** — `require includes/myapi.token.inc` directo (sin dependencias de Drupal). Casos: `myapi_token_hash()` determinístico y de 64 hex chars; `myapi_token_generate_access()` 64 hex chars y dos llamadas difieren; `myapi_token_generate_refresh()` 128 hex chars; `myapi_token_generate_reset()` 64 hex chars.

4. **`tests/unit/AuthBearerTest.php`** — `require includes/myapi.auth.inc`. Casos para `myapi_auth_parse_bearer()` stubeando `$_SERVER['HTTP_AUTHORIZATION']`: header ausente → `NULL`; `"Bearer abc123"` → `"abc123"`; case-insensitive (`"bearer abc"`); formato inválido (`"Basic xxx"`, `"Beareraaa"`, vacío) → `NULL`.

5. **`tests/unit/PasswordResetExecuteTest.php`** — `require resources/auth.resource.inc` (vía el stub del bootstrap). Solo los dos early-return de `myapi_auth_password_reset_execute()`: `new_password` de 7 chars → `field_too_short`; de 256 chars → `field_too_long`. No se testea el resto (toca BD).

6. **`scripts/run-unit-tests.sh` / `.ps1`** — `composer install --no-interaction && vendor/bin/phpunit`. Verificar que corre en verde localmente.

7. **`tests/integration/myapi_test.info`** + **`tests/integration/myapi_test.module`** (vacío, solo declara el módulo) — companion module dev-only, nunca tocado por `scripts/deploy.sh`.

8. **`tests/integration/MyapiAuthTestCase.test`**, esqueleto — `getInfo()` (`group: 'My API'`), `setUp()` habilita `myapi` + `simpletest`, un primer método `testLoginSuccess()` vía `$this->curlExec()` (POST JSON crudo, no `drupalPost()` que es para Form API) contra `api/v1/auth/login` con un usuario creado por `$this->drupalCreateUser()`.

9. **Ampliar con los casos de error de login**: `missing_field`/`invalid_field` (username/password ausente o vacío), `invalid_credentials` (password incorrecto, usuario inexistente, usuario bloqueado — mismo body en los tres), flood `429` (6 intentos fallidos con el mismo username), `405` con GET.

10. **Casos de `refresh`**: éxito + rotación (el `refresh_token` viejo revocado, ya no sirve); `token_expired` (manipulando `refresh_expires_at` directo vía `db_update` antes de refrescar); `invalid_token` (desconocido o ya revocado); `missing_field`; `405`.

11. **Casos de `logout`**: éxito (fila queda `revoked=1`); `invalid_token` cuando el `refresh_token` del body no matchea el de la sesión del access token; `missing_authorization`; `missing_field`; `405`.

12. **Casos de `forgot`**: cuenta existente y activa → `200` + fila nueva en `myapi_password_reset_tokens` + fila anterior sin usar queda `used=1`; usuario/email inexistente → mismo `200` genérico, sin fila nueva; `missing_field`; `405`. El email real **no se envía** dentro del sandbox de SimpleTest — `DrupalWebTestCase` lo intercepta automáticamente y queda disponible vía `$this->drupalGetMails()`, que se usa en el siguiente paso para extraer el token.

13. **Casos de `reset`**: roundtrip completo llamando primero a `forgot`, extrayendo el token del link dentro de `$this->drupalGetMails()`, y confirmando éxito (password cambia de verdad — se verifica con un login posterior; todas las filas `my_api_tokens` del usuario quedan revocadas); `field_too_short`/`field_too_long`; `invalid_token` (token desconocido o ya usado); `token_expired` (manipulando `expires_at`); `405`.

14. **`scripts/run-integration-tests.sh` / `.ps1`** — sube `tests/integration/` al servidor como `sites/all/modules/myapi_test` vía SSH (mismo patrón que `deploy.sh`), `drush en myapi_test -y`, `drush test-run MyapiAuthTestCase`, imprime resultado, `drush dis myapi_test -y` al final.

15. **`tests/e2e/package.json`** (deps `newman`, `imapflow`) + **`tests/e2e/auth.postman_collection.json`** — flujo `login → refresh → logout` contra `https://crespcord.lamotora.com` con la cuenta dedicada, más negativos baratos y no destructivos (password incorrecto → `401`, campo faltante → `422`, método incorrecto → `405`) y smoke check de `/forgot` (solo el `200` genérico).

16. **`tests/e2e/password-reset-roundtrip.js`** — llama `/forgot`, hace poll por IMAP (con reintentos/timeout) al último correo de reset en la casilla de la cuenta de prueba, extrae el token del link, llama `/reset` usando como `new_password` el **mismo password actual** de la cuenta, y verifica con un login posterior que sigue funcionando igual.

17. **`tests/e2e/auth.postman_environment.example.json`** (committeado, placeholders) + entradas en `.gitignore` (`tests/e2e/*.postman_environment.json` real, `tests/e2e/.env`, `tests/e2e/node_modules/`, `vendor/`).

18. **`scripts/run-e2e-tests.sh` / `.ps1`** — `newman run tests/e2e/auth.postman_collection.json -e <environment local>` seguido de `node tests/e2e/password-reset-roundtrip.js`.

19. **`tests/README.md`** — cómo correr cada capa, cómo provisionar los archivos de entorno/credenciales, y las restricciones de diseño descubiertas (`drupal_exit()` impide llamadas in-process, tokens solo se persisten como hash).

20. **Correr las tres suites de punta a punta** y verificar contra los criterios de aceptación.

---

## Criterios de aceptación

- [ ] `composer install && vendor/bin/phpunit` corre en verde sin bootstrapear Drupal.
- [ ] `TokenTest`: `myapi_token_hash()` es determinístico (mismo input → mismo hash) y de 64 hex chars; `generate_access()` devuelve 64 hex chars distintos en cada llamada; `generate_refresh()` 128 hex chars distintos; `generate_reset()` 64 hex chars distintos.
- [ ] `AuthBearerTest`: `myapi_auth_parse_bearer()` devuelve el token correcto con `"Bearer <token>"` (incluyendo prefijo case-insensitive), y `NULL` con header ausente, `"Basic xxx"`, o formato malformado.
- [ ] `PasswordResetExecuteTest`: `myapi_auth_password_reset_execute()` devuelve `field_too_short` con password de 7 chars y `field_too_long` con 256, sin tocar la base de datos.
- [ ] `drush en myapi_test -y && drush test-run MyapiAuthTestCase` corre en verde contra el sandbox de SimpleTest, sin afectar datos de producción.
- [ ] Integración cubre `login`: éxito con tokens/user correctos, `invalid_credentials` (password incorrecto, usuario inexistente, usuario bloqueado — mismo body en los tres), `missing_field`/`invalid_field`, `429` tras 5 intentos fallidos del mismo username, `405` con método distinto de POST.
- [ ] Integración cubre `refresh`: éxito con rotación verificada (el `refresh_token` viejo deja de servir), `token_expired`, `invalid_token`, `missing_field`, `405`.
- [ ] Integración cubre `logout`: éxito con la fila quedando `revoked=1`, `invalid_token` por mismatch de sesión, `missing_authorization`, `missing_field`, `405`.
- [ ] Integración cubre `forgot`: cuenta existente crea fila nueva en `myapi_password_reset_tokens` e invalida la anterior; cuenta inexistente responde el mismo `200` genérico sin crear fila; `missing_field`; `405`.
- [ ] Integración cubre `reset`: roundtrip completo (token extraído de `$this->drupalGetMails()`) cambia el password de verdad y revoca todas las sesiones activas del usuario; `field_too_short`/`field_too_long`; `invalid_token`; `token_expired`; `405`.
- [ ] `myapi_test` queda deshabilitado en el servidor al terminar `run-integration-tests.sh`, sin dejar rastros en producción.
- [ ] `newman run tests/e2e/auth.postman_collection.json -e <env>` corre en verde contra `https://crespcord.lamotora.com`: `login → refresh → logout` completo, más los negativos baratos (`401`, `422`, `405`) y el smoke check de `/forgot`.
- [ ] `node tests/e2e/password-reset-roundtrip.js` completa el roundtrip `forgot → IMAP → reset` contra producción y deja la cuenta de prueba con el mismo password que tenía antes (verificado con un login posterior exitoso).
- [ ] Ninguna corrida de e2e se acerca a los umbrales reales de flood (máximo 1 intento fallido por caso negativo, muy por debajo de 5/20).
- [ ] El archivo real de credenciales (`auth.postman_environment.json`, `.env` de IMAP) nunca queda commiteado — solo los `.example.json` con placeholders.
- [ ] `tests/README.md` documenta cómo correr las 3 capas y cómo provisionar credenciales.
- [ ] Ningún archivo de `tests/` es subido por `scripts/deploy.sh` (deploy normal de producción queda sin cambios).

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Entorno de integración/e2e | Producción (`crespcord.lamotora.com`) con cuenta de prueba dedicada | Entorno local nuevo (DDEV/Docker) | Pedido explícito del usuario; evita levantar y mantener un segundo entorno. |
| Framework unitario | PHPUnit standalone | SimpleTest para todo | Más rápido (sin bootstrap de Drupal) para funciones genuinamente puras. |
| Framework de integración | SimpleTest (`DrupalWebTestCase`) | PHPUnit + bootstrap manual de Drupal | Estándar nativo de D7; evita un bootstrap manual frágil. |
| Alcance de endpoints | Los 5 JSON (`login`, `refresh`, `logout`, `forgot`, `reset`) | Los 6, incluyendo la página HTML `password/reset` | La página no sigue el envelope JSON; se probaría distinto (parseo HTML). Spec aparte si se decide cubrirla. |
| Cómo llamar a los endpoints en integración | HTTP real vía `curlExec()` contra el sandbox de SimpleTest | Llamar las funciones PHP directo desde el test | `myapi_respond()`/`myapi_error()` terminan en `drupal_exit()` — invocarlas in-process mataría el test runner. |
| Usuario de prueba en producción | Ya existe, provisto por el usuario | Crearlo como parte de este spec | El usuario indicó que ya tiene una cuenta dedicada. |
| Cómo evitar contaminar el buzón en cada corrida | La cuenta de prueba usa un mail propio controlado; e2e no depende del buzón para el smoke check de `/forgot` | Leer el token de la BD | El token de reset **nunca se persiste en texto plano** (solo su hash SHA-256) — leerlo de la BD es imposible por diseño. |
| Cómo obtener el token real para el roundtrip de `/reset` en e2e | Poll IMAP a la casilla real de la cuenta de prueba (`password-reset-roundtrip.js`) | Servicio de test-inbox (Mailtrap/Mailosaur) | Pedido explícito del usuario; no requiere dar de alta un servicio externo. |
| Cómo obtener el token en integración | `$this->drupalGetMails()` (captura automática de SimpleTest) | También IMAP | Dentro del sandbox de SimpleTest el email nunca se envía de verdad; Drupal ya lo expone sin infraestructura extra. |
| Password usado en el reset e2e | El mismo password actual de la cuenta | Un password nuevo, con un segundo roundtrip para restaurarlo | Ejercita la lógica real (hash, revocación) sin duplicar el roundtrip ni arriesgar dejar la cuenta en un estado desconocido si algo falla a mitad de camino. |
| Registro de los tests de integración en Drupal | Módulo companion `myapi_test` (`.info`/`.module` propios, dev-only) | Agregar los `.test` a `files[]` de `myapi.info` | Desacopla los tests de `scripts/deploy.sh`: un deploy normal nunca sube ni depende de código de test. |
| Herramienta e2e | Colección Postman/Newman + script Node aparte para IMAP | Todo en un script PHPUnit+Guzzle | Postman simula bien los headers/flujo de la app Flutter; IMAP no corre dentro del sandbox JS de Postman, de ahí el script Node separado solo para ese paso. |
| Manejo de credenciales | Archivo de entorno gitignoreado + `.example.json` con placeholders committeado | Pasar todo por `--env-var` en la línea de comandos | Evita tener que reconstruir un comando largo cada vez; el `.example.json` documenta qué claves hacen falta. |
| CI/CD | Ninguno por ahora, todo manual | GitHub Actions | Pedido explícito del usuario; se puede agregar en un spec futuro. |
| Cleanup entre corridas | Cada test deja el estado como lo encontró (password igual, sesiones propias revocadas sin afectar otras) | Reseteo manual periódico | Pedido explícito del usuario; hace las corridas repetibles sin intervención. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Correr contra producción.** Un bug en un test destructivo (logout, reset) podría afectar la cuenta real de un momento a otro si el usuario de prueba se reutiliza mal. | Uso exclusivo de la cuenta dedicada, nunca cuentas de residentes reales; cada test/roundtrip deja el estado como lo encontró. |
| **Flakiness de IMAP.** El correo de `/forgot` puede tardar en llegar; un poll sin reintentos fallaría espuriamente. | `password-reset-roundtrip.js` hace poll con reintentos y timeout (ej. cada 2s hasta 30s) antes de fallar. |
| **Flood real en e2e.** Correr los negativos muchas veces (ej. reintentos de CI manual) podría acercarse a los umbrales de flood de producción (5 login fallidos/hora por usuario, 20/hora por IP) y bloquear la cuenta o la IP de quien corre los tests por una hora. | Cada caso negativo se ejecuta una sola vez por corrida; un login exitoso al inicio del flujo limpia ambos contadores de flood (comportamiento ya documentado del endpoint). |
| **`myapi_test` mal deshabilitado.** Si `run-integration-tests.sh` falla a mitad de camino, el módulo companion podría quedar habilitado en producción. | El script deshabilita `myapi_test` en un bloque que corre incluso si `drush test-run` falla; documentado en `tests/README.md` cómo deshabilitarlo a mano si hace falta. |
| **Cambios futuros en `auth.resource.inc` rompen el stub del bootstrap unitario.** Si se agrega una llamada a otra función de Drupal a nivel de archivo (no dentro de una función), `tests/unit/bootstrap.php` dejaría de ser suficiente. | Documentado explícitamente en `tests/README.md`: cualquier nueva llamada Drupal a nivel de archivo en los `.inc` testeados por unit requiere ampliar el stub. |
| **Credenciales IMAP/cuenta de prueba filtradas.** Si alguien comitea por error el `.env` o el `.postman_environment.json` real. | `.gitignore` explícito para ambos; solo se commitean los `.example.json` con placeholders; revisar `git status` antes de cada commit de `tests/e2e/`. |
| **Deriva entre `docs/auth.md` y los tests.** Si `auth.md` cambia (nuevo error, nuevo campo) sin actualizar los tests, la suite queda desactualizada silenciosamente. | Sin mitigación automática en este spec — mismo criterio ya aceptado en el resto del proyecto (docs y código se actualizan juntos, no hay chequeo automático de deriva). |
