# SPEC 135 — Reglas de negocio en el formulario de reserva del back office

> **Estado:** Implemented (desplegada y verificada manualmente en producción el 2026-11-24; quedan sin hacer las dos pruebas de bloqueo con MySQL y aplazados los permisos de `pagos` y `supervisor`, ver *Pendiente*) · **Depende de:** SPEC 35 (crear reserva), SPEC 41 (reservas que cruzan la medianoche), SPEC 45 (cupo simultáneo), SPEC 52 (formato de horas), SPEC 134 (selectores dependientes) · **Fecha:** 2026-11-24
>
> **Objetivo:** Que una reserva guardada desde `node/add/reservation` o `node/{nid}/edit` cumpla las mismas reglas que una creada con `POST /api/v1/reservations`. **La única que el back office puede saltarse es el saldo de la vivienda.**

---

## Problema

Las reglas de una reserva vivían dentro de `myapi_reservation_create()`. El formulario de nodo no pasa por ahí, así que solo validaba el formato de las horas (SPEC 52) y la coherencia de las referencias (SPEC 134). Un operador podía guardar una reserva encima de otra, fuera del horario del área, en el pasado o en un área inactiva. El hueco estaba escrito en `includes/myapi.reservation_admin.inc` y declarado fuera de alcance en las SPEC 52 y 134.

## Decisión del propietario del producto (2026-11-24)

1. Desde el back office **solo** se salta la validación de saldo insuficiente. Todas las demás se aplican.
2. Los roles `pagos` y `supervisor` también deben poder crear reservas.

Sobre el punto 2: la validación de esta spec **no mira el rol del operador**; se aplica igual a `administrator`, `backend`, `administrador edificio`, `supervisor` y `pagos`, y no bloquea a ninguno. Quién puede abrir `node/add/reservation` lo decide el permiso `create reservation content` de Drupal, que vive en la base de datos y esta spec no toca. Ver *Pendiente*.

## Alcance

**Dentro:**

- **`includes/myapi.reservation_rules.inc`** (nuevo): las reglas, definidas una sola vez. Recibe sin cambios lo que estaba en el recurso (lectores de campos, aritmética de horas de SPEC 41, consultas de las validaciones 6 y 7) y añade `myapi_reservation_requester_may_reserve()`, `myapi_reservation_schedule_violations()` y `myapi_reservation_slot_is_full()`.
- **`resources/reservation.resource.inc`** (modificar): `myapi_reservation_create()` llama a esas tres funciones. Mismo orden, mismos códigos de error y de estado; el contrato del endpoint no cambia.
- **`includes/myapi.reservation_admin.inc`** (modificar): `myapi_reservation_validate_rules()`, sus ayudantes y el bloqueo del área (`myapi_reservation_hold_area_lock()` / `myapi_reservation_release_area_lock()`).
- **`myapi.module`** (modificar): una llamada más en la rama `reservation` de `hook_node_validate()`, y `myapi_reservation_form_saved()` desde `hook_node_insert()` y `hook_node_update()`. Solo pegamento.
- **`myapi.info`** (modificar): un `files[]`.
- **`tests/unit/ReservationAdminRulesTest.php`** (nuevo), `tests/unit/bootstrap.php` y `tests/stubs/drupal7.stub.php` (`form_get_errors()`).
- **`docs/reservation-form.md`**, **`docs/reservation.md`** y **`manual_backoffice/guia/04_reservas.md`**.

**Fuera de alcance:**

- El saldo de la vivienda: no se valida en el formulario, por decisión.
- Permisos de Drupal de ningún rol.
- Notificar al residente una reserva creada desde el back office (sigue sin notificarse, SPEC 48).
- Cambios de schema o `hook_update_N()`.

---

## Diseño

### Reglas

| # | Regla (SPEC 35) | API | Back office | Campo marcado |
|---|---|---|---|---|
| 1 | El solicitante cumple `who_can_reserve` del área | `403 reservation_role_not_allowed` | Sí | Solicitante |
| 2 | El área está `active` | `409 area_not_active` | Sí | Área |
| 3 | Fecha + hora de inicio no están en el pasado | `422 invalid_field` (`date`) | Sí | Fecha |
| 4 | No cruza la medianoche en un área que cierra el mismo día | `422 reservation_crosses_midnight` | Sí | Hora de fin |
| 4 | Dentro del horario del área | `422 reservation_outside_hours` | Sí | Hora de inicio |
| 5 | No supera la duración máxima | `422 reservation_duration_exceeded` | Sí | Hora de fin |
| 6 | No supera el cupo simultáneo | `409 reservation_overlap` / `area_capacity_full` | Sí | Hora de inicio |
| 7 | La vivienda no tiene otra reserva activa en el área | `409 reservation_already_active` | Sí | Vivienda |
| 8 | Saldo de la vivienda | `403 insufficient_balance` | **No** | — |

