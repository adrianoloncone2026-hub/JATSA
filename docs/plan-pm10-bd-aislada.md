# Plan: validar pm10 en una base SQL Server aislada

**Estado:** el código está **preparado para revisión estática y NO se ha ejecutado nada**: no se creó ninguna base, login ni copia de trabajo, no se ejecutó SQL real, ni siquiera la rama de rechazo de los scripts. Nada de lo que sigue se hace sin las autorizaciones A, B y C, nuevas, separadas y expresas del propietario (ver §8). **pm10 ya no puede ejecutarse contra la base habitual**: se niega a correr sin `-BaseAislada JATSA_pm10_aislada_AAAAMMDD_HHMM`.

## 1. Hallazgos que condicionan el diseño
1. **`backend/src/Core/Env.php` prioriza `backend/.env` sobre las variables del proceso** (`Env::obtener` mira primero el archivo y solo después `getenv`), y `Config::cargar($raiz)` lee siempre `<raiz>/.env`, donde `<raiz>` es la carpeta `backend` **desde la que se ejecuta el código**. Definir `$env:DB_NAME` **no** cambia el destino de ningún proceso PHP. El aislamiento se logra ejecutando una **copia de trabajo** del backend con su propio `.env` generado; el `.env` habitual no se edita, no se copia y no se lee (solo se calcula su SHA-256, sin mostrarlo).
2. **El script SQL original apunta a la base real:** su cabecera es `IF DB_ID('JATSA_Encomiendas') IS NULL CREATE DATABASE JATSA_Encomiendas; … USE JATSA_Encomiendas;` y, desde la línea ~296, trae `INSERT` con datos de ejemplo. Antes del primer `INSERT` hay exactamente **12 `CREATE TABLE`** separados por `GO` y nada más (sin índices, `ALTER` ni otras sentencias). **Ejecutarlo tal cual, aunque fuera contra otra base, crearía/usaría `JATSA_Encomiendas`.** Está prohibido modificarlo y nunca se ejecuta: la estructura de la base aislada sale de una **copia derivada** generada en un directorio temporal. Su SHA-256 se compara antes y después.

## 2. Alcance: UNA base descartable con semilla sintética mínima
Se empieza con **una sola** base, con una semilla **sintética** (nada de datos personales originales):

| Tabla | Filas | Contenido |
|---|---|---|
| `Persona` | 2 | `99999901` «Gerente Sintético Semilla» y `99999902` «Persona Sintética Sin Cuenta» |
| `Sucursal` | 2 | `TST001`, `TST002` |
| `Telefono_Sucursal` | 2 | un teléfono por sucursal |
| `Usuario` | 1 | **`seed_gerente`** (GERENTE, **activo**; contraseña aleatoria que nadie conoce) |
| `Vehiculo` | 1 | `TST-001` |
| `Ruta` | 1 | tarifa `TST001 → TST002` (SOBRE) |
| `Reserva`, `Envio`, `Bulto`, `Viaje`, `Envio_Viaje`, `Pago` | **0** | **vacías a propósito** |

**Qué cubre y qué no (no se declara cobertura de casos no ejecutados):**
- Cubre la identificación de filas temporales en tablas **inicialmente vacías** (`Viaje`, `Envio_Viaje`) y en tablas con filas (`Persona`, `Sucursal`, `Telefono_Sucursal`, `Usuario`, `Vehiculo`, `Ruta`).
- Cubre la regla del **último gerente** de verdad: en la base aislada se desactiva a `seed_gerente` (quedan activos solo los dos gerentes temporales), estos se desactivan mutuamente a la vez, se exige que nunca queden cero gerentes activos y **se restaura** a `seed_gerente` (si ninguno puede, lo hace `escritura_pm10.php reactivar-semilla` antes de limpiar) para que los datos originales queden idénticos.
- **NO cubre** una base completamente vacía: su variante exigiría adaptar explícitamente la carrera del último gerente, sus aserciones y las precondiciones (la actual necesita un gerente semilla), así que **no se reutiliza el validador sin cambios** ni se afirma esa cobertura. Es opcional y posterior.
- **NO cubre** datos históricos reales, volúmenes realistas ni el comportamiento de la base habitual.
- En una base descartable el avance de `IDENT_CURRENT` no daña nada: se **informa**, no se promete que coincida, y no retrocede.

