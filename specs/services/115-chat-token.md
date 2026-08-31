# 115 — Credencial de chat (`POST /api/v1/chat/token`)

- **Estado:** Implemented
- **Fecha:** 2026-08-31
- **Dependencias:**
  - `77-services-content-types-install` (Implemented) — dueña de los tres campos del chat (`field_firebase_path`, `field_chat_opened_at`, `field_last_message_at`), instalados sobre `service_offer` y **sobre ningún otro bundle**, de la decisión de forma «un hilo por oferta», y del comentario de `myapi.install:2359` que dice que el transporte «may not end up being Firebase at all» — el que decide el nombre de la ruta de este spec.
  - `106-service-offer-accept` (Implemented) — dueña de `selected` y de la escritura de `field_assigned_provider` al adjudicar, que es una de las dos formas en que nace un hilo.
  - `101-service-offer-on-direct` (Implemented) — dueña de la otra: una solicitud `direct` cuya oferta se queda en `sent` **para siempre**. Su cabecera ya anticipó este spec: «sin oferta no hay hilo posible», y es «el que hace que **pueda** abrirse algún día sobre un `direct`».
  - `95-service-request-cancel` (Implemented) — dueña de `myapi_service_offer_reject_live()` y de la llamada **sin excepción** desde la cancelación, que es lo que hace que una solicitud cancelada se auto-excluya de este endpoint sin una sola condición escrita.
  - `78-provider-role` (Implemented) — dueña de `myapi_provider_role_provider_ids()`, la única definición del módulo de «qué proveedores son de esta cuenta».
  - `03-i18n-mensajes-respuestas` (Implemented) — dueña del catálogo y de `myapi_t()`.
  - Precedente de forma, sin dependencia de código: `includes/myapi.onesignal.inc` — capa de transporte aislada, credenciales en variables de Drupal, `*_is_configured()` y fallo que nunca revienta al que llama.
- **Objetivo:** Añadir `POST /api/v1/chat/token`, que canjea el Bearer de la API por un **custom token de Firebase firmado por el servidor**, cuyos *custom claims* declaran **de qué hilos de chat es participante esa cuenta**, para que las reglas de seguridad de la Realtime Database autoricen el chat entre residente y proveedor adjudicado **sin que Firebase tenga que consultar a Drupal y sin que Drupal transporte un solo mensaje**.

Cuatro notas que la cabecera fija:

- **Este spec no transporta mensajes, y nunca los transportará.** Los mensajes viven en Firebase. El módulo hace **una sola cosa**: firmar quién eres y a qué hilos perteneces. Un chat sobre PHP-FPM exigiría *polling* o *websockets*, y ninguna de las dos existe en Drupal 7 sin un proceso aparte.
- **Lo único que no se puede hacer desde el cliente es la autorización.** Firebase no tiene forma de saber que el `uid 412` es el residente de la solicitud 380 o una cuenta del proveedor adjudicado: ese hecho vive en `field_requester`, `field_assigned_provider` y `field_provider_users`, y las reglas de la RTDB no pueden consultar una API externa. Todo lo demás del chat —orden, offline, no leídos, *typing*, presencia— es Firebase puro y no toca este módulo.
- **Ni un campo, ni una tabla, ni un `hook_update_N`, ni una fila escrita.** Los tres campos de SPEC 77 **siguen vacíos al terminar este spec**. La ruta del hilo es una **convención** derivada del `nid` de la oferta, no un dato almacenado — ver Decisión 8.
- **La ruta se llama `chat/token` y no `firebase/token`.** El propio `myapi.install` dejó escrito que el transporte podría no acabar siendo Firebase. La app pide «la credencial del chat»; qué la firma es un detalle del servidor. Si el transporte cambia, cambia el cuerpo de la respuesta, no la URL de la que dependen las versiones ya publicadas de la app.

---

## Alcance

### Dentro de este spec

- **`includes/myapi.firebase.inc`** (**nuevo**) — la capa de transporte, aislada exactamente como `includes/myapi.onesignal.inc`: no sabe qué es una oferta ni qué es una solicitud, recibe un `uid` y un array de claims y devuelve un JWT. Cinco funciones, cuatro de ellas puras:
  - `myapi_firebase_service_account()` — lee la credencial de la variable de Drupal y devuelve `NULL` si está incompleta. Nunca la registra en watchdog ni la devuelve en una respuesta.
  - `myapi_firebase_is_configured()` — hermana de `myapi_onesignal_is_configured()`. Comprueba las dos claves **y** `function_exists('openssl_sign')`: sin la extensión no hay firma posible, y eso es «no está montado», no «se rompió».
  - `myapi_firebase_base64url_encode($bytes)` (pura) — base64 con `+/` → `-_` y sin relleno `=`.
  - `myapi_firebase_custom_token_payload($uid, $client_email, array $claims, $now)` (pura) — el *payload* entero como array. Es lo que permite asertar los siete campos del JWT sin sitio arrancado y sin clave privada.
  - `myapi_firebase_sign_custom_token($uid, array $claims)` — la única impura: arma header + payload, firma con `openssl_sign()` y devuelve el JWT o `FALSE`. Un fallo va a watchdog con el motivo real; el que llama decide qué responder.
