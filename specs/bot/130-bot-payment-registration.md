# SPEC 130 — Registro de pagos desde el bot de WhatsApp (`POST /api/v1/bot/payments`)

> **Estado:** Approved · **Depende de:** SPEC 20 (`myapi_payment_create()`, `myapi_payment_save_file()`, `myapi_payment_normalize_date()`, `myapi_payment_reference_exists()`, `myapi_payment_build_node()` y `myapi_payment_build_created_item()` — el pago que este endpoint reproduce), SPEC 09 (`myapi_unit_related_nids()` en `includes/myapi.unit_access.inc`, la comprobación de propietario u ocupante), SPEC 80 (`myapi_payment_notify_created()` — el correo al rol `backend`), SPEC 123 (`ModuleContractTest` / `EndpointContractTest` y el gate de cobertura), SPEC 127 (`resources/bot.resource.inc`, `myapi_bot_require_api_key()` en `includes/myapi.bot_auth.inc` y el contrato de respuesta del bot), **SPEC 129** (`field_canal`, `field_banco_emisor`, `field_banco_destino`, `field_comprobante_ocr` y las constantes `MYAPI_PAYMENT_CHANNEL_*`) · **Fecha:** 2026-09-18
>
> **Objetivo:** Exponer `POST /api/v1/bot/payments`, autenticado con la misma API key de máquina de la SPEC 127, que recibe en `multipart/form-data` la lectura del comprobante hecha por el bot más la imagen del comprobante, revalida que la persona sea propietaria u ocupante de la unidad indicada, y crea el **mismo** nodo `pagos` que crea la app —con `canal = bot`, los dos bancos del OCR y la evidencia cruda guardada— de forma **idempotente** por mensaje de WhatsApp, de modo que un reintento de n8n nunca duplique un pago.

**El porqué, como tercer paso de la trilogía del bot:** la SPEC 127 responde «¿quién es este teléfono?» y la 128 «¿qué unidad es esta que me dictaron?». Las dos son de lectura y terminan en el mismo sitio: un `unit_id` y un `uid`. Este endpoint es lo que el bot llama **con esos dos datos en la mano y la foto del comprobante ya leída por el modelo**: el punto donde la conversación de WhatsApp se convierte en un pago pendiente de verificar, idéntico al que habría creado la app, distinguible solo por su canal.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.payment_write.inc`** (**nuevo**) — los helpers de escritura de un pago, **movidos tal cual** desde `resources/payment.resource.inc`, sin cambiarles la firma: `myapi_payment_normalize_date()`, `myapi_payment_reference_exists()`, `myapi_payment_save_file()`, `myapi_payment_build_node()` y `myapi_payment_build_created_item()`, más la constante `MYAPI_PAYMENT_METHOD_CASH`.
- **`resources/payment.resource.inc`** (modificar) — deja de definir esos helpers y los carga del include. **Cero cambios de comportamiento**: mismas funciones, mismas firmas, mismo endpoint.
- **`resources/bot.resource.inc`** (modificar) — el tercer endpoint del bot:
  - `myapi_bot_payment_dispatch()` — enruta por método; solo `POST`; cualquier otro → `405`.
  - `myapi_bot_payment_create()` — exige la API key, parsea el `multipart/form-data`, valida el payload, revalida identidad y unidad, resuelve la idempotencia, guarda el archivo y crea el nodo.
  - Helpers propios: lectura y validación del JSON, armado del sobre de evidencia y del nodo con los campos de la SPEC 129.
- **`myapi.module`** (modificar) — `api/v1/bot/payments` en `hook_menu()`, `MENU_CALLBACK`, `access callback => TRUE`, `file => resources/bot.resource.inc`, igual que las rutas de las SPEC 127 y 128.
- **`myapi.install`** (modificar) — `hook_schema()` para la tabla **`myapi_bot_payments`** (el libro de idempotencia) y **`myapi_update_7047()`** que la crea en sitios ya instalados.
- **`myapi.info`** (modificar) — `files[] = includes/myapi.payment_write.inc`.
- **`includes/myapi.i18n.inc`** y **`docs/i18n.md`** (modificar) — las claves de error nuevas.
- **`tests/`** (modificar/añadir) — cobertura de la validación del payload, del sobre de evidencia, del mapeo a nodo y del dispatcher.
- **`docs/bot.md`** (modificar) — la sección `POST /api/v1/bot/payments` completa, siguiendo la plantilla; y una nota en **`docs/payment.md`** de que existe una segunda puerta de creación de pagos.

### Por qué aparece un refactor: la regla 5 del CLAUDE.md

Este endpoint necesita exactamente lo que ya hace la app: normalizar la fecha, detectar referencia duplicada, guardar el comprobante como managed file, armar el nodo `pagos` y mapearlo a la respuesta. Todo eso vive hoy **dentro de `resources/payment.resource.inc`**, y la regla 5 prohíbe que un recurso llame a las tripas de otro; la 3 manda lo compartido a `includes/`.

Hay tres salidas y solo una es aceptable:

| Salida | Veredicto |
|---|---|
| `bot.resource.inc` llama a `myapi_payment_build_node()` | **Prohibido** por la regla 5. Ata los dos recursos: cualquier cambio en el de pagos rompe el del bot en silencio. |
| Copiar las cinco funciones al recurso del bot | **Prohibido** por la regla 3 y por «no duplicar lógica». Dos validaciones de fecha que se van separando es exactamente la deuda que el CLAUDE.md quiere evitar. |
| **Moverlas a `includes/myapi.payment_write.inc`** | Lo que hace esta spec. Un pago escrito por la app y uno escrito por el bot son **el mismo nodo**; el código que lo arma es lógica compartida, no lógica de un recurso. |

El movimiento es mecánico —cortar y pegar, sin tocar una línea de cuerpo— y lo respalda la batería de tests de la SPEC 20 que ya existe.

### Fuera de este spec

- **Crear o modificar campos Drupal** — los cuatro campos son de la SPEC 129, que debe estar aplicada (`drush updb`) antes de tocar esta.
- **Leer las confianzas del OCR.** `confidence` y `corrected` se guardan en la evidencia y **nadie en el backend los mira**: ningún umbral, ningún estado distinto, ningún rechazo automático. Decidir si un `0.3` merece repreguntar es del bot.
- **Un estado propio para los pagos del bot** — `field_estado_pago` sale siempre `"Pendiente de verificar"`, igual que la app. El backend revisa y cambia el estado por su cuenta, como hoy.
- **Verificar el teléfono del remitente.** Se guarda en la evidencia, pero **no** se comprueba contra el `uid`: la SPEC 128 permite llegar a la unidad por nombre precisamente cuando el teléfono no identificó a nadie, y exigir que coincidan rompería ese camino.
- **Descargar la imagen desde Meta.** El archivo llega en el `multipart`, ya descargado por n8n. El backend no habla con la API de WhatsApp.
- **Exponer los campos de la 129 por la API** — siguen sin salir en ninguna respuesta, tampoco en la de este endpoint.
- **Leer, listar, editar o anular pagos del bot** — no hay `GET /api/v1/bot/payments` ni filtro por canal. El pago se consulta por los endpoints que ya existen, que no distinguen el canal.
- **Notificar al residente por WhatsApp** que su pago quedó registrado — la respuesta del endpoint es lo que el bot usa para contestarle; el backend no manda mensajes.
- **Resolver el `unit_id` o el `uid`** — eso ya lo hicieron las SPEC 127 y 128. Este endpoint los recibe hechos y se limita a **revalidarlos**.
- **Segundo comprobante para el mismo pago** — un pago, un archivo (`field_archivo` es de valor único).
- **Correos nuevos** — se dispara el de la SPEC 80, con `Canal: Bot de WhatsApp` gracias a la 129. No se añade ninguno.

---

## Modelo de datos

### Transporte: `multipart/form-data`

| Parte | Tipo | Oblig. | Contenido |
|---|---|---|---|
| `payload` | campo de texto | **Sí** | El JSON de la lectura, como string. Máximo **64 KB**. |
| `file` | archivo | **Sí** | La imagen del comprobante. Mismas reglas que la app: `pdf jpg jpeg png`, ≤ 5 MB, MIME real verificado con `finfo`, guardado en `private://comprobantes_pago/`. |

