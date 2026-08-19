# 92 — La transacción inicial de una solicitud de servicio

- **Estado:** Implemented
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `service_transaction` y de sus cuatro campos (`field_request`,
    `field_request_status`, `field_status_date`, `field_comment`), del catálogo
    `myapi_services_request_statuses()` y de `includes/myapi.services_common.inc`.
    Este spec **escribe** en ese bundle por primera vez; no crea ni modifica
    ningún campo, instancia ni tipo de contenido.
  - `87-service-request-direct-status` (Implemented) — dueña de `direct`, el
    segundo estado en el que una solicitud puede **nacer**. Es la razón de que
    el acuse tenga dos textos y no uno.
  - `90-service-request-create` (Implemented) — el `POST` que dejó
    explícitamente fuera la transacción («*NO service_transaction IS CREATED*»,
    `resources/service_request.resource.inc:2204`). Este spec la añade sin tocar
    una línea ejecutable de ese endpoint: solo ese docblock, que a partir de
    aquí miente.
  - `57-claim-transaction-timeline-and-modal` (Implemented) — precedente exacto:
    `myapi_claim_transaction_create_initial()` colgada de `hook_node_insert()`,
    que es lo que cubre el formulario del back office y cualquier camino
    programático con **una sola** implementación.
  - `60-claim-transaction-auto-title` (Implemented) — precedente del título
    autogenerado en `hook_node_presave()`, y del reparto puro / Field API
    (`myapi_claim_transaction_title()` vs `set_title()`) que este spec replica.
  - `61-claim-transaction-initial-comment` (Implemented) — precedente del
    comentario de acuse, y de por qué una transacción nunca nace sin comentario.

**Objetivo:** Que toda solicitud de servicio nazca con su primera entrada de
línea de tiempo — un `service_transaction` que copia el estado con el que la
solicitud fue creada — tanto si viene de `node/add/service_request` como de
`POST /api/v1/service-requests`.

Cuatro notas que la cabecera fija:

- **El disparador es el hook de nodo, no el endpoint ni el formulario.** Una
  rama `service_request` en `myapi_node_insert()` cubre los dos caminos
  pedidos, y también cualquier tercero futuro (migración, otro recurso). Es la
  decisión que SPEC 57 ya tomó para reclamos, y por eso `myapi_claim_create()`
  no tiene ni una línea sobre transacciones.
- **«Según el estado» significa copiar, no filtrar.** Siempre se crea
  exactamente una transacción, y su `field_request_status` es el que la
  solicitud tenga en ese instante — `open`, `direct` o el que el operador
  eligiera. El estado no decide *si* se crea, decide *qué se registra* y *qué
  texto de acuse* lleva.
- **El título entra en el spec por necesidad, no por gusto.**
  `service_transaction` tiene título nativo y nadie lo escribe: sin esto, cada
  transacción automática se guardaría con `title = ''` y `/admin/content`
  mostraría una lista de enlaces en blanco — el mismo agujero que SPEC 60 vino
  a tapar en reclamos, ya conocido y ya resuelto una vez.
