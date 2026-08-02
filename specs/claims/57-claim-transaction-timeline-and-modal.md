# SPEC 57 — Edición de reclamo con línea de tiempo de transacciones y modal de creación

> **Estado:** Approved · **Depende de:** SPEC 56 (modo `via_claim`, permisos `create`/`edit any` sobre `claim_transaction` para `administrador edificio`, listado que enlaza a `node/%nid/edit`), SPEC 55 (bundles `reclamo`/`claim_transaction` y sus campos) · **Fecha:** 2026-08-01
> **Objetivo:** En el formulario nativo de edición de `reclamo`, mostrar debajo la línea de tiempo de sus transacciones con un botón que abre un modal (AJAX Framework nativo de Drupal 7) para crear una nueva, sincronizando el estado del reclamo con el de la transacción guardada y creando automáticamente la transacción inicial al crear un reclamo.

Decisiones técnicas de base, fijadas antes del alcance porque condicionan todo lo demás:

- El modal se abre por AJAX (core D7, sin contrib), pero el **envío del formulario es un POST normal** que redirige de vuelta a `node/%nid/edit` — recarga completa, sin `ajax_command_replace()` en el submit.
- El formulario del modal es un **formulario FAPI propio** (`myapi_claim_transaction_create_form()`), no el formulario nativo de nodo de `claim_transaction` — permite mostrar exactamente los 4 campos elegidos y ocultar `field_claim`, sin pelear con los pasos del formulario nativo dentro de un modal.
- `field_status` del reclamo pasa a **`#disabled = TRUE`** en `node/%nid/edit` (solo lectura, con el valor visible y enviado igual) — pero sigue siendo editable normalmente en `node/add/reclamo`, porque ahí es donde se elige el estado inicial que la transacción automática va a copiar.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (nuevo) — toda la lógica de esta pantalla:
  - `myapi_claim_transaction_timeline_rows($claim_nid)` — consulta de solo lectura, una fila por transacción del reclamo, `ORDER BY field_status_date DESC, nid DESC`. `LEFT JOIN` a `field_data_field_status`, `field_data_field_status_date`, `field_data_field_comment`, y a `users` vía `uid` nativo del nodo transacción (autor).
  - `myapi_claim_transaction_create_form($form, &$form_state, $claim_node)` — formulario FAPI propio (no el formulario nativo de nodo de `claim_transaction`) con los 4 campos: `field_status` (`options_select`, requerido), `field_status_date` (selector de fecha, requerido, `#default_value` = hoy), `field_comment` (textarea, opcional), `field_images` / `field_attachment` (`managed_file`, opcionales, mismas extensiones/tamaño máximo que la instancia de SPEC 55). `field_claim` **no** se muestra: viaja como valor fijo en `$form_state['#claim_nid']`.
  - `myapi_claim_transaction_create_form_submit()` — crea el nodo `claim_transaction` (`field_claim` = el reclamo actual, `uid` = usuario actual), lo guarda con `node_save()`, y fija `$form_state['redirect'] = 'node/' . $claim_nid . '/edit'`.
  - `myapi_claim_transaction_modal_callback($claim_node)` — page callback de la ruta del modal: construye el formulario anterior y lo envuelve en `ajax_command_open_modal_dialog()` cuando la petición es AJAX (detectado con el patrón estándar del AJAX Framework de D7 core); si se accede sin JS, degrada a la página normal del formulario (sin modal, mismo formulario, mismo submit).
  - Función de render de la línea de tiempo: tabla o lista con estado, fecha de estado, comentario, autor, y el enlace "Crear transacción" (`class="use-ajax"` apuntando a la ruta del modal) al inicio del bloque.
