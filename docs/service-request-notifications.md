# Service request notifications (request created)

This is **not a REST endpoint**. It is one behavior hanging off
`POST /api/v1/service-requests` (SPEC 109): when a resident creates a service
request from the app, the providers who can answer it are told.

- A request born **open** notifies **every active provider of its category** —
  one notice per provider — through push, inbox and email.
- A request born **direct** (created with `assigned_provider_id`) notifies
  **only the awarded provider**, through the same three channels.
- In **both** cases the `backend` role also receives a detail email.

`POST /api/v1/service-requests` did not change its contract: it still answers
`201` with the same nineteen-key body of SPEC 90. See `docs/service-request.md`
for the endpoint itself.

**One notice per provider, not one per batch.** `provider_id` is a column of the
inbox row, so an open request makes as many calls to
`myapi_notification_create()` as its category has active providers, each with
that provider's own accounts. An account that operates **two** active providers
of the category therefore gets **two** rows and **two** pushes, with a different
`provider_id` in each. That is a consequence of the model, not an oversight: the
app needs to know which provider it is entering as.

**The provider never sees the home or the person.** Not in the push, not in the
inbox row (`unit_id` is `NULL`), not in the email: no unit, no requester, no
requester email, no description. That detail belongs to
`GET /api/v1/service-requests/{nid}`, which already decides what each `viewer`
is allowed to read.

**The trigger lives in the endpoint, not in a node hook.** A request typed into
`node/add/service_request` by an operator, or created by drush or an import,
notifies nobody — the same criterion as payments (SPEC 80) and reservations
(SPEC 48), and the opposite of claims (SPEC 68), whose two creation paths made
the hook the right home. It is also why the back office is not told about
requests it created itself.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.service_request_notification.inc` | The whole behavior: constants, the two recipient resolvers, the pure text builders and the orchestrator. |
| `includes/myapi.notification.inc` | `myapi_notification_create()` (inbox rows + push enqueue), which this spec taught the `provider_id` and `audience` params, and `myapi_notification_role_uids()` for the `backend` audience. |
| `includes/myapi.provider_query.inc` | `myapi_provider_apply_active_conditions()` — the SQL half of "an active provider" (SPEC 83), reused as-is by the category lookup. |
| `includes/myapi.mail_queue.inc` | Generic deferred mail queue (`myapi_mail_send`), shared with every other email of the module. |
| `includes/myapi.mail.inc` | The two mail formatters and their HTML builders, on the shared CrespCord shell. |
| `myapi.module` | Glue only: the two `hook_mail()` branches. |
| `resources/service_request.resource.inc` | **One call**, in `myapi_service_request_create()`, after the `file_usage_add()` calls and before the `201`. |
| `resources/notification.resource.inc` | Selects `provider_id` and exposes it as `deep_link.provider`. |
| `myapi.install` | The `provider_id` column (`myapi_update_7036()`) and the two mail keys in `myapi_html_mail_keys()`. |

After deploying, run:

```bash
drush updb && drush cc all
```

`drush updb` is **not optional**: without `myapi_update_7036()` there is no
`provider_id` column to write to, and the two mail keys fall back to
`DefaultMailSystem`, which delivers their HTML body converted to plain text.

---

## Who is notified

| Request | Audience | Resolved by |
|---------|----------|-------------|
| Open (`status = open`) | Every **active** provider whose `field_categories` contains the request's category | `myapi_service_request_active_providers_for_category()` |
| Direct (`status = direct`) | Only the provider in `field_assigned_provider` | The id the endpoint already validated |
| Both | Every **active** user holding the `backend` role (email only) | `myapi_notification_role_uids('backend')` |

### What "active provider" means here

Exactly what it means in `GET /api/v1/providers`, because it is the same SQL:
`myapi_provider_apply_active_conditions()` adds the `field_license_expiry`
INNER JOIN, `node.status = 1` and `field_license_expiry_value >= REQUEST_TIME`.
So a provider that is **unpublished**, has an **expired licence** or has **no
licence row at all** receives nothing, through any of the three channels. The
comparison is `>=`, so a licence is valid throughout its expiry timestamp.

The direct branch does **not** re-check any of this:
`myapi_service_request_validate_provider()` (SPEC 90) checked it two steps
earlier in the same request, and restating the rule here would be its third
home.

### Which accounts of a provider

`field_provider_users`, filtered to `users.status = 1` and `uid > 0`. The
`proveedor` **role is not required**: the field is the source of truth of who
operates a provider (SPEC 78), so an account listed there is notified whether or
not somebody remembered to give it the role. An account listed twice on the same
provider is one recipient.

### Three empty sets that are not errors

| Case | Behavior |
|------|----------|
| A category with no active provider | Nothing is written, nothing is enqueued, the `201` is unchanged. |
| A provider with no account attached | That provider is skipped; the others are still notified. |
| Nobody holding `backend` | No detail email; everything else is unchanged. |

---

## Push + inbox

One call to `myapi_notification_create()` **per provider** inserts that
provider's inbox rows and enqueues its push, exactly as every other trigger of
the module. The inbox is synchronous and immediate; the push is deferred to the
`myapi_onesignal_push` queue.

Common to **every** row this feature writes:

| Column | Value |
|--------|-------|
| `source_type` | `service_request` |
| `source_nid` | nid of the request |
| `type` | `service_request_open` · `service_request_direct` |
| `deep_link_target` | `service_request_provider` |
| `deep_link_id` | nid of the request |
| `condominium_id` | `field_condominium` of the request |
| `unit_id` | always `NULL` |
| `provider_id` | nid of **this** provider |

> **`deep_link.target = "service_request_provider"` is a new value for the
> app**, and deliberately different from the resident's `service_request`: the
> same nid opens a different screen depending on which side of the marketplace
> is looking. A client that does not know it **must degrade to opening the
> inbox**, never break.

### The texts

| Case | `title` | `body` |
|------|---------|--------|
| Open | `Nueva solicitud de servicio` | four lines, below |
| Direct | `Nueva solicitud directa para ti` | *identical* |

```
Título:  Nueva solicitud de servicio
Cuerpo:  Fuga en el calentador
         Categoría: Plomería
         Condominio: Los Robles
         Inicio: 03/09/2026 09:30
