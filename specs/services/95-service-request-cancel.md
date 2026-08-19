# 95 — Cancelación de una solicitud de servicio desde la app

- **Estado:** Implemented
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `service_request`, del bundle `service_offer` y de los tres catálogos que
    este spec **lee y no reescribe**: `myapi_services_request_statuses()`,
    `myapi_services_request_transitions()` (el grafo que ya dice que `open`,
    `direct`, `offered` y `assigned` van a `cancelled`, y que `closed` y
    `cancelled` son terminales) y `myapi_services_offer_statuses()`. Cero
    cambios de esquema: ni campo, ni instancia, ni bundle, ni tabla.
  - `92-service-request-initial-transaction` (Implemented) — dueña de
    `includes/myapi.service_transaction.inc`. Su docblock afirma que «cada
    transición de estado creará su propia transacción en su propio spec»; este
    es el primero que cumple esa promesa. Reutiliza su patrón de creación de
    `service_transaction` sin extraer abstracción nueva.
  - `93-service-request-transactions-in-detail` (Implemented) — dueña de
    `myapi_service_request_load_transactions()`. Este spec **no la toca**: la
    transacción de cancelación aparece en el timeline del detalle sin una línea
    de cambio, porque ya está escrita para leer cualquier transacción
    publicada.
  - `94-service-transaction-backoffice` (Implemented) — dueña de
    `myapi_service_transaction_sync_request_status()`, colgada de
    `hook_node_insert()`. El orden de escritura de este spec (solicitud
    primero, transacción después) depende de que ese sync encuentre los dos
    estados iguales y no vuelva a guardar. Quien toque cualquiera de los dos
    rompe al otro.
  - `89-service-request-detail` (Implemented) — dueña de
    `myapi_service_request_viewer()` y del criterio de que el `field_requester`
    manda sobre `node.uid`. Este spec **no la llama**: su regla de acceso es
    más estrecha (solo el requester escribe) y la escribe aparte, con la
    comprobación sobre la misma columna.
  - `90-service-request-create` (Implemented) — dueña de
    `resources/service_request.resource.inc` como archivo de escritura, del
    orden de validación (id → token → carga → acceso → estado → cuerpo) y de la
    familia de claves i18n `service_request_*`.
  - `36-cancel-reservation` (Implemented) y `23-anular-pago` (Implemented) —
    precedentes de forma exactos: ruta `PUT /api/v1/<recurso>/%/cancel`,
    dispatcher propio de un solo método, `409` cuando el estado no admite la
    cancelación, `reason` opcional en el cuerpo.
  - `50-reservation-cancel-reason` (Implemented) — precedente del `reason`
    opcional de 255 caracteres validado en función pura, y del criterio de que
    un cuerpo ausente no es un error.

**Objetivo:** Que el residente pueda cancelar su propia solicitud de servicio
desde la app con `PUT /api/v1/service-requests/{id}/cancel`, dejando la
solicitud en `cancelled`, su entrada de timeline escrita y todas las ofertas
vivas rechazadas.

---

## Alcance

**Dentro:**

- Una ruta nueva en `hook_menu()` de `myapi.module`:
  `api/v1/service-requests/%/cancel`, con `page callback`
  `myapi_service_request_cancel_dispatch`, acceso `TRUE` y `file`
  `resources/service_request.resource.inc`. Es la tercera ruta de tres
  segmentos + verbo del módulo, junto a `api/v1/reservations/%/cancel` y
  `api/v1/payments/%/cancel`.
- Un dispatcher `myapi_service_request_cancel_dispatch($nid)` en
  `resources/service_request.resource.inc`: solo `PUT`, cualquier otro método
  `405 method_not_allowed`, y el método se comprueba antes que el token, como
  en todos los dispatchers del módulo.
- `myapi_service_request_cancel($nid)` en el mismo archivo, con el orden de
  validación de siempre: nid → token → carga → autoría → estado cancelable →
  cuerpo.
- Acceso: **solo el `field_requester` exacto** de la solicitud. El proveedor
  nunca cancela; el operador ya cancela desde el back office (spec 94).
- Estados que admiten la cancelación: `open`, `direct`, `offered`, `assigned` —
  leídos de `myapi_services_transition_allowed($status, 'cancelled')`, nunca de
  una lista escrita a mano aquí. Desde `closed` o `cancelled`,
  `409 service_request_not_cancellable`.
- `reason` opcional en el cuerpo JSON: hasta 255 caracteres, validado en una
  función pura. Ausente, vacío o solo espacios significa «sin motivo» y **no**
  es un error.
- Escritura de `field_request_status = cancelled` en la solicitud, con
  `node_save()`.
- Creación de la `service_transaction` de la cancelación, con `field_comment` =
  el motivo del residente o, si no vino, un texto automático de respaldo.
- Efecto lateral sobre las ofertas: toda oferta publicada de esa solicitud en
  `sent` o `selected` pasa a `rejected`. Las `rejected` y las `withdrawn` no se
  tocan.
- `field_assigned_offer` y `field_assigned_provider` de la solicitud **se
  conservan**, aunque su oferta pase a `rejected`.
