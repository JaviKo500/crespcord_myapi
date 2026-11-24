# SPEC 134 — Selectores dependientes en el formulario de reserva del back office

> **Estado:** Implemented (verificada manualmente en producción el 2026-11-24) · **Depende de:** SPEC 32 (tipos de contenido de reservas), SPEC 49 y 51 (rol `administrador edificio`), SPEC 52 (validación de horas), SPEC 53 (ajustes de `entityreference`) · **Fecha:** 2026-11-24
>
> **Objetivo:** Que en `node/add/reservation` y `node/{nid}/edit`, al elegir el **Condominio**, los campos **Vivienda** y **Área** solo ofrezcan los de ese condominio, y que **Solicitante** solo ofrezca al propietario y a los ocupantes de la **Vivienda** elegida. Los campos dependientes están bloqueados hasta que su padre tiene valor.

---

## Problema

Los cuatro campos de referencia del formulario son autocompletados de `entityreference` independientes. Lo único que los acota es el rol: un `administrador edificio` solo ve lo de sus condominios (SPEC 49/51), pero un usuario `backend` ve viviendas, usuarios y áreas de **todo el sitio**, elija el condominio que elija.

Además, nada impedía guardar una reserva incoherente: una vivienda de otro condominio, un área de otro condominio o un solicitante que no vive en la vivienda.

## Alcance

**Dentro:**

- **`includes/myapi.reservation_form.inc`** (nuevo): la cadena de selectores, el `form alter`, el *callback* de autocompletado con sus consultas y la validación al guardar.
- **`js/myapi.reservation_form.js`** (nuevo): reapunta, vacía y bloquea/desbloquea los selectores.
- **`includes/myapi.response.inc`** (modificar): `myapi_respond_autocomplete()`.
- **`myapi.module`** (modificar): la ruta, `myapi_reservation_form_access()`, `hook_form_reservation_node_form_alter()` y la rama `reservation` de `hook_node_validate()`. Solo pegamento.
- **`myapi.info`** (modificar): un `files[]`.
- **`tests/unit/ReservationFormTest.php`** (nuevo), **`ResponseEnvelopeTest.php`** y **`ModuleContractTest.php`** (modificar: la ruta entra en `NON_VERSIONED_PATHS`).
- **`docs/reservation-form.md`** (nuevo) y **`manual_backoffice/guia/04_reservas.md`** (modificar).

**Fuera de alcance:**

- Disponibilidad: solapamiento, cupo simultáneo, horario del área y fecha pasada siguen validándose solo en `POST /api/v1/reservations`. El hueco descrito en `includes/myapi.reservation_admin.inc` sigue igual de abierto.
- `POST /api/v1/reservations` y cualquier otro endpoint `api/v1/...`.
- El formulario de `area` y los filtros del calendario y del listado.
- Cambios de schema o `hook_update_N()`.
- Las capturas del manual (`ae_reserva_nueva.png`, `ae_reserva_llena.png`): hay que rehacerlas cuando esté en staging.

---

## Diseño

### La cadena

```
condominio ─┬─> vivienda ──> solicitante
            └─> área
```

| Selector | Depende de | Ofrece |
|---|---|---|
| Vivienda (`field_unit`) | Condominio | Nodos `vivienda` publicados con ese `field_condominio` |
| Área (`field_area`) | Condominio | Nodos `area` publicados con ese `field_condominium` |
| Solicitante (`field_requester`) | Vivienda | Usuarios activos en `field_propietario`, `field_ocupante` o `field_ocupantes` de esa vivienda |

El solicitante se busca **solo por nombre de usuario** y la sugerencia no muestra nada más. Ver la decisión 7.

### Tres capas

1. **Formulario.** El `form alter` marca los cuatro inputs con `data-myapi-reservation-selector` y cambia la ruta de autocompletado de los tres dependientes por la del módulo. Si alguno de los cuatro no es un `entityreference_autocomplete` de un solo valor, no toca nada.
2. **Sugerencias.** `admin/content/reservations/autocomplete/{selector}/{padre}/{texto}`, acotada al id del padre que viaja en la ruta. Las consultas de nodos llevan la etiqueta `node_access`, así que el recorte por rol de SPEC 49 se aplica encima.
3. **Guardado.** `myapi_reservation_form_validate_scope()` desde `hook_node_validate()`, para **todos** los roles.

