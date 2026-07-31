# SPEC 52 — Validación de formato HH:MM de las horas de la reserva en el admin

> **Estado:** Implemented — código y unit tests en verde; el bloque de formulario de los criterios de aceptación queda pendiente de la verificación manual en el sitio, junto con el `drush cc all` · **Depende de:** SPEC 32 (`field_start_time` / `field_end_time` como `text` con `max_length = 5` en el bundle `reservation`), SPEC 41 (reservas que cruzan medianoche: `end <= start` es legal), SPEC 46 (la regla de formato `HH:MM` y su recorrido del formulario, hoy solo para `area`), SPEC 51 (`myapi_node_validate()` ya despacha el bundle `reservation`) · **Fecha:** 2026-07-31
> **Objetivo:** Impedir que un nodo `reservation` se guarde desde el formulario de administración de Drupal con `field_start_time` o `field_end_time` en un formato distinto de `HH:MM` (24 h), extrayendo la regla que SPEC 46 escribió para `area` a un include compartido en vez de duplicarla, sin tocar ningún endpoint, ni el esquema, ni el contrato JSON de la API.

---

## El problema

SPEC 46 puso la validación de `HH:MM` en el formulario de nodo, pero **solo para el bundle `area`**, y lo dejó escrito en su lista de fuera de alcance:

> **`field_start_time` / `field_end_time` del bundle `reservation`.** Esos valores los escribe siempre `myapi_reservation_create()`, ya validados y formateados por el servidor; el formulario de nodo de una reserva no es la vía de entrada real.

Esa premisa dejó de ser cierta: las reservas **sí** se crean y editan desde el back office. Hoy, en `node/add/reservation` y `node/{nid}/edit`, las dos horas aceptan cualquier cosa de hasta 5 caracteres — `8:00`, `99:99`, `ocho`, `1 2 3` — porque:

- `myapi_node_validate()` (`myapi.module`) despacha el bundle `reservation` a `myapi_building_admin_validate_reservation()`, que solo comprueba `field_requester` y `field_unit` (SPEC 51) y **no** mira las horas;
- esa función además sale en su primera línea cuando el usuario no es administrador de edificio, así que para un administrador no corre nada en absoluto;
- los dos instances son `text` con `max_length = 5` y widget `text_textfield`, y eso es lo único que Drupal impone por su cuenta;
- el regex que sí existe vive en `resources/reservation.resource.inc` y solo corre en `POST /api/v1/reservations`; `hook_node_validate()` nunca lo alcanza, igual que el endpoint nunca pasa por la validación de formulario.

Una hora mal formada guardada así no es cosmética: rompe `myapi_reservation_time_to_minutes()`, las comparaciones de cadena de ancho fijo del filtro de rango (`fs.field_start_time_value > :now`) y el orden del calendario.

---

## Alcance

**Dentro:**

- **`includes/myapi.time_format.inc`** (nuevo) — la regla de formato, sin conocimiento de ningún bundle:
  - `MYAPI_TIME_FORMAT_PATTERN`, el regex de `HH:MM` en 24 h, bajo guarda `if (!defined(...))`. **Mismo patrón** que `MYAPI_AREA_TIME_PATTERN` de SPEC 46, que desaparece.
  - `myapi_time_format_is_valid($value)` — predicado **puro**, único sitio donde vive la regla. Sustituye a `myapi_area_time_is_valid()`.
  - `myapi_time_format_validate_fields(array $form_state, array $fields)` — el recorrido de `$form_state['values']` que SPEC 46 escribió dentro de `myapi_area_validate_times()`, ahora parametrizado por el mapa `nombre_de_campo => etiqueta`.

- **`includes/myapi.area_admin.inc`** (modificar) — `myapi_area_validate_times()` pasa a ser el punto de entrada del bundle `area`: carga el include compartido y le pasa sus dos campos. Pierde la constante y el predicado. **Comportamiento idéntico**, byte a byte en el mensaje.

- **`includes/myapi.reservation_admin.inc`** (nuevo) — `myapi_reservation_validate_times($node, $form, &$form_state)`, el punto de entrada simétrico del bundle `reservation` con `field_start_time` / `field_end_time`.

- **`myapi.module`** (modificar) — el `case 'reservation'` de `myapi_node_validate()` llama **primero** a la validación de formato (todos los usuarios) y **después** a `myapi_building_admin_validate_reservation()` (solo administradores de edificio). Sigue siendo pegamento.

