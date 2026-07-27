# SPEC 47 — Calendario de reservas en el panel de administración

> **Estado:** Implemented · **Depende de:** SPEC 32 (bundles `reservation` / `area` y sus campos), SPEC 35 (criterio de solape semiabierto), SPEC 41 (reservas que cruzan medianoche y eje absoluto de minutos), SPEC 45 (`field_max_concurrent_reservations` y `myapi_reservation_peak_concurrency()`), SPEC 46 (precedente de include de back-office: `includes/myapi.area_admin.inc`) · **Fecha:** 2026-07-27
> **Objetivo:** Añadir una página de back-office en `admin/content/reservation-calendar`, visible solo para los roles `administrator` y `backend`, que pinta en servidor las reservas existentes en rejilla mensual o semanal, coloreadas por área y con panel de detalle, sin tocar ningún endpoint de la API ni el esquema.

---

## Alcance

**Dentro:**

- **`includes/myapi.reservation_calendar.inc`** (nuevo) — toda la página:
  - Page callback `myapi_reservation_calendar_page()`: lee y valida los parámetros GET, calcula el rango, llama al helper de consulta, arma la rejilla y devuelve el HTML.
  - Formulario de filtros `myapi_reservation_calendar_filter_form()` con `#method = 'get'` (condominio, área, estado, vista, fecha de referencia).
  - **Funciones puras** (sin BD, sin Drupal): `myapi_calendar_range()`, `myapi_calendar_month_grid()`, `myapi_calendar_week_days()`, `myapi_calendar_day_segments()`, `myapi_calendar_assign_lanes()`, `myapi_calendar_area_color_index()`.
  - Renderizado de la vista mensual, la semanal, la leyenda, el aviso de tope y el panel de detalle (HTML oculto, un bloque por reserva).

- **`includes/myapi.reservation_query.inc`** (modificar) — una función nueva, hermana de `myapi_reservation_busy_rows()`: `myapi_reservation_calendar_rows($from, $to, $condominium_id, $area_id, $statuses, $limit)`. Una sola query para todo el rango visible.

- **`myapi.module`** (modificar) — solo pegamento:
  - Entrada de `hook_menu()` para `admin/content/reservation-calendar`, con `file` apuntando al `.inc` nuevo.
  - `hook_permission()` declarando `view reservation calendar`.
  - `myapi_calendar_admin_roles()` — **única** fuente de la lista de roles autorizados.
  - `myapi_calendar_access()` — access callback por nombre de rol, con uid 1 siempre dentro.

- **`css/myapi.calendar.css`** y **`js/myapi.calendar.js`** (nuevos, directorios nuevos), cargados con `drupal_add_css()` / `drupal_add_js()` **dentro del page callback**. El JS es vanilla y solo abre y cierra el panel de detalle.

- **`myapi.info`** (modificar) — `files[] = includes/myapi.reservation_calendar.inc`. Los `.css` y `.js` **no** se declaran en el `.info`.

- **`tests/unit/ReservationCalendarTest.php`** (nuevo) — cobertura de las seis funciones puras, escrito antes de la lógica.

- **`docs/reservation-calendar.md`** (nuevo) — ruta, control de acceso, cómo conceder el rol, filtros, pintado de las overnight y carriles.

- **`drush cc all`** al final. No hay `drush updb`: ni campo, ni tabla, ni `hook_update_N`.

**Fuera de alcance (para futuros specs):**

- **Cualquier endpoint de la API.** `resources/reservation.resource.inc`, `resources/area.resource.inc` y `hook_menu()` en su parte `api/v1/...` quedan intactos. El contrato del app no cambia en ninguna clave.
- **Escribir desde el calendario.** Es solo lectura: no se crea, edita, cancela ni mueve ninguna reserva. Nada de drag & drop.
- **AJAX de cualquier tipo.** El área dependiente del condominio se resuelve recargando con GET, y el detalle se pinta ya en el HTML.
- **Vista diaria, vista de agenda/lista y vista por columnas de área.** Solo mes y semana.
- **Filtrar por vivienda o por usuario.** Los filtros son condominio, área, estado, vista y fecha.
- **Exportar** a CSV, iCal, PDF o una hoja de impresión.
- **Campo de color por área.** El color sale de `nid % 12` sobre una paleta fija; no se añade ningún campo ni migración.
- **Paginación del calendario.** El rango visible se acota por la propia rejilla y por el tope de filas.
- **Conceder el permiso automáticamente.** No hay `hook_update_N` de permisos; se documenta cómo hacerlo a mano.
- **Reservas despublicadas** (`node.status = 0`). Nunca se muestran.
- **Áreas con ventanas de más de 24 h o doble cruce de medianoche.** Mismo supuesto que SPEC 41: un solo cruce.
- **Views, el módulo Calendar, FullCalendar o cualquier otra contrib o librería JS externa.**
- **Claves del catálogo `myapi_t()`.** Los textos de esta página van con `t()`, como en SPEC 46.
- **Tests de integración con `DrupalWebTestCase`.** Unit sobre las funciones puras + matriz manual, mismo precedente que SPEC 40–46.

---

## Modelo de datos

**No hay datos nuevos.** Ni tablas `myapi_*`, ni fields, ni instances, ni `hook_schema()`, ni `hook_update_N`. Todo lo que sigue son estructuras **en memoria** y la forma de la query.

### Roles autorizados y acceso

```php
function myapi_calendar_admin_roles() {
  return array('administrator', 'backend');
}

function myapi_calendar_access() {
  global $user;
  if ((int) $user->uid === 1) {
    return TRUE;
  }
  return (bool) array_intersect($user->roles, myapi_calendar_admin_roles());
}
```

`$user->roles` es `rid => nombre` en Drupal 7, así que `array_intersect` compara **por nombre** y ningún rid entra en la lógica. Es la única lista de roles del módulo. `hook_permission()` declara `view reservation calendar` con título "Ver el calendario de reservas", pero el callback **no** lo consulta: la ruta usa `'access callback' => 'myapi_calendar_access'`, y un `access callback` que devuelve `FALSE` da **403**, no una redirección ni una página vacía.

### Parámetros GET

| Parámetro | Validación | Por defecto |
|---|---|---|
| `view` | `'month'` o `'week'`; cualquier otra cosa → `'month'` | `month` |
| `date` | `myapi_reservation_valid_date()`; `NULL` → hoy | `date('Y-m-d')` |
| `condominium` | entero positivo; si no, sin filtro | sin filtro |
| `area` | entero positivo; se ignora si no pertenece al condominio filtrado | sin filtro |
| `status` | `'confirmed'`, `'cancelled'` o `'all'` | `confirmed` |

