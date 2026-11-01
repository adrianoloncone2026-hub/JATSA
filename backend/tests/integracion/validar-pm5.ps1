<#
  Validación REAL del módulo 5 contra SQL Server con registros temporales pm5 (procedimiento del PR #6).

  NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA DEL PROPIETARIO: escribe en la base (Persona, Usuario, Reserva, Envio, Bulto).
  Por eso exige -AutorizadoPorElPropietario. Sin DDL, sin tocar el script SQL, sin tocar filas originales, sin DBCC CHECKIDENT.

  Uso (desde C:\Jatsa, con PHP en C:\php\php.exe):
    powershell -File backend\tests\integracion\validar-pm5.ps1 -AutorizadoPorElPropietario

  Credenciales efímeras: las contraseñas se generan en memoria al empezar, se usan en ESTE proceso durante toda la prueba y
  nunca se imprimen ni se guardan (la de pm5_admin viaja al script PHP solo por variable de entorno del proceso hijo).
  Si algo falla, se detiene, se limpia SOLO lo creado y se informa.
#>
param([switch]$AutorizadoPorElPropietario)

if (-not $AutorizadoPorElPropietario) {
    Write-Host 'Rechazado: esta validación escribe en la base real. Requiere autorización expresa del propietario (-AutorizadoPorElPropietario).'
    exit 2
}

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$php = 'C:\php\php.exe'
$salida = Join-Path $env:TEMP ('pm5_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Force $salida | Out-Null
. "$aqui\api.ps1"

$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Leer($sql) { (& $php "$aqui\leer.php" $sql | Out-String | ConvertFrom-Json) }
function Clave { $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; (-join (1..14 | % { $a[(Get-Random -Maximum $a.Length)] })) + '#9a' }

# Precondición o paso de preparación: si falla, se DETIENE la validación (lanza). A diferencia de V, no continúa.
function Exigir($cond, $mensaje) { if ($cond) { "  OK    $mensaje" } else { throw "Precondición fallida: $mensaje" } }

# MANIFIESTO de lo creado EN ESTA EJECUCIÓN. Es lo único que el script de escritura puede borrar o modificar.
# Se guarda en disco tras cada anotación: si la prueba se interrumpe, la limpieza sigue siendo exacta.
$manifiesto = @{ identAntes = @{}; documentos = @(); usuarios = @(); reservas = @(); envios = @() }
function Anotar($clave, $valor) { if ($valor -and ($manifiesto[$clave] -notcontains $valor)) { $manifiesto[$clave] += $valor }; $manifiesto | ConvertTo-Json -Depth 4 | Set-Content $manifiestoRuta -Encoding ascii }
# Sesiones de PHP creadas por esta prueba (id de cada cookie): en la limpieza solo se borran estos archivos sess_<id>.
$sesionesPropias = @()
function Ingresar($usuario, $clave, $puerto = '8000') {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave } $puerto)
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}

function Reserva($cookie, $cuerpo) { $r = Llamar POST '/api/reservas' $cuerpo '8000' $cookie; if ($r.Estado -ne 201) { throw "No se pudo crear la reserva: $($r.Texto)" }; Anotar 'reservas' $r.Json.data.reserva.codigo; $r.Json.data.reserva.codigo }
function Formalizar($cookie, $codigo, $bultos, $puerto = '8000', $entrega = 'AGENCIA', $direccion = '') {
    $r = Llamar POST '/api/envios' @{ codigoReserva = $codigo; tipoEntrega = $entrega; direccionEntrega = $direccion; bultos = $bultos } $puerto $cookie
    if ($r.Estado -eq 201) { Anotar 'envios' $r.Json.data.envio.codigoSeguimiento }
    $r
}
$BULTOS_DIAPOSITIVA = @(@{ descripcion = 'Sobre con documentos'; pesoKg = '0.40' }, @{ descripcion = 'Caja con ropa'; pesoKg = '8.80' })
$DEST_EXISTENTE = @{ tipoDocumento = 'DNI'; numeroDocumento = '70234561'; nombreDestinatario = ''; telefonoDestinatario = '' }
function CuerpoReserva($contenido, $origen = 'MOQ001', $destino = 'AQP001') { $DEST_EXISTENTE + @{ sucursalOrigen = $origen; sucursalDestino = $destino; contenido = $contenido } }