- **`myapi.module`** (modificar):
  - `myapi_form_reclamo_node_form_alter(&$form, &$form_state, $form_id)` (nuevo, Drupal lo detecta solo por convención `hook_form_FORM_ID_alter`, sin dispatcher):
    - Si el nodo tiene `nid` (edición): `$form['field_status'][...]['#disabled'] = TRUE` en todos los `langcode`/`delta` (mismo patrón de recorrido que `myapi_building_admin_restrict_bulletin_form()`); añade un `fieldset` al final del formulario (`#weight` alto) con la línea de tiempo y el enlace del modal.
    - Si el nodo es nuevo (`node/add/reclamo`, sin `nid`): no toca nada — ni el `#disabled`, ni la sección de transacciones.
  - Entrada nueva de `hook_menu()`: `node/%node/claim-transaction/add` (`MENU_CALLBACK`), `page callback` → `myapi_claim_transaction_modal_callback`, `page arguments` → `array(1)`, `access callback` → verifica `node_access('update', $node)` sobre el reclamo **y** `node_access('create', 'claim_transaction')` del usuario actual.
  - `myapi_node_insert()` (modificar) — nueva rama para `'reclamo'`: crea la transacción inicial con `field_status` copiado del reclamo recién insertado, `field_status_date` = hoy, `uid` = autor del reclamo, sin comentario ni adjuntos. Va como una rama más del `if` existente, sin reestructurar el resto.
  - `myapi_node_insert()` / `myapi_node_update()` (modificar) — nueva rama para `'claim_transaction'` en ambos hooks: carga el reclamo vía `field_claim`, compara `field_status`; si difiere, actualiza `field_status` del reclamo y `node_save()`. Si es igual, no toca el reclamo.
  - `myapi.info` (modificar) — `files[] = includes/myapi.claim_transaction_admin.inc`.
- **`js/myapi.claim_transaction_modal.js`** (nuevo, solo si el AJAX Framework nativo de D7 lo requiere para el binding del enlace `use-ajax` — se confirma en el plan de implementación) — cargado con `drupal_add_js()` **solo** dentro de `myapi_form_reclamo_node_form_alter()` cuando el nodo tiene `nid`.
- **`docs/claim-transaction-timeline.md`** (nuevo) — ruta del modal, campos del formulario, regla de sincronización de estado, regla de creación automática, y la nota de que `field_status` del reclamo es de solo lectura en edición.
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

### Formulario del modal — `myapi_claim_transaction_create_form()`

```php
$form['field_status'] = [
  '#type'          => 'select',
  '#title'         => t('Estado'),
  '#options'       => myapi_claim_status_options(), // catálogo de SPEC 55, extraído a helper para no repetir los 5 pares valor|etiqueta
  '#required'      => TRUE,
];
$form['field_status_date'] = [
  '#type'          => 'date_select', // o 'date_popup' si el módulo Date lo trae instalado; se confirma en el plan
  '#title'         => t('Fecha de estado'),
  '#default_value' => ['year' => ..., 'month' => ..., 'day' => ...], // hoy
  '#required'      => TRUE,
];
$form['field_comment'] = [
  '#type'  => 'textarea',
  '#title' => t('Comentario'),
];
$form['field_images'] = ['#type' => 'managed_file', '#title' => t('Imágenes'), '#upload_validators' => [...]];
$form['field_attachment'] = ['#type' => 'managed_file', '#title' => t('Adjunto'), '#upload_validators' => [...]];
$form['claim_nid'] = ['#type' => 'value', '#value' => $claim_node->nid];
$form['actions']['submit'] = ['#type' => 'submit', '#value' => t('Guardar')];
```

`myapi_claim_status_options()` es un helper nuevo y pequeño en el mismo `.inc`, la única fuente de los 5 pares `value|label` de `field_status` para que este formulario y cualquier otro futuro no repitan el catálogo (hoy solo vive como `allowed_values` dentro de `myapi.install`).

### Ruta del modal

```php
$items['node/%node/claim-transaction/add'] = [
  'title'            => 'Crear transacción',
  'page callback'    => 'myapi_claim_transaction_modal_callback',
  'page arguments'   => [1],
  'access callback'  => 'myapi_claim_transaction_modal_access',
  'access arguments' => [1],
  'type'             => MENU_CALLBACK,
  'file'             => 'includes/myapi.claim_transaction_admin.inc',
];
```

`myapi_claim_transaction_modal_access($node)` — `TRUE` solo si `$node->type === 'reclamo'` **y** `node_access('update', $node)` **y** `node_access('create', 'claim_transaction')`. Cierra la ruta para cualquier otro tipo de nodo y respeta el filtro de condominio de SPEC 56 (que ya decide `node_access('update', ...)` para `administrador edificio`).

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

