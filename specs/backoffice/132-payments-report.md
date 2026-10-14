# SPEC 132 — Reporte de pagos con filtro por canal y exportación CSV

> **Estado:** Approved · **Depende de:** SPEC 14 (bundle `pagos` y sus campos), SPEC 20 (la app escribe pagos), SPEC 22/23 (catálogo de `field_estado_pago`: `Nuevo`, `Pendiente de verificar`, `Completado`, `Anulado`), SPEC 47 (patrón de página back-office sin AJAX y helpers `myapi_calendar_condominium_options()` / `_positive_int()` / `_filter_form_after_build()`), SPEC 129 (`field_canal`, `field_banco_emisor`, `field_banco_destino`, constantes `MYAPI_PAYMENT_CHANNEL_*`), SPEC 130 (el bot escribe `canal = bot`), SPEC 131 (molde exacto de listado y reglas comunes; **esta spec revoca su decisión 7**) · **Fecha:** 2026-10-14
>
> **Objetivo:** Añadir una página de back-office de solo lectura en `admin/content/payments` que liste los pagos con los filtros de la View *Reporte Pagos* más un filtro por canal (`App` / `Bot` / `Back office`), muestre el total del valor filtrado y permita descargar el resultado filtrado como CSV.

---

## Alcance

**Dentro:**

- **`includes/myapi.payments_admin.inc`** (nuevo): la página, su formulario de filtros, sus helpers puros, **su propia consulta** (con tag `node_access`, que se queda junto a la página como decidió la SPEC 125, decisión 7) y la salida CSV. Página y CSV comparten la misma función que arma la consulta filtrada, para que nunca diverjan.
- **`myapi.module`** (modificar):
  - `admin/content/payments` → `MENU_NORMAL_ITEM`, mismo patrón que `admin/content/service-requests`.
  - `admin/content/payments/csv` → `MENU_CALLBACK` con el mismo acceso.
  - **`myapi_payments_admin_roles()`** → `administrator`, `backend`; **`myapi_payments_admin_access()`**, calcado de `myapi_claims_admin_access()`. Tiene función propia y no reutiliza `myapi_marketplace_admin_access()`: la pregunta es otra ("¿puede ver el back office financiero?"), aunque hoy la respuesta coincida.
- **`css/myapi.claims.css`** (modificar): `.myapi-payments-filters` se suma al selector de la fila de filtros, más el estilo de la fila de total y del botón *Descargar CSV*.
- **`myapi.info`** (modificar): un `files[]`.
- **`tests/unit/PaymentsAdminPageTest.php`** (nuevo): helpers puros (validación de filtros, rango por defecto, armado de fila, etiquetas, escape y formato de cada fila CSV), calcados de `ServiceRequestsAdminPageTest.php`.
- **`docs/payments-list.md`** (nuevo).
- **`docs/produccion-checklist.md`** (modificar): enlace *Reporte de pagos* en el menú *Navegación*, debajo de *Calificaciones de servicio*.
- **`specs/backoffice/131-backoffice-listings.md`** (modificar): la decisión 7 queda marcada como *Revocada por la SPEC 132*, con enlace.

**Fuera de alcance:**

- **Editar desde el listado.** Es de solo lectura; el enlace de cada fila lleva al nodo (`/edit` o vista, según permiso), como en la SPEC 131.
- **Acceso del `administrador edificio`.** Abrirle el back office financiero sería una spec propia (ver `docs/building-admin-role.md`).
- **Retirar o modificar la View *Reporte Pagos*.** Sigue existiendo tal cual; convivir o retirarla es una decisión operativa posterior.
- **`field_comprobante_ocr` en pantalla o en el CSV.** Es texto largo de evidencia; se consulta abriendo el nodo.
- **`Banco emisor` / `Banco destino` en pantalla.** Van solo en el CSV.
- **Ordenar por columnas, acciones masivas, exportar a XLSX/PDF, totales por estado o por canal.**
- **Cualquier endpoint `api/v1/...`, cambio de schema o `hook_update_N()`.** `git diff main -- resources/ myapi.install` vacío.
- **AJAX y Views.** Filtros por GET y tabla a mano.
- **Crear el enlace del menú.** Es configuración del sitio; se documenta el procedimiento.

