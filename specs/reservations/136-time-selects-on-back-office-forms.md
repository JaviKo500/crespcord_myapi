# SPEC 136 — Desplegables de hora en los formularios de reserva y de área del back office

> **Estado:** Implemented, **pendiente de verificación manual** (ni staging ni producción) · **Depende de:** SPEC 32 (tipos de contenido de reservas), SPEC 41 (áreas que cierran pasada la medianoche), SPEC 46 y 52 (formato de horas), SPEC 134 (selectores dependientes), SPEC 135 (reglas de negocio en el formulario) · **Fecha:** 2026-11-24
>
> **Objetivo:** Que las horas de `node/add/reservation`, `node/add/area` y sus formularios de edición se **elijan de una lista** en vez de escribirse, siempre en formato de 24 horas. En la reserva, las horas ofrecidas dependen del **área** elegida.

---

## Problema

Los cuatro campos de hora (`field_open_time`, `field_close_time`, `field_start_time`, `field_end_time`) son cuadros de texto. La validación de SPEC 46/52 rechaza lo mal escrito, pero el operador solo se entera al guardar: *8:00*, *16.00* o *4pm* son errores de todos los días.

## Alcance

**Dentro:**

- **`includes/myapi.reservation_form.inc`** (modificar): `myapi_reservation_form_time_grid()`, el etiquetado de los dos inputs de hora en el `form alter` y la ruta `myapi_reservation_form_area_schedule()`.
- **`includes/myapi.area_admin.inc`** (modificar): `myapi_area_form_alter()`.
- **`includes/myapi.time_format.inc`** (modificar): `myapi_time_format_text_input_langcode()`.
- **`js/myapi.reservation_form.js`** (modificar) y **`js/myapi.time_select.js`** (nuevo).
- **`myapi.module`** (modificar): la ruta y `hook_form_area_node_form_alter()`. Solo pegamento.
- **`tests/unit/ReservationFormTimesTest.php`** (nuevo) y **`ModuleContractTest.php`** (la ruta entra en `NON_VERSIONED_PATHS`).
- **`docs/reservation-form.md`** y **`manual_backoffice/guia/04_reservas.md`** (modificar).

**Fuera de alcance:**

- Qué se guarda y qué se valida: los campos siguen siendo texto `HH:MM` y las validaciones de SPEC 46, 52 y 135 no se tocan.
- **Rechazar en el servidor una hora que no cae en un bloque.** Ni el formulario ni `POST /api/v1/reservations` lo hacen hoy, y esta spec no lo añade. Ver decisión 3.
- `POST /api/v1/reservations` y cualquier endpoint `api/v1/...`.
- Cambios de schema o `hook_update_N()`.
- Las capturas del manual (`ae_reserva_nueva.png`, `ae_reserva_llena.png`, `ae_area_nueva.png`, `ae_area_error_hora.png`): hay que rehacerlas.

---

## Diseño

### Reserva: horas del área elegida

```
condominio ──> área ──> hora de inicio ──> hora de fin
```

| Lista | Ofrece |
|---|---|
| Hora de inicio | Desde la apertura del área, de bloque en bloque (`field_slot_minutes`), sin incluir el cierre |
| Hora de fin | Las horas posteriores al inicio elegido, hasta el cierre y sin pasar de la duración máxima (`field_max_minutes`) |

- Sin área elegida, las dos listas están bloqueadas con *- Elige un área -*.
- El cierre es siempre la última opción de fin, aunque el horario no sea múltiplo del bloque (08:00–22:30 en bloques de 60 termina en …, 22:00, 22:30).
- Un área que cierra pasada la medianoche (cierre ≤ apertura, SPEC 41) da la vuelta al reloj: 22:00, 23:00, 00:00, 01:00, 02:00.
- Al cambiar el área o el inicio se vacía la hora que ya no esté entre las opciones.
- **Al abrir el formulario no se vacía nada.** Una hora guardada que no cae en un bloque (*16:20*) se añade como opción extra: editar una reserva no le cambia las horas.
- Si el área no tiene horario utilizable (sin bloque, hora mal formada) o la petición falla, vuelven los cuadros de texto.

### Área: hora y minutos

Apertura y cierre pasan a ser dos listas cada una: hora (00–23) y minutos (de 5 en 5, más 59, más el minuto ya guardado). Un valor guardado que no sea `HH:MM` se deja como cuadro de texto, para que se vea y se pueda corregir.

### Cómo está hecho