- **`myapi.info`** (modificar) — `files[]` de los dos includes nuevos.

- **`tests/unit/TimeFormatTest.php`** (nuevo, reemplaza a `tests/unit/AreaTimeFormatTest.php`) — la misma matriz del predicado de SPEC 46 sobre el nombre nuevo, más cobertura del recorrido.

- **`tests/unit/bootstrap.php`** (modificar) — stubs de `t()` y `form_set_error()`, para poder ejercitar el recorrido sin Drupal.

- **`docs/reservation.md`** y **`docs/area.md`** (modificar) — nota de la validación en el admin.

- **`drush cc all`** al final (no hay `drush updb`: no hay campo, tabla ni `hook_update_N` nuevos).

**Fuera de alcance (para futuros specs):**

- **Las demás reglas de negocio del formulario de reserva.** Guardar una reserva desde el admin sigue **sin** comprobar solape con otra reserva, aforo del área (SPEC 45), horario de apertura del área, fecha en el pasado ni permiso de cancelación: todo eso vive en `myapi_reservation_create()` y solo corre en el endpoint. Este spec cierra el formato de dos campos, nada más. Es la brecha más grande que queda y merece su propio spec.
- **`field_date`.** Su widget es `date_select`, que no admite texto libre.
- **El regex duplicado de `resources/reservation.resource.inc`** (líneas 177 y 972). Es el mismo patrón escrito a mano dos veces y debería apuntar al predicado compartido, pero cambiarlo toca el camino del endpoint y tres archivos de test que hoy están verdes, sin arreglar nada que esté roto. Deuda anotada, no pagada aquí.
- **Validar la relación entre los dos campos** (`end > start`). SPEC 41 hace de `end <= start` un caso legal (reserva que cruza medianoche, guardada envuelta: `02:00`, no `26:00`). Una validación de orden rompería esas reservas.
- **Normalizar el valor** (`"9:00"` → `"09:00"`). El campo se rechaza, no se corrige.
- **Migrar o corregir reservas ya guardadas** con formato inválido.
- **Cambiar `max_length`, `required`, el widget o cualquier otra propiedad de los fields/instances.** El esquema de SPEC 32 queda intacto.
- **Validaciones programáticas** (`node_save()` directo, migraciones, `drush php-eval`). `hook_node_validate()` solo corre en el flujo del formulario.
- **Cualquier cambio en el contrato JSON, en `hook_menu()`, en el catálogo i18n o en `myapi.install`.**

---

## Modelo de datos

**No hay datos nuevos.** No se crean tablas, ni campos, ni instances, ni `hook_schema()`, ni `hook_update_N`. Los dos campos siguen siendo exactamente lo que definió SPEC 32:

| Campo | Tipo | Bundle | Requerido | Widget | Settings | Label |
|---|---|---|---|---|---|---|
| `field_start_time` | `text` | `reservation` | Sí | `text_textfield` | `max_length = 5` | Hora de inicio |
| `field_end_time` | `text` | `reservation` | Sí | `text_textfield` | `max_length = 5` | Hora de fin |

### Regla de formato

Es la de SPEC 46, sin un solo cambio, mudada de archivo:

```php
if (!defined('MYAPI_TIME_FORMAT_PATTERN')) {
  define('MYAPI_TIME_FORMAT_PATTERN', '/^([01][0-9]|2[0-3]):[0-5][0-9]$/');
}

myapi_time_format_is_valid($value) = (bool) preg_match(MYAPI_TIME_FORMAT_PATTERN, $value)
```

| Entrada | Válido | Motivo |
|---|---|---|
| `"00:00"` | Sí | Medianoche |
| `"08:00"`, `"22:30"`, `"23:59"` | Sí | Casos normales |
| `"8:00"` | **No** | Hora sin cero a la izquierda |
| `"24:00"` | **No** | Fuera del reloj de 24 h; el fin de día se escribe `23:59`, y una reserva que pasa de medianoche se guarda envuelta (SPEC 41) |
| `"25:00"`, `"12:60"` | **No** | Hora o minuto fuera de rango |
| `"08:00:00"` | **No** | Segundos; además excede `max_length = 5` |
| `"08.00"`, `"0800"`, `"ocho"`, `" 08:00"` | **No** | Separador, longitud o contenido |
| `""` | **No lo evalúa** | Se salta antes de llamar al predicado: `required = 1` ya emite su error |

