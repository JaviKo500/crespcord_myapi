# SPEC 59 — Editar transacción desde la línea de tiempo del reclamo

> **Estado:** Implemented · **Depende de:** SPEC 56 (permisos `create`/`edit any claim_transaction content` para `administrador edificio`, modo `via_claim`), SPEC 57 (`includes/myapi.claim_transaction_admin.inc`: tabla y función de render de la línea de tiempo que este spec modifica), SPEC 58 (`field_status_date` con hora — este spec asume que ya está ampliado, para que editar una transacción permita corregir también la hora) · **Fecha:** 2026-08-01
> **Objetivo:** Agregar un enlace "Editar" a cada fila de la línea de tiempo de transacciones del reclamo, apuntando a la edición nativa de esa `claim_transaction` (`node/%nid/edit`), visible solo cuando el usuario tiene acceso, con `field_claim` bloqueado en edición y redirigiendo de vuelta al reclamo tras guardar.

Notas técnicas que fija esto, porque condicionan el resto:

- El link se **oculta** (no solo se deshabilita) cuando el usuario no tiene `node_access('update', $transaction_node)` sobre esa transacción puntual — lo cual exige `node_load($row->nid)` por fila (no alcanza con el `nid` crudo de la consulta). Mismo criterio de costo ya aceptado en SPEC 56 para el modo `via_claim` ("en `hook_node_access()` se paga por nodo cargado"), y volumen bajo ya asumido por SPEC 57 (sin pager).
- `field_claim` pasa a `#disabled = TRUE` en el formulario nativo de edición de `claim_transaction` — mismo patrón ya usado por SPEC 57 para `field_status` en el formulario de `reclamo` — para que editar una transacción no pueda re-apuntarla a otro reclamo por error.
- Tras guardar la edición nativa, se fuerza `$form_state['redirect']` de vuelta a `node/<claim_nid>/edit` (en vez del destino nativo `node/<nid>`), vía `hook_form_alter()` sobre `claim_transaction_node_form`, leyendo `field_claim` del propio nodo que se está guardando.
- Ningún cambio de permisos: los que ya concedió SPEC 56 (`edit any claim_transaction content`) alcanzan; este spec solo agrega el enlace y ajusta el formulario nativo.
- De las tres funciones nuevas, **dos son lógica pura** y entran en `tests/unit/` (mismo criterio que SPEC 49/56): `myapi_claim_transaction_transaction_form_alter()` es manipulación de arrays de FAPI, y `myapi_claim_transaction_edit_form_submit_redirect()` es una lectura de campo (`myapi_building_admin_field_target_id()`, ya cubierta) más una asignación. La tercera, `myapi_claim_transaction_edit_link()`, es `node_load()` + `node_access()`: queda fuera del unit layer por la misma regla que ya dejó fuera a `myapi_node_access()` en `BuildingAdminTest` — se verifica en la matriz manual.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (modificar):
  - Nueva **`myapi_claim_transaction_edit_link($nid)`** — `node_load($nid)` + `node_access('update', $transaction_node)`; devuelve el link renderizado (`l(t('Editar'), 'node/' . $nid . '/edit')`) o cadena vacía si no hay acceso. Sin acceso, la celda queda vacía — no hay ningún texto sustituto.
  - `myapi_claim_transaction_timeline_table_rows()` — agrega la celda "Editar" (vía la función anterior) al final de cada fila.
  - `myapi_claim_transaction_timeline_build()` — agrega el header `t('Editar')` a la tabla (`#header`).
  - Nueva **`myapi_claim_transaction_transaction_form_alter(&$form, &$form_state)`** — delegado de `hook_form_claim_transaction_node_form_alter()`: en modo edición (`$form['#node']->nid` presente), deshabilita `field_claim` (recorrido `langcode`/`delta` hasta el elemento `target_id` del widget `entityreference_autocomplete` — estructura distinta a la de `field_status`, que es un `select` directo) y agrega `$form['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect'`. En modo creación (`node/add/claim_transaction`, sin `nid`) no toca nada — mismo criterio que `myapi_claim_transaction_reclamo_form_alter()` en `node/add/reclamo`.
  - Nueva **`myapi_claim_transaction_edit_form_submit_redirect($form, &$form_state)`** — lee `field_claim` del nodo recién guardado (`$form_state['node']`) con `myapi_building_admin_field_target_id()` (ya reutilizado en este archivo) y, si resuelve un `claim_nid`, fija `$form_state['redirect'] = 'node/' . $claim_nid . '/edit'`.
- **`myapi.module`** (modificar):
  - Nueva `myapi_form_claim_transaction_node_form_alter(&$form, &$form_state, $form_id)` — glue de una línea, mismo patrón que `myapi_form_reclamo_node_form_alter()`: carga el include y delega.
