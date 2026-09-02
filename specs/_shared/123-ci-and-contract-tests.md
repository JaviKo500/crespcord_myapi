# 123 — Integración continua y tests de contrato del módulo

- **Estado:** Implemented — 13 casos nuevos, CI en PHP 7.4, hook de pre-commit,
  suite unitaria en verde (3.666 tests, 17.224 assertions; antes 3.653 / 16.105)
- **Fecha:** 2026-09-02
- **Dependencias:**
  - `121-remaining-unit-tests` (Implemented) — quien llevó la capa unitaria a
    cubrir el módulo entero. Este spec no añade cobertura de comportamiento:
    añade lo que esa capa, por construcción, no puede ver.
  - `122-input-parsing-unification` (Implemented) — el precedente inmediato de
    un cambio ancho (18 archivos de producción) apoyado en la suite. Es el tipo
    de cambio que este spec quiere que nadie pueda empujar sin que algo lo corra.
  - `03-i18n-mensajes-respuestas` (Implemented) — el catálogo de `myapi_t()`,
    cuya segunda dirección (¿existe la clave que el código usa?) se cierra aquí.
- **Objetivo:** Que la suite se ejecute sola y en la versión de PHP de
  producción, y que las reglas estructurales de `CLAUDE.md` —enrutado, registro
  de archivos, documentación, prohibiciones— dejen de depender de que nadie se
  despiste.

---

## El problema

La suite unitaria estaba en un punto raro: 3.653 casos, verdes en menos de un
segundo, cubriendo todos los recursos del módulo... y **nada la ejecutaba**. No
había `.github/`, ni un hook de git activo, ni un paso en `scripts/deploy.sh`.
Una suite que solo corre cuando alguien se acuerda no protege de un cambio
futuro: protege del cambio que estaba haciendo quien se acordó.

El segundo problema es de versión. Producción es **PHP 7.4.33** y las máquinas
de desarrollo ya no lo son: aquí `php` es 8.4. Eso significa que `php -l` en
local **no dice nada** sobre el objetivo — un `match`, un `?->` o un
`str_contains()` pasan el lint de la máquina que los escribió y fatalan en el
servidor. La regla «No PHP 8.0+ syntax» de `CLAUDE.md` no tenía ninguna
comprobación detrás.

Y el tercero es de forma. El checklist de «Adding a new endpoint» de
`CLAUDE.md` son siete pasos, y **ningún test ve seis de ellos**:

| Si alguien se salta... | Lo que pasa | Lo que ve la suite unitaria |
|---|---|---|
| `files[] = ...` en `myapi.info` | Fatal en la primera petición que llegue sola a ese `.inc` | Nada: el test hace `require` del archivo él mismo |
| El `'file'` correcto en la ruta | 404: Drupal incluye el archivo declarado y la función no está ahí | Nada: el test llama al dispatcher por su nombre |
| `'access callback'` | Drupal cae a `user_access('access content')` — el endpoint **se abre**, no se cierra | Nada |
| Registrar la ruta | Una feature escrita, testeada, mergeada e inalcanzable | Nada |
| `docs/<name>.md` | Un endpoint sin contrato escrito | Nada |
| El prefijo `api/v1/` | Una ruta fuera del versionado | Nada |

Ninguno de esos seis es un fallo de comportamiento, y por eso ninguna clase de
`tests/unit/` puede verlo. Son fallos de **cableado**, y el cableado es
exactamente de lo que está hecho el checklist.

---

## Alcance

### Dentro de este spec

**`.github/workflows/tests.yml` (nuevo)** — un job en `ubuntu-latest` con PHP
**7.4**: `composer install`, `php -l` sobre los 178 archivos del repositorio
(`.php`, `.inc`, `.module`, `.install`, `.test`) y la suite unitaria completa.
Corre en cada push de cualquier rama y en cada pull request.

**`scripts/hooks/pre-commit` (nuevo)** — dos puertas antes de cada commit: que
el **contenido en staging** parsee (no el del árbol de trabajo: un arreglo
escrito y no añadido no debe hacer pasar un commit roto) y que la suite esté en
verde. Tarda ~2 s. Se salta con `git commit --no-verify`.

**`scripts/install-git-hooks.sh` / `.ps1` (nuevos)** — apuntan
`core.hooksPath` a `scripts/hooks`, en vez de copiar a `.git/hooks`: no hay
copia que mantenga nadie sincronizada, y un cambio en el hook llega a todos en
el siguiente pull.