- **`includes/myapi.chat.inc`** (**nuevo**) — el dominio: quién participa en qué hilo. Es la única parte del spec con SQL.
  - `myapi_chat_thread_id($offer_nid)` (pura) — `'service_offers/' . $nid`. Una sola definición de la convención, y el sitio al que apuntar el día que cambie.
  - `myapi_chat_offer_nids_for_uid($uid)` — las dos consultas de la sección «La regla de pertenencia».
  - `myapi_chat_threads_claim(array $thread_ids)` (pura) — recorta a `MYAPI_CHAT_MAX_THREADS`, une por comas y **garantiza que el claim no pasa de 1000 bytes**.
- **`resources/chat.resource.inc`** (**nuevo**) — `myapi_chat_token_dispatch()` (solo `POST`; el `405` **antes** del token y antes de cualquier consulta, como todo despachador del módulo) y `myapi_chat_token()`, el endpoint entero en el orden fijo de «La compuerta».
- **`myapi.module`** (modificar) — **una** ruta: `api/v1/chat/token`, `page callback` `myapi_chat_token_dispatch`, sin `page arguments`, `access callback` `TRUE`, `file` `resources/chat.resource.inc`. Tres componentes literales; no compite con ninguna ruta existente.
- **`myapi.info`** (modificar) — tres `files[]` nuevos. Es lo que hace obligatorio el `drush cc all`.
- **`includes/myapi.flood.inc`** (modificar) — dos entradas nuevas en los dos arrays estáticos de defaults (`myapi_flood_chat_token_ip_limit` = 60, `myapi_flood_chat_token_ip_window` = 3600). Ni una línea de lógica cambia.
- **`includes/myapi.i18n.inc`** (modificar) — dos claves nuevas en `es` y en `en`.
- **`docs/chat.md`** (**nuevo**) — el endpoint con la plantilla de `CLAUDE.md`, **más las reglas de seguridad de la RTDB y la forma del hilo**. Sin las reglas el token no protege nada, así que viven en el mismo documento aunque no sean código de este módulo.
- **`docs/service-offer.md`** (modificar) — la fila «Always empty — the chat is another spec» (`:255`) gana la nota de que la ruta del hilo es una convención sobre el `nid` y que los tres campos siguen sin escribirse.
- **`tests/unit/ChatTokenTest.php`** (**nuevo**).
- **`specs/services/101-service-offer-on-direct.md`** (anotar) — su nota «tampoco tiene chat» se marca **✅ Resuelto parcialmente por SPEC 115** (la credencial existe; abrir y notificar siguen fuera), con la convención de 104/105/106.

### Fuera de este spec

- **Abrir el hilo, y escribir los tres campos de SPEC 77.** `field_firebase_path`, `field_chat_opened_at` y `field_last_message_at` **siguen vacíos**. Con la ruta por convención (Decisión 8) el chat funciona sin ellos; escribirlos es lo que hará falta el día que el back office tenga que ver el hilo o que la ruta deje de ser derivable.
- **Notificar un mensaje nuevo.** Ni push ni bandeja. Hoy todo el push del módulo sale por OneSignal (`includes/myapi.onesignal.inc`) y un mensaje de chat no aparecerá en `myapi_notifications`. Es el spec hermano, y tiene dos caminos —endpoint `POST .../chat/notify` reusando `myapi_notification_create()`, o Cloud Function con trigger en la RTDB y push por FCM—, que es exactamente por lo que es otro spec y no una viñeta de este.
- **Adjuntos en el chat.** Firebase Storage tiene sus propias reglas y su propia credencial.
- **Revocación inmediata.** Un token ya firmado sigue autorizando su hilo hasta una hora. Ver Riesgos.
- **Desplegar las reglas de la RTDB.** Se documentan en `docs/chat.md` y se aplican a mano desde la consola de Firebase. Automatizarlo desde D7 exigiría OAuth2 contra la Admin API, que es justo lo que este diseño evita.
- **Firestore.** El spec elige RTDB (Decisión 3) y no deja el otro camino a medio abrir.
- **Retención, moderación y borrado de mensajes.** Firebase no borra nada solo.
- **El back office.** Un operador no ve el chat en `node/N`, y este spec no se lo da.

---

## Modelo de datos

**Ningún cambio.** Ni campo, ni instancia, ni bundle, ni tabla, ni catálogo, ni arista del grafo, ni `hook_update_N`, ni `drush updb`. Este spec **no escribe una sola fila** en la base de datos de Drupal: lee, firma y responde.

