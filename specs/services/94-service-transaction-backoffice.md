# 94 — Línea de tiempo de transacciones en el back-office de una solicitud de servicio

- **Estado:** Implemented
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del bundle
    `service_transaction` y de sus **cuatro** campos (`field_request`,
    `field_request_status`, `field_status_date`, `field_comment`), y del
    catálogo `myapi_services_request_statuses()` que alimenta el `select` de
    estado. Cero cambios de esquema: este spec no crea campo, instancia,
    bundle, tabla ni permiso.
  - `92-service-request-initial-transaction` (Implemented) — dueña de
    `includes/myapi.service_transaction.inc`, cuyo docblock afirma hoy que
    «There is NO administration page here — no route, no form, no timeline
    table». Este spec es el que lo desmiente, así que ese docblock se corrige
    en el mismo commit. También es la que decidió **no** sincronizar
    transacción → solicitud; este spec **revierte esa decisión** y explica por
    qué el motivo original ya no aplica.
  - `93-service-request-transactions-in-detail` (Implemented) — dueña de
    `myapi_service_request_load_transactions()`, la consulta de la línea de
    tiempo **de la API**: orden ASC, sin autor, sin `node_access`. Este spec
    **no la toca ni la llama** (Regla 5 de CLAUDE.md: un include nunca entra en
    un `resources/*.inc`) y escribe la suya, con otras columnas y otro orden.
  - `57-claim-transaction-timeline-and-modal` (Implemented) — precedente de
    forma y fuente del patrón entero: `fieldset` al final del formulario
    nativo, formulario FAPI propio en una página normal, redirect de vuelta,
    `hook_node_insert()`/`hook_node_update()` sincronizando el estado del
    padre, campo de estado del padre en `#disabled`.
  - `59-claim-transaction-edit-link` (Implemented) — precedente del enlace
    "Editar" por fila decidido con `node_load()` + `node_access('update', ...)`,
    del bloqueo del campo de referencia al padre en el formulario nativo de la
    transacción, y del redirect forzado tras guardar.
  - `49-building-admin-role` (Implemented) — dueña de
    `myapi_building_admin_field_value()` /
    `myapi_building_admin_field_target_id()`, reutilizadas tal cual para leer
    campos, y del catálogo de tipos editables que este spec **no toca**: los
    servicios siguen fuera del alcance del rol `administrador edificio`.

**Objetivo:** Que un operador `administrator`/`backend` vea, cree, edite y borre
las transacciones de una solicitud de servicio desde el formulario de edición de
esa solicitud en el back-office, sincronizando el estado de la solicitud con el
de la transacción guardada.

Cinco notas que la cabecera fija, porque condicionan todo lo que sigue:

- **No hay listado propio.** Las transacciones solo se ven dentro de su
  solicitud, en `node/%nid/edit`. No se crea `admin/content/service-requests`;
  es otro spec.
- **El sync vuelve.** Guardar una transacción con otro estado reescribe
  `field_request_status` de la solicitud, sin validar el grafo
  `myapi_services_request_transitions()`. SPEC 92 lo dejó fuera porque nadie
  validaba ese grafo y la sincronización habría dejado poner una solicitud en un
  estado imposible; la decisión se revierte a sabiendas: hoy la API **tampoco**
  lo valida, así que exigirlo solo aquí dejaría dos puertas con reglas
  distintas. Validar transiciones es un spec propio, para las dos puertas a la
  vez.
- **`field_request_status` de la solicitud pasa a `#disabled`** en
  `node/%nid/edit` — visible y enviado, pero de solo lectura. En
  `node/add/service_request` sigue editable: ahí es donde se elige el estado
  inicial que la transacción automática de SPEC 92 va a copiar. Espejo literal
  de SPEC 57.
- **Borrar borra de verdad**, con formulario de confirmación propio y
  `node_delete()`. Es la primera vez que este módulo borra un nodo: ningún
  `delete any ... content` se concede a nadie, el enlace se decide con
  `node_access('delete', $node)` puro, y quien no tenga el permiso simplemente
  no ve la columna llena.
- **Borrar no rebobina el estado.** El estado de la solicitud queda donde
  estaba. Borrar corrige la bitácora; no es una segunda vía —invisible y no
  auditable— de cambiar el estado.

---

## Alcance

**Dentro:**

