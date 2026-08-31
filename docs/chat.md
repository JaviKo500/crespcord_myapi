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
derived, never read from a field. `field_firebase_path`, `field_chat_opened_at`
and `field_last_message_at` (SPEC 77) are **still empty** and this feature never
writes them.

The message shape the rules below enforce:

```
service_offers/901/messages/{push_id}
    from  -> the sender's uid, as a string  (must equal auth.uid)
    text  -> up to 2000 characters
    at    -> Firebase's server clock
```

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

| Variable | Default |
|----------|---------|
| `myapi_flood_chat_token_ip_limit` | 60 |
| `myapi_flood_chat_token_ip_window` | 3600 |

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

- **Open the thread, or write the three chat fields of SPEC 77.** With the path
  derived from the `nid`, the chat works without them. They get written the day
  the back office has to see a thread.
- **Notify a new message.** Neither push nor inbox: a chat message does not
  appear in `myapi_notifications` and OneSignal never fires. That is the sibling
  spec, and it is the first thing to build after this one.
- **Attachments.** Firebase Storage has rules and a credential of its own.
- **Immediate revocation.** See above: up to one hour.
- **Deploy the rules.** By hand, from the console.
- **Retention, moderation and deletion.** Firebase deletes nothing on its own.
- **The back office.** An operator does not see the chat on `node/N`.
