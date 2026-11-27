# SPEC 139 — Generación del PDF del comprobante de pago

> **Estado:** Approved · **Depende de:** SPEC 138 (modelo de comprobantes), SPEC 20 (nodo `pagos`), SPEC 123 (`ModuleContractTest` y gate de CI) · **Fecha:** 2026-11-27
>
> **Objetivo:** Integrar TCPDF 6.x en el módulo y crear una función que, a partir del `id` de un comprobante, devuelva los bytes de un PDF A4 con los datos del condominio, del pagador y del pago, el QR del código de seguridad y el código de barras del número.

**Por qué existe esta spec:** cubre el punto 2 de la hoja de tareas. La hoja pide una librería compatible con PHP 7.4, pero producción corre PHP 8.3.36. El dompdf 0.6 que hay en `sites/all/libraries/` no funciona en 8.3. Se elige TCPDF porque genera QR y Code128 sin dependencias, y porque se despliega como una carpeta más del módulo, sin Composer en el servidor. La función solo produce el PDF: guardarlo, emitirlo al verificar el pago y enviarlo por correo es el punto 3.

---

## Alcance

**Dentro:**

- **`lib/tcpdf/`** (nuevo, commiteado):
  - TCPDF 6.x, fijado a la última versión 6.x estable en el momento de implementar; la versión exacta se anota en `lib/tcpdf/VERSION`;
  - recortado: se quedan `tcpdf.php`, `tcpdf_barcodes_1d.php`, `tcpdf_barcodes_2d.php`, `include/`, `LICENSE.TXT` y las fuentes `dejavusans` y `dejavusansb`. Se quitan `examples/`, `tools/` y el resto de fuentes.
- **`includes/myapi.payment_receipt_pdf.inc`** (nuevo):
  - `myapi_payment_receipt_pdf_render($receipt_id)` devuelve los bytes del PDF;
  - recoge los datos del comprobante, del condominio y del pago, y dibuja la plantilla;
  - la recogida de datos y el dibujo van en funciones separadas para poder testear cada parte.
- **`myapi.info`** (modificar): `files[] = includes/myapi.payment_receipt_pdf.inc`.
- **`scripts/deploy.sh` y `scripts/deploy.ps1`** (modificar): suben también `lib/`.
- **`phpstan.neon`** (modificar): excluye `lib/` del análisis.
- **`scripts/local/receipt-pdf-preview.sh`** (nuevo, solo local):
  - recibe el `nid` de un pago de la BD local;
  - crea un comprobante `pending` con número y código reales;
  - escribe el PDF en `.local/receipt-preview/`.
- **`tests/unit/PaymentReceiptPdfTest.php`** (nuevo) y los stubs que necesite.
- **`docs/payment-receipt-pdf.md`** (nuevo): documentación interna de la plantilla y de la función. No es un endpoint.

**Plantilla (A4, vertical, en español):**

- **Cabecera izquierda:** logo del condominio (`field_logo`) y QR del `security_code` con el pie "Código de seguridad".
- **Cabecera derecha:**
  - título "COMPROBANTE DE PAGO";
  - mes y año de la fecha de pago ("Julio del 2026");
  - "Número: 277";
  - a partir de la versión 2, "Versión N";
  - código de barras Code128 de `{condominium_nid}-{number}` con su pie.
- **Bloque del condominio:** nombre, RUC, teléfono y dirección.
- **Bloque del pagador**, sacado de `billing_snapshot`:
  - nombres y apellidos ("Contacto");
  - teléfono, dirección, identificación y email;
  - fecha de pago.
- **Bloque del pago:** valor, forma de pago, banco, referencia y detalle.
- **Datos que faltan:**
  - un texto vacío se imprime como **S/N**;
  - un condominio sin logo deja el hueco en blanco;
  - en ningún caso falla la generación.

**Fuera de alcance (otras specs):**

- Emisión al verificar el pago, guardado del PDF como archivo privado gestionado y cambio de estado del comprobante (punto 3).
- Envío por correo con el PDF adjunto (punto 3).
- Endpoints de descarga, de datos del usuario y de datos de facturación (punto 4).
- Marca de agua "ANULADO" para el estado `voided`.
- URL pública de verificación del código de seguridad.
- Validación del RUC o la cédula.
- PDF en inglés u otros idiomas según `Accept-Language`.
- Botón de vista previa en el back office.
- Rellenar los datos fiscales y los logos de los condominios.

