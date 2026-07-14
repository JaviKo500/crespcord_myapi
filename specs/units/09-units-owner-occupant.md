# 09 — Owner y ocupante actual en el listado de unidades

- **Estado:** Approved
- **Fecha:** 2026-07-02
- **Dependencias:**
  - `08-units-list` (Implemented) — `GET /api/v1/units`, `myapi_unit_list()` en
    `resources/unit.resource.inc`. Este spec **modifica** ese endpoint, no crea
    uno nuevo.
- **Objetivo:** Que cada unidad devuelta por `GET /api/v1/units` incluya
  también el `uid` del propietario y el `uid`/nombre del ocupante actual, para
  que la app pueda mostrar quién ocupa cada unidad sin una llamada adicional.

---

## Alcance

### Dentro de este spec

- **`resources/unit.resource.inc`** (modificar) — exponer `owner_uid` (ya
  calculado internamente en `myapi_unit_fetch_units()` pero no incluido en la
  respuesta) y agregar `occupant_uid` / `occupant_name` a cada unidad.
- **`docs/unit.md`** (modificar) — documentar los campos nuevos en el mismo
  commit que el cambio de código.

### Fuera de este spec

- **Endpoint de detalle** (`GET /api/v1/units/%`) — se mantiene la decisión de
  spec 08 de no crearlo; los campos nuevos se agregan a la misma respuesta de
  listado.
- **Historial de ocupantes** — solo se expone el ocupante actual, no quiénes
  ocuparon la unidad antes.
- **Restricciones de privacidad por rol** — no se ocultan estos campos según
  si el usuario autenticado es propietario u ocupante; ver justificación en
  Decisiones tomadas y descartadas.
- **Escritura** (asignar/cambiar propietario u ocupante) — solo lectura, igual
  que spec 08.

---

## Modelo de datos

Reutiliza las tablas ya documentadas en spec 08. Se agrega el uso de la
columna `delta` (estándar de Drupal 7 en campos multi-value) de
`field_data_field_ocupantes`, no usada hasta ahora.

| Tabla | Columnas relevantes | Uso |
|---|---|---|
| `field_data_field_ocupante` | `entity_id`, `field_ocupante_target_id` | Ocupante legacy (single-value). Se usa como *fallback* si la unidad no tiene filas en `field_data_field_ocupantes`. |
| `field_data_field_ocupantes` | `entity_id`, `field_ocupantes_target_id`, `delta` | Ocupante(s) actuales (multi-value). La fila con mayor `delta` por `entity_id` es "el último asignado" = ocupante actual. |

### Contrato de los campos nuevos

1. **`owner_uid`** — ya viene en las filas de `myapi_unit_fetch_units()`
   (`field_propietario_target_id`); solo falta incluirlo en el array que arma
   `myapi_unit_build_properties()`. `NULL` si la unidad no tiene propietario.

2. **Resolución de `occupant_uid` por unidad** (función nueva
   `myapi_unit_fetch_occupant_uids($nids)`, análoga en estilo a
   `myapi_unit_fetch_condominium_titles()`):
   - Query sobre `field_data_field_ocupantes` (`entity_id IN ($nids)`,
     `deleted = 0`), trayendo `entity_id`, `field_ocupantes_target_id`,
     `delta`, ordenada por `delta ASC`. En PHP, recorrer los resultados
     sobrescribiendo `map[entity_id] = target_id` en cada fila — como están
     ordenadas ascendentemente por `delta`, el valor que queda al final del
     recorrido es el de mayor `delta` (el ocupante asignado más
     recientemente).
   - Query sobre `field_data_field_ocupante` (`entity_id IN ($nids)`,
     `deleted = 0`) para las unidades que **no** quedaron resueltas en el paso
     anterior (fallback legacy).
   - Devuelve un mapa `nid => occupant_uid` (unidades sin ningún ocupante
     simplemente no aparecen en el mapa).

3. **Resolución de `occupant_name`** — reutiliza la misma función que resuelve
   `owner_name` en spec 08 (`myapi_unit_fetch_owner_names()`, renombrada a
   `myapi_unit_fetch_user_names()` para reflejar que ya no es específica de
   propietarios), pasándole la unión de `owner_uid` **y** `occupant_uid`
   distintos en una sola query — evita una segunda consulta idéntica a
   `users` + `field_data_field_nombre` + `field_data_field_apellidos`. Mismo
   criterio de fallback: `"$nombre $apellidos"` si ambos vienen no vacíos,
   `users.name` completo si falta cualquiera de los dos.

4. **Armado en `myapi_unit_build_properties()`** — cada unidad en `units[]`
   agrega `owner_uid`, `occupant_uid` y `occupant_name` (todos `NULL` si no
   aplica), sin tocar los campos ya existentes (`id`, `name`, `category`,
   `area_m2`, `owner_name`).

Forma de respuesta resultante:

```json
{
  "properties": [
    {
      "id": 12,
      "name": "Edificio El Sáuco",
      "units": [
        {
          "id": 45,
          "name": "Depto. 4B",
          "category": "departamento",
          "area_m2": 92.0,
          "owner_uid": 3,
          "owner_name": "Priscila Cordero",
          "occupant_uid": 7,
          "occupant_name": "Juan Pérez"
        }
      ]
    }
  ]
}
```

---

## Plan de implementación

1. **`myapi_unit_build_properties()`** — agregar `owner_uid` (ya disponible en
   `$unit->owner_uid`) al array de cada unidad.
2. **`myapi_unit_fetch_occupant_uids($nids)`** (nueva) — implementar la lógica
   de resolución descrita arriba (multi-value por `delta` máximo, con
   fallback a legacy single-value).
