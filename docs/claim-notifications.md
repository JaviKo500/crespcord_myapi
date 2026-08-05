# Claim notifications (created / published / new transaction / closed)

These are **not REST endpoints**. They are three behaviors triggered around the
claim lifecycle (SPEC 68), plus a fourth one added by SPEC 70:

- A claim **created** notifies its requester (push + inbox + email) and, when
  the claim is public, every other owner and occupant of its condominium. Only
  when the claim was filed **from the app** does the detail email also reach the
  `backend` role and the building admins of that condominium.
- A claim that goes from **private to public** notifies the neighbours of its
  condominium, requester excluded.
- A **new transaction** on a claim notifies the requester and, when the claim is
  public, the neighbours — each with their own text. The automatic initial
  transaction is deliberately silent.
- A **claim closed by its own requester** from the app (`PUT /api/v1/claims/%/close`,
  SPEC 70) notifies **only the back office**, by email. No push and no inbox row
  for anybody: the resident pressed the button and the `200` is their
  acknowledgement.

No endpoint changed its contract: `POST /api/v1/claims` still answers `201` with
the same body, `POST /api/v1/claims/{id}` still answers `200` with the same body
of SPEC 67, and every `GET` is byte for byte what it was. See `docs/claim.md`
for the API itself.

**The first three triggers live in node hooks, not in the endpoints.** A claim is
created by two paths (`POST /api/v1/claims` and `node/add/reclamo`) and turned
public by two more, so hooking the endpoints would have duplicated the call and
left the back office out. The API contributes **one flag** and nothing else.

The fourth one is the exception that proves the rule: the closure of a claim is
a `claim_transaction` like any other, and nothing in the node tells you the
requester wrote it rather than an operator. Only the endpoint knows, so only the
endpoint can call it — and the transaction it saves carries
`myapi_skip_claim_notification`, which is what keeps the regular transaction
notification from reaching the resident about their own click.

**A claim has no unit.** The `reclamo` bundle carries `field_condominium` and no
`field_unit`, which is why `unit_id` is `NULL` in every `myapi_notifications`
row this feature writes, and why the back-office email has no `Vivienda` line.

---

## Files

| File | Role |
|------|------|
| `includes/myapi.claim_notification.inc` | The whole behavior: constants, the equivalent row, the three detectors, the recipient resolvers (`myapi_claim_admin_uids()` among them), the pure text builders and the four orchestrators. |
| `includes/myapi.notification.inc` | `myapi_notification_create()` (inbox row + push enqueue) and, since this spec, the two generalized back-office recipient queries: `myapi_notification_role_uids()` and `myapi_notification_building_admin_uids()`. |
| `includes/myapi.mail_queue.inc` | Generic deferred mail queue (`myapi_mail_send`), shared with the reservation emails. |
| `includes/myapi.mail.inc` | The five mail formatters and their four HTML builders, on the shared CrespCord shell. |
| `myapi.module` | Glue only: the six `hook_mail()` branches, the two `hook_node_insert()` calls and the new `reclamo` branch of `hook_node_update()`. |
| `resources/claim.resource.inc` | **One line**: the origin flag, before the `node_save()` of `myapi_claim_create()`. |
| `includes/myapi.claim_transaction_admin.inc` | **One line**: the opt-out flag, before the `node_save()` of `myapi_claim_transaction_create_initial()`. |
| `myapi.install` | Maps the five mail keys to `MyapiHtmlMailSystem` (`myapi_html_mail_keys()`, applied by `myapi_update_7024()`). |

After deploying, run:

```bash
drush updb && drush cc all
```

`drush updb` is **not optional**: without `myapi_update_7024()` the five keys
fall back to `DefaultMailSystem` and the HTML body arrives converted to plain
text.

---

## Triggers

