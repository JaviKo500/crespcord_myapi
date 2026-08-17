# 86 — La vivienda de una solicitud de servicio (instancia de `field_unit` en `service_request`)

- **Estado:** Implemented
- **Fecha:** 2026-08-17
- **Dependencias:**
  - Reservas (fecha no localizada en `specs/`, campo confirmado en `myapi.install`) —
    **dueña de `field_unit`** (`entityreference` → `vivienda`, cardinalidad 1) y de su
    instancia en `reservation`, que es requerida. Este spec **reutiliza** ese campo con
    una instancia nueva; no lo crea ni lo modifica.
  - `53-entityreference-selection-settings` (Implemented) —
    `_myapi_entityreference_field_settings()`, donde `field_unit` ya tiene su
    `target_bundles = ['vivienda' => 'vivienda']`. Al ser ajuste **de campo**, la
    instancia nueva lo hereda sin declarar nada.
  - `77-services-content-types-install` (Implemented) — crea el bundle
    `service_request` y los helpers idempotentes
    `_myapi_reservations_ensure_instance()` que este spec reutiliza tal cual.
  - `84-provider-detail` (Implemented) — **el precedente exacto**: añadió una instancia
    de este mismo `field_unit` al bundle `service_rating` con su propio
    `myapi_update_7030()`. Es también quien documentó la carencia que este spec cierra.
  - `85-provider-logo` (Implemented) — quien **ocupa `myapi_update_7031()`**: este spec
    estrena el `7032`.

**Objetivo:** Dar al content type `service_request` la relación con la vivienda a la que
va dirigido el servicio, mediante una instancia **obligatoria** del campo ya existente
`field_unit` (`entityreference` → `vivienda`, cardinalidad 1).

Cuatro notas que la cabecera fija:

- **No se crea ningún campo.** `field_unit` existe desde la feature de reservas y lo
  usan `reservation` (requerido) y `service_rating` (opcional, SPEC 84). Aquí nace solo
  una instancia más. Es la Regla 3 de `CLAUDE.md`, con el mismo criterio con el que
  SPEC 55/77 reutilizaron `field_condominium` y `field_requester`.
- **La vivienda se registra, no se adivina.** `84-provider-detail.md` dejó escrito por
  qué no se podía inferir: *«`service_request` no tiene `field_unit` y un residente
  puede ocupar más de una vivienda (`myapi_user_occupied_unit_nids()` devuelve una
  lista), así que no hay una única vivienda "correcta" que inferir en caliente»*. Con el
  campo, el dato lo aporta quien crea la solicitud.
- **Es obligatoria, y es el primer update de esta feature que lo es.** `7028`–`7031`
  añadieron campos opcionales, que nacen vacíos y no invalidan nada. Una instancia
  requerida sobre un bundle ya instalado impide **volver a guardar** una solicitud que
  no la tenga. Ver Riesgos: hoy no existe ninguna solicitud, y por eso se puede.
- **No hay endpoint que tocar.** `service_request` sigue sin ningún
  `resources/*.resource.inc` y sin ninguna ruta en `myapi_menu()`. Este spec es puro
  esquema: ninguna respuesta de API cambia de forma.

---

## Alcance

**Dentro del alcance:**

- **`myapi.install`** (modificar):
  - `_myapi_services_install()`, bloque de instancias de `service_request`: una
    instancia nueva de `field_unit`, `required = 1`, label «Vivienda», widget
    `entityreference_autocomplete`, colocada junto a `field_requester` y
    `field_condominium` porque los tres son el «a quién / dónde» de la solicitud.
  - Nuevo **`myapi_update_7032()`**: una llamada a `_myapi_services_install()`, mismo
    patrón que `myapi_update_7028()`, `7029()`, `7030()` y `7031()`.
  - `_myapi_services_uninstall_destructive()`: **no cambia**. `field_unit` es prestado,
    así que sigue fuera de `$owned`, igual que `field_condominium`/`field_requester`.
    La instancia se pierde con el bundle; el campo sobrevive porque nunca se listó.
