# Payment receipts — data model (SPEC 138)

This is the internal data model of the payment receipts. It is **not a REST
endpoint** and it does not generate any PDF: it is the structure and the two
helpers the emission spec (point 2 to 4 of the task sheet) will build on.

A payment receipt is an **internal document, one per verified payment**. It has
nothing to do with the SRI — there is no access key, no XML and no
`001-001-…` format — and nothing to do with the monthly `recibo` that
`GET /api/v1/units/%/receipts` exposes: that one is the statement of account,
this one is the proof of a single payment.

Everything is created programmatically. `myapi_update_7051()` creates the three
tables and `myapi_update_7052()` creates the four condominium fields; both are
idempotent and skip whatever already exists.

---

## Condominium fields

Added to the `condominio` bundle by `myapi_update_7052()`. All four are
optional, with a text or number widget, and hidden from the node display.

| Field | Type | Settings | Label |
|---|---|---|---|
| `field_ruc` | `text` | `max_length` 255 (shared field), `text_processing` 0 | RUC / Cédula |
| `field_direccion` | `text` | `max_length` 255, `text_processing` 0 | Dirección |
| `field_telefono` | `text` | `max_length` 255 (shared field), `text_processing` 0 | Teléfono |
| `field_receipt_start_number` | `number_integer` | `min` 1, default 1 | Número inicial de comprobantes |

Two different lifetimes, and the difference matters:

- **`field_ruc`, `field_direccion` and `field_telefono` are read live** when a
  receipt is rendered. A regenerated PDF therefore shows the condominium's
  current address and phone, not the ones it had at emission time.
- **`field_receipt_start_number` is read only once**, while creating the
  condominium's counter row. Changing it afterwards does not renumber anything;
  the field description warns about this. It exists so a condominium that
  already numbered receipts by hand can continue from its last number.

> `field_ruc` and `field_telefono` already existed in the local site as shared
> fields (used by `tokens_payphone` and by the user profile), with
> `max_length` 255. The update respects the existing definition and only adds
> the `condominio` instance; changing the shared field would affect those other
> bundles too.

---

## Tables

### `myapi_receipt_billing_data`

Billing data of a payer, per user and unit.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | serial | no | — | PK |
| `uid` | int unsigned | no | — | Owner of the data (`users.uid`) |
| `unit_nid` | int unsigned | no | — | Unit (`vivienda`) node |
| `first_name` | varchar 128 | no | — | Payer first names |
| `last_name` | varchar 128 | no | — | Payer last names |
| `document_number` | varchar 13 | no | — | Cédula or RUC, stored as typed |
| `email` | varchar 254 | no | — | |
| `address` | varchar 255 | no | `''` | |
| `phone` | varchar 20 | no | `''` | |
| `status` | varchar 16 | no | `active` | `active` / `inactive` |
| `created` | int | no | — | Unix timestamp |
| `changed` | int | no | — | Unix timestamp |

Index: `uid_unit_status (uid, unit_nid, status)`.

A user may keep **several rows per unit** — one per billing identity, so the
same user can invoice each unit under a different name. The rule "one `active`
per user and unit" is enforced by the endpoint code, not by the database:
MySQL has no partial unique indexes. Rows are never deleted; they move to
`inactive` so the receipts that point at them keep resolving.

### `myapi_payment_receipts`

One row per emitted version of a payment receipt.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | serial | no | — | PK |
| `condominium_nid` | int unsigned | no | — | Issuing condominium node |
| `number` | int unsigned | no | — | Sequential number within the condominium; shared by every version |
| `version` | int unsigned | no | 1 | Raised on regeneration |
| `payment_nid` | int unsigned | no | — | Payment (`pagos`) node |
| `billing_data_id` | int unsigned | yes | — | Source billing row; `NULL` if the receipt was not built from a stored row (billing rows are never deleted) |
| `billing_snapshot` | text | no | — | JSON copy of the payer data at emission |
| `security_code` | varchar 20 | no | — | Code of the QR and the barcode |
| `pdf_fid` | int unsigned | yes | — | `file_managed.fid`; `NULL` while there is no PDF |
| `status` | varchar 16 | no | `pending` | See the receipt states below |
| `created` | int | no | — | Unix timestamp |

Unique keys:

- `payment_version (payment_nid, version)` — a payment cannot have two rows
  with the same version.
- `condominium_number_version (condominium_nid, number, version)` — the second
  barrier against two simultaneous emissions taking the same number.
- `security_code` — a code identifies one version.

Index: `condominium_status (condominium_nid, status)`.

**Versions share the number.** All versions of the same payment have the same
`number`; each version has its own `security_code`, its own PDF and its own
snapshot of the payer data. When a receipt is regenerated, the previous version
moves to `superseded` and a new row is inserted with `version + 1`. That is why
a scanned old PDF can be recognised as superseded instead of looking valid.

### `myapi_receipt_sequences`

One counter per condominium.

