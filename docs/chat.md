## POST /api/v1/chat/token

The **chat credential** (SPEC 115): trades this API's Bearer for a **Firebase
custom token signed by the server**, whose *custom claims* declare **which chat
threads this account takes part in**, so the Realtime Database rules can
authorise the conversation between a resident and their awarded provider
**without Firebase ever asking Drupal**.

**This endpoint carries no messages, and never will.** Messages live in
Firebase. The module does one thing: it signs who you are and which threads are
yours. Ordering, offline, unread counts, typing and presence are Firebase's, and
none of them touches this API.

**Why the server has to be involved at all.** Everything about a chat can be
done from the client except one thing: authorisation. Firebase has no way of
knowing that uid `412` is the resident of request 380 or an account of the
awarded provider — that fact lives in `field_requester`,
`field_assigned_provider` and `field_provider_users`, and an RTDB rule cannot
query an external API. So the server states it, once, signed.

**The route is called `chat/token` and not `firebase/token`** on purpose. The
app asks for *the chat credential*; what signs it is a detail of the server. If
the transport ever changes, the **body** of this response changes — not a URL
that already-published versions of the app depend on.

**Authentication:** required (Bearer access token). No role is needed: a
resident and a provider call the same route and the claims come out different.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**Request body**

**Empty.** There is not one key to send — who you are is what the Bearer says,
and which threads are yours is what the database says — so nothing is parsed and
nothing can fail. A body with keys and a body that is malformed JSON are the
same thing: **ignored**. There is no `422` on this route.

```json
{ }
```

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3Mi…",
    "expires_at": 1756701600,
    "firebase_uid": "412",
    "threads": ["service_offers/901", "service_offers/88"]
  }
}
```

| Key | What it is |
|-----|------------|
| `token` | The **custom token**. The app trades it with `signInWithCustomToken()`; it is **not** the token that talks to the database. |
| `expires_at` | Unix timestamp (integer) of the **custom token's** expiry, `iat + 3600`. Informative: the ID token the app gets in exchange refreshes itself. |
| `firebase_uid` | The Drupal uid **as a string**, which is what Firebase requires. It is the same value the rules will see as `auth.uid`. |
| `threads` | The threads this token authorises, **already trimmed**: exactly the ones inside the claim, not one more. |

**`threads` travels even though the app could derive it.** It is the list the
token *actually* authorises. **The app must not paint a chat its token does not
cover** — if this disagrees with what the app believes, this is the one that is
right.

**Zero threads is not an error.** A resident with nothing awarded yet gets
`200`, `threads: []` and a valid token. The token states an identity; that you
have no conversations yet is a datum, not an authentication failure.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method other than `POST`. Answered **before** the token and before any query. |
| 429 | `too_many_attempts` | Flood limit by **IP**: 60 per hour by default. Evaluated **before** the token — every call costs an RSA signature, and what is bounded is that cost, not a failed attempt. |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked. |
| 503 | `chat_not_configured` | The Firebase credential is missing or incomplete, or the OpenSSL extension is not available. **Not a 500**: the deployment is not broken, it is not set up. Watchdog names which of the three is missing; the response never does. |
| 500 | `chat_token_failed` | `openssl_sign()` refused the key. The real reason goes to watchdog. A key of its own and not a reused `server_error`, so the app can retry the chat without dropping the session. |

---

## POST /api/v1/chat/threads/{offer_nid}/notify

The **new-message notice** (SPEC 116): tells the **other side** of a
conversation that somebody wrote, as a push, **without Drupal seeing, storing or
carrying the text of the message**.

**This endpoint is told *that* there was a message, never *what* it said.** It
does not read the Realtime Database, it does not write to it, and it does not
receive the text. It works out who the other side is — the very rule the token
route signs into a claim — and sends a banner naming **who wrote** and **which
request** it was about. The content of the chat passes through neither this
server nor OneSignal.

**The client that wrote is what fires this.** It is the only process that knows
a message happened without somebody watching Firebase. A Cloud Function with an
`onWrite` trigger was considered and set aside: **it cannot resolve the
recipients**, because membership lives in `field_requester`,
`field_assigned_provider` and `field_provider_users` — three Drupal fields an
RTDB trigger cannot read. Any design that starts in Firebase ends up calling
this same endpoint.

### ⚠️ Two steps, in this order — read this before wiring the app

```
1.  write the message to Firebase        await ref.push({from, text, at})
2.  THEN call this endpoint              POST /api/v1/chat/threads/901/notify
                                         { "preview": text }
