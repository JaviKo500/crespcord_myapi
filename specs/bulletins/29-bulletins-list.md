# SPEC 29 — Listado de boletines visibles para el usuario

> **Estado:** Implemented · **Depende de:** SPEC 14, SPEC 25, SPEC 08 · **Fecha:** 2026-07-17
> **Objetivo:** Agregar `GET /api/v1/bulletins`, un endpoint autenticado y paginado que lista los nodos `boletin` publicados que el usuario autenticado puede ver según la audiencia del boletín (cruce `field_tipo_de_boletin` × `field_enviar_a`), leídos directamente de los nodos, con orden y filtro opcional por rango de fechas sobre `node.created`.

**Dependencias (detalle):**

- `14-unit-payments-list` (Implemented) — modelo de referencia del contrato: dispatcher por método, paginación (`page`/`limit`/`sort`), filtro laxo de rango de fechas y forma de respuesta `{ recurso: [...], pagination: {...} }`.
- `25-notifications-inbox-boletin` (Implemented) — define el modelo de audiencia (`field_tipo_de_boletin` × `field_enviar_a`) y el mapeo de campos del content type `boletin`. Este endpoint expone al **lector** el mismo conjunto de boletines que el fan-out le notificó, invirtiendo la resolución de `myapi_boletin_recipient_uids()`.
- `08-units-list` (Implemented) — `includes/myapi.unit_access.inc`; este spec agrega helpers de resolución de rol del lector (unidades propias/ocupadas y sus condominios) en ese mismo archivo.

---

## Alcance

### Dentro de este spec

- **`resources/bulletin.resource.inc`** (nuevo) — `myapi_bulletin_dispatch()` (solo `GET`) y las funciones `myapi_bulletin_list()`, `myapi_bulletin_count()`, `myapi_bulletin_fetch()`, `myapi_bulletin_build_item()`, más los helpers de rango de fechas (`myapi_bulletin_parse_date_range()` / `myapi_bulletin_valid_date()`) y el armador de la condición de audiencia inversa (`myapi_bulletin_visibility_condition()`). Misma estructura que `payment.resource.inc`.
- **`includes/myapi.unit_access.inc`** (modificar) — agregar los resolvedores de rol del lector: `myapi_user_owned_unit_nids($uid)`, `myapi_user_occupied_unit_nids($uid)` y `myapi_units_condominium_nids(array $unit_nids)`, sin tocar las funciones existentes.
- **`myapi.module`** (modificar) — registrar `GET /api/v1/bulletins` en `hook_menu()`.
- **`myapi.info`** (modificar) — agregar `resources/bulletin.resource.inc` a `files[]`.
- **`docs/bulletin.md`** (nuevo) — documentación del endpoint siguiendo la plantilla del proyecto.

### Fuera de este spec

- **Detalle individual** (`GET /api/v1/bulletins/%`) — solo el listado de colección.
- **Escritura de boletines** (crear/editar/borrar nodos `boletin`) — solo lectura; los boletines se siguen creando desde el backend de Drupal.
- **Resolver la URL o el contenido del adjunto** — se expone solo el `file_id` (fid) de `field_adjunto`; la app resuelve la URL aparte. No se lee `file_managed` ni se genera `file_create_url()`.
- **Marca leído/no-leído del boletín** — eso vive en el inbox `/notifications` (`myapi_notifications`); este endpoint no toca esa tabla ni expone estado de lectura.
- **Tocar `myapi_boletin_recipient_uids()` ni el fan-out del spec 25** — este endpoint invierte esa lógica en una query propia; no modifica el disparador de notificaciones.
- **Filtrar sobre otro campo que no sea `node.created`** (por `type`, `send_to`, condominio, etc.) — el único filtro opcional es el rango de fechas.
- **Boletines no publicados** (`node.status = 0`) — un borrador nunca aparece, igual que no dispara notificación.
- **Normalizar/sanear el HTML de `field_mensaje`** — se expone crudo tal cual se guarda; el saneo/render es responsabilidad de la app.

---

## Modelo de datos

Content type `boletin` (ya existe en Drupal). Todos los campos son single-value salvo `field_personalizar` y `field_ocupantes` (multi-value). Se leen directo de las tablas Field API.