| Event | Fires from | Detector |
|-------|-----------|----------|
| Claim created | `myapi_node_insert()`, `reclamo` branch, **after** the initial transaction is created | none — it always runs; the flag only decides whether the back office is mailed |
| Claim `private` → `public` | `myapi_node_update()`, `reclamo` branch | `myapi_claim_is_publication_transition()` |
| New transaction | `myapi_node_insert()`, `claim_transaction` branch, **after** the status sync | `myapi_claim_transaction_is_notifiable()` |

Both insert branches notify **after** their data work, never before: the claim
already has its timeline when it is announced, and a failure of the notifier
can never keep the initial transaction or the status sync from happening.

### The full matrix

| Event | Origin | Recipients | Channels |
|-------|--------|-----------|----------|
| Claim created, **private** | Back office | Requester | push + inbox + email |
| Claim created, **public** | Back office | Requester (own text) + rest of the condominium (neighbour text) | push + inbox + email |
| Claim created, **private** | App (API) | Requester · **+ detail email to `backend` and `administrador edificio`** | push + inbox + email |
| Claim created, **public** | App (API) | Requester + rest of the condominium · **+ detail email to the back office** | push + inbox + email |
| Claim `private` → `public` | App or back office | Condominium **minus** the requester | push + inbox + email |
| New transaction, claim **private** | Back office | Requester | push + inbox + email |
| New transaction, claim **public** | Back office | Requester (own text) + rest of the condominium | push + inbox + email |

The requester is **always excluded from the neighbour fan-out**. That is what
guarantees **one notification per person**, with the text that belongs to them:
*"Se publicó un nuevo reclamo en tu condominio"* is simply false for the person
who wrote it.

### Who exactly is a neighbour

`myapi_claim_condominium_uids($condominium_id, $exclude_uid)` wraps
`myapi_condominium_member_uids([$condo], 'todos')` — the same universe the
condominium bulletin already resolves (SPEC 09) — and adds two things:

- only **active** accounts (`users.status = 1`);
- the requester excluded.

The audience is therefore the **owners and occupants of the units of the
claim's condominium**, and nobody else. It matches the visibility rule of
SPEC 64: a public claim is readable by the neighbours of its condominium, so
notifying further would announce something the recipient cannot open.

### Who exactly is a back-office recipient

The **union**, deduplicated, of:

- `myapi_notification_role_uids('backend')` — every active user holding
  `backend`, matched by role **name** and never by rid (the rid varies per
  environment). `uid 1` is included only if the role is actually assigned to it.
- `myapi_notification_building_admin_uids($condominium_id)` — the active users
  holding `administrador edificio` whose `field_condominio_admin` contains the
  claim's condominium (SPEC 49).

Somebody holding **both** roles receives **one** email. Building admins of other
condominiums receive nothing: that would be noise and a leak of other residents'
data. A claim with no condominium resolved reaches no building admin — and costs
no query.

These two functions are the ones SPEC 48 used to carry as
`myapi_reservation_backend_uids()` and `myapi_reservation_building_admin_uids()`;
those two are now one-line wrappers that delegate here, so the reservation
emails behave exactly as before.

### What does NOT notify anybody

| Case | Why |
|------|-----|
| Editing a claim's subject, description, condominium, type or files | Only the visibility transition is a trigger. None of those changes who can see the claim. |
| Turning a claim from `public` back to `private` | You cannot un-notify whoever already read it, and the notice would only point at something that stopped being available. |
| Editing an already public claim, leaving it public | The detector requires the previous value to be different from `public`. |
| The automatic initial transaction | It carries the opt-out flag. The fact it records — *"the claim was received"* — is already announced by the creation of the claim. |
| Editing an existing transaction | Only the **creation** of a transaction is a trigger. Correcting a typo in a comment sends nothing. |
| Deleting a claim or a transaction | Not a trigger. |
| A claim created from the back office, **as far as the back office is concerned** | No origin flag, so no detail email. The operator who filed it already knows. |
| A transaction or a publication, **as far as the back office is concerned** | Those emails do not exist. Both events are written by the operator; mailing them back what they just typed is noise. |

---

## The two transient flags

