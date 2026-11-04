# Plan: validar pm11 (módulo 11) en una base SQL Server aislada

**Estado: scripts preparados y revisados solo de forma estática. NO se ha ejecutado ninguno, no se creó ninguna base ni copia, no se ejecutó SQL.** Cada fase A–D necesita la autorización **nueva, separada y expresa** del propietario; una no habilita otra. No se toca `JATSA_Encomiendas`, la base de pm10, `backend/.env` ni el script SQL original.

## Scripts (`backend/tests/integracion/`)
| Archivo | Qué hace |
|---|---|
| `pm11.php` | Lanzador de las fases. Cada fase exige su propia bandera (`--autorizo-A` … `--autorizo-D`); `B` admite además `--solo-preparar` (sin SQL). |
| `pm11_trabajo.php` | Solo corre desde la **copia** de trabajo: `cargar` (fase B) y `validar` (fase C). |
| `pm11_comun.php` | Validación de nombre, `.env`, estructura derivada, identidad de cada conexión e instantánea de las 12 tablas. |
| `pm11_semilla.php` | Semilla sintética con fechas relativas + **oráculo** (repositorios en PHP puro con las mismas interfaces que los SQL). |
Pruebas sin SQL de estas bibliotecas: `backend/tests/reportes.php`. Datos no secretos: `JATSA_PM11_SERVIDOR` y, opcional, `JATSA_PM11_PUERTO`.

## Controles de aislamiento
- Base `JATSA_pm11_aislada_AAAAMMDD_HHMM` (patrón exacto; se rechaza «Encomiendas» y pm10). Autenticación integrada de Windows: **no hay aislamiento por permisos** (la cuenta es sysadmin); el aislamiento es la configuración y la verificación de `DB_NAME()`, `ORIGINAL_LOGIN()` y `auth_scheme` en **cada** conexión (CLI y proceso HTTP, este con sonda).
- Copia de trabajo `%TEMP%\jatsa_pm11_AAAAMMDD_HHMM\backend` con `.env` generado (sin credenciales); no se lee ni copia `backend/.env`; la copia debe ser idéntica al repositorio (SHA-256 por archivo) antes de B y de C. El script solo se ejecuta desde esa ruta.
- Manifiesto de la fase A (cuenta Windows, `database_id`, fecha de creación): B, C y D lo exigen. D solo borra si el `database_id` y la fecha coinciden con los de A.
- Tras sembrar, la base pasa a **`READ_ONLY`**; C se niega a correr si no lo está. Login y GET no escriben en SQL.
- Se comparan antes/después el SHA-256 de `backend/.env` y del script SQL original (el script solo se **lee** para derivar la estructura; nunca se ejecuta).

## Fases y operaciones SQL que requieren autorización
| Fase | Comando | Operaciones SQL |
|---|---|---|
| A | `php pm11.php A <base> --autorizo-A` | solo `CREATE DATABASE` (en `master`), tras comprobar que no existe |
| B | `php pm11.php B <base> --autorizo-B` (antes, opcional: `--solo-preparar`) | 12 `CREATE TABLE` derivados + `INSERT` de la semilla (usuarios con hash de contraseña aleatoria) en una transacción + `ALTER DATABASE … SET READ_ONLY` |
| C | `php pm11.php C <base> --autorizo-C` | solo `SELECT` y los GET/login por HTTP local (puerto 18111) |
| D | `php pm11.php D <base> --autorizo-D` | `DROP DATABASE` de esa base y borrado de la copia (y de las credenciales sintéticas) |
Las contraseñas sintéticas se generan al cargar y viven solo en `credenciales_pm11.json` dentro de la copia temporal; se borran en D.

## Semilla y resultados esperados
Fechas relativas a la carga: mes **M** (anterior completo), **M-1**, mes actual, y un par **diciembre Y-1 / enero Y** (Y = año de carga − 1) que cruza el año cualquier día. Envíos de M en todos los estados (ANULADO y DEVUELTO no cuentan), pagos REGISTRADO/ANULADO cobrados en una sucursal distinta de origen/destino, pagos en el último segundo de M y el primero del mes actual, 11 viajes (terminados, en ruta con ETA +0, +1 y sin ruta, programados), un transbordo, un viaje con bultos SOBRE y PAQUETE (no debe duplicarse con el filtro de tipo) y una tarifa cerrada que no debe influir en la ETA. Los esperados los calcula el **oráculo** en PHP, no los repositorios; `reportes.php` comprueba los valores calculados a mano (p. ej. M: 6 envíos, +100 %, cobrado 180.50, +50 %; flota M: 3 viajes, 810 kg, uso 35 %, transbordo 20 %).

## Qué comprueba la fase C
1. Los **8 métodos** (`ResumenRepository`: `enviosDelPeriodo`, `cobradoDelPeriodo`, `viajesAbiertos`; `FlotaRepository`: `viajesAbiertos`, `terminados`, `transbordos`, `viajesEnRuta`, `transportistas`) contra el oráculo: 4 sucursales × 7 periodos y 10 combinaciones de filtros × 7 periodos.
2. Los dos endpoints por HTTP: 200 con los datos de los servicios reales sobre el oráculo (incluye cruce de año, mes sin datos y los límites 9998), 401 sin sesión, 403 para ADMINISTRADOR, TRANSPORTISTA, CLIENTE y EMPRESARIO, y 422 (incl. `9999`).
3. Las 12 tablas idénticas antes y después (`COUNT`, `IDENT_CURRENT`, SHA-256 del contenido), exactamente 12 tablas, mismo número de objetos y base aún en `READ_ONLY`.

Pendiente fuera de SQL: pasada visual en navegador y el filtro Transportista con más de 200 cuentas (`hayMas`).
