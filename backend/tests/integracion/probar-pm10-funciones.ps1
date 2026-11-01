<# Prueba SIN base de datos ni red de las reglas puras del validador pm10. Uso: powershell -File backend\tests\integracion\probar-pm10-funciones.ps1 #>
$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\pm10-funciones.ps1"
. "$PSScriptRoot\pm10-aislamiento.ps1"
$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
# Verdadero si el bloque lanza una excepción (en PowerShell 5.1 `try` no es una expresión).
function Lanza([scriptblock]$bloque) { try { $null = & $bloque; $false } catch { $true } }
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
$valido = @{ baseAislada = 'JATSA_pm10_aislada_20261101_1200'; idsAntes = $id; sucursales = @('PMX901'); vehiculos = @('PM1-901'); documentos = @('90000101', '20000000107'); usuarios = @('pm10_admin'); viajes = @('VJ2026110101') }
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

'-- trabajos paralelos: finalización confirmada y una respuesta por tarea (sin Start-Job, sin red)'
function Info($id, $estado, $respuestas, $falla = $null) { [pscustomobject]@{ Id = $id; Estado = $estado; Respuestas = $respuestas; Error = $falla } }
function Http($estado, $codigo = '') { [pscustomobject]@{ Puerto = '8000'; Estado = $estado; Codigo = $codigo; Ruta = '/x' } }
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 201))), (Info 2 'Completed' @((Http 409 'sucursal_con_viajes')))) 2
V 'dos trabajos Completed con una respuesta cada uno: confirmado' ($ev.Confirmado -and $ev.Motivo -eq '')
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 201))), (Info 2 'Running' @())) 2
V 'un trabajo sigue Running tras el timeout: NO confirmado (y se identifica)' ((-not $ev.Confirmado) -and $ev.Pendientes -eq 1 -and $ev.Motivo -match 'siguen activos' -and $ev.Motivo -match 'ids: 2')
V 'un trabajo Blocked o NotStarted también cuenta como pendiente' ((Get-EvaluacionTrabajos @((Info 1 'Blocked' @()), (Info 2 'NotStarted' @())) 2).Pendientes -eq 2)
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200))), (Info 2 'Completed' @())) 2
V 'un trabajo Completed SIN respuesta: NO confirmado' ((-not $ev.Confirmado) -and $ev.SinRespuesta -eq 1)
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200), (Http 200))), (Info 2 'Completed' @((Http 200)))) 2
V 'un trabajo con DOS respuestas (anomalía) no se acepta' ((-not $ev.Confirmado) -and $ev.SinRespuesta -eq 1)
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @([pscustomobject]@{ Estado = $null })), (Info 2 'Completed' @((Http 200)))) 2
V 'una respuesta sin estado HTTP (null) no cuenta como respuesta' (-not $ev.Confirmado)
V 'una respuesta con estado no numérico no cuenta' (-not (Get-EvaluacionTrabajos @((Info 1 'Completed' @([pscustomobject]@{ Estado = 'abc' })), (Info 2 'Completed' @((Http 200)))) 2).Confirmado)
$ev = Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200))), (Info 2 'Failed' @() 'No se pudo conectar con el servidor remoto')) 2
V 'un trabajo Failed: NO confirmado y el error se captura en el motivo' ((-not $ev.Confirmado) -and $ev.Fallidos -eq 1 -and $ev.Motivo -match 'No se pudo conectar')
V 'un trabajo Completed pero con un error registrado también falla' ((Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200)) 'se produjo un error'), (Info 2 'Completed' @((Http 200)))) 2).Fallidos -eq 1)
V 'un trabajo Stopped (detenido por el timeout) no se acepta' ((Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200))), (Info 2 'Stopped' @())) 2).Fallidos -eq 1)
V 'faltan trabajos (se lanzaron 2 y solo hay 1): NO confirmado' ((Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200)))) 2).Motivo -match 'se esperaban 2 trabajos y hay 1')
V 'sobran trabajos (hay 3 y se esperaban 2): NO confirmado' (-not (Get-EvaluacionTrabajos @((Info 1 'Completed' @((Http 200))), (Info 2 'Completed' @((Http 200))), (Info 3 'Completed' @((Http 200)))) 2).Confirmado)
V 'ninguna información de trabajos: NO confirmado' (-not (Get-EvaluacionTrabajos @() 2).Confirmado)
V 'los problemas se acumulan en un mismo motivo (pendiente + fallido)' ((Get-EvaluacionTrabajos @((Info 1 'Running' @()), (Info 2 'Failed' @() 'x')) 2).Motivo -match 'siguen activos.*fallaron')