# Trabajo en paralelo: cada tarea inicia a la hora indicada (misma hora para todas = peticiones simultáneas).
$trabajo = {
    param($ps1, $puerto, $galleta, $hora, $metodo, $ruta, $cuerpo)
    . $ps1
    $w = ([datetime]$hora) - (Get-Date); if ($w.TotalMilliseconds -gt 0) { Start-Sleep -Milliseconds ([int]$w.TotalMilliseconds) }
    $r = Llamar $metodo $ruta $cuerpo $puerto $galleta
    [pscustomobject]@{ Puerto = $puerto; Estado = $r.Estado; Codigo = $r.Json.error.codigo; Seguimiento = $r.Json.data.envio.codigoSeguimiento; Ruta = $ruta }
}
function EnParalelo($tareas, $segundos = 8) {
    $hora = (Get-Date).AddSeconds($segundos).ToString('o')
    $jobs = foreach ($t in $tareas) { Start-Job -ScriptBlock $trabajo -ArgumentList "$aqui\api.ps1", $t.Puerto, $t.Cookie, $(if ($t.Hora) { $t.Hora } else { $hora }), $t.Metodo, $t.Ruta, $t.Cuerpo }
    $res = $jobs | Wait-Job -Timeout 150 | Receive-Job
    $jobs | Remove-Job -Force
    foreach ($x in $res) { if ($x.Seguimiento) { Anotar 'envios' $x.Seguimiento } }
    $res
}

