# SPEC 45 — Aforo simultáneo por área (`field_max_concurrent_reservations`)

> **Estado:** Implemented · **Depende de:** SPEC 35 (`POST /api/v1/reservations` y sus 8 validaciones ordenadas), SPEC 40 (disponibilidad y helpers `busy_rows`/`busy_ranges`), SPEC 41 (eje absoluto de minutos y normalización de `field_date`), SPEC 42 (disponibilidad por sesión), SPEC 44 (último `hook_update_N` y paso del item de área a 14 claves) · **Fecha:** 2026-07-27
> **Objetivo:** Generalizar la validación 6 de `POST /api/v1/reservations` de "un solape rechaza" a un aforo de N reservas simultáneas declarado por área en el campo nuevo `field_max_concurrent_reservations`, exponiendo la capacidad efectiva en el item de área y la ocupación por tramos en `GET /api/v1/areas/{id}/availability`, sin alterar el comportamiento de las áreas de capacidad 1.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - Nuevo field `field_max_concurrent_reservations` (`number_integer`, `cardinality` 1) en el bloque (b) de fields de `_myapi_reservations_install()`.
  - Instance **solo** en el bundle `area`, en el bloque (c): `label` "Reservas simultáneas permitidas", `required` 0, widget `number`, `default_value` 1, `description` explicando que vacío o menor que 1 se interpreta como 1.
  - `myapi_update_7009()` idempotente, con el mismo patrón que `myapi_update_7007()`/`myapi_update_7008()` (`_myapi_reservations_ensure_field()` + `_myapi_reservations_ensure_instance()`).
  - `'field_max_concurrent_reservations'` añadido al array `$fields` de `_myapi_reservations_uninstall_destructive()`.

- **`includes/myapi.reservation_query.inc`** (modificar) — tres funciones **puras nuevas** (sin BD, sin Drupal, unit-testables):
  - `myapi_reservation_effective_capacity($raw)` — regla de normalización fail-closed, único sitio donde vive.
  - `myapi_reservation_peak_concurrency($intervals, $cand_start, $cand_end)` — pico de reservas simultáneas dentro de la ventana del candidato, sobre intervalos ya proyectados al eje absoluto.
  - `myapi_reservation_occupancy_ranges($busy, $capacity)` — partición del eje en tramos de ocupación constante con `reserved`/`remaining`.

- **`resources/reservation.resource.inc`** (modificar):
  - `module_load_include('inc', 'myapi', 'includes/myapi.reservation_query')` en la cabecera del archivo.
  - **Validación 6** reescrita: leer y normalizar la capacidad del área ya cargada, y rechazar solo cuando `pico + 1 > capacidad`. Sigue dentro del bloque `db_transaction()` + `SELECT ... FOR UPDATE` existente.
  - `myapi_reservation_has_overlap()` se reescribe y se renombra a `myapi_reservation_fetch_abs_intervals()`, devolviendo los intervalos absolutos en vez de un booleano.

- **`resources/area.resource.inc`** (modificar):
  - `leftJoin` a `field_data_field_max_concurrent_reservations` (alias `fcap`) en `myapi_area_base_select()`, con `addField` del `_value`.
  - Clave `max_concurrent_reservations` **normalizada** (int, siempre ≥ 1) al final de `myapi_area_build_item()`. El item pasa de 14 a 15 claves.
  - `myapi_area_availability()`: la respuesta pasa de `{date, busy}` a `{date, capacity, busy, occupancy}`, con `busy` calculado de forma distinta según la capacidad efectiva.

- **`includes/myapi.i18n.inc` y `docs/i18n.md`** — clave nueva `area_capacity_full` en `en` y `es`.

- **`tests/unit/ReservationCapacityTest.php`** (nuevo) — cobertura de `peak_concurrency()` y `occupancy_ranges()`, escrito **antes** de tocar la lógica.

- **`docs/area.md` y `docs/reservation.md`** — clave nueva, sección de `availability` reescrita, validación 6 con su nueva semántica y `area_capacity_full` en la tabla de errores del `POST`.

**Fuera de alcance (para futuros specs):**

- **La validación 7 (una reserva confirmada activa por vivienda+área) no se toca.** El aforo es del área, no de la vivienda; una misma vivienda sigue sin poder tener dos reservas activas en la misma área aunque queden plazas.
- **El aforo cuenta reservas, no personas.** No se añade número de asistentes ni ningún campo por reserva.
- **Endpoints de escritura de áreas.** `field_max_concurrent_reservations` se edita solo desde el admin de Drupal, como `field_area_notes` (SPEC 44).
- **Aforo por franja horaria o por día de la semana.** La capacidad es un entero único por área.
- **Validaciones 1–5 y 8, el orden de las validaciones y sus `error_code`.** Solo cambia la 6.
- **Áreas con ventanas de más de 24 h / doble cruce de medianoche.** Mismo supuesto que SPEC 41 y 42: el fetch `D±1` cubre un solo cruce.
- **Exponer identidad en `occupancy`.** Ni `id`, ni `unit_id`, ni `requester_id`, ni nombres: la regla de privacidad de SPEC 40 se mantiene intacta.
- **Cambiar el cliente Flutter o el contrato del request.** Los cambios de respuesta son aditivos.
- **Filtrar u ordenar áreas por capacidad.**
- **Tests de integración/HTTP con fixtures.** Solo unit sobre funciones puras + matriz `curl`, mismo precedente que SPEC 40/41/42.

---

## Modelo de datos

### Field nuevo (storage), una sola vez para todo el sitio