'-- decisión de limpieza automática: ante cualquier duda, NO'
$d = Get-DecisionLimpieza @() @() @()
V 'sin incidencias, sin procesos PHP vivos y sin trabajos vivos: se permite' ($d.Permitida -and $d.Motivos.Count -eq 0)
$d = Get-DecisionLimpieza @('1 trabajo(s) siguen activos tras el límite de espera') @() @()
V 'un timeout no confirmado en una carrera DETIENE la limpieza automática y lo reporta' ((-not $d.Permitida) -and $d.Motivos[0] -match 'incidencia en trabajos paralelos')
$d = Get-DecisionLimpieza @() @(4321) @()
V 'un proceso PHP de prueba que no terminó impide la limpieza (podría estar atendiendo una petición)' ((-not $d.Permitida) -and $d.Motivos[0] -match 'proceso\(s\) PHP' -and $d.Motivos[0] -match '4321')
$d = Get-DecisionLimpieza @() @() @(7, 8)
V 'un trabajo de PowerShell todavía vivo impide la limpieza' ((-not $d.Permitida) -and $d.Motivos[0] -match '2 trabajo')
$d = Get-DecisionLimpieza @('a', 'b') @(1) @(2)
V 'varias causas se reportan todas' ((-not $d.Permitida) -and $d.Motivos.Count -eq 4)
V 'valores nulos o vacíos en las listas no cuentan como incidencias' ((Get-DecisionLimpieza @($null, '') @($null) @($null)).Permitida)
'-- manifiesto: la base aislada es obligatoria'
$m = @{} + $valido; $m.baseAislada = 'JATSA_Encomiendas'
V 'rechaza un manifiesto cuya base es la habitual' ((Test-ManifiestoPm10 $m).Count -ge 1)
$m = @{} + $valido; $m.baseAislada = ''
V 'rechaza un manifiesto sin base aislada' ((Test-ManifiestoPm10 $m).Count -ge 1)
$m = @{} + $valido; $m.baseAislada = 'JATSA_pm10_aislada_20261101_1200_Encomiendas'
V 'rechaza un nombre que se parezca al patrón pero contenga «Encomiendas»' ((Test-ManifiestoPm10 $m).Count -ge 1)

'-- nombre de la base aislada'
V 'acepta JATSA_pm10_aislada_20261101_1200' (Test-NombreBdAislada 'JATSA_pm10_aislada_20261101_1200')
foreach ($malo in @('JATSA_Encomiendas', 'jatsa_encomiendas', 'JATSA_pm10_aislada', 'JATSA_pm10_aislada_2026110_1200', 'JATSA_pm10_aislada_20261101_12', 'xJATSA_pm10_aislada_20261101_1200', 'JATSA_pm10_aislada_20261101_1200 ', 'JATSA_pm10_aislada_20261101_1200;DROP', 'jatsa_pm10_aislada_20261101_1200', '', 'master')) {
    V "rechaza «$malo»" (-not (Test-NombreBdAislada $malo))
}

