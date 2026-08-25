# 101 — Presupuestar una solicitud `direct` (`POST /api/v1/service-requests/{id}/offers`)

> **Estado:** Approved · **Depende de:** `77-services-content-types-install` (Implemented) — dueña del bundle `service_offer`, de los tres campos del chat y del grafo de `includes/myapi.services_common.inc`; `87-service-request-direct-status` (Implemented) — dueña del estado `direct`, de que sea una **raíz** del grafo y de que cerrar desde él exija calificación; `90-service-request-create` (Implemented) — la que valida al proveedor contra la categoría **al nacer** una solicitud `direct`, que es lo que este spec da por hecho; `92-service-request-initial-transaction` (Implemented) — dueña de la forma de una `service_transaction`; `96-service-request-update` (Implemented) — dueña de `myapi_service_request_update_gate()`, que ya cuenta ofertas en vez de fiarse del estado y por eso **no hay que tocarla**; `100-service-offer-create` (Implemented) — dueña del endpoint, de `myapi_service_offer_eligibility()` y de la decisión 12, que este spec **relaja** · **Fecha:** 2026-08-25
> **Objetivo:** Permitir que el proveedor **adjudicado** de una solicitud `direct` envíe su presupuesto por el mismo endpoint que ya existe, **sin mover el estado de la solicitud**, dejando constancia en su línea de tiempo.

Cuatro notas que la cabecera fija:

- **Un `direct` hoy es un trabajo adjudicado sin sitio donde poner el precio.** `service_request` tiene seis campos propios y ninguno es monetario; el único lugar del módulo donde vive el precio de un trabajo es `field_offer_amount`, **que está en `service_offer`**. Un `direct` sin oferta no tiene precio, y no lo tendrá nunca por diseño, no por olvido.
- **Y tampoco tiene chat.** `field_firebase_path`, `field_chat_opened_at` y `field_last_message_at` están instanciados sobre `service_offer` y sobre ningún otro bundle. Sin oferta no hay hilo posible. Este spec no abre el chat —sigue siendo otra spec— pero es el que hace que **pueda** abrirse algún día sobre un `direct`.
- **Y su calificación no tiene a qué apuntar.** `myapi_services_close_requires_rating('direct')` devuelve `TRUE` porque hay una empresa que hizo el trabajo, y `field_rating_offer` se hizo **opcional** en SPEC 87 precisamente porque un `direct` no tenía oferta. El modelo lleva señalando este agujero desde entonces.
- **El estado NO se mueve, y esa es la decisión central.** Un `direct` que pasa a `offered` se puede cerrar **sin calificar al proveedor**, porque esa función solo devuelve `TRUE` para `assigned` y `direct`. Un `direct` que pasa a `assigned` registra un precio como acordado que el residente nunca aceptó. La única opción que no rompe nada es la que no toca el estado.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.service_offer.inc`** (modificar):
  - `myapi_service_offer_eligibility()` — la condición 5 gana una rama para `direct`. Sigue siendo pura y sigue devolviendo el primer `error_code` que falla; lo que cambia es qué considera ofertable.
  - `myapi_service_offer_direct_quote_comment($provider_name)` (nueva, pura) — el `field_comment` de la entrada de línea de tiempo de un presupuesto sobre un `direct`. Hermana de `myapi_service_offer_transaction_comment()`, que se queda como está para el `open → offered`.

- **`resources/service_offer.resource.inc`** (modificar) — la escritura 3 gana una segunda puerta: se escribe transacción **cuando el estado se movió (como hasta ahora) o cuando se presupuestó un `direct`**. La escritura 2 **no se toca**: su `if` ya compara contra `open` y un `direct` no entra.

- **`docs/service-offer.md`** (modificar) — la tabla de la compuerta, la de las tres escrituras y un apartado propio para el `direct`.

- **`docs/service-request.md`** (modificar) — la nota que hoy dice que nada impide crear una oferta sobre un `direct` desde el back office pasa a decir que ahora también hay una puerta por el API, y la sección de la línea de tiempo recoge la entrada nueva.

- **Tests**: `tests/unit/ServiceOfferCreateTest.php` amplía la matriz de la compuerta y añade la del comentario.

**Fuera del alcance (para specs futuras):**

- **Que el residente acepte o rechace el presupuesto.** La oferta nace `sent` y **nada la mueve de ahí**. No hay `selected`, no hay `rejected`, y `field_assigned_offer` sigue sin escribirse nunca. Es una limitación real, anotada en Riesgos.
- **Editar o retirar la oferta.** Sigue siendo el spec inmediatamente siguiente, y este lo hace **más urgente** — ver Riesgo 1.
- **El chat.** Los tres campos siguen vacíos. Lo que este spec cambia es que ahora existe la fila de la que podrían colgar.
- **Cambiar `myapi_services_close_requires_rating()`** ni la firma con la que se pregunta. Sigue decidiéndose con un solo valor.
- **Mover `direct` en el grafo.** `myapi_services_request_transitions()` no se toca: `direct` sigue siendo una raíz que solo sale a `closed` y a `cancelled`.
- **Cualquier `hook_update_N`.** No hay campo nuevo, no hay instancia nueva, no hay migración. Este spec no toca la base de datos.

---

## Modelo de datos

**Ningún cambio.** Ni un campo, ni una instancia, ni un valor de catálogo, ni una transición. Todo lo que este spec necesita existe desde SPEC 77.

### La condición 5, antes y después

Hoy (SPEC 100):

| | |
|---|---|
| Ofertable | `status ∈ (open, offered)` **y** `assigned_offer_raw` vacío **y** `assigned_provider_raw` vacío |

Después:

| Caso | Regla |
|---|---|
| `status ∈ (open, offered)` | **Sin cambios.** Las dos columnas crudas vacías, o `409 service_request_not_offerable`. |
| `status = direct` | `assigned_provider_raw` **es exactamente el `provider_id` que oferta**, y `assigned_offer_raw` está vacío. Si no, `409 service_request_not_offerable`. |
| Cualquier otro estado | `409 service_request_not_offerable`, como hoy. |

**Sigue leyéndose la columna cruda y no la resuelta**, por el mismo motivo de SPEC 100: un `assigned_provider_id` resuelto queda a `NULL` cuando el nodo del proveedor está despublicado, y la comparación fallaría abierta en vez de cerrada.

**Un `direct` adjudicado a OTRO proveedor responde `409` y no un código propio.** No hace falta uno: ese proveedor no puede ni ver la solicitud —`myapi_service_request_viewer()` y el mercado del listado ya lo bloquean por las mismas columnas crudas— así que el `409` no le dice nada que no supiera, y un código específico sí filtraría que esa solicitud está adjudicada.

`assigned_offer_raw` tiene que seguir vacío incluso siendo mío: si ya hay una oferta adjudicada, el trabajo pasó por una ronda y este no es el caso que este spec abre.

### Las condiciones que NO cambian

| # | Condición | Qué pasa en un `direct` propio |
|---|---|---|
| 1 | El proveedor es mío | **Se mantiene.** |
| 2 | El proveedor está activo | **Se mantiene.** Una licencia vencida suspende a la empresa del mercado, y presupuestar es operar. |
| 4 | No es mi propia solicitud | **Se mantiene.** Una cuenta que es residente y proveedor a la vez no se autoadjudica. |
| 6 | La categoría | **Se salta** cuando el `direct` es mío — ver Decisión 5. |
| 7 | Unicidad | **Se mantiene.** Una oferta viva por proveedor y solicitud. |

### Las tres escrituras

| # | Escritura | `open` | `offered` | `direct` propio |
|---|---|:---:|:---:|:---:|
| 1 | La oferta, `sent` | ✅ | ✅ | ✅ |
| 2 | La solicitud → `offered` | ✅ | — | **—** |
| 3 | La `service_transaction` | ✅ | — | **✅ (nuevo)** |

La escritura 2 **no necesita ni una línea**: su `if` ya compara `$request_row->status === MYAPI_SERVICES_REQUEST_STATUS_OPEN`, y `direct` no lo es.

La escritura 3 es la única que cambia, y **relaja la decisión 12 de SPEC 100**: `service_transaction` deja de ser estrictamente «una entrada por cambio de estado». La entrada de un presupuesto sobre un `direct` lleva `field_request_status = direct`, **repitiendo el que ya había**. Ver Decisión 3.

| Campo | Valor |
|---|---|
| `field_request` | La solicitud |
| `field_request_status` | `direct` — el que ya tenía, sin mover |
| `field_status_date` | `date('Y-m-d H:i:00')` |
| `field_comment` | `myapi_service_offer_direct_quote_comment($provider_name)` |
| `uid` | La cuenta que presupuestó |

El título lo pone `myapi_service_transaction_set_title()` desde `hook_node_presave()`, como siempre.

### La respuesta

**Sin cambios de forma.** `data.service_offer` son las mismas quince claves y `data.request` los mismos `id` y `status` — solo que aquí `status` responde `direct`, que es el que la solicitud tenía y sigue teniendo. Esa clave existe justamente para que el cliente no tenga que adivinarlo.

### Lo que NO hay que tocar, y conviene decirlo

- **`myapi_service_request_update_gate()`** (SPEC 96) — permite editar en `open` o `direct` **con cero ofertas**, y cuenta ofertas en vez de fiarse del estado. En cuanto un `direct` recibe su presupuesto, el residente deja de poder cambiar el enunciado del trabajo, que es exactamente la regla que se quiere. **Cero líneas.**
- **`myapi_service_request_viewer()`** y el mercado de `myapi_service_request_base_query()` — los dos ya bloquean a cualquier proveedor que no sea el adjudicado, por las columnas crudas. **Cero líneas.**
- **`myapi_service_request_reject_live_offers()`** (SPEC 95) — al cancelar, rechaza la oferta viva del `direct` sin saber que existe. **Cero líneas.**
- **`myapi_services_close_requires_rating()`** — sigue devolviendo `TRUE` para `direct`, porque el estado no se movió. **Ese es el motivo de todo este diseño.**

---

## Plan de implementación

Cuatro pasos.

1. **`includes/myapi.service_offer.inc` — la condición 5.**
   La rama de `direct` dentro de `myapi_service_offer_eligibility()`, y el salto de la condición 6 para ese caso. Sigue pura, sigue con la misma firma.
   *Verificación: `php -l`; la matriz de la compuerta ampliada — `direct` mío, `direct` ajeno, `direct` mío con `assigned_offer_raw` relleno, `direct` mío con licencia vencida, `direct` mío de otra categoría — y **la matriz existente en verde sin tocar un solo caso**, que es la prueba de que `open` y `offered` no se movieron.*

2. **El mismo fichero — el comentario de la transacción.**
   `myapi_service_offer_direct_quote_comment($provider_name)`, pura, hermana de la de SPEC 100 y con sus mismas reglas: nunca vacía, sin `check_plain()`, sin placeholder de `t()`.
   *Verificación: `php -l`; test del texto, del nombre con `&` y del proveedor sin nombre.*

3. **`resources/service_offer.resource.inc` — la escritura 3.**
   La segunda puerta de la transacción. Un solo `if` más y la elección del comentario; el resto del bloque es el que ya está.
   *Verificación: `php -l`; los tests de endpoint existentes en verde.*

4. **Documentación.**
   `docs/service-offer.md` y `docs/service-request.md`.
   *Verificación: lectura contra la implementación.*

**No hay paso de instalación, ni de `hook_update_N`, ni de `drush updb`.** Basta `drush cc all` tras desplegar.

---

## Criterios de aceptación

**La compuerta**

- [ ] El proveedor **adjudicado** de un `direct` puede ofertar: `201`.
- [ ] Otro proveedor de la misma categoría sobre ese `direct` → `409 service_request_not_offerable`.
- [ ] Un `direct` adjudicado a un proveedor **mío** pero ofertando con **otro proveedor mío** → `409`.
- [ ] Un `direct` adjudicado a un proveedor cuyo nodo está **despublicado**, ofertando con él → `403 service_offer_provider_not_active`, no `409`: la comparación de la columna cruda lo reconoce como adjudicado, y la condición 2 lo para antes.
- [ ] Un `direct` mío con `field_assigned_offer` relleno (dato incoherente) → `409`.
- [ ] Un `direct` mío de una categoría que mi proveedor **ya no atiende** → `201`. El residente me eligió; perder la categoría después no me quita el trabajo.
- [ ] Un segundo presupuesto sobre el mismo `direct` → `409 service_offer_already_sent`.
- [ ] `open` y `offered` responden **exactamente** lo que respondían antes de este spec, en los veinticuatro casos de la matriz de SPEC 100.

**Las escrituras**

- [ ] La oferta se crea con `field_offer_status = sent`.
- [ ] **La solicitud NO se guarda.** Sigue en `direct`, con su `field_assigned_provider` intacto y su `changed` sin tocar.
- [ ] `field_assigned_offer` sigue **vacío**: presupuestar no adjudica la oferta.
- [ ] Se crea **una** `service_transaction` con `field_request_status = direct`, los segundos a `00` y el comentario nombrando al proveedor.
- [ ] Un segundo presupuesto (rechazado por unicidad) no crea ninguna transacción.

**La respuesta**

- [ ] `201`, con las mismas quince claves y `message` `service_offer_created`.
- [ ] `data.request.status` responde `"direct"`.

**No regresión**

- [ ] `myapi_services_close_requires_rating()` sigue devolviendo `TRUE` para ese `direct`: cerrarlo **sigue exigiendo calificación**.
- [ ] El grafo de transiciones no cambia: `direct` sigue saliendo solo a `closed` y `cancelled`.
- [ ] Tras el presupuesto, `POST /api/v1/service-requests/{id}` (SPEC 96) responde `409 service_request_not_editable` **sin haber tocado `myapi_service_request_update_gate()`**.
- [ ] Cancelar ese `direct` deja su oferta en `rejected`, por `myapi_service_request_reject_live_offers()` y sin tocarla.
- [ ] El detalle del residente muestra la oferta en `offers` y `offers_count = 1` sobre una solicitud `direct`.
- [ ] Toda la suite unitaria en verde.
- [ ] **Ningún `hook_update_N` nuevo**, y `drush updb` no encuentra nada pendiente.

**Documentación**

- [ ] `docs/service-offer.md` documenta el caso `direct` en la compuerta, en las escrituras y en un apartado propio.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| 1. El estado | **No se mueve. Sigue `direct`.** | `direct → offered`; o `direct → assigned` con la oferta `selected` | `offered` **pierde la calificación al cerrar**: `myapi_services_close_requires_rating()` solo devuelve `TRUE` para `assigned` y `direct`, así que un `direct` movido a `offered` se cerraría sin puntuar al proveedor, en silencio y sin ningún test rojo. Y `offered` significa «sin adjudicar», lo que contradiría a `field_assigned_provider`. `assigned` con la oferta `selected` es coherente, pero **registra como acordado un precio que el residente nunca aceptó**, sin marcha atrás: `assigned` solo sale a `closed` o `cancelled`. |
| 2. Quién puede | **Solo el proveedor adjudicado.** | Cualquier proveedor de la categoría | Un `direct` es un trabajo privado. Abrirlo a la categoría sería convertirlo en un `open` sin decirlo. La regla ya existe en tres sitios más del módulo, todos leyendo las columnas crudas. |
| 3. La transacción | **Se escribe, aunque el estado no cambie.** | No escribirla, para respetar la decisión 12 de SPEC 100 | Es la única cosa que `offered` daba y que sin esto se perdería: que el residente vea en su línea de tiempo *«tu proveedor te presupuestó»*. La regla «una entrada por cambio de estado» se relaja **a propósito y en un solo caso**, y el `field_request_status` repetido es la verdad: el estado no cambió. La alternativa —dejar que el residente se entere solo por `offers`— esconde el hecho en un array que la app puede no estar pintando en esa pantalla. |
| 4. El estado de la oferta | **`sent`, y nada la mueve.** | `selected`, ya que el trabajo es suyo | `selected` significa «el residente eligió esta oferta», y aquí el residente no ha visto ningún precio todavía. Adjudicar la oferta es otro verbo y otro spec. |
| 5. La categoría | **Se salta en un `direct` propio.** | Exigirla como en `open` | SPEC 90 **ya validó** al proveedor contra la categoría al crear la solicitud `direct`. Volver a pedirla aquí significa que una empresa que dejó de atender «Plomería» el mes pasado no puede presupuestar un trabajo de plomería **que el residente le dio a ella**. El encargo manda sobre el catálogo. |
| 6. El proveedor activo | **Se exige, sin excepción.** | Saltarla igual que la categoría | No es simétrica con la anterior: la categoría dice *qué* haces, la licencia dice *si puedes operar*. Una empresa con la licencia vencida no factura, aunque el trabajo ya fuera suyo. Y a diferencia de la categoría, la salida es inmediata y está en sus manos: renovarla. |
| 7. El código de error | **`409 service_request_not_offerable`, reutilizado.** | Un `service_offer_direct_not_yours` propio | Un proveedor ajeno no puede ni ver esa solicitud, así que el `409` no le oculta nada que ya supiera — y un código propio **sí le confirmaría** que está adjudicada. Doce claves nuevas fue lo correcto en SPEC 100 porque cada una era accionable; esta no lo sería. |
| 8. Esquema | **Cero cambios.** | Un `field_agreed_amount` en `service_request` | Un campo de precio en la solicitud no resuelve nada: el proveedor seguiría sin poder **proponer** —¿quién lo escribe?— y el chat seguiría sin existir, porque cuelga de la oferta. Es un parche que además crea un segundo sitio donde vive el precio de un trabajo. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **1. El proveedor no puede corregir su presupuesto, y aquí duele más.** La unicidad lo bloquea en `sent`, y en un `direct` **no hay competencia que absorba el error**: un cero de más deja al residente sin más salida que cancelar el trabajo. | Asumido, y este spec **convierte el `PUT` de editar oferta en el siguiente spec obligatorio**, no en uno más de la lista. Hasta entonces, la app debe confirmar el envío mostrando el importe en grande. Nótese que nada queda registrado como *acordado*, así que el daño es menor que en las alternativas de la Decisión 1. |
| **2. No queda constancia de que el residente aceptara el precio.** La oferta se queda `sent` para siempre. | Es correcto para lo que este spec hace: en un `direct` la **adjudicación** ya ocurrió al nacer la solicitud, y lo que falta acordar es el importe, que se discute en el chat —que este spec hace posible por primera vez. El día que se quiera consentimiento explícito, se construye **encima** de esto sin migrar nada: la oferta ya existe en `sent`, solo falta quién la pase a `selected`. |
| **3. La línea de tiempo gana una entrada cuyo `field_request_status` repite el anterior.** Un cliente que asumiera «cada entrada es un cambio de estado» pintaría dos veces «Directa». | Es la relajación consciente de la Decisión 3. El comentario es lo que distingue las dos entradas y es lo que la app pinta; el estado repetido es dato, no titular. `docs/service-transaction.md` debe decirlo. |
| **4. `offers_count` de un `direct` pasa a poder ser `1`.** Un cliente que asumiera `direct ⇒ 0 ofertas` se rompe. | Esa asunción **ya era falsa**: nada impide crear una oferta sobre un `direct` desde el back office, y `docs/service-request.md` lo dice desde SPEC 96 — que es justamente por lo que `myapi_service_request_update_gate()` cuenta ofertas en vez de fiarse del estado. Este spec no crea el caso, le pone una puerta. |
| **5. La condición 5 pasa de dos comparaciones a una rama.** Es la función que decide quién puede ofertar, y una rama mal escrita abre un trabajo privado a toda la categoría. | Es pura, y la matriz de tests la recorre entera sin sitio arrancado. El paso 1 exige además que **los veinticuatro casos de SPEC 100 sigan en verde sin tocar uno**, que es la prueba de que `open` y `offered` no se movieron. Y aunque la rama fallara, quedan **tres defensas más** leyendo las mismas columnas crudas: el detalle, el mercado del listado y el acceso a nodos. |

---

## Lo que **NO** está en este spec

- Que el residente **acepte o rechace** el presupuesto de su `direct`.
- Editar, retirar o borrar la oferta.
- Adjudicar la oferta: `selected` y `field_assigned_offer` siguen sin escribirse.
- El chat. Los tres campos siguen vacíos; lo único que cambia es que ya existe la fila de la que colgarían.
- Notificar al residente de que le han presupuestado.
- Cualquier cambio de esquema, de catálogo o del grafo de transiciones.
- Un precio en `service_request`.
