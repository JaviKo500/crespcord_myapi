# 100 — Creación de una oferta (`POST /api/v1/service-requests/{id}/offers`)

> **Estado:** Implemented · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer`, de sus ocho campos actuales y de los catálogos de `includes/myapi.services_common.inc`; `78-provider-role` (Implemented) — dueña del rol `proveedor`, de `myapi_provider_role_is()`, `myapi_provider_role_provider_ids()` y `myapi_provider_role_category_ids_for_providers()`; `87-service-request-direct-status` (Implemented) — dueña del estado `direct`, que es el que decide que una solicitud nacida adjudicada **no** admite ofertas; `89-service-request-detail` (Implemented) — dueña de `myapi_service_request_detail_row()` y de `myapi_service_request_build_offer()`, las dos funciones que este spec **extrae** antes de escribir una línea nueva; `92-service-request-initial-transaction` (Implemented) — dueña de la forma de una `service_transaction`; `95-service-request-cancel` (Implemented) — el precedente de escritura completo: `node_save()` + transacción + respuesta reconstruida, y el precedente de «las validaciones de negocio van antes que las de campo»; `98-service-requests-provider-list` / `99-service-request-provider-detail` (Implemented) — dueñas de la compuerta `403 provider_role_required` y de la pantalla desde la que el proveedor pulsa «Ofertar»; `90-service-request-create` (Implemented) — el precedente de forma de un `create`, y el precedente de **extraer primero y escribir después** · **Fecha:** 2026-08-25
> **Objetivo:** Añadir `POST /api/v1/service-requests/{id}/offers`, que crea la oferta de un proveedor sobre una solicitud abierta de su categoría, con diez campos nuevos que convierten «un texto y un número» en un presupuesto comparable, moviendo la solicitud de `open` a `offered` y dejando constancia en su línea de tiempo.

Cuatro notas que la cabecera fija:

- **Extrae primero, escribe después.** La oferta es un recurso propio y va en `resources/service_offer.resource.inc`, y la Regla 5 de `CLAUDE.md` le prohíbe llamar a funciones internas de `service_request.resource.inc`. Antes de escribir el `create`, `myapi_service_request_detail_row()` se muda a `includes/myapi.service_request_query.inc` **con su nombre intacto**, y `myapi_service_request_build_offer()` se muda a `includes/myapi.service_offer.inc` como `myapi_service_offer_build()`. Cero cambio de comportamiento en ambas, y la suite existente es la prueba.
- **Diez campos nuevos en un solo `hook_update_7035`, y ninguno obligatorio en el back office.** Crear un campo es barato hoy y carísimo dentro de seis meses nodo a nodo; **exigirlo** es una decisión del endpoint, que se ajusta sin tocar la base de datos. Las diez instancias nacen `required = 0` precisamente porque ya hay ofertas reales guardadas: una instancia obligatoria dejaría cada una de ellas sin poder guardarse desde `node/%/edit` hasta que un humano la rellenase.
- **Cero ficheros.** Las fotos de trabajos anteriores y el presupuesto en PDF **no** están aquí. Colgar un fichero de una oferta rompe la cadena de propiedad que `myapi_service_request_file_request_nid()` documenta en su regla de mantenimiento — resuelve por `n.type = service_request` y una oferta no lo es — y obliga a decidir si la competencia ve tu presupuesto. Eso es un spec propio, y meterlo aquí duplicaría el tamaño de este.
- **Esta es la primera escritura del módulo que mueve el estado de un nodo ajeno.** El proveedor no es el `field_requester` de la solicitud y aun así la empuja de `open` a `offered`. La transición se **pregunta** a `myapi_services_transition_allowed()`, nunca se transcribe, y solo se escribe una `service_transaction` cuando el estado se ha movido de verdad.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.service_request_query.inc`** (nuevo) — la primera extracción, mismo patrón que `includes/myapi.claim_query.inc`, `myapi.provider_query.inc` y `myapi.reservation_query.inc`:
  - `myapi_service_request_detail_row($nid)` — movida **verbatim** desde `resources/service_request.resource.inc`, sin renombrar y sin tocar una línea de su consulta ni de su docblock. Ya tiene **siete** puntos de llamada dentro del recurso (el detalle de SPEC 89, el detalle del proveedor de SPEC 99, la descarga de ficheros, la creación de SPEC 90, la cancelación de SPEC 95 y las dos de la edición de SPEC 96); este spec añade el octavo desde fuera, y ese es el motivo del traslado.

- **`includes/myapi.service_offer.inc`** (nuevo) — el dominio de la oferta, todo puro salvo las dos consultas que se nombran:
  - `myapi_service_offer_build($row)` — **movida** desde `myapi_service_request_build_offer()`, y ampliada de 6 a 15 claves. Las **seis primeras se quedan primeras y en su orden exacto**.
  - `myapi_service_offer_provider_row($provider_id)` (consulta) — nodo, publicación, `field_license_expiry`, `field_categories` y `title` del proveedor, en una sola consulta. El `title` viaja porque lo necesita el título del nodo de la oferta y el comentario de la transacción, y pedirlo aquí ahorra una segunda consulta.
  - `myapi_service_offer_live_offer_exists($request_nid, $provider_id)` (consulta) — si ese proveedor ya tiene una oferta `sent` o `selected` sobre esa solicitud.
  - `myapi_service_offer_eligibility($request_row, $provider_row, $uid, $now)` (pura) — las seis condiciones de la sección «La compuerta», devolviendo el primer `error_code` que falla o `NULL`.
  - `myapi_service_offer_validate_body($body)` (pura) — los once campos del cuerpo en el orden de la tabla de validación, devolviendo `['ok' => TRUE, 'values' => [...]]` o `['ok' => FALSE, 'error_code' => …, 'replacements' => …]`, mismo contrato que `myapi_service_request_validate_cancel_reason()` de SPEC 95.
  - `myapi_service_offer_build_node($uid, $request_nid, $provider_id, $provider_name, array $values)` (pura) — construye el nodo sin guardar, mismo patrón que `myapi_claim_build_node()` y `myapi_service_request_build_node()`.
  - `myapi_service_offer_title($request_nid, $provider_name)` (pura) — el `node.title`, truncado a 255.
  - `myapi_service_offer_transaction_comment($provider_name)` (pura) — el `field_comment` de la transacción.

- **`resources/service_request.resource.inc`** (modificar) — `myapi_service_request_detail_row()` y `myapi_service_request_build_offer()` se **borran** de aquí; no quedan como envoltorio, porque no hay un segundo llamador del nombre viejo que lo justifique. Sus llamadores pasan a `module_load_include()` de los dos includes nuevos. Ni una línea de comportamiento cambia. `myapi_service_request_reject_live_offers()` pasa a usar las dos constantes nuevas de estado de oferta en vez de los literales `'sent'` / `'selected'`, que es el mismo valor escrito de otra manera.

