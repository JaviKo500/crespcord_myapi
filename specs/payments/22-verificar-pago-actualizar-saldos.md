# 22 — Verificar pago: actualizar saldos y completar (`hook_node_presave`)

- **Estado:** Implemented
- **Fecha:** 2026-07-14
- **Dependencias:**
  - `20-register-payment` (Implemented) — crea los nodos `pagos` con `field_estado_pago = "Pendiente de verificar"`, que es el estado inicial desde el cual dispara este hook. Reutiliza los mismos machine names (`field_vivienda`, `field_valor`, `field_estado_pago`).
  - `10-units-saldo-actual` (Implemented) — origen del campo `field_saldo_actual` (bundle `vivienda`, `decimal(10,4)`) que este hook descuenta.
  - `17-condominium-summary` (Implemented) — origen del campo `field_saldo_caja` (bundle `condominio`, `decimal(10,2)`) que este hook incrementa, y de la relación `vivienda → field_condominio → condominio`.
- **Objetivo:** Un `hook_node_presave` en `myapi` que, cuando un nodo `pagos` transita de `field_estado_pago` `"Pendiente de verificar"` a `"Nuevo"`, descuenta `field_valor` del `field_saldo_actual` de su vivienda, lo suma al `field_saldo_caja` del condominio, fija el pago en `"Completado"` (sin llegar a persistir `"Nuevo"`) y cancela las tareas pendientes de `rules_scheduler` (recordatorio y penalizaciones) de esa vivienda — replicando la regla `rules_actualizar_saldo_pago` pero disparada por **verificación** en vez de por **creación**.

---

## Alcance

### Dentro de este spec

- **`myapi.module`** (modificar) — implementar `myapi_node_presave($node)` como *glue* mínimo: descarta todo lo que no sea un nodo `pagos` y delega en el helper de `includes/`. Sin lógica de negocio en el módulo.
- **`includes/myapi.payment_workflow.inc`** (nuevo) — toda la lógica del flujo:
  - `myapi_payment_is_verification_transition($node)` — detecta la transición exacta `"Pendiente de verificar"` → `"Nuevo"` comparando `$node->original` contra el `$node` entrante.
  - `myapi_payment_apply_verification($node)` — orquesta: valida precondiciones (vivienda/condominio/monto), descuenta el saldo de la vivienda, incrementa la caja del condominio, fija el pago en `"Completado"` y cancela las tareas programadas.
  - Helpers internos para cargar/guardar la vivienda y el condominio, y para cancelar tareas de `rules_scheduler`.
- **`myapi.info`** (modificar) — agregar `files[] = includes/myapi.payment_workflow.inc`.
- **`docs/payment-workflow.md`** (nuevo) — documentar el comportamiento del hook (disparador, precondiciones, efectos sobre saldos, estado final, tareas canceladas), ya que no hay endpoint pero el proyecto exige documentar todo cambio de comportamiento.

### Fuera de este spec

- **Un endpoint REST de verificación** (`PUT /api/v1/payments/%/verify` o similar) — el disparo es por `node_save` (formulario admin o código); no se crea ninguna ruta. Si más adelante hace falta el endpoint, va en otro spec y este hook lo cubrirá automáticamente.
- **Rechazar / anular un pago** (transición a un estado de rechazo, revertir saldos) — solo se cubre la verificación exitosa `"Pendiente de verificar"` → `"Nuevo"` → `"Completado"`.
- **Reajustar saldos al editar un pago ya `"Completado"`** — el hook solo reacciona a la transición de verificación; editar monto/vivienda de un pago completado no re-toca saldos (decisión 3).
- **Crear o reprogramar** tareas de `rules_scheduler` (los componentes `rules_recordatorio_pago`, `rules_recalcular_con_penalizacion*`) — este spec solo **cancela** tareas pendientes; la creación de esos recordatorios/penalizaciones sigue en el sistema de Rules existente.
- **Migrar los recordatorios/penalizaciones a código custom** — quedan en `rules_scheduler`; este hook solo borra sus filas pendientes por `config` + `identifier`.
- **Otras reglas de Rules** distintas de `rules_actualizar_saldo_pago` — este spec replica únicamente esa.
- **Interpretación de negocio del signo del saldo** — se aplica la aritmética tal cual (`saldo − valor`, `caja + valor`), sin normalizar signos (mismo criterio que specs 10 y 17).