---

## Modelo de datos

Esta spec no crea tablas ni campos. Lee `myapi_payment_receipts` (SPEC 138), el nodo `condominio` y el nodo `pagos` (SPEC 20).

### Funciones de `includes/myapi.payment_receipt_pdf.inc`

| Función | Devuelve | Responsabilidad |
|---|---|---|
| `myapi_payment_receipt_pdf_render($receipt_id)` | `string` (bytes del PDF) | Punto de entrada: llama a las dos siguientes. |
| `myapi_payment_receipt_pdf_data($receipt_id)` | `array` | Lee la BD y arma los datos. No toca TCPDF. |
| `myapi_payment_receipt_pdf_build(array $data)` | `string` | Dibuja con TCPDF. No toca la BD. |

- TCPDF se carga con `require_once` dentro de `myapi_payment_receipt_pdf_build()`, desde `drupal_get_path('module', 'myapi') . '/lib/tcpdf/tcpdf.php'`. Ninguna otra petición lo carga.
- Antes del `require_once` se define `K_PATH_CACHE` apuntando a `file_directory_temp()`. La cabecera y el pie por defecto de TCPDF se desactivan.

### Forma de `$data`

```php
[
  'receipt' => [
    'number'        => 277,
    'version'       => 1,
    'security_code' => 'K7M2…',    // 20 caracteres, en bruto (va al QR)
    'barcode'       => '42-277',   // "{condominium_nid}-{number}"
  ],
  'condominium' => [
    'name'      => 'Condominio Galicia',
    'ruc'       => '',             // vacío → la plantilla imprime "S/N"
    'phone'     => '',
    'address'   => '',
    'logo_path' => NULL,           // ruta local legible o NULL
  ],
  'payer' => [                     // tal cual billing_snapshot
    'first_name' => '…', 'last_name' => '…', 'document_number' => '…',
    'email' => '…', 'address' => '…', 'phone' => '…',
  ],
  'payment' => [
    'date'      => '2026-07-03',   // primeros 10 caracteres de field_fecha_de_pago
    'period'    => 'Julio del 2026',
    'amount'    => '125.50',       // field_valor, siempre con 2 decimales
    'method'    => '…',            // field_forma_de_pago
    'bank'      => '…',            // nombre del término de field_banco
    'reference' => '…',            // field_referencia
    'detail'    => '…',            // field_detalle
  ],
]
```

### Reglas

- **Textos vacíos.** Todos los textos llegan como `string`: un dato ausente es `''`, nunca `NULL`. Al dibujar, un texto vacío o solo con espacios se imprime como `S/N`.
- **Logo.**
  - `logo_path` sale de `drupal_realpath()` sobre el URI de `field_logo`.
  - Vale `NULL` si no hay logo, si el archivo no existe, si no es legible o si su MIME no es `image/png` ni `image/jpeg`.
- **Mes.** `period` usa los meses en español con mayúscula inicial: "Enero" … "Diciembre". Si no hay fecha de pago, `date` y `period` son `''`.
- **Valor.** Se imprime como `$ 125.50`.
- **Código de seguridad.**
  - El QR lleva los 20 caracteres en bruto, con nivel de corrección `M`.
  - Bajo el QR se imprime "Código de seguridad" y el código en grupos de 4 (`K7M2-…`), para leerlo a mano.
- **Código de barras.** Code128 de `barcode`, con el pie "Código de barras del número de comprobante".
- **Versión.** "Versión N" solo se imprime si `version >= 2`.
- **Metadatos del PDF.** El título es `Comprobante de pago {number}` y el creador es `myapi`.

### Errores

Un dato opcional que falta **no** es un error. Se lanza una excepción solo cuando no hay forma de construir el documento:

| Caso | Excepción |
|---|---|
| No existe ningún comprobante con ese `id` | `InvalidArgumentException` |
| El nodo `pagos` o el `condominio` del comprobante no existen | `RuntimeException` |
| `billing_snapshot` no es un JSON válido o no trae las seis claves | `RuntimeException` |

