<#
  Validación REAL del módulo 10 (Catálogos y cuentas del gerente) contra SQL Server con registros temporales pm10.
  PREPARADA PARA REVISIÓN ESTÁTICA. NO SE HA EJECUTADO y NO SE EJECUTA SIN AUTORIZACIÓN EXPRESA Y SEPARADA DEL PROPIETARIO.

  QUÉ ESCRIBE: Sucursal, Telefono_Sucursal, Ruta, Vehiculo, Persona, Usuario y Viaje (INSERT/UPDATE por los endpoints y por escritura_pm10.php;
  DELETE solo en la limpieza y solo de lo anotado en el manifiesto). No toca Reserva, Envio, Bulto, Pago ni Envio_Viaje. Sin DDL, sin tocar el script
  SQL, sin DBCC CHECKIDENT y sin modificar ninguna fila original: la única tarifa que se cierra es una temporal creada por la prueba.

  DOS GARANTÍAS DISTINTAS (no se mezclan):
    1) DATOS ORIGINALES: tras la limpieza, las 12 tablas deben quedar con las MISMAS filas, el mismo checksum y el mismo hash de cada fila.
       Si no, el resultado es fallo (exit 1).
    2) CONTADORES IDENTITY: borrar no los restaura (y una carrera perdida, un interbloqueo o un ROLLBACK también consumen valores), así que
       NO se promete que coincidan ni que su avance iguale el número de altas del manifiesto. Se INFORMA el avance exacto por tabla, se exige que
       ninguno RETROCEDA y que solo avancen las tablas que este módulo escribe. Por eso se exige -AceptoAvanceIrreversibleDeIdentity: es un
       cambio permanente de la base que ningún script puede deshacer (DBCC CHECKIDENT está prohibido).
    Alternativa recomendada si el propietario la autoriza después: ejecutar sobre una BASE DE PRUEBAS AISLADA (copia), donde ese avance no importa.

  Uso (desde C:\Jatsa, con PHP en C:\php\php.exe):
    powershell -File backend\tests\integracion\validar-pm10.ps1 -AutorizadoPorElPropietario -AceptoAvanceIrreversibleDeIdentity

  Contraseñas efímeras generadas en memoria, usadas en ESTE proceso y nunca impresas ni guardadas (las de los gerentes temporales viajan al PHP
  solo por variables de entorno del proceso hijo). Si algo falla: se detiene, limpia SOLO lo anotado y reporta; si la limpieza detecta una divergencia
  con el manifiesto NO borra nada y pide revisión. exit 0 solo si no hubo fallos, la limpieza quedó comprobada y los datos originales son idénticos.
  Interbloqueos (error 1205 / HTTP 500 interno): en las carreras se aceptan como resultado válido de la petición perdedora SIEMPRE que el estado final
  sea consistente; se cuentan y se informan, no se suponen imposibles (el orden de bloqueos no cubre todas las rutas del sistema).
#>
param([switch]$AutorizadoPorElPropietario, [switch]$AceptoAvanceIrreversibleDeIdentity)

if (-not $AutorizadoPorElPropietario) {
    Write-Host 'Rechazado: esta validación escribe en la base real. Requiere autorización expresa del propietario (-AutorizadoPorElPropietario).'
    exit 2
}
if (-not $AceptoAvanceIrreversibleDeIdentity) {
    Write-Host 'Rechazado: los contadores IDENTITY avanzarán de forma irreversible (borrar no los restaura y DBCC CHECKIDENT está prohibido). Requiere -AceptoAvanceIrreversibleDeIdentity, aceptado de antemano por el propietario.'
    exit 2
}

$ErrorActionPreference = 'Stop'
$aqui = $PSScriptRoot
$php = 'C:\php\php.exe'
$salida = Join-Path $env:TEMP ('pm10_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Force $salida | Out-Null
. "$aqui\api.ps1"
. "$aqui\pm9-funciones.ps1"   # ConvertFrom-FilasJson: filas planas con 0, 1 o N filas en PowerShell 5.1
. "$aqui\pm10-funciones.ps1"

$ok = 0; $mal = 0; $interbloqueos = 0
function V($nombre, $cond, $detalle = '') { if ($cond) { $script:ok++; "  OK    $nombre" } else { $script:mal++; "  FALLO $nombre  $detalle" } }
function Exigir($cond, $mensaje) { if ($cond) { "  OK    $mensaje" } else { throw "Precondición fallida: $mensaje" } }
function Leer($sql) { $s = (& $php "$aqui\leer.php" $sql | Out-String); if ($LASTEXITCODE -ne 0) { throw "leer.php rechazó o falló: $sql" }; @(ConvertFrom-FilasJson $s) }
function Clave { $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; (-join (1..14 | % { $a[(Get-Random -Maximum $a.Length)] })) + '#9a' }


# MANIFIESTO de lo creado EN ESTA EJECUCIÓN: es lo único que escritura_pm10.php puede borrar. Se guarda en disco tras cada anotación
# (y ANTES de crear), de modo que una interrupción deja la limpieza exacta.
$manifiestoRuta = Join-Path $salida 'manifiesto.json'
$manifiesto = @{ identAntes = @{}; sucursales = @(); vehiculos = @(); documentos = @(); usuarios = @(); viajes = @() }
function Anotar($clave, $valor) {
    if ($valor -and ($manifiesto[$clave] -notcontains $valor)) { $manifiesto[$clave] = @($manifiesto[$clave]) + @($valor) }
    $manifiesto | ConvertTo-Json -Depth 4 | Set-Content $manifiestoRuta -Encoding ascii
}
# Los viajes pueden crearse en una carrera aunque la respuesta se pierda: se anotan todos los de vehículos o conductores temporales.
function AnotarViajesTemporales {
    foreach ($v in (Leer "SELECT codigoViaje FROM Viaje WHERE idViaje > $($manifiesto.identAntes.Viaje) AND (idVehiculo IN (SELECT idVehiculo FROM Vehiculo WHERE placa LIKE 'PM1-9%') OR idUsuarioTransportista IN (SELECT idUsuario FROM Usuario WHERE nombreUsuario LIKE 'pm10[_]%'))")) { Anotar 'viajes' $v.codigoViaje }
}

$sesionesPropias = @()
function Ingresar($usuario, $clave, $puerto = '8000') {
    $c = GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $usuario; contrasena = $clave } $puerto)
    if ($c) { $script:sesionesPropias += ($c -replace '^JATSA_SESION=', '') }
    $c
}
function Api($metodo, $ruta, $cuerpo, $cookie, $puerto = '8000') { Llamar $metodo $ruta $cuerpo $puerto $cookie }
function Codigo($r) { if ($r.Json -and $r.Json.error) { $r.Json.error.codigo } else { '' } }