- Respuesta `200`: **el mismo objeto de 19 claves que sirve el detalle**
  (spec 89), reconstruido después de las escrituras con sus mismos cargadores y
  su mismo serializador, más `offers_rejected` como clave hermana. Con
  `message_key`. *(Revisado el 2026-08-19: este punto decía «respuesta mínima»
  — ver la sección de respuesta y la tabla de decisiones.)*
- Cuatro claves i18n nuevas (`es` y `en`) en `includes/myapi.i18n.inc`.
- `docs/service-request.md`: bloque del endpoint nuevo, en el mismo commit.
- Tests unitarios de las piezas puras en `tests/unit/`.

**Fuera de alcance (para specs futuros):**

- **Cierre de una solicitud** (`closed`, con o sin rating). Es la otra salida
  terminal y tiene sus propias reglas — `myapi_services_close_requires_rating()`
  existe desde spec 77 y nadie la llama todavía.
- **Adjudicación de una oferta** (`offered → assigned`) y **creación de
  ofertas** (`open → offered`). Son las dos transiciones que faltan del grafo y
  ninguna existe como endpoint.
- **Validación general del grafo de transiciones** en el back office y en el
  resto de la API. Spec 92 lo dejó anotado como pendiente y spec 94 lo
  repitió: hoy nadie valida transiciones al escribir el estado a mano. Este
  spec valida el grafo **solo en su propia ruta** y no arregla las otras
  puertas.
- **Notificaciones** al proveedor de que su oferta fue rechazada, o al
  proveedor asignado de que el trabajo se canceló. El marketplace no tiene
  notificador todavía (spec 92, decisión registrada) y este spec no lo crea;
  tampoco añade el flag transitorio de opt-out que reservas y pagos sí tienen,
  porque no hay nada que silenciar.
- **Cancelación por parte del proveedor** («renuncio al trabajo asignado»). Es
  otra acción, con otro actor y otro estado destino.
- **Reapertura** de una solicitud cancelada. `cancelled` es terminal en el
  grafo desde spec 77 y sigue siéndolo.
- **Registro del rechazo de cada oferta** en un timeline propio. Las ofertas no
  tienen timeline y este spec no lo inventa.
- **Borrado** de la solicitud. Cancelar no borra nada.

---

## Modelo de datos

Ninguna estructura nueva: cero campos, cero instancias, cero bundles, cero
tablas. Todo lo de abajo es forma de petición y de respuesta, y escrituras
sobre campos que existen desde spec 77.

### Petición — `PUT /api/v1/service-requests/{id}/cancel`

| Cabecera | Valor |
|----------|-------|
| `Authorization` | `Bearer <access_token>` |
| `Content-Type` | `application/json` (solo si se envía cuerpo) |

Cuerpo **opcional**:

```json
{ "reason": "Ya resolví el problema por mi cuenta." }
```

- Cuerpo ausente, `reason` ausente, `""` o solo espacios → «sin motivo», y
  **no** es error.
- `reason` presente pero no string → `422 invalid_field` con `@field = reason`.
- `reason` de más de 255 caracteres (`drupal_strlen`, no `strlen`) →
  `422 field_too_long` con `@field = reason`.

Ambas claves i18n ya existen. La validación vive en
`myapi_service_request_validate_cancel_reason($body)`, pura, con el mismo
contrato de retorno que `myapi_reservation_validate_cancel_reason()`:
`['ok' => TRUE, 'value' => string|NULL]` o
`['ok' => FALSE, 'error_code' => ..., 'replacements' => ...]`.

### El comentario de la transacción

Función pura `myapi_service_request_cancel_comment($reason)`:

- `$reason` con contenido → se devuelve tal cual, sin etiqueta ni prefijo. El
  motivo del residente es el comentario.
- `$reason` `NULL` → `t('El residente canceló la solicitud.')`.

Nunca devuelve cadena vacía: spec 92 estableció que ninguna transacción nace
sin comentario y este spec lo respeta. `check_plain()` **no** se aplica —
`field_comment` se guarda en crudo y lo escapa quien lo renderiza, igual que en
spec 92.

### Escrituras, en este orden

1. **La solicitud.** `field_request_status[LANGUAGE_NONE][0]['value'] =
   'cancelled'` + `node_save()`. Nada más se toca: `field_assigned_offer` y
   `field_assigned_provider` se conservan.
2. **La transacción.** Un nodo `service_transaction` con los cuatro campos de
   siempre:

| Campo | Valor |
|-------|-------|
| `uid` | el uid autenticado (el residente que cancela) |
| `status` | `1` |
| `field_request` | `target_id` = nid de la solicitud |
| `field_request_status` | `'cancelled'` |
| `field_status_date` | `date('Y-m-d H:i:00')` — el instante real con los segundos fijados |
| `field_comment` | el resultado de `myapi_service_request_cancel_comment()` |

El título no se escribe aquí: lo pone `myapi_service_transaction_set_title()`
desde `hook_node_presave()`, ya escrito en spec 92.

`myapi_service_transaction_sync_request_status()` (spec 94) se dispara al
insertar esta transacción, compara los dos estados, los encuentra iguales y
**no vuelve a guardar la solicitud**. Ese es el motivo del orden 1 → 2 y no al
revés.

3. **Las ofertas.** Una consulta para localizarlas y un `node_save()` por cada
   una:

```sql
SELECT no.nid
FROM field_data_field_request fq
INNER JOIN node no ON no.nid = fq.entity_id
INNER JOIN field_data_field_offer_status fos ON fos.entity_id = no.nid
WHERE fq.entity_type = 'node' AND fq.deleted = 0
  AND fq.field_request_target_id = :nid
  AND no.type = 'service_offer' AND no.status = 1
  AND fos.entity_type = 'node' AND fos.deleted = 0
  AND fos.field_offer_status_value IN ('sent', 'selected')
```

- `no.type = 'service_offer'` es obligatorio, no decorativo: `field_request`
  está compartido con `service_transaction` (spec 77), y sin esa condición la
  consulta devolvería las transacciones de la propia solicitud.
- `INNER JOIN` sobre `field_offer_status`: una oferta sin fila de estado no
  entra, porque no hay nada que reescribir.
- Cada nid se recorre con `node_load()` + escritura de
  `field_offer_status = 'rejected'` + `node_save()`, no con un `db_update()`
  directo: la Field API tiene revisiones y caché de entidad, y un UPDATE a pelo
  las deja mintiendo.

Coste: una consulta, más un `node_load()`/`node_save()` por oferta viva. Una
solicitud sin ofertas paga solo la consulta.

### Respuesta de éxito (200)

> **Revisado el 2026-08-19.** Este spec se aprobó con una respuesta mínima
> (`id`, `status`, `offers_rejected` y la transacción suelta). Se cambió a la
> respuesta completa del detalle a petición del consumidor de la API: la app
> repinta la pantalla entera después de cancelar y con la forma mínima tenía
> que pedir el detalle otra vez, que es justo el viaje que la forma mínima
> quería ahorrar. El coste, seis consultas, está aceptado. La decisión
> original y su reverso quedan los dos en la tabla del final.

```json
{
  "success": true,
  "data": {
    "service_request": {
      "id": 412,
      "title": "Fuga en el calentador",
      "description": "El calentador del baño principal gotea desde el lunes.",
      "status": "cancelled",
      "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
      "unit": { "id": 55, "name": "A-301" },
      "offers_count": 4,
      "assigned_offer": { "id": 52, "status": "rejected" },
      "assigned_provider": { "id": 9, "name": "Plomería Ruiz" },
      "created": "2026-08-17T10:05:00",
      "desired_start": "2026-08-20T08:00:00",
      "viewer": "requester",
      "requester": { "id": 42, "name": "Ana Pérez" },
      "condominium": { "id": 7, "name": "Torres del Este" },
      "images": [],
      "attachment": null,
      "closed_at": null,
      "offers": [
        { "id": 51, "status": "rejected", "...": "..." },
        { "id": 52, "status": "rejected", "...": "..." },
        { "id": 53, "status": "rejected", "...": "..." },
        { "id": 54, "status": "withdrawn", "...": "..." }
      ],
      "transactions": [
        { "id": 512, "status": "open", "...": "..." },
        {
          "id": 987,
          "status": "cancelled",
          "status_date": "2026-08-19T14:30:00",
          "comment": "Ya resolví el problema por mi cuenta.",
          "created": "2026-08-19T14:30:00"
        }
      ]
    },
    "offers_rejected": 2
  },
  "message": "Solicitud cancelada correctamente."
}
```

`data.service_request` es **byte por byte** lo que responde
`GET /api/v1/service-requests/{id}` para esa solicitud en ese instante: las
mismas 19 claves, los mismos cargadores (`myapi_service_request_detail_row()`,
`myapi_service_request_load_images()`,
`myapi_service_request_offer_counts_by_nid()`,
`myapi_service_request_load_offers()`,
`myapi_service_request_load_transactions()`) y el mismo serializador
(`myapi_service_request_build_detail()`). No se construye nada a mano aquí, así
que las dos respuestas no pueden separarse. Es la misma decisión que el `201`
de spec 90.

Todo se lee **después** de las tres escrituras, de modo que `status` viene
`cancelled`, las ofertas vivas vienen ya en `rejected` y la entrada de la
cancelación es la última de `transactions`. **No hay clave `transaction`
suelta**: sería el mismo objeto dos veces.

`viewer` siempre vale `"requester"` — el paso 4 ya demostró que quien llega
aquí es el `field_requester`, y nadie más alcanza esa línea. Por lo mismo,
`offers` viaja **sin recortar**: es la respuesta del solicitante.

`offers_rejected` es **hermana** de `service_request` y no una vigésima clave
suya, para que el objeto de dentro siga siendo idéntico al del detalle y la app
lo pueda sustituir sin caso especial. Es además lo único que la app no puede
deducir de lo que acaba de recibir: `offers` dice qué ofertas están rechazadas
**ahora**, no cuáles rechazó **esta llamada** — una que ya estuviera rechazada
de antes se ve exactamente igual.

Coste: seis consultas después de las escrituras. Es lo que se paga por que la
app no tenga que pedir el detalle a continuación.

