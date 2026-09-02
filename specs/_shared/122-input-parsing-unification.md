# 122 — Corrección de los siete hallazgos del spec 121

- **Estado:** Implemented — 7 hallazgos corregidos, 18 archivos de producción
  tocados, suite unitaria en verde (3.653 tests, 16.105 assertions; antes
  3.637 / 16.003)
- **Fecha:** 2026-09-02
- **Dependencias:**
  - `121-remaining-unit-tests` (Implemented) — es quien encontró los siete
    hallazgos y quien dejó fijado, caso a caso, el comportamiento que este spec
    cambia. **Sin esa suite este spec no se podía escribir**: toca trece
    recursos a la vez, y lo único que separa eso de una apuesta son los 633
    casos que ya cubren esos listados.
  - `73-auth-unit-tests-expansion` (Implemented) — quien añadió el modificador
    `D` al validador compartido, la corrección que las copias nunca recibieron.
  - `102-service-offers-provider-list` (Implemented) — el precedente de
    delegación (`myapi_service_request_parse_id_param()`), que es la forma que
    este spec repite seis veces.
- **Objetivo:** Corregir los siete hallazgos del spec 121 y eliminar la
  duplicación que los produjo, sin cambiar ni un byte de lo que la app ya
  consume salvo donde el hallazgo era precisamente ese byte.

---

## El problema

El spec 121 cubrió el módulo entero con tests y, al hacerlo, encontró siete
comportamientos que no eran los deseados. Los fijó con un caso con nombre propio
cada uno y no tocó producción, porque era un spec de tests. Este es el que los
corrige.

Cinco de los siete no son siete errores distintos: son **dos errores copiados**.

| | Duplicación | Copias | Consecuencia |
|---|---|---|---|
| Hallazgo 1 | El validador ISO de fecha | 6 idénticas + 8 patrones en línea | Solo la versión compartida recibió el `D` del spec 73 |
| Hallazgo 2 | El parseo de `page`/`limit` | 13 recursos, ~30 ocurrencias | Solo una de las trece llevaba la guarda `is_scalar()` |

En los dos casos **la versión correcta ya existía en el módulo** y las copias la
ignoraban. Es la regla 3 de `CLAUDE.md` incumplida dos veces, y el coste no fue
teórico: una corrección de seguridad de un spec anterior se quedó a un sexto del
código.

---

## Alcance

### Dentro de este spec

**`includes/myapi.request.inc` (modificar)** — cuatro funciones nuevas:

| Función | Qué resuelve |
|---|---|
| `myapi_is_positive_int_param($value)` | La guarda `is_scalar()` que faltaba, en un solo sitio |
| `myapi_parse_page_param()` | El `?page` de los trece listados |
| `myapi_parse_limit_param($allow_unlimited, $default, $max)` | El `?limit`, con el centinela `-1` como **parámetro** |
| `myapi_parse_optional_id_param($name)` | El hermano **lax** de `myapi_parse_id_param()` |

**13 recursos (modificar)** — `area`, `bulletin`, `claim`, `expense`,
`extra_fee`, `notification`, `payment`, `provider`, `receipt`, `reservation`,
`service_category`, `service_offer`, `service_request`: las ~30 ocurrencias del
idioma copiado pasan a llamar a los helpers.

**6 recursos (modificar)** — `receipt`, `extra_fee`, `payment`, `bulletin`,
`claim`, `reservation`: el cuerpo de su `myapi_*_valid_date()` pasa a ser una
delegación de una línea a `myapi_valid_iso_date()`.

**8 patrones anclados en línea (modificar)** — los que el barrido por nombre de
función no cubría; ver "El barrido completo" más abajo.

**Los cinco hallazgos restantes**, cada uno en su archivo.

**`docs/notification.md` (modificar)** — la única frase de documentación que
describía un comportamiento que este spec cambia.

