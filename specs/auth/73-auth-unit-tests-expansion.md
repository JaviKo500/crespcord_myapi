# 73 — Ampliación de los tests unitarios: todo lo unitariamente testeable de `specs/auth/`

- **Estado:** Implemented — 143 casos nuevos, suite unitaria en verde (554 tests, 2462 assertions)
- **Fecha:** 2026-08-06
- **Dependencias:**
  - `21-auth-testing` (Implemented) — define las tres capas y el `bootstrap.php` que este spec extiende. Este spec **no** toca `tests/integration/` ni `tests/e2e/`.
  - `01-bootstrap-modulo` (Implemented) — `includes/myapi.request.inc`, cuyos validadores se cubren aquí por primera vez.
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` / `myapi_get_lang()`.
  - `02-login-tokens` (Implemented) — `myapi_auth_build_user_payload()`.
  - `07-password-reset` (Implemented) — los renderers y los handlers de `password/reset`.
  - `06-brute-force-protection` (Implemented) — `includes/myapi.flood.inc`, que tenía **un** test en todo el proyecto.
- **Objetivo:** Cubrir con tests unitarios **todo lo que de `specs/auth/` es unitariamente testeable** y no lo estaba tras SPEC 21. Al terminar, lo único sin cobertura unitaria en esos specs es lo que empieza en el primer `db_select()` — y este spec deja escrito, función por función, qué es y por qué.

---

## El problema

SPEC 21 dejó la capa unitaria acotada a lo que en ese momento era puro dentro de `auth`: hashing y generación de tokens, parseo del header `Bearer` y los dos early-return de longitud de `myapi_auth_password_reset_execute()`. Esa acotación era correcta para su alcance, pero dejó fuera cuatro cosas que **también** son puras y que pesan más que lo cubierto:

1. **`includes/myapi.request.inc`.** `myapi_request_require_fields()` y `myapi_request_require_strings()` son el filtro por el que pasa el input de *todos* los recursos, no solo de `auth`. No tenían ningún test propio: `ClaimCloseReasonTest` las menciona en su docblock pero prueba otra función. La razón de que nadie las hubiera testeado está anotada en `ClaimListFilterTest`: rechazan llamando a `myapi_error()`, que imprime y termina con `drupal_exit()`, así que en proceso matarían al runner.
2. **`includes/myapi.i18n.inc`.** Una clave que exista en `es` y no en `en` no falla: `myapi_t()` devuelve la clave misma, así que la app muestra `reservation_overlap` donde debería mostrar una frase. Con ~140 claves × 2 idiomas mantenidas a mano, eso es cuestión de tiempo y no hay nada que lo detecte.
3. **`myapi_auth_build_user_payload()`.** El sub-objeto `user` de login y refresh. La app decide qué pantallas abre leyendo `data.user.roles[].uid`; un `rid` que salga como string en vez de int es un bug de cliente sin síntoma de servidor.
4. **La página `password/reset` entera.** SPEC 21 la excluyó explícitamente ("no sigue el envelope JSON, se probaría distinto"). El resultado es que la única página que un usuario real mira, y la única que imprime un valor tomado del query string, no tenía cobertura de escapado en ninguna de las tres capas.
5. **`includes/myapi.flood.inc` (SPEC 06).** Tenía **un** test en todo el proyecto — el sexto login fallido para el mismo username, en integración — sobre siete contadores. Los otros seis (`login_ip`, `refresh_ip`, `logout_ip`, `forgot_ip`, `forgot_identifier`, `reset_ip`) y **todos** los umbrales configurables estaban sin ejecutar. Y el modo de fallo es silencioso por construcción: un nombre de variable mal escrito cae al comodín 10/3600 y un *threshold* y una *window* intercambiados siguen devolviendo un booleano, así que un rate limit equivocado no produce ningún error en ningún sitio.
6. **`includes/myapi.response.inc` (SPEC 01).** El envelope que CLAUDE.md llama "sin excepciones". Nada lo assertaba nunca, porque ambas funciones terminan en `drupal_exit()`.
7. **`resources/ping.resource.inc` (SPEC 01).** El recurso de referencia que todos los demás copian: cero cobertura en las tres capas.
8. **Los cinco dispatchers y los TTL.** El `405` de cada endpoint solo se verificaba en integración, y `expires_in` no se assertaba en ninguna capa.

---

## Alcance

### Dentro de este spec

- **`tests/unit/bootstrap.php`** (modificar) — nueve stubs nuevos y un helper:
  - `variable_get()`, `url()`, `ip_address()`, `drupal_exit()`, `drupal_add_http_header()`, `drupal_json_encode()`, `flood_is_allowed()`, `flood_register_event()` y `flood_clear_event()`, todos del tipo Drupal ya establecido en el archivo, más la clase `MyapiExit`.
  - `myapi_user_fetch_profile_fields()`, **el único stub que sustituye una función de `myapi` y no de Drupal**.
  - `myapi_test_capture()`, la costura única entre "una función que responde y termina la petición" y una assertion.
- **`tests/unit/RequestValidationTest.php`** (nuevo, 33 casos) — `myapi_request_require_fields()`, `myapi_request_require_strings()`, `myapi_valid_iso_date()`, `myapi_parse_date_range_param()`, `myapi_request_method()`, `myapi_request_post_field()` y `myapi_request_post_field_array()`.
- **`tests/unit/I18nTest.php`** (nuevo, 18 casos) — paridad del catálogo entre `es`/`en`, paridad de placeholders, ausencia de claves duplicadas, sustitución con `strtr()`, fallbacks, y las siete reglas de resolución de `myapi_get_lang()`.
- **`tests/unit/AuthUserPayloadTest.php`** (nuevo, 9 casos) — `myapi_auth_build_user_payload()`.
- **`tests/unit/PasswordResetPageTest.php`** (nuevo, 33 casos) — los cuatro renderers **y los tres handlers** `..._page()`, `..._page_get()`, `..._page_post()`.
- **`tests/unit/ResponseEnvelopeTest.php`** (nuevo, 14 casos) — `myapi_respond()` y `myapi_error()` **reales**: forma del envelope, orden de claves, códigos de estado, traducción y el escapado hexadecimal de `drupal_json_encode()`.
- **`tests/unit/FloodTest.php`** (nuevo, 14 casos) — `myapi_flood_is_allowed()`, `myapi_flood_check()`, `myapi_flood_register()`, los siete contadores documentados y la conformidad de los call sites reales.
- **`tests/unit/PingTest.php`** (nuevo, 4 casos) — `myapi_ping_dispatch()` y `myapi_ping_get()`.
- **`tests/unit/AuthEndpointGuardsTest.php`** (nuevo, 14 casos) — las guardas de los 5 endpoints JSON: routing por método, puerta de flood y primera puerta de validación de cada uno.
- **`tests/unit/TokenTest.php`** (modificar, +4 casos) — `myapi_token_access_ttl()`, `myapi_token_refresh_ttl()` y `myapi_password_reset_ttl()`.
- **`includes/myapi.request.inc`** (modificar, **una línea**) — el modificador `D` en el patrón de `myapi_valid_iso_date()`; ver "El defecto encontrado".
- **`tests/README.md`** (modificar) — la sección de unitarios y la lista de stubs.

### Fuera de este spec — y por qué

Todo lo que sigue empieza en el primer `db_select()`, y ahí termina lo que esta capa puede decir. Se lista función por función para que la ausencia sea una decisión anotada y no un olvido:

| Función | Por qué no |
|---|---|
| `myapi_request_body()` | Lee `php://input`, que un test no puede escribir. Su consecuencia —un body vacío— **sí** se ejercita: es lo que dispara la puerta de validación de los cinco endpoints en `AuthEndpointGuardsTest`. |
| `myapi_token_persist()`, `myapi_password_reset_token_persist()`, `myapi_password_reset_token_invalidate_previous()` | `db_insert()` / `db_update()` puros. |
| `myapi_auth_require_access_token()` | Su primera rama (`missing_authorization`) queda cubierta vía el guard de logout; las tres siguientes son `db_select()` + `user_load()`. |
| `myapi_auth_login/refresh/logout/password_forgot/password_reset()` | Cubiertas **hasta** su primera consulta: routing, flood y validación. Credenciales, rotación, revocado y envío de correo son de integración. |
| `myapi_auth_password_reset_execute()` | Solo los dos early-return de longitud, como en SPEC 21. Gastar un token real es de integración. |
| `myapi_user_fetch_profile_fields()` | Cuatro `LEFT JOIN`. Su consumidor sí está cubierto. |
| `myapi.install` (`hook_schema()`, `hook_install/enable/uninstall()`, `myapi_update_7001` y siguientes) | Crean tablas y mapean el mail system; no hay nada puro que ejecutar. |
| `hook_menu()` en `myapi.module` | Devuelve un array de rutas que solo significa algo dentro de Drupal. Que cada ruta llegue a su dispatcher se verifica en integración; que cada dispatcher haga lo correcto, aquí. |