```php
_myapi_reservations_ensure_field('field_max_concurrent_reservations', [
  'field_name'  => 'field_max_concurrent_reservations',
  'type'        => 'number_integer',
  'cardinality' => 1,
]);
```

### Instance, solo en el bundle `area`

```php
_myapi_reservations_ensure_instance('field_max_concurrent_reservations', 'area', [
  'field_name'    => 'field_max_concurrent_reservations',
  'entity_type'   => 'node',
  'bundle'        => 'area',
  'label'         => 'Reservas simultáneas permitidas',
  'required'      => 0,
  'description'   => 'Número de reservas que pueden coincidir en el mismo horario. Vacío o menor que 1 se interpreta como 1.',
  'default_value' => [['value' => 1]],
  'widget'        => ['type' => 'number'],
]);
```

Field API crea `field_data_field_max_concurrent_reservations` y su tabla de revisiones con la columna `field_max_concurrent_reservations_value` (int). El bundle `reservation` **no** recibe instance.

### Capacidad efectiva (regla de normalización)

```
capacidad_efectiva = ($raw === NULL || (int) $raw < 1) ? 1 : (int) $raw
```

Vive en **una sola función**, `myapi_reservation_effective_capacity($raw)`, en `includes/myapi.reservation_query.inc`. La usan los tres llamadores: la validación 6, `myapi_area_build_item()` y `myapi_area_availability()`.

La regla es **fail-closed a propósito**: un área a la que se le olvide rellenar el campo se comporta exactamente como hoy (capacidad 1, solape binario). `NULL` nunca significa ilimitado. Un `0` o un negativo introducidos a mano tampoco cierran el área: se leen como 1. Esto queda documentado en el docblock de la función.

| Valor en el nodo | Capacidad efectiva |
|---|---|
| Sin fila en la tabla del campo | 1 |
| `NULL` | 1 |
| `0` o negativo | 1 |
| `1` | 1 |
| `n > 1` | `n` |

### Eje absoluto (heredado de SPEC 41, sin cambios)

Las funciones nuevas **no** recalculan la proyección: reciben intervalos **ya proyectados** como pares `[start_abs, end_abs]` de minutos absolutos. `myapi_reservation_abs_interval()` se queda donde está (`resources/reservation.resource.inc`), sin moverse. Así las funciones puras nuevas no dependen de ella y no hay riesgo de redeclaración cuando PHPUnit hace `require_once` de los dos archivos en la misma ejecución.

### `myapi_reservation_peak_concurrency($intervals, $cand_start, $cand_end)`

Devuelve el número **máximo** de reservas existentes simultáneas en algún instante dentro de `[$cand_start, $cand_end)`.

```
1. Filtrar a las solapantes con el criterio SEMIABIERTO del módulo:  s < cand_end && e > cand_start
2. Si no queda ninguna → 0
3. Puntos a evaluar: cand_start, más el inicio de cada solapante estrictamente dentro de la ventana
4. Para cada punto t: contar intervalos con  s <= t && e > t
5. Devolver el máximo
```

Semiabierto significa que **una reserva que acaba justo cuando empieza otra no es simultánea**, igual que hoy no solapa. La concurrencia solo sube en un inicio, así que el máximo dentro de la ventana ocurre necesariamente en uno de los puntos del paso 3.

Es O(n²) a propósito: `n` es un puñado de filas por área y día, y el código se razona y se testea mucho mejor que una cola de eventos.

### `myapi_reservation_occupancy_ranges($busy, $capacity)`

`$busy` es la salida de `myapi_reservation_busy_ranges()` (items de 4 claves `start_date`, `start_time`, `end_date`, `end_time`), ya filtrada por sesión y ordenada. Devuelve la lista de **tramos de ocupación constante**: parte el eje por todos los límites (inicios y finales), y por cada tramo con al menos una reserva emite:

```json
{
  "start_date": "2026-08-01",
  "start_time": "10:00",
  "end_date": "2026-08-01",
  "end_time": "11:00",
  "reserved": 2,
  "remaining": 1
}
```

- `reserved` — int, número de reservas activas en ese tramo.
- `remaining` — int, `max(0, $capacity - $reserved)`. **Nunca negativo**: un admin puede bajar la capacidad con reservas ya creadas.
- Los tramos con `reserved = 0` **no se emiten**.
- Ordenada ascendente por `(start_date, start_time)`.
- Lista vacía de entrada → `[]` de salida.

### Item de área: 14 → 15 claves

```php
$query->leftJoin('field_data_field_max_concurrent_reservations', 'fcap', "fcap.entity_id = n.nid AND fcap.entity_type = 'node' AND fcap.deleted = 0");
$query->addField('fcap', 'field_max_concurrent_reservations_value', 'max_concurrent_reservations');
```

La clave va **al final**, después de `notes`:

```json
{
  "...": "14 claves previas, sin cambios",
  "notes": "Aforo máximo 20 personas.",
  "max_concurrent_reservations": 3
}
```

**Desviación deliberada de la convención del recurso.** Todos los demás campos del item pasan crudos, con `NULL` cuando el nodo no tiene fila. Este **no**: se expone ya **normalizado** (int, siempre ≥ 1). El cliente necesita la capacidad efectiva para pintar plazas libres, y duplicar la regla de normalización en Flutter es exactamente lo que produciría discrepancias con el servidor. Se documenta en el docblock de `myapi_area_build_item()` y en `docs/area.md`.

### Respuesta de `GET /api/v1/areas/{id}/availability`

```json
{ "date": "2026-08-01", "capacity": 3, "busy": [], "occupancy": [] }
```

