# 121 — El proveedor rechaza una `direct` (`PUT /api/v1/service-requests/{id}/reject`)

> **Estado:** Implemented · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del catálogo `myapi_services_request_statuses()`, donde `cancelled` ya existe, y del grafo `myapi_services_request_transitions()`, donde la arista `direct → cancelled` ya está dibujada; `78-provider-role` (Implemented) — dueña de `myapi_provider_role_is()` y de `myapi_provider_role_provider_ids()`, las dos mitades de la compuerta de rol; `87-service-request-direct-status` (Implemented) — dueña del estado `direct`; `90-service-request-create` (Implemented) — la que escribe `field_assigned_provider` al nacer una `direct`, que es contra lo que este spec gatea; `92-service-request-initial-transaction` (Implemented) — dueña de la forma de una `service_transaction`; `95-service-request-cancel` (Implemented) — dueña de la ruta hermana `/cancel`, del orden de las tres escrituras, del validador del motivo y **de la frase que anuncia este spec**: «el proveedor asignado nunca cancela — "yo me bajo de este trabajo" es otra acción, con otro actor»; `99-service-request-provider-detail` (Implemented) — dueña del criterio «el rol se comprueba antes de cargar la fila»; `106-service-offer-accept` (Implemented) — dueña de `myapi_service_transaction_record()` y del criterio «se lee la columna RAW y nunca la unida»; `110-service-offer-received-notification` (Implemented) — precedente del aviso al residente, cuya forma este spec copia; `113-service-request-cancel-notifications` (Implemented) — dueña de `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_CANCELLED`, que apunta al revés y **no** se reutiliza; `120-service-offer-direct-award` (Implemented) — la que decide **cuándo se cierra esta puerta**: en cuanto el proveedor presupuesta, la solicitud es `assigned` y ya no se puede rechazar · **Fecha:** 2026-09-09
> **Objetivo:** Dar al proveedor adjudicado de una solicitud `direct` una forma de decir **que no puede tomar el trabajo**, con un motivo obligatorio que el residente pueda leer, dejando la solicitud `cancelled` y el rechazo anotado en la línea de tiempo.

Cuatro notas que la cabecera fija:

- **Este spec escribe la acción que SPEC 95 anunció y no hizo.** El docblock de `myapi_service_request_cancel()` dice literalmente que el proveedor asignado nunca cancela, que eso es otra acción, con otro actor «y probablemente otro estado destino». Resultó compartir el estado destino y nada más: el actor es el proveedor, la compuerta es `field_assigned_provider` en vez de `field_requester`, y el motivo es **obligatorio** donde allí es opcional.

- **Solo desde `direct`, y ahí está todo el sentido del verbo.** «No puedo tomar este trabajo» se dice **antes** de ponerle precio. En cuanto el proveedor presupuesta, SPEC 120 mueve la solicitud a `assigned` y el trabajo queda comprometido a un importe; desde ahí esto responde `409` y la única salida es que el residente cancele.

- **Aterriza en `cancelled` y nunca de vuelta en `open`.** `direct → cancelled` es una arista del grafo desde SPEC 77, así que este spec no inventa ningún estado ni ninguna arista: lo que añade es un **segundo actor** autorizado a recorrer esa. Devolver el trabajo al mercado abierto se consideró y se descartó — ver Decisiones.

- **Ni un campo nuevo, ni una tabla, ni un catálogo, ni una arista.** El único `hook_update_N` del spec no toca el esquema: registra la clave de correo nueva en `MyapiHtmlMailSystem`.

---

## Alcance

**Dentro del alcance:**

- **`myapi.module`** (modificar) — **una** ruta nueva, `api/v1/service-requests/%/reject`, con `page callback` `myapi_service_request_reject_dispatch`, `page arguments` `[3]`, acceso `TRUE` y `file` `resources/service_request.resource.inc`. Cinco componentes, como `/cancel`, `/close` y `/offers`, y se distingue de las tres por el literal de la quinta posición. Y **un `case` nuevo** en el `hook_mail()`.

