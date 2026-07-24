# SPEC 41 — Reservas que cruzan la medianoche en áreas con cierre después de medianoche (`POST /api/v1/reservations`)

> **Estado:** Implemented · **Depende de:** SPEC 32 (content types de reservas/áreas), SPEC 35 (`POST /api/v1/reservations` y sus 8 validaciones), SPEC 40 (disponibilidad: convención `field_date`=día de reloj + derivación de cruce) · **Fecha:** 2026-07-24
> **Objetivo:** Hacer que `POST /api/v1/reservations` acepte reservas en áreas cuyo `field_close_time` es anterior al `field_open_time` (cierre tras medianoche), normalizando `field_date` al día de reloj del inicio y proyectando las validaciones 3, 4 y 6 sobre la ventana extendida `[open, close+24h]`, sin alterar el comportamiento de las áreas normales ni el contrato del cliente.

---

## Alcance

**Dentro:**

- **`resources/reservation.resource.inc`** (modificar):
  - **`myapi_reservation_create()`** — insertar, tras la validación 0d (área cargada), la **normalización de `field_date`**: si el área envuelve (`close_minutes <= open_minutes`) y `start_minutes < close_minutes` (el inicio cae en la cola de madrugada `[00:00, close)`), el `date` efectivo con el que se opera y se guarda pasa a ser `date + 1 día`. El cliente sigue mandando `date = D`.
  - **Validación 3** (no-pasado) — construir el timestamp de inicio sobre el `date` ya normalizado.
  - **Validación 4** (horario) — reemplazar la comparación lineal por una proyección sobre la ventana extendida cuando el área envuelve; añadir el corte previo `reservation_crosses_midnight` cuando el rango cruza medianoche en un área que **no** envuelve.
  - **Validación 6** (solapamiento) — comparar sobre un eje absoluto (fecha+hora), trayendo también las reservas de `date−1` y `date+1` que puedan pisar la ventana.
  - **Helpers puros nuevos** en el mismo archivo (sin BD, unit-testables): detección de área que envuelve, normalización de fecha, proyección de la ventana de horario, e intervalo absoluto para el solape.
  - **`myapi_reservation_has_overlap()`** — reescribir su comparación al eje absoluto y ampliar su fetch a las tres fechas.
- **`includes/myapi.i18n.inc` / `docs/i18n.md`** — nueva clave `reservation_crosses_midnight` (422) en `es`/`en`.
- **`docs/reservation.md`** — reescribir el párrafo que afirma que ningún `field_close_time` pasa de medianoche; documentar la convención de día (normalización), la ventana extendida, el nuevo `error_code` y su fila en la tabla de errores.
- **`tests/unit/`** — tests PHPUnit sobre los helpers puros (normalización, ventana extendida, solape en eje absoluto, no-regresión de áreas normales) + matriz `curl` documentada.

**Fuera de alcance (para futuros specs):**

- **Cambiar el cliente Flutter** — la decisión es que el servidor normaliza; el contrato del request (`date` = día visto) no cambia.
- **`GET /api/v1/areas/%/availability` (SPEC 40)** — ya maneja el cruce correctamente; no se toca.
- **Cancelación, listado y detalle de reservas** — la normalización solo aplica en creación; el listado/detalle leen `field_date` tal cual se guardó.
- **Validación 7 (una reserva activa) y validación 8 (saldo)** — operan sobre el `field_date` ya normalizado sin lógica especial de medianoche; no cambian.
- **Áreas con ventanas de más de 24 h** (`max_minutes > 1440` cruzando dos medianoches) — fuera; se asume que ninguna reserva abarca más de un cruce de medianoche.
- **Reservas donde el inicio cae en el hueco muerto** entre `close` y `open` (p. ej. `05:00` en un área `12:00–02:00`) — no se normalizan; caen en `reservation_outside_hours` como hoy.

---

## Modelo de datos

**No hay datos persistentes nuevos.** No se crean tablas ni campos ni content types. El nodo `reservation` y los campos del área (`field_open_time`, `field_close_time`, `field_date`, `field_start_time`, `field_end_time`) ya existen (SPEC 32). Este spec define la **convención de día** y la **aritmética de proyección** que aplican las validaciones antes de escribir el nodo.

### Convención de día (normalización de `field_date`)

`field_date` guarda **siempre el día de reloj del `start_time`** (misma convención que ya usa SPEC 40 al mostrar el cruce). El cliente manda `date = D` (el día que ve en el calendario) sin cambios.

