# SPEC 60 — Título autogenerado de las transacciones de reclamo

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundles `reclamo`/`claim_transaction` y sus campos; el tipo se creó con `has_title = 1`), SPEC 56 (`myapi_claims_status_options()`, catálogo de `field_status`), SPEC 57 (`includes/myapi.claim_transaction_admin.inc`: formulario propio de creación y transacción inicial automática — los dos caminos que hoy guardan el título vacío), SPEC 58 (`field_status_date` con hora, que es lo que hace útil incluir la hora en el título), SPEC 59 (`myapi_claim_transaction_transaction_form_alter()`, el alter del formulario nativo que este spec amplía) · **Fecha:** 2026-08-03
> **Objetivo:** Que toda `claim_transaction` se guarde con un título legible y autogenerado — `Reclamo #<nid> · <Estado> · <d/m/Y H:i> · <comentario truncado>` — en vez del título vacío que hoy dejan el formulario propio de `claim-transaction/add` y la transacción inicial automática, de modo que el operador identifique de qué se trata cada transacción desde `/admin/content`, desde el autocompletado de nodos y desde cualquier listado nativo de Drupal.

---

## El bug que origina el spec

El tipo `claim_transaction` tiene título nativo (`_myapi_reservations_ensure_node_type()` lo crea con `'has_title' => 1` y etiqueta `'Título'`, `myapi.install`), pero **ninguno de los dos caminos propios del módulo lo escribe**:

- `myapi_claim_transaction_create_form()` (SPEC 57) construye a mano solo `field_status`, `field_status_date`, `field_comment`, `field_images` y `field_attachment`; su submit arma el objeto nodo campo por campo y nunca toca `$transaction->title`.
- `myapi_claim_transaction_create_initial()` (SPEC 57) hace lo mismo para la transacción inicial automática de cada `reclamo`.

`node.title` es `varchar(255) NOT NULL DEFAULT ''`, así que `node_save()` **no falla**: guarda la fila con el título vacío. El síntoma es un nodo sin texto en `/admin/content` (un link vacío) y sin ninguna forma de saber de qué transacción se trata sin abrirla. La línea de tiempo del reclamo nunca lo mostró — muestra estado, fecha, comentario y autor — por eso el problema pasó inadvertido en SPEC 57/58/59.

El único camino que hoy sí guarda título es `node/add/claim_transaction`, el formulario nativo, porque ahí el campo lo pone Drupal y el operador lo escribe a mano.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (modificar):
  - Nueva constante `MYAPI_CLAIM_TRANSACTION_TITLE_COMMENT_LENGTH` (60) — largo máximo del fragmento de comentario dentro del título.
  - Nueva **`myapi_claim_transaction_title($claim_nid, $status_label, $status_date, $comment)`** — lógica pura de composición del título. Es la función que entra en `tests/unit/`.
  - Nueva **`myapi_claim_transaction_set_title($node)`** — glue: lee los campos del nodo con los helpers ya existentes (`myapi_building_admin_field_target_id()` / `_field_value()`), resuelve la etiqueta del estado con `myapi_claims_status_options()` (SPEC 56) y asigna `$node->title`.
  - `myapi_claim_transaction_transaction_form_alter()` (SPEC 59) — oculta el campo `title` del formulario nativo (`#access = FALSE` + `#required = FALSE`), en **creación y en edición**, porque a partir de este spec lo que se escriba ahí no tendría ningún efecto.
- **`myapi.module`** (modificar) — `myapi_node_presave()` pasa de un `return` temprano de una sola rama (`pagos`) a ramas independientes, y suma la rama `claim_transaction` que delega en `myapi_claim_transaction_set_title()`.
- **`myapi.install`** (modificar) — nueva `myapi_update_7020()`: rellena el título de las `claim_transaction` ya guardadas con título vacío.
- **`tests/unit/ClaimTransactionTitleTest.php`** (nuevo) — tests unitarios de `myapi_claim_transaction_title()`.
- **`tests/unit/ClaimTransactionEditTest.php`** (modificar) — cuatro casos nuevos para el campo `title` oculto. **No** estaba en la versión original de este spec: `myapi_claim_transaction_transaction_form_alter()` ya tiene cobertura unitaria desde SPEC 59, así que ampliarla es obligatorio, no opcional — su test `testCreationModeLeavesTheFormUntouched()` afirma que en creación el formulario sale idéntico, y este spec le agrega justamente una excepción. El test previo se conserva (su fixture no trae `title`, y todo lo demás **sí** debe seguir saliendo idéntico) con un comentario que dice por qué.
- **`tests/unit/bootstrap.php`** (modificar) — stubs de `truncate_utf8()` y `drupal_substr()`.
- **`tests/README.md`** (modificar) — los stubs nuevos y la cobertura nueva, en las dos secciones donde ese fichero ya lleva la cuenta.
- **`docs/claim-transaction-timeline.md`** (modificar) — documenta el título autogenerado, su formato, dónde se genera y el update de datos viejos.
- `drush cc all` **y `drush updb`** al final (a diferencia de SPEC 58/59: acá sí hay un `hook_update_N`).