**10 clases de test (modificar)** — los casos que fijaban el comportamiento
anterior, más 15 casos nuevos.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| `myapi_auth_bearer_token()` (`'/^Bearer\s+(\S+)$/i`) | Es el único patrón anclado del módulo al que **no** hay que añadirle la `D`. `\S+` no puede casar un salto de línea, así que un header `Bearer abc\n` extrae `abc` — que es lo correcto. Añadir la `D` lo **rechazaría**, rompiendo un cliente cuyo header lleve un espacio en blanco final por lo que sea. Comprobado y dejado como está a propósito. |
| Unificar el resto del parseo de query string (`?sort`, `?status`, `?unread`) | Usan `in_array(..., TRUE)`, que es seguro con arrays y no emite nada. No hay hallazgo que corregir; unificarlos sería un refactor sin defecto detrás. |
| El `max(1, ...)` que desaparece del clamp | No es un cambio de comportamiento: la condición ya exigía un valor `> 0`, así que nunca podía dispararse. Documentado en el docblock del helper. |
| Cambiar los defaults o los topes de algún listado | Este spec no cambia lo que responde ningún endpoint salvo donde el hallazgo era eso mismo. |

---

## El barrido completo

La primera pasada corrigió las seis funciones `myapi_*_valid_date()` — las que
el spec 121 había encontrado por nombre. Al verificar apareció que **el mismo
defecto vivía en ocho patrones más**, escritos en línea y por eso invisibles a
una búsqueda por nombre de función. Se barrió entonces por *forma*: todo
`preg_match('/^...$/')` de producción sin el modificador.

| Archivo | Qué valida | ¿Alcanzable desde la red? |
|---|---|---|
| `payment.resource.inc` ×2 | `myapi_payment_normalize_date()`, sus dos formas | No — `myapi_request_post_field()` recorta antes. Corregido igual: la función es pública y su trabajo es no depender de eso. |
| `area.resource.inc` | El `?date` de `/areas/%/availability` | **Sí**, `$_GET` no se recorta |
| `reservation.resource.inc` | La `date` del create | **Sí**, cuerpo JSON |
| `reservation.resource.inc` | El `start_time` del create | **Sí** — y el peor: se **almacenaba** con el salto de línea en una columna de ancho fijo que el módulo compara y ordena como cadena |
| `reservation.resource.inc` | `myapi_reservation_valid_time()` (`?time_from`/`?time_to`) | **Sí** — excluía en silencio la hora frontera que nombra |
| `service_transaction_admin.inc` | La fecha del formulario de back office | **Sí**, desde el formulario |
| `myapi.time_format.inc` | `MYAPI_TIME_FORMAT_PATTERN`, las horas de los formularios de nodo | **Sí**, desde el formulario |
| `reservation_calendar.inc` | `myapi_calendar_positive_int()` | Sí, y además era una **tercera** definición de "entero positivo": ahora delega |

---

## Los siete hallazgos, uno a uno

### 1. Seis copias del validador ISO + ocho patrones en línea

**Antes:** `"2026-06-01\n"` pasaba como fecha, viajaba a la consulta con el
salto incluido y **excluía en silencio el día que nombraba** (porque
`"2026-06-01\n"` ordena después de `"2026-06-01"`).

**Ahora:** las seis funciones son una delegación de una línea, con el docblock
que explica por qué el nombre sobrevive:

```php
function myapi_receipt_valid_date($value) {
  return myapi_valid_iso_date($value);
}
```

Y los ocho patrones en línea llevan la `D`. Ya no queda en producción ningún
`'/^...$/'` sin ella, salvo el de `auth.inc`, que no debe llevarla.

**Cambio de comportamiento:** una cota con salto de línea ahora se **descarta**
(el filtro no se aplica) en vez de aplicarse rota. En el create de reservas y en
los dos formularios de back office pasa a ser un rechazo explícito.

### 2. `?page[]=1` emitía un aviso de PHP en trece recursos

**Antes:** el cast `(string)` sobre un array levantaba «Array to string
conversion» antes de responder `'Array'` y caer al valor por defecto. La
respuesta era correcta; el aviso no, y con `display_errors` activo se imprime
**dentro del cuerpo JSON**.