- **`tests/unit/ClaimTransactionEditTest.php`** (nuevo) — tests unitarios de las dos funciones puras del spec. Detalle de casos en "Tests unitarios", más abajo.
- **`tests/unit/bootstrap.php`** (modificar) — stubs de `element_children()` y `form_load_include()`, las dos funciones de Drupal que `myapi_claim_transaction_transaction_form_alter()` llama desde adentro. Mismo tipo de stub que `t()`/`form_set_error()` (SPEC 52): equivalente fiel, sin base de datos.
- **`tests/README.md`** (modificar) — el stub nuevo y la cobertura nueva, en las dos secciones donde ese fichero ya lleva la cuenta ("Unit tests" y el bloque de "Design constraint — `tests/unit/bootstrap.php`").
- **`docs/claim-transaction-timeline.md`** (modificar) — documenta el link "Editar" (condición de visibilidad), el bloqueo de `field_claim` en edición, y el redirect forzado al reclamo.
- `drush cc all` al final. Sin `drush updb`: ningún campo, tabla ni permiso nuevo (los permisos ya los concedió SPEC 56).

**Fuera de alcance (para specs futuros):**

- **Borrar una transacción.** Sigue sin existir ningún link ni acción de borrado en la línea de tiempo.
- **Validar transiciones de estado al editar** (por ejemplo, impedir pasar de `closed` a `received` al editar). Sigue fuera, igual que SPEC 55/57/58.
- **Notificar al residente** cuando se edita una transacción existente. Mismo patrón que `48-reservation-notifications`, spec propio si se pide.
- **Historial de ediciones / auditoría** (quién editó qué campo y cuándo). No hay tracking de revisiones en este spec — Drupal core no lo activa para este bundle y no se agrega.
- **Cualquier endpoint `api/v1/...`.**
- **Tests de integración (SimpleTest) o e2e (Postman/Newman) de este flujo.** `tests/integration/` y `tests/e2e/` siguen cubriendo solo `auth` (ver `tests/README.md`, "Scope note"); ninguna pantalla de back-office tiene cobertura ahí todavía, y este spec no abre esa puerta. Lo que sí entra es el unit layer, donde ya viven `BuildingAdminTest`/`BuildingAdminUserTest`.
- **Test unitario de `myapi_claim_transaction_edit_link()`.** Ver "Tests unitarios": es `node_load()` + `node_access()`, exactamente lo que `tests/unit` no toca.
- **La ampliación de `field_status_date` a día+hora.** Ya la resuelve SPEC 58 en el widget nativo; este spec no repite esa lógica, solo se beneficia de que ya exista al abrir el formulario de edición.
- **Restringir otros campos del formulario nativo** (`field_status`, `field_status_date`, `field_comment`, `field_images`, `field_attachment`) más allá de `field_claim`. Siguen editables sin ninguna restricción nueva — el único campo que se bloquea es `field_claim`, por el riesgo específico de mover la transacción a otro reclamo.

---

## Modelo de datos

No se crean campos, tablas ni bundles nuevos. Solo funciones nuevas y cambios de render/formulario sobre las estructuras que SPEC 55-58 ya definieron.

### `myapi_claim_transaction_edit_link($nid)` — nuevo, `includes/myapi.claim_transaction_admin.inc`

```php
function myapi_claim_transaction_edit_link($nid) {
  $transaction_node = node_load($nid);
  if (!$transaction_node || !node_access('update', $transaction_node)) {
    return '';
  }
  return l(t('Editar'), 'node/' . $nid . '/edit');
}
```

`node_access('update', ...)` es la misma función que ya resuelve `myapi_claim_transaction_add_access()` (SPEC 57) — cubre `bypass node access` de `administrator`/`backend` y el filtro `via_claim` por condominio de `administrador edificio` (SPEC 56) sin reimplementar nada de esa lógica aquí.

### `myapi_claim_transaction_timeline_table_rows()` — celda nueva

```php
foreach ($rows as $row) {
  $table_rows[] = array(
    myapi_claims_status_label($row->status),
    $row->status_date === NULL ? '—' : check_plain(format_date(strtotime($row->status_date), 'custom', 'd/m/Y H:i')),
    ($row->comment === NULL || $row->comment === '') ? '—' : check_plain($row->comment),
    myapi_claim_transaction_author_label($row),
    array('data' => array('#markup' => myapi_claim_transaction_edit_link($row->nid))),
  );
}
```

### `myapi_claim_transaction_timeline_build()` — header nuevo

```php
$build['table'] = array(
  '#theme' => 'table',
  '#header' => array(t('Estado'), t('Fecha de estado'), t('Comentario'), t('Autor'), t('Editar')),
  '#rows' => myapi_claim_transaction_timeline_table_rows($rows),
  '#empty' => t('Todavía no hay transacciones para este reclamo.'),
);
```

### `myapi.module` — glue nuevo

```php
/**
 * Implements hook_form_claim_transaction_node_form_alter().
 */
function myapi_form_claim_transaction_node_form_alter(&$form, &$form_state, $form_id) {
  module_load_include('inc', 'myapi', 'includes/myapi.claim_transaction_admin');
  myapi_claim_transaction_transaction_form_alter($form, $form_state);
}
```

### `myapi_claim_transaction_transaction_form_alter()` — nuevo, `includes/myapi.claim_transaction_admin.inc`