---

## Modelo de datos

Este spec **no introduce tablas propias** (`myapi_*`), no hay `hook_schema()` ni cambios en `myapi.install`. Solo lee y escribe estructuras existentes de Drupal a través de la Field API / `node_save()`, y borra filas de la tabla `rules_scheduler` (propiedad del módulo contribuido `rules_scheduler`, no de `myapi`).

### Disparador — detección de la transición

Se compara el estado **anterior** contra el **entrante** dentro de `hook_node_presave` (donde `$node->original` ya está cargado por `node_save()`):

| Origen | Lectura | Valor que dispara |
|---|---|---|
| `$node->original->field_estado_pago[LANGUAGE_NONE][0]['value']` | estado anterior (en BD) | debe ser exactamente `"Pendiente de verificar"` |
| `$node->field_estado_pago[LANGUAGE_NONE][0]['value']` | estado entrante | debe ser exactamente `"Nuevo"` |

- Solo se actúa cuando **ambas** condiciones se cumplen. Cualquier otra combinación (incluido `"Completado"` → cualquier cosa, o un `node_insert` sin `original`) → el hook no hace nada.
- Un `node_insert` (creación) no tiene `$node->original`, así que nunca dispara: este hook es **solo** para la verificación por actualización.

### Nodo `pagos` (el nodo que se guarda)

| Campo | Rol | Acción del hook |
|---|---|---|
| `field_estado_pago` (list_text) | disparador y resultado | Se **reescribe a `"Completado"`** en el `$node` entrante (en presave), de modo que `"Nuevo"` nunca se persiste; se guarda directamente `"Completado"`. |
| `field_vivienda` (entity ref → nodo) | precondición | Debe existir y apuntar a un nodo de bundle `vivienda` publicado; si no, no se hace nada (decisión 6). |
| `field_valor` (decimal) | sumando | Monto a mover. Debe ser numérico y `> 0`; si no, no se hace nada (decisión 8). |

### Nodo `vivienda` (referenciado, se carga y se re-guarda)

| Campo | Tipo | Acción del hook |
|---|---|---|
| `field_saldo_actual` (decimal(10,4)) | saldo de la unidad | `nuevo_saldo = (saldo_actual ?? 0) − field_valor`. Se escribe en el nodo vivienda. |
| `field_condominio` (entity ref → nodo) | precondición | Debe existir y apuntar a un nodo bundle `condominio`; si no, no se hace nada (decisión 6). |

- La vivienda se guarda con `$vivienda->revision = 1` (nueva revisión, decisión 9).

### Nodo `condominio` (referenciado, se carga y se re-guarda)

| Campo | Tipo | Acción del hook |
|---|---|---|
| `field_saldo_caja` (decimal(10,2)) | caja del condominio | `nueva_caja = (saldo_caja ?? 0) + field_valor`. Se escribe en el nodo condominio. |

- El condominio se guarda con `$condominio->revision = 1` (nueva revisión, decisión 9).

### Tareas programadas — tabla `rules_scheduler`

Las 4 `schedule_delete` de la regla se replican borrando las filas pendientes de esa vivienda. La tabla `rules_scheduler` identifica cada tarea por el nombre del componente (`config`) y el identificador (`identifier`):

| `config` (componente) | `identifier` (con `{nid}` = nid de la **vivienda**) |
|---|---|
| `rules_recordatorio_pago` | `recordatorio {nid}` |
| `rules_recalcular_con_penalizacion` | `penalizacion 10 {nid}` |
| `rules_recalcular_con_penalizacion_15` | `penalizacion 15 {nid}` |
| `rules_recalcular_con_penalizacion_31` | `penalizacion 31 {nid}` |

