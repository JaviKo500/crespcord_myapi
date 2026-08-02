# SPEC 59 — Editar transacción desde la línea de tiempo del reclamo

> **Estado:** Draft · **Depende de:** SPEC 56 (permisos `create`/`edit any claim_transaction content` para `administrador edificio`, modo `via_claim`), SPEC 57 (`includes/myapi.claim_transaction_admin.inc`: tabla y función de render de la línea de tiempo que este spec modifica), SPEC 58 (`field_status_date` con hora — este spec asume que ya está ampliado, para que editar una transacción permita corregir también la hora) · **Fecha:** 2026-08-01
> **Objetivo:** Agregar un enlace "Editar" a cada fila de la línea de tiempo de transacciones del reclamo, apuntando a la edición nativa de esa `claim_transaction` (`node/%nid/edit`), visible solo cuando el usuario tiene acceso, con `field_claim` bloqueado en edición y redirigiendo de vuelta al reclamo tras guardar.

Notas técnicas que fija esto, porque condicionan el resto:

- El link se **oculta** (no solo se deshabilita) cuando el usuario no tiene `node_access('update', $transaction_node)` sobre esa transacción puntual — lo cual exige `node_load($row->nid)` por fila (no alcanza con el `nid` crudo de la consulta). Mismo criterio de costo ya aceptado en SPEC 56 para el modo `via_claim` ("en `hook_node_access()` se paga por nodo cargado"), y volumen bajo ya asumido por SPEC 57 (sin pager).
- `field_claim` pasa a `#disabled = TRUE` en el formulario nativo de edición de `claim_transaction` — mismo patrón ya usado por SPEC 57 para `field_status` en el formulario de `reclamo` — para que editar una transacción no pueda re-apuntarla a otro reclamo por error.
- Tras guardar la edición nativa, se fuerza `$form_state['redirect']` de vuelta a `node/<claim_nid>/edit` (en vez del destino nativo `node/<nid>`), vía `hook_form_alter()` sobre `claim_transaction_node_form`, leyendo `field_claim` del propio nodo que se está guardando.
- Ningún cambio de permisos: los que ya concedió SPEC 56 (`edit any claim_transaction content`) alcanzan; este spec solo agrega el enlace y ajusta el formulario nativo.

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
- **`docs/claim-transaction-timeline.md`** (modificar) — documenta el link "Editar" (condición de visibilidad), el bloqueo de `field_claim` en edición, y el redirect forzado al reclamo.
- `drush cc all` al final. Sin `drush updb`: ningún campo, tabla ni permiso nuevo (los permisos ya los concedió SPEC 56).

**Fuera de alcance (para specs futuros):**

- **Borrar una transacción.** Sigue sin existir ningún link ni acción de borrado en la línea de tiempo.
- **Validar transiciones de estado al editar** (por ejemplo, impedir pasar de `closed` a `received` al editar). Sigue fuera, igual que SPEC 55/57/58.
- **Notificar al residente** cuando se edita una transacción existente. Mismo patrón que `48-reservation-notifications`, spec propio si se pide.
- **Historial de ediciones / auditoría** (quién editó qué campo y cuándo). No hay tracking de revisiones en este spec — Drupal core no lo activa para este bundle y no se agrega.
- **Cualquier endpoint `api/v1/...`.**
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

  foreach (element_children($form['field_claim']) as $langcode) {
    foreach (element_children($form['field_claim'][$langcode]) as $delta) {
      if (isset($form['field_claim'][$langcode][$delta]['target_id'])) {
        $form['field_claim'][$langcode][$delta]['target_id']['#disabled'] = TRUE;
      }
    }
  }

  $form['#submit'][] = 'myapi_claim_transaction_edit_form_submit_redirect';
}
```

Recorrido `langcode` + `delta` (no solo `langcode`, como con `field_status` en SPEC 57): el widget `entityreference_autocomplete` anida el textfield real bajo `target_id` dentro de cada delta, a diferencia de `options_select`, donde el propio elemento de nivel `langcode` ya es el `select`.

### `myapi_claim_transaction_edit_form_submit_redirect()` — nuevo

```php
function myapi_claim_transaction_edit_form_submit_redirect($form, &$form_state) {
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $claim_nid = myapi_building_admin_field_target_id($form_state['node'], 'field_claim');
  if ($claim_nid) {
    $form_state['redirect'] = 'node/' . $claim_nid . '/edit';
  }
}
```

Corre **después** del submit nativo de `node_form_submit()` (agregado al final de `$form['#submit']`), que ya guardó el nodo y ya dejó `$form_state['node']` con el objeto actualizado — incluido `field_claim`, cuyo valor Drupal reenvía igual pese a estar `#disabled` (mismo mecanismo ya documentado en SPEC 57 para `field_status`). Si `$claim_nid` no resuelve (caso ya cubierto como riesgo en SPEC 56/57: `field_claim` vacío o corrupto), no se sobrescribe `$form_state['redirect']` y Drupal cae al destino nativo (`node/<nid>`).

---

## Plan de implementación

1. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_edit_link($nid)`.** *Verificación: `php -l`.*

2. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_timeline_table_rows()` y `myapi_claim_transaction_timeline_build()`.** Columna/header "Editar" agregados. *Verificación: `node/%nid/edit` de un reclamo con transacciones muestra la columna nueva con el link en cada fila accesible.*

3. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_transaction_form_alter()` + `myapi_claim_transaction_edit_form_submit_redirect()`.** *Verificación: `php -l`.*

4. **`myapi.module` — `myapi_form_claim_transaction_node_form_alter()`.** *Verificación: `drush cc all`; abrir `node/%nid/edit` de una `claim_transaction` muestra `field_claim` con su valor visible pero deshabilitado (no se puede tipear ni autocompletar otro reclamo).*

5. **`docs/claim-transaction-timeline.md`.** Documenta el link "Editar" (y su condición de visibilidad), el bloqueo de `field_claim`, y el redirect forzado al reclamo tras guardar. *Verificación: lectura contra la implementación.*

6. **`drush cc all` + matriz manual.** Click en "Editar" desde la línea de tiempo abre `node/%nid/edit` de esa transacción; cambiar estado/comentario/fecha y guardar redirige de vuelta a `node/<claim_nid>/edit`, con la línea de tiempo ya reflejando los cambios (incluida la sincronización de `field_status` del reclamo, ya cubierta por SPEC 57); intentar cambiar `field_claim` a mano (URL manipulada) no tiene efecto, porque Drupal descarta el valor enviado de un elemento `#disabled`; un `administrador edificio` sin el condominio del reclamo asignado no ve el link "Editar" en ninguna fila (ni accede a `node/%nid/edit` de esa transacción por URL directa: 403); crear una `claim_transaction` desde `node/add/claim_transaction` (ruta nativa, sin `nid` todavía) sigue funcionando exactamente igual que antes, sin ningún redirect forzado ni campo deshabilitado.

---

## Criterios de aceptación

**Link "Editar" en la línea de tiempo**

- [ ] Cada fila de la línea de tiempo muestra una columna "Editar" al final, con un link a `node/<nid>/edit` de esa `claim_transaction` cuando el usuario tiene acceso.
- [ ] Un `administrator` o `backend` ve el link "Editar" en **todas** las filas, de cualquier reclamo.
- [ ] Un `administrador edificio` con el condominio del reclamo asignado ve el link "Editar" en todas las filas de ese reclamo.
- [ ] Un `administrador edificio` **sin** el condominio del reclamo asignado no ve la fila en absoluto (ya está fuera de su listado por SPEC 56) — no es un caso alcanzable en la práctica, pero si se llegara a esa línea de tiempo por algún otro medio, la celda de "Editar" queda vacía.
- [ ] El link navega a `node/<nid>/edit`, el formulario nativo de Drupal para `claim_transaction` — no a ningún formulario propio.

**`field_claim` en edición**

- [ ] En `node/%nid/edit` de una `claim_transaction` existente, `field_claim` se muestra con su valor actual pero **deshabilitado** — no se puede tipear ni autocompletar otro reclamo.
- [ ] Guardar el formulario (tocando otros campos) no modifica `field_claim`, incluso si el HTML llegara manipulado con otro valor (Drupal descarta el envío de un elemento `#disabled`, mismo mecanismo ya usado para `field_status` en SPEC 57).
- [ ] En `node/add/claim_transaction` (creación nativa, sin `nid`), `field_claim` sigue siendo un campo normal, editable y requerido — sin ningún cambio.

**Redirect tras editar**

- [ ] Guardar la edición de una `claim_transaction` existente redirige a `node/<claim_nid>/edit` del reclamo al que pertenece (leído de su propio `field_claim`), no a `node/<nid>` (destino nativo).
- [ ] Tras la redirección, la línea de tiempo del reclamo muestra los cambios guardados (estado, fecha de estado, comentario) de inmediato.
- [ ] Si `field_claim` no resuelve un reclamo válido (caso ya documentado como riesgo en SPEC 56/57: dato corrupto), el redirect cae al destino nativo de Drupal (`node/<nid>`) sin error.
- [ ] Crear una `claim_transaction` nueva desde `node/add/claim_transaction` (ruta nativa) **no** se ve afectada por este redirect — sigue su comportamiento nativo actual, sin cambios.

**Sincronización y permisos (no regresión de SPEC 56/57)**

- [ ] Editar el `field_status` de una transacción desde `node/%nid/edit` sigue sincronizando el `field_status` del reclamo padre, exactamente como ya garantiza SPEC 57 vía `hook_node_update()`.
- [ ] Ningún permiso nuevo se concede ni se revoca: `create`/`edit any claim_transaction content` siguen siendo exactamente los que otorgó SPEC 56.
- [ ] Un usuario sin `edit any claim_transaction content` (o sin `node_access('update')` sobre esa transacción puntual) recibe 403 al acceder a `node/%nid/edit` por URL directa, aunque conozca el `nid` — comportamiento nativo de Drupal, no tocado por este spec.

**No regresión / infra**

- [ ] `resources/*.resource.inc` no aparece en el diff.
- [ ] `hook_menu()` no cambia ninguna ruta — no se agrega ninguna ruta nueva en este spec.
- [ ] Las ramas existentes de `myapi_node_insert()`/`myapi_node_update()` (`reclamo`, `claim_transaction`, `pagos`, `boletin`, etc.) no cambian de comportamiento.
- [ ] `drush cc all` no reporta errores.
- [ ] `docs/claim-transaction-timeline.md` documenta el link "Editar", el bloqueo de `field_claim` y el redirect.

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

Cada uno, si entra, va en su propio spec.
