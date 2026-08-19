# 93 — La línea de tiempo en el detalle de una solicitud de servicio

- **Estado:** Implemented
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `89-service-request-detail` (Implemented) — dueña de
    `myapi_service_request_detail()`, de `myapi_service_request_detail_row()`,
    de `myapi_service_request_viewer()` y del serializador
    `myapi_service_request_build_detail()` al que este spec **añade una clave**.
    Es también la que dejó la línea de tiempo explícitamente fuera de alcance
    («**La línea de tiempo.** `service_transaction` existe desde SPEC 77 y no se
    sirve»). Este spec la mete.
  - `92-service-request-initial-transaction` (Implemented) — la que hace que
    esta clave no nazca vacía: toda solicitud creada desde entonces tiene al
    menos una transacción, con su estado, su fecha y su comentario de acuse.
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `service_transaction` y de sus **cuatro** campos (`field_request`,
    `field_request_status`, `field_status_date`, `field_comment`). Cero cambios
    de esquema: este spec solo lee.
  - `90-service-request-create` (Implemented) — el `POST` cuyo `201` usa el
    mismo serializador que el detalle, y que por tanto pasa a devolver también
    la transacción inicial de SPEC 92.
  - `88-service-requests-list` (Implemented) — dueña de
    `myapi_service_request_base_query()` y de la regla de que **toda consulta
    sobre `field_data_field_request` filtra por `bundle`**, que este spec vuelve
    a aplicar desde el otro lado.
  - `64-claims-list-and-detail` (Implemented) — precedente de forma:
    `myapi_claim_load_transactions()`, el orden ascendente por `status_date`, el
    filtro por publicadas y la carga en **una** consulta.

**Objetivo:** Servir la línea de tiempo de una solicitud de servicio —sus
`service_transaction` publicados, en orden cronológico— como una clave
`transactions` más de `GET /api/v1/service-requests/{id}`.

Cuatro notas que la cabecera fija:

- **Una clave nueva, ninguna clave cambiada.** El detalle pasa de diecisiete a
  dieciocho claves. Ni el listado, ni el endpoint de ficheros, ni el
  serializador de ofertas se tocan.
- **Los dos lectores ven lo mismo.** El proveedor de la categoría recibe la
  línea de tiempo entera, igual que el solicitante. Los comentarios que hoy
  existen los escribe SPEC 92 y van dirigidos al residente; no hay notas
  internas que ocultar, y ocultarlas sin un campo que las marque sería inventar
  una regla que nadie puede consultar.
- **Cinco claves, no siete.** `service_transaction` no tiene `field_images` ni
  `field_attachment` —nunca los tuvo—, así que la transacción viaja con `id`,
  `status`, `status_date`, `comment` y `created`. No se sirven `images: []` ni
  `attachment: null` fijos: una clave que siempre miente es peor que una clave
  ausente.
- **El `201` del `POST` también la lleva.** Comparte serializador con el
  detalle, y en ese instante la transacción inicial de SPEC 92 ya existe dentro
  del mismo `node_save()`. Devolverla vacía sería servir un dato falso.

---

## Alcance

### Dentro del alcance

**`resources/service_request.resource.inc`** (modificar — sigue siendo el único
fichero con lógica de este recurso):

- `myapi_service_request_load_transactions($nid)` (nueva) — **una** consulta:
  los `service_transaction` publicados cuyo `field_request` apunta a esa
  solicitud, con su estado, su fecha, su comentario y su `created`. Devuelve una
  lista ya ordenada y ya serializada, o `[]`. Toma un `nid` y no un array: el
  detalle sirve una solicitud, no una página, y el `IN (...)` de reclamos aquí
  sobraría.
- `myapi_service_request_build_detail()` (modificar) — un parámetro más,
  `array $transactions`, y una clave más en el `array_merge()` final. Es puro,
  sigue sin tocar base de datos.
- `myapi_service_request_detail()` (modificar) — una línea: cargar las
  transacciones antes de serializar, junto a las imágenes y las ofertas.