**Cuerpo degradado (raro).** El cargador del detalle lleva tres `INNER JOIN`,
uno de ellos a `taxonomy_term_data`. Una solicitud con el término de categoría
borrado no se puede construir — y es una solicitud que **ya** es invisible en
el detalle y en el listado, por los mismos joins, así que nadie ha podido
abrirla; un id viejo en la app sí puede llegar aquí. En ese caso **la
cancelación se aplica igual y se responde `200`**, pero `service_request` lleva
solo `id` y `status`, y la inconsistencia se registra con `watchdog()`. La app
distingue las dos formas por `viewer`, que solo trae el objeto completo.
Responder `500` mentiría sobre una operación que sí ocurrió y empujaría al
cliente a un reintento que contesta `409`.

### Errores

| Código HTTP | `error_code` | Cuándo |
|-------------|--------------|--------|
| 405 | `method_not_allowed` | cualquier método que no sea `PUT` (antes que el token) |
| 401 | `missing_authorization` / `invalid_token` | resuelto por `myapi_auth_require_access_token()` |
| 404 | `service_request_not_found` | el nid no es entero positivo, no existe, no está publicado, o no es del bundle `service_request` |
| 403 | `service_request_forbidden` | existe, pero el que pide no es su `field_requester` |
| 409 | `service_request_not_cancellable` | el estado actual no lleva a `cancelled` según `myapi_services_transition_allowed()` — hoy: `closed` y `cancelled`, y también un estado corrupto o vacío |
| 422 | `invalid_field` / `field_too_long` | `reason` con tipo equivocado o de más de 255 caracteres |

El 403 y el 404 se distinguen a propósito, igual que en el detalle (spec 89):
la solicitud existe y el nid no es un secreto, así que el 403 le dice al
cliente algo accionable en vez de fingir que no está.

### Claves i18n nuevas — `includes/myapi.i18n.inc`, en `es` y `en`

| Clave | Español | Inglés |
|-------|---------|--------|
| `service_request_not_found` | `Solicitud de servicio no encontrada.` | `Service request not found.` |
| `service_request_forbidden` | `No puedes cancelar esta solicitud.` | `You cannot cancel this service request.` |
| `service_request_not_cancellable` | `Esta solicitud ya no se puede cancelar.` | `This service request can no longer be cancelled.` |
| `service_request_cancelled` | `Solicitud cancelada correctamente.` | `Service request cancelled successfully.` |

---

## Plan de implementación

Cinco pasos. Cada uno se puede commitear solo y deja el módulo funcionando:
hasta el paso 4 la ruta no existe, así que nada de lo anterior es alcanzable
desde fuera y nada existente cambia de comportamiento.

### Paso 1 — Las cuatro claves i18n

`includes/myapi.i18n.inc`: añadir `service_request_not_found`,
`service_request_forbidden`, `service_request_not_cancellable` y
`service_request_cancelled` a los catálogos `es` y `en`, junto a las cinco
`service_request_*` que ya están ahí (spec 90).

**Verificación:** `vendor/bin/phpunit --filter I18nTest` sigue en verde — ese
test ya comprueba que toda clave existe en los dos idiomas, que ninguna está
declarada dos veces y que los placeholders coinciden. Cuatro claves nuevas mal
puestas lo rompen sin que haya que escribir un test nuevo.

### Paso 2 — Las dos funciones puras

En `resources/service_request.resource.inc`, al final del archivo:

- `myapi_service_request_validate_cancel_reason($body)` — copia estructural de
  `myapi_reservation_validate_cancel_reason()`, con la clave `reason` en vez de
  `cancel_reason`. **No** se reutiliza aquella ni se extrae una común a
  `includes/`: la clave del cuerpo es distinta, el nombre del campo que viaja
  en `@field` es distinto, y una función compartida que reciba el nombre de la
  clave por parámetro sería una indirección para ahorrar doce líneas de las que
  once son el contrato de retorno.
- `myapi_service_request_cancel_comment($reason)` — devuelve el motivo o el
  texto automático. Solo `t()`, nada de Drupal más.

Las dos viven en el archivo del recurso y no en `includes/`, igual que las de
reservas: la Regla 3 de `CLAUDE.md` manda a `includes/` lo que se comparte
entre recursos, y esto no lo usa nadie más.

**Verificación:** `tests/unit/ServiceRequestCancelTest.php`, nuevo, con las dos
funciones cubiertas (ver los criterios de aceptación).

### Paso 3 — El rechazo de las ofertas vivas

`myapi_service_request_reject_live_offers($nid)` en el mismo archivo: ejecuta
la consulta de la sección anterior, recorre los nids con `node_load()`, escribe
`field_offer_status = 'rejected'`, guarda y devuelve el número de ofertas
reescritas.

Devuelve `0` sin tocar nada cuando el nid no es un entero positivo, y también
cuando no hay ofertas vivas. Es la única función del paso que habla con la base
de datos, y está separada de `myapi_service_request_cancel()` precisamente para
que la orquestación de abajo no mezcle la consulta con las validaciones.

**Verificación:** no hay test unitario posible (necesita base de datos); se
prueba en el paso 5 con un cliente HTTP contra una solicitud con ofertas en los
cuatro estados.

### Paso 4 — El endpoint y su ruta

