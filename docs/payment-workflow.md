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

## Out of scope

- A REST verification endpoint (`PUT /api/v1/payments/%/verify`).
- Rejecting/cancelling a payment or **reverting** balances.
- Re-adjusting balances when editing a payment that is already `"Completado"`.
- Creating or rescheduling reminders/penalties, or migrating `rules_scheduler`
  to custom code.
