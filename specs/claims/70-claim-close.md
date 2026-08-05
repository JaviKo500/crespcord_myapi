# SPEC 70 — Cierre de un reclamo por su solicitante (`PUT /api/v1/claims/%/close`)

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundle `reclamo` y `claim_transaction`), SPEC 57 (`myapi_claim_transaction_sync_claim_status()`), SPEC 58 (`field_status_date` con minutos), SPEC 60 (título autogenerado de la transacción), SPEC 64 (`myapi_claim_fetch()`, `myapi_claim_build_item()`), SPEC 67 (`myapi_claim_update()`, la regla "solo mientras `received`"), SPEC 68 (notificaciones de reclamos y `myapi_skip_claim_notification`) · **Fecha:** 2026-08-05
> **Objetivo:** Añadir `PUT /api/v1/claims/%/close`, que permite al **solicitante** de un reclamo cerrarlo — y solo mientras su estado siga siendo `received` — dejando constancia en el timeline y avisando por email al back office.

Cuatro notas técnicas que fija la cabecera, porque condicionan el resto del documento:

- **Ruta propia, no una rama más de `myapi_claim_dispatch()`.** `PUT /api/v1/claims/%/close` copia el patrón que ya existe dos veces en el módulo: `PUT /api/v1/reservations/%/cancel` (SPEC 36) y `PUT /api/v1/payments/%/cancel`. Cambiar de estado no es "editar el recurso", y meterlo dentro de `POST /api/v1/claims/%` obligaría a mezclar un cuerpo `multipart` de actualización total con una operación que solo necesita un motivo.
- **`PUT` y no `POST`:** el cuerpo es JSON, no `multipart/form-data`, así que la limitación de PHP que obligó a SPEC 67 a usar `POST` sobre el item (`$_POST`/`$_FILES` vacíos en un `PUT`) no aplica aquí. `myapi_request_body()` lee `php://input` sin ayuda de PHP.
- **El estado se escribe creando un `claim_transaction`, nunca tocando `field_status` del reclamo.** `hook_node_insert()` → `myapi_claim_transaction_sync_claim_status()` (SPEC 57) es quien sincroniza el reclamo. Es el único camino que mantiene la invariante "el estado del reclamo es el de su última transacción" y el único que deja entrada en el timeline.
- **No hay `drush updb`:** ningún campo, tabla ni bundle cambia. El estado `closed` ya está en el catálogo desde SPEC 55, y `claim_transaction` ya tiene los cuatro campos que se escriben.

---

## Alcance

**Dentro:**

- **`myapi.module`** (modificar):
  - Ruta nueva `api/v1/claims/%/close` → `myapi_claim_close_dispatch(3)`, `'file' => 'resources/claim.resource.inc'`, `'access callback' => TRUE` como toda ruta `api/v1` (el token se resuelve dentro para que el error sea el envelope JSON y no la página 403 de Drupal).
  - `myapi_mail()`: clave nueva `claim_closed_admin`, delegada a `myapi_mail_format_claim_closed_admin()`.
- **`resources/claim.resource.inc`** (modificar):
  - `myapi_claim_close_dispatch($id)` (nuevo) — enruta por método: `PUT` cierra, cualquier otro `405`.
  - `myapi_claim_close($id)` (nuevo) — la orquestación completa.
  - `myapi_claim_validate_close_reason($body)` (nuevo) — puro, devuelve veredicto; es lo único de este spec que se puede testear sin Drupal.
