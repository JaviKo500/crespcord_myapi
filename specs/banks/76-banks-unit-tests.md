# 76 — Tests unitarios de `GET /api/v1/banks` (spec 18)

- **Estado:** Implemented — 63 casos nuevos, suite unitaria en verde (806 tests, 3455 assertions)
- **Fecha:** 2026-08-07
- **Dependencias:**
  - `18-banks-list` (Implemented) — `myapi_bank_dispatch()`, `myapi_bank_list()`
    y `myapi_bank_build_item()` en `resources/bank.resource.inc`.
  - `05-middleware-access-token-logout` (Implemented) — el middleware
    `myapi_auth_require_access_token()` que este endpoint aplica.
  - `21-auth-testing` (Implemented) — define las tres capas de `tests/`.
  - `73-auth-unit-tests-expansion` (Implemented) — `bootstrap.php` y el helper
    `myapi_test_capture()`.
  - `74-units-unit-tests` (Implemented) — el *fixture query builder*,
    `user_load()` y `REQUEST_TIME`, que son lo que permite correr el middleware
    de access token real fuera de Drupal.
- **Objetivo:** Que las tres funciones que produjo el spec 18 tengan cobertura
  unitaria real —dispatcher, handler y mapper—, y que cada criterio de
  aceptación de ese spec esté ejercitado por un test con nombre propio en vez de
  por un `curl` manual.

---

## El problema

`specs/banks/` tiene un solo spec (18) y produjo tres funciones. **Ninguna**
tenía un test en ninguna de las tres capas:

| Función | Archivo | Cobertura antes de este spec |
|---|---|---|
| `myapi_bank_dispatch()` | `resources/bank.resource.inc` | ninguna |
| `myapi_bank_list()` | `resources/bank.resource.inc` | ninguna |
| `myapi_bank_build_item()` | `resources/bank.resource.inc` | ninguna |

La verificación del spec 18 era su paso 8: `drush cc all` + `curl` sobre los
casos de la sección de aceptación. Eso se corre una vez, el día que se
implementa.

Y el endpoint no es trivial pese a su tamaño: es el único recurso del módulo que
**no** lee una tabla sino la API de taxonomía, tiene un camino degradado que
decide entre `200 banks: []` y un fatal (`FALSE->vid`), tiene un `sort` con
whitelist estricta, y su decisión de diseño central —no ser público, porque las
descripciones llevan números de cuenta— es exactamente el tipo de cosa que se
rompe en silencio: quitar `myapi_auth_require_access_token()` no produce ningún
error, produce un catálogo bancario abierto que responde `200`.

---

## Alcance

### Dentro de este spec

- **`tests/unit/bootstrap.php`** (modificar) — dos stubs de la API de taxonomía
  y sus helpers de fixture:
  - `taxonomy_vocabulary_machine_name_load()` — responde el vocabulario sembrado
    o `FALSE`, que es lo que Drupal responde para un machine name inexistente y
    lo que dispara la rama degradada del recurso.
  - `taxonomy_get_tree()` — responde la lista de términos sembrada para ese
    `vid`, en el orden de siembra (el orden natural de peso que el recurso
    después sobreescribe).
  - `myapi_test_taxonomy_seed()` / `myapi_test_taxonomy_calls()` — sembrar
    vocabularios y leer las llamadas ejecutadas, con sus argumentos.
- **`tests/unit/BankBuildItemTest.php`** (nuevo, 19 casos) —
  `myapi_bank_build_item()`, la única función pura del recurso.
- **`tests/unit/BankEndpointTest.php`** (nuevo, 44 casos) —
  `myapi_bank_dispatch()` y `myapi_bank_list()` de punta a punta: se llama al
  dispatcher como lo llama `hook_menu()` y se asserta el JSON impreso y el
  código de estado.
- **`specs/banks/18-banks-list.md`** (modificar) — la errata del ordenamiento
  descrita abajo, como nota al inicio; el cuerpo del spec queda como quedó.
