# SPEC 61 — Comentario de acuse en la transacción inicial del reclamo

> **Estado:** Implemented · **Depende de:** SPEC 55 (bundles `reclamo`/`claim_transaction`, `field_claim_type` con sus dos valores y `field_comment` en la transacción), SPEC 56 (`myapi_claims_valid_claim_type()`, criterio de "los valores almacenados son parte del modelo"), SPEC 57 (`myapi_claim_transaction_create_initial()`, la transacción inicial automática que este spec completa; y la línea de tiempo que la muestra), SPEC 60 (`myapi_claim_transaction_title()`, que ahora recibe un comentario donde antes no había ninguno) · **Fecha:** 2026-08-03
> **Objetivo:** Que la transacción inicial automática de cada `reclamo` nazca con un comentario de acuse de recibo — *"Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad."* — en vez de con `field_comment` vacío, y que el sustantivo siga al tipo del reclamo (`reclamo` / `requerimiento`).

---

## El problema

`myapi_claim_transaction_create_initial()` (SPEC 57) escribe tres campos —
`field_claim`, `field_status` y `field_status_date` — y **nunca**
`field_comment`. La consecuencia se ve en la primera fila de la línea de tiempo
del reclamo: la columna "Comentario" muestra `—`
(`myapi_claim_transaction_timeline_table_rows()` lo pone cuando el valor es
`NULL` o `''`). El reclamo queda registrado, pero sin ninguna frase que diga que
fue recibido y qué pasa a continuación.

Es la única de las cuatro transacciones posibles que llega sin comentario: el
formulario propio de `claim-transaction/add` lo tiene `#required = TRUE`, y en
las dos rutas nativas lo escribe el operador.

---

## Alcance

**Dentro:**

- **`includes/myapi.claim_transaction_admin.inc`** (modificar):
  - Nueva **`myapi_claim_transaction_initial_comment($claim_type)`** — lógica
    pura (solo `t()`), la que entra en `tests/unit/`.
  - `myapi_claim_transaction_create_initial()` — escribe `field_comment` con esa
    función, leyendo `field_claim_type` del reclamo con
    `myapi_building_admin_field_value()` (SPEC 49), el helper que esa misma
    función ya usa para `field_status`.
  - Docblock de `myapi_claim_transaction_title()` (SPEC 60): decía que la
    transacción inicial es el caso normal "sin comentario"; a partir de acá ese
    caso es el formulario nativo con la textarea vacía.
- **`tests/unit/ClaimTransactionInitialCommentTest.php`** (nuevo).
- **`tests/unit/ClaimTransactionTitleTest.php`** (modificar) — solo el docblock
  de `testWithoutCommentTheSeparatorDoesNotDangle()`, por lo mismo. La aserción
  no cambia: el caso "sin comentario" sigue existiendo y sigue teniendo que
  cortar el separador.
- **`tests/README.md`** (modificar) — la cobertura nueva, en la sección donde ese
  fichero ya lleva la cuenta.
- **`docs/claim-transaction-timeline.md`** (modificar) — el comentario de acuse,
  su tabla de sustantivos y el efecto sobre el título.
- `drush cc all` al final. **No** hay `hook_update_N`, ni cambios de esquema.

**Fuera de alcance:**

- **Rellenar el comentario de los reclamos ya creados.** Un acuse de recibo es un
  mensaje escrito en el momento de la recepción; back-datearlo sobre líneas de
  tiempo viejas afirmaría algo que no ocurrió. Los reclamos anteriores a este
  spec conservan su primera fila con `—`.
- **Notificar al residente** (push/email) con ese texto. La transacción inicial
  no dispara ninguna notificación hoy y este spec no cambia eso; si se pide,
  entra por `includes/myapi.notification.inc` y su propio spec.
- **Traducir el comentario** por el catálogo de `includes/myapi.i18n.inc`. Ese
  catálogo es de mensajes de **API** (`docs/i18n.md`); esto se guarda como dato
  en `field_comment` y se escribe con `t()` directo, igual que toda la pantalla
  de SPEC 56-60.
- **Comentario automático en las demás transacciones.** Las otras tres rutas las
  escribe una persona.
- Cualquier endpoint `api/v1/...`; tests de integración o e2e.

---

## Modelo de datos

No se crean campos ni tablas. Se escribe `field_comment` (`text_long`,
`cardinality 1`, instancia de `claim_transaction`, `myapi.install`), que hoy
queda vacío en este camino.

### El texto

| `field_claim_type` | Comentario guardado |
|---|---|
| `claim` | `Hemos recibido su reclamo. Será revisado por la administración y se le notificará cualquier novedad.` |
| `requirement` | `Hemos recibido su requerimiento. Será revisado por la administración y se le notificará cualquier novedad.` |
| ausente / desconocido | El de `claim` |

