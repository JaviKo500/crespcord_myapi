# 91 — La vivienda en el listado de solicitudes (`GET /api/v1/service-requests`)

- **Estado:** Implemented
- **Fecha:** 2026-08-19
- **Dependencias:**
  - `88-service-requests-list` (Implemented) — dueña del endpoint, de
    `myapi_service_request_base_query()` y del idioma de filtros que este spec
    extiende. No cambia nada de lo ya entregado: sin `?unit_id` la respuesta es
    la misma salvo por la clave nueva.
  - `86-service-request-unit` (Implemented) — dueña de la instancia
    **obligatoria** de `field_unit` en `service_request`, con
    `target_bundles = vivienda` y cardinalidad 1. Este spec **lee** ese campo y
    no toca el esquema.
  - `89-service-request-detail` (Implemented) — ya resolvía la vivienda en dos
    saltos (`field_unit` → nodo `vivienda` → `field_nombre_vivienda`). Este spec
    **mueve esa resolución al serializador compartido**, y ahí está su única
    consecuencia sobre lo ya entregado.
  - `90-service-request-create` (Implemented) — la razón de que `?unit_id` sea
    estricto: el `POST` ya responde `422 invalid_field` a un `unit_id`
    malformado, y la app manda aquí el mismo valor.

**Objetivo:** Que el residente pueda pedir *«las solicitudes de mi piso»* y que
el listado le diga, en cada elemento, de qué piso es cada una.

Son las dos mitades de una misma cosa y por eso van en un solo spec: un filtro
por un dato que la respuesta no contiene obliga al cliente a pintar un selector
a ciegas, y una clave que nadie puede usar para filtrar deja al cliente
filtrando en memoria sobre una página de veinte elementos.

---

## Alcance

**Dentro del alcance:**

- **`resources/service_request.resource.inc`**:
  - `myapi_service_request_parse_unit_id()` — lee `?unit_id`.
  - `myapi_service_request_parse_id_param($name)` — el parser estricto del que
    pasan a estar hechos **`?category_id` y `?unit_id`**; una sola copia de las
    tres malformaciones y del `422`.
  - `myapi_service_request_base_query()` — un `LEFT JOIN` a
    `field_data_field_unit` y la condición opcional sobre el `target_id` crudo.
  - `myapi_service_request_fetch()` — el segundo salto (nodo `vivienda` y
    `field_nombre_vivienda`) y las columnas `unit_id` / `unit_name`.
  - `myapi_service_request_build_item()` — la clave `unit`, **la undécima**.
  - `myapi_service_request_build_detail()` — deja de resolver la vivienda y pasa
    a **sobrescribirla** (`null` para el proveedor).
  - `myapi_service_request_detail_row()` — deja de unir `field_data_field_unit`:
    ya viene de la consulta base.
- **`docs/service-request.md`** — el parámetro, la clave, la asimetría con el
  `POST` y el cambio de posición en el detalle.
- **`tests/unit/ServiceRequestListEndpointTest.php`,
  `ServiceRequestDetailEndpointTest.php`,
  `ServiceRequestCreateEndpointTest.php`**.

**Fuera del alcance:**

- **Filtrar por condominio.** `field_condominium` se deriva de la vivienda y el
  alcance del endpoint ya es `field_requester = uid`; un residente de dos
  condominios es un caso que nadie ha pedido todavía.
- **Filtrar por varias viviendas** (`?unit_id=55,56`). Un valor, como
  `?category_id`. La lista es otro spec y otra forma de contar.
- **Cualquier cambio de esquema.** No hay `hook_update_N()`. `field_unit` ya
  existe, ya es obligatorio y ya está poblado por el `POST`.
- **El lado del proveedor** («las solicitudes que puedo atender»). Sigue siendo
  otro spec, y ahí la vivienda no se pinta.

---

## El parámetro

| Param | Valores | Defecto | Notas |
|-------|---------|---------|-------|
| `unit_id` | entero positivo (`nid` de `vivienda`) | *(sin filtro)* | Estrecha el listado a las solicitudes de esa vivienda. Compone con `AND` con el resto. |