- **`resources/service_offer.resource.inc`** (nuevo) — el único fichero con lógica de este recurso:
  - `myapi_service_offer_dispatch($nid)` — `POST` a `myapi_service_offer_create($nid)`; cualquier otro método a `405 method_not_allowed` **antes del token y antes de cualquier consulta**.
  - `myapi_service_offer_create($nid)` — la orquestación completa, en el orden fijo de la sección «Validación».

- **`includes/myapi.services_common.inc`** (modificar) — dos catálogos nuevos y dos constantes, en la casa de los catálogos, de donde el instalador los lee:
  - `myapi_services_offer_amount_types()` → `fixed|Precio cerrado`, `estimate|Estimado`, `hourly|Por hora`, `on_site_quote|A presupuestar en sitio`.
  - `myapi_services_offer_duration_units()` → `hours|Horas`, `days|Días`.
  - `MYAPI_SERVICES_OFFER_STATUS_SENT` (`'sent'`) y `MYAPI_SERVICES_OFFER_STATUS_SELECTED` (`'selected'`).

- **`myapi.install`** (modificar) — `_myapi_services_install()` gana diez `_myapi_reservations_ensure_field()` y diez `_myapi_reservations_ensure_instance()`; `_myapi_services_uninstall_destructive()` gana los diez nombres en su lista; y `myapi_update_7035()` los aplica sobre un sitio instalado. **Sin backfill**: no se toca ningún nodo existente.

- **`myapi.module`** (modificar) — una ruta: `api/v1/service-requests/%/offers`.

- **`myapi.info`** (modificar) — `files[]` de los tres ficheros nuevos.

- **`includes/myapi.i18n.inc`** (modificar) — doce claves nuevas `es`/`en`.

- **`docs/service-offer.md`** (nuevo) — la plantilla de `CLAUDE.md` para el `POST`.

- **`docs/service-request.md`**, **`docs/service-request-provider.md`** (modificar) — el objeto oferta pasa de 6 a 15 claves en `offers` y en `my_offers`. De paso se corrige el `"status": "accepted"` de tres ejemplos de `docs/service-request-provider.md` (líneas 177, 453 y 471): ese valor no existe en el catálogo, el correcto es `selected`.

- **`docs/services-install.md`** (modificar) — la tabla de `service_offer` pasa de 8 a 18 filas, y el registro de updates gana `myapi_update_7035`.

- **Tests**: `tests/unit/ServiceOfferCreateTest.php` (nuevo). Modifican su expectativa `ServicesInstallTest`, `ServiceRequestDetailEndpointTest`, `ServiceRequestProviderDetailTest` y `ServiceRequestCancelTest` — las tres últimas solo por el tamaño del objeto oferta.

**Fuera de alcance (para specs futuras):**

- **Ficheros de la oferta** (fotos de trabajos anteriores, presupuesto en PDF). Spec propia, por la cadena de propiedad de ficheros privados y por la regla de acceso que hay que inventar.
- **Editar o retirar una oferta** (`PUT` / `DELETE`, el estado `withdrawn`). Este spec crea, y nada más. Es una limitación real, anotada en Riesgos.
- **Adjudicar una oferta** (`selected`, `field_assigned_offer`, `offered → assigned`). Otro spec, del lado del residente.
- **Rechazar ofertas** más allá de lo que `myapi_service_request_reject_live_offers()` ya hace al cancelar (SPEC 95).
- **El chat.** `field_firebase_path`, `field_chat_opened_at` y `field_last_message_at` se quedan vacíos, como llevan desde SPEC 77.
- **Notificaciones al residente.** No existe infraestructura de notificaciones para servicios — `includes/myapi.notification.inc` no menciona `service_request` — y montarla es más grande que este spec.
- **Expirar ofertas por `valid_until`.** El campo se guarda y se sirve; **ningún proceso lo mira**. Una oferta caducada sigue siendo `sent` hasta que alguien la adjudique o la solicitud se cancele.
- **Rate limiting.** La unicidad de la compuerta ya acota a una oferta viva por proveedor y solicitud, que es el grueso del abuso imaginable. `myapi_flood_check()` sigue disponible el día que haga falta.
- **Los campos del «nivel 2» como obligatorios.** Nacen creados y opcionales; endurecerlos es un cambio de endpoint, no de esquema.
- **Un `GET /api/v1/service-requests/{id}/offers`.** Las ofertas ya viajan dentro del detalle, en `offers` (residente) y `my_offers` (proveedor). Cualquier método que no sea `POST` responde `405`.

---

## Modelo de datos

### Los diez campos nuevos de `service_offer`

Todos con `cardinality = 1` y todas las instancias con `required = 0` — ver la nota de cabecera sobre por qué.

| Campo | Tipo | Catálogo / ajustes | Etiqueta (back office) |
|---|---|---|---|
| `field_offer_amount_type` | `list_text` | `allowed_values` = `myapi_services_offer_amount_types()` | Tipo de precio |
| `field_offer_valid_until` | `datestamp` | mismos `settings` de fecha que el resto del bundle | Válida hasta |
| `field_offer_available_from` | `datestamp` | ídem | Disponible desde |
| `field_offer_duration` | `number_integer` | — | Duración estimada |
| `field_offer_duration_unit` | `list_text` | `allowed_values` = `myapi_services_offer_duration_units()` | Unidad de la duración |
| `field_offer_includes` | `text_long` | `text_processing = 0` | Qué incluye |
| `field_offer_excludes` | `text_long` | `text_processing = 0` | Qué no incluye |
| `field_offer_tax_included` | `list_boolean` | `allowed_values` = `[0 => 'No', 1 => 'Sí']` | Impuesto incluido |
| `field_offer_warranty_days` | `number_integer` | — | Garantía (días) |
| `field_offer_requires_visit` | `list_boolean` | `allowed_values` = `[0 => 'No', 1 => 'Sí']` | Requiere visita previa |

`field_offer_includes` y `field_offer_excludes` van con `text_processing = 0` y no con el `1` de `field_offer_message`: aquel guarda un formato (`plain_text` fijado) por herencia de SPEC 77, y estos dos no necesitan uno para almacenar texto que se escapa al renderizarlo. Un `format` que nadie lee es una columna que alguien acabará interpretando.

**`min = 0` no es una restricción SQL** en `field_offer_duration` ni en `field_offer_warranty_days`: en Drupal 7 es un `#element_validate` que el widget `number` añade, así que un `node_save()` programático escribe un `-15` sin protestar. El endpoint repite el corte, exactamente como avisa `docs/services-install.md` para `field_hourly_rate`.

### Los ocho campos que ya existían

Sin un solo cambio. `field_request`, `field_provider`, `field_offer_message`, `field_offer_amount`, `field_offer_status` y los tres del chat siguen como los dejó SPEC 77.

### Cuerpo del request — `application/json`

**JSON y no `multipart/form-data`**, porque este spec no sube ningún fichero. La solicitud (`{id}`) viaja en la ruta y **no** es un campo del cuerpo: así no puede haber un `request_id` que contradiga al de la URL.