- **Tests para otros recursos.** Los validadores, el envelope y el i18n cubiertos aquí son compartidos y benefician a todos, pero este spec no añade casos por recurso.
- **Los contadores de flood a escala real** (que el sexto intento sea de verdad el que corta) siguen siendo de integración: hace falta un sandbox que avance la tabla `{flood}`.

---

## El defecto encontrado

`testValidIsoDateRejectsMalformedStrings` falló en su primera corrida con el caso `"2026-08-06\n"`:

```php
preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',  "2026-08-06\n");  // 1  ← pasaba
preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', "2026-08-06\n");  // 0
```

En PCRE, `$` sin el modificador `D` hace match también **justo antes de un salto de línea final**. `myapi_valid_iso_date()` devuelve `$value`, no las capturas, así que un `?date_from=2026-08-06%0A` volvía con el `\n` pegado y entraba tal cual en el `BETWEEN` que construye el caller. No es explotable —el valor viaja como parámetro enlazado, no concatenado— pero es una forma no validada saliendo de un validador, y `myapi_parse_date_range_param()` compara los dos extremos como texto para detectar la inversión, comparación que ese carácter invisible desajusta.

La corrección es el modificador `D`, con el porqué anotado en el docblock de la función. **Es el único cambio a código de producción de este spec**: el resto es exclusivamente `tests/`.

