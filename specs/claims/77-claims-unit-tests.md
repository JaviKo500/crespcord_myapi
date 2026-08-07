# 77 — Tests unitarios del dominio de reclamos (specs 55 a 71)

- **Estado:** Implemented — 255 casos nuevos, suite unitaria en verde (1061 tests, 4369 assertions)
- **Fecha:** 2026-08-07
- **Dependencias:**
  - Los 17 specs de `specs/claims/` (55 a 71), que produjeron el dominio entero.
  - `21-auth-testing` (Implemented) — define las tres capas de `tests/`.
  - `73-auth-unit-tests-expansion` (Implemented) — `bootstrap.php`, `myapi_test_capture()`.
  - `74-units-unit-tests` (Implemented) — el *fixture query builder*, `user_load()`
    y `REQUEST_TIME`, que son lo que permite correr el middleware de access token
    real fuera de Drupal.
  - `76-banks-unit-tests` (Implemented) — el precedente inmediato: stubs de una
    API de Drupal (taxonomía) con registro de llamadas.
- **Objetivo:** Que el código que produjeron los 17 specs de reclamos —el
  dominio más grande del módulo, ~100 funciones en seis archivos— tenga
  cobertura unitaria **funcional**: no assertions sobre helpers puros sueltos,
  sino los endpoints y las páginas corriendo de punta a punta sobre fixtures,
  con el middleware real, las queries reales y el JSON impreso como assertion.

---

## El problema

`specs/claims/` es el dominio más grande del proyecto y el que más superficie
sensible tiene: es el único donde un residente escribe (crear, editar, cerrar),
el único con archivos privados servidos por dos consumidores distintos, y el
único cuya regla de visibilidad decide qué ve un vecino de lo que escribió otro.

Antes de este spec había ocho clases de test en `tests/unit` con nombre de
reclamos, y todas cubrían la misma mitad: **los textos**. Las funciones que
deciden *qué* se muestra, *a quién* y *si se guarda* no tenían ninguna cobertura
en ninguna capa:

| Archivo | Funciones | Con test antes |
|---|---|---|
| `resources/claim.resource.inc` | 29 | 6 (solo parseo de query string) |
| `includes/myapi.claims_files.inc` | 4 | **0** |
| `includes/myapi.claim_query.inc` | 1 | **0** |
| `includes/myapi.claims_admin.inc` | 10 | **0** |
| `includes/myapi.claim_transaction_admin.inc` | 19 | 6 |
| `includes/myapi.claim_notification.inc` | 35 | 17 (los textos) |

La razón estaba escrita, una y otra vez, en los propios docblocks: *"db_select()
y Field API, es decir exactamente lo que tests/unit evita"*
(`ClaimNotificationTest`), *"no son puras en el sentido que exigen los archivos
de tests/unit, y esta página no tiene ninguno"*
(`includes/myapi.claims_admin.inc`), *"node_load() + node_access() + l(), tres
llamadas a Drupal"* (`ClaimTransactionEditTest`).

Esa razón dejó de ser cierta con el spec 74 —que metió un *fixture query
builder* en la capa— y termina de caer acá, con cuatro stubs más. Ninguno de
esos docblocks estaba equivocado cuando se escribió; lo que cambió es el
harness, no el criterio.

**El costo de dejarlo así no era teórico.** La regla de visibilidad de
`myapi_claim_base_query()` —un solo `db_or()`— es lo único que separa a un
residente de los reclamos privados de sus vecinos. La comprobación de
pertenencia de `myapi_claim_file_download()` es lo único que impide pedir
`/claims/140/files/9` con el `fid` de otro edificio. El `403 claim_edit_denied`
es lo único que impide editar el reclamo de otro. Los tres fallan **en
silencio**, con HTTP 200 y un cuerpo perfectamente plausible.

---

## Alcance

### Dentro de este spec

- **`tests/unit/bootstrap.php`** (modificar) — el harness que faltaba:
  - `db_or()` / `db_and()` y la clase `MyapiTestConditionGroup`, más el soporte
    de grupos en `MyapiTestSelectQuery::condition()`. Sin esto la query base de
    reclamos ni siquiera se construye.
  - `addTag()`, `extend()` y `limit()` en el query builder — el `PagerDefault`
    y el `node_access` del listado de back office.
  - Resolución **calificada** de columnas (`'fs.field_status_value'`) en
    condiciones, orden y proyección: ver "El hallazgo del harness".
  - `field_info_field()` / `field_info_instance()` + `myapi_test_field_seed_allowed_values()`.
  - `node_load()`, `file_load()`, `node_access()`, `l()`, `form_error()`,
    `drupal_static()` (reseteable), `file_transfer()` (termina el request).
  - `node_save()`, `node_object_prepare()`, `file_usage_add()`,
    `file_usage_delete()`, `file_delete()` como **registradores**, más
    `myapi_test_write_reset()` y los tres lectores de lo registrado.
  - `LANGUAGE_NONE`, y `url()` pasa a honrar `'absolute'` (dejó de ser
    inalcanzable: todas las URLs de archivo de la API se construyen con él).