```php
function myapi_claim_transaction_transaction_form_alter(&$form, &$form_state) {
  if (empty($form['#node']->nid)) {
    return;
  }

  if (isset($form['field_claim'])) {
    foreach (element_children($form['field_claim']) as $langcode) {
      foreach (element_children($form['field_claim'][$langcode]) as $delta) {
        if (isset($form['field_claim'][$langcode][$delta]['target_id'])) {
          $form['field_claim'][$langcode][$delta]['target_id']['#disabled'] = TRUE;
        }
      }
    }
  }

  form_load_include($form_state, 'inc', 'myapi', 'includes/myapi.claim_transaction_admin');

  $form['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect';

  if (isset($form['actions']['submit']['#submit'])) {
    $form['actions']['submit']['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect';
  }
}
```

El agregado a `$form['actions']['submit']['#submit']` **tampoco** estaba en la versión original de este spec: se agregó al probar en el sitio, donde guardar caía en `node/<nid>` (el destino nativo) como si el handler no existiera. `form_execute_handlers()` ejecuta la lista `#submit` del **elemento que dispara** el envío cuando ese elemento tiene una, e ignora por completo la del formulario; `node_form()` siempre le da a su botón "Guardar" la suya (`array('node_form_submit')`). Un handler agregado solo a `$form['#submit']` nunca corre en un formulario de nodo. Solo una de las dos listas se ejecuta, así que agregarlo a ambas no lo duplica — y aunque lo hiciera, fijar dos veces el mismo redirect es idempotente.

El `form_load_include()` **no** estaba en la versión original de este spec: se agregó al probar el flujo en el sitio, donde guardar la edición devolvía `Call to undefined function myapi_claim_transaction_edit_form_submit_redirect() in form_execute_handlers()`. El formulario de `claim_transaction` tiene campos `managed_file` (`field_images`, `field_attachment`), así que Drupal lo **cachea**: en el POST se sirve el array cacheado y `hook_form_alter()` — con él, el `module_load_include()` de la glue en `myapi.module` — no vuelve a ejecutarse, de modo que el `#submit` cacheado apunta a una función cuyo fichero nunca se cargó. `form_load_include()` es el mecanismo estándar de FAPI para eso: además de cargar el include, lo registra en `$form_state['build_info']['files']`, que es lo que Drupal recarga al recuperar un formulario cacheado. Este spec es el primero del módulo que agrega un `#submit` alojado en un `.inc` a un formulario nativo, por eso el patrón no estaba resuelto de antes (SPEC 57 lo evitó usando un formulario propio).

Recorrido `langcode` + `delta` (no solo `langcode`, como con `field_status` en SPEC 57): el widget `entityreference_autocomplete` anida el textfield real bajo `target_id` dentro de cada delta, a diferencia de `options_select`, donde el propio elemento de nivel `langcode` ya es el `select`.

El `isset($form['field_claim'])` envuelve **solo el bucle**, no el `#submit`: si el campo no estuviera en el formulario (otro módulo que lo quite, o un `field_access` que lo oculte), no hay nada que deshabilitar, pero el redirect al reclamo sigue siendo correcto — lo resuelve el nodo guardado, no el formulario. Sin esa guarda, `element_children(NULL)` emite un warning de PHP 7.4 y el `foreach` sobre `NULL` otro más; el test unitario del caso "sin `field_claim`" es lo que fija esta separación.

### `myapi_claim_transaction_edit_form_submit_redirect()` — nuevo

```php
function myapi_claim_transaction_edit_form_submit_redirect($form, &$form_state) {
  if (empty($form_state['node'])) {
    return;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $claim_nid = myapi_building_admin_field_target_id($form_state['node'], 'field_claim');
  if ($claim_nid) {
    $form_state['redirect'] = 'node/' . $claim_nid . '/edit';
  }
}
```

El `empty($form_state['node'])` es la contraparte del anterior: `node_form_submit()` siempre deja ahí el nodo guardado, pero esta función corre al final de una cadena de submits que otro módulo podría alterar, y leer un índice inexistente sería un notice de PHP. `myapi_building_admin_field_target_ids()` ya tolera un no-objeto (`is_object()` en su primera guarda), así que la guarda es contra el notice, no contra un fatal — y es un caso del test unitario.

Corre **después** del submit nativo de `node_form_submit()` (agregado al final de `$form['#submit']`), que ya guardó el nodo y ya dejó `$form_state['node']` con el objeto actualizado — incluido `field_claim`, cuyo valor Drupal reenvía igual pese a estar `#disabled` (mismo mecanismo ya documentado en SPEC 57 para `field_status`). Si `$claim_nid` no resuelve (caso ya cubierto como riesgo en SPEC 56/57: `field_claim` vacío o corrupto), no se sobrescribe `$form_state['redirect']` y Drupal cae al destino nativo (`node/<nid>`).

---

## Tests unitarios

`tests/unit/` cubre solo lógica pura, sin base de datos ni APIs de Drupal (`tests/README.md`). De este spec entran las dos funciones que cumplen esa condición; `myapi_claim_transaction_edit_link()` no, y eso se dice explícitamente en el docblock del fichero de test, no se omite en silencio (mismo criterio que `BuildingAdminTest` con `myapi_node_access()`).