- **Ninguna respuesta de API cambia.** No se toca `myapi_menu()`, ni
  `hook_schema()`, ni el serializador del listado o del detalle. La línea de
  tiempo queda escrita en base de datos y **no expuesta**: exponerla es otro
  spec, igual que en reclamos lo fue.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.service_transaction.inc`** (nuevo) — espejo de
  `includes/myapi.claim_transaction_admin.inc`, pero solo con la mitad que este
  spec necesita (sin página de administración, sin formulario, sin timeline).
  Dos constantes guardadas con `defined()` y cinco funciones, repartidas en dos
  grupos:
  - **Puras** (sin base de datos, sin Field API; solo `t()`, `format_date()` y
    `truncate_utf8()`), que es lo que las mete enteras en `tests/unit`:
    - `myapi_service_transaction_initial_comment($status)` — el texto de acuse,
      tres variantes según el estado.
    - `myapi_service_transaction_title($request_nid, $status_label, $status_date, $comment)`
      — compone el título a partir de cuatro valores ya resueltos.
    - `myapi_service_transaction_title_comment($comment)` — el comentario
      colapsado a una línea y recortado, para que quepa en el título.
  - **Con Field API**:
    - `myapi_service_transaction_set_title($node)` — delegada de
      `hook_node_presave()`.
    - `myapi_service_transaction_create_initial($node)` — delegada de
      `hook_node_insert()`.

- **`myapi.module`** (modificar) — solo pegamento, dos ramas nuevas:
  - `myapi_node_presave()` gana una rama `service_transaction` que llama a
    `myapi_service_transaction_set_title()`. Va junto a la rama
    `claim_transaction` que ya hace exactamente lo mismo para el otro bundle.
  - `myapi_node_insert()` gana una rama `service_request` que llama a
    `myapi_service_transaction_create_initial()`. Ninguna rama existente
    cambia: las actuales (`pagos`, `reclamo`, `claim_transaction`, `boletin`)
    quedan intactas.

- **`myapi.info`** (modificar) — `files[] = includes/myapi.service_transaction.inc`.

- **`resources/service_request.resource.inc`** (modificar) — **solo el
  docblock** de `myapi_service_request_create()`, cuyo párrafo «*NO
  service_transaction IS CREATED. Unlike 'reclamo', this bundle has no
  hook_node_insert() branch…*» (línea 2204) pasa a decir lo contrario y a
  apuntar a este spec. **Cero cambios ejecutables** en el fichero: el endpoint
  no llama a nada nuevo — la transacción la dispara el `node_save()` que ya
  hace, igual que en `myapi_claim_create()`.

- **`tests/unit/ServiceTransactionTest.php`** (nuevo) — al estilo de
  `ClaimTransactionTitleTest` + `ClaimTransactionInitialCommentTest`: los tres
  textos de acuse, el fallback del estado desconocido, la composición del
  título con y sin cada segmento, el colapso del comentario, el recorte a 255 y
  el guard que lee `myapi.module` como texto para verificar que las dos ramas
  existen y delegan.

- **`docs/service-transaction.md`** (nuevo) — no es doc de endpoint (no hay
  ninguno): documenta el comportamiento automático, los tres textos y el
  formato del título, siguiendo el precedente de
  `docs/claim-transaction-timeline.md`.

- `drush cc all` al final — hay un `.inc` nuevo registrado en `myapi.info`.
  **No hay `drush updb`**: ni campos, ni instancias, ni bundles, ni tablas.

**Fuera de alcance (para specs futuros):**

- **`hook_node_update()`.** Cambiar el estado de una solicitud ya creada — hoy
  solo posible desde `node/%nid/edit` — **no** genera una transacción nueva.
  Cada transición tendrá su propio spec (ofertar, adjudicar, cerrar, cancelar)
  y creará su transacción ahí, con el resto de sus efectos.
- **Sincronización inversa transacción → solicitud.** Guardar un
  `service_transaction` con otro estado **no** re-escribe
  `field_request_status` de la solicitud, al contrario de lo que
  `myapi_claim_transaction_sync_claim_status()` hace en reclamos. Sin validación
  del grafo, esa sincronización dejaría poner una solicitud en un estado
  imposible desde `node/add/service_transaction`.
- **Validar el grafo de transiciones.** `myapi_services_request_transitions()`
  sigue sin un solo lector, igual que desde SPEC 77.
- **Exponer la línea de tiempo en la API.** Ni `transactions` en el detalle
  (SPEC 89), ni en el `201` del `POST` (SPEC 90), ni en el listado (SPEC 88).
  Las tres respuestas quedan byte a byte iguales.
- **La tabla de la línea de tiempo en el back office** y su botón «Crear
  transacción», con todo lo que arrastra: `hook_form_alter()`, permisos, una
  ruta de `hook_menu()` y un formulario propio. En reclamos eso fue un spec
  entero (SPEC 57).
- **Backfill de las solicitudes ya existentes.** Las creadas antes de este spec
  no reciben transacción retroactivamente: el hook solo corre en un `INSERT`.
- **Notificaciones** de ningún tipo. El marketplace no tiene todavía ni una, así
  que aquí no hay ninguna bandera transitoria que poner: el
  `$node->myapi_skip_claim_notification` de reclamos **no** tiene equivalente en
  este spec.
- **Claves i18n / `myapi_t()`.** Los tres textos y el título los lee el
  operador en el back office, no viajan en ninguna respuesta. Van con `t()`,
  exactamente como `myapi_claim_transaction_initial_comment()`.
- **`myapi.install` y cualquier `hook_update_N`.** El bundle y sus cuatro campos
  ya existen desde SPEC 77 sin un solo cambio pendiente.

---

## Modelo de datos

**No se crean campos, instancias, bundles ni tablas.** El bundle
`service_transaction` y sus cuatro campos existen intactos desde SPEC 77; este
spec es el primero que escribe en ellos. Lo único nuevo son tres textos, un
formato de título y dos constantes.

### El nodo que se crea, campo a campo

| Propiedad / campo | Valor | Notas |
|---|---|---|
| `type` | `service_transaction` | |
| `uid` | El `uid` de la solicitud recién insertada | Vía API, el residente del token; vía back office, el operador que la guardó. Es un hecho, no una decisión — mismo criterio que reclamos. |
| `status` | `1` | Publicada. |
| `title` | Autogenerado en `hook_node_presave()` | Ver más abajo. Este creador **no** escribe el título: lo pone la rama de presave, que corre después y cubre también las transacciones creadas a mano. |
| `field_request` | `target_id` = `nid` de la solicitud | |
| `field_request_status` | Copiado tal cual de `field_request_status` de la solicitud | Es «según el estado». Si la solicitud nació sin estado (imposible por formulario, posible en un `node_save()` programático descuidado), se copia el vacío: la transacción registra lo que hubo, no lo que debería haber habido. |
| `field_status_date` | `date('Y-m-d H:i:00')` | Instante real del servidor, segundos fijados a `:00`. El campo es `datetime` (compartido con `claim_transaction`, SPEC 77), no `datestamp`: se escribe la cadena, no un timestamp. |
| `field_comment` | `myapi_service_transaction_initial_comment($status)` | Se escribe **solo** `value`, sin `format`, igual que en reclamos: la columna se guarda cruda y quien la muestre la escapa. |

Los valores se leen de la solicitud con
`myapi_building_admin_field_value()` y `myapi_building_admin_field_target_id()`
(`includes/myapi.building_admin.inc`, SPEC 49), reutilizadas tal cual en vez de
volver a escribir `$node->field_x[LANGUAGE_NONE][0]['value']` a mano — es lo que
ya hace `myapi_claim_transaction_create_initial()`.

### Los tres textos de acuse

`myapi_service_transaction_initial_comment($status)`, pura, con `t()`:

| Estado de nacimiento | Texto |
|---|---|
| `open` | Hemos recibido su solicitud. Los proveedores de la categoría podrán enviarle ofertas y se le notificará cualquier novedad. |
| `direct` | Hemos recibido su solicitud y fue enviada al proveedor seleccionado. Se le notificará cualquier novedad. |
| `offered`, `assigned`, `closed`, `cancelled`, o cualquier clave desconocida | Solicitud registrada con estado @estado. |
| `NULL` o `''` | Solicitud registrada. |

- `@estado` es la **etiqueta** del catálogo `myapi_services_request_statuses()`
  («Con ofertas», «Asignada»…), o la clave cruda si la clave no está en el
  catálogo. Un estado desconocido degrada a un texto que sigue leyéndose, nunca
  a una transacción sin comentario.
- El catálogo se lee de `includes/myapi.services_common.inc`, que es la única
  fuente de verdad —la misma que `myapi.install` usa para construir los
  `allowed_values`—, y **no** se transcriben etiquetas a mano. Diferencia
  deliberada con reclamos, donde el texto del acuse evita el catálogo a
  propósito: allí las etiquetas son editables desde la UI y podían reescribir la
  frase a media oración; aquí el catálogo está en código y no lo toca nadie
  desde el navegador.
- Los dos textos de `open` y `direct` son los únicos que hablan en primera
  persona al residente. Los demás describen un registro administrativo, porque
  eso es exactamente lo que son: una solicitud que un operador crea ya en
  `closed` no está acusando recibo de nada.

### El título autogenerado

`myapi_service_transaction_title($request_nid, $status_label, $status_date, $comment)`,
pura, cuatro segmentos unidos por ` · `:

```
Solicitud #412 · Abierta · 19/08/2026 14:30 · Hemos recibido su solicitud. Los…
```

- **Cada segmento se omite si su valor falta**, sin separador colgando. Si no
  resuelve ninguno, el título es `Transacción de solicitud` — la promesa de que
  una transacción nunca vuelve a quedarse sin título.
- La fecha se renderiza `d/m/Y H:i` vía `strtotime()`: un valor no parseable
  **cae el segmento** en lugar de imprimir `01/01/1970`.
- El comentario pasa antes por `myapi_service_transaction_title_comment()`, que
  colapsa saltos de línea y espacios múltiples a uno solo y recorta a **60**
  caracteres con `truncate_utf8($wordsafe, $add_ellipsis)`; el corte cae en
  frontera de palabra y termina en `…`.
- El título completo se recorta a **255** (`node.title` es `varchar(255)`), para
  que el corte lo haga PHP en frontera de carácter UTF-8 y no MySQL a mitad de
  uno.
- Nada se escapa aquí: `node.title` se guarda crudo y Drupal lo escapa al
  pintarlo.

### Las dos constantes

| Constante | Valor | Para qué |
|---|---|---|
| `MYAPI_SERVICE_TRANSACTION_TITLE_COMMENT_LENGTH` | `60` | Cuánto comentario cabe en el título. |
| `MYAPI_SERVICE_TRANSACTION_TITLE_MAX_LENGTH` | `255` | Techo de `node.title` / `node_revision.title`. |

Ambas con guard `defined()`, como todas las del módulo, para que un
`require_once` de PHPUnit junto a otro fichero no emita aviso de redefinición.
Son propias y **no** se reutilizan las de `claim_transaction`: ese include no lo
carga este, y compartirlas ataría el largo del título de dos features que pueden
divergir.

---

## Plan de implementación

1. **`includes/myapi.service_transaction.inc` — la mitad pura.** Cabecera
   `@file`, `module_load_include()` de `includes/myapi.services_common` (para el
   catálogo), las dos constantes con guard `defined()`, y las tres funciones sin
   Field API: `myapi_service_transaction_initial_comment()`,
   `myapi_service_transaction_title()` y
   `myapi_service_transaction_title_comment()`. Va primero porque es lo único
   que se puede probar sin sitio arrancado, y porque los pasos 3 y 5 la
   consumen. *Verificación: `php -l`.*

2. **`myapi.info` + `drush cc all`.** `files[] = includes/myapi.service_transaction.inc`.
   Aquí y no al final: a partir de este punto el include está registrado y los
   pasos siguientes se pueden probar en el sitio conforme se escriben.
   *Verificación: `drush cc all` sin errores y el módulo sigue habilitado.*

3. **Mismo include — `myapi_service_transaction_set_title()`.** Lee los cuatro
   valores del nodo con los dos helpers de SPEC 49, resuelve la etiqueta contra
   `myapi_services_request_statuses()` (con fallback al valor crudo si la clave
   no está) y escribe `$node->title`. *Verificación: `php -l`.*

4. **`myapi.module` — rama `service_transaction` en `myapi_node_presave()`.**
   Tres líneas de pegamento junto a la rama `claim_transaction`, con su docblock
   ampliado. *Verificación en el sitio: crear una transacción a mano desde
   `node/add/service_transaction` y ver el título compuesto en
   `/admin/content`; editarla y ver que el título se regenera.*

   > El título entra **antes** que el creador automático a propósito: cuando el
   > paso 6 empiece a insertar transacciones, ninguna podrá nacer sin título ni
   > un solo commit.

5. **Mismo include — `myapi_service_transaction_create_initial()`.** Construye
   el nodo con `node_object_prepare()`, escribe los cuatro campos de la tabla
   del modelo de datos y llama a `node_save()`. Sin bandera transitoria de
   notificación: no hay notificador al que silenciar. *Verificación: `php -l`.*

6. **`myapi.module` — rama `service_request` en `myapi_node_insert()`.** El
   pegamento que enciende todo, colocada junto a la rama `reclamo` que hace lo
   equivalente, con su docblock. *Verificación en el sitio: (a) crear una
   solicitud desde `node/add/service_request` en estado «Abierta» y comprobar en
   `/admin/content` que aparece **una** transacción, con el estado copiado, la
   fecha del momento y el acuse de `open`; (b) `curl -F` contra
   `POST /api/v1/service-requests` sin `assigned_provider_id` → misma
   transacción, mismo texto; (c) el mismo `curl` **con** un
   `assigned_provider_id` elegible → transacción en `direct` con el otro texto;
   (d) crear una desde el back office ya en «Cerrada» → transacción con el texto
   genérico.*

7. **`resources/service_request.resource.inc` — el docblock.** Sustituir el
   párrafo «*NO service_transaction IS CREATED…*» por la descripción real, con
   la referencia a este spec y a la rama del hook. *Verificación: `git diff` del
   fichero no toca ni una línea ejecutable.*

8. **`tests/unit/ServiceTransactionTest.php`.** `require_once` de **los dos**
   includes —`myapi.services_common.inc` y `myapi.service_transaction.inc`—
   porque `module_load_include()` es un no-op en `tests/unit/bootstrap.php` y sin
   él el catálogo no existiría. Cubre: los cuatro casos del acuse (`open`,
   `direct`, un estado del catálogo, una clave desconocida) más el `NULL`; el
   título con los cuatro segmentos, sin comentario, sin fecha, con fecha
   ilegible y completamente vacío; el colapso y recorte del comentario a 60; el
   techo de 255. Más dos guards que leen `myapi.module` como texto y fallan si
   alguna de las dos ramas desaparece — mismo recurso que ya usa
   `ServicesInstallTest`. *Verificación: la suite completa en verde, sin una
   sola regresión en los tests existentes.*

9. **`docs/service-transaction.md`.** Qué se crea y cuándo, la tabla de los
   cuatro campos, los tres textos, el formato del título, y una sección
   explícita de «lo que este comportamiento **no** hace» (no reacciona a
   ediciones, no sincroniza al revés, no se expone en la API, no notifica).

10. **Aplicar y verificar.** `drush cc all` y recorrer los criterios de
    aceptación en el sitio.

**No se toca:** `myapi.install` (ningún `hook_update_N`, ningún campo),
`hook_menu()` (ninguna ruta), `hook_schema()` (ninguna tabla),
`includes/myapi.services_common.inc` (el catálogo se lee, no se amplía), ni
ninguno de los tres serializadores de `service_request` (listado, detalle y
`201` responden igual).

---

## Criterios de aceptación

**La transacción se crea, por los dos caminos**

- [x] Crear una solicitud desde `node/add/service_request` deja **exactamente una** `service_transaction` apuntando a ella por `field_request`.
- [x] `POST /api/v1/service-requests` deja **exactamente una**, indistinguible de la anterior salvo por el `uid`.
- [x] `field_request_status` de la transacción es **idéntico** al de la solicitud en el instante de crearla: `open`, `direct`, o el que el operador eligiera en el formulario (`offered`, `assigned`, `closed`, `cancelled`).
- [x] `field_status_date` es el instante real del alta con los segundos en `:00`, no la medianoche de ese día.
- [x] El `uid` de la transacción es el de la solicitud: el residente del token vía API, el operador vía back office.
- [x] La transacción nace publicada (`status = 1`).
- [x] **Editar** una solicitud ya creada —cambiarle el estado, el título o la descripción desde `node/%nid/edit`— **no** crea ninguna transacción nueva.
- [x] Guardar un `service_transaction` **no** modifica `field_request_status` de su solicitud, ni siquiera cuando difieren.
- [x] Las solicitudes creadas **antes** de este cambio siguen sin transacción; nada las toca.

**Los tres textos**

- [x] Una solicitud nacida en `open` produce el acuse de ofertas; una nacida en `direct`, el del proveedor seleccionado. Son textos distintos.
- [x] Una solicitud creada desde el back office en `closed` produce «Solicitud registrada con estado Cerrada.» — con la **etiqueta** del catálogo, no la clave.
- [x] Una clave de estado que no está en el catálogo produce el mismo texto genérico con la clave cruda, no un comentario vacío ni un error.
- [x] Un estado ausente o vacío produce «Solicitud registrada.», sin `@estado` colgando.
- [x] Cambiar una etiqueta en `myapi_services_request_statuses()` cambia el texto genérico sin tocar `includes/myapi.service_transaction.inc`.

**El título**

- [x] Ninguna `service_transaction` creada tras este cambio tiene `title = ''`: `/admin/content` no muestra un solo enlace en blanco.
- [x] Con los cuatro valores presentes, el título es `Solicitud #<nid> · <Etiqueta> · <d/m/Y H:i> · <comentario recortado>`.
- [x] Falta un valor → falta su segmento, sin ` · ` colgando al principio, en medio ni al final.
- [x] Una `field_status_date` no parseable **omite** el segmento en vez de imprimir `01/01/1970`.
- [x] Un comentario con saltos de línea sale en una sola línea; uno de más de 60 caracteres se corta en frontera de palabra y termina en `…`; uno de exactamente 60 **no** lleva `…`.
- [x] El título nunca supera 255 caracteres, y el corte cae en frontera de carácter UTF-8.
- [x] Crear una transacción a mano desde `node/add/service_transaction` también recibe el título compuesto; editarla lo **regenera** en vez de dejar el anterior.
- [x] Sin ningún valor resoluble, el título es `Transacción de solicitud`.

