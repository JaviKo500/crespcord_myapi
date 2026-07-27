# SPEC 43 — Refinamiento horario del rango de fechas y orden por hora en `GET /api/v1/units/{id}/reservations`

> **Estado:** Implemented · **Depende de:** SPEC 34 (endpoint de listado, `date_from`/`date_to`/`sort`), SPEC 37 (restricción por `field_requester`), SPEC 41 (normalización de `field_date` al día de reloj en reservas nocturnas) · **Fecha:** 2026-07-27
> **Objetivo:** Permitir que el listado corte por **hora** además de por día —`date_from=D&time_from=HH:MM` = "desde D a las HH:MM en adelante"— y que las reservas del mismo día vuelvan en orden cronológico en vez de por `nid`.

---

## Contexto

Hoy `date_from=2026-07-27` trae el día 27 **completo**, incluidas las reservas cuya hora ya pasó, y `sort=asc` ordena por `field_date_value` con desempate por `nid`, así que un mismo día puede llegar con las 15:00 antes que las 09:00. La app necesita "mis próximas reservas": un corte por instante, no por día, y una lista que se lea como una línea de tiempo.

Un filtro horario **plano** (aplicar `start_time >= time_from` a todos los días del rango) resolvería el día de hoy pero rompería los días siguientes: pedir "desde hoy 09:00" no puede ocultar las reservas de mañana a las 08:00. Por eso la hora se modela como un **refinamiento de su propia cota de fecha**, activo solo en el día frontera.

---

## Alcance

**Dentro:**

- Dos query params nuevos en `GET /api/v1/units/%/reservations`: `time_from` (refina `date_from`) y `time_to` (refina `date_to`), en formato `HH:MM` 24h.
- Semántica de corte por instante en la cota inferior y su espejo en la superior, aplicada **antes** de paginar.
- `sort` pasa a ordenar por `(date, start_time, nid)` en lugar de `(date, nid)`, en la misma dirección.
- `myapi_reservation_count()` y `myapi_reservation_fetch()` comparten la construcción del rango para que `total` no pueda divergir de las filas devueltas.
- Parseo laxo idéntico al resto del endpoint: nunca un `422`.
- Doc `docs/reservation.md` y tests unitarios de los helpers de parseo.

**Fuera de alcance:**

- Un `scope=upcoming|past` que resuelva el instante actual en el servidor (se puede construir encima de esto en un spec propio).
- Filtrar por solapamiento ("reservas activas a esta hora") en vez de por hora de inicio.
- Ordenar por cualquier otro campo, u ordenar por hora sin ordenar por fecha.
- Cambios en el resto de endpoints de reservas (creación, cancelación, detalle, disponibilidad).

---

## Modelo

**Query params** (parseo laxo, fallback en silencio):

| Param | Default | Regla |
|---|---|---|
| `time_from` | — | `HH:MM` 24h (`^([01]\d|2[0-3]):([0-5]\d)$`). Refina `date_from` **solo en ese día**. Se descarta si es inválido o si `date_from` está ausente/es inválido. |
| `time_to` | — | Mismas reglas, refina `date_to` solo en ese día. |

**Comparación resultante** (`date` = `SUBSTR(field_date_value,1,10)`):

```
cota inferior:  date > date_from  OR (date = date_from AND start_time >= time_from)
cota superior:  date < date_to    OR (date = date_to   AND start_time <= time_to)
```

Sin su hora, cada cota se queda en la comparación de día actual (`>=` / `<=`), sin cambios respecto a SPEC 34.

**Reglas de borde:**

- Ambos extremos **inclusivos**: una reserva que empieza exactamente en `time_from` (o en `time_to`) entra.
- Solo se compara `start_time`. Una reserva en curso (`08:00→10:00` con `time_from=09:00`) **no** se devuelve: el filtro responde "cuáles empiezan a partir de esta hora", no "cuáles están activas".
- En el día frontera, una reserva sin fila `field_start_time` queda fuera (`NULL` en la comparación), mismo criterio que una reserva sin fecha dentro de un rango. En el resto de días del rango sigue entrando.
- Rango de fechas invertido (`from > to`): se descarta el filtro entero (fechas **y** horas), como en SPEC 34.
- Rango de un solo día (`from == to`) con horas invertidas (`time_from > time_to`): se descartan **solo las horas** y se conserva el día. Un resultado garantizadamente vacío nunca es lo que pidió el cliente.
- Días distintos con horas "invertidas" (`27 desde 18:00` → `28 hasta 09:00`) es una ventana legítima y se conserva.
- Reservas que cruzan medianoche: SPEC 41 las guarda bajo su propio día de reloj con su `start_time` real, así que casan por el día en que **empiezan** (`23:00→01:00` del 27 entra por `date_from=27&time_from=22:00`, no por las cotas del 28).

**Orden:** `ORDER BY field_date_value DIR, field_start_time_value DIR, nid DIR`. `start_time` se almacena con cero a la izquierda (`HH:MM`), así que el orden de cadena ya es cronológico y no hace falta convertir. Las filas sin `start_time` se agrupan en un extremo de su día (orden de `NULL`), nunca intercaladas.

---

## Plan de implementación

1. **`myapi_reservation_valid_time($value)`** — gemelo de `myapi_reservation_valid_date()`: devuelve el string cuando casa `^([01]\d|2[0-3]):([0-5]\d)$`, si no `NULL`. *Verificación: unit tests.*

2. **`myapi_reservation_parse_date_range()`** — pasa a devolver cuatro claves (`from`, `to`, `time_from`, `time_to`). Cada hora se lee solo si su cota de fecha sobrevivió; el rango invertido sigue anulándolo todo; el caso de un solo día con horas invertidas anula solo las horas. *Verificación: unit tests.*