## 3. Archivos preparados (todos en `backend/tests/integracion/`, ninguno ejecutado)
| Archivo | Autorización | Qué hace |
|---|---|---|
| `crear_bd_aislada.ps1` + `.php` | **A** | Comprueba que la base y el login no existen; `CREATE DATABASE`, `CREATE LOGIN jatsa_pm10_aislado`, `CREATE USER`, `db_owner` y `VIEW SERVER STATE` (para ver sesiones y transacciones antes de limpiar). Verifica con el **login aislado** que entra a la base nueva y que **no puede abrir `JATSA_Encomiendas`**. Cuenta administradora y contraseñas solo por variables de entorno de la sesión del propietario. |
| `cargar_bd_aislada.ps1` + `.php` | **B** | Crea la **copia de trabajo** y su `.env`, deriva y valida la estructura, y desde la copia aplica la estructura y la semilla a la base **vacía**. `-SoloPreparar` hace todo menos tocar SQL Server (para inspeccionarlo antes de autorizar B). |
| `validar-pm10.ps1` (de la copia) | **C** | La validación pm10, solo en modo base aislada. |
| `pm10-aislamiento.ps1`, `pm10-funciones.ps1`, `pm10_reglas.php`, `sonda_db.plantilla`, `escritura_pm10.php` | — | Funciones puras con pruebas sintéticas, reglas de propiedad de filas, la plantilla de la sonda y las escrituras acotadas al manifiesto. |

## 4. La copia de trabajo y la configuración independiente
1. `%TEMP%\jatsa_pm10_AAAAMMDD_HHMMSS\backend` con copias de `src/`, `config/`, `public/` y `tests/integracion/`; `storage/sessions` y `storage/logs` vacíos. **No se copia ni se lee `backend/.env`.**
2. Un `.env` **generado** con `New-ContenidoEnvAislado`: `DB_HOST`, `DB_PORT`, `DB_NAME` (la base aislada), `DB_USER` (`jatsa_pm10_aislado`), `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_CERT` y `APP_ENV=prueba`. Todas las claves de conexión van definidas (si faltara alguna, `Env` caería a las variables del proceso). Se rechazan valores con saltos de línea, nombres de base no válidos o con «Encomiendas», otro login y contraseñas cortas.
3. **Mismo código que el repositorio:** se compara el SHA-256 de cada archivo copiado (`Get-DiferenciasHashes`: detecta archivos distintos, ausentes o de más); si difiere alguno, se aborta.
4. Todos los procesos PHP —los 4 servidores `php -S … -t <copia>\backend\public` y la CLI (`leer.php`, `instantanea.php`, `escritura_pm10.php`, `cargar_bd_aislada.php`)— se lanzan **desde la copia**, porque el orquestador copiado resuelve todo desde `$PSScriptRoot`.
5. La copia **no se borra automáticamente** (si la limpieza queda detenida, hace falta para la recuperación). Contiene la contraseña del login descartable: el propietario la borra al terminar.