- **`tests/unit/ClaimEndpointTest.php`** (nuevo, 70 casos) — `GET /api/v1/claims`
  y `GET /api/v1/claims/%` de punta a punta.
- **`tests/unit/ClaimWriteGuardsTest.php`** (nuevo, 40 casos) — las tres rutas de
  escritura y su secuencia de guardas.
- **`tests/unit/ClaimFileAccessTest.php`** (nuevo, 42 casos) —
  `includes/myapi.claims_files.inc` completo y `GET /api/v1/claims/%/files/%`.
- **`tests/unit/ClaimNotificationRowTest.php`** (nuevo, 44 casos) — la fila
  equivalente, los detectores, la audiencia y los params de correo.
- **`tests/unit/ClaimsAdminPageTest.php`** (nuevo, 30 casos) — el listado de back
  office y su query.
- **`tests/unit/ClaimTimelineTest.php`** (nuevo, 29 casos) — la línea de tiempo
  de transacciones y el validador de fecha de estado.
- **`tests/README.md`** (modificar) — la capa nueva y los stubs.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| El camino feliz de **subida de archivos** (`myapi_claim_create_save_files()`, `myapi_claim_create_delete_files()`) | Es `file_save_upload()`, `finfo_file()` sobre bytes reales y `file_prepare_directory()`. Sin disco ni `$_FILES` real no se prueba nada de lo que importa (que la extensión y el MIME coincidan). Todos los casos mandan cero archivos, que es la forma que entra y sale de esa función sin tocar Drupal. |
| El camino feliz de **`PUT /claims/%/close`** (la transacción de cierre) | `myapi_request_body()` lee `php://input` y **memoriza en un static**: un test no puede escribir ese stream ni resetear el memo. Lo que sí se cubre son las seis guardas anteriores y el `422 missing_field` con cuerpo vacío; el validador en sí ya está cubierto entero por `ClaimCloseReasonTest`. Ver "Los hallazgos". |
| Los **cuatro orquestadores** de notificación (`myapi_claim_notify_created/published/transaction/closed_by_requester`) y sus dos helpers de canal | Escriben en `myapi_notifications` y en la cola de correo a través de includes que esta capa no carga. Lo que componen —los textos y la fila— está cubierto acá y en `ClaimNotificationTest`. |
| `myapi_claims_list_page()`, `myapi_claims_list_filter_form()`, `myapi_claim_transaction_create_form()`, `myapi_claim_transaction_add_page_callback()` | `drupal_get_form()`, `theme('pager')`, `drupal_add_css()`: el pipeline de render de Drupal, no una decisión nuestra. Lo que ensamblan está cubierto pieza por pieza. |
| `myapi.install` (`_myapi_claims_install()`, `myapi_update_7017`…) | Crea content types y campos. Es `tests/integration` o nada. |
| `hook_node_insert/update/presave`, `hook_node_access`, `hook_query_alter` | Son la **invocación** de Drupal. Que `node_save()` dispare la transacción inicial o que el tag `node_access` recorte el listado por condominio solo lo contesta un sitio. |
| Que el SQL devuelva estas filas contra el esquema real | El fixture aplica condiciones sobre las columnas que la fila trae y **registra los joins sin resolverlos**. Esa línea es la que separa esta capa de `tests/integration`, y está declarada en el `@file` de `bootstrap.php` y en el docblock de cada clase nueva. |

---

## Diseño: qué prueba de verdad el harness

El criterio es el del spec 74, extendido:

- **Lo que corre de producción:** el middleware de access token **real** sobre
  `my_api_tokens` sembrada, la resolución de condominios del lector, la query
  base con su `db_or()`, los tres fetchers, los serializadores, las 20 guardas
  de las tres rutas de escritura, la resolución de propiedad de un archivo, la
  regla de rol/condominio, los filtros del back office y el armado de las dos
  tablas. Sin copias.
- **Lo que se asserta estructuralmente:** que la query de reclamos **no** lleva
  `->addTag('node_access')` y que la del back office **sí**; que la regla de
  visibilidad es **un solo grupo OR** y no dos condiciones sueltas; el
  `ORDER BY` + `range(0,1)` de la resolución de propiedad; el `PagerDefault`.
  Son exactamente los puntos donde un cambio silencioso abre datos.
- **Lo que NO se prueba:** la semántica del motor, la persistencia
  (`node_save()` es un registrador), la entrega de bytes (`file_transfer()`
  también), y la resolución de `node_access()` de Drupal (fixture).

