<#
  AUTORIZACIÓN A del plan docs/plan-pm10-bd-aislada.md: CREAR la base SQL Server aislada y su login dedicado. PREPARADO PARA REVISIÓN ESTÁTICA. NO SE HA EJECUTADO.
  No se ejecuta sin autorización expresa y separada del propietario.

  Qué hace (lo hace crear_bd_aislada.php): comprueba que ni la base ni el login existen; CREATE DATABASE; CREATE LOGIN jatsa_pm10_aislado; CREATE USER y db_owner
  en la base nueva; GRANT VIEW SERVER STATE al login (para comprobar sesiones y transacciones antes de limpiar); y verifica con el LOGIN AISLADO que entra a la
  base nueva y que NO puede abrir JATSA_Encomiendas. No toca ninguna otra base, no ejecuta el script SQL original y no borra nada.

  Datos que da el propietario SOLO por variables de entorno de su sesión (este script no las guarda ni las imprime):
    $env:JATSA_PM10_ADMIN_SERVIDOR, $env:JATSA_PM10_ADMIN_PUERTO (opcional), $env:JATSA_PM10_ADMIN_USUARIO, $env:JATSA_PM10_ADMIN_CLAVE   cuenta ADMINISTRADORA
    $env:JATSA_PM10_CLAVE_AISLADA   contraseña que se asignará al login aislado (16-64 caracteres: letras, números y # _ . -)

  Uso:  powershell -File backend\tests\integracion\crear_bd_aislada.ps1 -AutorizadoCrearBase -Base JATSA_pm10_aislada_AAAAMMDD_HHMM
#>
param([switch]$AutorizadoCrearBase, [string]$Base = '')

if (-not $AutorizadoCrearBase) {
    Write-Host 'Rechazado: crear una base y un login en SQL Server requiere autorización expresa del propietario (-AutorizadoCrearBase, autorización A).'
    exit 2
}
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\pm10-aislamiento.ps1"
if (-not (Test-NombreBdAislada $Base)) {
    Write-Host 'Rechazado: el nombre debe ser JATSA_pm10_aislada_AAAAMMDD_HHMM y nunca contener «Encomiendas».'
    exit 2
}
foreach ($variable in 'JATSA_PM10_ADMIN_SERVIDOR', 'JATSA_PM10_ADMIN_USUARIO', 'JATSA_PM10_ADMIN_CLAVE', 'JATSA_PM10_CLAVE_AISLADA') {
    if (-not [Environment]::GetEnvironmentVariable($variable)) { Write-Host "Rechazado: falta la variable de entorno $variable."; exit 2 }
}
$php = 'C:\php\php.exe'
if (-not (Test-Path $php)) { Write-Host 'Rechazado: no se encuentra PHP en C:\php\php.exe.'; exit 2 }

"== A. Crear la base aislada «$Base» y el login «$LOGIN_AISLADO» (un solo intento; si algo falla se detiene y se informa)"
& $php "$PSScriptRoot\crear_bd_aislada.php" $Base
$codigo = $LASTEXITCODE
if ($codigo -ne 0) { "DETENIDO (código $codigo). No se reintenta ni se corrige nada por cuenta propia."; exit $codigo }
"Hecho. Siguiente paso, con su propia autorización (B): cargar_bd_aislada.ps1 -AutorizadoCargarEstructura -Base $Base"
exit 0