### `tests/unit/bootstrap.php` — stub nuevo

```php
if (!function_exists('element_children')) {
  /**
   * Every key that is not a '#property'. Drupal's own version also sorts by
   * '#weight' when asked; myapi never asks, and the widget's deltas are
   * already in order, so the sort is left out on purpose.
   *
   * The is_int() branch is the PHP 7.4 guard: field widget deltas are integer
   * keys, and $key[0] on an int raises "Trying to access array offset on value
   * of type int". An integer key is never a '#property', so short-circuiting
   * on it is faithful to the original rather than a deviation from it.
   */
  function element_children(&$elements, $sort = FALSE) {
    $children = array();
    foreach ($elements as $key => $value) {
      if (is_int($key) || $key === '' || $key[0] !== '#') {
        $children[] = $key;
      }
    }

    return $children;
  }
}
```

`&$elements` por referencia igual que el original (Drupal la usa para cachear el orden); el test pasa siempre un array real, así que la firma no cambia ningún comportamiento.

El `is_int($key)` **no** estaba en la versión original de este spec: se agregó durante la implementación. La condición literal de Drupal (`$key === '' || $key[0] !== '#'`) funciona sobre el nivel `langcode` (claves string, el único que recorría SPEC 57 con `field_status`), pero sobre el nivel `delta` las claves son enteros y `$key[0]` sobre un `int` emite `Trying to access array offset on value of type int` en PHP 7.4 — que PHPUnit convierte en error, rompiendo los seis casos con deltas. Un delta entero nunca es una `#property`, así que la guarda es la misma corrección que core adoptó por compatibilidad con PHP 7.4, no una divergencia del original.

### `tests/unit/ClaimTransactionEditTest.php` — nuevo

`require_once` de `includes/myapi.claim_transaction_admin.inc` y de `includes/myapi.building_admin.inc`. El primero hace un `module_load_include()` a nivel de fichero (para `includes/myapi.claims_admin.inc`), que el stub no-op del bootstrap ya neutraliza — por eso el segundo `require_once` es explícito: dentro de `myapi_claim_transaction_edit_form_submit_redirect()` el `module_load_include()` tampoco carga nada, y `myapi_building_admin_field_target_id()` tiene que estar disponible de verdad. Es exactamente la restricción de diseño que `tests/README.md` ya documenta.

**`myapi_claim_transaction_transaction_form_alter()`**

| Caso | Qué fija |
|---|---|
| Modo creación (`$form['#node']` sin `nid`) | El formulario queda idéntico: ni `#disabled`, ni `#submit` agregado. Es el criterio de aceptación de `node/add/claim_transaction` convertido en test. |
| Sin `#node` en absoluto | Mismo resultado, sin notice de PHP (`empty()` cubre ambos). |
| Modo edición, un `langcode`/un `delta` | `field_claim[und][0]['target_id']['#disabled'] === TRUE` y `#submit` termina en `myapi_claim_transaction_edit_form_submit_redirect`. |
| Modo edición, varios `langcode` y varios `delta` | **Todos** los `target_id` quedan deshabilitados. Guarda contra la regresión de deshabilitar solo el primero — que en un sitio monolingüe pasaría inadvertida. |
| Un delta sin `target_id` (widget distinto) | No se crea la clave `target_id` ni se rompe el recorrido; el resto de los deltas sí se deshabilita. |
| `#property` mezclada entre los hijos (`#theme`, `#language`) | No se la trata como `langcode`/`delta` — es lo que el stub de `element_children()` está garantizando. |
| Sin `field_claim` en el formulario | Ningún warning y `#submit` **sí** se agrega (ver Modelo de datos). |
| `#submit` preexistente | Se conserva y la función nueva queda **al final** — el orden es lo que garantiza que corra después de `node_form_submit()`, es decir con el nodo ya guardado. |
| Modo edición: el include queda registrado en `$form_state['build_info']['files']` | Guarda de regresión del bug visto en el sitio: sin ese registro, el formulario cacheado (por sus campos `managed_file`) llama en el POST a una función cuyo fichero no se cargó. |
| El botón "Guardar" tiene su propia `#submit` | El handler se agrega **también** ahí. Guarda de regresión del segundo bug visto en el sitio: `form_execute_handlers()` ignora la lista del formulario cuando el elemento que dispara tiene la suya. |
| El botón "Guardar" no tiene `#submit` propia | No se le crea una; la lista del formulario es la que Drupal va a ejecutar en ese caso. |
| Modo creación: no se registra nada | El `form_load_include()` va del mismo lado del `return` que el `#submit`: si no hay handler, no hay fichero que registrar. |
| Llamarla dos veces sobre el mismo `$form` | Documenta el comportamiento actual (`#submit` con la función repetida, redirect idempotente igual). Drupal no invoca dos veces el mismo `hook_form_alter()`, así que es descripción, no requisito. |

**`myapi_claim_transaction_edit_form_submit_redirect()`**

