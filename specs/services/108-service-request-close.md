# 108 — Cerrar una solicitud de servicio y calificar al proveedor (`PUT /api/v1/service-requests/{id}/close`)

- **Estado:** Implemented
- **Fecha:** 2026-08-28
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña de **todo lo que
    este spec escribe y no crea**: el bundle `service_rating` con sus cinco
    instancias, `field_closed_at` en `service_request`, `field_rating_avg` y
    `field_rating_count` en `provider`, el catálogo
    `myapi_services_star_values()`, la arista `→ closed` del grafo y la regla
    `myapi_services_close_requires_rating()`. Los seis llevan instalados desde
    entonces y **ninguno se ha escrito jamás**. Cero cambios de esquema.
  - `92-service-request-initial-transaction` (Implemented) — dueña de
    `includes/myapi.service_transaction.inc`, de la forma de un nodo
    `service_transaction` y del criterio «ninguna transacción nace sin
    comentario».
  - `94-service-transaction-backoffice` (Implemented) — dueña de
    `myapi_service_transaction_sync_request_status()`, colgada de
    `hook_node_insert()`. **Obliga el orden de escritura de este spec**, igual
    que obligó el de SPEC 95 y el de SPEC 106.
  - `95-service-request-cancel` (Implemented) — precedente de forma exacto:
    ruta `PUT /api/v1/service-requests/%/<verbo>`, despachador de un solo
    método, `node_load()` en vez de consulta porque va a escribir,
    `field_requester` como única regla de acceso, el grafo preguntado y no
    transcrito, el detalle entero como `200` y la rama degradada. Dueña de
    `myapi_service_request_validate_cancel_reason()`, cuyo contrato
    `['ok' => …]` este spec repite.
  - `106-service-offer-accept` (Implemented) — dueña de
    `myapi_service_transaction_record()`, de
    `includes/myapi.service_request_detail.inc` y del precedente de una
    escritura que toca varios nodos en una pasada con el orden defendido.
  - `89-service-request-detail` (Implemented) — dueña de
    `myapi_service_request_detail_row()`, de `myapi_service_request_build_detail()`
    y del criterio «manda `field_requester` y no `node.uid`».
  - `90-service-request-create` (Implemented) — dueña de la escritura de
    `field_assigned_provider` en el nacimiento de una `direct`, que es el
    proveedor que este spec califica cuando no hubo ronda.
  - `70-claim-close` (Implemented) — precedente de la **misma URL y el mismo
    actor** en otro recurso: `PUT /api/v1/claims/{id}/close` es del requester,
    con `close_reason` obligatorio y `403` a quien ve el reclamo sin ser su
    dueño. Dueña de `myapi_claim_validate_close_reason()`, que este spec imita
    sin llamar (regla 5 de `CLAUDE.md`).
  - `83-providers-list` (Implemented) y `84-provider-detail` (Implemented) —
    **leen** `field_rating_avg` y `field_rating_count` desde
    `resources/provider.resource.inc:578`. Este spec es quien por fin los
    escribe: hasta hoy todo proveedor del marketplace responde `rating_avg:
    null` y `rating_count: 0`.

**Objetivo:** Que el residente cierre su propia solicitud con
`PUT /api/v1/service-requests/{id}/close`, calificando al proveedor cuando hubo
uno que hizo el trabajo, dejando la solicitud en `closed` con `field_closed_at`
escrito por primera vez, su entrada de timeline puesta y los dos contadores de
reputación del proveedor recalculados.

Cuatro notas que la cabecera fija:

- **`closed` es el único estado del catálogo sin puerta.** `cancelled` la tiene
  desde SPEC 95, `assigned` desde SPEC 106, `offered` desde SPEC 100 y `open` y
  `direct` son estados de nacimiento. Una solicitud adjudicada **no puede
  terminar**: se queda en `assigned` para siempre, y el proveedor que hizo el
  trabajo no cobra reputación por él. Este spec es la salida que falta.

- **Cerrar es calificar, y por eso son un solo endpoint.**
  `myapi_services_close_requires_rating()` existe desde SPEC 77 y **nadie la
  llama**; dice que cerrar desde `assigned` y desde `direct` exige calificación.
  Partir el verbo en dos llamadas dejaría una ventana en la que la solicitud
  está cerrada y sin calificar, que es exactamente el estado que esa función
  fue escrita para prohibir.

- **Ni un campo, ni una instancia, ni una tabla, ni un `hook_update_N`.** Todo
  lo que este spec escribe lleva instalado desde SPEC 77 esperando a que
  alguien lo llene. Es la segunda vez que ocurre en esta serie — SPEC 106 dijo
  lo mismo de `field_assigned_offer` — y por la misma razón: el esquema se
  diseñó entero antes que los endpoints.

- **Cierra el residente, y solo el residente.** Ni el proveedor adjudicado, ni
  un operador desde el endpoint. La razón no es de estilo: la calificación es
  del residente sobre el proveedor, y un cierre ejecutado por el proveedor
  llegaría sin lo único que el catálogo exige que lleve. Ver Decisión 1.

---

## Alcance

**Dentro del alcance:**

- **`myapi.module`** (modificar) — **una** ruta nueva:
  `api/v1/service-requests/%/close`, con `page callback`
  `myapi_service_request_close_dispatch`, `page arguments` `[3]`, acceso `TRUE`
  y `file` `resources/service_request.resource.inc`. Y **cuatro ramas nuevas**
  para el bundle `service_rating`, glue y nada más, como las de
  `service_transaction`: una en `myapi_node_presave()` (el título) y una en
  cada uno de `myapi_node_insert()`, `myapi_node_update()` y
  **`myapi_node_delete()`** (los contadores). Las tres primeras funciones ya
  existen; **`hook_node_delete()` lo estrena este spec** — ver la sección de
  los contadores, donde se explica por qué borrar no es opcional aquí.

- **`resources/service_request.resource.inc`** (modificar) — tres funciones
  nuevas y ni una línea tocada de las existentes:
  - `myapi_service_request_close_dispatch($nid)` — `PUT` y nada más; el `405`
    **antes** del token y antes de cualquier consulta.
  - `myapi_service_request_close($nid)` — el endpoint entero, en el orden de la
    sección «La compuerta».
  - `myapi_service_request_validate_close_body($body, $requires_rating)` (pura)
    — el cuerpo, que **tiene dos formas según el estado**. Mismo contrato
    `['ok' => TRUE, 'values' => [...]]` / `['ok' => FALSE, 'error_code' => …,
    'replacements' => …]` que `myapi_service_offer_validate_body()` de SPEC 100.