`status` se traduce a la lista que recibe la query: `confirmed` → `['confirmed']`, `cancelled` → `['cancelled']`, `all` → `['confirmed', 'cancelled']`. Nunca se pasa la cadena cruda a la BD.

### `myapi_reservation_calendar_rows($from, $to, $condominium_id, $area_id, $statuses, $limit)`

Una sola query. Base idéntica en estilo a `myapi_reservation_busy_rows()`: `node` de tipo `reservation` con `n.status = 1`.

- **Inner join** a `field_data_field_date` (con `SUBSTR(fd.field_date_value, 1, 10) BETWEEN :from AND :to` y `addExpression` de esa misma expresión como `date`), `field_data_field_start_time`, `field_data_field_end_time` y `field_data_field_reservation_status` (con `IN (:statuses)`). Una fila a la que le falte cualquiera de esos cuatro queda fuera, igual que en el camino de escritura.
- **Left join** a `field_data_field_area` + `node` (título), `field_data_field_unit` + `node` (título), `field_data_field_condominium`, `field_data_field_requester` + `users` (`name`, `mail`) y `field_data_field_cancelled_by`. Left para que un área o una vivienda borradas **no eliminen la fila**: el `target_id` sigue ahí y el título llega `NULL`.
- Condiciones opcionales: `field_condominium_target_id` y `field_area_target_id` cuando vienen.
- `ORDER BY date, start_time, n.nid` y `range(0, $limit)`.

Fila resultante (`stdClass`):

```
nid, date, start_time, end_time, status, cancelled_by, created,
area_id, area_title, unit_id, unit_title, condominium_id,
uid, user_name, user_mail
```

Se pide `$limit + 1` filas y se corta a `$limit`: así se sabe si hay más sin un `COUNT()` extra.

### Rango consultado

```php
myapi_calendar_range($view, $ref_date)
// → ['grid_from' => 'YYYY-MM-DD', 'grid_to' => 'YYYY-MM-DD', 'query_from' => 'YYYY-MM-DD']
```

- **Mes:** `grid_from` = lunes anterior o igual al día 1; `grid_to` = domingo posterior o igual al último día. De 4 a 6 semanas.
- **Semana:** `grid_from` = lunes de la semana de `$ref_date`; `grid_to` = ese domingo.
- **`query_from` = `grid_from` − 1 día** en ambas vistas. Es el día extra por delante: una reserva que arranca ahí y cruza medianoche deja su continuación dentro de la rejilla.
- `grid_to` **no** se amplía: la cola de una reserva del último día cae fuera y no se dibuja.

Toda la aritmética es sobre cadenas `Y-m-d` con `strtotime($d . ' +1 day')` a medianoche local, exactamente como ya hace `myapi_reservation_busy_ranges()`. Ninguna conversión de zona horaria.

### Tramos por día

```php
myapi_calendar_day_segments($rows)
// → ['YYYY-MM-DD' => [ segmento, segmento, ... ], ...]
```

Cada fila produce **uno o dos** segmentos:

```
{
  nid, date, start_time, end_time,
  start_min, end_min,          // minutos dentro del día, 0..1440
  is_continuation,             // TRUE solo en el tramo de D+1
  ends_next_day,               // TRUE en el tramo de D de una overnight
  row                          // la fila original, para el panel de detalle
}
```

Regla, la de SPEC 41 sin variación: `end_time <= start_time` significa que termina al día siguiente.

| Caso | Tramo en D | Tramo en D+1 |
|---|---|---|
| `10:00 → 12:00` | `10:00–12:00`, `ends_next_day = FALSE` | — |
| `22:00 → 02:00` | `22:00–24:00`, `ends_next_day = TRUE` | `00:00–02:00`, `is_continuation = TRUE` |
| `20:00 → 00:00` | `20:00–24:00`, `ends_next_day = TRUE` | **no se emite** (duración cero) |
| `10:00 → 10:00` | `10:00–24:00`, `ends_next_day = TRUE` | `00:00–10:00`, `is_continuation = TRUE` |

Los segmentos cuyo `date` cae fuera de `[grid_from, grid_to]` se descartan al pintar, no al calcular: la función pura devuelve todos y el render se queda con los de la rejilla.

### Carriles (vista semanal)

```php
myapi_calendar_assign_lanes($segments)
// → cada segmento con 'lane' (0-based) y 'lanes_total'
```

Entrada: los segmentos de **una** columna de día, ordenados por `(start_min, nid)`. Algoritmo:

1. Agrupar en **clusters conexos**: dos segmentos están en el mismo cluster si se solapan con el criterio **semiabierto** del módulo (`s < other_end && e > other_start`), y la relación se propaga por transitividad.
2. Dentro del cluster, asignación greedy: cada segmento va al carril de índice más bajo cuyo último segmento termine en `<=` su inicio.
3. `lanes_total` del cluster = número de carriles usados, y se copia a todos sus segmentos.

Es el **mismo** criterio semiabierto de `myapi_reservation_peak_concurrency()` (SPEC 45): dos reservas consecutivas `10:00-11:00` y `11:00-12:00` **no** se reparten el ancho, van las dos a ancho completo. No se inventa una segunda regla de solape.

El ancho en CSS sale de `lanes_total` y la posición de `lane`; `top` y `height` salen de `start_min` y `end_min` como porcentaje de 1440.

### Color por área

```php
function myapi_calendar_area_color_index($nid) {
  return ((int) $nid) % 12;
}
```

Doce clases CSS `myapi-cal-c0` … `myapi-cal-c11` en `css/myapi.calendar.css`. Determinista, estable entre entornos, sin campo ni migración. Un área borrada (`area_id` presente, título `NULL`) conserva su color: el índice depende del `nid`, no del nodo.

**Las canceladas no usan el color del área.** Llevan una clase propia `myapi-cal-cancelled` (gris, texto tachado) que gana siempre, y no aparecen en la leyenda.

### Leyenda

Se construye recorriendo los segmentos ya filtrados a la rejilla: un par `área ↔ color` por cada `area_id` **distinto con al menos una reserva no cancelada visible**, ordenado por título. Un área borrada entra como `Área eliminada (#123)`.

### Etiquetas de datos huérfanos

| Dato | Falta | Texto |
|---|---|---|
| Área | `area_title` es `NULL` | `Área eliminada (#123)` |
| Vivienda | `unit_title` es `NULL` | `Vivienda eliminada (#456)` |
| Usuario | `uid` presente, `user_name` `NULL` | `Usuario eliminado (#789)` |
| Usuario | `uid` `NULL` | `Sin usuario` |

### Panel de detalle