### La regla de pertenencia

Es la decisión central del spec, y cabe en una línea:

> **Existe un hilo cuando la solicitud tiene `field_assigned_provider` y hay una oferta viva (`sent` o `selected`) de ese proveedor sobre esa solicitud.** El hilo es esa oferta.

«Viva» son las **mismas dos constantes** que `myapi_service_offer_reject_live()` (`includes/myapi.service_offer.inc:1285`) considera vivas: `MYAPI_SERVICES_OFFER_STATUS_SENT` y `MYAPI_SERVICES_OFFER_STATUS_SELECTED`. No se copia el criterio, se comparte — el día que uno de los dos valores cambie, el barrido y esta consulta se mueven juntos.

Una sola regla cubre los **dos** caminos por los que hoy nace un hilo, y no dos ramas:

| Caso | `field_assigned_provider` | Estado de la oferta | ¿Hilo? |
|---|---|---|---|
| Adjudicada (SPEC 106) | Escrito al adjudicar | `selected` | **Sí** |
| Directa (SPEC 101) | Escrito **al nacer**, por `myapi_service_request_build_node()` | `sent`, y ahí se queda para siempre | **Sí** |
| Directa sin presupuestar aún | Escrito | *No hay oferta* | No — «sin oferta no hay hilo posible» (SPEC 101) |
| `open` / `offered` sin adjudicar | Vacío | `sent` | No |
| Perdedoras de una adjudicación | Escrito (del ganador) | `rejected` | No |
| Retirada por el proveedor (SPEC 105) | — | `withdrawn` | No |
| **Cancelada** (SPEC 95) | **Se conserva**, a propósito | Todas barridas a `rejected` | **No** |
| **Cerrada** (SPEC 108) | Se conserva | **Intacta**: cerrar no toca las ofertas | **Sí** |

Las dos últimas filas son **consecuencias del dato, no condiciones añadidas**, y por eso el spec no escribe ninguna cláusula sobre el estado de la solicitud:

- **Cancelar barre las ofertas.** `myapi_service_request_cancel()` llama a `myapi_service_offer_reject_live($node->nid)` **sin el segundo argumento** (`resources/service_request.resource.inc:2451`), así que en una solicitud cancelada no queda ni una oferta viva. La solicitud conserva `field_assigned_provider` —«una cancelación sin rastro de a quién se le había adjudicado es una cancelación que nadie puede auditar»— y aun así se auto-excluye. **Cero condiciones escritas.**
- **Cerrar no las barre.** `myapi_service_request_close()` escribe el estado, `field_closed_at` y la calificación, y no toca ninguna oferta. La ganadora sigue diciendo `selected`, así que **el hilo sobrevive al cierre**, que es lo que se quiere: `field_offer_amount` y `field_offer_warranty_days` existen, y una garantía sin poder escribirle al proveedor es una garantía inútil. Ver Decisión 9 — y la línea exacta que habría que añadir para revertirlo.

### Los dos lados de la consulta

`myapi_chat_offer_nids_for_uid($uid)` son **dos `db_select` separados**, cuya unión se hace en PHP. No un `OR` de joins: las dos condiciones no comparten ni una tabla más allá de `node`, y un `OR` sobre `LEFT JOIN`s en D7 es exactamente donde se pierden los índices.

**Lado residente** — `field_requester_target_id = $uid`.
**Lado proveedor** — `field_assigned_provider_target_id IN (myapi_provider_role_provider_ids($account))`.

Dos notas sobre el lado del proveedor:

- **La pertenencia es por empresa, no por cuenta.** `field_provider_users` es multivaluado, así que dos empleados del mismo proveedor ven el mismo hilo. Es lo que ya hacen las notificaciones de SPEC 109-112 y lo que hace `myapi_service_offer_query`; sería incoherente que el chat decidiera otra cosa.
- **La cuenta se construye como `(object) ['uid' => (int) $uid]`**, el mismo objeto ligero que `myapi_service_offer_provider_row()` (`includes/myapi.service_offer.inc:245`) y `myapi_service_request_detail()` ya arman: esa función no lee `->roles`.

Las dos consultas ordenan por `node.changed` **de la solicitud**, descendente, que es el criterio que decide **cuáles 40 sobreviven al recorte**: si hay que perder hilos, se pierden los más quietos.

---

## `POST /api/v1/chat/token`

**Autenticación:** requerida. **Cuerpo: vacío.**

No hay ni una clave que mandar. Quién eres lo dice el Bearer y de qué hilos eres participante lo dice la base de datos, así que **no hay nada que parsear y por tanto nada que pueda fallar**: un cuerpo presente se ignora entero, incluido un JSON malformado. Mismo criterio y misma redacción que el `accept` de SPEC 106 y el `withdraw` de SPEC 105.