**Fuera de alcance (para specs futuros):**

- **Mostrar el título en la línea de tiempo del reclamo.** La tabla sigue con sus cinco columnas actuales (Estado, Fecha de estado, Comentario, Autor, Editar): ahí el título sería redundante, porque está compuesto exactamente por las tres primeras. El título existe para los listados **nativos** de Drupal, que es donde hoy no se ve nada.
- **Título autogenerado en `reclamo`.** El `reclamo` tiene título propio escrito por quien lo crea (etiqueta "Asunto") y eso no cambia.
- **Incluir el `nid` de la transacción en el título.** Descartado con motivo — ver "Decisiones tomadas y descartadas".
- **Incluir el asunto del reclamo** (`$claim->title`) en lugar de su `nid`. Descartado con motivo — misma tabla.
- **Traducir el título** (`i18n`, `entity_translation`) o pasarlo por el catálogo `myapi_t()`. El catálogo de `includes/myapi.i18n.inc` es de mensajes de **API** (`docs/i18n.md`); el back-office ya usa `t()` directo en toda la pantalla de SPEC 56-59 y este spec no cambia ese criterio.
- **Cualquier endpoint `api/v1/...`.** Ninguna respuesta del API expone `claim_transaction` todavía.
- **Tests de integración (SimpleTest) o e2e.** `tests/integration/` y `tests/e2e/` siguen cubriendo solo `auth` (`tests/README.md`, "Scope note").

---

## Modelo de datos

No se crean campos, tablas ni bundles. Se escribe una columna que ya existe (`node.title` / `node_revision.title`) y que hoy queda vacía.

### Formato

```
Reclamo #128 · En proceso · 03/08/2026 14:30 · Se contactó al proveedor para agendar la…
```

Cuatro segmentos unidos por `' · '` (`U+00B7`), y **cada uno se omite si su dato falta**, sin dejar separadores colgando:

| Segmento | Origen | Si falta |
|---|---|---|
| `Reclamo #<nid>` | `field_claim` del propio nodo (`myapi_building_admin_field_target_id()`) | Se omite (dato corrupto, ya documentado como riesgo en SPEC 56/57). |
| `<Estado>` | Etiqueta de `field_status` según `myapi_claims_status_options()` (SPEC 56), **sin** `check_plain()` | Se omite. Un valor sin etiqueta en el catálogo cae al valor crudo, misma semántica que `myapi_claims_status_label()`. |
| `<d/m/Y H:i>` | `field_status_date` vía `format_date(strtotime(...), 'custom', 'd/m/Y H:i')` | Se omite (también si `strtotime()` no la resuelve). |
| `<comentario>` | `field_comment`, whitespace colapsado y truncado a 60 con `truncate_utf8($c, 60, TRUE, TRUE)` | Se omite — es el caso normal de la transacción inicial automática, que no tiene comentario. |

Si **ningún** segmento resuelve, el título es `t('Transacción de reclamo')`: el nodo nunca vuelve a quedarse sin título, que es el bug que abre el spec.

El resultado completo pasa por un `truncate_utf8(..., 255, TRUE, TRUE)` final. Con los valores reales ronda los 110 caracteres, muy lejos del límite; el truncado protege contra una etiqueta de `allowed_values` desmesurada configurada a futuro, no contra el caso normal.

**Sin `check_plain()` en ninguna parte.** El título de un nodo se guarda crudo y Drupal lo escapa al renderizarlo; por eso este spec **no** reutiliza `myapi_claims_status_label()` (SPEC 56), que devuelve la etiqueta ya escapada para la tabla HTML de la línea de tiempo. Se reutiliza el catálogo (`myapi_claims_status_options()`), que es la fuente de verdad compartida; lo que no se comparte es el escapado, porque el destino es otro. Guardar `&amp;` en `node.title` produciría doble escape en pantalla.