- **`includes/myapi.service_rating.inc`** (**nuevo**) — el único fichero nuevo
  del spec, y por tanto lo único que hace obligatorio el `drush cc all`:
  - `myapi_service_rating_record($provider_nid, $offer_nid, $unit_nid, $uid, $stars, $comment)`
    — construye y guarda el nodo `service_rating`. El título **no** se pone
    aquí; lo pone el `hook_node_presave()`.
  - `myapi_service_rating_set_title($node)` — delegado de la rama de
    `myapi_node_presave()`.
  - `myapi_service_rating_title($provider_name, $stars, $created)` (pura) — el
    texto del título.
  - `myapi_service_rating_provider_aggregates($provider_nid, $exclude_nid = NULL)`
    — **una** consulta agregada: `COUNT(*)` y `AVG(field_stars_value)` sobre las
    calificaciones de ese proveedor. El segundo parámetro existe solo para el
    borrado; ver más abajo.
  - `myapi_service_rating_sync_provider($provider_nid, $exclude_nid = NULL)` —
    delegado de las tres ramas de nodo: recalcula y escribe los dos contadores
    en el nodo `provider`.
  - `myapi_service_rating_format_average($count, $avg)` (pura) — el redondeo a
    dos decimales y la decisión de qué se guarda cuando no hay calificaciones.

- **`includes/myapi.service_transaction.inc`** (modificar) — una función pura
  nueva, junto a las dos que ya viven ahí:
  - `myapi_service_transaction_close_comment($reason, $stars, $provider_name)`
    — el texto de la entrada de timeline del cierre. Vive aquí y no en el
    recurso porque `myapi_service_transaction_initial_comment()` y
    `myapi_service_transaction_accept_comment()` ya viven aquí.

- **`myapi.info`** (modificar) — gana el
  `files[] = includes/myapi.service_rating.inc`.

- **`includes/myapi.i18n.inc`** (modificar) — las tres claves nuevas en `es` y
  `en`.

- **`docs/service-request.md`** (modificar) — la sección del endpoint siguiendo
  la plantilla de `CLAUDE.md`, y la lista «What is still not here» **pierde el
  punto del cierre**, que es justo el que este spec cumple.

- **`docs/provider.md`** (modificar) — una nota en `rating_avg` /
  `rating_count`: dejan de ser siempre vacíos y desde cuándo.

- **`docs/provider-detail.md`** (modificar) — **tres frases que este spec deja
  obsoletas**. `ratings` y `rating_summary` existen desde SPEC 84 y llevan
  respondiendo `[]` y cinco ceros porque no había ninguna calificación que
  leer; a partir de aquí traen datos. Y la nota de `unit` —«*`null` en toda
  calificación de hoy, hasta que el flujo que crea una calificación rellene el
  campo*»— deja de aplicar: **este spec es ese flujo** y sí rellena
  `field_unit`.

- **Tests**: `tests/unit/ServiceRequestCloseTest.php` (nuevo) y
  `tests/unit/ServiceRatingAggregatesTest.php` (nuevo). Ningún test existente
  se toca.

**Fuera del alcance (para specs futuras):**

- **Que el proveedor señale «trabajo terminado».** Es el arreglo natural del
  riesgo 1 —la solicitud que se queda en `assigned` porque el residente nunca
  abre la app— y **necesita una arista nueva en el grafo o un campo nuevo**, lo
  que lo saca de un spec que presume de no tocar el esquema. El proveedor
  seguirá sin ningún verbo sobre la solicitud.
- **El cierre automático por cron** a los N días de la adjudicación. Mismo
  motivo, y además tendría que decidir con qué calificación cierra — o inventar
  el cierre sin calificar que `myapi_services_close_requires_rating()` prohíbe.
- **Editar o retirar una calificación desde la app.** Se escribe una vez, con
  el cierre, y el residente no la toca más: no hay `PUT` ni `DELETE` sobre una
  calificación. El back office sí puede editarla y borrarla, y por eso los
  agregados se recalculan también en `hook_node_update()` y en
  `hook_node_delete()`.
- **Leer las calificaciones: ya está hecho, y no se toca.**
  `GET /api/v1/providers/{id}` sirve `ratings` (las tres últimas, con autor
  abreviado, comentario y vivienda) y `rating_summary` (el histórico agrupado
  por estrellas) desde SPEC 84, con sus consultas escritas y probadas. Llevan
  respondiendo `[]` y cinco ceros desde entonces **porque no existe ni una
  calificación en el sitio**. Este spec no añade ni una línea a ese endpoint:
  lo que hace es darle por fin algo que leer. Es el criterio de aceptación más
  vistoso del spec y ni un fichero de lectura cambia.
- **Reabrir.** `closed` sigue siendo terminal. El grafo no gana ninguna arista.
- **Notificar al proveedor de que lo calificaron.** El marketplace sigue sin
  notificador, exactamente como lo dejaron SPEC 95, 100 y 106.
- **Cerrar desde `open`.** El grafo no lleva `open → closed` y este spec no se
  la añade: una solicitud sin una sola oferta se **cancela**, que es lo que
  significa, y para eso está SPEC 95. Responde `409`.
- **Validar el grafo en el back office.** Un operador sigue pudiendo poner
  `closed` a mano desde `node/%/edit` sin calificación y sin `field_closed_at`.
  Es el spec pendiente que `includes/myapi.service_transaction_admin.inc:747`
  lleva anotado, y sigue sin dueño.
- **Atomicidad.** Las tres escrituras no van en una transacción de base de
  datos, por la misma razón y con el mismo precio que SPEC 95 y SPEC 106
  aceptaron — ver Riesgos.

---

## Modelo de datos

**Ningún cambio de esquema.** Ni campo, ni instancia, ni bundle, ni tabla, ni
catálogo, ni arista del grafo, ni `hook_update_N`.

### Lo que se lee, y de dónde