Both are **object properties, not fields**: they are never persisted and live
only for the `node_save()` that carries them. In Drupal 7 the node hooks receive
the same `$node` object the caller modified, which is what makes them visible —
the same mechanism SPEC 30 and SPEC 48 already run in production.

| Flag | Set by | Read by | Effect |
|------|--------|---------|--------|
| `$node->myapi_claim_from_api` | `myapi_claim_create()` (SPEC 66), before its `node_save()` | `myapi_claim_is_creation_from_api()` | Present → the detail email also goes to the back office. Absent → only the resident is notified. |
| `$transaction->myapi_skip_claim_notification` | `myapi_claim_transaction_create_initial()` (SPEC 57), before its `node_save()` | `myapi_claim_transaction_is_notifiable()` | Present → notifies nobody. This is what tells "the first transaction" apart from the rest, with no row counting. |

**The default of each one is the safe one.** With no origin flag no back-office
email goes out; with no opt-out flag a transaction does notify. A future path
that creates claims or transactions without marking anything — drush, a
migration, an import — does the conservative thing in the first case and the
expected thing in the second.

Silencing a new path is one line:

```php
$transaction->myapi_skip_claim_notification = TRUE;   // before node_save()
```

Worth knowing for a **bulk import of transactions**: without that line, each one
fires its own notification.

### The publication detector

`myapi_claim_is_publication_transition($node)` returns `TRUE` only when **all**
of these hold:

1. `$node->original` exists (it is an update of a stored node, not an insert).
2. The previous `field_visibility` is **not** `public`.
3. The incoming `field_visibility` **is** `public`.

The literal comes from `MYAPI_CLAIM_VISIBILITY_PUBLIC`, the single source in
`includes/myapi.claim_notification.inc`. The stored value itself is created by
`_myapi_claims_install()` in `myapi.install`.

---

## Push + inbox

One call to `myapi_notification_create()` inserts the inbox rows and enqueues
the OneSignal push, exactly as bulletins, payments, fees and reservations do.
The inbox is synchronous and immediate; the push is deferred to the
`myapi_onesignal_push` queue.

Common to **every** row this feature writes:

| Column | Value |
|--------|-------|
| `source_type` | `claim` |
| `source_nid` | nid of the **claim** (never the transaction's) |
| `deep_link_target` | `claim` |
| `deep_link_id` | nid of the claim |
| `condominium_id` | `field_condominium` of the claim, or `NULL` |
| `unit_id` | always `NULL` |

> **`deep_link.target = "claim"` is a new value for the app.** Until now only
> `bulletin`, `payment`, `receipt`, `extra_fee` and `reservation` existed. A
> client that does not know it **must degrade to opening the inbox**, never
> break. The inbox shows the notification regardless, so nothing is lost while
> the deep link is not implemented yet. The deep link always points at the
> **claim**, never at a transaction: the detail screen with its full timeline is
> where the news has context.

### Creation — to the requester (`type: claim_created`)

```
Título:  Reclamo recibido
Cuerpo:  Fuga de agua en el pasillo
         Recibido el 04/08/2026 16:45
```

With `field_claim_type = requirement` the title reads `Requerimiento recibido`.

### Public creation and publication — to the neighbours (`type: claim_published`)

```
Título:  Nuevo reclamo en tu condominio
Cuerpo:  Fuga de agua en el pasillo
         Publicado el 04/08/2026 16:45
```

`Publicado el` is the **reception date** when the claim is born public, and the
**save time** when it turns public later. In both cases the line states the same
thing: the instant that neighbour could see it.

### Transaction — to the requester (`type: claim_transaction_created`)

```
Título:  Tu reclamo pasó a "En proceso"
Cuerpo:  Fuga de agua en el pasillo
         Se asignó un técnico para revisar la tubería del tercer piso.
         05/08/2026 09:30
```

### Transaction — to the neighbours (same `type`)

```
Título:  Novedad en un reclamo de tu condominio
Cuerpo:  Fuga de agua en el pasillo
         Estado: En proceso · 05/08/2026 09:30
         Se asignó un técnico para revisar la tubería del tercer piso.
```

The status goes in the **title** for the requester — it is the only thing read
on a locked screen — and in the **body** for the neighbour, whose title has to
say first that the claim is not theirs.

### Rules that apply to every text

- The **noun follows `field_claim_type`**: `Requerimiento recibido`,
  `Nuevo requerimiento en tu condominio`, `Tu requerimiento pasó a …`. An
  unknown or missing type reads as `reclamo`, the bundle's own name.
- Dates are `d/m/Y H:i`, the format the calendar, the claims panel and the
  reservation emails already use. `YYYY-MM-DD` appears in no user-facing text.
- The **subject is cut to 80 characters** with `…` and the operator's **comment
  to 120**, both on a word boundary. A subject of 80 characters or fewer appears
  whole and without an ellipsis.
- The full body still goes through `myapi_onesignal_truncate_body()` (200-char
  cut). With the subject already cut to 80, that cut can only ever reach the
  tail of the comment, never the first line.
- The texts are **fixed in Spanish** and do not go through `myapi_t()`, the same
  criterion as specs 27/28/30/48: these fire inside `node_save()`, where there
  is no `Accept-Language` to resolve, and translating only the API-born half
  would leave two notifications of the same claim in two different languages.
- Everything travels **raw**: these bodies are stored in `myapi_notifications`
  and rendered by the app as plain text, never as HTML.

### Degraded texts

| Case | Behavior |
|------|----------|
| Empty comment | Its line is dropped; the body stays at two lines, with no blank line dangling. |
| `field_status` with no resolvable label | The requester's title falls back to `Novedad en tu reclamo`; the neighbour's middle line loses its `Estado: … · ` prefix and keeps the bare date. |
| Missing or unknown `field_claim_type` | Reads as `reclamo` / `Reclamo`, the same criterion as SPEC 61. |
| Empty subject | Its line is dropped rather than opening the body with a blank one. |

---

## Emails

All six are HTML (CrespCord branding, inline styles, the logo of
`myapi_mail_logo_url()`) and all six leave through the deferred mail queue.

| Mail key | Recipient | Subject |
|----------|-----------|---------|
| `claim_created_requester` | The requester | `Reclamo recibido — {asunto}` |
| `claim_published_neighbour` | The neighbours (public creation **and** publication) | `Nuevo reclamo en tu condominio — {asunto}` |
| `claim_transaction_requester` | The requester | `Novedad en tu reclamo — {asunto}` |
| `claim_transaction_neighbour` | The neighbours | `Novedad en un reclamo de tu condominio — {asunto}` |
| `claim_created_admin` | `backend` + the building admins of the condominium | `Nuevo reclamo #{nid} — {condominio}` |
| `claim_closed_admin` | `backend` + the building admins of the condominium | `El solicitante cerró el reclamo #{nid} — {condominio}` |

The noun of every subject follows `field_claim_type`. The claim's subject is cut
to 80 characters in the **mail subject line** only — the data block always shows
it whole. `claim_created_admin` and `claim_closed_admin` carry the nid and the
condominium instead, so they are the two keys that are never truncated — they
are the two an operator triages by claim, not by headline.

The two back-office keys share their audience, resolved once by
`myapi_claim_admin_uids()`: `backend` plus the building admins of the claim's
condominium, deduplicated, so somebody holding both roles gets one email.

### To the residents

Greeting, a context sentence, a data block and a footer. The requester's email
greets them by name (`Hola Javier Correa`, from `field_nombre` +
`field_apellidos`, SPEC 54, falling back to the username); the neighbour emails
greet with `Hola` alone, because resolving the display name of every owner and
occupant of a condominium would be one query per recipient just for a greeting.

| Key | Context sentence |
|-----|------------------|
| `claim_created_requester` | `Hemos recibido tu reclamo. Será revisado por la administración y te notificaremos cualquier novedad.` |
| `claim_published_neighbour` | `Se publicó un nuevo reclamo en tu condominio.` |
| `claim_transaction_requester` | `Tu reclamo tiene una novedad.` |
| `claim_transaction_neighbour` | `Un reclamo de tu condominio tiene una novedad.` |

The first one is **the same sentence SPEC 61 stores** in the claim's initial
transaction, in the second person, so the email and the first row of the
timeline say literally the same thing.

Data block of the two **creation / publication** emails:

| Line | Example |
|------|---------|
| Asunto | `Fuga de agua en el pasillo` |
| Tipo | `Reclamo` |
| Condominio | `Residencias El Parque` |
| Estado | `Recibido` |
| Recibido el | `04/08/2026 16:45` |
| Descripción | text block, `check_plain()` + `nl2br()` |

`Recibido el` always carries the claim's **real reception date**, including in
the neighbour email of a claim published days later: the label says "received",
so printing the publication instant under it would be false. The date that
moves is the `Publicado el` line of the push, whose label does say what it
means.

Data block of the two **transaction** emails — no description of the claim, and
the operator's comment **in full** (there is no 200-character budget to respect
here):