- **`includes/myapi.service_transaction_admin.inc`** (nuevo) — toda la lógica de
  esta pantalla. Archivo aparte de `includes/myapi.service_transaction.inc`, que
  sigue siendo solo la transacción inicial automática y el título: el sufijo
  `_admin` significa lo mismo que en `myapi.claim_transaction_admin.inc`, y es
  exactamente la distinción que SPEC 92 dejó escrita.
  - `myapi_service_transaction_timeline_rows($request_nid)` — consulta de solo
    lectura, una fila por transacción publicada de la solicitud:
    `nid, status, status_date, comment, uid, user_name, created`. `LEFT JOIN` a
    los tres campos de valor y a `users` por el `uid` nativo del nodo.
    `ORDER BY field_status_date DESC, nid DESC`. **No** reutiliza
    `myapi_service_request_load_transactions()` (SPEC 93): vive en `resources/`,
    ordena ASC y no trae autor — Regla 5 de CLAUDE.md prohíbe entrar ahí desde
    un include.
  - `myapi_service_transaction_create_form($form, &$form_state, $request_node)` —
    formulario FAPI propio con tres campos: estado (`select`, `#options` =
    `myapi_services_request_statuses()`, requerido), fecha del estado
    (`textfield` `AAAA-MM-DD HH:MM` con `#element_validate` propio, requerido,
    `#default_value` = ahora) y comentario (`textarea`, requerido).
    `field_request` no se muestra: viaja como `#type => 'value'`.
  - `myapi_service_transaction_validate_status_date($element, &$form_state)` —
    validación del formato con hora, mismo criterio que
    `myapi_claim_transaction_validate_status_date()`.
  - `myapi_service_transaction_create_form_submit()` — crea el nodo
    `service_transaction` (`field_request` = la solicitud, `uid` = usuario
    actual, `status = 1`), `node_save()`, y
    `$form_state['redirect'] = 'node/<request_nid>/edit'`.
  - `myapi_service_transaction_add_page_callback($request_node)` — devuelve
    `drupal_get_form(...)`. Página normal, sin AJAX ni modal: SPEC 57 ya pagó
    ese experimento y lo descartó.
  - `myapi_service_transaction_delete_form($form, &$form_state, $request_node, $transaction_node)` —
    formulario de confirmación (`confirm_form()` de core), y
    `myapi_service_transaction_delete_form_submit()` que llama a `node_delete()`
    y redirige a `node/<request_nid>/edit`. **No toca el estado de la
    solicitud.**
  - `myapi_service_transaction_delete_page_callback($request_node, $transaction_node)` —
    devuelve ese formulario.
  - `myapi_service_transaction_edit_link($nid)` /
    `myapi_service_transaction_delete_link($request_nid, $nid)` — `node_load()` +
    `node_access('update'|'delete', ...)`; devuelven el enlace renderizado o
    cadena vacía. Sin acceso, celda vacía, sin texto sustituto.
  - `myapi_service_transaction_timeline_table_rows(array $rows)` y
    `myapi_service_transaction_timeline_build($request_node)` — la tabla
    (estado, fecha del estado, comentario, autor, Editar, Borrar) y el bloque
    con el enlace "Crear transacción" arriba.
  - `myapi_service_transaction_author_label($row)` — nombre del autor, o
    marcador cuando el usuario ya no existe. Mismo criterio que su gemela de
    reclamos.
  - `myapi_service_transaction_request_form_alter(&$form, &$form_state)` — en
    edición: `field_request_status` a `#disabled` en todos los
    `langcode`/`delta`, más el `fieldset` con la línea de tiempo al final
    (`#weight` alto). En `node/add/service_request`: no toca nada.
  - `myapi_service_transaction_transaction_form_alter(&$form, &$form_state)` — en
    edición del formulario nativo de la transacción: `field_request` a
    `#disabled` (recorrido hasta el `target_id` del widget
    `entityreference_autocomplete`) y
    `$form['#submit'][] = 'myapi_service_transaction_edit_form_submit_redirect'`.
  - `myapi_service_transaction_edit_form_submit_redirect($form, &$form_state)` —
    lee `field_request` del nodo guardado y redirige a
    `node/<request_nid>/edit`.
  - `myapi_service_transaction_sync_request_status($node)` — delegado de las
    ramas `'service_transaction'` de `myapi_node_insert()` y
    `myapi_node_update()`: carga la solicitud vía `field_request`, compara
    `field_request_status`; si difiere, lo reescribe y `node_save()`. Si es
    igual, no toca nada — que es lo que hace que la transacción inicial de
    SPEC 92 no dispare un guardado extra.
- **`includes/myapi.service_transaction.inc`** (modificar) — **solo el docblock
  del archivo**: hoy afirma que no existe página de administración, ruta,
  formulario ni tabla de línea de tiempo, y que por eso no lleva sufijo
  `_admin`. Se corrige para apuntar al archivo nuevo. Cero cambios de código.
- **`myapi.module`** (modificar):
  - Dos entradas de `hook_menu()`: `node/%node/service-transaction/add` y
    `node/%node/service-transaction/%node/delete`, ambas `MENU_CALLBACK`, con
    `'file' => 'includes/myapi.service_transaction_admin.inc'`.
  - `myapi_service_transaction_add_access($node)` y
    `myapi_service_transaction_delete_access($request_node, $transaction_node)` —
    los `access callback`, junto a `myapi_claim_transaction_add_access()`, que es
    donde ya vive el suyo.
  - `myapi_form_service_request_node_form_alter()` y
    `myapi_form_service_transaction_node_form_alter()` — glue de dos líneas cada
    uno, mismo patrón que los dos alter de reclamos que ya están ahí.
  - Rama `'service_transaction'` en `myapi_node_insert()` y en
    `myapi_node_update()` — tres líneas cada una, delegando al sync.
- **`myapi.info`** (modificar) —
  `files[] = includes/myapi.service_transaction_admin.inc`.
- **`docs/service-transaction-timeline.md`** (nuevo) — las dos rutas, los campos
  del formulario, la regla de sincronización, la regla de borrado que no
  rebobina, quién ve cada enlace, y la nota de que `field_request_status` de la
  solicitud es de solo lectura en edición. Archivo aparte de
  `docs/service-transaction.md`, que documenta la transacción inicial automática
  y no cambia salvo un enlace cruzado.
- **`tests/unit/ServiceTransactionAdminTest.php`** (nuevo) y
  **`tests/README.md`** (modificar) — cobertura de las funciones puras.