### Verificación del harness (mutantes)

26 mutaciones deliberadas en producción, **las 26 detectadas**:

| Mutación | Resultado |
|---|---|
| Quitar el grupo `db_or()` de visibilidad | 7 fallos |
| `db_or()` → `db_and()` | 9 errores, 21 fallos |
| Quitar el `IN` de condominios del lector | 4 fallos |
| Quitar `n.status = 1` | 2 fallos |
| Quitar el desempate por `nid` del orden | 1 fallo |
| Quitar la `'T'` de `reception_date` | 3 fallos |
| Quitar el `(int)` de `build_item()` | 1 fallo |
| Quitar el `(int)` de las transacciones | 2 fallos |
| Ignorar `?include=transactions` | 2 errores, 2 fallos |
| Ignorar el `owner_map` de las imágenes | 1 fallo |
| Leer `field_requester` del request | 1 fallo |
| No forzar `field_status = 'received'` al crear | 1 error |
| Quitar el `403 claim_edit_denied` | 2 fallos |
| Quitar el `409 claim_not_editable` | 2 fallos |
| Ensanchar la condición de cierre | 2 fallos |
| Degradar el `403 condominium_access_denied` a "sin filtro" | 2 fallos |
| Quitar el tope de `limit` (50) | 1 fallo |
| Quitar la comprobación de pertenencia del archivo | 2 fallos |
| Quitar la restricción de bundle en la propiedad del archivo | 1 fallo |
| Quitar el alcance por condominio del admin de edificio | 3 fallos |
| Servir una transacción con `field_claim` vacío | 1 fallo |
| Quitar `->addTag('node_access')` del listado | 1 fallo |
| Invertir el orden de la línea de tiempo | 1 fallo |
| Celda "Editar" como string en vez de `#markup` | 2 fallos |
| Detectar publicación también en el INSERT | 1 error |
| Ignorar el flag de opt-out de la transacción | 1 fallo |
| Invertir el default del detector de cambio de estado | 1 fallo |

Dos de esos mutantes **sobrevivieron en la primera pasada** y las pruebas se
corrigieron por eso, que es exactamente para lo que sirve el ejercicio:

- *"Quitar la comprobación de pertenencia del archivo"* pasaba porque el caso
  del `fid` ajeno no sembraba el archivo: lo rechazaba `file_load()`, no la
  regla. Ahora el archivo existe y es cargable, así que lo único que puede
  rechazarlo es la pertenencia.
- *"Quitar la restricción de bundle"* pasaba porque el nodo de otro bundle no
  tenía `field_claim`, así que igual caía en el `return NULL` siguiente. Se
  agregó el caso que sí lo tiene — un bundle futuro que referencie un reclamo —
  que es precisamente contra lo que la condición protege.

---

## Los hallazgos

### 1. `myapi_claim_create()` no tiene la guarda de "sin filas" que sí tienen `update()` y `close()`

Las tres rutas de escritura terminan releyendo el reclamo con
`myapi_claim_fetch()` para responder. `update()` y `close()` hacen:

```php
$rows = myapi_claim_fetch(...);
if (empty($rows)) { myapi_error('claim_not_found', 404); }
$row = reset($rows);
```

`create()` hace solo `$row = reset($rows);`. Si esa relectura no devolviera
nada, `$row` sería `FALSE` y la línea siguiente accedería a `$row->id`.

**Hoy es inalcanzable**, y por construcción: el reclamo recién creado lleva
`field_requester = $uid`, así que la mitad OR de la regla de visibilidad
siempre lo deja pasar, y su condominio ya fue validado contra los del autor. Se
documenta y **no se cambia**: es una asimetría entre tres funciones hermanas,
no un bug, y agregar una guarda inalcanzable a una ruta en producción es una
decisión de producto. Quedó anotado acá y es lo primero a revisar si alguna vez
se toca la regla de visibilidad.

### 2. El cuerpo JSON no es inyectable en esta capa

`myapi_request_body()` lee `php://input` **y memoriza el resultado en un
`static`**. Un test no puede escribir ese stream (y aunque registrara un stream
wrapper propio, el memo haría que solo el primer cuerpo del proceso contara).
Consecuencia concreta: de `PUT /claims/%/close` se cubren las guardas 1-5 y el
`422 missing_field` con cuerpo vacío, pero **no** el cierre exitoso.

No se toca producción para hacerlo testeable: el memo existe porque
`php://input` solo se puede leer una vez por request, que es una restricción
real del entorno. La transacción de cierre queda en la matriz de aceptación
manual del spec 70.

### 3. El listado de back office y la API discrepan, a propósito, con un rango invertido

`?date_from=2026-08-31&date_to=2026-08-01`:

- La **API** (`myapi_claim_parse_date_range()`) descarta **las dos** cotas y
  responde como si no hubiera rango.