Los dos sustantivos son masculinos, así que el resto de la frase es invariable:
no hace falta ninguna rama por género más allá del sustantivo.

### `myapi_claim_transaction_initial_comment()` — nuevo, lógica pura

```php
function myapi_claim_transaction_initial_comment($claim_type) {
  $noun = ($claim_type === 'requirement') ? t('requerimiento') : t('reclamo');

  return t('Hemos recibido su @noun. Será revisado por la administración y se le notificará cualquier novedad.', array('@noun' => $noun));
}
```

Pura por diseño — `t()` y nada más —, que es lo que la pone en `tests/unit/`.
Mismo corte que SPEC 60 entre `myapi_claim_transaction_title()` (lógica) y
`myapi_claim_transaction_set_title()` (Field API).

`field_claim_type` es obligatorio en el bundle `reclamo`, así que el fallback
cubre datos corruptos, no un caso normal: aun ahí la fila sale con comentario y
no con el `—` que este spec viene a sacar.

### Escritura en `myapi_claim_transaction_create_initial()`

```php
$transaction->field_comment[LANGUAGE_NONE][0]['value'] = myapi_claim_transaction_initial_comment(
  myapi_building_admin_field_value($node, 'field_claim_type')
);
```

Solo `value`, sin `format`, exactamente como
`myapi_claim_transaction_create_form_submit()`: la línea de tiempo lee la columna
cruda y le aplica `check_plain()` ella misma.

### Efecto sobre el título (SPEC 60)

La transacción inicial deja de ser "la que no tiene comentario", así que su
título autogenerado gana el cuarto segmento:

```
Reclamo #128 · Recibido · 03/08/2026 14:30 · Hemos recibido su reclamo. Será revisado por la…
```

Sin cambios en `myapi_claim_transaction_title()`: el truncado a 60 caracteres con
`truncate_utf8()` ya estaba, y el segmento entra por el mismo camino que
cualquier comentario escrito a mano.

---

## Tests unitarios

### `tests/unit/ClaimTransactionInitialCommentTest.php` — nuevo

| Caso | Afirma |
|---|---|
| `testClaimUsesTheClaimNoun()` | Texto exacto con "reclamo". |
| `testRequirementUsesTheRequirementNoun()` | Texto exacto con "requerimiento". |
| `testMissingTypeFallsBackToTheClaimNoun()` | `NULL` y `''` caen en "reclamo". |
| `testUnknownTypeFallsBackToTheClaimNoun()` | Un `allowed_value` inventado a futuro no es un tercer sustantivo. |
| `testCommentIsASingleLine()` | Sin saltos de línea: el texto viaja al título, donde un `\n` rompería el render. |
| `testTheCommentReachesTheAutogeneratedTitle()` | Compuesto con `myapi_claim_transaction_title()`, el título sale con los cuatro segmentos y el comentario truncado. |

No necesita stubs nuevos en `bootstrap.php`: `t()`, `truncate_utf8()` y
`format_date()` ya están desde SPEC 50/60.

**Fuera de este layer, dicho en voz alta y no omitido en silencio** (mismo
criterio que `ClaimTransactionTitleTest`): `myapi_claim_transaction_create_initial()`
—Field API y `node_save()`— y la rama `'reclamo'` de `myapi_node_insert()`, tres
líneas de glue. Van a la matriz manual.

---

## Plan de implementación

1. **`includes/myapi.claim_transaction_admin.inc` — `myapi_claim_transaction_initial_comment()` + escritura de `field_comment` + docblocks.** *Verificación: `php -l`.*
2. **`tests/unit/ClaimTransactionInitialCommentTest.php` (nuevo).** *Verificación: `vendor/bin/phpunit` en verde, suite entera.*
3. **`docs/claim-transaction-timeline.md` y `tests/README.md`.** *Verificación: lectura contra la implementación.*
4. **`drush cc all` + matriz manual** (ver criterios de aceptación).

---

## Criterios de aceptación

> Marcados los que se verificaron **en el repositorio** (`vendor/bin/phpunit`,
> `php -l`, lectura del diff). Los que quedan sin marcar necesitan el sitio
> desplegado.

**Comentario**

- [x] Crear un `reclamo` con tipo "Reclamo" genera la transacción inicial con el comentario del acuse y el sustantivo "reclamo".
- [x] Crear uno con tipo "Requerimiento" usa "requerimiento", con el resto de la frase idéntico.
- [x] Un tipo ausente o desconocido no deja la fila sin comentario: cae en "reclamo".
- [x] El comentario es una sola línea.
- [x] La primera fila de la línea de tiempo del reclamo muestra ese texto en la columna "Comentario", en vez de `—`.
- [x] El texto se ve sin escapar en pantalla (la línea de tiempo le aplica `check_plain()` al renderizar, no al guardar).

