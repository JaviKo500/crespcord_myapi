# 121 — Tests unitarios de todo lo que quedaba sin cubrir

- **Estado:** Implemented — 633 casos nuevos en 18 clases, suite unitaria en verde
  (3.637 tests, 16.003 assertions; antes 3.004 / 12.912)
- **Fecha:** 2026-09-02
- **Dependencias:**
  - `21-auth-testing` (Implemented) — define las tres capas de `tests/`.
  - `73-auth-unit-tests-expansion` (Implemented) — `bootstrap.php`,
    `myapi_test_capture()` y los stubs que hacen ejecutable un responder.
  - `74-units-unit-tests` (Implemented) — el *fixture query builder*.
  - `75-shared-specs-unit-tests`, `76-banks-unit-tests`, `77-claims-unit-tests`
    (Implemented) — los tres specs que fijaron el estilo que este continúa.
- **Objetivo:** Cerrar el hueco de cobertura unitaria del módulo completo: pasar
  de **63 %** de funciones ejercitadas a **80 %** por mención directa, y de **94**
  funciones *inalcanzables* desde la suite a **14**, todas ellas callbacks de la
  Form API de Drupal declaradas fuera de alcance más abajo.

---

## El problema

La suite unitaria había crecido spec a spec: `auth`, `units`, `banks`, `claims`,
la mayor parte de `services` y la aritmética de `reservations`. Todo lo demás
—ocho recursos completos y una docena de includes— no tenía **ningún** test en
ninguna de las tres capas. La medición al inicio de este spec:

| Métrica | Antes |
|---|---|
| Funciones de `includes/` + `resources/` | 731 |
| Ejercitadas (mencionadas en `tests/unit/`) | 458 (63 %) |
| Inalcanzables desde cualquier test | 94 |
| Recursos con 0 % de cobertura | 8 |

Los ocho recursos sin una sola línea de test eran, además, los que manejan
**dinero y datos personales**: pagos, recibos, alícuotas extra, gastos, el
resumen de caja del condominio, boletines, notificaciones y métodos de pago. Su
verificación era —como en el spec 18 antes del 76— una lista de `curl` que se
corre una vez, el día que se implementa.

Y el modo de fallo de todos ellos es el mismo y es silencioso: **un 200
perfectamente plausible**. Un filtro de estado que se copia con el operador
equivocado, una condición `uid` que se pierde en un `UPDATE`, un aforo que se
lee al revés — nada de eso rompe nada visible. Aparece como el recibo de otro
vecino, como la reserva de otro residente, o como un saldo que no cuadra.

---

## Alcance

### Dentro de este spec

**18 clases nuevas en `tests/unit/`**, 633 casos:

| Clase | Casos | Qué cubre |
|---|---:|---|
| `PaymentMethodEndpointTest` | 39 | `GET /payment-methods` completo (spec 19) |
| `ReceiptBuildItemTest` | 20 | El mapper de 40 claves de un recibo |
| `ReceiptEndpointTest` | 46 | `GET /units/%/receipts` completo (specs 11, 12) |
| `ExtraFeeEndpointTest` | 27 | `GET /units/%/extra-fees` (spec 13) |
| `ExpenseEndpointTest` | 30 | `GET /condominiums/%/expenses` (spec 16) |
| `CondominiumSummaryTest` | 30 | `GET /condominiums/%/summary` (spec 17) |
| `BulletinEndpointTest` | 41 | `GET /bulletins` y su regla de audiencia (specs 29, 31) |
| `BulletinNotificationTest` | 44 | El fan-out de un boletín publicado (spec 25) |
| `NotificationEndpointTest` | 40 | Los tres endpoints del buzón (specs 25, 26) |
| `PaymentEndpointTest` | 48 | Los cuatro endpoints de pagos (specs 14, 20, 23, 24) |
| `PaymentWorkflowTest` | 34 | El workflow de verificación de pagos (specs 22, 27, 30, 80) |
| `AreaEndpointTest` | 33 | Áreas: listado, detalle y disponibilidad (specs 33, 39, 40, 45) |
| `ReservationEndpointTest` | 48 | Los cuatro endpoints de reservas (specs 34, 36, 37, 38, 43, 50) |
| `ReservationNotificationTest` | 35 | Los tres eventos de notificación de reserva (specs 48, 49, 50, 54) |
| `CalendarRenderTest` | 41 | Filtros y renderizado del calendario admin (spec 47) |
| `MailTemplatesTest` | 22 | Las plantillas HTML de correo (specs 07, 48, 50, 68, 71) |
| `SharedHelpersTest` | 39 | Los helpers compartidos sin dueño |
| `BuildingAdminUserAccessTest` | 16 | El alcance de personas del admin de edificio (specs 49, 51) |

