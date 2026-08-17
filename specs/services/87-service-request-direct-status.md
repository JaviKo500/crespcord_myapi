# 87 — El estado «Proveedor directo» (`direct`) en `field_request_status`

- **Estado:** Implemented
- **Fecha:** 2026-08-17
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña del catálogo
    `myapi_services_request_statuses()`, del grafo
    `myapi_services_request_transitions()`, de la regla
    `myapi_services_close_requires_rating()` y del campo compartido
    `field_request_status` (`list_text`, cardinalidad 1) en `service_request` y
    `service_transaction`. Este spec **amplía** ese catálogo; no crea ningún campo.
  - `78-provider-role` (Implemented) — dueña de
    `myapi_provider_role_request_visible()` y del filtro SQL de
    `myapi_provider_role_visible_request_ids()`, los dos únicos lectores de los
    estados hoy. Ambos tenían la lista `open`/`offered` escrita dos veces.
  - `84-provider-detail` (Implemented) — dueña de la instancia de `field_unit` en
    `service_rating` y de los dos queries de calificaciones, que este spec
    **no** rompe: cuelgan de `field_rating_provider`, no de la oferta.
  - `62-...` (`myapi_update_7021()`, Implemented) — el precedente de cómo se
    toca un `allowed_values` en un sitio ya instalado. Allí se **quitaba** un
    valor y hubo que migrar filas antes; aquí se **añade** y no hay nada que migrar.
  - `86-service-request-unit` (Implemented) — quien **ocupa
    `myapi_update_7032()`**: este spec estrena el `7033`.

**Objetivo:** Añadir un sexto estado, `direct` («Proveedor directo»), al catálogo
de `field_request_status`, para la solicitud que nace ya dirigida a un proveedor
elegido por el residente, sin ronda de ofertas.

Cuatro notas que la cabecera fija:

- **`direct` es una raíz del grafo.** Ningún estado lleva a él: una solicitud
  nace con proveedor elegido o pasa por la ronda, y los dos caminos no se cruzan.
  Sus únicas salidas son `closed` y `cancelled`.
- **Cerrar desde `direct` exige calificación**, igual que desde `assigned`: en
  los dos casos hay un proveedor que hizo el trabajo. Es decisión explícita del
  usuario, y es la que arrastra el único cambio de modelo de este spec.
- **Ese cambio de modelo: `field_rating_offer` pasa a opcional.** Un directo no
  tiene oferta a la que colgar la calificación. Dejar el campo requerido habría
  exigido una calificación imposible de guardar.
- **No hay endpoint que tocar.** `service_request` sigue sin
  `resources/*.resource.inc` y sin ruta en `myapi_menu()`. Ninguna respuesta de
  API cambia de forma; los dos endpoints de proveedores siguen respondiendo igual.

---

## Alcance

**Dentro del alcance:**

- **`includes/myapi.services_common.inc`** (modificar):
  - Nueva constante `MYAPI_SERVICES_REQUEST_STATUS_DIRECT` (`'direct'`), con el
    mismo guard `defined()` que las cinco anteriores.
  - `myapi_services_request_statuses()`: el par `direct => 'Proveedor directo'`
    en **segunda** posición.
  - `myapi_services_request_transitions()`: entrada nueva
    `direct => [closed, cancelled]`, y ninguna otra entrada gana `direct` como
    destino.
  - `myapi_services_close_requires_rating()`: TRUE también para `direct`.
- **`includes/myapi.provider_role.inc`** (modificar): nueva
  `myapi_provider_role_broadcast_statuses()` con los tres estados que se
  difunden por categoría (`open`, `direct`, `offered`), leída por
  `myapi_provider_role_request_visible()` y por el filtro SQL de
  `myapi_provider_role_visible_request_ids()` — que hasta ahora repetían la lista.
- **`myapi.install`** (modificar):
  - La instancia de `field_rating_offer` en `service_rating`: `required => 0`,
    con descripción nueva.
  - La descripción de `field_rating_provider`, que decía que sale «de la oferta
    calificada» y ahora admite el proveedor directo.
  - Nuevo **`myapi_update_7033()`**: `_myapi_services_install()` **más**
    `field_update_field()` sobre `field_request_status` y
    `field_update_instance()` sobre `field_rating_offer`.
- **Pruebas unitarias** — `tests/unit/ServicesInstallTest.php` (catálogo, grafo,
  la raíz, la regla de calificación, los guards del `7033`) y
  `tests/unit/ProviderRoleTest.php` (las tres combinaciones de `direct` y el
  guard de la lista única).
- **`docs/services-install.md`** y **`docs/provider-role.md`** (modificar).
- `drush updb` y `drush cc all` al final.

**Fuera de alcance:**