---

## Plan de implementación

1. **Librería.**
   - Descargar la última versión 6.x estable de TCPDF.
   - Copiarla recortada a `lib/tcpdf/`, como dice el alcance. Anotar la versión en `lib/tcpdf/VERSION`.
   - Excluir `lib/` en `phpstan.neon`.
   - Verificar: `make stan` sin errores nuevos. Desde el contenedor, un `php -r` que hace `require_once` de `tcpdf.php` y genera un PDF vacío no da ningún `Deprecated`.
2. **Despliegue.**
   - Añadir `scp -r "$SRC/lib"` a `scripts/deploy.sh` y su equivalente a `scripts/deploy.ps1`, en el mismo bloque que `includes/` y `resources/`.
   - Añadir `lib/` a la corrección de permisos 755/644 que ya hacen los dos scripts.
   - Verificar: leer el diff. No se despliega en este paso.
3. **Recogida de datos.**
   - Crear `includes/myapi.payment_receipt_pdf.inc` con `myapi_payment_receipt_pdf_data()` y sus helpers privados (mes en español, formato del valor, ruta del logo). Registrarlo en `myapi.info`.
   - Escribir los tests de datos en `tests/unit/PaymentReceiptPdfTest.php`.
   - Verificar: `make test`, `make stan`, `make cc`.
4. **Dibujo.**
   - Añadir `myapi_payment_receipt_pdf_build()` y `myapi_payment_receipt_pdf_render()`, con la plantilla completa: cabecera, condominio, pagador, pago, QR y Code128.
   - Escribir los tests de dibujo: salida que empieza por `%PDF-`, metadatos y `S/N`.
   - Verificar: `make test`, `make stan`.
5. **Vista previa local.**
   - Crear `scripts/local/receipt-pdf-preview.sh <payment_nid>`.
   - El script hace un `drush php-eval` como `www-data` que:
     - crea `myapi_receipt_billing_data` desde el perfil del dueño si el pago no tiene;
     - inserta un comprobante `pending` dentro de una transacción con `myapi_payment_receipt_next_number()` y `myapi_payment_receipt_security_code()`;
     - escribe el PDF en `.local/receipt-preview/receipt-{id}.pdf`.
   - El script se niega a correr si el host de la BD no es el contenedor local.
   - Verificar: generar el PDF de un pago real y abrirlo; el QR y el Code128 se leen con el móvil.
6. **Documentación.**
   - Escribir `docs/payment-receipt-pdf.md`:
     - ubicación y versión de TCPDF, y cómo actualizarla;
     - forma de `$data`, reglas de `S/N`, logo y excepciones;
     - uso del script de vista previa.
   - Añadir el include a `ModuleContractTest` si enumera los includes.
   - Verificar: `make test`.

---

## Criterios de aceptación

Automáticos (`make test`, `make stan`):

- [ ] `PaymentReceiptPdfTest`: `myapi_payment_receipt_pdf_data()` lanza `InvalidArgumentException` con un `id` que no existe.
- [ ] `PaymentReceiptPdfTest`: lanza `RuntimeException` si falta el nodo `pagos`, si falta el `condominio` o si `billing_snapshot` no trae las seis claves.
- [ ] `PaymentReceiptPdfTest`: `barcode` es `"{condominium_nid}-{number}"`.
- [ ] `PaymentReceiptPdfTest`: con fecha de pago `2026-07-03`, `period` es `Julio del 2026`; sin fecha, `date` y `period` son `''`.
- [ ] `PaymentReceiptPdfTest`: `field_valor` `125.5` da `amount` `125.50`.
- [ ] `PaymentReceiptPdfTest`: los campos vacíos del condominio llegan como `''`, nunca `NULL`.
- [ ] `PaymentReceiptPdfTest`: `logo_path` es `NULL` sin logo, con un archivo que no existe y con un MIME que no es PNG ni JPEG.
- [ ] `PaymentReceiptPdfTest`: `myapi_payment_receipt_pdf_build()` devuelve una cadena que empieza por `%PDF-`, con un `$data` completo y con uno en que todo lo opcional está vacío.
- [ ] `PaymentReceiptPdfTest`: el título en los metadatos del PDF es `Comprobante de pago 277`.
- [ ] `PaymentReceiptPdfTest`: generar el PDF no emite ningún `Warning`, `Notice` ni `Deprecated` (los tests convierten los avisos en errores).
- [ ] `ModuleContractTest` pasa con el include nuevo.
- [ ] PHPStan sin errores nuevos y con `lib/` excluido.