- El **back office** (`myapi_claims_list_filters()`) las conserva tal cual, así
  que la tabla sale vacía.

Las dos conductas están ahora fijadas por un test con nombre propio en su clase
respectiva. No es un bug —cada una valida sus cotas de forma independiente— pero
era una diferencia que nadie había escrito en ningún lado.

### 4. El hallazgo del harness: el alias `status` choca con `node.status`

La query de reclamos proyecta `field_data_field_status` como `status`, y `node`
tiene su propia columna `status` (el flag de publicado). Una fila de fixture
plana no puede llevar las dos. La solución fue enseñarle al builder a resolver
**la clave calificada primero** (`'fs.field_status_value'`) tanto en condiciones
como en orden y proyección; una fila solo necesita esa forma cuando hay choque,
y todo lo demás sigue plano. Está documentado en `bootstrap.php` y en el
docblock del fixture de cada clase que lo usa.

---

## Cobertura: función → casos

### `ClaimEndpointTest` (70) — `GET /api/v1/claims`, `GET /api/v1/claims/%`

| Grupo | Casos |
|---|---|
| Routing (`myapi_claim_dispatch`) | `testPutAndDeleteAre405WithoutATokenOnBothRoutes`, `testGetRoutesToListOrDetailByThePresenceOfAnId`, `testLowercaseGetIsAccepted` |
| Access token | `testMissingAuthorizationHeaderIs401AndTouchesNoTable`, `testMalformedAuthorizationHeaderIs401`, `testUnknownRevokedAndExpiredTokensAre401`, `testTokenOfADeletedOrBlockedUserIs401`, `testDetailAlsoRequiresAToken` |
| **Visibilidad** (`myapi_claim_base_query`) | `testAPublicClaimOfANeighbourIsVisible`, `testAPrivateClaimOfANeighbourIsInvisible`, `testTheReadersOwnPrivateClaimIsVisible`, `testAPublicClaimOfAnotherCondominiumIsInvisible`, `testTheReadersOwnClaimInAForeignCondominiumIsInvisible`, `testAuthorshipDoesNotGrantVisibility`, `testAnUnpublishedClaimIsInvisible`, `testANodeOfAnotherTypeIsNotListed`, `testTheDetailAppliesTheSameRuleAndAnswers404`, `testTheTotalCountsOnlyVisibleClaims`, `testTheVisibilityRuleIsASingleOrGroup`, `testTheClaimsQueryCarriesNoNodeAccessTag` |
| Respuestas vacías | `testAReaderWithoutUnitsGetsAnEmptyList`, `testAReaderWithoutUnitsRunsNoClaimQuery`, `testTheDetailOfAReaderWithoutUnitsIs404`, `testAnEmptyPageLoadsNeitherImagesNorTransactions` |
| Serialización (`myapi_claim_build_item`, `build_file`, `load_images`) | `testTheListItemHasTheDocumentedShape`, `testReceptionDateIsTheStoredValueWithATAndNoConversion`, `testAPreSpec63ClaimKeepsItsMidnightTime`, `testAClaimWithNoReceptionDateAnswersNull`, `testTheAttachmentCarriesTheAuthenticatedUrl`, `testIdsTravelAsIntegers`, `testAClaimWithNoRequesterAnswersNull` |
| Transacciones (`myapi_claim_load_transactions`) | `testTheListCollapsesTransactionsToIdsInTimelineOrder`, `testIncludeTransactionsExpandsThem`, `testAnyOtherIncludeValueLeavesThemCollapsed`, `testTheExpandedTransactionNeverExposesItsAuthor`, `testAnUnpublishedTransactionIsNotListed`, `testTransactionsOfAnotherClaimAreNotListed`, `testATransactionImageUrlCarriesTheClaimNid`, `testTheDetailAlwaysExpandsTransactions` |
| Filtros | `testStatusFilterNarrowsTheList`, `testStatusAcceptsACommaSeparatedList`, `testAnInvalidItemOfTheStatusListIsDropped`, `testAnUnknownStatusFallsBackToNoFilter`, `testClaimTypeFilterNarrowsTheList`, `testAnUnknownClaimTypeFallsBackToNoFilter`, `testTheDateRangeIsInclusiveOnBothEnds`, `testDateFromExcludesEarlierClaims`, `testDateToExcludesLaterClaims`, `testAnImpossibleDateIsIgnored`, `testAnInvertedRangeDropsBothBounds`, `testADateFilterDropsAClaimWithoutAReceptionDate`, `testCondominiumIdNarrowsWithinTheReadersSet`, `testAForeignCondominiumIdIs403`, `testANonExistentCondominiumIdAnswersTheSame403`, `testAMalformedCondominiumIdIsIgnoredWithoutA403`, `testTheDetailIgnoresTheQueryString` |
| Paginación y orden (`myapi_claim_count`, `fetch`) | `testTheDefaultPageIsTwentyRowsNewestFirst`, `testTheSecondPageCarriesTheRemainder`, `testAPagePastTheEndIsAnEmptyList`, `testLimitMinusOneReturnsEverythingOnOnePage`, `testLimitMinusOneWithNoRowsAnswersZeroPages`, `testLimitIsClampedAndGarbageFallsBackToTwenty`, `testAGarbagePageFallsBackToOne`, `testSortAscReversesTheOrderAndGarbageFallsBackToDesc`, `testTiedReceptionDatesAreBrokenByNidInTheSameDirection` |
| 404 del detalle y costo en queries | `testEveryWayOfNotSeeingAClaimAnswersTheSame404`, `testANonNumericDetailIdNeverReachesAQuery`, `testTheListCostsTheSameNumberOfQueriesForOneRowAsForMany`, `testExpandingTransactionsAddsExactlyOneQuery`, `testTheCountIgnoresPagination` |