**`tests/unit/bootstrap.php` (modificar)** — el harness creció en cinco frentes,
cada uno documentado en su propio bloque dentro del archivo:

1. **El lado de escritura ahora APLICA** en vez de lanzar excepción
   (`MyapiTestWriteQuery` y sus cuatro subclases). Es lo que hace observable la
   mitad del módulo que *escribe y luego responde*.
2. **Un parser de fragmentos `where()`**, porque reservas escribe expresiones
   compuestas (`(A) OR (B AND C)`) que antes lanzaban.
3. **Agregados `SUM()` / `AVG()`** y la fila única que SQL devuelve para un
   agregado sin `GROUP BY` sobre cero filas.
4. **Lógica de tres valores de SQL** en las comparaciones: `NULL <> 'x'` es
   *unknown* y excluye la fila, como en MySQL y al revés que en PHP.
5. **Stubs nuevos**: `DrupalQueue`, `file_load_multiple()`,
   `user_load_multiple()`, `drupal_mail()`, `language_default()`,
   `valid_email_address()`, `drupal_basename()`, `user_access()`,
   `user_view_access()`, `fetchAllKeyed()` y `exists()` dentro de un grupo de
   condiciones.

**`tests/unit/ServiceRequestNotificationTest.php` (modificar)** — ocho casos
adaptados; ver "Decisiones tomadas".

**`tests/README.md` (modificar)** — la capa unitaria y los stubs nuevos.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| `POST /api/v1/reservations` más allá de su primera guarda | Toma **toda** su entrada del cuerpo JSON, y `myapi_request_body()` lee `php://input`, que un test unitario no puede escribir (limitación ya documentada por `ServiceOfferCreateTest` y `ChatNotifyTest`). En su lugar se ejercitan directamente las piezas de las que están hechas sus ocho validaciones. |
| El `reason` opcional de `PUT /payments/%/cancel` y de `PUT /reservations/%/cancel` | Mismo motivo. El validador puro de la razón sí se ejercita entero. |
| La rama de subida de archivo de `POST /payments` | `file_save_upload()` es de Drupal; stubearla sería probar el stub. Es el mismo criterio de `ServiceRequestCreateEndpointTest`. |
| `myapi_reservation_calendar_filter_form()`, su `after_build` y `myapi_reservation_calendar_page()` | Devuelven arrays de la Form API / render arrays que solo significan algo dentro de Drupal. Sus **partes** sí están cubiertas. |
| `myapi_service_transaction_delete_form()` y sus tres compañeras | Igual: formulario de confirmación + page callback. |
| `myapi_building_admin_alter_user_query()` | Un `hook_query_alter` sobre un `SelectQuery` real de Drupal; la **decisión** que aplica sí está cubierta. |
| `resources/chat.resource.inc` (`myapi_chat_token`) | Firma un JWT de Firebase con `openssl_sign()` sobre una clave de servicio; verificar la firma es de integración. |
| `tests/integration/` y `tests/e2e/` para estos recursos | El spec 21 dejó esas capas acotadas a `auth`; montarlas es un spec propio. |
| Corregir cualquiera de los hallazgos | Este es un spec de tests: **no se modificó una sola línea de `resources/` ni de `includes/`**. Los hallazgos se fijan con un caso con nombre propio y se documentan abajo. |

---

## Diseño: qué hace realmente el harness nuevo

### 1. Las escrituras ahora se aplican

Hasta este spec, `db_insert()`, `db_update()`, `db_delete()` y `db_merge()`
lanzaban una excepción, y **ese lanzamiento era la observación**: tres specs
comprueban que un hook *intentó* escribir en una tabla y ahí se detienen.

Lo que el lanzamiento hacía **imposible** es la otra mitad del módulo: un
endpoint que escribe y después **responde**.
`PUT /api/v1/notifications/%/read` es un `UPDATE` seguido del ítem que acaba de
cambiar, y su contrato entero es la **idempotencia** — una segunda llamada no
debe mover `read_at`. Eso es una afirmación sobre lo que ve la *segunda*
escritura, y no se puede observar si la primera nunca ocurrió.