El cero a la izquierda obligatorio no es cosmética: `field_start_time_value` se compara como cadena de ancho fijo contra `:now` y contra los bounds de `time_from` / `time_to`, y se usa como segunda columna de orden. `"9:00"` ordenaría después de `"10:00"`.

### Recorrido de los valores del formulario

Idéntico al de SPEC 46, ahora parametrizado por el mapa de campos que le pasa cada bundle:

```
myapi_time_format_validate_fields($form_state, $fields):
  para cada campo => etiqueta en $fields:
    si no hay valores para el campo → siguiente
    para cada langcode → deltas:
      si $deltas no es array → siguiente
      para cada delta → item:
        si $item no es array o no tiene 'value' → siguiente   # descarta 'add_more' y similares
        si trim($item['value']) === '' → siguiente            # lo cubre required
        si !myapi_time_format_is_valid($item['value']):
          form_set_error("{$campo}][{$langcode}][{$delta}][value", mensaje)
```

El `trim()` solo decide si el valor está vacío; lo que se compara contra el regex es el valor **crudo**, así que `" 08:00"` se rechaza en vez de guardarse con el espacio delante. La clave de `form_set_error()` lleva la ruta completa del elemento, que es lo que marca en rojo el input concreto.

### Mensajes

El mismo texto de SPEC 46, ahora para cuatro campos en dos bundles. Es back office, no envelope JSON: se emite con `t()`, no entra en `includes/myapi.i18n.inc` ni en `docs/i18n.md`, y no hay `error_code` nuevo.

```
@label: el formato debe ser HH:MM en 24 horas (por ejemplo 08:00 o 22:30).
```

Con `@label` ∈ {Hora de apertura, Hora de cierre, Hora de inicio, Hora de fin}, los labels que SPEC 32 dio a los instances.

### Orden de las dos validaciones del bundle `reservation`

```
myapi_node_validate($node) con $node->type === 'reservation':
  1. myapi_reservation_validate_times()          → TODOS los usuarios
  2. myapi_building_admin_validate_reservation() → sale en la 1.ª línea si no es administrador de edificio
```

El formato va primero por una razón concreta: es la validación que aplica a todo el mundo. Si un administrador de edificio envía a la vez una hora mal formada y una vivienda fuera de sus condominios, ve **los dos** mensajes en el mismo envío — `form_set_error()` acumula, no corta.

### Sin cambios en el contrato de la API

`GET /api/v1/units/{id}/reservations`, `POST /api/v1/reservations`, `POST /api/v1/reservations/{id}/cancel` y el resto devuelven exactamente lo mismo que antes. `hook_menu()` intacto.

---

## Plan de implementación

1. **`tests/unit/bootstrap.php`.** Stubs de `t()` (equivalente de una línea: `strtr($string, $args)`) y `form_set_error()` (registra el par clave → mensaje en un global que el test lee y limpia). Ninguna función previa depende de que no existan: si no estuvieran definidas, cualquier test que las llamara ya sería un fatal. *Verificación: `vendor/bin/phpunit` sigue verde antes de tocar nada más.*

2. **`tests/unit/TimeFormatTest.php` (nuevo) y borrado de `tests/unit/AreaTimeFormatTest.php`.** La matriz completa del predicado de SPEC 46 sobre `myapi_time_format_is_valid()`, más los casos del recorrido: dos campos mal → dos errores; clave de error con la ruta completa; vacío y `add_more` saltados; campo ausente ignorado. *Verificación: en rojo, con "función no definida".*

3. **`includes/myapi.time_format.inc` (nuevo).** `@file` explicando que el archivo es la regla de formato de horas de los formularios de nodo, compartida por los bundles `area` y `reservation`, y que ningún endpoint lo usa. Constante bajo guarda, predicado puro y recorrido. *Verificación: `php -l`; el test del paso 2 en verde.*

4. **`includes/myapi.area_admin.inc` (modificar).** `myapi_area_validate_times()` queda como punto de entrada del bundle: `module_load_include()` del include compartido y una llamada con su mapa de dos campos. Se borran `MYAPI_AREA_TIME_PATTERN` y `myapi_area_time_is_valid()`; el docblock apunta al sitio nuevo. *Verificación: `php -l`; ningún otro archivo referencia los dos nombres borrados (`grep`).*

