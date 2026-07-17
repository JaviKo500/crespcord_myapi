# 26 — Notificaciones: contexto condominio/unidad (plomería para triggers futuros)

- **Estado:** Implemented
- **Fecha:** 2026-07-16
- **Dependencias:**
  - `25-notifications-inbox-boletin` (Approved, código ya implementado en el repo) — tabla `myapi_notifications`, `myapi_notification_create()`, `myapi_notification_create_from_boletin()`, endpoints de `resources/notification.resource.inc`, `myapi_update_7004()` como patrón para el nuevo update.
- **Objetivo:** Agregar las columnas nullable `condominium_id` y `unit_id` a `myapi_notifications`, propagarlas por `myapi_notification_create()` (payload de push incluido) y exponerlas en `deep_link` de los endpoints de inbox, dejando la plomería lista para que triggers futuros (pago aprobado, alícuota creada) puedan asociar una notificación a una vivienda y un condominio concretos — sin implementar todavía esos triggers.

---

## Alcance

### Dentro de este spec

- **`myapi.install`** (modificar) — agregar `condominium_id` y `unit_id` al array de `myapi_notifications` en `myapi_schema()` (después de `deep_link_id`, antes de `is_read`). Nuevo `myapi_update_7005()` con `db_add_field()` para sitios donde el módulo ya está instalado (patrón de `myapi_update_7004()`). Sin índices nuevos.
- **`includes/myapi.notification.inc`** (modificar) — `myapi_notification_create()` acepta dos claves opcionales nuevas en `$params`: `condominium_id` y `unit_id` (default `NULL`), las persiste en el insert, y el `$data` armado para el push de OneSignal pasa a tener las claves `deep_link_target`, `deep_link_id`, `deep_link_unit`, `deep_link_condominium`, `notification_type` (renombrando `target`→`deep_link_target`, `id`→`deep_link_id`).
- **`resources/notification.resource.inc`** (modificar) — `myapi_notification_list()` y `myapi_notification_mark_read()` agregan `condominium_id`/`unit_id` a sus `SELECT`; `myapi_notification_build_item()` agrega `unit` y `condominium` **dentro del objeto `deep_link` ya existente** (mismo criterio que `id`: `NULL` si no hay valor, si no, cast a `int`).
- **`docs/notification.md`** (modificar) — ejemplo de respuesta actualizado con `deep_link.unit`/`deep_link.condominium`, y nota explícita de que ambos son `NULL` en notificaciones de boletín.

### Fuera de este spec

- **Los triggers que van a usar estos campos** (pago aprobado, alícuota creada). Este spec solo deja `condominium_id`/`unit_id` disponibles en la tabla, en `myapi_notification_create()` y en la respuesta de los endpoints; no crea ningún `hook` ni llamador nuevo.
- **`myapi_notification_create_from_boletin()`**. No se toca: sigue sin pasar `condominium_id`/`unit_id`, por lo que quedan en `NULL` para boletines (comportamiento por defecto de `myapi_notification_create()`).
- **Validaciones o endpoints nuevos.** No hay `POST`/filtros nuevos ni reglas de negocio; es un cambio de schema y de la función de creación existente.
- **Índices nuevos** sobre `condominium_id`/`unit_id`. Quedan sin indexar en esta entrega.
- **Migración de compatibilidad del payload de push.** El rename `target`/`id` → `deep_link_target`/`deep_link_id` se aplica directo, sin mantener las claves viejas; la coordinación con la app se maneja fuera de este spec (ver Riesgos).

---

## Modelo de datos

### Columnas nuevas en `myapi_notifications`

Se agregan en `myapi_schema()`, en el array de campos de `myapi_notifications`, **después de `deep_link_id` y antes de `is_read`**:

| Columna | Tipo | Notas |
|---|---|---|
| `condominium_id` | int unsigned, null | Logical FK al nid del condominio. `NULL` cuando la notificación no nace atada a un condominio único (p. ej. boletín). Se completa en triggers futuros (pago aprobado, alícuota creada). |
| `unit_id` | int unsigned, null | Logical FK al nid de la vivienda. Mismo criterio que `condominium_id`: `NULL` para boletín, se completa en triggers futuros. |

Sin índices nuevos sobre estas columnas en esta entrega.

### `myapi_update_7005()`

Sigue el patrón de `myapi_update_7004()` pero con `db_add_field()` (la tabla ya existe en sitios instalados):

```php
function myapi_update_7005() {
  if (!db_field_exists('myapi_notifications', 'condominium_id')) {
    db_add_field('myapi_notifications', 'condominium_id', [
      'description' => 'Logical FK to the condominium node (nid). NULL for notifications with no single condominium context (e.g. boletin fan-out).',
      'type'        => 'int',
      'unsigned'    => TRUE,
      'not null'    => FALSE,
    ]);
  }
  if (!db_field_exists('myapi_notifications', 'unit_id')) {
    db_add_field('myapi_notifications', 'unit_id', [
      'description' => 'Logical FK to the unit (vivienda) node (nid). NULL for notifications with no single unit context (e.g. boletin fan-out).',
      'type'        => 'int',
      'unsigned'    => TRUE,
      'not null'    => FALSE,
    ]);
  }
}
```

