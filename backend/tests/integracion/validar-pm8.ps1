<#
  Validación REAL del módulo 8 (Llegadas y entregas) y de su cruce con Caja (módulo 6) contra SQL Server con registros temporales pm8
  (procedimiento del PR #9).

  NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA DEL PROPIETARIO: escribe en la base (Persona, Usuario, Reserva, Envio, Bulto, Viaje, Envio_Viaje y Pago).
  Por eso exige -AutorizadoPorElPropietario. Sin DDL, sin tocar el script SQL, sin tocar filas originales, sin DBCC CHECKIDENT.
  Nunca entrega, devuelve, cobra ni anula pagos de envíos originales: todo se hace sobre envíos, viajes y pagos creados por esta misma
  ejecución, con un vehículo que no tiene ningún viaje en ruta (se comprueba en las precondiciones).

  Uso (desde C:\Jatsa, con PHP en C:\php\php.exe):
    powershell -File backend\tests\integracion\validar-pm8.ps1 -AutorizadoPorElPropietario

  Credenciales efímeras: las contraseñas se generan en memoria al empezar, se usan en ESTE proceso durante toda la prueba y
  nunca se imprimen ni se guardan (las del personal viajan al script PHP solo por variables de entorno del proceso hijo).
  Si algo falla, se detiene, se limpia SOLO lo creado y se informa. Si la limpieza detecta una divergencia con el manifiesto,
  NO borra nada y pide revisión. El script termina con exit 0 solo si no hubo ningún fallo y la limpieza quedó comprobada.
#>
param([switch]$AutorizadoPorElPropietario)

if (-not $AutorizadoPorElPropietario) {
    Write-Host 'Rechazado: esta validación escribe en la base real. Requiere autorización expresa del propietario (-AutorizadoPorElPropietario).'
    exit 2
}

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$php = 'C:\php\php.exe'
$salida = Join-Path $env:TEMP ('pm8_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
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
$manifiesto = @{ identAntes = @{}; documentos = @(); usuarios = @(); reservas = @(); envios = @(); viajes = @(); pagos = @() }
function Anotar($clave, $valor) { if ($valor -and ($manifiesto[$clave] -notcontains $valor)) { $manifiesto[$clave] += $valor }; $manifiesto | ConvertTo-Json -Depth 4 | Set-Content $manifiestoRuta -Encoding ascii }
# Sesiones de PHP creadas por esta prueba (id de cada cookie): en la limpieza solo se borran estos archivos sess_<id>.
$sesionesPropias = @()
function Ingresar($usuario, $clave, $puerto = '8000') {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave } $puerto)
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}