**No regresión — el criterio caro de este spec**

- [x] `GET /api/v1/service-requests`, `GET /api/v1/service-requests/%` y el `201` de `POST /api/v1/service-requests` devuelven **exactamente las mismas claves y valores** que antes: ninguna gana `transactions`.
- [x] `offers_count` **no** cuenta la transacción nueva. `field_request` está compartido por `service_offer` y `service_transaction` (SPEC 77), y el filtro por bundle de SPEC 88/89 es lo único que lo impide: es el punto exacto donde este spec podría romper algo ya entregado.
- [x] El array `offers` del detalle sigue sin incluir la transacción.
- [x] El rol `proveedor` ve la transacción exactamente igual de lejos que la solicitud a la que pertenece: el modo `via_request` de SPEC 78 ya la cubre y no se toca.
- [x] Las ramas previas de `myapi_node_insert()` (`pagos`, `reclamo`, `claim_transaction`, `boletin`) y las de `myapi_node_update()` quedan intactas: crear un reclamo sigue generando **una** `claim_transaction`, ni una más.
- [x] La rama `claim_transaction` de `myapi_node_presave()` sigue titulando reclamos igual: los títulos de las transacciones de reclamos no cambian ni un carácter.
- [x] `resources/service_request.resource.inc` no cambia ni una línea ejecutable — solo comentarios.
- [x] `myapi.install` no aparece en el diff; no hay `hook_update_N` nuevo y `drush updb` no tiene nada que ejecutar.
- [x] `myapi.info` no declara ninguna dependencia nueva.
- [x] Insertar la transacción **no** dispara una segunda inserción: no hay rama `service_transaction` en `myapi_node_insert()` y nada re-guarda la solicitud, así que no hay cascada.

