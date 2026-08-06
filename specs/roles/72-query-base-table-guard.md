# 72 — Los filtros del rol «administrador edificio» solo actúan sobre la tabla **base** de la consulta

- **Estado:** Implemented — código y unit tests en verde; la verificación manual en el sitio queda pendiente
- **Fecha:** 2026-08-06
- **Dependencias:**
  - `49-building-admin-role` (Implemented) — `myapi_building_admin_alter_node_query()`, la mitad de listados del filtro de contenido.
  - `51-building-admin-people-scope` (Implemented) — `myapi_building_admin_alter_user_query()`, la mitad de listados del filtro de personas. **Este spec repara un bug introducido por él.**
- **Objetivo:** Que cada uno de los dos *query alters* del rol se aplique únicamente cuando su tabla (`node` / `users`) es la tabla **base** de la consulta, y no cuando aparece como tabla unida por un `JOIN`. Con eso vuelve a funcionar `/admin/content` para el rol, sin aflojar ninguna de las dos mitades de acceso directo.

---

## El problema

Un usuario con el rol `administrador edificio`, con sus condominios correctamente asignados en `field_condominio_admin`, veía **«No hay contenido disponible»** en `/admin/content` para **todos** los tipos: ni sus condominios, ni sus viviendas, ni sus áreas, ni sus reservas.

Todo lo demás del rol funcionaba. La evidencia recogida durante el diagnóstico:

| Comprobación | Resultado |
|---|---|
| `field_condominio_admin` del usuario | 3 nodos `condominio`, publicados |
| `/node/<condominio asignado>` | **200** — `myapi_node_access()` lo ignora correctamente |
| Selector de condominio de `/node/add/boletin` | Ofrece **exactamente** los 3 asignados |
| `/admin/content` (cualquier tipo) | **0 filas** |
| El mismo listado como `administrator` | Completo |

El selector del boletín y el listado se reescriben con la **misma** función y la **misma** etiqueta, así que el filtro de contenido no podía ser el culpable.

### La causa

La vista que sirve `/admin/content` en este sitio (la del módulo *Administration Views*) tiene una **relación `Contenido: Autor`**, es decir, une la tabla `users` para poder mostrar y filtrar por el autor de cada nodo.

Views añade a la consulta la *access query tag* de la tabla base de **cada relación**, no solo la de la vista:

```php
// views_handler_relationship::query()
// Add access tags if the base table provide it.
if (empty($this->query->options['disable_sql_rewrite']) && isset($table_data['table']['base']['access query tag'])) {
  $this->query->add_tag($table_data['table']['base']['access query tag']);
}
```

Y `user.views.inc` declara `'access query tag' => 'user_access'` para la tabla `users`. Resultado: la consulta del listado de contenido —cuya tabla base es `node`— **arrastra las dos etiquetas**, `node_access` y `user_access`.

`myapi_query_user_access_alter()` disparaba entonces sobre una consulta de contenido. Su guarda 2 buscaba *cualquier* tabla llamada `users`:

```php
foreach ($query->getTables() as $table) {
  if (... && $table['table'] === 'users') {
    $users_alias = $table['alias'];   // ← el alias de la RELACIÓN, users_node
    break;
  }
}
$query->condition($users_alias . '.uid', myapi_building_admin_visible_uids(), 'IN');
```

En un listado de personas, `users.uid` es *la persona listada*. Aquí es **el autor del nodo**. La consulta pasaba a significar:

> contenido **cuyo autor** sea residente de mis condominios (o yo mismo)

Los condominios, viviendas, áreas y reservas del sitio los crea un operador que no es propietario ni ocupante de ninguna vivienda, así que nunca está en `myapi_building_admin_visible_uids()`. De ahí las **cero filas en todos los tipos**: la condición no discrimina por tipo, discrimina por autor.

### Por qué pasó desapercibido

- El docblock de la guarda advertía del riesgo **inverso** —«la etiqueta no implica la tabla», pensado en consultas sin columna `uid` que fallarían con error SQL— pero no de este: una consulta que **sí** tiene la tabla, unida por otro motivo y con otro significado.
- `views_plugin_query_default::execute()` envuelve la ejecución en `try/catch`; una vista que devuelve vacío no muestra ningún error en pantalla. El síntoma es indistinguible de «no hay contenido».
- El criterio de SPEC 51 —«ninguna consulta bajo `resources/` ni `includes/` lleva la etiqueta `user_access`, así que ningún endpoint cambia»— era y sigue siendo cierto. Lo que no se revisó es qué consultas **de terceros** la llevan.

---

## Alcance

### Entra en este spec

1. Un helper puro compartido, `myapi_building_admin_query_base_table_alias(array $tables, $table_name)`, en `includes/myapi.building_admin.inc`, que devuelve el alias de la tabla **base** de la consulta cuando es la pedida y `NULL` en cualquier otro caso.
2. La guarda 2 de `myapi_building_admin_alter_user_query()` pasa a usarlo. **Esto es el arreglo.**
3. La guarda 2 de `myapi_building_admin_alter_node_query()` pasa a usarlo también, por simetría: el mismo bug existía en espejo, latente.
4. Unit tests del helper, incluido el caso exacto que rompió el listado.
5. Documentación en `docs/building-admin-role.md`.

### NO entra en este spec