Efecto secundario, declarado: un cliente que hoy mande un salto de línea final pasa de "filtro aplicado" a "filtro ignorado". El filtro es laxo por diseño (nunca responde 422), así que en ambos casos la respuesta es un 200 — cambia qué filas trae, no si falla.

---

## Los stubs nuevos, y la decisión que los ordena

`bootstrap.php` ya tenía dos categorías de stub: `module_load_include()` (llamado a nivel de archivo, para que el `require` no fatal) y los equivalentes fieles de funciones de Drupal (`check_plain()`, `truncate_utf8()`, `element_children()`, …). Este spec amplía la segunda y añade una tercera de un solo miembro:

| Stub | Tipo | Por qué |
|---|---|---|
| `variable_get()` | Drupal | Lee de `$GLOBALS['myapi_test_variables']` en vez de la tabla `variable`. Un sitio sin override devuelve el default del call site, que es exactamente lo que asserta un test del comportamiento shipeado. |
| `url()` | Drupal | La mitad relativa a la raíz. Dos divergencias declaradas e inalcanzables desde el código bajo test: no resuelve alias (myapi solo pasa rutas de sistema) y ignora `absolute`. |
| `drupal_exit()` | Drupal | Lanza `MyapiExit`. Es lo que reproduce "y aquí termina la petición": el código que sigue a un `myapi_error()` tampoco corre en un test. |
| `drupal_add_http_header()` | Drupal | Registra la cabecera en vez de enviarla. Es lo que hace assertable el **código de estado**: 401 vs 422 vs 429 es la mitad del contrato de esta API, y assertar solo el body la perdería entera. |
| `drupal_json_encode()` | Drupal | La implementación de core, con los cuatro flags `JSON_HEX_*`. Fiel en vez de cómoda a propósito: esos flags cambian los **bytes** de cada respuesta, y un `json_encode()` pelado haría que los tests discreparan de producción. |
| `ip_address()` | Drupal | Una IP fija y controlable, que es lo que identifica a seis de los siete contadores de flood. |
| `flood_is_allowed()`, `flood_register_event()`, `flood_clear_event()` | Drupal | Responden lo que el test diga y **registran cómo se les llamó**. Contar filas en `{flood}` no es responsabilidad de `myapi.flood.inc`; resolver un límite y una ventana a partir de dos nombres de variable y pasarlos en el orden correcto, sí. |
| `myapi_user_fetch_profile_fields()` | **myapi** | Devuelve una fila controlada por el test. Lo que se prueba no es la función (cuatro `LEFT JOIN`) sino su consumidor. |