| Dato | De dónde | De quién es |
|---|---|---|
| La solicitud entera, para escribirla | `node_load($nid)` | SPEC 95, mismo criterio |
| `field_requester` | `myapi_building_admin_field_target_id()` | SPEC 49 |
| `field_request_status` | `myapi_building_admin_field_value()` | SPEC 49 |
| `field_assigned_provider` (el proveedor a calificar) | idem, sobre el nodo | SPEC 90 / 106 |
| `field_assigned_offer` (la oferta calificada, si la hay) | idem | SPEC 106 |
| `field_unit` (la vivienda del residente) | idem | SPEC 86 |
| ¿Exige calificación este estado? | `myapi_services_close_requires_rating()` | SPEC 77 |
| ¿Se puede ir a `closed` desde aquí? | `myapi_services_transition_allowed()` | SPEC 77 |
| El catálogo de estrellas | `myapi_services_star_values()` | SPEC 77 |

**Ni una consulta nueva en el endpoint.** Todo sale del `node_load()` que la
escritura necesita de todas formas. La única consulta que este spec estrena es
la agregada de la reputación, y vive en el `hook_node_insert()`, no aquí.

**`field_assigned_provider` se lee del nodo y no de
`myapi_service_request_detail_row()`**, al revés que en SPEC 106. Aquí el
`node_load()` ya está pagado —hay que guardar la solicitud— y la columna del
`detail_row` (`assigned_provider_id`) llega **filtrada por `status = 1`**: un
proveedor despublicado la deja a `NULL` y el cierre respondería `409` a un
residente que sí tuvo a alguien haciéndole el trabajo. La cruda
(`assigned_provider_raw`) es la que vale, y en el nodo **todas** las columnas
son crudas. Es el mismo split que el `@return` de
`myapi_service_offer_detail_row()` documenta, resuelto por el otro lado.

### El cuerpo tiene dos formas, y el estado decide cuál

Es la única decisión de forma que este spec estrena, y está defendida en la
Decisión 3.

**A. Cerrando un trabajo — `assigned` o `direct`**, es decir
`myapi_services_close_requires_rating()` responde `TRUE`:

```json
{ "stars": 5, "comment": "Llegó puntual y dejó todo limpio." }
```

| Campo | Obligatorio | Reglas | Si falla |
|---|:---:|---|---|
| `stars` | **Sí** | Entero, o string que sea un entero. Tiene que ser **una clave de `myapi_services_star_values()`**: 1, 2, 3, 4 o 5. El catálogo se pregunta, no se transcribe. | Ausente → `422 missing_field` (`@field: stars`). Presente y no válido → `422 invalid_field` |
| `comment` | No | String. `trim()` antes de nada; vacío tras el trim = ausente. Máximo **1000** caracteres, contados con `drupal_strlen()` | Presente y no string → `422 invalid_field`. Más de 1000 → `422 field_too_long` |
| `close_reason` | — | **Se ignora en silencio** si viene. El comentario de la calificación es el texto del cierre aquí | — |

**B. Cerrando sin adjudicar — `offered`**, es decir la misma función responde
`FALSE`:

```json
{ "close_reason": "Lo resolví con un conocido, ya no necesito el servicio." }
```

| Campo | Obligatorio | Reglas | Si falla |
|---|:---:|---|---|
| `close_reason` | **Sí** | String, `trim()`, de 1 a **1000** caracteres. Idéntico al de `PUT /api/v1/claims/{id}/close` (SPEC 70) | Ausente o vacío tras el trim → `422 missing_field` (`@field: close_reason`). No string → `422 invalid_field`. Más de 1000 → `422 field_too_long` |
| `stars`, `comment` | — | **Se ignoran en silencio** si vienen. No hay a quién calificar | — |

Tres notas sobre las dos formas:

- **`close_reason` es obligatorio y el `reason` de la cancelación es opcional**,
  y la diferencia no es un descuido. Cancelar es abandonar y el motivo se
  entiende solo; **cerrar sin adjudicar habiendo recibido ofertas** es dejar
  colgados a los proveedores que las mandaron, y esa entrada del timeline es lo
  único que queda para explicárselo. Es además el trato que SPEC 70 ya le da a
  la misma palabra en el otro recurso.
- **1000 caracteres y no 255.** El `reason` de SPEC 95 son 255 porque es una
  línea; aquí los dos textos —el motivo del cierre y el comentario de la
  calificación— son la opinión escrita de un residente sobre un servicio, y el
  precedente del módulo para eso es el `close_reason` de SPEC 70, que son 1000.
- **Las claves que sobran se ignoran, y no son un `422`.** Mismo criterio que
  el resto del módulo: se validan las claves que se nombran y ninguna más. Una
  app que mande el formulario entero en los dos casos funciona, que es lo que
  se quiere de una app.

---

## La compuerta

La primera condición que falla responde.

| # | Condición | Si falla |
|:-:|---|---|
| 1 | `{id}` es entero positivo | `404 service_request_not_found` — **antes del token y sin consulta** |
| 2 | Token válido | `401 missing_authorization` / `401 invalid_token` |
| 3 | `node_load()` devuelve nodo, del bundle `service_request` y publicado | `404 service_request_not_found` |
| 4 | `field_requester === $uid` | `403 service_request_forbidden` |
| 5 | `myapi_services_transition_allowed($status, 'closed')` | `409 service_request_not_closable` |
| 6 | El cuerpo, en la forma que decide `myapi_services_close_requires_rating($status)` | `422 missing_field` / `invalid_field` / `field_too_long` |
| 7 | **Solo si exige calificación:** `field_assigned_provider` tiene un target_id | `409 service_request_provider_missing` |

Seis notas sobre este orden:

- **Las condiciones 1 a 5 son, línea por línea, las de
  `myapi_service_request_cancel()`**, con `closed` donde aquella pone
  `cancelled` y con su propia clave de `409`. No es copia por comodidad: los
  dos endpoints son el mismo acto —el residente termina su solicitud— con dos
  significados distintos, y el día que la regla de acceso cambie tiene que
  cambiar en los dos.