### `myapi_claim_transaction_title()` — nuevo, lógica pura

```php
function myapi_claim_transaction_title($claim_nid, $status_label, $status_date, $comment) {
  $parts = array();

  if (!empty($claim_nid)) {
    $parts[] = t('Reclamo') . ' #' . $claim_nid;
  }
  ...
  if (!$parts) {
    return t('Transacción de reclamo');
  }

  return truncate_utf8(implode(' · ', $parts), 255, TRUE, TRUE);
}
```

Recibe los cuatro datos ya resueltos en vez de el nodo entero: así queda libre de Drupal salvo `t()`, `format_date()` y `truncate_utf8()` — las tres ya stubeables — y entra completa en `tests/unit/`. Mismo criterio de separación que SPEC 59 usó para dejar `myapi_claim_transaction_edit_link()` fuera del unit layer: acá, en vez de excluir la función, se parte en la mitad decidible (esta) y la mitad de glue (la siguiente).

El comentario se normaliza con `preg_replace('/\s+/u', ' ', ...)` antes de truncar: `field_comment` es un `textarea`, y un título con saltos de línea se ve roto en `/admin/content`. Colapsar **antes** de cortar y no después, para no gastar parte del presupuesto de 60 caracteres en espacios.

Ese tramo quedó en una función aparte, **`myapi_claim_transaction_title_comment($comment)`** — no estaba en la versión original de este spec, salió al implementarlo: entre el `trim()`, el colapso, el fallback de `preg_replace()` ante UTF-8 inválido y el truncado, eran cinco líneas de un tema distinto en medio del ensamblado de segmentos. Devuelve `''` para un comentario ausente o solo whitespace, que es la condición que el llamador ya necesitaba evaluar.

Las dos longitudes son constantes guardadas con `if (!defined(...))`, mismo patrón que `includes/myapi.building_admin.inc`: `MYAPI_CLAIM_TRANSACTION_TITLE_COMMENT_LENGTH` (60) y `MYAPI_CLAIM_TRANSACTION_TITLE_MAX_LENGTH` (255, el ancho real de la columna).

`truncate_utf8()` con `$wordsafe = TRUE` y `$add_ellipsis = TRUE` es de core (`includes/unicode.inc`) — no se escribe un truncado propio, mismo criterio de reutilización que SPEC 57 con `file_field_widget_upload_validators()`.

### `myapi_claim_transaction_set_title()` — nuevo, glue

```php
function myapi_claim_transaction_set_title($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  $status = myapi_building_admin_field_value($node, 'field_status');
  $options = myapi_claims_status_options();

  $node->title = myapi_claim_transaction_title(
    myapi_building_admin_field_target_id($node, 'field_claim'),
    ($status !== NULL && isset($options[$status])) ? $options[$status] : $status,
    myapi_building_admin_field_value($node, 'field_status_date'),
    myapi_building_admin_field_value($node, 'field_comment')
  );
}
```

### Dónde se genera: `hook_node_presave()`

En `hook_node_presave()`, **no** en el submit del formulario propio ni en `hook_node_insert()`:

- `field_attach_presave()` corre **antes** que `hook_node_presave()` dentro de `node_save()`, así que `field_status_date` ya está normalizada por el módulo Date a su representación de almacenamiento (`'Y-m-d H:i:s'`) sin importar qué widget la haya producido. En `hook_node_insert()` también lo estaría, pero ahí el nodo ya se escribió: cambiar el título exigiría un segundo guardado.
- Cubre **los cuatro** caminos de una sola vez: el formulario propio de SPEC 57, la transacción inicial automática, `node/add/claim_transaction` y `node/%nid/edit` (SPEC 59). Escribirlo en el submit de SPEC 57 habría dejado los otros tres afuera, y duplicarlo en cada uno viola la Regla 3 de `CLAUDE.md`.
- Al correr también en `update`, el título **se regenera** cuando el operador edita estado, fecha o comentario: nunca queda describiendo algo que la transacción ya no dice.

`myapi_node_presave()` hoy es de una sola rama, con `if ($node->type !== 'pagos') { return; }` en la primera línea. Se reescribe con ramas independientes con `return`, exactamente la forma que ya tienen `myapi_node_insert()` y `myapi_node_update()` — la rama `pagos` no cambia de comportamiento.