1. **Spike previo, sin código de producto: confirmar que Drupal 7.64 core trae `ajax_command_open_modal_dialog()` y el binding `use-ajax`** (`includes/ajax.inc`, `misc/ajax.js`, agrupados bajo `system` desde 7.14) en el sitio real. *Verificación: un enlace de prueba con `class="use-ajax"` apuntando a una página cualquiera abre un diálogo jQuery UI sin JS propio. Si no está disponible en este entorno, se documenta como decisión revisada antes de seguir — no se avanza a ciegas.*

2. **`includes/myapi.claim_transaction_admin.inc` (nuevo) — catálogo y consulta.** `myapi_claim_status_options()` (los 5 pares `value|label`) y `myapi_claim_transaction_timeline_rows($claim_nid)`. *Verificación: `php -l`.*

3. **`includes/myapi.claim_transaction_admin.inc` — formulario de creación, sin modal todavía.** `myapi_claim_transaction_create_form()` + `_submit()`, alcanzable como página normal (aún sin ruta de menú). *Verificación: `php -l`.*

4. **`myapi.module` — `hook_menu()` y `myapi.info`.** Ruta `node/%node/claim-transaction/add`, `myapi_claim_transaction_modal_access()`, `files[]` del `.inc` nuevo. *Verificación: `drush cc all`; abrir la ruta manualmente en el navegador (sin AJAX) muestra el formulario de creación, guarda la transacción y redirige a `node/<nid>/edit`.*

5. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_modal_callback()`.** Envuelve el formulario del paso 3 en `ajax_command_open_modal_dialog()` cuando la petición es AJAX; sin JS, sirve la página normal del paso 4 sin cambios. *Verificación: la misma ruta, pedida por un enlace `use-ajax`, abre el modal en vez de navegar.*

6. **`myapi.module` — `myapi_form_reclamo_node_form_alter()`.** `#disabled` de `field_status` en edición, render de la línea de tiempo (`myapi_claim_transaction_timeline_rows()`) y el enlace "Crear transacción" con `class="use-ajax"` hacia la ruta del paso 4, todo dentro de un `fieldset` de `#weight` alto. Nada de esto se ejecuta en `node/add/reclamo`. *Verificación: `node/%/edit` de un reclamo muestra el estado deshabilitado y la lista de transacciones debajo; `node/add/reclamo` no muestra ninguna de las dos cosas.*

7. **`myapi.module` — `myapi_node_insert()`, rama `'reclamo'`.** Crea la transacción inicial copiando `field_status`. Se añade como una rama más del `if` existente, sin tocar las de `'pagos'`/`'boletin'`. *Verificación: crear un reclamo desde `node/add/reclamo` genera automáticamente una `claim_transaction` visible en la línea de tiempo del paso 6.*

8. **`myapi.module` — `myapi_node_insert()` y `myapi_node_update()`, rama `'claim_transaction'`.** La sincronización de estado hacia el reclamo padre. *Verificación: crear una transacción con un estado distinto al del reclamo actualiza el reclamo; crear una con el mismo estado no dispara un `node_save()` de más (comprobable con un `watchdog` temporal o revisando `changed` del reclamo).*

9. **`docs/claim-transaction-timeline.md` (nuevo).** Ruta del modal, campos del formulario, regla de sincronización, regla de creación automática, nota de solo-lectura de `field_status`. *Verificación: lectura contra la implementación.*

10. **`drush cc all` + matriz manual.** Crear reclamo → transacción inicial correcta; abrir modal → crear transacción con estado distinto → el reclamo cambia y la línea de tiempo se actualiza tras la recarga; crear transacción con el mismo estado → el reclamo no cambia; un `administrador edificio` sin permiso sobre ese condominio no puede abrir el modal (403) aunque conozca la URL.

---

## Criterios de aceptación

**Formulario de reclamo**

- [ ] En `node/add/reclamo`, `field_status` es un `select` normal, editable, sin ningún fieldset de transacciones debajo.
- [ ] En `node/%nid/edit` de un reclamo existente, `field_status` aparece con el valor actual pero **deshabilitado** — no se puede cambiar desde ahí.
- [ ] Guardar el formulario de edición del reclamo (tocando otros campos) no modifica `field_status`, incluso si el HTML llegara manipulado con otro valor (Drupal descarta el envío de un elemento `#disabled`).
- [ ] Debajo del formulario de edición aparece la línea de tiempo de transacciones del reclamo, ordenada por `field_status_date` descendente (empate por `nid` descendente).
- [ ] Cada fila de la línea de tiempo muestra estado, fecha de estado, comentario (si tiene) y autor.
- [ ] El botón/enlace "Crear transacción" aparece al inicio de la línea de tiempo, no al final.

