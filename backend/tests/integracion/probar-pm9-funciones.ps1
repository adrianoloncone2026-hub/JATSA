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

'-- Leer(): conversión de JSON a filas planas (0, 1 y N filas) — datos sintéticos, sin SQL Server'
$json0 = "[]"
$json1 = "[`n    {`n        `"idRuta`":  3,`n        `"o`":  `"MOQ001`",`n        `"d`":  `"AQP001`",`n        `"tipoEnvio`":  `"SOBRE`",`n        `"desde`":  `"2026-01-01`",`n        `"horas`":  4`n    }`n]"
$jsonN = '[{"idRuta":1,"o":"MOQ001","d":"AQP001","tipoEnvio":"PAQUETE","desde":"2025-01-01","horas":9},{"idRuta":2,"o":"MOQ001","d":"AQP001","tipoEnvio":"PAQUETE","desde":"2026-01-01","horas":4},{"idRuta":3,"o":"MOQ001","d":"AQP001","tipoEnvio":"SOBRE","desde":"2026-01-01","horas":4},{"idRuta":4,"o":"ILO001","d":"MOQ001","tipoEnvio":"SOBRE","desde":"2026-01-01","horas":2},{"idRuta":5,"o":"ILO001","d":"MOQ001","tipoEnvio":"SOBRE","desde":"2026-01-01","horas":3}]'
$f0 = @(ConvertFrom-FilasJson $json0); $f1 = @(ConvertFrom-FilasJson $json1); $fN = @(ConvertFrom-FilasJson $jsonN)
V '0 filas: arreglo vacío (Count = 0)' ($f0.Count -eq 0)
V '1 fila: Count = 1 y el elemento es la FILA (no un arreglo anidado)' ($f1.Count -eq 1 -and $f1[0] -isnot [array] -and $f1[0].o -eq 'MOQ001')
V 'N filas: Count = 5, aplanado, cada elemento es una fila' ($fN.Count -eq 5 -and @($fN | Where-Object { $_ -is [array] }).Count -eq 0 -and $fN[4].idRuta -eq 5)
V 'cada fila conserva el tipo PSCustomObject y sus números como números (idRuta, horas)' ($fN[0] -is [pscustomobject] -and $fN[0].idRuta -is [int] -and $fN[0].horas -is [int])
V '[int] sobre idRuta y horas de CADA fila funciona (el fallo original)' ((@($fN | ForEach-Object { [int]$_.idRuta + [int]$_.horas }) -join ',') -eq '10,6,7,6,8')
V 'Sort-Object por idRuta descendente sobre las filas planas' ((@($fN | Sort-Object @{ e = { [int]$_.idRuta }; Descending = $true } | ForEach-Object { $_.idRuta }) -join ',') -eq '5,4,3,2,1')
V 'contrato con otras secciones: (Leer)[0].campo, foreach y .Count siguen funcionando con 1 fila' ($f1[0].horas -eq 4 -and (@($f1 | ForEach-Object { $_.horas }) -join ',') -eq '4' -and @($f1).Count -eq 1)
V 'texto vacío o en blanco -> 0 filas (sin error)' (@(ConvertFrom-FilasJson '').Count -eq 0 -and @(ConvertFrom-FilasJson "  `n").Count -eq 0)
V 'un objeto JSON suelto (no arreglo) -> 1 fila' (@(ConvertFrom-FilasJson '{"n":7}').Count -eq 1 -and (@(ConvertFrom-FilasJson '{"n":7}'))[0].n -eq 7)
V 'una fila con valores nulos conserva sus campos' ((@(ConvertFrom-FilasJson '[{"a":null,"b":2}]'))[0].b -eq 2)
V 'unicode (tildes) intacto' ((@(ConvertFrom-FilasJson '[{"n":"Agencia Arequipa Centro ñ é"}]'))[0].n -eq 'Agencia Arequipa Centro ñ é')
$viejo = @($json1 | ConvertFrom-Json)
"  (informativo) el método anterior @(`$s | ConvertFrom-Json) con 1 fila daba Count = $($viejo.Count) y elemento de tipo $($viejo[0].GetType().Name) en PowerShell $($PSVersionTable.PSVersion.Major)"

'-- Get-RutasEsperadas() con filas planas (la construcción que falló en la ejecución real)'
$e0 = Get-RutasEsperadas $f0; $e1 = Get-RutasEsperadas $f1; $eN = Get-RutasEsperadas $fN
V '0 filas: sin rutas' ($e0.Count -eq 0)
V '1 fila: una ruta, 1 tipo, tiempo 4 h' ($e1.Count -eq 1 -and $e1['MOQ001-AQP001'].tipos.Count -eq 1 -and $e1['MOQ001-AQP001'].horas -eq 4)
V 'N filas: 2 rutas; PAQUETE toma la fila vigente más reciente (idRuta 2, no la vieja de 9 h); tiempo máximo 4' ($eN.Count -eq 2 -and $eN['MOQ001-AQP001'].tipos['PAQUETE'].idRuta -eq 2 -and $eN['MOQ001-AQP001'].horas -eq 4)
V 'misma fecha: gana el idRuta mayor (ILO001-MOQ001 SOBRE -> 5, 3 h)' ($eN['ILO001-MOQ001'].tipos['SOBRE'].idRuta -eq 5 -and $eN['ILO001-MOQ001'].horas -eq 3)
V 'una ruta con 2 tipos (SOBRE y PAQUETE) los conserva' (@($eN['MOQ001-AQP001'].tipos.Keys | Sort-Object).Count -eq 2)
"Resultado: $ok correctas, $mal con fallo."
if ($mal -gt 0) { exit 1 }
exit 0