El JSON viaja en un campo de texto y no como cuerpo de la petición porque el archivo obliga a `multipart`: es la misma decisión de la SPEC 20, y permite reutilizar `myapi_payment_save_file()` sin tocarlo.

### El payload (contrato en inglés)

Las claves son las que manda n8n tras renombrar su JSON. Los `@field` de los errores usan la **ruta con punto** (`receipt.amount`), para que el fallo se lea directo en el log de n8n.

| Clave | Tipo | Oblig. | Regla | Destino |
|---|---|---|---|---|
| `message.message_key` | string | **Sí** | No vacío. El `wamid` del mensaje de WhatsApp; primera mitad de la clave de idempotencia. | Libro de idempotencia + evidencia |
| `message.channel`, `message.provider`, `message.type` | string | No | Se guardan sin validar. | Evidencia |
| `message.sender.id`, `.local_phone`, `.name` | string | No | **No se validan contra el `uid`** (ver alcance). | Evidencia |
| `message.button.id`, `.title` | string | No | — | Evidencia |
| `message.received_at` | string | No | — | Evidencia |
| `identity.person.uid` | int | **Sí** | Entero > 0; el usuario debe existir y estar **activo** (`users.status = 1`). | `node->uid` |
| `identity.person.name` | string | No | Informativo; el nombre real sale de Drupal. | Evidencia |
| `identity.unit.unit_id` | int | **Sí** | Entero > 0; nodo existente, tipo `vivienda`, publicado; y el `uid` debe ser propietario u ocupante (`myapi_unit_related_nids()`). | `field_vivienda` |
| `identity.unit.condominium_id` | int | **Sí** | Entero > 0, y debe ser **exactamente** el `field_condominio` de esa vivienda. Si no coincide → `422 invalid_field`. | Solo evidencia |
| `identity.unit.unit`, `.condominium`, `.relation` | — | No | Informativos. Los **nombres** no se comparan con nada: se escriben de mil formas, los ids no. | Evidencia |
| `identity.source` | string | No | — | Evidencia |
| `media.ref` | string ≤64 | No | Identifica **cuál** adjunto del mensaje es este comprobante. Segunda mitad de la clave de idempotencia; ausente → `-`. | Libro + evidencia |
| `media.mime_type`, `media.file_name` | — | No | Informativos. El archivo real es la parte `file` del multipart; estos campos **no** lo validan. | Evidencia |
| `receipt.reference` | string | **Sí** | No vacío, ≤ 255, `check_plain()`. Duplicado en esa vivienda → `409`. | `field_referencia` |
| `receipt.amount` | número | **Sí** | Numérico y **> 0**. | `field_valor` |
| `receipt.date` | string | **Sí** | `YYYY-MM-DD` o `YYYY-MM-DDTHH:MM:SS` (`myapi_payment_normalize_date()`). Ausente → `422 missing_field`. **No hay caída a la hora del servidor.** | `field_fecha_de_pago` |
| `receipt.issuing_bank` | string | No | ≤ 255, `check_plain()`. Texto libre, sin validar contra `bancos`. | `field_banco_emisor` |
| `receipt.destination_bank` | string | No | Igual. | `field_banco_destino` |
| `receipt.confidence` | objeto | No | Se guarda y **no se lee**. | Evidencia |
| `receipt.corrected` | array | No | Igual. | Evidencia |
| `receipt.model` | string | No | Igual. | Evidencia |

**`receipt.date` es obligatoria aquí y opcional en la app**, a propósito: en la app hay una persona delante que sabe qué día pagó; aquí, una fecha ausente significa que el OCR no la leyó, y fechar el pago en el instante de la conversación en vez del día de la transferencia sería un dato falso que nadie detectaría después.

Una clave desconocida en el payload **no es un error**: se ignora para el nodo y viaja entera a la evidencia. Que el bot añada un campo mañana no debe tumbar el endpoint.

### Lo que fija el servidor

| Campo Drupal | Valor | Por qué |
|---|---|---|
| `node->type` | `pagos` | El mismo nodo que crea la app. |
| `node->title` | `"Pago {reference} - {YYYY-MM-DD}"` | Idéntico a la SPEC 20. |
| `node->uid` | `identity.person.uid` | El pago es del residente, no de una cuenta de servicio. |
| `node->status` | `1` | — |
| `field_estado_pago` | `"Pendiente de verificar"` | Forzado, igual que la app. |
| `field_forma_de_pago` | `"Transferencia"` | Un comprobante bancario nunca es efectivo. |
| `field_canal` | `bot` (`MYAPI_PAYMENT_CHANNEL_BOT`) | Lo fija la puerta de entrada, nunca el payload. |
| `field_banco` | **`NULL` siempre** | El term ref al vocabulario `bancos` es de la app; el bot usa los dos campos de texto de la SPEC 129. |

**`"Transferencia"` se valida igual que en la app**, contra los `allowed_values` de `field_forma_de_pago`. Si esa clave no existe en el campo —porque alguien la renombró en el back office— eso **no** es culpa del bot: se responde `500 server_error` y se registra en `watchdog`, en vez de un `422` que mandaría a n8n a buscar un error en su payload que no está ahí.

### La evidencia (`field_comprobante_ocr`)

Se guarda **el payload entero tal como llegó**, envuelto en una marca de cuándo se guardó:

```json
{
  "stored_at": "2026-09-18T14:03:12",
  "payload": { "message": {…}, "identity": {…}, "media": {…}, "receipt": {…} }
}
```

Serializado con `drupal_json_encode()`. Guardar el payload completo y no una selección es deliberado: es **evidencia**, y una evidencia recortada por el criterio de hoy no sirve para la pregunta de dentro de seis meses. Ahí quedan a la vez el `wamid` que originó el pago, el modelo que lo leyó, las confianzas de cada campo y qué corrigió el bot.

### Tabla `myapi_bot_payments` — el libro de idempotencia