- **Cualquier endpoint.** Nada crea, lista ni cierra solicitudes todavía. Este
  spec amplía el catálogo y las reglas puras que ese spec futuro leerá, igual que
  SPEC 77 dejó el grafo escrito sin ningún lector.
- **Quién puede poner una solicitud en `direct`**, y con qué permiso. Hoy solo
  `administrator`/`backend` alcanzan el formulario.
- **Restringir un directo al proveedor elegido.** El estado se difunde por
  categoría como un `open` — decisión explícita del usuario. Estrechar la
  visibilidad a `field_assigned_provider` sería una condición **añadida** sobre
  la regla actual, y le toca al spec del flujo que rellene ese campo.
- **Hacer obligatorio `field_assigned_provider` cuando el estado es `direct`.**
  Es una validación condicional entre dos campos, que Drupal 7 no expresa en la
  configuración de campo: necesita `hook_node_validate()`, o el endpoint.
- **Que la calificación de un directo apunte al proveedor correcto.** Al soltar
  el `required` de la oferta, la coherencia oferta ↔ solicitud ↔ proveedor pasa a
  ser íntegramente lógica de negocio. Ver Riesgos.
- **Backfill o migración de filas.** Se añade un valor, no se quita ninguno: no
  hay fila que reescribir, y ninguna solicitud existente pasa a `direct` por sí
  sola.
- **Claves del catálogo `myapi_t()`** — ninguna respuesta de API traduce estados
  todavía.
- **`myapi.info`, `myapi.module`, `resources/*` y `hook_schema()`** — nada que
  añadir: ningún include nuevo, ninguna ruta, ninguna tabla.

---

## Modelo de datos

### El catálogo después de este spec

| Clave | Etiqueta | Spec |
|---|---|:---:|
| `open` | Abierta | 77 |
| **`direct`** | **Proveedor directo** | **87** |
| `offered` | Con ofertas | 77 |
| `assigned` | Asignada | 77 |
| `closed` | Cerrada | 77 |
| `cancelled` | Cancelada | 77 |

`direct` va **segundo** porque el orden del catálogo es el del ciclo de vida y
`direct` es un estado de **entrada**, no un paso de la ronda: `open` y `direct`
son las dos formas en que nace una solicitud. Que se comporte como `assigned` al
cerrar es una regla de `myapi_services_close_requires_rating()`, no un sitio en
la lista.

Como `field_request_status` es **compartido** por `service_request` y
`service_transaction` (SPEC 77), el valor nuevo aparece de golpe en los dos
selects: el de la solicitud y el de la línea de tiempo.

### El grafo

```
open ──(1ª oferta)──> offered ──(adjudicación)──> assigned
                         │                          │
                         └────────> closed <────────┘
                                      ▲
direct ───────────────────────────────┘

open | direct | offered | assigned ──> cancelled
```

| Desde | Hacia | Notas |
|---|---|---|
| `direct` | `closed`, `cancelled` | Las dos únicas salidas. |
| cualquiera | `direct` | **Prohibido.** `direct` no es alcanzable. |

`open → direct` dejaría las ofertas ya recibidas sin nada que las resuelva, y
`assigned → direct` contradiría la oferta que el residente ya adjudicó. Un test
recorre las seis entradas del grafo y falla si alguna nombra `direct` como
destino: el cierre del grafo sobre el catálogo no lo detectaría, porque un estado
al que nadie llega está bien formado.

### La consecuencia: `field_rating_offer` pasa a opcional

| Ajuste | Antes (SPEC 77) | Después (este spec) |
|---|---|---|
| `field_rating_offer` en `service_rating` | `required = 1` | **`required = 0`** |
| `field_rating_provider` en `service_rating` | `required = 1` | `required = 1` (sin cambios) |

La cadena es corta y no tiene salida: cerrar un `direct` exige calificación → un
directo no tiene oferta → una calificación requerida sin oferta posible es una
calificación que no se puede guardar. La referencia que **siempre** existe es
`field_rating_provider`, y esa sigue requerida: una calificación sin proveedor no
puntúa a nadie.

Lo que se pierde con ello, dicho en voz alta: SPEC 77 presumía de que apuntar a
la oferta hacía «irrepresentable» calificar a un proveedor que no ofertó. Eso
deja de ser cierto para los directos, y para los adjudicados nunca se validó que
la oferta fuera la de esa solicitud (lo anotó ya SPEC 84). La integridad
oferta ↔ solicitud ↔ proveedor es, desde este spec, íntegramente responsabilidad
del flujo que cree calificaciones.

Ningún query existente se rompe: los dos de SPEC 84
(`myapi_provider_ratings_recent()`, `myapi_provider_rating_summary()`) hacen
`innerJoin` sobre `field_data_field_rating_provider` y `field_data_field_stars`,
y **nunca** tocan `field_data_field_rating_offer`.