```

Send the **same** `text` you just wrote as `preview`: the server has no way to
check it, so sending anything else puts a line on somebody's lock screen that
does not match the message they will read.

- **Second, never first.** If the notice went out before the write and the write
  then failed, the recipient gets a banner for a message that does not exist.
- **Fire-and-forget.** Nobody waits for this response. Do not block the UI on
  it, and do not show an error if it fails: **the message is already delivered**
  — what is lost is the banner, not the conversation.
- **Retrying is safe.** A second call inside the debounce window silences
  itself, so the endpoint is idempotent within the minute with nothing written
  to make it so. Send it twice after a reconnection if that is simpler.
- **If the phone dies between step 1 and step 2**, the message still arrives and
  the banner does not. The recipient sees it when they next open the app. That
  is the accepted price of this design.

**Authentication:** required (Bearer access token). No role is needed: a
resident and a provider call the same route.

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer `<access_token>` |

**The `{offer_nid}` component is the offer's `nid`, not the thread path.**
`service_offers/901` carries a slash and does not fit in one URL component. The
path is derived by the server and answered back.

**Request body**

**One key, and it is optional.** Who wrote is what the Bearer says and which
thread is what the URL says; all the body adds is **what was written**, for the
banner's preview.

```json
{ "preview": "¿Te viene bien el jueves por la mañana?" }
```

| Key | Type | Required | If it does not fit |
|-----|------|:--------:|--------------------|
| `preview` | string | **no** | Absent, empty, whitespace only, `null`, a number, an array, or a malformed body → **ignored**, and the banner comes out with two lines instead of three |

**There is no `422` on this route**, and now that there *is* a body that is a
decision rather than an oversight. The notice is fire-and-forget — nobody reads
this response — so a `422` would be a banner lost to a validation the server can
settle by itself. Everything degrades instead: a long preview is **cut**, a
preview that is not text is **ignored**, a malformed body is **ignored whole**.

**Send plain text and do not pre-truncate it.** The server sanitises it
(`myapi_text_to_plain()`: markup out, entities decoded, **every newline
collapsed into a space**) and cuts it at **140 characters** with an ellipsis. A
`\n` left in would turn the three-line banner into four and make one message
look like two.

⚠️ **The preview is the sender's word, not the server's.** Drupal never reads
the Realtime Database, so what it forwards is what the writing client *says* it
wrote — it cannot compare the two. Treat the banner as a courtesy of the sender
about their own message. The message itself is whatever is in Firebase.

**And it is not stored.** The preview is sanitised, sent to OneSignal and
forgotten: no table, no log, no watchdog entry. The conversation still lives in
exactly one place.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "thread": "service_offers/901",
    "recipients": 2,
    "notified": 2,
    "muted": 0
  }
}
```

| Key | What it is |
|-----|------------|
| `thread` | The thread's path, derived. Sent back so the client can check it talked about the thread it thought it was talking about. |
| `recipients` | How many accounts are on the other side. **The sender is never counted.** |
| `notified` | How many were sent a banner **in this call**. |
| `muted` | How many were **not**, because they already got one for this same thread inside the debounce window. |

**`notified: 0` is a `200`, not an error.** Not when the other side has no
active account, not when the debounce silenced everyone, not when OneSignal is
unconfigured, and not when the call to OneSignal failed. The push is
best-effort end to end: **the message is already in Firebase and the chat works
without a banner.** A `5xx` here would only make the app doubt a message that
did arrive. Real failures go to watchdog, which is where they are looked at.

`notified + muted === recipients` **whenever the outgoing call succeeded**. When
OneSignal is down or unconfigured the sum is lower, on purpose: those recipients
are neither notified nor muted — nothing was sent to them and **their debounce
window was not burnt**, so the next message tries again.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 405 | `method_not_allowed` | Any method other than `POST`. Answered **before** the flood, the token and any query. |
| 429 | `too_many_attempts` | Flood limit by **IP**: 600 per hour by default. Evaluated **before** the token, because until then there is no uid to count against. The ceiling is high because chatting is many calls and a household shares one address; what really bounds the outgoing traffic is the debounce below. |
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or its user blocked. |
| 404 | `not_found` | The `{offer_nid}` **is not a thread** — it does not exist, its offer is not live, or its request is not awarded to its provider — **or it is not *your* thread**. |