```php
$schema['myapi_bot_payments'] = [
  'description' => 'Maps a WhatsApp message attachment to the payment node it created.',
  'fields' => [
    'idempotency_key' => ['type' => 'char', 'length' => 64, 'not null' => TRUE,
      'description' => 'sha256 hex of "<message_key>#<media_ref>".'],
    'message_key' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
    'media_ref'   => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE,
      'description' => 'The payload media.ref, or "-" when absent.'],
    'nid'         => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
    'uid'         => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
    'unit_nid'    => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
    'created'     => ['type' => 'int', 'not null' => TRUE],
  ],
  'primary key' => ['idempotency_key'],
  'indexes' => [
    'message_key' => ['message_key'],
    'nid'         => ['nid'],
    'created'     => ['created'],
  ],
];
```

- **`idempotency_key` es la clave primaria**, no una columna con índice: la unicidad la impone la base de datos, y es lo que hace la idempotencia a prueba de dos reintentos simultáneos de n8n.
- **Es un sha256 hexadecimal**, de 64 caracteres fijos, y no la concatenación en claro: `message_key` y `media_ref` tienen longitud incierta, y una clave primaria `varchar` en MySQL con `utf8mb4` no puede pasar de 191 caracteres (límite de 767 bytes del índice en InnoDB antiguo). El hash elimina de un golpe el problema de longitud y el de juego de caracteres.
- **`message_key` y `media_ref` se guardan además en claro**, con índice el primero: el hash sirve para la unicidad, no para auditar. Un operador que pregunte «¿qué pagos entraron por este mensaje de WhatsApp?» necesita leer el `wamid`.
- `uid`, `unit_nid` y `created` no participan en la idempotencia: están para auditar desde SQL cuántos pagos entró el bot, de quién y cuándo, sin recorrer nodos.

### Por qué la clave es compuesta

El payload trae `media.ref: "m-2"`, que identifica **cuál** de los adjuntos de un mensaje es este comprobante. Si una persona manda dos comprobantes en un solo mensaje de WhatsApp, los dos llegan con el **mismo** `message_key`: con la clave simple, el segundo se respondería como reintento con `200` y el pago equivocado, y un pago se perdería en silencio. `message_key + media_ref` cuesta una concatenación y cierra ese agujero; cuando el bot no manda `media.ref`, la segunda mitad es `-` y el comportamiento es el de la clave simple.

### El flujo de idempotencia

1. **Buscar** la `idempotency_key` en la tabla. Si hay fila y el nodo existe → `200` con ese pago, sin crear nada.
2. Si hay fila pero el nodo **fue borrado** → la fila está huérfana: se elimina y se sigue como si no existiera. Un pago borrado a mano en el back office no puede bloquear para siempre ese mensaje.
3. Validar todo el payload (y cortar con su error antes de tocar nada).
4. Guardar el archivo.
5. **En una transacción** (`db_transaction()`): `node_save()` + `db_insert()` en el libro.
   - Si el `INSERT` choca con la clave primaria, otro reintento ganó la carrera: se **revierte** la transacción entera (el nodo no llega a existir), se relee la fila ganadora y se responde `200` con su pago.
6. Fuera de la transacción, ya comprometida: el correo de la SPEC 80.

Que el correo vaya **después** del commit no es un detalle: dentro de la transacción, un reintento perdedor encolaría un correo que luego se revierte, o peor, un correo que sobrevive a un nodo que no existe.

### Respuesta

Idéntica a la de la app —mismas claves, mismo orden— tanto en el `201` como en el `200` idempotente:

```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 87,
      "title": "Pago 018273645 - 2026-09-12",
      "unit_id": 45821,
      "payment_date": "2026-09-12T00:00:00",
      "status": "Pendiente de verificar",
      "payment_method": "Transferencia",
      "reference": "018273645",
      "amount": 45.3,
      "bank_id": null,
      "bank_name": null,
      "file_id": 55,
      "file_name": "comprobante.jpg"
    }
  },
  "message": "Pago registrado correctamente."
}
```

`bank_id` y `bank_name` son **siempre `null`** en este endpoint: el bot no usa el vocabulario `bancos`. Los dos bancos que leyó el OCR quedan en el nodo pero **no** salen en la respuesta, como todo lo de la SPEC 129.

La única diferencia entre un pago nuevo y un reintento es el **código HTTP**: `201` contra `200`. El cuerpo es el mismo para que n8n no tenga que ramificar.

### Errores

| Código | `error_code` | Cuándo | ¿Nuevo? |
|---|---|---|---|
| 401 | `unauthorized` | `X-Api-Key` ausente, vacía o distinta. | Reutilizado (127) |
| 405 | `method_not_allowed` | Cualquier método que no sea `POST`. | Reutilizado |
| 422 | `invalid_payload` | `payload` ausente, vacío, mayor de 64 KB, o que no parsea a un objeto JSON. | **Nuevo** |
| 422 | `missing_field` | Falta un obligatorio; `@field` lleva la ruta con punto (`receipt.amount`). | Reutilizado |
| 422 | `invalid_field` | Presente pero con forma imposible (`identity.person.uid` no entero, usuario inactivo, unidad inexistente o no publicada, condominio que no cuadra). | Reutilizado |
| 422 | `invalid_amount` | `receipt.amount` no numérico o ≤ 0. | Reutilizado (20) |
| 422 | `invalid_date` | `receipt.date` con formato o fecha imposible. | Reutilizado (20) |
| 422 | `missing_file` | Sin parte `file`. | **Nuevo** |
| 422 | `invalid_file` / `invalid_file_type` | Extensión, tamaño o MIME real fuera de lo permitido. | Reutilizado (20) |
| 403 | `unit_access_denied` | El `uid` no es propietario ni ocupante de esa unidad. | Reutilizado (20) |
| 409 | `duplicate_reference` | Esa referencia ya existe en esa vivienda **con otra clave de idempotencia**. | Reutilizado (20) |
| 500 | `server_error` | `"Transferencia"` no está en los `allowed_values` del campo, o los campos de la SPEC 129 no existen. Se registra en `watchdog`. | Revisar catálogo |

**El `403` frente al `409` marca la diferencia de intención:** el `403` dice «el bot se equivocó de persona o de unidad» y manda a n8n a repreguntar; el `409` dice «este comprobante ya está registrado» y es una conversación terminada.

---

## Plan de implementación

### 1. Mover los helpers de escritura a `includes/myapi.payment_write.inc`

Cortar y pegar desde `resources/payment.resource.inc`, **sin tocar ningún cuerpo**: `MYAPI_PAYMENT_METHOD_CASH`, `myapi_payment_normalize_date()`, `myapi_payment_reference_exists()`, `myapi_payment_save_file()`, `myapi_payment_build_node()`, `myapi_payment_build_created_item()`. En el recurso queda el `module_load_include('inc', 'myapi', 'includes/myapi.payment_write')` y en `myapi.info` el `files[] =` correspondiente.

Se hace **primero y solo**, con los tests de la SPEC 20 en verde antes de escribir una línea del endpoint nuevo. Si algo se rompe aquí, se ve aislado y no mezclado con código nuevo.

### 2. `hook_schema()` + `myapi_update_7047()` en `myapi.install`

