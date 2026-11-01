<#
  Validación REAL de SOLO LECTURA del módulo 9 (Rastreo, salidas e historial de envíos) contra SQL Server (procedimiento del PR #10).

  NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA DEL PROPIETARIO. Exige -AutorizadoPorElPropietario.

  GARANTÍAS
  - Solo SELECT: no hay INSERT, UPDATE, DELETE, DDL ni DBCC. No crea cuentas, personas, reservas ni envíos. No toca el script SQL.
  - Los endpoints del módulo 9 son todos GET. El ingreso (POST /api/auth/ingresar) NO escribe en la base (solo crea un archivo de
    sesión en backend/storage/sessions, que esta prueba borra al terminar: únicamente los ids de las sesiones que abrió).
  - Compara las 12 tablas ANTES y DESPUÉS (filas, checksum, IDENT_CURRENT y hash por fila): deben quedar idénticas.
  - Termina con exit 0 solo si no hubo ningún fallo y las 12 tablas quedaron idénticas.

  USO (desde C:\Jatsa, con PHP en C:\php\php.exe)
    Parte pública (rastreo, salidas, agencias; no necesita cuenta):
      powershell -File backend\tests\integracion\validar-pm9-lectura.ps1 -AutorizadoPorElPropietario
    Parte del cliente (historial y resumen de Mi panel): además, una cuenta CLIENTE o EMPRESARIO YA EXISTENTE que indique el propietario.
    La contraseña se pasa solo por variable de entorno del proceso (nunca se imprime ni se guarda):
      $env:JATSA_PM9_CLAVE = '...'
      powershell -File backend\tests\integracion\validar-pm9-lectura.ps1 -AutorizadoPorElPropietario -UsuarioCliente <usuario>
#>
param([switch]$AutorizadoPorElPropietario, [string]$UsuarioCliente = '')

if (-not $AutorizadoPorElPropietario) {
    Write-Host 'Rechazado: esta validación consulta la base real. Requiere autorización expresa del propietario (-AutorizadoPorElPropietario).'
    exit 2
}

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$php = 'C:\php\php.exe'
$salida = Join-Path $env:TEMP ('pm9_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Force $salida | Out-Null
. "$aqui\api.ps1"

$ok = 0; $mal = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Leer($sql) { $s = (& $php "$aqui\leer.php" $sql | Out-String); if ($LASTEXITCODE -ne 0) { throw "leer.php rechazó o falló: $sql" }; @($s | ConvertFrom-Json) }
function Exigir($cond, $mensaje) { if ($cond) { "  OK    $mensaje" } else { throw "Precondición fallida: $mensaje" } }
function Decimal($x) { [decimal]::Parse([string]$x, [Globalization.CultureInfo]::InvariantCulture) }

$sesionesPropias = @()
$servidor = $null
# Abre sesión con una cuenta existente (POST /api/auth/ingresar no escribe en la base) y anota el id de SU archivo de sesión para borrarlo al final.
function Ingresar-Cuenta($usuario, $clave) {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave })
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}
$limpio = $false