3. **`myapi_reservation_apply_range($query, $from, $to, $time_from, $time_to)`** — helper compartido que añade los `where()` de rango. Con hora emite la condición de día frontera (`OR`), sin hora la comparación de día. El placeholder del día se repite bajo dos nombres (`:date_from` / `:date_from_day`) porque un mismo placeholder nombrado no puede aparecer dos veces en una sentencia preparada. Los joins los pone cada caller. *Verificación: `php -l`.*

4. **`myapi_reservation_count()`** — firma `($unit_id, $from, $to, $time_from, $time_to, $status, $uid)`; cuando hay alguna hora activa añade el `leftJoin` a `field_data_field_start_time` (left, para que la fila sin hora llegue al `WHERE` y se descarte ahí) y delega en el helper.

5. **`myapi_reservation_fetch()`** — misma extensión de firma; ya tenía el `leftJoin` de `fstart` para el campo de salida, así que solo mueve la aplicación del rango detrás de ese join y delega en el helper. Añade `orderBy('fstart.field_start_time_value', DIR)` entre la fecha y el `nid`.

6. **`myapi_reservation_list()`** — lee las dos claves nuevas del rango y las pasa a ambas queries.

7. **`docs/reservation.md`** — filas nuevas en la tabla de query params, sección "Time refinement", nota de orden actualizada, tabla de esquema y ejemplo `curl`.

8. **`tests/unit/ReservationListFilterTest.php`** — cobertura de `myapi_reservation_valid_time()` y de las siete ramas de `myapi_reservation_parse_date_range()`.

9. **`drush cc all`** — no hay rutas nuevas, pero sí cambia un `.inc` ya registrado.

---

## Criterios de aceptación

- [x] `date_from=D&time_from=T` devuelve, del día `D`, solo las reservas con `start_time >= T`, y de los días posteriores **todas**.
- [x] `date_to=D&time_to=T` devuelve, del día `D`, solo las que tienen `start_time <= T`, y de los días anteriores todas.
- [x] Ambos extremos son inclusivos (`start_time == time_from` / `== time_to` entra).
- [x] `time_from`/`time_to` sin su cota de fecha, o con una cota de fecha inválida, se ignoran por completo.
- [x] Una hora malformada (`9:00`, `24:00`, `23:60`, `09:00:00`, `ahora`, vacío) se ignora en silencio, sin `422`, y su cota de fecha sigue aplicando.
- [x] Rango de fechas invertido descarta fechas y horas; rango de un solo día con horas invertidas conserva el día y descarta las horas; horas "invertidas" entre días distintos se conservan.
- [x] `total`/`total_pages` cuentan el conjunto ya filtrado por hora (count y fetch comparten `apply_range()`).
- [x] `sort=asc` devuelve `09:00` antes que `15:00` dentro del mismo día; `sort=desc` al revés; el empate final por `nid` mantiene la paginación estable.
- [x] Las reservas sin `start_time` quedan agrupadas en un extremo de su día, y en el día frontera de un filtro horario quedan excluidas.
- [x] `docs/reservation.md` documenta ambos params y el orden nuevo.
- [x] `vendor/bin/phpunit` en verde.

---

## Decisiones

- **Sí:** hora acoplada a su cota de fecha (corte por instante) en vez de un filtro horario plano. "Desde hoy a las 09:00" no puede ocultar las mañanas de los días siguientes; el filtro plano sí lo haría.
- **Sí:** comparar solo `start_time`, no el intervalo completo. El caso de uso es "próximas reservas"; incluir las que están en curso exigiría comparar contra `end_time` con la aritmética de cruce de medianoche de SPEC 41, y cambia la pregunta que responde el endpoint.
- **Sí:** helper `apply_range()` compartido entre `count()` y `fetch()`. Duplicar el `WHERE` era exactamente la forma de que `pagination.total` dejara de casar con las filas devueltas.
- **Sí:** el `orderBy` por hora entra siempre, no detrás de un flag. El orden por `nid` dentro de un día nunca fue una garantía útil para el cliente; el cronológico sí, y ningún consumidor puede depender del anterior.
- **Sí:** descartar solo las horas (no el día) en el rango invertido de un solo día. Devolver siempre vacío es el peor fallback posible para un parseo laxo.
- **Sí:** comparación de cadenas contra `field_start_time_value`, sin `STR_TO_TIME` ni cast. El formato `HH:MM` con cero a la izquierda ya ordena y compara cronológicamente, y es el formato que valida la creación (SPEC 35).
- **No:** `scope=upcoming`. Es azúcar sobre esto mismo y merece su propia decisión sobre zona horaria del sitio vs. del cliente.
- **No:** claves i18n nuevas. Nada de esto produce errores.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `count()` y `fetch()` filtrando distinto → `total` mentiroso | Un único `myapi_reservation_apply_range()` para las dos, con los mismos alias (`fdate`, `fstart`). |
| Reutilizar un placeholder nombrado dos veces en la misma sentencia | Dos nombres distintos para el mismo valor de día (`:date_from` / `:date_from_day`). |
| Filas sin `start_time` desapareciendo de días que no son el frontera | `leftJoin` (no inner) de `field_data_field_start_time` en `count()`; la rama `date > from` del `OR` las mantiene en el resto de días. |
| Un cliente que dependiera del orden por `nid` dentro del día | Nunca fue documentado como un orden con significado; el doc ahora fija `(date, start_time, nid)` explícitamente. |

## Lo que **NO** está en este spec

- `scope=upcoming|past` con instante resuelto en el servidor.
- Filtro por solapamiento (reservas activas a una hora dada).
- Otros campos de orden, o desacoplar el orden por hora del orden por fecha.
- Filtros por área o por solicitante.