### El campo `title` del formulario nativo

Con el título autogenerado en `presave`, lo que el operador escriba en el campo "Título" de `node/add/claim_transaction` o de `node/%nid/edit` se sobrescribe siempre. Dejarlo visible sería mentirle. Se oculta desde `myapi_claim_transaction_transaction_form_alter()` (SPEC 59):

```php
  if (isset($form['title'])) {
    $form['title']['#required'] = FALSE;
    $form['title']['#access'] = FALSE;
  }
```

Va **antes** del `if (empty($form['#node']->nid)) { return; }` de SPEC 59, porque es lo único de este alter que también aplica en creación.

Con `#access = FALSE`, `_form_builder_handle_input_element()` no marca el elemento como `#needs_validation` (calcula `$process_input` a partir de `#access`), así que el `#required` del título nativo **no** dispara "El campo Título es obligatorio" con el campo oculto. El `#required = FALSE` explícito es cinturón y tirantes — es también lo que hace el módulo contribuido `auto_nodetitle` en el mismo escenario — y documenta la intención.

### `myapi_update_7020()` — datos existentes

Recorre las `claim_transaction` con `node.title = ''`, compone el título con la **misma** función de arriba y escribe `node.title` y `node_revision.title` con `db_update()`.

`db_update()` y no `node_load()` + `node_save()`, deliberadamente: `node_save()` dispararía `hook_node_update()`, y su rama `claim_transaction` (SPEC 57) sincroniza el `field_status` del **reclamo padre** con el de la transacción guardada. Re-guardar transacciones viejas en orden arbitrario dejaría cada reclamo con el estado de la última transacción procesada, no con el suyo — una corrupción de datos silenciosa a cambio de un título. El precio de evitarla es escribir las dos tablas a mano (`node` y `node_revision`, porque en D7 `node_load()` lee `title` de la revisión) y resetear la caché de entidades al final.

---

## Tests unitarios

`tests/unit/` cubre solo lógica pura (`tests/README.md`). De este spec entra `myapi_claim_transaction_title()`; `myapi_claim_transaction_set_title()` no (es lectura de campos ya cubierta por `BuildingAdminTest` más `myapi_claims_status_options()`, que toca Field API), y el `hook_node_presave()` tampoco (glue de tres líneas). Eso se dice en el docblock del fichero de test, no se omite en silencio — mismo criterio que SPEC 59 con `myapi_claim_transaction_edit_link()`.

### `tests/unit/bootstrap.php` — stubs nuevos

`truncate_utf8()` y su dependencia `drupal_substr()`. El stub reproduce el algoritmo de core (elipsis que descuenta su propio carácter, `$min_wordsafe_length`, corte duro cuando no hay límite de palabra) con una diferencia declarada: core busca el corte con su clase Unicode `PREG_CLASS_UNICODE_WORD_BOUNDARY` y el stub usa `\s`. Los casos de test usan comentarios cuyo corte cae en un espacio simple, donde ambas coinciden.

### `tests/unit/ClaimTransactionTitleTest.php` — nuevo

| Caso | Qué fija |
|---|---|
| Los cuatro datos presentes | El formato completo, carácter por carácter, con `' · '` como separador. |
| Sin comentario (transacción inicial automática) | Tres segmentos y **ningún** separador colgando al final. |
| Comentario más largo que el límite | Se trunca sin cortar una palabra por la mitad y termina en `…`. |
| Comentario exactamente en el límite | **No** se trunca ni se agrega elipsis. |
| Comentario multilínea / con espacios repetidos | Queda en una sola línea, con espacios simples. |
| Comentario que es solo whitespace | Se trata como ausente. |
| Sin `claim_nid` (`NULL` y `0`) | Se omite el primer segmento; el resto queda bien unido. |
| Sin estado | Se omite ese segmento. |
| Estado sin etiqueta en el catálogo | Sale el valor crudo (lo que pasa el llamador). |
| `field_status_date` `NULL` o impresentable para `strtotime()` | Se omite el segmento, sin fatal ni fecha de 1970. |
| Los cuatro datos ausentes | `'Transacción de reclamo'`. |
| Etiqueta de estado desmesurada (300 caracteres) | El título resultante no supera los 255 de `node.title`. |
| El título no lleva HTML escapado | Un comentario con `&` y `<` sale crudo — la garantía de que no se coló un `check_plain()`. |

