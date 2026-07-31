# SPEC 53 — Los settings de selección de `entityreference` van en el campo, no en la instancia

> **Estado:** Implemented — código y unit tests en verde; el `drush updb` + `drush cc all` y la comprobación de los cinco autocompletados quedan pendientes de la verificación manual en el sitio · **Depende de:** SPEC 32 (`field_condominium`, `field_unit`, `field_area`, `field_requester` y sus instancias), SPEC 49 (`field_condominio_admin` sobre la entidad usuario) · **Fecha:** 2026-07-31
> **Objetivo:** Que los cinco autocompletados `entityreference` del módulo ofrezcan **solo** el bundle que les corresponde, moviendo `handler` y `handler_settings.target_bundles` del *instance* al *field* — que es donde `entityreference` los lee — y reparando con un update hook los sitios ya instalados, sin tocar un solo dato guardado.

---

## El problema

Al escribir en `field_condominium` del formulario de `area`, el autocompletado devuelve **cualquier nodo del sitio**: viviendas, boletines, recibos. Lo mismo ocurre en `field_unit` y `field_area` del formulario de `reservation`, y en el autocompletado de etiquetas `field_condominio_admin` de `/user/N/edit`.

No es un problema de datos ni de permisos ni de los filtros de SPEC 49 / 51. Es **dónde** están escritos los settings.

En Drupal 7 `entityreference`, los tres settings que deciden qué se puede referenciar — `target_type`, `handler` y `handler_settings.target_bundles` — son **de nivel campo**. El handler genérico los lee del campo y solo del campo:

```php
// EntityReference_SelectionHandler_Generic::buildEntityFieldQuery()
if (!empty($this->field['settings']['handler_settings']['target_bundles'])) {
  $query->entityCondition('bundle', $this->field['settings']['handler_settings']['target_bundles'], 'IN');
}
```

Lo que engaña es la interfaz: `entityreference` los expone a través de `hook_field_settings_form()`, y Drupal dibuja ese formulario **dentro de la pantalla de la instancia**, aunque lo guarde en el campo. De ahí que se escribieran donde parecía que se leían.

`myapi.install` los ponía en la instancia:

```php
_myapi_reservations_ensure_field('field_condominium', [
  'type'     => 'entityreference',
  'settings' => ['target_type' => 'node'],          // ← sin handler_settings
]);
_myapi_reservations_ensure_instance('field_condominium', 'area', [
  'settings' => [
    'handler'          => 'base',
    'handler_settings' => ['target_bundles' => ['condominio' => 'condominio']],  // ← nunca se lee
  ],
  ...
]);
```

Resultado: el `entityCondition('bundle', ...)` no se añade nunca y la consulta devuelve todos los nodos cuyo título haga match. Los cinco campos tienen el mismo patrón, así que **fallan los cinco**.

El comentario de `_myapi_reservations_install()` afirmaba lo contrario ("solo `target_type` es de nivel campo; el handler vive en la instancia"), y ese comentario es el origen del error: se copió a `_myapi_building_admin_install()` cuando SPEC 49 añadió el quinto campo, y a `docs/reservations-install.md`. SPEC 32 sí lo tenía bien escrito — su tabla "Settings de campo" incluye `handler=base` y `handler_settings.target_bundles=['condominio']` —, así que la implementación se desvió del spec y ningún test lo notó.

### Por qué importa más allá de lo cosmético

- **`field_condominio_admin`.** Es la clave del rol `administrador edificio`: el mapa de condominios de SPEC 49 se lee directamente de ese campo. Asignar por error un nodo que no es un condominio deja al rol con un ámbito imposible de resolver.
- **`field_unit` / `field_area`.** El validador de `myapi_building_admin_validate_reservation()` y `myapi_reservation_create()` comprueban la coherencia entre área, vivienda y condominio, pero el formulario de nodo permite elegir un objetivo del bundle equivocado antes de llegar ahí.
- **Ruido puro** en el mejor de los casos: un desplegable con cientos de nodos irrelevantes.

---

## Alcance

**Dentro:**

- **`myapi.install`** (modificar):
  - `_myapi_entityreference_field_settings()` (nuevo) — catálogo único: nombre de campo → array `settings` de nivel campo, con `target_type`, `handler` y `handler_settings`. Lo leen los dos instaladores y el update hook.
  - `_myapi_entityreference_repair_settings()` (nuevo) — función **pura** que decide qué escribir sobre un campo ya existente: rellena lo que falta, nunca pisa lo que hay.
  - `_myapi_reservations_install()` y `_myapi_building_admin_install()` — los cinco `_myapi_reservations_ensure_field()` de tipo `entityreference` toman su `settings` del catálogo; las cinco instancias **pierden** su clave `settings`.
  - `myapi_update_7016()` (nuevo) — repara los campos de los sitios ya instalados con `field_update_field()`.
  - Los comentarios que afirmaban la ubicación equivocada, corregidos.

