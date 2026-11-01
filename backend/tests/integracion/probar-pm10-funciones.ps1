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
$id = @{ Sucursal = @(1, 2); Telefono_Sucursal = @(); Ruta = @(1); Vehiculo = @(1); Persona = @(1, 2, 3); Usuario = @(1, 2); Viaje = @(); Envio_Viaje = @() }   # varias tablas pueden estar inicialmente vacías
$valido = @{ idsAntes = $id; sucursales = @('PMX901'); vehiculos = @('PM1-901'); documentos = @('90000101', '20000000107'); usuarios = @('pm10_admin'); viajes = @('VJ2026110101') }
V 'un manifiesto bien formado no tiene problemas' ((Test-ManifiestoPm10 $valido).Count -eq 0)
foreach ($caso in @(@('sucursales', 'MOQ001'), @('vehiculos', 'B7K-210'), @('documentos', '71889345'), @('usuarios', 'lflores'), @('viajes', 'VJ12'))) {
    $m = @{} + $valido; $m[$caso[0]] = @($caso[1])
    V "rechaza un valor ORIGINAL en $($caso[0]) ($($caso[1])): el PHP nunca podría borrar datos originales" ((Test-ManifiestoPm10 $m).Count -ge 1)
}
$m = @{} + $valido; $m.idsAntes = @{ Sucursal = @(1) }
V 'rechaza un manifiesto sin la lista de ids iniciales de las demás tablas' ((Test-ManifiestoPm10 $m).Count -ge 1)
$m = @{} + $valido; $m.idsAntes = Get-ConCambios $id @{ Persona = @(1, 1) }
V 'rechaza ids iniciales repetidos' ((Test-ManifiestoPm10 $m).Count -ge 1)
$m = @{} + $valido; $m.idsAntes = Get-ConCambios $id @{ Viaje = @('uno') }
V 'rechaza ids iniciales que no son enteros' ((Test-ManifiestoPm10 $m).Count -ge 1)
V 'acepta TABLAS INICIALMENTE VACÍAS (lista vacía): la ausencia de la lista sí se rechaza, la lista vacía no' ((Test-ManifiestoPm10 $valido).Count -eq 0 -and $valido.idsAntes.Viaje.Count -eq 0)

'-- clave duplicada al construir una variante de la ruta (el fallo de la línea «origen = destino»)'
$ruta = @{ origen = 'PMX901'; destino = 'PMX902'; tipoEnvio = 'SOBRE'; precioBase = '8,00'; precioPorKm = '0.005'; distanciaKm = '100'; tiempoHoras = 2 }
$fallaConSuma = $false; try { $null = ($ruta + @{ destino = 'PMX901' }) } catch { $fallaConSuma = $true }
V 'demostración: sumar dos tablas hash con la clave «destino» repetida LANZA una excepción (así fallaba antes)' $fallaConSuma
$variante = Get-ConCambios $ruta @{ destino = 'PMX901' }
V 'Get-ConCambios sustituye «destino» sin lanzar y deja el resto igual' ($variante.destino -eq 'PMX901' -and $variante.origen -eq 'PMX901' -and $variante.tipoEnvio -eq 'SOBRE' -and $variante.Count -eq $ruta.Count)
V 'la tabla original NO se modifica (copia independiente)' ($ruta.destino -eq 'PMX902')
V 'añade claves nuevas y combina varias a la vez' ((Get-ConCambios $ruta @{ destino = 'X'; extra = 1 }).extra -eq 1 -and (Get-ConCambios $ruta @{ destino = 'X'; extra = 1 }).Count -eq ($ruta.Count + 1))

