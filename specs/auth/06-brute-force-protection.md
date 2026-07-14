# 06 — Protección contra fuerza bruta en endpoints de autenticación

- **Estado:** Implemented
- **Fecha:** 2026-06-29
- **Dependencias:**
  - `02-login-tokens` (Implemented) — endpoint de login en `resources/auth.resource.inc`.
  - `03-i18n-mensajes-respuestas` (Implemented) — catálogo `myapi_t()` y `myapi_error()`.
  - `04-refresh-token` (Implemented) — endpoint de refresh en `resources/auth.resource.inc`.
  - `05-middleware-access-token-logout` (Implemented) — endpoint de logout en
    `resources/auth.resource.inc`.
- **Objetivo:** Proteger los endpoints `/auth/login`, `/auth/refresh` y
  `/auth/logout` contra ataques de fuerza bruta usando la Flood API de Drupal 7,
  con umbrales configurables por variable Drupal.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.i18n.inc`** (modificar) — añadir la key `too_many_attempts`
  al catálogo `es`/`en`.
- **`includes/myapi.flood.inc`** (nuevo) — helpers `myapi_flood_check()` y
  `myapi_flood_register()` que encapsulan la Flood API de Drupal.
- **`resources/auth.resource.inc`** (modificar) — añadir llamadas a flood en
  `myapi_auth_login()`, `myapi_auth_refresh()` y `myapi_auth_logout()`.
- **`myapi.info`** (modificar) — registrar `files[] = includes/myapi.flood.inc`.
- **`docs/auth.md`** (modificar) — documentar el nuevo error 429 en los tres
  endpoints.

### Fuera de este spec

- **Otros endpoints futuros** — cada recurso nuevo aplicará flood en su propio
  spec si lo requiere.
- **Bloqueo por nombre de usuario en refresh y logout** — no hay username
  disponible en esos requests; solo se bloquea por IP.
- **Interfaz de administración** para ver o limpiar contadores de flood.
- **Purga de filas expiradas** en la tabla `flood` de Drupal (la gestiona el
  core vía `hook_cron()`; no requiere código aquí).
- **Notificación al usuario** del tiempo restante de bloqueo.

---

## Modelo de datos

No hay tablas nuevas. La Flood API de Drupal usa su propia tabla `flood`
gestionada por el core.

### Key nueva en el catálogo (`includes/myapi.i18n.inc`)

| Key                  | `es`                                               | `en`                                        |
|----------------------|----------------------------------------------------|---------------------------------------------|
| `too_many_attempts`  | Demasiados intentos. Inténtalo de nuevo más tarde. | Too many attempts. Please try again later.  |

### Variables Drupal configurables

| Variable                          | Valor por defecto | Significado                                      |
|-----------------------------------|-------------------|--------------------------------------------------|
| `myapi_flood_login_user_limit`    | `5`               | Intentos por nombre de usuario antes de bloquear |
| `myapi_flood_login_user_window`   | `3600`            | Ventana en segundos (1 hora)                     |
| `myapi_flood_login_ip_limit`      | `20`              | Intentos por IP en login antes de bloquear       |
| `myapi_flood_login_ip_window`     | `3600`            | Ventana en segundos (1 hora)                     |
| `myapi_flood_refresh_ip_limit`    | `10`              | Intentos por IP en refresh antes de bloquear     |
| `myapi_flood_refresh_ip_window`   | `900`             | Ventana en segundos (15 min)                     |
| `myapi_flood_logout_ip_limit`     | `20`              | Intentos por IP en logout antes de bloquear      |
| `myapi_flood_logout_ip_window`    | `900`             | Ventana en segundos (15 min)                     |

### Contrato de los helpers en `includes/myapi.flood.inc`

**`myapi_flood_check($event, $identifier, $limit_var, $window_var)`**
- Lee `variable_get($limit_var)` y `variable_get($window_var)`.
- Llama a `flood_is_allowed($event, $limit, $window, $identifier)`.
- Si NO está permitido → `myapi_error('too_many_attempts', 429)` (detiene la
  petición).

**`myapi_flood_register($event, $identifier, $window_var)`**
- Lee `variable_get($window_var)`.
- Llama a `flood_register_event($event, $window, $identifier)`.

### Flujo por endpoint

**Login** (`myapi_auth_login()`):
1. Leer `username` del body.
2. `myapi_flood_check('myapi_login_ip', $ip, 'myapi_flood_login_ip_limit', 'myapi_flood_login_ip_window')`.
3. `myapi_flood_check('myapi_login_user', $username, 'myapi_flood_login_user_limit', 'myapi_flood_login_user_window')`.
4. Intentar autenticación.
5. Si falla → `myapi_flood_register('myapi_login_ip', $ip, 'myapi_flood_login_ip_window')` +
   `myapi_flood_register('myapi_login_user', $username, 'myapi_flood_login_user_window')` → error 401.
6. Si éxito → `flood_clear_event('myapi_login_ip', $ip)` +
   `flood_clear_event('myapi_login_user', $username)` → respuesta 200.

**Refresh** (`myapi_auth_refresh()`):
1. `myapi_flood_check('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_limit', 'myapi_flood_refresh_ip_window')`.
2. Validar token.
3. Si falla → `myapi_flood_register('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_window')` → error 401.
4. Si éxito → `flood_clear_event('myapi_refresh_ip', $ip)` → respuesta 200.

**Logout** (`myapi_auth_logout()`):
1. `myapi_flood_check('myapi_logout_ip', $ip, 'myapi_flood_logout_ip_limit', 'myapi_flood_logout_ip_window')`.
2. Validar tokens.
3. Si falla → `myapi_flood_register('myapi_logout_ip', $ip, 'myapi_flood_logout_ip_window')` → error 401/422.
4. Si éxito → `flood_clear_event('myapi_logout_ip', $ip)` → respuesta 200.

La IP se obtiene con `ip_address()` (función de Drupal 7, respeta proxies
configurados en `$conf['reverse_proxy_addresses']`).

---

## Plan de implementación

Cada paso deja el sistema en estado funcional.

1. **`includes/myapi.i18n.inc` — añadir key al catálogo.** Insertar
   `too_many_attempts` en los arrays `es` y `en` de `myapi_t()`. En este punto
   la key existe pero nadie la usa aún.

2. **`includes/myapi.flood.inc` — crear el archivo.** Implementar:
   - `myapi_flood_check($event, $identifier, $limit_var, $window_var)` — comprueba
     con `flood_is_allowed()` y detiene con `myapi_error('too_many_attempts', 429)`
     si el límite se ha superado.
   - `myapi_flood_register($event, $identifier, $window_var)` — registra un
     intento fallido con `flood_register_event()`.

3. **`myapi.info` — registrar el archivo nuevo.** Añadir
   `files[] = includes/myapi.flood.inc`.

4. **`resources/auth.resource.inc` — proteger `myapi_auth_login()`.** Añadir
   al inicio de la función: check IP, check usuario. Tras fallo de credenciales:
   registrar ambos eventos. Tras éxito: limpiar ambos eventos con
   `flood_clear_event()`.

5. **`resources/auth.resource.inc` — proteger `myapi_auth_refresh()`.** Añadir
   al inicio: check IP. Tras fallo de validación: registrar evento. Tras éxito:
   limpiar evento.

6. **`resources/auth.resource.inc` — proteger `myapi_auth_logout()`.** Añadir
   al inicio (antes de `myapi_auth_require_access_token()`): check IP. Tras
   cualquier fallo de validación: registrar evento. Tras éxito: limpiar evento.

7. **`docs/auth.md` — documentar el error 429.** Añadir en la tabla de errores
   de los tres endpoints la fila: `429 | too_many_attempts | Límite de intentos
   superado`.

8. **Aplicar y verificar.** `drush cc all` y probar con `curl`:
   - Login: 5 intentos fallidos por usuario → 6.º devuelve 429; login exitoso
     limpia el contador.
   - Login: 20 intentos fallidos por IP → 21.º devuelve 429.
   - Refresh: 10 intentos fallidos por IP → 11.º devuelve 429; refresh exitoso
     limpia el contador.
   - Logout: 20 intentos fallidos por IP → 21.º devuelve 429; logout exitoso
     limpia el contador.
   - Verificar que `Accept-Language: en` devuelve el mensaje en inglés.

---

## Criterios de aceptación

- [x] 5 intentos de login fallidos con el mismo `username` → el 6.º devuelve
      **HTTP 429** con `error_code: "too_many_attempts"`, independientemente de
      la IP.
- [x] 20 intentos de login fallidos desde la misma IP (usuarios distintos) →
      el 21.º devuelve **HTTP 429** con `error_code: "too_many_attempts"`.
- [x] Un login exitoso limpia los contadores de flood del usuario y de la IP.
- [x] 10 intentos de refresh fallidos desde la misma IP → el 11.º devuelve
      **HTTP 429** con `error_code: "too_many_attempts"`.
- [x] Un refresh exitoso limpia el contador de flood de la IP.
- [x] 20 intentos de logout fallidos desde la misma IP → el 21.º devuelve
      **HTTP 429** con `error_code: "too_many_attempts"`.
- [x] Un logout exitoso limpia el contador de flood de la IP.
- [x] El check de flood se ejecuta **antes** de cualquier consulta a BD en cada
      endpoint.
- [x] Cambiar `myapi_flood_login_user_limit` vía `variable_set()` y rehacer la
      prueba refleja el nuevo umbral sin `drush cc all`.
- [x] `Accept-Language: en` en una respuesta 429 devuelve el mensaje en inglés;
      sin header o `Accept-Language: es` lo devuelve en español.
- [x] `drush cc all` no produce errores tras los cambios.

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| API de rate limiting | Flood API de Drupal 7 | Implementación propia con tabla custom | La Flood API es nativa de Drupal 7, está probada y no requiere schema nuevo |
| Identificador en login | IP + nombre de usuario (doble flood) | Solo IP | Mismo patrón que Drupal core; el bloqueo por usuario detiene ataques de diccionario dirigidos aunque vengan de IPs distintas |
| Identificador en refresh y logout | Solo IP | IP + uid extraído del token | Extraer el uid requeriría consultar la BD antes del check de flood, lo que anula el beneficio de bloquear antes de tocar BD |
| Reset en éxito | `flood_clear_event()` en login, refresh y logout exitosos | No limpiar | Un usuario legítimo no queda bloqueado tras un error puntual seguido de éxito |
| Umbrales | Variables Drupal (`variable_get` / `variable_set`) | Constantes en código | Permiten ajustar sin despliegue; útil si el tráfico real exige calibración |
| Momento del check | Inicio de la función, antes de tocar BD | Después de parsear y validar el body | Rechazar cuanto antes evita trabajo innecesario y protege la BD de carga por fuerza bruta |
| Encapsulación | Helpers en `includes/myapi.flood.inc` | Llamadas directas a la Flood API en cada recurso | Centraliza la lógica; si cambia la API o los nombres de variables, se toca un único archivo |
| HTTP status para rate limit | 429 Too Many Requests | 403 Forbidden | 429 es el código estándar para rate limiting (RFC 6585); el cliente Flutter puede distinguirlo de un 403 por permisos |

---

## Riesgos identificados

- **IP compartida (NAT corporativo, universidad).** El bloqueo por IP en login,
  refresh y logout puede afectar a usuarios legítimos que comparten salida
  pública con un atacante. *Mitigación:* los umbrales por IP son generosos (20
  intentos) respecto al bloqueo por usuario (5 intentos); en entornos con NAT
  masivo se puede subir el límite IP vía `variable_set()` sin tocar código.

- **Spoofing de IP tras proxy mal configurado.** Si Drupal no tiene
  `$conf['reverse_proxy_addresses']` configurado correctamente, `ip_address()`
  puede devolver la IP del proxy en lugar de la del cliente real, haciendo el
  flood por IP inefectivo o bloqueando el proxy entero. *Mitigación:* queda
  fuera del alcance de este spec pero documentado; revisar la configuración del
  proxy en producción antes de activar el módulo.

- **Ventana de concurrencia en el check.** Dos peticiones simultáneas desde la
  misma IP podrían superar el check de flood antes de que alguna registre el
  evento fallido. *Mitigación:* el riesgo es mínimo en PHP de proceso único con
  MySQL; no se añade locking explícito.

- **Crecimiento de la tabla `flood`.** Un ataque sostenido inserta muchas filas.
  *Mitigación:* Drupal core purga la tabla `flood` en cada ejecución de
  `hook_cron()`; no requiere código adicional aquí.
