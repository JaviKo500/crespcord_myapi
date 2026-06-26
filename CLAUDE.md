# CLAUDE.md — Módulo `myapi`

Instrucciones persistentes para trabajar en este repositorio. Léelas antes de
generar o modificar código. Son **reglas, no sugerencias**.

---

## 1. Qué es este proyecto

Módulo **personalizado** de Drupal 7 que expone una **API REST** para ser
consumida por una app Flutter. No se usa el módulo Services ni ningún otro
módulo contribuido de exposición de servicios: **todos los endpoints se
implementan a mano** dentro de este módulo.

El objetivo es una API con **arquitectura limpia, reutilizable y escalable**:
añadir un recurso nuevo debe costar crear un archivo y registrar sus rutas,
sin tocar la lógica existente.

---

## 2. Entorno y restricciones (no negociables)

- **Drupal 7.64.** Usa únicamente APIs de Drupal 7 (`hook_menu()`, `db_select()`,
  `db_insert()`, `drupal_json_encode()`, etc.). **Nunca** sugieras soluciones de
  Drupal 8/9/10/11 (rutas YAML, controladores Symfony, anotaciones, servicios DI).
- **PHP 7.4.33.** El código debe ser compatible con esta versión exacta. Puedes
  usar características de PHP 7.4 (arrow functions, typed properties, null
  coalescing assignment, etc.), pero **nada** de PHP 8.0+ (named arguments,
  `match`, constructor property promotion, enums, etc.) porque romperá.
- **Sin Services.** Prohibido depender de `services`, `rest_server` o similares.
- **Drupal 7 está en EOL (fin de vida, enero 2025).** No esperes parches de
  seguridad del core; extrema el cuidado en validación y sanitización.
- **HTTPS obligatorio** en producción: las credenciales y tokens viajan en las
  peticiones.

---

## 3. Principios de arquitectura (expresados como reglas verificables)

1. **`myapi.module` solo enruta.** Contiene `hook_menu()` y, como mucho, glue
   mínimo. **Nunca** lógica de negocio dentro del `.module`.
2. **Un recurso = un archivo** en `resources/<name>.resource.inc`. Toda la
   lógica de ese recurso (listar, leer, crear, actualizar, borrar) vive ahí.
3. **Helpers compartidos en `includes/`.** Respuestas, parsing de request y
   utilidades comunes se escriben **una sola vez** y se reutilizan. No se
   duplica código entre recursos.
4. **Toda respuesta sale por los helpers** `myapi_respond()` (éxito) o
   `myapi_error()` (error). Prohibido imprimir JSON crudo a mano en un recurso.
5. **Aislamiento entre recursos.** Un recurso no llama funciones internas de
   otro. Si comparten lógica, esa lógica sube a `includes/`.
6. **Versionado desde el día 1.** Todas las rutas cuelgan de `api/v1/...`.

---

## 4. Estructura de carpetas

```
myapi/
├── myapi.info                       Declaración del módulo + files[]
├── myapi.install                    hook_schema() y tareas de instalación
├── myapi.module                     hook_menu(): SOLO enrutado
│
├── includes/                        Utilidades compartidas (se escriben una vez)
│   ├── myapi.response.inc           myapi_respond() / myapi_error()
│   └── myapi.request.inc            leer body JSON, método HTTP, validaciones
│
├── resources/                       Un archivo por recurso (aquí se escala)
│   └── <name>.resource.inc          Lógica CRUD de cada recurso
│
└── docs/                            Documentación, un archivo por recurso
    └── <name>.md
```

Regla mental: **¿recurso nuevo? → archivo nuevo en `resources/` + su doc en
`docs/` + registrar rutas en `myapi.module`.** Nada más se toca.

---

## 5. Convenciones de nombres

- **Todo el código en inglés.** Nombres de funciones, variables, parámetros,
  tablas, columnas, claves de los JSON y **comentarios/docblocks** se escriben
  en inglés. La única excepción son los mensajes de error orientados al usuario
  final, que pueden ir en el idioma de la app si así se decide en su spec.
- **Prefijo de funciones:** todas empiezan por `myapi_`
  (ej. `myapi_product_list()`, `myapi_respond()`).