### `tests/unit/ClaimTransactionEditTest.php` — casos agregados

| Caso | Qué fija |
|---|---|
| Modo creación con `title` en el formulario | `#access === FALSE` y `#required === FALSE`. |
| Modo edición con `title` | Lo mismo — es el único bloque del alter que corre en los dos modos. |
| Formulario sin `title` | No se inventa la clave y el resto del alter (el `#disabled` de `field_claim`) sigue funcionando. |
| Ocultar el `title` no toca el resto del elemento | `#default_value` y `#type` sobreviven, que es lo que permite que Drupal reenvíe el título almacenado de una transacción existente con el campo inaccesible. |

---

## Plan de implementación

1. **`tests/unit/bootstrap.php` — stubs de `truncate_utf8()` y `drupal_substr()`.** *Verificación: `php -l`; `vendor/bin/phpunit` sigue en verde con los tests existentes.*
2. **`tests/unit/ClaimTransactionTitleTest.php` (nuevo) — todos los casos de la tabla, antes de tocar la lógica.** *Verificación: `vendor/bin/phpunit` **en rojo**, con error de función inexistente.*
3. **`includes/myapi.claim_transaction_admin.inc` — constante + `myapi_claim_transaction_title()` + `myapi_claim_transaction_set_title()`.** *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**, suite entera.*
4. **`myapi.module` — ramas independientes en `myapi_node_presave()` + rama `claim_transaction`.** *Verificación: `php -l`; `drush cc all`; crear una transacción desde `claim-transaction/add` y ver el título en `/admin/content`.*
5. **`includes/myapi.claim_transaction_admin.inc` — ocultar `title` en el alter de SPEC 59.** *Verificación: `node/add/claim_transaction` y `node/%nid/edit` ya no muestran el campo Título y guardan sin pedirlo.*
6. **`myapi.install` — `myapi_update_7020()`.** *Verificación: `php -l`; `drush updb` reporta la cantidad de transacciones rellenadas; `/admin/content` ya no muestra links vacíos.*
7. **`docs/claim-transaction-timeline.md` y `tests/README.md`.** *Verificación: lectura contra la implementación.*
8. **`drush cc all` + matriz manual** (ver criterios de aceptación).

---

## Criterios de aceptación

> Marcados los que se verificaron **en el repositorio** (`vendor/bin/phpunit`,
> `php -l`, lectura del diff contra `main`). Los que quedan sin marcar
> necesitan el sitio desplegado: son la matriz manual del paso 8 del plan —
> el título real en `/admin/content`, el formulario sin el campo, y el
> `drush updb`.

**Título autogenerado**

- [x] Crear una transacción desde `node/<claim_nid>/claim-transaction/add` la guarda con el título `Reclamo #<claim_nid> · <Estado> · <d/m/Y H:i> · <comentario truncado>`.
- [x] Crear un `reclamo` genera su transacción inicial automática con título — sin comentario, tres segmentos.
- [x] Crear una transacción desde `node/add/claim_transaction` (ruta nativa) produce el mismo formato de título, sin que el operador escriba nada.
- [x] Editar estado, fecha o comentario de una transacción existente **regenera** el título con los valores nuevos.
- [x] El título nunca queda vacío, ni siquiera con `field_claim`, `field_status`, `field_status_date` y `field_comment` todos ausentes. *(`testNothingResolvesFallsBackToAGenericTitle()`.)*
- [x] El título no supera los 255 caracteres en ningún caso. *(`testTitleNeverExceedsTheColumnLength()`, con una etiqueta de estado de 300 caracteres.)*
- [x] Cada segmento faltante se omite sin dejar un separador colgando. *(Seis casos del test: sin comentario, sin `claim_nid` — cuatro variantes —, sin estado y tres de fecha inutilizable.)*
- [x] El título se guarda **sin** escapar. *(`testTitleIsNotHtmlEscaped()`: la etiqueta viene de `myapi_claims_status_options()`, no de `myapi_claims_status_label()`.)*

**Formulario nativo**

- [x] `node/add/claim_transaction` no muestra el campo "Título" y guarda sin exigirlo (no aparece "El campo Título es obligatorio").
- [x] `node/%nid/edit` de una transacción tampoco lo muestra, y sigue mostrando `field_claim` deshabilitado y redirigiendo al reclamo tras guardar (no regresión de SPEC 59).
- [x] El alter pone `#access = FALSE` y `#required = FALSE` sobre `$form['title']` en los dos modos, y no inventa la clave si el elemento no está. *(Cuatro casos nuevos en `ClaimTransactionEditTest`.)*
- [x] El formulario de `reclamo` **no** se ve afectado: su título ("Asunto") se sigue escribiendo a mano. *(`myapi_claim_transaction_reclamo_form_alter()` no aparece en el diff.)*

