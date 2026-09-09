# 120 — El presupuesto de una `direct` adjudica el trabajo (`POST /api/v1/service-requests/{id}/offers`)

> **Estado:** Implemented · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer`, del catálogo `myapi_services_offer_statuses()` donde vive `selected`, del campo `field_assigned_offer` y del grafo `myapi_services_request_transitions()`; `87-service-request-direct-status` (Implemented) — dueña del estado `direct`, de que sea una **raíz** del grafo y de que cerrar desde él exija calificación; `90-service-request-create` (Implemented) — la que escribe `field_assigned_provider` **al nacer** una `direct`, que es lo que este spec da por hecho y no reescribe; `100-service-offer-create` (Implemented) — dueña del endpoint, de `myapi_service_offer_eligibility()`, de `myapi_service_offer_build_node()` y del orden de las tres escrituras; `101-service-offer-on-direct` (Implemented) — dueña de la condición 5 que deja presupuestar una `direct` propia, y **de la decisión central que este spec revierte**; `105-service-offer-update-withdraw` (Implemented) — dueña de la regla «solo se edita y se retira una oferta `sent`», que es el precio de este spec; `106-service-offer-accept` (Implemented) — dueña de `myapi_service_transaction_record()` y de `myapi_service_transaction_accept_comment()`, **las dos que este spec reutiliza en vez de duplicar**, y del orden de escritura que hace que `myapi_service_transaction_sync_request_status()` no vuelva a guardar la solicitud; `110-service-offer-received-notification` (Implemented) — dueña del aviso al residente, cuyo texto este spec ramifica sin duplicar el aviso · **Fecha:** 2026-09-09
> **Objetivo:** Que el presupuesto que el proveedor adjudicado envía sobre su propia solicitud `direct` **adjudique el trabajo en la misma pasada**: la oferta nace `selected`, la solicitud pasa a `assigned` con `field_assigned_offer` escrito, y el residente recibe **un solo** aviso que dice lo que de verdad pasó.

Cuatro notas que la cabecera fija:

- **Este spec revierte la decisión central de SPEC 101, y lo hace a sabiendas.** Aquella escribió que «la única opción que no rompe nada es la que no toca el estado», y SPEC 107 dejó el movimiento en manos del residente con `PUT /api/v1/service-offers/{id}/accept`. La objeción que las dos hacían era real y sigue siendo cierta; lo que ha cambiado es la valoración del precio a pagar, no los hechos. Ver «Decisiones tomadas y descartadas».

- **En una `direct` el residente YA adjudicó, y lo hizo al elegir la empresa.** Pedirle que adjudique una segunda vez —una al nombrar la compañía, otra al aceptar su precio— era un paso que no llevaba ninguna decisión dentro: hay un proveedor, un presupuesto y ninguna ronda que resolver, así que la segunda aceptación solo podía decir que sí a lo único que había encima de la mesa.

- **Ni un campo nuevo, ni una tabla, ni una arista nueva en el grafo, ni un `hook_update_N`.** `selected` está en el catálogo de estados de oferta, `assigned` en el de solicitudes, `field_assigned_offer` está instalado desde SPEC 77 y la arista `direct → assigned` la dibujó SPEC 107. Este spec solo cambia **quién la recorre y cuándo**.

- **La ronda de licitación no se toca.** Una oferta sobre una solicitud `open` u `offered` sigue naciendo `sent` y sigue sin mover nada hacia `assigned`: ahí el residente tiene entre qué elegir, y elegir es suyo. `PUT /api/v1/service-offers/{id}/accept` sigue siendo el verbo de esa ronda.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.services_common.inc`** (modificar) — **solo documentación**. Se reescribe el párrafo `DISCARDED: assigning automatically when a 'direct' receives an offer` del docblock de `myapi_services_request_transitions()`, que a partir de este spec documentaría lo contrario de lo que hace el código. Se ajustan el grafo ASCII, la viñeta de `direct → assigned` y la línea del catálogo que dice que `selected` no lo escribe nadie. **Ni una línea de código cambia en este fichero**: la arista ya existía.