Las cuatro consultas ahora aplican sobre `$GLOBALS['myapi_test_db']`, y el
descargo de `db_select()` vale palabra por palabra: **esto no es una base de
datos**. No hay tipos, ni constraints, ni transacciones, ni auto_increment más
allá de un `max+1` ingenuo, ni cascadas. Lo que se vuelve testeable es la mitad
PHP: qué filas decidió tocar un recurso, con qué valores, y qué respondió
después.

**Una divergencia deliberada respecto de `db_select()`:** una condición sobre
una columna que la fila fixture **no** tiene no coincide aquí, mientras que el
stub de lectura la omite. La laxitud es segura al leer un fixture que solo
siembra las columnas del caso; en una escritura convertiría un `UPDATE` estrecho
en "todas las filas", que es exactamente el bug que estos tests existen para
atrapar.

Cada llamada se sigue registrando en `$GLOBALS['myapi_test_db_writes']` con la
misma forma `['call', 'table']`, así que los tests que leían ese array leen lo
mismo que antes.

### 2. El fallo de escritura pasó de accidente a decisión

Seis casos de `ServiceRequestNotificationTest` usaban el lanzamiento para probar
la propiedad que de verdad importa: **el fan-out de notificaciones es best
effort**, va envuelto en un `try/catch`, y un fallo de base de datos dentro de
él no puede propagarse al 201 que el residente está esperando.

Hacer que las escrituras funcionen habría borrado esas pruebas en silencio: el
código habría tenido éxito, no se habría capturado ninguna excepción, y seis
tests verdes habrían dejado de afirmar nada.

Por eso el fallo se volvió explícito:

```php
myapi_test_db_fail_writes('myapi_notifications');
```

Es estrictamente mejor que el accidente anterior — el caso ahora **dice** qué
fallo está simulando, y el mismo interruptor cubre un `UPDATE` o un `DELETE`
fallando, que el lanzamiento indiscriminado no podía distinguir.

### 3. Un parser para los `where()` compuestos

Hasta ahora `where()` entendía una sola forma: una cota de fecha
`SUBSTR(col, 1, 10) >= :ph`. El recurso de reservas escribe tres más, y dos de
ellas son **compuestas**:

```sql
(SUBSTR(fdate.field_date_value, 1, 10) > :date_from)
  OR (SUBSTR(...) = :date_from_day AND fstart.field_start_time_value >= :time_from)
```

Ese es el refinamiento `?time_from` del spec 43 — «desde el día D a las 09:00»,
que estrecha el día frontera y deja íntegros los días posteriores — y
`myapi_reservation_has_active_reservation()` escribe la misma forma. Registrarlas
sin aplicarlas habría hecho pasar todos los tests de cota horaria sobre un
filtro que nunca corrió.

La gramática es pequeña y cerrada; cualquier cosa fuera de ella **sigue
lanzando** en vez de omitirse en silencio.

### 4. La lógica de tres valores de SQL

`NULL <> 'Nuevo'` es **unknown** en SQL, y un `WHERE` unknown excluye la fila.
PHP dice que es `TRUE`. Un stub que comparara laxamente entregaría al listado de
pagos todas las filas cuya columna de estado es `NULL`, que es precisamente lo
que su condición `<> 'Nuevo'` existe para excluir. La corrección hizo pasar dos
casos del listado de pagos que fallaban por el motivo correcto.

### 5. Verificación por mutación

Un test que no falla cuando el código se rompe no prueba nada, así que se
comprobó al revés — rompiendo producción a propósito y confirmando que la suite
lo detecta:

| Mutación en producción | Resultado |
|---|---|
| Recibos: estado `=` → `<>` | 2 errores, 17 fallos |
| Pagos: estado `<>` → `=` | 4 fallos |
| Notificaciones: quitar la guarda de idempotencia | 2 fallos |
| `read-all`: quitar la condición `uid` | 1 fallo |
| Boletines: romper la correlación del `EXISTS` | 4 fallos |
| Boletines: quitar la guarda de tipo `Personalizado` | **sobrevivió** → hueco real, cerrado (ver abajo) |
| Workflow: el descuento se vuelve suma | 3 fallos |
| Workflow: nunca notificar a los ocupantes | 1 fallo |
| Áreas: un área oculta responde 403 en vez de 404 | 1 fallo |
| Reservas: cualquiera puede cancelar | 2 fallos |
| Reservas: la ventana de cancelación nunca cierra | 2 fallos |
| Notificaciones: quitar las filas de todo el condominio de un scope de vivienda | 1 fallo |
| Reservas: ampliar el correo de cancelación a los admins de edificio | 1 fallo |
| Cola de correo: reintentar para siempre | 1 fallo |
| Archivos: dejar de quitar los caracteres de control | 1 fallo |

**El mutante que sobrevivió era un hueco real.** Quitar
`ftipo.field_tipo_de_boletin_value = 'Personalizado'` de esa rama dejaba los 40
casos en verde, porque ninguno tenía un lector referenciado en un nodo de **otro**
tipo. Sin la guarda, estar referenciado en cualquier parte abriría un boletín sin
importar su audiencia. Se añadió
`testTheReferenceOnlyOpensBulletinsOfThePersonalizadoType`, y el mutante ahora
falla.

**Mutante equivalente anotado:** quitar la guarda `$row->total !== NULL` de
`myapi_condominium_expense_totals()` **no falla**, y es correcto que no falle:
`(float) NULL` es `0.0` en PHP, así que las dos versiones responden el mismo
número. La guarda sigue siendo la forma correcta de escribirlo —dice lo que
quiere decir— pero no hay entrada que distinga las dos versiones.

---

## Los hallazgos

> **Nota (spec 122):** los siete quedaron **corregidos** en
> `specs/_shared/122-input-parsing-unification.md`, que además encontró ocho
> instancias más del hallazgo 1 escritas en línea. Las descripciones de abajo
> son las del estado que este spec encontró y fijó; los casos que las fijaban
> están actualizados en el 122, cada uno con su porqué.

Ninguno se corrigió aquí: este es un spec de tests. Cada uno quedó **fijado por
un caso con nombre propio**, de modo que arreglarlo fuera una decisión y no una
sorpresa — que es exactamente lo que ocurrió.

### 1. Seis copias del validador ISO de fecha, y solo la compartida tiene la `D`

El spec 73 añadió el modificador `D` a `myapi_valid_iso_date()`
(`includes/myapi.request.inc`) para que `"2026-08-06\n"` dejara de pasar como
fecha: sin él, PCRE deja que `$` case justo antes de un salto de línea final.

Los recursos de **recibos, alícuotas extra, pagos, boletines, reclamos y
reservas** llevan cada uno su **propia copia** de ese validador —byte por byte
idéntica al helper compartido salvo por el modificador— y ninguna de las seis lo
recibió. Consecuencia observable: una cota con salto de línea final se
acepta, viaja a la consulta con el salto incluido, y **excluye en silencio el
día que nombra** (porque `"2026-06-01\n"` ordena después de `"2026-06-01"`).

- Fijado por
  `ReceiptEndpointTest::testATrailingNewlineIsStillAcceptedByThisResourcesOwnValidator`
  y `ExtraFeeEndpointTest::testTheValidatorAcceptsRealDatesAndTheTrailingNewline`.
- Y la otra mitad, fijada al lado:
  `ExpenseEndpointTest::testATrailingNewlineBoundIsRejectedBecauseTheSharedParserIsUsed`
  — gastos **sí** usa el helper compartido y por eso se comporta bien.
- Es además la regla 3 de `CLAUDE.md` incumplida: el helper compartido ya existe.
  Unificar las seis copias es un spec propio, y el patrón de la corrección ya
  está en el módulo: `myapi_service_request_parse_id_param()` es una delegación
  de una línea a `myapi_parse_id_param()`.

### 2. `?page[]=1` emite un aviso de PHP dentro de la respuesta

**Los trece recursos paginados del módulo** —recibos, alícuotas extra, pagos,
gastos, boletines, notificaciones, reservas, áreas, reclamos, categorías de
servicio, proveedores, ofertas y solicitudes de servicio— leen la paginación
así:

```php
isset($_GET['page']) && ctype_digit((string) $_GET['page'])
```