La tabla `myapi_bot_payments` de la sección anterior, en `hook_schema()` (para instalaciones nuevas) y en un `hook_update_N()` con `db_create_table()` guardado por `db_table_exists()` (para el sitio que ya corre). Es el update siguiente al `7046` de la SPEC 129, y **esta spec no se despliega sin aquella aplicada**.

### 3. La ruta, en `myapi.module`

```php
$items['api/v1/bot/payments'] = [
  'page callback'   => 'myapi_bot_payment_dispatch',
  'access callback' => TRUE,
  'type'            => MENU_CALLBACK,
  'file'            => 'resources/bot.resource.inc',
];
```

Igual que las de las SPEC 127 y 128: la autenticación se resuelve dentro del recurso.

### 4. `myapi_bot_payment_dispatch()`

`myapi_request_method()`; `POST` → `myapi_bot_payment_create()`; cualquier otro → `myapi_error('method_not_allowed', 405)`.

### 5. Parseo y validación del payload

Dos helpers puros, que son los que concentran casi todos los tests:

- **`myapi_bot_payment_parse_payload()`** — lee la parte `payload`, corta a 64 KB, `drupal_json_decode()`, exige que el resultado sea un array asociativo. Cualquier fallo → `422 invalid_payload`.
- **`myapi_bot_payment_validate(array $payload)`** — recorre el contrato en el orden de la tabla y devuelve los valores ya normalizados (`uid`, `unit_nid`, `condominium_id`, `reference`, `amount`, `date`, `issuing_bank`, `destination_bank`, `message_key`, `media_ref`). Un helper de lectura por ruta con punto, `myapi_bot_payload_get($payload, 'receipt.amount')`, evita una escalera de `isset()` anidados y da gratis el `@field` del error.

Las validaciones que ya existen se **reutilizan**, no se reescriben: `myapi_payment_normalize_date()` para la fecha y la misma comparación `is_numeric() && > 0` para el importe.

### 6. `myapi_bot_payment_create()` — el orden exacto

1. `myapi_bot_require_api_key()` (corta `401`).
2. **Guardia de esquema:** si `field_info_field('field_comprobante_ocr')` no devuelve nada, la SPEC 129 no está aplicada → `watchdog(WATCHDOG_ERROR)` y `500 server_error`. Es preferible un endpoint que no funciona a uno que guarda pagos mutilados.
3. Parsear el payload (paso 5) → `422 invalid_payload`.
4. Validar el payload (paso 5) → `422` con su `@field`.
5. **Idempotencia, primera pasada:** `$key = hash('sha256', $message_key . '#' . $media_ref);` y `SELECT nid FROM myapi_bot_payments WHERE idempotency_key = :key`.
   - Fila con nodo vivo → `myapi_respond(['payment' => …], 200, 'payment_created')`. **Fin, sin tocar nada más.**
   - Fila huérfana (nodo borrado) → `DELETE` de la fila y seguir.
6. **Persona:** `user_load($uid)`; debe existir y tener `status = 1` → si no, `422 invalid_field` con `@field = identity.person.uid`.
7. **Unidad:** `node_load($unit_nid)`; existente, tipo `vivienda`, publicada → si no, `422 invalid_field`. Su `field_condominio` debe ser igual a `identity.unit.condominium_id` → si no, `422 invalid_field` con `@field = identity.unit.condominium_id`.
8. **Acceso:** `in_array($unit_nid, myapi_unit_related_nids($uid))` → si no, `403 unit_access_denied`.
9. **Forma de pago:** `"Transferencia"` debe estar en los `allowed_values` de `field_forma_de_pago` → si no, `watchdog(WATCHDOG_ERROR)` y `500 server_error`.
10. **Referencia duplicada:** `myapi_payment_reference_exists($reference, $unit_nid)` → `409 duplicate_reference`.
11. **Archivo:** sin parte `file` → `422 missing_file`; con ella, `myapi_payment_save_file()` (que ya corta `422` por extensión, tamaño o MIME).
12. **Transacción:**
    ```php
    $tx = db_transaction();
    try {
      $node = myapi_payment_build_node($uid, $unit_nid, $reference, $amount,
                                       'Transferencia', NULL, $date, $file);
      myapi_bot_payment_decorate_node($node, $payload, $issuing, $destination);
      node_save($node);
      db_insert('myapi_bot_payments')->fields([
        'idempotency_key' => $key,
        'message_key'     => $message_key,
        'media_ref'       => $media_ref,
        'nid'             => (int) $node->nid,
        'uid'             => $uid,
        'unit_nid'        => $unit_nid,
        'created'         => REQUEST_TIME,
      ])->execute();
    }
    catch (Exception $e) {
      $tx->rollback();
      // Otro reintento ganó la carrera: releer y responder 200 con su pago.
    }
    unset($tx);   // commit
    ```
13. `file_usage_add($file, 'myapi', 'node', $node->nid)`.
14. **Fuera de la transacción**, ya comprometida: `myapi_payment_notify_created($node, $unit, NULL, $file)` — el correo de la SPEC 80, que gracias a la 129 dirá `Canal: Bot de WhatsApp`.
15. `myapi_respond(['payment' => myapi_payment_build_created_item($node, $file, NULL)], 201, 'payment_created')`.

El `NULL` del `bank_term` en los pasos 12, 14 y 15 es lo que deja `bank_id` y `bank_name` en `null`: el bot nunca usa el vocabulario `bancos`.

### 7. `myapi_bot_payment_decorate_node()`

El único trozo que el bot añade sobre el nodo que arma la app:

```php
$node->field_canal[LANGUAGE_NONE][0]['value'] = MYAPI_PAYMENT_CHANNEL_BOT;
$node->field_comprobante_ocr[LANGUAGE_NONE][0]['value'] = drupal_json_encode([
  'stored_at' => date('Y-m-d\TH:i:s'),
  'payload'   => $payload,
]);
if ($issuing !== NULL) {
  $node->field_banco_emisor[LANGUAGE_NONE][0]['value'] = $issuing;
}
if ($destination !== NULL) {
  $node->field_banco_destino[LANGUAGE_NONE][0]['value'] = $destination;
}
```

Está aparte de `myapi_payment_build_node()` a propósito: el nodo base es el mismo para los dos canales, y lo específico del bot no debe colarse en el camino de la app.

### 8. Catálogo i18n

Claves nuevas en `includes/myapi.i18n.inc` y `docs/i18n.md`: `invalid_payload`, `missing_file`, y `server_error` si no está ya. Se reutilizan `unauthorized`, `method_not_allowed`, `missing_field`, `invalid_field`, `invalid_amount`, `invalid_date`, `invalid_file`, `invalid_file_type`, `unit_access_denied`, `duplicate_reference` y el mensaje de éxito `payment_created`.

### 9. Tests

