# Plan: validar pm10 en una base SQL Server aislada (SOLO PLAN)

**Estado:** propuesta para revisión. **No se ha creado ninguna base, login ni copia; no se ha ejecutado SQL ni pm10.** Nada de lo que sigue se hace sin autorizaciones nuevas, separadas y expresas del propietario (ver §8).

## 1. Hallazgos que condicionan el diseño
1. **`backend/src/Core/Env.php` prioriza `backend/.env` sobre las variables del proceso** (`Env::obtener` mira primero el archivo y solo después `getenv`). Definir `$env:DB_NAME` **no** cambia el destino de ningún proceso PHP, y `Config::cargar($raiz)` lee siempre `<raiz>/.env`, donde `<raiz>` es la carpeta `backend` **desde la que se ejecuta el código**. Por eso el aislamiento se logra ejecutando una **copia de trabajo** del backend con su propio `.env`, nunca editando el `.env` habitual.
2. **El script SQL original apunta a la base real:** su cabecera es `IF DB_ID('JATSA_Encomiendas') IS NULL CREATE DATABASE JATSA_Encomiendas; … USE JATSA_Encomiendas;` y, desde la línea ~296, trae `INSERT` con **datos de ejemplo** (personas, cuentas, reservas, envíos…). **Ejecutarlo tal cual, aunque sea contra la base aislada, crearía/usaría `JATSA_Encomiendas`.** Está prohibido modificarlo y no se ejecutará nunca sin transformar: la estructura de la base aislada saldrá de una **copia derivada** generada en un directorio temporal (§5), con el original intacto (se compara su SHA-256 antes y después).

## 2. La base aislada
- **Nombre inequívoco y descartable:** `JATSA_pm10_aislada_AAAAMMDD_HHMM` (patrón `^JATSA_pm10_aislada_\d{8}_\d{4}$`). El orquestador **rechaza** cualquier nombre que no cumpla el patrón y, además, cualquiera que contenga `Encomiendas` o sea `JATSA_Encomiendas`.
- **Login dedicado** `jatsa_pm10_aislado`, usuario **solo** de esa base (`db_owner` en ella y **sin acceso** a `JATSA_Encomiendas`). Así, aunque por error algún proceso leyera la configuración habitual, el servidor SQL le negaría el acceso a la base real. La contraseña se genera en memoria y viaja por variable de entorno local; **nunca** en el repositorio, el chat ni los informes.
- **Tras la prueba:** la base se **conserva** hasta que el propietario decida; borrarla (`DROP DATABASE`) es una operación aparte y también requiere su autorización. Ningún script la borra automáticamente.

## 3. Copia de trabajo del backend (sin tocar `backend/.env`)
1. Directorio temporal exclusivo `%TEMP%\jatsa_pm10_<marca>\backend` con copias de `src/`, `config/`, `public/` y `tests/integracion/`, y `storage/sessions` y `storage/logs` **vacíos**. **No se copia ni se lee `backend/.env`.**
2. Un `.env` **generado** solo con los valores del aislamiento: `DB_HOST`, `DB_PORT`, `DB_NAME` (la base aislada), `DB_USER` (el login aislado), `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_CERT`, `APP_ENV=prueba`. Todas las claves de conexión se definen explícitamente (si faltara alguna, `Env` caería a las variables del proceso).
3. **Mismo código que el del repositorio:** tras copiar, se compara el SHA-256 de cada archivo con su original (si difiere alguno, se aborta).
4. **El `.env` habitual queda intacto:** se calcula su SHA-256 **sin mostrar su contenido** antes de empezar y al terminar; deben ser iguales.
5. Todos los procesos PHP — los 4 servidores de prueba (`php -S … -t <copia>\backend\public`) y la CLI (`leer.php`, `instantanea.php`, `escritura_pm10.php`) — se lanzan **desde la copia**. El orquestador copiado usa rutas absolutas basadas en `$PSScriptRoot` (hoy usa rutas relativas a `C:\Jatsa`; es uno de los cambios de §6).
6. Al terminar se borra la copia temporal (con sus sesiones, registros y la sonda).