```
wraps          = close_minutes <= open_minutes          // el área cierra tras medianoche
in_tail        = wraps && start_minutes < close_minutes // el inicio cae en [00:00, close)
effective_date = in_tail ? (D + 1 día) : D
```

- Área `12:00–02:00`, `start=01:00` → `wraps=true`, `01:00 < 02:00` → `effective_date = D+1`. Se guarda `field_date = D+1`, `start_time=01:00`, `end_time=02:00`.
- Área `12:00–02:00`, `start=20:00` → `wraps=true`, `20:00 < 02:00` es falso → `effective_date = D`. Se guarda `field_date = D`, `end_time=02:00` (cruce derivado por `end <= start`). *(Igual que hoy graba SPEC 40.)*
- Área normal `08:00–22:00` → `wraps=false` → **nunca** normaliza. `effective_date = D` siempre.

`end_time` se sigue calculando con `myapi_reservation_add_minutes()` (sin wrap a 24:00); su cruce se deriva luego por comparación, no por el string.

### Ventana de horario extendida (validación 4)

Se compara sobre un eje de minutos donde la cola de madrugada se proyecta con `+1440`:

```
// Área que NO envuelve (close > open): igual que hoy.
valido = start_min >= open_min && end_abs <= close_min

// Área que envuelve (close <= open): ventana [open_min, close_min + 1440].
start_eff = (start_min < close_min) ? start_min + 1440 : start_min
end_eff   = start_eff + (end_min - start_min)   // end proyectado sobre el mismo marco que start_eff (dur = end_min - start_min)
valido    = start_eff >= open_min && end_eff <= (close_min + 1440)
```

> Nota: `end_eff` se ancla en `start_eff` (no en `end_min` suelto) para que un inicio en la cola de madrugada cuya duración cruza el cierre —p. ej. `01:00` + `120` → `03:00` en un área `12:00–02:00`— caiga fuera de horario. Con `end_eff = end_min + (end_min <= start_min ? 1440 : 0)` ese caso se aceptaría por error, porque para el candidato `end_min = start_min + duration` nunca es `<= start_min`.

- Corte previo `reservation_crosses_midnight`: el rango calculado **cruza medianoche** (`end_min <= start_min`, o `duration` empuja `end` a `>= 24:00`) **y el área no envuelve** → `422 reservation_crosses_midnight`, antes de evaluar la ventana.

### Eje absoluto para el solapamiento (validación 6)

Cada reserva (nueva y existentes) se convierte a un intervalo absoluto en minutos, anclado en la medianoche de su `field_date`:

```
start_abs = base(field_date) + start_min
end_abs   = base(field_date) + end_min + (end_min <= start_min ? 1440 : 0)   // cruce derivado
```

Solape (semiabierto, back-to-back permitido): `new_start_abs < existing_end_abs && new_end_abs > existing_start_abs`.

El fetch de reservas existentes se amplía de una a **tres fechas**: `field_date IN (D_eff − 1, D_eff, D_eff + 1)`, para capturar tanto la cola de una reserva del día anterior que termina tras medianoche como una reserva de madrugada del día siguiente contra la que la nueva (si cruza) podría chocar. El volumen por área/día sigue siendo bajo; la comparación de intervalos se hace en PHP.

### Helpers puros nuevos (unit-testables, sin BD)

| Helper | Firma | Devuelve |
|---|---|---|
| Área envuelve | `myapi_reservation_area_wraps($open_min, $close_min)` | `bool` (`close_min <= open_min`) |
| Fecha efectiva | `myapi_reservation_effective_date($date, $start_min, $open_min, $close_min)` | `string 'Y-m-d'` (D o D+1) |
| Intervalo absoluto | `myapi_reservation_abs_interval($date, $start_min, $end_min)` | `[start_abs, end_abs]` (int, int) |
| Ventana OK | `myapi_reservation_within_hours($start_min, $end_min, $open_min, $close_min)` | `bool` |
| Cruza y no permitido | `myapi_reservation_crosses_disallowed($start_min, $end_min, $open_min, $close_min)` | `bool` |

Estos helpers **no** llaman a Drupal ni a la BD (solo aritmética de minutos y `date()`/`strtotime()` sobre strings), de modo que los tests unit los cubren igual que `myapi_reservation_busy_ranges()` en SPEC 40.

---

## Plan de implementación