- `myapi_service_request_create()` (modificar) — una línea: cargar las
  transacciones del nodo recién creado antes del `201`, en vez de pasar `[]`.

**`docs/service-request.md`** (modificar): la clave `transactions` en el detalle
y en el `201` del `POST`, la tabla del objeto transacción con sus cinco claves,
la regla de orden, y por qué son cinco y no las siete de reclamos.

**`tests/unit/ServiceRequestDetailEndpointTest.php`** (modificar, no se crea
fichero nuevo).

**Nada más.** No se toca `myapi.module` (ninguna ruta nueva), ni `myapi.info`
(ningún `.inc` nuevo), ni `myapi.install` (ningún campo, instancia ni
`myapi_update_XXXX()`).

**Ni `drush updb` ni `drush cc all`.** Es la primera vez en esta feature que no
hace falta ninguno de los dos: no hay esquema que actualizar ni fichero nuevo ni
`hook_menu()` modificado que el caché tenga que descubrir.

### Fuera del alcance

- **`images` y `attachment` en la transacción.** `service_transaction` no tiene
  esas dos instancias. Añadirlas es otro spec entero: instalador con su
  `myapi_update_XXXX()`, propiedad del fid en
  `includes/myapi.service_request_files.inc`, y
  `GET /api/v1/service-requests/%/files/%` resolviendo también los ficheros que
  cuelgan de una transacción, como hizo SPEC 65 en reclamos.
- **`?include=transactions` en el listado.** El listado no cambia ni una clave.
  La línea de tiempo se sirve solo en el detalle y en el `201`.
- **Escribir transacciones desde la API.** Ni
  `POST /api/v1/service-requests/%/transactions`, ni comentar, ni cambiar de
  estado. Cada transición (ofertar, adjudicar, cerrar, cancelar) creará su
  transacción en su propio spec, junto al resto de sus efectos.
- **`status_label` o cualquier traducción del estado.** Viaja la clave cruda
  (`open`, `direct`, `assigned`…), igual que ya hacen `status` en el listado y
  en el detalle. El catálogo lo traduce el cliente.
- **El autor de la transacción.** El `uid` no se expone, misma regla que
  reclamos: al residente le importa qué pasó y cuándo, no quién lo tecleó.
- **Paginación, límite o filtros sobre las transacciones.** Van todas, siempre,
  sin `?page` ni `?limit`. Una solicitud tiene unidades de transacciones.
- **Backfill.** Las solicitudes anteriores a SPEC 92 no tienen transacciones y
  responden `"transactions": []`. No se inventa ninguna fila.
- **Notificaciones.** Endpoint de lectura: no dispara ninguna.
- **`hook_file_download()` y el endpoint de ficheros.** No se tocan, porque no
  hay ficheros nuevos que servir.
- **Etiquetar la consulta con `node_access`.** Decisión heredada de SPEC 88/89:
  el acceso ya lo decidió `myapi_service_request_viewer()` sobre la solicitud, y
  la transacción no tiene acceso propio.

---

## Modelo de datos

**Este spec no introduce ninguna estructura nueva.** Ni campo, ni instancia, ni
tabla, ni `myapi_update_XXXX()`. Lo que define es **la forma de la clave nueva**
y **la consulta que la puebla**.

### La clave `transactions` en la respuesta

Se añade al final del objeto que devuelven `GET /api/v1/service-requests/{id}`
y el `201` de `POST /api/v1/service-requests`. Siempre presente y siempre un
array, vacío cuando no hay ninguna:

```json
"transactions": [
  {
    "id": 512,
    "status": "open",
    "status_date": "2026-08-19T14:30:00",
    "comment": "Hemos recibido su solicitud. Los proveedores de la categoría podrán enviarle ofertas y se le notificará cualquier novedad.",
    "created": "2026-08-19T14:30:07"
  }
]
```