- **`resources/service_request.resource.inc`** (modificar) — cuatro funciones nuevas y ni una línea tocada de las existentes:
  - `myapi_service_request_reject_dispatch($nid)` — `PUT` y nada más; el `405` antes del token.
  - `myapi_service_request_reject($nid)` — el endpoint entero, en los ocho pasos de «La compuerta».
  - `myapi_service_request_reject_gate($assigned_provider_id, $status, array $provider_ids)` — pura, las dos condiciones del paso 6.
  - `myapi_service_request_validate_reject_reason($body)` — pura, el motivo **obligatorio**.

- **`includes/myapi.service_transaction.inc`** (modificar) — `myapi_service_request_reject_comment($provider_name, $reason)`, pura, con las cuatro variantes de nombre/motivo.

- **`includes/myapi.service_request_notification.inc`** (modificar) — la constante de tipo `MYAPI_NOTIFICATION_TYPE_SERVICE_REQUEST_REJECTED`, la clave de correo `MYAPI_SERVICE_REQUEST_REJECTED_RESIDENT_MAIL_KEY`, dos constructores de texto puros, los params del correo y el orquestador `myapi_service_request_notify_rejected()`.

- **`includes/myapi.mail.inc`** (modificar) — el formateador y el constructor de HTML del correo al residente.

- **`includes/myapi.i18n.inc`** (modificar) — dos claves en `es` y `en`: `service_request_rejected` (mensaje de éxito) y `service_request_not_rejectable` (error 409).

- **`myapi.install`** (modificar) — la clave nueva entra en `myapi_html_mail_keys()`, y `myapi_update_7045()` vuelve a llamar a `myapi_mail_system_register()`. **No hay cambio de esquema**: sin ese `updb` el correo llegaría con el HTML aplanado a texto plano por `DefaultMailSystem`.

- **Docs**: `docs/service-request-provider.md` (la sección del endpoint, siguiendo la plantilla de `CLAUDE.md`) y `docs/service-request-notifications.md` (el aviso).

- **Tests**: `tests/unit/ServiceRequestRejectTest.php` (nuevo), `ServiceRequestNotificationTest` (los textos), `ServiceRequestDetailEndpointTest` (la función nueva entra en la lista blanca de las que pueden llamar a `node_load()`), `ServicesInstallTest` (el techo de la numeración sube a 7045).

**Fuera del alcance (para specs futuras):**

- **Rechazar desde cualquier otro estado.** Solo `direct`. Una vez presupuestada, la solicitud es `assigned` y esto responde `409`.
- **Devolver la solicitud al mercado** (`direct → open`). Descartado, ver Decisiones.
- **Que el proveedor rechace una oferta suya sin tumbar la solicitud.** Para eso está `PUT /api/v1/service-offers/{id}/withdraw`, y solo mientras la oferta sea `sent`.
- **Avisar al `backend`.** Fuera de la audiencia de este spec.
- **Distinguir en `field_request_status` una cancelación del residente de un rechazo del proveedor.** Las dos son `cancelled`; lo que las separa es la entrada de la línea de tiempo. Ver Decisiones.
- **Cualquier cambio de esquema, de catálogo o del grafo.**

---

## Modelo de datos

**Ningún cambio de esquema.** `cancelled` está en `myapi_services_request_statuses()` y `direct → cancelled` en `myapi_services_request_transitions()`, las dos desde SPEC 77. El único `hook_update_N` del spec registra una clave de correo.

### Petición

```
PUT /api/v1/service-requests/{id}/reject
Authorization: Bearer <access_token>
Content-Type: application/json

{ "reason": "No tengo disponibilidad esta semana." }
```

| Campo | Tipo | Obligatorio | Reglas |
|-------|------|:---:|--------|
| `reason` | string | **Sí** | Aplanado a texto plano, luego **1–255 caracteres**. Solo espacios cuenta como ausente. |

### La compuerta — ocho pasos, y el orden es el contrato

| # | Comprueba | Responde |
|---|---|---|
| 1 | La forma del `{id}` | `404 service_request_not_found` |
| 2 | El token | `401 missing_authorization` / `invalid_token` |
| 3 | El rol `proveedor` | `403 provider_role_required` |
| 4 | Que la cuenta opere algún proveedor | `403 service_request_forbidden` |
| 5 | El nodo existe, es del bundle y está publicado | `404 service_request_not_found` |
| 6a | `field_assigned_provider` ∈ mis proveedores | `403 service_request_forbidden` |
| 6b | El estado es `direct` **y** el grafo admite `direct → cancelled` | `409 service_request_not_rejectable` |
| 7 | El motivo | `422 missing_field` / `invalid_field` / `field_too_long` |