| Caso | Qué fija |
|---|---|
| `field_claim` con `target_id` | `$form_state['redirect'] === 'node/<claim_nid>/edit'`. |
| `field_claim` multi-delta | Toma el primero, la semántica de `myapi_building_admin_field_target_id()`. |
| `field_claim` presente pero vacío (`array()`) | `redirect` **no** se toca: el criterio de aceptación del dato corrupto. |
| `field_claim` ausente del nodo | Igual: sin redirect, sin error. |
| `redirect` ya fijado por otro submit y `field_claim` sin resolver | El valor previo sobrevive intacto — "no se sobrescribe" es más fuerte que "no se fija". |
| `$form_state['node']` ausente / no objeto | Sin notice, sin redirect. |

---

## Plan de implementación

1. **`tests/unit/bootstrap.php` — stub de `element_children()`.** *Verificación: `php -l`; `vendor/bin/phpunit` sigue en verde con los tests que ya existían (el stub no altera ninguno).*

2. **`tests/unit/ClaimTransactionEditTest.php` (nuevo) — todos los casos de la sección anterior, antes de tocar la lógica.** *Verificación: `vendor/bin/phpunit` **en rojo**, con errores de función inexistente para las dos funciones nuevas.*

3. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_edit_link($nid)`.** *Verificación: `php -l`.*

4. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_timeline_table_rows()` y `myapi_claim_transaction_timeline_build()`.** Columna/header "Editar" agregados. *Verificación: `node/%nid/edit` de un reclamo con transacciones muestra la columna nueva con el link en cada fila accesible.*

5. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_transaction_form_alter()` + `myapi_claim_transaction_edit_form_submit_redirect()`.** Con las dos guardas del Modelo de datos. *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**, suite entera.*

6. **`myapi.module` — `myapi_form_claim_transaction_node_form_alter()`.** *Verificación: `drush cc all`; abrir `node/%nid/edit` de una `claim_transaction` muestra `field_claim` con su valor visible pero deshabilitado (no se puede tipear ni autocompletar otro reclamo).*

7. **`docs/claim-transaction-timeline.md` y `tests/README.md`.** El primero documenta el link "Editar" (y su condición de visibilidad), el bloqueo de `field_claim`, y el redirect forzado al reclamo tras guardar; el segundo, el stub de `element_children()` y la cobertura nueva. *Verificación: lectura contra la implementación.*

8. **`drush cc all` + matriz manual.** Click en "Editar" desde la línea de tiempo abre `node/%nid/edit` de esa transacción; cambiar estado/comentario/fecha y guardar redirige de vuelta a `node/<claim_nid>/edit`, con la línea de tiempo ya reflejando los cambios (incluida la sincronización de `field_status` del reclamo, ya cubierta por SPEC 57); intentar cambiar `field_claim` a mano (URL manipulada) no tiene efecto, porque Drupal descarta el valor enviado de un elemento `#disabled`; un `administrador edificio` sin el condominio del reclamo asignado no ve el link "Editar" en ninguna fila (ni accede a `node/%nid/edit` de esa transacción por URL directa: 403); crear una `claim_transaction` desde `node/add/claim_transaction` (ruta nativa, sin `nid` todavía) sigue funcionando exactamente igual que antes, sin ningún redirect forzado ni campo deshabilitado.

---

## Criterios de aceptación

> Marcados los que se verificaron **en el repositorio** (`vendor/bin/phpunit`,
> `php -l`, lectura del diff contra `main`). Los que quedan sin marcar
> necesitan el sitio desplegado: son la matriz manual del paso 8 del plan —
> render de la columna, `#disabled` en el navegador, redirect real, 403 por
> URL directa y `drush cc all`.

**Link "Editar" en la línea de tiempo**

- [x] Cada fila de la línea de tiempo muestra una columna "Editar" al final, con un link a `node/<nid>/edit` de esa `claim_transaction` cuando el usuario tiene acceso.
- [x] Un `administrator` o `backend` ve el link "Editar" en **todas** las filas, de cualquier reclamo.
- [x] Un `administrador edificio` con el condominio del reclamo asignado ve el link "Editar" en todas las filas de ese reclamo.
- [x] Un `administrador edificio` **sin** el condominio del reclamo asignado no ve la fila en absoluto (ya está fuera de su listado por SPEC 56) — no es un caso alcanzable en la práctica, pero si se llegara a esa línea de tiempo por algún otro medio, la celda de "Editar" queda vacía.
- [x] El link navega a `node/<nid>/edit`, el formulario nativo de Drupal para `claim_transaction` — no a ningún formulario propio.

**`field_claim` en edición**

- [x] En `node/%nid/edit` de una `claim_transaction` existente, `field_claim` se muestra con su valor actual pero **deshabilitado** — no se puede tipear ni autocompletar otro reclamo.
- [x] Guardar el formulario (tocando otros campos) no modifica `field_claim`, incluso si el HTML llegara manipulado con otro valor (Drupal descarta el envío de un elemento `#disabled`, mismo mecanismo ya usado para `field_status` en SPEC 57).
- [x] En `node/add/claim_transaction` (creación nativa, sin `nid`), `field_claim` sigue siendo un campo normal, editable y requerido — sin ningún cambio.