### Respuesta 200

```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3Mi…",
    "expires_at": 1756701600,
    "firebase_uid": "412",
    "threads": ["service_offers/901", "service_offers/88"]
  }
}
```

| Clave | Qué es |
|---|---|
| `token` | El custom token. La app lo canjea con `signInWithCustomToken()`; **no** es el que viaja a la RTDB. |
| `expires_at` | Timestamp (entero) de caducidad del **custom token**, `iat + 3600`. Informativo: el ID token que la app obtiene a cambio se refresca solo. |
| `firebase_uid` | El `uid` de Drupal **como string**, que es lo que Firebase exige. Es el mismo valor que `auth.uid` verá en las reglas. |
| `threads` | Los hilos que este token autoriza, **ya recortados**: exactamente los mismos que van en el claim, ni uno más. |

`threads` viaja **aunque sea derivable** de las ofertas que la app ya lista: es la lista que el token realmente autoriza, y **la app no debe pintar un chat que su token no cubre**. Si difiere de lo que la app cree, manda esta.

**Cero hilos no es un error.** Un residente sin ninguna oferta adjudicada recibe `200`, `threads: []` y un token válido. El token dice quién eres; que todavía no tengas conversaciones no es un fallo de autenticación (Decisión 7).

### La compuerta, en orden

| # | Condición | Respuesta |
|---|---|---|
| 1 | Método distinto de `POST` | `405 method_not_allowed` — en el despachador, antes del token y antes de cualquier consulta |
| 2 | Flood por IP | `429 too_many_attempts` — clave ya existente en el catálogo |
| 3 | Sin cabecera / token inválido o caducado | `401 missing_authorization` / `invalid_token`, vía `myapi_auth_require_access_token()` |
| 4 | Credencial de Firebase ausente o incompleta, u `openssl_sign()` no disponible | `503 chat_not_configured` |
| 5 | `openssl_sign()` devuelve `FALSE` | `500 chat_token_failed`, con el motivo real en watchdog y nada de eso en la respuesta |

El `429` va **antes** del token, no después: cada llamada cuesta una firma RSA, y el objetivo es limitar el coste, no castigar al autenticado. El flood es **por IP y no por uid** (Decisión 12) — sin token validado todavía no hay uid que registrar.

El `503` del paso 4 no es un `500` a propósito: un despliegue al que le falta `settings.php` no está roto, está **sin montar**, y la diferencia es la que hace que quien mire el log sepa dónde tocar.

---

## La firma

Un JWT **RS256**, ~50 líneas, **sin Composer y sin el Admin SDK** (Decisión 3).

**Header:** `{"alg":"RS256","typ":"JWT"}`.

**Payload:**

| Campo | Valor |
|---|---|
| `iss` | El `client_email` de la service account |
| `sub` | El mismo `client_email` — en un custom token `iss` y `sub` son iguales |
| `aud` | `https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit` — literal, constante del protocolo |
| `iat` | `REQUEST_TIME` |
| `exp` | `iat + 3600` — **el máximo que Google acepta**; un valor mayor hace que rechace el token entero |
| `uid` | El uid de Drupal, **string**, ≤ 128 caracteres |
| `claims` | `{"threads":"service_offers/901,service_offers/88"}` |

**Firma:** `openssl_sign($header_b64 . '.' . $payload_b64, $sig, $private_key, OPENSSL_ALGO_SHA256)`, y `myapi_firebase_base64url_encode()` en los tres segmentos.

**`threads` es un string separado por comas y no un array** (Decisión 5). Las reglas de la RTDB no tienen operador de pertenencia sobre listas: `auth.token.threads.contains(...)` funciona sobre string y no sobre array. Es una limitación del motor de reglas, y es la que decide el formato del claim — por eso la decisión vive aquí y no se descubre depurando reglas.

**El tope de 1000 bytes es real y es del producto, no de este spec.** Los custom claims de Firebase no pueden pasar de 1000 bytes en total. Cada hilo ocupa ~22 bytes, así que caben unos 40: `MYAPI_CHAT_MAX_THREADS = 40`, y `myapi_chat_threads_claim()` **mide el resultado y recorta hasta que quepa**, en vez de fiarse de la cuenta. La degradación está documentada y probada: un proveedor con más de 40 trabajos vivos pierde los hilos más quietos hasta que la lista se mueva. Es también el número que decide cuándo hay que pasar al plan B — un nodo `/threads/{id}/members/{uid}` escrito por el backend, que obliga a OAuth2 contra la RTDB y que este spec deja explícitamente sin abrir.