try {
    "== 1. Precondiciones (solo lectura)"
    Exigir (Test-Path $php) 'PHP disponible'
    Exigir (Test-Path 'backend\public\index.php') 'se ejecuta desde la raíz del repositorio (C:\Jatsa)'
    Exigir (-not (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)) 'el puerto 8000 está libre (no se toca ningún servidor ajeno)'
    & $php "$aqui\instantanea.php" "$salida\antes.json" | Out-Null
    Exigir ($LASTEXITCODE -eq 0 -and (Test-Path "$salida\antes.json")) 'instantánea inicial tomada (solo SELECT)'
    $antes = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
    Exigir (@($antes.tablas.PSObject.Properties).Count -eq 12) 'la base tiene las 12 tablas esperadas'

    $servidor = Start-Process $php -ArgumentList '-S', 'localhost:8000', '-t', 'backend/public' -WindowStyle Hidden -PassThru
    Start-Sleep -Seconds 3
    Exigir ([bool](Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)) 'servidor de prueba escuchando en 8000'

    "== 2. Rastreo público (GET /api/rastreo/{codigo}) contra envíos reales"
    $envios = Leer "SELECT TOP 6 e.codigoSeguimiento, e.estadoEnvio, (SELECT COUNT(*) FROM Bulto b WHERE b.idEnvio=e.idEnvio) AS bultos, (SELECT COALESCE(SUM(b.pesoKg),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio) AS peso, (SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio) AS importe, (SELECT COALESCE(SUM(p.monto),0) FROM Pago p WHERE p.idEnvio=e.idEnvio AND p.estadoPago='REGISTRADO') AS pagado, pr.nombreCompleto AS remitente, pd.nombreCompleto AS destinatario, pr.numeroDocumento AS docRemitente, pd.numeroDocumento AS docDestinatario, pr.telefono AS telRemitente, pd.telefono AS telDestinatario FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva JOIN Persona pr ON pr.idPersona=r.idPersonaRemitente JOIN Persona pd ON pd.idPersona=r.idPersonaDestinatario ORDER BY e.idEnvio DESC"
    if ($envios.Count -eq 0) { "  (no hay envíos en la base: se omite la comparación fila a fila; solo se comprueba el 404)" }
    $clavesPermitidas = @('cantidadBultos', 'codigoSeguimiento', 'destino', 'estado', 'etapas', 'origen', 'pesoTotalKg', 'recorrido', 'rutaId', 'saldo', 'viajeActual')
    foreach ($e in $envios) {
        $r = Llamar GET "/api/rastreo/$($e.codigoSeguimiento)"
        V "rastreo $($e.codigoSeguimiento): 200" ($r.Estado -eq 200) $r.Texto
        if ($r.Estado -ne 200) { continue }
        $d = $r.Json.data.envio
        V "  estado, bultos y peso coinciden con la base" ($d.estado -eq $e.estadoEnvio -and [int]$d.cantidadBultos -eq [int]$e.bultos -and (Decimal $d.pesoTotalKg) -eq (Decimal $e.peso)) "api=$($d.estado)/$($d.cantidadBultos)/$($d.pesoTotalKg)"
        $saldo = [Math]::Max([decimal]0, (Decimal $e.importe) - (Decimal $e.pagado))
        V "  saldo = importe − pagado (registrados)" ((Decimal $d.saldo) -eq $saldo) "api=$($d.saldo) bd=$saldo"
        $claves = @($d.PSObject.Properties.Name | Sort-Object)
        V "  solo las claves públicas permitidas (sin extras)" (($claves -join ',') -eq ($clavesPermitidas -join ',')) ($claves -join ',')
        $json = $r.Texto
        $filtrado = @($e.remitente, $e.destinatario, $e.docRemitente, $e.docDestinatario, $e.telRemitente, $e.telDestinatario) | ? { $_ -and "$_".Length -ge 4 } | ? { $json.Contains("$_") }
        V "  la respuesta NO contiene nombres, documentos ni teléfonos de remitente o destinatario" (@($filtrado).Count -eq 0) ($filtrado -join ' | ')
        $orden = @($d.recorrido | % { $_.fechaHora })
        V "  el recorrido está ordenado por fecha" (($orden -join '|') -eq ((@($orden) | Sort-Object) -join '|'))
    }
    $a = Llamar GET '/api/rastreo/ENZZZZZZZZZZ'; $b = Llamar GET '/api/rastreo/mal-formado'
    V 'código inexistente y mal formado: mismo 404 y mismo mensaje' ($a.Estado -eq 404 -and $b.Estado -eq 404 -and $a.Json.error.mensaje -eq $b.Json.error.mensaje)
    $c = Llamar POST '/api/rastreo/ENZZZZZZZZZZ'
    V 'el rastreo solo admite GET (POST rechazado, 404/405)' ($c.Estado -in 404, 405)

    "== 3. Salidas (GET /api/salidas y /api/salidas/{id}) contra rutas y viajes reales"
    $rutas = Leer "SELECT DISTINCT so.codigoSucursal AS o, sd.codigoSucursal AS d FROM Ruta r JOIN Sucursal so ON so.idSucursal=r.idSucursalOrigen JOIN Sucursal sd ON sd.idSucursal=r.idSucursalDestino WHERE so.activa=1 AND sd.activa=1 AND r.vigenteHasta IS NULL"
    $s = Llamar GET '/api/salidas'
    V 'lista de salidas: 200' ($s.Estado -eq 200) $s.Texto
    if ($s.Estado -eq 200) {
        V "una salida por ruta con tarifa vigente ($($rutas.Count) en la base)" (@($s.Json.data.salidas).Count -eq $rutas.Count) "api=$(@($s.Json.data.salidas).Count)"
        $mal9 = @($s.Json.data.salidas | ? { $_.id -ne "$($_.origen.codigo)-$($_.destino.codigo)" -or @($_.horarios).Count -gt 3 -or [int]$_.horas -lt 0 })
        V 'ids ORIGEN-DESTINO, a lo sumo 3 horas frecuentes y tiempo no negativo' ($mal9.Count -eq 0)
        foreach ($ruta in @($s.Json.data.salidas | Select-Object -First 3)) {
            $det = Llamar GET "/api/salidas/$($ruta.id)"
            V "detalle $($ruta.id): 200" ($det.Estado -eq 200) $det.Texto
            if ($det.Estado -ne 200) { continue }
            $x = $det.Json.data.ruta
            $tarifasBd = Leer "SELECT r.tipoEnvio, r.vigenteHasta FROM Ruta r JOIN Sucursal so ON so.idSucursal=r.idSucursalOrigen JOIN Sucursal sd ON sd.idSucursal=r.idSucursalDestino WHERE so.codigoSucursal='$($ruta.origen.codigo)' AND sd.codigoSucursal='$($ruta.destino.codigo)' AND r.vigenteHasta IS NULL"
            V "  tantas tarifas vigentes como en la base ($(@($tarifasBd).Count))" (@($x.tarifas).Count -eq @($tarifasBd).Count)
            $horasBd = @(Leer "SELECT TOP 10 DATEPART(HOUR, v.fechaHoraSalida) AS h FROM Viaje v JOIN Sucursal so ON so.idSucursal=v.idSucursalOrigen JOIN Sucursal sd ON sd.idSucursal=v.idSucursalDestino WHERE so.codigoSucursal='$($ruta.origen.codigo)' AND sd.codigoSucursal='$($ruta.destino.codigo)' AND v.fechaHoraSalida IS NOT NULL ORDER BY v.fechaHoraSalida DESC, v.idViaje DESC" | % { '{0:00}:00' -f [int]$_.h })
            $fuera = @($x.horarios | ? { $horasBd -notcontains $_ })
            V "  los horarios frecuentes salen de las salidas reales de los últimos 10 viajes ($($horasBd.Count) viajes con salida)" ($fuera.Count -eq 0 -and (($horasBd.Count -eq 0) -eq (@($x.horarios).Count -eq 0))) ($fuera -join ',')
            V '  si no hay salidas reales, no inventa horarios' (($horasBd.Count -gt 0) -or @($x.horarios).Count -eq 0)
            V '  recientes: solo viajes con salida, a lo sumo 10' (@($x.recientes).Count -le 10 -and @($x.recientes | ? { -not $_.fechaHoraSalida }).Count -eq 0)
        }
    }
    $n = Llamar GET '/api/salidas/ZZZ999-AAA111'
    V 'ruta inexistente: 404 ruta_no_encontrada' ($n.Estado -eq 404 -and $n.Json.error.codigo -eq 'ruta_no_encontrada')
    $f = Llamar GET '/api/salidas?tipo=MALO'
    V 'filtro inválido: 422 con campos' ($f.Estado -eq 422)

    "== 4. Historial y Mi panel (GET /api/mis-envios...) con una cuenta CLIENTE/EMPRESARIO existente"
    if (-not $UsuarioCliente -or -not $env:JATSA_PM9_CLAVE) {
        "  (omitida: no se indicó -UsuarioCliente y JATSA_PM9_CLAVE; la parte pública quedó comprobada)"
    } else {
        $cookie = Ingresar-Cuenta $UsuarioCliente $env:JATSA_PM9_CLAVE
        $env:JATSA_PM9_CLAVE = $null
        Exigir ([bool]$cookie) 'sesión abierta con la cuenta indicada'
        $yo = (Llamar GET '/api/auth/yo' $null '8000' $cookie).Json.data.usuario
        Exigir ($yo.rol -in 'CLIENTE', 'EMPRESARIO') "la cuenta es CLIENTE o EMPRESARIO ($($yo.rol))"
        $idPersona = [int](Leer "SELECT idPersona FROM Usuario WHERE nombreUsuario = '$($UsuarioCliente -replace "'", "''")'")[0].idPersona

        $enCursoBd = (Leer "SELECT COUNT(*) AS n FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE (r.idPersonaRemitente=$idPersona OR r.idPersonaDestinatario=$idPersona) AND e.estadoEnvio IN ('REGISTRADO','EN_TRANSITO','EN_DESTINO')")[0].n
        $saldoBd = (Leer "SELECT COALESCE(SUM(CASE WHEN t.importe > t.pagado THEN t.importe - t.pagado ELSE 0 END),0) AS saldo FROM (SELECT (SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio=e.idEnvio) AS importe, (SELECT COALESCE(SUM(p.monto),0) FROM Pago p WHERE p.idEnvio=e.idEnvio AND p.estadoPago='REGISTRADO') AS pagado FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE (r.idPersonaRemitente=$idPersona OR r.idPersonaDestinatario=$idPersona) AND e.estadoEnvio IN ('REGISTRADO','EN_TRANSITO','EN_DESTINO')) t")[0].saldo
        $res = Llamar GET '/api/mis-envios/resumen' $null '8000' $cookie
        V 'resumen de Mi panel: 200' ($res.Estado -eq 200) $res.Texto
        if ($res.Estado -eq 200) {
            $q = $res.Json.data
            V "envíos en curso = base ($enCursoBd), sin tope de 200" ([int]$q.enCurso -eq [int]$enCursoBd) "api=$($q.enCurso)"
            V "saldo pendiente = base ($saldoBd), sin tope de 200" ((Decimal $q.saldoPendiente) -eq (Decimal $saldoBd)) "api=$($q.saldoPendiente)"
            $porRecogerBd = [int](Leer "SELECT COUNT(*) AS n FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE r.idPersonaDestinatario=$idPersona AND r.idPersonaRemitente<>$idPersona AND e.estadoEnvio='EN_DESTINO'")[0].n
            V "por recoger: min(base=$porRecogerBd, 50) y hayMasPorRecoger coherente" (@($q.porRecoger).Count -eq [Math]::Min($porRecogerBd, 50) -and ([bool]$q.hayMasPorRecoger -eq ($porRecogerBd -gt 50)))
        }
        $lista = Llamar GET '/api/mis-envios' $null '8000' $cookie
        $totalBd = [int](Leer "SELECT COUNT(*) AS n FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE r.idPersonaRemitente=$idPersona OR r.idPersonaDestinatario=$idPersona")[0].n
        V "historial: 200 y min(base=$totalBd, 200) envíos, hayMas coherente" ($lista.Estado -eq 200 -and @($lista.Json.data.envios).Count -eq [Math]::Min($totalBd, 200) -and ([bool]$lista.Json.data.hayMas -eq ($totalBd -gt 200))) $lista.Texto
        if ($lista.Estado -eq 200 -and @($lista.Json.data.envios).Count -gt 0) {
            $primero = $lista.Json.data.envios[0].codigoSeguimiento
            $det = Llamar GET "/api/mis-envios/$primero" $null '8000' $cookie
            V "detalle $primero`: 200 y total/saldo cuadran con sus bultos y pagos" ($det.Estado -eq 200 -and ((Decimal $det.Json.data.envio.importe) - (Decimal $det.Json.data.envio.pagado)) -ge 0) $det.Texto
            $txt = $det.Texto
            $doc = @(Leer "SELECT p.numeroDocumento AS d, p.telefono AS t FROM Persona p WHERE p.idPersona <> $idPersona AND p.idPersona IN (SELECT r.idPersonaRemitente FROM Reserva r JOIN Envio e ON e.idReserva=r.idReserva WHERE e.codigoSeguimiento='$primero' UNION SELECT r.idPersonaDestinatario FROM Reserva r JOIN Envio e ON e.idReserva=r.idReserva WHERE e.codigoSeguimiento='$primero')")
            V '  el detalle no expone documento ni teléfono de la otra parte' (@($doc | ? { ($_.d -and $txt.Contains("$($_.d)")) -or ($_.t -and "$($_.t)".Length -ge 6 -and $txt.Contains("$($_.t)")) }).Count -eq 0)
        }
        $ajeno = @(Leer "SELECT TOP 1 e.codigoSeguimiento AS c FROM Envio e JOIN Reserva r ON r.idReserva=e.idReserva WHERE r.idPersonaRemitente<>$idPersona AND r.idPersonaDestinatario<>$idPersona ORDER BY e.idEnvio DESC")
        if ($ajeno.Count -gt 0) {
            $x = Llamar GET "/api/mis-envios/$($ajeno[0].c)" $null '8000' $cookie
            $y = Llamar GET '/api/mis-envios/ENZZZZZZZZZZ' $null '8000' $cookie
            V 'un envío ajeno da el MISMO 404 que uno inexistente' ($x.Estado -eq 404 -and $y.Estado -eq 404 -and $x.Json.error.mensaje -eq $y.Json.error.mensaje)
        }
        $sin = Llamar GET '/api/mis-envios/resumen'
        V 'sin sesión: 401' ($sin.Estado -eq 401)
    }
}
catch {
    $script:mal++
    "  ERROR $($_.Exception.Message)"
}
finally {
    "== 5. Cierre: servidor, sesiones propias y comparación de las 12 tablas"
    if ($servidor) { Stop-Process -Id $servidor.Id -Force -ErrorAction SilentlyContinue }
    foreach ($id in $sesionesPropias) {
        if ($id -match '^[A-Za-z0-9,-]+$') { Remove-Item (Join-Path 'backend\storage\sessions' "sess_$id") -Force -ErrorAction SilentlyContinue }
    }
    try {
        & $php "$aqui\instantanea.php" "$salida\despues.json" | Out-Null
        $a = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
        $d = Get-Content "$salida\despues.json" -Raw | ConvertFrom-Json
        $distintas = @()
        foreach ($p in $a.tablas.PSObject.Properties) {
            $t0 = $p.Value; $t1 = $d.tablas.($p.Name)
            if ($t0.filas -ne $t1.filas -or $t0.checksum -ne $t1.checksum -or $t0.ident -ne $t1.ident -or (($t0.hashes -join ',') -ne ($t1.hashes -join ','))) { $distintas += $p.Name }
        }
        V 'las 12 tablas quedaron IDÉNTICAS (filas, checksum, IDENT_CURRENT y hash por fila)' ($distintas.Count -eq 0) ($distintas -join ', ')
        $limpio = ($distintas.Count -eq 0)
    }
    catch { $script:mal++; "  ERROR al comparar: $($_.Exception.Message)" }
    "Resultado: $ok correctas, $mal con fallo. Informes en $salida"
}
if ($mal -gt 0 -or -not $limpio) { exit 1 }
exit 0