- **`tests/unit/EntityReferenceFieldSettingsTest.php`** (nuevo) — el catálogo, la función de reparación y **dos guardas** que leen `myapi.install` como texto: una falla si se crea un campo `entityreference` fuera del catálogo, otra si `handler_settings` reaparece en una instancia.

- **`docs/reservations-install.md`** y **`docs/building-admin-role.md`** (modificar) — la ubicación correcta, el update hook y cómo comprobarlo en el sitio.

**Fuera:**

- **Los endpoints `api/v1/...`.** Ninguno pasa por el handler de selección: leen y escriben `target_id` con consultas propias. El contrato JSON no cambia en ningún campo.
- **Los datos.** No se lee ni se escribe una sola fila de `field_data_*`. Un `target_id` guardado apuntando a un bundle equivocado — si alguien lo eligió mientras el autocompletado estaba roto — **no se corrige aquí**; ver "Lo que NO está en este spec".
- **Los `settings` muertos que quedan en las instancias antiguas.** `entityreference` no lee los settings de instancia, así que no molestan; limpiarlos serían cinco escrituras sin efecto observable.
- **`target_type`.** Siempre estuvo bien escrito, a nivel campo. El update **no lo toca**: cambiarlo dejaría huérfano cada `target_id` ya guardado.
- **Los filtros de SPEC 49 / 51.** Siguen siendo lo que estrecha los autocompletados a los condominios asignados; este spec estrecha *el bundle*, que es una capa distinta y anterior.

---

## Modelo de datos

### El catálogo

| Campo | Bundles | `target_type` | `handler` | `target_bundles` |
|---|---|---|---|---|
| `field_condominium` | `area`, `reservation` | `node` | `base` | `condominio` |
| `field_condominio_admin` | `user` | `node` | `base` | `condominio` |
| `field_unit` | `reservation` | `node` | `base` | `vivienda` |
| `field_area` | `reservation` | `node` | `base` | `area` |
| `field_requester` | `reservation` | `user` | `base` | — |

`sort` queda fijado a `['type' => 'none']` en los cinco: `entityreference` ordena entonces por la etiqueta de la entidad — el título del nodo o el nombre de usuario —, que es el orden que debe ver quien escribe.

**`field_requester` no lleva `target_bundles` y eso es correcto**, no un olvido: la entidad usuario tiene un único bundle. Lo que estrecha ese autocompletado a los residentes de los condominios asignados es el query alter de `user_access` de SPEC 51.

### Una consecuencia del nivel campo

Un campo **compartido** lleva **un solo** setting de selección para todos sus bundles. `field_condominium` está en `area` y en `reservation`, y los dos quieren `condominio`, así que aquí no se pierde nada. Pero un campo futuro que necesitara bundles distintos por bundle tendría que partirse en dos campos — exactamente lo que ya hacen `field_area_status` y `field_reservation_status` con sus `allowed_values`, por la misma razón y documentado en el mismo sitio.

### La regla de reparación

`_myapi_entityreference_repair_settings($current, $wanted)` devuelve los settings a escribir, o `NULL` cuando no hay nada que escribir:

| Estado actual del campo | Qué hace |
|---|---|
| Sin `handler` ni `handler_settings` (el caso de producción) | Escribe los dos del catálogo |
| `target_bundles` vacío o ausente | Lo rellena — `entityreference` trata el vacío igual que el ausente (`!empty()`) |
| `target_bundles` puesto a mano, distinto del catálogo | **Lo respeta.** Solo completa las claves que falten (`sort`) |
| `handler` cambiado a `views` a mano | **Lo respeta.** El campo sigue restringido, por la vista |
| Settings de otros módulos | Sobreviven; no se descarta ninguna clave desconocida |
| Todo ya en su sitio | Devuelve `NULL`: el update no escribe |

Es la misma línea conservadora que `_myapi_building_admin_install()` sostiene con los permisos desde SPEC 49: **rellenar lo que falta, no imponer**.

---

## Plan de implementación

1. `_myapi_entityreference_field_settings()` y `_myapi_entityreference_repair_settings()` en `myapi.install`, junto a los `_ensure_*`.
2. Los cinco `_ensure_field()` pasan a leer del catálogo; las cinco instancias pierden su `settings`.
3. `myapi_update_7016()`: recorre el catálogo, salta el campo que no exista, **reporta y salta** el campo cuyo `target_type` no coincida, y escribe con `field_update_field()` lo que devuelva la función de reparación. Cierra con `field_info_cache_clear()` y devuelve un resumen para `drush`.
4. `tests/unit/EntityReferenceFieldSettingsTest.php`.
5. Corregir los comentarios y los dos documentos.