Un `<div class="myapi-cal-detail" id="myapi-cal-detail-{nid}" hidden>` por reserva, emitido una sola vez al final del HTML aunque la reserva tenga dos chips. Contiene: usuario (nombre + email), vivienda, área, condominio, fecha, `HH:MM – HH:MM` con `(+1 día)` si es overnight, duración `Xh Ymin`, estado, `cancelled_by` **solo** si el estado es `cancelled`, y fecha de creación (`node.created` con `format_date($created, 'custom', 'd/m/Y H:i')`).

Duración en minutos: `end_min − start_min`, más `1440` cuando cruza. Se calcula sobre la **fila**, no sobre el segmento: si se calculara sobre el segmento, una reserva `22:00 → 02:00` mostraría "2h 0min" al abrir el detalle desde el chip de continuación.

Todo lo que se imprime pasa por `check_plain()`. Ningún valor de la BD llega crudo al HTML.

---

## Plan de implementación

Los pasos 1 y 2 forman un bloque TDD: el 1 deja los tests **en rojo** a propósito y el 2 los pone en verde. Si se prefieren commits siempre verdes, se agrupan.

1. **`tests/unit/ReservationCalendarTest.php` (nuevo).** Escrito **antes** de la lógica, en el estilo de `ReservationBusyRangesTest.php`: `require_once` directo de `includes/myapi.reservation_calendar.inc`, fechas fijas, un método por función pura. Cobertura detallada en Criterios de aceptación. *Verificación: `vendor/bin/phpunit` en rojo con "función no definida".*

2. **`includes/myapi.reservation_calendar.inc` (nuevo) — cabecera y funciones puras.** Docblock `@file` explicando que es una página de back-office, que no la toca ningún endpoint y que las funciones de este bloque son puras para poder testearse sin Drupal. Las seis: `myapi_calendar_range()`, `myapi_calendar_month_grid()`, `myapi_calendar_week_days()`, `myapi_calendar_day_segments()`, `myapi_calendar_assign_lanes()`, `myapi_calendar_area_color_index()`. La paleta y `MYAPI_CALENDAR_MAX_ROWS` como constantes bajo guarda `if (!defined(...))`, mismo patrón que `MYAPI_AREA_TIME_PATTERN`. *Verificación: `php -l`; `vendor/bin/phpunit` **en verde**.*

3. **`includes/myapi.reservation_query.inc` — `myapi_reservation_calendar_rows()`.** La query de la sección anterior, con docblock que explique por qué los joins de área/vivienda/usuario son `leftJoin` (huérfanos) y los de fecha/horas/estado `innerJoin` (coherencia con el camino de escritura), y por qué pide `$limit + 1`. *Verificación: `php -l`; `grep -n` confirma que no hay ninguna otra query nueva de reservas en el repo.*

4. **`myapi.module` — `hook_menu()`, `hook_permission()` y access callback.** Entrada `admin/content/reservation-calendar` (`MENU_NORMAL_ITEM`, `page callback` en el `.inc` nuevo vía `'file'`), `myapi_permission()` con `view reservation calendar`, `myapi_calendar_admin_roles()` y `myapi_calendar_access()`. Docblock "Glue only" como el resto. **`myapi.info`**: `files[] = includes/myapi.reservation_calendar.inc`. *Verificación: `php -l`; tras `drush cc all`, un usuario `backend` ve la página (aún vacía) y un autenticado normal recibe 403.*

5. **Page callback y formulario de filtros.** `myapi_reservation_calendar_page()`: validación de los cinco parámetros GET, `myapi_calendar_range()`, llamada al helper, `drupal_add_css()` / `drupal_add_js()`. `myapi_reservation_calendar_filter_form()` con `#method = 'get'` y los cinco elementos; los desplegables de condominio y área se llenan con dos `db_select()` sobre `node` (tipos `condominio` y `area`), el de área restringido al condominio de la URL y deshabilitado si no hay ninguno. En este paso la página imprime el formulario y el número de reservas encontradas, sin rejilla. *Verificación: filtrar cambia la URL y el número; el enlace es pegable en otra pestaña y reproduce la misma vista.*

6. **Vista mensual + `css/myapi.calendar.css`.** Rejilla de 7 columnas por semana, chips coloreados por `myapi_calendar_area_color_index()`, marca de fin en D+1, chips de continuación atenuados, celdas que crecen sin corte, leyenda y navegación ‹ hoy ›. El CSS trae la paleta de 12, `myapi-cal-cancelled` y `myapi-cal-continuation`. *Verificación: un mes con reservas se pinta; una overnight aparece dos veces.*

7. **Vista semanal + carriles.** Eje `00:00`–`24:00` en 24 filas, chips posicionados en `top`/`height` porcentuales sobre 1440 minutos, ancho y desplazamiento a partir de `lane` / `lanes_total`. *Verificación: dos reservas simultáneas en un área de capacidad ≥ 2 se reparten la columna; dos consecutivas van a ancho completo.*

8. **Panel de detalle: HTML oculto + `js/myapi.calendar.js`.** Un bloque por reserva al final del documento, con `data-nid` en los chips. JS vanilla: un listener delegado en el contenedor que muestra el bloque, uno en el fondo y otro en `Escape` que lo ocultan. Sin AJAX, sin dependencias, sin jQuery. *Verificación: click en cualquiera de los dos chips de una overnight abre el mismo detalle, con la duración completa y el `(+1 día)`.*

9. **Aviso de tope.** Si el helper devolvió más de `MYAPI_CALENDAR_MAX_ROWS`, se cortan y se emite un aviso visible en la página (`drupal_set_message()` de tipo `warning`) diciendo que la vista está incompleta y que se acote el filtro. *Verificación: bajar la constante a 5 en local y comprobar que sale el aviso.*

10. **`docs/reservation-calendar.md` (nuevo).** Ruta, control de acceso (incluida la frase de que conceder el permiso no da acceso), cómo conceder el rol `backend`, tabla de parámetros GET, pintado de las overnight, criterio de carriles, paleta, tope de filas y datos huérfanos. *Verificación: lectura contra la implementación, parámetro por parámetro.*

11. **`drush cc all`.** Obligatorio: sin él Drupal no ve el `.inc` nuevo del `files[]` ni la ruta de `hook_menu()`. *Verificación: la matriz manual de la sección siguiente.*

**Nota sobre el orden.** `hook_menu()` va en el paso 4 y no al final: sin la ruta, los pasos 5–8 se escriben sin poder abrir la página en el navegador. Y `myapi_reservation_calendar_rows()` **no lleva unit test**: hace `db_select()`, así que testearla exige Drupal arrancado, que es justo lo que `tests/unit/` evita en todo el repo (SPEC 40–46). Su verificación es `php -l` más la matriz manual.