1. **Helpers puros nuevos** en `resources/reservation.resource.inc` (justo al lado de `myapi_reservation_parse_time()`):
   - `myapi_reservation_area_wraps($open_min, $close_min)` → `$close_min <= $open_min`.
   - `myapi_reservation_effective_date($date, $start_min, $open_min, $close_min)` → si envuelve y `$start_min < $close_min`, devuelve `date('Y-m-d', strtotime($date . ' +1 day'))`; si no, `$date`.
   - `myapi_reservation_abs_interval($date, $start_min, $end_min)` → ancla en la medianoche de `field_date` con `intdiv(strtotime($date . ' 00:00:00'), 60)` y devuelve `[$base + $start_min, $base + $end_min + ($end_min <= $start_min ? 1440 : 0)]`.
   - `myapi_reservation_within_hours($start_min, $end_min, $open_min, $close_min)` → rama envuelve / no-envuelve según el modelo.
   - `myapi_reservation_crosses_disallowed($start_min, $end_min, $open_min, $close_min)` → `!wraps && (end cruza medianoche)`.
   *Verificación: `php -l`.*

2. **Normalización en `myapi_reservation_create()`** — tras la validación 0d (área cargada), leer `field_open_time`/`field_close_time`, parsear a minutos, y calcular `$date = myapi_reservation_effective_date($date, $start_minutes, $open_min, $close_min)` **antes** de la validación 3. A partir de aquí todo el flujo (validaciones 3–8 y `build_node`) usa el `$date` normalizado. *Verificación: `php -l`; el área normal deja `$date` intacto.*

3. **Validación 3 (no-pasado)** — sin cambios de código salvo que ya opera sobre el `$date` normalizado: `strtotime($date . ' ' . $start_time . ':00')`. El slot `01:00` normalizado a D+1 deja de evaluarse como pasado. *Verificación: unit sobre `effective_date` + prueba `curl`.*

4. **Validación 4 (horario) + corte `reservation_crosses_midnight`** — reemplazar el bloque lineal `open/close` por:
   1. Si `myapi_reservation_crosses_disallowed(...)` → `myapi_error('reservation_crosses_midnight', 422)`.
   2. Si `$open_time === NULL || $close_time === NULL || !myapi_reservation_within_hours(...)` → `myapi_error('reservation_outside_hours', 422)`.
   *Verificación: unit sobre `within_hours`/`crosses_disallowed`; áreas normales conservan su rechazo actual.*

5. **Validación 6 (solapamiento)** — reescribir `myapi_reservation_has_overlap()`:
   - Cambiar el `WHERE SUBSTR(fd.field_date_value,1,10) = :date` por `IN (:prev, :date, :next)` con `prev = date−1`, `next = date+1` (calculadas con `strtotime`).
   - Reemplazar la comparación de minutos por el eje absoluto: para la reserva nueva y cada existente, `myapi_reservation_abs_interval($row_date, $start_min, $end_min)`, y aplicar `new_start_abs < existing_end_abs && new_end_abs > existing_start_abs`.
   - La firma pasa a recibir el `$date` normalizado y los minutos de inicio/fin (ya disponibles en el caller).
   *Verificación: unit sobre `abs_interval` + solape; prueba `curl` de solape cruzado.*

6. **Catálogo i18n** — agregar `reservation_crosses_midnight` en `es`/`en` de `includes/myapi.i18n.inc` y en `docs/i18n.md`:
   - `es`: "Esta reserva cruzaría la medianoche y el área cierra el mismo día."
   - `en`: "This reservation would cross midnight but the area closes the same day."
   *Verificación: `php -l`; `myapi_t('reservation_crosses_midnight')` resuelve en ambos idiomas.*

7. **Documentar en `docs/reservation.md`** — reescribir el párrafo del `end_time`/medianoche; agregar la convención de día (normalización D→D+1), la ventana extendida, la fila `422 reservation_crosses_midnight` en la tabla de errores, y la nota de que las áreas normales no cambian. Incluir la **matriz `curl`** de los casos de aceptación.
   *Verificación: la doc casa con el contrato implementado.*

8. **Unit tests** en `tests/unit/` (p. ej. `ReservationMidnightTest.php`) sobre los 5 helpers puros: normalización D+1 vs D, hueco muerto sin normalizar, ventana extendida (`20:00`+6h OK, `20:00`+8h fuera), `crosses_disallowed` en área normal, solape en eje absoluto (cruzada vs madrugada siguiente, y back-to-back que **no** solapa), y no-regresión de `08:00–22:00`. *Verificación: `scripts/run-unit-tests.sh` pasa.*

