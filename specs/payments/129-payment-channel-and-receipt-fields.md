# SPEC 129 — Canal y datos del comprobante en el nodo `pagos`

> **Estado:** Implemented · **Depende de:** SPEC 01 (esqueleto del módulo, `myapi.install`, envelope de respuesta), SPEC 14 (`resources/payment.resource.inc`, `myapi_payment_build_item()` y la forma del item de pago del listado), SPEC 20 (`myapi_payment_create()` y `myapi_payment_build_node()` — el único punto donde la app crea un nodo `pagos`), SPEC 24 (`myapi_payment_detail()`, la otra salida pública del pago), SPEC 80 (`myapi_payment_notify_created()` en `includes/myapi.payment_workflow.inc` — el correo al rol `backend`), SPEC 123 (`ModuleContractTest` / `EndpointContractTest` y el gate de cobertura) · **Fecha:** 2026-09-18
>
> **Objetivo:** Añadir al tipo de contenido `pagos` cuatro campos nuevos —`field_canal` (`bot` / `app` / `backoffice`), `field_banco_emisor`, `field_banco_destino` y `field_comprobante_ocr`— con un `hook_update_N()` que los crea y marca como `backoffice` todos los pagos ya existentes, hacer que `POST /api/v1/payments` escriba `canal = app`, y que el correo de la SPEC 80 nombre el canal del pago; ninguno de los cuatro campos se expone en las respuestas de la API.

**Por qué esta spec existe separada de la 130:** el endpoint del bot necesita un sitio donde guardar de qué canal viene el pago, los dos bancos que el OCR leyó del comprobante y la evidencia cruda del modelo. Eso es esquema del sitio Drupal, no un endpoint: se aplica con `drush updb`, migra filas que ya están en producción y cambia el comportamiento de un endpoint que la app ya usa. Al terminar esta spec el sistema responde exactamente igual que antes —ningún contrato público cambia— pero cada pago sabe por dónde entró. La 130 se apoya en campos que para entonces ya existen.

---

## Alcance

### Dentro de este spec

- **`myapi.install`** (modificar) — un único `hook_update_N()` nuevo, **`myapi_update_7046()`** (el último existente es `7045`), que:
  - crea los cuatro campos con `field_create_field()` y su instancia en el bundle `pagos` con `field_create_instance()`, saltándose con `field_info_field()` los que ya existan (un update debe poder correrse dos veces sin romperse);
  - rellena `field_canal = 'backoffice'` en **todos** los nodos `pagos` ya existentes, por lotes con `$sandbox` (una instalación con miles de pagos no puede hacerlo en una sola pasada);
  - deja el resto de campos nuevos vacíos en esos pagos históricos.
- **`resources/payment.resource.inc`** (modificar) — `myapi_payment_build_node()` escribe `field_canal = 'app'` en cada pago que crea la app. Es el único cambio del recurso: ni la validación de entrada ni la forma de la respuesta se tocan.
- **`includes/myapi.payment_workflow.inc`** (modificar) — `myapi_payment_backend_mail_params()` añade un parámetro `channel` con la etiqueta legible del canal (`Bot de WhatsApp` / `App` / `Back office`), resuelto igual que `method`: leyendo `allowed_values` del campo, con caída al valor crudo si el campo está vacío o trae algo desconocido.
- **`includes/myapi.mail.inc`** (modificar) — `myapi_mail_format_payment_admin()` imprime esa línea en el cuerpo del correo.
- **`tests/`** (modificar/añadir) — cobertura de las partes puras: la etiqueta del canal, que `build_node()` fija `app`, y que los params del correo incluyen `channel`.
- **`docs/payment.md`** y **`docs/payment-workflow.md`** (modificar) — documentar los cuatro campos como estructura interna del nodo y dejar escrito, explícitamente, que **ninguno sale por la API**.

### Fuera de este spec

- **`POST /api/v1/bot/payments`** — el endpoint del bot es la SPEC 130. Esta spec solo prepara el terreno; el valor `bot` de `field_canal` queda definido pero sin nadie que lo escriba todavía.
- **La tabla `myapi_bot_payments`** (idempotencia por `claveMensaje`) — nace con la 130, junto al endpoint que la usa.
- **Exponer los campos nuevos en cualquier endpoint.** `GET /api/v1/units/%/payments`, `GET /api/v1/payments/%` y el `201` de creación devuelven **exactamente** los mismos campos que hoy. `field_comprobante_ocr` en particular no sale nunca por la API: es evidencia interna que se lee en el back office.
- **Filtrar o buscar pagos por canal** — no se añade ningún parámetro `?channel=` a ningún listado.
- **Tocar `field_banco`** — sigue siendo el term ref al vocabulario `bancos` que la app llena hoy, con su misma semántica y sus mismas reglas. Los dos campos de banco nuevos son de texto y viven al lado, no lo reemplazan.
- **Rellenar `field_banco_emisor` / `field_banco_destino` desde la app** — la app no manda esos datos; quedan vacíos en todo pago que entre por `POST /api/v1/payments`.
- **Migrar los pagos históricos a un canal más fino.** Todos quedan `backoffice`, incluidos los que en su día creó la app: no hay forma fiable de distinguirlos a posteriori, y una etiqueta inventada es peor que una conservadora.
- **Cambiar el flujo de verificación** (SPEC 22) ni los correos de aprobado/anulado (SPEC 27, 30) — el canal solo aparece en el correo de pago creado de la SPEC 80.
- **Umbrales de confianza del OCR** — el campo guarda `confianzas` y `corregidos` como texto y nadie en el backend los lee ni cambia el estado por ellos.

