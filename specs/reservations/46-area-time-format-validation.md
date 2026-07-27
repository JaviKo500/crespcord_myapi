# SPEC 46 — Validación de formato HH:MM de los horarios del área en el admin

> **Estado:** Implemented · **Depende de:** SPEC 32 (`field_open_time` / `field_close_time` como `text` con `max_length = 5` en el bundle `area`), SPEC 41 (áreas que cierran tras medianoche: `close <= open` es legal) · **Fecha:** 2026-07-27
> **Objetivo:** Impedir que un nodo `area` se guarde desde el formulario de administración de Drupal con `field_open_time` o `field_close_time` en un formato distinto de `HH:MM` (24 h), mediante `hook_node_validate()` delegado a un include nuevo, sin tocar ningún endpoint, ni el esquema, ni el contrato JSON de la API.

---

## Alcance

**Dentro:**

- **`includes/myapi.area_admin.inc`** (nuevo) — validación del formulario de nodo para el bundle `area`:
  - Constante `MYAPI_AREA_TIME_PATTERN` con el regex de `HH:MM` en 24 h, definida bajo guarda `if (!defined(...))`.
  - `myapi_area_time_is_valid($value)` — predicado **puro** (sin Drupal, sin BD), único sitio donde vive la regla de formato.
  - `myapi_area_validate_times($node, $form, &$form_state)` — recorre los valores de los dos campos en `$form_state['values']` y llama a `form_set_error()` con la ruta del elemento por cada valor mal formado.

- **`myapi.module`** (modificar) — `myapi_node_validate()` como **pegamento**: guarda de tipo `$node->type !== 'area'` → `return`, `module_load_include()` del include nuevo y una única llamada a `myapi_area_validate_times()`. Mismo patrón que los `myapi_node_presave/insert/update` ya existentes.

- **`myapi.info`** (modificar) — `files[] = includes/myapi.area_admin.inc`.

- **`tests/unit/AreaTimeFormatTest.php`** (nuevo) — cobertura de `myapi_area_time_is_valid()`, escrito **antes** de la lógica.

- **`docs/area.md`** (modificar) — nota en la sección de mapeo de campos indicando que `open_time` / `close_time` están validados como `HH:MM` en el admin y que la API los sigue devolviendo crudos.

- **`drush cc all`** al final (no hay `drush updb`: no hay campo, tabla ni `hook_update_N` nuevos).

**Fuera de alcance (para futuros specs):**

- **Cualquier endpoint de escritura de áreas.** Las áreas se siguen editando solo desde el admin de Drupal (mismo criterio que SPEC 44 y SPEC 45). Por eso esta validación **no** necesita `error_code` ni clave i18n.
- **Validar la relación entre los dos campos** (`close > open`, `close != open`, duración mínima de la ventana). SPEC 41 hace de `close <= open` un caso legal y deliberado (área que cierra tras medianoche); una validación de orden rompería esas áreas.
- **`field_start_time` / `field_end_time` del bundle `reservation`.** Esos valores los escribe siempre `myapi_reservation_create()`, ya validados y formateados por el servidor; el formulario de nodo de una reserva no es la vía de entrada real.
- **Normalizar el valor** (`"9:00"` → `"09:00"`, recortar espacios y guardar, aceptar `HH.MM` o `HHMM`). El campo se rechaza, no se corrige.
- **Migrar o corregir datos ya guardados** con formato inválido. La validación aplica a partir del siguiente guardado del nodo.
- **Cambiar `max_length`, `required`, el widget o cualquier otra propiedad del field/instance.** El esquema de SPEC 32 queda intacto.
- **Validaciones programáticas** (`node_save()` directo, migraciones, `drush php-eval`). `hook_node_validate()` solo corre en el flujo del formulario, y eso es exactamente lo pedido.
- **Parseo o defensa nueva en la API de lectura.** `myapi_area_build_item()` sigue devolviendo `open_time` / `close_time` crudos; `myapi_reservation_time_to_minutes()` sigue siendo la única defensa del lado de reservas.

---

## Modelo de datos