# El destinatario de todos los envíos es pm8_dest (documento 90000084): con él se comprueba la entrega.
$DEST = @{ tipoDocumento = 'DNI'; numeroDocumento = '90000084'; nombreDestinatario = ''; telefonoDestinatario = '' }
$DOC_DEST = '9000-0084'   # el documento que presenta quien recoge (con guion: se normaliza)
$BULTO_CHICO = @(@{ descripcion = 'Caja pm8'; pesoKg = '2' })
function Reserva($cookie, $contenido) {
    $r = Llamar POST '/api/reservas' ($DEST + @{ sucursalOrigen = 'MOQ001'; sucursalDestino = 'AQP001'; contenido = $contenido }) '8000' $cookie
    if ($r.Estado -ne 201) { throw "No se pudo crear la reserva: $($r.Texto)" }
    Anotar 'reservas' $r.Json.data.reserva.codigo; $r.Json.data.reserva.codigo
}
function EnvioNuevo($cookieCliente, $cookieAdmin, $contenido) {
    $codigo = Reserva $cookieCliente $contenido
    $r = Llamar POST '/api/envios' @{ codigoReserva = $codigo; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = $BULTO_CHICO } '8000' $cookieAdmin
    if ($r.Estado -ne 201) { throw "No se pudo formalizar $codigo : $($r.Texto)" }
    Anotar 'envios' $r.Json.data.envio.codigoSeguimiento; $r.Json.data.envio.codigoSeguimiento
}
function Programar($cookie) {
    $r = Llamar POST '/api/despacho/viajes' @{ placa = 'C3M-956'; transportista = 'pm8_trans'; destino = 'AQP001' } '8000' $cookie
    if ($r.Estado -eq 201) { Anotar 'viajes' $r.Json.data.viaje.codigo }
    $r
}
function Cargar($cookie, $viaje, $envio) { Llamar POST "/api/despacho/viajes/$viaje/carga" @{ codigoSeguimiento = $envio } '8000' $cookie }
function Salida($cookie, $viaje) { Llamar POST "/api/viajes/$viaje/salida" $null '8000' $cookie }
# Marca la llegada esperando el segundo que exige CK_VIAJE_FECHAS (llegada estrictamente posterior a la salida).
function Terminar($cookie, $viaje) {
    Start-Sleep -Milliseconds 1600
    for ($i = 0; $i -lt 4; $i++) {
        $r = Llamar POST "/api/viajes/$viaje/llegada" $null '8000' $cookie
        if ($r.Estado -ne 409 -or $r.Json.error.codigo -ne 'llegada_muy_pronto') { return $r }
        Start-Sleep -Milliseconds 1200
    }
    $r
}
function SaldoDe($cookie, $codigo) { (Llamar GET "/api/caja/envios/$codigo" $null '8000' $cookie).Json.data.envio.saldo }
function Pagar($cookie, $codigo, $monto) {
    $r = Llamar POST '/api/caja/pagos' @{ codigoSeguimiento = $codigo; monto = $monto; metodoPago = 'EFECTIVO'; pagadoPor = 'DESTINATARIO' } '8000' $cookie
    if ($r.Estado -eq 201) { Anotar 'pagos' ([int]$r.Json.data.pago.id) }
    $r
}
function PagarTodo($cookie, $codigo) { Pagar $cookie $codigo (SaldoDe $cookie $codigo) }
function AnularPago($cookie, $id) { Llamar POST "/api/caja/pagos/$id/anular" $null '8000' $cookie }
function Entregar($cookie, $codigo, $numero = $DOC_DEST, $tipo = 'DNI') { Llamar POST "/api/entregas/envios/$codigo/entrega" @{ tipoDocumento = $tipo; numeroDocumento = $numero } '8000' $cookie }
function Devolver($cookie, $codigo) { Llamar POST "/api/entregas/envios/$codigo/devolucion" $null '8000' $cookie }
function EnvioBD($seg) { (Leer "SELECT e.estadoEnvio, e.fechaHoraEntrega, DATEDIFF(SECOND, e.fechaHoraEntrega, SYSDATETIME()) AS seg, (SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio) AS importe, (SELECT COALESCE(SUM(p.monto),0) FROM Pago p WHERE p.idEnvio=e.idEnvio AND p.estadoPago='REGISTRADO') AS pagado FROM Envio e WHERE e.codigoSeguimiento='$seg'")[0] }
function Estado($seg) { (EnvioBD $seg).estadoEnvio }
function EstadoPago($id) { (Leer "SELECT estadoPago FROM Pago WHERE idPago = $([int]$id)")[0].estadoPago }
function TotalPagos { [int](Leer 'SELECT COUNT(*) AS n FROM Pago')[0].n }
# Nunca ENTREGADO con saldo: el estado ENTREGADO solo es válido si lo pagado (REGISTRADO) cubre el importe.
function Coherente($seg) { $b = EnvioBD $seg; -not ($b.estadoEnvio -eq 'ENTREGADO' -and [decimal]$b.pagado -lt [decimal]$b.importe) }