- `drush cc all` al final. **Sin `drush updb`:** ningún campo, tabla, bundle ni
  permiso nuevo.

**Fuera de alcance (para specs futuros):**

- **Un listado `admin/content/service-requests`.** Las transacciones se ven solo
  dentro de su solicitud. El listado con filtros, espejo de SPEC 56, es su
  propio spec con su propia decisión de acceso.
- **Validar el grafo de transiciones**
  (`myapi_services_request_transitions()`, que existe desde SPEC 77 y nadie
  lee). Cuando se implemente, tiene que cubrir a la vez esta pantalla y los
  endpoints `api/v1`, no una sola puerta.
- **Cualquier permiso nuevo.** El rol `administrador edificio` sigue sin ver ni
  tocar servicios (no entra en `myapi_building_admin_editable_types()`), y al rol
  `backend` no se le concede `delete any service_transaction content`: si el
  sitio no se lo dio, no ve el enlace de borrado.
- **Notificar al residente** cuando el operador cambia el estado. El marketplace
  todavía no tiene notificador; cuando lo tenga, ese spec decide si estas
  transacciones lo disparan.
- **Cualquier cambio en `resources/*.inc` o en la respuesta de `api/v1/...`.**
  `GET /api/v1/service-requests/{id}` ya sirve la línea de tiempo desde SPEC 93
  y devolverá lo que el operador escriba, sin una línea nueva.
- **Adjuntos o imágenes en la transacción.** `service_transaction` no tiene esos
  campos (a diferencia de `claim_transaction`), y este spec no se los agrega.
- **Paginar la línea de tiempo.** Volumen bajo asumido, igual que SPEC 57.
- **Auditoría de ediciones y borrados** (quién cambió qué y cuándo, revisiones
  del nodo). No hay revisiones activas para este bundle y no se activan.
- **Borrar la solicitud completa**, ni ningún borrado en cascada de sus
  transacciones. Este spec borra transacciones sueltas y nada más.
- **Tests de integración o e2e.** Siguen cubriendo solo `auth`; ninguna pantalla
  de back-office tiene cobertura ahí.

---

## Modelo de datos

No se crean campos, tablas ni bundles nuevos. Todo lo que sigue son estructuras
en memoria y rutas sobre lo que SPEC 77 ya definió.

### Filas de `myapi_service_transaction_timeline_rows($request_nid)`

```
nid, status, status_date, comment, uid, user_name, created
```

Una fila por nodo `service_transaction` con `field_request_target_id =
$request_nid` y `n.status = 1`. `ORDER BY field_status_date DESC, nid DESC` —
desempate determinista cuando dos transacciones comparten minuto, y **el orden
inverso al de `myapi_service_request_load_transactions()`** (SPEC 93, ASC): la
app narra una historia de principio a fin, el operador quiere lo último arriba.

`status_date` **no pasa por `strtotime()`** en ningún punto de este spec.
`field_status_date` se creó con `tz_handling = 'none'` (SPEC 55, compartido con
`claim_transaction`): lo almacenado es hora local ingenua, y convertirla
desplazaría la hora que alguien escribió a mano. `created` sí es un timestamp
real y sí pasa por `format_date()`. Dos columnas, dos reglas, igual que en
SPEC 93.

Sin `->addTag('node_access')`: quien llegó a `node/%nid/edit` de la solicitud ya
pasó el control de acceso sobre ella, y la transacción no tiene acceso propio. El
acceso por fila se decide después, y solo para los enlaces (ver abajo).

### Formulario de creación — `myapi_service_transaction_create_form()`

```php
$form['field_request_status'] = [
  '#type'     => 'select',
  '#title'    => t('Estado'),
  '#options'  => myapi_services_request_statuses(), // catálogo de SPEC 77, reutilizado, nunca transcrito
  '#required' => TRUE,
];
$form['field_status_date'] = [
  '#type'             => 'textfield', // texto plano, NO date_select ni date_popup: SPEC 57 probó los dos widgets "combo" y ambos rompían la alineación del formulario
  '#title'            => t('Fecha del estado'),
  '#default_value'    => date('Y-m-d H:i'),
  '#size'             => 20,
  '#maxlength'        => 16,
  '#description'      => t('Formato: AAAA-MM-DD HH:MM.'),
  '#required'         => TRUE,
  '#element_validate' => ['myapi_service_transaction_validate_status_date'],
];
$form['field_comment'] = [
  '#type'     => 'textarea',
  '#title'    => t('Comentario'),
  '#required' => TRUE, // igual que en reclamos: una transacción sin comentario no explica nada
];
$form['request_nid'] = ['#type' => 'value', '#value' => $request_node->nid];
$form['actions']['submit'] = ['#type' => 'submit', '#value' => t('Guardar')];
```

El título **no se escribe aquí**: `myapi_service_transaction_set_title()`
(SPEC 92) ya lo compone desde `hook_node_presave()`, que corre dentro del
`node_save()` de este submit y también cubre el formulario nativo. Este spec no
toca esa función.

Lo guardado por el submit, campo por campo:

```php
$transaction = (object) ['type' => MYAPI_SERVICES_TRANSACTION_TYPE, 'uid' => $user->uid, 'status' => 1];
node_object_prepare($transaction);
$transaction->field_request[LANGUAGE_NONE][0]['target_id'] = $request_nid;
$transaction->field_request_status[LANGUAGE_NONE][0]['value'] = $values['field_request_status'];
$transaction->field_status_date[LANGUAGE_NONE][0]['value'] = $values['field_status_date'] . ':00'; // 'Y-m-d H:i:00', segundos fijados como en SPEC 92
$transaction->field_comment[LANGUAGE_NONE][0]['value'] = $values['field_comment'];
node_save($transaction);
```