**Ahora:** `myapi_is_positive_int_param()` comprueba `is_scalar()` primero, y
los trece recursos llaman a los dos parsers. Ninguno vuelve a escribir el
idioma a mano.

**Cambio de comportamiento:** ninguno en la respuesta. Solo desaparece el aviso.

### 3. `myapi_payment_build_created_item()` leía `field_estado_pago` sin guarda

**Antes:** la única clave anulable de ese mapper sin `isset()`. Un pago sin fila
de estado —que el detalle sí responde, a diferencia del listado— devolvía 200
con `status: null` y un aviso de propiedad indefinida por el camino.

**Ahora:** guardada como sus doce vecinas. Misma respuesta, sin aviso.

### 4. `PUT /notifications/5abc/read` marcaba la notificación 5

**Antes:** el id de la ruta se leía con un `(int)` a secas, y el cast de PHP lee
los dígitos iniciales y se detiene.

**Ahora:** `myapi_is_positive_int_param()` antes de la consulta. `5abc` es un
404 que no toca ninguna fila y no cuesta ni una consulta.

**Cambio de comportamiento:** sí, y es el único de este spec que un cliente
podría notar. No es un agujero (la condición `uid` seguía decidiendo de quién
era la fila) pero era el único id del módulo que no exigía un entero positivo.
`docs/notification.md` actualizado.

### 5. La plantilla de correo imprimía «Motivo» en una creación

**Antes:** `myapi_mail_reservation_user_html()` imprimía el motivo siempre que
se lo dieran. El correo de creación salía limpio **por el llamador**, que pasa
`''` — cinturón sin tirantes.

**Ahora:** la línea va detrás de `$cancelled`. El llamador sigue pasando `''`, y
ahora eso es redundante en vez de load-bearing.

### 6. `_myapi_reservation_full_name()` no recortaba el relleno interior

**Antes:** el `trim()` se aplicaba a la cadena ya unida, así que `' Pablo '` +
`' Cordero '` salía como `'Pablo   Cordero'` en el back office y en todos los
correos construidos con esas etiquetas.

**Ahora:** cada mitad se recorta antes de unirse, y una mitad hecha solo de
espacios ya no aporta separador.

### 7. `myapi_unit_member_uids()` devolvía strings

**Antes:** `fetchCol()` entrega lo que da el driver, y **cuatro llamadores
distintos** llevaban su propio `array_map('intval', ...)` para arreglarlo.

**Ahora:** el cast se hace en el origen, y la deduplicación ocurre **después**,
así que `'7'` y `7` colapsan en un destinatario en vez de dos. Los cuatro casts
de los llamadores pasan de load-bearing a redundantes; se dejan, porque quitarlos
es un cambio de otro spec y no cuesta nada.

---

## Verificación

### Los tests que debían fallar, fallaron

Diez casos fijaban el comportamiento anterior y fallaron en cuanto se corrigió
cada hallazgo. Ese fallo **es la verificación**: prueba que el test estaba
midiendo lo que decía medir.

Uno de ellos no es del spec 121 sino del **75**, y su autor lo había escrito
para esto, literalmente:

> *if the parse is ever hardened with an is_string() guard, this case fails and
> gets updated deliberately instead of the warning disappearing unnoticed.*

`PaginationUnlimitedTest::testAnArrayLimitIsNotTheSentinelAndWarns` pasó a
llamarse `...AndIsSilent` y a afirmar lo contrario. Es la actualización
deliberada que ese comentario pedía.