- **Los pasos 3 y 4 corren antes de cargar el nodo**, criterio de SPEC 99: quién eres no depende de qué `nid` pediste, así que una cuenta sin rol nunca aprende qué ids existen.
- **6a va antes que 6b**: «no es tuya» es lo primero cierto de una solicitud que no tienes, así que un proveedor hurgando en la de otro recibe `403` y nunca el `409` que le confirmaría el estado.
- **Se lee la columna RAW**, el `target_id` del propio nodo, nunca un proveedor unido por `JOIN`: uno despublicado llegaría como `NULL` y se colaría por debajo. Es la decisión 15 de SPEC 106, leída aquí igual.
- **El grafo se pregunta, no se transcribe.** Hoy 6b no puede fallar si 6a pasa; está para que el día que alguien estreche las aristas de `direct`, el endpoint se estreche con ellas.
- **El motivo se valida el último**, criterio de SPEC 95: un cuerpo con basura nunca enmascara un error de acceso o de estado. Un `403`/`409` siempre gana a un `422`.

### El motivo, y por qué es obligatorio

El residente cancela algo suyo y no le debe explicación a nadie. El proveedor devuelve un trabajo que un residente está esperando, y un «no puedo» sin motivo lo deja sin poder distinguir un choque de agenda de un trabajo que la empresa no hace. Es la única asimetría entre las dos rutas y es deliberada.

Cuatro formas de fallar, y no son el mismo error:

| Caso | `error_code` |
|---|---|
| Sin cuerpo, sin clave, o cuerpo que no es objeto | `missing_field` |
| Presente pero no string | `invalid_field` |
| Vacío tras aplanar (`''`, `'   '`, `<p></p>`) | `missing_field` |
| Más de 255 caracteres | `field_too_long` |

El tercero es `missing_field` y no `invalid_field` a propósito: `'   '` no es un valor malformado, es la ausencia de uno disfrazada. Se aplana con `myapi_text_to_multiline()` **antes** de las dos comprobaciones, y la longitud se mide con `drupal_strlen()` — 255 caracteres acentuados son 510 bytes y tienen que caber.

### Las tres escrituras, en este orden

1. **La solicitud** → `cancelled`. Su estado y nada más: `field_assigned_provider`, la unidad, la categoría, la descripción y los ficheros quedan como estaban, y el nodo sigue publicado.
2. **La transacción**, con `cancelled` y la frase del rechazo. El `uid` es la cuenta del proveedor.
3. **El barrido de ofertas vivas** → `rejected`, vía `myapi_service_offer_reject_live()`.

**El orden es el de SPEC 95, con sus tres razones**, y la primera es la que obliga: la solicitud va antes que la transacción para que `myapi_service_transaction_sync_request_status()` compare dos estados iguales y no la guarde otra vez.

**El barrido es defensivo y se espera que no encuentre nada:** una solicitud en `direct` no lleva oferta viva de este proveedor, porque la única que podría mandar la mueve a `assigned` en la misma pasada. Se ejecuta igual porque una solicitud `cancelled` no debe quedarse con una oferta viva colgando la pusiera quien la pusiera —una creada desde el back office, por ejemplo—, y porque omitir una escritura de una consulta por «no puede hacer falta» es como las dos rutas se separan.

**`field_assigned_provider` se conserva**, igual que en la cancelación de SPEC 95: una solicitud cancelada sin rastro de a quién se le había dado es una que nadie puede auditar, y el residente tiene derecho a ver **quién** dijo que no.

### El comentario de la línea de tiempo

`myapi_service_request_reject_comment($provider_name, $reason)`:

> El proveedor **Plomería Ríos** canceló la solicitud: *No tengo disponibilidad esta semana.*

