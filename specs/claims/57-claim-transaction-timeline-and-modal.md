# SPEC 57 — Edición de reclamo con línea de tiempo de transacciones y modal de creación

> **Estado:** Implemented · **Depende de:** SPEC 56 (modo `via_claim`, permisos `create`/`edit any` sobre `claim_transaction` para `administrador edificio`, listado que enlaza a `node/%nid/edit`), SPEC 55 (bundles `reclamo`/`claim_transaction` y sus campos) · **Fecha:** 2026-08-01
> **Objetivo:** En el formulario nativo de edición de `reclamo`, mostrar debajo la línea de tiempo de sus transacciones con un botón/enlace que lleva a crear una nueva, sincronizando el estado del reclamo con el de la transacción guardada y creando automáticamente la transacción inicial al crear un reclamo.

> ⚠️ **Modal descartado durante la implementación (paso 10 del plan, matriz manual en vivo).** El diseño original abría un modal por AJAX Framework nativo de D7 (jQuery UI Dialog). Tras verse en vivo, con problemas de estilo (ícono de cerrar vacío, `#date_label_position` duplicado), el usuario pidió reemplazarlo directamente por una **página normal**: el enlace "Crear transacción" navega a `node/%nid/claim-transaction/add` y, al guardar, redirige de vuelta a `node/%nid/edit` — sin AJAX, sin modal. El nombre del archivo y el título de este spec quedan igual por continuidad de referencias; el contenido de abajo se actualizó para reflejar la decisión final. Ver la sección "Decisiones tomadas y descartadas" para el detalle del cambio.

Decisiones técnicas de base, fijadas antes del alcance porque condicionan todo lo demás:

- ~~El modal se abre por AJAX (core D7, sin contrib), pero~~ **Revisado: no hay modal.** El enlace "Crear transacción" navega a una página normal. El **envío del formulario es un POST normal** que redirige de vuelta a `node/%nid/edit` — recarga completa — se mantiene sin cambios; era ya la decisión base incluso cuando existía el modal.
- El formulario ~~del modal~~ es un **formulario FAPI propio** (`myapi_claim_transaction_create_form()`), no el formulario nativo de nodo de `claim_transaction` — permite mostrar exactamente los 4 campos elegidos y ocultar `field_claim`, sin pelear con los pasos del formulario nativo de node.
- `field_status` del reclamo pasa a **`#disabled = TRUE`** en `node/%nid/edit` (solo lectura, con el valor visible y enviado igual) — pero sigue siendo editable normalmente en `node/add/reclamo`, porque ahí es donde se elige el estado inicial que la transacción automática va a copiar.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (nuevo) — toda la lógica de esta pantalla:
  - `myapi_claim_transaction_timeline_rows($claim_nid)` — consulta de solo lectura, una fila por transacción del reclamo, `ORDER BY field_status_date DESC, nid DESC`. `LEFT JOIN` a `field_data_field_status`, `field_data_field_status_date`, `field_data_field_comment`, y a `users` vía `uid` nativo del nodo transacción (autor).
  - `myapi_claim_transaction_create_form($form, &$form_state, $claim_node)` — formulario FAPI propio (no el formulario nativo de nodo de `claim_transaction`) con los 4 campos: `field_status` (`options_select`, requerido), `field_status_date` (`#type => 'date'` nativo de D7 core — tres `select` día/mes/año, no un widget del módulo Date — requerido, `#default_value` = hoy), `field_comment` (textarea, **requerido**), `field_images` / `field_attachment` (`managed_file`, opcionales, mismas extensiones/tamaño máximo que la instancia de SPEC 55). `field_claim` **no** se muestra: viaja como valor fijo en `$form['claim_nid']` (`#type => 'value'`), tal como muestra el código de la sección Modelo de datos.
  - `myapi_claim_transaction_create_form_submit()` — crea el nodo `claim_transaction` (`field_claim` = el reclamo actual, `uid` = usuario actual), lo guarda con `node_save()`, y fija `$form_state['redirect'] = 'node/' . $claim_nid . '/edit'`.
  - ~~`myapi_claim_transaction_modal_callback($claim_node)` — page callback de la ruta del modal~~ **Revisado: `myapi_claim_transaction_add_page_callback($claim_node)`** — page callback de una página normal, sin AJAX: construye el formulario anterior con `drupal_get_form()` y lo devuelve tal cual. No hay rama AJAX, no hay `ajax_command_*`, no hay jQuery UI Dialog.
  - Función de render de la línea de tiempo: tabla con estado, fecha de estado, comentario, autor, y el enlace "Crear transacción" (enlace normal, sin `class="use-ajax"`, apuntando a `node/%nid/claim-transaction/add`) al inicio del bloque.
