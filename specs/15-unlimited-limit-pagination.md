# 15 — Valor `limit=-1` para desactivar la paginación en payments/receipts/extra-fees

> **Estado:** Implemented · **Depende de:** SPEC 11, SPEC 13, SPEC 14 · **Fecha:** 2026-07-06
> **Objetivo:** En `GET /api/v1/units/<unit_id>/receipts`, `GET /api/v1/units/<unit_id>/extra-fees` y `GET /api/v1/units/<unit_id>/payments`, permitir que `?limit=-1` devuelva **todos** los ítems del conjunto filtrado en una sola respuesta, sin aplicar el clamp `[1, 50]` ni el `range()` de paginación.

---

## Alcance

**Dentro de este spec:**

- **`resources/receipt.resource.inc`** (modificar) — `myapi_receipt_list()` reconoce `limit=-1` como valor especial; `myapi_receipt_fetch()` omite el `range()` cuando `$limit === -1`.
- **`resources/extra_fee.resource.inc`** (modificar) — mismo cambio en `myapi_extra_fee_list()` / `myapi_extra_fee_fetch()`.
- **`resources/payment.resource.inc`** (modificar) — mismo cambio en `myapi_payment_list()` / `myapi_payment_fetch()`.
- **`docs/receipt.md`, `docs/extra_fee.md`, `docs/payment.md`** (modificar) — documentar el valor especial `-1` en la tabla de query params.

**Fuera de este spec (para futuros specs):**

- **`page=-1` o cualquier otro valor especial de `page`** — `page` no cambia de comportamiento; con `limit=-1` se fuerza a `1` porque no hay páginas.
- **Un límite máximo distinto para el modo "todos"** (p. ej. tope de 500 filas) — se devuelve el conjunto completo sin ningún tope adicional.
- **Aplicar el mismo valor especial a otros endpoints** (`GET /api/v1/units`, etc.) — solo los tres endpoints listados arriba tienen este contrato de paginación.
- **Cambios en el filtro de fechas, el orden o la forma de cada ítem** — el cambio es exclusivamente sobre `limit`/`page`/`total_pages`.

---

## Modelo de datos

Este spec **no introduce estructuras de datos nuevas**. Redefine el contrato del query param `limit`, ya existente en los SPEC 11/13/14, para los tres endpoints.

### Contrato de `limit` (actualizado)

| Valor de `?limit=` | Comportamiento |
|---|---|
| Entero `> 0` | Igual que antes: se clampa a `[1, 50]`. |
| `-1` (literal, exacto) | **Nuevo.** Desactiva la paginación: se devuelven todos los ítems del conjunto filtrado (estado + rango de fechas si aplica), sin `range()` en la query. `page` se fuerza a `1` (se ignora `?page` si vino). `pagination.limit` en la respuesta es `-1`. `pagination.total_pages` es `1` si `total > 0`, o `0` si `total` es `0`. |
| Ausente, no numérico, `0`, negativo distinto de `-1`, o `> 50` | Igual que antes: cae al default `20` (o se clampa a `50` si es un entero positivo fuera de rango). |

La detección de `-1` es una comparación de string exacta (`$_GET['limit'] === '-1'`), **antes** de la validación `ctype_digit()` existente (que nunca acepta signos, por lo que `-1` nunca calificaba como entero válido bajo el contrato anterior y caía silenciosamente al default `20`).

### Forma de respuesta (con `limit=-1`)

```json
{
  "payments": ["...", "...", "... (todos los ítems del conjunto filtrado)"],
  "pagination": {
    "total": 137,
    "page": 1,
    "limit": -1,
    "total_pages": 1
  }
}
```

---

## Plan de implementación

1. **Parseo de `$limit` en cada `myapi_<recurso>_list()`.** Reemplazar el parseo actual por:
   ```php
   $limit = isset($_GET['limit']) && $_GET['limit'] === '-1'
     ? -1
     : (isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
       ? max(1, min(50, (int) $_GET['limit']))
       : 20);

   if ($limit === -1) {
     $page = 1;
   }
   ```
   Aplicado igual en `receipt.resource.inc`, `extra_fee.resource.inc` y `payment.resource.inc`. *Verificación: sin `?limit`, comportamiento idéntico a antes (default `20`).*

2. **`total_pages` con `-1`.** En cada `_list()`, cambiar el cálculo a:
   ```php
   $total_pages = $limit === -1
     ? ($total > 0 ? 1 : 0)
     : ($total > 0 ? (int) ceil($total / $limit) : 0);
   ```

3. **Omitir `range()` en cada `_fetch()`.** Envolver la línea `$query->range(($page - 1) * $limit, $limit);` en `if ($limit !== -1) { ... }`, en los tres archivos. Sin `range()`, la query devuelve todas las filas que matchean los filtros ya aplicados (estado, unidad, rango de fechas). *Verificación: con `limit=-1`, la cantidad de ítems devueltos es igual a `pagination.total`.*