'-- destino de cada conexión: DB_NAME() y el login'
$base = 'JATSA_pm10_aislada_20261101_1200'
V 'DB_NAME() y login aislados: válido' (Test-DestinoAislado $base 'jatsa_pm10_aislado' $base).Valido
$d = Test-DestinoAislado 'JATSA_Encomiendas' 'jatsa_pm10_aislado' $base
V 'DB_NAME() = JATSA_Encomiendas: se rechaza INEQUÍVOCAMENTE (aunque el login sea el aislado)' ((-not $d.Valido) -and $d.Motivo -match 'BASE HABITUAL')
V 'DB_NAME() = jatsa_encomiendas en minúsculas también' (-not (Test-DestinoAislado 'jatsa_encomiendas' 'jatsa_pm10_aislado' $base).Valido)
V 'otra base distinta de la esperada' (-not (Test-DestinoAislado 'JATSA_pm10_aislada_20261101_1300' 'jatsa_pm10_aislado' $base).Valido)
V 'master' (-not (Test-DestinoAislado 'master' 'jatsa_pm10_aislado' $base).Valido)
V 'login distinto (sa o el administrador)' (-not (Test-DestinoAislado $base 'sa' $base).Valido)
V 'DB_NAME() vacío o nulo' ((-not (Test-DestinoAislado '' 'jatsa_pm10_aislado' $base).Valido) -and (-not (Test-DestinoAislado $null 'jatsa_pm10_aislado' $base).Valido))
V 'si el nombre ESPERADO no es válido, nada es válido' (-not (Test-DestinoAislado 'JATSA_Encomiendas' 'jatsa_pm10_aislado' 'JATSA_Encomiendas').Valido)