- `myapi_bot_payload_get()`: rutas con punto presentes, ausentes, con un escalón intermedio que no es array.
- `myapi_bot_payment_parse_payload()`: JSON válido, JSON roto, vacío, mayor de 64 KB, y un JSON que parsea a escalar o a lista.
- `myapi_bot_payment_validate()`: cada obligatorio ausente da su `@field`; importe ≤ 0; fecha ausente y fecha mal formada; referencia de 256 caracteres.
- La clave de idempotencia: mismo `message_key` con `media.ref` distinto da claves distintas; `media.ref` ausente cae a `-`.
- `myapi_bot_payment_decorate_node()`: canal `bot`, evidencia con el payload entero, los dos bancos, y **ausencia** de `field_banco`.
- El dispatcher: `GET`/`PUT`/`DELETE` → `405`.
- Contratos de la SPEC 123 con la ruta nueva registrada.

### 10. Documentación

`docs/bot.md`: sección `POST /api/v1/bot/payments` con la plantilla completa (auth, headers, tabla del payload, reglas del archivo, `201`/`200`, tabla de errores, ejemplo de `curl -F`) y la explicación de la idempotencia. En `docs/payment.md`, una nota de que existe una segunda puerta de creación.

### 11. Aplicar y verificar

```bash
drush updb && drush cc all
drush vget myapi_bot_api_key     # la misma clave de la SPEC 127
```

Y con `curl -F 'payload=@caso.json' -F 'file=@comprobante.jpg'`: caso feliz, **el mismo `message_key` dos veces** (`201` y luego `200` con el mismo `id`), el mismo `message_key` con `media.ref` distinto (dos pagos), unidad ajena, condominio que no cuadra, fecha ausente, sin archivo y referencia repetida.

---

## Criterios de aceptación

> **Leyenda.** Todos nacen sin marcar (`Draft`). Al implementar se marcan `[x]` los verificables **estáticamente** (lógica pura o test aislado) y quedan `[ ]` los que exigen un Drupal vivo. Los 🔴 solo se cierran contra el servidor: tocan base de datos, `node_save()`, ficheros o la cola de correo.
>
> **Estado al cerrar el paso 10 de la implementación.** Marcados los 24 verificables sin servidor, contra `tests/unit/BotPaymentCreateTest.php` (63 casos) y la suite completa (**4009 tests, 21486 aserciones, en verde**; PHPStan sin errores). Los 🔴 quedan sin marcar **por la regla de la leyenda**, aunque siete de ellos ya tienen cobertura unitaria sobre fixtures y llegan al servidor como confirmación y no como primer descubrimiento: los de identidad y acceso (los cuatro de su bloque) y los dos de configuración, más la ausencia de `field_banco`. Lo que ningún test de este nivel alcanza y solo el paso 11 cierra: `node_save()`, el `INSERT` en el libro y su choque contra la clave primaria, la carrera de dos reintentos simultáneos, el archivo gestionado y la cola de correo.

**Autenticación y método**
- [x] Sin `X-Api-Key`, con la cabecera vacía o con una clave distinta → `401 unauthorized`, y **ninguna tabla se lee** antes de rechazar. — `testAMissingOrWrongKeyIs401`; lo de la tabla, `EndpointContractTest::testAnUnkeyedRequestReachesNoTable` con la ruta ya registrada.
- [x] Con `myapi_bot_api_key` sin configurar, **toda** petición es `401`. — `testAnUnconfiguredKeyRefusesEveryRequest`, incluida la que no manda clave.
- [x] `GET`, `PUT`, `DELETE` o `PATCH` sobre `/api/v1/bot/payments` → `405 method_not_allowed`. — `testEveryOtherMethodIs405` (seis métodos) y `EndpointContractTest`.
- [x] La clave que autentica es la **misma** de las SPEC 127 y 128: no hay una segunda variable. — `testItIsTheSameKeyAsTheOtherTwoBotEndpoints`: `myapi_bot_api_key` se lee en un solo sitio.

**Caso feliz**
- [ ] 🔴 Payload completo y válido + imagen → `201` con `{ "success": true, "data": { "payment": {…} }, "message": … }`.
- [x] La respuesta trae **exactamente** las mismas claves que el `201` de la app. — `testTheBodyCarriesExactlyTheKeysTheAppAnswers`, comparando contra `myapi_payment_build_created_item()` y no contra una lista escrita a mano. **Corrección al enunciado:** son **trece** claves, no las doce de arriba — el mapper de la app ganó `detail` después de redactarse este spec, y la promesa real («las mismas que la app») se cumple por construcción al compartir mapper.
- [ ] 🔴 `bank_id` y `bank_name` son `null`, y el nodo **no** tiene `field_banco`. *(Cubierto sin servidor por `testTheBodyNeverCarriesABank` y `testTheBotNeverTouchesTheBankTermReference`.)*
- [ ] 🔴 El nodo es tipo `pagos`, publicado, `uid` = `identity.person.uid`, `title` = `"Pago {reference} - {YYYY-MM-DD}"`.
- [ ] 🔴 `field_estado_pago` = `"Pendiente de verificar"` y `field_forma_de_pago` = `"Transferencia"`.
- [ ] 🔴 `field_canal` = `bot`.
- [ ] 🔴 `field_banco_emisor` y `field_banco_destino` guardan los nombres del payload tal cual (`check_plain()`-eados), sin resolverse contra el vocabulario `bancos`.
- [ ] 🔴 `field_comprobante_ocr` contiene un JSON con `stored_at` y `payload`, y el `payload` es **idéntico** al recibido: incluye `confidence`, `corrected`, `model` y `message.message_key`.
- [ ] 🔴 El archivo queda en `private://comprobantes_pago/`, permanente, con fila en `file_usage` para el nodo.
- [ ] 🔴 Se encola el correo de la SPEC 80 al rol `backend`, con la línea `Canal: Bot de WhatsApp`.
- [x] Una clave desconocida en el payload no provoca error: se ignora para el nodo y aparece entera en la evidencia. — `testAnUnknownKeyIsIgnoredAndNotAnError` y `testAnUnknownKeyReachesTheEvidence`.

**Idempotencia**
- [ ] 🔴 El **mismo `message_key` y `media.ref` dos veces** devuelve `201` la primera y `200` la segunda, **con el mismo `payment.id`**, y en la base hay **un** solo nodo `pagos` y **una** sola fila en `myapi_bot_payments`.
- [ ] 🔴 El mismo `message_key` con **`media.ref` distinto** crea **dos** pagos: un mensaje con dos comprobantes no pierde el segundo. *(La mitad pura — que las claves difieren — en `testTwoAttachmentsOfOneMessageGetDifferentKeys`.)*
- [x] `media.ref` ausente cae a `-`, y dos peticiones sin `media.ref` y con el mismo `message_key` son el mismo pago. — `testAnAbsentMediaRefFallsBackToTheSentinel` y `testTwoCallsWithoutMediaRefShareOneKey`.
- [x] El cuerpo del `200` es idéntico al del `201`: mismas claves, mismos valores. La única diferencia es el código HTTP. — `testTheIdempotentTwoHundredIsTheSameBodyAsTheTwoHundredAndOne`, comparando los bytes impresos.
- [ ] 🔴 El reintento **no** encola un segundo correo ni crea un segundo archivo.
- [ ] 🔴 Un reintento con la misma clave pero **payload distinto** (otro importe) devuelve el pago original sin modificarlo: el primer mensaje manda.
- [ ] 🔴 Si el nodo de esa clave fue **borrado** en el back office, la fila huérfana se elimina y la petición crea un pago nuevo.
- [ ] 🔴 Dos peticiones **simultáneas** con la misma clave acaban en un solo nodo: la perdedora revierte su transacción y responde `200` con el pago de la ganadora.
- [ ] 🔴 `idempotency_key` es clave primaria de `myapi_bot_payments`: un `INSERT` duplicado lanza excepción en la base, no se confía en un `SELECT` previo. *(Que está declarada como clave primaria: `testTheLedgerKeyIsThePrimaryKey`. Que la base lanza: solo el servidor.)*
- [x] `message_key` y `media_ref` quedan guardados **en claro** junto al hash, para poder auditar por `wamid` desde SQL. — `testTheLedgerKeepsBothHalvesInTheClear`, con el índice sobre `message_key`.

