# SPEC 138 — Modelo de datos de los comprobantes de pago

> **Estado:** Approved · **Depende de:** SPEC 01 (esqueleto del módulo y `myapi.install`), SPEC 20 (nodo `pagos`), SPEC 123 (`ModuleContractTest` y el gate de CI) · **Fecha:** 2026-11-27
>
> **Objetivo:** Crear el modelo de datos de los comprobantes de pago: datos fiscales del condominio, datos de facturación por usuario y vivienda, comprobantes versionados con código de seguridad, y numeración secuencial por condominio sin colisiones.

**Por qué existe esta spec:** es la base de la generación de comprobantes en PDF (puntos 2 a 4 de la hoja de tareas). Un comprobante es un documento interno por cada pago. No tiene relación con el SRI ni con el `recibo` mensual, que es el estado de cuenta que expone `GET /api/v1/units/%/receipts`. Esta spec deja el esquema, la función que reserva números y la que genera códigos de seguridad. No hay endpoints, no se genera ningún PDF y no se emite nada.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - `myapi_schema()` declara tres tablas nuevas: `myapi_receipt_billing_data`, `myapi_payment_receipts` y `myapi_receipt_sequences`.
  - `myapi_update_7051()` crea las tres tablas si no existen.
  - `myapi_update_7052()` crea los cuatro campos del bundle `condominio`: `field_ruc`, `field_direccion`, `field_telefono` y `field_receipt_start_number`.
  - Los dos updates son idempotentes: se saltan lo que ya exista.
- **`includes/myapi.payment_receipt.inc`** (nuevo):
  - las constantes de estado de las dos tablas;
  - `myapi_payment_receipt_next_number($condominium_nid)`, que reserva el siguiente número del condominio de forma atómica. Debe llamarse dentro de una transacción abierta;
  - `myapi_payment_receipt_security_code()`, que genera un código Base32 de 20 caracteres.
- **`myapi.info`** (modificar): `files[] = includes/myapi.payment_receipt.inc`.
- **`tests/unit/PaymentReceiptModelTest.php`** (nuevo), los stubs que hagan falta en `tests/unit/bootstrap.php` y `tests/stubs/drupal7.stub.php`, y las tablas nuevas en `ModuleContractTest` si ese test enumera el schema.
- **`docs/payment-receipt-model.md`** (nuevo): documentación interna del modelo. No es un endpoint, así que no sigue la plantilla de endpoint.

**Fuera de alcance (otras specs):**

- Librería PDF, plantilla del comprobante y dibujo del QR y del código de barras (punto 2).
- Emisión al verificar el pago, guardado del PDF como archivo privado gestionado y envío por correo (punto 3).
- Endpoints: descargar o ver el comprobante, actualizar datos del usuario y CRUD de datos de facturación (punto 4).
- URL pública de verificación del código de seguridad que abrirá el QR.
- Validación de la cédula o RUC con su dígito verificador, tanto del condominio como del pagador.
- Pantallas del back office para datos de facturación o listado de comprobantes.
- Rellenar los datos fiscales de los 107 condominios existentes: se hace a mano en el back office.
- Cualquier cambio en el nodo `pagos` o en el `recibo` mensual.

---

## Modelo de datos

### Campos nuevos en el bundle `condominio`

| Campo | Tipo | Ajustes | Etiqueta |
|---|---|---|---|
| `field_ruc` | `text` | `max_length` 13, `text_processing` 0 | RUC / Cédula |
| `field_direccion` | `text` | `max_length` 255, `text_processing` 0 | Dirección |
| `field_telefono` | `text` | `max_length` 20, `text_processing` 0 | Teléfono |
| `field_receipt_start_number` | `number_integer` | `min` 1, valor por defecto 1 | Número inicial de comprobantes |

- Los cuatro son opcionales (`required` 0), con widget de texto o número y display `hidden`.
- La descripción de `field_receipt_start_number` avisa: *"Solo se aplica al emitir el primer comprobante del condominio; después no tiene efecto."*