**Creación automática de la transacción inicial**

- [ ] Crear un reclamo con `field_status = received` genera una `claim_transaction` con `field_status = received`, `field_claim` apuntando al reclamo recién creado, y `field_status_date` = la fecha de creación.
- [ ] Crear un reclamo con un `field_status` distinto de `received` (por ejemplo `in_progress`) genera la transacción inicial con **ese mismo** estado, no forzado a `received`.
- [ ] La transacción inicial aparece de inmediato en la línea de tiempo al recargar `node/%nid/edit` del reclamo recién creado.
- [ ] Crear un reclamo no dispara ningún `node_save()` adicional del propio reclamo (la rama de sincronización de `claim_transaction` no encuentra diferencia de estado en este caso y no toca al reclamo).

**Modal de creación**

- [ ] El enlace "Crear transacción" abre un diálogo modal sin navegar fuera de `node/%nid/edit` (con JS activo).
- [ ] Sin JavaScript, el mismo enlace navega a `node/%nid/claim-transaction/add` y muestra el formulario como página normal, totalmente funcional.
- [ ] El formulario del modal muestra exactamente `field_status`, `field_status_date`, `field_comment`, `field_images`, `field_attachment` — **no** muestra `field_claim`.
- [ ] `field_status_date` llega preseleccionado con la fecha de hoy.
- [ ] Enviar el formulario crea la `claim_transaction` con `field_claim` apuntando al reclamo correcto, y redirige a `node/%nid/edit` de ese reclamo (recarga completa).
- [ ] Tras la redirección, la nueva transacción aparece primera en la línea de tiempo.
- [ ] Un usuario sin `create claim_transaction content` (o sin `node_access('update')` sobre ese reclamo por el filtro de condominio de SPEC 56) recibe 403 al acceder a la ruta del modal por URL directa, aunque conozca el `nid`.
- [ ] Subir una imagen de más de 3MB o con extensión no permitida, o un adjunto fuera de `pdf/doc/docx/xls/xlsx`, es rechazado con el mensaje nativo de validación de campo — mismos límites que SPEC 55.

**Sincronización de estado**

- [ ] Crear una transacción con `field_status` distinto al `field_status` actual del reclamo actualiza el reclamo a ese nuevo estado.
- [ ] Crear una transacción con el **mismo** `field_status` que el reclamo no modifica el reclamo (verificable porque su `changed` no avanza).
- [ ] La sincronización ocurre tanto si la transacción se crea desde el modal como si se crea directamente en `node/add/claim_transaction` (ruta nativa, para `administrator`/`backend`) — la regla vive en `hook_node_insert()`, no en el formulario del modal.
- [ ] Editar una transacción existente (`node/%nid/edit` de una `claim_transaction`, ruta nativa) y cambiarle el estado también sincroniza el reclamo, vía `hook_node_update()`.
- [ ] No se produce ningún bucle infinito ni doble guardado: guardar el reclamo actualizado por la sincronización no vuelve a crear una transacción (la creación automática solo escucha `hook_node_insert()` de `'reclamo'`, no `hook_node_update()`).

**No regresión / infra**