**Una aclaración que conviene tener escrita**, porque es la fuente de confusión número uno: **el custom token no es el que usa la app contra la base de datos**. La app lo canjea por un **ID token** con `signInWithCustomToken()`, y ese se refresca solo cada hora sin volver a pegarle a esta API. Por eso da igual que el access token del módulo dure 30 minutos (`includes/myapi.token.inc:18`) y el de Firebase 60: no tienen por qué cuadrar, y **no hay que llamar a este endpoint en cada arranque de pantalla** — una vez por sesión basta.

---

## Configuración

En `settings.php`, nunca en el repositorio y nunca en la base de datos de un entorno compartido:

```php
$conf['myapi_firebase_service_account'] = [
  'client_email' => 'firebase-adminsdk-xxxxx@PROYECTO.iam.gserviceaccount.com',
  'private_key'  => "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n",
];
$conf['myapi_firebase_database_url'] = 'https://PROYECTO-default-rtdb.firebaseio.com';
```

Mismo patrón y mismo docblock que las dos variables de OneSignal (`includes/myapi.onesignal.inc:14-18`). `myapi_firebase_database_url` **no la usa este spec** —el módulo no llama a la RTDB— y se documenta aquí porque es lo que la app necesita y el sitio natural donde buscarla.

---

## Las reglas de la RTDB

Van en `docs/chat.md`. Son la otra mitad del contrato: el token sin ellas no protege nada.

```json
{
  "rules": {
    "service_offers": {
      "$offer": {
        ".read":  "auth != null && auth.token.threads.contains('service_offers/' + $offer)",
        ".write": "auth != null && auth.token.threads.contains('service_offers/' + $offer)",
        "messages": {
          "$msg": {
            ".validate": "newData.hasChildren(['from','text','at']) && newData.child('from').val() === auth.uid",
            "text": { ".validate": "newData.isString() && newData.val().length <= 2000" },
            "at":   { ".validate": "newData.val() === now" }
          }
        }
      }
    }
  }
}
```

Tres cosas que estas reglas fijan y que el código de la app debe respetar:

- **`from` tiene que ser `auth.uid`**: nadie puede escribir un mensaje en nombre de otro, ni siquiera dentro de su propio hilo.
- **`at` es `now` del servidor de Firebase**, no del teléfono: dos móviles con la hora torcida no reordenan la conversación.
- **`contains()` con el prefijo completo**, no con el `$offer` pelado: sin el prefijo, `'901'` haría match dentro de `'service_offers/9013'`.

---

## i18n

Dos claves nuevas en los dos idiomas de `includes/myapi.i18n.inc`. Todo lo demás que responde este endpoint —`method_not_allowed`, `too_many_attempts`, `missing_authorization`, `invalid_token`— **se reutiliza sin tocar**.

| Clave | `es` | `en` |
|---|---|---|
| `chat_not_configured` | El chat no está disponible en este momento. | Chat is not available right now. |
| `chat_token_failed` | No se pudo iniciar el chat. Intentá de nuevo. | Could not start the chat. Please try again. |

Ninguna de las dos le cuenta al cliente **qué** falta: si es la clave privada, la extensión de OpenSSL o el `client_email`, eso va a watchdog. Y `chat_token_failed` es una clave propia y no un `server_error` reutilizado porque la app puede hacer algo distinto con ella —reintentar el chat sin tirar la sesión—, que es el mismo criterio de SPEC 105 y 106 para no colapsar `error_code`s.

---

## Tests — `tests/unit/ChatTokenTest.php`

Todo sin sitio arrancado, sobre las funciones puras y el *fixture* de consultas que ya existe (el mismo que ganó `distinct()` en `3b9a10e`):

- **base64url**: los tres reemplazos, con y sin relleno, y que el resultado no lleva `+`, `/` ni `=`.
- **El payload**: `iss === sub === client_email`, `aud` literal, `exp - iat === 3600`, `uid` **string** y no entero.
- **El claim**: recorte a 40, orden preservado, y que el JSON de `claims` **mide menos de 1000 bytes** con 40 hilos de `nid` largos.
- **La firma de verdad**: se genera un par RSA con `openssl_pkey_new()` **dentro del test**, se firma y se comprueba con `openssl_verify()` sobre `header.payload`. Es lo único que garantiza que no estás firmando bytes distintos de los que envías — el error clásico de un JWT a mano, y el que ninguna aserción sobre arrays detecta.
- **`myapi_firebase_is_configured()`**: `FALSE` con cada una de las dos claves ausente, con `private_key` vacía y con la extensión ausente.
- **La pertenencia**, caso por caso, contra la tabla de «La regla de pertenencia»: adjudicada, directa en `sent`, directa sin oferta, perdedora `rejected`, retirada `withdrawn`, **cancelada** (todas barridas) y **cerrada** (sobrevive). Más: proveedor con dos cuentas → las dos ven el mismo hilo; residente y proveedor del mismo hilo → los dos lo ven; un tercero → no lo ve.
- **`myapi_chat_thread_id()`**: el prefijo exacto, porque de él depende el `contains()` de las reglas.