### `myapi_receipt_billing_data`: datos de facturación del pagador

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | serial | no | PK |
| `uid` | int unsigned | no | Dueño de los datos |
| `unit_nid` | int unsigned | no | Vivienda (`vivienda`) |
| `first_name` | varchar 128 | no | Nombres |
| `last_name` | varchar 128 | no | Apellidos |
| `document_number` | varchar 13 | no | Cédula o RUC; aquí sin validar |
| `email` | varchar 254 | no | |
| `address` | varchar 255 | no, default `''` | |
| `phone` | varchar 20 | no, default `''` | |
| `status` | varchar 16 | no, default `active` | `active` / `inactive` |
| `created` | int | no | Timestamp Unix |
| `changed` | int | no | Timestamp Unix |

- Índices: `uid_unit_status (uid, unit_nid, status)`.
- Un usuario puede tener varios registros por vivienda. La regla "uno `active` por usuario y vivienda" la aplicará el código del endpoint (punto 4), no la BD: MySQL no tiene índices únicos parciales.
- Los registros no se borran: pasan a `inactive`.

### `myapi_payment_receipts`: comprobantes versionados

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `id` | serial | no | PK |
| `condominium_nid` | int unsigned | no | Condominio emisor |
| `number` | int unsigned | no | Número secuencial del condominio |
| `version` | int unsigned | no, default 1 | Sube al regenerar |
| `payment_nid` | int unsigned | no | Nodo `pagos` |
| `billing_data_id` | int unsigned | sí | Registro del que salió la copia |
| `billing_snapshot` | text | no | JSON de los datos del pagador al emitir |
| `security_code` | varchar 20 | no | Código del QR y del código de barras |
| `pdf_fid` | int unsigned | sí | `file_managed.fid`; nulo mientras no hay PDF |
| `status` | varchar 16 | no, default `pending` | `pending` / `generated` / `failed` / `voided` / `superseded` |
| `created` | int | no | Timestamp Unix |

- Únicos: `payment_version (payment_nid, version)`, `condominium_number_version (condominium_nid, number, version)` y `security_code`.
- Índices: `condominium_status (condominium_nid, status)`.
- Todas las versiones de un pago comparten `number`. Cada versión tiene su propio `security_code`, su PDF y su copia de datos. Al regenerar, la versión anterior pasa a `superseded`.
- `billing_snapshot` tiene siempre esta forma:
  `{"first_name":"…","last_name":"…","document_number":"…","email":"…","address":"…","phone":"…"}`

### `myapi_receipt_sequences`: contador por condominio

| Columna | Tipo | Nulo | Notas |
|---|---|---|---|
| `condominium_nid` | int unsigned | no | PK |
| `last_number` | int unsigned | no | Último número entregado |
| `changed` | int | no | Timestamp Unix |

### `myapi_payment_receipt_next_number($condominium_nid)`

1. Si no hay ninguna transacción abierta, lanza `LogicException`. Sin transacción, el bloqueo se soltaría antes de insertar el comprobante.
2. `INSERT … ON DUPLICATE KEY UPDATE` crea la fila con `last_number = field_receipt_start_number - 1` (o `0` si el campo está vacío) solo si no existía.
3. `SELECT last_number … FOR UPDATE` bloquea la fila hasta el commit.
4. `UPDATE last_number = last_number + 1` y devuelve el valor nuevo.

Si la transacción hace rollback, el contador vuelve atrás y el número no se pierde.

### `myapi_payment_receipt_security_code()`