### La visibilidad del rol `proveedor`

| Estado | ¿Se difunde por categoría? |
|---|:---:|
| `open` | Sí |
| **`direct`** | **Sí** |
| `offered` | Sí |
| `assigned`, `closed`, `cancelled` | No (salvo que el proveedor ya ofertara) |

Los tres estados visibles pasan a vivir en **una** función,
`myapi_provider_role_broadcast_statuses()`, leída por las dos mitades del filtro:
la decisión pura (URL directa) y la condición SQL (listados). Antes eran dos
copias de la misma lista, y un estado añadido a una y no a la otra hace una
solicitud alcanzable por URL y ausente de todo listado —o al revés— sin ningún
error que lo delate.

Un directo **no** se estrecha al proveedor elegido: la regla lee estado y
categorías, y `field_assigned_provider` no es una de sus entradas.

---

## Plan de implementación

1. **`includes/myapi.services_common.inc`.** La constante con su guard, el par en
   el catálogo, la entrada del grafo y el segundo brazo de
   `close_requires_rating()`. Los docblocks del grafo y de la regla explican por
   qué `direct` es raíz y por qué arrastra el `required` de la oferta.
   *Verificación: `php -l`.*

2. **`includes/myapi.provider_role.inc`.** Extraer
   `myapi_provider_role_broadcast_statuses()` y hacer que la lean la decisión
   pura y el `db_select()`. *Verificación: `php -l`.*

3. **`myapi.install` — la instancia.** `field_rating_offer` a `required => 0`,
   con el comentario que dice qué decisión del catálogo lo obliga, y las dos
   descripciones al día. *Verificación: `php -l myapi.install`.*

4. **`myapi.install` — `myapi_update_7033()`.** El primer update de esta feature
   que **no** basta con reejecutar el instalador: los helpers `_ensure_*` solo
   crean. `field_update_field()` con los valores leídos del catálogo (nunca
   retecleados), `field_update_instance()` con `required = 0`, y
   `field_info_cache_clear()`. *Verificación: `drush updb`; reejecutable.*

5. **Pruebas.** Sección SPEC 87 en `ServicesInstallTest` y las combinaciones
   nuevas en `ProviderRoleTest`, más el guard de numeración (`7033` existe,
   `7034` no). *Verificación: suite completa en verde.*

6. **Documentación.** `docs/services-install.md` (catálogo, grafo, tabla de
   `service_rating`, tabla de `service_request`, historial de updates) y
   `docs/provider-role.md` (la regla y el mapa de ficheros).

7. **Aplicar y verificar.** `drush updb`, `drush cc all` y recorrer los criterios.

---

## Criterios de aceptación

**Esquema**

- [ ] `admin/structure/types/manage/service-request/fields/field_request_status`
      lista los **seis** valores, con «Proveedor directo» en segundo lugar.
- [ ] El select de estado de `node/add/service-transaction` ofrece los mismos
      seis: el campo es compartido y una sola escritura alcanza a los dos bundles.
- [ ] `node/add/service-request` guarda una solicitud en «Proveedor directo».
- [ ] `field_rating_offer` figura como **no** requerido en
      `admin/structure/types/manage/service-rating/fields`, y `field_rating_provider`
      sigue requerido.
- [ ] `node/add/service-rating` guarda una calificación **sin** oferta y con
      proveedor, y se niega a guardar sin proveedor.
- [ ] En un sitio limpio, `drush en myapi` deja los seis valores sin ningún update.
- [ ] En un sitio ya instalado, `drush updb` ejecuta `myapi_update_7033` y
      reejecutarlo no lanza `FieldUpdateForbiddenException`.

**Datos**

- [ ] `SELECT DISTINCT field_request_status_value FROM field_data_field_request_status`
      devuelve exactamente lo que devolvía antes del update: ninguna fila cambió.
- [ ] Ninguna calificación existente perdió su oferta.

**No regresión**

- [ ] `GET /api/v1/providers`, `GET /api/v1/providers/{id}` y
      `GET /api/v1/service-categories` responden byte a byte igual.
- [ ] Un usuario con rol `proveedor` sigue viendo lo mismo en `assigned`,
      `closed` y `cancelled`; en `direct` ve las de su categoría.