---

## Modelo de datos

Sin estructuras nuevas: ni tabla, ni campo, ni `hook_update_N()`. La sección define qué se lee y cómo.

### Consulta

Sobre `node` con `n.type = 'pagos'` y **sin filtrar `n.status`**: el ciclo de vida del pago vive en `field_estado_pago`, no en la publicación, y `GET /api/v1/units/%/payments` tampoco filtra por ella. Se incluyen **todos los estados**, también `Nuevo`.

Mismas reglas que la SPEC 131: `->addTag('node_access')`, todos los joins `LEFT`, `->extend('PagerDefault')->limit(20)`. Orden: `field_fecha_de_pago DESC`, `n.nid DESC`.

| Alias | Tabla | Aporta |
|---|---|---|
| `fv` / `nv` | `field_data_field_vivienda` → `node` (`vivienda`) | vivienda (título) |
| `fnv` | `field_data_field_nombre_vivienda` de la vivienda | nombre de la vivienda |
| `fco` / `nc` | `field_data_field_condominio` **de la vivienda** → `node` (`condominio`) | condominio |
| `fpo` / `uo` | `field_data_field_propietario` de la vivienda → `users` | propietario |
| `uon`, `uoa`, `uoc` | `field_nombre`, `field_apellidos`, `field_cedula` del propietario | nombre y cédula |
| `foc` / `uc` | `field_data_field_ocupante` de la vivienda → `users` | ocupante |
| `ucn`, `uca`, `ucc` | `field_nombre`, `field_apellidos`, `field_cedula` del ocupante | nombre y cédula |
| `ffp` | `field_fecha_de_pago` | fecha de pago |
| `ffo`, `fba` / `tb` | `field_forma_de_pago`, `field_banco` → `taxonomy_term_data` | forma y banco |
| `fre`, `fva`, `fes`, `fca` | `field_referencia`, `field_valor`, `field_estado_pago`, `field_canal` | resto de columnas |
| `fbe`, `fbd` | `field_banco_emisor`, `field_banco_destino` | **solo en el CSV** |

Una sola función, `myapi_payments_admin_build_query($filters)`, arma la consulta con los filtros. La usan la tabla (con paginador), el total (`SUM`, sin paginador) y el CSV (por lotes de 500, sin paginador).

### Columnas en pantalla

| # | Columna | Origen | Cuando falta |
|---|---|---|---|
| 1 | ID | `nid`, enlazado (`/edit` o vista, según permiso) | — |
| 2 | Vivienda | `field_nombre_vivienda`, si no el título; enlazada a la vivienda | `—` / `Vivienda eliminada (#id)` |
| 3 | Condominio | título del condominio | `—` |
| 4 | Propietario | `nombre apellidos`, si no `name` | `—` / `Usuario eliminado (#uid)` |
| 5 | Cédula propietario | `field_cedula` real (no el usuario ni el correo, como hace la View) | `—` |
| 6 | Ocupante | ídem | ídem |
| 7 | Cédula ocupante | ídem | `—` |
| 8 | Fecha de pago | `d/m/Y` | `—` |
| 9 | Forma de pago | etiqueta de `myapi_payment_method_label()` | `—` |
| 10 | Banco | nombre del término de `bancos` | `—` |
| 11 | Referencia | `check_plain()` | `—` |
| 12 | Estado | etiqueta de `allowed_values` de `field_estado_pago` | `—` (fuera de catálogo: valor crudo) |
| 13 | Valor | `$1.234,56` | `—` |
| 14 | Canal | etiqueta de `myapi_payment_channel_label()` (`App` / `Bot de WhatsApp` / `Back office`) | `—` |
| 15 | Creado | `n.created`, `d/m/Y H:i` | — |

**Fila de total** al pie: `Total: $X` es la suma de `field_valor` de **todas las filas filtradas**, no solo de la página, junto con el número de pagos. Con el filtro de estado vacío la suma mezcla `Anulado` con el resto; el doc lo advierte.