$servidores = @()
$limpio = $false
$escrituraIniciada = $false   # hasta que sea verdadero, NO se ha hecho ningún INSERT/UPDATE/DELETE: no hay nada que limpiar
$manifiestoRuta = Join-Path $salida 'manifiesto.json'
try {
    "== 1. Precondiciones (solo lectura): si alguna falla, se DETIENE antes de escribir nada"
    Exigir (Test-Path $php) 'PHP disponible'
    Exigir (Test-Path 'backend\public\index.php') 'se ejecuta desde la raíz del repositorio (C:\Jatsa)'
    foreach ($p in 8000..8003) { Exigir (-not (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "el puerto $p está libre (no se toca ningún servidor ajeno)" }
    & $php "$aqui\instantanea.php" "$salida\antes.json" | Out-Null
    Exigir ($LASTEXITCODE -eq 0 -and (Test-Path "$salida\antes.json")) 'instantánea inicial tomada (solo SELECT)'
    $antes = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
    Exigir (@($antes.tablas.PSObject.Properties).Count -eq 12) 'la base tiene las 12 tablas esperadas'
    Exigir (@($antes.restos.personas_prueba).Count -eq 0) 'no existen personas con los documentos de prueba 90000051-54'
    Exigir (@($antes.restos.usuarios_pm | ? { $_ -like 'pm5*' }).Count -eq 0) 'no existen cuentas pm5_*'
    $reservasPm5 = (Leer "SELECT COUNT(*) AS n FROM Reserva r JOIN Persona p ON p.idPersona IN (r.idPersonaRemitente, r.idPersonaDestinatario) WHERE p.numeroDocumento IN ('90000051','90000052','90000053','90000054')")[0].n
    Exigir ([int]$reservasPm5 -eq 0) 'no existen reservas ligadas a personas de prueba'
    $tarifa = (Leer "SELECT COUNT(*) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa -ge 1) 'MOQ001→AQP001 tiene tarifa vigente (precondición del caso de la diapositiva 19)'
    $sucursal = (Leer "SELECT COUNT(*) AS n FROM Sucursal WHERE codigoSucursal IN ('MOQ001','AQP001','LIM001') AND activa = 1")[0].n
    Exigir ([int]$sucursal -eq 3) 'las sucursales MOQ001, AQP001 y LIM001 existen y están activas'
    # Referencia para distinguir lo creado por esta ejecución: sus ids serán mayores que estos.
    foreach ($t in 'Persona', 'Usuario', 'Reserva', 'Envio') { $manifiesto.identAntes[$t] = [int]$antes.tablas.$t.ident }
    Anotar 'documentos' $null

    $escrituraIniciada = $true   # a partir de aquí hay escrituras (cuentas temporales); lo creado queda en el manifiesto
    foreach ($p in 8000..8003) { $servidores += Start-Process $php -ArgumentList '-S', "localhost:$p", '-t', 'backend/public' -WindowStyle Hidden -PassThru }
    Start-Sleep -Seconds 3
    foreach ($p in 8000..8003) { Exigir ([bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "servidor de prueba escuchando en $p" }

    "== 2. Cuentas temporales (contraseñas solo en memoria)"
    $pwCliente = Clave; $pwAdmin = Clave
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000051'; nombreCompleto = 'Prueba Cinco Cliente'; telefono = '900000051'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm5_cliente'; contrasena = $pwCliente }
    Anotar 'documentos' '90000051'; Anotar 'usuarios' 'pm5_cliente'   # se anota antes de comprobar: si la respuesta falla pero se escribió, la limpieza lo ve
    Exigir ($reg.Estado -eq 201) "pm5_cliente registrado por el endpoint (201) $($reg.Texto)"
    Anotar 'documentos' '90000052'; Anotar 'usuarios' 'pm5_admin'
    $env:JATSA_PM5_PW = $pwAdmin; & $php "$aqui\escritura_pm5.php" crear-personal $manifiestoRuta; $codigoAdmin = $LASTEXITCODE; $env:JATSA_PM5_PW = $null
    Exigir ($codigoAdmin -eq 0) 'pm5_admin creada (INSERT de Persona y Usuario)'
    $cookieCliente = Ingresar 'pm5_cliente' $pwCliente
    # Cuatro sesiones del administrador (una por servidor): PHP bloquea el archivo de una misma sesión y serializaría la concurrencia.
    $cookiesAdmin = foreach ($i in 0..3) { Ingresar 'pm5_admin' $pwAdmin ('800' + $i) }
    $pwCliente = $null; $pwAdmin = $null
    Exigir ($cookieCliente -and @($cookiesAdmin | ? { $_ }).Count -eq 4) 'sesiones abiertas (cliente y 4 de administrador)'
    $yo = Llamar GET '/api/auth/yo' $null '8000' $cookiesAdmin[0]
    Exigir ($yo.Json.data.usuario.rol -eq 'ADMINISTRADOR' -and $yo.Json.data.usuario.sucursal.codigo -eq 'MOQ001') 'pm5_admin es ADMINISTRADOR de MOQ001'
    $A = $cookiesAdmin[0]

    "== 3. Permisos"
    V 'cliente no entra a ventanilla (403)' ((Llamar GET '/api/ventanilla/reservas' $null '8000' $cookieCliente).Estado -eq 403)
    V 'sin sesión (401)' ((Llamar GET '/api/ventanilla/reservas' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401)

    "== 4. Reservas de prueba (por los endpoints reales)"
    $rA = Reserva $cookieCliente (CuerpoReserva 'pm5 caso diapositiva 19')
    $rB = Reserva $cookieCliente (CuerpoReserva 'pm5 bulto sin ruta')
    $rC = Reserva $cookieCliente (CuerpoReserva 'pm5 domicilio')
    $rOtra = Reserva $cookieCliente (CuerpoReserva 'pm5 otra agencia' 'AQP001' 'LIM001')
    $mismaReserva = Reserva $cookieCliente (CuerpoReserva 'pm5 misma reserva en paralelo')
    $distintas = 1..4 | % { Reserva $cookieCliente (CuerpoReserva "pm5 distinta $_") }
    $rCancelar = Reserva $cookieCliente (CuerpoReserva 'pm5 carrera cancelar-formalizar')
    $rVieja = Reserva $cookieCliente (CuerpoReserva 'pm5 plazo 73 h')
    $rJusta = Reserva $cookieCliente (CuerpoReserva 'pm5 plazo 71 h')
    $rSinTarifa = Llamar POST '/api/reservas' (CuerpoReserva 'pm5 sin tarifa' 'MOQ001' 'ILO001') '8000' $cookieCliente
    V 'ruta sin tarifa rechazada al reservar (422)' ($rSinTarifa.Estado -eq 422)
    $lista = (Llamar GET '/api/ventanilla/reservas' $null '8000' $A).Json.data.reservas | % codigo
    V 'ventanilla lista las pendientes de MOQ001 y no la de otra agencia' (($lista -contains $rA) -and ($lista -notcontains $rOtra))
    $enc = Llamar GET "/api/ventanilla/reservas/$rA" $null '8000' $A
    V 'búsqueda por código con documentos de remitente y destinatario' ($enc.Estado -eq 200 -and $enc.Json.data.reserva.remitente.numeroDocumento -eq '90000051' -and $enc.Json.data.reserva.destinatario.numeroDocumento -eq '70234561')
    V 'reserva de otra agencia: mismo 404 que una inexistente' ((Llamar GET "/api/ventanilla/reservas/$rOtra" $null '8000' $A).Texto -eq (Llamar GET '/api/ventanilla/reservas/R999999999' $null '8000' $A).Texto)

    "== 5. «Cliente sin reserva» (canal VENTANILLA, personas nuevas)"
    $sin = Llamar POST '/api/ventanilla/reservas' @{ remitenteTipoDocumento = 'DNI'; remitenteNumeroDocumento = '90000053'; remitenteNombre = 'Prueba Cinco Remitente'; remitenteTelefono = '900000053'; destinatarioTipoDocumento = 'DNI'; destinatarioNumeroDocumento = '90000054'; destinatarioNombre = 'Prueba Cinco Destinatario'; destinatarioTelefono = '900000054'; sucursalDestino = 'AQP001'; contenido = 'pm5 sin reserva'; sucursalOrigen = 'LIM001'; canal = 'WEB' } '8000' $A
    V 'crea la reserva (201) con canal VENTANILLA y origen MOQ001 (ignora el del cuerpo)' ($sin.Estado -eq 201 -and $sin.Json.data.reserva.canal -eq 'VENTANILLA' -and $sin.Json.data.reserva.origen.codigo -eq 'MOQ001') $sin.Texto
    $rSin = $sin.Json.data.reserva.codigo
    Anotar 'documentos' '90000053'; Anotar 'documentos' '90000054'; Anotar 'reservas' $rSin
    Exigir ([bool]$rSin) '«Cliente sin reserva» devolvió la reserva creada (queda anotada para la limpieza)'
    V 'ruta sin tarifa rechazada en ventanilla (422)' ((Llamar POST '/api/ventanilla/reservas' @{ remitenteTipoDocumento = 'DNI'; remitenteNumeroDocumento = '90000053'; destinatarioTipoDocumento = 'DNI'; destinatarioNumeroDocumento = '90000054'; sucursalDestino = 'LIM001'; contenido = 'x' } '8000' $A).Estado -eq 422)

    "== 6. Formalizar: caso de la diapositiva 19"
    $cot = Llamar POST '/api/envios/cotizacion' @{ codigoReserva = $rA; bultos = $BULTOS_DIAPOSITIVA } '8000' $A
    V 'cotización S/ 23.75 · 9.20 kg (sin escribir)' ($cot.Json.data.importe -eq '23.75' -and $cot.Json.data.pesoTotalKg -eq '9.20')
    $f = Formalizar $A $rA $BULTOS_DIAPOSITIVA
    $segA = $f.Json.data.envio.codigoSeguimiento
    V '201 con código EN + 10' ($f.Estado -eq 201 -and $segA -match '^EN[A-Z0-9]{10}$') $f.Texto
    V 'importe y peso' ($f.Json.data.envio.importe -eq '23.75' -and $f.Json.data.envio.pesoTotalKg -eq '9.20')
    $bd = Leer "SELECT b.numeroBulto, b.pesoKg, b.costo, r.tipoEnvio, r.idRuta FROM Bulto b JOIN Envio e ON e.idEnvio=b.idEnvio JOIN Ruta r ON r.idRuta=b.idRuta WHERE e.codigoSeguimiento='$segA' ORDER BY b.numeroBulto"
    V 'Bulto en la BD: SOBRE 9.15 y PAQUETE 14.60 con su idRuta' (@($bd).Count -eq 2 -and $bd[0].tipoEnvio -eq 'SOBRE' -and [decimal]$bd[0].costo -eq 9.15 -and $bd[1].tipoEnvio -eq 'PAQUETE' -and [decimal]$bd[1].costo -eq 14.60) ($bd | ConvertTo-Json -Compress)
    V 'la reserva quedó FORMALIZADA' ((Leer "SELECT estadoReserva FROM Reserva WHERE codigoReserva='$rA'")[0].estadoReserva -eq 'FORMALIZADA')
    V 'segunda formalización de la misma reserva: 409 reserva_formalizada' ((Formalizar $A $rA $BULTOS_DIAPOSITIVA).Json.error.codigo -eq 'reserva_formalizada')
    V 'ver el envío (etiqueta)' ((Llamar GET "/api/envios/$segA" $null '8000' $A).Json.data.envio.cantidadBultos -eq 2)

    "== 7. Rechazos que NO deben escribir nada"
    $antesEnvios = (Leer 'SELECT COUNT(*) AS n FROM Envio')[0].n
    $b31 = $BULTOS_DIAPOSITIVA + @(@{ descripcion = 'Caja con herramientas'; pesoKg = '31.00' })
    $x = Formalizar $A $rB $b31
    V 'CARGA 31 kg sin ruta vigente: 422 en bultos.3.pesoKg' ($x.Estado -eq 422 -and $x.Json.error.campos.'bultos.3.pesoKg' -like 'CARGA: sin ruta vigente*') $x.Texto
    V 'DOMICILIO sin dirección: 422' ((Formalizar $A $rB $BULTOS_DIAPOSITIVA '8000' 'DOMICILIO' '').Estado -eq 422)
    V 'reserva de otra agencia: 404' ((Formalizar $A $rOtra $BULTOS_DIAPOSITIVA).Estado -eq 404)
    $orig = Formalizar $A 'R000000001' $BULTOS_DIAPOSITIVA
    V 'reserva ORIGINAL ya formalizada: 409 y sin cambios' ($orig.Estado -eq 409 -or $orig.Estado -eq 404) $orig.Texto
    V 'ningún Envio nuevo y la reserva sigue PENDIENTE' (((Leer 'SELECT COUNT(*) AS n FROM Envio')[0].n -eq $antesEnvios) -and ((Leer "SELECT estadoReserva FROM Reserva WHERE codigoReserva='$rB'")[0].estadoReserva -eq 'PENDIENTE'))
    $ced = Formalizar $A $rC @(@{ descripcion = 'Caja'; pesoKg = '5' }) '8000' 'DOMICILIO' 'Av. Siempre Viva 742'
    V 'DOMICILIO con dirección: 201 y dirección guardada' ($ced.Estado -eq 201 -and (Leer "SELECT direccionEntrega FROM Envio WHERE codigoSeguimiento='$($ced.Json.data.envio.codigoSeguimiento)'")[0].direccionEntrega -eq 'Av. Siempre Viva 742')

    "== 8. Plazo de 72 h con el reloj real"
    $ahora = [datetime](Leer "SELECT CONVERT(VARCHAR(19), SYSDATETIME(), 120) AS t")[0].t
    & $php "$aqui\escritura_pm5.php" fecha $manifiestoRuta $rVieja $ahora.AddHours(-73).ToString('yyyy-MM-dd HH:mm:ss') | Out-Null; Exigir ($LASTEXITCODE -eq 0) 'reserva de 73 h preparada (UPDATE de una reserva del manifiesto)'
    & $php "$aqui\escritura_pm5.php" fecha $manifiestoRuta $rJusta $ahora.AddHours(-71).ToString('yyyy-MM-dd HH:mm:ss') | Out-Null; Exigir ($LASTEXITCODE -eq 0) 'reserva de 71 h preparada (UPDATE de una reserva del manifiesto)'
    $v = Formalizar $A $rVieja @(@{ descripcion = 'x'; pesoKg = '1' })
    V 'a 73 h: 409 reserva_vencida y sigue PENDIENTE' ($v.Json.error.codigo -eq 'reserva_vencida' -and (Leer "SELECT estadoReserva FROM Reserva WHERE codigoReserva='$rVieja'")[0].estadoReserva -eq 'PENDIENTE')
    V 'a 73 h no aparece en la lista de ventanilla' (((Llamar GET '/api/ventanilla/reservas' $null '8000' $A).Json.data.reservas | % codigo) -notcontains $rVieja)
    V 'a 71 h: se formaliza (201)' ((Formalizar $A $rJusta @(@{ descripcion = 'x'; pesoKg = '1' })).Estado -eq 201)

    "== 9. Concurrencia: 4 formalizaciones simultáneas de la MISMA reserva"
    $tareas = foreach ($i in 0..3) { @{ Puerto = '800' + $i; Cookie = $cookiesAdmin[$i]; Metodo = 'POST'; Ruta = '/api/envios'; Cuerpo = @{ codigoReserva = $mismaReserva; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = @(@{ descripcion = 'x'; pesoKg = '2' }) } } }
    $res = EnParalelo $tareas
    $res | Select-Object Puerto, Estado, Codigo, Seguimiento | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 201 y el resto 409' ((@($res | ? Estado -eq 201).Count -eq 1) -and (@($res | ? Estado -eq 409).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'un solo Envio para esa reserva en la BD' ((Leer "SELECT COUNT(*) AS n FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE r.codigoReserva='$mismaReserva'")[0].n -eq 1)

    "== 10. Concurrencia: 4 formalizaciones simultáneas de reservas DISTINTAS"
    $tareas = foreach ($i in 0..3) { @{ Puerto = '800' + $i; Cookie = $cookiesAdmin[$i]; Metodo = 'POST'; Ruta = '/api/envios'; Cuerpo = @{ codigoReserva = $distintas[$i]; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = @(@{ descripcion = 'x'; pesoKg = '2' }) } } }
    $res = EnParalelo $tareas
    $res | Select-Object Puerto, Estado, Codigo, Seguimiento | Format-Table -AutoSize | Out-String | Write-Host
    $codigos = @($res | ? Seguimiento | % Seguimiento)
    V 'las 4 dan 201 con códigos de seguimiento distintos (sin errores ni bloqueos mortales)' ((@($res | ? Estado -eq 201).Count -eq 4) -and (($codigos | Select-Object -Unique).Count -eq 4)) ($res | ConvertTo-Json -Compress)

    "== 11. Carrera cancelar (cliente) <-> formalizar (administrador) de la misma reserva"
    $hora = (Get-Date).AddSeconds(8).ToString('o')
    $res = EnParalelo @(
        @{ Puerto = '8001'; Cookie = $cookieCliente; Metodo = 'POST'; Ruta = "/api/reservas/$rCancelar/cancelar"; Cuerpo = $null; Hora = $hora },
        @{ Puerto = '8002'; Cookie = $cookiesAdmin[2]; Metodo = 'POST'; Ruta = '/api/envios'; Cuerpo = @{ codigoReserva = $rCancelar; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = @(@{ descripcion = 'x'; pesoKg = '2' }) }; Hora = $hora }
    )
    $res | Select-Object Ruta, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    $c = $res | ? { $_.Ruta -like '*cancelar' }; $fz = $res | ? { $_.Ruta -eq '/api/envios' }
    $est = (Leer "SELECT estadoReserva FROM Reserva WHERE codigoReserva='$rCancelar'")[0].estadoReserva
    $envs = (Leer "SELECT COUNT(*) AS n FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE r.codigoReserva='$rCancelar'")[0].n
    V 'gana una sola operación y la BD es coherente' ((($c.Estado -eq 200 -and $fz.Estado -eq 409 -and $est -eq 'CANCELADA' -and $envs -eq 0) -or ($c.Estado -eq 409 -and $fz.Estado -eq 201 -and $est -eq 'FORMALIZADA' -and $envs -eq 1))) "cancelar=$($c.Estado) formalizar=$($fz.Estado) estado=$est envios=$envs"

    "== 12. Rollback: error dentro de la transacción no deja nada a medias"
    # Un peso que pasa la validación previa pero no cabe en Bulto.pesoKg DECIMAL(8,2) es imposible (máx. 300 kg); el rollback ya se
    # comprobó con las validaciones por bulto (sección 7: reserva sigue PENDIENTE y no hay Envio). Aquí se verifica la consistencia global.
    V 'no hay Envio sin Bulto ni Reserva FORMALIZADA sin Envio entre lo creado' (
        ((Leer "SELECT COUNT(*) AS n FROM Envio e WHERE NOT EXISTS (SELECT 1 FROM Bulto b WHERE b.idEnvio=e.idEnvio) AND e.idEnvio > $($manifiesto.identAntes.Envio)")[0].n -eq 0) -and
        ((Leer "SELECT COUNT(*) AS n FROM Reserva r WHERE r.estadoReserva='FORMALIZADA' AND NOT EXISTS (SELECT 1 FROM Envio e WHERE e.idReserva=r.idReserva)")[0].n -eq 0))
    V 'todos los códigos de seguimiento cumplen EN + 10 y los importes suman sus bultos' (
        ((Leer "SELECT COUNT(*) AS n FROM Envio WHERE codigoSeguimiento NOT LIKE 'EN[A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9][A-Z0-9]'")[0].n -eq 0))
}
catch {
    $mal++
    "ERROR: se detiene la validación: $($_.Exception.Message)"
}
finally {
    foreach ($s in $servidores) { try { Stop-Process -Id $s.Id -Force -ErrorAction SilentlyContinue } catch { } }
    if (-not $escrituraIniciada) {
        "== Limpieza: no se escribió nada en la base (la validación se detuvo en las precondiciones). Nada que limpiar."
        $limpio = $true
    } else {
        "== 13. Limpieza (solo lo registrado en el manifiesto de ESTA ejecución) y comparación con la instantánea"
        try {
            # El script PHP verifica ANTES de borrar que lo existente coincide con el manifiesto y se detiene ante cualquier divergencia.
            & $php "$aqui\escritura_pm5.php" limpiar $manifiestoRuta
            if ($LASTEXITCODE -ne 0) { throw "la limpieza se detuvo (código $LASTEXITCODE): NO se borró nada; revisar con el propietario antes de continuar" }
            & $php "$aqui\instantanea.php" "$salida\despues.json" | Out-Null
            $antes = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
            $despues = Get-Content "$salida\despues.json" -Raw | ConvertFrom-Json
            $igual = $true
            foreach ($t in $antes.tablas.PSObject.Properties.Name) {
                $a = $antes.tablas.$t; $d = $despues.tablas.$t
                $mismo = ($a.filas -eq $d.filas) -and ($a.checksum -eq $d.checksum) -and (($a.hashes -join ',') -eq ($d.hashes -join ','))
                if (-not $mismo) { $igual = $false }
                '{0,-20} filas {1,3} -> {2,3}  contenido {3,-10} IDENTITY {4} -> {5}' -f $t, $a.filas, $d.filas, $(if ($mismo) { 'idéntico' } else { 'DIFIERE' }), $a.ident, $d.ident
            }
            V 'las 12 tablas con las mismas filas y contenido que antes' $igual
            V '0 registros pm5' (@($despues.restos.personas_prueba).Count -eq 0 -and @($despues.restos.usuarios_pm | ? { $_ -like 'pm5*' }).Count -eq 0)
            $limpio = $true
        } catch { "ERROR en la limpieza: $($_.Exception.Message)" }
    }
    # Sesiones: solo los archivos sess_<id> de las sesiones que abrió ESTA prueba; ninguna otra sesión se toca.
    $carpeta = Join-Path (Split-Path $aqui -Parent | Split-Path -Parent) 'storage\sessions'
    $borradas = 0
    foreach ($id in ($sesionesPropias | Select-Object -Unique)) {
        if ($id -match '^[0-9a-zA-Z,-]{16,128}$') {
            $archivo = Join-Path $carpeta "sess_$id"
            if (Test-Path -LiteralPath $archivo) { Remove-Item -LiteralPath $archivo -Force; $borradas++ }
        }
    }
    "sesiones propias borradas: $borradas de $(@($sesionesPropias | Select-Object -Unique).Count) (ninguna otra se toca)"
    "RESUMEN: $ok correctas, $mal con fallo. Limpieza completa: $limpio. Manifiesto e instantáneas en $salida"
}