| Campo | Tipo | Obligatorio | Destino |
|---|---|:---:|---|
| `provider_id` | int > 0 | **Sí** | `field_provider` |
| `message` | string, 1..2000 | **Sí** | `field_offer_message` |
| `amount_type` | `fixed` \| `estimate` \| `hourly` \| `on_site_quote` | **Sí** | `field_offer_amount_type` |
| `amount` | decimal ≥ 0, ≤ 99999999.99 | condicional | `field_offer_amount` |
| `tax_included` | bool | No (solo con `amount`) | `field_offer_tax_included` |
| `valid_until` | string `Y-m-d H:i` | No | `field_offer_valid_until` |
| `available_from` | string `Y-m-d H:i` | No | `field_offer_available_from` |
| `duration` | int 1..9999 | No (acoplado a `duration_unit`) | `field_offer_duration` |
| `duration_unit` | `hours` \| `days` | No (acoplado a `duration`) | `field_offer_duration_unit` |
| `includes` | string, ≤ 2000 | No | `field_offer_includes` |
| `excludes` | string, ≤ 2000 | No | `field_offer_excludes` |
| `warranty_days` | int 0..3650 | No | `field_offer_warranty_days` |
| `requires_visit` | bool | No (por defecto `false`) | `field_offer_requires_visit` |

Los booleanos se leen **solo como booleanos JSON reales**. `"true"`, `"1"`, `1` y `"si"` son `422 invalid_field`: el cuerpo es JSON, el cliente puede mandar un booleano de verdad, y aceptar la cadena `"false"` abriría la puerta a que se leyera como verdadera.

Las longitudes se miden con `drupal_strlen()` (caracteres) y no con `strlen()` (bytes), por el mismo motivo que SPEC 95: 2000 caracteres acentuados no son 2000 bytes.

### Campos que el servidor fija, nunca el cliente

| Campo | Valor |
|---|---|
| `node.type` | `service_offer` |
| `node.uid` | `uid` del token Bearer — la **cuenta** que ofertó |
| `node.status` | `1` |
| `node.title` | `myapi_service_offer_title()` — «Oferta de @proveedor — solicitud @nid», truncado a 255 |
| `field_request` | El `{id}` de la ruta, ya validado |
| `field_offer_status` | Siempre `sent`. No es un campo del cuerpo |
| `field_firebase_path`, `field_chat_opened_at`, `field_last_message_at` | Siempre vacíos |

`node.uid` y `field_provider` son dos cosas distintas y las dos se escriben: un proveedor puede tener varias cuentas, y la oferta tiene que saber cuál de ellas la mandó.

### La compuerta: seis condiciones, cada una con su código

Se evalúan en este orden y la primera que falla responde. Todas van **antes** de validar el contenido del cuerpo, por el mismo criterio del paso 6 de SPEC 95: quién eres no depende de lo que hayas escrito.

| # | Condición | Si falla |
|---|---|---|
| 1 | `provider_id` ∈ `myapi_provider_role_provider_ids($uid)` | `403 service_offer_provider_not_owned` |
| 2 | Ese proveedor es activo: `myapi_services_provider_is_active($status, $license_expiry, REQUEST_TIME)` | `403 service_offer_provider_not_active` |
| 3 | La solicitud existe, es del bundle correcto y está publicada (`myapi_service_request_detail_row()` devuelve fila) | `404 service_request_not_found` |
| 4 | `requester_uid !== $uid` — nadie oferta su propia solicitud | `403 service_offer_own_request` |
| 5 | `status ∈ (open, offered)` **y** `assigned_provider_raw` vacío **y** `assigned_offer_raw` vacío | `409 service_request_not_offerable` |
| 6 | La categoría de la solicitud ∈ `myapi_provider_role_category_ids_for_providers([$provider_id])` | `403 service_offer_category_mismatch` |
| 7 | Ese proveedor no tiene ya una oferta `sent` ni `selected` sobre esa solicitud | `409 service_offer_already_sent` |

La condición 5 mira las columnas **crudas** (`assigned_provider_raw`, `assigned_offer_raw`) y no las resueltas: `myapi_service_request_detail_row()` deja a `NULL` un `assigned_provider_id` cuyo nodo se despublicó, y una adjudicación a un proveedor despublicado sigue siendo una adjudicación. La lectura cruda falla cerrado.

Un `direct` cae por la condición 5 sin necesidad de una regla propia: no está entre los dos estados y además nace con `field_assigned_provider` relleno. Un `direct` adjudicado **a mí** también: una solicitud que ya es mía no se oferta, se trabaja.

### Validación del cuerpo, en este orden

| # | Campo | Regla | Error |
|---|---|---|---|
| 1 | `message` | presente, string, no vacío tras `trim()`, ≤ 2000 caracteres | `422 missing_field` / `422 invalid_field` |
| 2 | `amount_type` | presente y ∈ `myapi_services_offer_amount_types()` | `422 missing_field` / `422 invalid_field` |
| 3 | `amount` | obligatorio si `amount_type ∈ (fixed, estimate, hourly)`; **prohibido** si `on_site_quote`; numérico, ≥ 0, ≤ 99999999.99 | `422 service_offer_amount_required` / `422 service_offer_amount_not_allowed` / `422 invalid_field` |
| 4 | `tax_included` | booleano real; solo tiene sentido con `amount` | `422 invalid_field` / `422 service_offer_tax_without_amount` |
| 5 | `valid_until` | `strtotime()` parsea y el resultado es **estrictamente mayor** que `REQUEST_TIME` | `422 invalid_field` |
| 6 | `available_from` | ídem | `422 invalid_field` |
| 7 | coherencia | si vienen los dos, `available_from <= valid_until` | `422 service_offer_dates_inconsistent` |
| 8 | `duration` / `duration_unit` | los dos o ninguno; `duration` entero 1..9999; `duration_unit` ∈ `myapi_services_offer_duration_units()` | `422 service_offer_duration_incomplete` / `422 invalid_field` |
| 9 | `includes`, `excludes` | string, ≤ 2000 caracteres; vacío tras `trim()` se guarda como ausente | `422 invalid_field` |
| 10 | `warranty_days` | entero, 0..3650 | `422 invalid_field` |
| 11 | `requires_visit` | booleano real; ausente = `false` | `422 invalid_field` |

El corte de `valid_until` y `available_from` es **estrictamente futuro**, la misma decisión que SPEC 90 tomó para `desired_start`, y por el mismo motivo: todo se compara contra el reloj del servidor y el segundo exacto no es un caso que un cliente arme a propósito.

La regla 7 compara `available_from <= valid_until` y no al revés: prometer disponibilidad para después de que la oferta caduque es la incoherencia; poder ir antes de que caduque no lo es.

### Las tres escrituras, en este orden

