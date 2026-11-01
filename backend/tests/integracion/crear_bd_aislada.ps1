<#
  AUTORIZACIÓN A del plan docs/plan-pm10-bd-aislada.md: CREAR la base SQL Server aislada. PREPARADO PARA REVISIÓN ESTÁTICA. NO SE HA EJECUTADO.
  No se ejecuta sin autorización expresa del propietario para el mecanismo de AUTENTICACIÓN INTEGRADA DE WINDOWS.

  MECANISMO: la instancia solo admite Windows (SoloWindows = 1) y la cuenta Windows del propietario es SYSADMIN. Por eso NO se crea ningún login ni usuario SQL, no se
  concede ningún permiso, no se activa el modo mixto ni se cambia la configuración de la instancia, y no hace falta ninguna credencial. NO EXISTE AISLAMIENTO POR
  PERMISOS: lo único que separa esta base de JATSA_Encomiendas es la configuración (copia independiente del backend) y las verificaciones de DB_NAME().

  Qué hace (lo hace crear_bd_aislada.php): comprueba autenticación Windows y sysadmin, que la base no existe, y ejecuta CREATE DATABASE; verifica con una conexión nueva.
  No toca ninguna otra base, no ejecuta el script SQL original y no borra nada.

  Datos NO secretos, por variables de entorno de la sesión: $env:JATSA_PM10_SERVIDOR y, opcional, $env:JATSA_PM10_PUERTO

  Uso:  powershell -File backend\tests\integracion\crear_bd_aislada.ps1 -AutorizadoCrearBase -Base JATSA_pm10_aislada_AAAAMMDD_HHMM
#>
param([switch]$AutorizadoCrearBase, [string]$Base = '')

if (-not $AutorizadoCrearBase) {
    Write-Host 'Rechazado: crear una base en SQL Server requiere autorización expresa del propietario (-AutorizadoCrearBase, autorización A).'
    exit 2
}
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\pm10-aislamiento.ps1"
if (-not (Test-NombreBdAislada $Base)) {
    Write-Host 'Rechazado: el nombre debe ser JATSA_pm10_aislada_AAAAMMDD_HHMM y nunca contener «Encomiendas».'
    exit 2
}
if (-not [Environment]::GetEnvironmentVariable('JATSA_PM10_SERVIDOR')) { Write-Host 'Rechazado: falta la variable de entorno JATSA_PM10_SERVIDOR.'; exit 2 }
$php = 'C:\php\php.exe'
if (-not (Test-Path $php)) { Write-Host 'Rechazado: no se encuentra PHP en C:\php\php.exe.'; exit 2 }

"== A. Crear la base aislada «$Base» con la cuenta Windows $(Get-LoginWindowsActual) (autenticación integrada; un solo intento; si algo falla se detiene y se informa)"
& $php "$PSScriptRoot\crear_bd_aislada.php" $Base
$codigo = $LASTEXITCODE
if ($codigo -ne 0) { "DETENIDO (código $codigo). No se reintenta ni se corrige nada por cuenta propia."; exit $codigo }
"Hecho. Siguiente paso, con su propia autorización (B): cargar_bd_aislada.ps1 -AutorizadoCargarEstructura -Base $Base"
exit 0