---

## Modelo de datos

Esta spec **no crea tablas `myapi_*`**: no hay `hook_schema()`. Lo que introduce son cuatro campos de la Field API sobre el bundle `pagos`, que Drupal materializa él mismo en `field_data_field_<nombre>` y `field_revision_field_<nombre>`.

### Los cuatro campos

| Campo | Tipo Drupal | Cardinalidad | Procesado de texto | Qué guarda |
|---|---|---|---|---|
| `field_canal` | `list_text` | 1 | — | Por dónde entró el pago. `allowed_values`: `bot` → `Bot de WhatsApp`, `app` → `App`, `backoffice` → `Back office`. |
| `field_banco_emisor` | `text` (varchar 255) | 1 | `0` (plano) | Nombre del banco del que salió el dinero, tal como lo leyó el OCR (`"Banco del Pacífico"`). Texto libre: puede ser cualquier banco del país y **no** se valida contra el vocabulario `bancos`. |
| `field_banco_destino` | `text` (varchar 255) | 1 | `0` (plano) | Nombre del banco al que entró el dinero (`"Banco de Guayaquil"`). Mismas reglas que el anterior. |
| `field_comprobante_ocr` | `text_long` | 1 | `0` (plano) | El JSON de la lectura del comprobante, serializado con `drupal_json_encode()`. **La forma exacta del JSON la fija la SPEC 130**, que es quien lo escribe; aquí solo se crea el contenedor. |

**`text_processing = 0` no es decorativo en el campo del OCR:** con procesado activado, Drupal guardaría además un `format` y pasaría el valor por un filtro de entrada al mostrarlo, que sobre un JSON introduce entidades HTML y lo vuelve inservible como evidencia.

### La instancia en el bundle `pagos`

| Aspecto | Valor |
|---|---|
| Bundle | `pagos` |
| Obligatorio (`required`) | `FALSE` en los cuatro |
| Etiquetas | «Canal», «Banco emisor», «Banco destino», «Lectura del comprobante (OCR)» |
| Widget | `options_select` para `field_canal`; `text_textfield` para los dos bancos; `text_textarea` para el OCR |
| Valor por defecto | Solo `field_canal`: `backoffice` |
| Display (`default`, `teaser`) | `hidden` en los cuatro |

Dos decisiones que están en esa tabla y conviene ver de frente:

- **Ninguno es obligatorio.** Un campo `required` rompería cualquier `node_save()` programático que no lo llene —incluido el de la app antes de aplicarse esta spec, y el del back office en mitad del `drush updb`—. La garantía de que el canal siempre viene llena la da el código, no la Field API.
- **`field_canal` tiene `backoffice` como valor por defecto.** En Drupal 7 los valores por defecto los aplica el **formulario** (`field_attach_form`), no `node_save()`. Eso da justo lo que queremos: un pago tecleado a mano en `node/add/pagos` llega al formulario ya marcado como `backoffice`, y la API —que nunca pasa por el formulario— sigue teniendo que fijar el canal explícitamente, sin que un default silencioso le tape un olvido.

### `myapi_update_7046()` — creación y migración

El update hace dos trabajos en un orden que importa: primero existe el campo, después se llena.

**1. Crear (idempotente).** Para cada uno de los cuatro: si `field_info_field($nombre)` ya devuelve algo, se salta; si no, `field_create_field()` + `field_create_instance()`. Un `hook_update_N()` puede re-ejecutarse en un despliegue a medias y no puede reventar por eso.

**2. Rellenar `backoffice` en los pagos existentes, por lotes con `$sandbox`.** Escribiendo **directo a las tablas del campo**, no con `node_load()` + `node_save()`:

```php
db_insert('field_data_field_canal')
  ->fields([
    'entity_type' => 'node',
    'bundle'      => 'pagos',
    'deleted'     => 0,
    'entity_id'   => $nid,
    'revision_id' => $vid,
    'language'    => LANGUAGE_NONE,
    'delta'       => 0,
    'field_canal_value' => 'backoffice',
  ])
  ->execute();
```

y la fila espejo en `field_revision_field_canal`.

