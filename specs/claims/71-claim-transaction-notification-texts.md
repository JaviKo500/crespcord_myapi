# SPEC 71 — Textos de las notificaciones de transacción de un reclamo

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundles `reclamo` y `claim_transaction`), SPEC 57 (`myapi_claim_transaction_sync_claim_status()`), SPEC 58 (`field_status_date` con minutos), SPEC 62 (catálogo de estados), SPEC 68 (notificaciones de reclamos: los cuatro constructores de texto) · **Fecha:** 2026-08-06
> **Objetivo:** Corregir tres defectos de redacción de las notificaciones push + bandeja que genera una `claim_transaction`, detectados sobre capturas reales de la app.

Nada de este spec toca endpoints, campos, tablas ni destinatarios. **No hay `drush updb`**: solo cambian los textos que leen el solicitante y sus vecinos, y una propiedad transitoria que ya se podía calcular pero se descartaba.

---

## El problema

Tres notificaciones consecutivas en la bandeja de un mismo residente:

```
Novedad en un requerimiento de tu condominio
Test de reclamo
Estado: En proceso · 06/08/2026 11:02
Se esta verificando el reclamo enviado por el administrador del edificio

Tu reclamo pasó a "En proceso"          ← hace 2 min
No hay servicio de wifi en BBQ 44
Reclamo desde el administrador backend
06/08/2026 11:00

Tu reclamo pasó a "En proceso"          ← hace 4 min
No hay servicio de wifi en BBQ 44
En detalle del reclamo
06/08/2026 10:59
```

1. **El título afirma una transición que no ocurrió.** Las dos últimas son transacciones del mismo reclamo con el mismo estado: la segunda no lo movió a ningún sitio, solo añadió un comentario. `myapi_claim_transaction_push_title()` solo mira el estado de la transacción, nunca si cambió, así que **todo seguimiento sobre un reclamo ya `in_progress` se titula igual**. Desde la pantalla bloqueada el residente no distingue una transición real de un comentario, ni ninguna de las dos de un duplicado.
2. **La fecha gasta una línea de tres y es redundante.** `06/08/2026 11:00` en una notificación que el sistema operativo ya rotula «ahora» y que la bandeja ya sella con `myapi_notifications.created`. El presupuesto útil de un push son 3–4 líneas: la línea de fecha desplaza el comentario del operador, que es lo único que dice qué pasó.
3. **El orden de líneas no coincide entre las dos variantes.** El vecino recibe `asunto → estado·fecha → comentario` y el solicitante `asunto → comentario → fecha`. La misma persona recibe ambas — es solicitante de un reclamo y vecino de otro, exactamente como en la captura — y lee el comentario en una posición distinta cada vez.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (modificar) — `myapi_claim_transaction_sync_claim_status()` guarda en `$node->myapi_claim_previous_status` el estado que tenía el reclamo **antes** de sincronizar.
- **`includes/myapi.claim_notification.inc`** (modificar):
  - `myapi_claim_date_is_today($timestamp, $now)` (nuevo, puro) — decide si la línea de fecha se imprime.
  - `myapi_claim_transaction_changed_status($node)` (nuevo) — lee la propiedad transitoria.
  - `myapi_claim_transaction_push_title()` — dos parámetros nuevos, con defecto compatible.
  - `myapi_claim_transaction_neighbour_push_title()` — ídem.
  - `myapi_claim_transaction_push_body()` — parámetro `$now`.
  - `myapi_claim_transaction_neighbour_push_body()` — parámetro `$now`.
  - `myapi_claim_notify_transaction()` — resuelve las dos entradas nuevas una sola vez y las pasa a los cuatro.
- **`tests/unit/ClaimNotificationTest.php`** (modificar) — los casos nuevos y los reescritos.
- **`docs/claim-notifications.md`** (modificar).

**Fuera de alcance (para specs futuros):**

