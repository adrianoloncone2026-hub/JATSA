<#
  Validación REAL del módulo 7 (Despacho y viajes) contra SQL Server con registros temporales pm7 (procedimiento del PR #8).

  NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA DEL PROPIETARIO: escribe en la base (Persona, Usuario, Reserva, Envio, Bulto, Viaje y Envio_Viaje).
  Por eso exige -AutorizadoPorElPropietario. Sin DDL, sin tocar el script SQL, sin tocar filas originales, sin DBCC CHECKIDENT.
  Nunca carga, hace salir ni llegar viajes o envíos originales: todo se hace sobre viajes y envíos creados por esta misma ejecución,
  con vehículos que no tienen ningún viaje en ruta (se comprueba en las precondiciones).

  Uso (desde C:\Jatsa, con PHP en C:\php\php.exe):
    powershell -File backend\tests\integracion\validar-pm7.ps1 -AutorizadoPorElPropietario

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
$salida = Join-Path $env:TEMP ('pm7_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
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
$manifiesto = @{ identAntes = @{}; documentos = @(); usuarios = @(); reservas = @(); envios = @(); viajes = @() }
function Anotar($clave, $valor) { if ($valor -and ($manifiesto[$clave] -notcontains $valor)) { $manifiesto[$clave] += $valor }; $manifiesto | ConvertTo-Json -Depth 4 | Set-Content $manifiestoRuta -Encoding ascii }
# Sesiones de PHP creadas por esta prueba (id de cada cookie): en la limpieza solo se borran estos archivos sess_<id>.
$sesionesPropias = @()
function Ingresar($usuario, $clave, $puerto = '8000') {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave } $puerto)
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}

# Reservas del cliente de prueba hacia el destinatario de prueba (pm7_dest, documento 90000074), por el endpoint real.
$DEST = @{ tipoDocumento = 'DNI'; numeroDocumento = '90000074'; nombreDestinatario = ''; telefonoDestinatario = '' }
function Reserva($cookie, $contenido, $origen, $destino) {
    $r = Llamar POST '/api/reservas' ($DEST + @{ sucursalOrigen = $origen; sucursalDestino = $destino; contenido = $contenido }) '8000' $cookie
    if ($r.Estado -ne 201) { throw "No se pudo crear la reserva: $($r.Texto)" }
    Anotar 'reservas' $r.Json.data.reserva.codigo; $r.Json.data.reserva.codigo
}
$BULTO_CHICO = @(@{ descripcion = 'Caja pm7'; pesoKg = '2' })                                                                  # 2 kg
$BULTOS_60 = @(@{ descripcion = 'Caja pm7 a'; pesoKg = '30' }, @{ descripcion = 'Caja pm7 b'; pesoKg = '30' })                   # 60 kg
$BULTOS_150 = 1..5 | % { @{ descripcion = "Caja pm7 $_"; pesoKg = '30' } }                                                      # 150 kg exactos
$BULTOS_180 = 1..6 | % { @{ descripcion = "Caja pm7 $_"; pesoKg = '30' } }                                                      # 180 kg
# Crea una reserva y la formaliza con la sesión de administrador indicada. Devuelve el código de seguimiento (queda en el manifiesto).
function EnvioNuevo($cookieCliente, $cookieAdmin, $contenido, $bultos, $origen = 'MOQ001', $destino = 'AQP001') {
    $codigo = Reserva $cookieCliente $contenido $origen $destino
    $r = Llamar POST '/api/envios' @{ codigoReserva = $codigo; tipoEntrega = 'AGENCIA'; direccionEntrega = ''; bultos = $bultos } '8000' $cookieAdmin
    if ($r.Estado -ne 201) { throw "No se pudo formalizar $codigo : $($r.Texto)" }
    Anotar 'envios' $r.Json.data.envio.codigoSeguimiento; $r.Json.data.envio.codigoSeguimiento
}
function Programar($cookie, $placa, $transportista, $destino, $puerto = '8000') {
    $r = Llamar POST '/api/despacho/viajes' @{ placa = $placa; transportista = $transportista; destino = $destino } $puerto $cookie
    if ($r.Estado -eq 201) { Anotar 'viajes' $r.Json.data.viaje.codigo }
    $r
}
function Cargar($cookie, $viaje, $envio, $puerto = '8000') { Llamar POST "/api/despacho/viajes/$viaje/carga" @{ codigoSeguimiento = $envio } $puerto $cookie }
function Quitar($cookie, $viaje, $envio, $puerto = '8000') { Llamar DELETE "/api/despacho/viajes/$viaje/carga/$envio" $null $puerto $cookie }
function Salida($cookie, $viaje, $puerto = '8000') { Llamar POST "/api/viajes/$viaje/salida" $null $puerto $cookie }
function Llegada($cookie, $viaje, $puerto = '8000') { Llamar POST "/api/viajes/$viaje/llegada" $null $puerto $cookie }
# Marca la llegada esperando el segundo que exige CK_VIAJE_FECHAS (llegada estrictamente posterior a la salida).
function Terminar($cookie, $viaje, $puerto = '8000') {
    Start-Sleep -Milliseconds 1600
    for ($i = 0; $i -lt 4; $i++) {
        $r = Llegada $cookie $viaje $puerto
        if ($r.Estado -ne 409 -or $r.Json.error.codigo -ne 'llegada_muy_pronto') { return $r }
        Start-Sleep -Milliseconds 1200
    }
    $r
}
function ViajeBD($codigo) { (Leer "SELECT v.idViaje, v.fechaHoraSalida, v.fechaHoraLlegada, so.codigoSucursal AS origen, sd.codigoSucursal AS destino, ve.placa, u.nombreUsuario AS transportista, (SELECT COUNT(*) FROM Envio_Viaje x WHERE x.idViaje=v.idViaje) AS cargas FROM Viaje v JOIN Sucursal so ON so.idSucursal=v.idSucursalOrigen JOIN Sucursal sd ON sd.idSucursal=v.idSucursalDestino JOIN Vehiculo ve ON ve.idVehiculo=v.idVehiculo JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE v.codigoViaje='$codigo'")[0] }
function EstadoEnvio($seg) { (Leer "SELECT estadoEnvio FROM Envio WHERE codigoSeguimiento='$seg'")[0].estadoEnvio }
function CargasDe($seg) { [int](Leer "SELECT COUNT(*) AS n FROM Envio_Viaje ev JOIN Envio e ON e.idEnvio=ev.idEnvio WHERE e.codigoSeguimiento='$seg'")[0].n }
function TotalCargas { [int](Leer 'SELECT COUNT(*) AS n FROM Envio_Viaje')[0].n }
function TotalViajes { [int](Leer 'SELECT COUNT(*) AS n FROM Viaje')[0].n }
function PesoCargado($codigo) { [decimal](Leer "SELECT COALESCE(SUM(b.pesoKg),0) AS t FROM Envio_Viaje ev JOIN Viaje v ON v.idViaje=ev.idViaje JOIN Bulto b ON b.idEnvio=ev.idEnvio WHERE v.codigoViaje='$codigo'")[0].t }
function EnRutaDe($usuario) { [int](Leer "SELECT COUNT(*) AS n FROM Viaje v JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE u.nombreUsuario='$usuario' AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL")[0].n }

