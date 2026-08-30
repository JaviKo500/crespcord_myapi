# Service request notifications

This is **not a REST endpoint**. It is two behaviors, each hanging off its own
POST of the service marketplace:

- **Request created** (SPEC 109), off `POST /api/v1/service-requests`: when a
  resident creates a service request from the app, the providers who can
  answer it are told. See the section right below.
- **Offer received** (SPEC 110), off
  `POST /api/v1/service-requests/{id}/offers`: when a provider bids on a
  request, its resident is told. See the
  [dedicated section](#offer-received-spec-110) further down.
- **Offer withdrawn** (SPEC 111), off
  `PUT /api/v1/service-offers/{id}/withdraw`: when a provider withdraws their
  offer, its resident is told. See the
  [dedicated section](#offer-withdrawn-spec-111) further down.
- **Offer awarded** (SPEC 112), off `PUT /api/v1/service-offers/{id}/accept`:
  when a resident awards an offer, the winning provider, every losing
  provider and the `backend` role are told. See the
  [dedicated section](#offer-awarded-spec-112) further down.

## Request created (SPEC 109)

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
| `includes/myapi.service_request_notification.inc` | All four behaviors: constants, the recipient resolvers, the pure text builders and the four orchestrators (`myapi_service_request_notify_created()`, `myapi_service_request_notify_offer_received()` (SPEC 110), `myapi_service_request_notify_offer_withdrawn()` (SPEC 111) and, since SPEC 112, `myapi_service_request_notify_offer_accepted()`). |
| `includes/myapi.service_offer.inc` | `myapi_service_offer_sent_offers_for_request()` (SPEC 112) — the read-only query that lists the losing offers, next to `myapi_service_offer_reject_live()`. |
| `includes/myapi.notification.inc` | `myapi_notification_create()` (inbox rows + push enqueue), which SPEC 109 taught the `provider_id` and `audience` params, and `myapi_notification_role_uids()` for the `backend` audience. |
| `includes/myapi.provider_query.inc` | `myapi_provider_apply_active_conditions()` — the SQL half of "an active provider" (SPEC 83), reused as-is by the category lookup. |
| `includes/myapi.mail_queue.inc` | Generic deferred mail queue (`myapi_mail_send`), shared with every other email of the module. |
| `includes/myapi.mail.inc` | The seven mail formatters (two of SPEC 109, one of SPEC 110, one of SPEC 111, three of SPEC 112) and their HTML builders, on the shared CrespCord shell. |
| `myapi.module` | Glue only: the seven `hook_mail()` branches. |
| `resources/service_request.resource.inc` | **One call**, in `myapi_service_request_create()`, after the `file_usage_add()` calls and before the `201`. |
| `resources/service_offer.resource.inc` | **Three calls**: `myapi_service_offer_create()` (SPEC 110), after the offer/transition/transaction writes and before the `201`; `myapi_service_offer_withdraw()` (SPEC 111), after the `node_save()` and before the `200`; `myapi_service_offer_accept()` (SPEC 112), after the losers are swept and before the `200`. |
| `resources/notification.resource.inc` | Selects `provider_id` and exposes it as `deep_link.provider`. |
| `myapi.install` | The `provider_id` column (`myapi_update_7036()`, SPEC 109) and the seven mail keys in `myapi_html_mail_keys()`, registered by `myapi_update_7037()` (SPEC 110), `myapi_update_7038()` (SPEC 111) and `myapi_update_7039()` (SPEC 112, no schema change either). |

After deploying, run:

```bash
drush updb && drush cc all
```

`drush updb` is **not optional**: without `myapi_update_7036()` there is no
`provider_id` column to write to, and without every update hook the mail keys
they register fall back to `DefaultMailSystem`, which delivers their HTML body
converted to plain text.

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

## What does NOT notify anybody (request created)

| Case | Why |
|------|-----|
| A request created from the back office, drush or an import | The trigger lives in the endpoint. Same criterion as payments and reservations. |
| The resident who created it | They just created it and got the `201` with the full detail. |
| Any other event of the marketplace | An offer created notifies the resident (SPEC 110), withdrawn notifies the resident (SPEC 111), and awarded notifies the winner, the losers and `backend` (SPEC 112) — see their dedicated sections below. Updated (105), a cancellation (95), an edit (96), a closure or a rating (108): none of them notifies today, and none starts here. |
| Providers of the category, when the request is **direct** | Its audience is the awarded provider and nobody else. |
| Building admins | Out of this spec's audience by decision. |

---

## Robustness (request created)

The whole trigger is **best-effort**. It runs after `node_save()` and after the
`file_usage_add()` calls, it is wrapped end to end, and every failure — a broken
queue, an invalid address, a deleted node in the middle — lands in `watchdog`
and stops there. It never throws, never undoes the node and never changes the
`201` the resident receives. The inbox rows that were written before a failure
stay written: a partial notification beats none.

The provider fan-out runs **before** the back-office email, so a failure mailing
the operators cannot cost the providers their notice.

---

## Offer received (SPEC 110)

One behavior hanging off `POST /api/v1/service-requests/{id}/offers`: when a
provider creates an offer on a request, the resident who requested it
(`field_requester`) is told — through push, inbox and email, **once per
offer**, whether it is the request's first offer or its fifth.

`POST /api/v1/service-requests/{id}/offers` did not change its contract: it
still answers `201` with the same `service_offer` (fifteen keys) + `request`
body of SPEC 100. See `docs/service-offer.md` for the endpoint itself.

**One notice per offer, not one per request.** Unlike the request-created
fan-out above, this trigger has exactly one recipient — the resident — so
there is no fan-out to speak of: `myapi_notification_create()` is called once,
with `uids = [requester_uid]`. A request with three offers from three
providers produces **three independent rows**, three pushes and three emails,
all to the same resident — the accepted consequence of "each offer is its own
event."

**Unlike the provider notice above, this one carries `condominium_id` and
`unit_id`.** It is the resident's own request; there is no privacy reason to
withhold either, the way the provider notice withholds `unit_id` to keep a
provider from learning which home asked.

**`source_type`/`source_nid` name the offer; `deep_link_target`/`deep_link_id`
name the request.** The event that fired is the offer, but there is no
per-offer screen to open (SPEC 100 never built one), so the app always lands on
the resident's own request detail. The two pairs are independent columns of
`myapi_notification_create()` — nothing forces them to agree, and the
request-created notice above already uses them independently too.

**The trigger lives in the endpoint, not in a node hook.** Same criterion as
the request-created trigger: an offer typed into `node/add/service_offer` by an
operator, or created by drush, notifies nobody.

### Who is notified

| Recipient | Resolved by |
|-----------|-------------|
| The resident who created the request (`field_requester`) | `requester_uid`, already resolved by the endpoint's own eligibility gate — nothing here re-queries the request. |

A requester account deleted between the request and the offer costs nothing:
`user_load()` answers `FALSE`, and the trigger returns without writing or
enqueuing anything.

### Push + inbox

One call to `myapi_notification_create()`:

| Column | Value |
|--------|-------|
| `source_type` | `service_offer` |
| `source_nid` | nid of the offer that was just created |
| `type` | `service_offer_received` |
| `deep_link_target` | `service_request` |
| `deep_link_id` | nid of the request (not the offer) |
| `condominium_id` | `field_condominium` of the request |
| `unit_id` | `field_unit` of the request |
| `provider_id` | nid of the provider that offered |

#### The texts

```
Título:  Nueva oferta recibida
Cuerpo:  Fuga en el calentador
         Proveedor: Plomería Sur
         Monto: 150.00 (Precio cerrado)
```

- The first line is the request's own `title`.
- `Monto` reads `A presupuestar en sitio` for an `on_site_quote` offer, or
  `{amount, 2 decimals} ({label})` for `fixed` / `estimate` / `hourly` — the
  label is `myapi_services_offer_amount_types()`'s (SPEC 100), never retyped.
  An `amount_type` outside that catalogue (corrupt data) falls back to the
  `on_site_quote` text rather than breaking the notice.
- Deliberately **without** the provider's free-text `message`: it can run long
  and would crowd the push banner. The resident reads it by opening the
  detail.
- Any value that does not resolve prints as `—`, never as an empty line. The
  texts are fixed in Spanish, same criterion as every other push and email of
  the module.

#### The push payload

```json
{
  "target": "service_request",
  "id": 1420,
  "unit": 55,
  "condominium": 87,
  "notification_type": "service_offer_received",
  "audience": "resident",
  "provider": 553
}
```

### Email (`service_request_offer_resident`)

HTML (same CrespCord shell as every other mail of the module), enqueued
already resolved and escaped, delivered on the next cron.

Subject: `Nueva oferta recibida — {asunto}`.

```
Hola Ana Pérez

Recibiste una nueva oferta para tu solicitud de servicio.

  Asunto      Fuga en el calentador
  Proveedor   Plomería Sur
  Monto       150.00 (Precio cerrado)

  [ Ver solicitud ]   →  myapp://service-requests/1420

Puedes ver el detalle completo desde el botón de abajo.
```

- The greeting reads `Hola {nombre}` when
  `myapi_user_fetch_profile_fields()` resolves a name, `Hola` alone otherwise
  — same criterion as the claim emails (SPEC 68).
- **Unlike the two request-created emails above, this one has a button.** The
  resident's next step is the app, the same reason the SPEC 07 password-reset
  email has one.

#### The deep link

The button's URL is built by `myapi_service_request_app_deep_link_url()`:

```php
$base = variable_get('myapi_service_request_deep_link_base', 'myapp://service-requests');
return check_plain($base . '/' . $request_nid);
```

| Variable | Default | Note |
|----------|---------|------|
| `myapi_service_request_deep_link_base` | `myapp://service-requests` | Base of the button's deep link. **Independent** of `myapi_password_reset_deep_link_base` (SPEC 07) — same custom-scheme pattern, different variable, changing one never changes the other. |

A resident with no app installed still sees the notice in
`GET /api/v1/notifications`; the button opening nothing is the same accepted
trade-off SPEC 07 already made for password reset.

### What does NOT notify anybody (offer received)

| Case | Why |
|------|-----|
| The provider who created the offer | They just created it and got the `201` with the full offer object. |
| The `backend` role | The request itself already told them (SPEC 109); an offer on a request they already know about does not earn a second email. |
| An offer created from the back office or drush | The trigger lives in the endpoint. |
| Any other offer event | Edited (without withdrawing), awarded, request closed or rated: none of them starts here. Each is its own future spec. Withdrawn is covered — see [Offer withdrawn (SPEC 111)](#offer-withdrawn-spec-111) below. |

### Robustness (offer received)

Same discipline as the request-created trigger: **best-effort**, run after the
offer/transition/transaction writes, wrapped end to end. A failure — a broken
queue, a deleted requester account, an invalid address — lands in `watchdog`
and never undoes the offer, the transition, the transaction, or the `201` of
`POST /api/v1/service-requests/{id}/offers`.

---

## Offer withdrawn (SPEC 111)

One behavior hanging off `PUT /api/v1/service-offers/{id}/withdraw`: when a
provider withdraws their offer, the resident who requested it
(`field_requester`) is told — through push, inbox and email, **once per
withdrawal**.

`PUT /api/v1/service-offers/{id}/withdraw` did not change its contract: it
still answers `200` with the same `service_offer` + `request` body of
SPEC 105. See `docs/service-offer.md` for the endpoint itself.

**Same shape as the offer-received notice above, minus the amount.** One
recipient, one call to `myapi_notification_create()` with
`uids = [requester_uid]`, `condominium_id`/`unit_id` populated (no privacy
reason to withhold them from the resident's own request), and
`source_type`/`source_nid` naming the offer while `deep_link_target`/
`deep_link_id` name the request — same reasoning as SPEC 110. The one
difference: the withdrawn offer's amount is **not** part of the content, since
it is no longer information the resident can act on.

**A second withdraw attempt never produces a second notice.** SPEC 105's gate
answers `409 service_offer_not_withdrawable` before this trigger is ever
reached, so exactly one notice exists per successful withdrawal.

**The trigger lives in the endpoint, not in a node hook.** Same criterion as
the other two triggers of this file: a withdrawal made from the back office or
by drush notifies nobody.

### Who is notified

| Recipient | Resolved by |
|-----------|-------------|
| The resident who created the request (`field_requester`) | `requester_uid`, already resolved by `myapi_service_offer_withdraw()`'s own write gate — nothing here re-queries the offer or the request. |

A requester account deleted between the request and the withdrawal costs
nothing: `user_load()` answers `FALSE`, and the trigger returns without
writing or enqueuing anything.

### Push + inbox

One call to `myapi_notification_create()`:

| Column | Value |
|--------|-------|
| `source_type` | `service_offer` |
| `source_nid` | nid of the offer that was just withdrawn |
| `type` | `service_offer_withdrawn` |
| `deep_link_target` | `service_request` |
| `deep_link_id` | nid of the request (not the offer) |
| `condominium_id` | `field_condominium` of the request |
| `unit_id` | `field_unit` of the request |
| `provider_id` | nid of the provider that withdrew |

#### The texts

```
Título:  Oferta retirada
Cuerpo:  Fuga en el calentador
         Proveedor: Plomería Sur
```

- The first line is the request's own `title`.
- **No amount anywhere** — the offer is no longer valid, so its figure is not
  something the resident needs to decide on.
- Any value that does not resolve prints as `—`, never as an empty line. The
  texts are fixed in Spanish, same criterion as every other push and email of
  the module.

#### The push payload

```json
{
  "target": "service_request",
  "id": 1420,
  "unit": 55,
  "condominium": 87,
  "notification_type": "service_offer_withdrawn",
  "audience": "resident",
  "provider": 553
}
```

### Email (`service_request_offer_withdrawn_resident`)

HTML (same CrespCord shell as every other mail of the module), enqueued
already resolved and escaped, delivered on the next cron.

Subject: `Oferta retirada — {asunto}`.

```
Hola Ana Pérez

El proveedor retiró la oferta que había enviado para tu solicitud de servicio.

  Asunto      Fuga en el calentador
  Proveedor   Plomería Sur

  [ Ver solicitud ]   →  myapp://service-requests/1420

Puedes ver el detalle completo desde el botón de abajo.
```

- The greeting reads `Hola {nombre}` when
  `myapi_user_fetch_profile_fields()` resolves a name, `Hola` alone otherwise
  — same criterion as the offer-received email.
- **Has a button**, same reason as the offer-received email: the resident's
  next step is the app.

#### The deep link

Reuses `myapi_service_request_app_deep_link_url()` and
`myapi_service_request_deep_link_base` unchanged — see
[the offer-received section](#the-deep-link) above. No new variable.

### What does NOT notify anybody (offer withdrawn)

| Case | Why |
|------|-----|
| The provider who withdrew | They just received the `200` with the full offer object. |
| The `backend` role | The request itself already told them (SPEC 109); a withdrawal on a request they already know about does not earn a second email. |
| A withdrawal made from the back office or drush | The trigger lives in the endpoint. |
| A second withdraw attempt on the same offer | Rejected `409` by SPEC 105 before this trigger is ever reached. |
| Any other offer event | Edited (without withdrawing), a request closed or rated: none of them starts here. Awarded is covered — see [Offer awarded (SPEC 112)](#offer-awarded-spec-112) below. |

### Robustness (offer withdrawn)

Same discipline as the other two triggers: **best-effort**, run right after
the `node_save()` of the withdrawal, wrapped end to end. A failure — a broken
queue, a deleted requester account, an invalid address — lands in `watchdog`
and never undoes the withdrawal, or the `200` of
`PUT /api/v1/service-offers/{id}/withdraw`.

---

## Offer awarded (SPEC 112)

One behavior hanging off `PUT /api/v1/service-offers/{id}/accept`: when a
resident awards an offer, three audiences are told — through push, inbox and
email for two of them, and email only for the third:

- The **winning provider**, once, with the amount of their own offer.
- **Every losing provider** — every other offer that was `sent` on the same
  request and just swept to `rejected` by this same call — once each, without
  revealing who won or for how much.
- The **`backend` role**, once per active user, email only, with the full
  award detail.

`PUT /api/v1/service-offers/{id}/accept` did not change its contract: it
still answers `200` with the same `service_request` + `offers_rejected` body
of SPEC 106. See `docs/service-offer.md` for the endpoint itself.

**Same include, one more orchestrator.** `myapi_service_request_notify_offer_accepted()`
lives next to the other three of this file, reusing
`MYAPI_NOTIFICATION_SOURCE_SERVICE_OFFER` and
`MYAPI_NOTIFICATION_DEEP_LINK_SERVICE_REQUEST_PROVIDER` unchanged — the same
deep link the request-created provider notice already uses, because there is
no per-offer screen for a provider to land on.

**The losers are captured BEFORE the sweep, not after.** The endpoint reads
`myapi_service_offer_sent_offers_for_request($request_nid, $nid)` — every
`sent` offer of the request except the winner — right before calling
`myapi_service_offer_reject_live()`. After the sweep runs, an offer that just
turned `rejected` and one that already was `rejected`/`withdrawn` look
identical; only the `sent` status, read at that exact moment, tells them
apart. A provider whose offer was already terminal before this call learns
nothing new and is not notified.

**A competitor never learns who won, or for how much.** Every channel
addressed to a loser — push, inbox row and email — carries only the
request's own subject. No `provider_name`, no `amount`, nothing that
identifies the winner or their price.

**The `backend` role gets email only**, same criterion as the request-created
admin email (SPEC 109): the role has no app, so push and inbox make no sense
for it.

### Who is notified

| Recipient | Resolved by |
|-----------|-------------|
| The winning provider | `provider_id`, already resolved by `myapi_service_offer_accept()`'s own gate. |
| Every losing provider | `myapi_service_offer_sent_offers_for_request()`, read before the sweep. |
| Every active user holding `backend` | `myapi_notification_role_uids('backend')`. |

A provider (winner or loser) with no account attached — `field_provider_users`
empty — is skipped; the others are still notified. Nobody holding `backend`
means no admin email; everything else is unchanged.

### Push + inbox

One call to `myapi_notification_create()` for the winner, and one **per
losing offer**:

| Column | Winner | Each loser |
|--------|--------|------------|
| `source_type` | `service_offer` | `service_offer` |
| `source_nid` | nid of the awarded offer | nid of **that** losing offer |
| `type` | `service_offer_accepted` | `service_offer_rejected` |
| `deep_link_target` | `service_request_provider` | `service_request_provider` |
| `deep_link_id` | nid of the request | nid of the request |
| `condominium_id` | `field_condominium` of the request | same |
| `unit_id` | always `NULL` | always `NULL` |
| `provider_id` | nid of the winning provider | nid of **that** losing provider |

`unit_id` is `NULL` for the same reason as the request-created provider
notice (SPEC 109): a provider never learns which home asked.

#### The texts

```
Ganador
Título:  ¡Fuiste seleccionado!
Cuerpo:  Fuga en el calentador
         Monto: 150.00 (Precio cerrado)

Perdedor
Título:  Ya se seleccionó un proveedor
Cuerpo:  Fuga en el calentador
```

- `Monto` on the winner's notice is `myapi_service_offer_amount_text()`'s
  (SPEC 110) — the same figure the winner is about to be paid, unlike the
  withdrawn notice (SPEC 111), whose amount is stale by the time it fires.
- The loser's body is **one line**: the request's own subject, nothing else.
- Any value that does not resolve prints as `—`, never as an empty line. The
  texts are fixed in Spanish, same criterion as every other push and email of
  the module.
- A request with a single offer (no losers) produces only the winner's row —
  a silent no-op for the loser block, not an error.

#### The push payloads

```json
{
  "target": "service_request_provider",
  "id": 1420,
  "unit": null,
  "condominium": 87,
  "notification_type": "service_offer_accepted",
  "audience": "provider",
  "provider": 553
}
```

```json
{
  "target": "service_request_provider",
  "id": 1420,
  "unit": null,
  "condominium": 87,
  "notification_type": "service_offer_rejected",
  "audience": "provider",
  "provider": 601
}
```

### Emails

All three are HTML (same CrespCord shell), enqueued already resolved and
escaped, delivered on the next cron.

| Mail key | Recipient | Subject |
|----------|-----------|---------|
| `service_request_offer_accepted_provider` | One per account of the winning provider | `Fuiste seleccionado — {asunto}` |
| `service_request_offer_rejected_provider` | One per account of each losing provider | `Solicitud adjudicada — {asunto}` |
| `service_request_awarded_admin` | One per active user holding `backend` | `Solicitud adjudicada #{nid} — {condominio}` |

#### To the winning provider (`service_request_offer_accepted_provider`)

```
¡Fuiste seleccionado!

Tu oferta fue elegida para esta solicitud de servicio.

  Asunto   Fuga en el calentador
  Monto    150.00 (Precio cerrado)

Revisa la solicitud en la app.
```

**No button**, same criterion as `service_request_provider` (SPEC 109): a
provider has no back office to land on.

#### To each losing provider (`service_request_offer_rejected_provider`)

```
Ya se seleccionó un proveedor

Se seleccionó a otro proveedor para esta solicitud de servicio.

  Asunto   Fuga en el calentador

Revisa la solicitud en la app.
```

No button, no amount, no winner identity — the same rule as the push, applied
to every channel a loser reads.

#### To the back office (`service_request_awarded_admin`)

```
Solicitud adjudicada

Se adjudicó una solicitud de servicio a un proveedor.

  Asunto                 Fuga en el calentador
  Proveedor adjudicado   Plomería Sur
  Monto                  150.00 (Precio cerrado)
  Condominio             Los Robles
  Vivienda               Casa 12

  [ Ver solicitud ]   →  node/1420 (absolute)

Solicitud #1420
```

The button opens the **node view**, same reason as `service_request_admin`
(SPEC 109): the operator reads the award before touching it.

### Degraded values

| Case | Behavior |
|------|----------|
| A losing provider deleted or unpublished between the award and cron | The line prints `—`; the notice still goes out. |
| Deleted condominium or unit | The line prints `—`. |

### What does NOT notify anybody (offer awarded)

| Case | Why |
|------|-----|
| The resident who awarded | They already got the `200` with the full detail (SPEC 106). |
| The loser learning who won, or for how much | By decision — see the spec's decisions table. |
| The winner learning who lost | Not part of this notice either. |
| A provider whose offer was already `withdrawn` or `rejected` before this call | Read before the sweep by `field_offer_status = 'sent'`; already-terminal offers are not in the set. |
| A second accept attempt on the same offer | Rejected `409 service_offer_not_acceptable` by SPEC 106 before this trigger is ever reached. |
| An award made from the back office or drush | The trigger lives in the endpoint. |
| Push or inbox for `backend` | Email only, same criterion as the request-created admin email. |

### Robustness (offer awarded)

Same discipline as the other three triggers: **best-effort**, run right
after the losers are swept and before the `200` is assembled, wrapped end to
end. A failure — a broken queue, a deleted provider account, an invalid
address — lands in `watchdog` and never undoes the award, the sweep, or the
`200` of `PUT /api/v1/service-offers/{id}/accept`.
