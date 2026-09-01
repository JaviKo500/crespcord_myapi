# 120 — Login por nombre de usuario **o** email (`POST /api/v1/auth/login`)

- **Estado:** Implemented — código y unit tests en verde (2945 tests, 12769 assertions); los tests de integración y la verificación manual contra el sitio quedan pendientes
- **Fecha:** 2026-09-01
- **Dependencias:**
  - `02-login-tokens` (Implemented) — el endpoint, el envelope, los tokens
    opacos y el `user_load_by_name()` que este spec sustituye. Su decisión
    "solo `username`" queda **superseded** aquí.
  - `06-brute-force-protection` (Implemented) — los dos contadores de flood del
    login, cuyo *sujeto* cambia en este spec.
  - `07-password-reset` (Implemented) — el precedente del repo para aceptar las
    dos formas de identificarse, del que se toma la precedencia
    "username primero".

**Objetivo:** Que la misma pantalla de login abra sesión escribiendo el nombre
de usuario **o** el correo, sin que el cliente tenga que decidir cuál de los dos
está viendo, y **sin regalar por ello un segundo cupo de intentos** contra la
misma contraseña.

---

## Motivación

La app pide "usuario y contraseña", pero lo que la gente recuerda es su correo:
es lo que escribió al registrarse, lo que le llegó el email de bienvenida y lo
único que ya usa para recuperar la contraseña (SPEC 07). Hasta ahora el correo
en ese campo devolvía `401 invalid_credentials` — indistinguible de una
contraseña mal escrita, así que el usuario reintentaba la contraseña, fallaba
cinco veces y se quedaba fuera una hora por un error que no era suyo.

---

## Contrato

El campo **sigue llamándose `username`** y sigue siendo el único requerido.
Ahora acepta las dos formas:

```json
{ "username": "javier",              "password": "1234" }
{ "username": "javier@lamotora.com", "password": "1234" }
```

| Aspecto | Comportamiento |
|---|---|
| Resolución | `users.name` primero; `users.mail` solo si el nombre no encontró nada **y** el valor contiene una `@` |
| Mayúsculas y espacios | Irrelevantes: se hace `trim()` y la comparación de la BD es *case-insensitive* |
| Fallo | `401 invalid_credentials`, idéntico para contraseña mala, usuario inexistente, **correo no registrado** y usuario bloqueado |
| Cupo de intentos | **5 por cuenta**, no 5 por forma de escribirla (ver decisión 3) |
| 422 | Sin cambios: falta `username` o `password` → `invalid_field` nombrando `username` |