- [ ] `resources/*.resource.inc` no aparece en el diff.
- [ ] `hook_menu()` no cambia ninguna ruta `api/v1/...`; la única entrada nueva es `node/%node/claim-transaction/add`.
- [ ] Las ramas existentes de `myapi_node_insert()`/`myapi_node_update()` (`pagos`, `boletin`, `recibo`, `alicuota_extra`, `reservation`) no cambian de comportamiento.
- [ ] `drush cc all` no reporta errores.
- [ ] Existe `docs/claim-transaction-timeline.md` con todo lo anterior documentado.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Mecanismo del modal | AJAX Framework nativo de Drupal 7 core (`ajax_command_open_modal_dialog()`, enlaces `use-ajax`) | ctools modal (contrib), o modal vanilla sin AJAX real | Sin contrib, y es el mecanismo estándar de D7 para esto desde la versión 7.14. Se verifica su disponibilidad real en el entorno como primer paso del plan, antes de construir nada sobre el supuesto. |
| Envío del formulario del modal | POST normal con `$form_state['redirect']`, recarga completa | Submit por `#ajax` con `ajax_command_replace()` sobre la línea de tiempo | Decisión explícita: más simple, y evita perder cambios sin guardar del formulario del reclamo si el submit AJAX fallara a medio camino. El costo es una recarga completa por cada transacción creada, aceptable para una acción poco frecuente. |
| Formulario del modal | FAPI propio (`myapi_claim_transaction_create_form()`), no el formulario nativo de nodo de `claim_transaction` | `node_form` nativo embebido en el modal, con `hook_form_alter()` ocultando `field_claim` | El formulario nativo de nodo trae pasos, vista previa y otros elementos (autor, fecha de creación, opciones de publicación) que no tienen sentido dentro de un modal de 4 campos. Un formulario propio da control total sobre lo que se muestra, al costo de reimplementar la construcción y el guardado del nodo a mano. |
| `field_status` en `node/%nid/edit` | `#disabled = TRUE` | `#access = FALSE` (ocultarlo), o dejarlo editable | `#disabled` conserva el valor visible y lo reenvía en el submit; `#access = FALSE` también lo haría pero sin mostrarlo, y el operador necesita ver el estado actual del reclamo aunque no pueda tocarlo desde ahí. Dejarlo editable habría permitido desincronizar reclamo y transacciones sin dejar rastro en la línea de tiempo. |
| `field_status` en `node/add/reclamo` | Editable, sin cambios | También deshabilitarlo, forzando siempre `received` | El estado inicial elegido ahí es exactamente lo que la transacción automática copia (ver la sección de Modelo de datos); deshabilitarlo habría entrado en conflicto con la decisión previa de "copia el `field_status` del reclamo" en vez de forzar `received`. |
| Transacción inicial automática | Se dispara desde `hook_node_insert()` de `'reclamo'`, copiando su `field_status` | Dispararla desde el propio formulario de creación del reclamo (un paso de formulario extra) | `hook_node_insert()` cubre también la creación programática de un reclamo (futuros endpoints `api/v1/...`, migraciones), no solo la del formulario admin — es la garantía real de "todo reclamo nace con su primera transacción", no una conveniencia de UI. |
| Dirección de la sincronización | Transacción → reclamo únicamente | Bidireccional (editar el reclamo también generaría una transacción) | Es literalmente lo pedido: "al guardar el estado de la transacción debe actualizar al reclamo". Como además `field_status` del reclamo pasa a ser de solo lectura en edición, la dirección inversa no tiene ningún camino por UI para dispararse — solo por un `node_save()` programático, que queda fuera de este spec. |
| Dónde vive la regla de sincronización | `hook_node_insert()` / `hook_node_update()` de `'claim_transaction'` en `myapi.module` | Dentro del `_submit()` del formulario del modal | El formulario nativo de nodo de `claim_transaction` (para `administrator`/`backend`, que no pasan por el modal) también debe disparar la sincronización. Ponerla en el hook de nodo la cubre para **cualquier** camino de guardado — modal, formulario nativo, o un futuro endpoint — sin duplicarla. |
| Reutilización de helpers de lectura de campo | `myapi_building_admin_field_value()` / `_field_target_id()` de `includes/myapi.building_admin.inc` | Copiar el mismo patrón de recorrido `langcode`/`delta` en el archivo nuevo | Regla 3 de CLAUDE.md: son funciones puras y genéricas pese a vivir en un archivo con "building_admin" en el nombre — su lógica no depende del rol en absoluto. Duplicar el recorrido de `LANGUAGE_NONE` sería la misma lógica compartida escrita dos veces. |
| Catálogo de estados | `myapi_claim_status_options()` nuevo, única fuente para el `select` del modal | Leer `field_info_field('field_status')['settings']['allowed_values']` en tiempo de ejecución | Los 5 pares ya están escritos como literal en `myapi.install` (SPEC 55) y no hay hoy ningún otro lugar del código que los necesite fuera de la instalación del campo; introducir una función que los repite en el `.inc` es más simple que resolver `allowed_values` dinámicamente para un catálogo que no va a cambiar en caliente. |
| Adjuntos en el modal | `managed_file`, mismas validaciones de tamaño/extensión que la instancia de SPEC 55 | Sin adjuntos en el modal, solo en la edición posterior de la transacción | Fue elegido explícitamente en las preguntas de alcance: el operador puede necesitar adjuntar evidencia (foto, documento) en el mismo momento en que registra el cambio de estado. |
| Paginación de la línea de tiempo | Ninguna | Pager como el listado de SPEC 56 | Volumen esperado bajo (cambios de estado de un reclamo, no un historial de chat); si en la práctica crece mucho, es un ajuste futuro acotado, no una razón para pagar la complejidad de un pager ahora. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El entorno real no tiene disponible `ajax_command_open_modal_dialog()` / el binding `use-ajax`** tal como se asume (depende de que el tema admin cargue jQuery UI y las librerías correctas). | Paso 1 del plan es exactamente verificar esto antes de escribir ninguna otra línea. Si falla, hay una salida de degradación ya prevista: el mismo formulario funciona como página normal sin JS (paso 4), así que el peor caso es "sin modal", no "sin funcionalidad". |
| **Bucle o cascada entre `hook_node_insert()`/`hook_node_update()`** de `reclamo` y `claim_transaction`. | Analizado explícitamente en Modelo de datos: la creación automática solo escucha `hook_node_insert()` de `'reclamo'` (no `update`), y la sincronización solo llama a `node_save()` del reclamo cuando el estado difiere — lo cual, en el caso de la transacción inicial, nunca ocurre porque nace con el mismo estado. Criterio de aceptación explícito que lo verifica. |
| **Un `#disabled` de FAPI se puede saltar con un POST armado a mano** (Drupal descarta el valor enviado para ese campo, pero si algún código intermedio leyera `$_POST` directamente en vez de `$form_state['values']`, podría colarse). | Ningún código de este spec lee `$_POST` directamente; todo pasa por `$form_state['values']` estándar, que es donde Drupal ya garantiza el descarte. Documentado como el mecanismo real de defensa, no una simple cosmética de UI. |
| **`field_claim` apunta a un nodo que no es un `reclamo`** (dato corrupto, o el `target_bundles` de SPEC 53 se relaja en el futuro). | `myapi_building_admin_field_value($claim, 'field_status')` sobre un nodo de otro tipo simplemente no encuentra el campo y devuelve `NULL`; la comparación `$transaction_status !== NULL` es `TRUE` casi siempre, lo que dispararía un `node_save()` que fallaría al intentar escribir `field_status` en un bundle que no lo tiene. Se documenta como caso no cubierto por un guard explícito — depende de que `target_bundles` (SPEC 53) siga restringiendo el campo, que es su función. |
| **El catálogo de estados vive duplicado**: los `allowed_values` en `myapi.install` (SPEC 55) y `myapi_claim_status_options()` en este spec. | Aceptado conscientemente (ver Decisiones): son 5 pares que no cambian en caliente. Si algún día se agrega o renombra un estado, hay que tocar los dos lugares — se documenta en `docs/claim-transaction-timeline.md` para que no se edite solo uno. |
| **Crear una `claim_transaction` sube dos archivos (`field_images`/`field_attachment`) por transacción**, y no hay límite de transacciones por reclamo. | Mismo criterio aceptado que `field_image` de SPEC 32 y los adjuntos de SPEC 55: gestión de almacenamiento operativa, no de este spec. |
| **Un `administrador edificio` con acceso al reclamo pero cuyo condominio cambia de asignación entre que abre el modal y lo envía** (edita su propio usuario, o un `administrator` le quita el condominio a mitad de sesión). | Caso raro. `myapi_claim_transaction_modal_access()` revalida en el `submit`, ya que `node_access('update', $node)` se vuelve a evaluar en cada petición — no hay estado de sesión que quede desactualizado más allá de la duración normal de una petición HTTP. |
| **El formulario nativo de `claim_transaction` (`node/add/claim_transaction`) sigue abierto para `administrator`/`backend`**, fuera del modal, y no aplica ningún `hook_form_alter` de este spec. | Es intencional: esos roles ya podían crear/editar `claim_transaction` desde SPEC 56. La sincronización de estado los alcanza igual porque vive en el hook de nodo, no en el formulario del modal — no hay un camino de creación que la esquive. |

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