| Clave | Tipo | Origen | Notas |
|---|---|---|---|
| `id` | int | `node.nid` de la transacción | |
| `status` | string \| null | `field_request_status` | Clave cruda del catálogo `myapi_services_request_statuses()`. `null` solo si alguien borró la fila del campo a mano — es requerido en el bundle. |
| `status_date` | string \| null | `field_status_date` | `Y-m-dTH:i:s`. **El valor almacenado con una `T`, sin conversión de zona.** |
| `comment` | string \| null | `field_comment` | Texto plano, sin `format`. `null` cuando la transacción no lleva comentario. |
| `created` | string | `node.created` | `Y-m-dTH:i:s` en la zona del sitio, vía `format_date()`. |

**`status_date` no pasa por `strtotime()`, y esto no es un detalle.**
`field_status_date` es el **mismo campo compartido con `claim_transaction`**
(SPEC 77), creado con `tz_handling = 'none'` en SPEC 55: lo almacenado es una
hora local ingenua, no un instante UTC. Convertirla la desplazaría por la zona
del servidor y devolvería una hora que nadie escribió. Es exactamente la regla
que `myapi_claim_load_transactions()` documenta y la que `reception_date` sigue
en reclamos. `created` sí es un timestamp real, y por eso sí pasa por
`format_date()` — dos columnas, dos reglas, a propósito.

### El orden

`ORDER BY field_status_date_value ASC, n.nid ASC`.

Es una línea de tiempo: se lee de la más vieja a la más nueva. El desempate por
`nid` ascendente hace que dos transacciones del mismo minuto —que las hay, si un
operador registra dos cambios seguidos— salgan en el orden en que se crearon y
no en el que MySQL decida. Copia literal del orden de reclamos.

### La consulta

Una sola, sin `IN (...)` porque solo hay una solicitud:

```
node n
  ├── n.type = 'service_transaction'   AND n.status = 1
  ├── INNER JOIN field_data_field_request  fr   ON fr.entity_id = n.nid
  │       AND fr.entity_type = 'node' AND fr.deleted = 0
  │       AND fr.field_request_target_id = :nid
  ├── LEFT  JOIN field_data_field_request_status frs
  ├── LEFT  JOIN field_data_field_status_date    fsd
  └── LEFT  JOIN field_data_field_comment        fcm
```

Tres cosas que esa consulta fija:

- **`n.type = 'service_transaction'` es obligatorio, no decorativo.**
  `field_request` está compartido con `service_offer` (SPEC 77): sin ese filtro,
  la línea de tiempo listaría las ofertas de la solicitud como si fueran
  transacciones. Es la misma regla que SPEC 92 dejó escrita para el sentido
  contrario (`offers_count` contando transacciones), aplicada desde este lado.
- **`n.status = 1`.** Solo publicadas. Una transacción despublicada desde el
  back office desaparece de la app, que es lo que despublicar significa.
- **Los tres `LEFT JOIN` son `LEFT` aunque dos de los campos sean requeridos.**
  Un campo requerido a nivel de instancia se puede quedar sin fila si alguien la
  borra a mano; con `INNER` la transacción entera desaparecería de la línea de
  tiempo en vez de salir con un `null`. Mismo criterio que ya usa
  `myapi_service_request_detail_row()` con `requester_uid`.

### Coste

**Una consulta más por respuesta**, tanto en el detalle como en el `201`. No hay
consulta por transacción: los cinco datos salen de los cuatro `JOIN` de arriba,
y no hay ficheros que cargar aparte —que es justamente lo que en reclamos obliga
a una segunda consulta de imágenes.

---

## Plan de implementación

Seis pasos. Cada uno deja el módulo funcionando y es commiteable por separado.

**1. `myapi_service_request_load_transactions($nid)`**

En `resources/service_request.resource.inc`, junto a
`myapi_service_request_load_offers()`. La consulta de la sección anterior, el
bucle que arma las cinco claves y el `return []` temprano si `$nid` no es un
entero positivo. Nadie la llama todavía: el módulo se comporta exactamente igual
que antes de este paso.

Prueba manual: ninguna. Es código muerto hasta el paso 3, y a propósito.