'-- 500 genéricos vs interbloqueos reales (evidencia en el registro de la aplicación)'
function Resp($estado, $codigo, $puerto) { [pscustomobject]@{ Puerto = $puerto; Estado = $estado; Codigo = $codigo } }
$c = Get-Clasificacion500 @((Resp 200 '' '8000'), (Resp 500 'error_interno' '8001')) @('[2026-11-01 10:00:00] ERROR Excepción: SQLSTATE[40001]: Transaction (Process ID 55) was deadlocked on lock resources with another process and has been chosen as the deadlock victim. 1205')
V 'un 500 con «deadlock / 1205» en el registro es un interbloqueo REAL' ($c.Interbloqueos -eq 1 -and $c.Genericos -eq 0 -and $c.Total500 -eq 1)
$c = Get-Clasificacion500 @((Resp 200 '' '8000'), (Resp 500 'error_interno' '8001')) @('[2026-11-01 10:00:00] ERROR Call to undefined method')
V 'un 500 SIN esa evidencia es genérico (fallo)' ($c.Interbloqueos -eq 0 -and $c.Genericos -eq 1)
$c = Get-Clasificacion500 @((Resp 500 'error_interno' '8000'), (Resp 500 'error_interno' '8001')) @('deadlock victim 1205')
V 'con dos 500 y una sola evidencia: uno es interbloqueo y el otro genérico' ($c.Interbloqueos -eq 1 -and $c.Genericos -eq 1)
$c = Get-Clasificacion500 @((Resp 200 '' '8000'), (Resp 409 'x' '8001')) @()
V 'sin ningún 500 no hay nada que clasificar (y el registro vacío no rompe)' ($c.Total500 -eq 0 -and $c.Interbloqueos -eq 0 -and $c.Genericos -eq 0)
$c = Get-Clasificacion500 @((Resp 200 '' '8000')) @('error 1205 antiguo')
V 'una evidencia sin ningún 500 no se cuenta (no puede haber más interbloqueos que 500)' ($c.Interbloqueos -eq 0)

'-- carrera de contraseñas: el 401 legítimo, solo cuando se comprueba quién confirmó'
$ok1 = Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 409 'contrasena_modificada' '8001')) '8000' $true $false $false
V '200 + 409 contrasena_modificada, sirve solo la del ganador (A): válido' $ok1.Valido
V '200 + 422 contrasena_incorrecta, gana B y solo sirve la de B: válido' (Get-ResultadoCarreraContrasena @((Resp 422 'contrasena_incorrecta' '8000'), (Resp 200 '' '8001')) '8000' $false $true $false).Valido
V '200 + 401 sesion_invalida (RequiereSesion ya vio el hash nuevo) con la contraseña del ganador funcionando: válido' (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 401 'sesion_invalida' '8001')) '8000' $true $false $false).Valido
V '200 + 401 sesion_invalida pero sirven las DOS contraseñas: inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 401 'sesion_invalida' '8001')) '8000' $true $true $false).Valido)
V '200 + 401 sesion_invalida pero NO sirve ninguna: inválido (un 401 no prueba que algo confirmó)' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 401 'sesion_invalida' '8001')) '8000' $false $false $false).Valido)
V '200 + 401 sesion_invalida pero solo sirve la del PERDEDOR: inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 401 'sesion_invalida' '8001')) '8000' $false $true $false).Valido)
V 'un 401 por OTRO motivo (no_autenticado) no se acepta' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 401 'no_autenticado' '8001')) '8000' $true $false $false).Valido)
V 'dos 401 (nadie confirmó) es inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 401 'sesion_invalida' '8000'), (Resp 401 'sesion_invalida' '8001')) '8000' $false $false $false).Valido)
V 'dos 200 (ambas confirmaron: la carrera no se cerró) es inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 200 '' '8001')) '8000' $true $false $false).Valido)
V '200 + 500 GENÉRICO es inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 500 'error_interno' '8001')) '8000' $true $false $false).Valido)
V '200 + 500 que ES un interbloqueo comprobado y solo sirve la del ganador: válido' (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000'), (Resp 500 'error_interno' '8001')) '8000' $true $false $true).Valido
V 'una sola respuesta (falta una) es inválido' (-not (Get-ResultadoCarreraContrasena @((Resp 200 '' '8000')) '8000' $true $false $false).Valido)

"Resultado: $ok correctas, $mal con fallo."
if ($mal -gt 0) { exit 1 }
exit 0