1. **La oferta.** `myapi_service_offer_build_node()` construye el nodo sin guardar y `node_save()` lo escribe. Es lo que el proveedor ha pedido crear y es la única escritura que no puede faltar.
2. **La transición de la solicitud, solo si se mueve.** Si `status === 'open'` y `myapi_services_transition_allowed('open', 'offered')` lo permite, se hace `node_load()` de la solicitud —una escritura necesita la entidad entera, no cuatro columnas, mismo argumento que el paso 3 de SPEC 95—, se cambia `field_request_status` a `offered` y se guarda. Si ya estaba en `offered`, **no se escribe nada**.
3. **La transacción, solo si el estado se movió.** Los cuatro campos de SPEC 92: `field_request` = la solicitud, `field_request_status` = `offered`, `field_status_date` = `date('Y-m-d H:i:00')`, `field_comment` = `myapi_service_offer_transaction_comment($provider_name)`. El `uid` de la transacción es el de la cuenta que ofertó. El título lo pone `myapi_service_transaction_set_title()` desde `hook_node_presave()`, como siempre.

**La transacción se escribe solo cuando el estado cambia**, y esa es una decisión, no un descuido: `service_transaction` es «una entrada por cambio de estado» desde SPEC 77, y la tercera oferta sobre una solicitud ya `offered` no cambia ninguno. Registrarla sería escribir en la línea de tiempo una entrada cuyo `field_request_status` repite el que ya había. El residente se entera de la segunda y la tercera por `offers_count` y por `offers`, no por el histórico.

El orden es el de SPEC 95: primero lo que el usuario pidió, después lo derivado. Si la escritura 2 o la 3 fallara, quedaría una oferta sobre una solicitud `open` — que es exactamente el estado inconsistente que el módulo **ya admite hoy** (una oferta creada desde el back office no mueve nada) y que `myapi_service_request_update_gate()` cubre por su cuenta contando ofertas en vez de fiarse del estado.

### Claves i18n nuevas

| Clave | `es` | `en` |
|---|---|---|
| `service_offer_created` | Oferta enviada correctamente. | Offer sent successfully. |
| `service_offer_provider_not_owned` | El proveedor indicado no pertenece a su cuenta. | The selected provider does not belong to your account. |
| `service_offer_provider_not_active` | Su proveedor no está activo. Revise la vigencia de la licencia. | Your provider is not active. Check the licence expiry date. |
| `service_offer_own_request` | No puede ofertar sobre su propia solicitud. | You cannot bid on your own request. |
| `service_request_not_offerable` | Esta solicitud ya no admite ofertas. | This request no longer accepts offers. |
| `service_offer_category_mismatch` | Su proveedor no atiende la categoría de esta solicitud. | Your provider does not serve this request's category. |
| `service_offer_already_sent` | Ya tiene una oferta activa en esta solicitud. | You already have an active offer on this request. |
| `service_offer_amount_required` | Indique el monto para este tipo de precio. | An amount is required for this price type. |
| `service_offer_amount_not_allowed` | Una oferta a presupuestar en sitio no lleva monto. | An on-site quote carries no amount. |
| `service_offer_tax_without_amount` | No puede indicar si el impuesto está incluido sin un monto. | Tax inclusion cannot be stated without an amount. |
| `service_offer_dates_inconsistent` | La disponibilidad no puede ser posterior a la validez de la oferta. | Availability cannot be later than the offer's validity. |
| `service_offer_duration_incomplete` | Indique la duración y su unidad, o ninguna de las dos. | Provide both the duration and its unit, or neither. |

Reutilizadas sin cambio: `missing_authorization`, `invalid_token`, `method_not_allowed`, `missing_field`, `invalid_field`, `provider_role_required`, `service_request_not_found`.

### El objeto oferta: 15 claves, siempre las 15, en este orden

Las **seis de SPEC 89**, sin una sola diferencia, seguidas de **nueve nuevas**.

| # | Clave | Tipo | Nota |
|---|---|---|---|
| 1 | `id` | int | `node.nid` |
| 2 | `provider` | object \| null | `{id, name, logo}` |
| 3 | `amount` | float \| null | `null` cuando no lleva monto |
| 4 | `message` | string | |
| 5 | `status` | string | `sent` \| `selected` \| `rejected` \| `withdrawn` |
| 6 | `created` | string | `Y-m-d\TH:i:s` |
| 7 | `amount_type` | string \| null | **`null` en toda oferta anterior a este spec** — ver Decisión 6 |
| 8 | `valid_until` | string \| null | `Y-m-d\TH:i:s` |
| 9 | `available_from` | string \| null | `Y-m-d\TH:i:s` |
| 10 | `duration` | object \| null | `{value: int, unit: 'hours'\|'days'}`, o `null` entero |
| 11 | `includes` | string \| null | |
| 12 | `excludes` | string \| null | |
| 13 | `tax_included` | bool \| null | `null` cuando no se declaró |
| 14 | `warranty_days` | int \| null | |
| 15 | `requires_visit` | bool | Nunca `null`: la ausencia de la afirmación «necesito visitar» se lee como `false` |

**Las seis primeras se quedan primeras y en su orden exacto**, aunque `amount_type` quedaría más legible pegado a `amount`. Es lo que hace que «las seis primeras claves son byte a byte las que SPEC 89 respondía» sea un criterio de aceptación que este fichero no puede romper por accidente, y lo que permite decir sin matices que ningún cliente de los detalles existentes se rompe.

`duration` es un objeto entero o un `null` entero, y no dos claves planas: los dos campos están acoplados —uno sin el otro no significa nada— y un `null` que dice «no hay duración» una sola vez es mejor que dos que hay que leer juntos. Es el mismo criterio con el que `provider` viaja como objeto completo o como `null` completo, nunca como `{id: null, name: null}`.

Un texto opcional vacío se sirve como `null` y no como `""`. `message` sí es `""` cuando está vacío, porque es obligatorio y un vacío ahí es una fila corrupta, no una ausencia.