- Borrado: `db_delete('rules_scheduler')->condition('config', $componente)->condition('identifier', $identifier)->execute();` por cada fila.
- El `{nid}` es el nid de la **vivienda** referenciada por el pago (`node:field-vivienda:nid` en la regla original), no el del pago.
- Si no hay tarea pendiente que coincida, el `db_delete` simplemente no borra nada (idempotente, sin error).

### Precisión y recursión

- **Precisión:** la aritmética se hace en PHP (`float`) y se almacena en el `decimal` del campo; se acepta la imprecisión de punto flotante ya asumida en specs 10 y 17. No se redondea salvo lo que imponga el `decimal(10,4)` / `decimal(10,2)`.
- **Recursión:** el hook filtra por `$node->type === 'pagos'` en la primera línea; al re-guardar los nodos `vivienda` y `condominio` el hook vuelve a invocarse pero retorna de inmediato (no son `pagos`), así que no hay recursión.

---

## Plan de implementación

1. **Crear `includes/myapi.payment_workflow.inc` con el esqueleto** y registrarlo en `myapi.info` (`files[] = includes/myapi.payment_workflow.inc`). Definir las constantes de estado al inicio del archivo:
   ```php
   define('MYAPI_PAYMENT_STATUS_PENDING',   'Pendiente de verificar');
   define('MYAPI_PAYMENT_STATUS_TRIGGER',   'Nuevo');
   define('MYAPI_PAYMENT_STATUS_COMPLETED', 'Completado');
   ```
   Tras esto, `drush cc all`. Estado funcional: el archivo existe y carga, aún sin hook.

2. **Helper de lectura de valor de campo** en el mismo archivo: `myapi_payment_field_value($node, $field)` → devuelve `$node->{$field}[LANGUAGE_NONE][0]['value']` o `NULL` si no existe. Evita repetir el acceso al array de la Field API.

3. **`myapi_payment_is_verification_transition($node)`** — devuelve `TRUE` solo si:
   - `isset($node->original)` (es una actualización, no un insert), **y**
   - el valor de `field_estado_pago` en `$node->original` es `MYAPI_PAYMENT_STATUS_PENDING`, **y**
   - el valor de `field_estado_pago` en `$node` es `MYAPI_PAYMENT_STATUS_TRIGGER`.

4. **`hook_node_presave()` en `myapi.module`:**
   ```php
   function myapi_node_presave($node) {
     if ($node->type !== 'pagos') {
       return;
     }
     module_load_include('inc', 'myapi', 'includes/myapi.payment_workflow');
     if (myapi_payment_is_verification_transition($node)) {
       myapi_payment_apply_verification($node);
     }
   }
   ```
   Tras esto, `drush cc all`. Estado funcional: el hook dispara pero `apply_verification` aún es un *stub*.

5. **Precondiciones en `myapi_payment_apply_verification($node)`** (si alguna falla → `return` sin tocar nada; el pago conserva su estado entrante y **no** se fuerza a `"Completado"`, decisiones 6 y 8):
   1. `$amount = myapi_payment_field_value($node, 'field_valor')`; requerir `is_numeric($amount) && (float) $amount > 0`.
   2. `$vivienda_nid = $node->field_vivienda[LANGUAGE_NONE][0]['target_id']` (si no existe → `return`); `node_load($vivienda_nid)`; requerir nodo existente con `type === 'vivienda'` y publicado.
   3. `$condominio_nid = $vivienda->field_condominio[LANGUAGE_NONE][0]['target_id']` (si no existe → `return`); `node_load($condominio_nid)`; requerir nodo existente con `type === 'condominio'`.

6. **Aplicar saldos** (solo si pasaron todas las precondiciones):
   1. `$saldo = (float) (myapi_payment_field_value($vivienda, 'field_saldo_actual') ?? 0);` → `$vivienda->field_saldo_actual[LANGUAGE_NONE][0]['value'] = $saldo - (float) $amount;` → `$vivienda->revision = 1;` → `node_save($vivienda);`
   2. `$caja = (float) (myapi_payment_field_value($condominio, 'field_saldo_caja') ?? 0);` → `$condominio->field_saldo_caja[LANGUAGE_NONE][0]['value'] = $caja + (float) $amount;` → `$condominio->revision = 1;` → `node_save($condominio);`
   3. **Fijar el estado del pago:** `$node->field_estado_pago[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_STATUS_COMPLETED;` (se modifica el `$node` en presave; el propio `node_save()` en curso lo persistirá directamente como `"Completado"`, sin segundo guardado ni recursión).