| Line | Example |
|------|---------|
| Asunto | `Fuga de agua en el pasillo` |
| Estado | `En proceso` |
| Fecha | `05/08/2026 09:30` |
| Comentario | text block |

Footer of all four: `Reclamo #141` (`Requerimiento #141` when the type says so).

### To the back office (`claim_created_admin`)

```
Se registró un nuevo reclamo desde la aplicación.

  Reclamo        #141
  Asunto         Fuga de agua en el pasillo
  Tipo           Reclamo
  Visibilidad    Privado
  Estado         Recibido
  Solicitante    Javier Correa
  Email          javiko500@gmail.com
  Condominio     Residencias El Parque
  Recibido el    04/08/2026 16:45
  Adjuntos       2 imágenes, 1 documento

  Descripción
  La mancha llega ya hasta la puerta 3-B y no para de crecer.

        [ Abrir en el back office ]   →   {base}/node/141
```

- **No `Vivienda` line**: the `reclamo` bundle has no `field_unit`, and deriving
  one from the requester's units is ambiguous the moment they own two in the
  same condominium.
- **`Adjuntos` is a count and never a link**: the files live in `private://` and
  their download requires a token (SPEC 65), so a link from a mail client would
  answer `404`. The line is omitted when the claim carries no file.
- **The button** points at the absolute URL of `node/{nid}`, so it opens the
  claim in the back office after login.
- **One email per recipient**, never one with everybody in copy: an invalid
  address does not drag the rest down, and no operator sees the others'
  addresses.

### To the back office (`claim_closed_admin`)

The only status change the back office did not make itself, so the only one it
has to be told about (SPEC 70):

```
El solicitante cerró su reclamo desde la aplicación.

  Reclamo        #141
  Asunto         Fuga de agua en el pasillo
  Condominio     Residencias El Parque
  Solicitante    Javier Correa
  Estado         Cerrado
  Cerrado el     05/08/2026 11:20

  Motivo
  Ya lo resolví directamente con el vecino.

        [ Abrir en el back office ]   →   {base}/node/141
```

- **The reason is the point of this email** and is the only line that can be
  long, so it goes last and travels whole — `check_plain()` + `nl2br()`, like
  every other free-text line.
- **No `Descripción` line**: the operator opening the claim from the button sees
  it in full, and repeating it here would bury the reason, which is the only
  thing this email adds to what they already knew.
- **Best-effort and after the write**: the closure is already stored when the
  mail is enqueued, so no mail failure can turn the endpoint's `200` into a
  `500`. A condominium with no `backend` user and no building admin simply
  notifies nobody.
- **The requester is not in copy.** They are the one who closed it.

---

## The mail queue

`myapi_mail_send`, the same generic queue the reservation emails use
(`includes/myapi.mail_queue.inc`):

```php
myapi_mail_queue_enqueue($key, $to, $params);   // one item per recipient
```