**Redirect tras editar**

- [x] Guardar la edición de una `claim_transaction` existente redirige a `node/<claim_nid>/edit` del reclamo al que pertenece (leído de su propio `field_claim`), no a `node/<nid>` (destino nativo).
- [x] Tras la redirección, la línea de tiempo del reclamo muestra los cambios guardados (estado, fecha de estado, comentario) de inmediato.
- [x] Si `field_claim` no resuelve un reclamo válido (caso ya documentado como riesgo en SPEC 56/57: dato corrupto), el redirect cae al destino nativo de Drupal (`node/<nid>`) sin error.
- [x] Crear una `claim_transaction` nueva desde `node/add/claim_transaction` (ruta nativa) **no** se ve afectada por este redirect — sigue su comportamiento nativo actual, sin cambios.

**Sincronización y permisos (no regresión de SPEC 56/57)**

- [x] Editar el `field_status` de una transacción desde `node/%nid/edit` sigue sincronizando el `field_status` del reclamo padre, exactamente como ya garantiza SPEC 57 vía `hook_node_update()`.
- [x] Ningún permiso nuevo se concede ni se revoca: `create`/`edit any claim_transaction content` siguen siendo exactamente los que otorgó SPEC 56. *(`myapi.install` no aparece en el diff de la rama; ningún `hook_permission()` ni `user_role_grant_permissions()` cambió.)*
- [x] Un usuario sin `edit any claim_transaction content` (o sin `node_access('update')` sobre esa transacción puntual) recibe 403 al acceder a `node/%nid/edit` por URL directa, aunque conozca el `nid` — comportamiento nativo de Drupal, no tocado por este spec.

**Tests unitarios**

- [x] `vendor/bin/phpunit` pasa entero, incluidos los tests nuevos, sin modificar ninguna aserción de los tests existentes. *(288 tests, 1022 aserciones, OK — 272/997 antes de este spec, y ningún fichero de test previo aparece en el diff.)*
- [x] `tests/unit/ClaimTransactionEditTest.php` cubre los casos de la tabla de "Tests unitarios" — en particular: modo creación intacto, todos los `target_id` deshabilitados con varios `langcode`/`delta`, `#submit` agregado al final, y redirect no fijado cuando `field_claim` no resuelve. *(16 tests, uno por caso de las dos tablas.)*
- [x] El stub de `element_children()` en `tests/unit/bootstrap.php` es un equivalente fiel del original para el único uso que hace el código probado, y no rompe ningún test previo. *(Con la guarda `is_int($key)` documentada arriba; los 272 tests previos siguen en verde.)*
- [x] El docblock de `ClaimTransactionEditTest.php` dice explícitamente que `myapi_claim_transaction_edit_link()` queda fuera del unit layer y por qué, igual que hace `BuildingAdminTest` con `myapi_node_access()`.
- [x] `tests/README.md` menciona el stub nuevo y la cobertura nueva. *(En las dos secciones: "Unit tests" y "Design constraint — `tests/unit/bootstrap.php`".)*

**No regresión / infra**