- **Pruebas unitarias** — ampliación de `tests/unit/ServicesInstallTest.php`: la
  instancia nueva (requerida, sin `settings`), que la de `service_rating` sigue siendo
  opcional, que `7032` no borra ni escribe nada, y el guard de numeración actualizado.
- **`docs/services-install.md`** (modificar) — la tabla de campos de `service_request`,
  la de campos prestados y el historial de updates.
- `drush updb` y `drush cc all` al final.

**Fuera de alcance:**

- **Cualquier endpoint.** Crear, listar o consultar solicitudes sigue sin especificar.
  Este spec solo abre el campo; nada lo escribe todavía, igual que `field_unit` en
  `service_rating` (SPEC 84) y `field_rating_avg` en SPEC 77 nacieron sin nadie que los
  rellenara.
- **Quién valida que la vivienda sea del solicitante.** Los helpers existen
  (`myapi_user_occupied_unit_nids()` en `includes/myapi.unit_access.inc:190`,
  `myapi_user_owned_unit_nids()` en `:166`), pero nadie los llama sobre una solicitud.
  Hoy el único que alcanza el formulario es `administrator`. Lo decidirá el spec del
  endpoint de creación, que además tendrá que resolver si la vivienda debe pertenecer
  al condominio de `field_condominium` — una coherencia que Drupal no valida sola.
- **La entrada de `service_request` en `myapi_building_admin_condominium_map()`**, que
  sigue ausente. Este campo no la habilita ni la necesita: el alcance por edificio lo
  da `field_condominium`, que ya está desde SPEC 77.
- **Backfill** de solicitudes existentes. No hay ninguna; si el sitio tuviera alguna,
  rellenarla es trabajo manual del operador.
- **Claves del catálogo `myapi_t()`** — no hay respuesta de API que traducir.
- **`myapi.info`, `myapi.module`, `resources/*` y `hook_schema()`** — nada que añadir:
  ningún include, ninguna ruta, ninguna tabla. Las tablas
  `field_data_field_unit` / `field_revision_field_unit` ya existen desde reservas.

---

## Modelo de datos

### El campo reutilizado: instancia de `field_unit` en `service_request`

| Ajuste | Valor |
|---|---|
| `field_name` | `field_unit` (ya existe; **no** se crea) |
| Tipo / cardinalidad | `entityreference` → `vivienda`, 1 — **de campo**, compartido |
| `bundle` | `service_request` |
| `label` | Vivienda |
| `required` | **1** |
| `description` | Vivienda a la que va dirigido el servicio. |
| `widget` | `entityreference_autocomplete` |
| `settings` | **ninguno** en la instancia |

Sin `settings` en la instancia: el `target_bundles` (`vivienda`) es ajuste de campo y ya
lo fija `_myapi_entityreference_field_settings()['field_unit']`, el mismo que usan las
instancias de `reservation` y `service_rating`.

### Estado de `service_request` después de este spec

| Campo | Tipo | Card. | Req. | Notas |
|---|---|:---:|:---:|---|
| `field_requester` | entityreference → user | 1 | Sí | Compartido (reservas). |
| `field_condominium` | entityreference → `condominio` | 1 | Sí | Compartido (reservas). |
| **`field_unit`** | **entityreference → `vivienda`** | **1** | **Sí** | **Nuevo aquí.** Compartido con `reservation` y `service_rating`. |
| `field_category` | taxonomy_term_reference | 1 | Sí | |
| `field_description` | text_long | 1 | Sí | Compartido (reclamos). |
| `field_desired_start` | datestamp | 1 | Sí | |
| `field_images` | image | ∞ | No | Compartido → `private://`. |
| `field_attachment` | file | 1 | No | Compartido → `private://`. |
| `field_request_status` | list_text | 1 | Sí | Default `open`. |
| `field_assigned_offer` | entityreference → `service_offer` | 1 | No | |
| `field_assigned_provider` | entityreference → `provider` | 1 | No | Denormalizado. |
| `field_closed_at` | datestamp | 1 | No | |

