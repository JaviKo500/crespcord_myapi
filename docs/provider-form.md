# Provider form — associated accounts

The back-office node form of a `provider` (`node/add/provider`,
`node/<nid>/edit`) shapes **Usuarios asociados** (`field_provider_users`):

- The field's autocomplete only offers **active** accounts holding the
  `proveedor` role, matches on user name, e-mail, first name or last name, and
  shows `First Last (username) — mail`.
- The field's help text links to **Crear usuario** (`admin/people/create`), in
  a new tab so the form being filled is not lost. The link is only shown to
  accounts with `administer users`. Accounts are not created from this form.

The field stays required, as before. The `api/v1/...` endpoints are not
involved: none of them reaches a node form.

---

## Files

| File | Role |
|---|---|
| `myapi.module` | The route, `myapi_provider_account_access()` and `hook_form_provider_node_form_alter()`. Glue only. |
| `includes/myapi.provider_account.inc` | The form alter and the autocomplete callback with its query. |
| `includes/myapi.response.inc` | `myapi_respond_autocomplete()` — the non-envelope response the autocomplete answers with. |
| `tests/unit/ProviderAccountFormTest.php` | The include under the test stubs, plus the route and its access callback. |

No schema change and no update hook: run `drush cc all` after deploying, for
the new route and the new include.

---

## The autocomplete

`GET admin/content/providers/user-autocomplete/<text>` — Drupal's autocomplete
JSON (`"username (uid)" => HTML`), at most 10 suggestions ordered by user name.

**Access:** whoever holds `create provider content`, `edit any provider
content` or `edit own provider content`.

The role filter is on the **suggestions**, not on the save. A reference already
stored to an account without the role — or typed by hand as `name (uid)` — is
still accepted, so a provider saved before this form existed can be edited. An
account without the role is useless to the provider anyway: the app answers it
`403 provider_role_required` (see [provider-role.md](provider-role.md)).

A resident who is going to operate a provider keeps their account: grant it
the `proveedor` role from **Personas** first, and it shows up here.

---

## Manual check

1. `node/add/provider`: type part of a provider account's name or e-mail in
   **Usuarios asociados** — only active accounts with the role are offered.
2. Press **Añadir otro elemento**: the new row uses the same autocomplete.
3. The help text shows **Crear usuario**, which opens `admin/people/create` in
   another tab.
4. Save with the field empty: Drupal's own "required" error.