### `ClaimWriteGuardsTest` (40) — `POST /claims`, `POST /claims/%`, `PUT /claims/%/close`

| Grupo | Casos |
|---|---|
| Creación: auth y campos | `testCreateRequiresATokenBeforeAnythingElse`, `testEachMissingFieldIsNamedInIts422`, `testAnEmptyOrWhitespaceFieldCountsAsMissing`, `testAnArrayFieldIsTreatedAsMissing`, `testTheRequiredFieldsAreCheckedInTheDocumentedOrder` |
| Creación: reglas por campo (`myapi_claim_valid_catalogue_value`) | `testSubjectIsBoundedAt255Bytes`, `testClaimTypeMustBeAKeyOfTheFieldsCatalogue`, `testAMissingCatalogueFieldRejectsEveryValue`, `testVisibilityMustBeAKeyOfItsOwnCatalogue`, `testAnInvalidClaimTypeIsReportedBeforeAnInvalidVisibility`, `testAMalformedCondominiumIdIs422AndNotA403`, `testAForeignCondominiumIs403`, `testAResidentWithoutCondominiumsGetsTheSame403` |
| Creación: el nodo (`myapi_claim_build_node`) | `testAValidCreationBuildsTheDocumentedNodeAndAnswers201`, `testTheRequesterIsTheTokensUidAndNeverTheRequests`, `testTheStatusIsWrittenExplicitlyAsReceived`, `testTheReceptionDateIsTheServersInstantWithZeroSeconds`, `testTheFromApiFlagTravelsOnTheSavedNode`, `testNoRejectedCreationSavesANode` |
| Edición (`myapi_claim_update`) | `testUpdateAnswers404ForAMalformedId`, `testUpdateAnswers404ForAClaimTheReaderCannotSee`, `testUpdatingSomebodyElsesVisibleClaimIs403`, `testUpdatingAClaimBeyondReceivedIs409`, `testOwnershipIsCheckedBeforeTheBody`, `testTheStatusIsCheckedBeforeTheBody`, `testTheFiveTextFieldsAreRequiredOnEveryUpdate`, `testUpdateAnswers404WhenTheNodeCannotBeLoaded`, `testTheImmutableFieldsAreUntouchedByAnUpdate` |
| Archivos de la edición (`node_file_fids`, `update_parse_removals`, `update_delete_files`) | `testRemoveImageIdsRejectsAFidTheClaimDoesNotOwn`, `testRemoveImageIdsRejectsAMalformedValue`, `testAValidUpdateOverwritesTheFieldsAndDeletesTheRemovedImage`, `testRemoveAttachmentEmptiesTheFieldOnlyForTheDocumentedValues`, `testRemovedFilesAreDeletedAfterTheSave`, `testDeletingAnAlreadyMissingFileIsANoOp` |
| Cierre (`myapi_claim_close_dispatch`, `close`) | `testCloseDispatchAcceptsOnlyPut`, `testCloseRequiresAToken`, `testCloseAnswers404ForAMalformedOrInvisibleClaim`, `testClosingSomebodyElsesClaimIs403`, `testClosingAClaimBeyondReceivedIs409IncludingAnAlreadyClosedOne`, `testTheReasonIsValidatedAfterTheBusinessChecks` |

### `ClaimFileAccessTest` (42) — archivos privados (spec 65)