9. **`drush cc all`** y ejecución manual de la matriz `curl` documentada. *(No se toca `hook_menu()` ni `myapi.install`: la ruta y el esquema ya existen.)*

---

## Criterios de aceptación

**Normalización de día (área que envuelve, `12:00–02:00`)**
- [x] `POST` con `date=D`, `start_time=01:00`, `duration=60` (elegido a las 20:00 de D) → `201`; el nodo se guarda con `field_date = D+1`, `start_time=01:00`, `end_time=02:00`.
- [x] Esa reserva aparece luego en `GET /api/v1/areas/{id}/availability?date=D+1` (SPEC 40) con `start_date = end_date = D+1`, sin que SPEC 40 se haya modificado.
- [x] `POST` con `date=D`, `start_time=20:00`, `duration=360` (→ `02:00`) → `201`; se guarda `field_date = D` (sin normalizar), `end_time=02:00`.
- [x] El cliente **no** cambia lo que manda: el request sigue llevando `date = D` en ambos casos.

**Ventana extendida (validación 4)**
- [x] Área `12:00–02:00`: `start=20:00`, `duration=360` (→ `02:00`) queda **dentro** de horario → no falla la validación 4.
- [x] Área `12:00–02:00`: `start=20:00`, `duration=480` (→ `04:00`, pasa el cierre proyectado `26:00`) → `422 reservation_outside_hours`.
- [x] Área `12:00–02:00`: `start=05:00` (hueco muerto entre `02:00` y `12:00`) → no se normaliza y → `422 reservation_outside_hours`.

**Corte `reservation_crosses_midnight` (área normal)**
- [x] Área `08:00–22:00`: `start=21:00`, `duration=240` (→ `01:00`, cruza medianoche) → `422 reservation_crosses_midnight`, **no** `reservation_outside_hours` ni `invalid_field`.
- [x] El `error_code` viaja como `reservation_crosses_midnight`; `error` traducido según `Accept-Language` (`es`/`en`).

**Solapamiento en eje absoluto (validación 6)**
- [x] Con una reserva existente `20:00→02:00` (`field_date=D`), un `POST` de madrugada `date=D+1`/`start=01:00`/`duration=60` (que se normaliza a `field_date=D+1`) → `409 reservation_overlap` (la cola de la existente pisa `D+1 01:00–02:00`).
- [x] El caso simétrico: existiendo la reserva de madrugada `field_date=D+1 01:00–02:00`, un `POST` `20:00→02:00` sobre `date=D` también detecta el solape.
- [x] Back-to-back sigue permitido: existente `20:00→02:00`, nueva `02:00→03:00` (misma área que envuelve) → **no** solapa → `201`.

**No-regresión de áreas normales (`08:00–22:00`)**
- [x] `start=10:00`, `duration=120` → `201`, `field_date=D` sin normalizar, mismo comportamiento que hoy.
- [x] `start=07:00` (antes de apertura) o `start=21:30`+`60` (→ `22:30`, pasa cierre sin cruzar medianoche) → `422 reservation_outside_hours`, igual que hoy.
- [x] El solape en un área normal (misma fecha, sin cruce) da el mismo resultado que antes del cambio.