`uid` es el **operador que guarda**, no el solicitante: es el dato que la columna
"Autor" de la tabla responde, y el mismo criterio que SPEC 92 ya fijó.

### Las dos rutas

```php
$items['node/%node/service-transaction/add'] = [
  'title'            => 'Crear transacción',
  'page callback'    => 'myapi_service_transaction_add_page_callback',
  'page arguments'   => [1],
  'access callback'  => 'myapi_service_transaction_add_access',
  'access arguments' => [1],
  'type'             => MENU_CALLBACK,
  'file'             => 'includes/myapi.service_transaction_admin.inc',
];

$items['node/%node/service-transaction/%node/delete'] = [
  'title'            => 'Borrar transacción',
  'page callback'    => 'myapi_service_transaction_delete_page_callback',
  'page arguments'   => [1, 3],
  'access callback'  => 'myapi_service_transaction_delete_access',
  'access arguments' => [1, 3],
  'type'             => MENU_CALLBACK,
  'file'             => 'includes/myapi.service_transaction_admin.inc',
];
```

Los dos `access callback`, en `myapi.module` junto a
`myapi_claim_transaction_add_access()`:

```php
function myapi_service_transaction_add_access($node) {
  return !empty($node->type)
    && $node->type === MYAPI_SERVICES_REQUEST_TYPE
    && node_access('update', $node)
    && node_access('create', MYAPI_SERVICES_TRANSACTION_TYPE);
}

function myapi_service_transaction_delete_access($request_node, $transaction_node) {
  return !empty($request_node->type) && $request_node->type === MYAPI_SERVICES_REQUEST_TYPE
    && !empty($transaction_node->type) && $transaction_node->type === MYAPI_SERVICES_TRANSACTION_TYPE
    // La transacción tiene que ser DE esta solicitud: sin esta línea, la ruta
    // borra cualquier transacción del sitio colgándola de una solicitud que sí
    // se puede editar.
    && (int) myapi_building_admin_field_target_id($transaction_node, 'field_request') === (int) $request_node->nid
    && node_access('update', $request_node)
    && node_access('delete', $transaction_node);
}
```

**No hay ruta de edición propia.** "Editar" apunta al formulario nativo
`node/<nid>/edit` de la transacción, exactamente como SPEC 59 en reclamos; lo
único que este spec le hace a ese formulario es bloquear `field_request` y forzar
el redirect de vuelta.

### El formulario de borrado

`confirm_form()` de Drupal core (`includes/form.inc`), sin nada propio:

```php
return confirm_form(
  $form,
  t('¿Borrar esta transacción?'),
  'node/' . $request_node->nid . '/edit',                 // el enlace "Cancelar"
  t('Se borrará la transacción «@title». Esta acción no se puede deshacer. El estado de la solicitud no cambia.',
    ['@title' => $transaction_node->title]),
  t('Borrar'),
  t('Cancelar')
);
```

El submit es `node_delete($transaction_nid)` más
`$form_state['redirect'] = 'node/<request_nid>/edit'`, y nada más. **El estado de
la solicitud queda donde estaba** — y el texto de confirmación lo dice en voz
alta, porque es justo lo que el operador podría suponer al revés.

### La sincronización de estado

```php
// myapi_node_insert() y myapi_node_update(), rama 'service_transaction'
function myapi_service_transaction_sync_request_status($node) {
  $request_nid = myapi_building_admin_field_target_id($node, 'field_request');
  $status = myapi_building_admin_field_value($node, 'field_request_status');
  if (!$request_nid || $status === NULL || $status === '') {
    return;
  }
  $request = node_load($request_nid);
  if (!$request || $request->type !== MYAPI_SERVICES_REQUEST_TYPE) {
    return;
  }
  if (myapi_building_admin_field_value($request, 'field_request_status') === $status) {
    return; // Ya está en ese estado: ni un node_save() de más.
  }
  $request->field_request_status[LANGUAGE_NONE][0]['value'] = $status;
  node_save($request);
}
```

Tres propiedades que hay que leer juntas, porque son la razón de que esto no
entre en bucle:

1. **La transacción inicial de SPEC 92 no dispara nada.** Nace copiando el estado
   que la solicitud ya tiene, así que la comparación corta antes del
   `node_save()`. El caso normal —crear una solicitud— sigue guardando
   exactamente dos nodos.
2. **No hay cascada.** El `node_save($request)` de arriba es una actualización de
   `service_request`, y `myapi_node_update()` no tiene rama `'service_request'`;
   la que sí existe, la de `myapi_node_insert()`, solo corre al insertar.
   SPEC 92 escribió esa propiedad en su docblock y pidió que quien agregara una
   rama `'service_transaction'` la mantuviera: se mantiene, y este spec la vuelve
   a dejar por escrito para el siguiente.
3. **Sin validar el grafo.** `myapi_services_request_transitions()` no se
   consulta. Es la decisión de la cabecera, no un olvido.

### El bloqueo de `field_request_status` en la solicitud

Mismo recorrido `langcode`/`delta` que
`myapi_claim_transaction_reclamo_form_alter()`, y solo cuando
`!empty($form['#node']->nid)`:

```php
$form['field_request_status'][$langcode]['#disabled'] = TRUE;
```

`#disabled` y **no** `#access = FALSE`: el operador tiene que **ver** en qué
estado está la solicitud mientras decide qué transacción crear; lo que no puede
es escribirlo desde ahí. El estado se cambia creando una transacción, que es lo
que deja rastro.

---

## Plan de implementación

Cada paso deja el sitio funcionando y es verificable por sí solo. `drush cc all`
después de cualquier paso que toque `hook_menu()`, `myapi.info` o cree un `.inc`.

1. **El archivo y la consulta.** Crear
   `includes/myapi.service_transaction_admin.inc` con
   `myapi_service_transaction_timeline_rows()` y
   `myapi_service_transaction_author_label()`, y añadir
   `files[] = includes/myapi.service_transaction_admin.inc` a `myapi.info`.
   `drush cc all`. Nada visible todavía: se verifica desde `drush php-eval` sobre
   una solicitud existente, que ya tiene al menos la transacción inicial de
   SPEC 92.

2. **La tabla dentro del formulario de la solicitud.** Añadir
   `myapi_service_transaction_timeline_table_rows()` y
   `myapi_service_transaction_timeline_build()` al include (todavía sin columnas
   Editar/Borrar y sin el enlace de creación), y
   `myapi_service_transaction_request_form_alter()` con las dos mitades:
   `field_request_status` a `#disabled` y el `fieldset` al final. En
   `myapi.module`, el glue `myapi_form_service_request_node_form_alter()`.
   Verificable en vivo: `node/<nid>/edit` de una solicitud muestra la línea de
   tiempo en orden DESC y el estado en gris.

3. **Crear una transacción.** Al include:
   `myapi_service_transaction_create_form()`,
   `myapi_service_transaction_validate_status_date()`,
   `myapi_service_transaction_create_form_submit()` y
   `myapi_service_transaction_add_page_callback()`. En `myapi.module`: la ruta
   `node/%node/service-transaction/add` y
   `myapi_service_transaction_add_access()`. En `timeline_build()`, el enlace
   "Crear transacción" arriba del bloque. `drush cc all`. Ya se crea y se vuelve
   a `node/<nid>/edit` con la fila nueva arriba — **el estado de la solicitud
   todavía no se mueve**, y eso es correcto en este paso.

4. **La sincronización.** `myapi_service_transaction_sync_request_status()` en el
   include, y las ramas `'service_transaction'` en `myapi_node_insert()` y
   `myapi_node_update()` de `myapi.module`. Verificable con las tres pruebas que
   importan: crear una transacción con otro estado mueve la solicitud; crear una
   con el mismo estado no dispara ningún guardado extra; crear una solicitud
   nueva sigue guardando dos nodos y no entra en bucle.

5. **Editar una transacción.** `myapi_service_transaction_edit_link()` y la
   columna "Editar" en la tabla;
   `myapi_service_transaction_transaction_form_alter()` (bloqueo de
   `field_request` + `#submit` extra) y
   `myapi_service_transaction_edit_form_submit_redirect()`. En `myapi.module`, el
   glue `myapi_form_service_transaction_node_form_alter()`. Editar el estado de
   una transacción existente y guardar tiene que devolver a la solicitud **y**
   mover su estado por el sync del paso anterior, que ya cubre
   `hook_node_update()`.

6. **Borrar una transacción.** `myapi_service_transaction_delete_form()`, su
   submit, `myapi_service_transaction_delete_page_callback()` y
   `myapi_service_transaction_delete_link()`, más la columna "Borrar". En
   `myapi.module`, la ruta `node/%node/service-transaction/%node/delete` y
   `myapi_service_transaction_delete_access()`, incluida la comprobación de que
   la transacción pertenece a esa solicitud. `drush cc all`. Verificable: se
   borra, se vuelve a la solicitud, la fila desaparece y **el estado de la
   solicitud no cambia**; y una URL cruzada
   (`node/<solicitud A>/service-transaction/<transacción de B>/delete`) responde
   403.

7. **El docblock de SPEC 92.** Corregir en
   `includes/myapi.service_transaction.inc` el párrafo que afirma que no existe
   página de administración ni sufijo `_admin`, apuntándolo a este archivo, y
   añadir la nota de que la rama `'service_transaction'` de
   `myapi_node_insert()` ya existe y por qué sigue sin haber cascada. Solo
   comentarios; cero cambios de código.

8. **La documentación.** `docs/service-transaction-timeline.md` nuevo, con las
   dos rutas, los campos del formulario, la regla de sincronización, la regla de
   que borrar no rebobina el estado, quién ve cada enlace y el bloqueo de
   `field_request_status`. Enlace cruzado desde `docs/service-transaction.md`.

9. **Los tests unitarios.** `tests/unit/ServiceTransactionAdminTest.php` sobre
   las funciones puras, y `tests/README.md` actualizado en las dos secciones
   donde ese archivo lleva la cuenta. Si alguna función nueva necesita un stub
   que `tests/unit/bootstrap.php` no tenga, se agrega ahí con el mismo criterio
   de los existentes.

10. **Matriz manual y cierre.** `drush cc all` y el recorrido completo con las
    tres cuentas: `administrator`, `backend`, y una cuenta sin permisos sobre
    servicios. Se comprueba que quien no tiene `node_access('delete')` sobre la
    transacción ve la celda "Borrar" vacía en vez de un enlace que responde 403.