- [ ] Ningún rol gana ni pierde permisos.
- [ ] `myapi_update_7032` y anteriores quedan intactos, con el mismo número.
- [ ] La suite unitaria pasa completa y `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Cómo se entra en `direct` | **Nace en `direct`**; ningún estado lleva a él | `open → direct`, o ambas | Elección explícita del usuario. Mantiene los dos caminos separados y sin estados intermedios que resolver: `open → direct` obligaría a decidir qué pasa con las ofertas ya recibidas (¿se rechazan en bloque? ¿el chat de cada una?) y eso es un flujo entero, no un estado. |
| Calificación al cerrar | **Exigida**, igual que en `assigned` | No exigirla, como en `offered` | Elección explícita del usuario, y es coherente con la regla real: se califica cuando hay un proveedor que hizo el trabajo, y en un directo lo hay. El precio —soltar el `required` de la oferta— se paga aquí y se documenta. |
| Visibilidad para el proveedor | **Se difunde por categoría**, como `open` | Solo el proveedor elegido | Elección explícita del usuario. Además, estrechar el directo exigiría leer `field_assigned_provider`, que nadie rellena todavía: la regla habría quedado escrita contra un campo vacío, negando el acceso a todos. Queda como condición añadida para el spec del flujo. |
| Posición en el catálogo | **Segunda**, junto a `open` | Después de `assigned`, junto al estado con el que comparte la regla de cierre | El orden del catálogo es el del ciclo de vida y también el del select del back office: los dos puntos de entrada juntos, luego la ronda, luego los terminales. Compartir la regla de cierre con `assigned` no lo convierte en un paso de la ronda. |
| La oferta de la calificación | **Opcional** | Requerida, con una oferta «sintética» por cada directo | Un nodo `service_offer` inventado para poder colgar una calificación contaminaría el conteo de ofertas de la solicitud, el historial del proveedor y el propio catálogo de estados de oferta, y habría que inventar también su mensaje e importe. Un campo vacío es un dato ausente; una oferta falsa es un dato falso. |
| La lista de estados difundidos | **Una función** leída por la decisión pura y por el SQL | Añadir `direct` en los dos sitios donde estaba escrita | Duplicar la lista era ya una deuda de SPEC 78: la primera divergencia entre las dos copias produce un acceso incoherente (visible por URL, invisible en el listado) sin ningún error. Extraerla es el cambio mínimo que la hace imposible, y un test lo fija. |
| Aplicación en sitios instalados | **`field_update_field()` + `field_update_instance()`** dentro del `7033` | Reejecutar solo `_myapi_services_install()`, como `7028`–`7032` | Los helpers `_ensure_*` solo **crean**: sobre un campo y una instancia que ya existen no harían nada, y el update habría terminado «con éxito» sin aplicar ni el valor nuevo ni el `required`. Es el primer cambio de esta feature que modifica configuración existente en vez de añadirla. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **El update no aplica nada y no se nota.** Si se hubiera escrito como los cinco anteriores —solo `_myapi_services_install()`— el `drush updb` habría salido en verde y el select seguiría con cinco valores. | `7033` llama a `field_update_field()` y `field_update_instance()` explícitamente, y dos tests unitarios fallan si esas llamadas desaparecen. El criterio de aceptación se comprueba en el formulario, no en la salida del `updb`. |
| **`allowed_values` retecleado en el update**, que es exactamente la deriva que `ServicesInstallTest` vigila en el instalador. | El update lee `myapi_services_request_statuses()`, y un test recorre las claves del catálogo y falla si alguna aparece escrita a mano en la función. |
| **Soltar `required` en `field_rating_offer` abre la puerta a calificaciones sin oferta en solicitudes adjudicadas**, donde sí debería haberla. | Aceptado y documentado: la coherencia oferta ↔ solicitud ↔ proveedor es del flujo que cree calificaciones, que además ya debía validar que la oferta fuera la correcta (deuda anotada por SPEC 84). El schema nunca la validó; lo que cambia es que ahora tampoco garantiza su presencia. |
| **Una solicitud en `direct` sin `field_assigned_provider`.** El campo es opcional y nadie lo valida, así que un directo puede quedar sin proveedor: la calificación al cerrar no tendría a quién apuntar. | Fuera de alcance por decisión explícita (es validación condicional entre campos, imposible en la configuración de campo de D7). Anotado para el spec del endpoint, que debe rechazar `direct` sin proveedor con su propio `error_code`. Hoy no hay ninguna solicitud guardada. |
| **`direct` se difunde a todos los proveedores de la categoría**, incluido el que no fue elegido, que puede ofertar sobre algo ya adjudicado de hecho. | Decisión explícita del usuario. Lo que lo contiene no es la visibilidad sino el grafo: `direct` no puede pasar a `offered` ni a `assigned`, así que una oferta sobre un directo no tiene forma de convertirse en adjudicación. El spec del endpoint de ofertas es quien decide si además la rechaza. |
| **Retirar `direct` más adelante** costaría lo que costó `myapi_update_7021()`: migrar filas antes de tocar el `allowed_values`, porque core prohíbe quitar un valor en uso. | Inherente a añadir un estado. Se anota aquí para que la decisión de añadirlo se tome sabiendo que el camino de vuelta es un update con migración de datos, no un borrado de dos líneas. |