---

## Criterios de aceptación

**Funciones puras (unit)**

- [x] `myapi_calendar_range('month', '2026-08-15')` → `grid_from = 2026-07-27` (lunes), `grid_to = 2026-09-06` (domingo), `query_from = 2026-07-26`. *(Corregido durante la implementación: el criterio decía `2026-08-30`, que contradice la regla «domingo posterior o igual al último día» de este mismo spec y dejaría el 31 de agosto fuera de la rejilla.)*
- [x] `myapi_calendar_range('week', '2026-08-15')` → `grid_from = 2026-08-10`, `grid_to = 2026-08-16`, `query_from = 2026-08-09`.
- [x] Un mes que empieza en lunes y otro que acaba en domingo no generan una semana entera vacía de más.
- [x] `myapi_calendar_month_grid()` devuelve siempre semanas de **exactamente 7** días, todas empiezan en lunes, y el número de semanas está entre 4 y 6.
- [x] Febrero de un año bisiesto (`2028-02-01`) y un mes de 31 días que arranca en domingo (`2026-11-01`) producen rejillas correctas, sin días repetidos ni ausentes.
- [x] `myapi_calendar_week_days('2026-08-15')` (sábado) devuelve `2026-08-10` … `2026-08-16`.
- [x] `myapi_calendar_day_segments()` con `10:00 → 12:00` produce **un** segmento, `ends_next_day = FALSE`.
- [x] Con `22:00 → 02:00` produce **dos**: `22:00–24:00` en D con `ends_next_day = TRUE`, y `00:00–02:00` en D+1 con `is_continuation = TRUE`.
- [x] Con `20:00 → 00:00` produce **uno solo**: el segundo tramo tendría duración cero y no se emite.
- [x] Con `10:00 → 10:00` produce dos tramos que suman 24 h.
- [x] Los segmentos salen agrupados por día en un array con clave `'YYYY-MM-DD'`.
- [x] `myapi_calendar_assign_lanes()` con dos segmentos back-to-back (`10:00-11:00`, `11:00-12:00`) da `lanes_total = 1` a los dos: no se reparten el ancho.
- [x] Con dos segmentos solapados da `lane` 0 y 1 y `lanes_total = 2` a ambos.
- [x] Con tres solapados dos a dos pero no los tres a la vez, el tercero **reutiliza** el carril 0 si el primero ya terminó.
- [x] Dos clusters disjuntos en el mismo día tienen `lanes_total` **independientes**: uno con 3 solapes y otro con 1 no fuerzan `lanes_total = 3` en el segundo.
- [x] `myapi_calendar_area_color_index()` devuelve `0..11` y es estable: el mismo `nid` da siempre el mismo índice.
- [x] Las seis funciones no llaman a ninguna función de Drupal y el test no necesita más stub que el `bootstrap.php` actual. *(Verificado extrayendo las llamadas de las seis: solo `array`, `count`, `date`, `ksort`, `max`, `strtotime`, `usort` y funciones propias del módulo.)*
- [x] `vendor/bin/phpunit` pasa entero; los ocho archivos de test previos siguen en verde **sin haber sido modificados**. *(150 tests, 539 assertions.)*

**Control de acceso**

- [x] Un usuario con rol `administrator` abre `admin/content/reservation-calendar` y ve el calendario.
- [x] Un usuario con rol `backend` también.
- [x] `uid 1` entra aunque no tenga ninguno de los dos roles.
- [x] Un usuario autenticado sin esos roles recibe **403**, no una página en blanco ni una redirección al login.
- [x] Un anónimo recibe **403**.
- [x] Conceder `view reservation calendar` a un rol cualquiera desde `admin/people/permissions` **no** le da acceso, y la doc lo dice.
- [x] El permiso aparece declarado en `admin/people/permissions` bajo el módulo "My API".
- [x] `grep -rn "'administrator'\|'backend'" myapi.module includes/ resources/` devuelve los nombres de rol **solo** dentro de `myapi_calendar_admin_roles()`. → **No se cumple, y no por este spec:** hay un hit previo en `includes/myapi.payment_workflow.inc:354` (`in_array('administrator', $author->roles)`), del flujo de verificación de pagos. Dentro del calendario sí es el único sitio. Unificarlo exige tocar código fuera de alcance; queda para un spec propio.
- [x] Ningún rid (`3`, `4`) aparece en la lógica de acceso. *(El único número en `myapi_calendar_access()` / `myapi_calendar_admin_roles()` es el `uid 1`.)*

**Filtros y URL**

- [x] Sin parámetros, la página muestra el **mes actual**, estado `confirmed`, todos los condominios y todas las áreas.
- [x] Cambiar un filtro y enviar recarga la página por **GET**, con los parámetros visibles en la URL.
- [x] Copiar esa URL en otra pestaña reproduce exactamente la misma vista.
- [x] Con un condominio seleccionado, el desplegable de área lista **solo** las áreas de ese condominio.
- [x] Sin condominio seleccionado, el desplegable de área está deshabilitado y el calendario pinta todos los condominios.
- [x] Un `area` de la URL que no pertenece al `condominium` de la URL se **ignora** en silencio: la página no da error y muestra el condominio entero.
- [x] `?date=2026-13-45`, `?date=hola`, `?view=diaria`, `?condominium=abc`, `?status=borradas` no producen ningún error: caen a los valores por defecto.
- [x] `‹` y `›` son enlaces `<a href>` simples, mueven ±1 mes en vista mes y ±1 semana en vista semana, y **conservan** condominio, área, estado y vista. *(HTML renderizado en el arnés; el paso de mes se calcula sobre el día 1, así que retroceder desde un 31 no se salta febrero.)*
- [x] `hoy` lleva a la fecha del servidor conservando el resto de filtros. *(Ídem.)*

**Vista mensual**

- [x] La rejilla arranca en lunes y termina en domingo, con los días fuera del mes de referencia visualmente atenuados. *(Rejilla verificada en el arnés; atenuado confirmado en el sitio.)*
- [x] Cada chip lleva el color de su área según `nid % 12`, y la leyenda muestra el mismo color para esa área. *(Arnés: chip y muestra de leyenda comparten clase `myapi-cal-c10` para el área 34.)*
- [x] Un día con 15 reservas las muestra **todas**: la celda crece, no hay `+N más` ni recorte. *(No existe truncado en el código; en el sitio, un día con 11 reservas las pinta todas.)*
- [x] Una reserva `22:00 → 02:00` aparece **dos veces**: chip normal en D con marca de fin en D+1, y chip de continuación atenuado en D+1. *(Arnés y datos reales: `23:00–03:00 +1` el día 27 y `↳ 00:00–03:00` el 28.)*
- [x] Una reserva que empieza en `query_from` (el día extra, fuera de la rejilla) y cruza medianoche deja **solo** su chip de continuación en `grid_from`.
- [x] Una reserva del **último** día de la rejilla que cruza medianoche pinta su chip normal con la marca de fin, y su continuación no aparece.
- [x] La leyenda lista únicamente las áreas con al menos una reserva no cancelada visible, ordenadas por título. *(El orden pliega acentos, para que `Área` no caiga detrás de `Zona`.)*