### Tablas Drupal usadas

| Tabla | Columna(s) | Uso |
|---|---|---|
| `node` | `nid`, `title`, `type`, `status`, `created` | Nodos `boletin` publicados. `created` es la columna de orden y de filtro de fechas. |
| `field_data_field_mensaje` | `entity_id`, `field_mensaje_value` | `message` (HTML crudo). Left join. |
| `field_data_field_tipo_de_boletin` | `entity_id`, `field_tipo_de_boletin_value` | `type` (`General`/`Condominio`/`Personalizado`). Inner join: es el eje de la condición de audiencia. |
| `field_data_field_enviar_a` | `entity_id`, `field_enviar_a_value` | `send_to` (`Propietarios`/`Ocupantes`/`Todos`). Left join; parte de la condición de audiencia. |
| `field_data_field_condominio` | `entity_id`, `field_condominio_target_id` | `condominium_id`. Left join; usado por la rama `Condominio`. |
| `field_data_field_personalizar` | `entity_id`, `field_personalizar_target_id` | Membresía del lector en la rama `Personalizado` (rol propietario). Solo `EXISTS`, no se expone. |
| `field_data_field_ocupantes` | `entity_id`, `field_ocupantes_target_id` | Membresía del lector en la rama `Personalizado` (rol ocupante). Solo `EXISTS`, no se expone. |
| `field_data_field_adjunto` | `entity_id`, `field_adjunto_fid` | `file_id`. Left join; solo se expone el `fid`. |

Todos los joins de campo llevan `deleted = 0` y amarran por `entity_id = n.nid`.

### Precálculo de los sets del lector (una vez por request, dado `$uid`)

Antes de la query principal se resuelven, con los helpers de `myapi.unit_access.inc`:

| Variable | Origen | Uso |
|---|---|---|
| `$owner_unit_nids` | `myapi_user_owned_unit_nids($uid)` — unidades donde `field_propietario = uid` | `$is_owner = !empty(...)` |
| `$occupant_unit_nids` | `myapi_user_occupied_unit_nids($uid)` — unidades donde `field_ocupante` **o** `field_ocupantes = uid` | `$is_occupant = !empty(...)` |
| `$owner_condos` | `myapi_units_condominium_nids($owner_unit_nids)` | Condominios donde el lector es propietario |
| `$occupant_condos` | `myapi_units_condominium_nids($occupant_unit_nids)` | Condominios donde el lector es ocupante |
| `$member_condos` | `array_unique(merge($owner_condos, $occupant_condos))` | Condominios donde el lector es propietario **u** ocupante |

`$is_member = $is_owner || $is_occupant`.

### Condición de audiencia inversa (`myapi_bulletin_visibility_condition($uid, ...)`)

Un `boletin` es visible para `$uid` si cumple **alguna** de las tres ramas (se arma con `db_or()`; cada sub-condición solo se agrega si su set/flag no está vacío, para no generar un `IN ()` inválido):

**Rama General** (`field_tipo_de_boletin_value = 'General'`), visible si:
- `field_enviar_a_value = 'Propietarios'` y `$is_owner`, **o**
- `field_enviar_a_value = 'Ocupantes'` y `$is_occupant`, **o**
- `field_enviar_a_value = 'Todos'` y `$is_member`.

**Rama Condominio** (`field_tipo_de_boletin_value = 'Condominio'`), visible si:
- `field_enviar_a_value = 'Propietarios'` y `field_condominio_target_id IN $owner_condos`, **o**
- `field_enviar_a_value = 'Ocupantes'` y `field_condominio_target_id IN $occupant_condos`, **o**
- `field_enviar_a_value = 'Todos'` y `field_condominio_target_id IN $member_condos`.

**Rama Personalizado** (`field_tipo_de_boletin_value = 'Personalizado'`), visible si:
- `field_enviar_a_value IN ('Propietarios','Todos')` y existe fila en `field_data_field_personalizar` con `field_personalizar_target_id = $uid`, **o**
- `field_enviar_a_value IN ('Ocupantes','Todos')` y existe fila en `field_data_field_ocupantes` con `field_ocupantes_target_id = $uid`.