1. `myapi_service_request_cancel($nid)`, orquestación en siete pasos numerados
   en comentarios, como el resto del archivo:
   1. nid entero positivo, o `404 service_request_not_found` — antes del token
      y sin coste de consulta.
   2. `myapi_auth_require_access_token()`.
   3. `node_load()`; si no existe, no está publicado o no es
      `service_request`, `404 service_request_not_found`.
   4. `field_requester` ≠ uid → `403 service_request_forbidden`. Se lee el
      `target_id` de `field_requester`, nunca `node->uid`.
   5. Estado actual; `myapi_services_transition_allowed($status,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED)` falso →
      `409 service_request_not_cancellable`. Un estado vacío o desconocido
      también cae aquí, porque esa función responde `FALSE` en vez de reventar.
   6. `myapi_service_request_validate_cancel_reason(myapi_request_body())`;
      `422` si falla. Se valida **después** del 403 y del 409, para que un
      cuerpo con basura nunca tape un error de acceso o de estado.
   7. Escribir el estado y guardar → crear la transacción →
      `myapi_service_request_reject_live_offers()` →
      `myapi_respond(..., 200, 'service_request_cancelled')`.
2. `myapi_service_request_cancel_dispatch($nid)`: solo `PUT`, resto `405`.
3. `hook_menu()` en `myapi.module`: `api/v1/service-requests/%/cancel`, junto a
   las otras dos rutas `/cancel` del módulo.
4. `module_load_include()` de `includes/myapi.services_common.inc` donde haga
   falta para leer el grafo, si el archivo no lo tiene ya cargado en ese
   camino.

**Verificación:** `drush cc all` y luego, con cliente HTTP: cancelar una `open`
(200), repetir sobre la misma (409), cancelar la de otro residente (403), `GET`
sobre la ruta (405), un nid inexistente (404), y un `reason` de 300 caracteres
(422).

### Paso 5 — La documentación

`docs/service-request.md`: bloque nuevo siguiendo la plantilla de `CLAUDE.md` —
método, ruta, autenticación requerida, cabeceras, cuerpo opcional, respuesta
200 completa y la tabla de errores de seis filas. En el **mismo commit** que el
paso 4, según la regla del proyecto de que un endpoint sin doc está incompleto.

---

## Criterios de aceptación

> `[x]` = verificado el 2026-08-19 sobre el harness de fixtures de
> `tests/unit/` (dispatcher ejecutado, nodos y tokens sembrados, `node_save()`
> capturado), por test unitario, o por inspección ejecutada sobre el código.
> `[ ]` = requiere el sitio Drupal corriendo: los casos que dependen del
> **cuerpo JSON** (`myapi_request_body()` lee `php://input`, que no se puede
> falsear en proceso — el validador puro sí está cubierto en
> `ServiceRequestCancelTest`), el **título** que escribe
> `hook_node_presave()`, y todo lo que necesita releer de la base de datos lo
> que se acaba de guardar (la transacción en la respuesta y en el timeline).

### Ruta y método

- [x] `PUT /api/v1/service-requests/412/cancel` con token válido del residente
      dueño responde `200`.
- [x] `GET`, `POST` y `DELETE` sobre esa misma ruta responden
      `405 method_not_allowed`.
- [x] El `405` se devuelve **sin** cabecera `Authorization` y sin que la
      solicitud exista.
- [x] `api/v1/service-requests/412` (dos segmentos) sigue respondiendo el
      detalle de spec 89, sin cambios.

### Autenticación y acceso

- [x] Sin cabecera `Authorization`: `401 missing_authorization`.
- [x] Con un token caducado o inventado: `401 invalid_token`.
- [x] Un residente autenticado que no es el `field_requester`:
      `403 service_request_forbidden`, y la solicitud sigue en su estado
      anterior.
- [x] Un proveedor con oferta viva en la solicitud:
      `403 service_request_forbidden`.
- [x] Una solicitud cuyo `node.uid` es el que pide pero cuyo `field_requester`
      es otro: `403`. La columna que manda es `field_requester`.

### Identificación de la solicitud

- [x] `/api/v1/service-requests/abc/cancel`, `/0/cancel` y `/-3/cancel`:
      `404 service_request_not_found`, sin consultar el token.
- [x] Un nid que no existe, uno despublicado, y uno de otro bundle (una oferta,
      una transacción): los tres responden el mismo
      `404 service_request_not_found`.

### Estados

- [x] Una solicitud en `open` se cancela: `200`.
- [x] Una solicitud en `direct` se cancela: `200`.
- [x] Una solicitud en `offered` se cancela: `200`.
- [x] Una solicitud en `assigned` se cancela: `200`.
- [x] Una solicitud en `closed`: `409 service_request_not_cancellable`, sin
      escritura de ningún tipo.
- [x] Una solicitud ya en `cancelled`: `409 service_request_not_cancellable`.
      La operación no es idempotente por diseño; el segundo intento no crea una
      segunda transacción.
- [x] Una solicitud con `field_request_status` vacío o con un valor fuera del
      catálogo: `409`, no un `500`.
- [x] Ninguna de las cuatro listas de estados cancelables está escrita a mano
      en `resources/service_request.resource.inc`: `grep -c "'open'"` sobre la
      función de cancelación devuelve `0`, y la decisión sale de
      `myapi_services_transition_allowed()`.

### El cuerpo `reason`

- [x] Petición **sin cuerpo**: `200`, y el comentario de la transacción es
      `El residente canceló la solicitud.`