- **Delivery depends on cron.** The push and the inbox row are the immediate
  channel; the emails leave on the next cron run.
- **Retries:** a failed send is re-enqueued with an incremented counter up to 3
  attempts and then dropped with a `watchdog` of level *error*, so a permanently
  rejected address can never block the queue.
- **The params travel already resolved and escaped** (strings, never nids). The
  email describes what was true at trigger time: deleting the claim or renaming
  the condominium between the trigger and the cron run neither breaks nor alters
  the send.

```bash
drush cron                       # drains every queue
drush queue-run myapi_mail_send  # drains only this one
```

See `docs/notifications-produccion.md` — a cron that only runs
`queue-run myapi_onesignal_push` will enqueue these emails and never send them.

---

## Volume and privacy — read before making claims public

**A public claim notifies the whole condominium.** In a 200-unit building that
is roughly 400 inbox rows, 400 pushes and 400 queued emails **per event** — and
each new transaction on that claim does it again.

**"Public" does not mean "visible if somebody goes looking". It means "sent to
everybody".** The subject and, in the email, the full description of the claim
reach the mailbox of every owner and occupant of the condominium. A resident may
mark a claim public without realising that. The app should say so plainly in the
visibility selector.

**A claim made public by mistake is irreversible.** Turning it private again
un-sends nothing and un-delivers no push. The inverse transition notifies
nobody, precisely so as not to draw attention to what somebody wanted hidden.

There are **no per-user notification preferences** today: nobody can mute a
channel. If the noise ever becomes a problem, the clean hook is filtering the
uid list inside `myapi_claim_condominium_uids()` — no text and no trigger would
have to change.

**Moving a claim to another condominium** (SPEC 67 allows it) leaves the
neighbours of the previous one already notified, and sends the following
transactions to the new one. That is the correct outcome: whoever stops being
able to see the claim stops receiving its news. What was already sent cannot be
withdrawn.

---

## Degraded cases

| Case | Behavior |
|------|----------|
| Claim with an empty `field_requester` | Nobody is notified through the requester path and a `watchdog` warning is logged. The public fan-out, if the claim is public, still happens. Never an accidental fan-out. |
| Requester with no email address | Push and inbox go out normally; the email is skipped with a `watchdog` warning, and the endpoint answers exactly the same. |
| Claim with no condominium resolved | No neighbour fan-out and no `administrador edificio` email; its row carries `condominium_id = NULL`. The `backend` email still goes out. |
| Deleted requester account | `Usuario eliminado (#789)` in the back-office email. |
| Deleted condominium | `Sin condominio`. |
| Transaction with an empty `field_comment` | Two-line body, no dangling blank line; the email drops its `Comentario` line. |
| `field_status` with no resolvable label | Title `Novedad en tu reclamo`, no `Estado:` prefix for the neighbour, `—` in the emails. No PHP notice. |
| Missing or unknown `field_claim_type` | Reads as `Reclamo`, same as SPEC 61. |
| Condominium with no active owners or occupants | Nothing is enqueued and no error is produced. |
| Nobody holds `backend` and no building admin matches | Creation from the app works exactly the same; no detail email is enqueued. |
| An invalid address in the recipient list | One queue item per recipient, so the others are unaffected. |
| Any failure while notifying | Caught and logged with `watchdog_exception()`. Best-effort by contract: the claim is already committed, so nothing here may turn a successful `201` into a `500`, and nothing may break a node save. |
| Re-save of the same node within one request | A `drupal_static()` guard keyed by nid in each orchestrator means a node notifies at most once per request. |

---

## Related docs

- `docs/claim.md` — the claim endpoints themselves.
- `docs/claims-list.md` — the back-office listing and the visibility rules.
- `docs/claim-transaction-timeline.md` — the timeline the deep link opens.
- `docs/building-admin-role.md` — the `administrador edificio` role.
- `docs/notification.md` — the inbox resource the app reads.
- `docs/notifications-produccion.md` — cron and queue setup in production.