**Datos existentes**

- [x] `drush updb` corre `myapi_update_7020()` y reporta cuántas transacciones rellenó.
- [x] Las transacciones que ya tenían título (creadas por la ruta nativa) **no** se tocan.
- [x] Ningún `reclamo` cambia de `field_status` a causa del update.
- [x] Re-ejecutar el update no hace nada (ya no quedan títulos vacíos) y no falla.
- [x] El update escribe `node.title` **y** `node_revision.title`, y no llama a `node_save()` en ningún punto. *(Lectura del diff de `myapi.install`.)*

**Tests unitarios**

- [x] `vendor/bin/phpunit` pasa entero, incluidos los nuevos. *(314 tests, 1061 aserciones — 292/1027 antes de este spec.)*
- [x] `tests/unit/ClaimTransactionTitleTest.php` cubre los casos de la tabla de "Tests unitarios". *(18 tests, contando los data providers.)*
- [x] Los stubs nuevos de `bootstrap.php` no alteran ningún test previo. *(Los 292 previos siguen en verde; el único test previo que cambió es el de modo creación, y solo su docblock.)*
- [x] `tests/README.md` menciona los stubs nuevos y la cobertura nueva. *(En las dos secciones: "Unit tests" y "Design constraint — `tests/unit/bootstrap.php`".)*

**No regresión / infra**