| Grupo | Casos |
|---|---|
| `myapi_claims_file_claim_nid()` | `testAnImpossibleFidAnswersNullWithoutAQuery`, `testAnImageOfAClaimResolvesToTheClaim`, `testTheAttachmentOfAClaimResolvesToTheClaim`, `testAnImageOfATransactionResolvesToItsClaim`, `testATransactionWithoutAClaimAnswersNull`, `testAFileOfAnotherBundleIsNotAClaimsFile`, `testAnotherBundleWithAFieldClaimStillResolvesToNothing`, `testAForeignFidAnswersNull`, `testDeletedRowsAndNonNodeEntitiesAreIgnored`, `testTheOwnershipQueryIsOrderedAndLimitedToOneRow`, `testAStringFidIsCast` |
| `myapi_claims_file_fid_by_uri()` | `testAnEmptyOrNonStringUriAnswersNullWithoutAQuery`, `testAKnownUriAnswersItsFidAsAnInt`, `testAnUnknownUriAnswersNull` |
| `myapi_claims_file_access()` | `testANonObjectAccountIsDenied`, `testUidOneIsAlwaysAllowed`, `testAnonymousAndPlainAccountsAreDenied`, `testAdministratorAndBackendSeeEveryClaim`, `testABuildingAdminSeesTheirOwnCondominiumsClaims`, `testABuildingAdminDoesNotSeeAnotherBuildingsClaim`, `testABuildingAdminWithoutCondominiumsSeesNothing`, `testAClaimWithNoCondominiumIsAllowedForTheRole`, `testAMissingClaimIsDeniedForABuildingAdmin`, `testRolesAreComparedByNameAndNotByRid` |
| `myapi_claims_file_download_headers()` | `testAForeignUriAnswersNull`, `testAFileThatBelongsToNoClaimAnswersNull`, `testAClaimFileWithoutAccessIsAHardDeny`, `testAnAllowedClaimFileAnswersInlineHeaders`, `testAnUnloadableFileAnswersNull` |
| `GET /claims/%/files/%` | `testTheFileRouteAcceptsOnlyGetAndAnswers405WithoutAToken`, `testTheFileRouteRequiresAToken`, `testAnInvisibleClaimAnswersClaimNotFoundWhateverTheFid`, `testAMalformedClaimIdAnswersClaimNotFound`, `testAMalformedFidAnswersFileNotFound`, `testAFidOfAnotherClaimIsFileNotFound`, `testAFidOfNoClaimIsFileNotFound`, `testAFileMissingFromDiskAnswersFileNotFound`, `testAValidRequestStreamsTheFileWithoutAnEnvelope`, `testATransactionsFileIsServedUnderTheClaimsNid`, `testATransactionsFileIsNotServedUnderTheTransactionsNid`, `testAReaderWithoutCondominiumsAnswersClaimNotFound` |
| Roles compartidos | `testTheClaimsAdminRolesAreTheThreeDocumentedOnes` |

### `ClaimNotificationRowTest` (44) — la fila, los detectores, la audiencia

| Grupo | Casos |
|---|---|
| `myapi_claim_notification_row()` | `testTheRowResolvesEveryDocumentedValue`, `testTheRequesterNameIsTheProfilePairWhenItExists`, `testTheRequesterNameFallsBackToTheUsername`, `testHalfAProfileNameIsTrimmed`, `testADeletedRequesterKeepsItsUidAndGetsALabel`, `testAClaimWithoutARequesterHasNoUidAndNoName`, `testAnEmptyMailAddressBecomesNull`, `testAClaimWithoutACondominiumGetsThePlaceholder`, `testACondominiumThatNoLongerLoadsGetsThePlaceholder`, `testAnUnknownStatusResolvesToANullLabel`, `testTheReceptionDateBecomesATimestamp`, `testAnUnusableReceptionDateFallsBackToTheCreationTime`, `testADegradedNodeStillProducesAUsableRow`, `testTheRowCountsImagesAndTheAttachment` |
| `myapi_claim_file_count()` | `testFileCountIsZeroForEveryEmptyShape`, `testFileCountCountsEveryDeltaOfEveryLanguage`, `testFileCountIgnoresItemsWithoutAUsableFid` |
| Fechas y etiquetas | `testTheDateLabelIsTheModulesSingleFormat`, `testDateIsTodayComparesCalendarDaysAndNotElapsedTime`, `testTheAttachmentLabelBranchesOnCountAndAttachment`, `testAnImpossibleImageCountProducesNoImagePart`, `testOnlyTheExplicitPublicValueLabelsAsPublic` |
| Detectores | `testTheApiOriginDefaultsToFalseAndReadsTheTransientFlag`, `testAnInsertIsNeverAPublicationTransition`, `testPrivateToPublicIsATransition`, `testAMissingPreviousVisibilityCountsAsNotPublic`, `testPublicToPublicIsNotATransition`, `testPublicToPrivateNotifiesNobody`, `testATransactionIsNotifiableUnlessItCarriesTheOptOut`, `testATransactionWithoutALoadableClaimIsNotNotifiable`, `testTheStatusChangeDetectorDefaultsToTrue`, `testANullPreviousStatusIsAValueAndNotAnAbsence`, `testTheStatusChangeDetectorComparesTheStoredValues` |
| Audiencia (`myapi_claim_condominium_uids`) | `testAClaimWithoutACondominiumHasNoAudience`, `testTheAudienceIsEveryOwnerAndOccupantOfTheCondominium`, `testTheRequesterIsExcludedFromTheNeighbourFanOut`, `testBlockedAccountsAreNotNotified`, `testAnEmptyAudienceShortCircuitsBeforeTheUsersQuery` |
| Params de correo | `testTheCreationMailParamsAreResolvedAndEscaped`, `testOnlyTheShortSubjectIsCut`, `testAnUnresolvedStatusPrintsADash`, `testTheTransactionMailParamsCarryTheWholeComment`, `testATransactionWithoutACommentPrintsNothing`, `testTheTransactionCommentIsEscaped` |

