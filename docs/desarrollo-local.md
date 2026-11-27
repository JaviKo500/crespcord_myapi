# Desarrollo local con Docker

Entorno local del sitio Drupal 7 que aloja `myapi`. El servidor de staging
(`crespcord.lamotora.com`) ya no existe y no habrá otro: **todo se prueba aquí antes
de ir a producción.**

El entorno reproduce producción con el código y los datos reales (saneados):

| | Producción | Local |
|---|---|---|
| SO | Ubuntu 22.04 | Ubuntu 22.04 (contenedor `web`) |
| PHP | 8.3.36 (Sury), PHP-FPM | 8.3.x del mismo PPA (hoy 8.3.37), PHP-FPM |
| Extensiones PHP | las de `php-fpm8.3 -m` | las mismas; el build falla si difieren |
| Servidor web | Apache 2.4.52, `mpm_event` + `proxy_fcgi` | igual |
| Base de datos | MariaDB 10.6.23 | `mariadb:10.6.23` |
| Drupal / Drush | 7.103 / 8.4.12 | el core copiado de prod / 8.4.12 |
| Correo | módulo `smtp` → SMTP real | módulo `smtp` → **Mailpit** |
| Módulo `myapi` | copiado por `scripts/deploy.sh` | **este repositorio, montado en vivo** |