Manuales en local (`make up`):

- [ ] `lib/tcpdf/VERSION` existe y `lib/tcpdf/` no contiene `examples/`, `tools/` ni fuentes distintas de `dejavusans` y `dejavusansb`.
- [ ] `scripts/local/receipt-pdf-preview.sh <payment_nid>` con un pago real genera `.local/receipt-preview/receipt-{id}.pdf` sin entradas nuevas de PHP en el watchdog.
- [ ] El PDF es A4 vertical, tiene una sola página y muestra el título, el mes, el número, el QR, el Code128 y los tres bloques en el orden del alcance.
- [ ] El QR, leído con el móvil, devuelve exactamente el `security_code` de la fila.
- [ ] El Code128, leído con el móvil, devuelve exactamente `{condominium_nid}-{number}`.
- [ ] Con un condominio que tiene `field_logo`, el logo aparece. Con uno sin logo, el hueco queda en blanco y el PDF se genera.
- [ ] Un condominio sin RUC, teléfono ni dirección imprime `S/N` en esos tres campos.
- [ ] Un nombre con tildes y ñ ("Muñoz Peñafiel") se imprime correctamente.
- [ ] Un comprobante con `version = 2` muestra "Versión 2"; uno con `version = 1` no muestra ninguna versión.
- [ ] Con el host de BD de producción, el script de vista previa se niega a ejecutar.
- [ ] `scripts/deploy.sh` y `scripts/deploy.ps1` incluyen `lib/` en la subida y en la corrección de permisos.
- [ ] `docs/payment-receipt-pdf.md` existe y describe la librería, `$data`, las reglas de datos faltantes, las excepciones y la vista previa.

---

## Decisiones