- **`tests/README.md`** (modificar) — la capa unitaria y los stubs nuevos.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| Que el vocabulario `bancos` exista en el sitio | Es configuración de Drupal, no código del módulo. Que exista lo contesta el sitio; que su ausencia devuelva `200 banks: []` en vez de un `500` sí está cubierto acá. |
| Que `taxonomy_get_tree()` arme la jerarquía, aplique peso y respete term access | Es la API de Drupal. El stub responde filas sembradas; probar la función de core sería probar el stub. |
| `hook_menu()` en `myapi.module` | Devuelve un array que solo significa algo dentro de Drupal. Que la ruta llegue al dispatcher es de integración. |
| `tests/integration/` y `tests/e2e/` para `banks` | SPEC 21 dejó esas capas acotadas a `auth`; montarlas para otro recurso es un spec propio. |
| Corregir el ordenamiento acentuado o el `sort` sobre `id` | Ver "Los hallazgos": se documentan y se fijan con un test, no se cambian. El endpoint está en producción. |

---

## Diseño: qué prueba de verdad el harness nuevo

Los dos stubs de taxonomía **no son Drupal**, y como con el `db_select()` del
spec 74 conviene declararlo antes que nada:

- **Lo que sí se ejecuta de producción:** todo el PHP del recurso. La guarda de
  método, el middleware de access token **real** (no stubeado) sobre
  `my_api_tokens` sembrada, la resolución del `sort`, la rama `=== FALSE`, el
  `array_map()`, el `usort()` con su comparador, los casts y el saneo.
- **Lo que se asserta estructuralmente:** que se pide el vocabulario `bancos` y
  no otro, y que el árbol se pide con el `vid` que acaba de resolverse. Las
  fixtures ponen `bancos` en el `vid` 2 a propósito, así que un `vid`
  hard-codeado a 1 —o un stub que ignorara el argumento— falla.
- **Lo que no se prueba acá:** que el vocabulario exista en el sitio real, ni
  nada de lo que decide la API de taxonomía por su cuenta.

### Verificación del harness (mutantes)

Un test que no falla cuando el código se rompe no prueba nada, así que se
comprobó al revés, rompiendo producción a propósito y confirmando que la suite
lo detecta:

| Mutación en producción | Resultado |
|---|---|
| Quitar `myapi_auth_require_access_token()` | 10 fallos |
| `'bancos'` → `'banco'` (machine name equivocado) | 4 errores, 20 fallos |
| Quitar el `usort()` completo | 13 fallos |
| Ignorar la dirección (`return $cmp;`) | 3 fallos |
| `strcasecmp()` → `strcmp()` | 1 fallo |
| `usort()` → `uasort()` (preserva claves → JSON objeto) | 1 fallo |
| Quitar el `(int)` del `tid` | 9 fallos |
| Quitar `check_plain()` de `description` | 5 fallos |
| Quitar `check_plain()` de `name` | 6 fallos |
| Quitar la guarda `$vocabulary === FALSE` | 1 error (fatal sobre `FALSE->vid`) |
| Dispatcher acepta cualquier método | 2 fallos |
| Dispatcher rechaza `GET` | 19 errores, 25 fallos |
| `description` vacía → `NULL` | 9 fallos |
| `taxonomy_get_tree($vocabulary->vid)` → `taxonomy_get_tree(1)` | 21 fallos |
| `200` → `201` | 8 fallos |
| Default `'asc'` → `'desc'` | 13 fallos |
| Clave `banks` → `bancos` | 19 errores, 5 fallos |
| Ordenar por `id` (el spec 18 original) en vez de por `name` | 14 fallos |

**Mutante equivalente anotado:** quitar el `TRUE` de
`in_array($_GET['sort'], ['asc', 'desc'], TRUE)` **no falla**, y es correcto que
no falle. `$_GET` solo entrega strings o arrays: `'0' == 'asc'` es `FALSE` en
PHP 7.4 y en 8.x, y un array nunca es igual a un string en comparación laxa. La
comparación estricta es la forma correcta de escribirlo, pero por HTTP no hay
ninguna entrada que distinga las dos versiones. Queda anotado para que nadie lo
lea como un hueco de cobertura.

---

## Los hallazgos

### 1. El spec 18 quedó desactualizado: `sort` ordena por `name`, no por `id`

