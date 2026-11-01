<#
  AUTORIZACIÓN B del plan docs/plan-pm10-bd-aislada.md: preparar la COPIA DE TRABAJO del backend con configuración independiente y CARGAR la estructura y la semilla
  sintética en la base aislada ya creada (autorización A). PREPARADO PARA REVISIÓN ESTÁTICA. NO SE HA EJECUTADO.

  Dos modos:
    -SoloPreparar   NO toca SQL Server: solo crea en %TEMP% la copia de trabajo (con su .env aislado) y la estructura derivada, y verifica todo. Sirve para que el
                    propietario inspeccione los archivos ANTES de autorizar la carga. No necesita autorización de base de datos.
    -AutorizadoCargarEstructura   además aplica la estructura y la semilla en la base aislada (lo hace cargar_bd_aislada.php DESDE LA COPIA, con autenticación integrada de Windows).

  Garantías:
    - NO se modifica ni se lee el contenido de backend/.env: solo se calcula su SHA-256 (sin mostrarlo) antes y después. Env.php da prioridad a ese archivo sobre las variables
      del proceso, por eso la configuración aislada vive en un .env GENERADO dentro de la copia.
    - El script SQL original (docs\JATSA_Envios-SQLQuery1 (1).sql) solo se LEE: crea «JATSA_Encomiendas» y hace «USE JATSA_Encomiendas», así que NUNCA se ejecuta. Se genera
      una copia derivada de solo estructura (sin CREATE DATABASE, sin USE original, sin INSERT) que se valida (12 CREATE TABLE, sin JATSA_Encomiendas) y se aplica a la base
      aislada. Su SHA-256 se compara antes y después.
    - La copia contiene el MISMO código que el repositorio (se compara el SHA-256 de cada archivo).

  AUTENTICACIÓN INTEGRADA DE WINDOWS: no hay login SQL, ni contraseñas, ni credenciales en el .env de la copia (DB_USER y DB_PASSWORD van definidas y vacías). La cuenta
  Windows es SYSADMIN: NO existe aislamiento por permisos; solo valen la copia independiente y las verificaciones de DB_NAME() y de la cuenta.

  Datos NO secretos por variables de entorno de la sesión: $env:JATSA_PM10_SERVIDOR y, opcional, $env:JATSA_PM10_PUERTO

  Uso:  powershell -File backend\tests\integracion\cargar_bd_aislada.ps1 -SoloPreparar -Base JATSA_pm10_aislada_AAAAMMDD_HHMM
        powershell -File backend\tests\integracion\cargar_bd_aislada.ps1 -AutorizadoCargarEstructura -Base JATSA_pm10_aislada_AAAAMMDD_HHMM
#>
param([switch]$AutorizadoCargarEstructura, [switch]$SoloPreparar, [string]$Base = '')

if (-not $AutorizadoCargarEstructura -and -not $SoloPreparar) {
    Write-Host 'Rechazado: cargar la estructura en SQL Server requiere autorización expresa del propietario (-AutorizadoCargarEstructura, autorización B). Con -SoloPreparar solo se crea la copia local.'
    exit 2
}
$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
. "$aqui\pm10-aislamiento.ps1"
if (-not (Test-NombreBdAislada $Base)) { Write-Host 'Rechazado: el nombre debe ser JATSA_pm10_aislada_AAAAMMDD_HHMM y nunca contener «Encomiendas».'; exit 2 }
foreach ($variable in 'JATSA_PM10_SERVIDOR') {
    if (-not [Environment]::GetEnvironmentVariable($variable)) { Write-Host "Rechazado: falta la variable de entorno $variable."; exit 2 }
}
$php = 'C:\php\php.exe'
if (-not (Test-Path $php)) { Write-Host 'Rechazado: no se encuentra PHP en C:\php\php.exe.'; exit 2 }

$repo = (Resolve-Path (Join-Path $aqui '..\..\..')).Path
$backendHabitual = Join-Path $repo 'backend'
$sqlOriginal = Join-Path $repo 'docs\JATSA_Envios-SQLQuery1 (1).sql'
if (-not (Test-Path -LiteralPath $sqlOriginal)) { Write-Host 'Rechazado: no se encuentra el script SQL original.'; exit 2 }

# 1) Huellas de lo que NO debe cambiar: el .env habitual (solo su hash, nunca su contenido) y el script SQL original.
$envHabitual = Join-Path $backendHabitual '.env'
$hashEnv0 = if (Test-Path -LiteralPath $envHabitual) { (Get-FileHash -LiteralPath $envHabitual -Algorithm SHA256).Hash } else { 'sin-env' }
$hashSql0 = (Get-FileHash -LiteralPath $sqlOriginal -Algorithm SHA256).Hash

