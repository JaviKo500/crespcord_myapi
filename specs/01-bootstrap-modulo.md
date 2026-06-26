# 01 — Bootstrap del módulo myapi

- **Estado:** Approved
- **Fecha:** 2026-06-26
- **Dependencias:** ninguna (primer spec)
- **Objetivo:** Dejar el módulo `myapi` instalable y activable, con la
  arquitectura de carpetas montada, los helpers de respuesta y request
  listos, y un recurso `ping` mínimo que demuestre el patrón completo,
  de modo que agregar un recurso nuevo solo implique crear su archivo
  en `resources/`, registrar sus rutas y escribir su doc.

---

## Alcance

### Dentro de este spec
- `myapi.info` — declaración del módulo, listado de todos los `.inc`
- `myapi.install` — `hook_install()` y `hook_uninstall()` vacíos
- `myapi.module` — `hook_menu()` con la ruta de ping únicamente
- `includes/myapi.response.inc` — `myapi_respond()` y `myapi_error()`
- `includes/myapi.request.inc` — `myapi_request_body()`,
  `myapi_request_method()`, `myapi_request_require_fields()`
- `resources/ping.resource.inc` — dispatcher + `myapi_ping_get()`
- `docs/ping.md` — documentación del endpoint ping

### Fuera de este spec
- Autenticación y tokens (spec propio pendiente)
- Cualquier otro recurso (`products`, `users`, etc.)
- Tablas en base de datos
- Permisos Drupal (`hook_permission()`)

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. Crear `myapi.info` con nombre, descripción, versión del core (`7.x`)
   y los `files[]` de todos los `.inc` del módulo.

2. Crear `myapi.install` con `hook_install()` y `hook_uninstall()`
   vacíos para que Drupal pueda activar y desactivar sin errores.

3. Crear `includes/myapi.response.inc` con:
   - `myapi_respond($data, $status = 200)` — imprime el envelope
     `{"success":true,"data":...}` y sale con el código HTTP dado.
   - `myapi_error($message, $status = 400)` — imprime
     `{"success":false,"error":...}` y sale.

4. Crear `includes/myapi.request.inc` con:
   - `myapi_request_body()` — lee `php://input`, decodifica JSON,
     devuelve array o `null`. Cachea en variable estática.
   - `myapi_request_method()` — devuelve el método HTTP en mayúsculas.
   - `myapi_request_require_fields(array $body, array $fields)` — llama
     a `myapi_error(…, 422)` si falta algún campo requerido.

5. Crear `resources/ping.resource.inc` con:
   - `myapi_ping_dispatch()` — enruta por método; solo acepta `GET`,
     devuelve 405 en cualquier otro.
   - `myapi_ping_get()` — devuelve `myapi_respond(['pong' => TRUE])`.

6. Crear `myapi.module` con `hook_menu()` que registre:
   - `api/v1/ping` → `myapi_ping_dispatch`, acceso público.

7. Crear `docs/ping.md` siguiendo la plantilla del `CLAUDE.md`.

8. Ejecutar `drush en myapi && drush cc all` para activar el módulo
   y verificar con `curl https://<site>/api/v1/ping`.

---

## Criterios de aceptación

- [ ] `drush en myapi` activa el módulo sin errores ni warnings.
- [ ] `drush dis myapi` desactiva el módulo sin errores.
- [ ] `GET /api/v1/ping` devuelve HTTP 200 y el cuerpo
      `{"success":true,"data":{"pong":true}}`.
- [ ] `POST /api/v1/ping` devuelve HTTP 405.
- [ ] Una llamada directa a `myapi_respond(['x' => 1])` produce
      `{"success":true,"data":{"x":1}}` con HTTP 200.
- [ ] Una llamada directa a `myapi_error('fallo', 422)` produce
      `{"success":false,"error":"fallo"}` con HTTP 422.
- [ ] `myapi_request_require_fields([], ['name'])` responde 422 sin
      llegar al código del recurso.
- [ ] Ningún `.inc` ausente del `files[]` de `myapi.info`
      (Drupal no lanza "class not found" ni "function not found").

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Tablas en este spec | Ninguna — `hook_install()` vacío | Crear `myapi_tokens` ya | Auth está fuera de alcance hasta su propio spec |
| Ruta de verificación | `GET /api/v1/ping` incluida | Solo `drush en` sin ruta | Permite verificar helpers end-to-end con un `curl` simple |
| Recurso de ejemplo | `ping.resource.inc` mínimo | Carpeta `resources/` vacía | Demuestra el patrón completo antes de implementar recursos reales |
| Funciones de request | Tres helpers explícitos (`body`, `method`, `require_fields`) | Un helper genérico único | Más legibles en los recursos; cada función tiene una responsabilidad clara |

---

## Riesgos identificados

- **`files[]` incompleto en `myapi.info`:** Si un `.inc` nuevo no se
  lista, Drupal no lo autoincluye y el primer request que lo necesite
  falla con "call to undefined function". Mitigación: el checklist de
  "añadir recurso" del `CLAUDE.md` ya lo exige; reforzarlo en el paso 1
  del plan.

- **`php://input` se lee una sola vez:** Si algo antes de
  `myapi_request_body()` ya consumió el stream, el cuerpo llega vacío.
  Mitigación: leer y cachear el resultado en una variable estática
  dentro de la función.

- **Headers HTTP en la respuesta:** `drupal_json_encode()` no pone
  `Content-Type: application/json` automáticamente. Mitigación:
  `myapi_respond()` y `myapi_error()` deben llamar a
  `drupal_add_http_header('Content-Type', 'application/json')` antes
  de imprimir.