**La decisión que ordena todo esto:** la primera versión de este spec stubeaba `myapi_error()` —una función de `myapi`— para poder testear los validadores. Se descartó al ampliar el alcance. Stubear en su lugar las **tres funciones de Drupal** que `myapi_error()` usa (`drupal_exit`, `drupal_add_http_header`, `drupal_json_encode`) es estrictamente mejor por tres razones:

1. `myapi_respond()` y `myapi_error()` pasan de ser imposibles de testear a estar cubiertas (`ResponseEnvelopeTest`), y ningún test asserta contra una reimplementación del envelope, que estaría de acuerdo consigo misma y no probaría nada.
2. Se abre en cascada todo lo que *responde*: los cinco dispatchers, `ping`, las puertas de flood y validación de cada endpoint, y los tres handlers de la página HTML. Casi la mitad de los casos de este spec existen gracias a ese cambio.
3. Desaparece el coste que la primera versión tuvo que documentar: ya solo hay **un** stub de función `myapi`, así que solo `includes/myapi.user.inc` es irrequeribile desde esta capa. Sigue siendo un fatal `Cannot redeclare` si alguien lo intenta, y el `@file` de `bootstrap.php` lo dice.

`myapi_test_capture()` es la única función que conoce esa mecánica: abre un buffer de salida, corre el callable, atrapa `MyapiExit` y devuelve `output` / `json` / `status` / `headers`. Cierra el buffer en un `finally`, para que un callable que lance otra cosa —un bug real en el código bajo test— no deje el buffer abierto y se coma el resto de la salida de la suite.

---

## Decisiones tomadas y descartadas