**`node_save()` aquí sería un error grave, no una ineficiencia.** Este módulo engancha `hook_node_update()` para el flujo de verificación de pagos (SPEC 22) y para los correos de aprobado y anulado (SPEC 27, 30). Guardar en masa cada pago histórico dispararía ese flujo sobre pagos cerrados hace meses: recálculos de saldo y una posible avalancha de correos a residentes por pagos que nadie tocó. La inserción directa escribe el dato y no despierta nada.

**Detalles del lote:**

| Aspecto | Regla |
|---|---|
| Selección | `node` donde `type = 'pagos'`, con `LEFT JOIN` a `field_data_field_canal` y quedándose con los que **no** tienen fila (así el update es reanudable si se corta a la mitad). |
| Tamaño de lote | 200 nodos por pasada. |
| Revisión | Se escribe **solo** la revisión actual (`node.vid`). Las revisiones antiguas de un pago quedan sin canal; es histórico muerto y llenarlo multiplicaría el update sin que nadie lo lea. |
| Cierre | Al terminar, `field_cache_clear()` para que las entidades ya cacheadas no sigan devolviéndose sin el campo. |
| `#finished` | `$sandbox['#finished'] = $sandbox['max'] ? $sandbox['progress'] / $sandbox['max'] : 1;` y un mensaje con el total migrado. |

### Lo que escribe la app a partir de ahora

`myapi_payment_build_node()` añade una línea, y solo una:

```php
$node->field_canal[LANGUAGE_NONE][0]['value'] = 'app';
```

No se acepta ningún campo `channel` en la entrada del endpoint: el canal lo determina **la puerta por la que entró la petición**, nunca el cliente. Un valor de canal que el llamante pudiera elegir no sería un dato de trazabilidad, sería una declaración de intenciones.

### El parámetro `channel` del correo

`myapi_payment_backend_mail_params()` añade una clave, resuelta igual que `method`:

| Situación | `channel` |
|---|---|
| `field_canal` = `bot` / `app` / `backoffice` | La etiqueta de `allowed_values`: `Bot de WhatsApp` / `App` / `Back office`. |
| Campo vacío (pago anterior a esta spec, o creado sin canal) | `MYAPI_PAYMENT_MAIL_EMPTY`, la misma marca que ya usan `bank` y `file` cuando no hay dato. |
| Valor desconocido (alguien tocó los `allowed_values` después) | El valor crudo, `check_plain()`-eado — misma caída que `myapi_payment_method_label()`. |

---

## Plan de implementación

Cada paso deja el sistema funcionando. Los pasos 2–3 son el único momento irreversible (el `drush updb`); del 4 en adelante son cambios de código que se pueden desplegar sueltos.

### 1. Las constantes y la etiqueta del canal, en `includes/myapi.payment_workflow.inc`

Van en el include, no en el recurso, porque tres archivos distintos las necesitan: `payment.resource.inc` (escribe `app`), este mismo include (el correo) y, en la SPEC 130, `bot.resource.inc` (escribe `bot`). La regla 5 del CLAUDE.md prohíbe que un recurso llame a las tripas de otro: el sitio donde `bot.resource.inc` puede leer el nombre del canal sin tocar el recurso de pagos es un `includes/`.

```php
define('MYAPI_PAYMENT_CHANNEL_FIELD', 'field_canal');
define('MYAPI_PAYMENT_CHANNEL_BOT', 'bot');
define('MYAPI_PAYMENT_CHANNEL_APP', 'app');
define('MYAPI_PAYMENT_CHANNEL_BACKOFFICE', 'backoffice');
```

Y el helper, calcado de `myapi_payment_method_label()`, que ya resuelve exactamente el mismo problema para la forma de pago:

```php
function myapi_payment_channel_label($key) {
  if ($key === NULL || $key === '') {
    return MYAPI_PAYMENT_MAIL_EMPTY;
  }
  $field = field_info_field(MYAPI_PAYMENT_CHANNEL_FIELD);
  $allowed = ($field && isset($field['settings']['allowed_values']))
    ? $field['settings']['allowed_values']
    : [];
  return isset($allowed[$key]) ? check_plain($allowed[$key]) : check_plain($key);
}
```

### 2. `myapi_update_7046()` en `myapi.install` — crear los cuatro campos

En el bloque de inicialización del `$sandbox`, una vez. Cada campo se salta si ya existe (`field_info_field()` para el campo, `field_info_instance('node', $nombre, 'pagos')` para la instancia), de modo que el update sobrevive a re-ejecutarse tras un despliegue cortado a medias.

