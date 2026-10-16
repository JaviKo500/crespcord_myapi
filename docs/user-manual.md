# Manual de usuario (web)

El manual de la app se publica como HTML estático en
**https://crespcord.lamotora.com/manual/**. Es público, con `noindex`, y no pasa
por Drupal: Apache sirve `/var/www/html/manual/` directamente.

| Página | URL |
|--------|-----|
| Índice (`USER_GUIDE.md`) | `/manual/` |
| `guia/01_acceso.md` | `/manual/acceso/` |
| `guia/02_inicio.md` | `/manual/inicio/` |
| `guia/03_alicuotas_y_pagos.md` | `/manual/alicuotas-y-pagos/` |
| `guia/04_reservas.md` | `/manual/reservas/` |
| `guia/05_reclamos.md` | `/manual/reclamos/` |
| `guia/06_servicios.md` | `/manual/servicios/` |
| `guia/07_mi_cuenta.md` | `/manual/mi-cuenta/` |
| `guia/08_modo_proveedor.md` | `/manual/modo-proveedor/` |
| `manual_bot/USER_GUIDE.md` (bot de WhatsApp) | `/manual/whatsapp/` |

El slug sale del nombre del fichero: se quita el prefijo de orden y `_` pasa a
`-`. Renumerar páginas no cambia las URL; **renombrarlas sí**, y rompe los
enlaces externos (por ejemplo, un futuro botón «Ayuda» de la app).

## Ficheros

| Ruta | Qué es |
|------|--------|
| `manual_app/` | Fuente: `USER_GUIDE.md`, `guia/*.md`, `assets/*.png`. Se regenera desde el repo de la app. |
| `manual_bot/` | Fuente del manual del bot de WhatsApp: `USER_GUIDE.md` y `assets/*.png`, copiados de `docs/` del repo `crespcord-lambda` (sin `CAPTURAS.md`, que es interno). Una sola página con su propio índice; sus capturas van a `/manual/whatsapp/img/`. |
| `scripts/build-manual.sh` | Genera `build/manual/` con pandoc. |
| `scripts/deploy-manual.sh` | Ejecuta el build, sube `build/manual/` y sustituye `/var/www/html/manual/` de una vez (`mv`). Los ficheros quedan de `root` y en solo lectura (644/755): PHP no puede modificarlos. |
| `scripts/manual/manual.lua` | Filtro de pandoc: reescribe enlaces `.md` y capturas, agrupa las capturas, crea la barra anterior/índice/siguiente. |
| `scripts/manual/template.html` | Plantilla HTML con el CSS (colores de la app, modo oscuro, móvil). |
| `scripts/manual/htaccess` | Se publica como `/manual/.htaccess`: caché del HTML y de las capturas. |
| `build/` | Salida generada, ignorada por git. |

`scripts/deploy.sh` **no** sube `manual_app/` ni `build/`: el manual no forma
parte del módulo.

## Actualizar el manual

1. Copiar el `docs/` regenerado de la app sobre `manual_app/`. Para el bot,
   copiar `USER_GUIDE.md` y `assets/*.png` de `crespcord-lambda/docs/` sobre
   `manual_bot/` (nunca `CAPTURAS.md`) y revisar que las capturas no muestren
   datos reales.
2. `./scripts/build-manual.sh` (requiere `pandoc`). Falla si hay un enlace a una
   página que no existe, una captura que falta o un ancla rota.
3. Revisar `build/manual/index.html` en el navegador.
4. `./scripts/deploy-manual.sh`. Vuelve a generar el manual y lo publica. No usa
   drush ni modo mantenimiento, y borra del servidor las capturas que ya no
   existen en `manual_app/`. Usa la misma clave que `deploy.sh`
   (`MYAPI_DEPLOY_KEY` o `~/.ssh/crespcord.pem`).

## Caché

El `.htaccess` raíz de Drupal 7 da 2 semanas de caché a todo. El de `/manual/`
lo anula: el HTML se revalida siempre (`Cache-Control: no-cache`) y los PNG se
cachean un año (`immutable`), porque cada captura lleva `?v=<hash>` en su URL y
cambia cuando cambia la imagen.

**Estado del servidor (comprobado el 2026-10-15):** Apache no tiene cargados
`mod_expires` ni `mod_headers`, así que esas reglas están dentro de `IfModule` y
hoy no hacen nada (tampoco el `ExpiresDefault` de Drupal). Los ficheros salen
solo con `ETag`/`Last-Modified` y el navegador aplica su caché heurística. Para
que las reglas tengan efecto habría que activar `mod_headers` en el servidor.

## Primera publicación

Antes de subirlo por primera vez, comprobar que Drupal no tiene una ruta ni un
alias `manual`: el directorio físico la taparía.