**Pruebas y cierre**

- [x] `tests/unit/ServiceTransactionTest.php` pasa, y la suite completa sigue en verde sin ninguna regresión.
- [x] Un test falla si la rama `service_request` de `myapi_node_insert()` o la rama `service_transaction` de `myapi_node_presave()` desaparecen de `myapi.module`.
- [x] `drush cc all` no reporta errores.
- [x] `docs/service-transaction.md` existe y describe los tres textos, el formato del título y las cuatro cosas que este comportamiento no hace.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Dónde cuelga el disparador | Una rama `service_request` en `hook_node_insert()` | Llamar al creador desde `myapi_service_request_create()` y desde el submit del formulario nativo | Es literalmente el enunciado: «desde el back office **o** desde el endpoint». Dos llamadores son dos sitios que mantener y uno que alguien olvidará el día que aparezca el tercer camino (migración, otro recurso). El hook los cubre todos con una implementación, que es la razón por la que `myapi_claim_create()` no tiene ni una línea sobre transacciones (SPEC 57). |
| Qué decide «según el estado» | Siempre se crea una transacción, que **copia** el estado de nacimiento | Crear solo si el estado es `open` o `direct` | Filtrar dejaría sin primera entrada justo a las solicitudes que un operador registra ya cerradas o canceladas — las que más falta hacen de un rastro de por qué están así. El estado decide **qué se registra** y **qué texto** lleva, no si existe la fila. |
| Alcance temporal | Solo `hook_node_insert()` | Añadir también `hook_node_update()` para crear una transacción en cada cambio de estado | Cada transición futura (ofertar, adjudicar, cerrar, cancelar) va a tener su propio spec y su propia lógica; ahí es donde tiene que nacer su transacción, junto al resto de sus efectos. Un `hook_node_update()` genérico ahora se quedaría a medias con todos ellos y habría que desmontarlo. |
| Sincronización inversa transacción → solicitud | **No** se replica | Copiar `myapi_claim_transaction_sync_claim_status()` de SPEC 57 | En reclamos la sincronización es segura porque el estado no tiene grafo que violar. Aquí sí lo hay (`myapi_services_request_transitions()`) y **nadie lo valida todavía**: la sincronización dejaría a cualquiera poner una solicitud en un estado imposible desde `node/add/service_transaction`, sin pasar por ninguna regla. Entra cuando entre el validador. |
| El título | Autogenerado en `hook_node_presave()`, cubriendo también las transacciones creadas a mano | Componerlo dentro del creador automático, o dejarlo para su propio spec | Presave es donde `field_attach_presave()` ya ha normalizado `field_status_date` y donde la fila aún no se ha escrito, así que no hace falta un segundo `node_save()`. Dejarlo fuera habría repetido el bug que SPEC 60 ya diagnosticó y arregló una vez: `node.title` es `NOT NULL DEFAULT ''`, así que nadie falla — solo aparecen enlaces en blanco en `/admin/content`. |
| Orden de implementación | El título (pasos 3–4) **antes** que el creador (pasos 5–6) | Escribir primero lo que se pidió y titular después | Así ninguna transacción automática puede nacer sin título en ningún commit intermedio. Cuesta cero y elimina un estado sucio del repositorio. |
| El comentario | Acuse automático, con tres variantes | Dejar `field_comment` vacío | Es el agujero exacto que SPEC 61 tapó en reclamos: sin comentario, la primera fila de la línea de tiempo dice `—` y no confirma nada. Un acuse cuesta una función pura. |
| Cuántos textos | Tres: `open`, `direct` y un genérico de registro | Uno solo para todo | `open` y `direct` son promesas distintas al residente (unos van a ofertar / uno ya fue elegido), y una solicitud creada por un operador ya en `closed` no está acusando recibo de nada. Un texto único mentiría en dos de los tres casos. |
| De dónde salen las etiquetas del texto genérico | Del catálogo `myapi_services_request_statuses()` | Transcribirlas a mano en el texto, como hace `myapi_claim_transaction_initial_comment()` | Divergencia **deliberada** con reclamos, y por un motivo concreto: allí las etiquetas son `allowed_values` editables desde la UI, y un administrador cambiándolas reescribiría la frase a media oración; aquí el catálogo vive en código y no lo toca nadie desde el navegador. La regla de fondo es la misma en los dos casos: la fuente de la etiqueta es la que no puede cambiar bajo los pies del texto. |
| Autor de la transacción | Copiar el `uid` de la solicitud | Fijarlo siempre al residente (`field_requester`), o al usuario 1 | `node.uid` responde «quién guardó el nodo que originó esto», que es un hecho verificable y el mismo que registra la solicitud. `field_requester` responde otra pregunta distinta —«de quién es la solicitud»— y ya está guardada en la solicitud misma; duplicarla aquí sería inventar un dato. |
| Dónde vive el código | `includes/myapi.service_transaction.inc` (nuevo) | Meterlo en `includes/myapi.services_common.inc` | `services_common` está declarado **puro** —sin base de datos, sin Drupal— y sus tests lo requieren sin sitio arrancado. `node_save()` ahí dentro rompería esa propiedad, que es justo lo que hace testeable todo el catálogo de la feature. |
| Nombre del include | `myapi.service_transaction.inc`, sin el sufijo `_admin` | `myapi.service_transaction_admin.inc`, calcando `myapi.claim_transaction_admin.inc` | Aquel fichero se llama `_admin` porque **es** una página de administración (ruta, formulario, tabla). Este no expone ni una pantalla: son dos delegadas de hooks y tres funciones puras. El sufijo prometería algo que no está dentro. |
| Constantes del título | Propias (`MYAPI_SERVICE_TRANSACTION_TITLE_*`) | Reutilizar las de `claim_transaction` | Reutilizarlas obligaría a que este include cargue el de reclamos entero para leer dos números, y ataría el largo del título de dos features que no tienen por qué evolucionar juntas. Duplicar dos `define()` cuesta menos que la dependencia. |
| Bandera transitoria de notificación | No existe | Copiar `$node->myapi_skip_claim_notification` de SPEC 68 | No hay ni un notificador en el marketplace todavía: la bandera no silenciaría nada y sería una pieza que quien escriba las notificaciones tendría que entender antes de poder ignorarla. Cuando llegue ese spec, decidirá él si la transacción inicial calla. |
| Exponer la línea de tiempo en la API | Fuera | Añadir `transactions` al detalle y al `201` | Cambiaría dos respuestas ya entregadas (SPEC 89 y 90) en el mismo spec que estrena el dato. En reclamos fueron dos specs distintos por la misma razón: primero se crea el dato, después se decide su forma en la API. |
| La tabla de la línea de tiempo en el back office | Fuera | Replicar `myapi_claim_transaction_timeline_build()` para `service_request` | Arrastra `hook_form_alter()`, una ruta de `hook_menu()`, permisos y un formulario de creación propio. En reclamos eso ocupó un spec entero (SPEC 57); meterlo aquí duplicaría el tamaño de este. |
| Backfill de solicitudes existentes | Fuera | Un `hook_update_N` que cree la transacción faltante leyendo `node.created` | Una transacción inventada llevaría un acuse que nadie emitió. Y la fecha, aunque se leyera de `node.created`, seguiría acompañando a un estado que es el **actual**, no el de nacimiento: para las solicitudes que ya cambiaron de estado, la fila sería directamente falsa. |
| Formato de `field_status_date` | `date('Y-m-d H:i:00')` | `date('Y-m-d')`, dejando la hora a medianoche | Es el instante real del alta, no el día. La medianoche fue exactamente el bug que SPEC 58 corrigió en reclamos; no se repite. |
| Traducción de los textos | `t()` | El catálogo `myapi_t()` / `includes/myapi.i18n.inc` | `myapi_t()` existe para traducir lo que viaja en una respuesta de API según `Accept-Language`. Estos textos los lee el operador en el back office y hoy no salen por ninguna respuesta. El día que la línea de tiempo se exponga, ese spec decidirá si migran. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`field_request` está compartido por `service_offer` y `service_transaction`** (SPEC 77). Hasta hoy ese campo solo tenía filas de ofertas, así que cualquier consulta descuidada sobre `field_data_field_request` daba el resultado correcto por accidente. A partir de este spec deja de darlo. Es el riesgo más caro: `offers_count` contaría transacciones como ofertas, y el detalle las listaría dentro de `offers`. | El filtro por bundle ya existe en `myapi_service_request_base_query()` y en el detalle (SPEC 88/89), y `ServiceRequestListEndpointTest` / `ServiceRequestDetailEndpointTest` ya siembran una `service_transaction` a propósito para comprobar que se excluye. Este spec no añade código nuevo ahí: lo que hace es convertir esos tests, que hasta ahora probaban una hipótesis, en la primera línea de defensa real. Está como criterio de aceptación explícito. |
| **Una excepción dentro de `myapi_service_transaction_create_initial()` aborta la creación de la solicitud entera.** `node_save()` corre en una transacción de base de datos y `hook_node_insert()` va dentro: un fallo al guardar la transacción tumba también la solicitud, y el `POST` responde un 500 sin envoltorio ni `error_code`. | El creador solo escribe cuatro campos que existen desde SPEC 77 y no hace ninguna E/S externa (ni ficheros, ni correo, ni HTTP), así que su superficie de fallo es la misma que la del `node_save()` que ya se ejecutó. Es exactamente el mismo riesgo que `myapi_claim_transaction_create_initial()` lleva asumido desde SPEC 57 sin un solo incidente. Se acepta a conciencia, y no se envuelve en un `try/catch` que dejaría solicitudes sin transacción en silencio — que es peor que fallar ruidosamente. |
| **Cascada de hooks.** Insertar la transacción vuelve a disparar `hook_node_insert()`, ahora con un nodo `service_transaction`. Hoy no hay rama para ese bundle y no pasa nada; el día que un spec futuro añada una, si esa rama re-guarda la solicitud, la cascada es infinita. | Documentado en el docblock de la rama y en `docs/service-transaction.md`. Nada en este spec re-guarda la solicitud —la dirección es solicitud → transacción y solo esa—, que es precisamente por lo que se descartó la sincronización inversa. |
| **Una solicitud guardada programáticamente sin `field_request_status`.** `node_save()` no pasa por `field_default_form()`, así que el `default_value` del campo nunca se aplica solo y el campo requerido no se valida. La transacción copiaría el vacío. | Es el caso que SPEC 66 ya documentó para reclamos, y por eso `myapi_service_request_build_node()` (SPEC 90) escribe el estado explícitamente. Si aun así llegara vacío, la transacción registra el vacío y el comentario degrada a «Solicitud registrada.» — un rastro honesto de que el dato no estaba, en vez de un estado inventado. |
| **Dos implementaciones casi idénticas** — la de reclamos y esta — que hay que mantener en paralelo: quien arregle un bug en el título de una puede no enterarse de la otra. | Aceptado, y **no** se extrae una abstracción común: las dos difieren en tres puntos que no son cosméticos (de dónde sale la etiqueta del estado, si hay sincronización inversa, y si hay bandera de notificación). Un helper compartido tendría que parametrizar los tres y sería más difícil de leer que las dos copias. La referencia cruzada queda escrita en los docblocks de ambos ficheros. |
| **El título se regenera en cada edición**, así que no es un identificador estable: editar el comentario de una transacción le cambia el título en `/admin/content` y en cualquier enlace guardado. | Es el comportamiento buscado —un título que describe algo que la transacción ya no es sería peor— y el mismo que SPEC 60 dejó en reclamos. El identificador estable es el `nid`, no el título. |
| **Crecimiento de `/admin/content`**: una fila más por cada solicitud, mezclada con todo lo demás y con títulos largos. | Aceptado, mismo criterio que reclamos desde SPEC 57. El día que el volumen moleste, la respuesta es la página de administración propia que este spec deja explícitamente fuera. |
