# SPEC 137 — Campos de cancelación en el formulario de reserva del back office

> **Estado:** Implemented en código (pendiente de desplegar y de la verificación manual) · **Depende de:** SPEC 32 (tipos de contenido de reservas), SPEC 48 (notificaciones), SPEC 50 (motivo de cancelación), SPEC 134 (formulario de reserva) · **Fecha:** 2026-11-25
>
> **Objetivo:** Que en `node/add/reservation` y `node/{nid}/edit` el **Motivo de cancelación** sea obligatorio al cancelar, y que **Cancelada por** no lo pueda elegir el operador: lo escribe el sistema.

---

## Problema

Los dos campos eran libres. Un operador podía cancelar sin motivo —el residente recibe la notificación sin explicación— y podía elegir *Usuario* en **Cancelada por**, o cambiarlo después, falseando quién canceló.

## Alcance

**Dentro:**

- **`includes/myapi.reservation_form.inc`** (modificar): las dos decisiones puras, el `form alter` de los dos campos, la validación del motivo y la escritura de `field_cancelled_by`.
- **`myapi.module`** (modificar): la llamada en `hook_node_validate()` y un `hook_node_submit()` nuevo. Solo pegamento.
- **`tests/unit/ReservationFormCancellationTest.php`** (nuevo), y el stub de `entity_load_unchanged()` en `tests/unit/bootstrap.php` y `tests/stubs/drupal7.stub.php`.
- **`docs/reservation-form.md`** y **`manual_backoffice/guia/04_reservas.md`** (modificar).

**Fuera de alcance:**

- `PUT /api/v1/reservations/{id}/cancel`: el motivo del residente sigue siendo opcional (SPEC 50).
- Cambios de schema o `hook_update_N()`. La instancia de `field_cancel_reason` sigue con `required = 0` y su descripción *Opcional…* en base de datos; el formulario la sustituye al mostrarse.
- Rellenar **Cancelada por** en las reservas ya canceladas que lo tienen vacío.
- Las capturas del manual (`reserva_cancelar.png`, `ae_reserva_nueva.png`): hay que rehacerlas.

---

## Diseño

### Motivo de cancelación

Obligatorio siempre que la reserva se guarda como *Cancelada*, con una excepción: una reserva que **ya estaba cancelada y sin motivo** se puede seguir editando sin él.

| Se guarda como | Estaba | Motivo enviado | Resultado |
|---|---|---|---|
| Cancelada | nueva o Confirmada | vacío o solo espacios | Rechazada |
| Cancelada | nueva o Confirmada | texto | Guardada |
| Cancelada | Cancelada con motivo | vacío | Rechazada |
| Cancelada | Cancelada sin motivo | vacío | Guardada |
| Confirmada | cualquiera | cualquiera | Guardada |

- La regla es `myapi_reservation_form_validate_cancel_reason()`, desde `hook_node_validate()`, para todos los roles.
- El asterisco lo pone `#states` mientras el estado elegido es *Cancelada*. El campo no es `#required`.

### Cancelada por

| Reserva | En el formulario |
|---|---|
| Nueva, confirmada, o cancelada sin valor guardado | Oculto (`#access` FALSE) |
| Guardada como cancelada con valor | Visible y bloqueado (`#disabled`) |

El valor lo decide `myapi_reservation_form_apply_cancelled_by()`, desde `hook_node_submit()`, en cada guardado del formulario:

| Se guarda como | Estaba | `field_cancelled_by` |
|---|---|---|
| Cancelada | nueva o Confirmada | `admin` |
| Cancelada | Cancelada | El guardado, sin tocar |
| Confirmada | cualquiera | Vacío |

**"Estaba" se lee de la base de datos**, con `entity_load_unchanged()`, y nunca con `node_load()`. En `node/{nid}/edit` el nodo del formulario es el mismo objeto que `node_load()` guarda en su caché estática, y Drupal escribe en él los valores enviados antes de invocar `hook_node_submit()`: leído con `node_load()`, una reserva que se está cancelando ya figuraría como cancelada y se quedaría sin **Cancelada por**. La primera versión de esta spec tenía ese fallo; lo cubre `testTheSubmittedNodeBeingTheCachedOneStillWritesAdmin`.

