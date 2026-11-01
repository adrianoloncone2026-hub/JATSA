<# Prueba SIN base de datos ni red de las reglas de horarios y «programado» del validador pm9. Uso: powershell -File backend\tests\integracion\probar-pm9-funciones.ps1 #>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\pm9-funciones.ps1"
$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Fila($o, $d, $s) { [pscustomobject]@{ o = $o; d = $d; salida = $s } }

# Datos: MOQ001-AQP001 con historial (15h x3, 14h x2, 08h x2, 09h x1); AQP001-LIM001 programada SIN historial; ILO001-MOQ001 SIN historial NI programados.
$viajes = @(
    (Fila 'MOQ001' 'AQP001' '2026-10-14 15:00:00'), (Fila 'MOQ001' 'AQP001' '2026-10-13 15:10:00'), (Fila 'MOQ001' 'AQP001' '2026-10-12 15:20:00'),
    (Fila 'MOQ001' 'AQP001' '2026-10-11 14:00:00'), (Fila 'MOQ001' 'AQP001' '2026-10-10 14:30:00'),
    (Fila 'MOQ001' 'AQP001' '2026-10-09 08:00:00'), (Fila 'MOQ001' 'AQP001' '2026-10-08 08:30:00'), (Fila 'MOQ001' 'AQP001' '2026-10-07 09:00:00'))
$horarios = Get-HorariosEsperados $viajes
$programados = @{ 'AQP001-LIM001' = 1; 'MOQ001-AQP001' = 2 }   # MOQ001-AQP001 también tiene viajes programados, pero SÍ tiene historial

'-- ruta con historial'
$r = Get-EsperadoDeRuta 'MOQ001-AQP001' $horarios $programados
V 'horarios exactos 08:00 14:00 15:00 (3 más frecuentes, empate: la más temprana, orden de reloj)' (($r.Horarios -join ',') -eq '08:00,14:00,15:00') ($r.Horarios -join ',')
V 'tiene historial: NO es «programado» aunque existan viajes programados' ($r.Programado -eq $false)

'-- ruta PROGRAMADA sin historial (clave inexistente en los horarios)'
V 'la clave no existe en los horarios esperados (no hay entrada nula)' (-not $horarios.ContainsKey('AQP001-LIM001'))
$r = Get-EsperadoDeRuta 'AQP001-LIM001' $horarios $programados
V 'cero horarios (Cantidad = 0, no @($null) con 1)' ($r.Cantidad -eq 0 -and $r.Horarios.Length -eq 0)
V 'es «programado»' ($r.Programado -eq $true)
V 'la comparación con una API de horarios vacíos coincide' ((@() -join ',') -eq ($r.Horarios -join ','))

'-- ruta SIN historial NI viajes programados'
$r = Get-EsperadoDeRuta 'ILO001-MOQ001' $horarios $programados
V 'cero horarios' ($r.Cantidad -eq 0 -and $r.Horarios.Length -eq 0)
V 'NO es «programado»' ($r.Programado -eq $false)

'-- casos límite'
V 'una entrada nula en los horarios tampoco cuenta como horario' ((Get-EsperadoDeRuta 'X-Y' @{ 'X-Y' = $null } @{ 'X-Y' = 3 }).Cantidad -eq 0)
V 'con un valor nulo y viajes programados sigue siendo «programado»' ((Get-EsperadoDeRuta 'X-Y' @{ 'X-Y' = $null } @{ 'X-Y' = 3 }).Programado -eq $true)
V 'sin viajes en absoluto no hay claves' ((Get-HorariosEsperados @()).Count -eq 0)
V 'frecuencias iguales: entran las 3 más tempranas' ((((Get-HorariosEsperados @((Fila 'A' 'B' '2026-10-14 16:00:00'), (Fila 'A' 'B' '2026-10-13 07:00:00'), (Fila 'A' 'B' '2026-10-12 11:00:00'), (Fila 'A' 'B' '2026-10-11 09:00:00')))['A-B']) -join ',') -eq '07:00,09:00,11:00')

"Resultado: $ok correctas, $mal con fallo."
if ($mal -gt 0) { exit 1 }
exit 0