- **Ningún cambio en la vista de `/admin/content`.** No hay que quitar la relación de autor ni tocar «Disable SQL rewriting» — esta última nunca, porque apagaría también el filtro de condominio y abriría el contenido de todo el sitio al rol.
- **Ningún cambio en las dos mitades de acceso directo:** `myapi_node_access()` y `myapi_building_admin_user_view_access()` quedan intactas. Un nodo o un perfil ajeno sigue respondiendo 403.
- **Ningún cambio en `api/v1/...`.** Ninguna consulta del módulo lleva `node_access` salvo `myapi_claims_list_rows()`, cuya tabla base es `node` (`db_select('node', 'n')`) y que por tanto se sigue reescribiendo igual.
- **Ningún update hook.** No hay datos ni configuración que migrar: es lógica pura en un `.inc`.

---

## La regla

> Una *access query tag* dice **de qué trata** la consulta, no qué tablas contiene. Solo es cierta para la tabla **base**.

En Drupal 7 la tabla base es la única entrada de `$query->getTables()` sin `join type`: `SelectQuery::__construct()` la registra con `addJoin(NULL, $table, $alias)` antes de que pueda existir ningún `JOIN`, y PHP conserva ese orden de inserción.

```php
function myapi_building_admin_query_base_table_alias(array $tables, $table_name) {
  foreach ($tables as $table) {
    if (!is_array($table) || !empty($table['join type'])) {
      continue;
    }

    return (isset($table['table']) && is_string($table['table']) && $table['table'] === $table_name && isset($table['alias']))
      ? $table['alias']
      : NULL;
  }

  return NULL;
}
```

Fail closed en los dos bordes: una tabla base que es un subquery (objeto, no cadena) devuelve `NULL`, y `NULL` significa **no tocar la consulta**, nunca «filtrar a ciegas».

### Los dos consumidores legítimos siguen intactos

En ambos, `users` **es** la tabla base:

- El autocompletado `field_requester` del formulario de reserva. `EntityReference_SelectionHandler_Generic` construye un `EntityFieldQuery`, y `EntityFieldQuery::propertyQuery()` hace `db_select('users')` y le añade la etiqueta `user_access`.
- Cualquier vista de personas, `/admin/people` incluida: tabla base `users`.

Lo mismo del lado del contenido: el selector `entityreference` de condominios (`EntityFieldQuery` sobre `node`), la vista de `/admin/content` (base `node`) y `myapi_claims_list_rows()` (base `node`, alias `n`) se siguen reescribiendo exactamente igual.

### La consecuencia aceptada

Una consulta que trata de otra cosa y **une** la tabla deja de reescribirse. El caso concreto: una vista de personas con una relación al contenido que han creado mostraría títulos de nodos sin filtrar por condominio.

Se acepta a conciencia. El precio de la alternativa ya está medido: aplicar el filtro a una columna que significa otra cosa vació el back office entero y tardó una sesión de diagnóstico en localizarse, mientras que ninguna vista del sitio tiene hoy esa forma. Las dos mitades de acceso directo no se ven afectadas, y `myapi_building_admin_query_base_table_alias()` está en un solo sitio, así que endurecerlo más adelante es una edición de una función.

---

## Criterios de aceptación

1. Como `administrador edificio` con condominios asignados, `/admin/content` lista **sus** condominios, viviendas, áreas, reservas, boletines y reclamos, y **solo** los de sus condominios.
2. En ese mismo listado no aparece ningún `pagos`, `recibo` ni `gastos`, ni siquiera de sus condominios.
3. La columna *Autor* puede mostrar el nombre de alguien ajeno a sus condominios —quien creó el nodo—, pero al abrir ese perfil sigue respondiendo **403**.
4. `/admin/people` y el autocompletado *Solicitante* del formulario de reserva siguen ofreciendo **solo** las personas de sus condominios más él mismo.
5. El selector de condominio de los formularios de boletín, área y reserva sigue ofreciendo **solo** los condominios asignados.
6. `/node/<nodo de otro condominio>` sigue respondiendo **403**; `/user/<residente ajeno>` también.
7. Un `administrator` no percibe ningún cambio en ninguna pantalla.
8. Ningún endpoint `api/v1/...` cambia un byte.

## Verificación manual

```
drush cc all
```

No hace falta `updb`.

| Paso | Como | Esperado |
|---|---|---|
| 1 | `administrador edificio` | `/admin/content`, Tipo = `- Any -` → aparecen filas |
| 2 | `administrador edificio` | Tipo = `Condominio` → **exactamente** los asignados |
| 3 | `administrador edificio` | Tipo = `Vivienda` → solo las de esos condominios |
| 4 | `administrador edificio` | Clic en el autor de una fila ajena → **403** |
| 5 | `administrador edificio` | `/admin/people` → solo residentes de sus condominios y él mismo |
| 6 | `administrador edificio` | `/node/add/reservation`, campo *Solicitante* → mismo conjunto |
| 7 | `administrador edificio` | `/node/<nodo de otro condominio>` → **403** |
| 8 | `administrator` | `/admin/content` y `/admin/people` completos |

## Riesgos identificados

- **Una vista con «Disable SQL rewriting» marcado** deja fuera los dos filtros, este spec no cambia eso y sigue siendo el riesgo documentado en SPEC 49. Merece una revisión periódica de las vistas del back office.
- **Otras etiquetas de acceso viajando de más.** El mecanismo de `views_handler_relationship::query()` no es exclusivo de `users`: cualquier relación a una tabla base con *access query tag* añade la suya. La regla de este spec —solo la tabla base— es precisamente lo que hace que eso deje de importar.
- **Un `hook_query_TAG_alter()` futuro** que copie la forma antigua de la guarda reintroduce el bug. El unit test `testJoinedTableIsNeverTakenForTheBaseTable()` es el que debe fallar primero.