- **`includes/myapi.service_offer.inc`** (modificar) — `myapi_service_offer_build_node()` gana un **sexto parámetro** `$status`, con valor por defecto `MYAPI_SERVICES_OFFER_STATUS_SENT`, y la línea que escribía la constante a pelo pasa a escribir el parámetro. Un parámetro con defecto y **no un segundo constructor**: los otros siete valores que fija el servidor son idénticos en los dos caminos, y una segunda función serían dos definiciones de qué es una oferta.

- **`resources/service_offer.resource.inc`** (modificar) — `myapi_service_offer_create()`, en sus pasos 9a a 10:
  - **9a** — `$direct_quote` se calcula aquí (antes se calculaba en 9c) porque ahora dependen de él tres pasos, y calcularlo una vez es lo que impide que discrepen. De él sale `$offer_status`, que se pasa al constructor.
  - **9b** — una **segunda rama**, `elseif`, para la arista `direct → assigned`: `node_load()`, `field_request_status` a `assigned`, `field_assigned_offer` al `nid` de la oferta recién creada, `node_save()`. `field_assigned_provider` **no se toca**. La transición se **pregunta** a `myapi_services_transition_allowed()`, nunca se transcribe. Rama defensiva idéntica a la de arriba: si el `node_load()` falla en una carrera, se registra en `watchdog` y no se aborta, porque la oferta ya está escrita y es correcta.
  - **9c** — tres formas en vez de dos, y el estado registrado es **siempre** el que la solicitud tiene de verdad después de 9b: `assigned` cuando se adjudicó, `offered` cuando fue la primera oferta de una ronda, y `direct` repetido **solo** por la rama defensiva. El bloque que construía el nodo de la transacción a mano pasa a llamar a `myapi_service_transaction_record()` (SPEC 106) — una definición de más de los mismos cuatro campos, que la regla 3 de `CLAUDE.md` prohíbe.
  - **9d** — el contexto del aviso gana `direct_award`.
  - **10** — `status` de la oferta sale de `$offer_status`, y el `status` de la solicitud de una cadena `if/elseif/else` sobre las dos banderas, nunca de `$request_row->status` a secas, que sigue diciendo lo que decía **antes** de 9b.

- **`includes/myapi.service_request_notification.inc`** (modificar) — dos funciones puras nuevas y una rama:
  - `myapi_service_offer_direct_push_title()` — `'Tu proveedor tomó el trabajo'`.
  - `myapi_service_offer_direct_push_body($subject, $provider_name, $amount_text)` — **llama a su hermana** y le añade una cuarta línea. Las tres líneas etiquetadas no se repiten a mano.
  - `myapi_service_offer_resident_mail_params()` gana `direct_award`, booleano y **sin escapar**: el formateador ramifica sobre él, no lo imprime.
  - `myapi_service_request_notify_offer_received()` elige el par de textos. **El `type` no cambia** (`service_offer_received`), ni el `source_nid`, ni el deep link, ni la clave de correo, ni el número de avisos.

- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_service_request_offer_resident()` ramifica el asunto y `myapi_mail_service_request_offer_resident_html()` ramifica **una** frase de la entrada. Todo lo demás del correo es idéntico.

- **Docs**: `docs/service-offer.md` (la tabla de las tres escrituras, el sibling `request` del `201` y la sección «Quoting a `direct` request` entera), `docs/service-request.md` (la fila de `assigned_offer` en el nacimiento de una `direct`), `docs/service-request-notifications.md` (la variante de textos de SPEC 110).

- **Tests**: `ServiceOfferAcceptTest::testQuotingADirectAssignsNothing` se convierte en `testQuotingADirectAwardsIt` y **cambia de sentido**; `ServiceRequestNotificationTest` gana cuatro casos para el par de textos nuevo.

**Fuera del alcance (para specs futuras):**

