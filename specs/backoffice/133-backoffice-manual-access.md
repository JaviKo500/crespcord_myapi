# SPEC 133 — Manual del back office solo para usuarios con sesión de back office

> **Estado:** Approved · **Depende de:** SPEC 49 (rol `administrador edificio`), manual del back office (`manual_backoffice/`, `scripts/build-manual.sh`, `docs/user-manual.md`) · **Fecha:** 2026-10-16
>
> **Objetivo:** Que `https://crespcord.lamotora.com/manual/backoffice/` y todas sus páginas y capturas solo se puedan leer con una sesión de Drupal de los roles `administrator`, `backend` o `administrador edificio`. El manual de la app (`/manual/`) y el del bot (`/manual/whatsapp/`) siguen siendo públicos.

---

## Problema

El manual se publica como HTML estático en `/var/www/html/manual/`, y Apache lo sirve sin pasar por Drupal. Cualquiera con la URL lee el manual del back office: las pantallas, los nombres de los menús, los flujos de aprobación de pagos y los ID de ejemplo. No es información de residentes, pero sí es un mapa del back office que no tiene por qué ser público.

Apache no puede responder la pregunta «¿esta petición viene de alguien con sesión de back office?». La sesión vive en Drupal.

## Alcance

**Dentro:**

- **`includes/myapi.backoffice_manual.inc`** (nuevo): el *page callback* que decide el acceso y entrega el fichero, y sus helpers puros (validación de la ruta, tipo de contenido, cabeceras, decisión de acceso).
- **`myapi.module`** (modificar): la ruta `manual/backoffice` en `hook_menu()`. Solo enrutado.
- **`myapi.info`** (modificar): un `files[]`.
- **`tests/unit/BackofficeManualTest.php`** (nuevo): los helpers puros.
- **`tests/unit/ModuleContractTest.php`** (modificar): `manual/backoffice` entra en `NON_VERSIONED_PATHS`.
- **`scripts/build-manual.sh`** (modificar): el manual del back office se genera en `build/manual-backoffice/`, **fuera** de `build/manual/`, para que nunca pueda acabar en la carpeta pública.
- **`scripts/deploy-manual.sh`** (modificar): sube `build/manual-backoffice/` a `/var/www/manual-backoffice/` (fuera del *document root*), con el mismo reemplazo atómico y los mismos permisos que el manual público.
- **`docs/backoffice-manual.md`** (nuevo) y **`docs/user-manual.md`** (modificar).

**Fuera de alcance:**

- Proteger `/manual/` o `/manual/whatsapp/`. Son para residentes y siguen públicos.
- Permisos de Drupal configurables desde la UI. El acceso se decide por nombre de rol, como en el resto del back office (SPEC 131, 132).
- Cambios de schema, `hook_update_N()` o endpoints `api/v1/...`.
- Distinguir qué páginas ve cada rol. El manual es uno: el `administrador edificio` ve también el capítulo de pagos, que explica una pantalla a la que no tiene acceso.

---

## Diseño

### Ruta

`manual/backoffice` → `MENU_CALLBACK`, *page callback* `myapi_backoffice_manual_page()`. Drupal pasa los segmentos que siguen a la ruta como argumentos del callback, así que la misma ruta atiende:

| Petición | Fichero (relativo a la carpeta privada) |
|---|---|
| `/manual/backoffice/` | `index.html` |
| `/manual/backoffice/pagos/` | `pagos/index.html` |
| `/manual/backoffice/img/pagos_reporte.png?v=…` | `img/pagos_reporte.png` |

Las URL publicadas no cambian. Hoy Apache las servía desde disco; cuando la carpeta `/var/www/html/manual/backoffice/` deje de existir, la regla de reescritura del `.htaccess` de Drupal (`!-f`, `!-d` → `index.php`) las manda a Drupal sin configuración adicional.

### Acceso

`'access callback' => TRUE` en el router y la decisión dentro del *page callback*, como los recursos de la API, porque hay tres salidas y no dos:

| Quién | Respuesta |
|---|---|
| Anónimo | Redirección a `user/login?destination=<ruta pedida>`. Tras entrar, vuelve a la página del manual. |
| Con sesión, sin rol permitido (p. ej. un residente) | `MENU_ACCESS_DENIED` (403 de Drupal). |
| `uid 1`, `administrator`, `backend` o `administrador edificio` | El fichero. |

Un *access callback* que devolviera FALSE para el anónimo daría un 403 en vez de llevarlo al login, que es lo que hace falta cuando el operador abre un enlace al manual antes de haber entrado.

`myapi_backoffice_manual_roles()` es propia y no reutiliza `myapi_calendar_admin_roles()` aunque hoy la lista coincida: la pregunta es otra («¿puede leer el manual del back office?»), y las listas tienen que poder separarse.

### Validación de la ruta

La ruta pedida se convierte en una ruta relativa con una **lista cerrada de formas**, nunca concatenando lo que llega:

- `()` o `('')` → `index.html`.
- `(<slug>)` o `(<slug>, '')` → `<slug>/index.html`, con `<slug>` = `^[a-z0-9]+(-[a-z0-9]+)*$` y distinto de `img`.
- `('img', <nombre>.png)` → `img/<nombre>.png`, con `<nombre>` = `^[a-z0-9][a-z0-9_-]*$`.
- Cualquier otra cosa → 404 (`MENU_NOT_FOUND`).

Con esas formas no hay `..`, ni barras, ni otras extensiones. Además, la ruta real del fichero (`realpath`) tiene que quedar dentro de la carpeta configurada. Es una segunda barrera por si en el servidor alguien deja un enlace simbólico.