```

The body is the same in both cases: what changes between an open and a direct
request is **who** is told, not **what** they are told. The title is what tells
them apart, and it is the only line read on a locked screen.

- `Categoría` is the `name` of the `field_category` term.
- `Condominio` is the `title` of the `field_condominium` node.
- `Inicio` is `field_desired_start` as `d/m/Y H:i`.
- Any value that does not resolve prints as `—`, never as an empty line.
- The texts are **fixed in Spanish** and do not go through `myapi_t()`, the same
  criterion as every other push and email of the module.
- The full body reaches the inbox; `myapi_onesignal_truncate_body()` cuts the
  banner to 200 characters as usual.

### The push payload

```json
{
  "target": "service_request_provider",
  "id": 1420,
  "unit": null,
  "condominium": 87,
  "notification_type": "service_request_open",
  "audience": "provider",
  "provider": 553
}
```

`audience` and `provider` are new in this spec and are emitted by **every** push
of the module. See `docs/notification.md`.

---

## Emails

Both are HTML (CrespCord branding, inline styles, the logo of
`myapi_mail_logo_url()`) and both leave through the deferred mail queue, so they
go out on the next cron.

| Mail key | Recipient | Subject |
|----------|-----------|---------|
| `service_request_provider` | One per **account** of each notified provider | `Nueva solicitud de servicio — {asunto}` · `Nueva solicitud directa — {asunto}` |
| `service_request_admin` | One per active user holding `backend` | `Nueva solicitud de servicio #{nid} — {condominio}` |

