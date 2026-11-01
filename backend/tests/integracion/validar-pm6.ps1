<#
  Validación REAL del módulo 6 (Pagos y Caja) contra SQL Server con registros temporales pm6 (procedimiento del PR #7).

  NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA DEL PROPIETARIO: escribe en la base (Persona, Usuario, Reserva, Envio, Bulto y Pago).
  Por eso exige -AutorizadoPorElPropietario. Sin DDL, sin tocar el script SQL, sin tocar filas originales, sin DBCC CHECKIDENT.
  Nunca cobra, anula ni lee envíos o pagos originales: todo se hace sobre envíos creados por esta misma ejecución.

  Uso (desde C:\Jatsa, con PHP en C:\php\php.exe):
    powershell -File backend\tests\integracion\validar-pm6.ps1 -AutorizadoPorElPropietario

  Credenciales efímeras: las contraseñas se generan en memoria al empezar, se usan en ESTE proceso durante toda la prueba y
  nunca se imprimen ni se guardan (las de pm6_admin y pm6_admin2 viajan al script PHP solo por variables de entorno del proceso hijo).
  Si algo falla, se detiene, se limpia SOLO lo creado y se informa. Si la limpieza detecta una divergencia con el manifiesto,
  NO borra nada y pide revisión.
#>
param([switch]$AutorizadoPorElPropietario)

if (-not $AutorizadoPorElPropietario) {
    Write-Host 'Rechazado: esta validación escribe en la base real. Requiere autorización expresa del propietario (-AutorizadoPorElPropietario).'
    exit 2
}

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$php = 'C:\php\php.exe'
$salida = Join-Path $env:TEMP ('pm6_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Force $salida | Out-Null
. "$aqui\api.ps1"

$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Leer($sql) { (& $php "$aqui\leer.php" $sql | Out-String | ConvertFrom-Json) }
function Clave { $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; (-join (1..14 | % { $a[(Get-Random -Maximum $a.Length)] })) + '#9a' }

# Precondición o paso de preparación: si falla, se DETIENE la validación (lanza). A diferencia de V, no continúa.
function Exigir($cond, $mensaje) { if ($cond) { "  OK    $mensaje" } else { throw "Precondición fallida: $mensaje" } }

# MANIFIESTO de lo creado EN ESTA EJECUCIÓN. Es lo único que el script de escritura puede borrar.
# Se guarda en disco tras cada anotación: si la prueba se interrumpe, la limpieza sigue siendo exacta.
$manifiesto = @{ identAntes = @{}; documentos = @(); usuarios = @(); reservas = @(); envios = @(); pagos = @() }
function Anotar($clave, $valor) { if ($valor -and ($manifiesto[$clave] -notcontains $valor)) { $manifiesto[$clave] += $valor }; $manifiesto | ConvertTo-Json -Depth 4 | Set-Content $manifiestoRuta -Encoding ascii }
# Sesiones de PHP creadas por esta prueba (id de cada cookie): en la limpieza solo se borran estos archivos sess_<id>.
$sesionesPropias = @()
function Ingresar($usuario, $clave, $puerto = '8000') {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave } $puerto)
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}