### Respuesta de éxito (201)

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": null },
      "amount": 150.5,
      "message": "Puedo pasar el jueves por la mañana.",
      "status": "sent",
      "created": "2026-08-25T11:02:00",
      "amount_type": "fixed",
      "valid_until": "2026-09-01T23:59:00",
      "available_from": "2026-08-27T08:00:00",
      "duration": { "value": 3, "unit": "hours" },
      "includes": "Mano de obra, desplazamiento y sellado.",
      "excludes": "El calentador de repuesto, si hiciera falta.",
      "tax_included": true,
      "warranty_days": 90,
      "requires_visit": false
    },
    "request": { "id": 128, "status": "offered" }
  },
  "message": "Oferta enviada correctamente."
}
```

`request` es un **hermano** de `service_offer`, no una decimosexta clave suya, y trae solo `id` y `status`. Es lo único que el proveedor no puede deducir de lo que acaba de enviar —si su oferta fue la primera, la solicitud se ha movido— y se conoce en código, sin una consulta más. Mismo criterio con el que SPEC 95 puso `offers_rejected` al lado de `service_request` y no dentro.

**El objeto bajo `service_offer` es byte a byte el elemento que aparecerá en `my_offers`** la próxima vez que el proveedor pida `GET /api/v1/service-requests/provider/{id}`, porque sale del mismo serializador. Ese es un criterio de aceptación.

---

## Plan de implementación

Once pasos. Los cuatro primeros no encienden nada: cierran la deuda de arquitectura y preparan el esquema.

1. **`includes/myapi.service_request_query.inc` — la primera extracción.**
   `myapi_service_request_detail_row($nid)` movida verbatim desde `resources/service_request.resource.inc`, con su docblock íntegro. Sus siete puntos de llamada dentro del recurso pasan por `module_load_include('inc', 'myapi', 'includes/myapi.service_request_query')`. `myapi.info` gana la línea.
   *Verificación: `php -l`; `ServiceRequestDetailEndpointTest`, `ServiceRequestCancelTest`, `ServiceRequestUpdateTest` y `ServiceRequestProviderDetailTest` siguen en verde **sin tocar un solo test** — la prueba de que el traslado no movió nada.*

2. **`includes/myapi.services_common.inc` — los dos catálogos y las dos constantes.**
   `myapi_services_offer_amount_types()`, `myapi_services_offer_duration_units()`, `MYAPI_SERVICES_OFFER_STATUS_SENT`, `MYAPI_SERVICES_OFFER_STATUS_SELECTED`. `myapi_service_request_reject_live_offers()` pasa a usar las dos constantes en lugar de los literales.
   *Verificación: `php -l`; test unitario de los dos catálogos; `ServiceRequestCancelTest` en verde sin tocarla.*

3. **`myapi.install` — los diez campos.**
   Diez `_myapi_reservations_ensure_field()` y diez `_myapi_reservations_ensure_instance()` dentro de `_myapi_services_install()`, con los `allowed_values` **leídos de `services_common`**, nunca retecleados — la regla que `ServicesInstallTest` fija. Los diez nombres se añaden a la lista de `_myapi_services_uninstall_destructive()`.
   *Verificación: `php -l`; `ServicesInstallTest` ampliada exige los diez campos, las diez instancias, `required = 0` en todas, y que los dos catálogos vienen de `services_common`.*

4. **`myapi.install` — `myapi_update_7035()`.**
   `module_load_include()` de `services_common`, `_myapi_services_install()`, `field_info_cache_clear()`, y una línea de resumen. **No hace falta ningún `field_update_field()`**, al contrario que `myapi_update_7033()`: los diez campos son nuevos, y los helpers `_ensure_*` —que solo crean— son exactamente lo que hace falta. Sin backfill: ninguna oferta guardada se toca.
   *Verificación: `drush updb` sobre una copia con ofertas reales; las ofertas existentes siguen cargando y guardándose desde `node/%/edit` con los diez campos vacíos.*

5. **`includes/myapi.service_offer.inc` — el serializador, movido y ampliado.**
   `myapi_service_offer_build($row)` con las 15 claves de la tabla. `myapi_service_request_build_offer()` se borra del recurso y sus tres puntos de llamada —`myapi_service_request_detail()`, `myapi_service_request_provider_detail()` y `myapi_service_request_cancel()`— pasan a la función nueva. `myapi_service_request_load_offers()` amplía su `SELECT` con las diez columnas nuevas, todas por `leftJoin` (una oferta antigua no tiene fila en ninguna de las diez tablas).
   *Verificación: `php -l`; los tres tests de detalle actualizados a 15 claves; un test nuevo fija que las **seis primeras** son las mismas y en el mismo orden.*

6. **El mismo fichero — la compuerta y las consultas.**
   `myapi_service_offer_provider_row()`, `myapi_service_offer_live_offer_exists()` y `myapi_service_offer_eligibility()`, esta última pura y devolviendo el primer `error_code` que falla.
   *Verificación: `php -l`; la matriz completa de la compuerta contra filas fixture, sin sitio arrancado.*

7. **El mismo fichero — la validación y el constructor.**
   `myapi_service_offer_validate_body()` en el orden de la tabla, `myapi_service_offer_build_node()`, `myapi_service_offer_title()` y `myapi_service_offer_transaction_comment()`. Todas puras.
   *Verificación: `php -l`; test de cada regla, incluidas las tres condicionales (`amount` × `amount_type`, `tax_included` sin `amount`, `duration` sin `duration_unit`).*

8. **`includes/myapi.i18n.inc` — las doce claves.**
   En los bloques `es` y `en`, agrupadas bajo un comentario `// SPEC 100.`
   *Verificación: `myapi_t()` de las doce en ambos idiomas; `I18nTest` comprueba que ningún bloque tiene claves que le falten al otro.*

9. **`resources/service_offer.resource.inc` y la ruta.**
   `myapi_service_offer_dispatch($nid)` y `myapi_service_offer_create($nid)`, en el orden fijo: método → `nid` → token → rol → `provider_id` → compuerta (siete condiciones) → cuerpo (once reglas) → las tres escrituras → `201`. En `myapi.module`, `api/v1/service-requests/%/offers` con `access callback => TRUE` y `file => resources/service_offer.resource.inc`. `myapi.info` gana la línea.
   Sobre el enrutado: son **cinco componentes**, como `api/v1/service-requests/%/cancel`, y las dos se distinguen por el literal de la quinta posición. Drupal 7 compara primero el número de componentes, lo que ya separa esta ruta de las de cuatro y de la de seis; y `'offers'` no puede confundirse con `'cancel'`. Es el mismo razonamiento que SPEC 99 dejó escrito para su propia ruta.
   *Verificación: `drush cc all`; `curl -X POST` con el cuerpo mínimo crea la oferta y devuelve `request.status = "offered"`; `GET`, `PUT` y `DELETE` sobre la misma ruta responden `405`.*

10. **Documentación.**
    `docs/service-offer.md` nuevo con la plantilla de `CLAUDE.md`. En `docs/service-request.md` y `docs/service-request-provider.md`, el objeto oferta pasa a 15 claves y se corrige `"accepted"` → `"selected"` en los tres ejemplos. En `docs/services-install.md`, la tabla de `service_offer` y el registro de updates.
    *Verificación: lectura contra la implementación.*

11. **`drush cc all`, `drush updb` y matriz manual.**
    Recorrer los criterios con: una cuenta sin rol; una con rol y sin proveedor; una con dos proveedores (ofertando con el que no toca); un proveedor con licencia vencida; una solicitud `open`, una `offered`, una `direct`, una `assigned`, una `cancelled`; y una oferta repetida.

---

## Criterios de aceptación

