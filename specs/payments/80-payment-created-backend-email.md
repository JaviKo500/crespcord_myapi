# 80 — Email al rol `backend` cuando un residente registra un pago

- **Estado:** Implemented — código y unit tests en verde (1265 tests, 5072 assertions); la verificación manual en el sitio queda pendiente
- **Fecha:** 2026-08-12
- **Dependencias:**
  - `20-register-payment` (Implemented) — `POST /api/v1/payments`, `myapi_payment_create()` en `resources/payment.resource.inc`, nodo `pagos` y sus campos.
  - `48-reservation-notifications` (Implemented) — cola de correo diferida (`includes/myapi.mail_queue.inc`, cola `myapi_mail_send`), `MyapiHtmlMailSystem` y el shell HTML `myapi_mail_reservation_html()`, que este spec reutiliza sin modificar.
  - `68-claim-notifications` (Implemented) — `myapi_notification_role_uids($role_name)` en `includes/myapi.notification.inc`, que resuelve los uids activos de un rol por **nombre**.
- **Objetivo:** Cuando un residente registra un pago desde la app (`POST /api/v1/payments`), enviar a **todos los usuarios activos con el rol `backend`** un email con el detalle del pago y un botón que abre directamente el formulario de edición del nodo (`node/{nid}/edit`), que es donde se cambia `field_estado_pago` para verificarlo.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.payment_workflow.inc`** (modificar):
  - Constantes nuevas: `MYAPI_PAYMENT_NOTIFY_ROLE` (`'backend'`), `MYAPI_PAYMENT_CREATED_ADMIN_MAIL_KEY` (`'payment_created_admin'`), `MYAPI_PAYMENT_MAIL_EMPTY` (`'—'`) y un `define()` guardado de `MYAPI_PAYMENT_METHOD_FIELD`.
  - `myapi_payment_notify_created($node, $unit, $bank_term, $file)` — resuelve los destinatarios con `myapi_notification_role_uids(MYAPI_PAYMENT_NOTIFY_ROLE)` y encola **un ítem por destinatario** con `myapi_mail_queue_enqueue()`. Sin nadie en el rol no encola nada y retorna.
  - `myapi_payment_backend_mail_params()` — arma los params ya resueltos y escapados.
  - `myapi_payment_method_label()`, `myapi_payment_date_label()`, `myapi_payment_resident_label()`, `myapi_payment_resident_mail_label()` — las cuatro resoluciones que ese builder necesita.
- **`resources/payment.resource.inc`** (modificar) — `myapi_payment_create()` llama a `myapi_payment_notify_created()` tras `node_save()` y `file_usage_add()`, justo antes del `201`. `MYAPI_PAYMENT_METHOD_FIELD` pasa a definirse guardado.
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_payment_admin()` y `myapi_mail_payment_admin_html()`, que reutilizan el shell `myapi_mail_reservation_html()` con su parámetro de botón.
- **`myapi.module`** (modificar) — rama `payment_created_admin` en `myapi_mail()`.
- **`myapi.install`** (modificar) — `myapi_html_mail_keys()` gana `myapi_payment_created_admin` (y `myapi_claim_closed_admin`, que spec 70 dejó fuera de la lista); `myapi_update_7027()` reaplica el mapeo en sitios ya instalados.
- **`tests/unit/PaymentAdminMailTest.php`** (nuevo) — asunto, cabecera HTML, las doce líneas, el placeholder de banco/comprobante, el destino del botón y `myapi_payment_date_label()`.
- **`docs/payment-workflow.md`** y **`docs/payment.md`** (modificar).

### Fuera de este spec

- **Ampliar la audiencia** a los administradores de edificio del condominio del pago (como hace `reservation_created_admin` desde spec 49). Decisión explícita: solo `backend`.
- **Emails al back office por otras transiciones** (pago verificado, pago anulado): este spec cubre solo el registro desde la app.
- **Notificar pagos creados desde el back office, por drush o por importación.** El disparo vive en el endpoint, no en `hook_node_insert()`, exactamente por el mismo criterio que `myapi_reservation_notify_created()`: un operador que teclea un pago no necesita que le avisen de él.
- **Traducir el email vía `myapi_t()`** — texto fijo en español, mismo criterio que el resto de los correos del módulo.
- **Dar permiso de edición de nodos `pagos` al rol `backend`.** El botón asume que ya lo tiene; este spec no toca permisos.
- **Envío síncrono.** El correo sale en el siguiente cron, como todos los del módulo.