- Devuelve 20 caracteres del alfabeto `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (32 símbolos, sin `0 O 1 I`): unos 100 bits de entropía.
- Cada carácter sale de un byte de `drupal_random_bytes(20)` con `& 31`. Es uniforme porque 256 es múltiplo de 32.
- Se guarda sin guiones; agruparlo para imprimirlo es cosa de la plantilla.
- Si choca con el índice único, quien emite el comprobante reintenta. Eso lo define la spec de emisión.

---

## Plan de implementación

1. **Tablas.**
   - Declarar `myapi_receipt_billing_data`, `myapi_payment_receipts` y `myapi_receipt_sequences` en `myapi_schema()`.
   - Añadir `myapi_update_7051()`, que crea con `drupal_get_schema_unprocessed()` + `db_create_table()` las que no existan, igual que el update 7048.
   - Verificar: `make updb`, después `SHOW CREATE TABLE` de las tres; un segundo `make updb` no hace nada.
2. **Campos del condominio.**
   - Añadir `myapi_update_7052()`: `field_create_field()` + `field_create_instance()` de los cuatro campos, saltándose con `field_info_field()` / `field_info_instance()` lo que ya exista.
   - Verificar: `make updb`; `node/{nid}/edit` de un condominio muestra los cuatro campos; guardar `field_receipt_start_number = 0` da error de validación.
3. **Include y código de seguridad.**
   - Crear `includes/myapi.payment_receipt.inc` con las constantes de estado y `myapi_payment_receipt_security_code()`, y registrarlo en `myapi.info`.
   - Crear `tests/unit/PaymentReceiptModelTest.php` con los tests del código: longitud 20, solo caracteres del alfabeto, dos llamadas devuelven valores distintos.
   - Verificar: `make test`, `make stan`, `make cc`.
4. **Reserva de número.**
   - Añadir `myapi_payment_receipt_next_number()` y los stubs que necesite (`db_query`, `db_transaction` / `inTransaction`, `node_load` o lectura del campo).
   - Tests: sin transacción lanza `LogicException`; el primer número de un condominio sin campo es 1; con `field_receipt_start_number = 500` es 500; las llamadas siguientes dan 501 y 502; dos condominios llevan contadores independientes.
   - Verificar: `make test`, `make stan`.
5. **Contrato y documentación.**
   - Añadir las tablas y el include a `ModuleContractTest` si los enumera.
   - Escribir `docs/payment-receipt-model.md`: campos, tablas, estados, forma de `billing_snapshot`, uso obligatorio de la transacción y alfabeto del código.
   - Verificar: `make test`.

---

## Criterios de aceptación

Automáticos (`make test`, `make stan`):

- [ ] `PaymentReceiptModelTest`: el código de seguridad tiene 20 caracteres, todos del alfabeto `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`.
- [ ] `PaymentReceiptModelTest`: 1 000 códigos generados seguidos no se repiten.
- [ ] `PaymentReceiptModelTest`: `myapi_payment_receipt_next_number()` lanza `LogicException` sin transacción abierta.
- [ ] `PaymentReceiptModelTest`: el primer número de un condominio sin `field_receipt_start_number` es 1.
- [ ] `PaymentReceiptModelTest`: con `field_receipt_start_number = 500` los tres primeros números son 500, 501 y 502.
- [ ] `PaymentReceiptModelTest`: dos condominios distintos empiezan cada uno en su propio número inicial.
- [ ] `PaymentReceiptModelTest`: cambiar `field_receipt_start_number` después del primer número no altera la secuencia.
- [ ] `ModuleContractTest` pasa con las tres tablas y el include nuevos.
- [ ] PHPStan sin errores nuevos.

Manuales en local (`make up`):

- [ ] `make updb` ejecuta `7051` y `7052` sin `Warning` / `Notice` / `Deprecated` en el watchdog.
- [ ] Un segundo `make updb` no tiene updates pendientes, y ejecutar las dos funciones otra vez con `drush php-eval` no da error.
- [ ] `SHOW CREATE TABLE` de las tres tablas muestra las columnas, la PK, los tres índices únicos de `myapi_payment_receipts` y el índice `uid_unit_status`.
- [ ] El formulario de un condominio muestra **RUC / Cédula**, **Dirección**, **Teléfono** y **Número inicial de comprobantes** (este último con 1 por defecto) y los guarda.
- [ ] Guardar **Número inicial de comprobantes** con `0` da error de validación.
- [ ] Concurrencia: dos `drush php-eval` lanzados a la vez sobre el mismo condominio abren una transacción, piden número y esperan 5 s antes del commit. Obtienen números consecutivos distintos y el segundo espera a que el primero termine.
- [ ] Rollback: pedir un número dentro de una transacción que hace rollback y pedir otro después devuelve el mismo número.
- [ ] Insertar a mano en `myapi_payment_receipts` dos filas con el mismo `security_code`, o con el mismo `(payment_nid, version)`, falla por clave duplicada.
- [ ] `docs/payment-receipt-model.md` existe y describe los campos, las tablas, los estados y `billing_snapshot`.

---

## Decisiones

1. **Sí: `myapi_payment_receipts` y `myapi_receipt_billing_data`; no: `myapi_receipts` y `myapi_data_recipts`.** `receipts` ya se refiere en la API al `recibo` mensual (SPEC 11). Además, el nombre original tenía una errata.
2. **Sí: comprobante interno; no: SRI.** No hay clave de acceso, XML ni formato `001-001-…`. El número es un entero por condominio.
3. **Sí: datos de facturación por usuario y vivienda, varios por pareja.** El punto 4 de la hoja los pide "por vivienda", y así un usuario puede facturar cada vivienda a nombre distinto. La regla de uno `active` la aplica el código, porque MySQL no tiene índices únicos parciales.
4. **Sí: los datos de facturación se desactivan en vez de borrarse.** Los comprobantes siguen apuntando a un registro que existe.
5. **Sí: copia JSON en `billing_snapshot`; no: duplicar seis columnas.** Un PDF regenerado sale con los datos que tenía el pagador al emitir. La copia solo guarda al pagador. Los datos del condominio se leen en vivo, así que una regeneración muestra su dirección actual.
6. **Sí: versiones en la misma tabla (columna `version`); no: una tabla aparte de versiones.** Se ajusta a la tabla de la hoja y evita un join. Todas las versiones comparten número.
7. **Sí: token aleatorio guardado; no: HMAC de los datos.** No se puede derivar de nada, se invalida cambiando el estado de la fila y no depende de una clave secreta que pueda filtrarse o rotar.
8. **Sí: 20 caracteres Base32 sin `0 O 1 I`; no: 32 hexadecimales.** Con unos 100 bits basta, y el código de barras queda más corto y fácil de leer.
9. **Sí: un código de seguridad por versión.** Un PDF reemplazado se puede detectar al escanearlo: su fila está en `superseded`.
10. **Sí: contador en tabla propia con `SELECT … FOR UPDATE` dentro de la transacción del comprobante; no: `MAX(number) + 1`.** `MAX + 1` choca con dos emisiones a la vez. El bloqueo de fila las ordena, y un rollback devuelve el número. El índice único `(condominium_nid, number, version)` es la segunda barrera.
11. **Sí: los huecos solo aparecen por borrados manuales.** Un PDF que falla deja la fila en `failed` con su número, y al reintentar se reutiliza.
12. **Sí: número inicial como campo del condominio, leído solo al crear el contador.** Permite seguir una numeración manual previa. Cambiarlo después no reordena nada.
13. **Sí: `pdf_fid`; no: ruta de texto.** El punto 3 guarda el PDF como archivo gestionado, y con el `fid` Drupal controla el acceso y la limpieza.
14. **Sí: dos updates (`7051` tablas, `7052` campos).** Cada paso del plan se puede commitear y ejecutar con `updb` por separado.
15. **No: validar el RUC o la cédula aquí.** Va en otra spec, igual que la URL de verificación del QR.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Alguien llama a `myapi_payment_receipt_next_number()` fuera de una transacción y el bloqueo se suelta antes de insertar el comprobante. | La función lanza `LogicException` si no hay transacción abierta, y hay un test que lo cubre. |
| La transacción que reserva el número dura mucho (por ejemplo, si genera el PDF dentro) y bloquea las emisiones de ese condominio. | `docs/payment-receipt-model.md` deja escrito que el PDF se genera **después** del commit. La spec de emisión debe respetarlo. |
| `INSERT … ON DUPLICATE KEY UPDATE` y `FOR UPDATE` son de MySQL/MariaDB. | Producción y local usan MariaDB. La dependencia queda documentada. |
| Colisión del código de seguridad. | Con unos 100 bits es improbable. El índice único la detecta y quien emite reintenta. |
| Se cambia `field_receipt_start_number` esperando que reinicie la numeración. | La descripción del campo avisa de que solo cuenta para el primer comprobante. |
| Datos fiscales vacíos en los 107 condominios cuando llegue la emisión. | Fuera de esta spec. La spec de emisión decide qué hacer si faltan, y hay que rellenarlos en el back office antes de activarla. |

---

## Lo que **no** está en esta spec

- Generación del PDF, plantilla, QR y código de barras.
- Emisión al verificar el pago, almacenamiento del PDF y envío por correo.
- Endpoints de descarga, de datos del usuario y de datos de facturación.
- URL pública de verificación del código de seguridad.
- Validación del RUC o la cédula.
- Pantallas del back office para datos de facturación o comprobantes.

Cada una de estas cosas, cuando llegue, va en su propia spec.