- **El grafo se pregunta, no se transcribe.** La condición 5 no lleva escrito
  `assigned`, `direct` ni `offered` en ningún sitio. De ahí sale gratis que
  **`open` responda `409`** (no hay arista) y que **una solicitud ya `closed` o
  `cancelled` responda `409` también** (estados terminales, lista vacía). Y
  como `myapi_services_transition_allowed()` responde `FALSE` a un valor
  desconocido por diseño, un `field_request_status` corrupto es un `409` y
  jamás un `500`.
- **El cuerpo va DESPUÉS del `403` y del `409`**, igual que en SPEC 95. Un
  `422` que llegue antes de saber si el residente puede siquiera cerrar le
  diría al cliente que arregle el formulario cuando el problema es otro.
- **Y el cuerpo se valida SEGÚN EL ESTADO, que ya está leído en la condición
  5.** Ese es el orden que permite que una sola validación pura decida entre
  las dos formas sin volver a mirar el nodo.
- **La condición 7 es la última y solo existe para el caso corrupto.**
  `field_assigned_provider` está escrito por SPEC 90 en toda `direct` y por
  SPEC 106 en toda `assigned`; que falte significa un nodo editado a mano o una
  referencia rota. Sin proveedor no se puede guardar la calificación
  —`field_rating_provider` es **requerido** en el bundle— así que el cierre
  entero se detiene **antes de escribir nada**, en vez de cerrar la solicitud y
  fallar al calificar.
- **`404` y `403` significan cosas distintas**, mismo criterio que SPEC 89, 95,
  103 y 106.

---

## Las tres escrituras, en este orden

| # | Qué | Detalle |
|:-:|---|---|
| 1 | **La calificación** — *solo si el estado la exige* | Nodo `service_rating` nuevo: `uid` = el residente, `status` = 1, `field_rating_provider` = el `field_assigned_provider` de la solicitud, `field_rating_offer` = su `field_assigned_offer` **si lo tiene** (una `direct` no lo tiene y se deja vacío), `field_stars` = las estrellas validadas, `field_rating_comment` = el comentario o vacío, `field_unit` = el `field_unit` de la solicitud si lo tiene. El título lo pone `hook_node_presave()` |
| 2 | **La solicitud** | `field_request_status = 'closed'` y `field_closed_at = REQUEST_TIME`. Nada más: la categoría, la unidad, la descripción, los ficheros y **los dos campos de adjudicación se quedan como están**, y el nodo sigue publicado — cerrar no despublica ni borra |
| 3 | **La transacción** | `myapi_service_transaction_record($nid, 'closed', $uid, myapi_service_transaction_close_comment(...))` |

Y una cuarta que **no es de este endpoint**:

| # | Qué | Quién |
|:-:|---|---|
| 4 | **Los dos contadores del proveedor** | La rama `service_rating` de `myapi_node_insert()`, que dispara dentro del `node_save()` del paso 1 |

Cinco razones por las que el orden es este y no otro:

- **La calificación va PRIMERO, y es la decisión de orden más importante del
  spec.** Si fallara —una referencia rota, un `node_save()` que revienta— la
  solicitud sigue en `assigned`, el residente reintenta y todo cuadra. Al revés,
  con la solicitud ya en `closed`, la calificación que falló **no se puede
  volver a intentar nunca**: `closed` es terminal, el segundo `close` responde
  `409`, y ese proveedor se queda sin la reputación de un trabajo que hizo. Es
  el mismo razonamiento que puso las ofertas perdedoras antes de la solicitud en
  SPEC 106, aplicado al escalón que más cuesta perder.
- **La solicitud va ANTES que la transacción, y eso no es estilo.**
  `myapi_service_transaction_sync_request_status()` cuelga de
  `hook_node_insert()` desde SPEC 94 y reescribe el estado de la solicitud padre
  con el de la transacción. Escribiendo la solicitud primero, esa función compara
  dos estados iguales y **no vuelve a guardar**. Al revés, se pagaría un
  `node_save()` de más y se dependería del sync para algo que este endpoint ya
  sabe hacer. SPEC 95 y SPEC 106 lo avisan cada una en su sitio; esta es la
  tercera vez y sigue siendo la misma propiedad.
- **`field_closed_at` se estrena aquí**, y es el único campo del bundle que
  seguía sin un solo escritor. Va en el mismo `node_save()` que el estado
  porque son el mismo hecho: la solicitud se cerró, y este es el instante.
  `REQUEST_TIME` y no `time()`, como todo timestamp del módulo.
- **El paso 4 cuelga del hook y no del endpoint**, por la razón exacta de
  SPEC 94 y SPEC 92: una calificación creada desde `node/add/service_rating`
  tiene que actualizar la reputación igual que una creada desde la app, y una
  implementación sola cubre las dos puertas. Colgarlo del endpoint dejaría el
  back office escribiendo calificaciones que no cuentan.
- **Los pasos 2 y 3 corren igual cuando no hay calificación.** El cierre desde
  `offered` salta el paso 1 entero y el resto no se entera.

### El comentario del timeline

`myapi_service_transaction_close_comment($reason, $stars, $provider_name)`,
pura, en `includes/myapi.service_transaction.inc`. Tres formas:

| Situación | Texto |
|---|---|
| Cerrado sin adjudicar (`offered`) | El `close_reason` **tal cual**, sin etiqueta ni prefijo |
| Cerrado con calificación y proveedor conocido | `Servicio cerrado. @proveedor calificado con @estrellas estrellas.` |
| Cerrado con calificación sin nombre de proveedor | `Servicio cerrado y calificado con @estrellas estrellas.` |

Igual que `myapi_service_request_cancel_comment()`, **nunca devuelve vacío**:
SPEC 92 estableció que ninguna transacción nace sin comentario y esta función
mantiene la promesa. Y como allí, el `close_reason` viaja **sin `check_plain()`**:
`field_comment` se guarda crudo y lo escapa quien lo pinte.

**El comentario de la calificación NO va al timeline.** Vive en
`field_rating_comment`, que es su sitio, y repetirlo aquí crearía dos copias
del mismo texto que se desincronizan el día que el back office edite una.

---

## Los contadores de reputación

`includes/myapi.service_rating.inc`, y es la mitad del spec que no tiene nada
que ver con cerrar: **arregla un agujero que lleva abierto desde SPEC 83**.
`resources/provider.resource.inc:578` lee `field_rating_avg` y
`field_rating_count` en el listado y en el detalle de proveedores; nadie los ha
escrito nunca, así que hoy **todo proveedor del marketplace responde
`rating_avg: null` y `rating_count: 0`**.