> **Estado de la verificación — 2026-08-25.** Marcado tras implementar los once
> pasos del plan, con la suite unitaria en `OK (2140 tests, 9681 assertions)` y
> **sin haber desplegado ni ejecutado `drush updb`** contra el sitio.
>
> - `[x]` — **verificado automáticamente**, con un test que falla si se rompe.
> - `[ ]` *(🟡 función pura)* — la decisión está probada en su función pura, pero
>   **nadie ha comprobado que el recurso la llame bien**: un `myapi_error()` con el
>   status equivocado, o las dos filas pasadas invertidas a `eligibility()`,
>   pasarían los tests y romperían el endpoint.
> - `[ ]` *(⬜ sitio)* — necesita el sitio arrancado: `hook_menu()`, `drush updb`,
>   o una escritura real. Los cubre `spec-100-matriz.sh`.
>
> Recuento: **31 `[x]` · 14 🟡 · 16 ⬜** — 61 en total.

**Método, ruta y autenticación**

- [x] `GET`, `PUT`, `DELETE` y `PATCH` sobre `api/v1/service-requests/%/offers` → `405 method_not_allowed`, **antes** de leer el token.
- [x] `POST` sin `Authorization` → `401 missing_authorization`.
- [x] Con token inválido o expirado → `401 invalid_token`.
- [x] Un `{id}` no numérico, `0` o negativo → `404 service_request_not_found`, sin consultar nada.
- [x] `api/v1/service-requests/{id}/cancel` y `api/v1/service-requests/{id}/files/{fid}` siguen resolviendo sus propias rutas; ninguna es capturada por la nueva. *(⬜ sitio)*

**La compuerta de rol**

- [x] Una cuenta sin el rol `proveedor` → `403 provider_role_required`, antes de cualquier consulta, aunque sea administrador. *(🟡 función pura)*
- [x] Una cuenta con el rol pero sin ningún proveedor operable → `403 service_offer_provider_not_owned` para cualquier `provider_id`. *(🟡 función pura)*

**`provider_id`**

- [x] Ausente → `422 missing_field` con `@field = provider_id`.
- [x] No numérico, `0` o negativo → `422 invalid_field`. *(⬜ sitio)*
- [x] De un proveedor que no es de mi cuenta → `403 service_offer_provider_not_owned`. *(🟡 función pura)*
- [x] De un proveedor mío pero despublicado → `403 service_offer_provider_not_active`. *(🟡 función pura)*
- [x] De un proveedor mío publicado con `field_license_expiry` vencida o vacía → `403 service_offer_provider_not_active`. *(🟡 función pura)*
- [x] Una cuenta con **dos** proveedores oferta con el que indica, no con el primero: la oferta creada lleva ese `field_provider` y ningún otro. *(🟡 función pura)*

**La solicitud**

- [x] Un `{id}` que no existe, es de otro bundle o está despublicado → `404 service_request_not_found`. *(🟡 función pura)*
- [x] Ofertar sobre la propia solicitud (la cuenta es su `field_requester`) → `403 service_offer_own_request`, incluso teniendo el rol y un proveedor activo. *(🟡 función pura)*
- [x] Una solicitud en `direct`, `assigned`, `closed` o `cancelled` → `409 service_request_not_offerable`. *(🟡 función pura)*
- [x] Una solicitud en `open` u `offered` **con** `field_assigned_provider` o `field_assigned_offer` rellenos (dato incoherente) → `409 service_request_not_offerable`; se leen las columnas crudas, así que una adjudicación a un proveedor despublicado también cierra la puerta. *(🟡 función pura)*
- [x] Una solicitud cuya categoría no está entre las de mi proveedor → `403 service_offer_category_mismatch`. *(🟡 función pura)*
- [x] Un proveedor con `field_categories` vacío → `403 service_offer_category_mismatch` sin código especial. *(🟡 función pura)*
- [x] Un `field_request_status` vacío o corrupto → `409 service_request_not_offerable`, nunca `500`. *(🟡 función pura)*

**Unicidad**

- [x] Un segundo `POST` del mismo proveedor sobre la misma solicitud, con la primera oferta en `sent` → `409 service_offer_already_sent`, y no se crea ningún nodo. *(⬜ sitio)*
- [x] Con la primera oferta en `selected` → `409 service_offer_already_sent`. *(⬜ sitio)*
- [x] Con la primera oferta en `rejected` o `withdrawn` → se **permite** la nueva oferta. *(⬜ sitio)*
- [x] Dos proveedores **distintos** de la misma cuenta pueden ofertar sobre la misma solicitud. *(⬜ sitio)*

**Validación del cuerpo**

- [x] Cuerpo ausente o JSON no parseable → `422 missing_field` con `@field = message`, sin crear nada.
- [x] `message` ausente, vacío o solo espacios → `422 missing_field` / `422 invalid_field`.
- [x] `message` de 2001 caracteres → `422 invalid_field`; de 2000 caracteres acentuados → se acepta (`drupal_strlen`, no `strlen`).
- [x] `amount_type` ausente → `422 missing_field`; con un valor fuera del catálogo → `422 invalid_field`.
- [x] `amount_type = fixed|estimate|hourly` sin `amount` → `422 service_offer_amount_required`.
- [x] `amount_type = on_site_quote` con `amount` → `422 service_offer_amount_not_allowed`.
- [x] `amount` negativo → `422 invalid_field`; `amount` de más de 99999999.99 → `422 invalid_field`; `amount = 0` con `amount_type = fixed` → se acepta.
- [x] `tax_included` sin `amount` → `422 service_offer_tax_without_amount`.
- [x] `tax_included`, `requires_visit` con `"true"`, `"1"` o `1` → `422 invalid_field`; con `true` o `false` → se acepta.
- [x] `valid_until` o `available_from` con formato no parseable → `422 invalid_field`.
- [x] Cualquiera de las dos en el pasado, o en el instante exacto de `REQUEST_TIME` → `422 invalid_field`.
- [x] `available_from` posterior a `valid_until` → `422 service_offer_dates_inconsistent`.
- [x] `duration` sin `duration_unit`, o al revés → `422 service_offer_duration_incomplete`.
- [x] `duration = 0` o negativa → `422 invalid_field`; `duration_unit` fuera del catálogo → `422 invalid_field`.
- [x] `warranty_days` negativo → `422 invalid_field`; `warranty_days = 0` → se acepta.
- [x] `includes` o `excludes` de más de 2000 caracteres → `422 invalid_field`; vacíos tras `trim()` se guardan como ausentes y se sirven como `null`.
- [x] Un `403` o un `409` de la compuerta gana siempre a un `422` del cuerpo: un cuerpo vacío sobre una solicitud cerrada responde `409`, no `422`. *(⬜ sitio)*

**Las escrituras**