### JavaScript

`misc/autocomplete.js` lee la URL una sola vez y la guarda en un cierre inaccesible. Para cambiarla sin parchear el core, el script hace lo que haría un reemplazo por AJAX: sustituye el input por un clon sin manejadores, escribe la URL nueva en el input oculto, le quita la marca `once()` y deja que el *behavior* del core se vuelva a enganchar.

- Reacciona a `autocompleteSelect`, `change` y `blur` del padre.
- El id del padre se lee del `(123)` final del valor. Un texto escrito a mano sin elegir sugerencia no tiene id: los hijos siguen bloqueados.
- Al cargar la página no vacía nada: un formulario de edición, o uno devuelto con errores, llega relleno.
- Los dependientes **no** se renderizan con `#disabled` en PHP: Drupal descartaría el valor enviado.

### Validación al guardar

| Regla | Campo marcado | Mensaje |
|---|---|---|
| La vivienda es del condominio | Vivienda | *La vivienda no pertenece al condominio seleccionado.* |
| El área es del condominio | Área | *El área no pertenece al condominio seleccionado.* |
| El solicitante es propietario u ocupante de la vivienda | Solicitante | *El solicitante debe ser propietario u ocupante de la vivienda seleccionada.* |

Un extremo vacío no se reporta: los cuatro campos son obligatorios y Drupal ya lo dice.

**En edición solo se valida si cambió alguna de las cuatro referencias.** Cancelar una reserva antigua no puede fallar porque el solicitante ya no viva en la vivienda.

### Respuesta del autocompletado

No es el sobre de la API: el widget del core exige un objeto JSON plano `valor => HTML`. Se imprime desde `myapi_respond_autocomplete()`, en `includes/myapi.response.inc`, para que siga siendo cierto que toda respuesta sale de un helper y para que lleve las cabeceras `no-store`.

### Acceso a la ruta

Por **permiso**, no por rol: `create reservation content`, `edit any reservation content` o `edit own reservation content`. Quien puede abrir el formulario es quien puede preguntarle.

---

## Criterios de aceptación

Automáticos (`vendor/bin/phpunit`):

- [x] `ReservationFormTest`: cadena, clave de sugerencia, detección de cambio y regla de coherencia; y, sobre los stubs, el `form alter`, el *callback* de autocompletado con sus consultas (bundle, condominio, `node_access`, tope de 10, escapado), la validación al guardar (alta, edición sin cambios, edición con cambios) y el acceso a la ruta.
- [x] `ResponseEnvelopeTest`: `myapi_respond_autocomplete()` responde un objeto plano, `{}` cuando no hay sugerencias, con `no-store`.
- [x] `ModuleContractTest`: ruta declarada, con *callback*, acceso y fichero en `myapi.info`.

Manuales, en staging tras `drush cc all`:

- [x] `node/add/reservation` como `backend`: Vivienda, Solicitante y Área aparecen bloqueados con su texto de ayuda. — *Visto en producción el 2026-11-24 con PRUEBAS MOTORA (16533).* *No se comprobó el rol de la sesión usada.*
- [x] Al elegir Condominio se desbloquean Vivienda y Área, y solo sugieren los de ese condominio. — *Visto en producción el 2026-11-24 con PRUEBAS MOTORA (16533).*
- [x] Al elegir Vivienda se desbloquea Solicitante y solo sugiere a su propietario y ocupantes, buscando por nombre de usuario; un texto que solo coincide con un correo no devuelve nada. — *Producción, 2026-11-24: visto el desbloqueo y que la vivienda 30057 solo ofrece a sus dos miembros; la mitad del correo la comprobó el propietario.* — *Verificado por el propietario en producción el 2026-11-24.*
- [x] Al cambiar el Condominio se vacían Vivienda, Solicitante y Área; al cambiar la Vivienda se vacía el Solicitante. — *Visto en producción el 2026-11-24 con PRUEBAS MOTORA (16533).*
- [x] Guardar una reserva coherente funciona como antes. — *Verificado por el propietario en producción el 2026-11-24.*
- [x] `node/{nid}/edit` de una reserva existente llega con los cuatro campos rellenos y desbloqueados. — *Visto en producción el 2026-11-24 en `node/537508/edit`, sin guardar.*
- [x] Como `administrador edificio`: mismos comportamientos, y `…/autocomplete/unit/{condominio ajeno}/a` responde `{}`. — *Verificado por el propietario en producción el 2026-11-24.*
- [x] Con el tema del back office (Bootstrap) el icono del autocompletado sigue apareciendo y gira al buscar tras cambiar de condominio. — *Visto en producción el 2026-11-24 con PRUEBAS MOTORA (16533).*