El número es **7016** y no 7014 ni 7015: los dos están tomados (SPEC 51 y SPEC 49), y reutilizar uno dejaría el otro sin correr en cada sitio que ya haya pasado por él.

---

## Criterios de aceptación

1. `_myapi_entityreference_field_settings()` devuelve exactamente cinco entradas, con los `target_bundles` de la tabla de arriba.
2. Los cinco `_ensure_field()` de tipo `entityreference` de `myapi.install` toman su `settings` del catálogo. Ninguna instancia del archivo contiene `handler` ni `handler_settings`.
3. `_myapi_entityreference_repair_settings()` rellena lo ausente, respeta lo puesto a mano, conserva los settings desconocidos y devuelve `NULL` cuando no hay cambio.
4. `myapi_update_7016()` es idempotente: la segunda pasada no escribe nada y lo dice en su resumen.
5. `myapi_update_7016()` no lee ni escribe ninguna tabla `field_data_*` ni `field_revision_*`.
6. Un sitio sin alguno de los cinco campos corre el update sin error y lo nombra en el resumen.
7. Ningún endpoint `api/v1/...` cambia de respuesta.

## Verificación manual

En el sitio, tras `drush updb && drush cc all`:

```bash
drush php-eval "print_r(field_info_field('field_condominium')['settings']);"
```

`handler_settings.target_bundles` debe mostrar `condominio`. Repetir con `field_unit` (`vivienda`), `field_area` (`area`), `field_condominio_admin` (`condominio`) y `field_requester` (sin `target_bundles`, `target_type = user`).

Después, en el formulario:

1. `node/add/area` → escribir en **Condominio** dos letras que existan en el título de una vivienda y de un condominio: solo debe ofrecerse el condominio.
2. `node/add/reservation` → **Vivienda** ofrece solo `vivienda`, **Área** solo `area`, **Solicitante** solo usuarios.
3. `/user/N/edit` → **Condominios administrados** ofrece solo condominios.
4. Con un usuario del rol `administrador edificio`: los mismos cuatro autocompletados siguen ofreciendo **únicamente los condominios asignados** — el filtro de SPEC 49 no se ve afectado por este cambio.

## Decisiones

- **Un catálogo y no cinco definiciones sueltas.** El error se reintrodujo una vez ya, al añadir el quinto campo copiando el patrón del cuarto. Con una sola lista, un campo nuevo o entra en ella o falla la guarda del test.
- **Update hook además de arreglar el instalador.** `_myapi_reservations_ensure_field()` salta cualquier campo que exista, a propósito, así que ningún `drush updb` habría llegado nunca a los campos de un sitio instalado. Es el único sitio del módulo que escribe sobre una definición de campo existente, y se dice en su docblock.
- **Rellenar, no imponer.** Coherente con el resto del instalador. El precio es que un `target_bundles` puesto a mano y equivocado sobrevive al update; a cambio, uno puesto a mano y deliberado también.
- **`target_type` se reporta, no se repara.** Es el único setting cuyo cambio movería datos de sitio.
- **Guardas que leen el archivo como texto.** Lo que hay que comprobar es *dónde* se escriben los settings, y eso no lo expone ningún valor de retorno. Es un test feo a propósito: el bug era invisible en revisión porque los dos arrays parecen correctos.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un `target_bundles` puesto a mano en producción por una razón que nadie recuerda | El update no lo pisa; solo completa lo que falte |
| El `field_info` cache queda frío y los formularios siguen mostrando el comportamiento viejo | `field_update_field()` limpia el cache en cada escritura y el update cierra con `field_info_cache_clear()`; aun así el despliegue documentado incluye `drush cc all` |
| Alguien añade un sexto campo `entityreference` con los settings en la instancia | La guarda `testNoInstanceCarriesSelectionSettings()` falla; la de `testEveryEntityreferenceFieldComesFromTheCatalogue()` falla si además no entra en el catálogo |
| Nodos ya guardados apuntando a un bundle equivocado | Fuera de alcance, ver abajo. El update no puede distinguir un error de una asignación deliberada |

## Lo que **NO** está en este spec

- **Auditar y corregir los `target_id` ya guardados.** Si mientras el autocompletado estaba roto alguien eligió una vivienda como "condominio" de un área, ese nodo sigue guardado así. Detectarlo es una consulta de lectura por campo (join contra `node.type`), y decidir qué hacer con cada fila es una decisión del cliente, no de un update hook. Si aparecen filas, se abre spec propio.
- **Validación en `hook_node_validate()` del bundle del objetivo.** Sería el cinturón sobre los tirantes del autocompletado ya arreglado; hoy no hay caso de uso que lo pida.
- **Limpiar los `settings` muertos de las instancias antiguas.**
- **Tocar `handler` para usar vistas** en ninguno de los cinco campos.