**Estricto, como `?category_id`, y por la misma clase de razón: su gemelo.** El
`POST /api/v1/service-requests` ya responde `422 invalid_field` a un `unit_id`
malformado (SPEC 90), y la app manda aquí exactamente el valor con el que acaba
de crear una solicitud. Un listado que se tragase el id roto y devolviese la
lista **entera** le diría al cliente que su selector de vivienda funciona cuando
no funciona — y el residente leería la solicitud de otro bajo el título de su
piso.

- `?unit_id=abc`, `-3`, `0`, `?unit_id=` (vacío) y `?unit_id[]=55` →
  **`422 invalid_field`** nombrando `unit_id`, **antes de cualquier consulta del
  listado**.
- `?unit_id=<nid que no es tuyo | del que te mudaste | inexistente>` →
  **`200` con `service_requests: []` y `total: 0`**.

### Una vivienda ajena es una lista vacía, nunca un `403`

Es la única asimetría con la creación y es deliberada. El `POST` responde
`403 unit_access_denied` a una vivienda que el residente no posee ni ocupa
porque **va a escribir** en ella. El filtro no escribe nada: el alcance del
endpoint ya es `field_requester = uid`, así que una vivienda ajena solo puede
cortar tus propias solicitudes en el conjunto vacío.

Un `403` aquí haría lo contrario de proteger algo — le confirmaría a quien
sondea que la vivienda existe — y costaría una consulta para decir lo que una
lista vacía ya dice gratis. Mismo criterio que un `category_id` que nadie lleva.

**No se valida la propiedad de la vivienda, y no es un olvido:** validarla
costaría la consulta de `myapi_unit_related_nids()` para acabar respondiendo lo
mismo.

### El filtro compara el `target_id` crudo, nunca el nodo unido

```
WHERE fu.field_unit_target_id = :unit_id      -- y no  nu.nid = :unit_id
```

Dos consecuencias, las dos queridas:

1. **El conteo no resuelve ningún nodo.** La consulta del `count` se queda en
   las tablas de campo, sin arrastrar `node` ni `field_nombre_vivienda`, que
   solo añaden columnas.
2. **Una solicitud cuya vivienda se despublicó sigue siendo una solicitud DE esa
   vivienda.** `?unit_id=<esa>` la conserva y el elemento responde `unit: null`.
   Filtrar por el nodo resuelto vaciaría la pantalla del residente por un nodo
   del que nunca ha oído hablar.

---

## La clave

`unit` es la **undécima** clave del elemento del listado, y va **junto a
`category`**:

```json
{
  "id": 128,
  "title": "Fuga en el calentador",
  "description": "El calentador gotea desde el lunes.",
  "status": "assigned",
  "category": { "id": 12, "code": "plumbing", "name": "Plomería" },
  "unit": { "id": 55, "name": "A-301" },
  "offers_count": 3,
  "assigned_offer": { "id": 45, "status": "selected" },
  "assigned_provider": { "id": 7, "name": "Plomería Rivas" },
  "created": "2026-08-14T09:12:33",
  "desired_start": "2026-08-19T08:00:00"
}
```

**La posición no es estética.** `category` y `unit` responden qué es la
solicitud y dónde es; `offers_count` y las dos `assigned_*` son el estado del
mercado y se quedan contiguas. Y el detalle mezcla este mismo elemento primero,
así que moverla aquí la mueve allí.

- `{id, name}` o **un `null` entero**, nunca `{id: null, name: null}`: «no hay
  vivienda que pintar» es una respuesta, no dos medias respuestas. Misma forma
  que `assigned_offer` y `assigned_provider`.
- `name` es **`field_nombre_vivienda`**, no el título del nodo `vivienda` — el
  título es una etiqueta interna y el campo es el nombre con el que el residente
  conoce su piso. Es el mismo valor que responden `/api/v1/units` y el detalle.
- `id` viaja como **entero JSON**.

### Cuándo es `null`, y por qué la solicitud no desaparece

El `JOIN` a `field_data_field_unit` es **`LEFT`** aunque el campo sea
obligatorio, y aunque un filtro lo lea. Dos casos:

1. **Una solicitud anterior al requisito.** SPEC 86 puso `field_unit` como
   obligatorio en un bundle que ya tenía filas, **sin backfill**. Una solicitud
   cargada desde el back office antes de aquel día puede no tener fila de campo:
   con un `INNER JOIN` desaparecería del listado de su propio dueño sin que
   ningún mensaje lo explicase.