### `ClaimsAdminPageTest` (30) — listado de back office (spec 56)

| Grupo | Casos |
|---|---|
| Catálogos | `testTheAllowedValuesComeFromTheField`, `testAMissingFieldAnswersAnEmptyAllowedValues`, `testTheStatusOptionsAreTheFourKeysInSpecOrder`, `testAStatusWithNoLabelFallsBackToItsKey`, `testALabelForADroppedStatusIsNotOffered`, `testTheClaimTypeOptionsFollowTheSameRule` |
| Filtros GET | `testNoQueryStringMeansNoFilters`, `testTheValidFiltersPassThrough`, `testEveryMalformedFilterFallsBackToNoFilter`, `testArrayFiltersAreIgnoredWithoutAFatal`, `testTheTwoDateBoundsAreIndependent`, `testAnInvertedRangeIsKeptOnThisPage` |
| Etiquetas de fila | `testTheStatusLabelUsesTheCatalogueAndEscapes`, `testTheStatusLabelIsEscaped`, `testTheClaimTypeLabelFollowsTheSameThreeCases`, `testTheRequesterLabelHasThreeShapes`, `testTheRequesterNameIsEscaped` |
| Cuerpo de la tabla | `testARowBecomesTheSevenDocumentedCells`, `testAPreSpec63RowShowsItsMidnightTime`, `testARowWithoutAReceptionDateShowsADash`, `testNoRowsProduceNoCells`, `testTheSubjectIsEscapedInsideTheLink` |
| La query (`myapi_claims_list_rows`) | `testTheListingQueryCarriesTheNodeAccessTag`, `testTheListingQueryIsExtendedWithThePager`, `testOnlyPublishedClaimsAreListed`, `testTheListingIsOrderedByNidDescending`, `testEachFilterNarrowsOnItsOwn`, `testDateToIncludesTheWholeDay`, `testFilteringExcludesClaimsWithNoValueInThatColumn`, `testAClaimMissingOptionalValuesStillAppears` |

### `ClaimTimelineTest` (29) — línea de tiempo (specs 57/58/59)

| Grupo | Casos |
|---|---|
| `myapi_claim_transaction_timeline_rows()` | `testTheRowsCarryEveryColumnTheTableNeeds`, `testTheStatusDateKeepsItsTime`, `testTheTimelineIsNewestFirstAndTieBrokenByNid`, `testForeignUnpublishedAndOtherBundleRowsAreExcluded`, `testAHalfFilledTransactionStillAppears`, `testAClaimWithNoTransactionsAnswersAnEmptyList` |
| `author_label()` / `edit_link()` | `testTheAuthorLabelHasTwoCases`, `testTheAuthorNameIsEscaped`, `testTheEditLinkPointsAtTheTransaction`, `testTheEditLinkIsEmptyWithoutUpdateAccess`, `testAMissingTransactionGetsNoLink`, `testTheEditLinkAsksNodeAccessForUpdate` |
| `timeline_table_rows()` | `testARowBecomesTheFiveDocumentedCells`, `testTheEditCellIsARenderElementAndNotAString`, `testMissingValuesPrintAnEmDash`, `testTheCommentIsEscaped`, `testAnUnknownStatusPrintsItsRawValue` |
| `timeline_build()` y el alter de `reclamo` | `testTheBlockPutsTheCreationLinkBeforeTheTable`, `testAnEmptyTimelineCarriesItsEmptyText`, `testTheClaimEditFormDisablesTheStatusAndAppendsTheTimeline`, `testTheClaimCreationFormIsLeftAlone` |
| `validate_status_date()` | `testAValidDateAndTimePasses`, `testABareDateIsRejected`, `testImpossibleDatesAndTimesAreRejected` |
| Acceso a la ruta de creación | `testTheCreationRouteIsClosedForEveryOtherBundle`, `testTheCreationRouteNeedsBothUpdateAndCreate` |
| `title_comment()` | `testTheTitleCommentCollapsesWhitespace`, `testAnEmptyTitleCommentIsAnEmptyString`, `testALongTitleCommentIsCutOnAWordBoundary` |