Params are enqueued **already resolved and escaped**, because the queue drains
on cron long after the request was saved: a condominium renamed or a provider
deleted in between must not change or break the message.

### To the provider (`service_request_provider`)

The same four values as the push and nothing more — the email is a copy of the
notice for the account that does not have the app open, not a richer version of
it.

```
Nueva solicitud de servicio

Un residente creó una solicitud de servicio en una de tus categorías.

  Asunto            Fuga en el calentador
  Categoría         Plomería
  Fecha de inicio   03/09/2026 09:30
  Condominio        Los Robles

Revisa la solicitud en la app.
```

For a direct request the heading and the context sentence read
`Nueva solicitud directa` and *"Un residente creó una solicitud de servicio y te
la asignó directamente."*

**There is no button**, unlike every other admin-facing email of this module: a
provider has no back office to land on, and a link into it would take them to an
access-denied page. The call to action is the app itself.

### To the back office (`service_request_admin`)

The whole request, because the `backend` role is the one audience allowed to see
it: the unit, the requester, their email and the description are here and in no
other channel of this spec.

```
Nueva solicitud de servicio

Un residente creó una solicitud de servicio desde la aplicación.

  Asunto                  Fuga en el calentador
  Tipo                    Abierta
  Categoría               Plomería
  Fecha de inicio         03/09/2026 09:30
  Vivienda                Casa 12
  Condominio              Los Robles
  Solicitante             Ana Pérez
  Email del solicitante   ana@example.com
  Descripción             El calentador del baño principal gotea desde el lunes.
  Creada el               28/08/2026 10:15

  [ Ver solicitud ]   →  node/1420 (absolute)

Solicitud #1420
```

- `Tipo` reads `Abierta`, or `Directa a {nombre comercial}` when the request was
  born with an awarded provider. A direct request whose provider was deleted in
  between still reads `Directa a —`: that it is direct is what the operator
  triages by.
- `Solicitante` is `field_nombre` + `field_apellidos`, falling back to the
  username when either is missing (`myapi_user_display_names()`, SPEC 89).
- The button opens the **node view** and not the edit form: unlike a payment
  (SPEC 80), whose next action is a status change, a request is read before it
  is touched.
- **Building admins are not included.** Only the `backend` role, as asked;
  widening the audience the way claims do (SPEC 68) is a one-line spec of its
  own.

### Degraded values

| Case | Behavior |
|------|----------|
| Deleted category, condominium or unit | The line prints `—`. |
| `field_desired_start` empty | `Inicio: —` in the push, `Fecha de inicio —` in both emails. |
| Requester with no profile name | The username. |
| Requester with no email | `—`. |
| Direct request whose provider node is gone | `Directa a —`; the notice still goes out. |

---

## What does NOT notify anybody

| Case | Why |
|------|-----|
| A request created from the back office, drush or an import | The trigger lives in the endpoint. Same criterion as payments and reservations. |
| The resident who created it | They just created it and got the `201` with the full detail. |
| Any other event of the marketplace | An offer created (SPEC 100), withdrawn or updated (105), an award (106), a cancellation (95), an edit (96), a closure or a rating (108): none of them notifies today, and none starts here. This spec covers **creation** only. |
| Providers of the category, when the request is **direct** | Its audience is the awarded provider and nobody else. |
| Building admins | Out of this spec's audience by decision. |

---

## Robustness

The whole trigger is **best-effort**. It runs after `node_save()` and after the
`file_usage_add()` calls, it is wrapped end to end, and every failure — a broken
queue, an invalid address, a deleted node in the middle — lands in `watchdog`
and stops there. It never throws, never undoes the node and never changes the
`201` the resident receives. The inbox rows that were written before a failure
stay written: a partial notification beats none.

The provider fan-out runs **before** the back-office email, so a failure mailing
the operators cannot cost the providers their notice.