### Las tres instancias de `field_unit`, y por qué divergen

| Bundle | `required` | Quién la creó | Por qué |
|---|:---:|---|---|
| `reservation` | 1 | Reservas | No se reserva un área «para nadie». |
| `service_rating` | 0 | SPEC 84 | Se añadió a un bundle que podía tener nodos, y el flujo que la rellenaría no existe. |
| `service_request` | **1** | Este spec | El servicio va a una vivienda concreta; sin ella la solicitud no es accionable. Se puede exigir porque hoy no hay ninguna solicitud guardada. |

`required` es ajuste **de instancia**: las tres divergen sin tocarse entre ellas. Lo que
sí es común y no puede divergir es el tipo, la cardinalidad y el `target_bundles`.

---

## Plan de implementación

1. **`myapi.install` — la instancia.** En el bloque `// (d) Instances of
   'service_request'.`, después de la de `field_condominium`:
   `_myapi_reservations_ensure_instance('field_unit', $request_type, [...])` con
   `'required' => 1` explícito. Con un comentario que diga que el campo es prestado, que
   no se crea aquí, y por qué esta instancia es requerida y la de `service_rating` no.
   **No** se llama a `_myapi_reservations_ensure_field('field_unit', ...)` y **no** se
   toca el teardown. *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — `myapi_update_7032()`.** Después de `myapi_update_7031()`, con el
   docblock que explica por qué reejecuta el instalador completo y que la instancia nace
   obligatoria sin backfill. *Verificación: `drush updb` en el sitio; reejecutable.*

3. **Pruebas.** Ampliar `tests/unit/ServicesInstallTest.php` con la sección de este spec
   y actualizar `testTheUpdateNumberingOfPreviousSpecsIsUntouched()` para que el número
   libre pase a ser `7033`. *Verificación: suite completa en verde.*

4. **Documentación.** `docs/services-install.md`: la fila en la tabla de campos, la fila
   en la tabla de campos prestados (que hasta ahora no mencionaba `field_unit`) y la
   entrada `7032` en el historial de updates, diciendo que es el primero obligatorio.

5. **Aplicar y verificar.** `drush updb`, `drush cc all` y recorrer los criterios.

---

## Criterios de aceptación

**Esquema**

- [x] `admin/structure/types/manage/service-request/fields` lista **Vivienda** como
      requerida, con widget autocompletar.
- [x] El autocompletado de esa vivienda ofrece **solo** nodos `vivienda`: ninguna
      solicitud, ningún recibo, ningún condominio.
- [x] `node/add/service-request` se niega a guardar sin vivienda y guarda con ella.
- [x] En un sitio limpio, `drush en myapi` crea la instancia sin update ninguno.
- [x] En un sitio ya instalado, `drush updb` ejecuta `myapi_update_7032`.
- [x] Reejecutar `_myapi_services_install()` no duplica la instancia ni lanza
      `FieldException`.

**El campo prestado — el riesgo real de este spec**

- [x] `field_info_field('field_unit')` sigue devolviendo **un solo** campo, con su
      cardinalidad 1 y su `target_bundles` intactos.
- [x] La instancia de `reservation` sigue requerida y la de `service_rating` sigue
      **opcional**: nada se ha filtrado de una a otra.
- [x] `field_unit` sigue **fuera** de `$owned` en
      `_myapi_services_uninstall_destructive()`, y un test unitario falla si entra.
- [x] `_myapi_services_install()` no llama a
      `_myapi_reservations_ensure_field('field_unit', ...)` ninguna vez.

**No regresión**

- [x] Ningún endpoint `api/v1/...` cambia de respuesta: no se ha tocado nada de
      `resources/`. `GET /api/v1/reservations`, `GET /api/v1/units` y
      `GET /api/v1/providers/{id}` responden exactamente igual.