Con un array, el cast a string emite **«Array to string conversion»** (aviso en
el PHP 7.4 de producción, warning en versiones nuevas) antes de responder
`'Array'`, que no son todo dígitos y cae al valor por defecto. Es decir: la
**respuesta** es correcta y la petición nunca se rechaza, pero se emite un aviso
en mitad de la petición, y en un sitio con `display_errors` activo se imprimiría
**dentro del cuerpo JSON**.

`myapi_parse_id_param()` guarda con `is_scalar()` primero exactamente por esto;
los listados nunca recibieron esa guarda. Son ~30 ocurrencias del mismo idioma
copiado (`page` y `limit` en los trece, más `condominium` y `unit` en el buzón),
que es el mismo incumplimiento de la regla 3 que el hallazgo 1.

- Fijado por `ReceiptEndpointTest::testAnArrayPageOrLimitAnswersTheDefaultAndEmitsANotice`.
- Y el contraste, al lado:
  `SharedHelpersTest::testAnArrayParameterIsRejectedWithoutANotice`.

### 3. `myapi_payment_build_created_item()` lee `field_estado_pago` sin guarda

Todas las claves anulables de ese mapper van detrás de un `isset()` menos una:

```php
'status' => $node->field_estado_pago[LANGUAGE_NONE][0]['value'],
```

`GET /api/v1/payments/%` sobre un pago sin fila de estado responde **200 con
`status: null`** y emite un aviso de propiedad indefinida por el camino.

- Fijado por
  `PaymentEndpointTest::testAPaymentWithNoEstadoIsReadableByTheDetailButNotByTheListing`,
  que además documenta la **divergencia** que lo acompaña: ese mismo pago es
  invisible en el listado (join interno) y legible en el detalle (comparación
  contra el valor exacto excluido).

### 4. `PUT /api/v1/notifications/5abc/read` marca la notificación 5

El id de la ruta se lee con un `(int)` a secas y no con `ctype_digit()`, y el
cast de PHP lee los dígitos iniciales y se detiene. No es un agujero —el alcance
por `uid` sigue decidiendo de quién es la fila, y el 404 de una notificación
ajena no cambia— pero es el comportamiento real de la ruta.

- Fijado por `NotificationEndpointTest::testANumericPrefixResolvesToThatId`, que
  comprueba las dos mitades: que resuelve, y que el alcance por `uid` aguanta.

### 5. El correo de reserva imprime «Motivo» si se lo dan, sea creación o no

`myapi_mail_reservation_user_html()` no filtra la línea de motivo por la
variante: la imprime siempre que reciba una. Hoy el correo de creación sale
limpio **por el llamador** (`myapi_reservation_enqueue_user_mail()` pasa `''`),
no por la plantilla.

- Fijado por `MailTemplatesTest::testTheReasonLineAppearsOnlyWhenThereIsOne`,
  con las dos mitades una al lado de la otra.

### 6. `_myapi_reservation_full_name()` no recorta el relleno interior

El `trim()` se aplica a la cadena **ya unida**, así que un perfil con
`' Pablo '` + `' Cordero '` produce `'Pablo   Cordero'`. Ningún perfil real lo
lleva, y cambiarlo alteraría el texto que el back office ya imprime.

- Fijado por `CalendarRenderTest::testTheFullNameHelper`.

### 7. `myapi_unit_member_uids()` devuelve strings

Los uids salen tal cual los entrega el driver; `myapi_notification_create()` es
quien hace el `intval()` antes del insert. Un llamador que los compare con `===`
se llevaría una sorpresa.

- Fijado por `PaymentWorkflowTest::testAnAdministratorAuthorNotifiesTheOccupantsInstead`.

---

## Plan de implementación

1. **Medir el hueco de verdad.** La cuenta por mención de nombre sobre-reporta
   (una función ejercitada de punta a punta a través de su dispatcher no aparece
   citada), así que se construyó además un grafo de llamadas y se midió la
   *alcanzabilidad*. Las dos cifras acotan el problema por arriba y por abajo.
2. **De lo simple a lo complejo**, que es también de menos a más harness nuevo:
   catálogo de métodos de pago → recibos → alícuotas extra → gastos → resumen de
   condominio → boletines → notificaciones → pagos → áreas → reservas →
   calendario → correos → helpers sueltos.
3. **Extender el bootstrap solo cuando el código lo exigió**, y documentarlo en
   el mismo archivo con el criterio de los specs 74/76/79: un `SUM()` no
   soportado **lanza** hasta que alguien lo implementa a conciencia, nunca
   responde un número plausible.