## 4. Verificación de que TODO PHP se conecta solo a la base aislada
Antes de escribir nada (y otra vez al terminar), el orquestador aborta si cualquiera de estas comprobaciones falla:
1. **Nombre válido** por patrón y fuera de la lista prohibida (`JATSA_Encomiendas`).
2. **CLI desde la copia:** `leer.php` ejecutado desde la copia con `SELECT DB_NAME()` devuelve exactamente la base aislada; `instantanea.php` lo confirma por su lado.
3. **Cada servidor HTTP:** un archivo **`sonda_db.php` que solo existe en la copia** (`public/`) responde `SELECT DB_NAME()` para cada puerto 8000–8003; todos deben dar la base aislada. La sonda se borra al terminar. (Es la prueba directa de que el *proceso servidor* —no solo el script CLI— está conectado a la base correcta.)
4. **Procesos:** por `Win32_Process`, la línea de comandos de cada `php.exe` lanzado contiene la ruta de la copia y **no** `C:\Jatsa\backend`; ningún `php.exe` de la prueba usa el backend habitual.
5. **Defensa en profundidad del servidor SQL:** con el login aislado, un `SELECT` sobre `JATSA_Encomiendas` debe **fallar por permisos** (prueba de lectura que debe dar error). Además `SELECT DB_NAME(), SUSER_SNAME()` debe mostrar el login aislado.
6. **Al final:** repetir 3 y 4, comparar el SHA-256 de `backend/.env` y del script SQL original con los del inicio.

## 5. Creación y carga de la base (fase posterior; requiere autorización propia)
1. **DDL de la base y del login** (lo ejecuta el propietario o un script revisado por él): `CREATE DATABASE`, `CREATE LOGIN`, `CREATE USER … FOR LOGIN`, `ALTER ROLE db_owner ADD MEMBER`.
2. **Estructura:** a partir del script original se genera, **en el directorio temporal**, una copia derivada **de solo estructura**: se descartan la cabecera (`CREATE DATABASE`, `USE JATSA_Encomiendas`) y toda la sección de datos de ejemplo (desde el primer `INSERT`), y se antepone `USE [<base aislada>]`. Comprobaciones previas a ejecutarla: no contiene `JATSA_Encomiendas`, no contiene `INSERT`, contiene los 12 `CREATE TABLE`, y el SHA-256 del original no cambió. Se ejecuta con el **login aislado**.
3. **Datos sintéticos mínimos (opcionales), sin copiar datos personales originales:** variante *con semilla* (para que las tablas **no** estén vacías y se ejerciten los casos «original» y «ajena») con filas fabricadas y claramente falsas: sucursales `TST001` y `TST002` (con un teléfono cada una), un vehículo `TST-001`, una persona y una cuenta de gerente sintéticas (`seed_gerente`, con un hash generado en memoria), una tarifa `TST001 → TST002`. Variante *vacía* (sin ninguna fila) para ejercitar la corrección de las **tablas inicialmente vacías**. Se recomienda **ejecutar pm10 en ambas** (dos bases descartables).
4. En una base aislada el avance de `IDENT_CURRENT` **no daña nada**: sigue informándose, pero el propietario puede dispensar `-AceptoAvanceIrreversibleDeIdentity`; y el *uso exclusivo* se cumple por construcción (`-ConfirmoUsoExclusivoDeLaBase` pasa a ser una comprobación, no un acto de fe).

## 6. Cambios de código previstos (se preparan DESPUÉS de aprobar este plan)
- `validar-pm10.ps1`: parámetro `-BaseAislada <nombre>` (obligatorio en este modo) y `-DirectorioBackend <copia>`; rutas absolutas basadas en `$PSScriptRoot` en lugar de `backend\public` relativas a `C:\Jatsa`; nueva fase 0 con las verificaciones del §4; hash de `.env` y del script SQL al inicio y al final; rechazo si el nombre no cumple el patrón o apunta a `JATSA_Encomiendas`.
- `pm10-funciones.ps1`: funciones **puras** (con prueba sintética) para validar el nombre de la base, comprobar que una línea de comandos pertenece a la copia, y decidir el resultado de las sondas.
- Un `preparar_bd_aislada.ps1` **separado**, con su propia autorización, que genera la copia de trabajo, el `.env` aislado y la copia derivada del script. No forma parte del validador.
- Ampliación opcional con la base aislada: probar de verdad la regla del **«último gerente»** (desactivando también al gerente semilla), que contra la base real no es seguro.

## 7. Criterios de aborto
Cualquiera detiene todo antes de escribir: nombre fuera del patrón o con `Encomiendas`; `DB_NAME()` distinto en la CLI o en cualquier servidor; un `php.exe` que use el backend habitual; copia con archivos distintos del repositorio; el login aislado puede leer `JATSA_Encomiendas`; el hash de `backend/.env` o del script SQL cambió. Con trabajos paralelos no confirmados o procesos PHP vivos **no se hace limpieza automática** (ver `validar-pm10.ps1`), y se reporta para una recuperación controlada.

## 8. Autorizaciones que se pedirían (cada una por separado)
- **A.** Crear la base y el login aislados.
- **B.** Cargar la estructura (y, si se elige, la semilla sintética) con la copia derivada del script.
- **C.** Ejecutar `validar-pm10.ps1` en modo base aislada (una sola vez por variante).
- **D.** Borrar la base aislada cuando el propietario lo decida.

**Ahora, nada de A–D está autorizado.**