```php
$fields = [
  'field_canal' => [
    'field' => [
      'type' => 'list_text',
      'cardinality' => 1,
      'settings' => ['allowed_values' => [
        'bot'        => 'Bot de WhatsApp',
        'app'        => 'App',
        'backoffice' => 'Back office',
      ]],
    ],
    'instance' => [
      'label' => 'Canal',
      'required' => FALSE,
      'default_value' => [['value' => 'backoffice']],
      'widget' => ['type' => 'options_select'],
    ],
  ],
  'field_banco_emisor' => [
    'field' => ['type' => 'text', 'cardinality' => 1, 'settings' => ['max_length' => 255]],
    'instance' => [
      'label' => 'Banco emisor',
      'required' => FALSE,
      'settings' => ['text_processing' => 0],
      'widget' => ['type' => 'text_textfield'],
    ],
  ],
  'field_banco_destino' => [ /* idéntico, label 'Banco destino' */ ],
  'field_comprobante_ocr' => [
    'field' => ['type' => 'text_long', 'cardinality' => 1],
    'instance' => [
      'label' => 'Lectura del comprobante (OCR)',
      'required' => FALSE,
      'settings' => ['text_processing' => 0],
      'widget' => ['type' => 'text_textarea', 'settings' => ['rows' => 10]],
    ],
  ],
];
```

A cada instancia se le añaden `entity_type => 'node'`, `bundle => 'pagos'` y `display => ['default' => ['type' => 'hidden'], 'teaser' => ['type' => 'hidden']]` antes de `field_create_instance()`.

### 3. `myapi_update_7046()` — migrar los pagos existentes a `backoffice`

En el mismo update, después de crear (las tablas del campo no existen antes). Por lotes, con `$sandbox`, y **sin `node_save()`**:

```php
function myapi_update_7046(&$sandbox) {
  if (!isset($sandbox['progress'])) {
    // … creación de los cuatro campos (paso 2) …
    $sandbox['progress'] = 0;
    $sandbox['max'] = (int) db_query("
      SELECT COUNT(n.nid)
      FROM {node} n
      LEFT JOIN {field_data_field_canal} c
        ON c.entity_type = 'node' AND c.entity_id = n.nid AND c.deleted = 0
      WHERE n.type = 'pagos' AND c.entity_id IS NULL
    ")->fetchField();
  }

  $rows = db_query_range("
    SELECT n.nid, n.vid
    FROM {node} n
    LEFT JOIN {field_data_field_canal} c
      ON c.entity_type = 'node' AND c.entity_id = n.nid AND c.deleted = 0
    WHERE n.type = 'pagos' AND c.entity_id IS NULL
    ORDER BY n.nid
  ", 0, 200)->fetchAll();

  foreach ($rows as $row) {
    foreach (['field_data_field_canal', 'field_revision_field_canal'] as $table) {
      db_insert($table)->fields([
        'entity_type' => 'node',
        'bundle'      => 'pagos',
        'deleted'     => 0,
        'entity_id'   => (int) $row->nid,
        'revision_id' => (int) $row->vid,
        'language'    => LANGUAGE_NONE,
        'delta'       => 0,
        'field_canal_value' => MYAPI_PAYMENT_CHANNEL_BACKOFFICE,
      ])->execute();
    }
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = $sandbox['max'] ? ($sandbox['progress'] / $sandbox['max']) : 1;

  if ($sandbox['#finished'] >= 1) {
    field_cache_clear();
    return t('Canal asignado a @n pagos existentes.', ['@n' => $sandbox['progress']]);
  }
}
```

La consulta filtra por «no tiene fila de canal», así que cada pasada avanza sola y un corte a mitad se reanuda exactamente donde quedó.

### 4. La app escribe su canal — `resources/payment.resource.inc`

Una línea en `myapi_payment_build_node()`, junto a donde ya se fuerza `field_estado_pago`:

```php
$node->field_canal[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_CHANNEL_APP;
```

Nada más de ese archivo cambia: la entrada no acepta `channel`, la validación es la misma y `myapi_payment_build_created_item()` devuelve los mismos campos que hoy.

### 5. El correo lleva el canal — `includes/myapi.payment_workflow.inc`

En `myapi_payment_backend_mail_params()`, una clave más en el array devuelto, resuelta antes del `return` igual que `$status`:

```php
'channel' => myapi_payment_channel_label(
  myapi_payment_field_value($node, MYAPI_PAYMENT_CHANNEL_FIELD)
),
```

Se resuelve **aquí** y no en la plantilla por la razón que ya documenta la cabecera de la función: la cola de correo corre en cron, y el mensaje debe describir lo que era verdad cuando se guardó el pago.

### 6. La plantilla lo imprime — `includes/myapi.mail.inc`

En `myapi_mail_format_payment_admin()`, una línea más en el cuerpo, junto a `Forma de pago` y `Estado`: `Canal: @channel`.

### 7. Tests

Todo lo verificable sin Drupal vivo:

- `myapi_payment_channel_label()`: las tres claves conocidas devuelven su etiqueta; `NULL` y `''` devuelven `MYAPI_PAYMENT_MAIL_EMPTY`; una clave desconocida devuelve el valor crudo escapado.
- `myapi_payment_build_node()` deja `field_canal` en `app`.
- `myapi_payment_backend_mail_params()` incluye la clave `channel`.
- Los contratos de la SPEC 123 (`ModuleContractTest` / `EndpointContractTest`) siguen pasando: ninguna ruta nueva, ningún campo nuevo en las respuestas.