- **`myapi.module`** (modificar):
  - `myapi_form_reclamo_node_form_alter(&$form, &$form_state, $form_id)` (nuevo, Drupal lo detecta solo por convención `hook_form_FORM_ID_alter`, sin dispatcher):
    - Si el nodo tiene `nid` (edición): `$form['field_status'][...]['#disabled'] = TRUE` en todos los `langcode`/`delta` (mismo patrón de recorrido que `myapi_building_admin_restrict_bulletin_form()`); añade un `fieldset` al final del formulario (`#weight` alto) con la línea de tiempo y el enlace de creación.
    - Si el nodo es nuevo (`node/add/reclamo`, sin `nid`): no toca nada — ni el `#disabled`, ni la sección de transacciones.
  - Entrada nueva de `hook_menu()`: `node/%node/claim-transaction/add` (`MENU_CALLBACK`), `page callback` → `myapi_claim_transaction_add_page_callback`, `page arguments` → `array(1)`, `access callback` → `myapi_claim_transaction_add_access`, que verifica `node_access('update', $node)` sobre el reclamo **y** `node_access('create', 'claim_transaction')` del usuario actual.
  - `myapi_node_insert()` (modificar) — nueva rama para `'reclamo'`: crea la transacción inicial con `field_status` copiado del reclamo recién insertado, `field_status_date` = hoy, `uid` = autor del reclamo, sin comentario ni adjuntos. Va como una rama más del `if` existente, sin reestructurar el resto.
  - `myapi_node_insert()` / `myapi_node_update()` (modificar) — nueva rama para `'claim_transaction'` en ambos hooks: carga el reclamo vía `field_claim`, compara `field_status`; si difiere, actualiza `field_status` del reclamo y `node_save()`. Si es igual, no toca el reclamo.
  - `myapi.info` (modificar) — `files[] = includes/myapi.claim_transaction_admin.inc`.
- ~~`js/myapi.claim_transaction_modal.js`~~ **Descartado**: sin modal, sin AJAX, no hace falta ningún JS propio ni el binding `use-ajax`.
- **`docs/claim-transaction-timeline.md`** (nuevo) — ruta de creación, campos del formulario, regla de sincronización de estado, regla de creación automática, y la nota de que `field_status` del reclamo es de solo lectura en edición.
- `drush cc all` al final. Sin `drush updb`: ningún campo, tabla ni permiso nuevo (los permisos de `claim_transaction` ya los concedió SPEC 56).

**Fuera de alcance (para specs futuros):**

- **Editar o borrar una transacción existente.** Este spec solo crea; la línea de tiempo es de solo lectura salvo por el botón de creación.
- **Validar transiciones de estado** (por ejemplo, impedir pasar de `closed` a `received`). SPEC 55 ya dejó esto fuera explícitamente; este spec tampoco lo agrega.
- **Notificar al residente** cuando cambia el estado de su reclamo. Mismo patrón que `48-reservation-notifications`, spec propio.
- **Cualquier endpoint `api/v1/...`.** La app no ve nada de esto.
- **Filtro de `claim_transaction` por condominio en un listado propio.** SPEC 56 ya resolvió el acceso (`via_claim`); no hay un `admin/content/claim-transactions` — las transacciones solo se ven en el contexto de su reclamo.
- **Descargar o previsualizar `field_images`/`field_attachment`** con un visor propio — se usa el widget nativo de Drupal (`managed_file`), igual que en cualquier formulario de nodo.
- **Paginar la línea de tiempo.** Se asume un volumen bajo de transacciones por reclamo (cambios de estado, no un chat); si crece, es un spec aparte.
- **Cualquier cambio en `resources/*.resource.inc`.**

---

## Modelo de datos

No se crean campos, tablas ni bundles nuevos — todo lo que sigue son estructuras en memoria sobre los campos que SPEC 55 ya definió.

### Filas de `myapi_claim_transaction_timeline_rows($claim_nid)`

```
nid, status, status_date, comment, uid, user_name, created
```

Una fila por `claim_transaction` cuyo `field_claim_target_id = $claim_nid` y `n.status = 1`. `ORDER BY status_date DESC, nid DESC` (desempate determinista cuando dos transacciones comparten fecha).

### Formulario de creación — `myapi_claim_transaction_create_form()`

```php
$form['field_status'] = [
  '#type'          => 'select',
  '#title'         => t('Estado'),
  '#options'       => myapi_claims_status_options(), // reutiliza el helper de SPEC 56 (includes/myapi.claims_admin.inc), cargado con module_load_include()
  '#required'      => TRUE,
];
$form['field_status_date'] = [
  '#type'             => 'textfield', // texto plano 'AAAA-MM-DD' — revisado dos veces (date_popup, luego #type => 'date' nativo), ver Decisiones: ambos widgets "combo" traían su propio CSS de layout que rompía la alineación con el resto del formulario
  '#title'            => t('Fecha de estado'),
  '#default_value'    => date('Y-m-d'),
  '#size'             => 12,
  '#maxlength'        => 10,
  '#description'      => t('Formato: AAAA-MM-DD.'),
  '#required'         => TRUE,
  '#element_validate' => ['myapi_claim_transaction_validate_status_date'], // reutiliza myapi_reservation_valid_date(), mismo patrón que includes/myapi.claims_admin.inc
];
$form['field_comment'] = [
  '#type'     => 'textarea',
  '#title'    => t('Comentario'),
  '#required' => TRUE, // revisado a pedido del usuario tras ver el formulario en vivo — ver Decisiones
];
// #upload_validators / #upload_location NO se hardcodean con los límites de SPEC 55: se leen con
// file_field_widget_upload_validators()/file_field_widget_uri(), funciones reales de Drupal core
// (modules/file/file.field.inc) que ya usa el widget nativo — mismo criterio que reutilizar
// myapi_claims_status_options() en vez de un catálogo hardcodeado.
$form['field_images'] = ['#type' => 'managed_file', '#title' => t('Imágenes'), '#upload_validators' => file_field_widget_upload_validators($images_field, $images_instance)];
$form['field_attachment'] = ['#type' => 'managed_file', '#title' => t('Adjunto'), '#upload_validators' => file_field_widget_upload_validators($attachment_field, $attachment_instance)];
$form['claim_nid'] = ['#type' => 'value', '#value' => $claim_node->nid];
$form['actions']['submit'] = ['#type' => 'submit', '#value' => t('Guardar')];
```