- **`capacity`** — la capacidad efectiva normalizada del área. Siempre presente, siempre ≥ 1.
- **`occupancy`** — salida de `myapi_reservation_occupancy_ranges()`. **Siempre presente**, también en áreas de capacidad 1. Solo contadores: ni `id`, ni `unit_id`, ni `requester_id`, ni nombres.
- **`busy`** — conserva sus 4 claves y su significado ("esto no lo puedes reservar"), pero se calcula distinto según la capacidad efectiva:

| Capacidad efectiva | Cómo se calcula `busy` |
|---|---|
| `== 1` | **Exactamente** lo que devuelve hoy `myapi_reservation_busy_ranges()`, sin tocar nada: una entrada por reserva. |
| `> 1` | Solo los tramos **saturados** (`reserved >= capacity`) derivados de `occupancy`, fusionando tramos saturados contiguos (fin de uno == inicio del siguiente) en un único bloque. |

Este desdoble es intencional. Si se aplicara el cálculo de bloques a **todas** las áreas, dos reservas consecutivas (`10:00-11:00` y `11:00-12:00`) en un área de capacidad 1 se fusionarían en un bloque `10:00-12:00`, cambiando el payload de todas las áreas ya en producción. Con el desdoble la regresión es cero y `ReservationBusyRangesTest` sigue pasando **sin modificarse**.

Todo el manejo de sesiones de SPEC 42 (áreas que cierran tras medianoche, fetch de `D` y `D+1`) sigue igual: la ocupación se calcula sobre las filas que `myapi_reservation_busy_ranges()` ya asignó a la sesión.

### `myapi_reservation_has_overlap()` → `myapi_reservation_fetch_abs_intervals()`

La función actual devuelve `bool` y su único caller es la validación 6, que ahora necesita los **intervalos**, no un sí/no. Se reescribe y se renombra a `myapi_reservation_fetch_abs_intervals($area_id, $date, $start_minutes, $end_minutes)`, devolviendo la lista de pares `[start_abs, end_abs]` de las reservas confirmadas de las tres fechas.

**La query no cambia**: sigue siendo `reservation` publicadas, `field_reservation_status = 'confirmed'`, del área, con `field_date IN (D−1, D, D+1)`. Esas tres fechas son las que capturan las colas que cruzan medianoche (SPEC 41) y no se tocan. Lo único que cambia es que en vez de comparar y devolver `TRUE` al primer solape, proyecta cada fila con `myapi_reservation_abs_interval()` y devuelve todos los intervalos.

### Sin datos nuevos fuera de eso

No hay tablas `myapi_*` nuevas, ni cambios en `hook_schema()`, ni en el bundle `reservation`, ni en `hook_menu()`, ni en `myapi.info` (no hay `.inc` nuevo).

---

## Plan de implementación

Los pasos 4–7 forman un bloque TDD: el 4 deja los unit tests **en rojo** a propósito (las funciones aún no existen) y el 7 los pone en verde. Si se prefieren commits siempre verdes, ese bloque se agrupa en un único commit.

1. **`myapi.install` — instalación limpia.** Añadir `_myapi_reservations_ensure_field('field_max_concurrent_reservations', ...)` al bloque (b) de fields y `_myapi_reservations_ensure_instance('field_max_concurrent_reservations', 'area', ...)` al final del bloque (c) de instancias del bundle `area`, con las definiciones exactas del modelo de datos. *Verificación: `php -l myapi.install`.*

2. **`myapi.install` — uninstall y `hook_update_7009()`.** Añadir `'field_max_concurrent_reservations'` al array `$fields` de `_myapi_reservations_uninstall_destructive()`, y crear `myapi_update_7009()` tras `myapi_update_7008()` con la misma estructura idempotente: las dos llamadas a los helpers `ensure_*` con las definiciones del paso 1, y docblock explicando que solo toca el bundle `area` y que no añade endpoint ni lógica de negocio. *Verificación: `php -l`; `drush updb` en un sitio ya instalado; el formulario `node/add/area` muestra "Reservas simultáneas permitidas" con widget numérico y `1` precargado.*

3. **Catálogo i18n.** Añadir `'area_capacity_full'` a los arrays `en` y `es` de `includes/myapi.i18n.inc`, junto al resto de errores de reservas, y su fila en la tabla de `docs/i18n.md`:
   - `en`: "This area has reached its reservation capacity for that time slot."
   - `es`: "El área ya alcanzó su cupo de reservas para ese horario."

   El texto de `reservation_overlap` **no se toca**. *Verificación: `php -l`; `myapi_t('area_capacity_full')` resuelve en ambos idiomas.*

4. **`tests/unit/ReservationCapacityTest.php` (nuevo).** Escrito **antes** de la lógica, siguiendo el estilo de `ReservationBusyRangesTest.php`: `require_once` directo de `includes/myapi.reservation_query.inc`, fechas fijas, docblock que explique el escenario de referencia. Cobertura mínima detallada en Criterios de aceptación. *Verificación: `vendor/bin/phpunit` — en rojo, con "función no definida", que es la señal de que el test ejercita lo correcto.*

5. **`myapi_reservation_effective_capacity($raw)`** en `includes/myapi.reservation_query.inc`, junto a `myapi_reservation_time_to_minutes()`. Docblock explicando el razonamiento fail-closed: `NULL` nunca significa ilimitado, un área sin el campo se comporta como hoy. *Verificación: `php -l`.*

6. **`myapi_reservation_peak_concurrency($intervals, $cand_start, $cand_end)`** en el mismo archivo, con el algoritmo de 5 pasos del modelo de datos. Docblock que justifique el criterio semiabierto y por qué basta evaluar los puntos de inicio. *Verificación: la parte de `peak_concurrency` del test pasa.*