`specs/banks/18-banks-list.md` describe `sort` como un orden sobre `id` (tabla
de parámetros, paso 3 del plan y tres criterios de aceptación). El código que
está en producción ordena **alfabéticamente por `name`**, sin distinguir
mayúsculas:

```php
usort($banks, function ($a, $b) use ($sort) {
  $cmp = strcasecmp($a['name'], $b['name']);
  return $sort === 'desc' ? -$cmp : $cmp;
});
```

El cambio entró en el commit `342b9a5` (*feat(bank): add sort param to banks list
endpoint*), que tocó `resources/bank.resource.inc` y `docs/bank.md` pero no el
spec. Es decir: **`docs/bank.md` y el código coinciden**; el spec 18 es el que
quedó atrás.

Decisión: los tests fijan lo que está en producción y lo que documenta
`docs/bank.md` —orden por `name`—, y el spec 18 recibe una nota de errata al
inicio en vez de una reescritura de su cuerpo, que es el registro histórico de
cómo se decidió el endpoint. `BankEndpointTest::testOrderFollowsTheNameAndNotTheId`
es el caso que falla si alguien vuelve al orden por `id` sin decidirlo.

### 2. Los nombres acentuados quedan al final de la lista

`strcasecmp()` compara bytes, no caracteres con collation. Un banco cuyo nombre
empieza con acento —`Ábaco`— cae **después** de todos los nombres ASCII, no
donde un lector hispanohablante lo espera:

```
Banco Amazonas, Banco Zeta, Ábaco
```

Es un detalle real de la lista que ve el usuario en la app. Se documenta y se
fija con `testAccentedInitialsSortAfterEveryAsciiName`, no se corrige: cambiar
el comparador (a `Collator` de intl, o a una normalización previa) cambia el
orden que la app ya renderiza y es una decisión de producto, no de un spec de
tests. Con el catálogo de bancos ecuatorianos actual —todos con inicial ASCII—
el efecto no se observa.

### 3. El orden se aplica sobre el texto ya saneado

El `usort()` corre después del `check_plain()`, así que compara la cadena
escapada. Para nombres que empiezan con un carácter especial de HTML el orden
resultante no es el de los caracteres crudos: `'Alfa` (`&#039;Alfa`) va antes
que `"Beta` (`&quot;Beta`), al revés de lo que darían los bytes originales.
Fijado por `testOrderIsAppliedOverTheSanitizedName`. Sin impacto práctico —
ningún banco empieza con comilla— pero es la clase de detalle que hace que dos
implementaciones "equivalentes" del orden no lo sean.

---

## Plan de implementación

1. **`bootstrap.php`** — `taxonomy_vocabulary_machine_name_load()`,
   `taxonomy_get_tree()`, `myapi_test_taxonomy_seed()` y
   `myapi_test_taxonomy_calls()`, con guarda `function_exists()` y documentados
   en el `@file` con el mismo criterio que los stubs anteriores.
2. **`BankBuildItemTest.php`** — la función pura primero: forma documentada,
   el cast, el saneo y la descripción vacía.
3. **`BankEndpointTest.php`** — dispatcher + `myapi_bank_list()` completo:
   routing, las formas de fallar un access token, los dos casos degradados, la
   respuesta llena comparada entera y el ordenamiento.
4. **Verificación por mutación** — romper producción en dieciocho puntos,
   confirmar que la suite lo detecta y anotar el mutante equivalente; revertir.
5. **`specs/banks/18-banks-list.md`** — la nota de errata del hallazgo 1.
6. **`tests/README.md`** — la descripción de la capa y los stubs nuevos.

---

## Cobertura: función → casos

### `myapi_bank_build_item()` — `BankBuildItemTest` (19 casos)