- **Archivos de recurso:** `resources/<name>.resource.inc`, en singular y en
  inglés (`product.resource.inc`, `order.resource.inc`).
- **Tablas custom:** prefijo `myapi_` (ej. `myapi_tokens`).
- **Paths de la API:** en **inglés**, en **plural**, bajo `api/v1`:
  - Colección: `api/v1/products`
  - Elemento: `api/v1/products/%`
- **Métodos HTTP:** se respeta la semántica REST
  (`GET` leer, `POST` crear, `PUT` actualizar, `DELETE` borrar). Cada recurso
  usa un dispatcher que enruta según el método.

---

## 6. Formato de respuesta (uniforme, sin excepciones)

Toda respuesta de la API usa este envoltorio:

**Éxito**
```json
{
  "success": true,
  "data": { }
}
```

**Error**
```json
{
  "success": false,
  "error": "Mensaje legible del error"
}
```

Se implementa con `myapi_respond($data, $status)` y
`myapi_error($message, $status)`. Los códigos HTTP deben ser correctos
(200, 201, 400, 401, 403, 404, 405, 422, 429, 500 según corresponda).

---

## 7. Receta para agregar un endpoint nuevo

1. Crear `resources/<name>.resource.inc` con un dispatcher
   `myapi_<name>_dispatch()` que reparta según el método HTTP.
2. Implementar las funciones CRUD necesarias del recurso en ese mismo archivo.
3. Registrar las rutas en `hook_menu()` dentro de `myapi.module`
   (colección `api/v1/<name>` y elemento `api/v1/<name>/%`).
4. Declarar el archivo en `myapi.info` (`files[] = resources/<name>.resource.inc`).
5. Usar **siempre** `myapi_respond()` / `myapi_error()` para responder.
6. Validar y sanitizar toda entrada con los helpers de `includes/myapi.request.inc`.
7. Crear su documentación en `docs/<name>.md` siguiendo la plantilla de la
   sección 8.
8. Si el módulo ya estaba instalado y se añadió un `.inc` nuevo, limpiar caché
   para que `hook_menu()` registre las rutas.

---

## 8. Documentación: un `.md` por recurso

Cada recurso expuesto **debe** tener su archivo en `docs/<name>.md`. Cada
endpoint dentro de ese archivo sigue esta plantilla fija para que toda la doc
sea homogénea:

```markdown
## <MÉTODO> /api/v1/<path>

Descripción breve de qué hace.

**Autenticación:** requerida / pública

**Headers**
| Header | Valor |
|--------|-------|
| Content-Type | application/json |

**Request body**
\`\`\`json
{ }
\`\`\`

**Respuesta exitosa (código)**
\`\`\`json
{ "success": true, "data": { } }
\`\`\`

**Errores posibles**
| Código | Cuándo ocurre |
|--------|---------------|
| 422 | ... |
| 401 | ... |
```

La documentación se actualiza **en el mismo cambio** que crea o modifica el
endpoint. Un endpoint sin doc se considera incompleto.

---

## 9. Prohibiciones (resumen)

- ❌ No usar Services ni módulos contribuidos para exponer la API.
- ❌ No poner lógica de negocio en `myapi.module`.
- ❌ No imprimir JSON crudo: siempre `myapi_respond()` / `myapi_error()`.
- ❌ No duplicar lógica entre recursos: lo común sube a `includes/`.
- ❌ No usar APIs de Drupal 8+.
- ❌ No usar sintaxis de PHP 8.0+ (target: PHP 7.4.33).
- ❌ No escribir identificadores ni comentarios en español: el código va en inglés.
- ❌ No crear un endpoint sin su documentación en `docs/`.

---

## 10. Lo que se define en specs aparte (no aquí)

Este `CLAUDE.md` cubre **estructura, reglas y entorno**. Lo siguiente llegará
en specs dedicados y **no** debe asumirse ni inventarse hasta entonces:

- **Autenticación** (mecanismo de login, tokens, validación): se especificará
  por completo en su propio spec.
- **Cada recurso concreto** (campos, validaciones, si es nodo o tabla custom,
  permisos): cada uno tendrá su spec antes de implementarse.

Si una tarea requiere uno de estos puntos y no hay spec disponible, **pregunta
antes de implementar** en lugar de asumir.