**The `404` is one answer for two different things, deliberately.** Telling
"it exists but is not yours" (`403`) apart from "it does not exist" (`404`)
would turn this route into an **enumerator of live threads**: ask for `1..N` and
you would learn which offers are awarded and active. Both cases answer the same
status **and the same body**.

### Who gets told

Recipients come from the same rule the token route uses, and from the same
function the notifications of SPEC 109-112 use — not a second query:

- **The resident writes** → **every** active account of the awarded provider's
  `field_provider_users`. Membership is by company, so the notice is too.
- **The provider writes** → the request's `field_requester`, and only them.
- **The sender never gets their own push**, not even on another device. And a
  **colleague does not get a banner when their teammate writes**: the message
  came from their company, and they both already see the thread.

An account that is *both* the resident and an employee of the awarded provider
counts as the **resident** — the thread hangs off their request.

### What the banner says

With a preview — three lines:

```
Nuevo mensaje de Ferretería El Tornillo
Solicitud: Fuga en el calentador
¿Te viene bien el jueves por la mañana?
```

Without one — two, exactly as before the preview existed:

```
Nuevo mensaje de Ferretería El Tornillo
Solicitud: Fuga en el calentador
```

- The title names **the other side of the conversation**, never the individual
  employee: whoever hired talks to the company. When the resident writes, it is
  their profile name (`field_nombre` + `field_apellidos`), with their username
  as the fallback.
- **The request's line stays, and the preview does not replace it.** A provider
  with five open jobs receives five different conversations, and "¿Te viene bien
  el jueves?" with no context does not say which one. The title is what makes
  the notice actionable; the preview is what makes it worth opening.
- **The order matters.** Android and iOS show about two lines in the collapsed
  banner, so **who and about what is always visible** and the text is what you
  gain by expanding.
- The third line is the **sanitised, truncated** preview, or it is not there. It
  never appears as an empty line.

### The push `data`

**The same seven keys every other push of this API carries, plus an eighth**, so
the app does not have to learn a second format:

| Key | Value |
|-----|-------|
| `target` | `"chat"` |
| `id` | The offer's nid — what the deep link needs to open the thread |
| `thread` | `"service_offers/901"`, the derived path. **The eighth key**, and the only one no other push carries |
| `notification_type` | `"chat_message"` |
| `audience` | `"resident"` or `"provider"` — **the recipient's**, not the sender's |
| `provider` | The nid of the thread's provider |
| `condominium` | The nid of the request's condominium, **to both sides** |
| `unit` | The nid of the home **only when the recipient is the resident**. To the provider it is `null`, **always** |

**Why the unit travels one way only.** An account can hold more than one home
and the app always works *inside* one: without `unit` and `condominium` the
banner would open the thread in whatever context was last on screen — possibly
the wrong house. Towards the **provider** it is `null` even when the request has
a home, because a provider does not learn which door asked until they open the
detail endpoint; that rule is older than this endpoint and the chat does not
relax it. Knowing which complex a job is in is not knowing which door it is
behind, so `condominium` goes to both.

**If `unit` arrives `null` to the resident** — a request older than the
`field_unit` backfill — the app opens the thread **without switching context**,
which is what it already does with any notification that carries no unit. That
is not an error case.

### Collapsing, grouping and TTL

| Option | Value | Why |
|--------|-------|-----|
| `collapse_id` | `chat_{offer_nid}` | Twenty messages in a row are **one** banner that gets replaced, not twenty stacked |
| `thread_id` | The thread path | Native grouping on iOS |
| `android_group` | `chat_{offer_nid}` | The same on Android |
| `ttl` | `3600` | A chat notice from six hours ago is worth nothing. Without a TTL, a phone switched off all afternoon lights up with the whole afternoon at once |

### The debounce