---

## Contrato del email

**Clave:** `payment_created_admin`. **Asunto:** `Nuevo pago #{nid} — Ref. {reference}, {amount}`.

**Cuerpo** (doce líneas, en este orden):

| Línea | Origen |
|---|---|
| Referencia | `field_referencia`. **No** se vuelve a escapar: `myapi_payment_create()` ya le aplicó `check_plain()` antes de guardarlo. |
| Monto | `field_valor`, `number_format(..., 2)`. |
| Forma de pago | Label de `allowed_values` de `field_forma_de_pago`; la clave cruda si el label ya no existe. |
| Banco | Nombre del término de `bancos`, o `—` (efectivo). |
| Fecha del pago | `field_fecha_de_pago` reformateado a `d/m/Y` **por string** (`preg_match`), nunca vía `strtotime()`/`format_date()`: el valor guardado es la fecha de calendario que eligió el residente, no un instante, y una conversión de zona horaria podría correrla un día. |
| Vivienda / Condominio | `title` del nodo `vivienda` y de su `field_condominio`. |
| Residente / Email | Nombre completo (`field_nombre` + `field_apellidos` vía `myapi_user_fetch_profile_fields()`), con el username como respaldo; y el `mail` de la cuenta. |
| Comprobante | Nombre del archivo adjunto, o `—`. **Siempre se dibuja**: "sin comprobante" es justo el dato que decide si el pago se puede verificar. |
| Estado | `field_estado_pago`, o sea `"Pendiente de verificar"`. |
| Registrado el | `$node->created`, como `d/m/Y H:i`. |

Cualquier valor que no se pueda resolver se imprime como `—` (`MYAPI_PAYMENT_MAIL_EMPTY`), nunca como celda vacía.

**Botón:** `Revisar pago` → `url('node/{nid}/edit', ['absolute' => TRUE])`. El formulario de edición y no la ficha del nodo: la siguiente acción del operador es cambiar `field_estado_pago`.

---

## Decisiones tomadas

- **Disparo en el endpoint, no en `hook_node_insert()`.** Un pago creado desde el back office no genera correo. Mismo criterio (y mismo comentario) que las reservas.
- **Best-effort y fuera de la ruta crítica.** El pago ya está comprometido cuando se encola; ni un fallo al encolar ni la latencia de la cola pueden deshacerlo ni retrasar el `201`.
- **Un ítem de cola por destinatario**, para que una dirección inválida no arrastre al resto del lote.
- **Params ya resueltos y escapados al encolar.** La cola corre en cron mucho después: el mensaje describe lo que era cierto en el instante del disparo, y una vivienda renombrada o un término de banco borrado en el medio no lo cambian ni lo rompen.
- **Se reutiliza `myapi_mail_reservation_html()`** en lugar de duplicar 40 líneas de tabla con estilos inline (regla 3 de CLAUDE.md). Su nombre es de spec 48 y se deja como está.
- **`MYAPI_PAYMENT_METHOD_FIELD` se define guardado en los dos archivos**, para que cualquiera de ellos pueda ser el primero en cargarse en una petición sin emitir un aviso de constante ya definida (mismo patrón que `MYAPI_MAIL_QUEUE`).

---

## Verificación

1. `php -l` sobre los cinco archivos tocados.
2. `./vendor/bin/phpunit` — 1265 tests en verde, 9 de ellos nuevos.
3. `drush updb` (aplica `myapi_update_7027()`) y `drush cc all`.
4. Manual: registrar un pago desde la app con adjunto y sin adjunto, y uno en efectivo; correr `drush cron`; confirmar que cada usuario `backend` activo recibe el correo en HTML, que las doce líneas coinciden con el nodo y que el botón abre `node/{nid}/edit`.
5. Manual (caso borde): con nadie en el rol `backend`, el `POST` sigue respondiendo `201` y no se encola nada.