**`tests/unit/ModuleContractTest.php` (nuevo)** — 11 casos, 937 assertions:

| Caso | Qué afirma |
|---|---|
| `testEveryRouteDeclaresItsCallbackAndItsAccess` | Toda ruta declara `page callback`, `access callback` y `type` |
| `testEveryCallbackLivesInTheFileItsRouteDeclares` | El `'file'` existe **y contiene** la función que la ruta nombra |
| `testEveryRouteFileIsDeclaredInTheInfoFile` | Todo archivo enrutado está en `files[]` |
| `testEveryIncludeAndResourceIsDeclaredInTheInfoFile` | Y todo `.inc` del árbol, esté enrutado o no |
| `testEveryFileDeclaredInTheInfoFileExists` | Y al revés: ningún `files[]` apunta a un archivo borrado |
| `testEveryEndpointIsUnderTheVersionOnePrefix` | Regla 6 de `CLAUDE.md`, con allowlist de las 6 rutas no versionadas |
| `testTheNonVersionedAllowlistHasNoStaleEntries` | La allowlist no acumula entradas muertas |
| `testEveryEndpointIsDocumented` | Los 53 endpoints `api/v1/` aparecen en `docs/*.md`, ruta completa |
| `testEveryDispatcherIsRouted` | Ningún `*_dispatch()` definido queda sin ruta |
| `testNoResourcePrintsItsOwnJson` | Nadie imprime un cuerpo fuera de `myapi_respond()`/`myapi_error()` |
| `testNoSourceFileUsesPhp8OnlyCode` | Ni funciones ni sintaxis de PHP 8 |

**`tests/unit/I18nTest.php` (modificar)** — 2 casos nuevos que cierran la
dirección que faltaba del catálogo:

- `testEveryKeyUsedByTheModuleExistsInTheCatalogue` — las 90 claves literales
  que el módulo pasa a `myapi_error()`, `myapi_t()` y al `message_key` de
  `myapi_respond()` existen en los dos idiomas. Sin esto, una clave mal escrita
  **no falla**: el sobre es correcto, el `error_code` es el correcto, y el campo
  `error` lleva la clave cruda a la pantalla del residente.
- `testEveryCatalogueKeyIsReachedByTheModule` — y al revés, con un barrido más
  ancho (todo literal de cadena en producción, no solo los de un call site),
  porque un tercio del catálogo se usa indirectamente: devuelto por un validador
  como nombre de lo que falló, elegido por un ternario, o como clave del mapa
  que lo convierte en un 409.

**`tests/unit/bootstrap.php` (modificar)** — las constantes `MENU_*` de Drupal
y `WATCHDOG_INFO`, lo único que faltaba para poder hacer `require` de
`myapi.module` fuera de un sitio y **llamar a `myapi_menu()`** en lugar de
parsear su fuente con una regex.

### Fuera de este spec — y por qué

| Qué | Por qué no |
|---|---|
| PHPCompatibility (`phpcs`) | Correr el lint **y toda la suite** bajo 7.4 real cubre la sintaxis entera sin dependencia nueva; lo que un linter añadiría —funciones de PHP 8— lo cubre `testNoSourceFileUsesPhp8OnlyCode`. Queda como ampliación si algún día el barrido por nombre se queda corto. |
| Las capas de integración y e2e en CI | Necesitan un sitio Drupal vivo, credenciales reales y un buzón IMAP. Meterlas aquí sería meter los secretos de producción en un runner. Siguen con sus `scripts/run-*.sh`. |
| Medir cobertura de líneas | Requiere `pcov`/`xdebug` y una decisión sobre el umbral. Es el spec siguiente natural, no este. |
| Borrar las tres claves muertas del catálogo | Es un cambio a lo que el módulo publica; este es un spec de tests. Fijadas y documentadas, como los siete hallazgos del 121. |
| Un job en PHP 8 | Producción es 7.4. Un verde en 8.x no significa nada aquí y un rojo distraería. |

---

## Hallazgos

**Tres claves del catálogo no las produce nada.** `unauthorized`,
`user_not_found` y `missing_token` no aparecen como literal en ningún archivo de
producción ni en ningún test: son restos de specs de auth cuyos mensajes fueron
sustituidos por otras claves. Quedan listadas en `I18nTest::UNREACHED_KEYS`, que
es lo que mantiene el caso honesto sobre cuántas son. Borrarlas es una decisión
de una línea para el próximo spec que toque el catálogo.