- **`includes/myapi.claim_notification.inc`** (modificar):
  - `myapi_claim_admin_uids($condominium_id)` (nuevo) — la unión deduplicada de `backend` + administradores del condominio, **extraída** de `myapi_claim_enqueue_admin_mails()`, que pasa a llamarla. Regla 3 de `CLAUDE.md`: dos emails al back office, un solo cálculo de destinatarios.
  - `myapi_claim_notify_closed_by_requester($claim, $comment, $timestamp)` (nuevo) — encola `claim_closed_admin`. Mismo `drupal_static()` por nid y mismo `try/catch` best-effort que los tres orquestadores de SPEC 68.
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_claim_closed_admin()` y `myapi_mail_claim_closed_admin_html()`, sobre el mismo `myapi_mail_reservation_html()` que las demás plantillas.
- **`includes/myapi.i18n.inc`** (modificar) — tres claves nuevas en `es`/`en`: `claim_closed`, `claim_close_denied`, `claim_not_closable`.
- **`docs/claim.md`** y **`docs/claim-notifications.md`** (modificar).
- **`tests/unit/ClaimCloseReasonTest.php`** (nuevo).

**Fuera de alcance (para specs futuros):**

- **Reabrir un reclamo cerrado.** El cierre es terminal para el residente; el back office siempre puede crear otra transacción.
- **Cerrar desde cualquier otro estado** (`in_progress`, `resolved`). Si la administración ya lo tomó, el cierre lo decide ella.
- **Cerrar un reclamo de otro vecino,** aunque sea público, y cerrar siendo administrador desde la app.
- **Push ni notificación de bandeja** por el cierre. Solo email al back office (ver "Decisiones").
- **Adjuntar imágenes o archivos al motivo de cierre.** El endpoint es JSON; la transacción de cierre no lleva archivos.
- **`DELETE /api/v1/claims/%`.** Cerrar no es borrar: el reclamo y su historial siguen visibles.
- **Rate limiting** sobre el cierre.

Cinco casos límite decididos **dentro** de este alcance:

1. **Reclamo ya `closed`** (por el propio residente o por el back office): `409 claim_not_closable`. La regla es "exactamente `received`", no "distinto de `closed`".
2. **Reclamo público de otro vecino,** visible para quien llama: `403 claim_close_denied`, nunca `404` — es visible, no hay nada que ocultar.
3. **Reclamo que quien llama no puede ver:** `404 claim_not_found`, indistinguible de uno inexistente, exactamente como `GET /api/v1/claims/%`.
4. **Cuerpo sin `close_reason`, o con la cadena vacía / solo espacios:** `422 missing_field`. A diferencia del motivo de cancelación de una reserva (SPEC 50, opcional), aquí es obligatorio.
5. **El email al back office falla o no hay destinatarios:** el cierre ya está escrito y la respuesta sigue siendo `200`. Best-effort por contrato, igual que las notificaciones de SPEC 68.

---

## Modelo de datos

**No hay campos, tablas ni bundles nuevos.** El cierre escribe un nodo `claim_transaction` con los mismos cuatro campos que escribe el formulario del back office (SPEC 57).

### Request — JSON sobre `PUT /api/v1/claims/{id}/close`

| Campo | Tipo | Obligatorio | Efecto |
|---|---|---|---|
| `close_reason` | string, 1–1000 caracteres | Sí | `field_comment` de la transacción de cierre |

El límite se mide en **caracteres** (`drupal_strlen()`), no en bytes: `field_comment` es `text_long`, y contar bytes rechazaría un motivo perfectamente almacenable escrito con acentos — el idioma en el que escriben los residentes de este producto. El tope de 1000 no lo impone la columna, lo impone este spec: un motivo de cierre es una frase, y el timeline y el email lo imprimen entero.

### El nodo `claim_transaction` que se crea

| Campo | Valor |
|---|---|
| `type` | `claim_transaction` |
| `uid` | El uid del solicitante — es él quien cierra, y es lo que hace que el timeline del back office muestre su nombre como autor (`myapi_claim_transaction_author_label()`) |
| `status` | `1` (publicada, como toda transacción) |
| `field_claim` | El nid del reclamo |
| `field_status` | `closed` |
| `field_status_date` | `date('Y-m-d H:i:00')` — el instante real del cierre, con los segundos fijados a `:00` como en los otros dos caminos que crean transacciones |
| `field_comment` | El `close_reason` recortado |
| `title` | No se escribe: `hook_node_presave()` → `myapi_claim_transaction_set_title()` lo compone (SPEC 60) |
| `myapi_skip_claim_notification` | `TRUE` — bandera transitoria, no un campo |

### Campos del reclamo que este endpoint nunca toca directamente

`field_status` del reclamo lo escribe `myapi_claim_transaction_sync_claim_status()` al guardar la transacción, no este código. Todo lo demás — título, descripción, visibilidad, condominio, archivos, `field_reception_date`, `node.uid`, `node.created` — queda intacto **por construcción**: el reclamo ni siquiera se carga para escribir en él.

El `node_save()` del reclamo que dispara la sincronización entra en `hook_node_update()` por la rama `'reclamo'`, que solo notifica en la transición privado → público (SPEC 68). La visibilidad no cambia aquí, así que no notifica a nadie.

---

## Orden de validación

Cada paso aborta con su propio error **antes** de escribir nada:

1. **Autenticación** — `myapi_auth_require_access_token()`; `401` por su cuenta.
2. **`{id}` entero positivo** — si no, `404 claim_not_found`. `/api/v1/claims/abc/close` no es un error de PHP.
3. **Visibilidad** — `myapi_condominium_related_nids()` + `myapi_claim_fetch()`, la misma consulta y el mismo `404` uniforme que `GET /api/v1/claims/%`.
4. **Solicitante** — `row.requester_id !== uid` → `403 claim_close_denied`.
5. **Estado** — `row.status !== 'received'` → `409 claim_not_closable`. La petición es válida; lo que falla es el estado actual del recurso.
6. **`close_reason`** — `myapi_claim_validate_close_reason()`: ausente, no-string, vacío tras `trim()` → `422`; más de 1000 caracteres → `422 field_too_long`.

El motivo se valida **al final**, después de las dos comprobaciones de negocio: un reclamo que quien llama no puede cerrar sigue respondiendo `403`/`409` aunque el cuerpo venga vacío o con basura. Mismo criterio que el paso 6 de `myapi_reservation_cancel()`.

Después, y en este orden: `node_save()` de la transacción (que sincroniza el reclamo), email al back office (best-effort), y `200` con el mismo objeto que `GET /api/v1/claims/%` con las transacciones expandidas — los mismos helpers, así que las dos respuestas no pueden divergir.

---

## Email al back office (`claim_closed_admin`)

Sexta clave de correo del dominio de reclamos. Destinatarios: rol `backend` + los administradores del condominio del reclamo, unión deduplicada — exactamente los mismos que reciben `claim_created_admin`, y por eso el cálculo se extrae a `myapi_claim_admin_uids()` en lugar de repetirse.

| Línea | Valor |
|---|---|
| Asunto | `El solicitante cerró el {reclamo} #{nid} — {condominio}` |
| Cuerpo | Reclamo, asunto, condominio, solicitante, estado, fecha de cierre, motivo, y botón al nodo |