**Identidad y acceso**
- [ ] 🔴 `identity.person.uid` de un usuario inexistente o **bloqueado** (`status = 0`) → `422 invalid_field` con `@field = identity.person.uid`. *(Cubierto sobre fixtures por `testAnInactiveOrUnknownPersonIs422`.)*
- [ ] 🔴 `identity.unit.unit_id` inexistente, no publicada o de otro tipo → `422 invalid_field`. *(Cubierto por `testAMissingOrUnpublishedUnitIs422`, los tres casos.)*
- [ ] 🔴 `identity.unit.condominium_id` que **no** es el `field_condominio` de esa vivienda → `422 invalid_field` con `@field = identity.unit.condominium_id`, y no se crea nada. *(Cubierto por `testACondominiumThatDoesNotMatchTheUnitIs422` y `testAUnitWithNoCondominiumIs422`.)*
- [ ] 🔴 Un `uid` que no es propietario ni ocupante de esa unidad → `403 unit_access_denied`, y no se crea nada. *(Cubierto por `testAForeignUnitIs403`.)*
- [x] `message.sender.local_phone` que no corresponde al `uid` **no** provoca error: el teléfono no se valida (la SPEC 128 llega a la unidad sin él). — `testThePhoneAndTheNamesAreNotValidated`.
- [x] `identity.unit.unit` e `identity.unit.condominium` (los nombres) no se comparan con nada. — mismo test, con nombres que no existen en el sitio.

**Validación del payload**
- [x] Sin parte `payload`, vacía, mayor de 64 KB, o que no parsea a objeto JSON → `422 invalid_payload`. — `testAnAbsentOrEmptyPayloadIs422`, `testAPayloadOverTheCapIs422` (y `testAPayloadUnderTheCapParses`, para que el tope no sea un off-by-one).
- [x] Un `payload` que parsea a escalar o a lista (`[1,2,3]`) → `422 invalid_payload`, no un error de PHP. — `testJsonThatIsNotAnObjectIs422`, diez formas.
- [x] Falta cualquiera de los siete obligatorios → `422 missing_field` con la **ruta con punto** en `@field`. — `testEveryRequiredFieldReportsItsDottedPath`, uno por uno; más `testAMissingBranchStillReportsALeafPath` y `testAnEmptyRequiredStringIsMissing`.
- [x] `receipt.amount` no numérico, `0` o negativo → `422 invalid_amount`. — `testANonPositiveOrNonNumericAmountIsInvalid`.
- [x] `receipt.date` ausente → `422 missing_field`; con formato o fecha imposible (`2026-02-30`) → `422 invalid_date`. **No** hay caída a la hora del servidor. — `testAnAbsentDateIsMissingAndNeverTheServerClock` (que además comprueba que la fecha de hoy no aparece en la respuesta) y `testAnImpossibleDateIsInvalid`.
- [x] `receipt.date` = `"2026-09-12"` se guarda como `2026-09-12T00:00:00`. — `testADateIsNormalizedLikeTheApp`; el valor que llega al nodo, en `testDecoratingLeavesTheBaseNodeAlone`.
- [x] `receipt.reference` de más de 255 caracteres → `422 invalid_field`. — `testAReferenceOverTwoHundredAndFiftyFiveIsInvalid`, con los dos lados del límite.
- [x] `myapi_bot_payload_get()` devuelve `NULL` sin aviso de PHP cuando un escalón intermedio falta o no es array. — cinco tests, incluido el escalón escalar y el `NULL` explícito.

**Archivo**
- [x] Sin parte `file` → `422 missing_file`, y no se crea nodo ni fila en el libro. — `testWithoutTheFilePartItIs422AndNothingIsWritten`, que además comprueba que no hubo ninguna escritura.
- [ ] 🔴 Extensión fuera de `pdf/jpg/jpeg/png`, archivo > 5 MB, o MIME real distinto del declarado (un `.php` renombrado a `.jpg`) → `422`, sin nodo.

**Duplicados**
- [ ] 🔴 Una `reference` que ya existe en esa vivienda con **otra** clave de idempotencia → `409 duplicate_reference`.
- [ ] 🔴 La misma `reference` en **otra** vivienda → `201`.

**Configuración**
- [ ] 🔴 Si los campos de la SPEC 129 no existen (spec sin aplicar) → `500 server_error` y entrada en `watchdog`, **no** un pago guardado a medias. *(Cubierto por `testWithoutTheSpec129FieldsItIs500AndLogged` y `testTheSchemaGuardRunsBeforeThePayload`.)*
- [ ] 🔴 Si `"Transferencia"` no está en los `allowed_values` de `field_forma_de_pago` → `500 server_error` y una entrada en `watchdog`, **no** un `422`. *(Cubierto por `testAMissingPaymentMethodKeyIs500AndNotA422`.)*