Los mensajes del formulario son cadenas propias del back office, no las del catálogo `myapi_t()`: aquel le habla al residente (*«No tienes permitido…»*) y aquí quien lee es un operador que reserva para otra persona.

### Cuándo se aplican

- Van **las últimas** en `hook_node_validate()` y solo si las tres anteriores no dieron error: leen las horas como `HH:MM` y dan por hecho que vivienda, área y solicitante casan.
- Reserva **nueva** guardada como *Confirmada*: siempre.
- **Edición**: solo si se guarda como *Confirmada* y cambió la vivienda, el área, el solicitante, la fecha, la hora de inicio, la de fin o el estado.
- Una edición no cuenta su propia copia guardada en las reglas 6 y 7.

### Diferencias con el endpoint

- La regla 1 mira la relación del **solicitante** con la vivienda, nunca el rol del operador.
- **Fecha** se toma tal cual: es el día de reloj del inicio. El endpoint recibe el día del calendario de la app y normaliza a `D+1` un inicio de madrugada; el formulario no.
- El fin es un campo, no una duración: un fin igual o anterior al inicio es el día siguiente (SPEC 41).
- Un mensaje por campo: `form_set_error()` solo guarda el primero de cada nombre.

### Fallo cerrado

Si las reglas aplican y un valor no se puede leer (la fecha no es `YYYY-MM-DD…`, una hora no es `HH:MM`, el área no es un nodo `area`), la reserva **no se guarda** y se marca ese campo. Nunca se guarda una reserva *Confirmada* con las reglas saltadas en silencio. Si el widget de fecha entregara otro formato, se ve en el primer guardado.

### Bloqueo del área

Las reglas 6 y 7 cuentan reservas guardadas: solo valen si nadie más escribe entre la comprobación y el guardado. El endpoint lo resuelve con un bloqueo de fila sobre el nodo del área dentro de una transacción. El formulario toma **ese mismo bloqueo** (`myapi_reservation_lock_area()`) en la validación y lo mantiene hasta que el nodo está escrito, de modo que formulario y app se excluyen entre sí.

La transacción se abre en la validación y termina en uno de tres sitios:

1. `hook_node_insert()` / `hook_node_update()`, cuando la reserva se guardó. La transacción propia de `node_save()` sigue abierta en ese momento; Drupal confirma las dos juntas al cerrarse esa.
2. La propia validación, si la regla 6 o la 7 rechazan la reserva.
3. Una función de cierre de la petición, si se validó y no se guardó (error de otro módulo, o **Vista previa**). No es opcional: todo lo que la petición escribe después —la caché del formulario, la sesión con los mensajes— queda dentro de la transacción y hay que confirmarlo.

El bloqueo solo se toma si las reglas anteriores pasaron, y dura una petición.

---

## Decisiones

1. **Mover y no duplicar.** Las reglas salen del recurso a `includes/`: la regla 5 de `CLAUDE.md` prohíbe que un include llame a un recurso y que la lógica se repita. El recurso carga el include, así que todo lo que ya lo usaba sigue funcionando.
2. **Un solo bloqueo para los dos caminos.** Opción elegida por el propietario el 2026-11-24, frente a volver a comprobar en `hook_node_presave()` o aceptar el riesgo. Un test lee el código para que ni el recurso ni el formulario tengan un `forUpdate()` propio.
3. **El saldo se queda en el recurso.** El endpoint es su único llamador. Un test lee el código fuente para que el back office no lo llame nunca.
4. **Cancelar nunca valida.** Es la edición más frecuente y tiene que funcionar sobre una reserva antigua, en un área cerrada o con el horario lleno.
5. **Reactivar sí valida.** Pasar de *Cancelada* a *Confirmada* vuelve a ocupar el horario.
6. **Sin excepción por rol.** Ningún rol del back office se salta una regla.

## Riesgos

- **Widget de fecha — resuelto.** Los tests construyen el pseudo-nodo a mano; en producción (2026-11-24) las reservas válidas se guardan y las que violan una regla se rechazan, así que el widget entrega `YYYY-MM-DD…` a la validación. Si entregara otra cosa ya no es silencioso: ninguna reserva confirmada se podría guardar y el formulario marcaría **Fecha**.
- **El bloqueo no tiene test real.** La base de datos de los tests no tiene filas que bloquear ni segunda conexión: los tests afirman que se pide, cuándo y que se suelta. Que de verdad hace esperar a otro escritor se comprueba en staging (matriz de abajo).
- **Una transacción que abarca validación y guardado.** Todo lo que la petición escribe entre medias queda dentro. Si la petición muere con un error fatal después de validar, se revierte; es lo correcto para la reserva, pero también se pierden esas otras escrituras.
- **Depende de cómo Drupal anida transacciones.** Soltar la transacción exterior dentro de `node_save()` se apoya en que el core confirma las capas juntas cuando se cierra la interior. Es el comportamiento que se recuerda del core 7; no se ha leído en el 7.103 instalado. La prueba manual de guardado lo confirma.
- **Tablas InnoDB.** Con MyISAM el bloqueo no existe. Ya era requisito del endpoint.
- **Reservas antiguas incoherentes.** Las que el back office dejó crear antes de esta spec siguen pudiendo cancelarse, pero moverlas exige que cumplan las reglas.
- **Corregir una reserva pasada.** Cambiar el solicitante o la hora de una reserva confirmada que ya pasó se rechaza por la regla 3.
- **Un mensaje por campo.** Dos reglas sobre el mismo campo se ven de una en una.