2. **Una referencia que ya no resuelve** — la `vivienda` se borró o se
   despublicó.

Es exactamente el fallo que el `JOIN` de la categoría acepta a propósito y este
no: una solicitud sin categoría no es accionable, una sin vivienda sí lo es.

---

## Consecuencia sobre el detalle (lo único ya entregado que cambia)

`GET /api/v1/service-requests/%` sigue respondiendo **dieciocho claves** y
sigue respondiendo `unit: null` al proveedor. Cambian dos cosas:

- **Quién la resuelve.** Antes la resolvía `myapi_service_request_build_detail()`
  con su propio par de columnas; ahora llega ya construida desde
  `myapi_service_request_build_item()` y el detalle solo la **sobrescribe** para
  el proveedor. Es la única clave del listado que el detalle toca, y la regla de
  resolución queda en un solo sitio.
- **Su posición en el JSON.** Pasa de ir después de `requester` a ir después de
  `category`, porque el detalle mezcla el elemento del listado primero:

  ```
  antes:  … category, offers_count, …, desired_start, viewer, requester, unit, condominium, …
  ahora:  … category, unit, offers_count, …, desired_start, viewer, requester, condominium, …
  ```

  El orden de las claves de un objeto JSON no significa nada para el cliente
  Flutter, que las lee por nombre; se documenta porque este módulo lo fija por
  escrito y lo comprueba en los tests, no porque rompa nada.

`myapi_service_request_detail_row()` deja de unir `field_data_field_unit`: la
consulta base ya la trae. Un segundo `leftJoin()` de la misma tabla no sería
incorrecto, sería un alias duplicado.

---

## Coste

**Cero consultas nuevas**, con o sin el parámetro. El listado sigue costando
tres consultas — conteo, página, ofertas de la página — más la del token:

- La referencia (`field_data_field_unit`) entra en la **consulta base**, que
  comparten el conteo y la página, porque el filtro la lee. Cardinalidad 1: no
  puede multiplicar filas.
- El segundo salto (nodo `vivienda` + `field_nombre_vivienda`) lo añade **solo
  la página**, porque solo proyecta columnas. `node.nid` es clave primaria y
  `field_nombre_vivienda` tiene cardinalidad 1: tampoco multiplican.
- El `422` se responde **antes** de las tres consultas.

---

## Criterios de aceptación

**El filtro**

1. `?unit_id=55` devuelve solo las solicitudes de la vivienda 55, y `total`
   cuenta ese subconjunto.
2. `?unit_id` de una vivienda ajena, inexistente o sin solicitudes → `200`,
   lista vacía, `total: 0`, `total_pages: 0`. Nunca `403` ni `404`.
3. `?unit_id=abc | 0 | -3 | (vacío) | [55]` → `422 invalid_field` nombrando
   `unit_id`, sin ejecutar ninguna consulta del listado.
4. `?unit_id` roto junto a un `?category_id` válido → `422` igualmente, y sin
   consultas.
5. Compone con `AND` con `?category_id`, `?status` y el rango de fechas;
   `pagination` describe el resultado de todos juntos.
6. Una solicitud cuya `vivienda` está despublicada **sigue apareciendo** bajo
   `?unit_id=<esa vivienda>`, con `unit: null`.
7. Sin el parámetro, ninguna condición sobre `fu.field_unit_target_id` llega al
   SQL de ninguna de las dos consultas.
8. La condición está en el **conteo y en la página**, y en las dos sobre la
   columna cruda.

**La clave**

9. Cada elemento lleva **once** claves, en el orden documentado, con `unit`
   detrás de `category`.
10. `unit` es `{id, name}` con `id` entero JSON, y `name` leído de
    `field_nombre_vivienda` y no del título del nodo.
11. Una solicitud sin fila de `field_unit`, y una cuya `vivienda` no resuelve,
    responden `unit: null` entero y **siguen listadas**.

**El detalle**

12. Sigue respondiendo dieciocho claves, con `unit` en su nueva posición.
13. El proveedor sigue leyendo `unit: null`.
14. Las **once** primeras claves del detalle son byte a byte las del listado
    para la misma solicitud.