- **Agrupar los push de un mismo reclamo** (`android_group` / `thread_id` / `collapse_id` de OneSignal). Es un cambio en `myapi_onesignal_send()`, afecta a los cinco orígenes de notificación del módulo y merece su propio spec.
- **Los seis emails.** Ahí no hay presupuesto de 200 caracteres: siguen imprimiendo estado y fecha siempre, y su asunto no cambia.
- **Los textos de creación y de publicación** (`claim_created`, `claim_published`). Su fecha **no** es redundante: `Recibido el` y `Publicado el` llevan etiqueta y afirman algo que ninguna superficie muestra.
- **Las notificaciones de boletines, pagos, alícuotas y reservas.** Mismo criterio de fecha aplicable, distinto spec.
- **Preferencias de notificación por usuario.** Sigue sin haberlas (SPEC 68).
- **Comillas tipográficas** en `pasó a "En proceso"`. Cosmético.

---

## La propiedad transitoria

| Propiedad | La escribe | La lee | Efecto |
|---|---|---|---|
| `$transaction->myapi_claim_previous_status` | `myapi_claim_transaction_sync_claim_status()` (SPEC 57), antes de decidir si re-guarda el reclamo | `myapi_claim_transaction_changed_status()` | Permite distinguir una transición real de un seguimiento |

Es la tercera propiedad transitoria del módulo sobre estos bundles, con el mismo mecanismo que `myapi_claim_from_api` y `myapi_skip_claim_notification` (SPEC 68): **propiedad del objeto, nunca campo**, viva solo durante ese `node_save()`.

**Por qué ahí y no en el notificador.** `myapi_claim_transaction_sync_claim_status()` es el último punto del request donde el estado anterior del reclamo todavía existe: inmediatamente después, o el reclamo ya se re-guardó con el nuevo, o ya tenía ese valor. `myapi_node_insert()` llama primero a la sincronización y después al notificador, sobre el mismo objeto (`myapi.module`), así que el dato llega intacto.

**Por qué se guarda el valor y no un booleano.** El notificador es quien sabe qué quiere hacer con él, y un valor crudo envejece mejor que la conclusión de otro. Si mañana un texto necesita decir *«de Recibido a En proceso»*, el dato ya está.

**`property_exists()` y no `isset()`.** Un reclamo sin `field_status` guarda `NULL`, que es un valor anterior legítimo y no una ausencia.

**El defecto sin propiedad es `TRUE`**, es decir la redacción anterior a este spec. Sin propiedad no hay con qué comparar — un camino que guarde una transacción sin pasar por la sincronización, una migración — y afirmar que el estado no se movió sería inventarse un hecho. Decir *«pasó a X»* es, como mucho, lo que ya estaba en producción.

---

## Los cuatro textos

`<tipo>` sigue a `field_claim_type` (`reclamo` / `requerimiento`), igual que en SPEC 68.

### Título al solicitante

| Caso | Texto |
|---|---|
| El estado cambió, con etiqueta | `Tu reclamo pasó a "En proceso"` |
| El estado cambió, sin etiqueta resoluble | `Novedad en tu reclamo` |
| **Mismo estado, con comentario** | `Nueva respuesta en tu reclamo` |
| **Mismo estado, sin comentario** | `Novedad en tu reclamo` |

### Título al vecino

Su título nunca llevó el estado — tiene que decir antes que nada que el reclamo no es suyo — así que solo cambia una palabra:

| Caso | Texto |
|---|---|
| **Mismo estado, con comentario** | `Nueva respuesta en un reclamo de tu condominio` |
| Cualquier otro caso | `Novedad en un reclamo de tu condominio` |

### Cuerpo al solicitante

```
Fuga de agua en el pasillo
Se asignó un técnico para revisar la tubería del tercer piso.
```

### Cuerpo al vecino

```
Fuga de agua en el pasillo
Estado: En proceso
Se asignó un técnico para revisar la tubería del tercer piso.
```

Los dos en el mismo orden — **asunto, el hecho, el comentario** — con el estado en el título para el solicitante y en el cuerpo para el vecino. El comentario va **último en ambos**, que es lo que arregla el defecto 3.

### La regla de la fecha