Un valor desconocido de `field_tipo_de_boletin` o `field_enviar_a` no matchea ninguna rama ⇒ el boletín queda oculto (fail-safe, mismo criterio que el fan-out).

### Mapeo de campos → claves JSON

| Campo Drupal | Clave JSON | Tipo | Regla `NULL` |
|---|---|---|---|
| `nid` | `id` | int | nunca `NULL` |
| `title` | `title` | string | nunca `NULL` |
| `field_mensaje_value` | `message` | string (HTML crudo) | `NULL` si no hay fila |
| `field_tipo_de_boletin_value` | `type` | string | nunca `NULL` (inner join) |
| `field_enviar_a_value` | `send_to` | string | `NULL` si no hay fila |
| `field_condominio_target_id` | `condominium_id` | int | `NULL` salvo boletines de tipo `Condominio` |
| `field_adjunto_fid` | `file_id` | int | `NULL` si no hay adjunto |
| `node.created` | `created_at` | int (unix ts) | nunca `NULL` |

### Contrato de paginación / orden

- Query params: `page` (default `1`), `limit` (default `20`, clamp `[1, 50]`), `sort` (`asc`\|`desc`, default `desc`).
- Valores inválidos o ausentes caen a su default silenciosamente (sin `422`), igual que pagos.
- Orden siempre por `node.created`.
- `total` = cantidad total de boletines visibles para el usuario (con el rango de fechas ya aplicado si viene), sin paginar. `total_pages = ceil(total / limit)`, o `0` si `total` es `0`.

### Filtro por rango de fechas (opcional, sobre `node.created`)

| Param | Formato | Default | Regla |
|---|---|---|---|
| `date_from` | `YYYY-MM-DD` | ausente = sin límite inferior | Si es válido, filtra `created >= strtotime(date_from 00:00:00)`. |
| `date_to` | `YYYY-MM-DD` | ausente = sin límite superior | Si es válido, filtra `created <= strtotime(date_to 23:59:59)`. |

- Un límite es **válido** solo si matchea `YYYY-MM-DD` y es fecha real (`checkdate()`); cualquier otra cosa se ignora.
- Cada límite es independiente; rango invertido (`from > to`) descarta el filtro completo. Nunca hay `422`.
- Como `created` es un timestamp entero, el borde superior usa `23:59:59` del día indicado (a diferencia de pagos, que compara strings con `SUBSTR`). Se usa la timezone del sitio vía `strtotime()`.

### Forma de respuesta

`myapi_respond()` envuelve en `{ "success": true, "data": {...} }`. El `data`:

```json
{
  "bulletins": [
    {
      "id": 812,
      "title": "Corte de agua programado",
      "message": "<p>El sábado de 8:00 a 12:00 se suspende el servicio.</p>",
      "type": "Condominio",
      "send_to": "Todos",
      "condominium_id": 34,
      "file_id": 91,
      "created_at": 1752566400
    }
  ],
  "pagination": { "total": 5, "page": 1, "limit": 20, "total_pages": 1 }
}
```

---

## Plan de implementación

1. **Helpers de rol del lector en `includes/myapi.unit_access.inc`** (aditivo, sin tocar lo existente):
   - `myapi_user_owned_unit_nids($uid)` — `field_data_field_propietario` con `field_propietario_target_id = $uid` y `deleted = 0`; devuelve `entity_id` (nids).
   - `myapi_user_occupied_unit_nids($uid)` — merge de `field_data_field_ocupante` y `field_data_field_ocupantes` con el `target_id = $uid`; dedupe.
   - `myapi_units_condominium_nids(array $unit_nids)` — `field_data_field_condominio` con `entity_id IN $unit_nids`; devuelve `field_condominio_target_id` únicos (vacío si `$unit_nids` vacío).
   - *Verificación: `GET /api/v1/units` y los otros endpoints siguen igual (funciones nuevas, nada modificado).*

2. **Crear `resources/bulletin.resource.inc`** con los `module_load_include()` del patrón (request, response, i18n, token, auth, unit_access) y `myapi_bulletin_dispatch()` que enruta solo `GET` → `myapi_bulletin_list()`; cualquier otro método → `myapi_error('method_not_allowed', 405)`. Skeleton compilable.