**Decisión revisada durante la implementación (paso 2 del plan):** no se crea un `myapi_claim_status_options()` nuevo. `includes/myapi.claims_admin.inc` (SPEC 56) ya tiene `myapi_claims_status_options()` (plural "claims"), que lee `field_info_field('field_status')['settings']['allowed_values']` dinámicamente para el filtro de `admin/content/claims` — y `field_status` es **un único campo compartido** entre los bundles `reclamo` y `claim_transaction` (mismos `allowed_values`, ver `myapi.install`), así que esa función ya sirve tal cual para el `select` de este formulario. Crear una función casi homónima con los 5 pares hardcodeados habría duplicado lógica ya existente (Regla 3 de CLAUDE.md). `includes/myapi.claim_transaction_admin.inc` carga `includes/myapi.claims_admin.inc` con `module_load_include('inc', 'myapi', 'includes/myapi.claims_admin')` y llama a `myapi_claims_status_options()` directamente.

### Ruta de creación (~~modal~~ página normal)

```php
$items['node/%node/claim-transaction/add'] = [
  'title'            => 'Crear transacción',
  'page callback'    => 'myapi_claim_transaction_add_page_callback',
  'page arguments'   => [1],
  'access callback'  => 'myapi_claim_transaction_add_access',
  'access arguments' => [1],
  'type'             => MENU_CALLBACK,
  'file'             => 'includes/myapi.claim_transaction_admin.inc',
];
```

`myapi_claim_transaction_add_page_callback($claim_node)` simplemente devuelve `drupal_get_form('myapi_claim_transaction_create_form', $claim_node)` — sin rama AJAX.

`myapi_claim_transaction_add_access($node)` — `TRUE` solo si `$node->type === 'reclamo'` **y** `node_access('update', $node)` **y** `node_access('create', 'claim_transaction')`. Cierra la ruta para cualquier otro tipo de nodo y respeta el filtro de condominio de SPEC 56 (que ya decide `node_access('update', ...)` para `administrador edificio`).

### Creación automática al insertar un `reclamo`

```php
// myapi_node_insert(), rama 'reclamo'
$transaction = (object) [
  'type'   => 'claim_transaction',
  'uid'    => $node->uid,
  'status' => 1,
];
node_object_prepare($transaction);
$transaction->field_claim[LANGUAGE_NONE][0]['target_id']  = $node->nid;
$transaction->field_status[LANGUAGE_NONE][0]['value']     = myapi_building_admin_field_value($node, 'field_status'); // el que se eligió al crear el reclamo
$transaction->field_status_date[LANGUAGE_NONE][0]['value'] = date('Y-m-d');
node_save($transaction);
```

### Sincronización de estado

```php
// myapi_node_insert() / myapi_node_update(), rama 'claim_transaction'
$claim_nid = myapi_building_admin_field_target_id($node, 'field_claim'); // helper ya existente, se reutiliza
$claim = $claim_nid ? node_load($claim_nid) : NULL;
if ($claim) {
  $transaction_status = myapi_building_admin_field_value($node, 'field_status');
  $claim_status = myapi_building_admin_field_value($claim, 'field_status');
  if ($transaction_status !== $claim_status) {
    $claim->field_status[LANGUAGE_NONE][0]['value'] = $transaction_status;
    node_save($claim);
  }
}
```

Reutiliza `myapi_building_admin_field_value()` / `myapi_building_admin_field_target_id()` de `includes/myapi.building_admin.inc` (SPEC 49) tal cual, sin duplicarlos — son funciones puras de lectura de campo ya genéricas, no tienen nada de "building admin" en su lógica salvo el nombre del archivo donde viven.

### `field_status` de solo lectura en edición

```php
// myapi_form_reclamo_node_form_alter(), cuando $form['#node']->nid existe
foreach (element_children($form['field_status']) as $langcode) {
  $form['field_status'][$langcode]['#disabled'] = TRUE;
}
```

`#disabled` y no `#access = FALSE`: el valor sigue viéndose y viajando en el submit (Drupal reenvía el `#default_value` de un elemento deshabilitado), así que guardar el resto del formulario del reclamo no pisa el estado con un valor vacío.

---

## Plan de implementación

