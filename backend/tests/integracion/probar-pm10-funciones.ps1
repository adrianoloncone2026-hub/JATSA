<# Prueba SIN base de datos ni red de las reglas puras del validador pm10. Uso: powershell -File backend\tests\integracion\probar-pm10-funciones.ps1 #>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\pm10-funciones.ps1"
$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Tabla($filas, $checksum, $ident, $hashes) { [pscustomobject]@{ filas = $filas; checksum = $checksum; ident = $ident; hashes = $hashes } }
function Instantanea([hashtable]$tablas) { $t = New-Object psobject; foreach ($k in $tablas.Keys) { $t | Add-Member -NotePropertyName $k -NotePropertyValue $tablas[$k] }; [pscustomobject]@{ tablas = $t } }
$nombres = 'Bulto', 'Envio', 'Envio_Viaje', 'Pago', 'Persona', 'Reserva', 'Ruta', 'Sucursal', 'Telefono_Sucursal', 'Usuario', 'Vehiculo', 'Viaje'
function Base { $h = @{}; $i = 10; foreach ($n in $nombres) { $h[$n] = Tabla 5 "c$i" $i @('1', '2', '3'); $i++ }; $h }

'-- datos originales idénticos vs contadores IDENTITY (son cosas distintas)'
$antes = Instantanea (Base)
$despuesIgual = Instantanea (Base)
V 'sin ningún cambio: las 12 tablas idénticas y todos los contadores SinCambio' ((Get-ComparacionOriginales $antes $despuesIgual | Where-Object { -not $_.Identica }).Count -eq 0 -and (Get-AvanceIdentity $antes $despuesIgual | Where-Object { $_.Estado -ne 'SinCambio' }).Count -eq 0)

$h = Base; foreach ($n in 'Sucursal', 'Ruta', 'Persona', 'Usuario', 'Vehiculo', 'Viaje') { $h[$n] = Tabla 5 $antes.tablas.$n.checksum ([long]$antes.tablas.$n.ident + 4) @('1', '2', '3') }
$despuesAvance = Instantanea $h
$cmp = @(Get-ComparacionOriginales $antes $despuesAvance); $ident = @(Get-AvanceIdentity $antes $despuesAvance)
V 'tras la limpieza con IDENTITY avanzado: los DATOS siguen idénticos' (($cmp | Where-Object { -not $_.Identica }).Count -eq 0)
V 'esos contadores se clasifican como «Avanzo» (informado, no falla) con su avance exacto' ((($ident | Where-Object { $_.Estado -eq 'Avanzo' }).Count -eq 6) -and (($ident | Where-Object { $_.Tabla -eq 'Ruta' }).Avance -eq 4))
V 'los contadores de tablas que el módulo no escribe siguen SinCambio' ((($ident | Where-Object { $_.Tabla -in 'Pago', 'Bulto', 'Reserva', 'Envio' } | Where-Object { $_.Estado -ne 'SinCambio' }).Count) -eq 0)

'-- fallos que deben detectarse'
$h = Base; $h['Envio'] = Tabla 5 $antes.tablas.Envio.checksum $antes.tablas.Envio.ident @('1', '2', '9')
V 'un hash de fila distinto en una tabla original = NO idéntica' ((Get-ComparacionOriginales $antes (Instantanea $h) | Where-Object { $_.Tabla -eq 'Envio' }).Identica -eq $false)
$h = Base; $h['Usuario'] = Tabla 6 $antes.tablas.Usuario.checksum $antes.tablas.Usuario.ident @('1', '2', '3')
V 'una fila de más (resto sin limpiar) = NO idéntica' ((Get-ComparacionOriginales $antes (Instantanea $h) | Where-Object { $_.Tabla -eq 'Usuario' }).Identica -eq $false)
$h = Base; $h['Viaje'] = Tabla 5 $antes.tablas.Viaje.checksum ([long]$antes.tablas.Viaje.ident - 1) @('1', '2', '3')
V 'un contador que RETROCEDE se marca RETROCEDIO' ((Get-AvanceIdentity $antes (Instantanea $h) | Where-Object { $_.Tabla -eq 'Viaje' }).Estado -eq 'RETROCEDIO')
$h = Base; $h['Pago'] = Tabla 5 $antes.tablas.Pago.checksum ([long]$antes.tablas.Pago.ident + 1) @('1', '2', '3')
V 'un contador que avanza en una tabla que el módulo no escribe se marca Inesperado' ((Get-AvanceIdentity $antes (Instantanea $h) | Where-Object { $_.Tabla -eq 'Pago' }).Estado -eq 'Inesperado')
$h = Base; $h.Remove('Pago')
V 'una tabla que desaparece de la instantánea final = NO idéntica (no se ignora)' ((Get-ComparacionOriginales $antes (Instantanea $h) | Where-Object { $_.Tabla -eq 'Pago' }).Identica -eq $false)

'-- orden de limpieza (hijos antes que padres)'
$o = Get-OrdenLimpieza
function Antes($a, $b) { [array]::IndexOf($o, $a) -lt [array]::IndexOf($o, $b) }
V 'Envio_Viaje y Viaje antes que Vehiculo, Usuario y Sucursal' ((Antes 'Envio_Viaje' 'Viaje') -and (Antes 'Viaje' 'Vehiculo') -and (Antes 'Viaje' 'Usuario') -and (Antes 'Viaje' 'Sucursal'))
V 'Telefono_Sucursal y Ruta antes que Sucursal' ((Antes 'Telefono_Sucursal' 'Sucursal') -and (Antes 'Ruta' 'Sucursal'))
V 'Usuario antes que Persona y que Sucursal' ((Antes 'Usuario' 'Persona') -and (Antes 'Usuario' 'Sucursal'))
V 'ninguna tabla repetida y ninguna que el módulo no cree' (($o | Select-Object -Unique).Count -eq $o.Count -and -not ($o -contains 'Pago') -and -not ($o -contains 'Envio') -and -not ($o -contains 'Reserva') -and -not ($o -contains 'Bulto'))

'-- manifiesto'
$id = @{ Sucursal = 1; Telefono_Sucursal = 1; Ruta = 1; Vehiculo = 1; Persona = 1; Usuario = 1; Viaje = 1; Envio_Viaje = 1 }
$valido = @{ identAntes = $id; sucursales = @('PMX901'); vehiculos = @('PM1-901'); documentos = @('90000101', '20000000107'); usuarios = @('pm10_admin'); viajes = @('VJ2026110101') }
V 'un manifiesto bien formado no tiene problemas' ((Test-ManifiestoPm10 $valido).Count -eq 0)
foreach ($caso in @(@('sucursales', 'MOQ001'), @('vehiculos', 'B7K-210'), @('documentos', '71889345'), @('usuarios', 'lflores'), @('viajes', 'VJ12'))) {
    $m = @{} + $valido; $m[$caso[0]] = @($caso[1])
    V "rechaza un valor ORIGINAL en $($caso[0]) ($($caso[1])): el PHP nunca podría borrar datos originales" ((Test-ManifiestoPm10 $m).Count -ge 1)
}
$m = @{} + $valido; $m.identAntes = @{ Sucursal = 1 }
V 'rechaza un manifiesto sin la instantánea de IDENT_CURRENT' ((Test-ManifiestoPm10 $m).Count -ge 1)

"Resultado: $ok correctas, $mal con fallo."
if ($mal -gt 0) { exit 1 }
exit 0