# Reservas del cliente de prueba hacia el destinatario de prueba (pm6_dest, documento 90000064), por el endpoint real.
$DEST = @{ tipoDocumento = 'DNI'; numeroDocumento = '90000064'; nombreDestinatario = ''; telefonoDestinatario = '' }
function Reserva($cookie, $contenido, $origen = 'MOQ001', $destino = 'AQP001') {
    $r = Llamar POST '/api/reservas' ($DEST + @{ sucursalOrigen = $origen; sucursalDestino = $destino; contenido = $contenido }) '8000' $cookie
    if ($r.Estado -ne 201) { throw "No se pudo crear la reserva: $($r.Texto)" }
    Anotar 'reservas' $r.Json.data.reserva.codigo; $r.Json.data.reserva.codigo
}
$BULTOS_DIAPOSITIVA = @(@{ descripcion = 'Sobre con documentos'; pesoKg = '0.40' }, @{ descripcion = 'Caja con ropa'; pesoKg = '8.80' })   # S/ 23.75
$BULTO_CHICO = @(@{ descripcion = 'Caja pm6'; pesoKg = '2' })                                                                               # S/ 14.60
# Crea una reserva y la formaliza con la sesión de administrador indicada. Devuelve el código de seguimiento (queda en el manifiesto).
function EnvioNuevo($cookieCliente, $cookieAdmin, $contenido, $bultos, $origen = 'MOQ001', $destino = 'AQP001') {
    $codigo = Reserva $cookieCliente $contenido $origen $destino
    $r = Llamar POST '/api/envios' @{ codigoReserva = $codigo; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = $bultos } '8000' $cookieAdmin
    if ($r.Estado -ne 201) { throw "No se pudo formalizar $codigo : $($r.Texto)" }
    Anotar 'envios' $r.Json.data.envio.codigoSeguimiento; $r.Json.data.envio.codigoSeguimiento
}
function Pagar($cookie, $codigo, $monto, $metodo = 'EFECTIVO', $pagadoPor = 'REMITENTE', $puerto = '8000') {
    $r = Llamar POST '/api/caja/pagos' @{ codigoSeguimiento = $codigo; monto = $monto; metodoPago = $metodo; pagadoPor = $pagadoPor } $puerto $cookie
    if ($r.Estado -eq 201) { Anotar 'pagos' ([int]$r.Json.data.pago.id) }
    $r
}
function Pagos($codigo) { @(Leer "SELECT p.idPago, p.monto, p.metodoPago, p.pagadoPor, p.estadoPago, s.codigoSucursal FROM Pago p JOIN Envio e ON e.idEnvio=p.idEnvio JOIN Sucursal s ON s.idSucursal=p.idSucursal WHERE e.codigoSeguimiento='$codigo' ORDER BY p.idPago") }
function Pagado($codigo) { [decimal](Leer "SELECT COALESCE(SUM(p.monto),0) AS t FROM Pago p JOIN Envio e ON e.idEnvio=p.idEnvio WHERE e.codigoSeguimiento='$codigo' AND p.estadoPago='REGISTRADO'")[0].t }
function TotalPagos { [int](Leer 'SELECT COUNT(*) AS n FROM Pago')[0].n }