---

## Criterios de aceptación

- [x] La regla de visibilidad del spec 64 tiene un caso por cada una de sus
      cuatro combinaciones, más el de autoría y el del condominio perdido.
- [x] Las tres rutas de escritura tienen cubierta su secuencia de guardas
      completa, incluido el ORDEN (403 y 409 antes del cuerpo).
- [x] Las cuatro funciones de `includes/myapi.claims_files.inc`, que no tenían
      ningún test, están cubiertas, incluido el caso del archivo de una
      transacción servido bajo el nid del reclamo.
- [x] El endpoint binario (`GET /claims/%/files/%`) se ejerce de punta a punta,
      con sus cuatro cabeceras y sin envelope JSON.
- [x] El listado de back office tiene fijado su `->addTag('node_access')`.
- [x] Los tres detectores de notificación tienen cubierto su default y sus dos
      salidas.
- [x] La suite unitaria completa pasa: **1061 tests, 4369 assertions**.
- [x] Cada clase nueva pasa también aislada (`vendor/bin/phpunit --filter
      ClaimEndpointTest`), la regla del spec 73 contra el estado compartido.
- [x] 26 mutaciones en producción producen fallos; las dos que sobrevivieron a
      la primera pasada están explicadas y las pruebas corregidas.
- [x] No se modificó ninguna línea de producción (`resources/`, `includes/`,
      `myapi.module`).

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Alcance | Todo lo que el harness puede correr de verdad, y una lista explícita de lo que no | Cubrir solo lo puro, como hasta ahora | Lo puro ya estaba cubierto; lo que no lo estaba es exactamente donde viven las reglas de acceso. |
| `node_save()` en los tests de escritura | Registrador: se asserta el objeto que el recurso le entrega | No probar las rutas de escritura | El objeto ES el contrato de `myapi_claim_build_node()` — que `field_requester` sea el del token y no el del request es la diferencia entre un reclamo propio y uno a nombre de otro. |
| Camino feliz de `close` | Fuera, documentado | Registrar un stream wrapper para `php://input` | Sobrescribir el wrapper `php` completo puede romper a PHPUnit, y el memo estático haría que solo el primer cuerpo del proceso contara. El costo supera al beneficio. |
| Choque del alias `status` | Resolución calificada en el builder | Renombrar el alias en producción | Producción no se toca en un spec de tests; y el alias es el nombre que el cliente ya consume. |
| `node_access()` | Fixture, con las llamadas registradas | Cargar el `hook_node_access` real del módulo | La resolución de grants es de Drupal. Lo que sí se prueba es que el enlace **delegue** en ella en vez de reimplementar la regla. |
| Seis clases en vez de una | Una por superficie (lectura / escritura / archivos / notificación / back office / línea de tiempo) | Un `ClaimTest.php` de 255 casos | Cada una tiene un `--filter` distinto y un tipo de assertion distinto; una sola no diría a qué superficie pertenece un fallo. |
| Los docblocks viejos que dicen "no testeable" | Se dejan como están; las clases nuevas explican por qué ahora sí | Reescribir los ocho docblocks anteriores | Eran correctos cuando se escribieron. Lo que cambió es el harness, y eso se cuenta acá y en `tests/README.md`. |

---

## Riesgos identificados

- **Falsa sensación de cobertura de persistencia.** `node_save()` no guarda
  nada. Un lector apurado puede leer "el cierre crea una transacción" como "la
  transacción queda en la base y el estado del reclamo se sincroniza". No es lo
  mismo: la sincronización es `hook_node_insert()`, es decir Drupal.
  *Mitigación:* está dicho en el `@file` del stub, en el docblock de las dos
  clases que lo usan y acá.
- **Fixtures que se desincronizan del esquema.** Una columna renombrada en la
  base no rompe estos tests. Es el límite que separa esta capa de
  `tests/integration`, y está declarado en "Fuera de este spec".
- **El builder puede divergir del `SelectQuery` real.** Ya soporta grupos, tags
  y el pager; el próximo `addExpression()` o `groupBy()` lanzará excepción en
  vez de devolver algo plausible, que es el comportamiento deseado.
- **`node_load()` y `file_load()` ahora existen en toda la suite unitaria.**
  Antes un test que los alcanzara por error moría con "undefined function";
  ahora reciben `FALSE`. *Mitigación:* la suite completa se corrió antes y
  después con idéntico resultado (806 tests en verde antes, 1061 después).
- **El camino de subida de archivos sigue sin ninguna cobertura en ninguna
  capa.** Es la parte del dominio con más superficie de ataque (extensión, MIME
  real, cantidad) y la única que este spec no toca. Queda como el siguiente
  candidato natural, en `tests/integration`.