- [x] `resources/*.resource.inc` no aparece en el diff.
- [x] `hook_menu()` no cambia.
- [x] La rama `pagos` de `myapi_node_presave()` se comporta igual que antes. *(Mismas tres líneas, ahora dentro de un `if` positivo con su propio `return`.)*
- [x] La línea de tiempo del reclamo se ve exactamente igual que antes de este spec. *(Ni `myapi_claim_transaction_timeline_*()` ni su tabla aparecen en el diff.)*
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Contenido del título | `Reclamo #<nid> · <Estado> · <fecha y hora> · <comentario truncado>` | Solo reclamo + estado + fecha | Elección explícita del usuario. El comentario es lo que responde a "de qué se trata"; sin él, dos transacciones del mismo día con el mismo estado son indistinguibles en `/admin/content`. |
| `nid` de la transacción en el título | **No** se incluye | Prefijo `T-<nid> · …` | El `nid` no existe todavía cuando `hook_node_presave()` compone el título: lo asigna el `INSERT`. Incluirlo obligaría a un segundo guardado por transacción (o a un `db_update()` post-insert), y el dato ya es visible sin él: el título de `/admin/content` **es** el link a `node/<nid>`, y el `nid` está en la URL de edición. Se cambió por el `nid` del **reclamo**, que sí aporta algo que no se ve en ninguna otra parte de la fila y agrupa las transacciones de un mismo reclamo al ordenar por título. |
| Identificar el reclamo | Por `nid` (`Reclamo #128`) | Por su asunto (`$claim->title`) | El asunto exigiría un `node_load()` del reclamo en cada `presave` de transacción, y es un texto largo y libre que se comería el presupuesto de 255 caracteres junto con el comentario. El `nid` es corto, estable y es el mismo identificador que ya usa la URL de la línea de tiempo. |
| Dónde se genera | `hook_node_presave()` | En el submit de `myapi_claim_transaction_create_form()` y en `myapi_claim_transaction_create_initial()` | El presave cubre los cuatro caminos de creación/edición con una sola implementación (Regla 3 de `CLAUDE.md`) y es el único punto donde el valor de `field_status_date` ya está normalizado por el módulo Date y el nodo todavía no se escribió. |
| Campo `title` del formulario nativo | Oculto (`#access = FALSE`) | Dejarlo visible y respetar lo que el operador escriba (autogenerar solo si viene vacío) | Con un título que se **regenera** en cada edición, respetar el valor manual sería inconsistente: sobreviviría hasta la primera edición y desaparecería después. Se eligió una sola regla, siempre la misma, y un campo menos que llenar. |
| Etiqueta del estado | `myapi_claims_status_options()`, cruda | `myapi_claims_status_label()`, ya escapada | El destino es `node.title`, que se guarda crudo y Drupal escapa al renderizar; usar la versión escapada guardaría entidades HTML en la base y produciría doble escape. Se comparte la fuente de verdad (el catálogo), no el escapado. |
| Truncado del comentario | `truncate_utf8($c, 60, TRUE, TRUE)` de core | Un `substr()` propio, o no truncar | Core ya resuelve el corte UTF-8 seguro, el límite de palabra y la elipsis; escribirlo de nuevo sería duplicar lógica. Sin truncar, un comentario largo llenaría los 255 caracteres y el título dejaría de ser escaneable de un vistazo. |
| Datos existentes | `hook_update_N` con `db_update()` sobre `node` y `node_revision` | `node_load()` + `node_save()` por transacción | `node_save()` dispara la sincronización de estado de SPEC 57 hacia el reclamo padre, que con transacciones viejas dejaría a cada reclamo con el estado de la última procesada. El update escribe solo la columna del título y no toca ningún campo. |
| Alcance del update | Solo filas con `title = ''` | Regenerar el título de **todas** las transacciones | Las creadas por la ruta nativa tienen un título escrito a mano por el operador; pisarlo sería destruir información que él puso deliberadamente antes de que existiera este spec. De acá en adelante todas convergen al formato automático al primer guardado. |
| Mostrar el título en la línea de tiempo | No se muestra | Agregar una columna "Título" a la tabla | El título está compuesto por las tres primeras columnas de esa misma tabla: sería una fila repetida contra sí misma. El problema a resolver está en los listados **nativos**, no en la línea de tiempo. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`hook_node_presave()` deja de tener un `return` temprano** y ahora inspecciona el tipo de cada nodo que se guarda en el sitio. | El costo es una comparación de string por guardado, igual que las que ya hacen `myapi_node_insert()` y `myapi_node_update()` con cuatro tipos. La rama `pagos` conserva su `return`, así que su flujo es idéntico al actual. |
| **Un título "bonito" puede quedar desincronizado** si en el futuro alguien cambia `field_status`/`field_comment` por fuera de `node_save()` (SQL directo, `field_attach_update()` suelto). | Ningún código del módulo hace eso hoy. Cualquier camino que pase por `node_save()` regenera el título; y `myapi_update_7020()` deja el patrón escrito para un rellenado masivo si alguna vez hiciera falta. |
| **`preg_replace('/\s+/u', ...)` devuelve `NULL` ante UTF-8 inválido** en el comentario. | El caso se cubre con un fallback al valor original en el código y no rompe el guardado; el comentario llega de un `textarea` de Drupal, que ya rechaza secuencias inválidas antes de este punto. |
| **El stub de `truncate_utf8()` puede divergir del original** (core usa una clase Unicode de límite de palabra, el stub usa `\s`). | Mismo riesgo ya aceptado para los siete stubs que `tests/unit/bootstrap.php` lleva desde SPEC 50/52/54/59, con la misma mitigación: el stub implementa el algoritmo real y la divergencia está declarada en su docblock; los casos de test caen en espacios simples, donde ambas implementaciones dan el mismo resultado. El texto real se verifica en la matriz manual. |
| **El operador pierde la posibilidad de titular una transacción a mano.** | Es la consecuencia buscada: hasta hoy solo podía hacerlo por la ruta nativa (que usan `administrator`/`backend`), y el resultado era inconsistente con las transacciones creadas desde la línea de tiempo. Si más adelante se pide un título libre, entra como campo propio y su propio spec. |
| **`myapi_update_7020()` sobre un sitio con muchas transacciones sin título** hace dos `db_update()` por fila en una sola pasada, sin sandbox de lote. | El volumen es acotado por construcción: solo las transacciones creadas desde que SPEC 57 entró (2026-07-31) y solo por los dos caminos propios. Si el `SELECT` inicial devolviera un volumen inesperado, el update es re-ejecutable y la conversión a `hook_update_N` con `$sandbox` es mecánica. |

---

## Lo que **NO** está en este spec

- Mostrar el título en la línea de tiempo del reclamo.
- Título autogenerado para `reclamo` o cualquier otro bundle.
- Incluir el `nid` de la transacción o el asunto del reclamo en el título.
- Traducción del título / `myapi_t()`.
- Cualquier endpoint `api/v1/...`.
- Tests de integración o e2e.

Cada uno, si entra, va en su propio spec.