7. **`myapi_reservation_occupancy_ranges($busy, $capacity)`** en el mismo archivo: partición del eje por todos los límites, emisión de los tramos con `reserved >= 1`, `remaining = max(0, capacity - reserved)`, orden ascendente por `(start_date, start_time)`. *Verificación: `vendor/bin/phpunit` **en verde**, todos los tests del archivo nuevo.*

8. **`resources/reservation.resource.inc` — carga y query.** Añadir `module_load_include('inc', 'myapi', 'includes/myapi.reservation_query')` a la cabecera del archivo, junto a los demás. Reescribir `myapi_reservation_has_overlap()` como `myapi_reservation_fetch_abs_intervals($area_id, $date, $start_minutes, $end_minutes)`: **misma query** (`confirmed`, publicadas, del área, `field_date IN (D−1, D, D+1)`), pero devolviendo la lista de pares `[start_abs, end_abs]` proyectados con `myapi_reservation_abs_interval()` en vez de un `bool`. *Verificación: `php -l`; ningún otro caller queda huérfano (`grep -rn has_overlap`).*

9. **Validación 6 reescrita** en `myapi_reservation_create()`, en su sitio actual, **dentro** del bloque `db_transaction()` + `SELECT ... FOR UPDATE` sobre el nodo del área:
   1. `$capacity = myapi_reservation_effective_capacity(myapi_reservation_node_value($area, 'field_max_concurrent_reservations'))`.
   2. Traer los intervalos con `myapi_reservation_fetch_abs_intervals()` y proyectar el candidato con `myapi_reservation_abs_interval()`.
   3. Atajo: si el número de intervalos solapantes es `< $capacity`, aceptar sin calcular el pico.
   4. Si no, `$peak = myapi_reservation_peak_concurrency(...)` y rechazar cuando `$peak + 1 > $capacity` (el `+1` es el propio candidato).
   5. Al rechazar: `$capacity == 1` → `myapi_error('reservation_overlap', 409)`; `$capacity > 1` → `myapi_error('area_capacity_full', 409)`.

   Comentario en el código dejando escrito **por qué** el `FOR UPDATE` sigue ahí: sin ese lock dos peticiones concurrentes leerían ambas "quedan plazas" y el aforo sería trivialmente superable. Las validaciones 1–5, 7 y 8 quedan intactas, en el mismo orden y con los mismos `error_code`. *Verificación: `php -l`; matriz `curl` de no-regresión con un área de capacidad 1.*

10. **`myapi_area_base_select()`.** `leftJoin` de `field_data_field_max_concurrent_reservations` con alias `fcap` y `addField` del `_value`, colocado tras el bloque de `field_area_notes`. *Verificación: `php -l`; el listado sigue devolviendo los mismos items — la columna se selecciona pero aún no se mapea, así que el paso deja el sistema funcional.*

11. **`myapi_area_build_item()`.** Añadir `'max_concurrent_reservations' => myapi_reservation_effective_capacity($area->max_concurrent_reservations)` como última clave, tras `notes`. Actualizar los docblocks que citan "14-key item shape" / "all 14 mapped keys" a **15**, y documentar en el docblock de la función que esta clave es la **única** que se expone normalizada en vez de cruda, con su motivo. *Verificación: `GET /api/v1/areas/{id}` devuelve 15 claves; un área sin fila devuelve `1`, no `null`.*

12. **`myapi_area_availability()`.** Tras calcular `$busy` con `myapi_reservation_busy_ranges()` (sin tocar esa llamada ni el manejo de sesión de SPEC 42):
    1. `$capacity = myapi_reservation_effective_capacity($area->max_concurrent_reservations)`.
    2. `$occupancy = myapi_reservation_occupancy_ranges($busy, $capacity)`.
    3. Si `$capacity > 1`, sustituir `$busy` por los tramos saturados (`reserved >= $capacity`) derivados de `$occupancy`, fusionando los contiguos y quedándose con las 4 claves de fecha/hora. Si `$capacity == 1`, `$busy` queda **tal cual**.
    4. `myapi_respond(['date' => $date, 'capacity' => $capacity, 'busy' => $busy, 'occupancy' => $occupancy], 200)`.

    Comentario explicando el desdoble y por qué no se aplica el cálculo de bloques a las áreas de capacidad 1. *Verificación: `php -l`; `curl` sobre un área de capacidad 1 devuelve el mismo `busy` que antes del cambio.*

13. **`docs/area.md`.** Clave `max_concurrent_reservations` en los ejemplos JSON de los dos endpoints de lectura, fila nueva en la tabla de mapeo de campos y en la de joins (con la nota de que se expone normalizada), y la sección de `availability` reescrita con `capacity`/`occupancy`, el desdoble de `busy` y la matriz `curl` ampliada. *Verificación: el doc casa clave por clave con el item y la respuesta implementados.*

14. **`docs/reservation.md`.** Validación 6 con su nueva semántica (aforo de N, pico de concurrencia, criterio semiabierto) y fila `409 area_capacity_full` en la tabla de errores del `POST`, dejando claro que las áreas de capacidad 1 siguen devolviendo `reservation_overlap`. *Verificación: el doc casa con el contrato.*

15. **`drush updb && drush cc all`** y ejecución de la matriz `curl` documentada. `updb` ejecuta `myapi_update_7009()`; el cache clear es obligatorio para que Field API vea la definición nueva y para recoger los `.inc` modificados.

---

## Criterios de aceptación

**Campo e instalación**