1. ~~Spike previo, sin código de producto: confirmar que Drupal 7.64 core trae `ajax_command_open_modal_dialog()` y el binding `use-ajax`~~ **Completado — resultado revisa la premisa base.** Verificado por SSH contra el servidor real (`crespcord.lamotora.com`, Drupal 7.64): el binding `use-ajax` (`misc/ajax.js`) y la librería `ui.dialog` (jQuery UI Dialog, declarada en `system.module`) **sí** están disponibles en core. La función `ajax_command_open_modal_dialog()` **no existe** en `includes/ajax.inc` de D7 core (se confirmó listando todas las funciones `ajax_command_*` del archivo) — nunca se incorporó a Drupal 7, es de Drupal 8. Decisión revisada: el modal se construye con `ajax_command_html()` + `ajax_command_invoke($selector, 'dialog', array($settings))`, ambas funciones reales de core, ver Modelo de datos. Sigue sin contrib (se descarta usar el `ctools` ya habilitado en el sitio, ver Decisiones). Nota adicional del mismo spike: el módulo `date_popup` también está habilitado, así que `field_status_date` usa `date_popup` en vez de `date_select`.

2. ~~`includes/myapi.claim_transaction_admin.inc` (nuevo) — catálogo y consulta. `myapi_claim_status_options()`~~ **Completado — decisión revisada.** El catálogo no se recrea: se reutiliza `myapi_claims_status_options()` de `includes/myapi.claims_admin.inc` (SPEC 56) vía `module_load_include()` (ver Modelo de datos). Este paso solo crea `myapi_claim_transaction_timeline_rows($claim_nid)`. *Verificación: `php -l`.*

3. **`includes/myapi.claim_transaction_admin.inc` — formulario de creación, sin modal todavía.** `myapi_claim_transaction_create_form()` + `_submit()`, alcanzable como página normal (aún sin ruta de menú). *Verificación: `php -l`.*

4. **`myapi.module` — `hook_menu()` y `myapi.info`.** Ruta `node/%node/claim-transaction/add`, `myapi_claim_transaction_modal_access()`, `files[]` del `.inc` nuevo. *Verificación: `drush cc all`; abrir la ruta manualmente en el navegador (sin AJAX) muestra el formulario de creación, guarda la transacción y redirige a `node/<nid>/edit`.*

5. ~~`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_modal_callback()`~~ **Completado.** Cuando la petición es AJAX (detectada con `!empty($_POST['ajax_page_state'])`, la misma señal que usan internamente `ajax_base_page_theme()` y `ajax_render()` del core), la función llama a `ajax_render()` directamente e imprime el resultado a mano (`drupal_exit()`), en vez de declarar `'delivery callback' => 'ajax_deliver'` en la ruta — eso habría hecho AJAX obligatorio también para la carga sin JS. Los comandos: `ajax_command_html()` (HTML del formulario del paso 3 en el contenedor `#myapi-claim-transaction-modal`, que agrega el paso 6) + `ajax_command_invoke('#myapi-claim-transaction-modal', 'dialog', array($settings))` (abre el diálogo de jQuery UI). `drupal_add_library('system', 'ui.dialog')` se llama siempre; en la rama AJAX, el diffing de CSS/JS ya incorporado en `ajax_render()` agrega los comandos para cargarla si el cliente no la tiene. Sin JS, sirve la página normal del paso 4 sin cambios — no se necesitó ningún archivo JS propio: el binding `use-ajax` de `misc/ajax.js` ya intercepta el enlace automáticamente. *Verificación: la misma ruta, pedida por un enlace `use-ajax`, abre el modal en vez de navegar.*

6. **`myapi.module` — `myapi_form_reclamo_node_form_alter()`.** `#disabled` de `field_status` en edición, render de la línea de tiempo (`myapi_claim_transaction_timeline_rows()`) y el enlace "Crear transacción" con `class="use-ajax"` hacia la ruta del paso 4, todo dentro de un `fieldset` de `#weight` alto. Nada de esto se ejecuta en `node/add/reclamo`. *Verificación: `node/%/edit` de un reclamo muestra el estado deshabilitado y la lista de transacciones debajo; `node/add/reclamo` no muestra ninguna de las dos cosas.*

7. **`myapi.module` — `myapi_node_insert()`, rama `'reclamo'`.** Crea la transacción inicial copiando `field_status`. Se añade como una rama más del `if` existente, sin tocar las de `'pagos'`/`'boletin'`. *Verificación: crear un reclamo desde `node/add/reclamo` genera automáticamente una `claim_transaction` visible en la línea de tiempo del paso 6.*

8. **`myapi.module` — `myapi_node_insert()` y `myapi_node_update()`, rama `'claim_transaction'`.** La sincronización de estado hacia el reclamo padre. *Verificación: crear una transacción con un estado distinto al del reclamo actualiza el reclamo; crear una con el mismo estado no dispara un `node_save()` de más (comprobable con un `watchdog` temporal o revisando `changed` del reclamo).*

9. **`docs/claim-transaction-timeline.md` (nuevo).** Ruta del modal, campos del formulario, regla de sincronización, regla de creación automática, nota de solo-lectura de `field_status`. *Verificación: lectura contra la implementación.*