**2. La clave en el serializador**

`myapi_service_request_build_detail()` recibe `array $transactions` como último
parámetro y añade `'transactions' => array_values($transactions)` al
`array_merge()` final. **Los dos llamantes se actualizan en este mismo paso**
pasando `[]` — el de la línea 1109 (detalle) y el de la 2394 (creación)—, porque
un parámetro nuevo sin actualizar los llamantes es un aviso de PHP en producción.

Prueba manual: `GET /api/v1/service-requests/{id}` responde igual que antes más
`"transactions": []`. El `201` del `POST`, lo mismo.

**3. El detalle carga de verdad**

En `myapi_service_request_detail()`, una línea antes de responder:
`$transactions = myapi_service_request_load_transactions($nid);`, y pasarla al
serializador.

Prueba manual: `GET /api/v1/service-requests/{id}` de una solicitud creada
después de SPEC 92 devuelve su transacción inicial, con el estado con el que
nació y el texto de acuse. Repetir con el token de un proveedor de la categoría:
la misma línea de tiempo, entera.

**4. El `201` carga de verdad**

En `myapi_service_request_create()`, la misma línea sobre el `nid` recién
guardado, sustituyendo el `[]` del paso 2.

Prueba manual: `POST /api/v1/service-requests` responde `201` con
`transactions` de un elemento, y ese elemento es idéntico al que devuelve un
`GET` inmediato sobre la misma solicitud.

**5. Pruebas unitarias**

En `tests/unit/ServiceRequestDetailEndpointTest.php`, sobre la mitad pura y
sobre la consulta con la base de datos de pruebas, al estilo de las que ya
tiene: la forma de las cinco claves, el orden, el filtro por bundle, el filtro
por publicadas, la solicitud sin transacciones, y la `T` sin conversión de zona.
La lista exacta está en Criterios de aceptación.

**6. Documentación**

`docs/service-request.md`: la clave en los dos ejemplos de respuesta (detalle y
`201`), la tabla del objeto transacción, la regla de orden, y el párrafo de por
qué son cinco claves y no las siete de reclamos. Sin doc, el endpoint está
incompleto (`CLAUDE.md`).

---

## Criterios de aceptación

### La respuesta

- [x] `GET /api/v1/service-requests/{id}` incluye la clave `transactions`.
- [x] La clave está **siempre**: una solicitud sin transacciones responde
      `"transactions": []`, nunca `null` y nunca ausente.
- [x] Cada transacción trae **exactamente cinco claves**: `id`, `status`,
      `status_date`, `comment`, `created`. No aparecen `images` ni `attachment`.
- [x] `id` es un entero, no una cadena.
- [x] `status` es la clave cruda del catálogo (`"open"`, `"direct"`,
      `"assigned"`…), sin etiqueta traducida.
- [x] `status_date` sale como `2026-08-19T14:30:00` — el valor almacenado con
      una `T`, sin desplazamiento de zona horaria.
- [x] `created` sale como `Y-m-dTH:i:s` en la zona del sitio.
- [x] `comment` es `null`, no `""`, cuando la transacción no lleva comentario.
- [x] Ninguna otra clave del detalle cambia de valor, de tipo ni de posición.

### El orden y el filtro

- [x] Dos transacciones con `status_date` distinto salen de la más antigua a la
      más reciente.
- [x] Dos transacciones con el **mismo** `status_date` salen ordenadas por `id`
      ascendente.
- [x] Una transacción despublicada (`node.status = 0`) no aparece.
- [x] Una **oferta** (`service_offer`) de la misma solicitud, que comparte
      `field_request`, **no** aparece en `transactions`.
- [x] Una transacción de **otra** solicitud no aparece.

### Los dos lectores

- [x] El solicitante recibe la línea de tiempo entera.
- [x] El proveedor con acceso al detalle recibe **la misma** línea de tiempo,
      con los mismos elementos y los mismos comentarios.