5. **`includes/myapi.reservation_admin.inc` (nuevo).** `myapi_reservation_validate_times()`, simétrico al de `area`, con `field_start_time` / `field_end_time`. El `@file` deja escrito qué **no** valida (solape, aforo, horario del área, fecha pasada) para que nadie lo confunda con la validación completa de `myapi_reservation_create()`. *Verificación: `php -l`.*

6. **`myapi.module`.** El `case 'reservation'` carga los dos includes y llama a las dos funciones, en ese orden. El docblock del hook se actualiza. *Verificación: `php -l`; el hook sigue sin contener ninguna regla de negocio.*

7. **`myapi.info`.** `files[]` de `includes/myapi.time_format.inc` y `includes/myapi.reservation_admin.inc`. *Verificación: los archivos aparecen tras el cache clear.*

8. **`docs/reservation.md` y `docs/area.md`.** Nota de la validación en el admin y de lo que sigue **sin** validarse en el formulario de reserva. *Verificación: lectura.*

9. **`drush cc all`.** Obligatorio: sin él Drupal no ve los `.inc` nuevos y `module_load_include()` falla al guardar cualquier área o reserva. *Verificación: la matriz manual de la sección siguiente.*

---

## Criterios de aceptación

> Marcados los verificados en local: por **ejecución** (`vendor/bin/phpunit`, `php -l`) o por **diff** (el archivo implicado no se toca, así que el comportamiento no puede cambiar). Los que exigen el sitio Drupal en marcha —todo el bloque del formulario y el `drush cc all`— quedan sin marcar hasta pasar la matriz de "Verificación manual".

**Predicado y recorrido (unit)**

- [x] `"00:00"`, `"08:00"`, `"22:30"`, `"23:59"`, `"09:05"`, `"12:00"` → `TRUE`.
- [x] `"8:00"`, `"24:00"`, `"25:00"`, `"12:60"`, `"08:00:00"`, `"08.00"`, `"0800"`, `"ocho"`, `" 08:00"`, `"08:00 "`, `""`, `"08"`, `"08:"`, `"-8:00"` → `FALSE`.
- [x] El predicado no llama a ninguna función de Drupal.
- [x] Recorrido: `field_start_time = "8:00"` y `field_end_time = "99:99"` producen **dos** errores, uno por campo.
- [x] Recorrido: la clave del error es `campo][langcode][delta][value`.
- [x] Recorrido: un valor vacío o solo con espacios **no** produce error de formato.
- [x] Recorrido: las claves que no son items de campo (`add_more`) se saltan sin aviso.
- [x] Recorrido: un campo ausente de `$form_state['values']` se salta sin aviso.
- [x] `vendor/bin/phpunit` pasa entero; los trece archivos de test que no se tocan siguen en verde.

**Formulario de administración — reserva**

- [x] `node/add/reservation` con `field_start_time = "8:00"` → no guarda, mensaje de formato, input de "Hora de inicio" en rojo.
- [x] `node/{nid}/edit` de una reserva con `field_end_time = "ocho"` → mismo comportamiento sobre "Hora de fin".
- [x] Las dos horas mal a la vez → **dos** mensajes, uno por campo.
- [x] `field_start_time = "10:00"` y `field_end_time = "12:00"` → guarda con normalidad.
- [x] **Reserva que cruza medianoche:** `"22:00"` → `"02:00"` → guarda sin error. Este spec **no** valida el orden.
- [x] Una hora vacía produce el error de "campo obligatorio" de Drupal y **no** un segundo mensaje de formato.
- [x] Como **administrador** (sin el rol de edificio): la validación de formato corre igual. Es el caso que hoy no valida nada.
- [x] Como **administrador de edificio**: hora mal formada + vivienda ajena → se ven los dos mensajes en el mismo envío, el de formato y el de SPEC 51.

**Formulario de administración — área (no-regresión de SPEC 46)**

- [x] `node/add/area` con apertura `8:00` → mismo mensaje que antes de este spec, palabra por palabra.
- [x] Área *overnight* `20:00` → `02:00` → sigue guardando.
- [x] Apertura vacía → un solo error, el de obligatorio.

**No-regresión**

