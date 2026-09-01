# 117 — Escritura de los tres campos de chat sobre la oferta

- **Estado:** Approved
- **Fecha:** 2026-09-01
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña de los tres campos, de sus instancias sobre `service_offer` y de sus descripciones. Su comentario de `myapi.install:2358` («*Created now, empty and unused… creating the columns now costs nothing while a second update hook later would cost a deploy*») describe exactamente este spec: las columnas llevan puestas desde entonces y aquí se estrenan sin un `hook_update_N` de esquema.
  - `115-chat-token` (Implemented) — dueña de la convención `myapi_chat_thread_id()`, de `myapi_chat_offer_nids_for_uid()` y del endpoint que aquí se convierte en el escritor de `field_firebase_path`. **Su Decisión 8 no se toca:** la ruta del hilo se sigue derivando del `nid` en cada firma, y lo que este spec escribe es un espejo, no una fuente.
  - `116-chat-message-push` (Implemented) — dueña de `myapi_chat_thread_row()` y del `notify`, que aquí se convierte en el escritor de los dos *timestamps*. Su cuarta nota de cabecera («*los tres campos siguen vacíos al terminar este spec*») es lo que este spec deroga.
  - Sin precedente de forma en el módulo: **no hay una sola escritura directa a `field_data_field_*` en todo `myapi`**. Ni un `cache_field`, ni un `resetCache()`, ni un `entity_get_controller()`. El precedente que sí existe es el contrario y está escrito a mano en `includes/myapi.service_offer.inc:1241` («*WHY node_load()/node_save() PER OFFER AND NOT ONE db_update()*»), y la Decisión 2 de este spec explica por qué aquí no aplica.
- **Objetivo:** Escribir por primera vez `field_firebase_path`, `field_chat_opened_at` y `field_last_message_at` sobre la oferta —el primero al emitir la credencial, el segundo en el primer aviso del hilo y el tercero en cada aviso— como **espejo de sólo lectura para el back office**, sin que ninguna lógica del módulo llegue a leerlos.

Tres notas que la cabecera fija:

- **Nadie lee lo que este spec escribe, y eso es el diseño y no una fase uno.** `myapi_chat_thread_id()` sigue siendo la única fuente de verdad de la ruta; ningún endpoint devuelve los tres campos, ninguna consulta ordena por ellos y ninguna condición los mira. Si mañana se borrara la tabla entera, el chat seguiría funcionando byte a byte igual. Son telemetría para un operador que abre `node/N`, y el spec no les da ni un consumidor más.
- **Es la primera escritura directa a campo del módulo, y por eso la Decisión 2 está tan argumentada.** Un `node_save()` de la oferta no dispara nada (`myapi_node_update()` no tiene rama para `service_offer`) y no reordena nada (las ofertas se ordenan por `n.created`, `includes/myapi.service_offer_query.inc:334`), así que lo único que compraría son la Field API y una revisión por nodo — a cambio de hasta 40 `node_save()` dentro del endpoint que la app llama al arrancar. No se compra. A cambio, este spec **se hace cargo de las dos cosas que la Field API hacía por nosotros**: la tabla de revisión y la caché de campo.
- **Ni un campo, ni una instancia, ni una tabla, ni un `drush updb` de esquema.** El único `hook_update_N` de este spec —el **7042**— no toca datos: corrige la **descripción** de `field_firebase_path`, que hoy dice que editarla a mano rompe el chat y a partir de aquí sería mentira. **No hay *backfill***: los hilos que ya existen se llenan solos la próxima vez que alguien arranque la app.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.chat.inc`** (modificar) — el espejo entero vive aquí, en cuatro funciones, **tres de ellas separadas a propósito para que la única impura no tenga ni una decisión dentro**:
  - `myapi_chat_field_state(array $offer_nids)` (**nueva**) — una consulta con **tres `LEFT JOIN`** a `field_data_field_firebase_path`, `field_data_field_chat_opened_at` y `field_data_field_last_message_at`, arrancando de `node` para traerse también el **`vid`**, que la escritura necesita y que ningún camino actual del chat conoce (`myapi_chat_offer_nids_for_uid()` devuelve `nid`s pelados). Devuelve `[nid => ['vid', 'path', 'opened_at', 'last_message_at']]`. Los tres joins son `LEFT` por definición: lo que se está preguntando es justamente qué falta.
  - `myapi_chat_field_values(array $current, $now, $is_message)` (**nueva, pura**) — **la única función con criterio, y la única que se testea**. Decide qué columnas hay que escribir:

    | Columna | Cuándo se escribe | Si ya tiene valor |
    |---|---|---|
    | `field_firebase_path` | Siempre que falte | **No se toca** |
    | `field_chat_opened_at` | Falta **y** `$is_message` | **No se toca** |
    | `field_last_message_at` | `$is_message` | **Se pisa** |

    Devuelve `[]` cuando no hay nada que escribir, y ese array vacío es lo que hace que el caso común —la app arrancando por enésima vez— **no ejecute ni una consulta de escritura**.
  - `myapi_chat_field_write($offer_nid, $vid, array $values)` (**nueva**, la única impura) — por cada columna del array, un `db_merge()` sobre `field_data_field_*` y otro sobre `field_revision_field_*`, y al terminar la invalidación de caché. Sin ramas, sin condiciones, sin saber qué es un hilo.
  - `myapi_chat_field_sync(array $offer_nids, $is_message)` (**nueva**) — la orquestadora de tres líneas que llaman los dos endpoints: `state` → `values` → `write`, y **un `try`/`catch` alrededor del conjunto**. Un fallo de escritura va a `watchdog` y **no cambia ni un byte de la respuesta**: el espejo nunca rompe el chat (Decisión 4).
- **`resources/chat.resource.inc`** (modificar) — dos llamadas y nada más:
  - En `myapi_chat_token()`, **antes** del `myapi_respond()` (que hace `exit`) y **después** de la firma: `myapi_chat_field_sync($nids, FALSE)` sobre **los hilos que el token realmente autoriza**, no sobre todos los de la consulta de pertenencia. `myapi_chat_offer_nids_for_uid()` no está recortada —el recorte a `MYAPI_CHAT_MAX_THREADS` = 40 lo hace `myapi_chat_threads_claim()`— así que un proveedor grande podría tener cientos de ofertas vivas, y escribir un espejo de un hilo que el token no cubre sería mentir en la columna. El lote queda **acotado a 40 por construcción**, sin una constante nueva (Decisión 3).
  - En `myapi_chat_notify()`, tras resolver el hilo y **antes** de la respuesta: `myapi_chat_field_sync([$thread['offer_nid']], TRUE)`. Va **fuera** del `if ($allowed)` y **fuera** del `if ($sent)`: se escribe aunque todos los destinatarios estén silenciados por el *debounce* y aunque OneSignal esté caído, porque el campo se llama `last_message_at` y no `last_push_at` (Decisión 5).
- **`myapi.install`** (modificar) — dos cambios, **ninguno de esquema**:
  - La cadena `'description'` de la instancia `field_firebase_path` (`:2836`) pasa de *«Generada por el servidor al abrir el chat. Vacío = chat no abierto. Editarla a mano rompe el chat sin dar ningún error.»* a decir la verdad de este spec: es un **espejo** de una ruta que se deriva del `nid` en cada firma, nadie la lee, y editarla a mano **no rompe nada** — sólo ensucia lo que el operador ve.
  - **`myapi_update_7042()`** (**nuevo**) — aplica esa descripción a los sitios ya instalados. `_myapi_reservations_ensure_instance()` **no sirve**: sólo crea si falta (`myapi.install:881`), así que el *hook* hace `field_read_instance()` + `field_update_instance()` explícitos, y no hace nada si la instancia no existe.
- **`docs/chat.md`** (modificar) — una sección nueva, «Los tres campos del espejo»: quién escribe cada uno, en qué llamada, y la frase que evita el malentendido — **el back office los lee, la app no los recibe y el módulo no los consulta**.
- **`docs/service-offer.md`** (modificar) — la fila `:255` («Always empty — see the note below») y el párrafo `:257-265` que hoy dice *«The three chat fields are still empty, and the chat already works»*. Ese párrafo ya dejó escrito el día de hoy —*«The day the back office has to see a thread, `field_firebase_path` gets written **with the same value**, and nothing in the app changes»*— así que se **actualiza conservando esa frase**, que es exactamente lo que ha pasado.
- **`tests/unit/ChatFieldMirrorTest.php`** (**nuevo**) — la tabla de `myapi_chat_field_values()` entera: las tres columnas × (vacía / con valor) × (`$is_message` sí / no), más el caso «no hay nada que escribir → `[]`». Es lógica pura, que es lo único que la suite cubre (`tests/unit/bootstrap.php`: *«Nothing here touches the database»*).
- **`specs/services/115-chat-token.md`** y **`specs/services/116-chat-message-push.md`** (anotar) — sus viñetas y notas de «los tres campos siguen vacíos» se marcan **✅ Resuelto por SPEC 117**, con la convención de 104/105/106. En el 115 son la cuarta nota de cabecera y la primera viñeta de «Fuera de este spec»; en el 116, la cuarta nota de cabecera y la viñeta *«Contador de no leídos, orden por último mensaje y `field_last_message_at`»*, que se resuelve **sólo en su tercera parte**: el campo se escribe, pero seguir sin ordenar y sin contar por él es deliberado.
- **`myapi.info`** — **sin cambios**: ni fichero nuevo ni ruta nueva. El despliegue es **`drush updb`** (por el 7042); `drush cc all` no es obligatorio, porque `field_update_instance()` ya invalida la caché de `field_info`.

### Fuera de este spec

- **El *backfill*.** Las ofertas vivas de hoy salen con las tres columnas vacías y se van llenando solas: la próxima vez que cualquiera de los dos lados arranque la app, su `chat/token` escribe el `path`. Un `updb` que recorre miles de filas para un dato que nadie lee no se paga.
- **Leer los tres campos desde cualquier sitio.** Ni un endpoint los devuelve, ni una consulta ordena por ellos, ni una condición los mira. `myapi_chat_thread_id()` sigue siendo la única fuente de verdad de la ruta y este spec **no la desplaza** — la Decisión 8 del 115 queda intacta (Decisión 1).
- **Ordenar hilos y contar no leídos por `field_last_message_at`.** Sigue siendo de Firebase, que es quien vio el mensaje. El 116 ya lo dejó fuera y este spec no lo reabre por haber escrito la columna.
- **Bloquear los tres *widgets* en el formulario del nodo.** La descripción corregida ya avisa de que es un espejo; un `hook_form_alter()` que los deshabilite es otro spec, y hoy no protege nada porque nadie lee lo que un operador podría escribir a mano.
- **Limpiar el espejo cuando el hilo muere.** Una oferta que pasa a `rejected` o `withdrawn` deja de ser un hilo y **conserva sus tres columnas**. Es historial, no estado, y barrerlo pediría un *cron* que este módulo no tiene.
- **El back office de verdad.** Un `view mode`, un formateador, o un enlace «ver el hilo» desde `node/N`. Este spec deja los datos en la ficha de la oferta; pintarlos bonito es otra cosa.
- **Escribir nada del lado de Firebase.** El nodo `members` en la RTDB que la Decisión 4 del 115 dejó cerrado sigue cerrado.
- **Adjuntos, retención, moderación y revocación inmediata.** Siguen fuera, igual que en el 115 y en el 116.

---

## Modelo de datos

**Ni un cambio de esquema.** Las seis tablas existen desde SPEC 77 y llevan desde entonces vacías; este spec estrena sus columnas y no crea ni una. El único `hook_update_N` (7042) cambia una cadena de texto de una instancia, no una tabla.

### Las seis tablas y su columna

Cada campo de D7 vive en **dos** tablas —la de datos y la de revisión— y este spec escribe en las dos, porque nadie lo va a hacer por él:

| Campo | Tipo | Tabla de datos | Columna de valor | Qué guarda |
|---|---|---|---|---|
| `field_firebase_path` | `text` (255) | `field_data_field_firebase_path` | `field_firebase_path_value` | `service_offers/{nid}` — el resultado exacto de `myapi_chat_thread_id()` |
| `field_chat_opened_at` | `datestamp` | `field_data_field_chat_opened_at` | `field_chat_opened_at_value` | Entero, `REQUEST_TIME` del **primer** `notify` del hilo |
| `field_last_message_at` | `datestamp` | `field_data_field_last_message_at` | `field_last_message_at_value` | Entero, `REQUEST_TIME` del **último** `notify` |

Sus gemelas de revisión son las mismas con el prefijo `field_revision_`.

El campo `text` tiene además una columna `field_firebase_path_format`, que se escribe a **`NULL`**: es texto plano y el *widget* de la instancia es `text_textfield` sin filtro, exactamente lo que guardaría la Field API.

### La fila que se escribe

Idéntica en la tabla de datos y en la de revisión, salvo la clave:

```php
// La clave del merge. 'revision_id' es lo único que cambia entre las dos
// tablas: en field_data_* es el vid actual del nodo, y en field_revision_*
// también — porque no se crea una revisión nueva (ver Decisión 2).
[
  'entity_type' => 'node',
  'entity_id'   => $offer_nid,
  'revision_id' => $vid,
  'deleted'     => 0,
  'delta'       => 0,
  'language'    => LANGUAGE_NONE,
]
// Los campos que acompañan a la clave.
[
  'bundle'                     => MYAPI_SERVICES_OFFER_TYPE, // 'service_offer'
  'field_firebase_path_value'  => 'service_offers/901',
  'field_firebase_path_format' => NULL,
]
```

`LANGUAGE_NONE` (`'und'`) y no un idioma: los tres campos se crearon sin `translatable`, así que la Field API guardaría exactamente eso.

`$vid` **no lo conoce ningún camino actual del chat**: `myapi_chat_offer_nids_for_uid()` y `myapi_chat_thread_row()` devuelven `nid`s. Por eso `myapi_chat_field_state()` arranca de `node` en vez de las tablas de campo — el `vid` sale de la misma consulta que ya hay que hacer para saber qué falta, y no de una segunda.

### La decisión, aislada en una función pura

`myapi_chat_field_values(array $current, $now, $is_message)` recibe lo que hay y devuelve lo que hay que escribir. Es la única parte con criterio y la única con tests:

| `$current` | `$is_message` | Devuelve |
|---|:---:|---|
| Las tres vacías | `FALSE` | `['field_firebase_path' => 'service_offers/901']` |
| Las tres vacías | `TRUE` | Las **tres**: `path`, `chat_opened_at => $now`, `last_message_at => $now` |
| `path` escrito, resto vacío | `FALSE` | `[]` — **nada, ni una consulta** |
| `path` escrito, resto vacío | `TRUE` | `['field_chat_opened_at' => $now, 'field_last_message_at' => $now]` |
| Las tres escritas | `FALSE` | `[]` |
| Las tres escritas | `TRUE` | `['field_last_message_at' => $now]` — **la única que se pisa** |

Tres reglas y ninguna más: **el `path` y el `opened_at` se escriben una vez en la vida del hilo**, el `last_message_at` se pisa siempre, y `$is_message === FALSE` no puede escribir un *timestamp* jamás.

La cuarta fila es el caso común de este spec en régimen: la app arranca, pide credencial, y **no se ejecuta ni una escritura**. Es lo que hace que la opción elegida no cueste 40 escrituras por arranque sino 40 una sola vez.

### Lo que la Field API hacía y aquí hay que hacer a mano

Escribir la tabla directamente deja dos cosas colgando, y las dos son de este spec:

```php
// 1. La caché de campo. Sin esto, un node_load() posterior devuelve el valor
//    viejo hasta que algo más invalide la entidad — y en el back office eso es
//    "he escrito el hilo y la ficha lo sigue viendo vacío".
cache_clear_all('field:node:' . $offer_nid, 'cache_field');