**Lo único que esta frase tiene que hacer es no confundirse con la cancelación del residente.** Los dos actos aterrizan en `cancelled`, así que la píldora de estado dice «Cancelada» en ambos casos y este comentario es el único sitio donde se distinguen. La del residente devuelve sus palabras tal cual, sin prefijo; esta **nombra al proveedor** y dice explícitamente que fue él.

Cuatro variantes, por nombre y por motivo. La rama sin motivo es inalcanzable desde el endpoint —el validador responde `422` antes— y existe porque una función pura a la que le pasan un valor vacío tiene que devolver una frase entera y no media, y porque SPEC 92 prohíbe una transacción sin comentario la cree quien la cree.

### Respuesta de éxito (200)

```json
{
  "success": true,
  "data": {
    "service_request": { "id": 128, "status": "cancelled" },
    "offers_rejected": 0
  },
  "message": "Solicitud cancelada correctamente."
}
```

**Pequeña, y es una decisión.** La cancelación del residente devuelve su detalle entero de diecinueve claves porque el residente se queda en esa pantalla; el proveedor que acaba de devolver un trabajo no, y el detalle que esta ruta podría construirle es el **del proveedor**: seis consultas, con `my_offers` vacío y un trabajo que ya no es suyo. Las dos claves de arriba son lo que la app necesita para salir de la pantalla, y es la misma forma que `PUT /api/v1/service-offers/{id}/withdraw` devuelve al mismo actor.

`offers_rejected` es **hermana** de `service_request` y no una tercera clave dentro, criterio de SPEC 95 sobre el mismo valor: dice lo que **esta** llamada rechazó, que no se deduce de ninguna lectura posterior.

### El aviso

**Una notificación, un push y un correo, al residente y a nadie más.**

| Columna | Valor |
|---|---|
| `source_type` | `service_request` |
| `type` | `service_request_rejected` |
| `deep_link_target` | `service_request` |
| `audience` | `resident` |

```
Título:  El proveedor canceló tu solicitud
Cuerpo:  {asunto}
         Proveedor: {nombre}
         Motivo: {motivo}
```

- **Tipo propio y no `service_request_cancelled`**, que ya existe y apunta al revés: aquel es el residente cancelando y el **proveedor** siendo avisado, con audiencia `provider`. Reutilizarlo archivaría esta fila bajo un hecho con el actor opuesto.
- **No «Solicitud cancelada»**, que es lo que manda SPEC 113. Ese título también es cierto aquí y es justo la confusión que este spec existe para evitar: el residente no canceló nada.
- **El motivo va en el push**, no solo en el correo: es el sentido entero del aviso.
- **Ni al proveedor que rechazó** —contarle lo que acaba de hacer no es una noticia— **ni al `backend`**.

Best-effort de punta a punta: un fallo al notificar nunca cambia el `200`.

---

## Plan de implementación

1. Las dos claves i18n.
2. Las tres funciones puras: la compuerta, el validador y el comentario.
3. El orquestador del aviso, sus dos textos y sus params.
4. El formateador del correo y el `case` del `hook_mail()`.
5. El endpoint, su despachador y su ruta.
6. La clave de correo en `myapi_html_mail_keys()` y `myapi_update_7045()`.
7. Los tests y la documentación.
8. `drush updb` y `drush cc all`.

---

## Criterios de aceptación

### Ruta y método

- Cualquier método que no sea `PUT` responde `405`, **antes** del token.
- La ruta no compite con `/cancel`, `/close` ni `/offers`.

### Acceso

- Sin rol `proveedor`: `403 provider_role_required`, **antes** de cargar el nodo.
- Con rol y sin proveedor: `403 service_request_forbidden`.
- Solicitud adjudicada a otro: `403 service_request_forbidden`, sin consultar el estado.
- Sin `field_assigned_provider` (`NULL`, `0`, `''`, negativo): `403`.
- El `target_id` llega como string desde la Field API y **debe** compararse como número.

### Estados

- Solo `direct` pasa. `open`, `offered`, `assigned`, `closed` y `cancelled` responden `409 service_request_not_rejectable`.
- Un `field_request_status` corrupto o vacío responde `409`, nunca `500`.
- **No idempotente**: una segunda llamada responde `409`, porque la solicitud ya es `cancelled`.

### Efecto