| Test | Antes afirmaba | Ahora afirma |
|---|---|---|
| `PaginationUnlimitedTest::...AndIsSilent` (×3 endpoints) | El aviso se emite | No se emite ninguno |
| `ReceiptEndpointTest::...AnswersTheDefaultSilently` | El aviso se emite | No se emite ninguno |
| `ReceiptEndpointTest::...RejectedLikeTheSharedHelperDoes` | La copia acepta el `\n` | Lo rechaza, y el filtro se descarta |
| `ExtraFeeEndpointTest::...RejectsTheTrailingNewline` | La copia acepta el `\n` | Lo rechaza |
| `PaymentEndpointTest::...ReadableByTheDetail...` | El aviso se emite | No se emite ninguno |
| `NotificationEndpointTest::...RejectedLikeEveryOtherMalformedId` | `5abc` marca la 5 | `5abc` es 404 y no toca nada |
| `MailTemplatesTest::testTheReasonLine...` | La plantilla imprime lo que le den | La creación descarta el motivo |
| `CalendarRenderTest::testTheFullNameHelper` | `'Pablo   Cordero'` | `'Pablo Cordero'` |
| `PaymentWorkflowTest::...NotifiesTheOccupantsInstead` | `['7', '8']` | `[7, 8]` |
| `SharedHelpersTest::...CondominiumMemberResolver` | `['3']` | `[3]` |

### Casos nuevos (15)

- `RequestValidationTest` (+10): los cuatro helpers nuevos, incluido el caso que
  afirma que **un array se rechaza sin emitir un aviso** y el que enfrenta el
  parser lax con el estricto sobre la misma entrada.
- `ReservationEndpointTest` (+1), `PaymentEndpointTest` (+1),
  `SharedHelpersTest` (+1), `AreaEndpointTest` y `CalendarRenderTest` (casos
  ampliados): el salto de línea en cada uno de los ocho patrones corregidos.
- `ServiceTransactionAdminTest` (+3): `myapi_service_transaction_validate_status_date()`
  no tenía **ningún** test, que es exactamente por qué su patrón conservaba el
  hueco. Encontrado por mutación (ver abajo).

### Verificación por mutación

| Mutación | Resultado |
|---|---|
| Quitar la guarda `is_scalar()` del helper compartido | 5 errores, 5 fallos |
| Quitar la `/D` de `myapi_valid_iso_date()` | 3 fallos |
| Quitar la `/D` del patrón HH:MM compartido | 1 fallo |
| Quitar la `/D` de `myapi_reservation_valid_time()` | 1 fallo |
| Quitar la `/D` del `?date` de disponibilidad | 1 fallo |
| Quitar la `/D` de `myapi_payment_normalize_date()` | 1 fallo |
| Quitar la `/D` de la fecha del back office | **sobrevivió** → hueco real, cerrado |
| Quitar la `/D` de la `date` del create de reservas | **sobrevivió** → inalcanzable en esta capa (ver abajo) |
| Revertir la delegación del calendario | 1 fallo |
| Quitar la guarda del id de ruta del buzón | 1 fallo |
| Quitar el cast de uids | 2 fallos |

**El superviviente que era un hueco real:** la fecha del formulario de back
office no tenía ningún test. Se añadieron tres casos y el mutante ahora falla.

**El superviviente inalcanzable, anotado:** la `date` del create de reservas
solo se puede alcanzar con un cuerpo JSON, y `myapi_request_body()` lee
`php://input`, que un test unitario no puede escribir (limitación heredada del
spec 121). **No es un mutante equivalente** —en producción la corrección sí
cambia el comportamiento— sino uno que esta capa no puede matar. Le corresponde
a `tests/integration/`.

**Un error de test encontrado por el camino:** el caso que se escribió para
`"2026-06-15 10:30\n"` en el validador de back office fallaba, y fallaba con
razón: esa función hace `trim()` sobre el valor completo antes de partirlo, así
que un salto de línea al final es inocuo y solo uno **interior** llega a la
mitad de la fecha. El caso estaba mal, no el código; se corrigió el caso y se
añadió la afirmación de que el valor se recorta primero.

---

## Criterios de aceptación

- [x] Los 7 hallazgos del spec 121 están corregidos.
- [x] No queda en `resources/` ni en `includes/` ninguna copia del idioma
      `ctype_digit((string) $_GET[...])`.
- [x] No queda ningún patrón `'/^...$/'` sin `D` salvo el de `auth.inc`, que no
      debe llevarla y está documentado.