# 2) Copia de trabajo: src, config, public y tests\integracion; storage vacío; SIN el .env habitual.
$marca = Get-Date -Format 'yyyyMMdd_HHmmss'
$dirCopia = Join-Path $env:TEMP "jatsa_pm10_$marca"
$backendCopia = Join-Path $dirCopia 'backend'
"== B1. Copia de trabajo del backend en $backendCopia"
$carpetas = @('src', 'config', 'public', 'tests\integracion')
foreach ($c in $carpetas) {
    $destino = Join-Path $backendCopia $c
    New-Item -ItemType Directory -Force (Split-Path $destino -Parent) | Out-Null
    Copy-Item -LiteralPath (Join-Path $backendHabitual $c) -Destination $destino -Recurse -Force
}
New-Item -ItemType Directory -Force (Join-Path $backendCopia 'storage\sessions'), (Join-Path $backendCopia 'storage\logs') | Out-Null
$inventario = {
    param($raiz, $subcarpetas)
    $h = @{}
    foreach ($c in $subcarpetas) {
        $carpeta = Join-Path $raiz $c
        Get-ChildItem -LiteralPath $carpeta -Recurse -File | ForEach-Object { $h[$_.FullName.Substring($raiz.Length).TrimStart('\').ToLowerInvariant()] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
    }
    $h
}
$dif = @(Get-DiferenciasHashes (& $inventario $backendHabitual $carpetas) (& $inventario $backendCopia $carpetas))
if ($dif.Count -gt 0) { Write-Host "DETENIDO: la copia no es idéntica al repositorio: $($dif -join '; ')"; exit 4 }
"   la copia tiene exactamente el mismo código que el repositorio ($($carpetas -join ', '))"
if (Test-Path -LiteralPath (Join-Path $backendCopia 'public\sonda_db.php')) { Write-Host 'DETENIDO: la copia ya trae una sonda.'; exit 4 }

# 3) .env independiente, generado (nunca se copia el habitual).
$puerto = [Environment]::GetEnvironmentVariable('JATSA_PM10_PUERTO'); if ($null -eq $puerto) { $puerto = '' }
$contenidoEnv = New-ContenidoEnvAislado ([Environment]::GetEnvironmentVariable('JATSA_PM10_SERVIDOR')) $puerto $Base
[IO.File]::WriteAllText((Join-Path $backendCopia '.env'), $contenidoEnv, (New-Object Text.UTF8Encoding($false)))
$verif = Test-EnvDeCopia ([IO.File]::ReadAllText((Join-Path $backendCopia '.env'), [Text.Encoding]::UTF8)) $Base
if (-not $verif.Valido) { Write-Host "DETENIDO: el .env generado no es válido: $($verif.Motivo)"; exit 4 }
"== B2. .env independiente generado en la copia: DB_NAME = $Base, autenticación integrada de Windows (DB_USER y DB_PASSWORD vacíos; sin credenciales)"

# 4) Estructura derivada del script original (el original solo se lee).
$derivada = ConvertTo-DdlDerivado ([IO.File]::ReadAllText($sqlOriginal, [Text.Encoding]::UTF8)) $Base
$problemas = @(Test-DdlDerivado $derivada $Base)
if ($problemas.Count -gt 0) { Write-Host "DETENIDO: la estructura derivada no es válida: $($problemas -join '; ')"; exit 4 }
$rutaDerivada = Join-Path $dirCopia 'estructura_derivada.sql'
[IO.File]::WriteAllText($rutaDerivada, $derivada, (New-Object Text.UTF8Encoding($false)))
"== B3. Estructura derivada en $rutaDerivada (12 CREATE TABLE, $(@(Split-LotesGo $derivada).Count) lotes, sin INSERT ni JATSA_Encomiendas)"

$codigo = 0
if ($SoloPreparar -and -not $AutorizadoCargarEstructura) {
    "== -SoloPreparar: NO se tocó SQL Server. Revisa la copia y la estructura derivada; la carga requiere -AutorizadoCargarEstructura."
} else {
    "== B4. Cargar la estructura y la semilla sintética EN LA BASE AISLADA, desde la copia (un solo intento)"
    & $php (Join-Path $backendCopia 'tests\integracion\cargar_bd_aislada.php') $Base $rutaDerivada (Get-LoginWindowsActual)
    $codigo = $LASTEXITCODE
    if ($codigo -ne 0) { "DETENIDO (código $codigo). No se reintenta ni se corrige nada por cuenta propia." }
}

# 5) Lo que no debía cambiar, no cambió.
$hashEnv1 = if (Test-Path -LiteralPath $envHabitual) { (Get-FileHash -LiteralPath $envHabitual -Algorithm SHA256).Hash } else { 'sin-env' }
$hashSql1 = (Get-FileHash -LiteralPath $sqlOriginal -Algorithm SHA256).Hash
if ($hashEnv0 -ne $hashEnv1 -or $hashSql0 -ne $hashSql1) { Write-Host 'ALERTA: el .env habitual o el script SQL original CAMBIARON durante la preparación.'; exit 5 }
"   comprobado: el SHA-256 de backend/.env y del script SQL original es el mismo que al empezar"
if ($codigo -eq 0) {
    "Copia de trabajo lista. Siguiente paso, con su propia autorización (C), ejecutar el validador DE LA COPIA:"
    "   powershell -File `"$backendCopia\tests\integracion\validar-pm10.ps1`" -AutorizadoPorElPropietario -BaseAislada $Base"
}
exit $codigo