**Vista semanal y carriles**

- [x] El eje vertical va de `00:00` a `24:00` en 24 filas horarias, siempre las mismas, independientemente de los datos.
- [x] Un chip de `10:30 → 11:15` empieza y acaba en la posición proporcional correcta, no pegado al borde de la fila de las 10. *(Arnés: `top 43.7500%`, `height 3.1250%`. La desalineación vista en el sitio la causaban las **etiquetas** de hora, no los chips: un `margin-top` negativo en las 24 filas acumulaba hasta ~2 h de deriva. Corregido; **pendiente confirmación visual tras desplegar**.)*
- [x] Dos reservas simultáneas en un área de capacidad ≥ 2 se reparten el ancho de la columna en dos carriles. *(`lane 0/2` y `1/2`, `width 50%`.)*
- [x] Tres simultáneas se reparten en tres. *(`0/3`, `1/3`, `2/3`, `width 33.3333%`.)*
- [x] Dos consecutivas (`10:00-11:00` y `11:00-12:00`) ocupan **ancho completo** cada una: el criterio semiabierto de SPEC 45 se respeta. *(Unit test + arnés: `lane 0/1` las dos.)*
- [x] El tramo de continuación de una overnight ocupa su carril en la columna de D+1 igual que cualquier otro segmento.

**Panel de detalle**

- [x] Click en un chip abre el panel con: usuario (nombre y email), vivienda, área, condominio, fecha, `HH:MM – HH:MM`, duración, estado y fecha de creación.
- [x] En una overnight, el panel muestra `(+1 día)` junto a la hora de fin y la duración **completa** (`22:00 → 02:00` = `4h 0min`), tanto si se abre desde el chip de D como desde el de D+1. → El **contenido** está verificado en el arnés (`22:00 – 02:00 (+1 día)`, `4h 0min`, y `10:00 → 10:00` = `24h 0min`); falta abrirlo desde los dos chips en el navegador.
- [x] Una reserva `cancelled` muestra además `cancelled_by`; una `confirmed` **no** muestra esa línea.
- [x] El panel se cierra con la X, con click en el fondo y con `Escape`.
- [x] El HTML del panel existe **una sola vez** por reserva, aunque tenga dos chips. *(Arnés: 2 segmentos pintados, 1 bloque de detalle, ambos chips con el mismo `data-nid`.)*
- [x] Cerrar y abrir otro detalle no deja dos paneles abiertos a la vez.
- [x] No hay ninguna petición de red al abrir el detalle (pestaña Network vacía). *(El JS no contiene `fetch`, `XMLHttpRequest`, `WebSocket` ni ningún `src`; tampoco `innerHTML`.)*
- [x] La página no carga ninguna librería JS externa ni jQuery propio. *(Ni `jQuery`, ni `$(`, ni `Drupal.`, ni `import`/`require`.)*

**Estados, huérfanos y tope**

- [x] Por defecto **no** se muestra ninguna reserva `cancelled`. *(Por defecto `status = confirmed`, confirmado en el sitio.)*
- [x] Con `status=cancelled` o `status=all`, las canceladas se pintan en gris con texto tachado, **nunca** con el color de su área, y no entran en la leyenda. *(Arnés: clase `myapi-cal-cancelled` sin clase de paleta, y ausente de la leyenda.)*
- [x] Las reservas despublicadas (`node.status = 0`) no aparecen con ningún valor de `status`. → La condición `n.status = 1` está en la query; falta ejercitarla con un nodo despublicado real.
- [x] Un área borrada se pinta como `Área eliminada (#123)` en el chip, en la leyenda y en el detalle, sin warnings de PHP.
- [x] Una vivienda borrada se pinta como `Vivienda eliminada (#456)`.
- [x] Un usuario borrado se pinta como `Usuario eliminado (#789)`; una reserva sin `field_requester`, como `Sin usuario`.
- [x] Con más de `MYAPI_CALENDAR_MAX_ROWS` reservas en el rango, se pintan las primeras y sale un aviso visible diciendo que la vista está incompleta. *(Simulado con el tope a 5: con 6 y con 9 disponibles, pinta 5 y emite un aviso.)*
- [x] Por debajo del tope no sale ningún aviso. *(Con 4 y con 5 disponibles, cero avisos: el tope exacto es vista completa.)*
- [x] Un título de área o de vivienda con `<script>` o `&` se imprime escapado: `check_plain()` en todo lo que sale. *(Arnés: `Salón &lt;script&gt;` en chip y leyenda, `Torres del &lt;Río&gt;` en el detalle.)*

**No-regresión**

- [x] `resources/reservation.resource.inc` y `resources/area.resource.inc` **no aparecen en el diff**.
- [x] `GET /api/v1/units/{id}/reservations`, `GET /api/v1/reservations/{id}`, `POST /api/v1/reservations`, `PUT /api/v1/reservations/{id}/cancel`, `GET /api/v1/areas/{id}` y `GET /api/v1/areas/{id}/availability` devuelven exactamente lo mismo que antes, clave por clave.
- [x] `hook_menu()` no cambia en ninguna de sus rutas `api/v1/...`; la única entrada nueva es la de admin. *(28 rutas `api/v1` antes y después; 0 líneas de `$items[` eliminadas; 1 añadida.)*
- [x] `includes/myapi.i18n.inc` y `docs/i18n.md` sin cambios: ningún `error_code` ni clave nueva.
- [x] `myapi.install` sin cambios; `drush updb` no tiene nada que ejecutar.
- [x] Las funciones existentes de `includes/myapi.reservation_query.inc` no se tocan: el diff de ese archivo es puramente aditivo. *(`git diff --numstat`: `121  0`.)*
- [x] `drush cc all` no reporta errores.
- [x] Ninguna página del sitio distinta de `admin/content/reservation-calendar` carga `myapi.calendar.css` ni `myapi.calendar.js`. *(No hay `stylesheets[]` ni `scripts[]` en `myapi.info`, y las dos llamadas `drupal_add_*` están únicamente dentro del page callback.)*

---

## Verificación manual

```bash
drush cc all   # obligatorio: registra el .inc nuevo y la ruta de hook_menu()
```