'-- sondas de los 4 servidores HTTP'
function Sonda($puerto, $estado = 200, $bd = $base, $login = 'jatsa_pm10_aislado') { [pscustomobject]@{ Puerto = $puerto; Estado = $estado; Bd = $bd; Login = $login } }
$puertos = @('8000', '8001', '8002', '8003')
V 'los 4 servidores en la base aislada: válido' (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002'), (Sonda '8003')) $puertos $base).Valido
$r = Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002' 200 'JATSA_Encomiendas'), (Sonda '8003')) $puertos $base
V 'UN servidor conectado a la base habitual invalida todo y se identifica' ((-not $r.Valido) -and $r.Motivo -match 'puerto 8002' -and $r.Motivo -match 'BASE HABITUAL')
$r = Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002')) $puertos $base
V 'falta la respuesta de un puerto' ((-not $r.Valido) -and $r.Motivo -match 'puerto 8003 no respondió')
V 'un servidor que responde HTTP 500 (sin conexión) invalida' (-not (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002'), (Sonda '8003' 500 '' '')) $puertos $base).Valido)
V 'un servidor sin respuesta (estado 0) invalida' (-not (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002'), (Sonda '8003' 0 '' '')) $puertos $base).Valido)
V 'un puerto repetido o inesperado invalida' ((-not (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8000'), (Sonda '8001'), (Sonda '8002'), (Sonda '8003')) $puertos $base).Valido) -and (-not (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002'), (Sonda '8003'), (Sonda '9999')) $puertos $base).Valido))
V 'un login distinto en un servidor invalida' (-not (Get-ResultadoSondas @((Sonda '8000'), (Sonda '8001'), (Sonda '8002' 200 $base 'sa'), (Sonda '8003')) $puertos $base).Valido)

'-- procesos PHP: deben salir de la copia, nunca del backend habitual'
$copia = 'C:\Users\Usuario\AppData\Local\Temp\jatsa_pm10_20261101_120000\backend'
$habitual = 'C:\Jatsa\backend'
V 'php -S … -t <copia>\public: válido' (Test-LineaDeComandosEnCopia "C:\php\php.exe -S localhost:8000 -t `"$copia\public`"" $copia @($habitual))
V 'insensible a mayúsculas y a la dirección de las barras' (Test-LineaDeComandosEnCopia ('C:/php/php.exe -S localhost:8000 -t ' + $copia.ToUpper().Replace('\', '/') + '/public') $copia @($habitual))
V 'php -S … -t C:\Jatsa\backend\public (el habitual): INVÁLIDO' (-not (Test-LineaDeComandosEnCopia 'C:\php\php.exe -S localhost:8000 -t C:\Jatsa\backend\public' $copia @($habitual)))
V 'una línea que menciona la copia Y el backend habitual: INVÁLIDA' (-not (Test-LineaDeComandosEnCopia "C:\php\php.exe -S localhost:8000 -t $copia\public -c C:\Jatsa\backend\php.ini" $copia @($habitual)))
V 'una línea vacía o sin la copia no es válida' ((-not (Test-LineaDeComandosEnCopia '' $copia @($habitual))) -and (-not (Test-LineaDeComandosEnCopia 'C:\php\php.exe -v' $copia @($habitual))))

'-- .env independiente de la copia'
$clave = 'Aislada#Segura2026ab'
$env1 = New-ContenidoEnvAislado 'localhost' '1433' $base 'jatsa_pm10_aislado' $clave
$ev = Test-EnvDeCopia $env1 $base
V 'el .env generado es válido y trae TODAS las claves de conexión' ($ev.Valido -and $env1 -match 'DB_HOST=localhost' -and $env1 -match "DB_NAME=$base" -and $env1 -match 'DB_USER=jatsa_pm10_aislado' -and $env1 -match 'DB_PORT=1433')
V 'el .env no menciona la base habitual' ($env1 -notmatch '(?i)encomiendas')
V 'se negó a generar un .env para la base habitual' ((Lanza { New-ContenidoEnvAislado 'localhost' '' 'JATSA_Encomiendas' 'jatsa_pm10_aislado' $clave }))
V 'se negó a generar un .env con otro login (p. ej. sa)' ((Lanza { New-ContenidoEnvAislado 'localhost' '' $base 'sa' $clave }))
V 'se negó con una contraseña vacía o corta' (((Lanza { New-ContenidoEnvAislado 'localhost' '' $base 'jatsa_pm10_aislado' '' })) -and ((Lanza { New-ContenidoEnvAislado 'localhost' '' $base 'jatsa_pm10_aislado' 'corta' })))
V 'se negó con saltos de línea en un valor (inyección de claves)' ((Lanza { New-ContenidoEnvAislado 'localhost' '' $base 'jatsa_pm10_aislado' "$clave`nDB_NAME=JATSA_Encomiendas" }))
V 'puerto vacío sigue definiendo DB_PORT (no cae a variables del proceso)' ((Test-EnvDeCopia (New-ContenidoEnvAislado 'localhost' '' $base 'jatsa_pm10_aislado' $clave) $base).Valido)
V 'Test-EnvDeCopia rechaza un .env al que le falta DB_NAME (Env usaría la variable del proceso)' (-not (Test-EnvDeCopia (($env1 -split "`n" | Where-Object { $_ -notmatch '^DB_NAME=' }) -join "`n") $base).Valido)
V 'Test-EnvDeCopia rechaza un DB_NAME repetido' (-not (Test-EnvDeCopia ($env1 + "`nDB_NAME=$base") $base).Valido)
V 'Test-EnvDeCopia rechaza DB_NAME = JATSA_Encomiendas' (-not (Test-EnvDeCopia ($env1.Replace("DB_NAME=$base", 'DB_NAME=JATSA_Encomiendas')) $base).Valido)
V 'Test-EnvDeCopia rechaza otro DB_USER y una contraseña vacía' ((-not (Test-EnvDeCopia ($env1.Replace('DB_USER=jatsa_pm10_aislado', 'DB_USER=sa')) $base).Valido) -and (-not (Test-EnvDeCopia ($env1.Replace("DB_PASSWORD=$clave", 'DB_PASSWORD=')) $base).Valido))

'-- la copia debe ser idéntica al repositorio (hashes)'
V 'inventarios iguales: sin diferencias' ((Get-DiferenciasHashes @{ 'src\a.php' = 'AA'; 'config\b.php' = 'BB' } @{ 'src\a.php' = 'AA'; 'config\b.php' = 'BB' }).Count -eq 0)
$dif = Get-DiferenciasHashes @{ 'src\a.php' = 'AA'; 'config\b.php' = 'BB' } @{ 'src\a.php' = 'XX'; 'public\sonda_db.php' = 'CC' }
V 'detecta archivo distinto, archivo que falta y archivo que sobra (p. ej. una sonda olvidada)' (($dif -contains 'difiere: src\a.php') -and ($dif -contains 'falta en la copia: config\b.php') -and ($dif -contains 'sobra en la copia: public\sonda_db.php'))

'-- estructura derivada del script original (el original NO se modifica ni se ejecuta)'
$origSintetico = "--1. Crear Base de datos`nIF DB_ID('JATSA_Encomiendas') IS NULL CREATE DATABASE JATSA_Encomiendas;`nGO`n`nUSE JATSA_Encomiendas;`nGO`n`n-- tablas`n" + (($script:TABLAS_ESPERADAS | ForEach-Object { "CREATE TABLE $_ (`n    id INT IDENTITY(1,1) NOT NULL`n);`nGO`n" }) -join "`n") + "`n--3. Insertar Datos de Prueba`nINSERT INTO Persona (tipoDocumento) VALUES ('DNI');`nINSERT INTO Sucursal (codigoSucursal) VALUES ('AQP001');"
$der = ConvertTo-DdlDerivado $origSintetico $base
V 'sintético: la derivada empieza con USE de la base aislada' ($der.StartsWith("USE [$base];`nGO"))
V 'sintético: la derivada es válida (12 tablas, sin INSERT ni JATSA_Encomiendas, un USE)' ((Test-DdlDerivado $der $base).Count -eq 0)
V 'sintético: no queda nada del encabezado original ni de los datos' (($der -notmatch 'JATSA_Encomiendas') -and ($der -notmatch 'INSERT') -and ($der -notmatch 'CREATE DATABASE'))
V 'se negó a derivar hacia la base habitual' ((Lanza { ConvertTo-DdlDerivado $origSintetico 'JATSA_Encomiendas' }))
V 'se negó si el original no tiene «USE JATSA_Encomiendas»' ((Lanza { ConvertTo-DdlDerivado "CREATE TABLE Persona (id INT);`nGO`nINSERT INTO Persona VALUES (1);" $base }))
V 'se negó si el original no tiene INSERT (no se sabría dónde termina la estructura)' ((Lanza { ConvertTo-DdlDerivado "USE JATSA_Encomiendas;`nGO`nCREATE TABLE Persona (id INT);`nGO" $base }))
V 'Test-DdlDerivado detecta un INSERT escondido' ((Test-DdlDerivado ($der + "`nINSERT INTO Persona (tipoDocumento) VALUES ('DNI');`nGO") $base) -match 'INSERT')
V 'Test-DdlDerivado detecta JATSA_Encomiendas, aunque sea en un comentario' ((Test-DdlDerivado ($der + "`n-- viene de JATSA_Encomiendas") $base) -match 'JATSA_Encomiendas')
V 'Test-DdlDerivado detecta CREATE DATABASE, DROP y un USE a otra base' (((Test-DdlDerivado ($der + "`nCREATE DATABASE X`nGO") $base) -match 'bases, logins') -and ((Test-DdlDerivado ($der + "`nDROP TABLE Persona`nGO") $base) -match 'no estructurales') -and ((Test-DdlDerivado $der.Replace("USE [$base];", 'USE [master];') $base) -match 'exactamente un USE'))
V 'Test-DdlDerivado exige las 12 tablas (falta una)' ((Test-DdlDerivado ($der -replace 'CREATE TABLE Pago', 'CREATE TABLE Otra') $base) -match 'faltan las tablas: Pago')
V 'Split-LotesGo separa por GO, ignora lotes vacíos y no parte «GOTO» ni palabras que empiezan por GO' ((@(Split-LotesGo "A`nGO`n`nGO`nB GO`nGOTO x`nGO`nC").Count -eq 3))

'-- contra el script original REAL (solo lectura del archivo; no se ejecuta nada)'
$ruta = Join-Path $PSScriptRoot '..\..\..\docs\JATSA_Envios-SQLQuery1 (1).sql'
if (Test-Path -LiteralPath $ruta) {
    $hash0 = (Get-FileHash -LiteralPath $ruta -Algorithm SHA256).Hash
    $real = [IO.File]::ReadAllText($ruta, [Text.Encoding]::UTF8)
    $derReal = ConvertTo-DdlDerivado $real $base
    $problemasReal = @(Test-DdlDerivado $derReal $base)
    V 'la estructura derivada del script REAL es válida (12 CREATE TABLE, sin INSERT ni JATSA_Encomiendas)' ($problemasReal.Count -eq 0) ($problemasReal -join '; ')
    V 'el original SÍ crea y usa JATSA_Encomiendas y trae INSERT (por eso nunca se ejecuta tal cual)' (($real -match 'CREATE DATABASE JATSA_Encomiendas') -and ($real -match '(?m)^\s*USE JATSA_Encomiendas') -and ($real -match '(?m)^\s*INSERT INTO'))
    V 'las 12 tablas del real son exactamente las esperadas' ((@([regex]::Matches($derReal, '(?im)^\s*CREATE\s+TABLE\s+(\w+)') | ForEach-Object { $_.Groups[1].Value } | Sort-Object) -join ',') -eq (($script:TABLAS_ESPERADAS | Sort-Object) -join ','))
    V 'el archivo original no cambió al derivarlo' ((Get-FileHash -LiteralPath $ruta -Algorithm SHA256).Hash -eq $hash0)
} else { 'ADVERTENCIA: no se encontró el script original; se omiten las 4 comprobaciones contra el archivo real.' }

'-- sesiones y transacciones de SQL Server antes de limpiar'
function Ses($id, $estado, $tx, $sol) { [pscustomobject]@{ session_id = $id; status = $estado; transacciones = $tx; solicitudes = $sol } }
V 'con permiso y SIN sesiones del login aislado: segura' (Get-EvaluacionSesionesSql 1 @()).Segura
$e = Get-EvaluacionSesionesSql 1 @((Ses 61 'sleeping' 1 0))
V 'una sesión con una transacción abierta: NO segura y lo explica' ((-not $e.Segura) -and $e.Motivos[0] -match 'transacción')
$e = Get-EvaluacionSesionesSql 1 @((Ses 62 'running' 0 1))
V 'una sesión ejecutando una solicitud: NO segura' ((-not $e.Segura) -and $e.Motivos[0] -match 'solicitud')
$e = Get-EvaluacionSesionesSql 1 @((Ses 63 'sleeping' 0 0))
V 'una sesión inactiva que sigue conectada con todos los PHP terminados: NO segura (ante la duda, no limpiar)' ((-not $e.Segura) -and $e.Motivos[0] -match 'sigue conectada')
$e = Get-EvaluacionSesionesSql 0 @()
V 'SIN VIEW SERVER STATE la ausencia de filas no prueba nada: NO segura' ((-not $e.Segura) -and $e.Motivos[0] -match 'no se puede verificar')
V 'el permiso ausente o nulo también se trata como «no verificable»' ((-not (Get-EvaluacionSesionesSql $null @()).Segura) -and (-not (Get-EvaluacionSesionesSql '' @()).Segura))
V 'varias sesiones se reportan todas' ((Get-EvaluacionSesionesSql 1 @((Ses 61 'sleeping' 1 0), (Ses 62 'running' 0 1))).Motivos.Count -eq 2)
$d = Get-DecisionLimpieza @() @() @() @('sesión 61 [sleeping] con 1 transacción(es) abierta(s)')
V 'la decisión de limpieza incorpora los problemas de SQL Server: una transacción pendiente la impide' ((-not $d.Permitida) -and $d.Motivos[0] -match '^SQL Server:')
V 'sin problemas de SQL, ni procesos, ni trabajos, ni incidencias: se permite' ((Get-DecisionLimpieza @() @() @() @()).Permitida)
V 'compatibilidad: la llamada con tres argumentos (sin SQL) sigue funcionando' ((Get-DecisionLimpieza @() @() @()).Permitida)
"Resultado: $ok correctas, $mal con fallo."
if ($mal -gt 0) { exit 1 }
exit 0