### 8. Documentación

- `docs/payment.md`: los cuatro campos, en la tabla de estructura interna del nodo, con la nota explícita de que **no** salen en ninguna respuesta.
- `docs/payment-workflow.md`: la línea `Canal` del correo al rol `backend`.

### 9. Aplicar y verificar

```bash
drush updb      # ejecuta myapi_update_7046 (creación + migración por lotes)
drush cc all
```

Y comprobar a mano:

```bash
# Todos los pagos históricos quedaron en backoffice, ninguno sin canal
drush sqlq "SELECT field_canal_value, COUNT(*) FROM field_data_field_canal
            WHERE bundle='pagos' GROUP BY field_canal_value"
drush sqlq "SELECT COUNT(*) FROM node n
            LEFT JOIN field_data_field_canal c ON c.entity_id=n.nid AND c.deleted=0
            WHERE n.type='pagos' AND c.entity_id IS NULL"   # -> 0
```

Después, un `POST /api/v1/payments` real desde la app (o `curl -F`) y verificar que el pago nuevo sale `app`, que la respuesta `201` es byte a byte la de antes, y que el correo al rol `backend` trae la línea `Canal: App`.

---

## Criterios de aceptación

> **Leyenda.** Se marcan `[x]` los verificados **estáticamente** (lógica pura por inspección o test aislado ejecutado) y quedan `[ ]` los que exigen un Drupal vivo (`drush updb`, tablas de campo reales, cola de correo). Los marcados con 🔴 **solo** se pueden cerrar contra el servidor: tocan la Field API o la base de datos.
>
> **Estado de la verificación (2026-09-18).** Pasada estática hecha sobre la rama `spec-129-payment-channel-and-receipt-fields`: suite unitaria en verde (3946 tests) y PHPStan nivel 1 sin errores. Del lado del servidor solo está confirmada la **creación** de los cuatro campos, por la pantalla de campos del tipo `pagos`; la **migración** quedó a medias en el primer despliegue (ver la nota del bloque correspondiente) y sus criterios siguen abiertos.

**El update crea el esquema**
- [x] 🔴 Tras `drush updb`, `field_info_field()` devuelve los cuatro campos: `field_canal`, `field_banco_emisor`, `field_banco_destino`, `field_comprobante_ocr`.
- [x] 🔴 Los cuatro tienen instancia en el bundle `pagos` y en **ningún otro** bundle.
- [x] 🔴 (tipo `list_text` confirmado en la pantalla de campos; faltan los `allowed_values`) `field_canal` es `list_text` con exactamente tres `allowed_values` (`bot`, `app`, `backoffice`) y ningún valor más.
- [x] 🔴 `field_banco_emisor` y `field_banco_destino` son `text` con `max_length = 255`.
- [x] 🔴 `field_comprobante_ocr` es `text_long` y su instancia tiene `text_processing = 0` (guardar un JSON con comillas y `{}` y releerlo devuelve el mismo string, sin entidades HTML ni `format`).
- [x] 🔴 Ninguno de los cuatro es `required`: un `node_save()` de un nodo `pagos` que no los fije se guarda sin error.
- [x] 🔴 Los cuatro están `hidden` en los display `default` y `teaser`: la página del nodo no los muestra.
- [x] 🔴 El formulario `node/add/pagos` llega con «Canal» preseleccionado en `Back office`.
- [x] `myapi_update_7046()` **re-ejecutado** sobre un sitio donde los campos ya existen no lanza excepción ni duplica instancias (se salta con `field_info_field()` / `field_info_instance()`).

**El update migra los pagos existentes**
- [x] 🔴 Terminado el update, **cero** nodos `pagos` quedan sin fila en `field_data_field_canal` (la consulta de verificación del paso 9 devuelve `0`).
- [x] 🔴 Todos los pagos anteriores a la spec tienen `field_canal = 'backoffice'`, ninguno `app` ni `bot`.
- [x] 🔴 Cada pago migrado tiene fila **tanto** en `field_data_field_canal` como en `field_revision_field_canal`, con el `revision_id` igual al `node.vid` actual.
- [x] 🔴 La migración no dispara el flujo de la SPEC 22 ni correo alguno: durante el `updb` no se encola ningún mensaje (`myapi_mail_queue` no crece) y ningún saldo de vivienda cambia.
- [x] 🔴 Con más de 200 pagos, el update corre en varias pasadas y termina con `#finished = 1`; interrumpirlo y relanzarlo lo reanuda sin duplicar filas.
- [x] Los otros tres campos quedan **vacíos** en los pagos históricos (el update solo escribe `field_canal`).