## Pendiente

- **Pruebas de bloqueo con MySQL.** Las dos de la matriz manual no se hicieron. Los tests unitarios afirman que el bloqueo se pide dentro de una transacción, antes de contar, y que se suelta; que de verdad hace esperar a otro escritor (formulario o app) no está comprobado en ningún entorno. Hacerlas en staging o, en producción, solo con un área del condominio de pruebas y sin dejar la sesión de MySQL abierta.
- **Permiso de `pagos` y `supervisor` — aplazado.** En producción (consultado el 2026-11-24) ninguno de los dos roles tiene permisos sobre reservas, al contrario de lo que decía SPEC 134: solo `backend` (crear, editar, borrar) y `administrador edificio` (crear, editar cualquiera) los tienen. El cliente no ha confirmado todavía si deben crear reservas. Cuando lo confirme, el propietario los concederá a mano en `admin/people/permissions`; no requiere código, porque la validación de esta spec no distingue roles. Para estos dos roles nada recorta por condominio: verán viviendas, áreas y solicitantes de todo el sitio, igual que `backend`.

---

## Criterios de aceptación

Automáticos (`vendor/bin/phpunit`):

- [x] `ReservationAdminRulesTest`: cada regla y su orden; el cupo y la exclusión de la propia reserva; cuándo se valida una edición; el mapa de mensajes; y, sobre los stubs, el guardado: reserva válida, cada regla marca su campo, vivienda con deuda **sí** se guarda sin consultar recibos, cancelar no consulta nada, un valor ilegible **rechaza** el guardado y marca su campo.
- [x] `ReservationAdminRulesTest`, bloqueo: se toma dentro de una transacción y antes de contar; sigue abierta al terminar la validación; se suelta en `hook_node_insert()` / `hook_node_update()`, al rechazar, y por la función de cierre; no se toma si una regla anterior ya rechazó; el endpoint y el formulario usan la misma función.
- [x] `ReservationCreateRulesTest`: `POST /api/v1/reservations` de punta a punta — cada regla responde su `error_code` y su estado HTTP, gana la primera regla violada, el saldo se sigue comprobando, y la reserva válida se guarda bajo el bloqueo del área. Cada caso corre en su propio proceso, con `php://input` servido por el test.
- [x] `ReservationEndpointTest`, `ReservationMidnightTest`, `ReservationCapacityTest`: sin cambios y en verde tras mover las funciones.
- [x] `ModuleContractTest`: el include nuevo está en `myapi.info`.
- [x] `vendor/bin/phpstan analyse`: sin errores.

Manuales. Hechos directamente en **producción** el 2026-11-24 por el propietario, tras desplegar `f27d8fc`; no se pasó por staging. Las marcadas constan por su confirmación, salvo donde se indica:

- [x] Crear una reserva válida como `backend`: se guarda, aparece el aviso *«Reserva … se ha creado»* y la reserva está en el listado. (Confirma el formato de fecha y que la transacción se confirma.)
- [x] **Vista previa** de una reserva válida: se muestra, y al pulsar **Guardar** se guarda. (Confirma que la caché del formulario se confirma por la función de cierre.)
- [ ] **Bloqueo.** En una sesión de MySQL: `BEGIN; SELECT nid FROM dr_node WHERE nid = <área> FOR UPDATE;`. Guardar en el navegador una reserva válida de esa área: la página se queda esperando. Al hacer `COMMIT;` en MySQL, la reserva se guarda. — *No realizada (2026-11-24).*
- [ ] **Bloqueo, sentido contrario.** Con la misma sesión de MySQL bloqueando el área, crear una reserva de esa área desde la app: la petición espera hasta el `COMMIT;`. — *No realizada (2026-11-24).*
- [x] Crear una reserva encima de otra en un área de cupo 1: no guarda y marca **Hora de inicio**.
- [x] Crear una reserva fuera del horario del área, en el pasado y en un área inactiva: no guarda y marca el campo de cada una.
- [x] Crear una reserva para una vivienda con saldo pendiente: **sí** se guarda.
- [x] Cancelar una reserva antigua: se guarda.
- [x] Cambiar la hora de una reserva existente a un horario libre: se guarda (no choca consigo misma).
- [ ] Crear una reserva válida como `pagos` y como `supervisor`: se guarda. — *Aplazado: los roles no tienen el permiso; ver Pendiente.*
- [x] `POST /api/v1/reservations` desde la app: mismos errores que antes, incluido `insufficient_balance`.
