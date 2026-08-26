# 105 — Editar y retirar una oferta (`PUT /api/v1/service-offers/{id}` y `.../withdraw`)

> **Estado:** Approved · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer` y del catálogo `myapi_services_offer_statuses()`, donde `withdrawn` lleva esperando desde entonces; `100-service-offer-create` (Implemented) — dueña del cuerpo de la oferta, de `myapi_service_offer_validate_body()`, de `myapi_service_offer_build()`, de `myapi_service_offer_eligibility()` y de la compuerta que este spec **reordena sin reescribir**; `101-service-offer-on-direct` (Implemented) — dueña del presupuesto sobre un `direct`, que es el caso que hace urgente este spec; `102-service-offers-provider-list` (Implemented) — dueña del archivo del proveedor, donde una oferta retirada ya viaja y ya se filtra por `status=withdrawn` sin un cambio; `103-service-offer-detail` (Implemented) — dueña de `myapi_service_offer_item_dispatch()`, de la ruta `api/v1/service-offers/%` y de `myapi_service_offer_detail_row()`; `78-provider-role` (Implemented) — dueña de `myapi_provider_role_is()` y `myapi_provider_role_provider_ids()`, las dos funciones de la compuerta; `89-service-request-detail` (Implemented) — dueña del criterio «manda el campo de dominio y no `node.uid`», que es la base de la Decisión 4; `95-service-request-cancel` (Implemented) — dueña de `myapi_service_request_reject_live_offers()`, la razón por la que una solicitud `cancelled` **no necesita regla propia** aquí, y precedente del retiro no idempotente; `96-service-request-update` (Implemented) — precedente de forma: reemplazo total, y editar **no** escribe línea de tiempo · **Fecha:** 2026-08-26
> **Objetivo:** Añadir `PUT /api/v1/service-offers/{id}` y `PUT /api/v1/service-offers/{id}/withdraw`, para que el proveedor corrija por reemplazo total o retire su propia oferta mientras siga `sent`, escribiendo por fin el estado `withdrawn` que existe en el catálogo desde SPEC 77 y que hasta hoy nadie escribía.

Cuatro notas que la cabecera fija:

- **Este spec cierra la limitación que su propia documentación se puso.** `docs/service-offer.md` la llama «la limitación real de este endpoint» y la marca como **el spec obligatorio siguiente**, no como uno más de la lista. Desde SPEC 101 un proveedor que presupuesta un `direct` con un cero de más no tiene marcha atrás, y la única salida del residente es cancelar el trabajo.

- **Ni un campo nuevo, ni una tabla, ni un `hook_update_N`.** `withdrawn` está en `myapi_services_offer_statuses()` desde SPEC 77 y `field_offer_status` ya lo admite como valor. Los doce campos del cuerpo son los de SPEC 100. Este spec es **solo endpoints**: dos rutas, dos ramas de despachador y una compuerta.

- **La compuerta no se reescribe, se reordena.** `myapi_service_offer_eligibility()` decide hoy quién puede ofertar sobre una solicitud. Editar y retirar preguntan menos que ofertar —no hay unicidad que comprobar, y la categoría ya se comprobó el día que la oferta nació—, así que este spec escribe una compuerta propia y **corta**, en `includes/myapi.service_offer.inc`, en lugar de meterle banderas a la de SPEC 100. Una función con un `$is_edit` dentro es dos funciones mal pegadas.

- **Retirar es un cambio de estado y por eso es un `PUT` y no un `DELETE`.** La oferta se queda publicada, sigue apareciendo en los dos detalles, sigue en el archivo del proveedor y **sigue contando en `offers_count`** — la decisión explícita de SPEC 88: «cuántas ofertas recibí» es la pregunta que un listado contesta, y una retirada se recibió. Un `DELETE` prometería una desaparición que no ocurre.

---

## Alcance

**Dentro del alcance:**

- **`myapi.module`** (modificar) — **una** ruta nueva: `api/v1/service-offers/%/withdraw`, con `page callback` `myapi_service_offer_withdraw_dispatch`, `page arguments` `[3]`, acceso `TRUE` y `file` `resources/service_offer.resource.inc`. Cinco componentes, así que no compite con `api/v1/service-offers/%` (cuatro) ni con `api/v1/service-offers/provider/%` (cinco pero con literal en `[3]`, que Drupal 7 prefiere siempre al comodín). La ruta del `PUT` de edición **ya existe**: es la de SPEC 103.

- **`resources/service_offer.resource.inc`** (modificar):
  - `myapi_service_offer_item_dispatch($nid)` — la rama `PUT` deja de caer en `405` y llama a `myapi_service_offer_update($nid)`. `GET` sigue sirviendo el detalle del residente, sin tocar una línea. `POST` y `DELETE` siguen en `405`.
  - `myapi_service_offer_update($nid)` (nueva) — el endpoint completo, en el orden fijo de la sección «La compuerta».
  - `myapi_service_offer_withdraw_dispatch($nid)` (nueva) — `PUT` y nada más; el `405` antes del token y antes de cualquier consulta, como todo despachador del módulo.
  - `myapi_service_offer_withdraw($nid)` (nueva) — la compuerta, el `node_save()` y la respuesta.

- **`includes/myapi.service_offer.inc`** (modificar) — dos compuertas y una extracción:
  - `myapi_service_offer_write_gate($row, array $provider_ids)` (pura, nueva) — las condiciones que **editar y retirar comparten**, en orden. Devuelve el primer `error_code` que falla o `NULL`.
  - `myapi_service_offer_update_gate($row, array $provider_ids, $provider_row, $now)` (pura, nueva) — llama a la anterior y **le añade la licencia**. La diferencia entre los dos verbos es un punto de llamada, no una bandera dentro de una función.
  - `myapi_service_offer_apply_values($node, array $values)` (pura, **extraída**) — escribe los doce valores validados sobre un nodo, **incluido el borrado de los ausentes**. Sale de dentro de `myapi_service_offer_build_node()` de SPEC 100, que pasa a llamarla. **Cero cambio de comportamiento**, y `ServiceOfferCreateTest` en verde sin tocar un test es la prueba — el mismo procedimiento con el que SPEC 100 extrajo `myapi_service_request_detail_row()`.

- **`includes/myapi.i18n.inc`** (modificar) — las cuatro claves nuevas en `es` y `en`.

- **`docs/service-offer.md`** (modificar) — dos secciones nuevas siguiendo la plantilla de `CLAUDE.md`; el aviso ⚠️ «No puedes corregir una oferta ya enviada» **se reescribe** para apuntar a las dos rutas nuevas; y la lista «What is still not here» **pierde su primer punto**, que es justo el que este spec cumple.

- **`specs/services/100-service-offer-create.md`** (anotar) — el **Riesgo 1** («el proveedor no puede corregir su oferta») se marca **✅ Resuelto por SPEC 105**, con la convención de SPEC 42/104: el spec viejo conserva lo que decidió y apunta a quién lo cambió.

- **Tests**: `tests/unit/ServiceOfferUpdateTest.php` (nuevo) y `tests/unit/ServiceOfferWithdrawTest.php` (nuevo). `ServiceOfferCreateTest` **no se toca**: es la red de la extracción.

**Fuera del alcance (para specs futuras):**

- **Adjudicar una oferta.** `selected`, `field_assigned_offer` y la transición `offered → assigned` siguen siendo el lado del residente y otro spec. Este spec escribe `withdrawn` y nada más; `selected` sigue sin tener quien lo escriba.
- **Devolver la solicitud a `open` al retirarse la última oferta viva.** Decisión 6, tomada y explicada: el grafo de `myapi_services_request_transitions()` **no gana la arista `offered → open`**, y una solicitud puede quedarse en `offered` con cero ofertas vivas. Es la misma inconsistencia que el módulo ya admite desde que una oferta creada en el back office no mueve nada.
- **Línea de tiempo.** Ninguno de los dos verbos escribe `service_transaction`, ni siquiera sobre un `direct`. Decisión 7.
- **Un motivo del retiro.** El cuerpo del `withdraw` está vacío y no hay `field_offer_withdraw_reason`. Decisión 8.
- **Edición parcial (`PATCH`).** El `PUT` es reemplazo total. Un cuerpo parcial obligaría a distinguir «ausente» de «bórralo» y a evaluar las reglas condicionales contra la mezcla de lo guardado y lo enviado.
- **Que el residente rechace o retire una oferta.** `rejected` lo sigue escribiendo solo `myapi_service_request_reject_live_offers()` al cancelar (SPEC 95). Retirar es del proveedor, por definición.
- **Historial de cambios de una oferta.** Un `PUT` sobrescribe y no versiona. Lo que el residente vio ayer y lo que ve hoy no se pueden comparar, y ese es el precio del reemplazo total — anotado en Riesgos.
- **Ficheros, chat, notificaciones y expirar por `valid_until`.** Siguen exactamente donde SPEC 100 y SPEC 103 los dejaron: fuera.
- **El back office.** Un operador ya edita y retira desde `node/%/edit`, porque `withdrawn` está en los `allowed_values` desde SPEC 77. No se toca nada de eso.
- **`myapi.info`.** No hay fichero nuevo, así que **no cambia**. Se dice aquí para que nadie lo busque en el plan.

Un detalle del alcance que conviene ver antes de seguir, porque es el que más confunde a un cliente:

> **En `api/v1/service-offers/{id}` conviven dos actores distintos.** El `GET` es del **residente** —SPEC 103 responde `403` a un proveedor, incluso al que ofertó— y el `PUT` que este spec añade es del **proveedor**, y responderá `403` a un residente. Un despachador que enruta por método con una compuerta propia en cada rama es exactamente lo que permite eso; el proveedor lee su oferta por la otra ruta, `GET /api/v1/service-offers/provider/{id}`.

---

## Modelo de datos

**Ningún cambio de esquema.** Ni campo, ni instancia, ni bundle, ni tabla, ni catálogo, ni arista del grafo de transiciones, ni `hook_update_N`. `withdrawn` está en `myapi_services_offer_statuses()` y en los `allowed_values` de `field_offer_status` desde SPEC 77; lo único que faltaba era alguien que lo escribiera.

### Lo que se lee, y de dónde

| Dato | Función | De quién es |
|---|---|---|
| La oferta y sus quince claves | `myapi_service_offer_detail_row($nid)` | SPEC 103 |
| El estado de su solicitud | `myapi_service_request_detail_row($row->request_id)` | SPEC 100 (extraída de SPEC 89) |
| Los proveedores de la cuenta | `myapi_provider_role_provider_ids($uid)` | SPEC 78 |
| La licencia del proveedor (**solo el `PUT`**) | `myapi_service_offer_provider_row($provider_raw, $uid)` | SPEC 100 |

**Ninguna consulta nueva.** `myapi_service_offer_detail_row()` ya devuelve los quince alias que `myapi_service_offer_build()` lee, más `provider_raw` y `request_id`, y ya devuelve `FALSE` en los cuatro casos del `404`. Escribir otra sería una segunda definición de «qué es una oferta legible» — ver Decisión 14.

**La compuerta decide sobre `provider_raw` y nunca sobre `provider_id`.** Es el mismo split que el `@return` de esa función ya documenta: la columna unida es lo que se pinta y la cruda es lo que decide. Gatear sobre la unida respondería `403` a una cuenta **por su propia oferta** el día que su proveedor se despublica — y dejar viva una oferta equivocada porque te suspendieron la ficha es exactamente lo que este spec viene a arreglar.

### `PUT /api/v1/service-offers/{id}` — cuerpo

**Los doce campos de SPEC 100, sin uno solo de más ni de menos**, validados por `myapi_service_offer_validate_body()` **sin tocar una línea de ella**: `message`, `amount_type`, `amount`, `tax_included`, `valid_until`, `available_from`, `duration`, `duration_unit`, `includes`, `excludes`, `warranty_days`, `requires_visit`. Mismas diez reglas, mismo orden, mismos `error_code`.

**`provider_id` no es un campo de este cuerpo.** La oferta ya sabe de quién es, y un `provider_id` aquí solo puede contradecir a la URL. Si llega —con cualquier valor, incluso el correcto— responde `422 invalid_field` con `@field = provider_id`. Es una comprobación explícita en el recurso, porque `myapi_service_offer_validate_body()` ignora las claves que no conoce y en silencio sería peor que un error.

**Reemplazo total, y esto es lo que hay que leer dos veces:** un campo opcional **ausente se borra**. Un `PUT` sin `warranty_days` deja la oferta sin garantía, aunque ayer tuviera 90 días. La app debe mandar el formulario **entero**, precargado con lo que la oferta tiene hoy.

Lo que el `PUT` **no** toca, pase lo que pase en el cuerpo:

| Campo | Sigue valiendo |
|---|---|
| `node.uid` | La cuenta que la creó — **no** la que edita. Un dato histórico no se reescribe. |
| `node.created` | El instante en que se ofertó. `changed` sí se mueve, lo mueve `node_save()`. |
| `node.title` | El de SPEC 100. Nombra al proveedor y a la solicitud, y ninguno de los dos cambia. |
| `field_request` | La solicitud. Editar no muda la oferta de sitio. |
| `field_provider` | El proveedor. Cambiarlo sería otra oferta, no esta. |
| `field_offer_status` | Sigue `sent`. |
| Los tres del chat | Vacíos, como llevan desde SPEC 77. |

### `PUT /api/v1/service-offers/{id}/withdraw` — sin cuerpo

**Ni una clave.** Un cuerpo presente se ignora entero, incluido un JSON malformado: no hay nada que parsear, así que no hay nada que pueda fallar. Lo único que se escribe es `field_offer_status = 'withdrawn'`.

### La compuerta

Los dos verbos, en este orden. La primera condición que falla responde.

| # | Condición | Si falla | ¿Compartida? |
|---|---|---|---|
| 1 | `{id}` es entero positivo | `404 not_found` — **antes del token y sin consulta** | ✅ |
| 2 | Token válido | `401 missing_authorization` / `401 invalid_token` | ✅ |
| 3 | La cuenta tiene el rol `proveedor` | `403 provider_role_required` | ✅ |
| 4 | `myapi_service_offer_detail_row()` devuelve fila | `404 not_found` | ✅ |
| 5 | `provider_raw` ∈ `myapi_provider_role_provider_ids($uid)` | `403 service_offer_provider_not_owned` | ✅ |
| 6 | `status === 'sent'` | `409 service_offer_not_editable` / `409 service_offer_not_withdrawable` | ✅ (código distinto) |
| 7 | El estado de la solicitud ∈ (`open`, `offered`, `direct`) | `409 service_request_not_offerable` | ✅ |
| 8 | `myapi_services_provider_is_active(...)` | `403 service_offer_provider_not_active` | ❌ **solo el `PUT`** |
| 9 | El cuerpo, las diez reglas de SPEC 100 | `422 …` | ❌ **solo el `PUT`** |

Cuatro notas sobre este orden:

- **Las condiciones 4 a 7 son `myapi_service_offer_write_gate()`, y son literalmente las mismas para los dos verbos.** La 8 es lo único que el `PUT` añade, y lo añade **desde fuera**, en `myapi_service_offer_update_gate()`, que llama a la compartida y sigue. La diferencia entre editar y retirar es un punto de llamada, no un `if ($is_edit)` dentro de una función.
- **La licencia va después del estado de la solicitud, y es deliberado.** Las condiciones 5, 6 y 7 preguntan *«¿se puede escribir sobre esta oferta?»* y la 8 pregunta *«¿puedes mandar un presupuesto nuevo?»*. La segunda solo tiene sentido si la primera ya pasó.
- **Una solicitud `cancelled` no necesita regla propia.** Al cancelar, `myapi_service_request_reject_live_offers()` (SPEC 95) dejó la oferta en `rejected`, así que la condición 6 responde antes de que la 7 tenga nada que decir. La 7 solo se alcanza con estados que hoy únicamente escribe el back office, y falla cerrado.
- **Retirar dos veces responde `409`, no `200`.** Es el precedente literal de SPEC 95, que documenta su cancelación como «NOT IDEMPOTENT, on purpose»: un segundo `PUT` sobre una oferta ya retirada responde `service_offer_not_withdrawable`, que es la verdad, y no un `200` que fingiría haber hecho algo.

### Respuesta de éxito (200) — idéntica en los dos verbos

```json
{
  "success": true,
  "data": {
    "service_offer": {
      "id": 901,
      "provider": { "id": 41, "name": "Plomería Torres", "logo": "https://…/logo.png" },
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
  "message": "Oferta actualizada correctamente."
}
```

- **`200` y no `201`:** no nace nada. `201` mentiría sobre lo que acaba de pasar.
- **Las quince claves salen de `myapi_service_offer_build()`**, el mismo serializador de los tres sitios donde una oferta viaja hoy. En el `withdraw`, `status` vale `withdrawn`; en el `PUT`, sigue valiendo `sent`.
- **Ninguna de las dos hace una consulta de más para responder.** La fila que la compuerta ya leyó se reutiliza: el `withdraw` le cambia `status` y el `PUT` le sobrescribe los doce valores validados. Es el criterio de SPEC 100 — «un `SELECT` aquí costaría un viaje para averiguar lo que esta función ya sabe».
- **Aquí el logo del proveedor sí viaja**, al revés que en el `201` de SPEC 100, y no es una incoherencia: allí se decidió no pagar una consulta por él, y aquí viene **gratis** en la fila que la compuerta necesitaba de todas formas.
- **`request` sigue siendo hermano y no una decimosexta clave**, y trae el estado **sin moverlo** — Decisión 6. Viaja igualmente porque la pantalla del proveedor es la misma que la del `201` y un cliente que lee la misma forma en los tres sitios no tiene nada que ramificar.

### Claves i18n nuevas — cuatro

| Clave | `es` | `en` |
|---|---|---|
| `service_offer_updated` | Oferta actualizada correctamente. | Offer updated successfully. |
| `service_offer_withdrawn` | Oferta retirada correctamente. | Offer withdrawn successfully. |
| `service_offer_not_editable` | Esta oferta ya no se puede modificar. | This offer can no longer be edited. |
| `service_offer_not_withdrawable` | Esta oferta ya no se puede retirar. | This offer can no longer be withdrawn. |

Reutilizadas sin cambio: `not_found`, `missing_authorization`, `invalid_token`, `method_not_allowed`, `provider_role_required`, `service_offer_provider_not_owned`, `service_offer_provider_not_active`, `service_request_not_offerable`, `missing_field`, `invalid_field` y las cinco de las reglas del cuerpo de SPEC 100.

---

## Plan de implementación

Seis pasos. Los tres primeros no encienden nada: preparan el terreno y ninguno cambia una respuesta.

El **retiro va antes que la edición**, y no es un orden arbitrario: es el más pequeño de los dos, no depende de la extracción del paso 1, y **él solo ya desbloquea el caso que motiva el spec** — retirar y volver a ofertar, porque la condición 7 de SPEC 100 nunca bloqueó con una oferta `withdrawn`. Si el spec se parase después del paso 4, el proveedor del `direct` con el precio equivocado ya tendría salida.

1. **`includes/myapi.service_offer.inc` — la extracción.**
   `myapi_service_offer_apply_values($node, array $values)` sale de dentro de `myapi_service_offer_build_node()`, con los doce campos y el borrado de los ausentes. `build_node()` pasa a llamarla y se queda con lo suyo: el `type`, el `uid`, el `status`, el `title`, `field_request`, `field_provider` y `field_offer_status`.
   *Verificación: `php -l`; **`ServiceOfferCreateTest` en verde sin tocar un solo test** — la prueba de que la extracción no movió nada. Un test nuevo llama a `apply_values()` sobre un nodo que ya trae valores y comprueba que los ausentes quedan vacíos.*

2. **El mismo fichero — las dos compuertas.**
   `myapi_service_offer_write_gate($row, array $provider_ids)` con las condiciones 4 a 7, y `myapi_service_offer_update_gate($row, array $provider_ids, $provider_row, $now)`, que la llama y le añade la 8. Las dos puras, devolviendo el primer `error_code` o `NULL`. Nada las llama todavía.
   *Verificación: `php -l`; la matriz completa de las dos contra filas fixture, sin sitio arrancado — el mismo patrón con el que se prueba `myapi_service_offer_eligibility()`.*

3. **`includes/myapi.i18n.inc` — las cuatro claves.**
   `service_offer_updated`, `service_offer_withdrawn`, `service_offer_not_editable`, `service_offer_not_withdrawable`, en `es` y en `en`.
   *Verificación: `php -l`; `I18nTest` (paridad de los dos catálogos) en verde.*

4. **El retiro, entero: ruta, despachador y endpoint.**
   `api/v1/service-offers/%/withdraw` en `hook_menu()`, `myapi_service_offer_withdraw_dispatch()` con el `405` antes del token, y `myapi_service_offer_withdraw()`: compuerta compartida, `node_load()`, `field_offer_status = 'withdrawn'`, `node_save()`, respuesta. Un commit que deja algo funcionando de punta a punta.
   *Verificación: `ServiceOfferWithdrawTest`; `drush cc all` y un `PUT` real — la oferta responde `withdrawn` en los tres sitios donde viaja (`offers` del detalle del residente, `my_offers` del detalle del proveedor y el archivo de SPEC 102), y **un `POST` nuevo del mismo proveedor sobre la misma solicitud se acepta**.*

5. **La edición: la rama `PUT` y el endpoint.**
   `myapi_service_offer_item_dispatch()` deja de responder `405` al `PUT` y llama a `myapi_service_offer_update()`: compuerta de edición, el rechazo explícito de `provider_id`, `myapi_service_offer_validate_body()`, `node_load()`, `myapi_service_offer_apply_values()`, `node_save()`, respuesta. La ruta ya existe desde SPEC 103, así que aquí no se toca `hook_menu()`.
   *Verificación: `ServiceOfferUpdateTest`; un `PUT` real que baja un monto y **omite** `warranty_days`, y la oferta responde `warranty_days: null`; el `GET` del residente sobre la misma URL sigue respondiendo su detalle sin un cambio.*

6. **Documentación.**
   `docs/service-offer.md`: dos secciones nuevas con la plantilla de `CLAUDE.md`, el aviso ⚠️ «No puedes corregir una oferta ya enviada» **reescrito** para apuntar a las dos rutas, y la lista «What is still not here» **sin su primer punto**. Y la anotación **✅ Resuelto por SPEC 105** sobre el Riesgo 1 de SPEC 100.
   *Verificación: lectura contra la implementación; ninguna afirmación desfasada del tipo «no hay `PUT` ni `DELETE`» queda en `docs/`.*

**No hay `hook_update_N` ni `drush updb`.** Basta `drush cc all` tras el paso 4, por la ruta nueva.

---

## Criterios de aceptación

**Rutas y métodos**

- [ ] `PUT /api/v1/service-offers/{id}` deja de responder `405` y edita.
- [ ] `PUT /api/v1/service-offers/{id}/withdraw` existe y retira.
- [ ] `POST`, `PATCH` y `DELETE` sobre `/api/v1/service-offers/{id}` siguen en `405 method_not_allowed`.
- [ ] Cualquier método que no sea `PUT` sobre `.../withdraw` → `405`, **antes del token y sin una consulta**.
- [ ] `GET /api/v1/service-offers/{id}` sigue sirviendo el detalle del residente (SPEC 103) sin un byte de diferencia.
- [ ] `GET /api/v1/service-offers/provider` y `/provider/{id}` (SPECS 102 y 103) siguen respondiendo lo suyo: la ruta nueva de cinco componentes no se los come.

**La compuerta, en orden, en los dos verbos**

- [ ] `{id}` no entero positivo (`abc`, `0`, `-1`, `1,2`) → `404 not_found`, **sin consulta y antes del token**.
- [ ] Sin cabecera `Authorization` → `401 missing_authorization`; token inventado o caducado → `401 invalid_token`.
- [ ] Cuenta sin el rol `proveedor` → `403 provider_role_required`, **antes de leer la oferta**.
- [ ] Oferta inexistente, despublicada, de otro bundle, o **cuya solicitud está despublicada** → `404 not_found`, los cuatro indistinguibles entre sí.
- [ ] Oferta de un proveedor que no está en `myapi_provider_role_provider_ids($uid)` → `403 service_offer_provider_not_owned`.
- [ ] **Otra cuenta del mismo proveedor edita y retira sin problema**, aunque la oferta la mandara una tercera: la compuerta lee `field_provider`, no `node.uid`.
- [ ] **Un proveedor despublicado retira y edita su propia oferta**: la compuerta lee `provider_raw` y no la columna unida.
- [ ] Oferta en `selected`, `rejected` o `withdrawn` → `409 service_offer_not_editable` en el `PUT` y `409 service_offer_not_withdrawable` en el retiro.
- [ ] Retirar dos veces: el segundo `PUT` responde `409 service_offer_not_withdrawable` y **no** `200`.
- [ ] Solicitud en `assigned` o `closed` con la oferta todavía `sent` → `409 service_request_not_offerable` en los dos verbos.
- [ ] Solicitud `cancelled`: responde por la **condición 6** (`409 …_not_editable` / `…_not_withdrawable`), porque SPEC 95 ya dejó la oferta en `rejected`.
- [ ] Un residente que hace `PUT` sobre la URL de una oferta que recibió → `403 provider_role_required`.

**La licencia, y la asimetría entre los dos verbos**

- [ ] Proveedor con `field_license_expiry` vencida → `403 service_offer_provider_not_active` en el `PUT`.
- [ ] Ese mismo proveedor, con esa misma licencia vencida, **retira su oferta con `200`**.
- [ ] En el `PUT`, el `409` del estado de la solicitud gana al `403` de la licencia (la 7 va antes que la 8).

**El cuerpo del `PUT`**

- [ ] Las diez reglas de SPEC 100 responden aquí lo mismo, en el mismo orden y con los mismos `error_code`, contra la misma matriz de casos.
- [ ] `provider_id` en el cuerpo → `422 invalid_field` con `@field = provider_id`, **incluso cuando su valor es el proveedor correcto**.
- [ ] Todo `422` del cuerpo llega **después** de la compuerta entera: una oferta ajena con un cuerpo inválido responde `403`, nunca `422`.

**Reemplazo total**

- [ ] Un `PUT` que omite `warranty_days` sobre una oferta que tenía 90 → responde `warranty_days: null` y el campo queda vacío en el nodo.
- [ ] Lo mismo con `includes`, `excludes`, `valid_until`, `available_from`, `duration`/`duration_unit` y `tax_included`.
- [ ] Un `PUT` que cambia `amount_type` de `fixed` a `on_site_quote` **sin** `amount` → `200`, y `amount` queda a `null`.
- [ ] `requires_visit` ausente → `false`, nunca `null`.
- [ ] Tras el `PUT`: `node.uid`, `node.created`, `node.title`, `field_request`, `field_provider` y `field_offer_status` valen **exactamente lo que valían**. `node.changed` sí se mueve.

**El retiro**

- [ ] Tras el `withdraw`, `field_offer_status` vale `withdrawn` y **ningún otro campo de la oferta cambia**.
- [ ] Un cuerpo cualquiera en el `withdraw` —vacío, con claves, o JSON malformado— se ignora y responde `200`.
- [ ] **Tras retirar, un `POST /api/v1/service-requests/{id}/offers` del mismo proveedor sobre la misma solicitud se acepta** — el criterio que cierra el caso del `direct`.
- [ ] Tras retirar el presupuesto de un `direct` y mandar otro, la solicitud sigue en `direct` y el residente ve la oferta nueva.

**Lo que este spec no mueve**

- [ ] Ni el `PUT` ni el retiro cambian `field_request_status` de la solicitud. Una solicitud en `offered` cuya única oferta se retira **sigue en `offered`**.
- [ ] Ni el `PUT` ni el retiro escriben una `service_transaction`. La línea de tiempo del detalle responde exactamente las mismas entradas antes y después.
- [ ] `offers_count` **no cambia** al retirar: una oferta retirada se recibió.
- [ ] `myapi_service_request_update_gate()` sigue bloqueando la edición de la solicitud, porque cuenta cualquier oferta publicada.
- [ ] El grafo `myapi_services_request_transitions()` es byte a byte el de antes: no gana la arista `offered → open`.

**Las quince claves y la no-regresión**

- [ ] La oferta bajo `service_offer` es **byte a byte** la que el detalle del proveedor (SPEC 103) responde para ese mismo nid un segundo después, porque sale del mismo serializador.
- [ ] Las **seis primeras claves** siguen siendo las de SPEC 89, primeras y en su orden.
- [ ] Una oferta retirada sigue viajando entera en `offers`, en `my_offers` y en el archivo de SPEC 102, y `status=withdrawn` la filtra allí sin un cambio de código.
- [ ] `ServiceOfferCreateTest` pasa **sin tocar un solo test** tras la extracción del paso 1.
- [ ] `ServiceRequestDetailEndpointTest`, `ServiceRequestProviderDetailTest`, `ServiceOfferProviderListTest` y `ServiceOfferDetailTest` pasan sin tocarse.
- [ ] La suite unitaria entera en verde.

---

## Decisiones

| # | Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|---|
| 1 | Los dos verbos | **En un solo spec** | Retirar ahora, editar en un spec 106 | Comparten compuerta, consulta y respuesta. Partirlos habría escrito dos veces el razonamiento de «quién puede tocar esta oferta», que es el 80 % del trabajo; el `PUT` encima ya sale casi gratis del cuerpo y del validador de SPEC 100. |
| 2 | Si la edición hace falta, existiendo el retiro | **Sí, hace falta** | Solo retirar: el proveedor retira y vuelve a ofertar | Retirar y reofertar deja **dos nodos** en el listado del residente, pierde el `created` original y hace que `offers_count` cuente dos ofertas de una sola empresa. El requisito es que el residente vea **una oferta corregida**, no un rastro de intentos. |
| 3 | Semántica del `PUT` | **Reemplazo total** | `PATCH` parcial | Un cuerpo parcial obliga a distinguir «ausente» de «bórralo», y a evaluar las tres reglas condicionales (`amount` × `amount_type`, `tax_included` sin `amount`, `duration` sin `duration_unit`) contra una mezcla de lo guardado y lo enviado. Ahí es donde nacen los bugs. El total reutiliza `myapi_service_offer_validate_body()` **sin tocarla**, y es el precedente de SPEC 96. |
| 4 | Quién es el dueño a efectos de escritura | **`field_provider`** | `node.uid`, la cuenta que la mandó | Es el criterio que SPEC 89 ya fijó para el residente: manda el campo de dominio, no `node.uid`. Una empresa con dos empleados no puede quedarse con una oferta bloqueada porque la mandó el que hoy está de vacaciones. |
| 5 | Cómo se retira | **`PUT .../withdraw`** | `DELETE /api/v1/service-offers/{id}` | La oferta **no desaparece**: se queda publicada, viaja en los dos detalles, sale en el archivo de SPEC 102 y sigue contando en `offers_count`. Un `DELETE` prometería una desaparición que no ocurre. Además es la forma exacta de SPECS 23, 36 y 95 para «cambiar de estado un recurso ajeno a su ciclo normal». |
| 6 | La solicitud al retirarse la última oferta viva | **No se mueve** | Añadir `offered → open` al grafo y volver | La arista nueva la lee todo el que lee el grafo, obliga a un recuento en cada retiro y abre la carrera de dos proveedores retirando a la vez. Y no compra nada: `myapi_service_request_update_gate()` cuenta **cualquier** oferta publicada, así que el residente no recupera la edición de su solicitud por esta vía. Una solicitud en `offered` sin ofertas vivas es la misma inconsistencia que el módulo ya admite desde que una oferta del back office no mueve nada. |
| 7 | Línea de tiempo | **Ninguno de los dos verbos escribe** | Escribir en el retiro de un `direct`, como hizo SPEC 101 con el presupuesto | Una `service_transaction` es «una entrada por cambio de estado de la solicitud» desde SPEC 77, y aquí no se mueve ninguno. SPEC 101 hizo la excepción porque en un `direct` esa entrada era **la única señal** de que había pasado algo; aquí la señal es la oferta misma, que el residente está mirando. Editar tampoco la escribe, exactamente como SPEC 96. |
| 8 | Motivo del retiro | **Sin `reason`, cuerpo vacío** | `reason` en un `field_offer_withdraw_reason` nuevo | Es esquema nuevo —campo, instancia, `hook_update_N` y una fila más en el destructivo— para un dato que **nadie ha pedido leer**. Sin transacción donde meterlo (Decisión 7) y sin nadie a quien notificárselo, el motivo se quedaría escrito en una columna que ninguna respuesta sirve. |
| 9 | La licencia vencida | **Bloquea editar, no bloquea retirar** | La misma regla para los dos verbos | Editar es mandar un presupuesto nuevo, y quien no puede operar no presupuesta: es la condición 2 de SPEC 100 sin cambios. Retirar no compromete a nada, y **obligar a un proveedor a dejar viva una oferta equivocada porque se le caducó la licencia es exactamente el daño que este spec viene a arreglar**. |
| 10 | Qué estados son escribibles | **Solo `sent`** | Permitir editar una `selected` | `selected` significa que el residente ya eligió ese precio. Cambiarlo por detrás es la peor cosa que este endpoint podría hacer. `rejected` y `withdrawn` están muertas: no hay nada que corregir. Hoy nada escribe `selected`, y la regla se escribe igualmente para que el spec de la adjudicación no tenga que volver aquí. |
| 11 | Retirar dos veces | **`409`, no idempotente** | `200` en el segundo `PUT` | Precedente literal de SPEC 95, cuyo docblock dice «NOT IDEMPOTENT, on purpose». Un `200` fingiría haber hecho algo. El `409` con `service_offer_not_withdrawable` es la verdad, y la app puede mostrarlo sin alarma. |
| 12 | La categoría | **No se comprueba al editar** | Repetir la condición 6 de SPEC 100 | La oferta ya existe y la categoría se comprobó el día que nació. Volver a preguntarla dejaría a una empresa **sin poder corregir un precio** solo porque dejó de servir esa categoría después — el mismo error que SPEC 101 identificó al saltarse esa condición en un `direct` propio. |
| 13 | `provider_id` en el cuerpo del `PUT` | **`422 invalid_field`** | Ignorarlo en silencio | `myapi_service_offer_validate_body()` ignora las claves que no conoce, así que en silencio es lo que pasaría por omisión. Un cliente que manda `provider_id` cree estar cambiando algo; el silencio le confirma un cambio que no ocurrió. El mismo argumento con el que SPEC 100 dejó `request_id` fuera del cuerpo. |
| 14 | De dónde sale la fila | **`myapi_service_offer_detail_row()`, reutilizada** | Una consulta nueva de escritura | Ya devuelve los quince alias, el `provider_raw` que la compuerta necesita y el `request_id` del contexto, y ya devuelve `FALSE` en los cuatro casos del `404`. Escribir otra sería una segunda definición de «qué es una oferta legible», y el síntoma de que se separasen sería un `404` en una ruta y un `200` en otra. |
| 15 | La forma de las dos compuertas | **Una compartida más una que la llama** | Una función con un `$is_edit` dentro | Una función con una bandera de modo son dos funciones mal pegadas, y la bandera crece: el día que haya un tercer verbo habrá dos. La diferencia entre editar y retirar queda donde se ve, en el punto de llamada. |
| 16 | Cómo se escriben los doce campos | **`apply_values()` extraída de `build_node()`** | Reescribir la asignación en el endpoint de edición | Duplicar la escritura de doce campos es garantizar que dentro de tres meses el `POST` y el `PUT` guarden distinto. La extracción no cambia comportamiento y `ServiceOfferCreateTest` en verde **sin tocar un test** es la prueba — el procedimiento que SPEC 100 usó para `myapi_service_request_detail_row()`. |
| 17 | El logo del proveedor en la respuesta | **Viaja** | `null`, como en el `201` de SPEC 100 | Allí se decidió no pagar una consulta por un dato que la app del proveedor ya tiene. Aquí **viene gratis** en la fila que la compuerta necesitaba de todas formas, y devolverlo a `null` a propósito sería romper la identidad byte a byte con lo que el detalle sirve para ese mismo nid. |

---

## Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| 1 | **El reemplazo total borra en silencio.** Una app que mande el formulario a medias —o que olvide precargar un campo que el proveedor no tocó— deja la oferta sin garantía, sin plazo o sin duración, y responde `200` porque el cuerpo era válido. | Está en el contrato, en mayúsculas, en `docs/service-offer.md`, y hay un criterio de aceptación que lo fija (`PUT` sin `warranty_days` → `null`). La app precarga el formulario con lo que responde el detalle, que es **byte a byte** lo que el `PUT` devuelve. Es el mismo trato que SPEC 96 tiene con la edición de una solicitud, y allí no ha dado problemas. |
| 2 | **El residente no tiene forma de saber que una oferta cambió.** No hay historial, y las quince claves **no incluyen `changed`**: la oferta que mira hoy puede tener otro precio que la de ayer, sin ninguna marca. En el peor caso decide sobre un número que ya no existe. | Asumido y explícito, y la salida está diseñada aunque no implementada: una decimosexta clave `updated` con el `node.changed`, que **pertenece al spec que traiga las notificaciones de servicios** — avisar de un cambio y poder verlo son el mismo problema, y resolver la mitad aquí obligaría a rehacerla allí. Mientras tanto, `valid_until` sigue siendo el plazo que el propio proveedor se puso. |
| 3 | **Una solicitud en `offered` con cero ofertas vivas.** Es el precio de la Decisión 6, y un operador que lea el back office verá un estado que no describe lo que hay. | No es nuevo: el módulo ya lo admite desde que una oferta creada desde el back office no mueve el estado, y `myapi_service_request_update_gate()` está escrito precisamente para no fiarse del estado y contar ofertas. El día que se quiera arreglar, la arista `offered → open` es un spec propio con su carrera que resolver. |
| 4 | **Dos actores en la misma URL.** `GET /api/v1/service-offers/{id}` es del residente y el `PUT` es del proveedor, así que cada uno recibe `403` en el verbo del otro. Un cliente que lo descubra con el token equivocado concluirá que el endpoint está roto. | La doc lo dice en una nota propia, con las dos rutas que cada actor debe usar para leer. Y es la consecuencia de una regla buena: el despachador enruta por método y **cada rama tiene su compuerta**, que es lo que permite que la lectura no exija rol y la escritura sí. |
| 5 | **Retirar y volver a ofertar sigue dejando dos nodos.** El camino no desaparece porque exista el `PUT`, y `offers_count` cuenta las dos: un residente puede leer «2 ofertas» de una única empresa. | Es la decisión explícita de SPEC 88 —«cuántas ofertas recibí» incluye las retiradas— y no se toca. La edición existe justo para que ese camino deje de ser el único, y la app debe ofrecer «corregir» y no «retirar y volver a enviar» siempre que la oferta siga `sent`. |

---

## Lo que **no** está en este spec

- **Adjudicar una oferta**: `selected`, `field_assigned_offer` y `offered → assigned`. Sigue sin existir quien escriba `selected`.
- **Que la solicitud vuelva a `open`** al quedarse sin ofertas vivas. El grafo no gana ninguna arista.
- **Cualquier entrada de línea de tiempo**, en cualquiera de los dos verbos, incluido el `direct`.
- **Un motivo del retiro**, y por tanto `field_offer_withdraw_reason`.
- **Edición parcial (`PATCH`)**.
- **Historial de cambios de una oferta**, y la clave `updated` que lo haría visible.
- **Que el residente rechace o retire una oferta.** `rejected` lo sigue escribiendo solo la cancelación de SPEC 95.
- **Ficheros de la oferta, chat, notificaciones y expirar por `valid_until`.** Exactamente donde SPECS 100 y 103 los dejaron.
- **El back office**, que ya edita y retira desde `node/%/edit` desde SPEC 77.

Cada uno de ellos, si llega, va en su propio spec.