## 5. Verificación de que TODO PHP se conecta solo a la base aislada (fase 0 del validador)
Antes de escribir nada, todo `SELECT`, y se detiene ante cualquier fallo:
1. **Nombre** de la base por patrón exacto (`Test-NombreBdAislada`; rechaza `JATSA_Encomiendas`, cualquier nombre con «Encomiendas», `master`, etc.).
2. **Copia:** el script corre desde una carpeta `jatsa_pm10_*` y su `.env` (`Test-EnvDeCopia`) tiene `DB_NAME` = la base aislada, `DB_USER` = el login aislado, sin claves repetidas ni faltantes.
3. **CLI:** `SELECT DB_NAME(), SUSER_SNAME()` por `leer.php` (`Test-DestinoAislado`: rechaza inequívocamente `DB_NAME() = JATSA_Encomiendas`).
4. **Acceso denegado a la base real:** una lectura con el login aislado sobre `JATSA_Encomiendas` **debe fallar**; si funcionara, se detiene todo.
5. **Cada servidor HTTP:** la sonda `sonda_db.php` (copiada desde `sonda_db.plantilla` solo a la copia y borrada al terminar) devuelve `DB_NAME()` y el login de **ese proceso** en cada puerto 8000–8003 (`Get-ResultadoSondas`).
6. **Procesos:** la línea de comandos de cada `php.exe` (`Win32_Process`) está en la copia y no en el backend habitual (`Test-LineaDeComandosEnCopia`).
7. **Servidor SQL:** el login aislado tiene `VIEW SERVER STATE` (necesario para el §6) y existe `seed_gerente` activo.
Además `escritura_pm10.php` repite la comprobación en **cada operación**: el manifiesto trae `baseAislada` y `DB_NAME()` debe coincidir, o no escribe nada.

## 6. Antes de limpiar: procesos, trabajos, sesiones y transacciones SQL
La limpieza automática (el único lugar con `DELETE`) solo se ejecuta si **todo** esto se confirma:
1. todos los trabajos paralelos concluyeron (`Completed`) con exactamente una respuesta por tarea (`Get-EvaluacionTrabajos`);
2. los servidores PHP fueron detenidos y se **confirmó que terminaron** (`WaitForExit`);
3. no queda ningún trabajo de PowerShell vivo;
4. **SQL Server confirma que no quedan sesiones ni transacciones del login aislado** (`Get-EvaluacionSesionesSql` sobre `sys.dm_exec_sessions`, `sys.dm_tran_session_transactions` y `sys.dm_exec_requests`, con reintentos hasta 30 s). `WaitForExit` prueba que terminó el *proceso de Windows*, no que SQL Server haya terminado el rollback. Sin `VIEW SERVER STATE` las vistas solo muestran la sesión propia y la ausencia de filas no prueba nada: se trata como «no verificable».
Ante cualquier duda **no hay `DELETE`**: se reporta la incidencia con los pasos de recuperación controlada.

## 7. Cómo se identifican las filas temporales
No se usa «id > `IDENT_CURRENT`». El manifiesto guarda el **conjunto real de ids** de cada tabla en la instantánea inicial (`idsAntes`, que puede ser vacío) y `pm10_clasificarFilas` clasifica cada tabla: **propia** (clave del manifiesto e id que no estaba antes), **original** (una clave del manifiesto coincide con una fila previa → aborta) y **ajena** (fila nueva que no es del manifiesto → aborta sin borrar). En la base aislada la semilla queda como «original» y se restaura.

## 8. Autorizaciones que se pedirían (cada una por separado; hoy ninguna está concedida)
- **A.** Ejecutar `crear_bd_aislada.ps1` (crear la base y el login; el propietario aporta una cuenta administradora y la contraseña del login por variables de entorno).
- **B.** Ejecutar `cargar_bd_aislada.ps1 -AutorizadoCargarEstructura` (copia de trabajo, `.env` aislado, estructura derivada y semilla). Antes se puede pedir `-SoloPreparar` (no toca SQL Server) para inspeccionar los archivos.
- **C.** Ejecutar `validar-pm10.ps1` de la copia, una sola vez.
- **D.** Borrar la base aislada (nunca automático).

**Pasos para el propietario, en orden:** (1) revisión estática de los archivos de la tabla del §3; (2) opcionalmente `-SoloPreparar`; (3) autorizar A y ejecutarla; (4) autorizar B; (5) autorizar C; (6) decidir D. Se detiene ante el primer fallo, sin reintentos ni correcciones por cuenta propia.