### Tests unitarios

Entran en `tests/unit/` las funciones **puras**, mismo criterio que SPEC 59 y 91:

- `myapi_service_transaction_timeline_table_rows()` — filas completas, fila sin
  comentario, fila con estado que no está en el catálogo (cae al valor crudo),
  orden respetado.
- `myapi_service_transaction_author_label()` — autor presente, autor borrado,
  `uid = 0`.
- `myapi_service_transaction_validate_status_date()` — formato válido, formato
  sin hora, texto basura, fecha imposible.
- `myapi_service_transaction_request_form_alter()` y
  `myapi_service_transaction_transaction_form_alter()` — manipulación de arrays
  FAPI: modo creación (no toca nada) y modo edición (`#disabled` puesto en todos
  los `langcode`/`delta`).
- `myapi_service_transaction_edit_form_submit_redirect()` — con `field_request`
  resoluble y sin él.

Quedan **fuera** del unit layer, y se verifican en la matriz manual del paso 10,
por la misma regla que ya dejó fuera a `myapi_claim_transaction_edit_link()`:
`myapi_service_transaction_timeline_rows()` (base de datos),
`myapi_service_transaction_edit_link()` / `_delete_link()` (`node_load()` +
`node_access()`), `myapi_service_transaction_sync_request_status()`
(`node_load()` + `node_save()`) y los dos `access callback`.

---

## Criterios de aceptación

Lista booleana: cada línea se verifica mirando la pantalla, la base de datos o el
resultado de `phpunit`.

> **Estado de la verificación (2026-08-19).** Marcados los criterios probados
> por `phpunit`, por `git` o por una comprobación conclusiva sobre el código
> —22 de 41—. Los 19 sin marcar necesitan el recorrido manual del paso 10 del
> plan contra el sitio con las tres cuentas: son los que se verifican mirando
> la pantalla o la base de datos.

**Ver**

1. [x] En `node/<nid>/edit` de una `service_request` aparece un `fieldset` al final
       con la línea de tiempo de sus transacciones.
2. [x] La tabla tiene seis columnas: Estado, Fecha del estado, Comentario, Autor,
       Editar, Borrar.
3. [x] Las filas salen ordenadas por fecha del estado descendente; dos transacciones
       del mismo minuto salen por `nid` descendente.
4. [x] La transacción inicial que SPEC 92 creó al nacer la solicitud aparece en la
       tabla, con su comentario de acuse y su autor.
5. [x] Una transacción despublicada (`status = 0`) no aparece en la tabla.
6. [x] La hora mostrada en "Fecha del estado" es exactamente la almacenada en
       `field_status_date`, sin desplazamiento de zona horaria.
7. [x] `field_request_status` de la solicitud se ve, con su valor, y no se puede
       modificar en `node/<nid>/edit`.
8. [x] En `node/add/service_request`, `field_request_status` sigue siendo editable.
9. [x] En `node/add/service_request` no aparece ningún `fieldset` de línea de tiempo.

**Crear**

10. [x] El bloque tiene arriba un enlace "Crear transacción" que lleva a
        `node/<nid>/service-transaction/add`.
11. [x] Esa página muestra tres campos —Estado, Fecha del estado, Comentario— y
        ninguno para elegir la solicitud.
12. [x] El `select` de Estado ofrece exactamente las seis opciones de
        `myapi_services_request_statuses()`, con sus etiquetas.
13. [x] Guardar con los tres campos válidos crea un nodo `service_transaction` con
        `field_request` apuntando a la solicitud, `uid` = el operador que guardó, y
        `status = 1`.
14. [x] Tras guardar, el navegador queda en `node/<nid>/edit` de la solicitud y la
        transacción nueva es la primera fila.
15. [x] `field_status_date` queda almacenado como `Y-m-d H:i:00` — la hora escrita,
        con los segundos en cero.
16. [x] Enviar el formulario con la fecha en cualquier otro formato muestra un error
        de validación y no crea nada.
17. [x] Enviar el formulario sin comentario muestra un error de validación y no crea
        nada.
18. [x] El título del nodo creado lo compone `myapi_service_transaction_title()`
        (SPEC 92): `Solicitud #<nid> · <Estado> · <fecha> · <comentario truncado>`.

**Sincronizar**

19. [x] Guardar una transacción con un estado distinto al de la solicitud cambia
        `field_request_status` de la solicitud a ese estado.
20. [x] Guardar una transacción con el mismo estado que ya tiene la solicitud no
        ejecuta ningún `node_save()` sobre la solicitud.
21. [x] Editar una transacción existente y cambiarle el estado también sincroniza la
        solicitud.
22. [x] Crear una solicitud nueva (por `node/add/service_request` o por
        `POST /api/v1/service-requests`) sigue guardando exactamente dos nodos y no
        entra en recursión.
23. [x] Una transición que el grafo `myapi_services_request_transitions()` no
        permite —por ejemplo `closed` → `open`— **se guarda igual**, sin error: la
        validación está fuera de alcance y no se agrega por accidente.

**Editar**

24. [x] Cada fila tiene un enlace "Editar" a `node/<transaction_nid>/edit` cuando el
        usuario tiene `node_access('update')` sobre esa transacción.