### Filtros GET

| Parámetro | Control | Validación | Aplica sobre |
|---|---|---|---|
| `condominium` | select | entero positivo entre `myapi_calendar_condominium_options()` | condominio de la vivienda |
| `status` | select | clave de `allowed_values` de `field_estado_pago` | `fes` |
| `unit_name` | texto | `trim`, 1–100 caracteres, `db_like()` | `nv.title LIKE %x%` **OR** `fnv LIKE %x%` |
| `owner_last_name` | texto | ídem | `uoa.field_apellidos_value LIKE %x%` |
| `occupant_last_name` | texto | ídem | `uca.field_apellidos_value LIKE %x%` |
| `payment_method` | select | clave de `allowed_values` de `field_forma_de_pago` | `ffo` |
| `channel` | select | `bot`, `app` o `backoffice` (constantes `MYAPI_PAYMENT_CHANNEL_*`) | `fca` |
| `date_from` / `date_to` | fecha | `Y-m-d` válida (`myapi_reservation_valid_date()`); `00:00:00` / `23:59:59`; extremos independientes | `field_fecha_de_pago` |

Como en la SPEC 131, la basura en cualquier parámetro se ignora (ese filtro no se aplica, sin error); los filtros se combinan con AND y sobreviven al paginador.

**Rango por defecto.** Si la URL **no trae ni `date_from` ni `date_to`**, se aplican los últimos 30 días (`hoy − 30` → `hoy`) y el formulario los muestra rellenados. Si los trae **vacíos** (el usuario los borró y aplicó), no hay límite de fecha. El cálculo lo hace una función pura que recibe `REQUEST_TIME`, para poder testearla.

### CSV — `admin/content/payments/csv`

- El botón *Descargar CSV* enlaza a esa ruta con **los filtros efectivos**: si se está aplicando el rango por defecto, lo manda explícito, para que el CSV coincida exactamente con la tabla.
- Cabeceras: `Content-Type: text/csv; charset=utf-8` y `Content-Disposition: attachment; filename="pagos-YYYYmmdd-HHii.csv"`. Se escribe con `fputcsv()` sobre `php://output` y termina con `drupal_exit()`. No pasa por `myapi_respond()`, porque no es la API JSON.
- UTF-8 con BOM, separador `,`, primera fila de encabezados.
- Columnas: las 15 de la pantalla más **Banco emisor** y **Banco destino** (en total 17).
- Valores crudos: el valor como `186.40` (sin `$`), la fecha de pago como `Y-m-d`, la de creación como `Y-m-d H:i`; estado, forma de pago y canal como etiqueta. Un dato que falta va como celda vacía, no como `—`.
- **Contra inyección de fórmulas:** si una celda empieza por `=`, `+`, `-`, `@`, tab o retorno de carro, se le antepone `'`. Es una función pura con test.
- Se lee por lotes de 500 con `->range()` sobre la misma consulta ordenada, sin límite de filas.

---

## Menú *Navegación* (configuración del sitio)

El enlace se añade a mano en `admin/structure/menu/manage/navigation`, **debajo de *Calificaciones de servicio***:

| Título | Ruta |
|---|---|
| Reporte de pagos | `admin/content/payments` |

Drupal oculta el enlace a quien no tiene acceso a la ruta. Se documenta además el equivalente con `drush php-eval` + `menu_link_save()`, como en `docs/building-admin-role.md`.

---

## Plan de implementación

Todo en un solo commit de feature (`feat(backoffice): add payments report page (SPEC 132)`), más uno de docs para el checklist y la nota en la SPEC 131. Dentro del trabajo, el orden es: **test en rojo → helpers puros → consulta → página → CSV → ruta, `.info` y CSS → doc**.