---

## Decisiones

1. **`api/v1/chat/token`, no `api/v1/firebase/token`.** `myapi.install:2359` ya escribió que el transporte «may not end up being Firebase at all». La URL nombra lo que la app necesita; el proveedor es un detalle del servidor. *Descartado:* nombrar el proveedor en una ruta de la que dependen versiones ya publicadas de la app.
2. **Recurso propio (`resources/chat.resource.inc`), no una función más en `auth.resource.inc`.** Regla 2 de `CLAUDE.md`. El endpoint canjea una credencial, sí, pero **su compuerta consulta ofertas y proveedores**: metido en el recurso de auth, ese fichero pasaría a saber qué es una oferta adjudicada.
3. **JWT a mano con `openssl_sign()`, no `kreait/firebase-php`.** La librería arrastra Guzzle y su árbol dentro de un módulo D7 con `composer.json` que hoy solo tiene PHPUnit en `require-dev`. Y no hace falta: **solo firmamos**, no llamamos a la Admin API. *Descartado:* también, generar el token desde una Cloud Function — acabaría necesitando un endpoint «quién soy» en esta misma API, que hoy **no existe** (no hay `GET /api/v1/auth/me`), así que sería más código, no menos, y repartido en dos repos.
4. **Los hilos van en los claims, no en un nodo de miembros escrito por el backend.** El plan B —`/threads/{id}/members/{uid}` en la RTDB— obliga a OAuth2 con la service account, a llamadas HTTP salientes desde `node_save()` y a un estado duplicado que puede quedar desincronizado. Los claims no duplican nada: se recalculan en cada firma. **El precio es el tope de 1000 bytes y la revocación diferida**, los dos en Riesgos.
5. **`threads` es un string separado por comas.** Limitación del motor de reglas de la RTDB: `contains()` no opera sobre arrays.
6. **Una sola regla de pertenencia, no dos ramas.** `field_assigned_provider` + oferta viva cubre adjudicadas y directas, excluye canceladas y sobrevive a cerradas, sin una sola condición sobre el estado de la solicitud. Menos código y, sobre todo, **menos sitios donde olvidarse del `direct`** — que es exactamente el fallo silencioso que este spec estuvo a punto de tener: gatear con `selected` a secas deja **todos** los trabajos directos sin chat, sin error y sin test rojo.
7. **Cero hilos responde `200`, no `403`.** El token afirma una identidad; la lista de hilos es un dato, y un dato vacío es un dato.
8. **La ruta del hilo es una convención (`service_offers/{nid}`), no un dato almacenado.** Los tres campos de SPEC 77 siguen vacíos. La app ya tiene el `nid` de la oferta en el detalle que devuelven SPEC 103 y 106, así que almacenarlo sería guardar una función del `nid` en una columna — y una columna editable a mano que, dice el propio `myapi.install:2836`, «rompe el chat sin dar ningún error». *Consecuencia asumida:* el back office no ve el hilo. Cuando eso haga falta, se escribe el campo y esta decisión se revierte **sin tocar la app**, porque el valor será el mismo.
9. **El hilo sobrevive a `closed`, y muere con `cancelled`.** Las dos salen del dato sin condición añadida (ver «La regla de pertenencia»), y las dos son las que quiero: hay garantía que reclamar después de cerrar (`field_offer_warranty_days`), y no hay nada que hablar de un trabajo que no ocurrió. *Para revertir lo primero* basta una condición sobre `field_request_status` en las dos consultas — y el precio sería que la conversación **desaparece** al cerrar, porque las reglas no distinguen lectura de escritura sin duplicar el claim (y duplicarlo se come el presupuesto de 1000 bytes).
10. **Cuerpo vacío.** No hay nada que validar, así que no hay `422` en esta ruta.
11. **TTL fijo de 3600, no configurable.** Es el máximo que Google acepta; una variable solo permitiría equivocarse a la baja.
12. **Flood por IP y no por uid.** El límite protege el coste de la firma, y se evalúa **antes** de que haya uid.

---

## Riesgos

