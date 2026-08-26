# 104 — Las dos fechas de una oferta no son un rango (`POST /api/v1/service-requests/{id}/offers`)

> **Estado:** Implemented · **Depende de:** `100-service-offer-create` (Implemented) — dueña del endpoint, de `myapi_service_offer_validate_body()` y de la **regla 7** que este spec retira; `101-service-offer-on-direct` (Implemented) — el mismo endpoint y el mismo cuerpo sobre un `direct`, que hereda el cambio sin tocar nada; `78-provider-role` (Implemented) — dueña del rol que envía estas ofertas y del formulario de la app donde se escriben las dos fechas · **Fecha:** 2026-08-26
> **Objetivo:** Retirar del cuerpo del endpoint la comprobación `available_from <= valid_until`. Las dos fechas se siguen validando **cada una por su cuenta** —parseables y estrictamente futuras— y dejan de compararse entre sí.

Tres notas que la cabecera fija:

- **La regla nació de leer las dos fechas como un rango, y no lo son.** `valid_until` es el plazo que el proveedor le da al **residente** para aceptar la oferta: una fecha sobre la **decisión**. `available_from` es desde cuándo el proveedor puede empezar el trabajo: una fecha sobre la **ejecución**. Miden cosas distintas y sobre sujetos distintos, así que entre ellas no hay ningún orden obligatorio.
- **El orden que la regla prohibía es el frecuente.** Primero se acepta, después se trabaja. El caso real que hoy se rechaza es `valid_until: 2026-08-27 08:00` («necesito respuesta antes de las 8») con `available_from: 2026-08-27 11:00` («y puedo empezar a las 11»). Lo que la regla dejaba pasar —empezar antes de que caduque el plazo para aceptar— es el caso raro.
- **La app ya quitó la comprobación en cliente** (SPEC 78, formulario de ofertas). Mientras el endpoint la mantenga, esos envíos responden `422` y el proveedor ve un error genérico **sin nada que corregir**, porque no hay nada mal en su oferta. De ahí la urgencia.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.service_offer.inc`** (modificar):
  - `myapi_service_offer_validate_body()` — desaparece el bloque de la regla 7. Las reglas 8..11 pasan a 7..10; el resto de la función, sus valores y su contrato (`['ok' => …]`) no se tocan.
  - Su docblock — el párrafo de las dos fechas explica ahora por qué **no** se comparan.
- **`includes/myapi.i18n.inc`** (modificar) — se retira la clave `service_offer_dates_inconsistent` de `es` y de `en`. Ningún endpoint la responde ya.
- **`resources/service_offer.resource.inc`** (modificar) — el paso 8 del despachador pasa de «once reglas» a «diez reglas». Ni una línea de lógica.
- **`docs/service-offer.md`** (modificar) — la tabla del cuerpo, la de errores, el orden de las reglas y una nota propia que explique por qué las dos fechas no son un rango.
- **`specs/services/100-service-offer-create.md`** (anotar) — la regla 7, su párrafo, su clave i18n y su criterio de aceptación quedan marcados **⚠️ Superseded por SPEC 104**, con la convención de SPEC 40/42.
- **Tests**: `tests/unit/ServiceOfferCreateTest.php` — el test de la coherencia se **invierte** (el cuerpo que se rechazaba ahora se acepta y se comprueba que las dos fechas llegan enteras a los valores), y la clave retirada sale de la lista que se fija por nombre.

**Fuera del alcance:**

- **Las otras dos validaciones de fecha.** `valid_until` y `available_from` siguen siendo **estrictamente futuras** cada una, con el corte de SPEC 90. La app las mantiene también en cliente.
- **Caducar una oferta por su `valid_until`.** Sigue sin existir: el campo se guarda y se sirve, y nadie lo compara con hoy. Era y sigue siendo otra spec.
- **Cualquier otra regla del cuerpo.** Las diez que quedan conservan su orden y su código de error.
- **Las ofertas ya guardadas.** No hay migración: la regla solo actuaba al escribir, y todo lo almacenado la cumplía. Nada que corregir hacia atrás.

---

## Modelo de datos

**Ningún cambio.** Ni campo, ni instancia, ni catálogo, ni transición, ni `hook_update_N`. `field_offer_valid_until` y `field_offer_available_from` siguen siendo dos `datestamp` independientes, que es exactamente lo que este spec reconoce.

---

## Plan de implementación

Cuatro pasos.

1. **`includes/myapi.service_offer.inc` — la regla 7 y el docblock.**
   Se borra el `if` de la coherencia y se renumeran los comentarios de las reglas siguientes. El párrafo `THE TWO DATES MUST BE STRICTLY IN THE FUTURE` pierde la frase de la dirección y gana la razón de que no haya comparación.
   *Verificación: `php -l`; la matriz del cuerpo entera en verde con el caso invertido.*

2. **`includes/myapi.i18n.inc` — la clave retirada.**
   Las dos líneas, `es` y `en`.
   *Verificación: `php -l`; `I18nTest` (paridad del catálogo) y la lista por nombre de `ServiceOfferCreateTest` en verde.*

3. **Documentación.**
   `docs/service-offer.md` y las anotaciones de superseded en SPEC 100.
   *Verificación: lectura contra la implementación; `grep -rn "dates_inconsistent"` no devuelve nada en `includes/`, `resources/` ni `tests/`.*

4. **Tests.**
   `testTheTwoDatesAreNotComparedAgainstEachOther()` sustituye a `testTheTwoDatesMustBeCoherentInOneDirectionOnly()`.
   *Verificación: `scripts/run-unit-tests.sh`.*

**No hay instalación ni `drush updb`.** Basta `drush cc all` tras desplegar, y ni eso hace falta: no cambia ninguna ruta.

---

## Criterios de aceptación

> **Verificado el 2026-08-26, ejecutando la suite unitaria: 2291 tests, 10317 aserciones, en verde.**

- [x] `valid_until: +1h` con `available_from: +2h` (la disponibilidad **después** del plazo) → **se acepta**, y los dos valores llegan al nodo tal cual. *(el caso que motiva el spec)*
- [x] `valid_until: +2h` con `available_from: +1h` → se acepta, como antes.
- [x] Las dos en el mismo instante → se acepta, como antes.
- [x] Cualquiera de las dos sola → se acepta, como antes.
- [x] Cualquiera de las dos en el pasado, o en el instante exacto de `REQUEST_TIME` → sigue siendo `422 invalid_field` con `@field` nombrándola.
- [x] Cualquiera de las dos con formato no parseable → sigue siendo `422 invalid_field`.
- [x] `valid_until` no parseable **y** `available_from` en el pasado → responde por `valid_until`: la regla 5 sigue ganando a la 6.
- [x] Ninguna respuesta del módulo contiene ya `service_offer_dates_inconsistent`, y la clave no está en el catálogo en ningún idioma.
- [x] Las demás reglas del cuerpo responden lo mismo que antes, en el mismo orden: la matriz de SPEC 100 pasa entera sin tocar un caso.
- [x] La compuerta (`403`/`409`) sigue ganando siempre al `422` del cuerpo.
- [x] Un `direct` presupuestado (SPEC 101) hereda el cambio sin ninguna modificación propia.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| 1. Qué se hace con la regla | **Se retira entera.** | Invertirla (`valid_until <= available_from`) | Invertirla sería el mismo error con el signo cambiado: seguiría imponiendo un orden entre dos fechas que no lo tienen. Un proveedor puede perfectamente poder empezar mañana y dar de plazo hasta el viernes, y también al revés. |
| 2. La clave i18n | **Se borra del catálogo.** | Dejarla huérfana «por si acaso» | Ningún endpoint puede responderla ya, y una clave que nadie emite es una entrada que el siguiente que lea el catálogo tomará por un caso real. `I18nTest` mantiene la paridad `es`/`en` sin ella. |
| 3. Los datos ya guardados | **No se tocan.** | Un `hook_update_N` de revisión | La regla solo actuaba en la escritura y era más restrictiva que la nueva: todo lo almacenado sigue siendo válido. Un update sin nada que actualizar es riesgo sin beneficio. |
| 4. Las otras dos validaciones | **Se mantienen tal cual.** | Relajar también el «estrictamente futuro» | Son validaciones de **una** fecha contra el reloj, no de una fecha contra otra: nada en el razonamiento de este spec las alcanza. La app las conserva en cliente y el servidor sigue siendo la última palabra. |
| 5. Dónde se documenta el cambio | **Spec propio + anotaciones de superseded en SPEC 100.** | Reescribir SPEC 100 en su sitio | Es la convención del repo desde SPEC 42: el spec viejo conserva lo que decidió y apunta a quién lo cambió. Reescribirlo borraría el razonamiento original, que es justamente lo que hay que poder releer para no repetir el error. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **1. Se pierde una red contra la errata.** Un proveedor que quiera decir «hasta el viernes» y teclee un año equivocado en `available_from` ya no encuentra ningún tope. | Nunca fue una red: la regla solo cazaba erratas en una dirección, y rechazaba el caso legítimo mucho más a menudo de lo que cazaba una errata. Lo que sí queda en pie es el «estrictamente futuro» de cada fecha, que caza la errata más común (el año pasado). |
| **2. Un cliente antiguo puede seguir esperando el `422`.** Una app que mostrara un mensaje propio para `service_offer_dates_inconsistent` se queda con código muerto. | Es código muerto inofensivo: la clave nunca llega, así que la rama no se ejecuta. La app de SPEC 78 ya quitó su comprobación, que es lo que hace urgente este spec. |
| **3. La numeración de las reglas cambia (8..11 → 7..10).** Docblocks, docs y comentarios de tests que citen «la regla 8» quedan desfasados. | Se renumeran en el mismo commit —función, recurso, doc y tests—, y la doc pública nombra las reglas **por campo** y no por número, que es lo que un cliente lee. |