**Tests y no-regresión general**
- [x] Los unit tests de los 5 helpers puros pasan (`scripts/run-unit-tests.sh`).
- [x] `docs/reservation.md` ya no afirma que ningún `field_close_time` pasa de medianoche; documenta normalización, ventana extendida y `reservation_crosses_midnight`.
- [x] `docs/i18n.md` e `includes/myapi.i18n.inc` incluyen `reservation_crosses_midnight` en `es`/`en`.
- [x] `GET /api/v1/areas/%/availability`, listado, detalle y cancelación de reservas quedan sin cambios de código.
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Quién calcula el día de reloj | **Servidor normaliza** `field_date` a D+1 cuando el inicio cae en la cola de madrugada | Cliente manda D+1 para slots de madrugada | Evita coordinar un release del cliente Flutter; el request (`date` = día visto) no cambia. El servidor ya tiene `open`/`close` para decidir. |
| Convención de `field_date` | Día de **reloj** del inicio (normalizado) | Guardar `date=D` (día de sesión) y solo proyectar al validar | La opción de "día de sesión" rompe la consistencia con SPEC 40, que ya guarda y muestra el cruce por día de reloj; un slot `01:00` quedaría mal ubicado en disponibilidad. |
| Disparo de la normalización | `wraps && start_min < close_min` (inicio en `[00:00, close)`) | `wraps && start_min < open_min` (inicio antes de apertura) | La segunda normalizaría también el hueco muerto (`05:00` en `12:00–02:00`); acotar a `< close` deja ese caso en `reservation_outside_hours`, que es lo correcto. |
| Comparación de solape | **Eje absoluto** (fecha+hora en minutos) trayendo `D−1, D, D+1` | Mantener la comparación de minutos por fecha única | El eje absoluto maneja de forma uniforme mismo-día, cruce y madrugada; el fetch de 3 fechas captura las colas que hoy la consulta de fecha única ignora. |
| Rango que cruza en área que no envuelve | `error_code` propio `reservation_crosses_midnight` (422) | Reusar `reservation_outside_hours` | El cliente necesita distinguir "cruza medianoche y el área cierra el mismo día" de un simple fuera-de-horario para dar un mensaje útil; hoy ni siquiera lo distingue de `invalid_field`. |
| Semántica del solape | Semiabierto (`new_start < existing_end && new_end > existing_start`), back-to-back permitido | Incluir bordes como solape | Se conserva la regla ya vigente de SPEC 35; solo cambia el eje, no la semántica. |
| Reescritura de `myapi_reservation_has_overlap()` vs función nueva | Reescribir la existente (la usa solo el `POST`) | Crear una función paralela y dejar la vieja | A diferencia de SPEC 40 (que evitó tocarla por estar el `POST` fuera de alcance), aquí **el `POST` es el alcance**; una segunda función duplicaría lógica contra la regla de no-duplicación. |
| Ventanas > 24 h y doble cruce | Fuera de alcance (se asume ≤ un cruce) | Soportar `max_minutes > 1440` | Ningún área real declarada excede 24 h; el fetch `D±1` cubre un solo cruce. Si aparece, va en otro spec. |
| Nivel de tests | Unit sobre helpers puros + matriz `curl` | Integración con fixtures de nodos reales | Mismo precedente que SPEC 40; el proyecto no tiene runner automatizado y la lógica de riesgo (proyección/normalización) es aritmética pura, aislable sin BD. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Tocar `myapi_reservation_has_overlap()` rompe el camino feliz del `POST`.** Es una función crítica ya en producción. | Se cubre con unit tests sobre los helpers puros de intervalo/solape y con la matriz `curl` de no-regresión de áreas normales; la semántica semiabierta se conserva idéntica, solo cambia el eje y el rango de fechas. |
| **DST al calcular `date+1` / `date−1` con `strtotime`.** Un salto de hora podría desplazar la fecha. | `field_date` se guarda `tz_handling = none` (SPEC 32) y se opera sobre `Y-m-d` a medianoche local; `strtotime('... +1 day')` sobre fechas sin hora no cruza saltos DST relevantes. Los unit tests fijan fechas concretas. Mismo criterio que SPEC 40. |
| **Doble cruce / reservas > 24 h.** Una reserva que abarcara dos medianoches no se compararía contra todas las fechas afectadas (el fetch es `D±1`). | Declarado fuera de alcance; se asume `duration ≤ 24 h`. `field_max_minutes` de las áreas reales lo garantiza en la práctica. |
| **Condición de carrera en el solape** (dos `POST` casi simultáneos). | Sin cambios respecto a SPEC 35: el `db_transaction()` + `SELECT ... FOR UPDATE` sobre el nodo del área ya serializa las creaciones concurrentes; este spec solo cambia la lógica dentro de esa sección crítica. |
| **Normalización silenciosa confunde al cliente** al ver que `field_date` devuelto ≠ `date` enviado. | La respuesta ya devuelve `date`/`start_time`/`end_time` reales del nodo; se documenta explícitamente en `docs/reservation.md` que en áreas que cierran tras medianoche el `date` de un slot de madrugada se normaliza a D+1. |

## Lo que **NO** está en este spec

- Cambiar el cliente Flutter o el contrato del request (`date` = día visto se mantiene).
- Modificar `GET /api/v1/areas/%/availability` (SPEC 40), el listado, el detalle o la cancelación de reservas.
- Soporte de áreas con ventanas de más de 24 h o con doble cruce de medianoche.
- Normalizar reservas cuyo inicio cae en el hueco muerto entre `close` y `open` (siguen dando `reservation_outside_hours`).
- Tests de integración/HTTP automatizados con fixtures (solo unit sobre helpers puros + matriz `curl`).

Cada uno, si aterriza, va en su propio spec.