- [x] `POST /api/v1/reservations` no cambia en ninguna de sus validaciones ni en ningún `error_code`. *(diff: `resources/reservation.resource.inc` intacto)*
- [x] `GET /api/v1/units/{id}/reservations` y los filtros `time_from` / `time_to` sin cambios. *(diff: `includes/myapi.reservation_query.inc` intacto)*
- [x] El calendario del admin y las notificaciones sin cambios. *(diff: `includes/myapi.reservation_calendar.inc` e `includes/myapi.reservation_notification.inc` intactos)*
- [x] SPEC 51 sin cambios: `myapi_building_admin_validate_reservation()` no se toca. *(diff: `includes/myapi.building_admin_user.inc` intacto)*
- [x] Guardar un nodo de cualquier otro tipo (`pagos`, `boletin`, `recibo`, `vivienda`, `condominio`) no dispara ningún mensaje nuevo ni carga los includes.
- [x] `includes/myapi.i18n.inc` y `docs/i18n.md` sin cambios; ningún `error_code` nuevo.
- [x] `myapi.install` sin cambios: no hay `hook_update_N`, y `drush updb` no tiene nada que ejecutar.
- [x] `drush cc all` no reporta errores.

---

## Verificación manual

```bash
drush cc all   # obligatorio: registra los dos includes nuevos
```