**No hay datos nuevos.** No se crean tablas, ni campos, ni instances, ni `hook_schema()`, ni `hook_update_N`. `field_open_time` y `field_close_time` siguen siendo exactamente lo que definió SPEC 32:

| Campo | Tipo | Bundle | Requerido | Widget | Settings |
|---|---|---|---|---|---|
| `field_open_time` | `text` | `area` | Sí | `text_textfield` | `max_length = 5` |
| `field_close_time` | `text` | `area` | Sí | `text_textfield` | `max_length = 5` |

Lo que este spec añade es una **regla de formato** sobre esos valores, aplicada en el momento del guardado desde el formulario.

### Regla de formato

```php
if (!defined('MYAPI_AREA_TIME_PATTERN')) {
  define('MYAPI_AREA_TIME_PATTERN', '/^([01][0-9]|2[0-3]):[0-5][0-9]$/');
}
```

Vive en **una sola función**, `myapi_area_time_is_valid($value)`, que es la única que conoce el regex:

```
myapi_area_time_is_valid($value) = (bool) preg_match(MYAPI_AREA_TIME_PATTERN, $value)
```

| Entrada | Válido | Motivo |
|---|---|---|
| `"00:00"` | Sí | Medianoche |
| `"08:00"`, `"22:30"`, `"23:59"` | Sí | Casos normales |
| `"8:00"` | **No** | Hora sin cero a la izquierda |
| `"24:00"` | **No** | Fuera del reloj de 24 h; el fin de día se escribe `23:59` o el área es *overnight* |
| `"25:00"`, `"12:60"` | **No** | Hora o minuto fuera de rango |
| `"08:00:00"` | **No** | Segundos; además excede `max_length = 5` |
| `"08.00"`, `"0800"`, `"ocho"`, `" 08:00"` | **No** | Separador, longitud o contenido |
| `""` | **No lo evalúa** | Se salta antes de llamar al predicado (ver abajo) |

**El vacío no se valida aquí.** Un valor vacío o solo con espacios se salta: la propiedad `required = 1` del instance ya produce el error "campo obligatorio" en `entity_form_field_validate()`, y duplicarlo daría dos mensajes para el mismo campo en el mismo envío.

**El `trim()` es solo para decidir si está vacío.** El valor que se compara contra el regex es el crudo, así que `" 08:00"` se rechaza en vez de aceptarse y guardarse con el espacio delante.

### Recorrido de los valores del formulario

`myapi_area_validate_times()` no asume `LANGUAGE_NONE` ni `delta` 0: recorre lo que haya en `$form_state['values'][$field_name]`, saltando las claves que no sean items de campo (el widget mete cosas como `add_more`):

```
para cada campo en [field_open_time, field_close_time]:
  si no hay valores para el campo → siguiente
  para cada langcode → deltas:
    si $deltas no es array → siguiente
    para cada delta → item:
      si $item no es array o no tiene 'value' → siguiente   # descarta 'add_more' y similares
      si trim($item['value']) === '' → siguiente            # lo cubre required
      si !myapi_area_time_is_valid($item['value']):
        form_set_error("{$field_name}][{$langcode}][{$delta}][value", mensaje)
```

La clave que se pasa a `form_set_error()` usa la sintaxis de ruta de elemento de Drupal 7 (`campo][langcode][delta][value`), que es la que hace que se marque en rojo **el input concreto**, no solo el mensaje de la cabecera.

### Mensajes

Son mensajes de **back office**, no del envelope JSON de la API. No entran en el catálogo `includes/myapi.i18n.inc` ni en `docs/i18n.md`: ese catálogo existe para resolver `error` / `message` por `Accept-Language` en las respuestas de la API, y aquí no hay respuesta de API. Se emiten con `t()`, que es la vía estándar de Drupal para el idioma de la interfaz:

```
@label: el formato debe ser HH:MM en 24 horas (por ejemplo 08:00 o 22:30).
```

Con `@label` = "Hora de apertura" / "Hora de cierre", los mismos labels que SPEC 32 dio a los instances.

### Sin cambios en el contrato de la API

`GET /api/v1/condominiums/{id}/areas`, `GET /api/v1/areas/{id}` y `GET /api/v1/areas/{id}/availability` devuelven exactamente lo mismo que antes, con las mismas claves y los mismos valores crudos. Ningún `error_code` nuevo, ninguna clave i18n nueva, `hook_menu()` intacto.