0. **Comprobar los supuestos del esquema** en el sitio real con `drush php-eval` y `field_info_field()`:
   - `field_propietario` y `field_ocupante` tienen cardinalidad 1;
   - `field_nombre`, `field_apellidos` y `field_cedula` existen en `user`;
   - `field_estado_pago` y `field_forma_de_pago` son `list_text` con `allowed_values`;
   - `field_fecha_de_pago` tiene el tipo de fecha que ya compara `myapi_payment_count()`.

   Si alguno no se cumple, **se para y se actualiza la spec** antes de escribir código (ver Riesgo 1).
   *Verificación: salida de los cuatro `php-eval` pegada en el PR.*
1. **Acceso**: `myapi_payments_admin_roles()` / `_access()` en `myapi.module`.
   *Verificación: `php -l`.*
2. **`PaymentsAdminPageTest` en rojo**, que cubre:
   - validación de cada filtro, incluidos basura, arrays, `?date_from=2026-02-30` y textos de 101 caracteres;
   - rango por defecto (parámetros ausentes → 30 días; presentes y vacíos → sin límite), con `REQUEST_TIME` fijo;
   - etiquetas de persona y cédula, incluido el usuario eliminado;
   - formato del valor y del total;
   - armado de una fila de pantalla y de una fila CSV;
   - escape anti-fórmula (`=`, `+`, `-`, `@`, tab, CR).
3. **`includes/myapi.payments_admin.inc`, helpers puros** hasta que el test pase.
   *Verificación: `vendor/bin/phpunit --filter PaymentsAdminPageTest` en verde.*
4. **Consulta**: `myapi_payments_admin_build_query($filters)`, `_fetch_page()` y `_total()`.
5. **Página y formulario** (`#method = 'get'`, `#token = FALSE`, `#after_build` del calendario, `hidden q`), tabla con `#empty`, fila de total y botón *Descargar CSV* con los filtros efectivos.
6. **CSV**: callback de `admin/content/payments/csv`, que reutiliza `build_query()` y los helpers de fila y escape, por lotes de 500.
7. **Ruta, `files[]` y CSS**: las dos entradas en `hook_menu()`, una línea en `myapi.info` y los selectores en `css/myapi.claims.css`.
   *Verificación: `drush cc all`; `vendor/bin/phpunit` en verde con el gate de cobertura; `phpstan` sin errores nuevos; `ModuleContractTest` en verde.*
8. **`docs/payments-list.md`**: columnas, filtros, rango por defecto, total (con el aviso sobre `Anulado`), formato del CSV y riesgos.
9. **Docs de cierre**:
   - `docs/produccion-checklist.md`: enlace *Reporte de pagos* en *Navegación*, con el equivalente `menu_link_save()`;
   - `specs/backoffice/131-backoffice-listings.md`: decisión 7 marcada como *Revocada por la SPEC 132*.
10. **Matriz manual en un sitio real**:
    - acceso de `administrator`, `backend`, `administrador edificio` y un autenticado sin roles;
    - cada filtro por separado y combinados;
    - un pago de cada canal;
    - que el total de la página coincida con la suma de la columna Valor del CSV;
    - abrir el CSV en Excel y comprobar tildes, `ñ` y una referencia que empiece por `=`.
11. **Cierre**: `drush cc all`. **No hace falta `drush updb`**: no hay schema, campo ni permiso nuevo.

---

## Criterios de aceptación

**Rutas y acceso**

- [ ] `admin/content/payments` y `admin/content/payments/csv` responden 200 a `administrator` y `backend`, y 403 a `administrador edificio` y a un autenticado sin roles.
- [ ] `uid 1` entra a las dos.
- [ ] Una vez configurado el menú, un `administrador edificio` no ve *Reporte de pagos* en el sidebar.

**Listado**

- [ ] Aparecen pagos de los cuatro estados, incluido `Nuevo`.
- [ ] Orden: la fecha de pago más reciente arriba; si dos coinciden, el `nid` mayor primero.
- [ ] La columna Cédula muestra `field_cedula`, nunca el usuario ni el correo.
- [ ] Un pago cuya vivienda fue borrada aparece con `Vivienda eliminada (#id)` y `—` en condominio, propietario y ocupante: no desaparece.
- [ ] La columna Canal muestra `App`, `Bot de WhatsApp` o `Back office` según `field_canal`.