- [x] Tras `drush updb`, `field_info_field('field_max_concurrent_reservations')` existe y `field_info_instance('node', 'field_max_concurrent_reservations', 'area')` devuelve el instance; el bundle `reservation` **no** tiene instance de ese campo.
- [x] El formulario `node/add/area` muestra "Reservas simultáneas permitidas" como campo numérico no obligatorio, con `1` precargado y la descripción visible.
- [x] Reejecutar `myapi_update_7009()` no lanza `FieldException` ni duplica el campo o el instance.
- [x] Una instalación limpia (`drush en myapi` sobre BD virgen) crea el campo y su instance sin necesidad de `updb`.

**`myapi_reservation_effective_capacity()`**

- [x] `NULL` → `1`. `0` → `1`. `-5` → `1`. `'1'` → `1`. `'3'` → `3`. `3` → `3`.
- [x] El docblock explica que la regla es fail-closed y que `NULL` nunca significa ilimitado.

**`myapi_reservation_peak_concurrency()` (unit)**

- [x] Lista de intervalos vacía → `0`.
- [x] Intervalos que **no** solapan el candidato → `0`.
- [x] Back-to-back (una acaba exactamente cuando la otra empieza) → no cuentan como simultáneas.
- [x] **Caso clave:** existentes `10:00-11:00` y `13:00-14:00`, candidato `10:00-14:00` → pico **1**. Un conteo ingenuo de "reservas que solapan" daría 2; este test es el que distingue la implementación correcta de la ingenua.
- [x] El pico ocurre en el **interior** de la ventana, no en su inicio, y se detecta igual.
- [x] Intervalos que cruzan medianoche, proyectados al eje absoluto, dan el mismo pico que sus equivalentes sin cruce.
- [x] Con capacidad 1, `pico + 1 > 1` es equivalente exacto al booleano de solape actual en todos los casos probados.

**`myapi_reservation_occupancy_ranges()` (unit)**

- [x] `$busy` vacío → `[]`.
- [x] Solapes parciales generan tramos de ocupación **distinta**, con los límites en los inicios y finales de las reservas.
- [x] Los tramos con `reserved = 0` no se emiten.
- [x] `remaining` nunca es negativo: con `capacity = 2` y un tramo de `reserved = 3` (admin que bajó la capacidad), `remaining` es `0`.
- [x] La salida viene ordenada ascendente por `(start_date, start_time)`.

**`POST /api/v1/reservations` — no-regresión de capacidad 1**

- [x] Área con capacidad efectiva 1 (sin el campo, o con `1`, `0`, `NULL`): un solape devuelve `409 reservation_overlap` con el **mismo** `error_code` y el **mismo** texto que antes del cambio.
- [x] Los casos de solape que cruzan medianoche de SPEC 41 (existente `20:00→02:00` vs nueva de madrugada, y el simétrico) siguen dando `409 reservation_overlap`.
- [ ] Back-to-back sigue permitido: existente `20:00→02:00`, nueva `02:00→03:00` → `201`.
- [x] Ninguna reserva de un área de capacidad 1 devuelve nunca `area_capacity_full`.

**`POST /api/v1/reservations` — aforo > 1**

- [x] Área con capacidad 3 y 2 reservas confirmadas solapando el candidato → `201`.
- [x] La misma área con 3 reservas confirmadas solapando → `409 area_capacity_full`, no `reservation_overlap`.
- [x] **Caso del gimnasio:** dos viviendas distintas reservan la misma franja en un área de capacidad ≥ 2 → ambas `201`. El aforo no mira de qué vivienda viene cada reserva.
- [x] **Caso clave del pico:** área de capacidad 2 con reservas existentes `10:00-11:00` y `13:00-14:00`, candidato `10:00-14:00` → `201`. Solapa con dos reservas, pero nunca coinciden las dos a la vez.
- [x] `area_capacity_full` viaja como `error_code` estable y `error` traducido según `Accept-Language` (`es`/`en`).
- [x] Las validaciones 1–5, 7 y 8 conservan su orden y sus `error_code`. En particular, una vivienda que ya tiene una reserva activa en el área sigue recibiendo el error de la validación 7 aunque queden plazas libres.
- [x] La validación 6 sigue ejecutándose **dentro** del `db_transaction()`, después del `SELECT ... FOR UPDATE` sobre el nodo del área; el lock no se ha movido ni eliminado.

**Item de área (14 → 15 claves)**

- [x] `GET /api/v1/condominiums/{id}/areas` devuelve items de **15 claves**, con `max_concurrent_reservations` en último lugar, después de `notes`.
- [x] `GET /api/v1/areas/{id}` devuelve el mismo item de 15 claves envuelto como `{"area": ...}`.
- [x] Un área **sin** fila en `field_data_field_max_concurrent_reservations` devuelve `"max_concurrent_reservations": 1`, **nunca** `null`.
- [x] Un área con `0` o un negativo guardado devuelve `1`.
- [x] El valor es siempre un `int` del JSON, nunca una cadena.
- [x] El número de áreas del listado y el bloque `pagination` son idénticos a los de antes del cambio (el `leftJoin` no filtra nada).

**`GET /api/v1/areas/{id}/availability`**

