# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## What this project is

A **custom Drupal 7 module** that exposes a REST API consumed by a Flutter app. No contributed service-exposure modules (`services`, `rest_server`, etc.) are used — every endpoint is hand-rolled inside this module.

Goal: a clean, reusable, scalable API where adding a new resource means creating one file and registering its routes, without touching existing logic.

---

## Environment constraints (non-negotiable)

- **Drupal 7.64.** Use only Drupal 7 APIs (`hook_menu()`, `db_select()`, `db_insert()`, `drupal_json_encode()`, etc.). Never suggest Drupal 8/9/10/11 solutions (YAML routes, Symfony controllers, annotations, DI services).
- **PHP 7.4.33.** PHP 7.4 features are fine (arrow functions, typed properties, null-coalescing assignment). PHP 8.0+ syntax (`match`, named arguments, constructor property promotion, enums) will break.
- **Drupal 7 is EOL (January 2025).** No core security patches — validate and sanitize all input with extra care.
- **HTTPS required** in production; credentials and tokens travel in requests.

---

## Common commands

Cache must be cleared after adding a new `.inc` file or modifying `hook_menu()`:

```bash
# Clear all Drupal caches (Drush)
drush cc all

# Enable/disable the module
drush en myapi
drush dis myapi

# Run Drupal's built-in update system after schema changes
drush updb
```

There is no automated test runner. Manual testing is done via HTTP client (curl, Postman, etc.) against the running Drupal site.

---

## Architecture rules

1. **`myapi.module` routes only.** It contains `hook_menu()` and minimal glue. No business logic.
2. **One resource = one file** at `resources/<name>.resource.inc`. All CRUD logic for that resource lives there.
3. **Shared helpers in `includes/`.** Written once, reused everywhere. No logic duplication between resources.
4. **All responses go through helpers.** `myapi_respond($data, $status)` for success, `myapi_error($message, $status)` for errors. Never print raw JSON in a resource file.
5. **Resources are isolated.** A resource never calls another resource's internal functions. Shared logic moves to `includes/`.
6. **Versioned from day one.** All routes live under `api/v1/...`.

---

## File layout

```
myapi/
├── myapi.info          Module declaration; every .inc must be listed with files[]
├── myapi.install       hook_schema() and install/update tasks
├── myapi.module        hook_menu() — routing only
│
├── includes/
│   ├── myapi.response.inc   myapi_respond() / myapi_error()
│   └── myapi.request.inc    JSON body parsing, HTTP method detection, input validation
│
├── resources/
│   └── <name>.resource.inc  All CRUD logic for one resource
│
└── docs/
    └── <name>.md            API documentation for one resource
```

---

## Naming conventions

- **All code in English.** Function names, variables, parameters, table columns, JSON keys, and docblocks. Exception: end-user-facing error messages may use the app's language if the resource spec says so.
- **Function prefix:** `myapi_` (e.g. `myapi_product_list()`, `myapi_respond()`).
- **Resource files:** `resources/<name>.resource.inc`, singular English noun (`product.resource.inc`).
- **Custom tables:** `myapi_` prefix (e.g. `myapi_tokens`).
- **API paths:** English, plural, under `api/v1/` — collection `api/v1/products`, item `api/v1/products/%`.
- **HTTP methods:** REST semantics — `GET` read, `POST` create, `PUT` update, `DELETE` delete. Each resource file has a dispatcher that routes by method.

---

## Response envelope (no exceptions)

```json
// Success (no message)
{ "success": true, "data": { } }

// Success (with optional translated message)
{ "success": true, "data": { }, "message": "Sesión cerrada correctamente." }

// Error
{ "success": false, "error_code": "invalid_credentials", "error": "Usuario o contraseña incorrectos." }
```

- **`error_code`** — stable catalogue key (English, snake_case), language-independent, for client logic.
- **`error`** — message translated into the language resolved from `Accept-Language` (`es`/`en`, default `es`).
- **`message`** — optional, translated; only present on success when a `message_key` is passed to `myapi_respond()`.

Messages are never hard-coded in resources: pass a catalogue **key** to `myapi_error()` / `myapi_respond()` and `myapi_t()` translates it. See `docs/i18n.md`.

HTTP status codes must be correct: 200, 201, 400, 401, 403, 404, 405, 422, 429, 500.

---

## Adding a new endpoint (checklist)

1. Create `resources/<name>.resource.inc` with `myapi_<name>_dispatch()` routing by HTTP method.
2. Implement CRUD functions in that same file.
3. Register routes in `hook_menu()` in `myapi.module` (collection and item paths).
4. Add `files[] = resources/<name>.resource.inc` to `myapi.info`.
5. Use `myapi_respond()` / `myapi_error()` for every response.
6. Validate and sanitize all input via helpers in `includes/myapi.request.inc`.
7. Create `docs/<name>.md` following the doc template below.
8. Run `drush cc all` to pick up the new routes.

---

## Doc template (`docs/<name>.md`)

```markdown
## <METHOD> /api/v1/<path>

Brief description.

**Authentication:** required / public

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**
\`\`\`json
{ }
\`\`\`

**Success response (<code>)**
\`\`\`json
{ "success": true, "data": { } }
\`\`\`

**Possible errors**
| Code | When |
|------|------|
| 422  | ...  |
| 401  | ...  |
```

Doc is updated in the same commit that creates or modifies the endpoint. An endpoint without docs is incomplete.

---

## Hard prohibitions

- No Services or contributed modules for API exposure.
- No business logic in `myapi.module`.
- No raw JSON output — always `myapi_respond()` / `myapi_error()`.
- No logic duplication between resources — shared code goes to `includes/`.
- No Drupal 8+ APIs.
- No PHP 8.0+ syntax.
- No Spanish identifiers or comments — code is in English.
- No endpoint without a doc file in `docs/`.

---

## Out of scope until specs arrive

Do not assume or invent these — ask for the spec first:

- **Authentication** (login mechanism, token format, validation strategy).
- **Each concrete resource** (fields, validation rules, whether it maps to a node or a custom table, permissions).