# Trabajo en paralelo: cada tarea inicia a la hora indicada (misma hora para todas = peticiones simultáneas).
$trabajo = {
    param($ps1, $puerto, $galleta, $hora, $metodo, $ruta, $cuerpo)
    . $ps1
    $w = ([datetime]$hora) - (Get-Date); if ($w.TotalMilliseconds -gt 0) { Start-Sleep -Milliseconds ([int]$w.TotalMilliseconds) }
    $r = Llamar $metodo $ruta $cuerpo $puerto $galleta
    [pscustomobject]@{ Puerto = $puerto; Estado = $r.Estado; Codigo = $r.Json.error.codigo; PagoId = $r.Json.data.pago.id; Ruta = $ruta }
}
function EnParalelo($tareas, $segundos = 8) {
    $hora = (Get-Date).AddSeconds($segundos).ToString('o')
    $jobs = foreach ($t in $tareas) { Start-Job -ScriptBlock $trabajo -ArgumentList "$aqui\api.ps1", $t.Puerto, $t.Cookie, $(if ($t.Hora) { $t.Hora } else { $hora }), $t.Metodo, $t.Ruta, $t.Cuerpo }
    $res = $jobs | Wait-Job -Timeout 150 | Receive-Job
    $jobs | Remove-Job -Force
    foreach ($x in $res) { if ($x.PagoId -and $x.Ruta -eq '/api/caja/pagos') { Anotar 'pagos' ([int]$x.PagoId) } }   # todo pago creado queda anotado ANTES de comprobar nada
    $res
}
function Tarea($puerto, $cookie, $metodo, $ruta, $cuerpo = $null) { @{ Puerto = "$puerto"; Cookie = $cookie; Metodo = $metodo; Ruta = $ruta; Cuerpo = $cuerpo } }
function Resumen($res) { $res | Select-Object Puerto, Estado, Codigo, Ruta | Format-Table -AutoSize | Out-String | Write-Host }

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
    Exigir (@($antes.restos.personas_pm8).Count -eq 0) 'no existen personas con los documentos de prueba 90000081-85'
    Exigir (@($antes.restos.usuarios_pm | ? { $_ -like 'pm8*' }).Count -eq 0) 'no existen cuentas pm8_*'
    $reservasPm8 = (Leer "SELECT COUNT(*) AS n FROM Reserva r JOIN Persona p ON p.idPersona IN (r.idPersonaRemitente, r.idPersonaDestinatario) WHERE p.numeroDocumento IN ('90000081','90000082','90000083','90000084','90000085')")[0].n
    Exigir ([int]$reservasPm8 -eq 0) 'no existen reservas ligadas a personas de prueba'
    $tarifa = (Leer "SELECT COUNT(DISTINCT r.tipoEnvio) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.tipoEnvio IN ('SOBRE','PAQUETE') AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa -eq 2) 'MOQ001→AQP001 tiene tarifa vigente de SOBRE y de PAQUETE'
    $sucursal = (Leer "SELECT COUNT(*) AS n FROM Sucursal WHERE codigoSucursal IN ('MOQ001','AQP001') AND activa = 1")[0].n
    Exigir ([int]$sucursal -eq 2) 'las sucursales MOQ001 y AQP001 existen y están activas'
    $horas = (Leer "SELECT MAX(r.tiempoHoras) AS h FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.vigenteHasta IS NULL")[0].h
    Exigir ([int]$horas -gt 0) "MOQ001→AQP001 tiene tiempo estimado ($horas h) para comprobar «llega aprox.»"
    $veh = @(Leer "SELECT ve.placa, ve.activo, (SELECT COUNT(*) FROM Viaje v WHERE v.idVehiculo=ve.idVehiculo AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL) AS enRuta FROM Vehiculo ve WHERE ve.placa = 'C3M-956'")
    Exigir (@($veh).Count -eq 1 -and [int]$veh[0].activo -eq 1 -and [int]$veh[0].enRuta -eq 0) 'el vehículo C3M-956 existe, está activo y NO tiene un viaje en ruta (no se interfiere con viajes originales)'
    # Referencia para distinguir lo creado por esta ejecución: sus ids serán mayores que estos.
    foreach ($t in 'Persona', 'Usuario', 'Reserva', 'Envio', 'Viaje', 'Envio_Viaje', 'Pago') { $manifiesto.identAntes[$t] = [int]$antes.tablas.$t.ident }
    Anotar 'documentos' $null

    $escrituraIniciada = $true   # a partir de aquí hay escrituras (cuentas temporales); lo creado queda en el manifiesto
    foreach ($p in 8000..8003) { $servidores += Start-Process $php -ArgumentList '-S', "localhost:$p", '-t', 'backend/public' -WindowStyle Hidden -PassThru }
    Start-Sleep -Seconds 3
    foreach ($p in 8000..8003) { Exigir ([bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "servidor de prueba escuchando en $p" }

    "== 2. Cuentas temporales (contraseñas solo en memoria)"
    $pw = @{ cliente = Clave; dest = Clave; admin = Clave; admin2 = Clave; trans = Clave }
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000081'; nombreCompleto = 'Prueba Ocho Cliente'; telefono = '900000081'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm8_cliente'; contrasena = $pw.cliente }
    Anotar 'documentos' '90000081'; Anotar 'usuarios' 'pm8_cliente'   # se anota antes de comprobar: si la respuesta falla pero se escribió, la limpieza lo ve
    Exigir ($reg.Estado -eq 201) "pm8_cliente registrado por el endpoint (201) $($reg.Texto)"
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000084'; nombreCompleto = 'Prueba Ocho Destinatario'; telefono = '900000084'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm8_dest'; contrasena = $pw.dest }
    Anotar 'documentos' '90000084'; Anotar 'usuarios' 'pm8_dest'
    Exigir ($reg.Estado -eq 201) "pm8_dest registrado por el endpoint (201) $($reg.Texto)"
    foreach ($d in '90000082', '90000083', '90000085') { Anotar 'documentos' $d }
    foreach ($u in 'pm8_admin', 'pm8_admin2', 'pm8_trans') { Anotar 'usuarios' $u }
    $env:JATSA_PM8_PW_ADMIN = $pw.admin; $env:JATSA_PM8_PW_ADMIN2 = $pw.admin2; $env:JATSA_PM8_PW_TRANS = $pw.trans
    & $php "$aqui\escritura_pm8.php" crear-personal $manifiestoRuta; $codigoPersonal = $LASTEXITCODE
    $env:JATSA_PM8_PW_ADMIN = $null; $env:JATSA_PM8_PW_ADMIN2 = $null; $env:JATSA_PM8_PW_TRANS = $null
    Exigir ($codigoPersonal -eq 0) 'personal pm8 creado (INSERT de Persona y Usuario: 2 administradores y 1 transportista)'
    $cCli = Ingresar 'pm8_cliente' $pw.cliente
    $cAdm = Ingresar 'pm8_admin' $pw.admin
    $cTr = Ingresar 'pm8_trans' $pw.trans
    # Una sesión por servidor para el administrador de AQP001, que lanza peticiones simultáneas: PHP bloquea el archivo de una misma sesión y serializaría la concurrencia.
    $cAdm2 = foreach ($i in 0..3) { Ingresar 'pm8_admin2' $pw.admin2 ('800' + $i) }
    $pw = $null
    Exigir ($cCli -and $cAdm -and $cTr -and @($cAdm2 | ? { $_ }).Count -eq 4) 'sesiones abiertas (cliente, administrador de MOQ001, transportista y 4 del administrador de AQP001)'
    $yo = (Llamar GET '/api/auth/yo' $null '8000' $cAdm).Json.data.usuario
    Exigir ($yo.rol -eq 'ADMINISTRADOR' -and $yo.sucursal.codigo -eq 'MOQ001') 'pm8_admin es ADMINISTRADOR de MOQ001 (origen)'
    $yo = (Llamar GET '/api/auth/yo' $null '8000' $cAdm2[0]).Json.data.usuario
    Exigir ($yo.rol -eq 'ADMINISTRADOR' -and $yo.sucursal.codigo -eq 'AQP001') 'pm8_admin2 es ADMINISTRADOR de AQP001 (destino: entrega y cobra aquí)'

    "== 3. Permisos"
    V 'cliente no entra a Llegadas y entregas (403)' ((@('GET /api/entregas/pendientes', 'GET /api/entregas/envios/ENABCDEFGH23', 'POST /api/entregas/envios/ENABCDEFGH23/entrega', 'POST /api/entregas/envios/ENABCDEFGH23/devolucion') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cCli).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'transportista no entra a Llegadas y entregas (403)' ((@('GET /api/entregas/pendientes', 'POST /api/entregas/envios/ENABCDEFGH23/entrega') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cTr).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'sin sesión (401)' ((Llamar GET '/api/entregas/pendientes' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401)

    "== 4. Envíos y viaje de prueba (reservas, formalización, carga, salida y llegada por los endpoints reales)"
    $eA = EnvioNuevo $cCli $cAdm 'pm8 entrega basica'
    $eB = EnvioNuevo $cCli $cAdm 'pm8 pago parcial'
    $eC = EnvioNuevo $cCli $cAdm 'pm8 devolver'
    $eD = EnvioNuevo $cCli $cAdm 'pm8 cobros tras devolver'
    $eE = EnvioNuevo $cCli $cAdm 'pm8 4 entregas simultaneas'
    $eF = EnvioNuevo $cCli $cAdm 'pm8 cobro y entrega simultaneos'
    $eG = EnvioNuevo $cCli $cAdm 'pm8 anular y entregar 1'
    $eH = EnvioNuevo $cCli $cAdm 'pm8 anular y entregar 2'
    $eI = EnvioNuevo $cCli $cAdm 'pm8 devolver y entregar'
    $eK = EnvioNuevo $cCli $cAdm 'pm8 sin cargar'
    $eL = EnvioNuevo $cCli $cAdm 'pm8 viaje en camino'
    $v1 = (Programar $cAdm).Json.data.viaje.codigo
    Exigir ($v1) 'pm8_admin programa el viaje MOQ001 → AQP001 (C3M-956, pm8_trans)'
    foreach ($e in @($eA, $eB, $eC, $eD, $eE, $eF, $eG, $eH, $eI)) { Exigir ((Cargar $cAdm $v1 $e).Estado -eq 201) "carga $e en $v1" }
    Exigir ((Salida $cTr $v1).Estado -eq 200) 'pm8_trans marca la salida'
    Exigir ((Terminar $cTr $v1).Estado -eq 200) 'pm8_trans marca la llegada a AQP001'
    V 'los 9 envíos cargados quedaron EN_DESTINO (listos para entregar) y el no cargado sigue REGISTRADO' ((@($eA, $eB, $eC, $eD, $eE, $eF, $eG, $eH, $eI | ? { (Estado $_) -ne 'EN_DESTINO' }).Count -eq 0) -and (Estado $eK) -eq 'REGISTRADO')
    $v2 = (Programar $cAdm).Json.data.viaje.codigo
    Exigir ((Cargar $cAdm $v2 $eL).Estado -eq 201 -and (Salida $cTr $v2).Estado -eq 200) 'segundo viaje con eL: cargado y en ruta hacia AQP001 (queda en camino durante las pruebas)'

    "== 5. Lo pendiente de AQP001: en camino y listos"
    $p = Llamar GET '/api/entregas/pendientes' $null '8000' $cAdm2[0]
    $listos = @($p.Json.data.listos | % codigoSeguimiento)
    V 'listos para entregar: los 9 envíos EN_DESTINO de la prueba; no el REGISTRADO ni el que va en camino' ((@($eA, $eB, $eC, $eD, $eE, $eF, $eG, $eH, $eI | ? { $listos -notcontains $_ }).Count -eq 0) -and ($listos -notcontains $eK) -and ($listos -notcontains $eL)) $p.Texto
    V 'la lista no expone documentos ni teléfonos' ($p.Texto -notmatch '9000008|numeroDocumento|telefono')
    $salidaV2 = [datetime](Leer "SELECT v.fechaHoraSalida AS s FROM Viaje v WHERE v.codigoViaje='$v2'")[0].s
    $camino = @($p.Json.data.enCamino | ? { $_.codigo -eq $v2 })
    V '«en camino hacia aquí» incluye el segundo viaje con su llegada estimada = salida + horas de la ruta' ($camino.Count -eq 1 -and $camino[0].llegadaEstimada -eq $salidaV2.AddHours([int]$horas).ToString('yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture) -and $camino[0].cantidadEnvios -eq 1) $p.Texto
    $pm = Llamar GET '/api/entregas/pendientes' $null '8000' $cAdm
    V 'MOQ001 (el origen) no ve esos envíos como listos ni el viaje como «en camino»' ((@($pm.Json.data.listos | % codigoSeguimiento | ? { $listos -contains $_ }).Count -eq 0) -and (@($pm.Json.data.enCamino | % codigo) -notcontains $v2))
    $d = Llamar GET "/api/entregas/envios/$eA" $null '8000' $cAdm2[0]
    V 'detalle para el personal: el documento del destinatario registrado, bultos y saldo' ($d.Estado -eq 200 -and $d.Json.data.envio.destinatario.numeroDocumento -eq '90000084' -and $d.Json.data.envio.destinatario.tipoDocumento -eq 'DNI' -and $d.Json.data.envio.cantidadBultos -eq 1 -and [decimal]$d.Json.data.envio.saldo -gt 0) $d.Texto

    "== 6. Entregar: documento, saldo y fecha de la base"
    $antesA = EnvioBD $eA
    $x1 = Entregar $cAdm2[0] $eA '90000085'
    $x2 = Entregar $cAdm2[0] $eA $DOC_DEST 'RUC'
    $x3 = Entregar $cAdm2[0] $eA '1234567'
    V 'documento distinto, tipo distinto y número mal formado: 422 en numeroDocumento sin revelar el registrado' (($x1.Estado -eq 422 -and $x2.Estado -eq 422 -and $x3.Estado -eq 422) -and $x1.Texto -notmatch '90000084' -and $x1.Json.error.campos.numeroDocumento) "$($x1.Estado),$($x2.Estado),$($x3.Estado)"
    $bd = EnvioBD $eA
    V 'BD: sigue EN_DESTINO y sin fecha de entrega' ($bd.estadoEnvio -eq 'EN_DESTINO' -and $null -eq $bd.fechaHoraEntrega)
    $x = Entregar $cAdm2[0] $eA
    V 'documento correcto pero con saldo: 409 saldo_pendiente (hay que cobrar antes) y sin cambios' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'saldo_pendiente' -and (Estado $eA) -eq 'EN_DESTINO') $x.Texto
    $pa = PagarTodo $cAdm2[0] $eA
    V 'se cobra el saldo en la caja de AQP001 (201)' ($pa.Estado -eq 201 -and $pa.Json.data.envio.saldo -eq '0.00') $pa.Texto
    $idPagoA = [int]$pa.Json.data.pago.id
    $x = Entregar $cAdm2[0] $eA
    V 'con el documento correcto (con guion) y sin saldo: 200 ENTREGADO con fecha de entrega' ($x.Estado -eq 200 -and $x.Json.data.envio.estado -eq 'ENTREGADO' -and $x.Json.data.envio.fechaHoraEntrega) $x.Texto
    $bd = EnvioBD $eA
    V 'BD: ENTREGADO con fecha y hora puestas por la base (hace menos de 5 minutos)' ($bd.estadoEnvio -eq 'ENTREGADO' -and $null -ne $bd.fechaHoraEntrega -and [int]$bd.seg -ge -5 -and [int]$bd.seg -lt 300) ($bd | ConvertTo-Json -Compress)
    V 'segunda entrega: 409 envio_entregado y la fecha no cambia' ((Entregar $cAdm2[0] $eA).Json.error.codigo -eq 'envio_entregado' -and (EnvioBD $eA).fechaHoraEntrega -eq $bd.fechaHoraEntrega)
    V 'sale de «listos» y el detalle lo muestra ENTREGADO' ((@((Llamar GET '/api/entregas/pendientes' $null '8000' $cAdm2[0]).Json.data.listos | % codigoSeguimiento) -notcontains $eA) -and (Llamar GET "/api/entregas/envios/$eA" $null '8000' $cAdm2[0]).Json.data.envio.estado -eq 'ENTREGADO')
    "  -- pago parcial y luego completo"
    $pb = Pagar $cAdm2[0] $eB '5.00'
    $x = Entregar $cAdm2[0] $eB
    V 'con un pago parcial sigue el saldo: 409 saldo_pendiente con el monto restante (9.60)' ($pb.Estado -eq 201 -and $x.Estado -eq 409 -and $x.Json.error.codigo -eq 'saldo_pendiente' -and $x.Json.error.mensaje -like '*9.60*') $x.Texto
    $null = PagarTodo $cAdm2[0] $eB
    V 'pagado el resto: se entrega (200)' ((Entregar $cAdm2[0] $eB).Estado -eq 200 -and (Estado $eB) -eq 'ENTREGADO' -and (Coherente $eB))

    "== 7. Devolver"
    $x = Devolver $cAdm2[0] $eC
    V 'marcar devuelto (aun con saldo): 200 DEVUELTO' ($x.Estado -eq 200 -and $x.Json.data.envio.estado -eq 'DEVUELTO' -and $null -eq $x.Json.data.envio.fechaHoraEntrega) $x.Texto
    $bd = EnvioBD $eC
    V 'BD: DEVUELTO sin fecha de entrega (CK_ENVIO_ENTREGA)' ($bd.estadoEnvio -eq 'DEVUELTO' -and $null -eq $bd.fechaHoraEntrega)
    V 'devuelto: ya no se entrega (409 envio_devuelto) ni se devuelve otra vez (409)' (((Entregar $cAdm2[0] $eC).Json.error.codigo -eq 'envio_devuelto') -and ((Devolver $cAdm2[0] $eC).Json.error.codigo -eq 'envio_devuelto'))
    V 'un envío ya entregado no se devuelve (409 envio_entregado)' ((Devolver $cAdm2[0] $eA).Json.error.codigo -eq 'envio_entregado')

    "== 8. Caja después de cerrar el envío (cobros y anulaciones)"
    $nPagos = TotalPagos
    $x = Pagar $cAdm2[0] $eA '1.00'
    V 'cobrar un envío ENTREGADO: 409 envio_entregado y sin pago nuevo' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_entregado' -and (TotalPagos) -eq $nPagos) $x.Texto
    $x = AnularPago $cAdm2[0] $idPagoA
    V 'anular el pago de un envío ENTREGADO: 409 envio_entregado y el pago sigue REGISTRADO (nunca queda saldo en un envío entregado)' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_entregado' -and (EstadoPago $idPagoA) -eq 'REGISTRADO' -and (Coherente $eA)) $x.Texto
    $pd = PagarTodo $cAdm2[0] $eD
    $idPagoD = [int]$pd.Json.data.pago.id
    Exigir ($pd.Estado -eq 201 -and (Devolver $cAdm2[0] $eD).Estado -eq 200) 'eD: se cobra y luego se marca devuelto'
    $x = Pagar $cAdm2[0] $eD '1.00'
    V 'cobrar un envío DEVUELTO: 409 envio_devuelto y sin pago nuevo' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_devuelto' -and (TotalPagos) -eq ($nPagos + 1)) $x.Texto
    $x = AnularPago $cAdm2[0] $idPagoD
    V 'anular el pago de un envío DEVUELTO: 409 envio_devuelto y el pago sigue REGISTRADO (la anulación no sustituye a un reembolso)' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_devuelto' -and (EstadoPago $idPagoD) -eq 'REGISTRADO') $x.Texto
    V 'un pago de otra caja (MOQ001) sigue dando el mismo 404 que uno inexistente, aunque el envío esté cerrado' ((AnularPago $cAdm $idPagoA).Texto -eq (AnularPago $cAdm 999999999).Texto -and (AnularPago $cAdm $idPagoA).Estado -eq 404)

    "== 9. Otra agencia, estados y códigos"
    $otra = Llamar GET "/api/entregas/envios/$eE" $null '8000' $cAdm
    V 'el origen (MOQ001) no puede ver el envío: 404, igual que uno inexistente' ($otra.Estado -eq 404 -and $otra.Texto -eq (Llamar GET '/api/entregas/envios/ENZZZZZZZZZZ' $null '8000' $cAdm).Texto)
    $x = Entregar $cAdm $eE
    V 'ni entregarlo ni devolverlo (404) y sigue EN_DESTINO' ($x.Estado -eq 404 -and (Devolver $cAdm $eE).Estado -eq 404 -and (Estado $eE) -eq 'EN_DESTINO')
    V 'envío REGISTRADO (sin cargar): 409 envio_no_esta_en_destino; el que va en camino (EN_TRANSITO): igual' (((Entregar $cAdm2[0] $eK).Json.error.codigo -eq 'envio_no_esta_en_destino') -and ((Entregar $cAdm2[0] $eL).Json.error.codigo -eq 'envio_no_esta_en_destino') -and ((Devolver $cAdm2[0] $eK).Json.error.codigo -eq 'envio_no_esta_en_destino'))
    V 'código inexistente o mal formado: 404' (((Entregar $cAdm2[0] 'ENZZZZZZZZZZ').Estado -eq 404) -and ((Entregar $cAdm2[0] 'xx').Estado -eq 404))
    V 'cuerpo vacío o con tipos raros: 422 sin escribir' (((Llamar POST "/api/entregas/envios/$eE/entrega" @{} '8000' $cAdm2[0]).Estado -eq 422) -and ((Llamar POST "/api/entregas/envios/$eE/entrega" @{ tipoDocumento = @('DNI'); numeroDocumento = 5 } '8000' $cAdm2[0]).Estado -eq 422) -and (Estado $eE) -eq 'EN_DESTINO')

    "== 10. Concurrencia de entregas y de Caja"
    "  -- 4 «Confirmar entrega» SIMULTÁNEOS del MISMO envío (4 sesiones)"
    Exigir ((PagarTodo $cAdm2[0] $eE).Estado -eq 201) 'eE queda pagado'
    $res = EnParalelo @(foreach ($i in 0..3) { Tarea (8000 + $i) $cAdm2[$i] 'POST' "/api/entregas/envios/$eE/entrega" @{ tipoDocumento = 'DNI'; numeroDocumento = $DOC_DEST } })
    Resumen $res
    V 'exactamente un 200 y el resto 409 envio_entregado' ((@($res | ? Estado -eq 200).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'envio_entregado' }).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'BD: ENTREGADO una sola vez, con fecha, y sin saldo' ((Estado $eE) -eq 'ENTREGADO' -and $null -ne (EnvioBD $eE).fechaHoraEntrega -and (Coherente $eE))
    "  -- COBRAR el saldo y ENTREGAR a la vez (mismo envío)"
    $saldoF = SaldoDe $cAdm2[0] $eF
    $res = EnParalelo @((Tarea 8000 $cAdm2[0] 'POST' '/api/caja/pagos' @{ codigoSeguimiento = $eF; monto = $saldoF; metodoPago = 'EFECTIVO'; pagadoPor = 'DESTINATARIO' }), (Tarea 8001 $cAdm2[1] 'POST' "/api/entregas/envios/$eF/entrega" @{ tipoDocumento = 'DNI'; numeroDocumento = $DOC_DEST }))
    Resumen $res
    $rp = $res | ? { $_.Ruta -eq '/api/caja/pagos' }; $re = $res | ? { $_.Ruta -like '*/entrega' }
    V 'el cobro siempre se registra (201) y la entrega, según quién llegó primero, es 200 o 409 saldo_pendiente' ($rp.Estado -eq 201 -and (($re.Estado -eq 200) -or ($re.Estado -eq 409 -and $re.Codigo -eq 'saldo_pendiente'))) ($res | ConvertTo-Json -Compress)
    V 'BD: nunca ENTREGADO con saldo' (Coherente $eF) ((EnvioBD $eF) | ConvertTo-Json -Compress)
    if ($re.Estado -eq 409) { V 'si la entrega perdió la carrera, se puede entregar después (ya sin saldo)' ((Entregar $cAdm2[0] $eF).Estado -eq 200 -and (Coherente $eF)) }
    "  -- ANULAR el pago y ENTREGAR a la vez (dos envíos, para ver ambos desenlaces posibles)"
    foreach ($envAnula in @($eG, $eH)) {
        $pg = PagarTodo $cAdm2[0] $envAnula
        Exigir ($pg.Estado -eq 201) "$envAnula queda pagado"
        $idPg = [int]$pg.Json.data.pago.id
        $res = EnParalelo @((Tarea 8000 $cAdm2[0] 'POST' "/api/caja/pagos/$idPg/anular"), (Tarea 8001 $cAdm2[1] 'POST' "/api/entregas/envios/$envAnula/entrega" @{ tipoDocumento = 'DNI'; numeroDocumento = $DOC_DEST }))
        Resumen $res
        $ra = $res | ? { $_.Ruta -like '*/anular' }; $re = $res | ? { $_.Ruta -like '*/entrega' }
        $entregaPrimero = ($re.Estado -eq 200 -and $ra.Estado -eq 409 -and $ra.Codigo -eq 'envio_entregado')
        $anulaPrimero = ($ra.Estado -eq 200 -and $re.Estado -eq 409 -and $re.Codigo -eq 'saldo_pendiente')
        V "$envAnula : gana UNA sola operación (entrega y anulación rechazada, o anulación y entrega rechazada por saldo)" ($entregaPrimero -or $anulaPrimero) ($res | ConvertTo-Json -Compress)
        V "$envAnula : BD coherente: nunca ENTREGADO con saldo por una anulación posterior" ((Coherente $envAnula) -and ((Estado $envAnula) -eq 'ENTREGADO') -eq $entregaPrimero -and ((EstadoPago $idPg) -eq 'REGISTRADO') -eq $entregaPrimero) ((EnvioBD $envAnula) | ConvertTo-Json -Compress)
    }
    "  -- DEVOLVER y ENTREGAR a la vez (mismo envío)"
    Exigir ((PagarTodo $cAdm2[0] $eI).Estado -eq 201) 'eI queda pagado'
    $res = EnParalelo @((Tarea 8000 $cAdm2[0] 'POST' "/api/entregas/envios/$eI/devolucion"), (Tarea 8001 $cAdm2[1] 'POST' "/api/entregas/envios/$eI/entrega" @{ tipoDocumento = 'DNI'; numeroDocumento = $DOC_DEST }))
    Resumen $res
    $rd = $res | ? { $_.Ruta -like '*/devolucion' }; $re = $res | ? { $_.Ruta -like '*/entrega' }
    V 'gana UNA sola operación (devolución y entrega rechazada, o entrega y devolución rechazada)' ((($rd.Estado -eq 200 -and $re.Estado -eq 409 -and $re.Codigo -eq 'envio_devuelto') -or ($re.Estado -eq 200 -and $rd.Estado -eq 409 -and $rd.Codigo -eq 'envio_entregado'))) ($res | ConvertTo-Json -Compress)
    $bd = EnvioBD $eI
    V 'BD: ENTREGADO con fecha, o DEVUELTO sin fecha (nunca los dos)' ((($bd.estadoEnvio -eq 'ENTREGADO') -and $null -ne $bd.fechaHoraEntrega) -or (($bd.estadoEnvio -eq 'DEVUELTO') -and $null -eq $bd.fechaHoraEntrega)) ($bd | ConvertTo-Json -Compress)

    "== 11. El segundo viaje llega"
    $ll = Terminar $cTr $v2
    V 'pm8_trans marca la llegada: eL pasa a EN_DESTINO' ($ll.Estado -eq 200 -and (Estado $eL) -eq 'EN_DESTINO')
    $p = Llamar GET '/api/entregas/pendientes' $null '8000' $cAdm2[0]
    V 'ya no está «en camino» y eL aparece en «listos para entregar»' ((@($p.Json.data.enCamino | % codigo) -notcontains $v2) -and (@($p.Json.data.listos | % codigoSeguimiento) -contains $eL))

    "== 12. Consistencia global de lo creado en esta ejecución"
    $iE = $manifiesto.identAntes.Envio; $iP = $manifiesto.identAntes.Pago; $iC = $manifiesto.identAntes.Envio_Viaje; $iV = $manifiesto.identAntes.Viaje
    V 'ningún envío de la prueba está ENTREGADO con lo pagado (REGISTRADO) por debajo de su importe' ([int](Leer "SELECT COUNT(*) AS n FROM Envio e WHERE e.idEnvio > $iE AND e.estadoEnvio = 'ENTREGADO' AND (SELECT COALESCE(SUM(p.monto),0) FROM Pago p WHERE p.idEnvio=e.idEnvio AND p.estadoPago='REGISTRADO') < (SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio)")[0].n -eq 0)
    V 'fechaHoraEntrega solo en los ENTREGADO (CK_ENVIO_ENTREGA) y los DEVUELTO no tienen fecha' ([int](Leer "SELECT COUNT(*) AS n FROM Envio WHERE idEnvio > $iE AND ((estadoEnvio = 'ENTREGADO' AND fechaHoraEntrega IS NULL) OR (estadoEnvio <> 'ENTREGADO' AND fechaHoraEntrega IS NOT NULL))")[0].n -eq 0)
    V 'todo Pago nuevo está anotado en el manifiesto (nada sin registrar) y ninguno toca envíos anteriores a la prueba' (([int](Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $iP")[0].n -eq @($manifiesto.pagos).Count) -and ([int](Leer "SELECT COUNT(*) AS n FROM Pago WHERE idPago > $iP AND idEnvio <= $iE")[0].n -eq 0))
    V 'ninguna carga nueva toca viajes o envíos anteriores a la prueba y todo Viaje nuevo está anotado' (([int](Leer "SELECT COUNT(*) AS n FROM Envio_Viaje WHERE idEnvioViaje > $iC AND (idViaje <= $iV OR idEnvio <= $iE)")[0].n -eq 0) -and ([int](Leer "SELECT COUNT(*) AS n FROM Viaje WHERE idViaje > $iV")[0].n -eq @($manifiesto.viajes).Count))
    V 'no queda ningún viaje de prueba en ruta' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje v JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE u.nombreUsuario='pm8_trans' AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL")[0].n -eq 0)
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
            & $php "$aqui\escritura_pm8.php" limpiar $manifiestoRuta
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
            V 'las 12 tablas con las mismas filas y contenido que antes (incluidas Envio, Pago, Viaje y Envio_Viaje)' $igual
            $sinRestos = (@($despues.restos.personas_pm8).Count -eq 0 -and @($despues.restos.usuarios_pm | ? { $_ -like 'pm8*' }).Count -eq 0)
            V '0 registros pm8' $sinRestos
            # Solo se informa limpieza completa si se COMPROBÓ: tablas idénticas y sin restos pm8.
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