| Column | Type | Null | Notes |
|---|---|---|---|
| `condominium_nid` | int unsigned | no | PK; condominium node |
| `last_number` | int unsigned | no | Last number handed out; `0` when none has been emitted |
| `changed` | int | no | Unix timestamp of the last change |

The counter lives in its own table so the next number is reserved with
`SELECT … FOR UPDATE` inside the receipt's transaction, instead of
`MAX(number) + 1`, which two simultaneous emissions both read.

---

## Status catalogues

### Billing data

| Status | Meaning |
|---|---|
| `active` | Usable by the emission flow. |
| `inactive` | Kept for the receipts that already point at it; never offered again. |

Constants: `MYAPI_RECEIPT_BILLING_DATA_STATUS_ACTIVE` and
`MYAPI_RECEIPT_BILLING_DATA_STATUS_INACTIVE`.

### Receipt

| Status | Meaning |
|---|---|
| `pending` | The row exists but the PDF has not been produced yet. |
| `generated` | The PDF was produced and stored. |
| `failed` | The PDF could not be produced. The number is kept so a retry reuses it. |
| `voided` | The receipt was voided on purpose. Its security code no longer verifies. |
| `superseded` | A newer version of the same payment replaced this one. |

Constants: `MYAPI_PAYMENT_RECEIPT_STATUS_PENDING`, `_GENERATED`, `_FAILED`,
`_VOIDED` and `_SUPERSEDED`.

The normal flow is `pending` → `generated`; a failed generation leaves
`failed` and keeps the number for the retry. A regeneration inserts a new
version and moves the previous one to `superseded`; `voided` is a deliberate
business decision, not a technical failure.

---

## `billing_snapshot`

`billing_snapshot` is a JSON object with **exactly** these six keys:

```json
{
  "first_name": "…",
  "last_name": "…",
  "document_number": "…",
  "email": "…",
  "address": "…",
  "phone": "…"
}
```

It is the payer's data **as it was at emission time**. A regenerated PDF is
built from a new snapshot taken from the billing row in force at that moment,
which is why the snapshot is stored per version and never shared. The
condominium's fiscal data is **not** in the snapshot: it is read live, as
explained above.

---

## Reserving the next number

```php
$transaction = db_transaction();
$number = myapi_payment_receipt_next_number($condominium_nid);
// insert the receipt row, then commit
```

`myapi_payment_receipt_next_number()` **must be called inside an open
transaction**. It throws `LogicException` otherwise: without a transaction the
row lock would be released before the receipt is inserted, and two emissions
could take the same number.

Inside the transaction it runs three statements:

1. `INSERT … ON DUPLICATE KEY UPDATE` creates the condominium's counter row
   with `last_number = field_receipt_start_number - 1`, or `0` when the field is
   empty. The no-op `UPDATE` branch makes the statement idempotent without ever
   resetting a counter that already exists.
2. `SELECT last_number … FOR UPDATE` locks that row until the transaction ends.
3. `UPDATE last_number = last_number + 1` and the new value is returned.

Consequences the emission spec must respect:

- **The PDF is generated after the commit.** Generating it inside the
  transaction would hold the row lock for the whole render and block every
  other emission for that condominium.
- **A rollback moves the counter back**, so the number is not lost.
- **A failed PDF keeps its number** (`status = failed`); the retry reuses it.
- **Gaps only appear through manual deletes.** Nothing in the flow skips a
  number.
- The start number is read **only while creating the counter row**. Changing
  `field_receipt_start_number` afterwards has no effect.

MySQL/MariaDB dependency, stated on purpose: `INSERT … ON DUPLICATE KEY UPDATE`
and `SELECT … FOR UPDATE` are not portable SQL. Production and local both run
MariaDB.

---

## Security code

`myapi_payment_receipt_security_code()` returns **20 characters** from:

```
23456789ABCDEFGHJKLMNPQRSTUVWXYZ
```

The 32 symbols exclude `0`, `O`, `1` and `I`, which makes the printed code
readable by a person and by an OCR. Each character comes from one byte of
`drupal_random_bytes(20)` masked with `& 31`; the mask is uniform because 256
is an exact multiple of 32, so the code carries about **100 bits** of entropy.

- The code is stored **without hyphens**. Grouping it for printing is the
  template's job.
- Each version has its own code.
- A collision with the unique key is not retried inside the generator: the
  emission code catches the duplicate and decides how many attempts are
  acceptable. That belongs to the emission spec.

---

## Installation

- `myapi_update_7051()` creates `myapi_receipt_billing_data`,
  `myapi_payment_receipts` and `myapi_receipt_sequences` with
  `drupal_get_schema_unprocessed()` + `db_create_table()`, skipping any table
  that already exists.
- `myapi_update_7052()` creates the four condominium fields and their
  instances, skipping any field or instance that already exists.
- Running either update again is a no-op, and a second `drush updb` reports no
  pending updates.

---

## Out of scope

This spec does not include: PDF generation, template, QR or barcode drawing;
emission when a payment is verified; storing the PDF and emailing it; the
download/verification endpoints; the public URL behind the QR; RUC or cédula
validation; and filling in the fiscal data of the existing condominiums.