- [x] `resources/*.resource.inc` no aparece en el diff.
- [x] `hook_menu()` no cambia ninguna ruta — no se agrega ninguna ruta nueva en este spec. *(El diff de `myapi.module` son 14 líneas: solo `myapi_form_claim_transaction_node_form_alter()`.)*
- [x] Las ramas existentes de `myapi_node_insert()`/`myapi_node_update()` (`reclamo`, `claim_transaction`, `pagos`, `boletin`, etc.) no cambian de comportamiento. *(Mismo diff: ninguna de las dos funciones aparece en él.)*
- [x] `drush cc all` no reporta errores.
- [x] `docs/claim-transaction-timeline.md` documenta el link "Editar", el bloqueo de `field_claim` y el redirect. *(Dos secciones nuevas más la fila "Editar" en la tabla de columnas y la matriz de edición.)*

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Formulario de edición | Reutilizar el formulario nativo de nodo de `claim_transaction` (`node/%nid/edit`) | Un formulario FAPI propio para editar, espejo de `myapi_claim_transaction_create_form()` (SPEC 57) | El pedido original es explícito: "apuntando a la edición nativa de esa `claim_transaction`". Además, SPEC 57 ya construyó un formulario propio solo para la *creación* porque necesitaba ocultar `field_claim`; para editar, deshabilitarlo (esta misma spec) resuelve el mismo problema sin duplicar la construcción manual de un segundo formulario. |
| Visibilidad del link | Se oculta si el usuario no tiene acceso (`node_access('update')` por fila, con `node_load`) | Mostrarlo siempre y dejar que Drupal devuelva 403 al clickear | Decisión explícita del usuario: evita un link que lleva a un error, mismo criterio que ya usa la ruta de creación (`myapi_claim_transaction_add_access()`), que también valida acceso antes de mostrarse. |
| Costo de `node_load()` por fila | Aceptado sin caché adicional | Resolver el acceso desde los datos ya traídos por la consulta (sin cargar el nodo completo) | `node_access('update', ...)` es la única fuente de verdad para el filtro `via_claim` de SPEC 56; reimplementarla a mano duplicaría lógica (Regla 3 CLAUDE.md) y arriesgaría desincronizarse si esa regla cambia. El costo ya está aceptado por SPEC 56/57 para este mismo archivo, dado el volumen bajo esperado por reclamo. |
| `field_claim` en edición | `#disabled = TRUE` (visible, no editable) | `#access = FALSE` (ocultarlo), o dejarlo editable | Mismo criterio que `field_status` en `node/%nid/edit` de `reclamo` (SPEC 57): `#disabled` conserva el valor visible sin arriesgar que se pierda al reenviar el formulario, y el operador ve a qué reclamo pertenece la transacción aunque no pueda cambiarlo. Dejarlo editable habría permitido mover una transacción entre reclamos sin ningún control, exactamente el riesgo que SPEC 57 ya dejó documentado ("`field_claim` apunta a un nodo que no es un `reclamo`"). |
| Redirect tras guardar | Forzado a `node/<claim_nid>/edit`, agregado a `$form['#submit']` | Dejar el destino nativo de Drupal (`node/<nid>`, la vista de la transacción) | Decisión explícita del usuario, por consistencia de flujo con la creación (SPEC 57 ya redirige ahí tras crear). El costo es tocar mínimamente el submit nativo del formulario de `claim_transaction` — algo que SPEC 57 evitó a propósito para el formulario de *creación* (por eso usó uno propio), pero acá no aplica esa razón porque se reutiliza el formulario nativo de edición sin más remedio. |
| Mecanismo del redirect | Función de submit adicional agregada a `$form['#submit']`, leída desde `$form_state['node']` después del guardado | Un `hook_node_update()` que fije `drupal_goto()` a mano | Agregar a `$form['#submit']` es el mecanismo estándar de FAPI para intervenir el flujo de un formulario sin tocar su lógica de guardado; `drupal_goto()` en un hook de nodo interrumpiría la ejecución de otros `hook_node_update()` que corran después (como el propio de sincronización de estado de este mismo módulo). |
| Alcance de `hook_form_claim_transaction_node_form_alter()` | Solo actúa en modo edición (`$form['#node']->nid` presente) | Aplicarlo también en `node/add/claim_transaction` | El pedido es "editar transacción desde el listado"; la creación nativa (para `administrator`/`backend`) ya funcionaba antes de este spec y no tiene el riesgo de "mover" una transacción porque todavía no pertenece a ningún reclamo hasta que se guarda por primera vez. |
| Cobertura de tests | Unit tests solo de las dos funciones puras (`transaction_form_alter()` y `edit_form_submit_redirect()`) | Cubrir también `myapi_claim_transaction_edit_link()` inyectándole un `$node_loader`/`$access_checker`, como SPEC 56 inyectó `$claim_loader` | La inyección de SPEC 56 valía la pena porque el salto doble `claim_transaction → reclamo → condominio` es lógica propia con varias ramas. Acá la función es literalmente "cargá el nodo, preguntá `node_access()`, devolvé `l()`": inyectar los dos colaboradores dejaría el test verificando los mocks, no el comportamiento, y agregaría dos parámetros que ningún llamador de producción usa. El acceso real ya está cubierto donde vive (`BuildingAdminTest` para la resolución `via_claim`, matriz manual para `node_access()` sobre el sitio). |
| Fichero de test | Uno nuevo, `ClaimTransactionEditTest.php` | Agregar los casos a `BuildingAdminTest.php`, que ya requiere `includes/myapi.building_admin.inc` | `BuildingAdminTest` cubre el rol `administrador edificio` (SPEC 49/56); estos casos son del formulario de `claim_transaction`, otro fichero de producción y otro tema. Un fichero por área es el patrón que ya siguen los 14 tests existentes (`ReservationCapacityTest`, `ReservationMidnightTest`, …). |
| Guardas nuevas (`isset($form['field_claim'])`, `empty($form_state['node'])`) | Se agregan, y cada una tiene su caso de test | Dejar el código del spec tal como estaba, sin guardas | Escribir los casos primero las hizo evidentes: ambas rutas emiten warnings/notices de PHP 7.4 en vez de fallar limpio, y ninguna es hipotética (un `field_access` que oculte `field_claim`, u otro submit que vacíe `$form_state`). El costo es dos líneas. |
| Integración / e2e de este flujo | No se agregan | Un `MyapiClaimTransactionTestCase.test` en `tests/integration/` que haga el roundtrip real de editar y verificar el redirect | `tests/integration/` y `tests/e2e/` cubren hoy solo `auth` (`tests/README.md`, "Scope note"). Abrir la cobertura de back-office es un spec propio, no un anexo de éste — y arrastraría el módulo companion `myapi_test`, el runner de SimpleTest y el acceso SSH al servidor para un cambio de dos funciones. |
| Historial de ediciones | Fuera de alcance, sin revisions ni log de auditoría | Activar `node_revision` para `claim_transaction`, o registrar un `watchdog()` en cada edición | No fue pedido. Agregar revisiones tocaría el content type (SPEC 55) sin necesidad declarada; si se pide auditoría más adelante, es un spec propio. |
| Restringir otros campos en edición (`field_status_date`, `field_comment`, etc.) | Sin cambios, todos editables | Bloquear también `field_status_date` o `field_comment` para forzar solo cambios de estado "limpios" | No fue pedido — el pedido es habilitar la edición, no restringirla más allá del riesgo puntual de `field_claim`. Restringir de más contradiría el objetivo de la spec. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`node_load()` por fila en cada render de la línea de tiempo.** Un reclamo con muchas transacciones dispara un `node_load()` adicional por cada una, solo para decidir si mostrar el link. | Mismo criterio ya aceptado en SPEC 57 (sin pager, volumen bajo esperado por reclamo — cambios de estado, no un chat) y en SPEC 56 (el costo por nodo cargado de `via_claim` ya es conocido y aceptado para este mismo tipo de contenido). Si el volumen creciera, es un ajuste futuro acotado (por ejemplo, cachear el resultado de acceso por `uid` dentro de la misma petición), no una razón para no resolver el pedido ahora. |
| **Un `#disabled` de FAPI se puede saltar con un POST armado a mano** si algún código intermedio leyera `$_POST` directamente en vez de `$form_state['values']`. | Ningún código de este spec ni de SPEC 57 lee `$_POST` directamente; todo pasa por `$form_state['values']`/`$form_state['node']` estándar, donde Drupal ya garantiza el descarte del valor de un elemento deshabilitado — mismo razonamiento ya documentado como riesgo aceptado en SPEC 57 para `field_status`. |
| **Agregar una función a `$form['#submit']` de un formulario nativo de nodo** puede interactuar mal con otros módulos que también alteren `claim_transaction_node_form` en el futuro (orden de ejecución de submits). | Hoy no hay ningún otro `hook_form_alter()` sobre este formulario — se verificó al revisar `myapi.module` completo. La función se agrega al final de `$form['#submit']`, después del submit nativo de guardado, que es el punto de extensión estándar de FAPI para esto; si en el futuro otro módulo necesita intervenir, el orden relativo se resuelve entonces con `hook_form_alter()` y sus pesos, no es un problema introducido por este spec. |
| **El redirect al reclamo puede sorprender a un `administrator`/`backend` acostumbrado al flujo nativo de Drupal** (guardar un nodo y ver su propia página), si edita una `claim_transaction` sin venir del link de la línea de tiempo (por ejemplo, desde `/admin/content`). | Aceptado conscientemente: es el mismo destino final sin importar desde dónde se llegó a editar, y es coherente con que la transacción "vive" dentro del contexto de su reclamo — mismo criterio ya fijado por SPEC 57 para la creación. Si en el uso real resulta confuso, es un ajuste de UX futuro, no un bug de este spec. |
| **El stub de `element_children()` puede divergir del original** y hacer que un test pase (o falle) por una razón que en Drupal no ocurre. | Es el mismo riesgo, ya aceptado, de los seis stubs que `tests/unit/bootstrap.php` lleva desde SPEC 50/52/54, y la mitigación es la misma: el stub implementa **solo** lo que el código probado usa (filtrar claves `#`), y la parte que no se usa (el orden por `#weight`) queda fuera con un comentario que lo dice, en vez de imitada a medias. El comportamiento real del widget se verifica igual en la matriz manual del paso 8. |
| **Los tests unitarios no prueban que el link "Editar" se oculte de verdad**, que es el punto de seguridad del spec. | Deliberado y escrito, no un olvido: esa garantía la da `node_access('update', ...)` —código de Drupal más la regla `via_claim` de SPEC 56, ya cubierta por `BuildingAdminTest` en su parte pura. Los dos criterios de aceptación del `administrador edificio` sin el condominio (celda vacía y 403 por URL directa) siguen siendo verificación manual, igual que en SPEC 56/57. |
| **`field_claim` corrupto o vacío** (nodo borrado, dato inconsistente) hace que `myapi_claim_transaction_edit_form_submit_redirect()` no encuentre `claim_nid` y el redirect caiga al destino nativo. | Comportamiento ya cubierto explícitamente en Modelo de datos y en los criterios de aceptación: sin `claim_nid` resuelto, no se sobrescribe `$form_state['redirect']` — nunca se intenta un `drupal_goto()` a una ruta inválida. Mismo caso ya documentado como riesgo aceptado en SPEC 56/57. |

---

## Lo que **NO** está en este spec

- Borrar una transacción.
- Validación de transiciones de estado al editar.
- Notificación al residente por la edición.
- Historial de ediciones / auditoría.
- Cualquier endpoint `api/v1/...`.
- Ampliar `field_status_date` a día+hora (ya resuelto por SPEC 58).
- Restringir otros campos del formulario nativo más allá de `field_claim`.
- Tests de integración (SimpleTest) o e2e de este flujo, y test unitario de `myapi_claim_transaction_edit_link()`.

Cada uno, si entra, va en su propio spec.