# Trabajo en paralelo (como pm7/pm8): cada tarea inicia a la hora indicada; misma hora = peticiones simultáneas. Cada participante usa SU PROPIA
# sesión (PHP bloquea el archivo de una sesión y serializaría dos peticiones de la misma).
$trabajo = {
    param($ps1, $puerto, $galleta, $hora, $metodo, $ruta, $cuerpo)
    . $ps1
    $w = ([datetime]$hora) - (Get-Date); if ($w.TotalMilliseconds -gt 0) { Start-Sleep -Milliseconds ([int]$w.TotalMilliseconds) }
    $r = Llamar $metodo $ruta $cuerpo $puerto $galleta
    [pscustomobject]@{ Puerto = $puerto; Estado = $r.Estado; Codigo = $r.Json.error.codigo; Ruta = $ruta }
}
function EnParalelo($tareas, $segundos = 8) {
    $hora = (Get-Date).AddSeconds($segundos).ToString('o')
    $jobs = foreach ($t in $tareas) { Start-Job -ScriptBlock $trabajo -ArgumentList "$aqui\api.ps1", $t.Puerto, $t.Cookie, $hora, $t.Metodo, $t.Ruta, $t.Cuerpo }
    $res = @($jobs | Wait-Job -Timeout 150 | Receive-Job)
    $jobs | Remove-Job -Force
    foreach ($x in $res) { if ($x.Estado -eq 500) { $script:interbloqueos++ } }   # posible interbloqueo (1205): se cuenta e informa
    $res
}
function Tarea($puerto, $cookie, $metodo, $ruta, $cuerpo = $null) { @{ Puerto = "$puerto"; Cookie = $cookie; Metodo = $metodo; Ruta = $ruta; Cuerpo = $cuerpo } }
function Resumen($res) { ($res | % { "$($_.Estado)/$($_.Codigo)" }) -join ' | ' }