**Filtros**

- [ ] `?channel=bot` muestra solo pagos del bot; `?channel=app`, solo de la app; `?channel=backoffice`, incluye los históricos migrados por la SPEC 129.
- [ ] `?unit_name=` encuentra la vivienda tanto por su título como por `field_nombre_vivienda`, sin distinguir mayúsculas y por coincidencia parcial.
- [ ] `?owner_last_name=` y `?occupant_last_name=` filtran por `field_apellidos` del usuario correspondiente.
- [ ] `?condominium=` filtra por el condominio **de la vivienda**.
- [ ] El rango de fechas filtra `field_fecha_de_pago`, no la fecha de creación.
- [ ] Entrar sin parámetros de fecha aplica los últimos 30 días y los muestra en el formulario; enviar ambos vacíos quita el límite.
- [ ] Los parámetros basura (`?status=xyz`, `?condominium=-1`, `?date_from=2026-02-30`, `?channel[]=bot`, un texto de 101 caracteres) se ignoran sin error.
- [ ] Los filtros se mantienen al cambiar de página.

**Total**

- [ ] La fila de total suma `field_valor` de **todas** las filas filtradas (no solo de la página visible) y muestra el número de pagos.
- [ ] El total cambia al aplicar un filtro de estado.

**CSV**

- [ ] *Descargar CSV* baja un archivo `pagos-YYYYmmdd-HHii.csv` con las mismas filas que la tabla, sin paginar, incluido el rango por defecto cuando se está aplicando.
- [ ] El CSV tiene 17 columnas: las 15 de la pantalla más Banco emisor y Banco destino.
- [ ] Abierto en Excel, las tildes y la `ñ` se ven bien (BOM UTF-8).
- [ ] Una referencia `=1+1` aparece en el CSV como `'=1+1`.
- [ ] La suma de la columna Valor del CSV es igual al total de la página.
- [ ] Un filtro de 1.000 o más pagos se exporta completo, sin agotar memoria ni tiempo (lotes de 500).

**Comunes**

- [ ] `git diff main -- resources/ myapi.install includes/*_query.inc` está vacío.
- [ ] `vendor/bin/phpunit` pasa en verde con el gate de cobertura; `phpstan` no da errores nuevos; no hay sintaxis de PHP 8 (`ModuleContractTest`).
- [ ] `docs/payments-list.md` existe y describe lo implementado.
- [ ] La decisión 7 de la SPEC 131 está marcada como revocada.

---

## Decisiones tomadas y descartadas

**1. Página del módulo, no un filtro más en la View *Reporte Pagos*.** Lo decidió el usuario. Añadir `field_canal` como filtro expuesto a la View costaba minutos, pero no quedaba versionado, no corregía la columna de cédula y no daba un CSV coherente con la tabla. Se sigue el molde de la SPEC 131.

**2. Revoca la decisión 7 de la SPEC 131.** Esa decisión decía que no hacía falta listado de pagos porque existía la View. Cambiaron dos cosas: la SPEC 129/130 trajo un canal que la View no filtra, y hace falta una exportación. La View sigue existiendo; retirarla queda fuera de esta spec.

**3. Solo `administrator` y `backend`.** El `administrador edificio` queda fuera aunque el tag `node_access` ya acotaría los pagos por condominio (`via_unit`): `docs/building-admin-role.md` pide expresamente no darle el back office financiero, y cambiar eso es una decisión propia. La función de acceso es propia (`myapi_payments_admin_access()`) y no reutiliza la del marketplace, para que las dos puedan divergir.

**4. Sin filtrar `n.status` e incluyendo `Nuevo`.** El estado del pago vive en `field_estado_pago`. Un informe de back office que oculte pagos esconde justo lo que el operador busca. La API oculta `Nuevo` porque se dirige al residente; este informe no.

**5. Filtro de canal con los tres valores.** Ofrecer solo `App` / `Bot` dejaría sin forma de aislar los históricos `backoffice` de la SPEC 129.