| Grupo | Casos |
|---|---|
| Forma documentada | `testReturnsExactlyTheThreeDocumentedKeysInOrder`, `testMapsATermWhole`, `testNoOtherTermPropertyIsExposed`, `testDoesNotMutateTheTerm` |
| `id` (cast `(int)`) | `testIdIsCastFromTheStringTheDatabaseAnswers`, `testIdAlreadyAnIntIsUnchanged`, `testIdDropsLeadingZeros`, `testIdKeepsLargeValues`, `testIdIsNeverNull` |
| Saneo | `testNameIsEscaped`, `testDescriptionIsEscaped`, `testBothQuoteStylesAreEscaped`, `testAmpersandIsEscapedAndAlreadyEscapedTextIsEscapedAgain`, `testAccentedTextTravelsUnchanged`, `testInvalidUtf8YieldsAnEmptyString`, `testWhitespaceAndNewlinesArePreserved` |
| Descripción vacía | `testEmptyDescriptionYieldsAnEmptyString`, `testNullDescriptionYieldsAnEmptyString`, `testDescriptionOfZeroIsKept` |

### `myapi_bank_dispatch()` — `BankEndpointTest` (4 casos)

`testEveryMethodOtherThanGetIs405`,
`testRejectedMethodTouchesNeitherDatabaseNorTaxonomy`,
`testGetIsRoutedToTheListHandler`, `testLowercaseGetIsAccepted`.

### `myapi_bank_list()` — `BankEndpointTest` (40 casos)

| Grupo | Casos |
|---|---|
| Access token | `testMissingAuthorizationHeaderIs401AndLoadsNoVocabulary`, `testMalformedAuthorizationHeaderIs401`, `testLowercaseSchemeAndTabSeparatorAreAcceptedAsBearerHeaders`, `testLowercaseSchemeWithAValidTokenIsAccepted`, `testUnknownTokenIs401InvalidToken`, `testRevokedTokenIs401`, `testExpiredTokenIs401`, `testTokenExpiringExactlyNowIsStillValid`, `testTokenOfADeletedUserIs401`, `testTokenOfABlockedUserIs401`, `testAnyAuthenticatedUserGetsTheSameCatalogue`, `testTheOnlyQueryIsTheTokenLookup` |
| Respuesta | `testFullAnswerHasTheDocumentedShape`, `testAnswerIsPrintedAsAJsonArrayWithTheDocumentedKeyOrder`, `testEveryItemHasExactlyThreeKeys`, `testTermMarkupIsEscapedInTheRealResponse`, `testTermWithoutDescriptionAnswersAnEmptyString`, `testASingleTermIsStillAList` |
| Casos degradados | `testMissingVocabularyAnswersAnEmptyListAndNeverAsksForATree`, `testEmptyVocabularyAnswersAnEmptyList`, `testEmptyListIsAJsonArray` |
| Llamadas a taxonomía | `testTheVocabularyAskedForIsBancos`, `testTheTreeIsAskedForWithTheLoadedVid`, `testTheTreeIsAskedForOnlyOnce` |
| Ordenamiento | `testDefaultOrderIsAlphabeticalAscending`, `testSortAscIsAlphabeticalAscending`, `testSortDescIsAlphabeticalDescending`, `testDescIsExactlyAscReversed`, `testAnyOtherSortValueFallsBackToAscending`, `testAnArraySortValueIsIgnored`, `testOrderIsCaseInsensitive`, `testOrderIsAppliedOverTheSanitizedName`, `testAccentedInitialsSortAfterEveryAsciiName`, `testALeadingSpaceSortsFirst`, `testAPrefixSortsBeforeTheLongerName`, `testTermsSharingANameAreBothKept`, `testSortedListIsStillPrintedAsAnArray`, `testEachItemKeepsItsOwnIdAndDescriptionAfterSorting`, `testOrderFollowsTheNameAndNotTheId`, `testALargerCatalogueComesBackFullyOrdered` |

---

## Criterios de aceptación

- [x] Las 3 funciones producidas por el spec 18 tienen tests unitarios.
- [x] Cada criterio de aceptación del spec 18 que no dependa de Drupal tiene al
      menos un caso con nombre propio.
- [x] `myapi_bank_list()` se ejerce de punta a punta, assertando el JSON impreso
      y el código de estado, no un valor de retorno.
- [x] Las formas de rechazar un access token (ausente, malformado, desconocido,
      revocado, expirado) y las dos de rechazar al usuario (inexistente,
      bloqueado) dan **401** con el `error_code` documentado, y en ninguna se
      llega a cargar el vocabulario.