Las diferencias que quedan están en [Diferencias con producción](#diferencias-con-producción).

---

## 1. Requisitos

- macOS con **Docker Desktop** (probado con Docker 28 y Compose v2.39).
- **~6 GB libres en disco**: 0,5 GB de imágenes, ~3 GB de base de datos (dentro del
  disco de Docker), 0,1 GB de volcado y 0,1 GB de código. Los scripts comprueban el
  espacio antes de empezar y se niegan a seguir si no alcanza.
- Para traer código y datos: la clave `~/.ssh/LightsailDefaultKey-us-east-2-cresp.pem`
  y `MYAPI_PROD_HOST` en `scripts/deploy.local.env` (el mismo fichero que usa
  `scripts/deploy-target.sh`).
- En el host, solo para algunas tareas: `composer` (instalar `vendor/`), `node` ≥ 18
  y `npm` (tests e2e), `pandoc` (manual). PHP **no** hace falta en el host: PHPUnit y
  PHPStan corren dentro del contenedor, en PHP 8.3.

---

## 2. Primera puesta en marcha

```bash
# 1. Credenciales locales (el fichero .env está en .gitignore)
cp .env.example .env
#    Cambia DB_PASSWORD y DB_ROOT_PASSWORD por cualquier valor: solo existen aquí.

# 2. Imagen y contenedores
make build
make up

# 3. Código: core + sites/all de producción (rsync, ~25 MB de transferencia, ~75 MB en disco; pide confirmación)
make pull-code

# 4. Datos: volcado de producción (~90 MB comprimido, ~1 min, pide confirmación)
make pull-db

# 5. Importar, sanear, updb y cc all (~2 min)
make import
```

Al terminar, el sitio responde en <http://localhost:8080> y `make import` imprime el
usuario del back-office.

Qué hace cada paso:

- **`make pull-code`** (`scripts/local/pull-code.sh`) copia el docroot de producción a
  `.local/docroot/` y genera el `settings.php` local. **No** copia
  `sites/default/settings.php` (credenciales), `sites/default/files` (850 MB),
  `payphone/` ni `manual/`. Sí copia `sites/default/files/mailsystem/`: ver
  [mail_system](#mail_system-por-qué-se-vigila-tanto).
- **`make pull-db`** (`scripts/local/pull-db.sh`) ejecuta `mysqldump
  --single-transaction` en producción y transmite el resultado comprimido por SSH. No
  escribe nada en el servidor. Trae la **estructura de todas las tablas** y los datos
  de todas menos: cachés, `watchdog`, `sessions`, `flood`, colas (`queue`),
  `history`, índice de búsqueda, tareas programadas, tokens de `myapi` y
  `dr_field_revision_*`.
- **`make import`** (`scripts/local/import-db.sh`) recrea la base, carga el volcado,
  **sanea los datos con SQL antes de que Drupal arranque por primera vez**, comprueba
  que no queda ningún email real, pone la contraseña local a todas las cuentas,
  verifica `mail_system`, ejecuta `drush updb -y` y `drush cc all`, guarda un volcado
  ya saneado y **borra el volcado de producción**.

> **La base real ocupa ~9,6 GB**, no los 1,5 GB que se manejaban: 6,7 GB son
> revisiones antiguas de campos (`dr_field_revision_*`). Por eso se traen sin filas
> (`DUMP_SKIP_FIELD_REVISIONS=1` en `.env`). Los valores actuales de los nodos están
> completos (`dr_field_data_*`); solo las revisiones antiguas se ven con los campos
> vacíos. Con `DUMP_SKIP_FIELD_REVISIONS=0` se traen también (~10 GB locales).

---

## 3. URLs y credenciales locales

| Qué | Dónde |
|---|---|
| Sitio / back-office | <http://localhost:8080> — login en `/user/login` |
| API | `http://localhost:8080/api/v1/...` |
| Mailpit (bandeja de todo el correo) | <http://localhost:8025> |
| MariaDB desde el host | `127.0.0.1:33066`, usuario y contraseña de `.env` |

- **Contraseña de todas las cuentas:** el valor de `LOCAL_USER_PASSWORD` en `.env`
  (por defecto `Local.1234`).
- **Usuario administrador:** la cuenta con uid 1; `make import` imprime su nombre.
- **Usuarios de la app:** tras el saneado, el nombre de usuario y el correo de cada
  cuenta son `user<uid>@example.test`. Para encontrar un propietario con viviendas:

  ```bash
  scripts/local/drush.sh sqlq "SELECT u.uid, u.name FROM dr_users u
    JOIN dr_field_data_field_propietario p ON p.field_propietario_target_id = u.uid
    WHERE u.status = 1 GROUP BY u.uid ORDER BY u.access DESC LIMIT 5"
  ```

- **Clave del bot** (`X-Api-Key` de `/api/v1/bot/...`): `LOCAL_BOT_API_KEY` de `.env`.

Comprobación rápida:

```bash
curl -s http://localhost:8080/api/v1/ping
# {"success":true,"data":{"pong":true}}

TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"user<uid>@example.test","password":"Local.1234"}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["access_token"])')

curl -s http://localhost:8080/api/v1/units -H "Authorization: Bearer $TOKEN"
```

Puertos y dirección de escucha se cambian en `.env` (`WEB_PORT`, `MAILPIT_PORT`,
`DB_PORT`, `WEB_BIND`) y se aplican con `make up`.

---

## 4. Día a día

```bash
make up            # levantar            make down     # parar (la base se conserva)
make shell         # shell en el contenedor web, como www-data
make shell-db      # cliente MariaDB sobre la base local
make cc            # drush cc all
make updb          # drush updb -y && drush cc all  (el "deploy" local)
make logs-php      # log de errores de PHP (debe estar vacío)
make watchdog      # últimos avisos y errores del log de Drupal
make help          # lista completa
```

**El módulo está montado en vivo**: este repositorio es
`sites/all/modules/myapi` dentro del contenedor. Un cambio en un `.inc` se ve en la
siguiente petición, sin desplegar. Sigue haciendo falta `make cc` después de tocar
`hook_menu()`, añadir un `.inc` a `myapi.info` o cambiar el registro de clases, y
`make updb` después de añadir un `hook_update_N()`: exactamente lo mismo que en
producción.

### Drush

```bash
scripts/local/drush.sh status
scripts/local/drush.sh vget --exact mail_system
scripts/local/drush.sh sqlq "SELECT schema_version FROM dr_system WHERE name='myapi'"
```

`scripts/local/drush.sh` ejecuta Drush 8.4.12 dentro del contenedor, **siempre como
`www-data`** y con la URL local. Dentro del contenedor, `drush` es un envoltorio que
hace lo mismo aunque se llame como root: no hay forma de ejecutarlo con otro usuario.

### Cron y colas

En producción el crontab de `www-data` vacía las colas cada minuto y lanza el cron
cada hora. **En local no hay cron automático**, a propósito: nada se envía ni se
procesa sin pedirlo.

```bash
make queue   # drush queue-run myapi_mail_send + myapi_onesignal_push
make cron    # drush cron
```

Los correos que `myapi` encola (por ejemplo el aviso al back-office al registrar un
pago) no aparecen en Mailpit hasta ejecutar `make queue`.

---

## 5. Correo: Mailpit

Todo el correo del sitio acaba en <http://localhost:8025>, por dos caminos:

1. **Módulo `smtp`** (el camino de producción): el `settings.php` local fija
   `smtp_host = mailpit`, puerto `1025`, sin usuario ni TLS. Como está en `$conf`,
   ningún formulario de administración ni update puede apuntarlo a otro sitio.
2. **`mail()` de PHP** (`DefaultMailSystem`): `sendmail_path` apunta a `msmtp`, que
   entrega también en Mailpit.

Mailpit no reenvía nada: un correo que llega ahí no sale de la máquina.

```bash
make mail-test   # un correo de myapi (password_reset) y otro con mail() de PHP
```

### `mail_system`: por qué se vigila tanto

La variable `mail_system` **no se toca en local**: llega tal cual está en producción
(`default-system` y `htmlmail` = `HTMLMailSystem__SmtpMailSystem`, y las claves
`myapi_*` = `MyapiHtmlMailSystem`). Solo se cambia el transporte.

El módulo `mailsystem` comprueba en cada arranque que existen las clases que nombra
esa variable y **borra o resetea, sin avisar, las que no puede cargar**. Ya pasó dos
veces, por dos motivos distintos:

- Drush ejecutado con un usuario que no podía leer `myapi/includes/`: desaparecieron
  las claves `myapi_*`. Aquí Drush corre siempre como `www-data`.
- **La clase `HTMLMailSystem__SmtpMailSystem` no vive en ningún módulo:** la genera
  `mailsystem` en `sites/default/files/mailsystem/`. Sin ese fichero, el primer
  arranque cambia `default-system` a `DefaultMailSystem` y elimina `htmlmail`. Por eso
  `pull-code.sh` trae ese directorio aunque excluya el resto de `files/`.

`import-db.sh` lee `mail_system` con SQL antes del primer arranque, comprueba que
existen los ficheros de las clases generadas, y después del arranque y del `cc all`
verifica que la variable sigue igual. Si no, el import falla y el volcado de
producción no se borra.

Para comprobarlo a mano:

```bash
scripts/local/drush.sh vget --exact mail_system
```

---

## 6. Datos: saneado y refresco

### Qué se sanea

`scripts/local/sanitize.sql` se ejecuta con el cliente de MariaDB **antes** de que
Drupal arranque, de modo que ningún hook, cron ni cola ve los datos reales:

| Dato | Qué queda |
|---|---|
| `dr_users.mail`, `init` | `user<uid>@example.test` |
| `dr_users.name` cuando era un email (casi todas) | `user<uid>@example.test` |
| Títulos de nodos con el email del propietario (viviendas, recibos, pagos: ~310.000) | el mismo `user<uid>@example.test` de su cuenta |
| Cualquier otro email en títulos, comentarios y campos de texto | dominio cambiado a `@example.test` |
| Teléfonos (`field_telefono`, `field_phone`, `field_phone_number`) | número ficticio derivado del id |
| Credenciales y tokens de PayPhone (nodos `configuracion_payphone`, `tokens_payphone`) | `local-disabled` |
| `smtp_username`, `smtp_password`, `smtp_host`, `cron_key`, `drupal_private_key`, `update_notify_emails` | borradas |
| Colas, sesiones, tokens de `myapi`, tareas programadas, `watchdog` | vacías |
| Contraseñas | la de `LOCAL_USER_PASSWORD` (`post-import.php`) |

Además, el `settings.php` local deja sin credenciales OneSignal (no sale ningún
push), Firebase (el chat responde «no configurado») y fija una clave de bot propia.
Como red de seguridad, `drupal_http_request()` va a un proxy inexistente: cualquier
llamada saliente de Drupal falla al instante.

Después del saneado, `scripts/local/check-sanitized.sh` recorre **todas** las columnas
de texto de cuentas, nodos y campos buscando emails fuera de `@example.test`. Si un
campo nuevo del sitio guarda direcciones, el import falla y dice qué columna añadir a
`sanitize.sql`.

> **Lo que el saneado no hace:** no anonimiza nombres, cédulas, RUC ni importes. La
> base local **sigue conteniendo datos personales** y hay que tratarla como tal: no se
> comparte, no se sube a ningún sitio y no se commitea.

### Refrescar desde producción

```bash
make refresh     # = make pull-db && make import
make pull-code   # solo si en producción cambió el core o algún módulo contrib
```

### Reimportar desde cero, sin tocar producción

```bash
make reset       # recrea la base desde .local/dumps/local-sanitized.sql.gz
```

### Qué queda en disco

- `.local/dumps/local-sanitized.sql.gz` (~90 MB): el único volcado que se conserva, ya
  saneado. El volcado de producción se borra al terminar `make import`.
- `make clean-dumps` borra **todos** los volcados, también el saneado: después
  `make reset` no funciona hasta repetir `make pull-db` y `make import`.
  `make destroy` borra contenedores **y** el volumen de la base.

Todo `.local/`, `.env` y cualquier `*.sql` / `*.sql.gz` están en `.gitignore`.

---

## 7. Tests en local

| Capa | Comando | Dónde corre |
|---|---|---|
| Unit + contrato (PHPUnit) | `make test` | contenedor, PHP 8.3 |
| Estático (PHPStan) | `make stan` | contenedor, PHP 8.3 |
| Integración (SimpleTest) | `make integration` | contenedor, contra el sitio local |
| E2E (Newman + roundtrip) | `make e2e` | host, contra `http://localhost:8080` |

`make test` y `make stan` necesitan `vendor/` (`composer install` en el host). Admiten
argumentos llamando al script: `scripts/local/run-unit-tests.sh --filter PingTest`.

Los scripts de `scripts/local/` son los equivalentes locales de los de `scripts/`, que
no se han modificado y siguen sirviendo para producción:

| Producción (SSH) | Local (`docker compose exec`) |
|---|---|
| `scripts/deploy.sh` | `scripts/local/deploy.sh` — solo `updb` + `cc all`; no hay nada que subir |
| `scripts/deploy-manual.sh` | `scripts/local/deploy-manual.sh` |
| `scripts/run-integration-tests.sh` | `scripts/local/run-integration-tests.sh` |
| `scripts/run-e2e-tests.sh` | `scripts/local/run-e2e-tests.sh` |
| `scripts/run-unit-tests.sh` | `scripts/local/run-unit-tests.sh` |
| `scripts/run-static-analysis.sh` | `scripts/local/run-static-analysis.sh` |

**Integración.** Mismos pasos que el original: habilita `myapi_test`, aparta
`rules_forms.test`, lanza `scripts/run-tests.sh --class MyapiAuthTestCase` y lo
restaura todo en un `trap`. No copia nada: `tests/integration/` ya está dentro del
módulo montado y Drupal descubre `myapi_test` ahí. Al terminar borra además las tablas
`dr_simpletest<N>*` que deja un sandbox abortado (el limpiador del core no las
encuentra con el prefijo `dr_`) y deshabilita `simpletest` si no estaba habilitado.

> **Estado actual: la suite de integración no pasa (1 pase, 33 excepciones), y no es
> por el entorno.** `myapi_install()` crea `field_tags` (SPEC 81) y después el perfil
> `standard` del sandbox intenta crearlo otra vez: `FieldException` en el primer
> `setUp()`, y las 32 restantes son consecuencia. Con el perfil `minimal` falla antes,
> porque el instalador necesita tipos de campo (`image`, `number_integer`…) de módulos
> que `myapi.info` no declara como dependencias. Es un arreglo del test o del
> instalador, pendiente y fuera de este entorno.
>
> El runner deja además avisos de PHP en el watchdog que son suyos y no del sitio:
> `include_once(.../rules_forms.test)` y `Undefined array key 1` en `run-tests.sh`.

**E2E.** Misma colección Postman y mismo roundtrip que el original, con dos cambios
obligados: el entorno de Postman se genera (`.local/e2e/`) con una cuenta propia
(`e2e_local`, creada si no existe), y el correo de reset se lee de la API de Mailpit
en vez de un buzón IMAP (`scripts/local/password-reset-roundtrip.js`). No necesita
`tests/e2e/.env` ni credenciales.

---

## 8. Apuntar la app Flutter al entorno local

El sitio toma el host de cada petición, así que las URLs que devuelve la API
(ficheros, enlaces) valen para el origen desde el que se llama.

| Desde | URL base de la API | Preparación |
|---|---|---|
| Emulador de Android | `http://10.0.2.2:8080` | ninguna (`10.0.2.2` es el `localhost` del host) |
| Simulador de iOS | `http://localhost:8080` | ninguna |
| Dispositivo físico (misma wifi) | `http://<IP-LAN-del-Mac>:8080` | abrir el puerto a la LAN, ver abajo |

Para un **dispositivo físico**:

```bash
ipconfig getifaddr en0          # IP del Mac en la LAN, p. ej. 192.168.1.50
```

En `.env`:

```dotenv
WEB_BIND=0.0.0.0
LOCAL_BASE_URL=http://192.168.1.50:8080
```

```bash
make up && make settings
```

- `WEB_BIND=0.0.0.0` publica el puerto en la red local; por defecto solo escucha en
  `127.0.0.1`. Vuelve a `127.0.0.1` al terminar: es una copia de datos de producción.
- `LOCAL_BASE_URL` es la URL que usa Drush para construir enlaces (correos enviados
  desde la cola). Con `localhost`, el enlace de un correo no se abre desde el teléfono.
- `settings.php` solo acepta `localhost`, `127.0.0.1`, rangos privados (`10.*`,
  `192.168.*`, `172.16-31.*`) y nombres `*.local`. Con otro nombre, Drupal responde 400.

**HTTP sin TLS.** Producción exige HTTPS; en local es HTTP. Android bloquea el tráfico
en claro por defecto (`android:usesCleartextTraffic="true"` en el manifiesto de
*debug*) e iOS necesita una excepción de ATS para ese host. Solo en compilaciones de
desarrollo.

**Push y chat** no funcionan contra local: OneSignal y Firebase están sin
credenciales. Para probarlos con proyectos de prueba, pon las claves en
`.local/settings.overrides.php` (se incluye al final del `settings.php` local) y
ejecuta `make settings`.

---

## 9. Flujo de trabajo: de local a producción

1. **Rama.** Nunca en `main`.
2. **Desarrollar y probar en local.** `make cc` / `make updb` según el cambio; probar
   los endpoints con curl o la app; revisar Mailpit si el cambio envía correo.
3. **Antes de dar el cambio por bueno:**
   - `make test` y `make stan` en verde.
   - `make e2e` en verde si se tocó `auth`.
   - `make logs-php` vacío y `make watchdog` sin `Warning`/`Notice`/`Deprecated`
     nuevos. El PHP local registra `E_ALL`.
   - Si hay `hook_update_N()`: probarlo sobre datos recién importados
     (`make reset` y luego `make updb`), que es el estado real de producción.
4. **Snapshot de Lightsail.** Es la única vuelta atrás. Consola (Instancia → Snapshots
   → *Create snapshot*) o:

   ```bash
   aws lightsail create-instance-snapshot --region us-east-2 \
     --instance-name <nombre> --instance-snapshot-name pre-deploy-$(date +%Y%m%d-%H%M)
   ```

5. **Deploy.**

   ```bash
   scripts/deploy.sh --env prod
   ```

   Copia el módulo, normaliza permisos y ejecuta `drush updb -y` y `drush cc all` como
   `www-data`.
6. **Verificación en producción** (todo como `sudo -u www-data`):

   ```bash
   curl -s https://sistema.crespcord.com/api/v1/ping
   sudo -u www-data drush vget --exact mail_system      # default-system y claves myapi_*
   sudo -u www-data drush sqlq "SELECT schema_version FROM dr_system WHERE name='myapi'"
   sudo tail /var/log/php8.3-fpm-errors.log
   sudo -u www-data drush watchdog-show --severity=warning --count=20
   ```

   Y una pasada por el endpoint o la pantalla que cambió.
7. Si algo falla y no se arregla en minutos: restaurar el snapshot.

---

## 10. Problemas comunes

**`make import` falla con «Falta sites/default/files/mailsystem/…».**
Falta la clase generada de `mailsystem`. `make pull-code` la trae. El contenedor `web`
queda parado a propósito para que Drupal no arranque sin ella.

**`make import` falla con «Quedan direcciones de correo reales…».**
Un campo nuevo guarda emails. Añade la columna que indica a `scripts/local/sanitize.sql`
y repite `make import`.

**«Espacio insuficiente».**
`make clean-dumps`, `docker system df` y, si hace falta, `docker builder prune` o
`docker image prune`. La base vive en el volumen `crespcord_dbdata`.

**El sitio responde 400 Bad Request.**
El host no encaja con `trusted_host_patterns` (ver sección 8).

**Un cambio en el código no se ve.**
`make cc` si tocaste `hook_menu()`, `myapi.info` o una clase. Para el resto, opcache
revalida en cada petición; `make restart` lo descarta del todo.

**Imágenes rotas y ficheros que no descargan.**
Esperado: `sites/default/files` y `/var/www/private` no se traen. Los ficheros que
subas en local sí funcionan. El watchdog muestra una vez «JavaScript translation file
… was lost»; Drupal lo regenera solo.

**El buscador del back-office no encuentra nada.**
El índice de búsqueda no se trae. `make cron` indexa 100 nodos por pasada.

**Un correo no llega a Mailpit.**
Si lo envía una cola, `make queue`. Si sigue sin llegar,
`scripts/local/drush.sh vget --exact mail_system` y `make watchdog`.

**Cambié `.env` y no pasa nada.**
Puertos y `WEB_BIND`: `make up`. Lo que va a `settings.php` (`LOCAL_BASE_URL`,
`LOCAL_BOT_API_KEY`, credenciales de la base): `make settings`. La contraseña de la
base solo se aplica al crear el volumen: `make destroy && make up && make reset`.

**`drush` dice «must run as www-data».**
Estás dentro del contenedor con otro usuario. Usa `make shell` o `scripts/local/drush.sh`.

---

## Diferencias con producción

- **PHP 8.3.37 frente a 8.3.36**: el PPA sirve siempre el último parche de la 8.3.
- **HTTP, sin TLS ni HSTS**, y sin la redirección a HTTPS.
- **`error_reporting = E_ALL`** en local; producción enmascara `E_DEPRECATED` y
  `E_STRICT` en el log de PHP. Local es más estricto a propósito.
- **Sin cron ni colas automáticas** (`make cron`, `make queue`).
- **Sin `sites/default/files` ni ficheros privados** de producción.
- **`dr_field_revision_*`, índice de búsqueda, cachés, sesiones y colas vacíos.**
- **Integraciones apagadas**: OneSignal, Firebase, PayPhone (credenciales borradas y
  directorio `payphone/` no copiado), comprobación de actualizaciones.
- **Deep links** (`myapi_password_reset_deep_link_base`,
  `myapi_service_request_deep_link_base`): producción los fija en su `settings.php`;
  en local valen los valores por defecto del módulo salvo que se definan en
  `.local/settings.overrides.php`.
- **InnoDB buffer pool de 768 MB** (128 MB en producción): solo afecta a la velocidad.
- **Apache**: un bloque extra (`docker/web/myapi-local.conf`) niega por HTTP todo el
  directorio del módulo salvo `css/`, `js/` y `assets/`, porque aquí el módulo es el
  repositorio entero (`scripts/`, `tests/`, `.env`).
- **Módulo `simpletest`** instalado tras la primera ejecución de `make integration`
  (dos tablas más: `dr_simpletest`, `dr_simpletest_test_id`).

---

## Ficheros del entorno

```
docker-compose.yml           web + db + mailpit
docker/web/                  Dockerfile, configuración de Apache y PHP, envoltorio de drush
.env.example                 plantilla de .env
Makefile                     atajos (make help)
scripts/local/
  lib.sh                     funciones comunes
  pull-code.sh               rsync desde producción
  pull-db.sh                 mysqldump desde producción
  import-db.sh               carga + saneado + updb + cc all
  sanitize.sql               saneado (SQL)
  check-sanitized.sh         comprueba que no quedan emails reales
  post-import.php            contraseñas locales y verificación de mail_system
  gen-settings.sh            genera el settings.php local
  drush.sh, shell.sh         acceso al contenedor
  deploy.sh, deploy-manual.sh, run-*.sh   equivalentes locales de scripts/
.local/                      (gitignored) docroot, volcados, ficheros privados
```