### La consulta

`myapi_service_rating_provider_aggregates($provider_nid)` — **una sola**:

```sql
SELECT COUNT(*) AS total, AVG(st.field_stars_value) AS average
FROM node n
INNER JOIN field_data_field_rating_provider rp
        ON rp.entity_id = n.nid AND rp.entity_type = 'node' AND rp.deleted = 0
INNER JOIN field_data_field_stars st
        ON st.entity_id = n.nid AND st.entity_type = 'node' AND st.deleted = 0
WHERE n.type = 'service_rating'
  AND rp.field_rating_provider_target_id = :nid
```

En `db_select()`, como todo el módulo. **Es la consulta de
`myapi_provider_rating_summary()` (SPEC 84,
`resources/provider.resource.inc:1222`) sin el `GROUP BY`**, y eso no es
casualidad: tiene que contar exactamente las mismas filas que aquella, o se
rompe una promesa escrita en `docs/provider-detail.md` — *«la suma de los cinco
valores de `rating_summary` siempre es igual a `rating_count`»*. Copiar sus
condiciones al carácter es lo que mantiene esa igualdad, y por eso las dos
decisiones de fondo no se toman aquí: se heredan.

- **`INNER JOIN` con `field_stars` y no `LEFT`**, igual que la suma. Una
  calificación sin estrellas no es una calificación: `field_stars` es requerido
  en el bundle, y una fila sin él es un nodo roto que no debe arrastrar el
  promedio hacia abajo contando como cero. (`myapi_provider_ratings_recent()`
  sí lo hace `LEFT`, porque enseñar una fila rota es menos grave que contarla.)
- **SIN `n.status = 1`, y es deliberado.** SPEC 77 decidió que **moderar una
  calificación es BORRAR el nodo, no despublicarlo**, y las dos consultas de
  SPEC 84 no llevan filtro de estado por esa razón, con el motivo escrito en el
  docblock de `myapi_provider_ratings_recent()`. Añadirlo aquí crearía una
  tercera lectura que cuenta cosas distintas de las otras dos y rompería la
  igualdad de arriba. **Es también lo que obliga a este spec a estrenar
  `hook_node_delete()`**: si moderar es borrar, y borrar no recalcula, entonces
  *cada acto de moderación* deja la reputación mal.
- **Se recalcula entero, nunca incrementalmente.** `avg = (avg × n + estrellas)
  / (n + 1)` acumula error de coma flotante, no sabe reparar un promedio que ya
  esté mal y se rompe entero si un guardado se pierde. Un recuento completo es
  una consulta indexada sobre un puñado de filas por proveedor y **se
  autorrepara**: cualquier calificación que se guarde deja los dos números
  correctos, vengan como vinieran.

### Lo que se guarda

`myapi_service_rating_format_average($count, $avg)`, pura:

| `count` | `field_rating_count` | `field_rating_avg` |
|:-:|:-:|---|
| 0 | `0` | vacío (`NULL`) |
| ≥ 1 | el entero | `round($avg, 2)` |

- **El cero se escribe explícitamente y no se deja vacío**, porque
  `resources/provider.resource.inc:804` ya convierte `NULL` en `0` al
  responder: escribiendo el cero, el almacenamiento dice lo mismo que la API y
  no hay dos verdades.
- **El promedio vacío cuando no hay nada, y no `0.00`.** Un proveedor sin
  calificar **no** tiene un cero de nota; `rating_avg: null` es lo que la app ya
  sabe pintar como «sin valoraciones» desde SPEC 83.
- **Dos decimales** porque `field_rating_avg` es `number_decimal` con
  `precision 3, scale 2`: cabe de `0.00` a `9.99`, dimensionado exactamente para
  un promedio de 1 a 5. Guardar más decimales los perdería en la escritura, sin
  aviso.

### Cuándo corre

| Hook | Qué lo dispara | `$exclude_nid` |
|---|---|:-:|
| `hook_node_insert()` | La calificación que crea este endpoint, y la que cree un operador desde `node/add/service_rating` | `NULL` |
| `hook_node_update()` | Un operador que edita las estrellas desde `node/%nid/edit` | `NULL` |
| `hook_node_delete()` | **La moderación**: SPEC 77 decidió que retirar una calificación es borrarla | **el nid que muere** |

En las tres se lee `field_rating_provider` del nodo y se sincroniza **ese**
proveedor. **No hay recursión posible**: lo que se guarda es un nodo `provider`,
y ninguna de las tres ramas reacciona a ese bundle.

**Por qué el borrado necesita `$exclude_nid`, y las otras dos no.**
`hook_node_delete()` en Drupal 7 corre **antes** de `field_attach_delete()` y
antes de que desaparezca la fila de `node`: en el instante en que este código se
ejecuta, la calificación que se está borrando **todavía está en las tablas que
la consulta agregada mira**, y recalcular ahí devolvería exactamente los mismos
números que antes del borrado. Drupal 7 no tiene un hook posterior donde
apoyarse. La salida es la que se ve —pedirle a la consulta que excluya ese nid—
y es un parámetro opcional en vez de una segunda consulta o de un
`variable_set()` con estado entre hooks. No es probable en PHPUnit —es SQL—, así
que lo cubre un criterio de aceptación de sitio arrancado, que además verifica
empíricamente el orden de los hooks en el que se apoya.

**Un operador que cambie el `field_rating_provider` de una calificación
existente deja obsoleto al proveedor viejo.** Es un caso de back office puro,
de una sola calificación, que se repara guardando cualquier otra del proveedor
afectado. Anotado en Riesgos y deliberadamente no resuelto: rastrear el valor
anterior obligaría a un `hook_node_presave()` con estado, para un caso que casi
no ocurre.

### El título de la calificación

`service_rating` es el **único bundle de esta serie sin generador de título**, y
un nodo sin título es un back office ilegible. Se resuelve como SPEC 92 y
SPEC 60 resolvieron los dos bundles de transacciones: una rama en
`myapi_node_presave()` que delega en `myapi_service_rating_set_title()`.
Presave y no el creador, por las mismas dos razones de siempre — los campos ya
están normalizados y la fila aún no se ha escrito, así que no hace falta un
segundo `node_save()` — y con la misma ventaja: **cubre también las
calificaciones creadas a mano**.