- **`myapi_get_lang()` en procesos separados (`@runInSeparateProcess`), no en el compartido.** La función memoiza el idioma en un `static` que ningún test puede resetear. En un solo proceso, el primer caso que corriera decidiría el idioma del resto de la suite — incluida `PasswordResetPageTest`, cuyo HTML depende de él. Los siete casos aislados cuestan ~0.3 s en total. Como red de seguridad, el `setUp()` de `PasswordResetPageTest` assertá `myapi_get_lang() === 'es'` como precondición: si algún día un archivo que ordene antes resuelve `en`, falla una assertion con mensaje claro en vez de seis casos por sus textos.
- **La paridad del catálogo se lee del código fuente por reflexión, no de una lista escrita a mano.** El catálogo es un array local dentro de `myapi_t()`: no hay handle en runtime. Una lista hardcodeada en el test envejecería con el siguiente recurso que añada mensajes. `ReflectionFunction` acota la lectura al rango de líneas de la función y `assertNotEmpty()` hace que un parse que deje de matchear falle en voz alta en vez de convertir las assertions de paridad en no-ops. El parse acepta comillas simples **y** dobles en el valor: los textos ingleses con apóstrofo (`"the area's opening hours"`) están escritos con dobles, y una primera versión que solo aceptaba simples reportó una falsa brecha de paridad.
- **`myapi_request_require_strings()` mide bytes, y el test lo fija en vez de llamarlo bug.** 255 es el ancho de columna, así que contar bytes es lo que protege el `INSERT`. Donde el límite es editorial y no estructural, los recursos **no** usan este helper: `myapi_claim_validate_close_reason()` cuenta caracteres con `drupal_strlen()` por esta misma razón (SPEC 70), y `ClaimCloseReasonTest` assertá el resultado contrario para el mismo input. Los dos tests juntos documentan la diferencia.
- **También se fija que la longitud se mide *sin* trim, aunque el vacío sí se juzga *con* trim.** Es la única inconsistencia del helper consigo mismo. Se documenta en vez de corregirse porque ningún recurso guarda el valor crudo, y cambiarlo movería el límite efectivo de todos los endpoints a la vez.
- **No se testea `myapi_respond()`.** Es el gemelo de `myapi_error()` y tendría el mismo problema; pero, a diferencia de aquel, ningún validador puro depende de él, así que stubearlo no destrabaría nada. Se queda entero en integración.
- **`assertSame()` sobre el payload completo de usuario, no assertion por clave.** Fija además el **orden de las claves**, que es el orden que imprime `drupal_json_encode()`.
- **La tabla de umbrales de `FloodTest` está transcrita de los specs, no leída de `myapi.flood.inc`.** Es el valor entero del test: es una segunda declaración independiente de los mismos números, así que un cambio en los defaults del código hay que hacerlo dos veces a propósito en vez de una vez por accidente. Dos casos más leen los **call sites reales** con una regex y comprueban que cada `(evento, variable de límite, variable de ventana)` coincide con esa tabla, y que ninguno de los siete contadores dejó de usarse: `myapi_flood_check('myapi_reset_ip', $ip, 'myapi_flood_forgot_ip_limit', …)` está a un carácter de lo escrito hoy y subiría el límite de reset de 10/15min a 10/1h sin ningún síntoma.
- **Se asserta el ORDEN de las puertas de cada endpoint, porque no es el mismo en todos y la diferencia es deliberada.** `login` valida los campos **antes** de tocar el contador (limita también por username, así que una petición sin username no tiene contador que cobrar, y cobrarle a la IP dejaría a un crawler bloqueando una red entera); `refresh`, `logout`, `forgot` y `reset` consultan el contador **primero** (identifican por IP, así que mandar basura no puede servir para esquivar el límite).
- **Los TTL sobrescritos NO se castean, y se fija así.** `drush vset myapi_token_access_ttl 60` guarda la cadena `"60"`, que llega tal cual a `expires_in`. Los valores que importan —los defaults— son ints de verdad; que el tipo JSON de un TTL sobrescrito cambie es una cuestión de configuración en runtime, no algo que una edición deba alterar en silencio. Un spec futuro que quiera `expires_in` int siempre tiene que cambiar este test a propósito.
- **`myapi_request_body()` no se testea, pero su consecuencia sí.** Ningún test puede escribir `php://input`, así que en esta capa el body siempre llega vacío — que es exactamente lo que hace falta para ejercitar la puerta de validación de los cinco endpoints, y es la forma que manda un cliente que se porta mal.

---

## Criterios de aceptación