---

## Plan de implementación

Los pasos 1 y 3 forman un bloque TDD: el 1 deja el unit test **en rojo** a propósito (la función aún no existe) y el 3 lo pone en verde. Si se prefieren commits siempre verdes, se agrupan en uno solo.

1. **`tests/unit/AreaTimeFormatTest.php` (nuevo).** Escrito **antes** de la lógica, en el estilo de `AuthBearerTest.php`: `require_once` directo de `includes/myapi.area_admin.inc` y cobertura de la matriz de la sección anterior. *Verificación: `vendor/bin/phpunit` — en rojo, con "función no definida".*

2. **`includes/myapi.area_admin.inc` (nuevo) — cabecera y predicado.** `@file` explicando que el archivo contiene la validación del formulario de nodo del bundle `area` y que no lo toca ningún endpoint. Constante `MYAPI_AREA_TIME_PATTERN` bajo guarda `if (!defined(...))` — la guarda es lo que permite que PHPUnit haga `require_once` del archivo sin avisos de redefinición. `myapi_area_time_is_valid($value)` con docblock que enumere por qué `"24:00"` y `"8:00"` se rechazan. *Verificación: `php -l includes/myapi.area_admin.inc`; el test del paso 1 pasa en verde.*

3. **`includes/myapi.area_admin.inc` — el recorrido.** `myapi_area_validate_times($node, $form, &$form_state)` con el algoritmo de la sección anterior. Docblock explicando: que los vacíos los cubre `required`, por qué el `trim()` es solo para el chequeo de vacío, y por qué la clave de `form_set_error()` lleva la ruta completa del elemento. *Verificación: `php -l`.*

4. **`myapi.module` — `myapi_node_validate()`.** Hook nuevo tras `myapi_node_update()`, con el mismo docblock "Glue only" del resto: guarda de tipo, `module_load_include('inc', 'myapi', 'includes/myapi.area_admin')` y la llamada. Dejar escrito en el docblock que este hook **solo** corre en el flujo del formulario de nodo, no en un `node_save()` programático. *Verificación: `php -l myapi.module`; la función no contiene ninguna regla de negocio.*

5. **`myapi.info`.** `files[] = includes/myapi.area_admin.inc` al final de la lista. *Verificación: el archivo aparece en el registro tras el cache clear.*

6. **`docs/area.md`.** Nota bajo la tabla de mapeo de campos: `open_time` / `close_time` se validan como `HH:MM` (24 h) en el formulario de administración desde este spec; la API los sigue devolviendo crudos y no valida el formato en lectura, así que un valor anterior mal formado se sigue entregando tal cual hasta que se reguarde el nodo. *Verificación: lectura.*

7. **`drush cc all`.** Obligatorio: sin él Drupal no ve el `.inc` nuevo del `files[]` y el hook no llega a cargarse. *Verificación: la matriz manual de la sección siguiente.*

---

## Criterios de aceptación

> Marcados los verificados en local: por **ejecución** (`vendor/bin/phpunit`, `php -l`) o por **diff** (el archivo implicado no se toca, así que el comportamiento no puede cambiar). Los que exigen el sitio Drupal en marcha —todo el bloque del formulario y el `drush cc all`— quedan sin marcar hasta pasar la matriz de "Verificación manual".

**Predicado puro (unit)**

- [x] `"00:00"`, `"08:00"`, `"22:30"`, `"23:59"` → `TRUE`.
- [x] `"8:00"`, `"24:00"`, `"25:00"`, `"12:60"`, `"08:00:00"`, `"08.00"`, `"0800"`, `"ocho"`, `" 08:00"`, `"08:00 "`, `""` → `FALSE`.
- [x] El predicado no llama a ninguna función de Drupal y el test no necesita más stub que el `bootstrap.php` actual.
- [x] `vendor/bin/phpunit` pasa entero; los seis archivos de test previos siguen en verde **sin haber sido modificados**.

**Formulario de administración**

