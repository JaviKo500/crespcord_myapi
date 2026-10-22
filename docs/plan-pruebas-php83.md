# Plan de pruebas — PHP 8.3 + Drupal 7.103 (staging)

Entorno: https://crespcord.lamotora.com. Cambios realizados:
`docs/actualizacion-php83-staging.md`.

Cómo trabajar:
- Marca cada caso con `[x]` (bien) o `[!]` (mal), y anota la URL y la hora si falla.
- Mientras pruebas, deja abierto en el servidor:

```bash
sudo tail -f /var/log/php8.3-fpm-errors.log /var/log/apache2/error.log
```

Al terminar, revisa en el backoffice *Informes → Mensajes recientes*
(`/admin/reports/dblog`), filtrando por tipo `php`. **Todo aviso o error con fecha
posterior al 22-10-2026 17:31 cuenta como fallo**, aunque la pantalla se viera bien.

**Antes de empezar:**
- La actualización cerró todas las sesiones web del backoffice: vuelve a iniciar
  sesión.
- En la app no hace falta cerrar sesión, pero para cubrir A1 empieza desde una
  instalación limpia.

---

## A. API — app Flutter

Usa la app contra staging o la colección de Postman (`tests/e2e/`). Si puedes,
prueba con dos roles distintos: propietario/inquilino y proveedor.

### A1. Autenticación
- [ ] Login con credenciales correctas → entra.
- [ ] Login con contraseña incorrecta → mensaje "Usuario o contraseña incorrectos" (401).
- [ ] Varios intentos fallidos → bloqueo por intentos (429).
- [ ] Cerrar la app y volver a abrirla → la sesión se renueva sola (refresh).
- [ ] Cerrar sesión → vuelve al login. El token anterior ya no sirve.
- [ ] "Olvidé mi contraseña" → llega el correo. El enlace o código permite cambiarla y entrar con la nueva.

### A2. Unidades, condominio, cuotas
- [ ] Mis viviendas / condominio: listados y detalle cargan con datos correctos.
- [ ] Alícuotas y cuotas extra: importes y fechas iguales que antes. Compara un caso concreto con producción.
- [ ] Recibos de una unidad (`units/{id}/receipts`): se listan y se abren o descargan.

### A3. Pagos (subida de archivos)
- [ ] Registrar un pago con comprobante en imagen (~1 MB) → 201, aparece en el listado.
- [ ] Registrar un pago con comprobante PDF.
- [ ] Comprobante de ~4 MB → se acepta.
- [ ] Comprobante de más de 10 MB → error claro, la app no se cuelga.
- [ ] Ver el detalle del pago y abrir el comprobante.
- [ ] Pago hecho por el bot de WhatsApp: los campos solo-bot aparecen solo en esos pagos.

### A4. Reclamos
- [ ] Crear un reclamo con 5 fotos de ~4 MB más un adjunto (~25–30 MB en total) → se crea con todos los archivos.
- [ ] Ver el detalle, la línea de tiempo y los archivos de un reclamo.
- [ ] Cambio de estado desde el backoffice → la app lo refleja y llega la notificación push.

### A5. Reservas
- [ ] Calendario de reservas: carga los días ocupados y libres.
- [ ] Crear una reserva → aparece en el listado y llega la notificación.
- [ ] Cancelar o modificar una reserva, si la app lo permite.

### A6. Servicios y proveedores
- [ ] Listado de proveedores y detalle, con galería de imágenes.
- [ ] Como proveedor: subir una imagen a la galería y borrar otra.
- [ ] Crear una solicitud de servicio con archivos adjuntos.
- [ ] Ofertas: el proveedor envía una oferta y el cliente la ve y la acepta.
- [ ] Calificar un servicio terminado; la calificación aparece en el listado.

### A7. Notificaciones, chat, boletines, gastos
- [ ] Llegan notificaciones push: crear algo que las dispare y esperar 1–2 minutos (cron de la cola `myapi_onesignal_push`).
- [ ] Listado de notificaciones y marcar como leída.
- [ ] Chat: abre, envía y recibe mensajes (usa `POST chat/token` y OpenSSL).
- [ ] Boletines y gastos del condominio: listados cargan.
- [ ] Idioma: con el teléfono en inglés los mensajes de error salen en inglés; en español, en español.

