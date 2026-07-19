# Payment verification workflow (`hook_node_presave`)

This is **not a REST endpoint**. It is a behavior triggered by saving a `pagos`
node, implemented as `myapi_node_presave()` in `myapi.module` (glue only) with
the logic in `includes/myapi.payment_workflow.inc`.

It replicates the legacy Rules component `rules_actualizar_saldo_pago`, but
fired by the **verification** of a payment instead of by its creation.

## Trigger

The workflow runs only when a `pagos` node is **updated** (not inserted) and its
`field_estado_pago` moves exactly:

| Source | Read | Value required to fire |
|--------|------|------------------------|
| `$node->original->field_estado_pago` | previous status (in DB) | `"Pendiente de verificar"` |
| `$node->field_estado_pago` | incoming status | `"Nuevo"` |

- A node **insert** has no `$node->original`, so it never fires: this hook is
  only for the verification-by-update.
- Any other combination (e.g. `"Completado"` → anything, or `"Completado"` →
  `"Nuevo"`) does nothing.
- The status strings are centralized as `MYAPI_PAYMENT_STATUS_PENDING`,
  `MYAPI_PAYMENT_STATUS_TRIGGER` and `MYAPI_PAYMENT_STATUS_COMPLETED` at the top
  of `includes/myapi.payment_workflow.inc`. The comparison is by exact string,
  so a differing accent, case or space breaks the trigger.
- Saving any node that is not `pagos` returns on the hook's first line, with no
  effect and no recursion.

## Preconditions

Once the transition is detected, **all** of these must hold. If any fails, the
hook returns with **no effect**: balances are untouched, no tasks are cancelled
and the payment keeps its incoming status — it is **not** forced to
`"Completado"`.

1. `field_valor` (amount) is present, numeric and strictly `> 0`.
2. `field_vivienda` references an existing node of bundle `vivienda` that is
   **published**.
3. That unit's `field_condominio` references an existing node of bundle
   `condominio`.

## Effects

When the trigger and all preconditions pass:

| Target | Field | Change |
|--------|-------|--------|
| Unit (`vivienda`) | `field_saldo_actual` (`decimal(10,4)`) | `saldo − field_valor` (a missing/`NULL` balance is treated as `0`). Saved as a **new revision** (`revision = 1`). |
| Condominium (`condominio`) | `field_saldo_caja` (`decimal(10,2)`) | `caja + field_valor` (a missing/`NULL` balance is treated as `0`). Saved as a **new revision** (`revision = 1`). |
| Payment (`pagos`) | `field_estado_pago` | Forced to `"Completado"` on the incoming node during presave, so `"Nuevo"` is never persisted and there is no second save nor recursion on the payment itself. |

Arithmetic is done in PHP `float` and stored in the field's `decimal`; the
floating-point imprecision already assumed in specs 10 and 17 is accepted. No
rounding beyond what `decimal(10,4)` / `decimal(10,2)` imposes.

## Cancelled scheduled tasks

After updating the balances, the four pending `rules_scheduler` rows for the
unit are deleted by `config` (component) + `identifier`, where `{nid}` is the
nid of the **referenced unit** (not the payment):

| `config` (component) | `identifier` |
|----------------------|--------------|
| `rules_recordatorio_pago` | `recordatorio {nid}` |
| `rules_recalcular_con_penalizacion` | `penalizacion 10 {nid}` |
| `rules_recalcular_con_penalizacion_15` | `penalizacion 15 {nid}` |
| `rules_recalcular_con_penalizacion_31` | `penalizacion 31 {nid}` |

The delete is idempotent: if a matching row does not exist, nothing is removed
and no error is raised. This spec only **cancels** pending tasks; creating or
rescheduling those reminders/penalties stays in the existing Rules system.

**Assumption:** `rules_scheduler` is installed (it is today). If the module is
disabled, `db_delete('rules_scheduler')` would fail on the missing table; making
it optional would require wrapping the delete in `db_table_exists('rules_scheduler')`.

## Notification on approval

When a payment reaches `field_estado_pago = "Completado"`,
`myapi_payment_notify_approved($node)` (in
`includes/myapi.payment_workflow.inc`) notifies the payment's author via
`myapi_notification_create()`. There are three independent triggers:

| Trigger | Where | Detail |
|---------|-------|--------|
| Verification transition `"Pendiente de verificar"` → `"Completado"` | End of `myapi_payment_apply_verification()` (`hook_node_presave`) | The transition above. `$node->nid` already exists (it is an update). |
| Direct creation already `"Completado"` | New branch in `hook_node_insert()` (`myapi.module`) | No preconditions: notifies with whatever the node has. |
| `"Nuevo"` → `"Completado"` via the legacy Rule `rules_actualizar_saldo_pago` | New `hook_node_update()` (`myapi.module`), guarded by `myapi_payment_is_rule_completion($node)` | A `pagos` node created directly in `"Nuevo"` (typically from the Drupal admin) is picked up by that still-active Rule, which applies the same balance changes as this file's presave logic and sets `field_estado_pago` to `"Completado"` via a `data_set` action. Rules' auto-save persists that change with a second `node_save()` (an update), which this hook observes — it does not repeat the balance work, only detects the transition and notifies. |