- [x] `node/add/area` con `field_open_time = "8:00"` → el formulario **no** se guarda, muestra el mensaje de formato y marca el input de "Hora de apertura" en rojo.
- [x] `node/{nid}/edit` de un área con `field_close_time = "24:00"` → mismo comportamiento sobre "Hora de cierre".
- [x] Los dos campos mal formados a la vez producen **dos** mensajes, uno por campo.
- [x] `field_open_time = "08:00"` y `field_close_time = "22:00"` → el nodo se guarda con normalidad.
- [x] **Área *overnight*:** `field_open_time = "20:00"` y `field_close_time = "02:00"` → se guarda sin error. Este spec **no** valida el orden.
- [x] `field_open_time = field_close_time = "08:00"` → se guarda; el orden y la igualdad no son asunto de este spec.
- [x] Un campo vacío produce el error de "campo obligatorio" de Drupal y **no** un segundo mensaje de formato.
- [x] Guardar un nodo de **cualquier otro tipo** (`pagos`, `boletin`, `recibo`, `reservation`, `vivienda`, `condominio`) no dispara ningún mensaje nuevo ni carga el include.

**No-regresión**

- [x] `GET /api/v1/condominiums/{id}/areas` y `GET /api/v1/areas/{id}` devuelven el mismo item de 15 claves de SPEC 45, con `open_time` / `close_time` crudos. *(diff: `resources/area.resource.inc` no se toca)*
- [x] `GET /api/v1/areas/{id}/availability` devuelve las mismas 4 claves (`date`, `capacity`, `busy`, `occupancy`). *(diff: mismo archivo intacto)*
- [x] `POST /api/v1/reservations` no cambia en ninguna de sus 8 validaciones ni en ningún `error_code`. *(diff: `resources/reservation.resource.inc` e `includes/myapi.reservation_query.inc` intactos)*
- [x] Un área ya guardada con un valor mal formado **sigue** devolviéndose por la API tal cual; ninguna lectura empieza a fallar por este cambio. *(diff: no se añade validación en ningún camino de lectura)*
- [x] `hook_menu()` sin cambios en el diff; no hay ruta nueva.
- [x] `includes/myapi.i18n.inc` y `docs/i18n.md` sin cambios; ningún `error_code` nuevo.
- [x] `myapi.install` sin cambios: no hay `hook_update_N`, y `drush updb` no tiene nada que ejecutar.
- [x] `drush cc all` no reporta errores.

---

## Verificación manual

```bash
drush cc all   # obligatorio: registra includes/myapi.area_admin.inc
```

| Caso | Acción en el admin | Resultado esperado |
|---|---|---|
| Alta con hora sin cero | `node/add/area`, apertura `8:00` | No guarda; "Hora de apertura: el formato debe ser HH:MM…"; input en rojo |
| Alta con hora fuera de rango | `node/add/area`, cierre `24:00` | No guarda; mismo mensaje sobre "Hora de cierre" |
| Alta con segundos | apertura `08:00:00` | No guarda; mensaje de formato (más, posiblemente, el de longitud máxima de Drupal) |
| Los dos mal | apertura `8:00`, cierre `25:00` | Dos mensajes, uno por campo |
| Alta válida | apertura `08:00`, cierre `22:00` | Guarda |
| Área *overnight* | apertura `20:00`, cierre `02:00` | Guarda (SPEC 41) |
| Edición | `node/{nid}/edit` de un área existente, cierre `22.00` | No guarda; mensaje de formato |
| Campo vacío | apertura en blanco | Un solo error, el de obligatorio |
| Otro content type | Guardar un `boletin` o un `pago` | Sin cambios respecto a hoy |

Comprobación de que la API no se movió:

```bash
curl -s -H "Authorization: Bearer $TOKEN" "https://<host>/api/v1/areas/34"
```

```json
{ "success": true, "data": { "area": { "open_time": "08:00", "close_time": "22:00", "...": "resto igual" } } }
```

---