- **Deshacer una adjudicación directa.** Un presupuesto equivocado sobre una `direct` no tiene marcha atrás por parte del proveedor: la salida es que el residente cancele. Es el precio de este spec y está en Riesgos.
- **Que el residente rechace un presupuesto directo sin cancelar la solicitud.** No existe y este spec no lo añade.
- **Notificar al `backend`.** Los tres avisos de adjudicación de SPEC 112 salen del endpoint del residente y **no** se disparan aquí. La adjudicación directa la conoce el residente y nadie más.
- **La ronda de licitación**, en cualquiera de sus formas.
- **Cualquier cambio de esquema, de catálogo o del grafo.**

---

## Modelo de datos

**Ningún cambio de esquema.** Ni campo, ni instancia, ni bundle, ni tabla, ni catálogo, ni arista, ni `hook_update_N`. Todo lo que este spec escribe son valores que el esquema admite desde SPEC 77 y una arista que SPEC 107 dibujó.

### Lo que cambia en la base de datos, respecto de SPEC 101

| | Antes (SPEC 101) | Ahora (SPEC 120) |
|---|---|---|
| `field_offer_status` de la oferta | `sent` | **`selected`** |
| `field_request_status` de la solicitud | `direct`, sin guardar el nodo | **`assigned`** |
| `field_assigned_offer` | vacío | **el `nid` de la oferta** |
| `field_assigned_provider` | escrito al nacer, intacto | escrito al nacer, **intacto** |
| `node.changed` de la solicitud | intacto | **ahora** (el nodo se guarda) |
| `field_request_status` de la transacción | `direct` repetido | **`assigned`** |

### Las tres escrituras, en este orden

1. **La oferta**, `selected`.
2. **La solicitud**, a `assigned` con `field_assigned_offer`.
3. **La transacción**, con `assigned` y el comentario de la adjudicación.

**El orden es el contrato, y por la razón de SPEC 106:** la solicitud se guarda **antes** que la transacción, de modo que `myapi_service_transaction_sync_request_status()` (SPEC 94), que salta en el `hook_node_insert()` de la transacción, compare dos estados iguales y **no** vuelva a guardar la solicitud. Quien toque cualquiera de los dos lados tiene que conservar esa propiedad.

**No es atómico**, con el mismo precio y por la misma razón que SPEC 100 y SPEC 106 aceptan.

### El comentario de la línea de tiempo

`myapi_service_transaction_accept_comment($provider_name, $amount, $amount_type)`, **la misma que escribe `PUT /api/v1/service-offers/{id}/accept`**. Los dos son el mismo hecho —un trabajo comprometido a un precio— y dos frases para él habrían divergido en tres meses.

`myapi_service_offer_direct_quote_comment()` **sobrevive** y no se borra: es lo que escribe la rama defensiva de 9b, donde la solicitud de verdad no se movió.

### Respuesta de éxito (201)

Igual que la de SPEC 100, con dos valores distintos sobre una `direct` propia:

```json
{
  "success": true,
  "data": {
    "service_offer": { "status": "selected", "...": "..." },
    "request": { "id": 128, "status": "assigned" }
  },
  "message": "Oferta enviada correctamente."
}
```

**Ninguna clave nueva, ningún `error_code` nuevo, ninguna clave i18n nueva.** El contrato de la app no cambia de forma: cambian dos valores dentro de él.

---

## Plan de implementación

1. El docblock de `myapi_services_request_transitions()` y la línea del catálogo de `selected`.
2. El sexto parámetro de `myapi_service_offer_build_node()`.
3. Los pasos 9a a 10 de `myapi_service_offer_create()`.
4. Los dos constructores de texto y la rama del orquestador del aviso.
5. El formateador del correo.
6. Los tests que afirmaban lo contrario, y los nuevos.
7. La documentación de los cuatro sitios.
8. `drush cc all`.

---

## Criterios de aceptación

### La oferta