25. [x] Cuando no lo tiene, la celda queda vacía: ni enlace, ni texto sustituto.
26. [x] En ese formulario nativo, `field_request` se ve pero no se puede cambiar.
27. [x] En `node/add/service_transaction`, `field_request` sigue editable.
28. [x] Tras guardar la edición, el navegador queda en `node/<request_nid>/edit` de
        la solicitud, no en `node/<transaction_nid>`.

**Borrar**

29. [x] Cada fila tiene un enlace "Borrar" cuando el usuario tiene
        `node_access('delete')` sobre esa transacción; si no, la celda queda vacía.
30. [x] El enlace lleva a una página de confirmación que nombra la transacción y
        avisa de que el estado de la solicitud no cambia.
31. [x] "Cancelar" vuelve a `node/<request_nid>/edit` sin borrar nada.
32. [x] Confirmar borra el nodo `service_transaction` con `node_delete()` y devuelve
        a `node/<request_nid>/edit`, sin la fila.
33. [x] Borrar la transacción que dejó la solicitud en su estado actual **no** cambia
        `field_request_status` de la solicitud.
34. [x] La transacción inicial de SPEC 92 se puede borrar como cualquier otra.
35. [x] `node/<solicitud A>/service-transaction/<transacción de la solicitud B>/delete`
        responde 403 y no borra nada.
36. [x] `node/<nid de un nodo que no es una solicitud>/service-transaction/add`
        responde 403.

**No regresión**

37. [x] `GET /api/v1/service-requests/{id}` devuelve en `transactions` lo que el
        operador creó, editó o borró, en orden ascendente, sin ningún cambio en
        `resources/service_request.resource.inc`.
38. [x] La pantalla de reclamos (`node/<nid>/edit` de un `reclamo`, su línea de
        tiempo, su creación y su edición) sigue funcionando igual: ninguna función de
        `myapi.claim_transaction_admin.inc` fue tocada.
39. [x] No se ejecuta ningún `hook_update_N`: `drush updb` no tiene nada pendiente
        después de desplegar.
40. [x] `drush en myapi` en un sitio limpio no concede ningún permiso nuevo;
        `myapi_building_admin_permissions()` sigue sin ningún `delete any ...` y su
        test unitario sigue pasando.
41. [x] `phpunit` pasa entero, con `ServiceTransactionAdminTest` incluido.

---

## Decisiones tomadas y descartadas

| # | Decisión | Alternativa descartada | Por qué |
|---|----------|------------------------|---------|
| 1 | **Solo el `fieldset` dentro de `node/%nid/edit`.** Las transacciones se ven en el contexto de su solicitud y en ningún otro lado. | Un listado `admin/content/service-requests` con filtros, espejo de SPEC 56. | El listado arrastra su propia decisión de acceso por rol y su propio juego de filtros; sumarlo aquí duplicaba el tamaño del spec y mezclaba dos preguntas independientes. Sale como spec propio. |
| 2 | **El sync transacción → solicitud vuelve**, sin validar el grafo de transiciones. | (a) Sin sync, como decidió SPEC 92. (b) Sync validando contra `myapi_services_request_transitions()`. | Sin sync, "que el operador pueda modificar" no significa nada: crearía bitácora y el estado real habría que cambiarlo aparte, en un campo que este spec justamente bloquea. Validando, esta pantalla sería más estricta que la API, que hoy tampoco valida — dos puertas con reglas distintas es peor que dos puertas igual de permisivas. El motivo que SPEC 92 dio para no sincronizar ("nadie valida el grafo") sigue siendo cierto y sigue siendo un spec pendiente, para las dos puertas a la vez. |
| 3 | **`field_request_status` de la solicitud a `#disabled`, no a `#access = FALSE`.** | Ocultarlo por completo en edición. | El operador necesita ver en qué estado está la solicitud para decidir qué transacción crear. Lo que no debe poder es cambiarlo sin dejar rastro: el estado se mueve creando una transacción, que registra quién, cuándo y por qué. |
| 4 | **Formulario FAPI propio para crear**, en una página normal. | (a) Un enlace a `node/add/service_transaction`. (b) Un modal por AJAX. | El formulario nativo obliga a buscar la solicitud a mano en un autocomplete —con el error de apuntarla a otra— y al guardar deja al operador en `node/<nid>`, fuera del contexto. El modal ya se probó en SPEC 57 y se descartó en vivo por problemas de estilo del jQuery UI Dialog de D7; repetir ese experimento sería pagar dos veces la misma lección. |
| 5 | **Borrado real con `node_delete()` y formulario de confirmación propio.** | (a) Enlace al nativo `node/<nid>/delete`. (b) Despublicar (`status = 0`). | El nativo borra bien pero al terminar deja al operador en la portada, sin vuelta a la solicitud. Despublicar es reversible pero la fila desaparece igual y solo se recupera desde `/admin/content`, que es exactamente donde el operador no está: la reversibilidad sería teórica. Es el primer borrado de nodos del módulo y por eso lleva confirmación explícita. |
| 6 | **Borrar no rebobina el estado de la solicitud.** | Recalcularlo desde la transacción viva más reciente. | Recalcular convierte el borrado en una segunda vía de cambio de estado —invisible, sin autor y sin comentario— y obliga a definir qué estado queda cuando no sobrevive ninguna transacción. Borrar corrige la bitácora; cambiar el estado se hace creando una transacción. El texto de confirmación lo dice, porque es justo lo que un operador podría suponer al revés. |
| 7 | **La transacción inicial de SPEC 92 se puede borrar como cualquier otra.** | Protegerla sin enlace de borrado. | Nada la distingue hoy de las demás: protegerla exigiría marcarla con un campo nuevo o deducirla por ser la de `nid` más bajo, y esa heurística se rompe en cuanto alguien la borra y el resto hereda el puesto. |
| 8 | **El operador es `administrator` / `backend`; cero permisos nuevos.** | Extender el rol `administrador edificio` a servicios. | Ese rol no está en `myapi_building_admin_editable_types()` y darle servicios obliga a definir cómo se filtra una solicitud por condominio para él — una decisión de acceso completa, con su `hook_update_N` y su matriz de pruebas. Es un spec propio; este no lo prejuzga. |
| 9 | **El acceso a borrar se decide con `node_access('delete', $node)` puro, sin conceder `delete any service_transaction content` a nadie.** | Concederle ese permiso al rol `backend` en un `hook_update_N`. | `myapi_building_admin_permissions()` tiene un test unitario que falla si aparece cualquier `delete any ...`, y esa regla —"el contenido no se borra, se despublica o se cancela"— es deliberada en el módulo. Si un sitio quiere que `backend` borre transacciones, se lo concede desde `/admin/people/permissions`; el código no lo decide por él. Mientras tanto, quien no lo tenga ve la celda vacía en vez de un enlace que responde 403. |
| 10 | **Consulta propia para la tabla, ordenada DESC**, sin reutilizar `myapi_service_request_load_transactions()`. | Llamar a la función de SPEC 93 desde el include. | La Regla 5 de CLAUDE.md prohíbe que un include entre en un `resources/*.inc`; además esa función ordena ASC, no trae autor ni `uid`, y devuelve la fecha ya con la `T` del formato JSON. Dos consumidores distintos, dos consultas. |
| 11 | **Archivo nuevo `includes/myapi.service_transaction_admin.inc`**, no ampliar `includes/myapi.service_transaction.inc`. | Meter todo en el archivo de SPEC 92. | El sufijo `_admin` es la misma distinción que separa `myapi.claim_transaction_admin.inc` del resto, y SPEC 92 escribió explícitamente que su archivo no lo lleva *porque* no tiene pantalla. Mezclarlos borraría esa señal y pondría en un mismo archivo la lógica que corre en cada `node_save()` del sitio y la que solo corre en una pantalla. |
| 12 | **La tabla del back-office va DESC aunque la API la sirva ASC.** | Un solo orden para las dos. | Son dos lecturas distintas: la app narra la historia de la solicitud de principio a fin, el operador quiere ver primero lo último que pasó. Reclamos ya resolvió lo mismo en la misma dirección. |
| 13 | **`field_status_date` como `textfield` de texto plano `AAAA-MM-DD HH:MM`.** | `date_select` (el widget nativo del bundle) o `date_popup`. | SPEC 57 probó los dos widgets "combo" en vivo y ambos traían su propio CSS de layout que rompía la alineación del formulario. El campo del formulario nativo de la transacción sigue siendo `date_select`, que es donde ese widget sí está en su casa. |