- La solicitud queda `cancelled`, publicada, con `field_assigned_provider` intacto.
- La transacción registra `cancelled`, con el `uid` del proveedor y la frase que lo nombra.
- La solicitud se guarda **antes** que la transacción.
- El barrido devuelve el número de ofertas que **esta** llamada rechazó.

### Tests unitarios

`tests/unit/ServiceRequestRejectTest.php` cubre la compuerta (propiedad antes que estado, comparación numérica, todos los estados, valores corruptos), el validador (las cuatro formas de fallar, el límite en caracteres y no en bytes) y el comentario (que nombra al proveedor, que **no** es la frase del residente, que degrada a una frase entera sin nombre, y que las cuatro combinaciones devuelven algo no vacío).

---

## Decisiones tomadas y descartadas

**1. Ruta propia y no una rama de `/cancel`.**
El actor, la compuerta y la obligatoriedad del motivo son distintos. Una ruta por actor es lo que impide que cualquiera de esas tres reglas se filtre a la otra.

**2. Destino `cancelled` y no `open`.**
`direct → open` no es una arista, `direct` es una raíz a la que no llega nada, y devolver al mercado abierto una solicitud que el residente dirigió a **una** empresa es una decisión del residente y no del proveedor que se baja. Puede crear una solicitud nueva, abierta o directa, en una pantalla. Elegir `cancelled` además sale gratis: cero cambios de esquema y cero aristas nuevas.

**3. El motivo es obligatorio.**
Ver «El motivo, y por qué es obligatorio».

**4. No se distingue en el esquema una cancelación del residente de un rechazo del proveedor.**
Las dos son `cancelled` y la píldora dice «Cancelada» en ambos casos, que es correcto: la solicitud está anulada, la anulara quien la anulara. Lo que las separa es la entrada de la línea de tiempo —que nombra al actor y lleva el motivo— y el `uid` de la transacción. Añadir un estado o un campo para distinguirlas habría sido esquema nuevo para una diferencia que el timeline ya cuenta.

**5. La respuesta es pequeña.**
Ver «Respuesta de éxito».

**6. No se avisa al proveedor ni al `backend`.**
El único proveedor implicado es el que acaba de actuar. El `backend` queda fuera porque, a diferencia de la cancelación del residente —que puede afectar a varias empresas licitando a la vez—, un rechazo concierne exactamente a dos personas.

**7. El barrido de ofertas se ejecuta aunque no pueda encontrar nada.**
Ver «Las tres escrituras».

**8. Se lee el nodo con `node_load()` y no con `myapi_service_request_detail_row()`.**
Esa fila hace `INNER JOIN` con el término de la categoría, así que una solicitud cuyo término se borró respondería `404` sobre un trabajo que el proveedor de verdad tiene. Y lo que viene después es una escritura, que necesita la entidad entera de todos modos.

---

## Riesgos identificados

**1. Las tres escrituras no son atómicas.** Mismo precio y misma razón que SPEC 95: si falla el barrido, queda una oferta viva colgando de una solicitud cancelada. No adjudica nada ni cobra nada.

**2. El residente ve «Cancelada» en los dos casos.** Es la decisión 4. El riesgo real es un cliente que pinte solo la píldora y no la línea de tiempo: ahí un rechazo del proveedor se leería como una cancelación propia. Está avisado en `docs/service-request-provider.md`.

**3. El `hook_update_N` es obligatorio y no toca el esquema**, que es exactamente por lo que se puede olvidar. Sin `drush updb` el correo del rechazo llega con el HTML aplanado a texto plano, sin ningún error visible.

**4. La ventana entre el rechazo y la lectura del residente.** El aviso es best-effort: si falla, la solicitud queda cancelada y el residente se entera al abrir la app. Aceptado, igual que en los seis avisos anteriores del módulo.

---

## Lo que **NO** está en este spec

- Rechazar desde cualquier estado que no sea `direct`.
- Devolver la solicitud al mercado abierto.
- Rechazar una oferta concreta sin tumbar la solicitud.
- Avisar al `backend` o al propio proveedor.
- Reabrir una solicitud cancelada.
- Cualquier cambio de esquema, de catálogo o del grafo de transiciones.