4. **Actualizar `docs/receipt.md`, `docs/extra_fee.md`, `docs/payment.md`.** Agregar la nota de `-1` en la fila de `limit` de la tabla de query params de cada uno. *Verificación: doc coincide con el comportamiento implementado.*

5. **Aplicar y verificar.** `drush cc all` y `curl` sobre los tres endpoints con `?limit=-1`, con y sin filtros de fecha, y con una unidad sin datos.

---

## Criterios de aceptación

- [x] `GET .../payments?limit=-1`, `.../receipts?limit=-1` y `.../extra-fees?limit=-1` devuelven **todos** los ítems del conjunto filtrado (estado + rango de fechas si aplica) en un solo array, sin importar cuántos sean.
- [x] Con `limit=-1`, `pagination.limit` es `-1`, `pagination.page` es `1` (aunque se haya mandado `?page=3` u otro valor), y `pagination.total_pages` es `1` cuando `total > 0` o `0` cuando `total` es `0`.
- [x] `pagination.total` con `limit=-1` es igual a la cantidad de ítems devueltos en el array.
- [x] El orden (`sort=asc`/`desc`) y el filtro de fechas (`date_from`/`date_to`) se siguen aplicando igual con `limit=-1`.
- [x] Cualquier otro valor de `limit` (ausente, `0`, negativo distinto de `-1`, no numérico, o `> 50`) se comporta exactamente igual que antes de este spec (default `20`, clamp `[1, 50]`).
- [x] Una unidad sin ítems en el conjunto filtrado, con `limit=-1`, devuelve `200` con el array vacío y `pagination.total: 0`, `total_pages: 0` (no es error).
- [x] El control de acceso (`403 unit_access_denied`), la autenticación (`401`) y el método (`405`) no cambian.
- [x] `docs/receipt.md`, `docs/extra_fee.md` y `docs/payment.md` documentan el valor especial `-1` en la tabla de query params.
- [x] `drush cc all` no reporta errores tras el cambio.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Valor que activa "todos los ítems" | `-1` | Un string como `all`/`none`, o un header aparte | Pedido explícito del usuario; `-1` es un valor fuera del rango natural de `limit` y fácil de detectar sin ambigüedad. |
| Tope adicional en modo "todos" | Ninguno — se devuelve el conjunto completo | Cap interno (p. ej. 500 filas) por seguridad de performance | Pedido explícito: "sin importar el limite"; el conjunto ya está acotado por unidad (`unit_id`) y por los filtros de estado/fecha, no es una tabla completa sin filtrar. |
| Valor de `pagination.page` con `limit=-1` | Forzado a `1` | Respetar el `?page` recibido | No hay páginas cuando se devuelve todo; dejar pasar un `page` arbitrario sería confuso para el cliente. |
| Valor de `pagination.total_pages` con `limit=-1` | `1` (o `0` si `total` es `0`) | `total` (una "página" por ítem), o `null` | Consistente con la semántica de "una sola página que contiene todo"; evita que el cliente intente iterar páginas. |
| Dónde aplicar el cambio | Los tres endpoints (`payments`, `receipts`, `extra-fees`) en un solo spec | Un spec por endpoint | Es el mismo cambio mecánico replicado en los tres recursos gemelos; separarlo en tres specs sería redundante. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Respuesta potencialmente muy pesada.** Sin el clamp de `50`, una unidad con muchos años de historial puede devolver cientos de filas en una sola respuesta (especialmente en `receipts`, con 37 campos por ítem). | Aceptado, es el comportamiento pedido explícitamente. El conjunto sigue acotado por `unit_id` y por los filtros de estado/fecha; no es un listado global sin filtrar. |
| **Cliente que asuma que `limit` siempre es un entero positivo.** Un consumidor existente de la API que lea `pagination.limit` como cantidad de ítems por página podría interpretar mal el `-1`. | Documentado explícitamente en los tres `docs/*.md`; es un valor centinela ya usado con ese significado en otras APIs REST. |
| **`ctype_digit()` nunca acepta el signo `-`.** El chequeo de `-1` se hace con comparación de string exacta *antes* de la validación numérica existente, para no depender de que `ctype_digit()` cambie de comportamiento. | Cubierto en el paso 1 del plan; la comparación `=== '-1'` es independiente del resto de la validación. |

---

## Lo que **no** entra en este spec

- `page=-1` o cualquier otro valor especial de `page`.
- Un tope adicional (cap) para el modo "todos los ítems".
- Aplicar `limit=-1` a `GET /api/v1/units` o a cualquier otro endpoint paginado que no sea payments/receipts/extra-fees.
- Cambios en el filtro de fechas, el orden o la forma de cada ítem devuelto.

Cada uno, si llega, va en su propio spec.