4. **Verificación por mutación** sobre quince puntos de producción; cerrar el
   hueco que encontró y anotar el mutante equivalente; revertir todo.
5. **`tests/README.md`** — la capa, los stubs nuevos y el nuevo alcance.

---

## Cobertura: qué se fijó, recurso por recurso

### `GET /api/v1/payment-methods` — `PaymentMethodEndpointTest` (39)

Gemelo de `banks` con **una regla que banks no tiene**: los términos se hidratan
en una segunda llamada por lotes y un método sin `field_tipo_pago` se **excluye**
del catálogo. Ambas mitades fallan en silencio — sin hidratar, la app recibe una
lista de métodos sin tipo; sin filtrar, se le ofrece un método con el que no
puede registrar un pago.

Grupos: routing (4), guarda de access token (8), forma de la respuesta (4),
caminos degradados (4), llamadas a taxonomía (3), la regla `type_method` (6),
casts y saneo (4), ordenamiento y `sort` (6).

### `GET /api/v1/units/%/receipts` — `ReceiptBuildItemTest` (20) + `ReceiptEndpointTest` (46)

El mapper más ancho del módulo: 40 claves, 32 de ellas decimales. La regla de
toda la clase es que **un campo con fila es un float y un campo sin fila es
null**, sin término medio: la app pinta un null como raya y un 0.0 como
«$0.00».

El endpoint cubre además el filtro de estado en lista y en cuenta, el acceso por
propietario/ocupante (con las tres relaciones), la forma de la consulta, la
paginación completa, el orden y el rango de fechas con sus cotas inclusivas.

### `GET /api/v1/units/%/extra-fees` — `ExtraFeeEndpointTest` (27)

El gemelo de recibos, y **no** un data provider sobre él: `details` es `""`
cuando falta, donde todo lo demás del mismo ítem es `null`. Un test compartido
habría tenido que escribir esa excepción, y una excepción en un test compartido
es donde se esconde una divergencia real.

### `GET /api/v1/condominiums/%/expenses` — `ExpenseEndpointTest` (30)

La primera lista con alcance de **condominio** en vez de vivienda, y por tanto
la primera con una resolución en **dos pasos** — las viviendas del usuario, y
después los condominios de esas viviendas. Cada paso es una decisión de acceso,
y ambos se rompen por separado en `testBothStepsOfTheResolutionAreRequired`.

### `GET /api/v1/condominiums/%/summary` — `CondominiumSummaryTest` (30)

El único endpoint que **agrega**. Tres propiedades quedan fijadas aquí y en
ningún otro sitio: que `total` y `count` no tienen por qué cuadrar 1:1 (el join
a `field_valor` es LEFT), que un conjunto vacío es `0.0` y no `null`, y que
`cash_balance` es `null` cuando no se registró y `0.0` solo cuando una fila dice
cero.

### `GET /api/v1/bulletins` — `BulletinEndpointTest` (41)

La regla de lectura más difícil del módulo y el único listado **sin 403**: la
audiencia va dentro de la consulta, así que un lector que no puede ver nada
recibe una lista vacía. Se recorre la rejilla 3×3 completa de «rol que se tiene
× audiencia a la que va dirigido», las tres ramas del cruce y las dos
sub-consultas `EXISTS` correlacionadas, más el filtro `?condominium_id` del spec
31 con su 422 y su 403.

### El fan-out de un boletín — `BulletinNotificationTest` (44)

La otra mitad de esa misma regla: quién recibe una fila en `myapi_notifications`
cuando el boletín se publica. Varios casos están escritos como **espejo** de un
caso de `BulletinEndpointTest`, porque la consulta de visibilidad y el resolutor
de destinatarios son código separado escrito desde la misma tabla, y una
divergencia significa notificar a alguien sobre un boletín que no puede abrir.

Cubre también `myapi_notification_plain_text()` entero (la conversión de un
WYSIWYG a la línea de un banner) y el troceado de ids externos por el límite de
OneSignal.

### El buzón — `NotificationEndpointTest` (40)

Los primeros endpoints de la suite que **escriben y después responden**, y el
motivo por el que el lado de escritura del fixture pasó a aplicar. Idempotencia
del marcado, contador de `read-all`, alcance por `uid` en las tres consultas, y
el filtro condominio/vivienda del spec 26 con su regla de visibilidad (una fila
sin contexto es más amplia, no está oculta).