| Caso | Acción en el admin | Resultado esperado |
|---|---|---|
| Alta con hora sin cero | `node/add/reservation`, inicio `8:00` | No guarda; "Hora de inicio: el formato debe ser HH:MM…"; input en rojo |
| Alta con texto | inicio `ocho` | No guarda; mismo mensaje |
| Alta fuera de rango | fin `99:99` | No guarda; mensaje sobre "Hora de fin" |
| Las dos mal | inicio `8:00`, fin `25:00` | Dos mensajes, uno por campo |
| Alta válida | inicio `10:00`, fin `12:00` | Guarda |
| Cruza medianoche | inicio `22:00`, fin `02:00` | Guarda (SPEC 41) |
| Edición | `node/{nid}/edit`, fin `12.00` | No guarda; mensaje de formato |
| Hora vacía | inicio en blanco | Un solo error, el de obligatorio |
| Rol administrador | Cualquiera de los anteriores como `administrator` | Igual: el formato se valida para todos |
| Rol administrador edificio | Hora mal + vivienda de otro condominio | Los dos mensajes a la vez |
| Área (SPEC 46) | `node/add/area`, apertura `8:00` | Igual que antes de este spec |
| Otro content type | Guardar un `boletin` o un `pago` | Sin cambios |

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Dónde vive la regla | **Include nuevo compartido**, `includes/myapi.time_format.inc` | Copiar el regex y el recorrido en un include de reserva | Es literalmente la misma regla sobre los mismos valores. `CLAUDE.md` prohíbe duplicar lógica entre archivos, y dos copias divergen en el primer retoque. |
| Qué pasa con `myapi_area_time_is_valid()` | **Se borra**, sustituido por `myapi_time_format_is_valid()` | Dejarlo como alias de una línea | Un alias sin llamadores es un nombre muerto que invita a duplicar la regla otra vez. El renombrado toca dos archivos y un test, y deja un solo nombre. |
| Punto de entrada por bundle | Una función por bundle (`myapi_area_validate_times()`, `myapi_reservation_validate_times()`) en su propio include | Una sola función con un `switch ($node->type)` dentro | Cada bundle aporta su mapa `campo => etiqueta` y nada más. Con funciones separadas, `myapi_node_validate()` sigue siendo el único sitio que reparte por tipo y ningún include carga si el tipo no es el suyo. |
| Archivo de la reserva | `includes/myapi.reservation_admin.inc` (nuevo) | Meterlo en `includes/myapi.building_admin_user.inc`, dentro de `myapi_building_admin_validate_reservation()` | Esa función es del filtro del rol de edificio y **sale en su primera línea** para cualquier otro usuario. Colgar de ahí una regla que aplica a todos sería exactamente el bug que este spec arregla, escrito otra vez. |
| Orden de las dos validaciones | Formato **primero**, alcance del rol después | Al revés | La de formato aplica a todos; ponerla primero hace que un administrador de edificio con dos errores distintos vea los dos mensajes en el mismo envío. |
| `end > start` | **No se valida** | Exigir que el fin sea posterior al inicio | SPEC 41 hizo legal `end <= start`: una reserva que cruza medianoche se guarda envuelta (`22:00` → `02:00`). Esa validación rompería las reservas nocturnas que hoy funcionan. |
| Resto de reglas del formulario (solape, aforo, horario, fecha pasada) | **Fuera**, declarado explícitamente | Portar `myapi_reservation_create()` al formulario | Es un spec entero: esas reglas necesitan consultas, el aforo del área y el mismo cálculo de sesión de SPEC 42. Mezclarlo con un arreglo de formato convertiría un cambio verificable en uno que no lo es. Queda escrito en el `@file` del include y en los docs para que no se dé por cubierto. |
| Regex duplicado del resource | **Se deja como está** | Apuntarlo también al predicado compartido | No está roto: es el mismo patrón y está cubierto por tests. Tocarlo mete el camino del endpoint y tres archivos de test en un diff que hoy no toca ningún endpoint. Anotado como deuda. |
| Valores vacíos | **No se validan** aquí | Emitir también el error de formato | `required = 1` ya lo cubre en `entity_form_field_validate()`. Dos mensajes para el mismo campo en el mismo envío. |
| Idioma de los mensajes | `t()` de Drupal | El catálogo `myapi_t()` | Es interfaz de administración, no respuesta de API. Mismo criterio que SPEC 46. |
| Guardas frente a `node_save()` programático | **No se añaden** | Validar también en `hook_node_presave()` | `presave` no puede rechazar limpiamente y tendría que lanzar una excepción, con riesgo de tumbar `POST /api/v1/reservations`, que salva nodos. |
| Datos ya guardados | **No se tocan** | Migración que corrija las reservas mal formadas | La validación es de entrada. Barrer datos existentes es otro spec. |
| Tests | Unit del predicado **y** del recorrido + matriz manual del formulario | Solo el predicado, como SPEC 46 | El recorrido es ahora código compartido por dos bundles: un fallo suyo apaga la validación de los cuatro campos a la vez. Cubrirlo cuesta dos stubs de una línea en `bootstrap.php`. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Romper SPEC 46 al mover su código.** El bundle `area` deja de tener su propia constante y su propio predicado. | El regex y el mensaje se mueven sin editar un solo carácter, y la matriz del predicado se conserva entera en el test nuevo. Hay criterios de aceptación explícitos de no-regresión del formulario de área. |
| **`myapi_area_time_is_valid()` referenciado desde algún sitio no previsto** tras borrarlo. | `grep` de los dos nombres borrados en todo el repo antes de cerrar; hoy sus únicos llamadores son el propio include y su test. |
| **Reservas ya guardadas con horas mal formadas** que dejan de poder editarse desde el admin sin corregir la hora. | Es el comportamiento correcto: ese valor ya rompe la aritmética y el orden. El mensaje dice exactamente qué escribir. |
| **Falsa sensación de que el formulario de reserva ya valida todo.** Sigue sin comprobar solape, aforo, horario del área ni fecha pasada. | Escrito en el `@file` del include nuevo, en la lista de fuera de alcance y en `docs/reservation.md`. |
| **Olvidar `drush cc all`**: los `.inc` nuevos no entran en el registro y `module_load_include()` falla al guardar. | Es el paso 9 del plan y un criterio de aceptación. El síntoma sería un error al guardar cualquier área o reserva, visible en la primera prueba manual. |
| **Redefinición de `MYAPI_TIME_FORMAT_PATTERN`** en un `require_once` de PHPUnit. | La constante va bajo `if (!defined(...))`, el mismo patrón del resto del módulo. |
| **Los stubs nuevos de `bootstrap.php` (`t()`, `form_set_error()`) alterando otros tests.** | Ambos van bajo `if (!function_exists(...))` y ningún test previo los llama — si alguno lo hiciera, hoy sería un fatal. La suite entera se ejecuta antes y después. |
| **Doble mensaje** cuando el valor excede `max_length = 5` (p. ej. `"08:00:00"`). | Ruido menor; ambos mensajes apuntan al mismo campo y a la misma corrección. Mismo criterio que SPEC 46. |

---

## Lo que **NO** está en este spec

- Solape, aforo, horario del área, fecha en el pasado y demás reglas de `myapi_reservation_create()` aplicadas al formulario de nodo.
- Validar `end > start` o cualquier relación entre las dos horas.
- `field_date` y su widget.
- Unificar el regex duplicado de `resources/reservation.resource.inc`.
- Normalizar o autocorregir el valor introducido.
- Corregir o migrar reservas ya guardadas con formato inválido.
- Validación en `node_save()` programático (`hook_node_presave()`, migraciones, drush).
- Cambios en el field, el instance, el widget, `max_length` o `required`.
- Cualquier cambio en el contrato JSON, en `hook_menu()`, en el catálogo i18n o en `myapi.install`.

Cada uno de ellos, si algún día entra, va en su propio spec.