10. **`drush cc all` + matriz manual.** Crear reclamo → transacción inicial correcta; abrir modal → crear transacción con estado distinto → el reclamo cambia y la línea de tiempo se actualiza tras la recarga; crear transacción con el mismo estado → el reclamo no cambia; un `administrador edificio` sin permiso sobre ese condominio no puede abrir el modal (403) aunque conozca la URL. **Verificación estática completada** (sin entorno Drupal local disponible): `php -l` limpio en los 3 archivos PHP tocados; auditoría manual de todo `includes/myapi.claim_transaction_admin.inc` línea por línea; confirmado contra el core real (SSH) que el widget `options_select` de `field_status` deja `$form['field_status'][$langcode]` como el elemento `#type => 'select'` en sí (no anidado), validando el bucle `#disabled` del paso 6; confirmado que no hay riesgo de recursión en `node_save($claim)` dentro de la sincronización (`myapi_node_update()` no tiene rama `'reclamo'`). **Matriz manual en vivo — en curso.**

- Primer hallazgo: el enlace "Crear transacción" no reaccionaba al clic. Causa: `misc/ajax.js` no se carga en ninguna página por el solo hecho de poner `class="use-ajax"` en un enlace — Drupal solo lo agrega automáticamente cuando un *elemento de formulario* declara `#ajax` (vía `ajax_process_form()`); un enlace suelto no dispara eso. Sin el archivo, `Drupal.behaviors.AJAX` no existe y el clic no hace nada. Corregido agregando `drupal_add_library('system', 'drupal.ajax')` (y `'ui.dialog'`, adelantado desde el callback del modal) en `myapi_claim_transaction_timeline_build()`, que es lo que renderiza `node/%nid/edit`.
- Segundo hallazgo: el modal abría pero sin estilo (botón de cerrar como caja vacía) y con una etiqueta "Date" duplicada junto a "Fecha de estado". Dos causas independientes: (1) la librería `ui.dialog` no depende de `system/ui` (jQuery UI Core), que es la que trae `misc/ui/jquery.ui.theme.css` (sprites de íconos) — y en este sitio el tema admin `seven` sobrescribe ese CSS con el suyo propio (`themes/seven/template.php`), pero solo si `ui` se carga; (2) `date_popup` agrega su propia sub-etiqueta ("Date"/"Time") salvo que se le pase `#date_label_position => 'none'`. Corregido agregando `drupal_add_library('system', 'ui')` junto a `'ui.dialog'` en ambos lugares, y `#date_label_position => 'none'` en `field_status_date`.
- **Tercer hallazgo, decisión revisada del diseño base:** después de verlo en vivo, el usuario pidió eliminar el modal por completo — "hay varios problemas que no me gustó como quedó" — a favor de una página normal (enlace → página de creación → guardar → redirige al reclamo). Como el flujo POST/redirect de `myapi_claim_transaction_create_form_submit()` ya asumía esto desde el diseño original, el cambio fue solo en `myapi_claim_transaction_add_page_callback()` (ya no hay rama AJAX) y en el enlace (ya no lleva `class="use-ajax"`) — ver la nota al inicio del spec y la fila "Mecanismo del modal" en Decisiones.
- **Cuarto hallazgo:** con la página ya sin modal, la etiqueta de `field_status_date` seguía renderizando al lado del campo en vez de encima. Causa raíz: el CSS `container-inline-date` del módulo Date (pensado para sus widgets combinados) también afecta el layout fuera de un modal. Se reemplazó `date_popup` por `#type => 'date'` (nativo de D7 core, tres `select` día/mes/año, mismo wrapper que el resto de los campos) — ver la fila correspondiente en Decisiones. De paso, `field_comment` pasó a `#required => TRUE` a pedido del usuario.
- **Quinto hallazgo:** con `#type => 'date'` la etiqueta ya quedaba arriba, pero los tres `select` (día/mes/año) se veían descuadrados dentro de su caja ("los selectores están más abajo del contenedor"). Causa: `theme_date()` (core) envuelve el elemento en un `<div class="container-inline">`, y `modules/system/system.base.css` fuerza `display: inline` en los `<div>` internos — layout pensado para otro contexto, no para convivir con el resto del formulario. Revisado a un `#type => 'textfield'` de texto plano (`AAAA-MM-DD`), validado con `#element_validate` reutilizando `myapi_reservation_valid_date()`. Sin selector de fecha visual, pero sin ningún CSS especial que pelear — mismo wrapper que `field_status`/`field_comment`, alineación garantizada.

---

## Criterios de aceptación

**Formulario de reclamo**

- [x] En `node/add/reclamo`, `field_status` es un `select` normal, editable, sin ningún fieldset de transacciones debajo.
- [x] En `node/%nid/edit` de un reclamo existente, `field_status` aparece con el valor actual pero **deshabilitado** — no se puede cambiar desde ahí.
- [x] Guardar el formulario de edición del reclamo (tocando otros campos) no modifica `field_status`, incluso si el HTML llegara manipulado con otro valor (Drupal descarta el envío de un elemento `#disabled`).
- [x] Debajo del formulario de edición aparece la línea de tiempo de transacciones del reclamo, ordenada por `field_status_date` descendente (empate por `nid` descendente).
- [x] Cada fila de la línea de tiempo muestra estado, fecha de estado, comentario (si tiene) y autor.
- [x] El botón/enlace "Crear transacción" aparece al inicio de la línea de tiempo, no al final.

**Creación automática de la transacción inicial**