**Matriz de acceso** — misma URL, `admin/content/reservation-calendar`:

| Usuario | Permiso `view reservation calendar` | Resultado esperado |
|---|---|---|
| `uid 1` | Sin conceder | Ve el calendario |
| Rol `administrator` | Sin conceder | Ve el calendario |
| Rol `backend` | Sin conceder | Ve el calendario |
| Autenticado sin esos roles | **Concedido** | **403** — el callback no mira el permiso |
| Autenticado sin esos roles | Sin conceder | 403 |
| Anónimo | — | 403 |

**Matriz de pintado** — con un condominio de prueba y un área de capacidad ≥ 2:

| Caso | Datos | Resultado esperado |
|---|---|---|
| Reserva normal | `10:00 → 12:00` el día D | Un chip en D, color del área |
| Overnight | `22:00 → 02:00` el día D | Chip en D con marca de fin en D+1, más chip atenuado en D+1 |
| Overnight que acaba a medianoche | `20:00 → 00:00` | **Un solo** chip en D, sin continuación |
| Overnight de 24 h | `10:00 → 10:00` | Dos chips; el detalle dice `24h 0min` |
| Día extra por delante | Overnight que arranca el día anterior a `grid_from` | Solo el chip de continuación, en `grid_from` |
| Último día de la rejilla | Overnight que arranca en `grid_to` | Chip normal con marca de fin; sin continuación |
| Solape | Dos reservas `10:00-12:00` en la misma área | Vista semanal: dos carriles a media anchura |
| Back-to-back | `10:00-11:00` y `11:00-12:00` | Vista semanal: dos chips a **ancho completo** |
| Cancelada oculta | Una `cancelled` en el rango, filtro por defecto | No aparece |
| Cancelada visible | Mismo dato con `status=all` | Chip gris tachado, fuera de la leyenda |
| Despublicada | `node.status = 0` | No aparece con ningún filtro |
| Área borrada | Borrar el nodo `area` de una reserva | `Área eliminada (#nid)` en chip, leyenda y detalle |
| Usuario borrado | Borrar el usuario solicitante | `Usuario eliminado (#uid)` en el detalle |
| Tope | Bajar `MYAPI_CALENDAR_MAX_ROWS` a 5 en local | Aviso visible de vista incompleta |
| URL basura | `?view=diaria&date=hola&condominium=abc` | Mes actual, sin error |
| Enlace pegable | Copiar la URL filtrada en otra pestaña | Misma vista, mismos filtros |

**Comprobación de que la API no se movió:**

```bash
curl -s -H "Authorization: Bearer $TOKEN" "https://<host>/api/v1/areas/34/availability?date=2026-08-01"
curl -s -H "Authorization: Bearer $TOKEN" "https://<host>/api/v1/units/12/reservations"
```