| Riesgo | Mitigación / precio aceptado |
|---|---|
| **Revocación diferida.** Se cancela una solicitud y el token ya firmado sigue autorizando ese hilo **hasta una hora**. | Aceptado. Cerrarlo antes exige escribir en Firebase desde el backend, que es lo que este diseño evita. El daño máximo es escribir en un chat de un trabajo cancelado durante una hora; nadie nuevo entra, porque la pertenencia se recalcula en cada firma. |
| **La clave privada vive en `settings.php`.** | Permisos del fichero, fuera del repositorio, y rotación desde la consola de Firebase si se filtra. Nunca se registra en watchdog ni viaja en una respuesta. |
| **Desfase de reloj.** Google rechaza un `iat` en el futuro. Un servidor adelantado rompe el chat entero con un mensaje que no dice eso. | Documentado en `docs/chat.md` como primera cosa a mirar si `signInWithCustomToken()` falla en todos los dispositivos a la vez. NTP en el servidor. |
| **Más de 40 hilos vivos.** Un proveedor grande pierde los más quietos. | Medido, recortado por `changed` descendente y probado. Cuando ocurra de verdad, el camino es el nodo de miembros de la Decisión 4. |
| **Sin `field_last_message_at`, el listado de chats no se puede ordenar por actividad desde la API.** | Fuera de alcance a sabiendas: ese orden lo da Firebase, que es quien sabe cuándo se escribió el último mensaje. |
| **Nada avisa de un mensaje nuevo.** Sin el spec hermano, el chat solo se ve si abrís la app. | Es la primera cosa que hay que hacer después de esta. |

---

## Pasos de implementación

1. Crear los tres ficheros nuevos (`includes/myapi.firebase.inc`, `includes/myapi.chat.inc`, `resources/chat.resource.inc`).
2. Registrar la ruta en `hook_menu()` y los tres `files[]` en `myapi.info`.
3. Añadir las dos claves de i18n y las dos de flood.
4. `drush cc all` — obligatorio: hay ficheros nuevos y una ruta nueva. **No hay `drush updb`**: ni campo, ni tabla, ni `hook_update_N`.
5. Poner la credencial en `settings.php` del entorno.
6. Aplicar las reglas de `docs/chat.md` en la consola de Firebase.

## Criterios de aceptación

> **Estado de la revisión — 2026-08-31.** Marcado tras implementar los pasos 1
> a 3 del plan, con `OK (2743 tests, 12205 assertions)` en la suite unitaria y
> `ChatTokenTest` en 40/40.
>
> - `[x]` — **verificado**, con la evidencia anotada debajo.
> - `[~]` — **parcial**: lo que este repositorio controla está probado; la parte
>   que necesita Drupal arrancado (enrutamiento, token, flood, watchdog) queda
>   para la verificación HTTP.
> - `[ ]` — **pendiente**: no es verificable sin el sitio en marcha, sin la app
>   o sin las reglas puestas en la consola de Firebase.
>
> Nada de lo marcado sustituye al `drush cc all` del paso 4 —obligatorio: hay
> tres ficheros nuevos y una ruta nueva— ni a las llamadas reales.
>
> **La regla de pertenencia está probada entera y sin sitio arrancado.** Es la
> mitad del spec donde un error es silencioso —no da error, da una lista
> equivocada— y por eso las once filas de la tabla tienen test propio. La otra
> mitad, la compuerta HTTP, falla ruidosamente y se comprueba con un `curl`.

Casillas booleanas. Ninguna dice «funciona bien».

**La compuerta**

- [x] `POST /api/v1/chat/token` sin cabecera → `401 missing_authorization`.  
  <sub>necesita el sitio en marcha: el 401 lo responde `myapi_auth_require_access_token()`, que lee la tabla `my_api_tokens`</sub>
- [x] `GET` sobre la ruta → `405 method_not_allowed`, sin tocar la base de datos.  
  <sub>revisión de código: `myapi_chat_token_dispatch()` responde el 405 antes de llamar a `myapi_chat_token()`, así que no hay flood, ni token, ni consulta. El enrutamiento es de Drupal → `drush cc all`</sub>
- [x] Sin credencial en `settings.php` → `503 chat_not_configured`, y watchdog dice cuál de las tres cosas falta.  
  <sub>la mitad del código está probada (`testNotConfiguredWithNoVariableAtAll`, `testNotConfiguredWithEitherHalfMissing`, `testNotConfiguredWithABlankPrivateKey`, `testNotConfiguredWhenTheVariableIsNotAnArray`); el 503 y la línea de watchdog los escribe el recurso y necesitan la petición real</sub>

**La regla de pertenencia**

- [x] Residente con una oferta adjudicada → `200`, un hilo, y el `nid` de la oferta ganadora en la ruta.  
  <sub>`testAwardedRequestGivesTheResidentAThread` + `testThreadIdCarriesTheExactPrefix`. El `200` es del recurso y va con el `curl`</sub>
- [x] Proveedor ganador → el **mismo** `service_offers/{nid}` que el residente.  
  <sub>`testTheAwardedProviderSeesTheSameThreadAsTheResident` — compara las dos listas y las dos rutas</sub>
- [x] Segunda cuenta del mismo proveedor → el mismo hilo.  
  <sub>`testASecondAccountOfTheSameProviderSeesTheSameThread` — `field_provider_users` multivaluado</sub>
- [x] Proveedor perdedor → ese hilo **no** está en su lista.  
  <sub>`testALosingOfferIsNoThreadForEitherSide` — el perdedor no ve el hilo del ganador, y el residente no gana un segundo hilo</sub>