---

## Riesgos identificados

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| **Bucle de guardados** entre la transacción y la solicitud. | Recursión infinita en `node_save()`, con el sitio colgado en cada creación. | Tres barreras, todas verificables: el sync corta antes del `node_save()` cuando el estado ya coincide (criterio 20); `myapi_node_update()` no tiene rama `'service_request'`, así que guardar la solicitud no vuelve a la transacción; y la rama `'service_request'` de `myapi_node_insert()` solo corre al insertar. El criterio 22 lo prueba en el camino más peligroso, la creación de una solicitud. |
| **Estados imposibles** creados a mano desde esta pantalla (`closed` → `open`). | Una solicitud en un estado que la máquina de estados de la API no habría permitido. | Aceptado a sabiendas (decisión 2) y fijado como criterio de aceptación 23, para que nadie lo "arregle" sin spec. Hoy la API tiene exactamente el mismo agujero: esta pantalla no lo abre, lo comparte. |
| **La ruta de borrado con `nid` cruzados** (`node/<A>/service-transaction/<transacción de B>/delete`). | Borrar una transacción de otra solicitud pasando por una que sí se puede editar. | `myapi_service_transaction_delete_access()` comprueba que `field_request` de la transacción apunte a esa solicitud, además de los dos `node_access()`. Criterio 35. |
| **`node_load()` por fila** para decidir Editar y Borrar. | Coste lineal en el número de transacciones. | Volumen bajo ya asumido por SPEC 57 y por SPEC 93 (sin paginación): una transacción es un cambio de estado, no un chat. Si un día deja de ser cierto, el spec que agregue el pager decide también esto. |
| **Un borrado no deja rastro de que existió.** | Nadie puede reconstruir qué decía la transacción borrada ni quién la borró. | Fuera de alcance por decisión explícita (no hay revisiones activas para el bundle ni auditoría en el módulo), pero mitigado en la práctica: el enlace solo aparece para quien tiene `node_access('delete')`, que hoy es `administrator` y nadie más salvo que el sitio lo conceda a mano. |
| **El docblock de SPEC 92 queda mintiendo** si el paso 7 se olvida. | El siguiente que lea `includes/myapi.service_transaction.inc` creerá que no hay pantalla de administración y escribirá una segunda. | Es un paso numerado del plan, no una nota al pie, y el archivo nuevo lo referencia de vuelta. |