- Sobre una `direct` adjudicada al proveedor que oferta, nace **`selected`**.
- Sobre `open` u `offered`, sigue naciendo **`sent`**.
- El `status` del cuerpo se ignora en los dos casos: no es un campo de esta petición.

### La solicitud

- Sobre una `direct` propia pasa a **`assigned`** y `field_assigned_offer` apunta a la oferta recién creada.
- **`field_assigned_provider` no se reescribe.** La condición 5 de la compuerta ya probó que es el mismo proveedor.
- Sobre `open` pasa a `offered`; sobre `offered` **no se guarda**.
- Nunca pasa a `offered` desde `direct`: eso permitiría cerrarla **sin calificar** al proveedor.

### La transacción

- Registra `assigned` cuando se adjudicó, con la frase de `myapi_service_transaction_accept_comment()`.
- Registra `offered` en la primera oferta de una ronda.
- Registra `direct` repetido **solo** si el `node_load()` de 9b falló, y ese caso deja rastro en `watchdog`.
- Se escribe **después** de la solicitud, siempre.

### Lo que se cierra solo, sin código nuevo

- **Editar o retirar la oferta** responde `409` (`service_offer_not_editable` / `service_offer_not_withdrawable`): `myapi_service_offer_write_gate()` exige `sent` y la oferta dice `selected`.
- **Una segunda oferta** sobre esa solicitud responde `409 service_request_not_offerable`: ya no está en `direct`, y `assigned` no está en `$biddable`.
- **`PUT /api/v1/service-offers/{id}/accept` del residente** responde `409 service_offer_not_acceptable`: la condición 6 de su compuerta exige `sent`, y es la primera del gate, así que gana sobre la 7.

### Lo que no se rompe

- **Editar la solicitud**: el residente ya la perdía con la primera oferta, porque `myapi_service_request_update_gate()` exige además `offer_count === 0`.
- **Cancelarla**: `assigned → cancelled` ya estaba en el grafo.
- **Cerrarla y calificar**: `myapi_services_close_requires_rating()` devuelve `TRUE` para `assigned` igual que para `direct`. Y mejora: la calificación ya puede colgar de una oferta.
- **El chat**: el hilo existe desde que hay `field_assigned_provider` más oferta viva, y `selected` es viva.
- **El mercado**: excluye por `assigned_provider` nulo, y una `direct` nace con él escrito.

### El aviso

- **Uno solo**: una fila de bandeja, un push, un correo. No hay aviso de «solicitud adjudicada» aparte.
- Mismo `type`, mismo `source_nid`, mismo deep link, misma clave de correo.
- Cambian el título, la cuarta línea del cuerpo, y el asunto y la entrada del correo.
- `on_site_quote` no necesita caso especial: la línea `Monto` ya dice «A presupuestar en sitio».

---

## Decisiones tomadas y descartadas

**1. Revertir la decisión central de SPEC 101, en vez de dejarla y añadir una ruta de «aceptar» para el proveedor.**
Una ruta propia habría movido la solicitud a `assigned` **antes** de que existiera un precio, y entonces `assigned` habría dejado de significar «precio acordado». Peor: el residente ya no habría podido aceptar el presupuesto después, porque las únicas salidas de `assigned` son `closed` y `cancelled`, así que su `PUT .../accept` habría respondido `409 service_request_not_assignable`. La pantalla de presupuestar habría producido una oferta que nadie podía aceptar nunca.

**2. Descartado: un estado intermedio nuevo entre `direct` y `assigned`.**
Habría costado un valor de catálogo, dos aristas, un `hook_update_N` y la propagación a todo lo que hoy ramifica por `direct` o por `assigned` —el cierre con calificación, el mercado, la edición, el chat, las notificaciones—. Un estado nuevo para representar «el proveedor dijo que sí pero todavía no dijo cuánto», que es exactamente el hueco que este spec elimina en vez de nombrar.

