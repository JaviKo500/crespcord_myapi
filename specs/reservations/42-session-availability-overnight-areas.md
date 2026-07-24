# SPEC 42 — Disponibilidad por sesión en áreas que cierran tras medianoche (`GET /api/v1/areas/{id}/availability`)

> **Estado:** Implemented · **Depende de:** SPEC 32 (content types de reservas/áreas), SPEC 40 (endpoint de disponibilidad y sus helpers `busy_rows`/`busy_ranges`), SPEC 41 (creación de reservas nocturnas: normalización de `field_date` al día de reloj y almacenamiento de `end_time` envuelto) · **Fecha:** 2026-07-24
> **Objetivo:** Hacer que `GET /api/v1/areas/{id}/availability?date=D` reporte la **sesión** operativa del área para el día `D` —la ventana `[D open, D+1 close]`— en lugar del día natural, para que en un área que cierra tras medianoche la cola de madrugada (que SPEC 41 guarda bajo su propio día de reloj `D+1`) aparezca en la sesión a la que pertenece y **no** se filtre a la sesión siguiente.

---

## Contexto y por qué supersede parte de SPEC 40

SPEC 40 modeló la disponibilidad **por día natural** y reunía una reserva que cruza medianoche solo por el mecanismo de **cruce** (`end_time <= start_time`), trayendo `date` y `date−1`. Ese modelo era correcto mientras las únicas reservas nocturnas eran las que cruzan (p. ej. `23:00→01:00`, guardadas en su día de inicio).

SPEC 41 hizo reservables los slots de **madrugada** (`00:00`, `01:00`) y los guarda —por la convención de día de reloj— bajo `field_date = D+1` como reservas que **no cruzan** (`00:00→02:00`). El derivador de SPEC 40 no tiene forma de saber que esa reserva es la cola de la sesión de `D`: la muestra bajo la sesión de `D+1` (donde no pertenece) y la omite en la de `D` (donde sí). Este spec corrige esa incoherencia haciendo el derivador **consciente de la sesión** (y por tanto del horario del área).

**Supersede de SPEC 40:**

- El criterio de aceptación de SPEC 40 línea 126 ("la misma reserva cruzada, consultada desde el día siguiente, aparece con `start_date = date − 1`") queda **reemplazado**: una reserva de la sesión `D−1` ya no aparece en la sesión `D`, porque termina antes de que la sesión `D` abra. Cada reserva pertenece a **exactamente una** sesión.
- La firma de `myapi_reservation_busy_ranges()` y el conjunto de fechas que trae el endpoint cambian (ver Modelo de datos).

El resto de SPEC 40 (acceso no-revelador `404`, validación de `date`, exclusión de `cancelled`, ausencia de `id`/`unit_id`/nombres, orden por `(start_date, start_time)`, `405`, i18n, `has_overlap` intacto) se conserva sin cambios.

---

## Alcance

**Dentro:**

- **`includes/myapi.reservation_query.inc`** (modificar):
  - `myapi_reservation_busy_ranges()` — nueva firma `($rows, $date, $next_date, $open_min, $close_min)` y lógica **consciente de la sesión**: partición de las filas por horario del área en lugar del filtro `prev_date`/cruce. Sigue siendo **función pura** (sin BD, sin Drupal), unit-testable.
  - `myapi_reservation_busy_rows()` — **sin cambios** (ya acepta una lista de fechas arbitraria).
- **`resources/area.resource.inc`** (modificar) — `myapi_area_availability()`: parsear `field_open_time`/`field_close_time` del área (ya vienen en la proyección de `myapi_area_fetch_one()`), calcular `wraps`, traer `[date]` (área normal) o `[date, date+1]` (área que envuelve), y pasar `open`/`close` al derivador.
- **`tests/unit/ReservationBusyRangesTest.php`** — reescribir a la semántica de sesión (área normal, tarde/noche de área que envuelve, cola de madrugada incluida en su sesión y excluida de la siguiente, tarde de la sesión siguiente excluida, horas nulas → día natural).
- **`docs/area.md`** — reescribir la sección de manejo de medianoche a "disponibilidad por sesión"; actualizar ejemplo y matriz `curl`.
- **Nota en `specs/reservations/40-area-availability.md`** marcando el criterio 126 como superseded por este spec.

**Fuera de alcance (para futuros specs):**

- **Creación, cancelación, listado y detalle de reservas** — este spec solo toca la lectura de disponibilidad. La normalización de creación es SPEC 41.
- **Áreas con ventanas de más de 24 h** (doble cruce de medianoche) — se asume `close ≤ open + 24h`; una sesión abarca `[D open, D+1 close]` y a lo sumo un cruce. Igual supuesto que SPEC 41.
- **Cambiar el contrato del request o del cliente Flutter** — el request sigue siendo `?date=YYYY-MM-DD`; `data.date` sigue devolviendo el día pedido tal cual.
- **Paginación, filtros extra, o exponer identidad** — igual que SPEC 40.