### `myapi_notification_create()` — nuevas claves en `$params`

| Clave | Tipo | Default | Notas |
|---|---|---|---|
| `condominium_id` | int\|null | `NULL` | Se castea a `(int)` si viene, se guarda tal cual si no. |
| `unit_id` | int\|null | `NULL` | Igual criterio. |

Ambas se agregan al `db_insert('myapi_notifications')->fields([...])` (después de `deep_link_id`, antes de `is_read`, igual que en el schema) y a cada `values([...])` del loop por `uid`.

### Payload `$data` del push (OneSignal) — claves renombradas

Antes:
```json
{ "target": "bulletin", "id": 812, "notification_type": "bulletin" }
```

Después:
```json
{
  "deep_link_target": "bulletin",
  "deep_link_id": 812,
  "deep_link_unit": null,
  "deep_link_condominium": null,
  "notification_type": "bulletin"
}
```

`deep_link_unit`/`deep_link_condominium` toman el valor de `$params['unit_id']`/`$params['condominium_id']` (o `NULL` si no vinieron, como en boletín). Este rename es breaking para cualquier consumidor actual del payload de push (`data.target`/`data.id`); se documenta como riesgo, sin mantener las claves viejas (ver Riesgos).

### Respuesta de los endpoints — `deep_link` extendido

`myapi_notification_build_item()` agrega `unit` y `condominium` dentro del objeto `deep_link` ya existente:

```json
{
  "id": 4021,
  "type": "bulletin",
  "title": "Corte de agua programado",
  "body": "El sábado de 8:00 a 12:00 se suspende el servicio...",
  "deep_link": {
    "target": "bulletin",
    "id": 812,
    "unit": null,
    "condominium": null
  },
  "is_read": false,
  "created_at": 1752566400,
  "read_at": null
}
```

`deep_link.unit`/`deep_link.condominium` son `NULL` si la fila no tiene valor, si no, cast a `int` — mismo criterio que ya usa `deep_link.id`. `GET /api/v1/notifications` y `PUT /api/v1/notifications/%/read` agregan `condominium_id`, `unit_id` a su `SELECT` para alimentar este mapeo.

---

## Plan de implementación

1. **Schema.** En `myapi.install`, agregar `condominium_id` y `unit_id` al array de campos de `myapi_notifications` en `myapi_schema()` (después de `deep_link_id`, antes de `is_read`), con la definición de la sección anterior. Agregar `myapi_update_7005()` con `db_add_field()` guardado por `db_field_exists()`, siguiendo el patrón de `myapi_update_7004()`. *Verificación: `drush updb` agrega las dos columnas sin error, tanto en un sitio limpio (las trae `myapi_schema()`) como en uno ya instalado (las trae `myapi_update_7005()`).*

2. **`includes/myapi.notification.inc`.** En `myapi_notification_create()`:
   - Leer `$params['condominium_id']` y `$params['unit_id']`, default `NULL` si no vienen.
   - Agregarlos a `db_insert(...)->fields([...])` y a cada `values([...])` del loop por `uid`.
   - Renombrar en el array `$data` del push: `target` → `deep_link_target`, `id` → `deep_link_id`; agregar `deep_link_unit` y `deep_link_condominium` con los valores de `unit_id`/`condominium_id`.
   - No tocar `myapi_notification_create_from_boletin()`: al no pasar esas dos claves en su llamada a `myapi_notification_create()`, siguen resolviendo a `NULL`.
   *Verificación: un boletín de prueba sigue insertando filas con `condominium_id`/`unit_id` en `NULL` y encolando el push con las 5 claves nuevas en `data`.*

3. **`resources/notification.resource.inc`.**
   - Agregar `condominium_id`, `unit_id` a los `->fields('n', [...])` de `myapi_notification_list()` y `myapi_notification_mark_read()`.
   - En `myapi_notification_build_item()`, agregar `unit` y `condominium` dentro del array `deep_link` (mismo criterio de cast que `id`: `NULL` o `(int)`).
   *Verificación: `GET /api/v1/notifications` y `PUT /api/v1/notifications/%/read` devuelven `deep_link.unit`/`deep_link.condominium` en `NULL` para notificaciones de boletín existentes, sin romper el resto de la respuesta.*

4. **`docs/notification.md`.** Actualizar los ejemplos de respuesta de `GET /api/v1/notifications` y `PUT /api/v1/notifications/%/read` con `deep_link.unit`/`deep_link.condominium`, y agregar una nota explícita: ambos campos son `NULL` en notificaciones de boletín porque no nacen atadas a una unidad/condominio único; se completan en triggers futuros.

5. **Aplicar y verificar.** Correr `drush cc all` (recarga rutas/schema) y `drush updb` (aplica `myapi_update_7005()` en el sitio ya instalado). Confirmar con un `GET /api/v1/notifications` real que las filas de boletín existentes muestran `deep_link.unit`/`deep_link.condominium` en `NULL` sin error.