**3. La objeción de SPEC 107 se responde con el estado de la oferta, no con la arista.**
Aquella rechazó esto porque congelaría un presupuesto equivocado: editar y retirar exigen `sent` (SPEC 105), así que un cero de más quedaría inarreglable. **Sigue siendo verdad** y es el precio, dicho y no escondido. Lo que el módulo compra a cambio es que el precio de un trabajo directo se acuerde en el momento en que se nombra, entre las dos únicas partes que hay.

**4. La bandera sale de `$request_row->status` y nunca del cuerpo.**
`status` no es un campo de esta petición, y que lo fuera dejaría al cliente decidir si se adjudica.

**5. `field_assigned_provider` no se reescribe.**
SPEC 90 lo escribió al nacer la solicitud y ya apunta a este mismo proveedor. Reescribirlo sería una escritura que no cambia nada.

**6. Se reutiliza `myapi_service_transaction_accept_comment()` en vez de escribir una frase nueva.**
Regla 3 de `CLAUDE.md`. Y de paso el bloque en línea que construía la transacción a mano pasa a `myapi_service_transaction_record()`: era una segunda definición de los mismos cuatro campos, heredada de antes de que SPEC 106 la extrajera.

**7. Un solo aviso, con textos propios.**
El proveedor hace **un** acto —le pone precio al trabajo que ya era suyo, y eso lo valora y lo compromete— así que produce **un** aviso. Mandar el texto de SPEC 110 y detrás uno de adjudicación serían dos alertas para un solo acto. El `type` no cambia porque lo que pasó sigue siendo que llegó una oferta, y la bandeja agrupa por `type`.

**8. El `201` lee las banderas y no `$request_row->status`.**
Esa fila sigue diciendo lo que decía antes de 9b. Leerla a secas habría devuelto `direct` sobre una solicitud ya `assigned`.

---

## Riesgos identificados

**1. Un presupuesto equivocado sobre una `direct` es irreversible para el proveedor.** `selected` cierra editar y retirar. La salida es que el residente cancele la solicitud. Es el riesgo que SPEC 107 no quiso correr; se corre a sabiendas y está avisado en `docs/service-offer.md` con un bloque propio.

**2. Las tres escrituras no son atómicas.** Si el `node_load()` de 9b falla, queda una oferta `selected` colgando de una solicitud `direct`. La rama defensiva lo registra en `watchdog` con el `nid` de las dos, la transacción dice la verdad (`direct`), y el `201` devuelve `status: "selected"` con `request.status: "direct"` — una combinación que el cliente tiene que saber leer. Ventana mínima y ya asumida por SPEC 100 y SPEC 106 para sus propias escrituras.

**3. Un cliente que asumiera `direct ⇒ 0 ofertas` se rompe.** Esa suposición ya era falsa desde SPEC 101 —y desde siempre para las ofertas creadas en el back office—, que es justo por lo que la compuerta de edición cuenta ofertas en vez de fiarse del estado.

**4. El hilo de chat de una `direct` nace ahora por un acto del PROPIO proveedor.** Antes lo creaba la adjudicación del residente, y el proveedor se enteraba más tarde, con un token ya al día. Ahora la pantalla del proveedor puede intentar leerlo en el mismo instante con la credencial que ya tenía en la mano, acuñada cuando la oferta no existía: los custom claims se congelan en el `signInWithCustomToken()` y no se recalculan solos. Es una carrera de propagación del claim, no un fallo del cálculo de pertenencia —que no mira ni el estado de la oferta más allá de «viva» ni el de la solicitud—, y se resuelve en el cliente refirmando la credencial y reabriendo el listener. Detectado en pruebas reales tras este spec.

---

## Lo que **NO** está en este spec

- Deshacer o corregir una adjudicación directa.
- Rechazar un presupuesto directo sin cancelar la solicitud.
- Notificar al `backend` de una adjudicación directa.
- Cualquier cambio en la ronda de licitación.
- Cualquier cambio de esquema, de catálogo o del grafo de transiciones.