- [x] La respuesta tiene exactamente las 4 claves `date`, `capacity`, `busy`, `occupancy`.
- [x] `capacity` es la capacidad efectiva normalizada, siempre `>= 1`.
- [x] **No-regresión:** en un área de capacidad efectiva 1, `busy` es **byte a byte** lo que devolvía antes del cambio, incluidas dos reservas consecutivas `10:00-11:00` y `11:00-12:00`, que siguen apareciendo como **dos** entradas y no como un bloque `10:00-12:00`.
- [x] En un área de capacidad `> 1`, `busy` contiene **solo** los tramos saturados (`reserved >= capacity`), con tramos saturados contiguos fusionados en un único bloque de 4 claves.
- [x] Un área de capacidad 3 con 2 reservas solapando devuelve `busy: []` y una `occupancy` con `reserved: 2, remaining: 1`.
- [x] `occupancy` está presente también en áreas de capacidad 1.
- [x] Ningún item de `occupancy` contiene `id`, `unit_id`, `requester_id` ni nombres.
- [x] El manejo de sesión de SPEC 42 no cambia: en un área que cierra tras medianoche, la cola de madrugada sigue apareciendo en la sesión de `D` y no en la de `D+1`, ahora también en `occupancy`.
- [x] `data.date` sigue devolviendo el `date` pedido tal cual; sin `date` → `422 missing_field`; formato inválido → `422 invalid_field`; método distinto de `GET` → `405`.
- [x] Acceso no-revelador intacto: área inexistente, oculta o de condominio ajeno → `404 area_not_found`.

**Tests, docs y no-regresión general**

- [x] `vendor/bin/phpunit` pasa entero, incluido `tests/unit/ReservationCapacityTest.php`.
- [x] `tests/unit/ReservationBusyRangesTest.php` pasa **sin haber sido modificado**.
- [x] `tests/unit/ReservationMidnightTest.php` pasa sin cambios.
- [x] `docs/area.md` documenta la clave nueva en los dos endpoints, en las dos tablas, la sección de `availability` con `capacity`/`occupancy`, el desdoble de `busy` y la matriz `curl` ampliada.
- [x] `docs/reservation.md` documenta la validación 6 con su nueva semántica y `area_capacity_full` en la tabla de errores del `POST`.
- [x] `docs/i18n.md` e `includes/myapi.i18n.inc` incluyen `area_capacity_full` en `es`/`en`, y el texto de `reservation_overlap` no ha cambiado.
- [x] `myapi.info` y `hook_menu()` sin cambios en el diff.
- [x] El payload de listado, detalle y cancelación de reservas no cambia en ninguna clave.
- [x] `drush cc all` no reporta errores.

---

## Verificación manual

Comandos tras aplicar los cambios:

```bash
drush updb     # ejecuta myapi_update_7009()
drush cc all   # obligatorio: Field API debe ver la definición nueva
```

Matriz `curl` mínima:

| Caso | Petición | Resultado esperado |
|---|---|---|
| No-regresión, capacidad 1 | `POST /api/v1/reservations` sobre un área sin el campo, solapando una reserva confirmada | `409 reservation_overlap`, texto idéntico al de antes |
| No-regresión, `availability` capacidad 1 | `GET /api/v1/areas/{id}/availability?date=D` con dos reservas consecutivas `10:00-11:00` y `11:00-12:00` | `capacity: 1`, `busy` con **dos** entradas, sin fusionar |
| Aforo con plazas | `POST` sobre un área de capacidad 3 con 2 reservas confirmadas solapando | `201` |
| Aforo saturado | El mismo `POST` con 3 reservas confirmadas solapando | `409 area_capacity_full` |
| Gimnasio del requisito | Dos viviendas distintas hacen `POST` de la misma franja en un área de capacidad ≥ 2 | ambas `201` |
| Pico vs conteo ingenuo | Área de capacidad 2 con `10:00-11:00` y `13:00-14:00`; `POST` de `10:00-14:00` | `201` |
| Ocupación | `GET .../availability?date=D` sobre el área de capacidad 3 con 2 reservas | `capacity: 3`, `busy: []`, `occupancy` con `reserved: 2, remaining: 1` |

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Unidad del aforo | **Reservas** simultáneas | Personas (número de asistentes por reserva) | Contar personas obliga a un campo por reserva, a validarlo y a decidir qué pasa con las reservas ya creadas sin ese dato. El requisito real —"que quepan N reservas a la vez en el gimnasio"— se resuelve contando reservas. |
| Valor por defecto de la capacidad | **Fail-closed**: `NULL`, `0` o negativo → capacidad 1 | `NULL` → ilimitado | Un área a la que se le olvide rellenar el campo debe comportarse **exactamente como hoy**. Interpretar `NULL` como ilimitado convertiría un olvido del admin en la desaparición silenciosa de la validación de solape en producción. |
| Dónde vive la normalización | Una sola función `myapi_reservation_effective_capacity()` en `includes/myapi.reservation_query.inc` | Repetir el ternario en cada llamador | Tres llamadores (validación 6, `build_item`, `availability`) con la misma regla es exactamente el caso que prohíbe la regla 5 de `CLAUDE.md`. |
| Cómo se expone la clave en el item | **Normalizada** (int, siempre ≥ 1) | Cruda con `NULL`, como el resto de campos del item | Desviación deliberada de la convención del recurso. El cliente necesita la capacidad efectiva para pintar plazas libres; duplicar la regla de normalización en Flutter es justo lo que produciría discrepancias con el servidor. Se documenta en el docblock y en `docs/area.md` para que la excepción no se lea como un descuido. |
| Criterio de rechazo | **Pico** de concurrencia dentro de la ventana del candidato | Contar reservas que solapan el candidato | El conteo de solapes es incorrecto: con capacidad 2 y existentes `10:00-11:00` y `13:00-14:00`, un candidato `10:00-14:00` solapa con dos pero **nunca** coinciden las dos a la vez. El pico responde a la pregunta real: "¿en algún instante habría más de N?". |
| Algoritmo del pico | **O(n²)** evaluando los puntos de inicio | Cola de eventos (barrido O(n log n)) | `n` es un puñado de filas por área y día. La versión de puntos de inicio se razona y se testea mucho mejor, y el rendimiento no es el cuello de botella aquí. |
| Puntos a evaluar | `cand_start` más los inicios estrictamente dentro de la ventana | Muestrear la ventana minuto a minuto, o evaluar también los finales | La concurrencia solo **sube** en un inicio, así que el máximo dentro de la ventana ocurre necesariamente en uno de esos puntos. Los finales solo la bajan. |
| Semántica del solape | **Semiabierto** (`s < cand_end && e > cand_start`), back-to-back permitido | Incluir los bordes | Es la regla vigente desde SPEC 35 y conservada en SPEC 41. Cambiarla ahora rompería reservas consecutivas que hoy se aceptan. |
| `error_code` al rechazar | `reservation_overlap` si capacidad 1, `area_capacity_full` si capacidad > 1 | Un único `area_capacity_full` para todos los casos | Las áreas de capacidad 1 son **todas** las que hay hoy en producción. Cambiarles el `error_code` obligaría a un release del cliente Flutter para un caso cuyo comportamiento no ha cambiado. El código nuevo solo aparece en situaciones que antes no existían. |
| `has_overlap()` → `fetch_abs_intervals()` | **Reescribir y renombrar** la función existente | Dejarla intacta y añadir una hermana con la misma query | La función tiene un único caller (la validación 6) y la alternativa duplica ~30 líneas de query contra la regla 5. Mismo criterio que SPEC 41, donde el `POST` también estaba en alcance. |
| Fechas de la query | `field_date IN (D−1, D, D+1)`, **sin cambios** | Ampliar o reducir el rango | Las tres fechas son las que capturan las colas que cruzan medianoche (SPEC 41). El cambio de aforo no altera qué reservas hay que mirar, solo qué se hace con ellas. |
| Ubicación del `SELECT ... FOR UPDATE` | **Se queda donde está**, con la validación 6 dentro de la transacción | Mover el aforo fuera del bloque transaccional | Sin ese lock, dos peticiones concurrentes leerían ambas "quedan plazas" y el aforo sería trivialmente superable. Con capacidad 1 el problema ya existía; con capacidad N es más fácil de provocar, porque hay más peticiones legítimas compitiendo a la vez. Queda escrito en el código. |
| Atajo antes de calcular el pico | Si el número de solapantes es `< capacidad`, aceptar sin calcular | Calcular siempre el pico | El pico nunca puede superar el número de solapantes, así que el atajo es exacto, no una aproximación. Evita el O(n²) en el camino feliz, que es la mayoría de las peticiones. |
| Dónde viven las funciones nuevas | `includes/myapi.reservation_query.inc` | `resources/reservation.resource.inc` | Las necesitan la creación y la disponibilidad, que son dos resources distintos. `area.resource.inc` ya carga ese include a nivel de archivo. |
| `myapi_reservation_abs_interval()` | **No se mueve** de `resources/reservation.resource.inc` | Trasladarla al include compartido | Las funciones nuevas reciben intervalos **ya proyectados**, así que no la necesitan. Moverla arriesga una redeclaración cuando PHPUnit hace `require_once` de los dos archivos en la misma ejecución, a cambio de nada. |
| Forma de `busy` | **Desdoble** por capacidad: capacidad 1 sin tocar, capacidad > 1 solo tramos saturados fusionados | Aplicar el cálculo de bloques a todas las áreas | Con el cálculo uniforme, dos reservas consecutivas (`10:00-11:00` y `11:00-12:00`) en un área de capacidad 1 se fusionarían en un bloque `10:00-12:00`, cambiando el payload de **todas** las áreas en producción. Con el desdoble la regresión es cero y `ReservationBusyRangesTest` sigue pasando sin modificarse. |
| Significado de `busy` | Se conserva: "esto **no** lo puedes reservar" | Redefinirlo como "hay al menos una reserva" | El cliente ya toma decisiones de UI con esa semántica. En un área de capacidad 3 con 1 reserva, esa franja **sí** es reservable, así que no puede estar en `busy`. |
| `occupancy` en áreas de capacidad 1 | **Siempre presente** | Omitirla o devolver `[]` cuando la capacidad es 1 | En capacidad 1 es redundante con `busy` (`reserved: 1, remaining: 0` por reserva), pero una clave siempre presente evita que el cliente tenga que ramificar por capacidad. El coste es un payload algo mayor en áreas que ya paginan. |
| Contenido de `occupancy` | Solo contadores (`reserved`, `remaining`) y las 4 claves de fecha/hora | Incluir `unit_id`, `requester_id` o nombres | La regla de privacidad de SPEC 40 es que el solicitante sabe **que** está ocupado, nunca por quién. El aforo no cambia esa regla; si acaso la hace más tentadora de romper. |
| Ámbito del aforo | Un entero **único** por área | Aforo por franja horaria o por día de la semana | Ninguna necesidad planteada lo pide. Si aparece, va en su propio spec y este campo se convierte en el valor por defecto. |
| Validación 7 | **No se toca** | Relajarla cuando queden plazas | Son reglas ortogonales: la 7 limita a la **vivienda** (una reserva activa por vivienda y área), la 6 limita al **área**. Tocarlas a la vez mezclaría dos cambios de comportamiento en un solo despliegue. |
| Nivel de tests | Unit sobre las dos funciones puras + matriz `curl` | Integración con fixtures de nodos reales | Mismo precedente que SPEC 40, 41 y 42. La lógica de riesgo (pico de concurrencia, partición de tramos) es aritmética pura y se aísla sin BD. |
| Orden de escritura | **Tests antes que lógica** | Implementar y testear después | El caso clave (`10:00-11:00` + `13:00-14:00` vs candidato `10:00-14:00`) es justo el que separa la implementación correcta de la ingenua. Escribirlo primero es la forma de no implementar la ingenua. |
| Edición del campo | Solo desde el admin de Drupal | Endpoint de escritura de áreas | Mismo criterio que SPEC 44 con `field_area_notes`: sin endpoint de escritura no hacen falta permisos, validaciones ni `error_code` nuevos. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Regresión en las áreas de capacidad 1**, que son todas las que hay hoy en producción. Se toca la validación 6, que es camino crítico del `POST`. | La normalización fail-closed garantiza que un área sin el campo llegue a la rama de capacidad 1. Con capacidad 1, `pico + 1 > 1` es equivalente exacto al booleano actual, y hay un test unit dedicado a esa equivalencia. La matriz `curl` incluye los casos de solape que cruzan medianoche de SPEC 41. |
| **Implementar el conteo ingenuo** ("cuántas reservas solapan el candidato") en vez del pico. Es el error natural y produce falsos `409` en el caso más visible del requisito. | El test del caso clave (`10:00-11:00` + `13:00-14:00` vs candidato `10:00-14:00` → pico 1) se escribe **antes** que la lógica y falla con la implementación ingenua. Es el paso 4 del plan. |
| **Carrera entre peticiones concurrentes** superando el aforo. Con capacidad N hay más peticiones legítimas compitiendo por la misma franja que con capacidad 1, así que la ventana se abre más a menudo. | El `db_transaction()` + `SELECT ... FOR UPDATE` sobre el nodo del área ya serializa las creaciones concurrentes desde SPEC 35. Este spec no lo mueve ni lo elimina, y deja un comentario en el código explicando por qué está ahí, para que un refactor futuro no lo saque del bloque. |
| **Admin que baja la capacidad** de un área con reservas ya creadas. `reserved` puede superar a `capacity`. | `remaining = max(0, capacity - reserved)`, nunca negativo, con test unit propio. Las reservas ya creadas **no** se cancelan ni se marcan: se respetan hasta que pasen, y el área simplemente no admite nuevas hasta que la ocupación baje. |
| **Admin que escribe `0`** esperando cerrar el área. | La normalización lo lee como 1, no como "cerrado". Cerrar un área se hace con `field_area_status`, que ya existe (SPEC 33/39). La `description` del campo lo dice explícitamente: "Vacío o menor que 1 se interpreta como 1". |
| **Cliente que valida la respuesta de `availability` con un esquema cerrado** y rompe al recibir `capacity` y `occupancy`. | El cambio es aditivo: `date` y `busy` conservan nombre, forma y, en capacidad 1, contenido. Documentado en `docs/area.md` como paso de 2 a 4 claves. |
| **Cliente que valida el item de área con un esquema cerrado** y rompe al recibir 15 claves. | Mismo argumento que en SPEC 44 al pasar de 13 a 14: añadir una clave es aditivo y ningún endpoint deja de devolver lo de antes. El doc registra el paso de 14 a 15. |
| **El desdoble de `busy` se pierde en un refactor** y alguien unifica el cálculo "por limpieza", cambiando el payload de todas las áreas de capacidad 1. | El motivo queda escrito en tres sitios: el comentario de `myapi_area_availability()`, `docs/area.md` y esta spec. Además hay un criterio de aceptación explícito sobre las dos reservas consecutivas que **no** deben fusionarse. |
| **Renombrar `myapi_reservation_has_overlap()`** deja un caller huérfano y rompe el `POST` con un fatal error. | El plan incluye un `grep -rn has_overlap` como verificación del paso 8. Hoy el único caller es `myapi_reservation_create()`. |
| **Redeclaración de funciones en PHPUnit** si las funciones nuevas necesitaran `myapi_reservation_abs_interval()`, que vive en el resource. | Las funciones nuevas reciben intervalos **ya proyectados** y no llaman a nada de `reservation.resource.inc`. `abs_interval()` no se mueve. |
| **Olvidar `drush cc all` tras `updb`**: Field API no ve la definición nueva, el formulario del nodo no muestra el campo y los `.inc` modificados no se recogen. | Es el paso 15 del plan y un criterio de aceptación. |
| **`occupancy` engorda el payload** del `availability` en áreas con muchas reservas, al emitir un tramo por cada partición del eje. | El número de tramos está acotado por `2n` con `n` reservas de la sesión, y `n` es un puñado por área y día. Si algún día molesta, se resuelve con un parámetro de la query en un spec propio, no recortando la salida en servidor. |
| **DST al calcular `date + 1`** en la partición de tramos. | `field_date` se guarda `tz_handling = none` (SPEC 32) y se opera sobre `Y-m-d` a medianoche local. Los unit tests fijan fechas concretas. Mismo criterio que SPEC 40, 41 y 42. |

---

## Lo que **NO** está en este spec

- **La validación 7.** Una vivienda sigue sin poder tener dos reservas confirmadas activas en la misma área, aunque el aforo permita más reservas simultáneas.
- **Aforo por personas.** El aforo cuenta reservas; no hay número de asistentes.
- **Endpoints de escritura de áreas.** El campo se edita solo desde el admin de Drupal.
- **Aforo variable por franja horaria o por día de la semana.**
- **Cambios en las validaciones 1–5 y 8, en su orden o en sus `error_code`.**
- **Exposición de identidad en `occupancy`** (`id`, `unit_id`, `requester_id`, nombres).
- **Soporte de áreas con ventanas de más de 24 h o doble cruce de medianoche.**
- **Filtros u orden de áreas por capacidad.**
- **Tests de integración/HTTP automatizados con fixtures.**

Cada uno de ellos, si algún día entra, va en su propio spec.