---

## Modelo de datos

**No hay datos persistentes nuevos.** Se lee la misma fuente que SPEC 40 (`myapi_reservation_busy_rows()`), cambiando qué fechas se traen y cómo se derivan los rangos.

### Definición de sesión

La disponibilidad de `date = D` reporta la **sesión** del área para `D`, es decir la ventana `[D open_time, D+1 close_time]`:

- **Área normal** (`close_min > open_min`, p. ej. `08:00–22:00`): la sesión es el día natural `D`. Solo las reservas con `field_date = D` pertenecen; ninguna cruza medianoche (la creación lo rechaza con `reservation_crosses_midnight`, SPEC 41).
- **Área que envuelve** (`close_min <= open_min`, p. ej. `12:00–02:00`): la sesión abarca dos días naturales y se arma de dos porciones almacenadas, porque SPEC 41 guarda cada reserva bajo el **día de reloj** de su inicio:

```
sesión(D) = { field_date = D   con start_min >= open_min }   // tarde / noche
          ∪ { field_date = D+1 con start_min <  close_min }  // cola de madrugada
```

Y se **excluyen** (pertenecen a otra sesión):

```
field_date = D   con start_min <  open_min   → cola de la sesión D−1
field_date = D+1 con start_min >= close_min  → tarde de la sesión D+1
```

El hueco muerto `[close, open)` no tiene reservas (la creación las rechaza como fuera de horario), así que `start >= open` y `start < close` particionan sin ambigüedad las filas de cada día.

### Derivación pura (`myapi_reservation_busy_ranges($rows, $date, $next_date, $open_min, $close_min)`)

```
wraps = (open_min !== NULL && close_min !== NULL && close_min <= open_min)

para cada fila:
  si !wraps:                          conservar solo si row.date == date
  si wraps && row.date == date:       descartar si start_min <  open_min   (cola de la sesión previa)
  si wraps && row.date == next_date:  descartar si start_min >= close_min  (tarde de la sesión siguiente)
  otra fecha:                         descartar

  crosses  = end_min <= start_min
  start_date = row.date
  end_date   = crosses ? row.date + 1 día : row.date
```

- `open_min`/`close_min` pueden ser `NULL` (área sin fila de horario) → `wraps = false` → degrada a día natural (comportamiento de SPEC 40 para áreas normales).
- Orden final ascendente por `(start_date, start_time)`: la tarde de la sesión precede a su cola de madrugada.
- La cola de madrugada nunca cruza (`00:00→02:00`, `end > start`), así que reporta `start_date == end_date == D+1` (su hora de reloj real).

### Fechas que trae el endpoint

```
next_date  = date + 1 día
fetch = wraps ? [date, next_date] : [date]
```

No se trae `date − 1`: una reserva de la sesión `D−1` termina a más tardar en `D close` (antes de `D open`), fuera de la ventana de la sesión `D`.

---

## Plan de implementación

1. **`myapi_reservation_busy_ranges()`** en `includes/myapi.reservation_query.inc` — nueva firma `($rows, $date, $next_date, $open_min = NULL, $close_min = NULL)` y la partición por sesión descrita arriba; conserva el cálculo de `crosses`/`end_date` y el orden por `(start_date, start_time)`. *Verificación: `php -l`; unit tests.*
2. **`myapi_area_availability()`** en `resources/area.resource.inc` — parsear `open`/`close` con `myapi_reservation_time_to_minutes()` (o `NULL` si falta), calcular `wraps`, `next_date`, `fetch = wraps ? [date, next] : [date]`, y llamar al derivador con `open`/`close`. *Verificación: `php -l`.*
3. **Unit tests** — reescribir `tests/unit/ReservationBusyRangesTest.php` a la nueva firma y semántica de sesión. *Verificación: `scripts/run-unit-tests.sh`.*
4. **Doc** — reescribir la sección de disponibilidad de `docs/area.md` (por sesión, ejemplo, matriz `curl`). *Verificación: casa con el contrato.*
5. **SPEC 40** — nota "superseded por SPEC 42" junto al criterio 126 y a la descripción del derivador.
6. **`drush cc all`** (opcional; no cambia `hook_menu()` ni el esquema, solo la lógica del `.inc`).

---

## Criterios de aceptación

**Área que envuelve (`12:00–02:00`), reserva de madrugada `00:00→02:00` guardada bajo `field_date = D+1`**
- [x] `availability?date=D` incluye esa cola con `start_date = end_date = D+1`, `00:00→02:00` (aparece en la sesión a la que pertenece).
- [x] `availability?date=D+1` **no** la muestra (esa cola es de la sesión `D`, no de la `D+1` que abre a las `12:00`).