**No regresión**
- [x] El movimiento de los cinco helpers a `includes/myapi.payment_write.inc` no cambia nada del endpoint de la app: los tests de la SPEC 20 pasan **sin modificarse**. — `PaymentEndpointTest` intacto y en verde, también ejecutado en aislado (52 tests). El bloque movido se comparó byte a byte contra el original: ni una línea cambiada.
- [x] `GET /api/v1/bot/person` (127) y `GET /api/v1/bot/units` (128) responden igual: solo comparten archivo de recurso. — `BotPersonTest` y `BotUnitsSearchTest` intactos y en verde.
- [ ] Los endpoints de pago de la app (listado, detalle, crear, anular) responden igual, y un pago del bot aparece en ellos como uno más. — *Primera mitad verificada (sus tests en verde, sin tocar); que un pago del bot aparezca en ellos exige el servidor.*
- [ ] `ModuleContractTest` y `EndpointContractTest` (SPEC 123) en verde con la ruta nueva, y el gate de cobertura pasa. — *Los dos contratos, en verde. **El gate de cobertura no se ha ejecutado**: la máquina de desarrollo no tiene Xdebug ni PCOV (`No code coverage driver available`). Queda para CI o para el paso 11.*
- [ ] `myapi.info` lista `includes/myapi.payment_write.inc` y `drush cc all` no reporta errores. — *Lo primero lo asegura `ModuleContractTest`; `drush cc all` exige el sitio.*
- [x] `docs/bot.md` incluye la sección completa del endpoint. — sección `POST /api/v1/bot/payments` con plantilla completa, subsección de idempotencia y el requisito de `media.ref` estable para n8n; `ModuleContractTest::testEveryEndpointIsDocumented` en verde.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Transporte | `multipart/form-data`: el JSON en el campo `payload`, la imagen en `file` | JSON puro con el archivo en base64; JSON con una URL que Drupal descarga | Elección del usuario. Reutiliza `myapi_payment_save_file()` sin tocar una línea. Base64 infla ~33% y obliga a escribir un guardado nuevo; una URL convierte al backend en cliente HTTP saliente, que en un Drupal 7 EOL es una superficie de SSRF que no hace falta abrir. |
| Idioma del contrato de entrada | **Inglés** (`receipt.amount`, `identity.person.uid`…); n8n renombra su JSON | Aceptar el español del bot tal cual | Elección del usuario, y el CLAUDE.md: el contrato público del módulo es inglés. Renombrar claves en n8n es un nodo de mapeo. |
| Idioma de la evidencia guardada | Lo que llega, es decir **inglés** | Español, como el JSON original del bot | Consecuencia de la anterior. La evidencia es «lo que entró por el cable»; traducirla al guardarla la dejaría de ser evidencia. |
| ¿Confiar en la identidad que manda el bot? | **Revalidar**: usuario activo, unidad publicada, y propietario u ocupante | Confiar y guardar lo que llegue | Elección del usuario. La API key es una credencial de **máquina**, no de persona: sin esta comprobación, cualquiera que tuviera la clave imputaría pagos a cualquier unidad. |
| Coherencia unidad ↔ condominio | `identity.unit.condominium_id` **obligatorio** y debe ser el `field_condominio` de esa vivienda; si no, `422` | Ignorarlo (solo evidencia) | Elección del usuario. Un par incoherente es un bug del flujo de n8n, y sin esta comprobación se guardaría en silencio durante meses. Se comparan **ids**, nunca los nombres. |
| Verificar el teléfono del remitente | **No** | Comprobar que `sender.local_phone` es del `uid` (lógica de la SPEC 127) | Elección del usuario. La SPEC 128 existe precisamente para llegar a la unidad **cuando el teléfono no identificó a nadie**; exigir que coincidan cerraría ese camino. El teléfono se guarda en la evidencia. |
| `node->uid` | `identity.person.uid`, el residente | Un usuario de servicio del bot, o `uid` 1 | El pago es del residente. Además `myapi_payment_resident_label()` de la SPEC 80 lo usa para nombrar a quién pagó en el correo. |
| `payment_method` | **Forzado** a `"Transferencia"` | Que el bot lo mande | Elección del usuario: un comprobante bancario nunca es efectivo, y un campo menos es un campo menos que validar. |
| Si `"Transferencia"` no existe en el campo | `500 server_error` + `watchdog` | `422 invalid_payment_method` | Un `422` mandaría a n8n a buscar un error en su payload que no está ahí. Es un fallo de configuración del sitio y debe leerse como tal. |
| Qué banco va a `field_banco` | **Ninguno**: siempre `NULL` en los pagos del bot | Resolver `destination_bank` contra el vocabulario `bancos` | Decidido en la SPEC 129: los dos bancos del OCR viven en campos de texto propios. Así desaparece la pregunta «¿y si el nombre no coincide con ningún término?». |
| Obligatoriedad de la imagen | **Obligatoria** → `422 missing_file` | Opcional, como en la app | Elección del usuario. Un pago del bot sin comprobante no hay forma de verificarlo; el `422` hace que el flujo de n8n falle ruidosamente en vez de crear pagos ciegos. |
| Obligatoriedad de `receipt.date` | **Obligatoria** (más estricta que la app) | Opcional con caída a la hora del servidor, como la SPEC 20 | Elección del usuario. En la app hay una persona que sabe qué día pagó; aquí, una fecha ausente significa que el OCR no la leyó, y fechar el pago en el instante de la conversación sería un dato falso que nadie detectaría después. |
| Qué se guarda como evidencia | **El payload entero**, envuelto con `stored_at` | Una selección de campos (solo `receipt` + `message_key`) | Una evidencia recortada por el criterio de hoy no responde la pregunta de dentro de seis meses. Guardar entero cuesta un `drupal_json_encode()`. |
| Claves desconocidas en el payload | Se ignoran para el nodo y viajan a la evidencia | Rechazar con `422` | Que el bot añada un campo mañana no debe tumbar el endpoint; y como la evidencia es el payload entero, el campo nuevo queda guardado sin tocar nada. |
| Umbrales de confianza | El backend **no** los mira | Rechazar o marcar distinto un `confidence` bajo | Elección del usuario. Decidir si un `0.3` merece repreguntar es del bot, que es quien tiene la conversación abierta. El backend los guarda para poder auditarlos. |
| Estado del pago | `"Pendiente de verificar"`, igual que la app | Un estado propio para el canal bot | Elección del usuario: el backend revisa por su cuenta y cambia el estado, sin distinguir de dónde vino. |
| Clave de idempotencia | `message.message_key` **+ `media.ref`** | Solo el `message_key`; la `reference`; un hash del payload | El `wamid` identifica el **mensaje**, que es lo que n8n reintenta, pero un mensaje puede traer varios adjuntos (`media.ref: "m-2"`): con la clave simple, el segundo comprobante de un mensaje se respondería como reintento y el pago se perdería en silencio. La `reference` identifica el comprobante, que es otra cosa: la misma persona puede reenviar el mismo comprobante en dos mensajes, y eso sí es un duplicado que hay que rechazar. |
| Forma de la clave primaria | **sha256 hex** (64 caracteres) de `"<message_key>#<media_ref>"` | La concatenación en claro como `varchar` | Los dos trozos tienen longitud incierta y una clave primaria `varchar` no puede pasar de 191 caracteres con `utf8mb4` (límite de 767 bytes del índice en InnoDB antiguo). El hash fija la longitud y elimina el problema de juego de caracteres. Los dos trozos se guardan además **en claro** en sus columnas, porque el hash sirve para la unicidad, no para auditar. |
| Dónde vive la idempotencia | Tabla `myapi_bot_payments` con clave primaria | Un campo Drupal `field_mensaje_clave` | Elección del usuario (13a). La Field API de Drupal 7 no indexa el valor de un campo de texto: la búsqueda iría sin índice y dos reintentos simultáneos duplicarían igual. Una clave primaria lo resuelve en la base. |
| Cómo se resuelve la carrera | `db_transaction()` + choque contra la clave primaria → `rollback` y responder `200` con el pago ganador | Fila «en curso» con `nid = 0` y un estado intermedio; un `LOCK` | La transacción no deja estados intermedios que limpiar ni un tiempo de expiración que inventar: o existe el pago y su fila, o no existe ninguno de los dos. |
| Respuesta del reintento | `200` con el **mismo cuerpo** que el `201` | `409`; o un `200` con un campo `idempotent: true` | Elección del usuario (14 y 19). n8n no ramifica: siempre lee `data.payment`. El código HTTP distingue el caso para quien mire los logs. |
| Referencia repetida con otra clave | `409 duplicate_reference` | Aceptarlo como pago nuevo | Elección del usuario. Es el mismo comprobante mandado dos veces en mensajes distintos: un duplicado real, no un reintento. |
| Nodo huérfano en el libro | Se borra la fila y se crea un pago nuevo | Responder `200` con un `payment` de un nodo que ya no existe; o `409` | Un pago borrado a mano en el back office no puede bloquear ese mensaje para siempre. |
| Cuándo se manda el correo | **Después** de comprometer la transacción | Dentro | Dentro, un reintento perdedor encolaría un correo que luego se revierte, o peor, uno que sobrevive a un nodo que no llegó a existir. |
| Dónde viven los helpers compartidos | **Movidos** a `includes/myapi.payment_write.inc` | Llamarlos desde `payment.resource.inc`; copiarlos al recurso del bot | Reglas 3 y 5 del CLAUDE.md. Un pago escrito por la app y uno escrito por el bot son el mismo nodo: el código que lo arma es lógica compartida. Llamar al otro recurso ata los dos; copiarla crea dos validaciones de fecha que se irán separando. |
| Lo específico del bot sobre el nodo | Un `myapi_bot_payment_decorate_node()` aparte | Añadir parámetros a `myapi_payment_build_node()` | El nodo base es idéntico para los dos canales; meterle parámetros del bot contaminaría el camino de la app, que es el que usan todos los residentes. |
| `@field` de los errores | La **ruta con punto** (`receipt.amount`) | Solo el nombre de la hoja (`amount`) | El payload es anidado y tiene más de un `reference` posible; la ruta completa hace que el error se lea directo en el log de n8n. |
| Ruta | `api/v1/bot/payments` (plural) | `api/v1/bot/payment`, como se pidió al principio | El CLAUDE.md fija plural en inglés para los recursos, y las SPEC 127/128 ya usan `bot/person` y `bot/units`. |
| Tope del payload | 64 KB | Sin tope; o el de PHP | El payload es texto estructurado de un bot: 64 KB son órdenes de magnitud más de lo que necesita. Sin tope, el `drupal_json_decode()` de un cuerpo enorme es una denegación de servicio barata sobre un Drupal 7 EOL. |

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **La API key deja de ser de solo lectura.** Hasta ahora abría dos consultas (SPEC 127, 128); ahora crea nodos, sube archivos y escribe en la base. Si se filtra, alguien registra pagos falsos a nombre de residentes reales. | Cuatro capas, ninguna suficiente sola: HTTPS obligatorio; la revalidación de identidad impide imputar a unidades que no son de esa persona; el pago nace `"Pendiente de verificar"` y **nadie cobra nada** hasta que un humano lo verifica en el back office; y cada rechazo de la clave ya queda en `watchdog` desde la SPEC 127. Conviene además registrar cada creación con su `message_key`, para poder barrer lo que entró en una ventana si la clave se compromete, y rotarla con `drush vset`. |
| **Desplegar la 130 sin la 129 aplicada.** Si `field_canal` y compañía no existen, `node_save()` **ignora esas propiedades en silencio**: los pagos del bot se crearían sin canal y **sin evidencia**, sin un solo error visible. | Guardia de esquema al arrancar `myapi_bot_payment_create()` (paso 6.2): si `field_info_field('field_comprobante_ocr')` no devuelve nada, `watchdog(WATCHDOG_ERROR)` y `500 server_error`. Es preferible un endpoint que no funciona a uno que guarda pagos mutilados. Y en el despliegue, `drush updb` antes del código. |
| **El refactor toca el endpoint que usa la app en producción.** Mover cinco funciones de `payment.resource.inc` a un `includes/` puede romper el registro de pagos de todos los residentes. | Es un movimiento mecánico, sin tocar cuerpos ni firmas, y se hace **como primer paso y aislado**, con los tests de la SPEC 20 en verde antes de escribir el endpoint nuevo. Hay un criterio de aceptación explícito: esos tests deben pasar **sin modificarse**. |
| **Archivo huérfano cuando la transacción revierte.** El comprobante se guarda como managed file **antes** de la transacción; si el `INSERT` choca y se revierte, el archivo queda en disco sin nodo ni `file_usage`. | `file_usage_add()` va después del commit, así que el huérfano queda como managed sin uso y el cron de Drupal lo recoge. Es el mismo compromiso que ya acepta la SPEC 20 y la ventana es estrecha: solo en la carrera de dos reintentos simultáneos. |
| **`node_save()` dentro de una transacción.** Dispara los `hook_node_insert()` del módulo (cola de correo, saldos). Si algún hook abriera su propia transacción o hiciera un `commit` implícito (un `TRUNCATE`, un `ALTER`), el `rollback` no revertiría todo. | Los hooks de pagos de este módulo solo hacen `INSERT`/`UPDATE` ordinarios, que la transacción cubre. El correo de la SPEC 80 se llama **fuera**, explícitamente. Si en el futuro un hook de pagos hiciera DDL, este supuesto habría que revisarlo. |
| **`media.ref` inestable entre reintentos.** La clave compuesta solo funciona si n8n manda **el mismo** `media.ref` al reintentar el mismo adjunto. Si lo regenerara en cada intento, cada reintento crearía un pago nuevo y la idempotencia no serviría de nada. | Requisito explícito para el flujo de n8n, documentado en `docs/bot.md`: `media.ref` debe ser estable para un adjunto dado. Señal de diagnóstico clara: si aparecen pagos duplicados con el mismo `wamid` y `media_ref` distintos, el problema está en n8n, y las dos columnas en claro de la tabla lo hacen visible con una sola consulta. |
| **Carrera en la referencia duplicada.** Dos peticiones con referencias iguales y claves de idempotencia distintas pueden pasar ambas la comprobación previa y crear dos pagos. | Heredado y aceptado de la SPEC 20: la Field API no ofrece índice único. La idempotencia por clave sí es a prueba de carreras, que es el caso frecuente (el reintento de n8n); dos comprobantes iguales en el mismo instante no lo es. |
| **La evidencia guarda datos personales.** El payload lleva nombre, teléfono y el identificador de WhatsApp de una persona real, y queda en la base indefinidamente. | El campo está `hidden` en todos los display (SPEC 129), no sale por ningún endpoint y solo lo ve quien puede editar el nodo en el back office. Si aparece una política de retención, el borrado es un `UPDATE` sobre una sola columna. |
| **Drupal 7 EOL con entrada de un tercero.** El payload lo produce un modelo a partir de una foto que manda cualquiera por WhatsApp: puede traer comillas, HTML, emojis o basura. | Todo lo que llega al nodo pasa por validación de tipo y `check_plain()`; la evidencia se guarda como `text_long` sin procesado y no se renderiza en ninguna vista; el payload tiene tope de 64 KB y el archivo, validación de extensión, tamaño y MIME real. Nada del payload se concatena en SQL: todo va por la API de consultas. |
| **`private://` no configurado.** Sin filesystem privado, la subida falla y ningún pago del bot se puede registrar. | Mismo caso que la SPEC 20 y misma respuesta (`422 invalid_file`), pero aquí es **bloqueante** porque el archivo es obligatorio. Queda documentado en `docs/bot.md` como requisito del sitio. |