- [x] `POST`/`PUT`/`DELETE`/`PATCH`/`HEAD`/`OPTIONS` dan **405** sin tocar la
      base ni la taxonomía.
- [x] Vocabulario ausente y vocabulario vacío dan **200** con `banks: []`, con
      los mismos bytes, y el ausente nunca pide el árbol.
- [x] `sort` está cubierto en sus tres formas (`asc`, `desc`, ausente) más los
      valores inválidos, incluido un array.
- [x] La suite unitaria completa pasa: **806 tests, 3455 assertions**.
- [x] Cada clase nueva pasa también aislada (`vendor/bin/phpunit --filter
      BankEndpointTest`), la regla del spec 73 contra el estado compartido.
- [x] Dieciocho mutaciones en producción producen fallos; el mutante equivalente
      está anotado.
- [x] No se modificó ninguna línea de producción (`resources/`, `includes/`).
- [x] La divergencia entre el spec 18 y el código quedó documentada en el propio
      spec 18.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Qué orden fijan los tests | El de producción y `docs/bank.md` (por `name`) | El del spec 18 (por `id`) | Los tests documentan lo que la app consume hoy. Cambiar el orden para que coincida con un spec desactualizado rompería la lista que ya se renderiza. |
| Qué hacer con el spec 18 | Nota de errata al inicio | Reescribir sus secciones de `sort` | El cuerpo del spec es el registro de cómo se decidió el endpoint; reescribirlo borraría que el criterio cambió después. |
| Stub de la API de taxonomía | Sí, con fixtures y registro de llamadas | Dejar `banks` fuera de `tests/unit` | Sin stub, las tres funciones seguían sin ninguna cobertura en ninguna capa; con él corre el recurso entero, sin copias. |
| Stub de `myapi_auth_require_access_token()` | No: corre el middleware **real** sobre `my_api_tokens` sembrada | Stubearlo y devolver una fila | La decisión central del spec 18 es que el catálogo no es público; un stub haría imposible probarla. |
| Dos clases en vez de una | `BankBuildItemTest` / `BankEndpointTest` | Un solo `BankTest.php` | Cada una tiene un tipo de assertion distinto (pura / HTTP) y un `--filter` distinto, igual que en el spec 74. |
| Orden de empate (dos bancos con el mismo nombre) | No se asserta | Fijar el orden de siembra | `usort()` es estable desde PHP 8.0 pero no en el 7.4 que corre producción; fijarlo sería fijar algo que el servidor no garantiza. |
| `check_plain(NULL)` en PHP 8 | Silenciar el `E_DEPRECATED` dentro del caso | Omitir el caso | Producción corre PHP 7.4, donde es silencioso; omitirlo dejaría sin probar que una descripción vacía no viaja como `null`. |

---

## Riesgos identificados

- **Falsa sensación de cobertura de Drupal.** Un lector apurado puede leer
  "`testTheVocabularyAskedForIsBancos` pasa" como "el vocabulario `bancos`
  existe en el sitio". No es lo mismo: el stub responde lo que la fixture
  siembra. *Mitigación:* el disclaimer está en el `@file` de `bootstrap.php`, en
  el docblock de las dos clases y en este spec.
- **El stub puede divergir de la API real.** Si el recurso empieza a usar
  `taxonomy_get_tree($vid, $parent, $max_depth, TRUE)` o
  `taxonomy_term_load_multiple()`, el harness no lo refleja. *Mitigación:* las
  llamadas se registran con **todos** sus argumentos, así que un cambio de firma
  es visible desde un test.
- **`taxonomy_get_tree()` ahora existe en toda la suite unitaria.** Antes, un
  test que la alcanzara por error moría con "undefined function"; ahora recibe
  un array vacío y sigue. *Mitigación:* se corrió la suite completa antes y
  después del cambio (743 tests en verde antes, 806 después, sin fallos), lo que
  prueba que ningún test existente dependía de ese fatal.
- **El orden acentuado puede cambiar sin aviso si alguien "arregla" el
  comparador.** *Mitigación:* está fijado por un test con nombre propio y
  explicado en su docblock, así que el cambio falla en vez de pasar
  inadvertido.