### Los cuatro endpoints de pagos — `PaymentEndpointTest` (48)

Cuatro formas distintas: un listado cuyo filtro de estado es por **exclusión**,
un detalle que oculta por estado **antes** de comprobar el acceso, un create
multipart con doce validaciones ordenadas y una transición de estado guardada.

### El workflow de verificación — `PaymentWorkflowTest` (34)

El único sitio del módulo donde un bug **cuesta dinero**. Los tres detectores de
transición (con la precisión que exige que el mismo `node_save` pueda venir de
la app, del formulario o del auto-save de Rules), la aritmética de los dos
saldos, las cuatro tareas de `rules_scheduler` identificadas por el nid de la
vivienda, y la resolución de destinatarios que redirige a los ocupantes cuando
el autor es un administrador.

### Áreas — `AreaEndpointTest` (33)

La propiedad que comparten sus tres endpoints: **un área que no es visible no se
revela en ninguna parte**. Cinco motivos distintos, una sola respuesta 404.
Cubre además el aforo normalizado (siempre int, siempre ≥ 1) y las dos reglas
opuestas de `busy` según la capacidad.

### Reservas — `ReservationEndpointTest` (48)

El listado más privado del módulo: acotado a la vivienda **y** al solicitante
(spec 37). Y el write más estricto: solo el `field_requester` exacto puede
cancelar, solo una reserva `confirmed`, y solo antes del plazo del área — cada
uno con su propio código, en orden fijo.

### Las notificaciones de reserva — `ReservationNotificationTest` (35)

Tres eventos y tres audiencias distintas, incluida la contraintuitiva: la
cancelación que hace el propio residente **no le notifica a él** y sí a los
operadores. Cada «quién NO recibe esto» tiene su assertion, porque el modo de
fallo es silencioso.

### El calendario admin — `CalendarRenderTest` (41)

Era el archivo más grande sin cubrir: 31 funciones de las que
`ReservationCalendarTest` tocaba seis. Se cubren los filtros (incluido el
descarte de un `?area` que no pertenece al `?condominium` elegido) y el HTML,
con especial atención al **escapado**: es texto escrito por operadores
interpolado a mano, sin capa de theme en medio.

### Las plantillas de correo — `MailTemplatesTest` (22)

La única salida del módulo que nadie vuelve a mirar. Tres propiedades: la
cabecera `Content-Type` es la funcionalidad entera, los valores llegan **ya
escapados** (y las dos excepciones deliberadas), y el asunto se **decodifica**
porque no es HTML.

### Los helpers compartidos — `SharedHelpersTest` (39) + `BuildingAdminUserAccessTest` (16)

Los archivos que no tiene nadie: demasiado pequeños para un spec, demasiado
compartidos para que el test de un llamador trate sobre ellos. Entre ellos
deciden si una hora malformada llega a la base, si se puede forjar una cabecera
con un nombre de archivo, si la notificación de un recibo llega, y si un correo
fallido se reintenta o se descarta.

---

## Criterios de aceptación

- [x] Los 8 recursos que estaban a 0 % tienen tests unitarios de punta a punta.
- [x] Cada uno se ejerce llamando a su **dispatcher** como lo llama `hook_menu()`,
      y se asserta el JSON impreso y el código de estado, no un valor de retorno.
- [x] Las formas de rechazar un access token dan **401** en cada endpoint nuevo, y
      en ninguna se llega a leer el dato que protegen.
- [x] Los 403/404 no reveladores responden **los mismos bytes** para «no existe» y
      «no es tuyo», en pagos, áreas, reservas, notificaciones y boletines.
- [x] La idempotencia de las dos rutas de marcado está probada como **secuencia**
      y no como dos fixtures.
- [x] La aritmética del workflow de pagos (dos saldos, cuatro tareas) tiene un
      caso por precondición fallida, y todas dejan el nodo intacto.
- [x] La rejilla 3×3 de la audiencia de boletines está recorrida entera, en
      lectura y en fan-out.
- [x] El renderizado del calendario escapa todo valor escrito por un operador.
- [x] Cada formatter de correo fija su cabecera `text/html`.
- [x] Quince mutaciones en producción producen fallos; la que sobrevivió reveló
      un hueco real, que se cerró; el mutante equivalente está anotado.