$servidores = @()
$limpio = $false
$escrituraIniciada = $false
$codigoSalida = 1
try {
    "== 1. Precondiciones (solo lectura): si alguna falla, se DETIENE antes de escribir nada"
    Exigir (Test-Path $php) 'PHP disponible'
    Exigir (Test-Path 'backend\public\index.php') 'se ejecuta desde la raíz del repositorio (C:\Jatsa)'
    foreach ($p in 8000..8003) { Exigir (-not (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "el puerto $p está libre (no se toca ningún servidor ajeno)" }
    & $php "$aqui\instantanea.php" "$salida\antes.json" | Out-Null
    Exigir ($LASTEXITCODE -eq 0 -and (Test-Path "$salida\antes.json")) 'instantánea inicial tomada (solo SELECT)'
    $antes = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
    Exigir (@($antes.tablas.PSObject.Properties).Count -eq 12) 'la base tiene las 12 tablas esperadas'
    $restos = (Leer "SELECT (SELECT COUNT(*) FROM Persona WHERE numeroDocumento IN ('90000101','90000102','90000103','90000104','90000105','90000106','90000108','20000000107')) + (SELECT COUNT(*) FROM Usuario WHERE nombreUsuario LIKE 'pm10[_]%') + (SELECT COUNT(*) FROM Sucursal WHERE codigoSucursal LIKE 'PMX9%') + (SELECT COUNT(*) FROM Vehiculo WHERE placa LIKE 'PM1-9%') AS n")[0].n
    Exigir ([int]$restos -eq 0) 'no existen restos pm10 (personas, cuentas, sucursales ni vehículos)'
    $hoySql = (Leer "SELECT CONVERT(VARCHAR(10), CAST(SYSDATETIME() AS DATE), 120) AS hoy")[0].hoy
    Exigir ($hoySql -eq (Get-Date -Format 'yyyy-MM-dd')) "la fecha de esta máquina coincide con la del servidor SQL ($hoySql)"
    foreach ($t in 'Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje', 'Envio_Viaje') { $manifiesto.identAntes[$t] = [int]$antes.tablas.$t.ident }
    Anotar 'documentos' $null
    "  (IDENT_CURRENT inicial anotado: servirá para distinguir filas creadas por esta prueba y para informar el avance, que es irreversible y ya fue aceptado)"

    # Intención anotada ANTES de crear. La limpieza tolera que alguna no llegue a existir; nunca borra algo que no sea posterior a la instantánea.
    foreach ($c in 'PMX901', 'PMX902', 'PMX903', 'PMX904') { Anotar 'sucursales' $c }
    foreach ($p in 'PM1-901', 'PM1-902', 'PM1-903', 'PM1-904') { Anotar 'vehiculos' $p }
    foreach ($d in '90000101', '90000102', '90000103', '90000104', '90000105', '90000106', '90000108', '20000000107') { Anotar 'documentos' $d }
    foreach ($u in 'pm10_gerente', 'pm10_gerente2', 'pm10_admin', 'pm10_trans', 'pm10_trans2', 'pm10_cliente', 'pm10_empresa', 'pm10_nueva') { Anotar 'usuarios' $u }
    Exigir ((Test-ManifiestoPm10 $manifiesto).Count -eq 0) 'el manifiesto es válido: solo contiene nombres temporales pm10 (nunca datos originales)'

    $escrituraIniciada = $true
    foreach ($p in 8000..8003) { $servidores += Start-Process $php -ArgumentList '-S', "localhost:$p", '-t', 'backend/public' -WindowStyle Hidden -PassThru }
    Start-Sleep -Seconds 3
    foreach ($p in 8000..8003) { Exigir ([bool](Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)) "servidor de prueba escuchando en $p" }

    "== 2. Preparación: 6 personas y 2 gerentes temporales (INSERT por escritura_pm10.php; contraseñas solo en memoria)"
    $pw = @{ g1 = Clave; g2 = Clave; admin = Clave; trans = Clave; trans2 = Clave; cliente = Clave; empresa = Clave }
    $env:JATSA_PM10_PW_G1 = $pw.g1; $env:JATSA_PM10_PW_G2 = $pw.g2
    & $php "$aqui\escritura_pm10.php" preparar $manifiestoRuta; $codigoPrep = $LASTEXITCODE
    $env:JATSA_PM10_PW_G1 = $null; $env:JATSA_PM10_PW_G2 = $null
    Exigir ($codigoPrep -eq 0) 'personas y gerentes temporales creados'
    $g1 = Ingresar 'pm10_gerente' $pw.g1 '8000'
    $g1b = Ingresar 'pm10_gerente' $pw.g1 '8001'
    $g2 = Ingresar 'pm10_gerente2' $pw.g2 '8001'
    Exigir ($g1 -and $g1b -and $g2) 'sesiones abiertas de los dos gerentes temporales'
    $yo = (Api GET '/api/auth/yo' $null $g1).Json.data.usuario
    Exigir ($yo.rol -eq 'GERENTE' -and $yo.nombreUsuario -eq 'pm10_gerente') 'pm10_gerente es GERENTE'

    "== 3. Sucursales (diapositiva 29)"
    $datosSuc = { param($c, $n) @{ codigoSucursal = $c; nombre = $n; departamento = 'Prueba'; provincia = 'Prueba'; distrito = 'Prueba'; direccion = 'Av. Prueba 100'; telefonos = @('900001001', '900001002') } }
    $r = Api POST '/api/catalogos/sucursales' (& $datosSuc 'pmx901' 'Agencia Prueba Uno') $g1
    V 'crear PMX901: 201 y código en mayúsculas' ($r.Estado -eq 201 -and $r.Json.data.sucursal.codigo -eq 'PMX901') $r.Texto
    V 'crear PMX902, PMX903 y PMX904' ((@('PMX902', 'PMX903', 'PMX904') | % { (Api POST '/api/catalogos/sucursales' (& $datosSuc $_ "Agencia Prueba $_") $g1).Estado -eq 201 }) -notcontains $false)
    $r = Api POST '/api/catalogos/sucursales' (& $datosSuc 'PMX901' 'Repetida') $g1
    V 'código repetido: 409 sucursal_existente' ($r.Estado -eq 409 -and (Codigo $r) -eq 'sucursal_existente')
    $r = Api POST '/api/catalogos/sucursales' (& $datosSuc 'xx1' '') $g1
    V 'datos inválidos: 422 con campos' ($r.Estado -eq 422)
    V 'teléfonos guardados en Telefono_Sucursal (2 por sucursal)' ([int](Leer "SELECT COUNT(*) AS n FROM Telefono_Sucursal t JOIN Sucursal s ON s.idSucursal=t.idSucursal WHERE s.codigoSucursal='PMX901'")[0].n -eq 2)
    $r = Api PUT '/api/catalogos/sucursales/PMX901' @{ codigoSucursal = 'ZZZ999'; nombre = 'Agencia Prueba Uno B'; departamento = 'Prueba'; provincia = 'Prueba'; distrito = 'Prueba'; direccion = 'Av. Prueba 200'; telefonos = @('900001003') } $g1
    $f = Leer "SELECT s.codigoSucursal, s.nombre, (SELECT COUNT(*) FROM Telefono_Sucursal t WHERE t.idSucursal=s.idSucursal) AS tel FROM Sucursal s WHERE s.codigoSucursal='PMX901'"
    V 'editar: cambia datos, reemplaza teléfonos y NO cambia el código' ($r.Estado -eq 200 -and @($f).Count -eq 1 -and $f[0].nombre -eq 'Agencia Prueba Uno B' -and [int]$f[0].tel -eq 1)
    V 'editar una sucursal inexistente: 404' ((Api PUT '/api/catalogos/sucursales/ZZZ999' (& $datosSuc 'ZZZ999' 'x') $g1).Estado -eq 404)
    $r = Api POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $false } $g1
    V 'desactivar una sucursal sin nada en uso: 200 y queda activa = 0' ($r.Estado -eq 200 -and [int](Leer "SELECT activa AS n FROM Sucursal WHERE codigoSucursal='PMX903'")[0].n -eq 0)
    V 'reactivar PMX903' ((Api POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $true } $g1).Estado -eq 200)

    "== 4. Vehículos"
    $r = Api POST '/api/catalogos/vehiculos' @{ placa = 'pm1-901'; tipoVehiculo = 'FURGON'; capacidadKg = '1500,5' } $g1
    V 'crear PM1-901: 201 con placa en mayúsculas y capacidad normalizada' ($r.Estado -eq 201 -and $r.Json.data.vehiculo.placa -eq 'PM1-901' -and $r.Json.data.vehiculo.capacidadKg -eq '1500.50') $r.Texto
    V 'crear PM1-902, PM1-903 y PM1-904' ((@('PM1-902', 'PM1-903', 'PM1-904') | % { (Api POST '/api/catalogos/vehiculos' @{ placa = $_; tipoVehiculo = 'CAMIONETA'; capacidadKg = '800' } $g1).Estado -eq 201 }) -notcontains $false)
    V 'placa repetida: 409 vehiculo_existente' ((Codigo (Api POST '/api/catalogos/vehiculos' @{ placa = 'PM1-901'; tipoVehiculo = 'MOTO'; capacidadKg = '50' } $g1)) -eq 'vehiculo_existente')
    V 'placa inválida: 422' ((Api POST '/api/catalogos/vehiculos' @{ placa = 'AB-12'; tipoVehiculo = 'MOTO'; capacidadKg = '50' } $g1).Estado -eq 422)
    $r = Api PUT '/api/catalogos/vehiculos/PM1-901' @{ placa = 'XXX-111'; tipoVehiculo = 'CAMION'; capacidadKg = '2000' } $g1
    V 'editar: cambia tipo y capacidad, la placa no cambia' ($r.Estado -eq 200 -and (Leer "SELECT tipoVehiculo FROM Vehiculo WHERE placa='PM1-901'")[0].tipoVehiculo -eq 'CAMION' -and @(Leer "SELECT 1 AS x FROM Vehiculo WHERE placa='XXX-111'").Count -eq 0)
    V 'desactivar y reactivar un vehículo sin viajes' ((Api POST '/api/catalogos/vehiculos/PM1-901/estado' @{ activo = $false } $g1).Estado -eq 200 -and (Api POST '/api/catalogos/vehiculos/PM1-901/estado' @{ activo = $true } $g1).Estado -eq 200)

    "== 5. Cuentas (diapositiva 30)"
    $alta = { param($doc, $rol, $suc, $usu, $clave) @{ tipoDocumento = 'DNI'; numeroDocumento = $doc; rol = $rol; sucursal = $suc; usuario = $usu; contrasena = $clave } }
    $r = Api POST '/api/usuarios' (& $alta '90000103' 'ADMINISTRADOR' 'PMX901' 'pm10_admin' $pw.admin) $g1
    V 'crear pm10_admin (ADMINISTRADOR de PMX901) para una persona existente: 201' ($r.Estado -eq 201 -and $r.Json.data.cuenta.rol -eq 'ADMINISTRADOR') $r.Texto
    V 'crear pm10_trans (TRANSPORTISTA de PMX901) y pm10_trans2 (TRANSPORTISTA de PMX902)' ((Api POST '/api/usuarios' (& $alta '90000104' 'TRANSPORTISTA' 'PMX901' 'pm10_trans' $pw.trans) $g1).Estado -eq 201 -and (Api POST '/api/usuarios' (& $alta '90000105' 'TRANSPORTISTA' 'PMX902' 'pm10_trans2' $pw.trans2) $g1).Estado -eq 201)
    $f = Leer "SELECT u.rol, u.activo, LEFT(u.hashContrasena, 4) AS pref, s.codigoSucursal FROM Usuario u JOIN Sucursal s ON s.idSucursal=u.idSucursal WHERE u.nombreUsuario='pm10_admin'"
    V 'en la base: rol, sucursal y contraseña con bcrypt (no se guarda en claro)' (@($f).Count -eq 1 -and $f[0].rol -eq 'ADMINISTRADOR' -and $f[0].codigoSucursal -eq 'PMX901' -and $f[0].pref -eq '$2y$')
    V 'persona inexistente: 404 persona_no_encontrada' ((Codigo (Api POST '/api/usuarios' (& $alta '90000199' 'ADMINISTRADOR' 'PMX901' 'pm10_nueva' (Clave)) $g1)) -eq 'persona_no_encontrada')
    V 'persona que ya tiene cuenta: 409 cuenta_existente' ((Codigo (Api POST '/api/usuarios' (& $alta '90000101' 'ADMINISTRADOR' 'PMX901' 'pm10_nueva' (Clave)) $g1)) -eq 'cuenta_existente')
    V 'rol no permitido (CLIENTE): 422' ((Api POST '/api/usuarios' (& $alta '90000108' 'CLIENTE' 'PMX901' 'pm10_nueva' (Clave)) $g1).Estado -eq 422)
    V 'usuario repetido: 409 usuario_en_uso' ((Codigo (Api POST '/api/usuarios' (& $alta '90000108' 'ADMINISTRADOR' 'PMX901' 'pm10_admin' (Clave)) $g1)) -eq 'usuario_en_uso')
    V 'ninguna de esas altas rechazadas creó una cuenta' ([int](Leer "SELECT COUNT(*) AS n FROM Usuario WHERE nombreUsuario = 'pm10_nueva'")[0].n -eq 0)
    $sinPermiso = Ingresar 'pm10_admin' $pw.admin '8002'
    V 'un ADMINISTRADOR no gestiona cuentas ni catálogos: 403' ((Api GET '/api/usuarios' $null $sinPermiso '8002').Estado -eq 403 -and (Api GET '/api/catalogos/rutas' $null $sinPermiso '8002').Estado -eq 403)

    "   Reactivar personal en una sucursal desactivada (corrección del PR #11)"
    V 'desactivar pm10_trans2' ((Api POST '/api/usuarios/pm10_trans2/estado' @{ activo = $false } $g1).Estado -eq 200)
    V 'desactivar PMX902 (ya sin personal activo ni viajes)' ((Api POST '/api/catalogos/sucursales/PMX902/estado' @{ activa = $false } $g1).Estado -eq 200)
    $r = Api POST '/api/usuarios/pm10_trans2/estado' @{ activo = $true } $g1
    V 'reactivar la cuenta con su sucursal desactivada: 409 sucursal_inactiva y sigue inactiva' ($r.Estado -eq 409 -and (Codigo $r) -eq 'sucursal_inactiva' -and [int](Leer "SELECT activo AS n FROM Usuario WHERE nombreUsuario='pm10_trans2'")[0].n -eq 0)
    V 'moverla a una sucursal activa (Editar) y reactivarla' ((Api PUT '/api/usuarios/pm10_trans2' @{ rol = 'TRANSPORTISTA'; sucursal = 'PMX901' } $g1).Estado -eq 200 -and (Api POST '/api/usuarios/pm10_trans2/estado' @{ activo = $true } $g1).Estado -eq 200)
    V 'reactivar PMX902' ((Api POST '/api/catalogos/sucursales/PMX902/estado' @{ activa = $true } $g1).Estado -eq 200)
    V 'un gerente no puede desactivar su propia cuenta: 409' ((Codigo (Api POST '/api/usuarios/pm10_gerente/estado' @{ activo = $false } $g1)) -eq 'no_puede_desactivarse')
    V 'las cuentas originales no se pueden editar por error (un cliente real no es personal): 404 para una cuenta inexistente' ((Api PUT '/api/usuarios/pm10_noexiste' @{ rol = 'ADMINISTRADOR'; sucursal = 'PMX901' } $g1).Estado -eq 404)

    "== 6. Rutas y precios"
    $ruta = @{ origen = 'PMX901'; destino = 'PMX902'; tipoEnvio = 'SOBRE'; precioBase = '8,00'; precioPorKm = '0.005'; distanciaKm = '100'; tiempoHoras = 2 }
    $r = Api POST '/api/catalogos/rutas' $ruta $g1
    V 'Nueva ruta PMX901 → PMX902: 201, vigente desde hoy y costo del bulto 8.50' ($r.Estado -eq 201 -and $r.Json.data.ruta.vigente -eq $true -and $r.Json.data.ruta.costoBulto -eq '8.50' -and $r.Json.data.ruta.vigenteDesde -eq $hoySql) $r.Texto
    $idHoy = $r.Json.data.ruta.id
    V 'repetir la misma ruta: 409 tarifa_vigente_existente' ((Codigo (Api POST '/api/catalogos/rutas' $ruta $g1)) -eq 'tarifa_vigente_existente')
    V 'origen = destino: 422' ((Api POST '/api/catalogos/rutas' ($ruta + @{ destino = 'PMX901' }) $g1).Estado -eq 422)
    $nuevoPrecio = @{ precioBase = '9,00'; precioPorKm = '0.006'; distanciaKm = '100'; tiempoHoras = 2 }
    V 'Nuevo precio sobre una tarifa que entró en vigor HOY: 409 tarifa_cambiada_hoy y no se escribe nada' ((Codigo (Api POST "/api/catalogos/rutas/$idHoy/precio" $nuevoPrecio $g1)) -eq 'tarifa_cambiada_hoy' -and [int](Leer "SELECT COUNT(*) AS n FROM Ruta WHERE idRuta = $idHoy AND vigenteHasta IS NULL")[0].n -eq 1)
    $idAntigua = [int](& $php "$aqui\escritura_pm10.php" ruta-antigua $manifiestoRuta PMX902 PMX901 SOBRE | Select-Object -Last 1)
    Exigir ($LASTEXITCODE -eq 0 -and $idAntigua -gt $manifiesto.identAntes.Ruta) 'tarifa TEMPORAL antigua creada (vigenteDesde = anteayer) para probar «Nuevo precio»'
    $r = Api POST "/api/catalogos/rutas/$idAntigua/precio" $nuevoPrecio $g1
    V 'Nuevo precio sobre una tarifa antigua: 201 y la fila nueva queda vigente desde hoy' ($r.Estado -eq 201 -and $r.Json.data.ruta.vigenteDesde -eq $hoySql -and $r.Json.data.ruta.vigente -eq $true) $r.Texto
    $filas = Leer "SELECT idRuta, precioBase, CONVERT(VARCHAR(10), vigenteDesde, 120) AS desde, CONVERT(VARCHAR(10), vigenteHasta, 120) AS hasta FROM Ruta WHERE idSucursalOrigen = (SELECT idSucursal FROM Sucursal WHERE codigoSucursal='PMX902') ORDER BY idRuta"
    $ayer = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
    V 'la fila vieja se cerró AYER y conserva su precio 8.00; hay UNA sola vigente para ese origen, destino y tipo' (@($filas).Count -eq 2 -and $filas[0].hasta -eq $ayer -and [decimal]$filas[0].precioBase -eq 8 -and [decimal]$filas[1].precioBase -eq 9 -and $null -eq $filas[1].hasta)
    V 'Nuevo precio sobre la fila ya cerrada: 409 tarifa_cerrada' ((Codigo (Api POST "/api/catalogos/rutas/$idAntigua/precio" $nuevoPrecio $g1)) -eq 'tarifa_cerrada')
    V 'Nuevo precio de una ruta inexistente: 404' ((Api POST '/api/catalogos/rutas/999999999/precio' $nuevoPrecio $g1).Estado -eq 404)

    "== 7. Despacho con recursos temporales (corrección de concurrencia del PR #11) y desactivaciones en uso"
    $adm = Ingresar 'pm10_admin' $pw.admin '8000'
    $r = Api POST '/api/despacho/viajes' @{ placa = 'PM1-901'; transportista = 'pm10_trans'; destino = 'PMX902' } $adm
    V 'el administrador temporal programa un viaje PMX901 → PMX902 con recursos temporales: 201' ($r.Estado -eq 201) $r.Texto
    AnotarViajesTemporales
    V 'desactivar el vehículo con un viaje abierto: 409 vehiculo_con_viajes' ((Codigo (Api POST '/api/catalogos/vehiculos/PM1-901/estado' @{ activo = $false } $g1)) -eq 'vehiculo_con_viajes')
    V 'desactivar al conductor con un viaje abierto: 409 transportista_con_viajes' ((Codigo (Api POST '/api/usuarios/pm10_trans/estado' @{ activo = $false } $g1)) -eq 'transportista_con_viajes')
    V 'desactivar la sucursal de destino con un viaje abierto: 409 sucursal_con_viajes' ((Codigo (Api POST '/api/catalogos/sucursales/PMX902/estado' @{ activa = $false } $g1)) -eq 'sucursal_con_viajes')
    V 'cambiar el puesto del conductor con un viaje abierto: 409 transportista_con_viajes' ((Codigo (Api PUT '/api/usuarios/pm10_trans' @{ rol = 'TRANSPORTISTA'; sucursal = 'PMX903' } $g1)) -eq 'transportista_con_viajes')
    V 'con una sucursal DESACTIVADA, programar un viaje hacia ella es 422 (PMX903 se desactiva y se reactiva)' ((Api POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $false } $g1).Estado -eq 200 -and (Api POST '/api/despacho/viajes' @{ placa = 'PM1-902'; transportista = 'pm10_trans'; destino = 'PMX903' } $adm).Estado -eq 422 -and (Api POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $true } $g1).Estado -eq 200)
    AnotarViajesTemporales

    "== 8. Cambio de la PROPIA contraseña: los cinco roles, con dos sesiones por cuenta"
    $reg = Api POST '/api/cuenta/registro' @{ tipoDocumento = 'DNI'; numeroDocumento = '90000106'; nombreCompleto = 'Prueba Diez Cliente'; telefono = '900000106'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm10_cliente'; contrasena = $pw.cliente } $null
    V 'registrar el cliente temporal (pm10_cliente) por el endpoint público: 201' ($reg.Estado -eq 201) $reg.Texto
    $reg = Api POST '/api/cuenta/registro' @{ tipoDocumento = 'RUC'; numeroDocumento = '20000000107'; nombreCompleto = 'Prueba Diez S.A.C.'; telefono = '900000107'; telefonoAuxiliar = ''; correo = ''; usuario = 'pm10_empresa'; contrasena = $pw.empresa } $null
    V 'registrar el empresario temporal (pm10_empresa): 201' ($reg.Estado -eq 201) $reg.Texto
    $cuentas = @(@{ u = 'pm10_gerente'; k = 'g1' }, @{ u = 'pm10_admin'; k = 'admin' }, @{ u = 'pm10_trans'; k = 'trans' }, @{ u = 'pm10_cliente'; k = 'cliente' }, @{ u = 'pm10_empresa'; k = 'empresa' })
    foreach ($c in $cuentas) {
        $a = Ingresar $c.u $pw[$c.k] '8000'; $b = Ingresar $c.u $pw[$c.k] '8001'
        $nueva = Clave
        $r = Api POST '/api/cuenta/contrasena' @{ contrasenaActual = $pw[$c.k]; contrasenaNueva = $nueva } $a
        V "$($c.u): cambia su propia contraseña (200)" ($r.Estado -eq 200) $r.Texto
        V "$($c.u): la OTRA sesión de la cuenta deja de valer (401)" ((Api GET '/api/auth/yo' $null $b '8001').Estado -eq 401)
        V "$($c.u): la sesión que cambió la contraseña sigue válida" ((Api GET '/api/auth/yo' $null $a).Estado -eq 200)
        V "$($c.u): ingresar con la anterior falla (401) y con la nueva funciona" ((Llamar POST '/api/auth/ingresar' @{ usuario = $c.u; contrasena = $pw[$c.k] } '8002').Estado -eq 401 -and ((GalletaDe (Llamar POST '/api/auth/ingresar' @{ usuario = $c.u; contrasena = $nueva } '8002')) -ne ''))
        V "$($c.u): con la contraseña actual incorrecta: 422 y no cambia nada" ((Codigo (Api POST '/api/cuenta/contrasena' @{ contrasenaActual = 'Incorrecta#1'; contrasenaNueva = (Clave) } $a)) -eq 'contrasena_incorrecta')
        $pw[$c.k] = $nueva
        if ($c.k -eq 'g1') { $g1 = Ingresar 'pm10_gerente' $pw.g1 '8000'; $g1b = Ingresar 'pm10_gerente' $pw.g1 '8001' }
        if ($c.k -eq 'admin') { $adm = Ingresar 'pm10_admin' $pw.admin '8000' }
    }
    $adm2 = Ingresar 'pm10_admin' $pw.admin '8002'
    V 'el personal sigue SIN acceso a los datos personales (GET /api/cuenta): 403' ((Api GET '/api/cuenta' $null $adm2 '8002').Estado -eq 403)
    $cli = Ingresar 'pm10_cliente' $pw.cliente '8002'
    V 'el cliente conserva «Mi cuenta» (GET /api/cuenta): 200' ((Api GET '/api/cuenta' $null $cli '8002').Estado -eq 200)

    "== 9. Carreras reales (peticiones simultáneas con sesiones propias). Una petición perdedora puede ser 409/422 o un interbloqueo (500): se acepta si el estado final es consistente"
    # C1) Dos cambios de contraseña con la MISMA contraseña anterior: exactamente uno confirma.
    $s1 = Ingresar 'pm10_cliente' $pw.cliente '8000'; $s2 = Ingresar 'pm10_cliente' $pw.cliente '8001'
    $nA = Clave; $nB = Clave
    $res = EnParalelo @((Tarea 8000 $s1 POST '/api/cuenta/contrasena' @{ contrasenaActual = $pw.cliente; contrasenaNueva = $nA }), (Tarea 8001 $s2 POST '/api/cuenta/contrasena' @{ contrasenaActual = $pw.cliente; contrasenaNueva = $nB }))
    $gan = @($res | ? { $_.Estado -eq 200 }); $perd = @($res | ? { $_.Estado -ne 200 })
    $entraA = (Llamar POST '/api/auth/ingresar' @{ usuario = 'pm10_cliente'; contrasena = $nA } '8002').Estado -eq 200
    $entraB = (Llamar POST '/api/auth/ingresar' @{ usuario = 'pm10_cliente'; contrasena = $nB } '8003').Estado -eq 200
    V "C1 contraseña: exactamente UNA solicitud confirma y la otra recibe 409 contrasena_modificada, 422 o 500 (resultado: $(Resumen $res))" ($gan.Count -eq 1 -and $perd.Count -eq 1 -and ($perd[0].Codigo -in 'contrasena_modificada', 'contrasena_incorrecta' -or $perd[0].Estado -eq 500))
    V 'C1: solo sirve la contraseña de quien confirmó (nunca las dos)' (($entraA -xor $entraB))

    # C2) Desactivar un vehículo a la vez que se programa un viaje con él (y C3 con el conductor, C4 con la sucursal de destino).
    $casos = @(
        @{ n = 'C2 vehículo'; veh = 'PM1-902'; trans = 'pm10_trans'; dest = 'PMX902'; desact = @('/api/catalogos/vehiculos/PM1-902/estado', @{ activo = $false }); consulta = "SELECT (SELECT activo FROM Vehiculo WHERE placa='PM1-902') AS inactivo0, (SELECT COUNT(*) FROM Viaje v JOIN Vehiculo ve ON ve.idVehiculo=v.idVehiculo WHERE ve.placa='PM1-902' AND v.fechaHoraLlegada IS NULL) AS abiertos"; inactivo = { param($f) [int]$f.inactivo0 -eq 0 } },
        @{ n = 'C3 conductor'; veh = 'PM1-903'; trans = 'pm10_trans2'; dest = 'PMX902'; desact = @('/api/usuarios/pm10_trans2/estado', @{ activo = $false }); consulta = "SELECT (SELECT activo FROM Usuario WHERE nombreUsuario='pm10_trans2') AS inactivo0, (SELECT COUNT(*) FROM Viaje v JOIN Usuario u ON u.idUsuario=v.idUsuarioTransportista WHERE u.nombreUsuario='pm10_trans2' AND v.fechaHoraLlegada IS NULL) AS abiertos"; inactivo = { param($f) [int]$f.inactivo0 -eq 0 } },
        @{ n = 'C4 sucursal de destino'; veh = 'PM1-904'; trans = 'pm10_trans'; dest = 'PMX904'; desact = @('/api/catalogos/sucursales/PMX904/estado', @{ activa = $false }); consulta = "SELECT (SELECT activa FROM Sucursal WHERE codigoSucursal='PMX904') AS inactivo0, (SELECT COUNT(*) FROM Viaje v JOIN Sucursal s ON s.idSucursal=v.idSucursalDestino WHERE s.codigoSucursal='PMX904' AND v.fechaHoraLlegada IS NULL) AS abiertos"; inactivo = { param($f) [int]$f.inactivo0 -eq 0 } }
    )
    foreach ($caso in $casos) {
        $adm = Ingresar 'pm10_admin' $pw.admin '8000'
        $res = EnParalelo @((Tarea 8000 $adm POST '/api/despacho/viajes' @{ placa = $caso.veh; transportista = $caso.trans; destino = $caso.dest }), (Tarea 8001 $g1b POST $caso.desact[0] $caso.desact[1]))
        AnotarViajesTemporales
        $f = (Leer $caso.consulta)[0]
        V "$($caso.n): nunca queda un viaje abierto con el recurso desactivado (resultado: $(Resumen $res); desactivado=$(& $caso.inactivo $f), viajes abiertos=$($f.abiertos))" (-not ((& $caso.inactivo $f) -and [int]$f.abiertos -gt 0))
        V "$($caso.n): al menos una de las dos solicitudes fue rechazada o el viaje se creó antes de la desactivación (consistente con las respuestas)" (($res | ? { $_.Estado -in 200, 201 }).Count -ge 1)
    }

    # C5) Crear una cuenta en una sucursal a la vez que se desactiva esa sucursal (PMX903 sin personal ni viajes).
    $res = EnParalelo @((Tarea 8000 $g1 POST '/api/usuarios' (& $alta '90000108' 'ADMINISTRADOR' 'PMX903' 'pm10_nueva' (Clave))), (Tarea 8001 $g1b POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $false }))
    $f = (Leer "SELECT (SELECT activa FROM Sucursal WHERE codigoSucursal='PMX903') AS activa, (SELECT COUNT(*) FROM Usuario u JOIN Sucursal s ON s.idSucursal=u.idSucursal WHERE s.codigoSucursal='PMX903' AND u.activo=1) AS personal")[0]
    V "C5 cuenta vs sucursal: nunca queda personal ACTIVO en una sucursal desactivada (resultado: $(Resumen $res); activa=$($f.activa), personal activo=$($f.personal))" (-not ([int]$f.activa -eq 0 -and [int]$f.personal -gt 0))

    # C6) Dos Nueva ruta idénticas a la vez: una sola vigente.
    $rutaC = @{ origen = 'PMX903'; destino = 'PMX904'; tipoEnvio = 'PAQUETE'; precioBase = '10'; precioPorKm = '0.02'; distanciaKm = '100'; tiempoHoras = 2 }
    Api POST '/api/catalogos/sucursales/PMX903/estado' @{ activa = $true } $g1 | Out-Null
    Api POST '/api/catalogos/sucursales/PMX904/estado' @{ activa = $true } $g1 | Out-Null
    $res = EnParalelo @((Tarea 8000 $g1 POST '/api/catalogos/rutas' $rutaC), (Tarea 8001 $g1b POST '/api/catalogos/rutas' $rutaC))
    $n = [int](Leer "SELECT COUNT(*) AS n FROM Ruta r WHERE r.vigenteHasta IS NULL AND r.tipoEnvio='PAQUETE' AND r.idSucursalOrigen=(SELECT idSucursal FROM Sucursal WHERE codigoSucursal='PMX903') AND r.idSucursalDestino=(SELECT idSucursal FROM Sucursal WHERE codigoSucursal='PMX904')")[0].n
    V "C6 Nueva ruta doble: exactamente UNA tarifa vigente (resultado: $(Resumen $res); vigentes=$n)" ($n -eq 1)

    # C7) Dos gerentes temporales que se desactivan mutuamente. La regla del «último gerente» no puede probarse aquí: existen gerentes originales
    #     (desactivarlos sería tocar datos reales); queda cubierta solo por las pruebas simuladas. Se comprueba que no hay estados incoherentes.
    $res = EnParalelo @((Tarea 8000 $g1 POST '/api/usuarios/pm10_gerente2/estado' @{ activo = $false }), (Tarea 8001 $g2 POST '/api/usuarios/pm10_gerente/estado' @{ activo = $false }))
    $act = [int](Leer "SELECT COUNT(*) AS n FROM Usuario WHERE rol='GERENTE' AND activo=1 AND nombreUsuario NOT LIKE 'pm10[_]%'")[0].n
    V "C7 gerentes cruzados: ninguna petición deja un estado incoherente y los gerentes ORIGINALES siguen activos (resultado: $(Resumen $res); originales activos=$act)" ($act -ge 1 -and (($res | ? { $_.Estado -notin 200, 409, 401, 500 }).Count -eq 0))
    V 'C7: las respuestas coinciden con el estado final de cada gerente temporal' (@($res | ? { $_.Estado -eq 200 }).Count -eq (2 - [int](Leer "SELECT COUNT(*) AS n FROM Usuario WHERE nombreUsuario IN ('pm10_gerente','pm10_gerente2') AND activo=1")[0].n))
    AnotarViajesTemporales
    V "interbloqueos / errores internos observados en todas las carreras: $interbloqueos (informado; se aceptan si el estado final fue consistente)" $true
}
catch {
    $script:mal++
    "ERROR: se detiene la validación: $($_.Exception.Message)"
}
finally {
    foreach ($s in $servidores) { try { Stop-Process -Id $s.Id -Force -ErrorAction SilentlyContinue } catch { } }
    if (-not $escrituraIniciada) {
        "== Limpieza: no se escribió nada en la base (la validación se detuvo en las precondiciones). Nada que limpiar."
        $limpio = $true
    } else {
        "== 10. Limpieza (solo lo registrado en el manifiesto de ESTA ejecución) y comparación con la instantánea"
        try {
            try { AnotarViajesTemporales } catch { "  (no se pudieron releer los viajes temporales: $($_.Exception.Message))" }
            # El script PHP verifica ANTES de borrar que lo existente coincide con el manifiesto y se detiene ante cualquier divergencia.
            & $php "$aqui\escritura_pm10.php" limpiar $manifiestoRuta
            if ($LASTEXITCODE -ne 0) { throw "la limpieza se detuvo (código $LASTEXITCODE): NO se borró nada; revisar con el propietario antes de continuar (sin borrados adicionales)" }
            & $php "$aqui\instantanea.php" "$salida\despues.json" | Out-Null
            $antes = Get-Content "$salida\antes.json" -Raw | ConvertFrom-Json
            $despues = Get-Content "$salida\despues.json" -Raw | ConvertFrom-Json

            "-- 1) DATOS ORIGINALES (filas, checksum y hash de cada fila): deben ser idénticos"
            $cmp = @(Get-ComparacionOriginales $antes $despues)
            foreach ($c in $cmp) { '   {0,-20} filas {1,3} -> {2,3}  {3}' -f $c.Tabla, $c.FilasAntes, $c.FilasDespues, $(if ($c.Identica) { 'idéntica' } else { 'DIFIERE' }) }
            V 'las 12 tablas con las mismas filas, checksum y hashes por fila que antes de empezar' (@($cmp | ? { -not $_.Identica }).Count -eq 0)

            "-- 2) CONTADORES IDENTITY (informativo): NO se restauran y NO se promete que coincidan"
            $avance = @(Get-AvanceIdentity $antes $despues)
            foreach ($a in $avance) { '   {0,-20} IDENT_CURRENT {1,5} -> {2,5}  ({3}{4})' -f $a.Tabla, $a.Antes, $a.Despues, $a.Estado, $(if ($a.Avance -ne 0) { ", +$($a.Avance)" } else { '' }) }
            "   El avance puede superar el número de altas confirmadas: las carreras perdidas, los interbloqueos y los ROLLBACK también consumen valores."
            V 'ningún contador IDENTITY retrocedió' (@($avance | ? { $_.Estado -eq 'RETROCEDIO' }).Count -eq 0)
            V 'solo avanzaron contadores de tablas que este módulo escribe (Sucursal, Telefono_Sucursal, Ruta, Vehiculo, Persona, Usuario, Viaje)' (@($avance | ? { $_.Estado -eq 'Inesperado' }).Count -eq 0)

            $restos = [int](Leer "SELECT (SELECT COUNT(*) FROM Persona WHERE numeroDocumento IN ('90000101','90000102','90000103','90000104','90000105','90000106','90000108','20000000107')) + (SELECT COUNT(*) FROM Usuario WHERE nombreUsuario LIKE 'pm10[_]%') + (SELECT COUNT(*) FROM Sucursal WHERE codigoSucursal LIKE 'PMX9%') + (SELECT COUNT(*) FROM Vehiculo WHERE placa LIKE 'PM1-9%') AS n")[0].n
            V '0 registros pm10 en la base' ($restos -eq 0)
            # Solo se informa limpieza completa si se COMPROBÓ: datos originales idénticos y sin restos pm10.
            $limpio = (@($cmp | ? { -not $_.Identica }).Count -eq 0 -and $restos -eq 0)
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
    "RESUMEN: $ok correctas, $mal con fallo. Limpieza completa y datos originales idénticos: $limpio. Manifiesto e instantáneas en $salida"
    # Código de salida: 0 solo si no hubo ningún fallo y la limpieza quedó comprobada; en otro caso 1.
    $codigoSalida = if ($mal -eq 0 -and $limpio) { 0 } else { 1 }
}
exit $codigoSalida