- **El `<select>` no tiene `name`.** Lo crea el JavaScript, oculta el input de texto y escribe en él. Drupal sigue leyendo y validando el mismo campo de texto; sin JavaScript el formulario es el de antes.
- **La rejilla se calcula en PHP** (`myapi_reservation_form_time_grid()`, pura y con test) y viaja por `GET admin/content/reservations/area-schedule/{area}` en el sobre habitual. Cada entrada lleva su hora y su distancia en minutos desde la apertura, para que el script mida duraciones a través de la medianoche sin rehacer esa aritmética.
- **Acceso a la ruta:** el mismo `myapi_reservation_form_access()` de SPEC 134. El área se resuelve con una consulta `node_access`: un `administrador edificio` recibe una rejilla vacía para un área ajena.
- **Nunca responde error:** id no numérico, área inexistente o no visible, y área sin horario utilizable responden la misma forma con `"grid": []`.
- **`max_minutes` se lee igual que al guardar:** solo `null` es "sin límite". Un área con duración máxima 0 no ofrece ninguna hora de fin, porque `myapi_reservation_schedule_violations()` rechazaría cualquiera.
- La comprobación "el campo es el cuadro de texto simple" es una sola, `myapi_time_format_text_input_langcode()` en `includes/myapi.time_format.inc`, usada por los dos `form alter`.
- JavaScript escrito para el jQuery 1.4 de Drupal 7 (`attr()`/`bind()`).

---

## Criterios de aceptación

Automáticos (`vendor/bin/phpunit`):

- [x] `ReservationFormTimesTest`: la rejilla (paso, cierre fuera de bloque, medianoche, día completo, horarios ilegibles), la ruta (sobre, ids inválidos sin consulta, `node_access`, acceso, área sin bloque, números guardados y que el máximo se lee igual que al guardar) y el etiquetado de los inputs en los dos formularios, incluido el todo-o-nada de las horas de la reserva.
- [x] `ModuleContractTest`: ruta declarada, con *callback*, acceso y fichero en `myapi.info`.

Fuera de la suite: los dos scripts se ejercitaron una vez con jsdom y jQuery 1.4.4 (alta, edición con hora fuera de bloque, cambio de área, área nocturna, área sin horario, petición fallida, respuesta tardía). No queda como test en el repositorio.

Manuales, en staging tras `drush cc all` — **ninguno hecho todavía**:

- [ ] `node/add/reservation`: las dos horas aparecen bloqueadas con *- Elige un área -*.
- [ ] Al elegir el área se cargan las horas de inicio según su apertura, cierre y bloque, en 24 horas.
- [ ] Al elegir el inicio, la hora de fin solo ofrece horas posteriores y dentro de la duración máxima.
- [ ] Cambiar el área, o el condominio, vacía las horas que ya no aplican.
- [ ] Guardar una reserva elegida con las listas funciona y pasa por las reglas de SPEC 135.
- [ ] `node/{nid}/edit` de una reserva existente llega con sus dos horas seleccionadas; una hora fuera de bloque se conserva.
- [ ] Un formulario devuelto con errores conserva las horas elegidas y marca la lista en rojo.
- [ ] Un área que cierra pasada la medianoche ofrece las horas de madrugada.
- [ ] `node/add/area` y `node/{nid}/edit`: apertura y cierre con hora y minutos; guardar funciona; un área existente llega con sus horas seleccionadas.
- [ ] Con el tema del back office (Bootstrap) las listas se ven alineadas con el resto de campos.
- [ ] Como `administrador edificio`: `…/area-schedule/{área ajena}` responde `"grid": []`.

---

## Decisiones

1. **Listas propias y no `<input type="time">`.** El selector nativo guarda `HH:MM`, pero se *muestra* en 12 o 24 horas según la región del equipo de cada operador y no se puede forzar. El propietario del producto pidió ver siempre *13:00*, *17:00*.
2. **El paso de la reserva lo da el área.** Decisión del propietario: "depende del área que se selecciona". Se usa `field_slot_minutes`, el mismo dato que la app recibe como `slot_minutes`.
3. **Comodidad, no regla.** La rejilla no se valida al guardar. Hacerlo cambiaría lo que acepta el formulario y dejaría sin poder editarse las reservas ya guardadas fuera de bloque; si se quiere, es otra spec y debería aplicarse también al endpoint.
4. **Paso de 5 minutos en el formulario de área.** El área no tiene de quién depender. Elegido por defecto, sin confirmar con el propietario; es una constante de `js/myapi.time_select.js`.
5. **Rejilla en el servidor.** La aritmética de la medianoche ya vive en `includes/myapi.reservation_rules.inc`; duplicarla en JavaScript la dejaría sin test.
6. **Se responde también por áreas no publicadas.** La ruta se pregunta por el área que una reserva ya tiene, y una reserva antigua de un área despublicada se sigue editando con su rejilla. El autocompletado, que es por donde se *elige* un área, sigue ofreciendo solo las publicadas.

## Riesgos

- **Sin verificar en navegador real.** Ni el aspecto con el tema Bootstrap ni la convivencia con el autocompletado del core están vistos en el sitio.
- **Una hora fuera de bloque ya no se puede teclear** desde el back office mientras el área tenga horario válido. Las ya guardadas se conservan, pero no se pueden crear nuevas.
- **Bloques muy pequeños dan listas muy largas.** Un área con bloque de 5 minutos abierta 14 horas ofrece 168 horas de inicio.
- **Una petición por cambio de área.** Si falla, el formulario vuelve a los cuadros de texto sin avisar.
- **Widget cambiado desde la UI de campos.** Los formularios vuelven al comportamiento anterior sin avisar; las validaciones siguen activas.