3. **`myapi_bulletin_visibility_condition($uid, $owner_condos, $occupant_condos, $member_condos, $is_owner, $is_occupant, $is_member)`** — arma y devuelve un `db_or()` con las tres ramas de la sección de modelo de datos, agregando cada sub-condición solo cuando su set/flag no está vacío. Las ramas `Personalizado` usan sub-selects `EXISTS` sobre `field_data_field_personalizar` / `field_data_field_ocupantes` filtrando por `$uid`.

4. **`myapi_bulletin_parse_date_range()` / `myapi_bulletin_valid_date()`** — validan `date_from`/`date_to` (`YYYY-MM-DD` + `checkdate`), devuelven los límites como timestamps (`00:00:00` / `23:59:59`) o `NULL`; rango invertido descarta ambos. Misma forma laxa que pagos, adaptada a `created` entero.

5. **`myapi_bulletin_count($uid, $sets, $from, $to)`** — `db_select('node')` con `type = 'boletin'`, `status = 1`, inner join a `field_data_field_tipo_de_boletin`, left join a `field_data_field_enviar_a` y `field_data_field_condominio`, aplica la condición de visibilidad y el rango de `created`; devuelve `countQuery()`.

6. **`myapi_bulletin_fetch($uid, $sets, $page, $limit, $sort, $from, $to)`** — misma base que el count; agrega left joins a `field_mensaje` (`message`) y `field_adjunto` (`file_id`); selecciona `nid`, `title`, `created`, `field_tipo_de_boletin_value`, `field_enviar_a_value`, `field_condominio_target_id`, `field_mensaje_value`, `field_adjunto_fid`; `orderBy('n.created', $sort)`, `range()` según `page`/`limit`.

7. **`myapi_bulletin_build_item($row)`** — arma el ítem: `id`/`created_at` a `int`; `title`/`type`/`send_to`/`message` tal cual; `condominium_id`/`file_id` a `int` cuando no son `NULL`.

8. **`myapi_bulletin_list()`** — orquesta: `myapi_auth_require_access_token()` → `$uid`; precalcula los sets del lector (paso 1); parseo de `page`/`limit`/`sort` con defaults/clamps; `myapi_bulletin_parse_date_range()` → `$from`/`$to`; `myapi_bulletin_count()` para `total`; `myapi_bulletin_fetch()` + `array_map('myapi_bulletin_build_item', $rows)`; `myapi_respond(['bulletins' => $items, 'pagination' => [...]], 200)`.

9. **Registrar la ruta en `myapi.module`:**
   ```php
   $items['api/v1/bulletins'] = [
     'page callback'   => 'myapi_bulletin_dispatch',
     'access callback' => TRUE,
     'type'            => MENU_CALLBACK,
     'file'            => 'resources/bulletin.resource.inc',
   ];
   ```

10. **Agregar a `myapi.info`:** `files[] = resources/bulletin.resource.inc`.

11. **Crear `docs/bulletin.md`** siguiendo la plantilla: auth, query params (paginación + `date_from`/`date_to`), tabla de campos de respuesta, tabla de errores, y nota de que la visibilidad replica la audiencia del fan-out (tipo × enviar_a) y de que `message` es HTML crudo y `file_id` solo el fid.

12. **Aplicar y verificar.** `drush cc all` + `curl` sobre los casos de la sección de aceptación (un usuario propietario, uno ocupante, uno sin unidades; boletines de cada tipo/rol).

---

## Criterios de aceptación