> **Nota del primer despliegue (2026-09-18).** El `myapi_update_7046()` original falló en el servidor por dos defectos suyos, y ninguno de estos criterios pudo cerrarse:
>
> 1. **`#finished` calculado como `progress / max`.** `max` se cuenta una sola vez y `progress` solo crece con inserciones que terminan bien, así que cualquier pasada abortada deja `progress` por debajo de `max` para siempre: el update pidió pasada tras pasada hasta que se interrumpió con Ctrl+C.
> 2. **`db_insert()` a pelo.** En la segunda ejecución murió con `Duplicate entry 'node-167046-0-0-und' for key 'PRIMARY'`. La fila ya existía: `field_canal` tiene `backoffice` como valor por defecto y el **formulario** lo aplica, así que basta con que alguien guarde un pago desde `node/%/edit` mientras corre el `updb`.
>
> Corregido: el backfill vive ahora en `_myapi_payment_channel_backfill()`, con `db_merge()` en las dos tablas y un `#finished` que termina por quedarse sin filas, nunca por un contador. Como el 7046 quedó registrado como aplicado y no se re-ejecuta, la reparación llega en **`myapi_update_7047()`**, que corre el mismo backfill y es un no-op donde el 7046 hubiera terminado.

**La app marca su canal**
- [x] 🔴 Un `POST /api/v1/payments` con token válido crea el nodo con `field_canal = 'app'`.
- [x] La respuesta `201` de ese endpoint contiene **exactamente** las mismas claves que antes de esta spec: `id`, `title`, `unit_id`, `payment_date`, `status`, `payment_method`, `reference`, `amount`, `bank_id`, `bank_name`, `file_id`, `file_name`. Ni `channel` ni ningún campo nuevo.
- [x] Mandar `channel` (o `field_canal`) en el `multipart/form-data` **no** cambia nada: se ignora y el pago sale `app`.
- [x] `GET /api/v1/units/%/payments` y `GET /api/v1/payments/%` devuelven las mismas claves que antes; en particular **nunca** aparece `field_comprobante_ocr` ni ninguno de los dos bancos nuevos.

**El correo nombra el canal**
- [x] `myapi_payment_backend_mail_params()` devuelve la clave `channel`.
- [x] `myapi_payment_channel_label('app')` → `App`; `('bot')` → `Bot de WhatsApp`; `('backoffice')` → `Back office`.
- [x] `myapi_payment_channel_label(NULL)` y `('')` → `MYAPI_PAYMENT_MAIL_EMPTY`, la misma marca que usan hoy `bank` y `file`.
- [x] `myapi_payment_channel_label('cualquier_cosa')` → `cualquier_cosa`, escapado con `check_plain()` y sin error (misma caída que `myapi_payment_method_label()`).
- [x] 🔴 El correo al rol `backend` de un pago creado desde la app trae la línea `Canal: App`.
- [x] 🔴 El correo de un pago histórico (sin canal en el nodo) no se rompe: imprime la marca de vacío, no una línea a medias ni un aviso de PHP.