Regardless of which trigger fires, the generated message is the same:

| Field | Value |
|-------|-------|
| Recipient (`uids`) | `[(int) $node->uid]` — only the payment's author, not the unit's owners/occupants. |
| `title` | `"Pago aprobado — Ref. {reference}"` |
| `body` | `"Tu pago de {amount} ha sido aprobado.\nReferencia: {reference}\nGracias."`, with `{amount}` formatted to 2 decimals (`number_format`). |
| `type` / `source_type` / `deep_link.target` | `"payment_approved"` / `"payment"` / `"payment"` (`deep_link.id` = the payment's nid). |
| `unit_id` / `condominium_id` | Resolved from `field_vivienda` (and that unit's `field_condominio`) when present; `NULL` otherwise. Best-effort context, not a precondition. |

Missing `field_referencia`/`field_valor`/`field_vivienda` (possible on a
directly-created node, which does not go through the preconditions above) do
not block the notification: it is built with whatever the node has (empty
reference, `"0.00"` amount, `NULL` unit/condominium).

Text is fixed in Spanish, not translated via `myapi_t()` — same criterion as
the bulletin notification body.

## Notification on cancellation

When a `pagos` node is **updated** and `field_estado_pago` moves to
`"Anulado"` from any other status, `myapi_payment_notify_cancelled($node)`
(in `includes/myapi.payment_workflow.inc`) notifies the payment's recipient
via `myapi_notification_create()`. Detected by
`myapi_payment_is_cancellation_transition($node)`, hooked in the `pagos`
branch of `hook_node_update()` (`myapi.module`), as an `elseif` sibling of the
approval transition above.

| Source | Read | Value required to fire |
|--------|------|------------------------|
| `$node->original->field_estado_pago` | previous status (in DB) | anything other than `"Anulado"` |
| `$node->field_estado_pago` | incoming status | `"Anulado"` |

- Only fires on **update** (`$node->original` must be set); a node inserted
  directly in `"Anulado"` never notifies.
- Any prior status qualifies (`"Pendiente de verificar"`, `"Completado"`,
  etc.) as long as it is not already `"Anulado"` — this only guards against
  re-firing on an update that leaves the payment cancelled (e.g. editing an
  already-cancelled payment without touching its status).

### Opt-out for `PUT /api/v1/payments/%/cancel`

`myapi_payment_cancel()` (spec 23, `resources/payment.resource.inc`) sets
`$node->myapi_skip_cancel_notification = TRUE;` before its own `node_save()`.
`myapi_payment_is_cancellation_transition()` checks this flag first and
returns `FALSE` when it is set, so the resident cancelling their own payment
through the endpoint is never notified of it — the notification only fires
for a cancellation made from the Drupal back office (by an administrator, on
someone else's payment).

### Recipient, message and metadata

| Field | Value |
|-------|-------|
| Recipient (`uids`) | `myapi_payment_notify_recipients($node, $unit_id)` — same resolution as the approval notification: the payment's author (`node->uid`), unless the author has the `administrator` role, in which case the unit's occupant(s) are notified instead (falling back to the author if none resolve). |
| `title` | `"Pago anulado — Ref. {reference}"` |
| `body` (with `field_detalle`) | `"Tu pago de {amount} ha sido anulado.\nMotivo: {detalle}\nReferencia: {reference}"` |
| `body` (without `field_detalle`) | `"Tu pago de {amount} ha sido anulado.\nReferencia: {reference}"` (no `Motivo:` line when `field_detalle` is `NULL` or empty after `trim()`) |
| `{amount}` | Formatted to 2 decimals (`number_format`); `"0.00"` if `field_valor` is missing. |
| `type` / `source_type` / `deep_link.target` | `"payment_cancelled"` / `"payment"` / `"payment"` (`deep_link.id` = the payment's nid). |
| `unit_id` / `condominium_id` | Resolved from `field_vivienda` (and that unit's `field_condominio`) when present; `NULL` otherwise. Best-effort context, not a precondition — the same resolution block as the approval notification, duplicated rather than shared. |

Missing `field_referencia`/`field_valor`/`field_vivienda` do not block the
notification: it is built with whatever the node has (empty reference,
`"0.00"` amount, `NULL` unit/condominium).

Text is fixed in Spanish, not translated via `myapi_t()` — same criterion as
the approval notification and the bulletin notification body.

## Out of scope

- A REST verification endpoint (`PUT /api/v1/payments/%/verify`).
- Rejecting/cancelling a payment or **reverting** balances.
- Re-adjusting balances when editing a payment that is already `"Completado"`.
- Creating or rescheduling reminders/penalties, or migrating `rules_scheduler`
  to custom code.
- Notifying the unit's owners/occupants (besides the payment's author) or
  other status transitions (rejected, cancelled) on approval.