- [x] Cuerpo `{}`: `200`, mismo comentario automático.
- [x] Cuerpo `{"reason": "   "}`: `200`, mismo comentario automático.
- [x] Cuerpo `{"reason": "Ya lo resolví."}`: `200`, y el `field_comment` de la
      transacción es exactamente `Ya lo resolví.`, sin prefijo ni etiqueta.
- [x] Cuerpo `{"reason": 42}` o `{"reason": ["a"]}`: `422 invalid_field` con
      `@field = reason`.
- [x] Cuerpo con un `reason` de 256 caracteres: `422 field_too_long` con
      `@field = reason`.
- [x] Un `reason` de 255 caracteres con acentos y eñes pasa: la medida es
      `drupal_strlen()` y no `strlen()`.
- [x] Un `422` por `reason` sobre una solicitud ajena responde `403` y no
      `422`: el acceso se comprueba antes.

### Efecto sobre la solicitud

- [x] Tras un `200`, `field_request_status` de la solicitud vale `cancelled`.
- [x] `field_assigned_offer` y `field_assigned_provider` conservan el valor que
      tenían antes de la cancelación.
- [x] Ningún otro campo de la solicitud cambia: título, unidad, categoría,
      descripción, imágenes y adjunto quedan como estaban.
- [x] La solicitud sigue publicada. Cancelar no despublica ni borra.

### La transacción

- [x] Tras un `200` existe exactamente **una** `service_transaction` nueva
      apuntando a esa solicitud.
- [x] Su `field_request_status` es `cancelled`, su `field_status_date` es el
      instante de la petición con los segundos en `00`, y su `uid` es el del
      residente que canceló.
- [x] Su título lo generó `myapi_service_transaction_set_title()` y tiene la
      forma `Solicitud #412 · Cancelada · 19/08/2026 14:30 · …`.
- [x] La solicitud se guarda **una sola vez**:
      `myapi_service_transaction_sync_request_status()` encuentra los dos
      estados iguales y sale sin `node_save()`.
- [x] La transacción aparece al final del timeline de
      `GET /api/v1/service-requests/412` sin haber tocado
      `myapi_service_request_load_transactions()`.

### Las ofertas

- [x] Una oferta en `sent` de esa solicitud queda en `rejected`.
- [x] Una oferta en `selected` queda en `rejected`.
- [x] Una oferta ya en `rejected` no se vuelve a guardar.
- [x] Una oferta en `withdrawn` sigue en `withdrawn`.
- [x] Una oferta de **otra** solicitud no se toca, aunque sea del mismo
      proveedor.
- [x] `offers_rejected` en la respuesta coincide con el número de ofertas que
      cambiaron de estado.
- [x] Cancelar una solicitud sin ofertas responde `200` con
      `offers_rejected: 0`.
- [x] Las transacciones de la propia solicitud **no** aparecen entre las
      ofertas reescritas: la condición `no.type = 'service_offer'` las excluye,
      aunque compartan `field_request`.

### Respuesta

- [x] El `200` lleva `success: true`, `data.service_request` con las **19
      claves del detalle** (spec 89) y `data.offers_rejected` de hermana, más
      `message`. **No** hay clave `data.transaction`.
- [x] `data.service_request.viewer` vale `"requester"`.
- [x] Una solicitud cuyo término de categoría fue borrado se cancela igual:
      `200`, `service_request` con solo `id` y `status`, `offers_rejected`
      presente, y una entrada de `watchdog()`. No hay fatal ni `500`.
- [x] `data.service_request.status` vale `cancelled`, sus ofertas vivas ya
      vienen en `rejected` y la entrada de la cancelación es la **última** de
      `transactions`: todo se lee después de las escrituras.
- [x] `data.service_request` es idéntico a lo que responde
      `GET /api/v1/service-requests/412` inmediatamente después, clave por
      clave.
- [x] Con `Accept-Language: en`, el `message` es
      `Service request cancelled successfully.`
- [x] Sin cabecera de idioma, el `message` es
      `Solicitud cancelada correctamente.`
- [x] El `status_date` de la entrada de cancelación dentro de `transactions`
      sale tal como está guardado, sin conversión de zona horaria.

### Tests unitarios — `tests/unit/ServiceRequestCancelTest.php`

- [x] `myapi_service_request_validate_cancel_reason()`: cuerpo `NULL`, cuerpo
      sin la clave, `""`, `"   "` → todos `['ok' => TRUE, 'value' => NULL]`.
- [x] Un `reason` con espacios alrededor se devuelve **trimado**.
- [x] Tipos no string → `invalid_field`; 256 caracteres → `field_too_long`;
      255 exactos → `ok`.
- [x] `myapi_service_request_cancel_comment()`: con motivo lo devuelve intacto;
      con `NULL` devuelve el texto automático; nunca devuelve cadena vacía.
- [x] `vendor/bin/phpunit` completo en verde, incluidos `I18nTest`,
      `ServiceTransactionTest` y `ServiceRequestDetailEndpointTest`.

### Documentación

- [x] `docs/service-request.md` documenta el endpoint con su tabla de errores
      de seis filas, en el mismo commit que el código.

---

## Decisiones tomadas y descartadas