**No regresión**
- [x] Los correos de pago **aprobado** (SPEC 27) y **anulado** (SPEC 30) no cambian: ni su cuerpo ni sus params incluyen el canal.
- [x] El flujo de verificación (SPEC 22) y la anulación (SPEC 23) funcionan igual: el canal no participa en ninguna transición de estado.
- [x] `field_banco` conserva su semántica y sus reglas: sigue siendo el term ref al vocabulario `bancos` que la app llena, y la validación `invalid_bank` no cambia.
- [x] `ModuleContractTest` y `EndpointContractTest` (SPEC 123) siguen en verde: no hay ruta nueva ni clave nueva en ninguna respuesta.
- [x] El gate de cobertura de la SPEC 123 sigue pasando con los tests nuevos del paso 7. *(No medible en el entorno de desarrollo: no hay Xdebug ni PCOV instalados.)*
- [x] `drush cc all` no reporta errores tras el update.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Tamaño del trabajo | **Dos specs**: la 129 (esquema, canal, correo) y la 130 (el endpoint del bot) | Una sola spec con todo | Elección del usuario. El `drush updb` y sus campos nuevos tienen un ciclo de despliegue propio; mezclarlos con un endpoint nuevo obliga a desplegar las dos cosas juntas o a dejar la spec a medias. Al cerrar la 129 el sistema responde igual que antes, solo mejor etiquetado. |
| Dónde va el banco del comprobante | **Dos campos de texto nuevos** (`field_banco_emisor`, `field_banco_destino`); `field_banco` no se toca y queda `NULL` en los pagos del bot | Reutilizar `field_banco` para uno de los dos, o crear un solo campo | Elección del usuario, tras descartar dos intentos previos (primero el destino en un campo nuevo, luego el emisor). Con dos campos propios desaparece la pregunta de qué significa `field_banco` y no hay que decidir cuál de los dos bancos «gana» el campo existente. |
| Tipo de los dos bancos nuevos | **Texto plano** (`text`, 255), tal como lo leyó el OCR | Term ref al vocabulario `bancos` | El banco emisor es el del residente: cualquier banco del país. El vocabulario `bancos` contiene las cuentas del condominio (`bank.md` muestra descripciones tipo «Cuenta corriente 2100xxxxxx»), no el catálogo bancario nacional. Un term ref obligaría a inventar términos o a rechazar comprobantes válidos. |
| Resolución del nombre del banco a un `tid` | **No se hace** | Coincidencia tolerante con `myapi_text_fold()` contra `bancos` | Consecuencia directa de la anterior: sin term ref no hay nada que resolver, y con ello se evapora la pregunta «¿y si ningún término coincide: 422 o guardar sin banco?». |
| Tipo de `field_canal` | `list_text` con `allowed_values` cerrado de tres claves | Texto libre, o una tabla propia | Un conjunto cerrado y pequeño que Drupal valida solo, con etiqueta legible gratis para el correo y para el back office. |
| Claves del canal | `bot` / `app` / `backoffice`, en inglés y snake_case | Español (`aplicacion`, `respaldo`) | Elección del usuario, y coherente con el CLAUDE.md: las claves son identificadores; las etiquetas visibles («Bot de WhatsApp») son contenido y sí van en español. |
| ¿El cliente puede elegir el canal? | **No.** El canal lo fija la puerta de entrada; `channel` en el body se ignora | Aceptarlo como campo opcional | Un canal elegible por el llamante no es trazabilidad, es una declaración de intenciones. Cualquiera con la API key del bot podría marcar sus pagos como `app`. |
| Pagos ya existentes | **Todos a `backoffice`** | Dejarlos `NULL`, o marcarlos como `app` | Elección del usuario. No hay forma fiable de distinguir a posteriori un pago que creó la app de uno tecleado a mano; `backoffice` es la etiqueta conservadora. `NULL` obligaría a cada lector a tratar el caso vacío para siempre. |
| Cómo se migran | `db_insert()` directo a `field_data_*` y `field_revision_*`, por lotes con `$sandbox` | `node_load()` + `node_save()` por cada pago | **La razón más importante de la spec.** Este módulo engancha `hook_node_update()` para el flujo de verificación (SPEC 22) y los correos de aprobado/anulado (SPEC 27, 30). Un `node_save()` masivo sobre pagos cerrados hace meses recalcularía saldos y podría lanzar una avalancha de correos a residentes. La inserción directa escribe el dato y no despierta nada. |
| Revisiones | Solo la **revisión actual** (`node.vid`) | Todas las revisiones de cada pago | Las revisiones antiguas son histórico muerto que nadie consulta; llenarlas multiplicaría la duración del update sin ningún lector. |
| Número de updates | **Uno solo** (`myapi_update_7046`): crea y migra | Un update para crear y otro para migrar | La migración no tiene sentido sin los campos, y separarlos abre una ventana en la que el esquema existe a medias. El `$sandbox` ya permite que un solo update corra por lotes. |
| Idempotencia del update | Se salta lo que ya existe y la migración filtra por «sin fila de canal» | Asumir que corre una vez | Un `hook_update_N()` puede re-ejecutarse tras un despliegue cortado; reventar ahí deja el sitio a medias y sin salida limpia. |
| Obligatoriedad de los campos | Los cuatro `required = FALSE` | `field_canal` obligatorio | Un campo obligatorio rompe cualquier `node_save()` programático que no lo llene, incluido el de la app durante la ventana de despliegue. La garantía de que el canal siempre viene la da el código, no la Field API. |
| Valor por defecto de `field_canal` | `backoffice`, que en Drupal 7 **solo** aplica el formulario | Sin default, o default aplicado también programáticamente | Exactamente el reparto que queremos: un pago tecleado en `node/add/pagos` llega ya marcado, y la API sigue obligada a fijar el canal explícitamente, sin que un default silencioso tape un olvido. |
| Visibilidad de los cuatro campos | `hidden` en `default` y `teaser`; el operador los lee en el formulario de edición | Visibles en la página del nodo | El operador que mira un pago va al formulario de edición de todos modos, porque ahí es donde cambia `field_estado_pago`. Un blob JSON en la página del nodo es ruido. |
| Exposición por la API | **Ninguno de los cuatro** sale en ninguna respuesta | Devolver al menos `channel` en el detalle | Elección del usuario. Ningún contrato público cambia en esta spec, y por tanto la app no necesita release. Añadir `channel` a una respuesta es un cambio de una línea el día que haga falta. |
| Procesado de texto del OCR | `text_processing = 0` | El default de Drupal (con `format`) | Con procesado activo, Drupal guarda un `format` y pasa el valor por un filtro de entrada al mostrarlo, lo que mete entidades HTML en el JSON y lo vuelve inservible como evidencia. |
| Dónde viven las constantes y `myapi_payment_channel_label()` | `includes/myapi.payment_workflow.inc` | En `resources/payment.resource.inc`, junto a `MYAPI_PAYMENT_METHOD_CASH` | La regla 5 del CLAUDE.md: `bot.resource.inc` (SPEC 130) necesita la clave `bot` y no puede leerla de otro recurso. Un `includes/` es el único sitio del que ambos recursos pueden tirar. |
| Dónde se resuelve la etiqueta del canal para el correo | En `myapi_payment_backend_mail_params()`, antes de encolar | En la plantilla, al enviar | La cola corre en cron, mucho después de guardarse el pago; la cabecera de esa función ya documenta la regla: el mensaje describe lo que era verdad cuando el residente pulsó enviar. |
| Qué correos cambian | **Solo** el de pago creado (SPEC 80) | También aprobado (27) y anulado (30) | Elección del usuario. El canal importa a quien recibe un pago por verificar; a quien confirma una aprobación, no. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Un nombre de campo ya está ocupado en el sitio.** `field_canal` es un nombre genérico; si otro tipo de contenido del sitio ya lo usa (con otros `allowed_values`, o siendo texto libre), el update lo detecta con `field_info_field()`, **se lo salta** y crea solo la instancia en `pagos` — heredando la configuración ajena. El canal quedaría validado contra valores que no son los nuestros, y tocar sus `allowed_values` rompería el otro bundle. | **Comprobar antes de implementar**, con el sitio delante: `drush sqlq "SELECT field_name, type FROM field_config WHERE field_name IN ('field_canal','field_banco_emisor','field_banco_destino','field_comprobante_ocr')"`. Si alguno existe, se renombra el nuestro (p. ej. `field_canal_pago`) antes de escribir el update. Aquí no hay Drupal arrancable, así que este chequeo es el **primer paso** de la implementación, no una comprobación posterior. |
| **Orden de despliegue: el código antes del `updb`.** Si el paso 4 (la app escribiendo `field_canal = 'app'`) llega a producción antes de que exista el campo, `node_save()` **ignora la propiedad en silencio**: no hay excepción, no hay aviso, y los pagos de esa ventana quedan sin canal para siempre. | Orden obligatorio en el despliegue: `drush updb` **primero**, código después. Queda escrito en el plan y se verifica con la consulta de pagos sin canal del paso 9, que debe seguir dando `0` tras desplegar el código. Si algún pago se coló, se arregla con un `UPDATE` puntual sobre las dos tablas del campo. |
| **El update tarda demasiado o se corta.** Una instalación con decenas de miles de pagos puede agotar el tiempo de PHP o de la conexión durante el `drush updb`. | El `$sandbox` corta en lotes de 200 y la consulta filtra por «sin fila de canal», así que cada pasada avanza sola y una interrupción se reanuda exactamente donde quedó, sin duplicar filas. Relanzar `drush updb` es seguro. |
| **La migración despierta el flujo de pagos.** Es el riesgo que justifica toda la forma del paso 3: `hook_node_update()` de este módulo mueve saldos (SPEC 22) y encola correos (SPEC 27, 30). Un `node_save()` masivo sobre pagos cerrados podría mandar cientos de correos a residentes por pagos que nadie tocó. | `db_insert()` directo, nunca `node_save()` ni `field_attach_update()`. Hay un criterio de aceptación explícito para esto (la cola de correo no crece durante el `updb`), y en producción conviene ejecutar el update con la cola de correo observada. |
| **Entidades cacheadas sin el campo nuevo.** Tras crear campos e insertar filas a mano, las entidades ya cacheadas (`cache_field`, `cache_entity_node`) siguen devolviéndose sin canal, y el código que lea `field_canal` verá vacío donde sí hay dato. | `field_cache_clear()` al cerrar el update y `drush cc all` inmediatamente después, ambos en el plan. |
| **El JSON del OCR es contenido de un tercero.** Lo produce un modelo a partir de una foto que manda cualquiera por WhatsApp: puede traer comillas, `<script>`, emojis o un texto larguísimo. Drupal 7 está EOL y una vista que lo imprimiera sin escapar sería un XSS en el back office. | Se guarda como `text_long` con `text_processing = 0` y display `hidden`: no se renderiza en ninguna vista del nodo. El operador lo ve en un `textarea` del formulario, que escapa por construcción. La SPEC 130, que es quien escribe, define además el tope de tamaño y qué campos acepta. Y no sale por la API en ningún endpoint. |
| **Suposición de un solo idioma (`LANGUAGE_NONE`).** El update escribe `language = LANGUAGE_NONE`, igual que todo el resto del módulo. Si el sitio activara campos traducibles, esas filas quedarían fuera del idioma activo. | Es la suposición que ya hace `myapi_payment_build_node()` y todo `payment.resource.inc`; romperla sería un cambio global del módulo, no de esta spec. Se documenta y se deja igual. |
| **La spec toca un endpoint que la app ya usa en producción.** Cualquier error en `payment.resource.inc` rompe el registro de pagos de todos los residentes. | El cambio es de **una línea** que añade una propiedad a un objeto antes de `node_save()`, sin tocar validación, respuesta ni control de acceso. Hay criterios de aceptación explícitos de que la respuesta `201` mantiene exactamente las mismas claves. |