---

## Criterios de aceptación

Automáticos (`vendor/bin/phpunit`):

- [x] `ReservationFormCancellationTest`: las dos tablas de arriba, el error sobre el input del motivo, que el valor enviado de **Cancelada por** se ignora, que el estado anterior se lee de la base de datos aunque el nodo enviado sea el de la caché, que `hook_node_validate()` ejecuta la regla, que el `hook_node_submit()` solo toca reservas y la forma de los dos campos en el formulario.

Manuales — *pendientes*:

- [ ] `node/add/reservation`: **Cancelada por** no aparece.
- [ ] Editar una reserva confirmada: **Cancelada por** no aparece; al elegir *Cancelada* el motivo muestra el asterisco.
- [ ] Guardar como *Cancelada* sin motivo: no guarda y marca el motivo en rojo.
- [ ] **Sin verificar, y es el criterio que más importa:** guardar como *Cancelada* con motivo, **directamente desde `node/{nid}/edit` y sin pasar por *Vista previa***: guarda, el listado muestra *Admin* y el motivo, y el residente recibe la notificación con el motivo. Es el camino del fallo descrito en *Diseño*; el test lo reproduce con stubs, no con Drupal.
- [ ] Lo mismo pasando antes por *Vista previa*.
- [ ] Volver a editar esa reserva: **Cancelada por** aparece con *Admin* y no se puede cambiar.
- [ ] Editar una reserva cancelada desde la app sin motivo: muestra *Usuario* bloqueado y se guarda sin pedir motivo.
- [ ] Pasar una cancelada a *Confirmada*: guarda (si pasa las reglas de SPEC 135) y **Cancelada por** queda vacío.
- [ ] Con el tema del back office (Bootstrap) el asterisco de `#states` se ve junto a la etiqueta.

---

## Decisiones

1. **Obligatorio también al editar una cancelada con motivo.** El propietario pidió "obligatorio si cambian a estado cancelada". Exigirlo solo en la transición permitiría cancelar con motivo y borrarlo en el guardado siguiente. Interpretación propia, sin confirmar.
2. **Una cancelada sin motivo se sigue pudiendo editar.** El residente cancela desde la app sin motivo obligatorio, y hay cancelaciones anteriores a esta spec.
3. **Bloqueado y no solo oculto tras guardar.** El propietario pidió que esté "oculto hasta que el usuario guarde" para que no se pueda modificar: después de guardar se enseña, pero sin poder cambiarse.
4. **Reconfirmar vacía Cancelada por.** Como el operador ya no puede tocar el campo, alguien tiene que limpiarlo; si no, una reserva confirmada arrastraría la marca de una cancelación anterior. Sin confirmar con el propietario. El motivo no se toca.
5. **`hook_node_submit()` y no `hook_node_presave()`.** Solo se invoca desde el formulario de nodo: los endpoints y cualquier `node_save()` programático quedan fuera sin necesidad de banderas.
6. **Sin `hook_update_N()`.** La descripción y el asterisco se ponen en el `form alter`.

## Riesgos

- **La lectura del estado guardado depende de cómo Drupal cachea las entidades.** `entity_load_unchanged()` vacía la caché estática de ese nodo y lo vuelve a leer de la base de datos: una consulta más por formulario mostrado, validado y guardado, y a partir de ahí `node_load()` devuelve en esa petición un objeto distinto del que lleva el formulario. Si otro módulo sirviera las entidades desde una caché persistente (*entitycache*), "sin cambios" pasaría a ser lo que esa caché tenga.

- **Sin test automático de lo que hace Drupal con `#access`, `#disabled` y `#states`.** Depende de la matriz manual.
- **Widget cambiado desde la UI de campos.** Si el motivo deja de ser el cuadro de texto simple se pierden el asterisco y la descripción; la validación sigue activa.
- **Cancelaciones antiguas con Cancelada por vacío** siguen vacías y con el campo oculto.