- [x] Las seis copias del validador ISO son delegaciones de una línea.
- [x] Los cuatro helpers nuevos tienen sus propios tests, incluido el que
      afirma que un array se rechaza **sin emitir un aviso**.
- [x] Los diez casos que fijaban el comportamiento anterior están actualizados,
      cada uno con el porqué en su docblock.
- [x] Once mutaciones producen fallos; los dos supervivientes están resueltos —
      uno cerrando el hueco, el otro anotado como inalcanzable en esta capa.
- [x] La documentación del único endpoint cuyo contrato cambia está actualizada.
- [x] La suite unitaria completa pasa: **3.653 tests, 16.105 assertions**.
- [x] Cada clase pasa también aislada, y la suite pasa en orden aleatorio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Alcance del hallazgo 2 | Extraer dos parsers compartidos | Añadir `is_scalar()` en las 30 ocurrencias | Arreglar la copia treinta veces garantiza la copia treinta y una. La duplicación **es** el hallazgo. |
| El centinela `-1` | Un parámetro del helper | Un default | No es universal: bulletins y providers no lo tienen. Un default les habría regalado un modo sin paginar que nadie especificó. |
| El `max(1, ...)` del clamp | Eliminado | Conservado | La condición previa ya exige `> 0`, así que nunca podía dispararse. Un lector que lo ve se pregunta qué caso lo necesita. |
| Los patrones en línea | Barrer por **forma** y corregir los ocho | Corregir solo las seis funciones que el spec 121 nombró | Habría dejado el hallazgo medio resuelto, incluido el peor de todos (el `start_time` que se almacenaba con el salto de línea). |
| `auth.inc` | **No** tocarlo | Añadirle la `D` por consistencia | `\S+` no casa un salto de línea, así que el token se extrae bien; la `D` solo rechazaría un header con espacio final. Una regresión sin beneficio. |
| El id de la ruta del buzón | Estricto | Dejarlo laxo | Era el único id del módulo que no exigía un entero positivo. El cambio es visible, y por eso lleva su nota en `docs/`. |
| `myapi_calendar_positive_int()` | Delegar | Añadirle la `D` y ya | Era una tercera definición de "entero positivo". La `D` sola habría dejado la duplicación intacta. |
| El cast de uids | En el origen | En cada llamador (como estaba) | Cuatro llamadores lo hacían ya; hacerlo una vez los vuelve redundantes en vez de necesarios. |
| Los `array_map('intval')` de los llamadores | Dejarlos | Quitarlos ahora | Ya no hacen nada, pero quitarlos es ruido en un diff que ya toca 18 archivos. |

---

## Riesgos identificados

- **Dieciocho archivos de producción en un solo cambio, doce de ellos
  recursos.** El riesgo real no es cada corrección —todas son de una a tres
  líneas— sino el volumen. *Mitigación:* los 633 casos del spec 121 cubren
  exactamente esos listados de punta a punta, y cada uno de los diez tests que
  cambiaron lo hizo por una razón nombrada. Un cambio de comportamiento no
  buscado habría roto alguno de los otros 3.643.
- **Un cambio de contrato visible para un cliente**: `5abc` deja de resolver.
  *Mitigación:* documentado en `docs/notification.md`; no hay razón para que un
  cliente dependa de ello, y el 404 es la misma respuesta que ya recibía para
  cualquier otro id malformado.
- **Un hallazgo sigue sin poder verificarse en esta capa** (la `date` del create
  de reservas). *Mitigación:* anotado arriba como mutante superviviente
  inalcanzable, con su capa asignada, para que nadie lo lea como cubierto.
- **`myapi_parse_limit_param()` acepta `$default` y `$max` que hoy nadie usa.**
  Un parámetro sin llamador es un parámetro que nadie prueba en producción.
  *Mitigación:* los dos tienen su caso en `RequestValidationTest`, y existen
  porque `service_category` ya cappea distinto — si mañana un listado necesita
  otros números, la alternativa es una decimocuarta copia.