- **Sí:** solo el `field_requester` exacto puede cancelar. Es la regla estricta
  de `36-cancel-reservation`, no la de vivienda de `23-anular-pago`: una
  solicitud de servicio la firma una persona, y el resto de la vivienda no
  hereda el derecho a retirarla.
- **No:** que el proveedor asignado pueda cancelar. Es otra acción — «renuncio
  al trabajo» — con otro actor y probablemente otro estado destino. Mezclarla
  aquí obligaría a que un mismo endpoint escribiera dos historias distintas en
  el mismo `field_comment`.
- **No:** que un administrador de edificio cancele por la API. Ya tiene esa
  puerta desde spec 94, con formulario, comentario obligatorio y su propia
  traza. Una segunda puerta sin formulario solo añadiría una forma de cancelar
  sin dejar constancia de quién.
- **Sí:** el grafo se consulta con
  `myapi_services_transition_allowed($status, 'cancelled')` y no con una lista
  literal de cuatro estados. La lista ya existe desde spec 77 y copiarla aquí
  crearía dos verdades que se separan en cuanto alguien añada un estado.
- **Sí:** un estado corrupto o vacío responde `409` y no `500`.
  `myapi_services_transition_allowed()` devuelve `FALSE` ante lo desconocido
  por diseño, y la lectura segura de «no sé en qué estado está esto» es «no lo
  cancelo».
- **Sí:** `reason` opcional, con texto automático de respaldo. Obligarlo
  pondría una fricción en la única salida que hoy tiene el residente, y spec 92
  ya fijó que ninguna transacción nace sin comentario — el respaldo cumple esa
  promesa sin exigirle nada al usuario.
- **Sí:** 255 caracteres, medidos con `drupal_strlen()`. Es el límite de
  reservas y de pagos. `field_comment` es `text_long` y aguantaría más, pero un
  tercer límite distinto en el mismo módulo es una regla que nadie recuerda.
- **No:** reutilizar `myapi_reservation_validate_cancel_reason()` ni extraer
  una versión común a `includes/`. La clave del cuerpo y el nombre del campo
  del error son distintos, y una función parametrizada por el nombre de la
  clave sería indirección pura para ahorrar doce líneas.
- **Sí:** la solicitud se guarda primero y la transacción después. Así el sync
  de spec 94 encuentra los dos estados iguales y no vuelve a guardar, el efecto
  principal está escrito a la vista en el endpoint, y el orden no depende de un
  `hook_node_insert()`.
- **No:** crear solo la transacción y dejar que el sync escriba el estado de la
  solicitud. Ahorraría una línea y escondería en un hook lo que este endpoint
  existe para hacer. Si mañana alguien cambia el sync, la cancelación dejaría
  de cancelar en silencio.
- **Sí:** todas las ofertas vivas — `sent` y `selected` — pasan a `rejected`.
  Es lo que evita que los proveedores sigan trabajando sobre una solicitud
  muerta, que es la carencia que motiva el spec.
- **No:** tocar las `withdrawn` ni las `rejected`. `withdrawn` es una retirada
  del propio proveedor y reescribirla borraría quién se fue por su cuenta;
  `rejected` ya es terminal.
- **No:** un estado nuevo `cancelled` en el catálogo de ofertas. Habría que
  crearlo, migrar el `allowed_values` del campo y enseñárselo a la app, todo
  para distinguir un matiz que el timeline de la solicitud ya cuenta.
- **Sí:** `field_assigned_offer` y `field_assigned_provider` se conservan. Una
  cancelación sin rastro de a quién se le había adjudicado es una cancelación
  que no se puede auditar.
- **No:** `db_update()` directo sobre `field_data_field_offer_status`. Sería
  una consulta en vez de N, pero dejaría las revisiones y la caché de entidad
  mintiendo. El número de ofertas de una solicitud es pequeño y acotado.
- **~~Sí: respuesta mínima~~ → REVERTIDA el 2026-08-19.** La decisión original
  era: respuesta mínima, con la transacción incluida en las cinco claves del
  timeline, porque «la app ya tiene la solicitud en pantalla; devolverle el
  detalle completo costaría seis consultas para repetirle lo que ya sabe». El
  razonamiento tenía un agujero: la app **no** tiene la solicitud actualizada
  después de cancelar — cambian el estado, las cuatro ofertas y el timeline a
  la vez — así que con la forma mínima pedía el detalle justo después, y
  pagaba las seis consultas igualmente, más un viaje de red. Se devuelve el
  detalle completo.
- **Sí:** el detalle completo, con `offers_rejected` de hermana y **sin** clave
  `transaction` suelta. La transacción de la cancelación ya es la última de
  `transactions`, y servirla dos veces obliga a la app a elegir cuál de las dos
  copias es la buena. Que `service_request` sea idéntico al del detalle es lo
  que permite sustituirlo en el estado de la app sin un caso especial.
- **Sí:** si el detalle no se puede construir, se responde `200` degradado y no
  `500`. La escritura ya ocurrió y fue correcta; un `500` mentiría sobre ella y
  el reintento del cliente chocaría con el `409`. Es el único punto del
  endpoint que puede fallar **después** de las escrituras, y por eso es el
  único que tiene red.