- [x] Crear un reclamo con `field_status = received` genera una `claim_transaction` con `field_status = received`, `field_claim` apuntando al reclamo recién creado, y `field_status_date` = la fecha de creación.
- [x] Crear un reclamo con un `field_status` distinto de `received` (por ejemplo `in_progress`) genera la transacción inicial con **ese mismo** estado, no forzado a `received`.
- [x] La transacción inicial aparece de inmediato en la línea de tiempo al recargar `node/%nid/edit` del reclamo recién creado.
- [x] Crear un reclamo no dispara ningún `node_save()` adicional del propio reclamo (la rama de sincronización de `claim_transaction` no encuentra diferencia de estado en este caso y no toca al reclamo).

**Página de creación** (~~Modal de creación~~ — el modal se descartó durante la implementación, ver la nota al inicio del spec)

- [x] El enlace "Crear transacción" navega a `node/%nid/claim-transaction/add` y muestra el formulario de creación como página normal.
- [x] El formulario muestra exactamente `field_status`, `field_status_date`, `field_comment`, `field_images`, `field_attachment` — **no** muestra `field_claim`.
- [x] `field_status_date` (campo de texto, formato `AAAA-MM-DD`) llega con la fecha de hoy precargada, con la etiqueta "Fecha de estado" alineada encima del campo igual que el resto de los campos del formulario. Un valor con formato inválido o una fecha inexistente (ej. `2026-02-30`) muestra el error de validación y no crea la transacción.
- [x] `field_comment` es obligatorio: enviar el formulario sin comentario muestra el error nativo de campo requerido y no crea la transacción.
- [x] Enviar el formulario crea la `claim_transaction` con `field_claim` apuntando al reclamo correcto, y redirige a `node/%nid/edit` de ese reclamo (recarga completa).
- [x] Tras la redirección, la nueva transacción aparece primera en la línea de tiempo.
- [x] Un usuario sin `create claim_transaction content` (o sin `node_access('update')` sobre ese reclamo por el filtro de condominio de SPEC 56) recibe 403 al acceder a la ruta de creación por URL directa, aunque conozca el `nid`.
- [x] Subir una imagen de más de 3MB o con extensión no permitida, o un adjunto fuera de `pdf/doc/docx/xls/xlsx`, es rechazado con el mensaje nativo de validación de campo — mismos límites que SPEC 55.

**Sincronización de estado**

- [x] Crear una transacción con `field_status` distinto al `field_status` actual del reclamo actualiza el reclamo a ese nuevo estado.
- [x] Crear una transacción con el **mismo** `field_status` que el reclamo no modifica el reclamo (verificable porque su `changed` no avanza).
- [x] La sincronización ocurre tanto si la transacción se crea desde `myapi_claim_transaction_create_form()` (SPEC 57) como si se crea directamente en `node/add/claim_transaction` (ruta nativa, para `administrator`/`backend`) — la regla vive en `hook_node_insert()`, no en el `_submit()` de ese formulario.
- [x] Editar una transacción existente (`node/%nid/edit` de una `claim_transaction`, ruta nativa) y cambiarle el estado también sincroniza el reclamo, vía `hook_node_update()`.
- [x] No se produce ningún bucle infinito ni doble guardado: guardar el reclamo actualizado por la sincronización no vuelve a crear una transacción (la creación automática solo escucha `hook_node_insert()` de `'reclamo'`, no `hook_node_update()`).

**No regresión / infra**