**6. Rango por defecto de 30 días; ausente ≠ vacío.** Replica la View y evita que la primera carga o un CSV sin filtros recorran toda la tabla. Si los parámetros están ausentes se aplica el defecto; si llegan vacíos, se entiende que el usuario quitó el límite a propósito.

**7. Nombre de vivienda en título y en `field_nombre_vivienda`.** El código ya los distingue y el operador no sabe cuál está viendo; buscar en ambos evita falsos "sin resultados".

**8. Cédula real en lugar de lo que muestra la View.** La View pone el usuario o el correo bajo "Cédula". Se lee `field_cedula`.

**9. Banco emisor y destino solo en el CSV.** La tabla ya tiene 15 columnas. Los bancos del OCR sirven para conciliar, y eso se hace en Excel.

**10. `field_comprobante_ocr` fuera de todo.** Es texto largo de evidencia; se lee en el nodo.

**11. El total suma todas las filas filtradas, `Anulado` incluido si no se filtra por estado.** Lo decidió el usuario: el total refleja el filtro, sin reglas ocultas. El doc lo advierte.

**12. CSV con la misma consulta que la tabla.** Una sola `build_query()`. Dos consultas paralelas acabarían dando conjuntos distintos y nadie se fiaría del CSV. El botón manda los filtros efectivos por la misma razón.

**13. CSV: UTF-8 con BOM, coma, valores crudos, sin límite de filas, por lotes de 500.** El BOM hace que Excel muestre bien las tildes; los valores crudos permiten sumar en la hoja. Se descartó el `;` (separador de Excel en locales con coma decimal) porque el sitio usa punto decimal. Se descartó XLSX porque añadiría una librería a un Drupal 7 en EOL.

**14. Escape anti-fórmula en el CSV.** Referencias y nombres vienen de la app y del bot, es decir, del usuario. Sin el escape, una referencia `=HYPERLINK(...)` se ejecutaría en el Excel del operador.

**15. Descartado: `GROUP_CONCAT` o subconsultas para propietario y ocupante.** Se unen directamente porque son de cardinalidad 1, y el paso 0 del plan lo comprueba.

---

## Riesgos identificados

**1. Cardinalidad de `field_propietario` / `field_ocupante`.** Si alguno es multivalor, los joins directos duplican pagos, el paginador cuenta mal y **el total se infla**. Mitigación: el paso 0 del plan lo comprueba antes de escribir código. Si resulta multivalor, se pasa al patrón de la SPEC 131 para proveedores (los nombres en una segunda consulta acotada a los nids de la página; el filtro por apellido con `n.nid IN (subconsulta)`) y se actualiza la spec.

**2. Número de joins.** Son unos 18 `LEFT JOIN`, más un `OR LIKE` con comodín inicial que no usa índice. Con el rango de 30 días por defecto el conjunto es acotado. Un CSV sin límite de fechas sobre toda la historia puede tardar: se mide en la matriz manual y, si pasa del `max_execution_time`, se añade `drupal_set_time_limit()` en el callback del CSV.

**3. `backend` + `proveedor` en la misma cuenta.** `pagos` no está entre los bundles que recorta el alter del proveedor, así que no debería afectar. Se verifica en la matriz manual con esa combinación, por el precedente del Riesgo 1 de las SPEC 125/131.

**4. Datos personales en el CSV.** El archivo lleva nombres y cédulas de residentes y sale del sistema sin control. Mitigación: acceso limitado a `administrator`/`backend` y un aviso en el doc. Auditar las descargas (`watchdog`) queda como mejora posible, fuera de alcance.

**5. Pagos históricos `backoffice` que en realidad vinieron de la app.** La SPEC 129 marcó todo lo anterior como `backoffice` porque no había forma fiable de distinguirlos. Filtrar `?channel=app` solo ve los pagos posteriores a esa spec. Se documenta y no se corrige.

**6. Gate de cobertura.** Página, formulario, consulta y callback CSV no se pueden ejercitar sin Drupal. Mitigación: la lógica va a funciones puras (filtros, rango, etiquetas, filas, escape), que es donde están los tests.