## Decisiones

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Dónde engancharse | **`hook_node_validate()`** | `hook_form_BASE_FORM_ID_alter()` + `#element_validate` | Es el hook que Drupal 7 tiene justo para esto. Con `form_alter` habría que navegar el árbol del formulario y filtrar por bundle a mano; el hook ya recibe el nodo y una guarda de una línea basta. |
| Dónde vive la lógica | Include nuevo `includes/myapi.area_admin.inc` | Escribirla dentro de `myapi.module` | Regla 1 de `CLAUDE.md`: el `.module` es routing y pegamento. El hook queda en 4 líneas, igual que `myapi_node_presave()`. |
| Nombre del include | `myapi.area_admin.inc` | `myapi.area_validate.inc`, o meterlo en `resources/area.resource.inc` | El archivo es "lo que el bundle `area` hace en el back office", no lógica de un endpoint. Meterlo en el resource cargaría código de formularios en cada petición HTTP de la API y mezclaría dos responsabilidades en un archivo que ya es grande. |
| Regla de formato | Un **predicado puro** aislado, `myapi_area_time_is_valid()` | Regex inline dentro del recorrido | Es lo que hace testeable el cambio sin Drupal, y deja la regla en un solo sitio si algún día la reutiliza otro bundle. Mismo criterio que las funciones puras de SPEC 45. |
| Rango aceptado | `00:00`–`23:59`, con cero a la izquierda obligatorio | Aceptar `8:00` y `24:00` | Los valores se comparan y se convierten a minutos en `myapi_reservation_time_to_minutes()`; un formato único y de longitud fija es lo que hace que esa aritmética y las comparaciones de cadena del resto del módulo sean predecibles. `24:00` además es ambiguo: el cierre a fin de día se escribe `23:59`, y si de verdad pasa de medianoche el área es *overnight* (SPEC 41). |
| Qué hacer con un valor casi correcto | **Rechazar** | Normalizar (`"9:00"` → `"09:00"`) y guardar | Lo pedido es validar. Normalizar en silencio significa guardar algo distinto de lo que el admin escribió, y abre la pregunta de hasta dónde normalizar (`8`, `08.00`, `8h`). El mensaje de error enseña el formato correcto en un envío. |
| Valores vacíos | **No se validan** aquí | Emitir también el error de formato | `required = 1` ya lo cubre en `entity_form_field_validate()`. Validarlo dos veces da dos mensajes para el mismo campo en el mismo envío. |
| `close > open` | **No se valida** | Exigir que el cierre sea posterior a la apertura | SPEC 41 hizo de `close <= open` un caso legal (área que cierra tras medianoche). Esa validación rompería las áreas *overnight* que hoy funcionan. |
| Recorrido de `$form_state['values']` | Recorrer langcodes y deltas | Leer directo `[LANGUAGE_NONE][0]['value']` | El acceso directo asume idioma y cardinalidad y se rompe en silencio (sin error, sin validación) si alguno cambia. El recorrido cuesta seis líneas y no asume nada. |
| Clave de `form_set_error()` | Ruta completa `campo][langcode][delta][value` | Solo el nombre del campo | Con la ruta completa Drupal marca el input concreto en rojo. Con solo el nombre, el mensaje sale arriba y el admin tiene que adivinar cuál de los dos campos es. |
| Idioma de los mensajes | `t()` de Drupal | El catálogo `myapi_t()` de `includes/myapi.i18n.inc` | El catálogo resuelve `error` / `message` del envelope JSON por `Accept-Language`. Aquí no hay respuesta de API: es la interfaz de administración, y su idioma lo resuelve Drupal. Meter claves de back office en el catálogo de la API mezclaría dos contratos distintos. |
| `error_code` nuevo | **Ninguno** | Añadir uno para el formato inválido | No hay endpoint de escritura de áreas: ninguna respuesta JSON puede devolver este error. Se añadirá si algún día entra ese endpoint, y entonces reusará el mismo predicado. |
| Alcance de los campos | Solo `field_open_time` y `field_close_time` del bundle `area` | Incluir `field_start_time` / `field_end_time` de `reservation` | Los de la reserva los escribe siempre `myapi_reservation_create()`, ya validados y formateados por el servidor. El formulario de nodo de una reserva no es la vía de entrada real, y ampliar el alcance obligaría a razonar sobre nodos que crea la API. |
| Guardas frente a `node_save()` programático | **No se añaden** | Validar también en `hook_node_presave()` | Lo pedido es el panel del admin. `presave` no puede rechazar limpiamente (no hay formulario donde poner el error) y tendría que lanzar una excepción, con riesgo de tumbar migraciones o el propio `POST /api/v1/reservations`, que salva nodos. |
| Datos ya guardados | **No se tocan** | Migración que corrija o marque las áreas mal formadas | La validación es de entrada. Barrer datos existentes es un cambio de otra naturaleza (¿corregir?, ¿despublicar?, ¿avisar?) y va en su propio spec si aparece la necesidad. |
| Tests | Unit sobre el predicado + matriz manual del formulario | Test de integración con `DrupalWebTestCase` | Mismo precedente que SPEC 40–45: se aísla la parte pura y el resto se verifica a mano. La parte con riesgo real (el regex) es exactamente la que queda cubierta. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **`hook_node_validate()` corre para todos los tipos de nodo** y una implementación descuidada afectaría a `pagos`, `boletin`, `recibo` o `reservation`. | La guarda `$node->type !== 'area'` es la **primera** línea del hook y el `module_load_include()` va después, así que ningún otro tipo llega a cargar el archivo. Hay un criterio de aceptación explícito sobre guardar nodos de otros tipos. |
| **Doble mensaje** cuando el valor excede `max_length = 5` (p. ej. `"08:00:00"`): sale el de formato y el de longitud de Drupal. | Es ruido menor y ambos mensajes apuntan al mismo campo y a la misma corrección. Suprimir el nuestro por longitud dejaría al admin con el mensaje genérico de Drupal, que no dice cuál es el formato esperado. |
| **Áreas ya existentes con valores mal formados** que dejan de poder guardarse desde el admin sin corregir el horario. | Es el comportamiento correcto: el formato malo ya rompe la aritmética de reservas. El mensaje dice exactamente qué escribir, y `docs/area.md` avisa de que los datos antiguos se siguen sirviendo hasta que se reguarde el nodo. |
| **Un `node_save()` programático sigue pudiendo escribir basura** (migración, `drush php-eval`, un futuro endpoint de escritura). | Está declarado fuera de alcance y escrito en el docblock del hook. Cuando entre un endpoint de escritura, reusará `myapi_area_time_is_valid()` en vez de duplicar el regex. |
| **Olvidar `drush cc all`**: el `.inc` nuevo no entra en el registro y el hook falla al cargarlo. | Es el paso 7 del plan y un criterio de aceptación. El síntoma sería un error al guardar cualquier área, muy visible en la primera prueba manual. |
| **Redefinición de `MYAPI_AREA_TIME_PATTERN`** si PHPUnit hace `require_once` del archivo junto a otro que ya la definió. | La constante va bajo `if (!defined(...))`, el mismo patrón que `MYAPI_ONESIGNAL_QUEUE` en `myapi.module`. |
| **El widget mete claves que no son items** (`add_more`) y un recorrido ingenuo intentaría validarlas. | El recorrido comprueba `is_array($item) && isset($item['value'])` antes de mirar nada. Con `cardinality = 1` hoy no aparecen, pero la guarda cuesta una línea. |
| **Regex demasiado estricto** que rechace un formato legítimo que el admin usaba. | La matriz de la sección "Regla de formato" enumera caso por caso qué entra y qué no, y el unit test la cubre entera. Los únicos rechazos discutibles (`8:00`, `24:00`) están razonados en Decisiones. |

---

## Lo que **NO** está en este spec

- Endpoints de escritura de áreas (alta, edición, borrado por API).
- Validar `close > open` o cualquier relación entre los dos campos.
- `field_start_time` / `field_end_time` del bundle `reservation`.
- Normalizar o autocorregir el valor introducido.
- Corregir o migrar áreas ya guardadas con formato inválido.
- Validación en `node_save()` programático (`hook_node_presave()`, migraciones, drush).
- Cambios en el field, el instance, el widget, `max_length` o `required`.
- Cualquier cambio en el contrato JSON, en `hook_menu()`, en el catálogo i18n o en `myapi.install`.

Cada uno de ellos, si algún día entra, va en su propio spec.