3. **`myapi_unit_fetch_owner_names()` → `myapi_unit_fetch_user_names()`**
   (rename) — sin cambios de lógica interna, solo generalizar el nombre y el
   docblock para reflejar que resuelve nombres tanto de propietarios como de
   ocupantes.
4. **`myapi_unit_list()`** — llamar a `myapi_unit_fetch_occupant_uids()`
   además de lo ya existente; construir el array de uids a resolver como la
   unión de `owner_uid` y `occupant_uid` distintos y no nulos antes de llamar
   a `myapi_unit_fetch_user_names()`.
5. **`myapi_unit_build_properties()`** — agregar `occupant_uid` y
   `occupant_name` (mismo criterio de `NULL` que `owner_name`).
6. **`docs/unit.md`** — documentar los 3 campos nuevos y la regla de "último
   ocupante asignado" en la sección de notas.
7. **Aplicar y verificar.** `drush cc all` y probar con `curl`:
   - Unidad con un solo valor en `field_ocupantes` → `occupant_uid` correcto.
   - Unidad con varios valores en `field_ocupantes` → `occupant_uid` es el de
     mayor `delta`.
   - Unidad solo con `field_ocupante` (legacy, sin filas en `field_ocupantes`)
     → `occupant_uid` cae al valor legacy.
   - Unidad con valores en ambos campos → gana `field_ocupantes` (el de mayor
     `delta`), `field_ocupante` se ignora.
   - Unidad sin ningún ocupante → `occupant_uid: null`, `occupant_name: null`.
   - Unidad sin propietario → `owner_uid: null` (ya cubierto por spec 08, solo
     confirmar que ahora se expone).
   - `owner_name`/`occupant_name` con `field_nombre`/`field_apellidos` vacíos
     → cae a `users.name`, igual que ya validado para `owner_name` en spec 08.

---

## Criterios de aceptación

- [ ] `owner_uid` aparece en cada unidad de `GET /api/v1/units`, igual al
      `uid` de `field_propietario`, `null` si no tiene propietario.
- [ ] `occupant_uid`/`occupant_name` reflejan el valor de mayor `delta` en
      `field_data_field_ocupantes` cuando existen filas para esa unidad.
- [ ] Si `field_data_field_ocupantes` no tiene filas para la unidad,
      `occupant_uid`/`occupant_name` caen al valor de `field_ocupante`
      (legacy).
- [ ] Si ninguno de los dos campos tiene valor, `occupant_uid: null` y
      `occupant_name: null`, sin error.
- [ ] `occupant_name` sigue el mismo criterio de fallback que `owner_name`
      (nombre + apellido, o `users.name` si falta alguno).
- [ ] No se agrega ninguna restricción de acceso adicional: cualquier usuario
      para el que la unidad aparece en su propio listado ve `owner_uid` y
      `occupant_uid`/`occupant_name` completos.
- [ ] `docs/unit.md` documenta los 3 campos nuevos.
- [ ] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Forma de exponer los datos | Agregar campos a la misma respuesta de `GET /api/v1/units` | Endpoint de detalle nuevo (`GET /api/v1/units/%`) | Spec 08 ya decidió no paginar por el volumen chico por usuario; un endpoint de detalle solo agregaría una llamada extra sin necesidad. |
| Ocupantes múltiples en `field_ocupantes` | Solo el último asignado (mayor `delta`), como `occupant_uid`/`occupant_name` singulares | Array de todos los ocupantes (`occupants: [{uid, name}]`) | Pedido explícito: el último asignado es quien ocupa la unidad actualmente; los anteriores no son relevantes para este caso de uso. |
| Precedencia entre `field_ocupante` y `field_ocupantes` | `field_ocupantes` (marcado como "current" en spec 08) gana; `field_ocupante` solo aplica si la unidad no tiene ninguna fila en `field_ocupantes` | Precedencia inversa, o combinar ambos con otra regla | Consistente con cómo ya se documentó cada campo en `docs/unit.md` (`field_ocupante` = legacy, `field_ocupantes` = current). |
| "Último asignado" en un campo multi-value | Fila con mayor `delta` por `entity_id` | Timestamp de asignación | Drupal 7 no guarda una fecha de asignación por valor de campo, solo el orden (`delta`); es la única señal disponible en el schema actual. |
| Privacidad de `owner_uid`/`occupant_uid` | Sin restricción adicional — se expone igual a cualquier usuario para el que la unidad ya aparece en su propio listado | Ocultar `owner_uid` a ocupantes, o viceversa | `GET /api/v1/units` ya filtra a unidades donde el usuario autenticado es propietario u ocupante (spec 08); no se expone información de una unidad ajena al usuario. |
| Resolución de nombres | Reutilizar y generalizar `myapi_unit_fetch_owner_names()` → `myapi_unit_fetch_user_names()`, una sola query para `owner_uid` + `occupant_uid` combinados | Función separada para nombres de ocupantes | Evita duplicar la misma lógica de fallback (`nombre + apellido` / `users.name`) en dos funciones, según la regla del proyecto de no duplicar lógica entre helpers. |

---

## Riesgos identificados

- **`delta` como proxy de "orden de asignación" puede no ser exacto.** Si
  alguna vez se reordenan valores de `field_ocupantes` sin agregar uno nuevo
  al final (p. ej. una edición manual en el nodo que reordena la lista), el
  de mayor `delta` podría no ser realmente el asignado más recientemente,
  solo el que quedó último en la lista. *Mitigación:* aceptado como
  limitación del schema actual; documentado en este spec y en
  `docs/unit.md`.
- **Cambio de contrato para consumidores existentes de `GET /api/v1/units`.**
  Se agregan campos, no se quita ni renombra ninguno existente, por lo que no
  debería romper clientes actuales que ignoren campos desconocidos. *Mitigación:*
  ninguna acción adicional necesaria, es un cambio aditivo.