**Área que envuelve, parte tarde/noche**
- [x] Reserva `20:00→22:00` (`field_date = D`, no cruza) → `start_date = end_date = D`.
- [x] Reserva `23:00→01:00` (`field_date = D`, cruza) → `start_date = D`, `end_date = D+1`.
- [x] Reserva `20:00→22:00` con `field_date = D+1` (tarde de la sesión siguiente) → **excluida** de `availability?date=D`.
- [x] La sesión completa de `D` viene ordenada por `(start_date, start_time)`: tarde/noche antes que la cola de madrugada.

**Área normal (`08:00–22:00`) — no regresión**
- [x] Reserva `10:00→12:00` (`field_date = D`) → aparece con `start_date = end_date = D`.
- [x] Una reserva con `field_date = D+1` no aparece en `availability?date=D`.
- [x] Área sin fila `open`/`close` → se comporta como día natural (solo filas de `date`).

**Contrato general (heredado de SPEC 40, sin cambios)**
- [x] `data.date` devuelve el `date` pedido tal cual; sin `date` → `422 missing_field`; formato/fecha inválida → `422 invalid_field`.
- [x] Acceso no-revelador: área inexistente/oculta/de condominio ajeno → `404 area_not_found`.
- [x] `cancelled` no aparece; los items llevan solo las 4 claves de fecha/hora.
- [x] Cualquier método distinto de `GET` → `405 method_not_allowed`.
- [x] `myapi_reservation_has_overlap()` y `POST /api/v1/reservations` quedan sin cambios.
- [x] Los unit tests de `myapi_reservation_busy_ranges()` pasan (`scripts/run-unit-tests.sh`).
- [x] `docs/area.md` documenta la disponibilidad por sesión y casa con lo implementado.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Unidad de reporte | **Sesión** `[D open, D+1 close]` | Día natural (SPEC 40) | El día natural parte la sesión de un área que envuelve en dos consultas y ubica mal la cola de madrugada; la sesión es la unidad que el usuario reserva. |
| Reescribir `busy_ranges` vs función nueva | **Reescribir** la existente (la usa solo el endpoint de disponibilidad) | Crear un derivador paralelo y dejar el viejo | Una segunda función duplicaría lógica contra la regla de no-duplicación; el único caller es este endpoint. |
| Cómo distinguir cola vs tarde | Pasar `open`/`close` al derivador y particionar por `start_min` | Inferirlo solo por `end <= start` (cruce) | La cola de madrugada **no** cruza (`00:00→02:00`), así que el cruce no la identifica; hace falta el horario del área. |
| Fechas a traer | `[D, D+1]` si envuelve, `[D]` si no | Mantener `[D, D−1]` de SPEC 40 | La sesión `D` se compone de `D` (tarde) y `D+1` (cola); `D−1` queda fuera de la ventana `[D open, D+1 close]`. |
| Horas nulas | Degradar a día natural (`wraps = false`) | Fallar o asumir cruce | Un área sin horario no puede envolver; conserva el comportamiento previo sin romper. |
| Dónde documentar | **Spec nuevo (42)** que supersede parte de SPEC 40 | Editar SPEC 40 (Implemented) | Convención del repo: cada cambio en su propio spec; no se reescribe un spec ya entregado. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Regresión en áreas normales** al reescribir el derivador. | La rama `!wraps` conserva exactamente el comportamiento de día natural (solo filas de `date`, sin cruce); cubierta por unit tests de no-regresión. Las áreas normales no tienen reservas que crucen (la creación las rechaza), así que quitar el fetch de `D−1` no pierde nada. |
| **Doble cruce / reservas > 24 h**: una sesión que abarcara dos medianoches no se cubriría con `[D, D+1]`. | Declarado fuera de alcance; se asume `close ≤ open + 24h`. Mismo supuesto que SPEC 41. |
| **DST al calcular `date + 1`** con `strtotime`. | `field_date` se guarda `tz_handling = none` (SPEC 32); se opera sobre `Y-m-d` a medianoche local. Los unit tests fijan fechas concretas. Mismo criterio que SPEC 40/41. |
| **Área con `open`/`close` mal cargados** (p. ej. solo uno). | El derivador trata `NULL` en cualquiera de los dos como no-envuelve y degrada a día natural; no revienta ni inventa una sesión. |
| **Cliente que aún asume el modelo de día natural de SPEC 40.** | El contrato de request y las 4 claves de `busy[]` no cambian; solo cambia **qué** reservas entran en cada día. Documentado en `docs/area.md`. |

---

## Lo que **NO** está en este spec

- Cambios en creación, cancelación, listado o detalle de reservas (la normalización de `field_date`/`end_time` es SPEC 41).
- Soporte de áreas con ventanas de más de 24 h o doble cruce de medianoche.
- Cambios al contrato del request (`?date=YYYY-MM-DD`) o al cliente Flutter.
- Paginación, filtros extra o exposición de identidad en `busy[]`.
- Tests de integración/HTTP automatizados (solo unit sobre la derivación pura + matriz `curl` en la doc).

Cada uno, si aterriza, va en su propio spec.