- [x] La oferta se crea con `node.status = 1`, `node.uid` = el `uid` del token y `field_provider` = el `provider_id` validado.
- [x] `field_offer_status` es `sent` siempre, aunque el cliente mande un `status` en el cuerpo — no es un campo del request.
- [x] Los tres campos del chat quedan vacíos.
- [x] `node.title` no supera los 255 caracteres ni con el nombre de proveedor más largo del sitio.
- [x] Ofertando sobre una solicitud en `open`: la solicitud queda en `offered` y se crea **una** `service_transaction` con `field_request_status = offered`. *(⬜ sitio)*
- [x] Ofertando sobre una solicitud ya en `offered`: la solicitud **no se guarda** y **no se crea ninguna transacción**; la oferta sí. *(⬜ sitio)*
- [x] La transacción creada lleva `field_status_date` con la hora real y los segundos a `00`, y su título lo pone `hook_node_presave()`. *(⬜ sitio)*
- [x] Ningún `field_assigned_offer` ni `field_assigned_provider` de la solicitud se toca: ofertar no adjudica. *(⬜ sitio)*

**Respuesta**

- [x] Éxito → `201`, con `data.service_offer` de exactamente 15 claves en el orden de la tabla, y `message` traducido (`service_offer_created`). *(🟡 función pura)*
- [x] Las seis primeras claves son byte a byte las que SPEC 89 ya respondía: mismos nombres, mismos tipos, mismo orden.
- [x] `data.request` trae `id` y `status`, y `status` es el de **después** de la escritura. *(⬜ sitio)*
- [x] Inmediatamente después del `POST`, `GET /api/v1/service-requests/provider/{id}` devuelve esa misma oferta dentro de `my_offers`, **byte a byte igual** al objeto del `201`. *(⬜ sitio)*
- [x] El detalle del residente (`GET /api/v1/service-requests/{id}`) muestra la oferta en `offers` y `offers_count` incrementado. *(⬜ sitio)*

**No regresión**

- [x] `GET /api/v1/service-requests`, `/{id}`, `/provider`, `/provider/{id}` y `/{id}/files/{fid}` no cambian ninguna clave, tipo ni código de estado, salvo las nueve claves nuevas dentro de cada elemento de `offers` / `my_offers`.
- [x] `POST /api/v1/service-requests` (SPEC 90), `POST /{id}/cancel` (SPEC 95) y `PUT /{id}` (SPEC 96) no cambian nada tras los traslados de los pasos 1 y 5.
- [x] Toda la suite unitaria en verde, incluidas las cuatro clases de test que solo cambian su expectativa por el tamaño del objeto oferta.
- [x] `drush updb` aplica `myapi_update_7035` y una segunda ejecución no encuentra nada pendiente. *(⬜ sitio)*
- [x] Las ofertas guardadas antes del update siguen abriéndose y guardándose desde `node/%/edit` sin rellenar ningún campo nuevo. *(⬜ sitio)*

**Documentación**