1. **Sí: TCPDF 6.x. No: mPDF 8, Dompdf 3 ni `tc-lib-pdf`.** Es la única que hace QR y Code128 de forma nativa y sin dependencias de Composer en ejecución. mPDF necesita `mpdf/qrcode` y cuatro paquetes más. Dompdf no hace códigos y necesitaría `chillerlan/php-qrcode` y `picqer/php-barcode-generator`. `tc-lib-pdf` es el sucesor de TCPDF, pero tiene muchas dependencias y todavía es joven.
2. **No: reutilizar el dompdf 0.6 de `sites/all/libraries/` ni el módulo `print`.** Esa versión de dompdf es anterior a PHP 7 y no funciona en 8.3. `print_pdf` está desactivado.
3. **Sí: la hoja dice PHP 7.4, pero se ignora.** Producción corre PHP 8.3.36 desde el 2026-10-28, y la librería se elige para esa versión.
4. **Sí: librería commiteada en `lib/tcpdf/` dentro del módulo. No: `sites/all/libraries/` ni `vendor/`.** Queda versionada, es la misma en local, CI y producción, y `deploy.sh` la sube. Usar `vendor/` obligaría a correr Composer en el servidor, y hoy `vendor/` es solo para desarrollo.
5. **Sí: librería recortada a lo necesario.** Sin ejemplos ni herramientas, y con solo dos fuentes, el repositorio y cada despliegue pesan mucho menos.
6. **Sí: DejaVu Sans embebida en subconjunto. No: las fuentes base (`helvetica`).** Las fuentes base usan cp1252 y pueden romper algún nombre. DejaVu cubre UTF-8, y en subconjunto apenas suma peso al PDF.
7. **Sí: dibujo con primitivas de TCPDF (`RoundedRect`, `Image`, `writeHTMLCell` con HTML simple). No: una plantilla HTML/CSS completa.** El diseño es fijo y de una página. El HTML de TCPDF es limitado, así que solo se usa para etiquetas en negrita.
8. **Sí: separar la recogida de datos del dibujo.** Cada parte se testea por su lado. La spec de emisión (punto 3) podrá reutilizar el dibujo.
9. **Sí: la función devuelve bytes. No: escribe en disco.** Dónde y cómo se guarda (archivo privado gestionado) lo decide el punto 3.
10. **Sí: el título es "Comprobante de pago". No: "Aviso de Cobro".** Es un documento por cada pago ya hecho, no un aviso de deuda.
11. **Sí: el QR lleva solo el `security_code`. No: una URL.** La URL de verificación no existe todavía. Cuando exista, irá en su propia spec, y los comprobantes antiguos seguirán siendo verificables por código.
12. **Sí: Code128 de `{condominium_nid}-{number}`. No: solo `number` ni `security_code`.** El número se repite entre condominios, y el `security_code` ya va en el QR. El par nid-número es único, corto y legible.
13. **Sí: la fecha y el mes salen de la fecha del pago. No: de la fecha de emisión.** Es lo que pidió el usuario por ahora. El comprobante refleja cuándo se pagó.
14. **Sí: un dato opcional vacío se imprime como `S/N` y no falla. No: exigir RUC o logo.** Hoy los 107 condominios tienen vacíos los datos fiscales. Que falte un dato no debe bloquear un comprobante. Solo fallan los casos en los que no se puede construir el documento.
15. **Sí: el logo es el `field_logo` del condominio. No: un logo fijo de la empresa.** El campo ya existe y cada condominio emite sus propios comprobantes.
16. **Sí: "Versión N" a partir de la 2. No, por ahora: la marca de agua "ANULADO".** La versión ayuda a distinguir un PDF regenerado. Anular es parte del flujo de emisión y queda fuera de esta spec.
17. **Sí: PDF solo en español.** Se genera en segundo plano y sin petición del usuario, así que no hay `Accept-Language` del que tirar.
18. **Sí: el script de vista previa crea los datos de facturación desde el perfil si faltan, solo en local.** El endpoint que los rellena es del punto 4. Sin esos datos no hay `billing_snapshot` que probar.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| TCPDF 6.x está en mantenimiento y puede dejar de recibir arreglos. | La versión exacta queda en `lib/tcpdf/VERSION`, y `docs/payment-receipt-pdf.md` explica cómo actualizarla. Como el dibujo está aislado en `myapi_payment_receipt_pdf_build()`, cambiar de librería solo toca esa función. |
| Una versión futura de TCPDF emite `Deprecated` en PHP 8.3. | Un test genera un PDF con los avisos convertidos en errores, así que CI lo detecta antes de desplegar. |
| `deploy.sh` no sube `lib/` y la emisión falla en producción con un `require_once` roto. | El paso 2 añade `lib/` a los dos scripts de despliegue, y un criterio de aceptación lo comprueba. |
| Un logo enorme o corrupto ralentiza la generación o la rompe. | Solo se aceptan PNG y JPEG legibles; si no, el hueco queda en blanco. El tamaño se fija en la plantilla, no en la imagen. |
| Generar el PDF dentro de la transacción que reserva el número bloquea las emisiones del condominio. | Esta función no abre transacciones. `docs/payment-receipt-model.md` ya indica que el PDF se genera después del commit, y la spec de emisión debe respetarlo. |
| Un texto largo (detalle o dirección) se sale de su bloque o crea una segunda página. | Los bloques usan celdas multilínea con alto máximo y recortan el exceso. La vista previa se prueba con el detalle más largo de la BD local. |
| El script de vista previa se ejecuta contra producción. | Se niega a ejecutar si el host de la BD no es el contenedor local, y un criterio de aceptación lo comprueba. |

---

## Lo que **no** está en esta spec

- Emisión al verificar el pago, guardado del PDF y envío por correo.
- Endpoints de descarga, de datos del usuario y de datos de facturación.
- Marca de agua "ANULADO".
- URL pública de verificación del código de seguridad.
- Validación del RUC o la cédula.
- PDF en otros idiomas.
- Vista previa en el back office.

Cada una de estas cosas, cuando llegue, va en su propia spec.