7. **Cancelar las tareas programadas** — `myapi_payment_cancel_scheduled_tasks($vivienda_nid)`:
   ```php
   $tasks = array(
     'rules_recordatorio_pago'              => 'recordatorio ' . $vivienda_nid,
     'rules_recalcular_con_penalizacion'    => 'penalizacion 10 ' . $vivienda_nid,
     'rules_recalcular_con_penalizacion_15' => 'penalizacion 15 ' . $vivienda_nid,
     'rules_recalcular_con_penalizacion_31' => 'penalizacion 31 ' . $vivienda_nid,
   );
   foreach ($tasks as $config => $identifier) {
     db_delete('rules_scheduler')
       ->condition('config', $config)
       ->condition('identifier', $identifier)
       ->execute();
   }
   ```
   Se llama **después** de guardar los saldos, dentro de `apply_verification`.

8. **Documentar en `docs/payment-workflow.md`** — disparador (transición de estado), precondiciones que abortan sin efecto, efectos (saldo vivienda −, caja condominio +, estado → `"Completado"`, revisiones nuevas), y la lista de las 4 tareas `rules_scheduler` que se cancelan con su patrón de `identifier`.

9. **Aplicar y verificar** — `drush cc all` y prueba manual: crear un pago (spec 20, queda en `"Pendiente de verificar"`), editarlo a `"Nuevo"` y guardar; verificar en BD que el pago quedó `"Completado"`, que `field_saldo_actual` de la vivienda bajó por el monto, que `field_saldo_caja` del condominio subió por el monto, que hay revisiones nuevas y que las filas de `rules_scheduler` de esa vivienda desaparecieron.

---

## Criterios de aceptación

**Disparo correcto**
- [x] Guardar un pago editándolo de `field_estado_pago` `"Pendiente de verificar"` a `"Nuevo"` deja el pago persistido como `"Completado"` (nunca queda `"Nuevo"` en BD).
- [x] Tras la verificación, `field_saldo_actual` de la vivienda referenciada = saldo anterior **−** `field_valor` del pago.
- [x] Tras la verificación, `field_saldo_caja` del condominio de esa vivienda = caja anterior **+** `field_valor` del pago.
- [x] La vivienda y el condominio quedan con una **revisión nueva** cada uno tras la actualización.
- [x] Las filas de `rules_scheduler` con `identifier` `recordatorio {nid}`, `penalizacion 10 {nid}`, `penalizacion 15 {nid}` y `penalizacion 31 {nid}` (donde `{nid}` = nid de la vivienda) quedan borradas; si no existían, no hay error.

**No dispara / idempotencia**
- [x] Crear un pago nuevo directamente con estado `"Nuevo"` (sin `$node->original`) **no** aplica saldos ni cambia el estado a `"Completado"`.
- [x] Re-guardar un pago que ya está en `"Completado"` (cambiando o no otros campos) **no** vuelve a mover saldos ni cancela tareas.
- [x] Editar un pago de `"Completado"` a `"Nuevo"` **no** dispara la lógica (el estado anterior no es `"Pendiente de verificar"`).
- [x] Guardar nodos que no son `pagos` (`vivienda`, `condominio`, cualquier otro tipo) sale del hook en la primera línea, sin efecto ni recursión.

**Precondiciones que abortan sin efecto**
- [x] Un pago sin `field_vivienda`, o cuya vivienda no existe / no es bundle `vivienda` / no está publicada → el hook no toca saldos, no cancela tareas y **no** fuerza `"Completado"` (el pago conserva el estado entrante).
- [x] Una vivienda sin `field_condominio`, o cuyo condominio no existe / no es bundle `condominio` → mismo comportamiento: sin efecto y sin forzar `"Completado"`.
- [x] Un pago con `field_valor` ausente, no numérico o `≤ 0` → sin efecto y sin forzar `"Completado"`.