La línea de fecha se imprime **solo cuando el evento no es del día**, comparando `field_status_date` con `REQUEST_TIME` a través de `format_date()` — nunca con `date()` — para que ambos se lean en la zona horaria del sitio.

| Caso | Solicitante | Vecino |
|---|---|---|
| Evento del día | sin línea de fecha | `Estado: En proceso` |
| `field_status_date` retroatada | `05/08/2026 09:30` al final | `Estado: En proceso · 05/08/2026 09:30` |
| Retroatada y sin etiqueta de estado | `05/08/2026 09:30` al final | `05/08/2026 09:30` sola |
| Del día y sin etiqueta de estado | sin línea de fecha | **la línea del medio desaparece entera** |

**Una fecha retroactiva sí se imprime.** `field_status_date` la escribe el operador y puede apuntar al pasado: registrar hoy una gestión del viernes es un movimiento normal de back office. Ese dato no lo muestra ni el banner ni la bandeja, así que se queda.

**Ningún cuerpo puede quedar vacío.** Si caen todas las líneas — asunto corrupto, sin comentario, evento del día — la fecha vuelve. Un push sin cuerpo es peor que uno redundante.

---

## Decisiones tomadas y descartadas

- **Contar las transacciones anteriores del reclamo** para saber si el estado cambió: descartado por el mismo motivo que SPEC 68 lo descartó para detectar la transacción inicial — depende del orden de guardado y se rompe el día que alguien borra una transacción. La propiedad transitoria es explícita y no cuesta consulta.
- **Comparar contra el estado del reclamo dentro del notificador:** imposible. La sincronización de SPEC 57 corre antes (`myapi.module`), así que cuando el notificador recarga el reclamo el estado ya es el nuevo. De ahí que el dato tenga que viajar en la transacción.
- **Quitar la fecha siempre:** descartado. Perdería la única fecha retroactiva del sistema, que es justo el caso en que la fecha significa algo.
- **Sustituir la fecha por un texto relativo («hace 2 min»):** descartado. El texto se guarda en `myapi_notifications` y se lee días después; «hace 2 min» sería falso en la bandeja. Lo relativo lo tiene que calcular la app sobre `created`.
- **Mover el estado al título del vecino** para igualar las dos variantes: descartado, es la decisión de SPEC 68 y sigue siendo correcta — su título tiene que decir primero que el reclamo no es suyo.
- **`Nuevo comentario` en vez de `Nueva respuesta`:** «comentario» describe el campo, «respuesta» describe lo que el residente está esperando. El texto es para el residente.
- **Cambiar la firma de los cuatro constructores en vez de añadir funciones nuevas:** los dos títulos llevan parámetros con defecto (compatibles hacia atrás), y los dos cuerpos reciben `$now` como parámetro obligatorio en vez de leer `REQUEST_TIME` por su cuenta. Eso es lo que los mantiene puros y enteros dentro de `tests/unit`, que es donde se afirman carácter a carácter.

---

## Riesgos

- **Un push sin fecha se puede leer como más antiguo de lo que es** si la app renderiza la bandeja sin sello de tiempo propio. La app **debe** mostrar `created` en cada fila; ya lo devuelve `GET /api/v1/notifications` (SPEC 25) y no cambia nada de ese contrato.
- **Un camino futuro que guarde `claim_transaction` sin pasar por `myapi_claim_transaction_sync_claim_status()`** vuelve a titular todo como transición. Es el defecto conservador y documentado, no un fallo silencioso.
- **Las filas ya escritas en `myapi_notifications` no se reescriben.** La bandeja seguirá mostrando los títulos antiguos de las notificaciones anteriores al deploy, lo cual es correcto: describen lo que se envió.

---

## Lo que **NO** está en este spec

- Agrupación de push por reclamo en OneSignal.
- Cualquier cambio en los seis emails, en sus asuntos o en sus destinatarios.
- Los textos de `claim_created` y `claim_published`.
- Los textos de boletines, pagos, alícuotas y reservas.
- Cualquier cambio de endpoint, campo, tabla o permiso.