- [x] Cada clase nueva pasa también aislada (`vendor/bin/phpunit --filter <Clase>`),
      la regla del spec 73 contra el estado compartido.
- [x] **No se modificó ninguna línea de producción** (`resources/`, `includes/`,
      `myapi.module`, `myapi.install`).
- [x] La suite unitaria completa pasa: **3.637 tests, 16.003 assertions**.
- [x] Funciones ejercitadas: de 458/731 (63 %) a **586/731 (80 %)** por mención
      directa; inalcanzables desde la suite: de **94 a 14**, todas declaradas
      fuera de alcance arriba.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| El lado de escritura del fixture | Aplicarlo sobre las fixtures | Seguir lanzando | Sin él, la mitad del módulo que escribe y responde es inobservable: idempotencia, contadores de filas afectadas, y todo 201 que relee lo que creó. |
| Los seis casos que dependían del lanzamiento | Adaptarlos a `myapi_test_db_fail_writes()` | Dejarlos verdes sobre un fallo que ya no ocurre | Habrían pasado sin afirmar nada. El fallo explícito además **dice** qué está simulando. |
| Laxitud de las condiciones en una escritura | Estricta (una columna ausente no coincide) | La misma laxitud que en lectura | En una escritura, la laxitud convierte un `UPDATE` estrecho en «todas las filas». |
| `SUM()` sobre cero filas | `NULL`, como SQL | `0` | Es lo que hace alcanzable la rama `!== NULL` del resumen de condominio. Con `0` ese test pasaría por el motivo equivocado. |
| `NULL <> 'x'` | `FALSE` (unknown excluye), como SQL | `TRUE`, como PHP | Sin esto, el listado de pagos devuelve las filas que su condición existe para excluir. |
| Un parser de `where()` | Sí, con gramática cerrada | Una tercera y una cuarta regex | Cada forma nueva iba a añadir otra; y una forma no reconocida **debe** lanzar, no evaporarse. |
| Recibos y alícuotas extra | Dos clases | Un data provider compartido | Divergen en una regla (`details` vacío) y una excepción dentro de un test compartido es donde se esconde una divergencia real. |
| `POST /reservations` | Probar su primera guarda y, aparte, las piezas de sus ocho validaciones | Stubear `php://input` | La limitación ya está documentada por tres clases previas; inventar un stub del cuerpo cambiaría lo que se está probando. |
| Los hallazgos | Fijarlos con un test y documentarlos | Corregirlos aquí | Este es un spec de tests, y cinco de los siete cambian bytes que la app ya consume. Cada uno merece su propia decisión. |
| Los callbacks de la Form API | Fuera de alcance, con sus partes cubiertas | Stubear la Form API | Devolver un array que solo significa algo dentro de Drupal; probarlo aquí sería probar el stub. |

---

## Riesgos identificados

- **El fixture de escritura no es una base de datos.** Un lector apurado puede
  leer «el `UPDATE` pasa» como «MySQL lo acepta». No hay tipos, constraints ni
  transacciones. *Mitigación:* el descargo está en el bloque de
  `MyapiTestWriteQuery`, en el docblock de las clases que escriben, y aquí.
- **Las escrituras ahora llegan más lejos que antes.** Código que antes moría en
  su primer `db_insert()` ahora corre hasta el final, lo que descubrió tres
  funciones de Drupal sin stub (`DrupalQueue`, `user_load_multiple()`,
  `valid_email_address()`). Puede haber más en caminos que ningún test recorre
  todavía. *Mitigación:* la suite completa se corrió después de cada extensión
  del bootstrap; cualquier función que falte se manifiesta como un error
  inmediato y no como un verde falso.
- **Siete comportamientos quedan fijados tal como están, y dos de ellos son
  bugs menores.** Un lector puede tomar el test por una aprobación.
  *Mitigación:* cada uno lleva en su docblock por qué está fijado y no
  corregido, y este spec los lista.
- **La cobertura por mención de nombre sobre-reporta y la de alcanzabilidad
  sub-reporta.** Ninguna de las dos es cobertura de líneas: el entorno no tiene
  Xdebug ni PCOV. *Mitigación:* se reportan las dos cifras, y la verificación por
  mutación es lo que de verdad respalda el número.