**Saldos en `NULL`**
- [x] Vivienda sin fila/valor en `field_saldo_actual` → se trata como `0` y queda `0 − field_valor`.
- [x] Condominio sin fila/valor en `field_saldo_caja` → se trata como `0` y queda `0 + field_valor`.

**Infra / no regresión**
- [x] `includes/myapi.payment_workflow.inc` está listado en `myapi.info` y el módulo carga sin errores tras `drush cc all`.
- [x] `docs/payment-workflow.md` describe disparador, precondiciones, efectos sobre saldos/estado/revisiones y las 4 tareas canceladas.
- [x] Registrar un pago (spec 20) sigue funcionando idéntico y sigue creándolo en `"Pendiente de verificar"`.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Mecanismo de disparo | `hook_node_presave` en `myapi` | Endpoint REST de verificación / `hook_node_update` | Atrapa el cambio venga del formulario admin o de un `node_save` programático, igual que Rules reaccionaba al evento de la entidad; un endpoint futuro lo aprovecharía sin cambios. |
| `presave` vs `update` | `presave` (modifica el `$node` en curso) | `hook_node_update` (segundo `node_save`) | En presave se fija `"Completado"` en el mismo guardado: `"Nuevo"` nunca se persiste y no hay segundo save ni recursión sobre el propio pago. |
| Estado `"Nuevo"` | Transitorio: solo dispara, el pago queda en `"Completado"` | Persistir `"Nuevo"` como estado intermedio real | Fiel a la regla original (`node_insert` con `"Nuevo"` → `"Completado"`); `"Nuevo"` es el valor que el admin elige para verificar. |
| Guarda de idempotencia | Actuar **solo** en la transición exacta `"Pendiente de verificar"` → `"Nuevo"` | Actuar en cualquier guardado con estado `"Nuevo"` (como la regla original) | Evita el doble descuento de saldo que la regla original arriesgaba; re-guardar un pago completado no reajusta nada. |
| Precondiciones fallidas | No hacer nada (no forzar `"Completado"`, no tocar saldos) | Forzar `"Completado"` igual / abortar con error visible | Equivale al `IF` de Rules (si no se cumple, nada ocurre); no dejar un pago "completado" sin haber actualizado saldos. |
| Monto inválido (`field_valor` ausente o `≤ 0`) | No hacer nada | Tratarlo como `0` y completar igual | Un pago a verificar debe tener monto; completarlo sin mover saldos sería un estado inconsistente. |
| Saldos en `NULL` | Tratar `field_saldo_actual` / `field_saldo_caja` ausentes como `0` | Abortar si falta el saldo | El saldo puede no existir aún; `0 ∓ valor` es el arranque natural, mismo criterio de `NULL` de specs 10 y 17. |
| Revisiones de vivienda/condominio | `revision = 1` (nueva revisión por cambio de saldo) | Guardar sin nueva revisión | Mantiene el rastro de auditoría que hacía Rules con `data_set node:...:revision = 1`. |
| Tareas programadas | **Cancelar** filas pendientes de `rules_scheduler` por `config` + `identifier` | Migrar el sistema de recordatorios/penalizaciones a código custom | `rules_scheduler` sigue instalado con esos 4 componentes; solo hay que replicar el `schedule_delete`, no reescribir el scheduler. |
| Borrado de tareas | `db_delete('rules_scheduler')` directo por `config`+`identifier` | API de `rules_scheduler` (cargar el componente y cancelar) | Borrado idempotente y sin dependencia de la firma interna del módulo; si no hay fila, no pasa nada. |
| Ubicación del código | Hook en `myapi.module`, lógica en `includes/myapi.payment_workflow.inc` | Un recurso en `resources/` / un módulo custom separado | No es un endpoint REST; respeta "el módulo solo enruta, la lógica va en `includes/`", dentro del mismo módulo `myapi`. |
| `{nid}` de las tareas | nid de la **vivienda** referenciada | nid del pago | La regla original usa `node:field-vivienda:nid`; los recordatorios/penalizaciones son por vivienda, no por pago. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El hook corre para todos los guardados de nodo.** Un `hook_node_presave` se invoca en cada save de cualquier tipo; un descarte tardío o incorrecto degradaría todos los guardados del sitio. | Primera línea del hook: `if ($node->type !== 'pagos') return;`. La carga del helper (`module_load_include`) ocurre solo para nodos `pagos`. |
| **Recursión al re-guardar vivienda/condominio.** `apply_verification` hace `node_save()` de otros nodos, que reinvocan el hook. | Esos nodos no son `pagos`, así que el hook retorna en la primera línea; no hay recursión. Además el estado del pago se fija en el `$node` en curso, sin un segundo `node_save` del propio pago. |
| **Condición de carrera / doble verificación.** Dos guardados casi simultáneos del mismo pago con la transición podrían aplicar el descuento dos veces. | La guarda compara contra `$node->original` (estado en BD al inicio del save); el segundo save vería `original = "Completado"` y no dispararía. Riesgo residual mínimo aceptado; sin lock a nivel BD (igual criterio que la deduplicación de spec 20). |
| **`rules_scheduler` no instalado o tabla ausente.** Si el módulo se deshabilita, el `db_delete('rules_scheduler')` falla por tabla inexistente. | Documentar en `docs/payment-workflow.md` que el hook asume `rules_scheduler` instalado (lo está hoy). Si se vuelve opcional, envolver el borrado en `db_table_exists('rules_scheduler')`; se deja anotado como ajuste puntual, no se implementa ahora. |
| **Identificadores de tarea desalineados.** Si los componentes de Rules generan el `identifier` con otro formato (espaciado, tokens distintos) que el asumido (`penalizacion 10 {nid}`, etc.), el `db_delete` no borra nada silenciosamente. | Los patrones se toman literalmente del export de la regla original. Señal de diagnóstico: si tras verificar siguen llegando recordatorios/penalizaciones, el `identifier` real difiere y se ajusta en un punto único. |
| **Machine names de bundles/campos.** El hook asume `pagos`/`vivienda`/`condominio` y `field_saldo_actual`/`field_saldo_caja`/`field_valor`/`field_condominio`/`field_estado_pago`. Un nombre distinto haría que nunca dispare o aborte siempre en precondiciones. | Nombres confirmados contra specs 10, 17 y 20 y el export de la regla. Diagnóstico claro: si toda verificación válida no mueve saldos, revisar bundle/campo esperado en un solo lugar. |
| **Valores de `field_estado_pago` sensibles a texto exacto.** La comparación es por string literal (`"Pendiente de verificar"`, `"Nuevo"`, `"Completado"`); una tilde, mayúscula o espacio distinto rompe el disparo. | Constantes centralizadas al inicio del helper (`MYAPI_PAYMENT_STATUS_*`); un cambio de etiqueta se corrige en un único sitio. Valores tomados de spec 20 (`"Pendiente de verificar"`) y de la regla (`"Nuevo"`/`"Completado"`). |
| **Drupal 7 EOL.** El hook escribe saldos monetarios a partir de un cambio de estado. | Precondiciones estrictas (bundles, monto `> 0`), escritura vía Field API / `node_save()` (no SQL crudo para los saldos), y guarda de transición exacta para no aplicar de más. |

---

## Lo que **no** entra en este spec

- Un endpoint REST de verificación (`PUT /api/v1/payments/%/verify`) — el disparo es por `node_save`; si el endpoint hace falta, va en otro spec.
- Rechazar/anular un pago o **revertir** saldos.
- Reajustar saldos al editar un pago ya `"Completado"`.
- Crear o reprogramar recordatorios/penalizaciones — solo se **cancelan** los pendientes.
- Migrar `rules_scheduler` a código custom.
- Replicar cualquier otra regla de Rules distinta de `rules_actualizar_saldo_pago`.

Cada una, si aparece, va en su propio spec.