- [x] `docs/service-offer.md` documenta el `POST` con la plantilla de `CLAUDE.md`: método, auth, cuerpo, la compuerta, la respuesta y la tabla de errores completa.
- [x] `docs/service-request.md` y `docs/service-request-provider.md` documentan las 15 claves, y ya no aparece `"accepted"` en ningún ejemplo.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| 1. Alcance de los campos | **Los diez de los niveles 1 y 2, en un solo `hook_update_7035`** | Solo el nivel 1 ahora y el 2 en otro update; o los tres niveles de golpe | Decisión explícita del usuario. Crear una columna es barato hoy y carísimo dentro de seis meses; **exigirla** es una decisión del endpoint que se ajusta sin tocar la base de datos. El nivel 3 queda fuera por lo que dice la nota 3 de la cabecera. |
| 2. Los ficheros de la oferta | **Fuera, spec propia** | Instancias de `field_images`/`field_attachment` sobre `service_offer` aquí mismo | Parecía la parte barata y es la cara: `myapi_service_request_file_request_nid()` resuelve el dueño por `n.type = service_request`, así que un fichero de una oferta nace privado e inalcanzable —para `hook_file_download()` y para la ruta de ficheros— y arreglarlo exige una cadena de propiedad de dos saltos y una regla de acceso nueva que decida si la competencia ve tu presupuesto. |
| 3. Ruta | **`POST /api/v1/service-requests/{id}/offers`** | `POST /api/v1/offers` con `request_id` en el cuerpo | Una oferta no existe fuera de su solicitud, y con el nid en la ruta no puede haber un `request_id` del cuerpo que contradiga al de la URL. Mismo criterio con el que SPEC 95 colgó `cancel` de la solicitud. |
| 4. Fichero del recurso | **`resources/service_offer.resource.inc`, nuevo**, más dos includes de extracción | Añadir el `create` a `service_request.resource.inc`, que ya tiene todo lo que hace falta | Reglas 2 y 5 de `CLAUDE.md`: un recurso, un fichero, y un recurso no llama a las funciones internas de otro. El precio es la extracción del paso 1, que es el mismo peaje que SPEC 90 pagó con `myapi_node_files_save()`. |
| 5. Cuerpo en JSON | **`application/json`** | `multipart/form-data`, como SPEC 90 | Este spec no sube ficheros y el multipart solo existe para eso. El día que la spec del nivel 3 los añada, entrarán por una ruta de subida propia y no cambiando el formato de esta —ver Riesgo 4. |
| 6. `amount_type` en la respuesta | **Obligatorio en el `POST`, `null` en el `GET`** | Un `hook_update_N` que rellene las ofertas antiguas; o un valor `unspecified` en el catálogo | Hay ofertas reales guardadas. Deducir su tipo de precio del monto (`hay monto → fixed`) sería ponerle al proveedor en la boca una afirmación que nunca hizo, y un valor `unspecified` ensuciaría el catálogo para siempre por un puñado de filas históricas. `null` dice exactamente lo que pasó: esa oferta es anterior al campo. Sin backfill, la misma disciplina de `myapi_update_7032`. |
| 7. Instancias `required = 0` | **Las diez opcionales en el back office** | `field_offer_amount_type` obligatoria, ya que el endpoint la exige | Una instancia obligatoria bloquea el guardado de **toda oferta ya existente** desde `node/%/edit` hasta que un humano la rellene. La obligación vive donde se puede razonar sobre ella: el endpoint. |
| 8. Orden de las claves | **Las seis de SPEC 89 primero, en su orden; las nueve nuevas después** | Insertar `amount_type` junto a `amount`, que se lee mejor | El orden es contrato en este módulo. Manteniendo el prefijo intacto, «las seis primeras son byte a byte las de SPEC 89» es un criterio verificable y ningún cliente de los dos detalles se rompe. La legibilidad la da la documentación. |
| 9. `duration` como objeto | **`{value, unit}` o `null` entero** | `duration` y `duration_unit` planas | Los dos campos están acoplados: uno sin el otro no significa nada. Un `null` entero dice «no hay duración» una sola vez, igual que `provider` viaja completo o `null` completo y nunca como `{id: null}`. |
| 10. `provider_id` obligatorio | **Siempre, aunque la cuenta opere un solo proveedor** | Derivarlo cuando solo hay uno | Derivarlo elige en silencio, y el día que esa cuenta opere dos, el cliente que nunca mandó el campo empieza a ofertar con la empresa equivocada sin que nada falle. Explícito siempre, es un entero. |
| 11. Unicidad | **Una oferta viva (`sent` o `selected`) por proveedor y solicitud** | Permitir varias ofertas del mismo proveedor | Varias ofertas vivas del mismo proveedor obligan al residente a comparar dos presupuestos de la misma empresa sin saber cuál vale. `rejected` y `withdrawn` no bloquean: una oferta muerta no compite. El precio —que el proveedor no puede corregirse— está en el Riesgo 1. |
| 12. La transacción | **Solo cuando el estado se mueve de `open` a `offered`** | Una entrada por cada oferta recibida | `service_transaction` es «una entrada por cambio de estado» desde SPEC 77. La tercera oferta no cambia ninguno, y registrarla escribiría en la línea de tiempo una fila cuyo `field_request_status` repite el anterior. El residente cuenta ofertas con `offers_count`. |
| 13. Ofertar sobre la propia solicitud | **`403 service_offer_own_request`** | Permitirlo; o no comprobarlo | Cuesta una comparación de enteros sobre una fila que ya está cargada, y el caso —una cuenta que es residente y proveedor a la vez— existe de verdad en este modelo de datos. |
| 14. `valid_until` no expira nada | **Se guarda y se sirve; ningún proceso la mira** | Un `hook_cron` que pase a `withdrawn` las ofertas caducadas | Expirar es una escritura automática sobre datos de un tercero, y necesita decidir qué pasa con una oferta caducada que ya estaba `selected`. Eso es una spec, no un efecto colateral de esta. El campo es informativo y así se documenta. |
| 15. Notificar al residente | **Nada** | Push u correo al recibir la primera oferta | No existe infraestructura de notificaciones para servicios: `includes/myapi.notification.inc` no menciona `service_request`. Montarla copiando la de reclamos o la de reservas es más grande que este spec entero. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **1. El proveedor no puede corregir su oferta.** La unicidad la bloquea en `sent` y no hay `PUT` ni `DELETE`: un cero de más en el monto se queda escrito hasta que el residente adjudique a otro o cancele. | Asumido y explícito. Es el precio de partir el trabajo en dos specs, y la salida —`PUT` y el estado `withdrawn`, que ya existe en el catálogo desde SPEC 77— está diseñada aunque no implementada. Debe ser el spec inmediatamente siguiente, y hasta entonces la app tiene que confirmar el envío con un resumen de lo que se manda. |
| **2. Doce campos hunden la tasa de oferta.** El proveedor está en obra, con el móvil, compitiendo por un trabajo de 150 dólares; cada campo es una razón para no terminar el formulario. | Solo tres son obligatorios (`provider_id`, `message`, `amount_type`) y el endpoint acepta una oferta con nada más. Del lado de la app, los cinco campos del nivel 2 van detrás de un «Añadir detalles» plegado. Y si el dato dice que sobran, quitarlos del formulario no cuesta ninguna migración: los campos siguen ahí, vacíos. |
| **3. Ofertas asimétricas.** Con nueve campos opcionales, un presupuesto llega con garantía y exclusiones y otro con dos líneas, y el residente compara peras con manzanas. | Es el coste consciente de la Decisión 7, y la alternativa —obligarlos— produce menos ofertas, que es peor. El detalle sirve `null` en todo lo no declarado, no lo omite, así que la app puede pintar «no indicado» y hacer visible la asimetría en vez de esconderla. |
| **4. El día que la oferta lleve ficheros, el cuerpo tendrá que dejar de ser JSON**, y eso rompe a todos los clientes que ya publiquen ofertas. | La spec del nivel 3 debe subir los ficheros por una **ruta propia** (`POST /api/v1/service-offers/{id}/files` o equivalente) sobre una oferta ya creada, dejando este `POST` en JSON para siempre. Anotado aquí para que esa decisión no se tome por descarte. |
| **5. La escritura no es atómica.** Si `node_save()` de la solicitud o de la transacción falla después de guardar la oferta, queda una oferta sobre una solicitud `open`. | Es el estado que el módulo **ya admite hoy** —una oferta creada desde el back office no mueve nada— y del que `myapi_service_request_update_gate()` ya se defiende contando ofertas en lugar de fiarse del estado. El orden de las escrituras pone primero la que el usuario pidió, igual que SPEC 95, y el fallo se registra en `watchdog` en vez de tragarse. |
| **6. Dos proveedores de la misma cuenta pueden ofertar sobre la misma solicitud**, y el residente ve dos presupuestos que en el fondo son de la misma persona. | Es correcto: son dos empresas distintas en el modelo de datos, con licencias, categorías y calificaciones separadas, y nada dice que una cuenta que gestiona dos no pueda competir con las dos. La unicidad es por proveedor, que es la entidad que el residente contrata. |
| **7. `myapi_service_request_load_offers()` gana diez `leftJoin`.** El detalle del residente de una solicitud con quince ofertas pasa a una consulta con diecisiete uniones. | Todas son sobre `field_data_*` por `entity_id`, que es la clave primaria de esas tablas, y el conjunto está acotado por las ofertas de **una** solicitud. Es el mismo patrón que `myapi_service_request_detail_row()` lleva usando desde SPEC 89 con doce uniones. Si alguna vez duele, la salida es una segunda consulta por columnas, no desnormalizar. |
| **8. El traslado de `myapi_service_request_detail_row()` toca cinco llamadores** y una divergencia sutil en el copiado rompería a la vez el detalle, la cancelación, la edición y las dos vistas del proveedor. | El paso 1 del plan es un cambio de **cero comportamiento** por diseño, y se verifica con cuatro clases de test existentes **sin tocar un solo test**. Es la misma disciplina con la que SPEC 90 extrajo `myapi_node_files_save()` y SPEC 89 extrajo `myapi_user_display_names()`. |
| **9. Se abre la puerta a que la solicitud la mueva alguien que no es su dueño.** Hasta hoy, solo el `field_requester` cambiaba el estado de su solicitud. | Está acotado a una transición, en un sentido, y desde un solo estado: `open → offered`, y solo cuando la compuerta de siete condiciones ha dado paso. La transición se **pregunta** a `myapi_services_transition_allowed()` en vez de transcribirse, así que el día que el grafo cambie, este endpoint lo obedece sin que nadie se acuerde de él. |

---

## Lo que **NO** está en este spec

- Ficheros de la oferta: fotos de trabajos anteriores y presupuesto en PDF.
- Editar, retirar o borrar una oferta ya enviada.
- Adjudicar una oferta (`selected`, `field_assigned_offer`, `offered → assigned`).
- El chat: los tres campos de `service_offer` siguen vacíos.
- Notificaciones al residente, en cualquier canal.
- Expirar ofertas por `valid_until`.
- Rate limiting sobre la creación de ofertas.
- Un `GET` de la colección de ofertas de una solicitud.
- Backfill de `amount_type` sobre las ofertas ya guardadas.
- Hacer obligatorio ninguno de los cinco campos del nivel 2.

Cada uno de ellos, si llega, va en su propio spec.