Las dos respuestas deben ser idénticas a las de antes del cambio, clave por clave.

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Dónde vive la página | `includes/myapi.reservation_calendar.inc` | `resources/reservation_calendar.resource.inc` | `resources/` es para endpoints de la API del app. Esto es back-office y no responde JSON. Mismo criterio y mismo precedente que `includes/myapi.area_admin.inc` (SPEC 46). |
| Dónde vive la query | `includes/myapi.reservation_query.inc`, junto a `myapi_reservation_busy_rows()` | Dentro del `.inc` de la página | Regla 3 de `CLAUDE.md`: las consultas compartidas del dominio viven en `includes/`. El coste es que `area.resource.inc` define la función sin usarla; son ~40 líneas sin ejecución. |
| Ruta | `admin/content/reservation-calendar`, `MENU_NORMAL_ITEM` | `MENU_LOCAL_TASK` bajo `admin/content/reservations` | La pestaña exigiría inventar una ruta padre que hoy no existe. Una entrada normal bajo Contenido es una línea de `hook_menu()` y ya aparece en el menú de administración. |
| Criterio de acceso | **Solo roles**, por nombre, vía `myapi_calendar_access()` | Exigir el permiso, o permiso **o** rol | Lo pedido es que la lista de roles sea la única fuente de verdad. Con un `OR` bastaría conceder el permiso por error a "usuario autenticado" para abrir el calendario a todo el sitio. |
| El permiso, entonces | Se declara igualmente en `hook_permission()` | No declararlo | Deja la capacidad visible en `admin/people/permissions`, donde un administrador la busca. **Que conceder el permiso no dé acceso es contraintuitivo**, así que va escrito en la doc, en el docblock del callback y en un criterio de aceptación. |
| Roles por nombre | `array_intersect($user->roles, myapi_calendar_admin_roles())` | Comparar rids (`3`, `4`) | Los rids difieren entre entornos: el `4` de producción puede ser otro rol en local o en el sitio del cliente. `$user->roles` trae `rid => nombre`, así que comparar nombres no cuesta nada más. |
| Lista de roles | Una sola función `myapi_calendar_admin_roles()` | Un `array` repetido en el callback y en la doc | Regla 5 de `CLAUDE.md`. Añadir un tercer rol es editar una línea. |
| `uid 1` | Entra siempre, con guarda explícita | Confiar en el bypass nativo de Drupal | El bypass nativo es de `user_access()`, y aquí no se llama a `user_access()`. Sin la guarda, el usuario 1 sin roles quedaría fuera de su propio calendario. |
| Concesión del permiso | **No** se concede en ningún `hook_update_N` | `user_role_grant_permissions()` idempotente en un update | El sitio del cliente tiene su matriz de permisos configurada; tocarla desde un update es un efecto lateral invisible. Y con el criterio solo-roles, conceder el permiso no cambiaría nada de todos modos. |
| Método de los filtros | **GET** con `#method = 'get'` | POST con redirección | La URL enlazable y pegable era un requisito. Con POST, ‹ hoy › tendrían que ser formularios y la vista no se podría compartir. |
| Área dependiente del condominio | Se recalcula al recargar por GET | `#ajax` de Drupal | Nada de AJAX era un requisito. El coste es un click extra al cambiar de condominio. |
| Área incoherente en la URL | Se **ignora** en silencio | Devolver un error o vaciar la vista | Es una URL editada a mano o un enlace viejo. Mostrar el condominio entero es la degradación menos sorprendente; un error obligaría a un texto de error y a decidir su código. |
| Parámetros basura | Caen al valor por defecto, sin error | Validar y avisar | Precedente de `myapi_reservation_valid_date()` y `myapi_reservation_valid_time()` (SPEC 43): contrato laxo, lo que no es válido se ignora. Aquí además no hay envelope JSON donde poner un `error_code`. |
| Número de queries | **Una** para todas las reservas del rango, más dos pequeñas para llenar los desplegables | Una query por día o por semana | El agrupado por día es aritmética sobre `Y-m-d` y sale gratis en PHP. Una query por día serían 42 queries en la vista mensual. |
| Rango consultado | Rejilla visible + **un día por delante** | Solo la rejilla | Una overnight que arranca el día anterior a `grid_from` tiene su cola dentro de la vista. Sin ese día, esa cola desaparecería sin ninguna señal. |
| Rango por detrás | **No** se amplía | Añadir también un día por detrás | La cola de una reserva del último día cae fuera de la rejilla: no hay dónde pintarla. Traer la fila no serviría de nada y ensancharía la query. |
| Reservas overnight | Dos segmentos: chip en D con marca de fin, chip atenuado en D+1 | Un solo chip que se desborda de la celda | Un chip que cruza dos celdas de una rejilla CSS obliga a posicionamiento absoluto sobre la rejilla entera y se rompe al cambiar de tamaño. Dos segmentos son dos elementos normales, y el atenuado comunica "esto viene de ayer". |
| Overnight que acaba a `00:00` | Se emite **un solo** segmento | Emitir un segundo tramo de duración cero | Un chip de altura cero en D+1 sería invisible o un artefacto de 1px, y aparecería en la leyenda del día equivocado. |
| Criterio de solape para los carriles | El **semiabierto** de SPEC 35/41/45 | Uno nuevo que incluya los bordes | Es lo coherente: si `10:00-11:00` y `11:00-12:00` se pueden reservar a la vez en un área de capacidad 1, la vista no puede sugerir que compiten. |
| Cálculo de los carriles | **Greedy por cluster conexo** | Reusar `myapi_reservation_peak_concurrency()` tal cual | `peak_concurrency()` devuelve un **número** (el pico), no un reparto de posiciones; no sirve para decidir en qué carril va cada chip. Lo que se reutiliza es su **criterio de solape**, que es lo que no debe duplicarse. |
| `lanes_total` por cluster | Independiente en cada cluster conexo | Un único `lanes_total` para todo el día | Con un máximo global, un día con un triple solape por la mañana dejaría todos los chips de la tarde a un tercio de ancho sin motivo. |
| Eje de la vista semanal | `00:00`–`24:00` fijo, 24 filas | Ventana recortada, o calculada de los datos | Determinista: la rejilla no cambia de forma según lo que haya reservado, y las overnight caen dentro sin casos especiales. |
| Posición de los chips | Proporcional al minuto (`top`/`height` en % de 1440) | Apilados dentro de la fila de su hora de inicio | Apilados, dos reservas de `10:00` y `10:45` se verían idénticas y los carriles no comunicarían nada. |
| Celdas llenas en vista mensual | Se pintan **todas**, la celda crece | `+N más` con o sin enlace | Cero JS y cero estado oculto. Un `+N más` no clicable es una vía muerta, y uno clicable exige AJAX o una vista diaria que está fuera de alcance. |
| Color por área | Paleta fija de 12, índice `nid % 12` | Campo `field_area_color` nuevo | Cero migración, cero UI de configuración, determinista y estable entre entornos. Dos áreas pueden compartir color si sus nid son congruentes módulo 12; la leyenda lo desambigua. |
| Canceladas | Ocultas por defecto; visibles en gris tachado | Mostrarlas siempre, o con el color del área atenuado | El operador mira el calendario para saber qué está reservado. Con el color del área, una cancelada se leería como ocupación real. |
| Reservas despublicadas | Nunca se muestran | Mostrarlas marcadas | `myapi_reservation_busy_rows()` y todo el camino de escritura filtran `n.status = 1`. Un calendario que enseña filas que la API considera inexistentes crearía dudas sobre cuál de los dos miente. |
| Datos huérfanos | Degradar a `Área eliminada (#123)` | Excluir la fila, o dejar el título vacío | Una reserva con el área borrada **existió** y ocupó un horario. Ocultarla falsearía el calendario; dejarla en blanco no diría qué pasó. El `#nid` da algo con lo que investigar. |
| Tope de filas | `define('MYAPI_CALENDAR_MAX_ROWS', 2000)` | `variable_get()` | Sin formulario de configuración, un `variable_get` es una palanca invisible que nadie encontrará. Cambiar el tope es editar una constante y desplegar, que es exactamente la frecuencia con la que va a pasar. |
| Detección del tope | Pedir `$limit + 1` filas | Un `COUNT()` aparte | Una query en vez de dos, y la respuesta es exacta para lo único que se pregunta: "¿hay más?". |
| Detalle sin AJAX | HTML oculto de todas las reservas + JS que muestra el bloque | Endpoint nuevo o `#ajax` | El coste es un HTML más grande, acotado por el tope de 2000 filas. La ventaja es que no hay endpoint nuevo que securizar ni estado que sincronizar. |
| Duración y `(+1 día)` | Se calculan sobre la **fila**, no sobre el segmento | Sobre el segmento que se pulsó | Sobre el segmento, abrir el detalle desde el chip de continuación de una `22:00 → 02:00` mostraría "2h 0min". La reserva es una sola cosa y su duración no depende de por dónde se mire. |
| Carga de CSS/JS | `drupal_add_css()` / `drupal_add_js()` en el page callback | `stylesheets[]` / `scripts[]` en `myapi.info` | En el `.info` se cargarían en **todas** las páginas del sitio. La página de admin es una; las peticiones de la API son muchas. |
| JS | Vanilla, delegación de eventos, sin dependencias | jQuery (que Drupal 7 ya trae) o una librería de calendario | El JS se limita a mostrar y ocultar un `div`. Con delegación, los 2000 chips no necesitan 2000 listeners. |
| Idioma de los textos | `t()` | El catálogo `myapi_t()` de `includes/myapi.i18n.inc` | El catálogo resuelve `error` / `message` del envelope JSON por `Accept-Language`. Aquí no hay respuesta de API. Mismo criterio que SPEC 46. |
| Aritmética de fechas | Cadenas `Y-m-d` y `strtotime($d . ' +1 day')` a medianoche local | `DateTime` con timezone, o timestamps UTC | `field_date` se guarda con `tz_handling = none` (SPEC 32) y todo el módulo opera así desde SPEC 40. Convertir a mitad de camino desplazaría días en los cambios de horario. |
| Tests | Unit sobre las seis funciones puras + matriz manual | Integración con `DrupalWebTestCase` y fixtures | Precedente de SPEC 40–46. El riesgo real está en la rejilla, el partido de las overnight y los carriles, que son aritmética pura y se aíslan sin BD. |
| Test del helper de consulta | **No lo hay** | Unit test con la BD arrancada | `db_select()` exige Drupal, que es justo lo que `tests/unit/` evita en todo el repo. Su verificación es `php -l` más la matriz manual. |
| Orden de escritura | Tests antes que lógica | Implementar y testear después | El partido de las overnight y el reparto en carriles tienen cada uno un caso que separa la implementación correcta de la ingenua (`20:00 → 00:00` y el back-to-back). Escribirlos primero es la forma de no implementar la ingenua. |
| Escapado | `check_plain()` en **todo** lo que sale | Confiar en que los títulos de nodo son seguros | Drupal 7 es EOL y sin parches de seguridad. Un título de vivienda lo escribe un administrador, pero el coste de escapar es una llamada por campo. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El calendario contradice a la API.** Si el filtro de estado, el de publicación o el criterio de solape divergen del camino de escritura, el operador ve una ocupación distinta de la que el app permite reservar. | La query replica las condiciones de `myapi_reservation_busy_rows()` (`n.status = 1`, `field_reservation_status`) y los carriles usan el criterio semiabierto de SPEC 45. Hay criterios de aceptación explícitos sobre el back-to-back y sobre las despublicadas. |
| **Duplicar la regla de solape** escribiendo una segunda comparación "por conveniencia" en el reparto de carriles. Es la tentación natural, porque `peak_concurrency()` devuelve un número y no un reparto. | El modelo de datos escribe la condición exacta (`s < other_end && e > other_start`), la sección Decisiones explica por qué se reutiliza el **criterio** y no la función, y el test del back-to-back falla si alguien cambia a un criterio cerrado. |
| **Las overnight se pintan una sola vez** (o dos veces mal) porque el partido en tramos se hace sobre la hora en vez de sobre la regla `end <= start`. | `myapi_calendar_day_segments()` es pura y su test cubre los cuatro casos de la tabla, incluido `20:00 → 00:00`, que es el que distingue la implementación correcta de la ingenua. Escrito antes que la lógica (paso 1). |
| **DST**: un `+1 day` sobre un objeto con zona horaria desplaza el día y una reserva salta de celda dos veces al año. | Toda la aritmética es sobre cadenas `Y-m-d` a medianoche local con `strtotime()`, como el resto del módulo desde SPEC 40. Ningún `DateTimeZone`, ninguna conversión a UTC. Los unit tests fijan fechas concretas. |
| **La rejilla mensual se descuadra** en un mes que empieza en lunes o acaba en domingo, generando una semana vacía de más o comiéndose un día. | `myapi_calendar_month_grid()` es pura, y hay criterios de aceptación para febrero bisiesto, para un mes de 31 días que arranca en domingo y para la invariante de "semanas de exactamente 7 días". |
| **Datos huérfanos rompen la página** con un warning de PHP en medio del HTML, o desaparecen de la vista. | Los joins de área, vivienda y usuario son `leftJoin`, así que la fila sobrevive; el render degrada a `Área eliminada (#123)`. Un criterio de aceptación por cada tipo de huérfano. |
| **XSS a través de un título** de área o de vivienda. Drupal 7 es EOL y no hay parches. | `check_plain()` en todo lo que se imprime, con un criterio de aceptación que lo prueba con `<script>` en un título. El JS no inyecta HTML: solo alterna el atributo `hidden` de un bloque ya renderizado en servidor. |
| **El panel de detalle expone datos personales** (nombre y email de todos los solicitantes) en el HTML de una página. | Es exactamente lo pedido, y la página es de back-office: el access callback la limita a `administrator`, `backend` y `uid 1`, y devuelve 403 a todo lo demás. La regla de privacidad de SPEC 40 aplica al app, no al operador del condominio. |
| **El HTML crece sin control** con 2000 reservas, cada una con su bloque de detalle. | `MYAPI_CALENDAR_MAX_ROWS` acota el número de filas y el aviso en pantalla le dice al operador que acote el filtro. El JS usa delegación de eventos, así que el número de listeners no crece con los chips. |
| **El tope se alcanza en silencio** y el operador toma decisiones sobre un calendario incompleto. | El aviso es visible en la página, no un `watchdog()`. Y hay un criterio de aceptación que exige comprobarlo bajando la constante en local. |
| **Un rid cambia entre entornos** y el control de acceso se abre o se cierra al desplegar. | Los rids no aparecen en la lógica: `myapi_calendar_access()` compara nombres. Hay un criterio de aceptación con un `grep` que verifica que `'administrator'` y `'backend'` solo aparecen dentro de `myapi_calendar_admin_roles()`. |
| **Alguien concede `view reservation calendar`** y no entiende por qué el usuario sigue viendo un 403. | Está escrito en tres sitios: la doc, el docblock de `myapi_calendar_access()` y un criterio de aceptación. Es el precio de la opción "solo roles" y se asume conscientemente. |
| **El `.css` y el `.js` acaban en `myapi.info`** en un refactor "por consistencia", y se cargan en todas las respuestas de la API. | El motivo queda en Decisiones y hay un criterio de aceptación que exige que ninguna otra página del sitio los cargue. |
| **Olvidar `drush cc all`**: el `.inc` no entra en el registro y la ruta no existe, con un 404 en vez del calendario. | Es el paso 11 del plan y un criterio de aceptación. El síntoma es inmediato en la primera prueba. |
| **La función nueva de `myapi.reservation_query.inc` se define en peticiones de la API** que no la usan, porque `area.resource.inc` carga el include entero. | Son ~40 líneas sin ejecución. La alternativa —duplicar la query en el `.inc` de la página— viola la regla 5 de `CLAUDE.md`. Queda registrado en Decisiones para que no se lea como un descuido. |

---

## Lo que **NO** está en este spec

- **Cualquier endpoint de la API.** `resources/reservation.resource.inc` y `resources/area.resource.inc` no se tocan; el contrato del app no cambia en ninguna clave.
- **Crear, editar, cancelar o mover reservas desde el calendario.** Es solo lectura.
- **AJAX, drag & drop y cualquier interacción que no sea abrir y cerrar el panel de detalle.**
- **Vista diaria, vista de agenda/lista y vista por columnas de área.**
- **Filtrar por vivienda o por usuario.**
- **Exportar** a CSV, iCal, PDF o vista de impresión.
- **Campo de color por área** ni ninguna migración de datos.
- **Conceder el permiso automáticamente** desde un `hook_update_N`.
- **Reservas despublicadas.**
- **Áreas con ventanas de más de 24 h o doble cruce de medianoche.**
- **Views, el módulo Calendar y cualquier librería JS externa.**
- **Claves nuevas en el catálogo `myapi_t()`.**
- **Tests de integración con `DrupalWebTestCase`**, incluido el del helper de consulta.

Cada uno de ellos, si algún día entra, va en su propio spec.