- [x] Quien no encaja en ninguna regla de `myapi_service_request_viewer()` sigue
      recibiendo `403`, sin la clave y sin haber ejecutado la consulta nueva.
- [x] Una solicitud inexistente o despublicada sigue respondiendo `404`.

### El `POST`

- [x] El `201` de `POST /api/v1/service-requests` devuelve `transactions` con la
      transacción inicial de SPEC 92 dentro.
- [x] Ese elemento es **byte a byte** el mismo que devuelve un `GET
      /api/v1/service-requests/{id}` inmediato sobre la solicitud recién creada.

### Lo que no se toca

- [x] `GET /api/v1/service-requests` (listado) responde exactamente las mismas
      claves que antes; `?include=transactions` no existe y se ignora.
- [x] `GET /api/v1/service-requests/{id}/files/{fid}` responde igual que antes.
- [x] `myapi.install`, `myapi.module` y `myapi.info` no tienen ni una línea
      modificada.
- [x] La feature funciona sin ejecutar `drush updb` ni `drush cc all`.

### Coste

- [x] El detalle ejecuta **una** consulta más que antes, no una por transacción.
- [x] Una solicitud con veinte transacciones cuesta las mismas consultas que una
      con una.

---

## Decisiones tomadas y descartadas

| Decisión | Se hace | Se descartó | Por qué |
|---|---|---|---|
| **Las claves del objeto** | Las cinco que existen: `id`, `status`, `status_date`, `comment`, `created` | (a) Las siete de reclamos con `images: []` y `attachment: null` fijos; (b) crear de verdad las instancias `field_images` y `field_attachment` en `service_transaction` | (a) son dos claves que mienten siempre, y una clave que nunca puede tener contenido enseña al cliente a confiar en un hueco. (b) es paridad real, pero arrastra `myapi_update_XXXX()`, la propiedad del fid y el endpoint de descarga: es un spec, no un apartado de este. La clave puede aparecer el día que tenga algo dentro; quitarla después no se podría. |
| **Quién ve la línea de tiempo** | Los dos lectores, entera | Ocultársela al proveedor, o servírsela sin `comment` | Los comentarios que hoy existen los escribe SPEC 92 y van dirigidos al residente. Recortar por si acaso sería inventar una regla de confidencialidad que ningún campo marca y que nadie podría consultar. El día que haya notas internas, lo que hace falta es un campo que las distinga, no un `if` sobre el rol. |
| **Dónde se sirve** | Detalle y `201` del `POST` | También el listado con `?include=transactions`, como reclamos | Nadie lo pidió, el listado ya trae once claves, y `?include=` es un contrato que después hay que sostener. Reclamos lo tiene porque su app pinta la línea de tiempo en la lista; aquí no. |
| **El `201` la lleva poblada** | Sí, con la transacción inicial | Devolverla vacía, u omitir la clave en el `201` | La transacción ya existe en ese instante: SPEC 92 la crea dentro del mismo `node_save()`. Vacía sería un dato falso; omitida rompería la promesa de SPEC 89 de que el `201` y el `GET /%` son el mismo objeto. |
| **El estado** | Clave cruda | Añadir `status_label` desde `myapi_services_request_statuses()` | El listado y el detalle ya sirven `status` crudo. Servir la etiqueta en un sitio y no en los otros dos obliga al cliente a saber cuál de las dos fuentes usar. |
| **El autor** | No se expone | Servir `author_id` o el nombre | Misma regla que reclamos: al residente le importa qué pasó y cuándo, no quién lo tecleó. Y ningún endpoint del módulo expone nombres de usuario hoy. |
| **La firma de la función de carga** | `($nid)`, una solicitud | `(array $nids)`, como `myapi_claim_load_transactions()` | Reclamos carga una página entera y por eso necesita el `IN (...)` y el mapa por `claim_id`. Aquí solo hay una solicitud. Copiar la firma traería un array de un elemento y un desagrupado que nadie usa. |
| **Dónde vive el código** | `resources/service_request.resource.inc` | `includes/myapi.service_transaction.inc`, que ya existe (SPEC 92) | Ese include es del **escritor** de transacciones y lo carga `myapi.module` desde dos hooks de nodo. Este es el **lector**, con un solo consumidor. La Regla 3 de `CLAUDE.md` mueve a `includes/` lo que se duplica, y aquí no se duplica nada. |
| **`status_date` sin `strtotime()`** | Se copia el valor almacenado, cambiando el espacio por una `T` | Normalizar con `strtotime()` + `format_date()` | El campo es `tz_handling = 'none'` (SPEC 55, compartido con `claim_transaction`): lo almacenado es hora local ingenua. Convertirla devolvería una hora que nadie escribió. Es el bug que SPEC 58 ya pagó una vez. |
| **Los `JOIN` de los campos** | `LEFT`, incluso los requeridos | `INNER` para `field_request_status` y `field_status_date` | Un campo requerido puede quedarse sin fila si alguien la borra a mano. Con `INNER` la transacción entera desaparecería de la línea de tiempo; con `LEFT` sale con un `null`, que es visible y diagnosticable. |
| **Sin backfill** | Las solicitudes anteriores a SPEC 92 responden `[]` | Inventar una transacción inicial para ellas | Llevaría un acuse que nadie emitió y un estado que es el *actual*, no el de nacimiento. Misma decisión que SPEC 92 ya tomó. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`field_request` está compartido con `service_offer`.** Una consulta sobre `field_data_field_request` que olvide filtrar por bundle mete las ofertas de la solicitud dentro de `transactions`, cada una con `status`, `status_date` y `comment` a `null` — un elemento que parece una transacción rota en vez de un error de consulta. | `n.type = 'service_transaction'` está en la consulta desde la primera línea, y hay un criterio de aceptación y una prueba unitaria que siembran una oferta a propósito para verificar que no sale. Es el mismo riesgo que SPEC 92 documentó en el sentido contrario (`offers_count` contando transacciones), ya conocido en las dos direcciones. |
| **`myapi_service_request_build_detail()` cambia de firma y tiene dos llamantes.** Actualizar uno y no el otro deja un aviso de PHP dentro de una respuesta JSON, que en producción rompe el `Content-Type` antes que el cuerpo. | El paso 2 del plan actualiza **los dos** llamantes en el mismo commit, con el parámetro a `[]`, antes de que ninguno cargue nada de verdad. Son dos líneas conocidas: 1109 y 2394. |
| **El `201` del `POST` cambia de forma.** Un cliente Flutter que deserialice la respuesta de creación con un modelo estricto —que rechace claves desconocidas— empieza a fallar al crear solicitudes, no al leerlas. | La clave es **aditiva** y va al final; ningún valor existente cambia. Se documenta en `docs/service-request.md` en el mismo commit, y el criterio de aceptación exige que el `201` y el `GET` devuelvan el mismo objeto, que es lo que hace la incidencia reproducible con una sola llamada. |
| **Las solicitudes anteriores a SPEC 92 responden `transactions: []`,** y el cliente puede leer eso como «la solicitud no tiene historia» cuando lo que pasa es que nació antes de que hubiera historia. | Es una decisión escrita, no un descuido: no se hace backfill. La app debe tratar el array vacío como estado normal, igual que trata `offers: []`. Documentado en la tabla de decisiones y en `docs/service-request.md`. |
| **Una transacción despublicada desaparece de la línea de tiempo sin dejar hueco,** y el residente ve una historia con un salto que nadie le explica. | Es lo que despublicar significa, y es la misma regla que reclamos lleva desde SPEC 64. Queda escrito en la documentación para que quien despublique desde el back office sepa qué está haciendo. |
| **La clave crece sin techo:** cada transición futura (ofertar, adjudicar, cerrar) añadirá transacciones, y no hay paginación. | Una solicitud recibe unidades de transacciones, no cientos, y el coste es una consulta fija. Si algún día un flujo automático las genera en masa, la paginación entra en el spec de ese flujo, que es quien sabrá cuántas produce. |