Una página pedida por cualquier ruta que no sea la canónica (`/manual/backoffice/pagos/`) se redirige (301) a ella con una URL absoluta. Los enlaces del manual son relativos (`pagos/`, `../img/…`) y solo funcionan desde ahí. Los dos casos reales son:

- sin la barra final (`/manual/backoffice/pagos`);
- por `?q=` (`/?q=manual/backoffice/pagos/`). En este sitio `url()` genera URL con `?q=`, y es a donde Drupal manda el navegador después del login.

Comprobado en el servidor: la redirección al login de un anónimo sale como `?q=user/login&destination=manual/backoffice/pagos`.

### Respuesta

| Tipo | `Content-Type` | `Cache-Control` |
|---|---|---|
| `.html` | `text/html; charset=utf-8` | `private, no-cache` |
| `.png` | `image/png` | `private, max-age=31536000, immutable` |

En las dos: `X-Content-Type-Options: nosniff` y `X-Robots-Tag: noindex, nofollow`. `private` impide que un proxy o CDN guarde una copia y se la entregue a otro. El año de caché de las capturas sigue siendo seguro porque el build añade `?v=<hash>` a cada URL.

Se escribe el fichero con `readfile()` y se termina con `drupal_exit()`: no pasa por el tema de Drupal ni por la caché de páginas.

### Carpeta privada

`variable_get('myapi_backoffice_manual_dir', '/var/www/manual-backoffice')`. Fuera de `/var/www/html`, así que Apache no la sirve aunque se equivoque una regla. Propiedad de `root`, ficheros 644 y carpetas 755: `www-data` la lee y no la puede modificar, igual que el manual público.

### Build y deploy

- `build-manual.sh` genera el manual del back office en `build/manual-backoffice/` (antes `build/manual/backoffice/`). Las rutas relativas internas no cambian, porque la estructura dentro de la carpeta es la misma. La comprobación de anclas rotas recorre también esa carpeta.
- `deploy-manual.sh` publica las dos carpetas con el mismo patrón (`.new` → `mv` → borrar `.old`). Como la carpeta pública se reemplaza entera, el `/var/www/html/manual/backoffice/` que pudiera quedar de antes desaparece en el primer deploy.

---

## Plan de implementación

1. `includes/myapi.backoffice_manual.inc` con los helpers puros y el *page callback*.
2. Ruta en `myapi.module`, `files[]` en `myapi.info`, entrada en `NON_VERSIONED_PATHS`.
3. `tests/unit/BackofficeManualTest.php`.
4. `build-manual.sh` y `deploy-manual.sh`.
5. `docs/backoffice-manual.md` y `docs/user-manual.md`.
6. En el servidor: deploy del módulo (`drush cc all` reconstruye el router) y luego `deploy-manual.sh`.

## Criterios de aceptación

1. Sin sesión, `/manual/backoffice/pagos/` redirige al login. Después de entrar con un usuario `backend`, el navegador termina en `/manual/backoffice/pagos/` (con barra y sin `?q=`) y la página se ve con sus capturas.
2. Con un usuario `backend` o `administrador edificio` se ven todas las páginas y todas las capturas del manual.
3. Con un usuario residente (sin esos roles), `/manual/backoffice/` responde «Acceso denegado».
4. `/manual/backoffice/img/../../../etc/passwd`, `/manual/backoffice/img/x.svg` y `/manual/backoffice/pagos/index.php` responden 404.
5. `/manual/backoffice`, `/manual/backoffice/pagos` y `/?q=manual/backoffice/pagos/` redirigen a la ruta canónica con barra final.
6. `/manual/` y `/manual/whatsapp/` siguen abriendo sin sesión.
7. En el servidor no existe `/var/www/html/manual/backoffice/`.
8. `vendor/bin/phpunit` pasa, incluido `ModuleContractTest`.

## Decisiones tomadas y descartadas

1. **Servir por Drupal y no por Apache.** Descartada la contraseña básica de Apache (`.htpasswd`): es una clave compartida distinta de la sesión de cada operador, no se puede revocar a una persona y acaba circulando. Descartado comprobar en Apache la presencia de la cookie de sesión: cualquier cookie inventada pasaría.
2. **`access callback` TRUE y decisión en el *page callback*.** Ver *Acceso*: el anónimo necesita una redirección, no un 403.
3. **Lista cerrada de formas de ruta** en vez de normalizar la ruta pedida y comprobar que no salga de la carpeta. Lo segundo también se hace (`realpath`), pero como segunda barrera.
4. **El mismo manual para los dos roles.** Hacer un manual por rol sería mantener dos copias de casi todo.
5. **La carpeta privada es una variable con valor por defecto**, no una constante, para que un entorno local la pueda apuntar a `build/manual-backoffice` desde `settings.php`.

## Riesgos identificados

- **El `.htaccess` de `/manual/`** define cabeceras de caché por `FilesMatch` para `.html` y `.png`. Las peticiones a `/manual/backoffice/...` pasan por ese directorio antes de reescribirse a `index.php`. Hoy no interfiere, porque el servidor no carga `mod_headers` ni `mod_expires` (`docs/user-manual.md`, *Caché*). Si algún día se activan, hay que comprobar con `curl -I` que una captura sigue saliendo con `Cache-Control: private, …`.
- **URLs limpias.** El diseño necesita que estén activas. En este sitio lo están: rutas como `/admin/content/reservation-calendar` ya funcionan sin `?q=`.
- **Orden del despliegue.** Si se publica el manual antes que el módulo, `/manual/backoffice/` da 404 hasta que se despliegue el módulo. Es un fallo cerrado, no una fuga.