**At most one banner per thread and per recipient every 60 seconds.** No new
table: it is the Flood API, over the composite identifier `{offer_nid}:{uid}`.

- A silenced recipient is a `muted++` and a `200`, **never a 429**.
- **The window is burnt only when a banner actually went out.** If OneSignal is
  unconfigured or the call failed, it is left open so the next message tries
  again.
- It does not know whether the recipient *read* anything, and it does not need
  to: they were told less than a minute ago.
- **Wanted side effect:** a retried or duplicated `POST` silences itself.

### No inbox row, and this one is on purpose

**A chat message is the first push of this API with no row in
`myapi_notifications`.** `myapi_notification_create()` is not called.

The reason is not taste: **the inbox has no way of learning that you read the
chat** — that happens in Firebase, where this module does not look — so the row
would stay `is_read = 0` **for ever** and the notifications badge would be
permanently dirty. A chat already has its own list and its own unread counter;
duplicating them in `myapi_notifications` would be a second source of truth
nobody is going to mark.

**Consequences for the app:** do not expect a chat message in
`GET /api/v1/notifications`, do not count it towards the badge, and take the
unread count from Firebase.

**The notice is sent synchronously**, not through the push queue. That queue is
drained by `drush queue-run` once a minute, and a minute of delay is fine for a
bulletin and useless for a chat: the banner would arrive after the other
person's reply.

---

## Which threads you get, and why

**One rule, and it fits in a line:**

> A thread exists when the request has a `field_assigned_provider` **and** there
> is a live offer (`sent` or `selected`) **from that provider** on that request.
> **The thread is that offer.**

It covers the two ways a thread is born today without naming either:

| Case | `field_assigned_provider` | Offer status | Thread? |
|------|---------------------------|--------------|:-------:|
| Awarded ([SPEC 106](service-offer.md)) | written when awarded | `selected` | **yes** |
| Direct, quoted ([SPEC 101](service-offer.md)) | written **at birth** | `sent`, and it stays there forever | **yes** |
| Direct, not quoted yet | written | *no offer* | no |
| `open` / `offered`, not awarded | empty | `sent` | no |
| Losers of an award | written (the winner's) | `rejected` | no |
| Withdrawn by the provider ([SPEC 105](service-offer.md)) | — | `withdrawn` | no |
| **Cancelled** ([SPEC 95](service-request.md)) | **kept**, on purpose | all swept to `rejected` | **no** |
| **Closed** ([SPEC 108](service-request.md)) | kept | **untouched** — closing sweeps nothing | **yes** |

The last two rows are **consequences of the data, not conditions**: cancelling
sweeps every live offer, so a cancelled request excludes itself with no rule
written for it; closing touches no offer, so **the conversation survives the
close** — which is what you want, because there is a warranty
(`field_offer_warranty_days`) to claim afterwards, and a warranty you cannot
write to the provider about is a useless warranty.

**Membership is by company, not by account.** `field_provider_users` is
multi-valued, so **two employees of the same provider see the same thread**. It
is what the notifications of SPEC 109-112 already do; the chat deciding
otherwise would be incoherent.

**At most 40 threads.** Firebase caps all custom claims at **1000 bytes**
together, and a thread costs about 22. The list is ordered by the request's last
activity, newest first, so if threads have to be lost, **the quietest ones go**.
This is measured, not estimated — see the claim below.

**Revocation is deferred, by design.** Cancel a request and the token already
signed keeps authorising that thread **for up to an hour**. Nobody new gets in —
membership is recomputed on every signature — and closing the window sooner would
mean writing to Firebase from the backend, which is exactly what this design
avoids.

---

## The shape of a thread

**The path is a convention, not stored data:**

```
service_offers/{offer_nid}
```

The app already holds the offer's `nid` — both
[the offer detail](service-offer.md) and the award hand it over — so the path is
derived, never read from a field. `field_firebase_path`, `field_chat_opened_at`,
`field_last_message_at` (SPEC 77) and `field_last_message_from` (SPEC 118) are
written since SPEC 117 — see
[The four mirror fields](#the-four-mirror-fields) below.

The message shape the rules below enforce:

```
service_offers/901/messages/{push_id}
    from  -> the sender's uid, as a string  (must equal auth.uid)
    text  -> up to 2000 characters
    at    -> Firebase's server clock
```

---

## The four mirror fields

Since SPEC 77 the offer has carried `field_firebase_path`,
`field_chat_opened_at` and `field_last_message_at`, and until SPEC 117 the three
of them were empty. They are written now — as a **read-only mirror for the back
office**, so an operator who opens `node/N` can tell that a conversation exists
and when it last moved. SPEC 118 added a fourth, `field_last_message_from`, and
it is the first one an **endpoint** reads back.

| Field | Written by | When | Value |
|-------|------------|------|-------|
| `field_firebase_path` | `POST /api/v1/chat/token` | The first time a credential covers this thread. **Never rewritten.** | `service_offers/{offer_nid}` |
| `field_chat_opened_at` | `POST /api/v1/chat/threads/{offer_nid}/notify` | The **first** notice of the thread. **Never rewritten.** | Unix timestamp |
| `field_last_message_at` | `POST /api/v1/chat/threads/{offer_nid}/notify` | **Every** notice. Overwritten each time. | Unix timestamp |
| `field_last_message_from` | `POST /api/v1/chat/threads/{offer_nid}/notify` | **Every** notice, **together with the one above**. | `resident` \| `provider` |

**`field_last_message_from` and `field_last_message_at` move together or not at
all.** "When the last message happened" and "which side sent it" are one fact
read twice: writing one without the other is how a date from this afternoon ends
up next to a side from last week, with nothing to say which of the two is
lying.

**The path is still derived and never read.** `myapi_chat_thread_id()` remains
the single source of truth for a thread's path — `field_firebase_path` is a copy
of what that function derives, not the place it is derived from. Delete it by
hand and the chat carries on byte for byte the same.

**The last two are read back by the listings, and only by them.** Since SPEC 118
[`GET /api/v1/service-requests`](service-request.md#the-chat-key) and
[`GET /api/v1/service-requests/provider`](service-request-provider.md#the-chat-key-is-mine-or-it-is-null)
answer a `chat` block carrying `last_message_at` and `last_message_from`. **They
are answered from these columns, so an operator who edits them by hand changes
what the app shows.** Whether the block appears at all is *not* read from a
column: it is the rule of membership, asked live, the same one the credential
signs.

Six things follow from that, and each one is a decision and not an accident:

- **The credential does not open a thread.** It writes the path and nothing
  else. A resident who launches the app and never writes would otherwise mark
  twelve conversations as "opened", and the column would end up meaning "a token
  was issued", which is what the path already says.
- **The token mirrors only the threads it authorises** — the ones in the
  `threads` array of the response, at most 40 — and never every live offer of
  the account. Writing down a thread the credential does not cover would be
  recording something that did not happen.
- **A second call writes nothing.** With the path already there and no message
  announced, the mirror runs not one write query. The cost is paid once in the
  life of a thread.
- **`field_last_message_at` moves even when no banner went out**: with every
  recipient silenced by the debounce, and with OneSignal unconfigured or down.
  The column is called `last_message_at` and not `last_push_at` — tying it to
  the transport would show a minute of somebody else's outage, forever, as a
  minute with no conversation.
- **A failure to write changes nothing.** It goes to `watchdog` and the response
  of both endpoints is identical, status and body. The chat worked without these
  three columns from SPEC 115 to SPEC 117 and it still would.
- **There was no backfill, for any of the four.** A thread that existed before
  the deploy stays empty until either side next launches the app. **Empty means
  "nobody has come back since", not "there was no conversation".** For
  `field_last_message_from` that is permanent for old messages: the messages
  live in Firebase and this server has no client for it, so nobody here can
  learn who sent one. Those threads answer `last_message_from: null` beside a
  real `last_message_at` until somebody writes again — never a guessed side.

Editing `field_firebase_path` or `field_chat_opened_at` by hand on the node form
dirties what the operator sees and nothing else. Blank `field_firebase_path` and
the next credential writes it again; type something else into it and it stays as
typed, because nothing compares it against anything. **The last two are the
exception since SPEC 118**: they reach the app in the `chat` block of the two
service-request listings, so editing them there changes what a resident reads on
their screen until the next message overwrites them.

---

## The RTDB security rules

**This is the other half of the contract: without these rules the token protects
nothing.** They are applied by hand from the Firebase console — this module does
not deploy them (automating it would need OAuth2 against the Admin API, which is
what this design exists to avoid).

**Two things have to exist in the project before the rules mean anything**, and
neither is a rule: the Realtime Database itself (create it in **locked mode** —
test mode opens everything for 30 days, which is what this whole design exists
to avoid), and **Firebase Authentication, turned on once from
Compilación → Authentication → Comenzar**. Skipping the second is the failure
described under "If `signInWithCustomToken()` fails" below: the token is signed
correctly, the rules are published correctly, and nothing works.

**Publishing this replaces the WHOLE ruleset.** If that database already holds
data for something else, merge the `service_offers` node into the existing
`rules` object instead of pasting over it.

```json
{
  "rules": {
    "service_offers": {
      "$offer": {
        ".read":  "auth != null && auth.token.threads.contains('service_offers/' + $offer)",
        ".write": "auth != null && auth.token.threads.contains('service_offers/' + $offer)",
        "messages": {
          "$msg": {
            ".validate": "newData.hasChildren(['from','text','at']) && newData.child('from').val() === auth.uid",
            "text": { ".validate": "newData.isString() && newData.val().length <= 2000" },
            "at":   { ".validate": "newData.val() === now" }
          }
        }
      }
    }
  }
}
```

Three things these rules fix, and the app's code has to respect all three:

- **`from` must be `auth.uid`.** Nobody writes a message in somebody else's
  name, not even inside their own thread.
- **`at` is Firebase's `now`, not the phone's.** Two devices with skewed clocks
  must not reorder the conversation.
- **`contains()` uses the full prefix**, never the bare `$offer`: without it,
  `'901'` would match inside `'service_offers/9013'`.

**Why the claim is a comma-separated string and not an array.** The rule engine
has no membership operator over lists: `auth.token.threads.contains(...)` works
on a string and not on an array. That limitation is what decides the shape of the
claim.

---

## The token itself

An **RS256** JWT, signed by the service account. Header
`{"alg":"RS256","typ":"JWT"}`; payload:

| Field | Value |
|-------|-------|
| `iss` | The service account's `client_email` |
| `sub` | The same `client_email` — in a custom token the two are equal |
| `aud` | `https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit` — a literal of the protocol |
| `iat` | `REQUEST_TIME` |
| `exp` | `iat + 3600` — **the maximum Google accepts**; a larger value makes it reject the whole token |
| `uid` | The Drupal uid, **as a string**, ≤ 128 characters |
| `claims` | `{"threads":"service_offers/901,service_offers/88"}` |

**The number-one confusion, written down:** the custom token is **not** what the
app uses against the database. The app trades it for an **ID token** through
`signInWithCustomToken()`, and *that* one refreshes itself every hour without
coming back here. So it does not matter that this module's access token lasts 30
minutes and the Firebase one 60 — the two clocks do not have to agree, and
**this endpoint does not need calling on every screen: once per session is
enough.**

---

## Configuration

In `settings.php` — never in the repository, and never in the `variable` table
of a shared environment:

```php
$conf['myapi_firebase_service_account'] = [
  'client_email' => 'firebase-adminsdk-xxxxx@PROJECT.iam.gserviceaccount.com',
  'private_key'  => "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n",
];
$conf['myapi_firebase_database_url'] = 'https://PROJECT-default-rtdb.firebaseio.com';
```

Both come straight out of the JSON key file the Firebase console downloads for a
service account. `myapi_firebase_database_url` **is not used by this module** —
nothing here calls the RTDB — and is documented next to its sibling because it is
what the app needs and the natural place to look for it.

Missing either half of the credential, or a PHP build with no OpenSSL
extension, answers `503 chat_not_configured` and writes to watchdog **which of
the three is missing**. The credential itself is never logged and never travels
in a response.

The flood ceiling can be tuned without a cache clear:

| Variable | Default | What it bounds |
|----------|---------|----------------|
| `myapi_flood_chat_token_ip_limit` | 60 | Signatures per hour and IP on `chat/token` |
| `myapi_flood_chat_token_ip_window` | 3600 | |
| `myapi_flood_chat_notify_ip_limit` | 600 | Calls per hour and IP on `threads/%/notify` |
| `myapi_flood_chat_notify_ip_window` | 3600 | |
| `myapi_flood_chat_notify_thread_limit` | 1 | **The debounce**: banners per thread and recipient |
| `myapi_flood_chat_notify_thread_window` | 60 | The debounce window, in seconds |

**`notify` needs no configuration of its own beyond these.** It uses the two
OneSignal variables that already exist (`myapi_onesignal_app_id`,
`myapi_onesignal_rest_api_key`) and adds no field, no table and no
`hook_update_N`.

---

## If `signInWithCustomToken()` fails

**`CONFIGURATION_NOT_FOUND` means Firebase Authentication was never turned on
for the project.** It is the first thing to check on a fresh project, it has
nothing to do with the token or with the rules, and the message says none of
that. Fix it in the console: **Compilación → Authentication → Comenzar**. That
is the whole fix — **do not enable any sign-in provider**. Custom-token sign-in
does not appear in that list and needs no toggle; it works because the token is
signed by a service account of the project. All the button does is make the
Identity Toolkit configuration exist.

The symptom is total and identical everywhere, which is what makes it
confusing: every device fails, and so does a bare `curl` carrying no token at
all —

```bash
curl -s "https://identitytoolkit.googleapis.com/v1/projects?key=$WEB_API_KEY"
```

— answers `CONFIGURATION_NOT_FOUND` too. **That call is the diagnosis**: it
takes no custom token, so an error there rules the signature, the claims and
the credential out in one step. A project with Authentication enabled answers
something else.

**Then look at the server's clock.** Google rejects a token whose `iat` is in
the future, and a server running fast breaks the chat **for every device at
once**, with an error message that says nothing about time either. Keep NTP
running.

After that: an expired or revoked API Bearer answers `401` here long before
Firebase is involved, and a `503` means the credential never reached
`settings.php` on that environment.

**A `permission_denied` from the database is a different failure and a later
one.** By then the token was accepted: what failed is the rules, and the usual
reason is a `threads` claim that does not cover the path being read. Decode the
custom token — its payload is plain base64url — and look at
`claims.threads` before touching the rules.

---

## What this feature deliberately does not do

- ~~**Open the thread, or write the three chat fields of SPEC 77.**~~ **Done by
  SPEC 117** — see [The four mirror fields](#the-four-mirror-fields). They are
  written as a mirror for the back office; the chat still works without them.
- ~~**Tell the listings whether a chat can be opened.**~~ **Done by SPEC 118** —
  the `chat` block of
  [`GET /api/v1/service-requests`](service-request.md#the-chat-key) and of
  [`GET /api/v1/service-requests/provider`](service-request-provider.md#the-chat-key-is-mine-or-it-is-null),
  plus the fourth mirror column `field_last_message_from`. Still **no unread
  count**: how many messages you have not read is Firebase's, and this server
  never learns that you read one.
- ~~**Notify a new message.**~~ **Done by SPEC 116** — see
  `POST /api/v1/chat/threads/{offer_nid}/notify` above. Push only: a chat
  message still does **not** appear in `myapi_notifications`, and that stays
  deliberate.
- **Notice a message without the client saying so.** If the phone that wrote
  dies between the Firebase write and the notify call, the message arrives and
  the banner does not.
- **Stay quiet when the recipient is looking at the thread.** Drupal cannot know
  whether they have the screen open; the client dismisses the banner in
  foreground. Real presence needs a Cloud Function.
- ~~**A preview of the text in the banner.**~~ **Added in the SPEC 116
  revision** — send `preview` in the body. What is still not done is
  **verifying** it: the server forwards the sender's word for their own message
  and cannot compare it with the Realtime Database.
- **Store the message, or even the fragment that travels.** The preview is
  sanitised, sent and forgotten.
- **Attachments.** Firebase Storage has rules and a credential of its own.
- **Immediate revocation.** See above: up to one hour.
- **Deploy the rules.** By hand, from the console.
- **Retention, moderation and deletion.** Firebase deletes nothing on its own.
- **The back office.** An operator sees the four mirror fields on `node/N` and
  nothing else: no view mode, no formatter, no "open the thread" link, and not
  one message — those live in Firebase.