Revisados sin staging (2026-11-24): la regla es solo de servidor; se comprobó el test que la fija y que `myapi.module` la invoca. No sustituye a verlo en el sitio, pero no depende del navegador:

- [x] Un POST manipulado con una vivienda de otro condominio no guarda y marca Vivienda en rojo. — `testANewReservationWithAUnitOfAnotherCondominiumIsRefused` (error sobre `field_unit][und][0][target_id`), llamado desde la rama `reservation` de `myapi_node_validate()`. — *Verificado por el propietario en producción el 2026-11-24.*
- [x] Cancelar una reserva cuyo solicitante ya no está en la vivienda **sí** guarda. — `testAnEditThatTouchesNoReferenceIsNotValidated`. — *Verificado por el propietario en producción el 2026-11-24.*
- [x] Un usuario sin permisos sobre reservas recibe 403 en la ruta de autocompletado. — `testWithoutAnyOfThemTheRouteIsClosed`; la ruta declara `myapi_reservation_form_access` como `access callback`. En producción (2026-11-24) una petición anónima recibió 403.

---

## Decisiones

1. **Solicitante por vivienda, no por condominio.** Decisión del propietario del producto: evita reservar a nombre de alguien que no vive en la vivienda. Obliga a elegir la vivienda antes que el solicitante.
2. **Campos bloqueados sin padre.** Mismo criterio que el filtro Área del calendario.
3. **JavaScript y no `#ajax`.** Reconstruir el formulario de nodo en cada cambio obliga a limitar la validación, a limpiar `$form_state['input']` a mano y a un viaje al servidor por cada `blur`. El script no toca el estado del formulario.
4. **Clonar el input y no parchear `Drupal.ACDB`.** Solo usa el *behavior* público del core.
5. **Validar en edición solo si cambian las referencias.** Ver arriba.
6. **Solo usuarios activos y nodos publicados** en las sugerencias. La validación al guardar no mira el estado: solo la pertenencia.
7. **Sin correo en el autocompletado de Solicitante.** La primera versión buscaba y mostraba también el correo. Se descartó: los roles `supervisor` (rid 8) y `pagos` (rid 6) tienen permisos sobre reservas y para ellos ninguna consulta se recorta por condominio, así que la ruta habría servido para leer el correo de los residentes de cualquier vivienda.

## Riesgos

- **Sin verificar en navegador.** El JavaScript y su convivencia con el tema del back office no tienen test automático; dependen de la matriz manual de arriba. El stub de `db_select()` no evalúa la condición de los `JOIN`, así que el filtro por condominio está afirmado sobre la consulta registrada, no sobre filas reales.
- **Dos mensajes sobre el mismo campo.** Un `administrador edificio` que envíe una vivienda ajena y además de otro condominio ve el mensaje de esta spec y el de SPEC 51: usan claves distintas de `form_set_error()`.
- **Hay que conocer el nombre de usuario del solicitante.** El campo no lista a los miembros de la vivienda sin escribir, y ya no se puede buscar por correo. Es la misma limitación que tenía el formulario antes de esta spec.
- **Reservas con solicitante ajeno a la vivienda.** Hasta hoy el back office permitía crearlas. Siguen pudiendo cancelarse, pero cambiar cualquiera de sus cuatro referencias exige dejarlas coherentes.
- **Widget cambiado desde la UI de campos.** El formulario vuelve al comportamiento anterior sin avisar; la validación al guardar sigue activa.
- **Vivienda sin propietario ni ocupantes.** No se puede reservar para ella desde el back office: no hay solicitante válido.