// 2. La caché estática de la entidad, para la MISMA petición.
entity_get_controller('node')->resetCache([$offer_nid]);
```

Las dos van dentro de `myapi_chat_field_write()`, después de los `db_merge()`, y **no hay una tercera**: `node.changed` no se toca (Decisión 2), no se crea revisión nueva, y `cache_node` no guarda valores de campo.

Ninguna de estas dos llamadas existe hoy en el módulo — es la primera vez, y por eso viven en **una sola función** y no repartidas por los dos endpoints.

---

## Plan de implementación

Nueve pasos. Los cuatro primeros **no cambian el comportamiento de nada** —añaden funciones que nadie llama— y son *commitables* uno a uno con la suite en verde.

1. **`myapi_chat_field_values()` y su test.** La función pura en `includes/myapi.chat.inc` y `tests/unit/ChatFieldMirrorTest.php` con las seis filas de la tabla del modelo de datos. Nadie la llama todavía.
   *Verificación:* `vendor/bin/phpunit --filter ChatFieldMirror` en verde, y el resto de la suite igual que antes.

2. **`myapi_chat_field_state()`.** La consulta de lectura: `node` + tres `LEFT JOIN`, devolviendo `vid` y las tres columnas por `nid`. Sigue sin llamarla nadie.
   *Verificación:* `drush php-eval "print_r(myapi_chat_field_state([901, 88]));"` sobre dos ofertas reales — salen los dos `vid` correctos y los tres valores a `NULL`.

3. **`myapi_chat_field_write()`.** Los `db_merge()` sobre las dos tablas de cada columna, más las dos invalidaciones de caché. Sin ramas y sin criterio.
   *Verificación:* `drush php-eval "myapi_chat_field_write(901, <vid>, ['field_firebase_path' => 'service_offers/901']);"`, luego `drush sqlq "SELECT * FROM field_data_field_firebase_path WHERE entity_id = 901"` y la misma consulta sobre `field_revision_field_firebase_path` — **una fila en cada una**. Y `drush php-eval "print node_load(901)->field_firebase_path[LANGUAGE_NONE][0]['value'];"` devuelve el valor, que es lo que prueba que la invalidación de caché funciona.

4. **`myapi_chat_field_sync()`.** Las tres líneas que encadenan `state` → `values` → `write`, con el `try`/`catch` y el `watchdog` alrededor. Último paso que no toca ningún endpoint.
   *Verificación:* `drush php-eval "myapi_chat_field_sync([901], TRUE);"` deja las tres columnas escritas; repetirlo cambia **sólo** `field_last_message_at`.

5. **Enganche en `myapi_chat_token()`.** Una línea en `resources/chat.resource.inc`, después de la firma y antes del `myapi_respond()`, sobre los `nid`s de los hilos que el token autoriza — derivados del `$claim` ya recortado, no de `$offer_nids`.
   *Verificación:* `POST /api/v1/chat/token` con un Bearer real → la respuesta es **idéntica a la de ayer** (mismo `token`, mismo `threads`) y `SELECT entity_id, field_firebase_path_value FROM field_data_field_firebase_path` devuelve una fila por hilo del `threads`, **ni una más**. Segunda llamada: **cero escrituras** (comprobable con el log de consultas de Devel, o porque `field_chat_opened_at` sigue vacío).

6. **Enganche en `myapi_chat_notify()`.** Una línea, tras resolver `$thread` y `$side`, **fuera** del `if ($allowed)` y **fuera** del `if ($sent)`.
   *Verificación:* `POST /api/v1/chat/threads/901/notify` → respuesta idéntica a la de ayer, y `field_chat_opened_at` y `field_last_message_at` con el mismo entero. Segundo `POST` dentro de los 60 s: la respuesta trae `"muted": 1`, **y `field_last_message_at` ha subido igual**. Con OneSignal apagado (`variable_del('myapi_onesignal_app_id')`): `"notified": 0` y el *timestamp* **también sube**.

7. **`myapi.install`.** La cadena `'description'` de la instancia `field_firebase_path` (`:2836`) y `myapi_update_7042()` con `field_read_instance()` + `field_update_instance()`, que no hace nada si la instancia no existe.
   *Verificación:* `drush updb` sin errores, y `node/<nid>/edit` de una oferta muestra la descripción nueva bajo el campo. Una instalación desde cero (`ServicesInstallTest` + `drush si`) sale ya con el texto correcto.

8. **La documentación.** La sección «Los tres campos del espejo» en `docs/chat.md`, y en `docs/service-offer.md` la fila `:255` y el párrafo `:257-265` —conservando su frase *«the day the back office has to see a thread, `field_firebase_path` gets written with the same value, and nothing in the app changes»*, que es literalmente lo que acaba de pasar.
   *Verificación:* lectura. Ninguna tabla de `docs/service-offer.md` dice ya «Always empty».

9. **Las anotaciones a 115 y 116.** Cuatro marcas **✅ Resuelto por SPEC 117** con la convención de 104/105/106: cabecera y primera viñeta de «Fuera» en el 115; cabecera y la viñeta del contador de no leídos en el 116 —esta última **marcada como resuelta sólo en su tercera parte**, porque ordenar y contar siguen sin hacerse.
   *Verificación:* lectura. Ningún spec del repositorio afirma ya que los tres campos estén vacíos.

---

## Criterios de aceptación

**El espejo se escribe**

- [ ] Un `POST /api/v1/chat/token` de un usuario con hilos deja una fila en `field_data_field_firebase_path` **y** otra en `field_revision_field_firebase_path` por cada hilo, con valor `service_offers/{nid}`.
- [ ] Ese mismo `POST` deja `field_chat_opened_at` y `field_last_message_at` **vacíos**.
- [ ] Un segundo `POST /api/v1/chat/token` del mismo usuario **no ejecuta ninguna escritura**.
- [ ] El primer `POST /api/v1/chat/threads/{nid}/notify` de un hilo escribe `field_chat_opened_at` y `field_last_message_at` con el mismo entero.
- [ ] Un `notify` posterior sube `field_last_message_at` y **no toca** `field_chat_opened_at`.
- [ ] Un `notify` sobre un hilo cuyo `field_firebase_path` está vacío escribe **las tres** columnas en la misma llamada.
- [ ] `node_load()` de la oferta inmediatamente después devuelve los valores escritos (la caché de campo quedó invalidada).
- [ ] `node.changed` de la oferta es el mismo antes y después de cualquiera de las escrituras.
- [ ] `SELECT COUNT(*) FROM node_revision WHERE nid = <nid>` es el mismo antes y después.

**El espejo no cambia nada de lo que ya funcionaba**

- [ ] La respuesta de `POST /api/v1/chat/token` es idéntica campo a campo a la de SPEC 115: `token`, `expires_at`, `firebase_uid`, `threads`.
- [ ] La respuesta de `POST /api/v1/chat/threads/{nid}/notify` es idéntica a la de SPEC 116: `thread`, `recipients`, `notified`, `muted`.
- [ ] Con OneSignal sin configurar, el `notify` responde `"notified": 0` **y `field_last_message_at` sube igual**.
- [ ] Con todos los destinatarios silenciados por el *debounce*, la respuesta trae `"muted"` distinto de cero **y `field_last_message_at` sube igual**.
- [ ] Un error de base de datos en la escritura deja una entrada en `watchdog` y la respuesta del endpoint **no cambia** (ni el código de estado ni el cuerpo).
- [ ] `ChatTokenTest` y `ChatNotifyTest` pasan **sin haber sido modificados**.

**Sólo se escribe lo que se autoriza**

- [ ] Un usuario con más de 40 hilos vivos recibe un `threads` de 40 y el `token` deja **exactamente 40** filas nuevas de `field_firebase_path`, no más.
- [ ] El `field_firebase_path` de cada hilo es byte a byte igual a `myapi_chat_thread_id($offer_nid)`.

**Nadie lo lee**

- [ ] `grep -rn "field_firebase_path\|field_chat_opened_at\|field_last_message_at" includes/ resources/ myapi.module` sólo devuelve líneas de `includes/myapi.chat.inc`, y ninguna dentro de un `SELECT` de lectura o de una condición.
- [ ] Borrar a mano las tres filas de un hilo y volver a llamar a `chat/token` devuelve el mismo `token` y el mismo `threads` que antes de borrarlas.

**Instalación y documentación**

- [ ] `drush updb` aplica `myapi_update_7042()` sin errores y `node/<nid>/edit` de una oferta muestra la descripción nueva.
- [ ] Ejecutar `myapi_update_7042()` dos veces no falla.
- [ ] `vendor/bin/phpunit` en verde, con `ChatFieldMirrorTest` cubriendo las seis filas de la tabla de decisión.
- [ ] Ningún fichero de `docs/` ni de `specs/` afirma ya que los tres campos estén vacíos.

---

## Decisiones tomadas y descartadas

1. **Sí: nadie lee lo que este spec escribe.** La ruta se sigue derivando con `myapi_chat_thread_id()` en cada firma, y la Decisión 8 del 115 queda intacta.
   **No: convertir `field_firebase_path` en la fuente de verdad**, leyéndolo cuando está escrito y derivándolo cuando no. Sería una segunda definición de la misma cosa, y la primera vez que las dos discreparan —un operador editando el campo, un `nid` reasignado— el chat autorizaría un hilo y la app abriría otro, **sin un solo error**. Un espejo que nadie consulta no puede mentir; una fuente duplicada sí.

2. **Sí: `db_merge()` directo sobre `field_data_field_*` y `field_revision_field_*`.**
   **No: `node_load()` + `node_save()` de la oferta**, que es lo que hace el resto del módulo y lo que `includes/myapi.service_offer.inc:1241` defiende por escrito. Ahí se movían **estados de negocio** con hooks detrás; aquí son tres columnas de telemetría, y las tres cosas que `node_save()` compraría no valen su precio: no dispara nada (`myapi_node_update()` no tiene rama para `service_offer`), no reordena nada (las ofertas se ordenan por `n.created`), y la revisión nueva por cada arranque de app sería basura pura. El precio de la decisión está pagado en la sección «Lo que la Field API hacía y aquí hay que hacer a mano» y en el Riesgo 1: **este spec se hace cargo de las dos invalidaciones de caché**, y es el único sitio del módulo que las conoce.
   **No: `node_save()` sólo para la apertura y `db_merge()` para el `last_message_at`.** Dos caminos de escritura para tres columnas hermanas es la duplicación que prohíbe la Regla 3 de `CLAUDE.md`, y el día que cambiara el criterio habría que acordarse de los dos.

3. **Sí: el `token` escribe el espejo de los hilos que el token autoriza, no de todos los de la consulta.** `myapi_chat_offer_nids_for_uid()` no está recortada —el corte a 40 lo hace `myapi_chat_threads_claim()`— así que un proveedor grande podría tener cientos de ofertas vivas.
   **No: escribir todos los `$offer_nids`.** Escribir el espejo de un hilo que la credencial no cubre es poner en la ficha algo que no ha ocurrido, y de paso convierte un lote acotado por construcción en uno sin techo. El recorte sale gratis: el conjunto correcto ya está calculado.
   **No: una constante `MYAPI_CHAT_OPEN_MAX_WRITES`.** Sería un segundo número que mantener sincronizado con `MYAPI_CHAT_MAX_THREADS` sin ganar nada.

4. **Sí: el espejo nunca rompe el chat.** `myapi_chat_field_sync()` envuelve las tres llamadas en un `try`/`catch`; un fallo va a `watchdog` y la respuesta del endpoint no cambia ni un byte.
   **No: dejar que la excepción suba.** El chat funciona sin estas tres columnas —lo lleva haciendo desde el 115— así que un `500` en `chat/token` por no poder escribir un dato que nadie lee sería tirar la sesión de chat de un usuario para nada. Mismo criterio con el que el 116 trata un fallo de OneSignal: se registra, no se propaga.

5. **Sí: `field_last_message_at` se escribe siempre que llegue un `notify` válido.** Aunque todos los destinatarios estén silenciados por el *debounce* y aunque OneSignal esté caído.
   **No: escribirlo sólo cuando salió el banner.** El campo se llama `last_message_at`, no `last_push_at`. Atarlo al transporte haría que un minuto de caída de OneSignal se viera para siempre en la ficha como un minuto sin conversación, y el operador estaría leyendo el estado de un proveedor externo creyendo que lee el del chat.

6. **Sí: el `token` escribe sólo `field_firebase_path`; `field_chat_opened_at` lo escribe el primer `notify`.** Así las tres columnas dicen tres cosas distintas y las tres útiles: *el hilo existe* / *el primer mensaje* / *el último*.
   **No: que el `token` escriba también `chat_opened_at`.** Un residente que arranca la app y no escribe jamás marcaría abiertos sus doce hilos, y el campo pasaría a significar «se emitió una credencial», que es exactamente lo que el `path` ya dice. Una columna que repite a su vecina no se escribe.

7. **No: *backfill*.** Los hilos vivos de hoy salen vacíos y se llenan solos en el próximo arranque de app de cualquiera de los dos lados. Un `updb` que recorre miles de filas para poblar un dato que nadie lee, y que además se poblaría solo en días, no se paga. El precio —hilos de cuentas inactivas que quedan vacíos para siempre— está en el Riesgo 2.

8. **Sí: corregir la descripción de la instancia con `myapi_update_7042()`.** Hoy dice *«Editarla a mano rompe el chat sin dar ningún error»*, y con la Decisión 1 eso es falso.
   **No: dejarla y explicarlo sólo en `docs/`.** El operador que edite ese campo dentro de un año leerá la etiqueta del formulario, no `docs/chat.md`. Una etiqueta que miente sobre la consecuencia de una acción es peor que no tener etiqueta.
   **No: reusar `_myapi_reservations_ensure_instance()`.** Sólo crea si falta (`myapi.install:881`), así que sobre un sitio instalado no haría absolutamente nada — y el fallo sería silencioso.

9. **No: deshabilitar los tres *widgets* en el formulario del nodo.** Con la Decisión 1 no hay nada que proteger: lo que un operador escriba a mano ensucia su propia ficha y no altera ni la autorización ni el transporte. El día que algo lea estas columnas, bloquearlas deja de ser cosmético y va en el spec que las lea.

10. **No: barrer el espejo cuando el hilo muere.** Una oferta que pasa a `rejected` o `withdrawn` deja de ser un hilo y conserva sus tres columnas. Es historial —*aquí hubo una conversación y este fue su último mensaje*—, no estado, y limpiarlo pediría un *cron* que este módulo no tiene y una decisión sobre a los cuántos días, que nadie ha tomado.

---

## Riesgos identificados

| # | Riesgo | Mitigación |
|---|---|---|
| 1 | **Es la primera escritura del módulo que se salta la Field API**, y las dos invalidaciones de caché no las recuerda ningún precedente. Una cuarta columna añadida al espejo dentro de un año, escrita en otro sitio, se olvidaría de ellas y el back office vería datos viejos sin un solo error. | Las dos invalidaciones viven **dentro de `myapi_chat_field_write()`** y no en los endpoints, así que cualquier columna nueva las hereda por pasar por la misma puerta. Es la razón de que el escritor sea una función y no dos líneas repetidas. |
| 2 | **Sin *backfill*, un hilo de dos cuentas inactivas se queda vacío para siempre.** El operador que abra esa ficha verá tres columnas en blanco sobre una conversación que existió. | Aceptado (Decisión 7). Vacío significa «nadie ha vuelto a abrir la app desde el despliegue», no «no hubo chat», y eso queda escrito en `docs/service-offer.md`. La salida, si algún día molesta, es un `hook_update_N` de una sola consulta que ya está descrita en el Alcance como fuera. |
| 3 | **La primera llamada de un usuario con 40 hilos vivos paga hasta 80 `db_merge()` y 40 invalidaciones dentro de la petición de `chat/token`**, que es la que la app hace al arrancar. | Ocurre **una vez en la vida de cada hilo**: la segunda llamada no escribe nada (fila 3 de la tabla de decisión). Son `merge`s por clave primaria sobre tablas de campo, sin joins. Si aun así molestara, el techo ya está acotado en 40 por la Decisión 3, y bajarlo es cambiar una constante que ya existe. |
| 4 | **Un `node_save()` concurrente de la misma oferta puede perder la escritura del espejo**: si otro proceso guarda el nodo entre nuestra lectura del `vid` y nuestro `merge`, la Field API reescribe `field_data_*` con los valores que cargó antes que nosotros. | Se pierde un dato de telemetría, no un dato de negocio: el siguiente `notify` lo vuelve a escribir y el siguiente `token` repone el `path`. No se abre transacción ni se bloquea la fila — el coste de proteger esto sería mayor que el de perderlo. |
| 5 | **`field_chat_opened_at` depende de que el cliente llame al `notify`.** Un cliente que escriba en Firebase y no avise —el Riesgo 1 heredado de la Decisión 1 del 116— deja el campo vacío mientras la conversación existe de verdad. | Es el mismo agujero que ya tiene el push, no uno nuevo, y se cierra el mismo día: cuando el aviso deje de depender del cliente, el espejo deja de depender de él. Mientras tanto el `path` sí se escribe siempre, así que el operador nunca ve un hilo «inexistente» — ve uno sin fecha. |

---

## Lo que **no** está en este spec

- El ***backfill*** de los hilos que ya existen.
- **Leer los tres campos desde cualquier parte**: ningún endpoint los devuelve, ninguna consulta ordena por ellos, ninguna condición los mira. `myapi_chat_thread_id()` sigue siendo la única fuente de verdad de la ruta.
- **Ordenar hilos y contar no leídos por `field_last_message_at`.** Eso lo sabe Firebase.
- **Bloquear los tres *widgets*** en el formulario del nodo.
- **Limpiar el espejo** cuando la oferta muere.
- **El back office de verdad**: un `view mode`, un formateador o un enlace «ver el hilo» desde `node/N`.
- **Escribir nada del lado de Firebase.**
- **Adjuntos, retención, moderación y revocación inmediata**, que siguen fuera desde el 115.

Cada una de ellas, si llega, va en su propio spec.