---

## B. Backoffice web (Drupal)

### B1. Básico
- [ ] Login y logout de un administrador y de un usuario con rol limitado.
- [ ] `/admin/reports/status`: sin errores en rojo. Anota los avisos amarillos.
- [ ] Crear, editar y borrar un nodo de cada tipo que se use a diario: vivienda, pago, gasto, alícuota.
- [ ] Formularios con *field collection*, campos condicionales y selector de fecha (popup).
- [ ] CKEditor + IMCE: insertar una imagen en un texto y guardar.

### B2. Vistas e informes (las que más se usan)
- [ ] **Conocido, roto:** `gestion-viviendas`, `reporte-viviendas-sin-condominio`, `exportar-format-pagos`, `exportar-formato-archivo-lecturas` y `exportar-formato-archivo-viviendas` muestran "opción ilegal". Ver pendiente 1 del documento de cambios; vuelve a probarlas cuando se corrijan.
- [ ] `reporte-pagos`, `reporte-alicuotas`, `reporte-alicuotas-extra`, `reporte-gastos`, `reporte-ingresos-varios`, `reporte-propietarios`, `reporte-ocupantes`, `reporte-condominios`: cargan, filtran por condominio y paginan. Los totales coinciden con producción.
- [ ] `por-cobrar-total`, `pagos-totales-por-vivienda`, `alicuotas-totales-mes-por-vivienda`: los totales (Views Aggregator) coinciden con producción.
- [ ] `alicuotas-por-enviar`, `alicuotas-extra-por-enviar`, `alicuotas-enviadas`, `eliminar-alicuotas`: las operaciones masivas (VBO) se ejecutan sobre 2–3 elementos de prueba.
- [ ] `impresion-alicuotas` e `impresion-alicuotas-extra`: la versión imprimible se genera bien.
- [ ] Gráficos (Simple Chart), si alguna vista los usa.

### B3. Exportaciones (riesgo alto: PHPExcel)
- [ ] Exportar a Excel (XLS/XLSX) desde cada vista con exportación → el archivo descarga y abre en Excel o LibreOffice, con acentos y números bien.
- [ ] Exportar a CSV.
- [ ] Una exportación grande (todo un condominio) → termina sin quedarse sin memoria. El límite es 512M y 300 s.

### B4. Importación, correo, reglas
- [ ] Feeds: importar un archivo de prueba pequeño (si se usa).
- [ ] Correo SMTP: provocar un correo (aviso o reseteo de contraseña) → llega con formato HTML.
- [ ] Rules: una acción que dispare una regla conocida (p. ej. al registrar un pago) → efecto correcto. Mira también las tareas programadas en `/admin/config/workflow/rules/schedule`.

---

## C. Cron y colas

- [ ] Al rato de crear avisos, `sudo -u www-data drush -r /var/www/html queue-list` muestra las colas `myapi_onesignal_push` y `myapi_mail_send` vacías (el cron las procesa cada minuto).
- [ ] El cron de Drupal (`/admin/reports/status` → "Cron ejecutado hace…") se ejecuta sin errores.

---

## D. Seguridad y configuración

- [ ] `curl -sI https://crespcord.lamotora.com/ | grep -i x-powered-by` → no devuelve nada.
- [ ] Un `.php` dentro de `sites/default/files/` no se ejecuta (ya comprobado; repetir si cambia la configuración de Apache).
- [ ] `https://crespcord.lamotora.com/payphone/error_log` → decidir si `payphone/` debe seguir siendo público.
- [ ] Subida de 11 MB en un solo archivo → rechazada. Subida total de 35 MB en varios archivos → aceptada.

---

## E. Rendimiento (orientativo)

- [ ] Las pantallas habituales de la app y del backoffice cargan igual o más rápido que antes.
- [ ] Con 2–3 personas usando la app a la vez, `free -m` en el servidor deja memoria disponible (más de 200 MB).

---

## Criterio para pasar a producción

1. Todas las secciones A–D en `[x]`, salvo las 5 vistas conocidas, que deben
   estar corregidas y verificadas.
2. Watchdog y `php8.3-fpm-errors.log` sin errores nuevos.
3. Al menos un día de uso normal en staging sin incidencias.