No hay ruta nueva en `hook_menu()`, ni tabla, ni key nueva en el catálogo i18n.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.user.inc`** (modificar) — `myapi_user_load_by_identifier()`
  (nueva): la resolución nombre → correo. Vive en `includes/` y no en el
  recurso porque el identificador no es solo del login (regla 3 de CLAUDE.md).
- **`resources/auth.resource.inc`** (modificar) —
  `myapi_auth_login_flood_subject()` y `myapi_auth_login_flood_subjects()`
  (nuevas) y el cableado del contador en `myapi_auth_login()`.
- **Pruebas unitarias** — `tests/unit/AuthLoginIdentifierTest.php` (nuevo, 18
  casos) y los stubs de `user_load_by_name()` / `user_load_by_mail()` en
  `tests/unit/bootstrap.php`.
- **Pruebas de integración** — 5 casos nuevos en
  `tests/integration/MyapiAuthTestCase.test`.
- **E2E** — un request "Login by email" en
  `tests/e2e/auth.postman_collection.json` (reutiliza `{{test_mail}}`, que la
  variable de entorno ya traía).
- **`docs/auth.md`** (modificar).

**Fuera de alcance:**

- **Un campo `email` aparte en el login.** Ver decisión 1.
- **Rehacer `POST /api/v1/auth/password/forgot`** para que su clave `username`
  acepte también un correo. Ya acepta las dos formas por dos claves distintas;
  unificarlo con `myapi_user_load_by_identifier()` es un spec propio y no una
  mejora que este deba arrastrar.
- **Login por teléfono o por cédula** (`field_telefono`, `field_cedula`). Son
  campos de la Field API, no columnas de `users`: costarían un JOIN por intento
  y ninguno de los dos es único.
- **Normalizar correos** (bajar a minúsculas al guardar, plegar los puntos de
  Gmail). Lo que hace falta para entrar ya lo da la colación de la BD.
- **Decirle al usuario cuál de las dos formas falló.** Sigue siendo el mismo
  `401` para todo, a propósito.

---

## Decisiones

1. **El correo viaja en el campo `username`, no en un campo nuevo.** Es lo que
   deja que la app de Flutter acepte correos **sin cambiar el request**: un
   solo cuadro de texto, un solo `POST`, y la validación de 422 intacta. Un
   campo `email` aparte obligaría a que el `username` dejara de ser requerido,
   y con ello el `422 invalid_field: username` se convertiría en el
   `missing_field: username_or_email` que usa `password/forgot` — un cambio de
   contrato de error para todos los clientes actuales, a cambio de nada.
   El nombre del campo queda como una deuda cosmética; el contrato no.

2. **El nombre de usuario gana, y la `@` decide si se consulta el correo.**
   Drupal 7 permite `@` en `users.name`, así que una cuenta puede llamarse
   `ana@lamotora.com` mientras esa misma dirección es el `mail` de otra. Probar
   `name` primero garantiza que quien ya entraba con esa cadena siga entrando a
   **su** cuenta: resolverlo al revés le movería la sesión a la cuenta ajena y
   la víctima solo vería "contraseña incorrecta". Y saltarse la consulta de
   `mail` cuando no hay `@` deja el camino de siempre —el de todos los clientes
   actuales— exactamente en la única consulta que costaba.

3. **Los 5 intentos son por CUENTA, no por identificador.** Es el riesgo real
   de esta feature y no se ve en ninguna respuesta: si cada forma tuviera su
   contador, aceptar el correo habría **duplicado en silencio** el presupuesto
   de fuerza bruta contra cada cuenta —5 por usuario más 5 por dirección,
   contra la misma contraseña—. Un intento hecho con el correo se cobra sobre
   **la dirección y sobre el nombre de usuario detrás de ella**, así que
   alternar entre las dos formas no reinicia nada.

4. **El sujeto del contador se pliega (`trim` + minúsculas).** Antes se usaba la
   cadena cruda, de modo que `Javier` y `javier` —que entran a la misma
   cuenta— gastaban dos cupos de cinco. Plegarlo cierra ese bypass que ya
   existía antes de este spec.

5. **El sujeto tecleado se comprueba ANTES de cargar la cuenta.** Es lo que
   mantiene la propiedad que tenía el endpoint: un atacante ya bloqueado recibe
   su `429` sin gastar una consulta. El segundo sujeto (el nombre detrás del
   correo) solo se puede conocer después de la carga, y se comprueba ahí; pero
   para llegar a esa carga el atacante tuvo que pasar antes el contador de la
   dirección, que es el que él mismo está agotando.

6. **`uid = 0` nunca es un sujeto.** La fila anónima tiene `name` vacío: si se
   plegara, todos los intentos fallidos del sitio compartirían un contador y el
   primer atacante que lo agotara dejaría fuera a cualquier login fallido
   ajeno.

7. **La resolución vive en `includes/` aunque hoy la use un solo endpoint.**
   "Quién es este string" no es una pregunta del login: ya se la hace
   `password/forgot` con su propia copia, y se la hará cualquier endpoint
   futuro que reciba una identidad escrita a mano.

---

## Riesgos

- **Correos duplicados en `dr_users`.** El core impide registrar dos cuentas
  con el mismo `mail`, pero nada lo garantiza en filas creadas por importación
  o a mano. `user_load_by_mail()` devuelve la primera coincidencia, así que la
  segunda cuenta sería inalcanzable por correo (por su nombre entra igual).
  *Mitigación:* comprobarlo antes de desplegar —
  `SELECT mail, COUNT(*) FROM dr_users WHERE mail <> '' GROUP BY mail HAVING COUNT(*) > 1;`

- **La insensibilidad a mayúsculas es de la BD, no del módulo.** Depende de la
  colación `utf8_general_ci` de MySQL. Bajo una colación `_bin` el correo en
  mayúsculas no entraría, y el test de integración que lo cubre lo diría.

- **Una carga de entidad más en el camino del correo.** Un intento con `@` que
  no resuelve por nombre cuesta dos consultas en vez de una. Está acotado por
  el contador de IP (20/h) y por el de la propia dirección (5/h), que se
  comprueban los dos antes.

---

## Verificación manual

```bash
BASE=https://crespcord.lamotora.com

# 1. El correo abre la misma sesión que el usuario: mismo uid en las dos.
curl -s -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"javier","password":"***"}' | jq '.data.user.uid'
curl -s -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"javier@lamotora.com","password":"***"}' | jq '.data.user.uid'

# 2. Mayúsculas y espacios no importan.
curl -s -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"  JAVIER@LAMOTORA.COM  ","password":"***"}' | jq '.success'

# 3. Un correo no registrado es el mismo 401 que una contraseña mala.
curl -s -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"nadie@example.com","password":"x"}' | jq '.error_code'

# 4. El cupo es compartido: 3 fallos por usuario + 2 por correo → el 6.º es 429.
for i in 1 2 3; do curl -s -o /dev/null -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' -d '{"username":"javier","password":"mal"}'; done
for i in 1 2; do curl -s -o /dev/null -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' -d '{"username":"javier@lamotora.com","password":"mal"}'; done
curl -s -X POST "$BASE/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"javier","password":"mal"}' | jq '.error_code'   # too_many_attempts

# 5. Limpiar el bloqueo del paso 4 antes de seguir probando.
drush sqlq "DELETE FROM flood WHERE event IN ('myapi_login_user','myapi_login_ip')"
```

Basta con desplegar el recurso y el include: no hay ruta nueva, así que
`drush cc all` solo hace falta si el `.info` cambió (no cambió).