# Trabajo en paralelo: cada tarea inicia a la hora indicada (misma hora para todas = peticiones simultáneas).
$trabajo = {
    param($ps1, $puerto, $galleta, $hora, $metodo, $ruta, $cuerpo)
    . $ps1
    $w = ([datetime]$hora) - (Get-Date); if ($w.TotalMilliseconds -gt 0) { Start-Sleep -Milliseconds ([int]$w.TotalMilliseconds) }
    $r = Llamar $metodo $ruta $cuerpo $puerto $galleta
    [pscustomobject]@{ Puerto = $puerto; Estado = $r.Estado; Codigo = $r.Json.error.codigo; ViajeCodigo = $r.Json.data.viaje.codigo; Ruta = $ruta }
}
function EnParalelo($tareas, $segundos = 8) {
    $hora = (Get-Date).AddSeconds($segundos).ToString('o')
    $jobs = foreach ($t in $tareas) { Start-Job -ScriptBlock $trabajo -ArgumentList "$aqui\api.ps1", $t.Puerto, $t.Cookie, $(if ($t.Hora) { $t.Hora } else { $hora }), $t.Metodo, $t.Ruta, $t.Cuerpo }
    $res = $jobs | Wait-Job -Timeout 150 | Receive-Job
    $jobs | Remove-Job -Force
    foreach ($x in $res) { if ($x.ViajeCodigo -and $x.Ruta -eq '/api/despacho/viajes') { Anotar 'viajes' $x.ViajeCodigo } }   # todo viaje creado queda anotado ANTES de comprobar nada
    $res
}
function Tarea($puerto, $cookie, $metodo, $ruta, $cuerpo = $null) { @{ Puerto = "$puerto"; Cookie = $cookie; Metodo = $metodo; Ruta = $ruta; Cuerpo = $cuerpo } }

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
    Exigir (@($antes.restos.personas_pm7).Count -eq 0) 'no existen personas con los documentos de prueba 90000071-76'
    Exigir (@($antes.restos.usuarios_pm | ? { $_ -like 'pm7*' }).Count -eq 0) 'no existen cuentas pm7_*'
    $reservasPm7 = (Leer "SELECT COUNT(*) AS n FROM Reserva r JOIN Persona p ON p.idPersona IN (r.idPersonaRemitente, r.idPersonaDestinatario) WHERE p.numeroDocumento IN ('90000071','90000072','90000073','90000074','90000075','90000076')")[0].n
    Exigir ([int]$reservasPm7 -eq 0) 'no existen reservas ligadas a personas de prueba'
    $tarifa = (Leer "SELECT COUNT(DISTINCT r.tipoEnvio) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.tipoEnvio IN ('SOBRE','PAQUETE') AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa -eq 2) 'MOQ001→AQP001 tiene tarifa vigente de SOBRE y de PAQUETE'
    $tarifa2 = (Leer "SELECT COUNT(*) AS n FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='AQP001' AND d.codigoSucursal='LIM001' AND r.tipoEnvio='PAQUETE' AND r.vigenteHasta IS NULL")[0].n
    Exigir ([int]$tarifa2 -ge 1) 'AQP001→LIM001 tiene tarifa vigente de PAQUETE (envío con transbordo por MOQ001)'
    $sucursal = (Leer "SELECT COUNT(*) AS n FROM Sucursal WHERE codigoSucursal IN ('MOQ001','AQP001','LIM001') AND activa = 1")[0].n
    Exigir ([int]$sucursal -eq 3) 'las sucursales MOQ001, AQP001 y LIM001 existen y están activas'
    $horas = (Leer "SELECT MAX(r.tiempoHoras) AS h FROM Ruta r JOIN Sucursal o ON o.idSucursal=r.idSucursalOrigen JOIN Sucursal d ON d.idSucursal=r.idSucursalDestino WHERE o.codigoSucursal='MOQ001' AND d.codigoSucursal='AQP001' AND r.vigenteHasta IS NULL")[0].h
    Exigir ([int]$horas -gt 0) "MOQ001→AQP001 tiene tiempo estimado ($horas h) para comprobar la llegada estimada"
    $veh = @(Leer "SELECT ve.placa, ve.capacidadKg, ve.activo, (SELECT COUNT(*) FROM Viaje v WHERE v.idVehiculo=ve.idVehiculo AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL) AS enRuta FROM Vehiculo ve WHERE ve.placa IN ('A1X-374','C3M-956','D8F-102')")
    Exigir (@($veh).Count -eq 3 -and @($veh | ? { [int]$_.activo -eq 1 -and [int]$_.enRuta -eq 0 }).Count -eq 3) 'los vehículos A1X-374, C3M-956 y D8F-102 existen, están activos y NINGUNO tiene un viaje en ruta (no se interfiere con viajes originales)'
    Exigir ([decimal](@($veh | ? { $_.placa -eq 'A1X-374' })[0].capacidadKg) -eq 150) 'A1X-374 (moto) admite exactamente 150 kg (para la prueba de capacidad)'
    # Referencia para distinguir lo creado por esta ejecución: sus ids serán mayores que estos.
    foreach ($t in 'Persona', 'Usuario', 'Reserva', 'Envio', 'Viaje', 'Envio_Viaje') { $manifiesto.identAntes[$t] = [int]$antes.tablas.$t.ident }
    Anotar 'documentos' $null

    $escrituraIniciada = $true   # a partir de aquí hay escrituras (cuentas temporales); lo creado queda en el manifiesto
    foreach ($p in 8000..8003) { $servidores += Start-Process $php -ArgumentList '-S', "localhost:$p", '-t', 'backend/public' -WindowStyle Hidden -PassThru }
    Start-Sleep -Seconds 3
    foreach ($p in 8000..8003) { Exigir ([bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "servidor de prueba escuchando en $p" }

    "== 2. Cuentas temporales (contraseñas solo en memoria)"
    $pw = @{ cliente = Clave; dest = Clave; admin = Clave; admin2 = Clave; trans1 = Clave; trans2 = Clave }
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000071'; nombreCompleto = 'Prueba Siete Cliente'; telefono = '900000071'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm7_cliente'; contrasena = $pw.cliente }
    Anotar 'documentos' '90000071'; Anotar 'usuarios' 'pm7_cliente'   # se anota antes de comprobar: si la respuesta falla pero se escribió, la limpieza lo ve
    Exigir ($reg.Estado -eq 201) "pm7_cliente registrado por el endpoint (201) $($reg.Texto)"
    $reg = Llamar POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000074'; nombreCompleto = 'Prueba Siete Destinatario'; telefono = '900000074'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm7_dest'; contrasena = $pw.dest }
    Anotar 'documentos' '90000074'; Anotar 'usuarios' 'pm7_dest'
    Exigir ($reg.Estado -eq 201) "pm7_dest registrado por el endpoint (201) $($reg.Texto)"
    foreach ($d in '90000072', '90000073', '90000075', '90000076') { Anotar 'documentos' $d }
    foreach ($u in 'pm7_admin', 'pm7_admin2', 'pm7_trans1', 'pm7_trans2') { Anotar 'usuarios' $u }
    $env:JATSA_PM7_PW_ADMIN = $pw.admin; $env:JATSA_PM7_PW_ADMIN2 = $pw.admin2; $env:JATSA_PM7_PW_TRANS1 = $pw.trans1; $env:JATSA_PM7_PW_TRANS2 = $pw.trans2
    & $php "$aqui\escritura_pm7.php" crear-personal $manifiestoRuta; $codigoPersonal = $LASTEXITCODE
    $env:JATSA_PM7_PW_ADMIN = $null; $env:JATSA_PM7_PW_ADMIN2 = $null; $env:JATSA_PM7_PW_TRANS1 = $null; $env:JATSA_PM7_PW_TRANS2 = $null
    Exigir ($codigoPersonal -eq 0) 'personal pm7 creado (INSERT de Persona y Usuario: 2 administradores y 2 transportistas)'
    $cCli = Ingresar 'pm7_cliente' $pw.cliente
    $cDest = Ingresar 'pm7_dest' $pw.dest
    # Una sesión por servidor para quien lanza peticiones simultáneas: PHP bloquea el archivo de una misma sesión y serializaría la concurrencia.
    $cAdm = foreach ($i in 0..3) { Ingresar 'pm7_admin' $pw.admin ('800' + $i) }
    $cAdm2 = Ingresar 'pm7_admin2' $pw.admin2
    $cT1 = foreach ($i in 0..3) { Ingresar 'pm7_trans1' $pw.trans1 ('800' + $i) }
    $cT2 = foreach ($i in 0..1) { Ingresar 'pm7_trans2' $pw.trans2 ('800' + $i) }
    $pw = $null
    Exigir ($cCli -and $cDest -and $cAdm2 -and @($cAdm | ? { $_ }).Count -eq 4 -and @($cT1 | ? { $_ }).Count -eq 4 -and @($cT2 | ? { $_ }).Count -eq 2) 'sesiones abiertas (cliente, destinatario, 4 de administrador MOQ001, 1 de AQP001, 4 y 2 de transportista)'
    $yo = (Llamar GET '/api/auth/yo' $null '8000' $cAdm[0]).Json.data.usuario
    Exigir ($yo.rol -eq 'ADMINISTRADOR' -and $yo.sucursal.codigo -eq 'MOQ001') 'pm7_admin es ADMINISTRADOR de MOQ001'
    $yo = (Llamar GET '/api/auth/yo' $null '8000' $cAdm2).Json.data.usuario
    Exigir ($yo.rol -eq 'ADMINISTRADOR' -and $yo.sucursal.codigo -eq 'AQP001') 'pm7_admin2 es ADMINISTRADOR de AQP001'
    $yo = (Llamar GET '/api/auth/yo' $null '8000' $cT1[0]).Json.data.usuario
    Exigir ($yo.rol -eq 'TRANSPORTISTA') 'pm7_trans1 es TRANSPORTISTA'

    "== 3. Permisos"
    V 'cliente no entra a Despacho ni a Viajes (403)' ((@('GET /api/despacho/viajes', 'POST /api/despacho/viajes', 'GET /api/despacho/envios-en-espera', 'GET /api/viajes', 'POST /api/viajes/VJ2026100001/salida') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cCli).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'administrador no entra a Mis viajes ni marca salida o llegada (403)' ((@('GET /api/viajes', 'POST /api/viajes/VJ2026100001/salida', 'POST /api/viajes/VJ2026100001/llegada') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cAdm[0]).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'transportista no entra a Despacho (403)' ((@('GET /api/despacho/viajes', 'POST /api/despacho/viajes', 'GET /api/despacho/opciones') | % { $m, $r = $_ -split ' '; (Llamar $m $r $null '8000' $cT1[0]).Estado } | ? { $_ -ne 403 }).Count -eq 0)
    V 'sin sesión (401) en Despacho y en Viajes' (((Llamar GET '/api/despacho/viajes' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401) -and ((Llamar GET '/api/viajes' $null '8000' 'JATSA_SESION=zzz').Estado -eq 401))

    "== 4. Envíos de prueba (reservas y formalización por los endpoints reales; aún sin viajes)"
    $eCarga = EnvioNuevo $cCli $cAdm[0] 'pm7 carga basica' $BULTO_CHICO
    $eCap6 = EnvioNuevo $cCli $cAdm[0] 'pm7 180 kg' $BULTOS_180
    $eCap5 = EnvioNuevo $cCli $cAdm[0] 'pm7 150 kg' $BULTOS_150
    $eExtra = EnvioNuevo $cCli $cAdm[0] 'pm7 extra chico' $BULTO_CHICO
    $eConc = EnvioNuevo $cCli $cAdm[0] 'pm7 cargas simultaneas del mismo envio' $BULTO_CHICO
    $e60 = 1..4 | % { EnvioNuevo $cCli $cAdm[0] "pm7 60 kg $_" $BULTOS_60 }
    $eTrans = EnvioNuevo $cCli $cAdm2 'pm7 transbordo AQP-MOQ-LIM' $BULTO_CHICO 'AQP001' 'LIM001'
    Exigir (($eCarga -match '^EN[A-Z0-9]{10}$') -and @($e60).Count -eq 4) '10 envíos de prueba formalizados (9 en MOQ001 → AQP001 y 1 en AQP001 → LIM001)'
    V 'ninguna carga existe todavía para los envíos de prueba' ((TotalCargas) -eq [int]$antes.tablas.Envio_Viaje.filas)
    $en = Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]
    $esperan = @($en.Json.data.envios | % codigoSeguimiento)
    V 'envíos en espera de MOQ001: están los de prueba REGISTRADOS y no el de AQP001' (($esperan -contains $eCarga) -and ($esperan -contains $eCap5) -and ($esperan -notcontains $eTrans)) $en.Texto

    "== 5. Programar viaje"
    $nViajes = TotalViajes
    $p1 = Programar $cAdm[0] 'A1X-374' 'pm7_trans1' 'AQP001'
    V '201: viaje PROGRAMADO con código VJ + 10 dígitos' ($p1.Estado -eq 201 -and $p1.Json.data.viaje.estado -eq 'PROGRAMADO' -and $p1.Json.data.viaje.codigo -match '^VJ\d{10}$') $p1.Texto
    $v1 = $p1.Json.data.viaje.codigo
    $bd = ViajeBD $v1
    V 'Viaje en la BD: origen MOQ001 (de la sesión), destino AQP001, moto A1X-374, pm7_trans1, sin salida ni llegada ni carga' ($bd.origen -eq 'MOQ001' -and $bd.destino -eq 'AQP001' -and $bd.placa -eq 'A1X-374' -and $bd.transportista -eq 'pm7_trans1' -and $null -eq $bd.fechaHoraSalida -and $null -eq $bd.fechaHoraLlegada -and [int]$bd.cargas -eq 0) ($bd | ConvertTo-Json -Compress)
    $n1 = (TotalViajes)
    $malos = @((Programar $cAdm[0] 'XX' 'pm7_trans1' 'AQP001'), (Programar $cAdm[0] 'A1X-374' 'nadie123' 'AQP001'), (Programar $cAdm[0] 'A1X-374' 'pm7_trans1' 'MOQ001'), (Programar $cAdm[0] 'ZZZ-999' 'pm7_trans1' 'AQP001'), (Programar $cAdm[0] 'A1X-374' 'pm7_trans1' 'TAC001'))
    V 'placa mal formada, transportista inexistente, destino igual al origen, vehículo inexistente y agencia inactiva: 422' (@($malos | ? { $_.Estado -ne 422 }).Count -eq 0) (($malos | % Estado) -join ',')
    V 'esos rechazos no crearon ningún viaje' ((TotalViajes) -eq $n1)
    $vCap = (Programar $cAdm[0] 'A1X-374' 'pm7_trans2' 'AQP001').Json.data.viaje.codigo
    $vDrv = (Programar $cAdm[0] 'C3M-956' 'pm7_trans1' 'AQP001').Json.data.viaje.codigo
    $vConcCap = (Programar $cAdm[0] 'A1X-374' 'pm7_trans2' 'AQP001').Json.data.viaje.codigo
    Exigir ($vCap -and $vDrv -and $vConcCap) 'viajes programados para las pruebas de capacidad y de conductor'
    "  -- 4 programaciones SIMULTÁNEAS"
    $res = EnParalelo @(foreach ($i in 0..3) { Tarea (8000 + $i) $cAdm[$i] 'POST' '/api/despacho/viajes' @{ placa = 'D8F-102'; transportista = 'pm7_trans2'; destino = 'AQP001' } })
    $res | Select-Object Puerto, Estado, Codigo, ViajeCodigo | Format-Table -AutoSize | Out-String | Write-Host
    $simultaneos = @($res | % ViajeCodigo | ? { $_ })
    V 'las 4 dan 201 con códigos de viaje DISTINTOS (el rango del día se bloquea en la transacción)' ((@($res | ? Estado -eq 201).Count -eq 4) -and (@($simultaneos | Select-Object -Unique).Count -eq 4)) ($res | ConvertTo-Json -Compress)
    V 'BD: 4 viajes más y ningún código repetido' ((TotalViajes) -eq ($n1 + 3 + 4) -and [int](Leer "SELECT COUNT(*) AS n FROM (SELECT codigoViaje FROM Viaje GROUP BY codigoViaje HAVING COUNT(*) > 1) x")[0].n -eq 0)

    "== 6. Cargar y quitar (sin concurrencia)"
    $c = Cargar $cAdm[0] $v1 $eCarga
    V '201: el envío entra al viaje; el viaje suma su peso' ($c.Estado -eq 201 -and $c.Json.data.viaje.cantidadEnvios -eq 1 -and $c.Json.data.viaje.pesoCargadoKg -eq '2.00') $c.Texto
    V 'BD: una fila de Envio_Viaje y el envío sigue REGISTRADO (cambia al salir el viaje)' ((CargasDe $eCarga) -eq 1 -and (EstadoEnvio $eCarga) -eq 'REGISTRADO')
    V 'ya no aparece en «envíos en espera»' ((@((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]).Json.data.envios | % codigoSeguimiento) -notcontains $eCarga))
    $n0 = TotalCargas
    $x = Cargar $cAdm[0] $vCap $eCarga
    V 'el mismo envío en otro viaje: 409 envio_ya_cargado' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'envio_ya_cargado') $x.Texto
    $otra = Cargar $cAdm[0] $v1 $eTrans
    V 'envío de OTRA agencia (AQP001): 404, mismo que uno inexistente' ($otra.Estado -eq 404 -and $otra.Texto -eq (Cargar $cAdm[0] $v1 'ENZZZZZZZZZZ').Texto) $otra.Texto
    V 'viaje inexistente: 404' ((Cargar $cAdm[0] 'VJ2026000001' $eExtra).Estado -eq 404)
    V 'código de envío mal escrito: 422' ((Cargar $cAdm[0] $v1 'XX').Estado -eq 422)
    $x = Cargar $cAdm[0] $vCap $eCap6
    V 'capacidad: 180 kg en una moto de 150 kg → 409 capacidad_excedida' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'capacidad_excedida') $x.Texto
    V 'ninguno de esos rechazos escribió una carga' ((TotalCargas) -eq $n0) "antes=$n0 después=$(TotalCargas)"
    $x = Cargar $cAdm[0] $vCap $eCap5
    V 'justo en la capacidad: 150 kg en la moto de 150 kg → 201' ($x.Estado -eq 201 -and $x.Json.data.viaje.pesoCargadoKg -eq '150.00') $x.Texto
    $x = Cargar $cAdm[0] $vCap $eExtra
    V '150 kg + 2 kg > 150 kg: 409 capacidad_excedida y la carga sigue en 150' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'capacidad_excedida' -and (PesoCargado $vCap) -eq 150)
    $q = Quitar $cAdm[0] $vCap $eCap5
    V 'quitar: 200, el viaje queda sin carga y la fila se borra de Envio_Viaje (solo esa)' ($q.Estado -eq 200 -and $q.Json.data.viaje.cantidadEnvios -eq 0 -and (CargasDe $eCap5) -eq 0 -and (CargasDe $eCarga) -eq 1) $q.Texto
    V 'el envío vuelve a esperar' ((@((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]).Json.data.envios | % codigoSeguimiento) -contains $eCap5))
    V 'quitar otra vez: 404 envio_no_encontrado' ((Quitar $cAdm[0] $vCap $eCap5).Json.error.codigo -eq 'envio_no_encontrado')
    V 'la agencia de destino no puede cargar ni quitar en un viaje ajeno (404) pero sí ver su detalle (200)' (((Cargar $cAdm2 $v1 $eExtra).Estado -eq 404) -and ((Quitar $cAdm2 $v1 $eCarga).Estado -eq 404) -and ((Llamar GET "/api/viajes/$vConcCap" $null '8000' $cAdm2).Estado -eq 200))
    $x = Cargar $cAdm[0] $vCap $eCap5
    Exigir ($x.Estado -eq 201) 'se vuelve a cargar eCap5 en la moto (queda cargado para las pruebas de salida)'

    "== 7. Concurrencia de cargas"
    "  -- 4 cargas SIMULTÁNEAS del MISMO envío en 4 viajes distintos"
    $res = EnParalelo @(foreach ($i in 0..3) { Tarea (8000 + $i) $cAdm[$i] 'POST' "/api/despacho/viajes/$($simultaneos[$i])/carga" @{ codigoSeguimiento = $eConc } })
    $res | Select-Object Puerto, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 201 y el resto 409 envio_ya_cargado' ((@($res | ? Estado -eq 201).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'envio_ya_cargado' }).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'BD: una sola fila de Envio_Viaje para ese envío' ((CargasDe $eConc) -eq 1)
    "  -- 4 envíos de 60 kg SIMULTÁNEOS al MISMO viaje de una moto de 150 kg"
    $res = EnParalelo @(foreach ($i in 0..3) { Tarea (8000 + $i) $cAdm[$i] 'POST' "/api/despacho/viajes/$vConcCap/carga" @{ codigoSeguimiento = $e60[$i] } })
    $res | Select-Object Puerto, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente dos 201 y dos 409 capacidad_excedida (la tercera ya no cabe)' ((@($res | ? Estado -eq 201).Count -eq 2) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'capacidad_excedida' }).Count -eq 2)) ($res | ConvertTo-Json -Compress)
    V 'BD: peso cargado = 120 kg (nunca más que la capacidad de 150 kg)' ((PesoCargado $vConcCap) -eq 120)

    "== 8. Salidas y llegadas"
    $s = Salida $cT1[0] $v1
    V 'pm7_trans1 marca la salida de su viaje: 200 EN_RUTA' ($s.Estado -eq 200 -and $s.Json.data.viaje.estado -eq 'EN_RUTA' -and $s.Json.data.viaje.fechaHoraSalida) $s.Texto
    $bd = ViajeBD $v1
    $seg = ([datetime]$bd.fechaHoraSalida)
    V 'BD: la salida la pone la base (hace menos de 5 minutos) y la llegada sigue vacía' ([int](Leer "SELECT DATEDIFF(SECOND, v.fechaHoraSalida, SYSDATETIME()) AS s FROM Viaje v WHERE v.codigoViaje='$v1'")[0].s -lt 300 -and $null -eq $bd.fechaHoraLlegada) ($bd | ConvertTo-Json -Compress)
    V 'llegada estimada = salida + horas de la ruta' ($s.Json.data.viaje.llegadaEstimada -eq $seg.AddHours([int]$horas).ToString('yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture)) $s.Texto
    V 'el envío cargado pasó a EN_TRANSITO' ((EstadoEnvio $eCarga) -eq 'EN_TRANSITO')
    V 'segunda salida del mismo viaje: 409 viaje_ya_salio' ((Salida $cT1[0] $v1).Json.error.codigo -eq 'viaje_ya_salio')
    V 'otro transportista no puede (404, igual que inexistente); el administrador tampoco (403)' (((Salida $cT2[0] $v1).Estado -eq 404) -and ((Salida $cAdm[0] $v1).Estado -eq 403))
    $n0 = TotalCargas
    V 'un viaje que ya salió no admite carga ni quitar carga (409 viaje_no_programado)' (((Cargar $cAdm[0] $v1 $eExtra).Json.error.codigo -eq 'viaje_no_programado') -and ((Quitar $cAdm[0] $v1 $eCarga).Json.error.codigo -eq 'viaje_no_programado') -and (TotalCargas) -eq $n0)
    $x = Salida $cT2[0] $vCap
    V 'vehículo ocupado: otra moto-viaje (otro conductor) con el vehículo en ruta → 409 vehiculo_ocupado y sin cambios' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'vehiculo_ocupado' -and $null -eq (ViajeBD $vCap).fechaHoraSalida -and (EstadoEnvio $eCap5) -eq 'REGISTRADO') $x.Texto
    $x = Salida $cT1[0] $vDrv
    V 'conductor ocupado: pm7_trans1 ya conduce un viaje en ruta → 409 transportista_ocupado aunque su vehículo (C3M-956) esté libre, y sin cambios' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'transportista_ocupado' -and $null -eq (ViajeBD $vDrv).fechaHoraSalida -and (EnRutaDe 'pm7_trans1') -eq 1) $x.Texto
    V 'llegada de un viaje que no salió: 409 viaje_no_salio; de un viaje ajeno: 404' (((Llegada $cT2[0] $vCap).Json.error.codigo -eq 'viaje_no_salio') -and ((Llegada $cT2[0] $v1).Estado -eq 404))
    $mis = Llamar GET "/api/viajes/$v1" $null '8000' $cT1[0]
    V 'detalle del viaje en ruta: carga con el envío, sin documentos ni teléfonos' ($mis.Estado -eq 200 -and @($mis.Json.data.viaje.carga).Count -eq 1 -and $mis.Json.data.viaje.carga[0].codigoSeguimiento -eq $eCarga -and $mis.Texto -notmatch '9000007|numeroDocumento|telefono') $mis.Texto
    $ll = Terminar $cT1[0] $v1
    V 'llegada: 200 TERMINADO' ($ll.Estado -eq 200 -and $ll.Json.data.viaje.estado -eq 'TERMINADO') $ll.Texto
    $bd = ViajeBD $v1
    V 'BD: llegada posterior a la salida' ($null -ne $bd.fechaHoraLlegada -and [datetime]$bd.fechaHoraLlegada -gt [datetime]$bd.fechaHoraSalida) ($bd | ConvertTo-Json -Compress)
    V 'el envío llegó a su destino final: EN_DESTINO' ((EstadoEnvio $eCarga) -eq 'EN_DESTINO')
    V 'segunda llegada: 409 viaje_ya_llego' ((Llegada $cT1[0] $v1).Json.error.codigo -eq 'viaje_ya_llego')
    V 'el vehículo y el conductor quedaron libres' ((EnRutaDe 'pm7_trans1') -eq 0)

    "== 9. Concurrencia de salidas"
    "  -- el MISMO conductor, DOS viajes con vehículos DISTINTOS, salidas SIMULTÁNEAS"
    $vD1 = (Programar $cAdm[0] 'C3M-956' 'pm7_trans1' 'AQP001').Json.data.viaje.codigo
    $vD2 = (Programar $cAdm[0] 'D8F-102' 'pm7_trans1' 'AQP001').Json.data.viaje.codigo
    Exigir ($vD1 -and $vD2) 'dos viajes de pm7_trans1 con vehículos distintos (C3M-956 y D8F-102)'
    $res = EnParalelo @((Tarea 8000 $cT1[0] 'POST' "/api/viajes/$vD1/salida"), (Tarea 8001 $cT1[1] 'POST' "/api/viajes/$vD2/salida"))
    $res | Select-Object Puerto, Estado, Codigo, Ruta | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 200 y un 409 transportista_ocupado' ((@($res | ? Estado -eq 200).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'transportista_ocupado' }).Count -eq 1)) ($res | ConvertTo-Json -Compress)
    V 'BD: como máximo UN viaje EN_RUTA del conductor' ((EnRutaDe 'pm7_trans1') -eq 1)
    $ganador = if ($null -ne (ViajeBD $vD1).fechaHoraSalida) { $vD1 } else { $vD2 }
    V 'BD: salió exactamente uno de los dos viajes' (($null -ne (ViajeBD $vD1).fechaHoraSalida) -ne ($null -ne (ViajeBD $vD2).fechaHoraSalida))
    $ll = Terminar $cT1[0] $ganador
    Exigir ($ll.Estado -eq 200) "el viaje en ruta ($ganador) termina para liberar al conductor"
    "  -- 4 salidas SIMULTÁNEAS del MISMO viaje (mismo conductor, 4 sesiones)"
    $vS4 = (Programar $cAdm[0] 'C3M-956' 'pm7_trans1' 'AQP001').Json.data.viaje.codigo
    $res = EnParalelo @(foreach ($i in 0..3) { Tarea (8000 + $i) $cT1[$i] 'POST' "/api/viajes/$vS4/salida" })
    $res | Select-Object Puerto, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 200 y el resto 409 viaje_ya_salio' ((@($res | ? Estado -eq 200).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'viaje_ya_salio' }).Count -eq 3)) ($res | ConvertTo-Json -Compress)
    V 'BD: una sola salida registrada y un solo viaje en ruta del conductor' ($null -ne (ViajeBD $vS4).fechaHoraSalida -and (EnRutaDe 'pm7_trans1') -eq 1)
    $ll = Terminar $cT1[0] $vS4
    Exigir ($ll.Estado -eq 200) 'el viaje termina para liberar al conductor y al vehículo'
    "  -- el MISMO vehículo (moto), DOS conductores distintos, salidas SIMULTÁNEAS"
    $vM1 = (Programar $cAdm[0] 'A1X-374' 'pm7_trans1' 'AQP001').Json.data.viaje.codigo
    $vM2 = (Programar $cAdm[0] 'A1X-374' 'pm7_trans2' 'AQP001').Json.data.viaje.codigo
    $res = EnParalelo @((Tarea 8002 $cT1[2] 'POST' "/api/viajes/$vM1/salida"), (Tarea 8000 $cT2[0] 'POST' "/api/viajes/$vM2/salida"))
    $res | Select-Object Puerto, Estado, Codigo | Format-Table -AutoSize | Out-String | Write-Host
    V 'exactamente un 200 y un 409 (vehículo ocupado)' ((@($res | ? Estado -eq 200).Count -eq 1) -and (@($res | ? { $_.Estado -eq 409 -and $_.Codigo -eq 'vehiculo_ocupado' }).Count -eq 1)) ($res | ConvertTo-Json -Compress)
    V 'BD: la moto tiene un solo viaje en ruta' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje v JOIN Vehiculo ve ON ve.idVehiculo=v.idVehiculo WHERE ve.placa='A1X-374' AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL")[0].n -eq 1)
    if ($null -ne (ViajeBD $vM1).fechaHoraSalida) { $ll = Terminar $cT1[2] $vM1 '8002' } else { $ll = Terminar $cT2[0] $vM2 }
    Exigir ($ll.Estado -eq 200) 'el viaje en ruta de la moto termina'

    "== 10. Transbordo AQP001 → MOQ001 → LIM001 (un envío, dos viajes)"
    $vT1 = (Programar $cAdm2 'D8F-102' 'pm7_trans1' 'MOQ001').Json.data.viaje.codigo
    Exigir ($vT1) 'pm7_admin2 (AQP001) programa AQP001 → MOQ001'
    $x = Cargar $cAdm2 $vT1 $eTrans
    V 'el envío de AQP001 se carga en su viaje (201)' ($x.Estado -eq 201) $x.Texto
    V 'MOQ001 todavía no ve ese envío en espera' (@((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]).Json.data.envios | % codigoSeguimiento) -notcontains $eTrans)
    Exigir ((Salida $cT1[0] $vT1).Estado -eq 200) 'pm7_trans1 marca la salida AQP001 → MOQ001'
    V 'en ruta: el envío está EN_TRANSITO y sigue sin aparecer en MOQ001' ((EstadoEnvio $eTrans) -eq 'EN_TRANSITO' -and @((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]).Json.data.envios | % codigoSeguimiento) -notcontains $eTrans)
    $ll = Terminar $cT1[0] $vT1
    V 'llegada a MOQ001 (no es su destino final): el envío SIGUE EN_TRANSITO' ($ll.Estado -eq 200 -and (EstadoEnvio $eTrans) -eq 'EN_TRANSITO')
    $espera = @((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm[0]).Json.data.envios)
    V 'MOQ001 ve el envío en espera (en tránsito, destino final Lima) y AQP001 ya no' ((@($espera | ? { $_.codigoSeguimiento -eq $eTrans -and $_.estado -eq 'EN_TRANSITO' -and $_.destino.codigo -eq 'LIM001' }).Count -eq 1) -and (@((Llamar GET '/api/despacho/envios-en-espera' $null '8000' $cAdm2).Json.data.envios | % codigoSeguimiento) -notcontains $eTrans))
    $vVuelta = (Programar $cAdm[0] 'C3M-956' 'pm7_trans2' 'AQP001').Json.data.viaje.codigo
    $x = Cargar $cAdm[0] $vVuelta $eTrans
    V 'no puede volver a la agencia donde nació: 409 destino_ya_visitado' ($x.Estado -eq 409 -and $x.Json.error.codigo -eq 'destino_ya_visitado' -and (CargasDe $eTrans) -eq 1) $x.Texto
    $vT2 = (Programar $cAdm[0] 'C3M-956' 'pm7_trans2' 'LIM001').Json.data.viaje.codigo
    $x = Cargar $cAdm[0] $vT2 $eTrans
    V 'MOQ001 → LIM001: se carga el envío en tránsito (201) y ahora tiene 2 cargas' ($x.Estado -eq 201 -and (CargasDe $eTrans) -eq 2) $x.Texto
    V 'detalle visible para origen y destino del viaje y no para otra agencia' (((Llamar GET "/api/viajes/$vT1" $null '8000' $cAdm2).Estado -eq 200) -and ((Llamar GET "/api/viajes/$vT1" $null '8000' $cAdm[0]).Estado -eq 200) -and ((Llamar GET "/api/viajes/$vT2" $null '8000' $cAdm2).Estado -eq 404))
    Exigir ((Salida $cT2[0] $vT2).Estado -eq 200) 'pm7_trans2 marca la salida MOQ001 → LIM001'
    $ll = Terminar $cT2[0] $vT2
    V 'llegada a LIM001 (destino final): el envío pasa a EN_DESTINO' ($ll.Estado -eq 200 -and (EstadoEnvio $eTrans) -eq 'EN_DESTINO')

    "== 11. Mis viajes (contra la BD)"
    foreach ($par in @(@('pm7_trans1', $cT1[0]), @('pm7_trans2', $cT2[0]))) {
        $nombre = $par[0]
        $api = Llamar GET '/api/viajes' $null '8000' $par[1]
        $codApi = @($api.Json.data.viajes | % codigo | Sort-Object)
        $codBd = @(Leer "SELECT v.codigoViaje FROM Viaje v JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE u.nombreUsuario='$nombre'" | % codigoViaje | Sort-Object)
        V "$nombre ve exactamente sus viajes (los de la BD) y ninguno ajeno" ($codApi.Count -gt 0 -and ($codApi -join ',') -eq ($codBd -join ',') -and $api.Json.data.hayMas -eq $false) "api=$($codApi -join ',') bd=$($codBd -join ',')"
        $term = @((Llamar GET '/api/viajes?estado=TERMINADO' $null '8000' $par[1]).Json.data.viajes | % codigo | Sort-Object)
        $termBd = @(Leer "SELECT v.codigoViaje FROM Viaje v JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE u.nombreUsuario='$nombre' AND v.fechaHoraLlegada IS NOT NULL" | % codigoViaje | Sort-Object)
        V "$nombre : el filtro TERMINADO coincide con la BD" (($term -join ',') -eq ($termBd -join ',')) "api=$($term -join ',') bd=$($termBd -join ',')"
    }
    V 'el orden es: en ruta, programados por código y terminados (ningún programado va después de un terminado)' ((@((Llamar GET '/api/viajes' $null '8000' $cT2[0]).Json.data.viajes | % estado) -join ',') -match '^(EN_RUTA,)*(PROGRAMADO,)*(PROGRAMADO|TERMINADO)(,TERMINADO)*$')
    V 'filtro inválido: 422' ((Llamar GET '/api/viajes?estado=CANCELADO' $null '8000' $cT1[0]).Estado -eq 422)
    V 'el viaje de un transportista es 404 para el otro' ((Llamar GET "/api/viajes/$v1" $null '8000' $cT2[0]).Estado -eq 404)

    "== 12. Consistencia global de lo creado en esta ejecución"
    $iV = $manifiesto.identAntes.Viaje; $iE = $manifiesto.identAntes.Envio; $iC = $manifiesto.identAntes.Envio_Viaje
    V 'no queda ningún viaje en ruta de las cuentas de prueba' ((EnRutaDe 'pm7_trans1') -eq 0 -and (EnRutaDe 'pm7_trans2') -eq 0)
    V 'ningún conductor de prueba tuvo dos viajes en ruta a la vez (se comprueba por solapamiento de fechas)' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje a JOIN Viaje b ON a.idUsuarioTransportista=b.idUsuarioTransportista AND a.idViaje < b.idViaje WHERE a.idViaje > $iV AND b.idViaje > $iV AND a.fechaHoraSalida IS NOT NULL AND b.fechaHoraSalida IS NOT NULL AND a.fechaHoraSalida < COALESCE(b.fechaHoraLlegada, '9999-12-31') AND b.fechaHoraSalida < COALESCE(a.fechaHoraLlegada, '9999-12-31')")[0].n -eq 0)
    V 'ningún vehículo de prueba tuvo dos viajes en ruta a la vez (solapamiento de fechas)' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje a JOIN Viaje b ON a.idVehiculo=b.idVehiculo AND a.idViaje < b.idViaje WHERE a.idViaje > $iV AND b.idViaje > $iV AND a.fechaHoraSalida IS NOT NULL AND b.fechaHoraSalida IS NOT NULL AND a.fechaHoraSalida < COALESCE(b.fechaHoraLlegada, '9999-12-31') AND b.fechaHoraSalida < COALESCE(a.fechaHoraLlegada, '9999-12-31')")[0].n -eq 0)
    V 'ninguna carga nueva toca viajes o envíos anteriores a la prueba (no se tocó nada original)' ([int](Leer "SELECT COUNT(*) AS n FROM Envio_Viaje WHERE idEnvioViaje > $iC AND (idViaje <= $iV OR idEnvio <= $iE)")[0].n -eq 0)
    V 'ningún viaje de prueba supera la capacidad de su vehículo' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje v JOIN Vehiculo ve ON ve.idVehiculo=v.idVehiculo WHERE v.idViaje > $iV AND (SELECT COALESCE(SUM(b.pesoKg),0) FROM Envio_Viaje ev JOIN Bulto b ON b.idEnvio=ev.idEnvio WHERE ev.idViaje=v.idViaje) > ve.capacidadKg")[0].n -eq 0)
    V 'llegada siempre posterior a la salida y códigos VJ + 10 dígitos' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje WHERE idViaje > $iV AND ((fechaHoraLlegada IS NOT NULL AND (fechaHoraSalida IS NULL OR fechaHoraLlegada <= fechaHoraSalida)) OR codigoViaje NOT LIKE 'VJ[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')")[0].n -eq 0)
    V 'todo Viaje nuevo está anotado en el manifiesto (nada sin registrar)' ([int](Leer "SELECT COUNT(*) AS n FROM Viaje WHERE idViaje > $iV")[0].n -eq @($manifiesto.viajes).Count) "bd=$((Leer "SELECT COUNT(*) AS n FROM Viaje WHERE idViaje > $iV")[0].n) manifiesto=$(@($manifiesto.viajes).Count)"
    V 'no hay Envio sin Bulto ni estados de envío fuera del flujo entre los de la prueba' ([int](Leer "SELECT COUNT(*) AS n FROM Envio e WHERE e.idEnvio > $iE AND (NOT EXISTS (SELECT 1 FROM Bulto b WHERE b.idEnvio=e.idEnvio) OR e.estadoEnvio NOT IN ('REGISTRADO','EN_TRANSITO','EN_DESTINO'))")[0].n -eq 0)
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
            & $php "$aqui\escritura_pm7.php" limpiar $manifiestoRuta
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
            V 'las 12 tablas con las mismas filas y contenido que antes (incluidas Viaje, Envio_Viaje y Envio)' $igual
            $sinRestos = (@($despues.restos.personas_pm7).Count -eq 0 -and @($despues.restos.usuarios_pm | ? { $_ -like 'pm7*' }).Count -eq 0)
            V '0 registros pm7' $sinRestos
            # Solo se informa limpieza completa si se COMPROBÓ: tablas idénticas y sin restos pm7.
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