1. `vendor/bin/phpunit` corre en verde: **554 tests, 2462 assertions, 0 fallos** (411 tests antes de este spec). Cada archivo pasa también **en aislamiento** (`--filter <clase>`), lo que verifica que ninguno depende del orden ni deja globals sucios.
2. `RequestValidationTest` cubre las tres claves de error de los validadores (`missing_field`, `invalid_field`, `field_too_short`/`field_too_long`) y el orden en que se evalúan, assertando el envelope **real** que recibe el cliente.
3. `I18nTest` falla si se añade una clave a un solo idioma, si se duplica una clave dentro de un idioma, si una clave resuelve a vacío, o si los `@placeholder` de las dos versiones de un mensaje dejan de coincidir.
4. `AuthUserPayloadTest` falla si cambia el orden o el conjunto de las nueve claves de `data.user`, si `uid` o `roles[].uid` dejan de castearse a int, o si `roles` deja de serializarse como array JSON.
5. `ResponseEnvelopeTest` falla si el envelope cambia de forma o de orden de claves, si `message` aparece sin pedirlo, si un `error_code` deja de ser la clave estable, o si el body deja de escaparse con los flags `JSON_HEX_*`.
6. `FloodTest` falla si cualquiera de los siete umbrales cambia, si un call site usa las variables de otro evento, si un contador deja de consultarse, si `myapi_flood_check()` empieza a registrar intentos, o si el `429` deja de ser `429`.
7. `AuthEndpointGuardsTest` falla si algún endpoint deja de ser POST-only, si un método rechazado empieza a consumir contador, si `login` deja de validar antes de contar, o si `logout` deja de exigir el access token antes del body.
8. `PasswordResetPageTest` falla si el token o el mensaje de error dejan de pasar por `check_plain()`, si el deep link deja de pasar por `rawurlencode()`, si el `meta refresh` aparece en un re-render de POST, si el campo de confirmación adquiere un `name`, si un intento fallido deja de cobrarse al contador, o si alguna de las cuatro pantallas deja de ser un documento HTML completo.
9. `myapi_valid_iso_date("2026-08-06\n")` devuelve `NULL`.
10. Ningún test de esta capa toca la base de datos: la regla de SPEC 21 sigue intacta.

---

## Riesgos identificados

- **El parse del catálogo por reflexión es sensible al formato.** Si alguien reescribe el catálogo (una clave y su valor en líneas distintas, por ejemplo), el parse deja de encontrar claves. `assertNotEmpty()` cubre el caso total; un cambio de formato que afecte solo a *algunas* claves las sacaría del chequeo en silencio. Mitigación de facto: las cuatro assertions de paridad y resolución corren sobre la misma lista, así que una brecha real sigue teniendo cuatro oportunidades de aparecer.
- **La precondición de idioma depende del orden alfabético de los archivos.** Está assertada en el `setUp()` de las cinco clases que dependen de textos traducidos, no supuesta: falla con mensaje propio si deja de cumplirse.
- **El fatal por `Cannot redeclare`** descrito arriba, ahora reducido a `includes/myapi.user.inc`. Documentado en `bootstrap.php` y en `tests/README.md`; la salida de PHP apunta a la línea exacta.
- **Las dos regex que leen call sites** (`FloodTest`) y la que lee el catálogo (`I18nTest`) son sensibles al formato del código fuente. Las tres tienen un `assertNotEmpty()` de sanidad que hace que un parse que deje de matchear falle en voz alta en vez de convertir sus assertions en no-ops.
- **Los stubs de la Flood API pueden dar una falsa sensación de cobertura.** Lo que garantizan es que `myapi.flood.inc` pide lo correcto; que la tabla `{flood}` cuente bien es de Drupal y se verifica en integración. Un test de esta capa **nunca** puede decir que el sexto intento es el que corta.
- **`AuthEndpointGuardsTest` cubre hasta la primera consulta y no más.** Es fácil leerlo como "los endpoints están testeados": no lo están. Las credenciales, la rotación de tokens, el revocado y el correo siguen dependiendo enteramente de `tests/integration`, y si esa suite deja de correrse, esta no la sustituye.
- **`myapi.install` sigue sin ninguna cobertura.** Los `hook_update_N()` solo se ejecutan una vez en cada entorno y no hay nada que los verifique antes de que corran en producción.