Va por la cola diferida (`myapi_mail_queue_enqueue()`), un item por dirección, como todos los demás: una dirección inválida no arrastra al resto y ningún destinatario ve el buzón de los otros.

**Al residente no se le notifica nada.** Acaba de pulsar el botón; la respuesta `200` ya es el acuse. Por eso la transacción lleva `myapi_skip_claim_notification`, que es justo lo que haría `myapi_claim_notify_transaction()` en caso contrario: mandarle push y email de "tu reclamo tiene una novedad" por una novedad que ha escrito él.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Forma del endpoint | `PUT /api/v1/claims/%/close` | (a) `POST /api/v1/claims/%` con `action=close`; (b) `PUT /api/v1/claims/%/status` genérica | (a) rompe el contrato de "actualización total con los cinco campos obligatorios" de SPEC 67 y mete dos operaciones en un dispatcher; (b) es una tabla de transiciones por rol que hoy no usa nadie: superficie sin uso. La sub-ruta ya es el patrón del módulo (reservas y pagos). |
| Cómo se escribe el estado | Creando un `claim_transaction` | `node_save()` directo del reclamo con `field_status = 'closed'` | Menos código, pero el timeline se quedaría con `received` como última entrada mientras el reclamo dice `closed`. Incoherencia visible en el back office y en la propia respuesta del endpoint. |
| Estado destino | `closed` | `resolved` | `resolved` es un veredicto de la administración ("se solucionó"); el residente solo puede afirmar que ya no le interesa. `closed` es lo que dice el catálogo de SPEC 55. |
| Motivo | Obligatorio, 1–1000 caracteres | (a) Opcional como el de las reservas (SPEC 50); (b) sin motivo, comentario fijo | La transacción queda para siempre en el timeline y es lo único que explica por qué un reclamo murió sin respuesta. Un comentario autogenerado no aporta nada que el estado no diga ya. |
| Quién se entera | Email a `backend` + admins del condominio | (a) Nadie, como el residente que cancela su reserva (SPEC 48); (b) push/email normal de transacción | (a) dejaría a la administración descubriéndolo al entrar al listado; (b) le mandaría al residente una notificación de su propio clic. |
| Longitud del motivo | Caracteres con `drupal_strlen()` | `strlen()` (bytes) | Un motivo de 600 caracteres con acentos pasa de 1000 bytes y sería rechazado sin motivo. Mismo razonamiento que SPEC 50. |
| Destinatarios del email | Helper extraído `myapi_claim_admin_uids()` | Repetir el `array_unique(array_merge(...))` en el notificador nuevo | Regla 3 de `CLAUDE.md`. Dos emails al back office con el mismo público: el día que se añada un tercer rol, hay una línea que tocar. |
| Validación del motivo | Función pura devolviendo veredicto | `myapi_request_require_strings()` | Ese helper mide bytes y aborta él mismo, así que no se puede testear ni ajustar el orden de los errores. La pura entra en `tests/unit` sin Drupal. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El `node_save()` de la transacción falla a medias** y el reclamo queda sin sincronizar. | La sincronización vive en `hook_node_insert()`, dentro de la misma transacción de base de datos que el `node_save()`; no hay estado intermedio observable. |
| **Doble clic del residente** creando dos transacciones de cierre. | La segunda petición encuentra el reclamo ya en `closed` y responde `409 claim_not_closable`. La ventana de carrera real es de milisegundos y el peor caso son dos entradas idénticas en el timeline, no un estado incorrecto. |
| **El email al back office falla** (cola llena, cuenta sin dirección). | `try/catch` con `watchdog_exception()`: el cierre ya está escrito y nada puede convertir el `200` en un `500`. |
| **Un cliente antiguo llama con `POST`** a la ruta nueva. | `405 method_not_allowed`, el mismo que cualquier otro método no soportado del módulo. |

---

## Lo que **NO** está en este spec

- Reabrir, borrar o archivar reclamos.
- Cambios de estado desde la app para roles administrativos.
- Push o notificación de bandeja por el cierre.
- Adjuntos en la transacción de cierre.
- Cualquier cambio de esquema, de catálogo de estados o del back office.