- [x] Ningún rol gana ni pierde permisos.
- [x] `myapi_update_7031` y anteriores quedan intactos, con el mismo número.
- [x] `myapi.info` no declara nada nuevo.
- [x] La suite unitaria pasa completa y `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Obligatoriedad | **Requerida** (`required = 1`) | Opcional, como la instancia de SPEC 84 en `service_rating` | Elección explícita del usuario. Un servicio va a una vivienda concreta: una solicitud sin ella no es accionable ni por el proveedor ni por el administrador, y dejarla opcional garantizaría que el endpoint futuro tuviera que exigirla por su cuenta, con la validación duplicada. Se puede exigir hoy porque el bundle no tiene ni un nodo guardado; en `service_rating` no se pudo por eso mismo al revés. |
| Campo | **Reutilizar `field_unit`** | Crear `field_request_unit` propio del bundle | Regla 3 de `CLAUDE.md` al pie de la letra, con el precedente de SPEC 84 y de `field_condominium`/`field_requester`. El precio es que cualquier cambio futuro al **campo** (cardinalidad, `target_bundles`) afecta a la vez a `reservation`, `service_rating` y `service_request`; los ajustes de instancia —empezando por `required`— siguen siendo independientes, y este spec ya los hace divergir. |
| Alcance | **Solo esquema** | Añadir de paso el flujo que la rellena | El endpoint de creación de solicitudes no existe todavía. Escribir la lógica sin la ruta que la invoca deja código inalcanzable, que es justo lo que SPEC 77 evitó al dejar el grafo de transiciones escrito y sin lector. |
| Posición en el formulario | Junto a `field_requester` y `field_condominium` | Al final, junto a los campos denormalizados | Los tres responden a la misma pregunta —de quién y dónde es esta solicitud— y quien rellena el formulario los espera juntos. Los denormalizados son otra categoría: nadie los escribe a mano. |
| Coherencia vivienda ↔ condominio | Fuera de este spec | Validarla ya, en `hook_node_validate()` | Es lógica de negocio, no configuración de campo, y hoy ningún rol operativo alcanza el formulario. Queda anotada como deuda del spec del endpoint, que es quien puede rechazar con un `error_code`. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **`required = 1` sobre un bundle ya instalado.** Si el sitio tuviera solicitudes guardadas sin vivienda, no se podrían volver a guardar —ni desde el back office ni con `node_save()` validado— hasta rellenarla. | Se comprueba **antes** de `drush updb` con `SELECT COUNT(*) FROM node WHERE type = 'service_request'`. Se espera 0: SPEC 77 dejó fuera de alcance todo flujo que las cree y solo `administrator`/`backend` alcanzan el formulario. Si no fuera 0, la decisión (rellenar a mano o dejar el campo opcional) es del usuario, no del implementador. |
| **Borrado accidental del campo prestado** en un uninstall destructivo: `field_delete_field('field_unit')` vaciaría las reservas. | El teardown **no se toca** y `field_unit` sigue fuera de `$owned`. `ServicesInstallTest::testFieldUnitStaysOutOfDestructiveUninstall()` (SPEC 84) ya falla si alguien lo añade. |
| **La vivienda puede no pertenecer al condominio de `field_condominium`.** Drupal no valida esa coherencia: el autocompletado ofrece todas las viviendas del sitio. | Aceptado y documentado. Hoy solo `administrator` llega al formulario. La validación es del spec del endpoint, que tiene `myapi_units_condominium_nids()` (`includes/myapi.unit_access.inc:221`) para resolverla sin consulta nueva. |
| **Nadie escribe el campo todavía**, así que la primera solicitud creada por API dependerá de que ese spec futuro lo mande. | Al ser requerido, `node_save()` con validación falla si no llega: el error salta en desarrollo, no en producción con datos incompletos. Es la ventaja de exigirlo ahora y no después. |
| **Divergencia de `required` entre las tres instancias** del mismo campo: quien lea el campo y no la instancia puede suponer que siempre viene relleno. | La tabla de las tres instancias de este spec y `docs/services-install.md` lo dejan escrito, y un test unitario fija los dos valores para que ningún cambio los iguale en silencio. |
