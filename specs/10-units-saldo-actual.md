# 10 — Saldo actual en el listado de unidades

- **Estado:** Implemented
- **Fecha:** 2026-07-03
- **Dependencias:**
  - `08-units-list` (Implemented) — `GET /api/v1/units`,
    `myapi_unit_fetch_units()` en `resources/unit.resource.inc`.
  - `09-units-owner-occupant` (Implemented) — última modificación del mismo
    endpoint. Este spec **modifica** ese endpoint, no crea uno nuevo.
- **Objetivo:** Que cada unidad devuelta por `GET /api/v1/units` incluya
  también su **saldo actual** (`field_saldo_actual`), para que la app pueda
  mostrar cuánto debe/tiene a favor cada unidad sin una llamada adicional.

---

## Alcance

### Dentro de este spec

- **`resources/unit.resource.inc`** (modificar) — agregar el saldo actual a
  cada unidad como campo `current_balance`, leyendo
  `field_data_field_saldo_actual`.
- **`docs/unit.md`** (modificar) — documentar el campo nuevo en el mismo commit
  que el cambio de código.

### Fuera de este spec

- **Escritura** (fijar/ajustar saldo) — solo lectura, igual que specs 08 y 09.
- **Historial de saldo / movimientos** — solo se expone el saldo actual, no el
  detalle de cargos y pagos que lo componen.
- **Interpretación del signo** — el valor se expone tal cual está almacenado
  (positivo o negativo); este spec no define qué significa el signo a nivel de
  negocio ni aplica ninguna transformación.
- **Restricciones de privacidad por rol** — no se oculta el saldo según si el
  usuario autenticado es propietario u ocupante, igual que spec 09.

---

## Modelo de datos

Verificado contra la BD de producción (`dr_field_data_field_saldo_actual`):
el campo `field_saldo_actual` está atado a `entity_type = 'node'`,
`bundle = 'vivienda'` — es decir, es un saldo **por unidad**. Single-value
(`MAX(delta) = 0`, una fila por `entity_id`), por lo que el LEFT JOIN no
multiplica filas.

| Tabla | Columnas relevantes | Uso |
|---|---|---|
| `field_data_field_saldo_actual` | `entity_id`, `field_saldo_actual_value` (`decimal(10,4)`) | Saldo actual de la unidad (`current_balance`). |

### Contrato del campo nuevo

- **`current_balance`** — valor de `field_saldo_actual_value` para la unidad,
  como número (`float`). `NULL` si la unidad no tiene fila en
  `field_data_field_saldo_actual` (nunca se le asignó saldo). El signo se
  expone tal cual está almacenado, sin transformación.
- **Clave en inglés (`current_balance`)** — se traduce el nombre del campo
  Drupal (`saldo_actual`) a inglés para respetar la regla del proyecto de
  claves JSON en inglés, consistente con `owner_uid`, `occupant_name`,
  `area_m2`, etc.

Forma de respuesta resultante (campo nuevo marcado):

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
          "occupant_name": "Juan Pérez",
          "current_balance": -3393.0
        }
      ]
    }
  ]
}
```

---

## Plan de implementación

1. **`myapi_unit_fetch_units($nids)`** — agregar un `leftJoin` a
   `field_data_field_saldo_actual` (por `entity_id = n.nid`,
   `entity_type = 'node'`, `deleted = 0`) y exponer
   `field_saldo_actual_value` como alias `saldo_actual` en las filas.
2. **`myapi_unit_build_properties()`** — agregar `current_balance` al array de
   cada unidad: `(float) $unit->saldo_actual` si no es `NULL`, `NULL` si lo es
   (mismo criterio de `NULL` que `area_m2`).
3. **`docs/unit.md`** — documentar el campo nuevo (ejemplo de respuesta, nota
   sobre `NULL`, tabla del modelo de datos).
4. **Aplicar y verificar.** `drush cc all` y probar con `curl`:
   - Unidad con fila en `field_saldo_actual` → `current_balance` con el valor.
   - Unidad sin fila → `current_balance: null`.
   - Valor negativo se expone tal cual (sin cambio de signo).

---

## Criterios de aceptación

- [x] `current_balance` aparece en cada unidad de `GET /api/v1/units`, igual al
      `field_saldo_actual_value` de esa `vivienda`.
- [x] `current_balance` es `null` cuando la unidad no tiene fila en
      `field_data_field_saldo_actual`.
- [x] El valor se expone tal cual (incluido signo negativo), sin
      transformación.
- [x] No se agrega ninguna restricción de acceso adicional respecto a specs 08
      y 09.
- [x] `docs/unit.md` documenta el campo nuevo.
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Nombre de la clave JSON | `current_balance` (inglés) | `saldo_actual` (español) | Regla del proyecto: claves JSON en inglés, consistente con el resto del endpoint. |
| Tipo del valor | `float` | `string` con decimales fijos | Consistente con `area_m2`, que también expone un decimal como número. |
| Interpretación del signo | Exponer tal cual | Normalizar a "deuda positiva" | No hay spec de negocio que defina el significado del signo; transformarlo sería inventar semántica. |
| Fuente del dato | LEFT JOIN en `myapi_unit_fetch_units()` | Query separada tipo `fetch_occupant_uids()` | Es single-value y sobre el mismo nodo `vivienda`; un JOIN no multiplica filas y evita una consulta extra, igual que `area_m2` / `owner_uid`. |

---

## Riesgos identificados

- **Cambio de contrato para consumidores existentes de `GET /api/v1/units`.**
  Se agrega un campo, no se quita ni renombra ninguno existente; es aditivo y
  no debería romper clientes que ignoren campos desconocidos. *Mitigación:*
  ninguna acción adicional.
- **Lectura directa de la tabla de Field API.** Igual que el resto del
  endpoint, un cambio de schema en `field_saldo_actual` (rename, paso a
  multi-value) rompería silenciosamente esta lectura sin aviso de Drupal.
  *Mitigación:* documentado en la tabla de "Data model assumptions" de
  `docs/unit.md`.