---

## Criterios de aceptación

- [x] `drush updb` agrega `condominium_id` y `unit_id` a `myapi_notifications` sin error, tanto en un sitio limpio (habilitación crea la tabla ya con las columnas) como en uno ya instalado (`myapi_update_7005()`). — *Requiere Drupal en marcha.*
- [x] `drush cc all` no arroja errores tras el cambio de schema y de código. — *Requiere Drupal en marcha.*
- [x] `myapi_notification_create()` acepta `condominium_id`/`unit_id` opcionales en `$params`, los persiste en el insert, y usa `NULL` por defecto si no vienen.
- [x] `myapi_notification_create_from_boletin()` sigue sin pasar `condominium_id`/`unit_id`; las notificaciones de boletín quedan con ambos campos en `NULL`.
- [x] El `$data` encolado para el push de OneSignal tiene exactamente las claves `target`, `id`, `unit`, `condominium`, `notification_type`; ya no existen `target`/`id`.
- [x] `GET /api/v1/notifications` y `PUT /api/v1/notifications/%/read` devuelven `deep_link.unit`/`deep_link.condominium`: `NULL` si la fila no tiene valor, `int` si lo tiene.
- [x] `PUT /api/v1/notifications/read-all` no cambia — no expone objetos individuales, no requiere tocarlo.
- [x] No se agregaron índices nuevos, endpoints nuevos, ni validaciones nuevas.
- [x] `docs/notification.md` documenta `deep_link.unit`/`deep_link.condominium` en los ejemplos de respuesta y aclara que son `NULL` en notificaciones de boletín.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Ubicación de `unit`/`condominium` en la respuesta | Anidados dentro de `deep_link` (junto a `target`/`id`) | Claves `unit_id`/`condominium_id` en la raíz del objeto notificación | Mismo objeto que ya agrupa la info de navegación; queda estándar y consistente con el criterio de cast que ya usa `deep_link.id`. |
| Payload de push (`$data`) | Renombrar `target`→`deep_link_target`, `id`→`deep_link_id` y agregar `deep_link_unit`/`deep_link_condominium`, sin mantener las claves viejas | Mantener `target`/`id` en paralelo durante una transición | Simplicidad; se acepta el riesgo de breaking change y se coordina el despliegue de la app por fuera de este spec (ver Riesgos). |
| Nombres de columnas nuevas | `condominium_id`, `unit_id` | `deep_link_condominium_id`/`deep_link_unit_id` (prefijo `deep_link_`) | Son datos de contexto de la notificación en sí (a quién/qué pertenece), no parte del par target/id de navegación; se acercan más a `source_nid` que a `deep_link_*`. |
| `myapi_notification_create_from_boletin()` | No se toca; sigue sin pasar `condominium_id`/`unit_id` | Pasar explícitamente `'condominium_id' => NULL, 'unit_id' => NULL` | El default de `myapi_notification_create()` ya resuelve a `NULL`; no agregar código que no cambia el comportamiento. |
| Índices sobre las columnas nuevas | Ninguno en esta entrega | Índice en `condominium_id` y/o `unit_id` para futuros filtros por vivienda/condominio | Sin caso de uso todavía (los triggers que los llenan no existen aún); se agrega en el spec que implemente el primer trigger, si hace falta filtrar por esas columnas. |
| Estado del spec `25-notifications-inbox-boletin` | Se deja como `Approved` | Actualizarlo a `Implemented` como parte de este cambio | Fuera del alcance de este spec; es un ajuste de housekeeping sobre otro documento, no relacionado con las columnas nuevas. |

---

## Riesgos identificados

- **Breaking change en el payload de push.** Cualquier consumidor actual de la notificación push (app Flutter u otro) que lea `data.target`/`data.id` deja de encontrarlas: pasan a `data.deep_link_target`/`data.deep_link_id`. *Mitigación:* aceptado (ver Decisiones); requiere coordinar el despliegue de la actualización de la app con este cambio de backend, fuera del alcance de este spec.
- **Triggers futuros que olviden pasar `condominium_id`/`unit_id`.** Si un trigger nuevo (pago aprobado, alícuota creada) llama a `myapi_notification_create()` sin esas claves, la notificación queda con contexto `NULL` silenciosamente — no hay error, pero la app no podría redirigir con datos completos. *Mitigación:* documentado en `docs/notification.md` y en este spec como responsabilidad de cada trigger; sin validación adicional en `myapi_notification_create()` (es opcional por diseño, para no romper `create_from_boletin()`).
- **`db_add_field()` y orden físico de columnas.** En sitios ya instalados, `myapi_update_7005()` puede agregar las columnas al final de la tabla en vez de entre `deep_link_id` e `is_read` (el orden exacto depende del driver de base de datos). *Mitigación:* sin impacto funcional — todo el código accede a columnas por nombre, nunca por posición.