- [x] `GET /api/v1/bulletins` con token válido devuelve `200` con `data.bulletins` (array mapeado según el modelo) y `data.pagination` (`total`, `page`, `limit`, `total_pages`).
- [x] Cada ítem incluye exactamente las 8 claves: `id`, `title`, `message`, `type`, `send_to`, `condominium_id`, `file_id`, `created_at`, con `NULL` en `message`/`send_to`/`condominium_id`/`file_id` cuando el nodo no tiene fila en ese campo.
- [x] Solo se listan nodos `boletin` publicados (`status = 1`); un borrador nunca aparece.
- [x] Un boletín **General** solo aparece si el usuario tiene el rol que exige `send_to`: `Propietarios` → es propietario de al menos una unidad; `Ocupantes` → es ocupante de al menos una; `Todos` → es propietario u ocupante de alguna.
- [x] Un boletín **Condominio** solo aparece si su `field_condominio` es un condominio donde el usuario tiene el rol que exige `send_to` (propietario / ocupante / cualquiera).
- [x] Un boletín **Personalizado** solo aparece si el usuario está referenciado en `field_personalizar` (cuando `send_to` es `Propietarios` o `Todos`) o en `field_ocupantes` (cuando es `Ocupantes` o `Todos`); ignora `field_condominio`.
- [x] El conjunto visible para un usuario coincide con el conjunto de boletines cuyo fan-out lo incluyó como destinatario (paridad con `myapi_boletin_recipient_uids()`).
- [x] Un usuario sin ninguna unidad (ni propietario ni ocupante) no ve boletines `General` ni `Condominio`; solo los `Personalizado` donde esté referenciado a mano.
- [x] Un boletín con `type` o `send_to` de valor desconocido no aparece para nadie (fail-safe).
- [x] Sin header `Authorization` → `401 missing_authorization`; token inválido/expirado/revocado → `401 invalid_token`.
- [x] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [x] `?page` y `?limit` paginan; `limit` se clampa a `[1, 50]`; valores inválidos/ausentes caen a los defaults (`page=1`, `limit=20`) sin error.
- [x] `?sort=asc`/`?sort=desc` invierte el orden por `created_at`; default `desc`; valor inválido cae a `desc`.
- [x] `date_from`/`date_to` filtran sobre `created_at` de forma inclusiva (borde superior incluye todo el día indicado, `23:59:59`); cada límite es independiente.
- [x] `date_from`/`date_to` con formato inválido, o rango invertido (`from > to`), se ignoran sin `422`.
- [x] `pagination.total` y `total_pages` reflejan el conjunto **ya filtrado** (audiencia + rango de fechas), no un total bruto.
- [x] Un usuario sin boletines visibles (o una página fuera de rango) devuelve `200` con `bulletins: []` y `pagination.total: 0`, `total_pages: 0` (no es error).
- [x] `docs/bulletin.md` documenta el endpoint completo (auth, query params, campos de respuesta, errores).
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Forma de la ruta | `GET /api/v1/bulletins` (por usuario autenticado) | `/units/%/bulletins` o `/condominiums/%/bulletins` | Un boletín se dirige a una audiencia por usuario, no a una vivienda; General y Personalizado no cuelgan de una unidad. Consistente con el inbox `/notifications`. |
| Fuente de datos | Nodos `boletin` publicados, leídos directo | Reusar la tabla `myapi_notifications` | La tabla ya es el inbox `/notifications`; leer los nodos da el historial completo y permite exponer `field_adjunto`, que es la razón de tener este endpoint. |
| Modelo de visibilidad | Completo: `field_tipo_de_boletin` × `field_enviar_a` | Solo el tipo, ignorando `send_to` | Lo que un usuario **ve** debe coincidir exacto con lo que fue **notificado**; evita mostrar boletines que nunca le llegaron. |
| Estrategia de filtrado | Query SQL inversa con `db_or()` + `EXISTS` | Cargar todos los boletines y filtrar en PHP con `myapi_boletin_recipient_uids()` | Pagina y cuenta en SQL como pagos; no carga todo el universo en cada request. Se asume el costo de mantener la lógica de roles en paralelo (ver Riesgos). |
| Formato de `message` | HTML crudo de `field_mensaje` | Texto plano (`myapi_notification_plain_text()`) o ambos | Pedido del usuario: la app renderiza formato rico con `flutter_html`; el saneo es responsabilidad del cliente. |
| Adjunto | Solo `file_id` (fid) | URL absoluta (`file_create_url`) o omitirlo | Consistente con `file_id` de pagos; no acopla el endpoint al esquema de archivos. |
| Campo de fecha | `node.created` para orden y filtro | Un campo de fecha propio del boletín | `boletin` no tiene campo de fecha; `created` es el timestamp de publicación. |
| Borde superior del filtro | `strtotime(date_to 23:59:59)` sobre `created` entero | `SUBSTR(...,1,10)` como pagos | `created` es un timestamp Unix, no un string de fecha; el `23:59:59` incluye todo el día indicado. |
| Alcance de la entrega | Solo el listado de colección | Listado + detalle `GET /api/v1/bulletins/%` | Acota la entrega; el detalle va en su propio spec (mismo criterio que el spec 14 hizo solo el listado de pagos). |
| Sin `403` de acceso | La audiencia se aplica en la query; el usuario recibe su conjunto (vacío si nada) | `403` como pagos (`unit_access_denied`) | No hay recurso ajeno que proteger: no es un listado scoped a una entidad de otro, sino el propio conjunto visible del usuario. |
| Helpers de rol del lector | Nuevos en `includes/myapi.unit_access.inc` | Privados en el resource | Son consultas de acceso por unidad/condominio, reutilizables; encajan en el archivo compartido de acceso (aditivo, como hizo el spec 25). |
| Nombres de claves | `message`, `type`, `send_to`, `condominium_id`, `file_id`, `created_at` | `body`, `tipo`, `enviar_a` (español) | CLAUDE.md exige claves JSON en inglés; `condominium_id`/`created_at` ya son convención del proyecto. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Deriva entre la visibilidad y el fan-out.** La query inversa de este endpoint y `myapi_boletin_recipient_uids()` (spec 25) codifican la misma regla de audiencia en dos lugares. Un cambio en una sin la otra rompe la paridad: un usuario vería boletines que no le llegaron, o al revés. | Criterio de aceptación explícito de paridad; documentado en `docs/bulletin.md` y aquí. Si en un spec futuro la regla cambia, ambos lugares se tocan juntos. |
| **Lectura directa de tablas Field API** (`field_tipo_de_boletin`, `field_enviar_a`, `field_condominio`, `field_personalizar`, `field_ocupantes`, `field_mensaje`, `field_adjunto`, `field_propietario`, `field_ocupante`, `field_ocupantes`). Un rename o cambio de cardinalidad rompe silenciosamente la query. | Documentado; mismo trade-off ya aceptado en specs 09/10/11/14/25. |
| **`IN ()` con set vacío.** Si el lector no tiene condominios de un rol (o ninguna unidad), armar `field_condominio_target_id IN ()` genera SQL inválido en Drupal 7. | Cada sub-condición se agrega al `db_or()` solo cuando su set/flag no está vacío; una rama sin datos simplemente no se incluye. Cubierto por el criterio del usuario sin unidades. |
| **`field_enviar_a` sin fila (left join → `NULL`).** Un boletín sin `send_to` no matchea ninguna comparación de rol y queda oculto. | Aceptado: es el mismo fail-safe del fan-out (rol desconocido/ausente ⇒ no se notifica a nadie). |
| **Valores inesperados en `field_tipo_de_boletin`/`field_enviar_a`.** Un valor nuevo agregado por el admin no matchea ninguna rama. | Degrada a oculto (no aparece para nadie), nunca a visible-para-todos. Criterio de aceptación explícito. |
| **Fan-out histórico vs. historial completo.** Este endpoint muestra boletines previos al alta del usuario que el inbox no tiene; puede haber divergencia entre lo que ve en `/bulletins` y en `/notifications`. | Es intencional y la razón de existir del endpoint; documentado en `docs/bulletin.md`. |
| **HTML crudo en `message`.** `field_mensaje` viaja sin sanear; un render inseguro en el cliente podría ejecutar markup no deseado. | El saneo/render seguro es responsabilidad de la app (`flutter_html`); documentado como contrato. |
| **`created` y timezone.** `strtotime()` usa la timezone del proceso PHP; un desalineo con la timezone del sitio podría correr el borde del filtro por horas. | Aceptado; el filtro es laxo (herramienta de conveniencia, no contable). Documentado en `docs/bulletin.md`. |

---

## Lo que **no** entra en este spec

- Detalle individual `GET /api/v1/bulletins/%`.
- Escritura de boletines (crear/editar/borrar).
- Resolver la URL o el contenido del adjunto (solo `file_id`).
- Marca leído/no-leído (vive en `/notifications`).
- Saneo del HTML de `message`.

Cada uno, si aparece, va en su propio spec.