# Trabajo en paralelo: cada tarea inicia a la hora indicada (misma hora para todas = peticiones simultáneas).
$trabajo = {
    param($ps1, $puerto, $galleta, $hora, $metodo, $ruta, $cuerpo)
    . $ps1
    $w = ([datetime]$hora) - (Get-Date); if ($w.TotalMilliseconds -gt 0) { Start-Sleep -Milliseconds ([int]$w.TotalMilliseconds) }
    $r = Llamar $metodo $ruta $cuerpo $puerto $galleta
    [pscustomobject]@{ Puerto = $puerto; Estado = $r.Estado; Codigo = $r.Json.error.codigo; Campo = $r.Json.error.campos.monto; PagoId = $r.Json.data.pago.id; Ruta = $ruta }
}
function EnParalelo($tareas, $segundos = 8) {
    $hora = (Get-Date).AddSeconds($segundos).ToString('o')
    $jobs = foreach ($t in $tareas) { Start-Job -ScriptBlock $trabajo -ArgumentList "$aqui\api.ps1", $t.Puerto, $t.Cookie, $(if ($t.Hora) { $t.Hora } else { $hora }), $t.Metodo, $t.Ruta, $t.Cuerpo }
    $res = $jobs | Wait-Job -Timeout 150 | Receive-Job
    $jobs | Remove-Job -Force
    foreach ($x in $res) { if ($x.PagoId) { Anotar 'pagos' ([int]$x.PagoId) } }   # todo pago creado queda anotado ANTES de comprobar nada
    $res
}
function CobroParalelo($cookies, $codigo, $monto) {
    EnParalelo @(foreach ($i in 0..3) { @{ Puerto = '800' + $i; Cookie = $cookies[$i]; Metodo = 'POST'; Ruta = '/api/caja/pagos'; Cuerpo = @{ codigoSeguimiento = $codigo; monto = $monto; metodoPago = 'EFECTIVO'; pagadoPor = 'REMITENTE' } } })
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
    Exigir (@($antes.restos.personas_pm6).Count -eq 0) 'no existen personas con los documentos de prueba 90000061-64'
    Exigir (@($antes.restos.usuarios_pm | ? { $_ -like 'pm6*' }).Count -eq 0) 'no existen cuentas pm6_*'
    $reservasPm6 = (Leer "SELECT COUNT(*) AS n FROM Reserva r JOIN Persona p ON p.idPersona IN (r.idPersonaRemitente, r.idPersonaDestinatario) WHERE p.numeroDocumento IN ('90000061','90000062','90000063','90000064')")[0].n
    Exigir ([int]$reservasPm6 -eq 0) 'no existen reservas ligadas a personas de prueba'
    $tarifa = (Leer "SELECT COUNT(DISTINCT r.tipoEnvio) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.tipoEnvio IN ('SOBRE','PAQUETE') AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa -eq 2) 'MOQ001→AQP001 tiene tarifa vigente de SOBRE y de PAQUETE (caso de la diapositiva 19: S/ 23.75)'
    $tarifa2 = (Leer "SELECT COUNT(*) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='AQP001' AND d.codigoSucursal='LIM001' AND r.tipoEnvio='PAQUETE' AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa2 -ge 1) 'AQP001→LIM001 tiene tarifa vigente de PAQUETE (envío «de otra agencia» para MOQ001)'
    $sucursal = (Leer "SELECT COUNT(*) AS n FROM Sucursal WHERE codigoSucursal IN ('MOQ001','AQP001','LIM001') AND activa = 1")[0].n
    Exigir ([int]$sucursal -eq 3) 'las sucursales MOQ001, AQP001 y LIM001 existen y están activas'
    $pagosEnBase = (Leer 'SELECT COUNT(*) AS n FROM Pago')[0].n
    Exigir ([int]$pagosEnBase -lt 150) "la lista de caja no se trunca (hay $pagosEnBase pagos; el tope es 200)"
    # Referencia para distinguir lo creado por esta ejecución: sus ids serán mayores que estos.
    foreach ($t in 'Persona', 'Usuario', 'Reserva', 'Envio', 'Pago') { $manifiesto.identAntes[$t] = [int]$antes.tablas.$t.ident }
    Anotar 'documentos' $null

    $escrituraIniciada = $true   # a partir de aquí hay escrituras (cuentas temporales); lo creado queda en el manifiesto
    foreach ($p in 8000..8003) { $servidores += Start-Process $php -ArgumentList '-S', "localhost:$p", '-t', 'backend/public' -WindowStyle Hidden -PassThru }
    Start-Sleep -Seconds 3
    foreach ($p in 8000..8003) { Exigir ([bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "servidor de prueba escuchando en $p" }

    "== 2. Cuentas temporales (contraseñas solo en memoria)"
    $pwCliente = Clave; $pwDest = Clave; $pwAdmin = Clave; $pwAdmin2 = Clave
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000061'; nombreCompleto = 'Prueba Seis Cliente'; telefono = '900000061'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm6_cliente'; contrasena = $pwCliente }
    Anotar 'documentos' '90000061'; Anotar 'usuarios' 'pm6_cliente'   # se anota antes de comprobar: si la respuesta falla pero se escribió, la limpieza lo ve
    Exigir ($reg.Estado -eq 201) "pm6_cliente registrado por el endpoint (201) $($reg.Texto)"
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000064'; nombreCompleto = 'Prueba Seis Destinatario'; telefono = '900000064'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm6_dest'; contrasena = $pwDest }
    Anotar 'documentos' '90000064'; Anotar 'usuarios' 'pm6_dest'
    Exigir ($reg.Estado -eq 201) "pm6_dest registrado por el endpoint (201) $($reg.Texto)"
    Anotar 'documentos' '90000062'; Anotar 'documentos' '90000063'; Anotar 'usuarios' 'pm6_admin'; Anotar 'usuarios' 'pm6_admin2'
    $env:JATSA_PM6_PW_ADMIN = $pwAdmin; $env:JATSA_PM6_PW_ADMIN2 = $pwAdmin2
    & $php "$aqui\escritura_pm6.php" crear-personal $manifiestoRuta; $codigoAdmin = $LASTEXITCODE
    $env:JATSA_PM6_PW_ADMIN = $null; $env:JATSA_PM6_PW_ADMIN2 = $null
    Exigir ($codigoAdmin -eq 0) 'pm6_admin (MOQ001) y pm6_admin2 (AQP001) creadas (INSERT de Persona y Usuario)'
    $cookieCliente = Ingresar 'pm6_cliente' $pwCliente
    $cookieDest = Ingresar 'pm6_dest' $pwDest
    # Cuatro sesiones del administrador de MOQ001 (una por servidor): PHP bloquea el archivo de una misma sesión y serializaría la concurrencia.
    $cookiesAdmin = foreach ($i in 0..3) { Ingresar 'pm6_admin' $pwAdmin ('800' + $i) }
    $cookieAdmin2 = Ingresar 'pm6_admin2' $pwAdmin2
    $pwCliente = $null; $pwDest = $null; $pwAdmin = $null; $pwAdmin2 = $null
    Exigir ($cookieCliente -and $cookieDest -and $cookieAdmin2 -and @($cookiesAdmin | ? { $_ }).Count -eq 4) 'sesiones abiertas (cliente, destinatario, 4 de administrador MOQ001 y 1 de AQP001)'
    $yo = Llamar GET '/api/auth/yo' $null '8000' $cookiesAdmin[0]
    Exigir ($yo.Json.data.usuario.rol -eq 'ADMINISTRADOR' -and $yo.Json.data.usuario.sucursal.codigo -eq 'MOQ001') 'pm6_admin es ADMINISTRADOR de MOQ001'
    $yo = Llamar GET '/api/auth/yo' $null '8000' $cookieAdmin2
    Exigir ($yo.Json.data.usuario.rol -eq 'ADMINISTRADOR' -and $yo.Json.data.usuario.sucursal.codigo -eq 'AQP001') 'pm6_admin2 es ADMINISTRADOR de AQP001'
    $A = $cookiesAdmin[0]; $B = $cookieAdmin2

    "== 3. Permisos"
    V 'cliente no entra a Caja: lista, cobro y anulación (403)' ((@('GET /api/caja/pagos', 'POST /api/caja/pagos', 'POST /api/caja/pagos/1/anular', 'GET /api/caja/envios/ENABCDEFGH23') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cookieCliente).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'administrador no entra a Mis pagos (403)' ((Llamar GET '/api/pagos' $null '8000' $A).Estado -eq 403)
    V 'sin sesión (401) en Caja y en Mis pagos' (((Llamar GET '/api/caja/pagos' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401) -and ((Llamar GET '/api/pagos' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401))

    "== 4. Envíos de prueba (reservas y formalización por los endpoints reales; aún sin pagos)"
    $eUno = EnvioNuevo $cookieCliente $A 'pm6 cobro completo' $BULTOS_DIAPOSITIVA
    $eDos = EnvioNuevo $cookieCliente $A 'pm6 parciales y anulacion' $BULTOS_DIAPOSITIVA
    $eTres = EnvioNuevo $cookieCliente $A 'pm6 4 cobros completos simultaneos' $BULTOS_DIAPOSITIVA
    $eCuatro = EnvioNuevo $cookieCliente $A 'pm6 4 cobros parciales simultaneos' $BULTOS_DIAPOSITIVA
    $eDistintos = 1..4 | % { EnvioNuevo $cookieCliente $A "pm6 cobro distinto $_" $BULTO_CHICO }
    $eDestino = EnvioNuevo $cookieCliente $A 'pm6 pago en destino' $BULTOS_DIAPOSITIVA
    $eOtra = EnvioNuevo $cookieCliente $B 'pm6 envio de otra agencia (AQP-LIM)' $BULTO_CHICO 'AQP001' 'LIM001'
    Exigir (($eUno -match '^EN[A-Z0-9]{10}$') -and @($eDistintos).Count -eq 4) '10 envíos de prueba formalizados (importes: 4 de S/ 23.75, 4 de S/ 14.60, 1 de S/ 23.75 en destino y 1 AQP→LIM)'
    V 'ningún pago existe todavía para los envíos de prueba' ((TotalPagos) -eq [int]$antes.tablas.Pago.filas)

    "== 5. Cobro completo (caso de la diapositiva 19: S/ 23.75)"
    $cobro = Llamar GET "/api/caja/envios/$eUno" $null '8000' $A
    V 'el envío a cobrar: importe 23.75, pagado 0.00, saldo 23.75, sugiere REMITENTE' ($cobro.Json.data.envio.importe -eq '23.75' -and $cobro.Json.data.envio.pagado -eq '0.00' -and $cobro.Json.data.envio.saldo -eq '23.75' -and $cobro.Json.data.envio.pagadoPorSugerido -eq 'REMITENTE') $cobro.Texto
    V 'sin documentos ni teléfonos en la respuesta' ($cobro.Texto -notmatch '9000006|numeroDocumento|telefono')
    $p = Pagar $A $eUno '23.75' 'EFECTIVO' 'REMITENTE'
    V '201: pago de 23.75 REGISTRADO en MOQ001 y envío con saldo 0.00' ($p.Estado -eq 201 -and $p.Json.data.pago.monto -eq '23.75' -and $p.Json.data.pago.estado -eq 'REGISTRADO' -and $p.Json.data.pago.sucursal.codigo -eq 'MOQ001' -and $p.Json.data.envio.saldo -eq '0.00') $p.Texto
    $fila = Pagos $eUno
    V 'Pago en la BD: monto 23.75, EFECTIVO, REMITENTE, REGISTRADO, sucursal MOQ001' (@($fila).Count -eq 1 -and [decimal]$fila[0].monto -eq 23.75 -and $fila[0].metodoPago -eq 'EFECTIVO' -and $fila[0].pagadoPor -eq 'REMITENTE' -and $fila[0].estadoPago -eq 'REGISTRADO' -and $fila[0].codigoSucursal -eq 'MOQ001') ($fila | ConvertTo-Json -Compress)
    $seg = (Leer "SELECT DATEDIFF(SECOND, fechaHoraPago, SYSDATETIME()) AS s FROM Pago WHERE idPago = $([int]$p.Json.data.pago.id)")[0].s
    V 'la fecha del pago la pone la base (hace menos de 5 minutos)' ([int]$seg -ge -5 -and [int]$seg -lt 300) "segundos=$seg"
    $x = Pagar $A $eUno '0.01'
    V 'envío ya pagado: 409 envio_pagado y sin pago nuevo' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_pagado' -and @(Pagos $eUno).Count -eq 1) $x.Texto

    "== 6. Rechazos que NO deben escribir nada"
    $n0 = TotalPagos
    $r1 = foreach ($monto in '0', '-1', '10.999', 'abc', '', '99999999999') { (Pagar $A $eDos $monto).Estado }
    V 'montos inválidos (0, negativo, 3 decimales, texto, vacío, desmesurado): 422' (@($r1 | ? { $_ -ne 422 }).Count -eq 0) ($r1 -join ',')
    V 'método inexistente: 422' ((Pagar $A $eDos '5.00' 'CHEQUE').Estado -eq 422)
    V 'pagadoPor inexistente: 422' ((Pagar $A $eDos '5.00' 'EFECTIVO' 'OTRO').Estado -eq 422)
    $s = Pagar $A $eDos '23.76'
    V 'sobrepago (23.76 sobre 23.75): 422 con error en monto' ($s.Estado -eq 422 -and $s.Json.error.campos.monto -like '*23.75*') $s.Texto
    V 'envío inexistente: 404' ((Pagar $A 'ENZZZZZZZZZZ' '5.00').Estado -eq 404)
    $otra = Pagar $A $eOtra '5.00'
    V 'envío de OTRA agencia (AQP→LIM): 404, mismo que uno inexistente' ($otra.Estado -eq 404 -and $otra.Texto -eq (Pagar $A 'ENZZZZZZZZZZ' '5.00').Texto)
    V 'consultar el envío de otra agencia: mismo 404 que uno inexistente' ((Llamar GET "/api/caja/envios/$eOtra" $null '8000' $A).Texto -eq (Llamar GET '/api/caja/envios/ENZZZZZZZZZZ' $null '8000' $A).Texto)
    V 'el cuerpo no puede fijar sucursal ni estado: 422 de monto y nada escrito' ((Llamar POST '/api/caja/pagos' @{ codigoSeguimiento = $eDos; monto = '0'; metodoPago = 'EFECTIVO'; pagadoPor = 'REMITENTE'; idSucursal = 1; estadoPago = 'ANULADO' } '8000' $A).Estado -eq 422)
    V 'ninguno de esos rechazos escribió un Pago' ((TotalPagos) -eq $n0) "antes=$n0 después=$(TotalPagos)"
    V 'el personal de destino (AQP001) sí ve el envío MOQ→AQP' ((Llamar GET "/api/caja/envios/$eDos" $null '8000' $B).Estado -eq 200)

    "== 7. Parciales, anulación y nuevo cobro"
    $p1 = Pagar $A $eDos '10.00' 'TARJETA'
    V 'pago parcial de 10.00: 201 y saldo 13.75' ($p1.Estado -eq 201 -and $p1.Json.data.envio.saldo -eq '13.75') $p1.Texto
    $x = Pagar $A $eDos '14.00'
    V '14.00 supera el saldo 13.75: 422' ($x.Estado -eq 422 -and $x.Json.error.campos.monto -like '*13.75*') $x.Texto
    $p2 = Pagar $A $eDos '13.75' 'BILLETERA'
    V 'el resto (13.75): 201 y saldo 0.00' ($p2.Estado -eq 201 -and $p2.Json.data.envio.saldo -eq '0.00') $p2.Texto
    $id1 = [int]$p1.Json.data.pago.id; $id2 = [int]$p2.Json.data.pago.id
    $an = Llamar POST "/api/caja/pagos/$id1/anular" $null '8000' $A
    V 'anular el primero: 200 ANULADO' ($an.Estado -eq 200 -and $an.Json.data.pago.estado -eq 'ANULADO') $an.Texto
    $f = @(Pagos $eDos)
    V 'la fila NO se borra: sigue en la BD como ANULADO con su monto' (@($f).Count -eq 2 -and ($f | ? { [int]$_.idPago -eq $id1 }).estadoPago -eq 'ANULADO' -and [decimal]($f | ? { [int]$_.idPago -eq $id1 }).monto -eq 10.00)
    V 'el saldo se recupera (10.00)' ((Llamar GET "/api/caja/envios/$eDos" $null '8000' $A).Json.data.envio.saldo -eq '10.00')
    V 'anular otra vez: 409 pago_anulado' ((Llamar POST "/api/caja/pagos/$id1/anular" $null '8000' $A).Json.error.codigo -eq 'pago_anulado')
    $ajeno = Llamar POST "/api/caja/pagos/$id2/anular" $null '8000' $B
    V 'otra caja (AQP001) no anula un pago de MOQ001: mismo 404 que uno inexistente' ($ajeno.Estado -eq 404 -and $ajeno.Texto -eq (Llamar POST '/api/caja/pagos/999999999/anular' $null '8000' $B).Texto)
    V '...y ese pago sigue REGISTRADO' (($f = @(Pagos $eDos) | ? { [int]$_.idPago -eq $id2 }).estadoPago -eq 'REGISTRADO')
    $p3 = Pagar $A $eDos '10.00' 'EFECTIVO'
    V 'se vuelve a cobrar lo anulado: 201 y saldo 0.00' ($p3.Estado -eq 201 -and $p3.Json.data.envio.saldo -eq '0.00') $p3.Texto
    V 'BD: 3 filas, suma de los REGISTRADO = 23.75' (@(Pagos $eDos).Count -eq 3 -and (Pagado $eDos) -eq 23.75)

    "== 8. Concurrencia: 4 cobros COMPLETOS simultáneos (S/ 23.75) del MISMO envío"
    $res = CobroParalelo $cookiesAdmin $eTres '23.75'
    $res | Select-Object Puerto, Estado, Codigo, PagoId | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 201 y el resto 409 envio_pagado' ((@($res | ? Estado -eq 201).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'envio_pagado' }).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'BD: un solo pago REGISTRADO y la suma no pasa de 23.75' (@(Pagos $eTres | ? estadoPago -eq 'REGISTRADO').Count -eq 1 -and (Pagado $eTres) -eq 23.75)

    "== 9. Concurrencia: 4 cobros de 10.00 simultáneos del MISMO envío de 23.75 (nunca más que el importe)"
    $res = CobroParalelo $cookiesAdmin $eCuatro '10.00'
    $res | Select-Object Puerto, Estado, Codigo, Campo, PagoId | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente dos 201 y dos 422 (el tercero ya no cabe en el saldo)' ((@($res | ? Estado -eq 201).Count -eq 2) -and (@($res | ? { $_.Estado -eq 422 -and $_.Codigo -eq 'datos_invalidos' }).Count -eq 2)) ($res | ConvertTo-Json -Compress)
    V 'BD: suma de los REGISTRADO = 20.00 (≤ importe 23.75)' ((Pagado $eCuatro) -eq 20.00 -and @(Pagos $eCuatro | ? estadoPago -eq 'REGISTRADO').Count -eq 2)

    "== 10. Concurrencia: 4 cobros simultáneos de envíos DISTINTOS (sin bloqueos entre sí)"
    $tareas = foreach ($i in 0..3) { @{ Puerto = '800' + $i; Cookie = $cookiesAdmin[$i]; Metodo = 'POST'; Ruta = '/api/caja/pagos'; Cuerpo = @{ codigoSeguimiento = $eDistintos[$i]; monto = '14.60'; metodoPago = 'TARJETA'; pagadoPor = 'REMITENTE' } } }
    $res = EnParalelo $tareas
    $res | Select-Object Puerto, Estado, Codigo, PagoId | Format-Table -AutoSize | Out-String | Write-Host
    V 'las 4 dan 201 con ids de pago distintos (sin errores ni bloqueos mortales)' ((@($res | ? Estado -eq 201).Count -eq 4) -and (@($res | % PagoId | Select-Object -Unique).Count -eq 4)) ($res | ConvertTo-Json -Compress)
    V 'BD: cada envío quedó con exactamente 14.60 pagado' (@($eDistintos | ? { (Pagado $_) -eq 14.60 }).Count -eq 4)

    "== 11. Concurrencia: 4 anulaciones simultáneas del MISMO pago"
    $idAnular = [int]($res | ? Puerto -eq '8000').PagoId
    $res = EnParalelo @(foreach ($i in 0..3) { @{ Puerto = '800' + $i; Cookie = $cookiesAdmin[$i]; Metodo = 'POST'; Ruta = "/api/caja/pagos/$idAnular/anular"; Cuerpo = $null } })
    $res | Select-Object Puerto, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 200 y el resto 409 pago_anulado' ((@($res | ? Estado -eq 200).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'pago_anulado' }).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'BD: el pago quedó ANULADO y sigue existiendo' ((Leer "SELECT estadoPago FROM Pago WHERE idPago = $idAnular")[0].estadoPago -eq 'ANULADO')

    "== 12. Cobro en la agencia de DESTINO (pago en destino)"
    $c = Llamar GET "/api/caja/envios/$eDestino" $null '8000' $B
    V 'pm6_admin2 (AQP001) ve el envío y se sugiere DESTINATARIO' ($c.Estado -eq 200 -and $c.Json.data.envio.pagadoPorSugerido -eq 'DESTINATARIO') $c.Texto
    $pd = Pagar $B $eDestino '23.75' 'TRANSFERENCIA' 'DESTINATARIO'
    V '201: el pago se registra en AQP001 como DESTINATARIO' ($pd.Estado -eq 201 -and $pd.Json.data.pago.sucursal.codigo -eq 'AQP001' -and $pd.Json.data.pago.pagadoPor -eq 'DESTINATARIO') $pd.Texto
    $fila = Pagos $eDestino
    V 'BD: idSucursal = AQP001, TRANSFERENCIA, DESTINATARIO' (@($fila).Count -eq 1 -and $fila[0].codigoSucursal -eq 'AQP001' -and $fila[0].metodoPago -eq 'TRANSFERENCIA' -and $fila[0].pagadoPor -eq 'DESTINATARIO')
    V 'el origen (MOQ001) ve el envío pagado (saldo 0.00)' ((Llamar GET "/api/caja/envios/$eDestino" $null '8000' $A).Json.data.envio.saldo -eq '0.00')
    $idDestino = [int]$pd.Json.data.pago.id
    V 'la caja de MOQ001 NO lista el pago cobrado en AQP001; la de AQP001 sí' ((((Llamar GET '/api/caja/pagos' $null '8000' $A).Json.data.pagos | % id) -notcontains $idDestino) -and (((Llamar GET '/api/caja/pagos' $null '8000' $B).Json.data.pagos | % id) -contains $idDestino))

    "== 13. Pagos cobrados aquí: totales contra la BD"
    foreach ($par in @(@('MOQ001', $A), @('AQP001', $B))) {
        $cod = $par[0]
        $caja = Llamar GET '/api/caja/pagos' $null '8000' $par[1]
        $bd = @(Leer "SELECT p.metodoPago, SUM(p.monto) AS t FROM Pago p JOIN Sucursal s ON s.idSucursal=p.idSucursal WHERE s.codigoSucursal='$cod' AND p.estadoPago='REGISTRADO' GROUP BY p.metodoPago")
        $esperado = @{}; foreach ($m in 'EFECTIVO', 'TARJETA', 'BILLETERA', 'TRANSFERENCIA') { $esperado[$m] = [decimal]0 }
        foreach ($g in $bd) { $esperado[$g.metodoPago] = [decimal]$g.t }
        $t = $caja.Json.data.totales
        $coincide = ([decimal]$t.EFECTIVO -eq $esperado.EFECTIVO) -and ([decimal]$t.TARJETA -eq $esperado.TARJETA) -and ([decimal]$t.BILLETERA -eq $esperado.BILLETERA) -and ([decimal]$t.TRANSFERENCIA -eq $esperado.TRANSFERENCIA) -and ([decimal]$t.total -eq ($esperado.EFECTIVO + $esperado.TARJETA + $esperado.BILLETERA + $esperado.TRANSFERENCIA))
        V "totales de la caja de $cod = suma real de la BD (solo REGISTRADO)" $coincide ($t | ConvertTo-Json -Compress)
        $cuantos = [int](Leer "SELECT COUNT(*) AS n FROM Pago p JOIN Sucursal s ON s.idSucursal=p.idSucursal WHERE s.codigoSucursal='$cod'")[0].n
        V "la lista de $cod trae todos sus pagos (anulados incluidos), el más reciente primero" (@($caja.Json.data.pagos).Count -eq $cuantos -and $caja.Json.data.hayMas -eq $false -and [int]$caja.Json.data.pagos[0].id -eq [int](Leer "SELECT MAX(p.idPago) AS m FROM Pago p JOIN Sucursal s ON s.idSucursal=p.idSucursal WHERE s.codigoSucursal='$cod'")[0].m)
    }

    "== 14. Mis pagos (lo que se imputó a cada persona)"
    $mis = Llamar GET '/api/pagos' $null '8000' $cookieCliente
    $ids = @($mis.Json.data.pagos | % id)
    $debe = @(Leer "SELECT p.idPago FROM Pago p JOIN Envio e ON e.idEnvio=p.idEnvio JOIN Reserva r ON r.idReserva=e.idReserva JOIN Persona q ON q.numeroDocumento='90000061' WHERE p.pagadoPor='REMITENTE' AND r.idPersonaRemitente=q.idPersona" | % idPago)
    V 'pm6_cliente (remitente) ve exactamente los pagos imputados al REMITENTE de sus envíos' ((($ids | Sort-Object) -join ',') -eq (($debe | Sort-Object) -join ',') -and $ids.Count -ge 6) "api=$($ids -join ',') bd=$($debe -join ',')"
    V 'pm6_cliente NO ve el pago imputado al DESTINATARIO (pago en destino)' ($ids -notcontains $idDestino)
    V 'total pagado = suma de los REGISTRADO imputados al remitente' ([decimal]$mis.Json.data.totalPagado -eq [decimal](Leer "SELECT COALESCE(SUM(p.monto),0) AS t FROM Pago p JOIN Envio e ON e.idEnvio=p.idEnvio JOIN Reserva r ON r.idReserva=e.idReserva JOIN Persona q ON q.numeroDocumento='90000061' WHERE p.estadoPago='REGISTRADO' AND p.pagadoPor='REMITENTE' AND r.idPersonaRemitente=q.idPersona")[0].t) $mis.Json.data.totalPagado
    V 'los pagos anulados se listan con su estado y no suman' ((@($mis.Json.data.pagos | ? estado -eq 'ANULADO').Count -ge 2))
    $dest = Llamar GET '/api/pagos' $null '8000' $cookieDest
    V 'pm6_dest (destinatario) ve solo el pago en destino imputado a él, por 23.75' ((@($dest.Json.data.pagos).Count -eq 1) -and [int]$dest.Json.data.pagos[0].id -eq $idDestino -and $dest.Json.data.totalPagado -eq '23.75') $dest.Texto
    V 'Mis pagos no expone documentos ni teléfonos' (($mis.Texto + $dest.Texto) -notmatch '9000006|numeroDocumento|telefono')

    "== 15. Consistencia global de lo creado en esta ejecución"
    $idEnv = $manifiesto.identAntes.Envio; $idPag = $manifiesto.identAntes.Pago
    V 'ningún envío de la prueba tiene más pagado (REGISTRADO) que su importe' ([int](Leer "SELECT COUNT(*) AS n FROM Envio e WHERE e.idEnvio > $idEnv AND (SELECT COALESCE(SUM(p.monto),0) FROM Pago p WHERE p.idEnvio=e.idEnvio AND p.estadoPago='REGISTRADO') > (SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio)")[0].n -eq 0)
    V 'todo Pago nuevo está anotado en el manifiesto (nada sin registrar)' ([int](Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $idPag")[0].n -eq @($manifiesto.pagos).Count) "bd=$((Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $idPag")[0].n) manifiesto=$(@($manifiesto.pagos).Count)"
    V 'ningún Pago nuevo pertenece a un envío anterior a la prueba (no se tocó nada original)' ([int](Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $idPag AND idEnvio <= $idEnv")[0].n -eq 0)
    V 'montos, métodos y estados válidos en los pagos de la prueba' ([int](Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $idPag AND (monto <= 0 OR metodoPago NOT IN ('EFECTIVO','TARJETA','BILLETERA','TRANSFERENCIA') OR estadoPago NOT IN ('REGISTRADO','ANULADO') OR pagadoPor NOT IN ('REMITENTE','DESTINATARIO'))")[0].n -eq 0)
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
        "== 16. Limpieza (solo lo registrado en el manifiesto de ESTA ejecución) y comparación con la instantánea"
        try {
            # El script PHP verifica ANTES de borrar que lo existente coincide con el manifiesto y se detiene ante cualquier divergencia.
            & $php "$aqui\escritura_pm6.php" limpiar $manifiestoRuta
            if ($LASTEXITCODE -ne 0) { throw "la limpieza se detuvo (código $LASTEXITCODE): NO se borró nada; revisar con el propietario antes de continuar (sin borrados adicionales)" }
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
            V 'las 12 tablas con las mismas filas y contenido que antes (incluida Pago)' $igual
            $sinRestos = (@($despues.restos.personas_pm6).Count -eq 0 -and @($despues.restos.usuarios_pm | ? { $_ -like 'pm6*' }).Count -eq 0)
            V '0 registros pm6' $sinRestos
            # Solo se informa limpieza completa si se COMPROBÓ: tablas idénticas y sin restos pm6.
            $limpio = ($igual -and $sinRestos)
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
    # Código de salida: 0 solo si no hubo ningún fallo y la limpieza quedó comprobada; en otro caso 1.
    $codigoSalida = if ($mal -eq 0 -and $limpio) { 0 } else { 1 }
}
exit $codigoSalida