Formato, con las partes vacías simplemente ausentes, como
`myapi_service_transaction_title()`:

```
Calificación · Fontanería Pérez · 5★ · 28/08/2026
```

---

## La respuesta

**`200` con el detalle entero de la solicitud**, más una clave.

Es la tercera vez que este módulo toma esa decisión —SPEC 95 al cancelar,
SPEC 106 al adjudicar— y por la misma razón: la app está en la pantalla del
detalle, y devolver el objeto completo reconstruido **después** de las
escrituras le ahorra un `GET` y hace imposible que la respuesta se desvíe de lo
que ese `GET` contestaría, porque **es** lo que contestaría.

```json
{
  "success": true,
  "data": {
    "service_request": { "…las diecinueve claves de SPEC 89…" },
    "rating_id": 4021
  },
  "message": "Solicitud cerrada correctamente."
}
```

> **Ampliado el 2026-08-28.** Dos de esas diecinueve claves crecieron:
> `assigned_provider` es la **tarjeta de proveedor entera** (ocho claves, `title`
> y no `name`) y `assigned_offer` es la **oferta entera** (quince claves, sacada
> de `offers` sin consulta nueva). Una solicitud que se cierra es, casi siempre,
> una adjudicada, así que este `200` es de los que sí pagan las dos consultas de
> la tarjeta. Ver
> [la ampliación del SPEC 89](89-service-request-detail.md#ampliación-2026-08-28--la-adjudicación-viaja-entera).

- **`rating_id`** es el nid de la calificación creada, o **`null`** cuando se
  cerró sin adjudicar. Siempre presente, como toda clave de este módulo: no
  aparece ni desaparece según el caso.
- **`viewer` vale siempre `requester`**: la condición 4 probó quién llegó aquí.
- **`status` vale `closed` y `closed_at` ya trae el instante**, porque el
  detalle se reconstruye después de las escrituras.
- **La rama degradada existe, igual que en SPEC 95 y 106.**
  `myapi_service_request_detail_row()` hace `INNER JOIN` con el término de la
  categoría, así que una solicitud cuya categoría se borró resuelve a `FALSE`.
  El cierre ya está escrito y **es correcto**, así que la respuesta sigue siendo
  `200`, degradada a `{id, status}` más `rating_id`, con `watchdog(…,
  WATCHDOG_ERROR)`. Un `500` mentiría sobre una operación que salió bien y
  mandaría al cliente a un reintento que responde `409`.

---

## Errores

| Código | `error_code` | Cuándo |
|:-:|---|---|
| 401 | `missing_authorization` / `invalid_token` | Sin token o con uno inválido |
| 403 | `service_request_forbidden` | Quien llama no es el `field_requester`. **También el proveedor adjudicado** |
| 404 | `service_request_not_found` | `{id}` no es entero positivo, no existe, no es del bundle o está despublicada |
| 405 | `method_not_allowed` | Cualquier método que no sea `PUT` |
| 409 | `service_request_not_closable` | El grafo no lleva de este estado a `closed`: `open`, `closed` y `cancelled`, y cualquier estado corrupto. **Una solicitud ya cerrada aterriza aquí** |
| 409 | `service_request_provider_missing` | El estado exige calificación y `field_assigned_provider` está vacío |
| 422 | `missing_field` | `stars` ausente (forma A) o `close_reason` ausente o vacío (forma B) |
| 422 | `invalid_field` | `stars` fuera del catálogo 1–5, o `comment` / `close_reason` no string |
| 422 | `field_too_long` | `comment` o `close_reason` de más de 1000 caracteres |

**El cierre no es idempotente**, igual que la cancelación y la adjudicación: el
segundo `close` responde `409 service_request_not_closable` y no escribe nada.
Es el mismo criterio de SPEC 95 y SPEC 106 y por el mismo motivo — un `200`
silencioso le ocultaría a la app que su primera llamada sí llegó.

---

## i18n

Tres claves nuevas en `includes/myapi.i18n.inc`, en `es` y en `en`, junto a las
`service_request_*` que ya están:

| Clave | `es` | `en` |
|---|---|---|
| `service_request_closed` | `Solicitud cerrada correctamente.` | `Service request closed successfully.` |
| `service_request_not_closable` | `Esta solicitud ya no se puede cerrar.` | `This service request can no longer be closed.` |
| `service_request_provider_missing` | `Esta solicitud no tiene proveedor asignado y no se puede calificar.` | `This service request has no assigned provider and cannot be rated.` |

`missing_field`, `invalid_field`, `field_too_long`,
`service_request_not_found`, `service_request_forbidden`,
`missing_authorization`, `invalid_token` y `method_not_allowed` **ya existen** y
se reutilizan con su `@field`.

---

## Tests

Todo lo probable de este spec es puro, que es justamente por lo que las
funciones puras se separaron de las que consultan.

**`tests/unit/ServiceRequestCloseTest.php`**

- `myapi_service_request_validate_close_body()`, forma A: `stars` ausente →
  `missing_field`; `5` y `'5'` → válidos e idénticos; `0`, `6`, `2.5`, `'abc'`,
  `TRUE`, `[]` → `invalid_field`; `comment` ausente / vacío / solo espacios →
  `NULL`; 1000 caracteres → válido; 1001 → `field_too_long`; 1000 caracteres con
  tildes → válido (cuenta caracteres, no bytes); `close_reason` presente →
  ignorado.
- Forma B: `close_reason` ausente / vacío / solo espacios → `missing_field`;
  no string → `invalid_field`; 1001 → `field_too_long`; válido → devuelto con
  `trim()`; `stars` presente → ignorado.
- `myapi_service_transaction_close_comment()`: las tres formas de la tabla,
  y que **ninguna** devuelve cadena vacía.
- `myapi_services_close_requires_rating()` decide la forma: `assigned` y
  `direct` → A; `offered` → B. (La función es de SPEC 77 y ya está probada; lo
  que se prueba aquí es que este endpoint la usa para elegir.)

**`tests/unit/ServiceRatingAggregatesTest.php`**

- `myapi_service_rating_format_average()`: `(0, NULL)` → `count 0` y `avg NULL`;
  `(3, 4.6666…)` → `4.67`; `(1, 5)` → `5.00`; `(2, 3.005)` → dos decimales y no
  más.
- `myapi_service_rating_title()`: con nombre y sin nombre, con y sin fecha
  parseable, y que ninguna parte vacía deja un separador colgando.

Ningún test existente se toca.

---

## Criterios de aceptación

**Puros (PHPUnit, sin sitio arrancado):**

- [x] Los dos ficheros de test nuevos pasan, y las suites de SPEC 95, 100, 103,
      105 y 106 siguen pasando sin una expectativa tocada.
      *`ServiceRequestCloseTest` (24) y `ServiceRatingAggregatesTest` (25) en
      verde; suite completa `OK (2520 tests, 11749 assertions)`. Las cinco
      suites citadas pasan intactas.*
      **Salvedad al «ningún test existente se toca»:**
      `ServiceRequestDetailEndpointTest::testNoReadPathCallsNodeLoad()` —de
      SPEC 89, no de las cinco de arriba— prohíbe `node_load()` en todo
      `service_request.resource.inc` salvo una lista blanca, y el paso 3 de la
      compuerta de este spec exige justamente un `node_load()`. Se añadió
      `myapi_service_request_close` a esa lista, que es la misma decisión que
      el propio test ya registra para `cancel` y para `update`.

**Sitio arrancado (HTTP + back office):**

- [x] `PUT /service-requests/{id}/close` con `{stars: 5}` sobre una `assigned`
      propia → `200`, `status: closed`, `closed_at` con el instante,
      `rating_id` con un nid.
- [x] Ese nodo `service_rating` existe, publicado, con `field_rating_provider`
      = el proveedor adjudicado, `field_rating_offer` = la oferta adjudicada,
      `field_stars` = 5, `field_unit` = la vivienda de la solicitud, y **título
      puesto**.
- [x] El nodo `provider` de ese proveedor pasa a `field_rating_count = 1` y
      `field_rating_avg = 5.00`, y `GET /api/v1/providers` lo refleja.
- [x] Una segunda calificación de 4 estrellas al mismo proveedor deja
      `count = 2` y `avg = 4.50`.
- [x] Editar esa segunda calificación a 2 estrellas desde `node/%nid/edit` deja
      `avg = 3.50` **sin tocar nada más**.
- [x] **Borrarla** desde `node/%nid/delete` deja `count = 1` y `avg = 5.00`
      —la moderación de SPEC 77— y no un `count = 2` con el promedio viejo.
- [x] `GET /api/v1/providers/{id}` de ese proveedor **cobra vida sin haber
      tocado una línea de su código**: `ratings` trae los items reales con
      autor, comentario y **`unit` no nulo**, y `rating_summary` deja de ser
      cinco ceros. La suma de sus cinco valores es igual a `rating_count`.
- [x] Cerrar una `direct` propia con `{stars: 3}` → `200`; el
      `service_rating` tiene `field_rating_provider` y **`field_rating_offer`
      vacío**.
- [x] Cerrar una `offered` propia con `close_reason` → `200`, `rating_id: null`,
      y **no** se crea ningún `service_rating`.
- [x] Cerrar una `offered` **sin** `close_reason` → `422 missing_field` y nada
      escrito.
- [x] Cerrar una `assigned` **sin** `stars` → `422 missing_field`, la solicitud
      sigue en `assigned` y no hay calificación.
- [x] Cerrar una `open` → `409 service_request_not_closable`.
- [x] Cerrar dos veces → la segunda `409 service_request_not_closable`, sin
      segunda calificación y sin segunda entrada de timeline.
- [x] Cerrar la solicitud de otro → `403 service_request_forbidden`, también
      siendo el proveedor adjudicado.
- [x] `POST`, `GET` y `DELETE` sobre la ruta → `405 method_not_allowed`.
- [x] En los tres cierres, el timeline del detalle trae **una** entrada nueva
      con `status: closed`, su texto y su fecha, y la solicitud **no** se
      guardó dos veces (el sync de SPEC 94 no reescribe).
- [x] `/service-requests/7/cancel`, `/service-requests/provider` y
      `/service-requests/provider/41` siguen resolviendo lo suyo tras el
      `drush cc all`.

---

## Decisiones

| # | Decisión | Alternativa | Por qué |
|:-:|---|---|---|
| 1 | **Cierra el residente, y solo él** | Que el proveedor pueda cerrar el trabajo que hizo | La calificación es del residente sobre el proveedor y es **obligatoria** en dos de los tres estados cerrables. Un cierre del proveedor llegaría sin ella, o le pediría al proveedor que se autocalifique. Además es la regla que SPEC 95 fijó para el verbo hermano y la que SPEC 70 fijó para el mismo verbo en reclamos: el que abre es el que cierra. El coste —la solicitud que se queda en `assigned`— es el Riesgo 1, y su arreglo necesita esquema nuevo |
| 2 | **Cerrar y calificar son un solo endpoint** | `PUT …/close` y `POST …/ratings` por separado | `myapi_services_close_requires_rating()` existe desde SPEC 77 precisamente para prohibir el estado intermedio «cerrada y sin calificar». Dos llamadas lo crearían en cada cierre, y la segunda podría no llegar nunca |
| 3 | **El cuerpo tiene dos formas y el estado decide cuál** | `close_reason` obligatorio siempre, y las estrellas aparte | Cerrando un trabajo, el comentario de la calificación **es** el texto del cierre; exigir además un `close_reason` sería pedir dos veces lo mismo. Cerrando sin adjudicar no hay calificación y el motivo es lo único que queda. La forma se elige con una función que ya existe y que ya sabe la respuesta |
| 4 | **La calificación se escribe primero** | La solicitud primero, como en SPEC 95 | Es lo único irrecuperable. Con la solicitud ya en `closed` —terminal— una calificación fallida no se puede reintentar jamás; al revés, el residente reintenta el cierre entero y no se pierde nada |
| 5 | **Los agregados cuelgan de `hook_node_insert()`/`update()`, no del endpoint** | Recalcularlos en `myapi_service_request_close()` | Precedente de SPEC 92 y 94: una implementación cubre la app **y** el back office. Colgarlo del endpoint dejaría las calificaciones creadas a mano sin contar, que es el mismo agujero que este spec viene a cerrar |
| 6 | **El promedio se recalcula entero, no incrementalmente** | `avg = (avg × n + estrellas) / (n + 1)` | Se autorrepara. El incremental acumula error, no arregla un promedio ya torcido y se rompe si un guardado se pierde. Una consulta agregada indexada sobre unas pocas filas es barata |
| 7 | **`field_rating_count = 0` se escribe; `field_rating_avg` se deja vacío** | Escribir `0.00` en los dos, o dejar los dos vacíos | `resources/provider.resource.inc:804` ya responde `0` para el conteo `NULL`: escribirlo hace que almacenamiento y API digan lo mismo. Un promedio de `0.00`, en cambio, **es una nota** y mentiría sobre un proveedor sin calificar |
| 8 | **1000 caracteres para los dos textos** | 255, como el `reason` de SPEC 95 | Los dos son opinión escrita sobre un servicio, no una etiqueta. El precedente del módulo para eso es el `close_reason` de SPEC 70 |
| 9 | **El proveedor se lee del nodo y no del `detail_row`** | Reutilizar `myapi_service_request_detail_row()` como hizo SPEC 106 | La columna del `detail_row` viene filtrada por `status = 1`, así que un proveedor despublicado respondería `409` a un cierre legítimo. El `node_load()` ya está pagado y todas sus columnas son crudas |
| 10 | **El título de la calificación va en `hook_node_presave()`** | Ponerlo dentro de `myapi_service_rating_record()` | Cubre también `node/add/service_rating`, y ninguna calificación puede nacer sin título. Es lo que SPEC 60 y SPEC 92 decidieron para los dos bundles de transacciones |
| 11 | **El comentario de la calificación no se copia al timeline** | Ponerlo como `field_comment` de la transacción | Serían dos copias del mismo texto que se desincronizan en cuanto el back office edite una. El timeline dice **que** se calificó y con cuántas estrellas; el texto vive en su campo |
| 12 | **La consulta agregada NO filtra por `n.status`** | Contar solo las publicadas, que es el reflejo habitual | SPEC 77 decidió que **moderar una calificación es borrarla**, no despublicarla, y las dos consultas de SPEC 84 llevan esa decisión escrita. Un filtro aquí haría que la suma de `rating_summary` dejara de coincidir con `rating_count`, que es una promesa publicada en `docs/provider-detail.md` |
| 13 | **Se estrena `hook_node_delete()`, con `$exclude_nid`** | Dejar el borrado sin recalcular, como estaba planteado al principio | Es consecuencia directa de la 12: si moderar **es** borrar, cada moderación dejaría la reputación mal. Y el parámetro es necesario porque en Drupal 7 el hook corre antes de que el nodo y sus campos desaparezcan, así que sin él la consulta seguiría contando la calificación que muere |

---

## Riesgos

| # | Riesgo | Mitigación |
|:-:|---|---|
| 1 | **La solicitud que nunca se cierra.** El proveedor hace el trabajo, cobra, y el residente no vuelve a abrir la app. La solicitud se queda en `assigned` para siempre y ese trabajo no da reputación | **Aceptado y documentado.** Es el precio de la Decisión 1. Su arreglo —«trabajo terminado» del proveedor, o cierre automático por cron— necesita esquema o grafo nuevos y está en «fuera de alcance» con nombre propio. Mientras tanto, un operador puede cerrar desde el back office |
| 2 | **Atomicidad: tres escrituras sin transacción de base de datos.** Un fallo entre la 1 y la 2 deja una calificación creada sobre una solicitud que sigue `assigned`; entre la 2 y la 3, una solicitud cerrada sin entrada de timeline | **Aceptado**, mismo precio que SPEC 95 y SPEC 106. El orden está elegido para que el fragmento más probable sea el **recuperable**: reintentar el cierre tras un fallo en el paso 1 vuelve a funcionar. El caso «calificación huérfana» deja una calificación válida, que cuenta para la reputación y no rompe nada |
| 3 | **Un residente puede calificar dos veces al mismo proveedor**, una por solicitud | **Es correcto y no es un riesgo del cierre.** Dos trabajos son dos opiniones. Lo que no puede es calificar dos veces **la misma** solicitud, y eso lo impide el `409` del segundo cierre |
| 4 | **Estrellas sin contrapeso.** Un residente enfadado hunde el promedio de un proveedor nuevo con una sola calificación de 1 | Inherente a cualquier sistema de reputación con pocos datos. `rating_count` viaja **junto** al promedio en el listado y en el detalle desde SPEC 83 precisamente para que la app pueda pintar «1 valoración» al lado del 1.00. Un mínimo de calificaciones para mostrar nota es una decisión de producto, no de este spec |
| 5 | **Cambiar el `field_rating_provider` de una calificación desde el back office** deja obsoleto al proveedor anterior | Caso raro, de una sola fila, que se repara guardando cualquier otra calificación de ese proveedor. Rastrear el valor previo exigiría un `hook_node_presave()` con estado. Documentado en «fuera de alcance» |
| 6 | **`hook_node_delete()` es un hook global nuevo**, y corre para **todos** los bundles del sitio | La rama compara `$node->type` contra el literal y sale, exactamente como las cinco ramas de `myapi_node_update()`. El coste para un nodo que no es `service_rating` es una comparación de cadenas |
| 7 | **Un borrado masivo de calificaciones** (`node_delete_multiple()` sobre N filas del mismo proveedor) dispara N recálculos | Correcto aunque redundante: cada uno excluye solo el nodo que está muriendo, así que los intermedios son transitorios y el último deja el número bueno. Es el mismo precio que paga cualquier sincronización colgada de un hook de nodo, y el caso —moderar varias calificaciones de golpe— es raro |
| 8 | **La consulta agregada crece con las calificaciones del proveedor** | Es una consulta agrupada sobre columnas indexadas (`field_rating_provider_target_id`, `node.type`, `node.status`) y corre **una vez por calificación guardada**, no por lectura. Los contadores denormalizados existen desde SPEC 77 exactamente para que el listado no pague un `AVG()` por fila |