**Todo lo demás estaba bien.** Los 59 items de `hook_menu()`, los 53 endpoints,
los 90 usos de claves, los 178 archivos: los once casos de contrato pasaron a la
primera. Eso no los hace inútiles —hacen falta el día que alguien añada el
recurso número 21— pero conviene decirlo: este spec no encontró un cableado
roto, encontró que no había nada vigilándolo.

---

## Verificación

Cada afirmación nueva se comprobó **rompiéndola** y viendo fallar el caso, con
el árbol restaurado después:

| Mutación | Caso que falló |
|---|---|
| Borrar `files[] = resources/ping.resource.inc` | `testEveryRouteFileIsDeclaredInTheInfoFile` y `testEveryIncludeAndResourceIsDeclaredInTheInfoFile` |
| Apuntar la ruta de `ping` a `bank.resource.inc` | `testEveryCallbackLivesInTheFileItsRouteDeclares` |
| Un `str_contains()` en `includes/myapi.text.inc` | `testNoSourceFileUsesPhp8OnlyCode` |
| `myapi_error('methd_not_allowed', 405)` | `testEveryKeyUsedByTheModuleExistsInTheCatalogue` |
| Una clave nueva en los dos catálogos sin usarla | `testEveryCatalogueKeyIsReachedByTheModule` |
| Un `.inc` con error de sintaxis en staging | El hook de pre-commit, en sus dos puertas |

---

## Decisiones tomadas y descartadas

| Decisión | Opción elegida | Alternativa descartada | Motivo |
|---|---|---|---|
| Leer la tabla de rutas | `require` de `myapi.module` y llamar a `myapi_menu()` | Parsear la fuente con regex | La regex se queda obsoleta la primera vez que una ruta se escriba con otro espaciado; el array es el que recibe el sitio. |
| Detectar PHP 8 | Tokens (`token_get_all`) | `grep` | Un docblock que menciona `str_contains()` es prosa, y un método `->match()` es legal en 7.4. |
| Rutas fuera de `api/v1/` | Allowlist explícita de 6, más un caso que la mantiene limpia | No afirmar nada | Una allowlist obliga a que añadir una excepción sea una línea en el diff que alguien revisa. |
| Claves usadas vía variable | Contarlas y saltarlas, con un `assertGreaterThan(50)` de cordura | Fallar | Un parse que deje de reconocer los literales debe fallar, no pasar afirmando nada. |
| El barrido inverso del catálogo | Todo literal de producción | Solo los call sites | Con el estrecho, 26 claves vivas salían como muertas y el caso habría necesitado una allowlist de treinta. |
| Dónde viven los hooks | `core.hooksPath` a `scripts/hooks` | Copiar a `.git/hooks` | La copia se desincroniza en silencio y no la versiona nadie. |
| El lint del hook | Sobre el contenido en staging (`git show :file`) | Sobre el árbol de trabajo | Un arreglo escrito y no añadido no debe hacer pasar un commit roto. |

---

## Riesgos identificados

- **La suite nunca ha corrido en PHP 7.4.** El código de producción sí es 7.4
  por política, pero los **tests** se han escrito siempre en 8.x. El primer run
  de CI es el primero que los ejecuta en la versión de destino, y puede sacar
  diferencias 7.4/8.x en la propia suite. *Mitigación:* es exactamente lo que se
  quería descubrir, y sale en el primer push, no en un deploy.
- **`testNoSourceFileUsesPhp8OnlyCode` es una heurística, no un compilador.**
  Cubre las funciones de PHP 8, `match`, `enum`, `?->` y los atributos; no cubre
  argumentos con nombre ni tipos union, que en 7.4 son error de sintaxis.
  *Mitigación:* de esos se encarga `php -l` bajo 7.4 en CI; el caso unitario es
  la versión rápida y local.
- **Un hook de pre-commit se salta con `--no-verify`.** Es intencional, y por
  eso CI corre lo mismo: el hook es comodidad, la puerta es el workflow.
- **Los casos de contrato leen el código fuente.** Si un archivo nuevo se
  escribe con una convención muy distinta (una ruta construida en un bucle, un
  dispatcher declarado condicionalmente), el barrido puede no verlo. *Mitigación:*
  cada barrido lleva una aserción de cordura sobre cuántos elementos encontró.