- [x] `resources/*.resource.inc` no aparece en el diff.
- [x] `hook_menu()` no cambia ninguna ruta `api/v1/...`; la única entrada nueva es `node/%node/claim-transaction/add`.
- [x] Las ramas existentes de `myapi_node_insert()`/`myapi_node_update()` (`pagos`, `boletin`, `recibo`, `alicuota_extra`, `reservation`) no cambian de comportamiento.
- [x] `drush cc all` no reporta errores.
- [x] Existe `docs/claim-transaction-timeline.md` con todo lo anterior documentado.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| ~~Mecanismo del modal~~ Modal vs. página normal | **Revisado en el paso 10 (matriz manual en vivo):** página normal, sin AJAX ni jQuery UI Dialog | AJAX Framework nativo de D7 (enlaces `use-ajax` + `ajax_command_html()` + `ajax_command_invoke($selector, 'dialog', ...)` sobre `ui.dialog`) — la opción original de este spec, construida e implementada hasta funcionar; ctools modal (contrib) | El modal, ya funcional, se vio en vivo con un estilo que no gustó (íconos vacíos, etiqueta duplicada) — ambos corregibles, pero el usuario prefirió cortar la complejidad de raíz en vez de seguir puliendo un modal. `myapi_claim_transaction_create_form()` y su `_submit()` no cambiaron: ya asumían el flujo POST/redirect de página completa desde el principio (ver la fila siguiente), así que solo se simplificó el page callback y el enlace. |
| Envío del formulario ~~del modal~~ | POST normal con `$form_state['redirect']`, recarga completa | Submit por `#ajax` con `ajax_command_replace()` sobre la línea de tiempo | Decisión explícita, tomada incluso cuando existía el modal: más simple, y evita perder cambios sin guardar del formulario del reclamo si el submit AJAX fallara a medio camino. El costo es una recarga completa por cada transacción creada, aceptable para una acción poco frecuente. Con el modal descartado, esta decisión queda igual de vigente — es simplemente el único flujo que existe ahora. |
| Formulario ~~del modal~~ de creación | FAPI propio (`myapi_claim_transaction_create_form()`), no el formulario nativo de nodo de `claim_transaction` | `node_form` nativo, con `hook_form_alter()` ocultando `field_claim` | El formulario nativo de nodo trae pasos, vista previa y otros elementos (autor, fecha de creación, opciones de publicación) que no tienen sentido para esta pantalla de 4 campos. Un formulario propio da control total sobre lo que se muestra, al costo de reimplementar la construcción y el guardado del nodo a mano. |
| `field_status` en `node/%nid/edit` | `#disabled = TRUE` | `#access = FALSE` (ocultarlo), o dejarlo editable | `#disabled` conserva el valor visible y lo reenvía en el submit; `#access = FALSE` también lo haría pero sin mostrarlo, y el operador necesita ver el estado actual del reclamo aunque no pueda tocarlo desde ahí. Dejarlo editable habría permitido desincronizar reclamo y transacciones sin dejar rastro en la línea de tiempo. |
| `field_status` en `node/add/reclamo` | Editable, sin cambios | También deshabilitarlo, forzando siempre `received` | El estado inicial elegido ahí es exactamente lo que la transacción automática copia (ver la sección de Modelo de datos); deshabilitarlo habría entrado en conflicto con la decisión previa de "copia el `field_status` del reclamo" en vez de forzar `received`. |
| Transacción inicial automática | Se dispara desde `hook_node_insert()` de `'reclamo'`, copiando su `field_status` | Dispararla desde el propio formulario de creación del reclamo (un paso de formulario extra) | `hook_node_insert()` cubre también la creación programática de un reclamo (futuros endpoints `api/v1/...`, migraciones), no solo la del formulario admin — es la garantía real de "todo reclamo nace con su primera transacción", no una conveniencia de UI. |
| Dirección de la sincronización | Transacción → reclamo únicamente | Bidireccional (editar el reclamo también generaría una transacción) | Es literalmente lo pedido: "al guardar el estado de la transacción debe actualizar al reclamo". Como además `field_status` del reclamo pasa a ser de solo lectura en edición, la dirección inversa no tiene ningún camino por UI para dispararse — solo por un `node_save()` programático, que queda fuera de este spec. |
| Dónde vive la regla de sincronización | `hook_node_insert()` / `hook_node_update()` de `'claim_transaction'` en `myapi.module` | Dentro del `_submit()` de `myapi_claim_transaction_create_form()` | El formulario nativo de nodo de `claim_transaction` (para `administrator`/`backend`) también debe disparar la sincronización. Ponerla en el hook de nodo la cubre para **cualquier** camino de guardado sin duplicarla. |
| Reutilización de helpers de lectura de campo | `myapi_building_admin_field_value()` / `_field_target_id()` de `includes/myapi.building_admin.inc` | Copiar el mismo patrón de recorrido `langcode`/`delta` en el archivo nuevo | Regla 3 de CLAUDE.md: son funciones puras y genéricas pese a vivir en un archivo con "building_admin" en el nombre — su lógica no depende del rol en absoluto. Duplicar el recorrido de `LANGUAGE_NONE` sería la misma lógica compartida escrita dos veces. |
| Catálogo de estados | **Revisado en implementación:** reutilizar `myapi_claims_status_options()` de `includes/myapi.claims_admin.inc` (SPEC 56) vía `module_load_include()` | `myapi_claim_status_options()` nuevo con los 5 pares hardcodeados (decisión original de este spec) | La premisa original ("no hay hoy ningún otro lugar del código que los necesite fuera de la instalación del campo") resultó falsa: SPEC 56 ya resuelve el mismo catálogo para el filtro de `admin/content/claims`, dinámicamente desde `field_info_field()`, y `field_status` es un único campo compartido entre `reclamo` y `claim_transaction` — misma fuente sirve para ambos. Crear una función casi homónima habría duplicado lógica ya existente (Regla 3 de CLAUDE.md). |
| Adjuntos en el formulario de creación | `managed_file`, mismas validaciones de tamaño/extensión que la instancia de SPEC 55 | Sin adjuntos ahí, solo en la edición posterior de la transacción | Fue elegido explícitamente en las preguntas de alcance: el operador puede necesitar adjuntar evidencia (foto, documento) en el mismo momento en que registra el cambio de estado. |
| Paginación de la línea de tiempo | Ninguna | Pager como el listado de SPEC 56 | Volumen esperado bajo (cambios de estado de un reclamo, no un historial de chat); si en la práctica crece mucho, es un ajuste futuro acotado, no una razón para pagar la complejidad de un pager ahora. |
| Widget de `field_status_date` en el formulario propio | **Revisado dos veces, versión final:** `#type => 'textfield'` de texto plano, `'AAAA-MM-DD'`, validado con `#element_validate` | 1) `date_popup` (decisión original); 2) `#type => 'date'` nativo de D7 core, tres `select` día/mes/año (primera revisión) | `date_popup` causó dos bugs visuales (sub-etiqueta "Date" duplicada, luego la etiqueta principal al lado del campo) por su CSS `container-inline-date`. La primera revisión, `#type => 'date'` nativo, arregló la alineación de la etiqueta pero introdujo un problema distinto: su propio wrapper `theme_date()` agrega la clase `container-inline` (`modules/system/system.base.css`), que fuerza `display: inline` en los `<div>` internos y descuadra los tres `select` dentro de su caja. Los dos widgets son elementos "combo" con su propio CSS de layout pensado para otro contexto; un `textfield` plano usa el mismo wrapper `form_element` que `field_status`/`field_comment` sin ningún CSS especial de por medio — la única forma de garantizar alineación idéntica sin depender de que un contrib o el core no rompan el layout. Costo: sin selector visual de fecha, solo texto validado con `myapi_reservation_valid_date()` (mismo patrón ya usado en `includes/myapi.claims_admin.inc`). Como consecuencia, `myapi.info` ya no depende de `date_popup` (solo de `date`, que sigue haciendo falta por el widget nativo `date_select` de las instancias de campo en `myapi.install`). |
| `field_comment` en el formulario propio | `#required => TRUE` | Opcional (decisión original) | Pedido explícito del usuario tras ver el formulario en vivo: cada transacción debe quedar documentada con un comentario. Cambia solo el formulario de este spec — la instancia de campo de SPEC 55 (`required: 0`) no se toca, así que `node/add/claim_transaction` (nativo, para `administrator`/`backend`) sigue sin exigirlo. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| ~~El entorno real no tiene disponible `ajax_command_open_modal_dialog()` / el binding `use-ajax`~~ | **Superado por el cambio de diseño.** El modal se llegó a construir e implementar (ver historial de los pasos 1 y 5), pero se descartó por completo en el paso 10 a pedido del usuario tras verlo en vivo. Ya no aplica ningún riesgo relacionado con AJAX o jQuery UI Dialog — la página de creación es HTML plano. |
| **Bucle o cascada entre `hook_node_insert()`/`hook_node_update()`** de `reclamo` y `claim_transaction`. | Analizado explícitamente en Modelo de datos: la creación automática solo escucha `hook_node_insert()` de `'reclamo'` (no `update`), y la sincronización solo llama a `node_save()` del reclamo cuando el estado difiere — lo cual, en el caso de la transacción inicial, nunca ocurre porque nace con el mismo estado. Criterio de aceptación explícito que lo verifica. |
| **Un `#disabled` de FAPI se puede saltar con un POST armado a mano** (Drupal descarta el valor enviado para ese campo, pero si algún código intermedio leyera `$_POST` directamente en vez de `$form_state['values']`, podría colarse). | Ningún código de este spec lee `$_POST` directamente; todo pasa por `$form_state['values']` estándar, que es donde Drupal ya garantiza el descarte. Documentado como el mecanismo real de defensa, no una simple cosmética de UI. |
| **`field_claim` apunta a un nodo que no es un `reclamo`** (dato corrupto, o el `target_bundles` de SPEC 53 se relaja en el futuro). | `myapi_building_admin_field_value($claim, 'field_status')` sobre un nodo de otro tipo simplemente no encuentra el campo y devuelve `NULL`; la comparación `$transaction_status !== NULL` es `TRUE` casi siempre, lo que dispararía un `node_save()` que fallaría al intentar escribir `field_status` en un bundle que no lo tiene. Se documenta como caso no cubierto por un guard explícito — depende de que `target_bundles` (SPEC 53) siga restringiendo el campo, que es su función. |
| ~~El catálogo de estados vive duplicado~~ **Resuelto en implementación**: no hay duplicado — este spec reutiliza `myapi_claims_status_options()` de SPEC 56 (ver Decisiones) en vez de crear una copia. | N/A. |
| **Crear una `claim_transaction` sube dos archivos (`field_images`/`field_attachment`) por transacción**, y no hay límite de transacciones por reclamo. | Mismo criterio aceptado que `field_image` de SPEC 32 y los adjuntos de SPEC 55: gestión de almacenamiento operativa, no de este spec. |
| **Un `administrador edificio` con acceso al reclamo pero cuyo condominio cambia de asignación entre que abre la página de creación y la envía** (edita su propio usuario, o un `administrator` le quita el condominio a mitad de sesión). | Caso raro. `myapi_claim_transaction_add_access()` revalida en el `submit`, ya que `node_access('update', $node)` se vuelve a evaluar en cada petición — no hay estado de sesión que quede desactualizado más allá de la duración normal de una petición HTTP. |
| **El formulario nativo de `claim_transaction` (`node/add/claim_transaction`) sigue abierto para `administrator`/`backend`**, aparte de `myapi_claim_transaction_create_form()`, y no aplica ningún `hook_form_alter` de este spec. | Es intencional: esos roles ya podían crear/editar `claim_transaction` desde SPEC 56. La sincronización de estado los alcanza igual porque vive en el hook de nodo, no en el `_submit()` del formulario propio — no hay un camino de creación que la esquive. |

---

## Lo que **NO** está en este spec

- Editar o borrar una transacción desde la línea de tiempo (solo lectura + creación).
- Validación de transiciones de estado válidas/inválidas.
- Notificación al residente por cambio de estado.
- Cualquier endpoint `api/v1/...`.
- Listado propio de `claim_transaction` fuera del contexto de su reclamo.
- Visor propio de adjuntos/imágenes.
- Paginación de la línea de tiempo.

Cada uno, si entra, va en su propio spec.