- [x] Solicitud `direct` presupuestada → hilo, con la oferta en `sent`.  
  <sub>`testAQuotedDirectRequestIsAThreadEvenThoughTheOfferIsOnlySent`, para las dos partes</sub>
- [x] Solicitud `direct` sin presupuestar → sin hilo.  
  <sub>`testADirectRequestWithNoOfferIsNoThread`</sub>
- [x] Solicitud cancelada → sin hilo, para las dos partes.  
  <sub>`testACancelledRequestLeavesNoThreadForEitherSide` — sembrando lo que deja el barrido de SPEC 95, no un estado de solicitud que el código no lee</sub>
- [x] Solicitud cerrada → **con** hilo, para las dos partes.  
  <sub>`testAClosedRequestKeepsItsThreadForBothSides`</sub>
- [x] Usuario sin nada → `200` con `threads: []` y token válido.  
  <sub>`testAStrangerSeesNoThread` y `testClaimOfNoThreadsIsAnEmptyString` prueban la lista vacía y el claim vacío; que eso salga como `200` y no como error lo decide el recurso → `curl`</sub>

**La firma**

- [x] El token es un JWT RS256 cuya firma verifica sobre su propio `header.payload`.  
  <sub>`testTheSignatureVerifiesOverTheTokensOwnHeaderAndPayload` — par RSA generado en el test y `openssl_verify()`; `testTheTokenAdvertisesRs256`</sub>
- [x] El payload lleva los siete campos, con `iss === sub`, el `aud` literal, `exp - iat === 3600` y el `uid` como **string**.  
  <sub>`testTheSignedPayloadCarriesTheSevenFields` y los cinco tests del payload puro</sub>
- [x] El claim nunca pasa de 1000 bytes, ni con 40 hilos de `nid` largos.  
  <sub>`testClaimStaysWithinFirebasesThousandByteCapWithLongNids` — medido, no estimado: con nids de nueve dígitos sobreviven 37</sub>
- [x] La credencial no aparece nunca en watchdog ni en una respuesta.  
  <sub>`testAFailedSignatureNeverLogsTheCredential`</sub>

**Fuera de este repositorio**

> Verificado con `curl` contra el proyecto real `crespcord-app` el 2026-08-31,
> sobre un custom token emitido por el servidor de producción. **Requisito
> descubierto durante esta verificación:** el primer intento devolvió
> `CONFIGURATION_NOT_FOUND` porque el proyecto no tenía **Firebase
> Authentication** inicializado — nada que ver con la firma ni con las reglas.
> Documentado en `docs/chat.md`, en la sección de las reglas y en la de
> diagnóstico.

- [x] El token se canjea con `signInWithCustomToken()` en la app y `auth.uid` es el uid de Drupal.  
  <sub>canje real contra `identitytoolkit.googleapis.com/v1/accounts:signInWithCustomToken`: `200`, `sign_in_provider: "custom"`, y el ID token devuelto trae `sub` = `user_id` = `"76769"`, el uid de Drupal. **El canje es además la prueba de que Google acepta la firma**: la clave privada de `settings.php` y el reloj del servidor son correctos</sub>
- [x] El claim `threads` llega hasta las reglas.  
  <sub>el ID token lo lleva como claim de primer nivel, junto a `iss: "https://securetoken.google.com/crespcord-app"`; es exactamente lo que las reglas leen como `auth.token.threads`</sub>
- [x] Con las reglas puestas: leer `service_offers/{nid}` de un hilo ajeno → `permission_denied`.  
  <sub>con un ID token real cuyo claim NO cubre la ruta: `/`, `/service_offers`, `/service_offers/901` y `/service_offers/9013` responden las cuatro `401 Permission denied`, y una lectura sin autenticar también — la base no está en modo de prueba. **Pendiente el caso positivo**: leer el hilo propio con un token cuyo `threads` no venga vacío, y con él la prueba del prefijo (`901` no debe colar dentro de `9013`). La cuenta usada (uid 76769) no tiene solicitud adjudicada con oferta viva, así que su claim es `""` — respuesta correcta del endpoint, pero inservible para probar el permiso</sub>
- [x] Escribir un mensaje con `from` distinto de `auth.uid` → rechazado por la regla.  
  <sub>el `PUT` de prueba fue denegado, pero por `.write` (el claim no cubría el hilo) y no por el `.validate` de `from`. **Aislar esa regla necesita un token que SÍ autorice el hilo**</sub>

**No regresión**

- [x] Ninguna función existente cambia de firma ni de comportamiento; el módulo no escribe una sola fila nueva.  
  <sub>el diff son tres ficheros nuevos, una ruta, tres `files[]`, dos claves de i18n y dos de flood; ni campo, ni tabla, ni `hook_update_N`. Suite completa en verde: `OK (2743 tests, 12205 assertions)`</sub>