**Título (SPEC 60)**

- [x] El título de la transacción inicial pasa a tener sus cuatro segmentos, con el comentario truncado a 60 caracteres. *(`testTheCommentReachesTheAutogeneratedTitle()`.)*
- [x] `myapi_claim_transaction_title()` no cambia. *(No aparece en el diff más que su docblock.)*

**No regresión**

- [x] `field_status` de la transacción inicial se sigue copiando del reclamo, así que la sincronización de estado sigue sin encontrar diferencia y crear un reclamo no lo re-guarda. *(`myapi_claim_transaction_sync_claim_status()` no aparece en el diff.)*
- [x] Las otras tres rutas de creación de transacción no cambian. *(`myapi_claim_transaction_create_form_submit()` y el alter de SPEC 59 no aparecen en el diff.)*
- [x] `resources/*.resource.inc`, `hook_menu()` y `myapi.install` no aparecen en el diff.
- [x] `vendor/bin/phpunit` pasa entero. *(320 tests, 1068 aserciones — 314/1061 antes de este spec.)*
- [x] `drush cc all` no reporta errores.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| De dónde sale el sustantivo | Escrito en `myapi_claim_transaction_initial_comment()`, ramificando por el valor almacenado | Minuscularizar la etiqueta de `myapi_claims_claim_type_options()` | Ese catálogo alimenta las etiquetas de los `select` ("Reclamo", "Requerimiento"); un administrador editando un `allowed_value` desde la UI reescribiría la frase por la mitad. Mismo corte que ya declara `myapi_claims_valid_claim_type()` (SPEC 56): las **etiquetas** salen del campo, el **significado** no. |
| Dónde vive la frase | `t()` directo, en el include del back-office | Catálogo `myapi_t()` de `includes/myapi.i18n.inc` | Ese catálogo es de mensajes de respuesta del API (`docs/i18n.md`), resueltos por `Accept-Language`. Esto no es una respuesta: es un dato que se guarda en un campo y se lee después desde el back-office y desde la app. |
| Estructura de la función | Pura, recibe el tipo ya resuelto | Recibir el nodo del reclamo y leer el campo adentro | La lee `create_initial()`, que ya tiene el nodo y el helper. Mantener la función libre de Field API es lo que la hace testeable en `tests/unit/`, igual que SPEC 60 con `title()` / `set_title()`. |
| Reclamos ya creados | No se tocan | `hook_update_N` que rellene el comentario faltante | El acuse describe un instante (la recepción). Escribirlo hoy sobre reclamos de la semana pasada afirmaría algo que no pasó, y a diferencia del título de SPEC 60 —que es un rótulo derivado de datos que ya existían— acá el dato nuevo es una frase con contenido propio. |
| Tipo desconocido | Cae en "reclamo" | Omitir el comentario, o una tercera frase genérica | El campo es obligatorio en el bundle: llegar sin valor es dato corrupto, y el peor resultado posible es justamente la fila vacía que el spec viene a eliminar. "Reclamo" es además el nombre del propio bundle. |
| `format` del campo | Solo `value` | Escribir también `format => 'plain_text'` | Consistencia con el único otro camino del módulo que arma el nodo a mano (`myapi_claim_transaction_create_form_submit()`), y la línea de tiempo lee la columna cruda. Si algún día hiciera falta el formato, se agrega en los dos a la vez. |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El texto se vuelve el más visible del módulo** (primera fila de toda línea de tiempo) y cualquier retoque futuro cambia lo que ya leyeron los residentes. | Está pinneado al carácter en `ClaimTransactionInitialCommentTest` — un cambio accidental rompe el test antes de llegar a producción. Un cambio deliberado es una línea, más el test. |
| **Convivencia visible entre reclamos viejos (sin comentario) y nuevos.** | Es la consecuencia aceptada de no back-datear el acuse; los viejos se ven igual que hasta hoy, no peor. |
| **El título de la transacción inicial cambia de tres a cuatro segmentos**, así que los títulos viejos y los nuevos no se ven iguales. | El título se regenera en cada guardado (SPEC 60), y su formato ya contempla el segmento faltante. Ninguna funcionalidad depende del texto del título. |
| **`t()` con `@noun` aplica `check_plain()` al reemplazo** antes de guardar en un campo que se guarda crudo. | Los dos sustantivos no tienen ningún carácter escapable, así que el resultado es idéntico con o sin escape; los tests lo verifican por igualdad exacta. |

---

## Lo que **NO** está en este spec

- Rellenar el comentario de las transacciones iniciales ya existentes.
- Notificar (push/email) al residente con ese texto.
- Comentario automático en las otras tres rutas de creación.
- Traducción por `myapi_t()` / `i18n`.
- Cualquier endpoint `api/v1/...`, tests de integración o e2e.

Cada uno, si entra, va en su propio spec.