- **No:** construir el objeto a mano en el endpoint. Se reutilizan los cinco
  cargadores y el serializador de spec 89 tal cual, igual que hace el `201` de
  spec 90: una copia del serializador se separaría del original en cuanto el
  detalle ganase una clave, y la app recibiría dos formas distintas de la misma
  solicitud según por dónde entrara.
- **Sí:** `offers_rejected` en la respuesta. Es lo único que la app no puede
  deducir de lo que tiene, y es exactamente lo que necesita para decirle al
  residente cuántas ofertas quedaron canceladas.
- **Sí:** familia de códigos prefijada (`service_request_not_found`,
  `service_request_forbidden`, `service_request_not_cancellable`). El detalle
  de spec 89 usa `not_found` y `forbidden` a secas, pero eso es un endpoint de
  lectura; los códigos de escritura del módulo van prefijados en reservas y en
  pagos, y esta ruta se parece más a aquellas.
- **No:** hacer la cancelación idempotente respondiendo `200` sobre una
  solicitud ya cancelada. El `409` le dice al residente que su acción no hizo
  nada, que es la verdad, y evita crear una segunda transacción idéntica.
- **No:** notificar a los proveedores cuyas ofertas quedaron rechazadas. El
  marketplace no tiene notificador (spec 92) y crearlo aquí sería un spec
  entero escondido dentro de otro. Tampoco se añade el flag transitorio de
  opt-out que reservas y pagos llevan: no hay nada que silenciar todavía.
- **No:** validar el grafo de transiciones en las otras puertas (formulario del
  back office, futuros endpoints). Sigue siendo el spec pendiente que 92 y 94
  ya anotaron; este valida solo su propia ruta y no finge cerrarlo.

---

## Riesgos identificados

| Riesgo | Mitigación |
|--------|------------|
| El paso 7 no es atómico: si el `node_save()` de una oferta falla a mitad, la solicitud ya está en `cancelled` con su transacción escrita y quedan ofertas vivas apuntando a una solicitud muerta. | Se acepta, y el orden lo hace lo menos dañino posible: lo primero que se escribe es el estado de la solicitud, que es lo que cierra la puerta. Una oferta viva sobre una solicitud `cancelled` es un dato inconsistente pero inofensivo — ningún flujo la puede adjudicar, porque `cancelled` es terminal. No se abre transacción de base de datos porque `node_save()` con Field API y hooks dentro de una transacción explícita es una fuente conocida de bloqueos en Drupal 7. |
| El sync de spec 94 cambia y deja de comparar antes de guardar: la solicitud se guardaría dos veces por cancelación. | El criterio de aceptación «la solicitud se guarda una sola vez» lo detecta, y el docblock de `myapi_service_transaction_create_initial()` ya advierte que esa propiedad la tiene que mantener quien toque cualquiera de los dos hooks. Este spec añade la misma advertencia en su función de cancelación. |
| Alguien añade en el futuro una rama `service_request` a `myapi_node_update()`. Hoy no existe, y es la segunda barrera que impide una cascada entre el `node_save()` de la solicitud y el del sync. | Documentado aquí y en spec 94. Quien añada esa rama tiene que releer las dos. |
| Dos peticiones de cancelación simultáneas sobre la misma solicitud podrían crear dos transacciones: las dos leen `open` antes de que ninguna escriba. | Riesgo real y no mitigado. Requiere que el mismo residente pulse dos veces en milisegundos desde dos dispositivos. El resultado es una transacción duplicada en el timeline, no un estado incorrecto. Un bloqueo pesimista (`SELECT ... FOR UPDATE`) sería la solución, y no la paga este spec — ningún otro endpoint de escritura del módulo lo hace. |
| El proveedor de una solicitud cancelada pierde el acceso al detalle si su oferta desaparece de la regla 2 de `myapi_service_request_viewer()`. | No ocurre: la regla 2 mira si el proveedor tiene una oferta en la solicitud, sea cual sea su estado, y una oferta `rejected` la sigue cumpliendo. El proveedor puede leer la cancelación y su motivo, que es lo que necesita. |
| Una solicitud con muchas ofertas hace que el endpoint tarde: un `node_load()` + `node_save()` por cada una. | El número de ofertas de una solicitud es pequeño por naturaleza (proveedores activos de una categoría) y el listado ya lo cuenta. Si algún día deja de serlo, la mitigación es una cola, no un `db_update()`. |
| `field_request` está compartido entre `service_offer` y `service_transaction`: una consulta sin filtro de bundle reescribiría transacciones como si fueran ofertas. | La condición `no.type = 'service_offer'` es obligatoria en la consulta, está justificada en el modelo de datos y tiene su propio criterio de aceptación. Es el mismo error que spec 93 tuvo que evitar en la dirección contraria. |

---

## Lo que **NO** está en este spec

- El cierre de una solicitud (`closed`) y su rating.
- La adjudicación de una oferta y la creación de ofertas.
- La validación del grafo de transiciones en el back office y en el resto de la
  API.
- Cualquier notificación al proveedor, y el notificador del marketplace en
  general.
- La cancelación por parte del proveedor.
- La reapertura de una solicitud cancelada.
- El borrado de solicitudes.

Cada una de esas, si llega, va en su propio spec.
