<?php
declare(strict_types=1);

/*
 * CARGA DE DATOS DE DEMOSTRACIÓN en JATSA_Encomiendas (solo INSERT). Plan y contrato: docs/datos-demo.md.
 *
 *   php backend/bin/cargar-datos-demo.php                  Simulación: verifica el destino, lee lo existente, genera y valida el conjunto en memoria. NO escribe.
 *   php backend/bin/cargar-datos-demo.php --ejecutar       Respaldo verificado + carga en UNA transacción + verificación + archivo local de credenciales.
 *   php backend/bin/cargar-datos-demo.php --verificar      Solo lectura: comprobaciones de integridad, cronología, importes y estados sobre lo ya cargado.
 *   Opciones: --servidor=localhost  --puerto=1433  --salida=<carpeta local para credenciales y manifiesto>
 *
 * Garantías: solo INSERT (nada de UPDATE, DELETE, DROP, TRUNCATE ni cambios de esquema); los registros anteriores no se tocan (se comparan sus huellas antes
 * y después); es REPETIBLE: si la carga ya existe (marca: cuenta «gerente.demo1»), no hace nada; cualquier error revierte toda la transacción. Autenticación
 * integrada de Windows (sin credenciales); no lee backend/.env. Los datos son FICTICIOS: documentos en rangos reservados, correos @example.com, teléfonos de
 * prueba y nombres inventados. Las contraseñas de prueba solo se escriben en un archivo LOCAL, fuera del repositorio.
 */
require dirname(__DIR__) . '/src/autoload.php';

use Jatsa\Shared\Tarifas;

date_default_timezone_set('America/Lima');
mt_srand(20261104); // el conjunto generado es determinista: mismos datos para la misma fecha de ejecución

const BD_DESTINO = 'JATSA_Encomiendas';
const MARCA = 'gerente.demo1';
const TABLAS = ['Persona' => 'idPersona', 'Sucursal' => 'idSucursal', 'Telefono_Sucursal' => 'idTelefono', 'Usuario' => 'idUsuario', 'Ruta' => 'idRuta', 'Vehiculo' => 'idVehiculo',
    'Reserva' => 'idReserva', 'Envio' => 'idEnvio', 'Bulto' => 'idBulto', 'Viaje' => 'idViaje', 'Envio_Viaje' => 'idEnvioViaje', 'Pago' => 'idPago'];
const LIMITES_KG = Tarifas::LIMITES_KG;

function abortar(string $mensaje, int $codigo = 4): never
{
    fwrite(STDERR, "DETENIDO: $mensaje\n");
    exit($codigo);
}

function azar(): float
{
    return mt_rand() / mt_getrandmax();
}

function entre(int $a, int $b): int
{
    return mt_rand($a, $b);
}

function elegir(array $lista): mixed
{
    return $lista[mt_rand(0, count($lista) - 1)];
}

/** @param array<string|int, int|float> $pesos */
function ponderado(array $pesos): string|int
{
    $r = azar() * array_sum($pesos);
    foreach ($pesos as $clave => $peso) {
        $r -= $peso;
        if ($r <= 0) {
            return $clave;
        }
    }

    return array_key_last($pesos);
}

function f(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

function enHorario(int $ts): int
{
    $h = (int) date('G', $ts);
    $dia = mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    if ($h < 8) {
        return $dia + 8 * 3600 + entre(0, 90) * 60;
    }
    if ($h >= 19) {
        return $dia + 86400 + 8 * 3600 + entre(0, 90) * 60;
    }

    return $ts;
}

function centimos(string $monto): int
{
    return (int) round(((float) $monto) * 100);
}

function soles(int $c): string
{
    return Tarifas::formatoSoles($c);
}

// ═══ Opciones y conexión verificada ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
$ejecutar = in_array('--ejecutar', $argv, true);
$soloVerificar = in_array('--verificar', $argv, true);
$opciones = [];
foreach ($argv as $a) {
    if (preg_match('/^--(servidor|puerto|salida)=(.+)$/', $a, $m) === 1) {
        $opciones[$m[1]] = $m[2];
    }
}
$servidor = $opciones['servidor'] ?? 'localhost';
$puerto = $opciones['puerto'] ?? '';
if (preg_match('/^[A-Za-z0-9_.\-\\\\]+$/', $servidor) !== 1 || ($puerto !== '' && preg_match('/^\d{2,5}$/', $puerto) !== 1)) {
    abortar('Servidor o puerto no válidos.', 2);
}
$salida = $opciones['salida'] ?? ((string) getenv('USERPROFILE') . '\\Documents\\JATSA-demo');
$repoRaiz = realpath(dirname(__DIR__, 2));
if ($repoRaiz !== false && str_starts_with(strtolower((string) realpath(dirname($salida)) ?: $salida), strtolower($repoRaiz))) {
    abortar('La carpeta de salida (credenciales) debe estar FUERA del repositorio.', 2);
}

if (!extension_loaded('pdo_sqlsrv')) {
    abortar('Falta la extensión pdo_sqlsrv.', 2);
}
$pdo = new PDO(sprintf('sqlsrv:Server=%s;Database=%s;Encrypt=false;TrustServerCertificate=true;LoginTimeout=8', $servidor . ($puerto !== '' ? ',' . $puerto : ''), BD_DESTINO), null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$id = $pdo->query("SELECT DB_NAME() AS bd, ORIGINAL_LOGIN() AS login, @@SERVERNAME AS servidor, (SELECT MAX(auth_scheme) FROM sys.dm_exec_connections WHERE session_id = @@SPID) AS auth,
    (SELECT is_read_only FROM sys.databases WHERE name = DB_NAME()) AS soloLectura, CONVERT(VARCHAR(19), GETDATE(), 120) AS ahora,
    CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS VARCHAR(400)) AS rutaRespaldo")->fetch();
$esperada = (string) getenv('USERDOMAIN') . '\\' . (string) getenv('USERNAME');
if ($id['bd'] !== BD_DESTINO || strcasecmp((string) $id['login'], $esperada) !== 0 || !in_array(strtoupper((string) $id['auth']), ['NTLM', 'KERBEROS', 'NEGOTIATE'], true) || (int) $id['soloLectura'] !== 0) {
    abortar('Destino inesperado: ' . json_encode($id) . '. Se esperaba ' . BD_DESTINO . ' con la cuenta ' . $esperada . ', autenticación de Windows y base de lectura/escritura.', 5);
}
$ahoraTs = (int) floor(time() / 60) * 60;
if (abs(strtotime((string) $id['ahora']) - $ahoraTs) > 300) {
    abortar('El reloj de SQL Server (' . $id['ahora'] . ') y el de PHP (' . f($ahoraTs) . ') difieren más de 5 minutos.', 5);
}
echo "Destino verificado: {$id['bd']} en {$id['servidor']}, cuenta {$id['login']} ({$id['auth']}). Ahora: " . f($ahoraTs) . "\n";

// ═══ Lectura de lo existente ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
$preMax = [];
foreach (TABLAS as $t => $pk) {
    $preMax[$t] = (int) $pdo->query("SELECT COALESCE(MAX($pk), 0) FROM $t")->fetchColumn();
}

/** Huella (filas y SHA-256 del contenido) de las filas con id <= $limite en cada tabla. @param array<string, int> $limite @return array<string, array<string, mixed>> */
function huellas(PDO $pdo, array $limite): array
{
    $h = [];
    foreach (TABLAS as $t => $pk) {
        $q = $pdo->prepare("SELECT * FROM $t WHERE $pk <= :m ORDER BY $pk");
        $q->execute([':m' => $limite[$t]]);
        $filas = $q->fetchAll();
        $h[$t] = ['filas' => count($filas), 'sha256' => hash('sha256', (string) json_encode($filas, JSON_UNESCAPED_UNICODE))];
    }

    return $h;
}

/** Comprobaciones de integridad, cronología, importes y estados de las filas demo (id > $pre). Devuelve problemas por comprobación. @param array<string, int> $pre @return array<string, int> */
function verificarDemo(PDO $pdo, array $pre, string $ahora): array
{
    $p = [':pr' => $pre['Reserva'], ':pe' => $pre['Envio'], ':pv' => $pre['Viaje'], ':pb' => $pre['Bulto'], ':pp' => $pre['Pago'], ':pc' => $pre['Envio_Viaje']];
    $SALDO = "(SELECT COALESCE(SUM(b.costo),0) FROM Bulto b WHERE b.idEnvio = e.idEnvio)";
    $PAGADO = "(SELECT COALESCE(SUM(pg.monto),0) FROM Pago pg WHERE pg.idEnvio = e.idEnvio AND pg.estadoPago = 'REGISTRADO')";
    $ULTIMA_LLEGADA = "(SELECT MAX(v.fechaHoraLlegada) FROM Envio_Viaje ev JOIN Viaje v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = e.idEnvio)";
    $comprobaciones = [
        'reservas FORMALIZADA sin envío' => ["SELECT COUNT(*) FROM Reserva r WHERE r.idReserva > :pr AND r.estadoReserva = 'FORMALIZADA' AND NOT EXISTS (SELECT 1 FROM Envio e WHERE e.idReserva = r.idReserva)", [':pr' => $pre['Reserva']]],
        'envíos cuya reserva no está FORMALIZADA' => ["SELECT COUNT(*) FROM Envio e JOIN Reserva r ON r.idReserva = e.idReserva WHERE e.idEnvio > :pe AND r.estadoReserva <> 'FORMALIZADA'", [':pe' => $pre['Envio']]],
        'reservas PENDIENTE con más de 72 h' => ["SELECT COUNT(*) FROM Reserva WHERE idReserva > :pr AND estadoReserva = 'PENDIENTE' AND fechaHoraReserva < DATEADD(HOUR, -72, :ahora)", [':pr' => $pre['Reserva'], ':ahora' => $ahora]],
        'reservas VENCIDA con menos de 72 h' => ["SELECT COUNT(*) FROM Reserva WHERE idReserva > :pr AND estadoReserva = 'VENCIDA' AND fechaHoraReserva >= DATEADD(HOUR, -72, :ahora)", [':pr' => $pre['Reserva'], ':ahora' => $ahora]],
        'envíos registrados fuera de las 72 h de su reserva' => ["SELECT COUNT(*) FROM Envio e JOIN Reserva r ON r.idReserva = e.idReserva WHERE e.idEnvio > :pe AND (e.fechaHoraRegistro < r.fechaHoraReserva OR e.fechaHoraRegistro > DATEADD(HOUR, 72, r.fechaHoraReserva))", [':pe' => $pre['Envio']]],
        'envíos sin bultos' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND NOT EXISTS (SELECT 1 FROM Bulto b WHERE b.idEnvio = e.idEnvio)", [':pe' => $pre['Envio']]],
        'bultos con ruta distinta a la de la reserva, tipo distinto al peso, tarifa no vigente o costo incorrecto' => ["SELECT COUNT(*) FROM Bulto b JOIN Envio e ON e.idEnvio = b.idEnvio JOIN Reserva r ON r.idReserva = e.idReserva JOIN Ruta ru ON ru.idRuta = b.idRuta
            WHERE b.idBulto > :pb AND (ru.idSucursalOrigen <> r.idSucursalOrigen OR ru.idSucursalDestino <> r.idSucursalDestino
              OR ru.tipoEnvio <> CASE WHEN b.pesoKg <= 1 THEN 'SOBRE' WHEN b.pesoKg <= 30 THEN 'PAQUETE' ELSE 'CARGA' END
              OR CAST(e.fechaHoraRegistro AS DATE) < ru.vigenteDesde OR (ru.vigenteHasta IS NOT NULL AND CAST(e.fechaHoraRegistro AS DATE) > ru.vigenteHasta)
              OR ABS(b.costo - ROUND(ru.precioBase + ru.precioPorKm * ru.distanciaKm, 2)) > 0.005)", [':pb' => $pre['Bulto']]],
        'envíos con pagos REGISTRADO mayores al costo' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND $PAGADO > $SALDO", [':pe' => $pre['Envio']]],
        'envíos ENTREGADO con saldo pendiente' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND e.estadoEnvio = 'ENTREGADO' AND $PAGADO <> $SALDO", [':pe' => $pre['Envio']]],
        'pagos anteriores al registro del envío o en el futuro' => ["SELECT COUNT(*) FROM Pago pg JOIN Envio e ON e.idEnvio = pg.idEnvio WHERE pg.idPago > :pp AND (pg.fechaHoraPago < e.fechaHoraRegistro OR pg.fechaHoraPago > :ahora)", [':pp' => $pre['Pago'], ':ahora' => $ahora]],
        'pagos sobre envíos ANULADO o DEVUELTO con estado REGISTRADO tras la anulación' => ["SELECT COUNT(*) FROM Pago pg JOIN Envio e ON e.idEnvio = pg.idEnvio WHERE pg.idPago > :pp AND e.estadoEnvio = 'ANULADO' AND pg.estadoPago = 'REGISTRADO'", [':pp' => $pre['Pago']]],
        'cargas anteriores al registro del envío' => ["SELECT COUNT(*) FROM Envio_Viaje ev JOIN Envio e ON e.idEnvio = ev.idEnvio WHERE ev.idEnvioViaje > :pc AND ev.fechaHoraCarga < e.fechaHoraRegistro", [':pc' => $pre['Envio_Viaje']]],
        'viajes que superan la capacidad de su vehículo' => ["SELECT COUNT(*) FROM Viaje v JOIN Vehiculo ve ON ve.idVehiculo = v.idVehiculo WHERE v.idViaje > :pv
            AND (SELECT COALESCE(SUM(b.pesoKg),0) FROM Envio_Viaje ev JOIN Bulto b ON b.idEnvio = ev.idEnvio WHERE ev.idViaje = v.idViaje) > ve.capacidadKg", [':pv' => $pre['Viaje']]],
        'viajes con fechas en el futuro' => ["SELECT COUNT(*) FROM Viaje WHERE idViaje > :pv AND (fechaHoraSalida > :ahora OR fechaHoraLlegada > :ahora2)", [':pv' => $pre['Viaje'], ':ahora' => $ahora, ':ahora2' => $ahora]],
        'viajes del mismo vehículo que se solapan' => ["SELECT COUNT(*) FROM Viaje a JOIN Viaje b ON a.idVehiculo = b.idVehiculo AND a.idViaje < b.idViaje WHERE (a.idViaje > :pv OR b.idViaje > :pv2) AND a.fechaHoraSalida IS NOT NULL AND b.fechaHoraSalida IS NOT NULL
            AND a.fechaHoraSalida < COALESCE(b.fechaHoraLlegada, '9999-12-31') AND b.fechaHoraSalida < COALESCE(a.fechaHoraLlegada, '9999-12-31')", [':pv' => $pre['Viaje'], ':pv2' => $pre['Viaje']]],
        'viajes del mismo transportista que se solapan' => ["SELECT COUNT(*) FROM Viaje a JOIN Viaje b ON a.idUsuarioTransportista = b.idUsuarioTransportista AND a.idViaje < b.idViaje WHERE (a.idViaje > :pv OR b.idViaje > :pv2) AND a.fechaHoraSalida IS NOT NULL AND b.fechaHoraSalida IS NOT NULL
            AND a.fechaHoraSalida < COALESCE(b.fechaHoraLlegada, '9999-12-31') AND b.fechaHoraSalida < COALESCE(a.fechaHoraLlegada, '9999-12-31')", [':pv' => $pre['Viaje'], ':pv2' => $pre['Viaje']]],
        'envíos REGISTRADO ya cargados en un viaje que salió' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND e.estadoEnvio = 'REGISTRADO' AND EXISTS (SELECT 1 FROM Envio_Viaje ev JOIN Viaje v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = e.idEnvio AND v.fechaHoraSalida IS NOT NULL)", [':pe' => $pre['Envio']]],
        'envíos EN_TRANSITO sin viaje que haya salido' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND e.estadoEnvio = 'EN_TRANSITO' AND NOT EXISTS (SELECT 1 FROM Envio_Viaje ev JOIN Viaje v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = e.idEnvio AND v.fechaHoraSalida IS NOT NULL)", [':pe' => $pre['Envio']]],
        'envíos EN_DESTINO/ENTREGADO/DEVUELTO que no llegaron al destino por viaje terminado' => ["SELECT COUNT(*) FROM Envio e JOIN Reserva r ON r.idReserva = e.idReserva WHERE e.idEnvio > :pe AND e.estadoEnvio IN ('EN_DESTINO','ENTREGADO','DEVUELTO')
            AND NOT EXISTS (SELECT 1 FROM Envio_Viaje ev JOIN Viaje v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = e.idEnvio AND v.fechaHoraLlegada IS NOT NULL AND v.idSucursalDestino = r.idSucursalDestino)", [':pe' => $pre['Envio']]],
        'entregas anteriores a la llegada final' => ["SELECT COUNT(*) FROM Envio e WHERE e.idEnvio > :pe AND e.estadoEnvio = 'ENTREGADO' AND e.fechaHoraEntrega < $ULTIMA_LLEGADA", [':pe' => $pre['Envio']]],
        'entregas en el futuro' => ["SELECT COUNT(*) FROM Envio WHERE idEnvio > :pe AND fechaHoraEntrega > :ahora", [':pe' => $pre['Envio'], ':ahora' => $ahora]],
        'envíos DOMICILIO sin dirección' => ["SELECT COUNT(*) FROM Envio WHERE idEnvio > :pe AND tipoEntrega = 'DOMICILIO' AND direccionEntrega IS NULL", [':pe' => $pre['Envio']]],
        'transportistas demo sin viajes' => ["SELECT COUNT(*) FROM Usuario u WHERE u.idUsuario > :pu AND u.rol = 'TRANSPORTISTA' AND NOT EXISTS (SELECT 1 FROM Viaje v WHERE v.idUsuarioTransportista = u.idUsuario)", [':pu' => $pre['Usuario']]],
    ];
    $resultado = [];
    foreach ($comprobaciones as $nombre => [$sql, $valores]) {
        $q = $pdo->prepare($sql);
        $q->execute($valores);
        $resultado[$nombre] = (int) $q->fetchColumn();
    }
    unset($p);

    return $resultado;
}

// ═══ Modo --verificar (solo lectura) ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($soloVerificar) {
    $manifiestoRuta = $salida . '\\manifiesto-demo.json';
    $m = is_file($manifiestoRuta) ? json_decode((string) file_get_contents($manifiestoRuta), true) : null;
    if (!is_array($m) || !isset($m['preMax'])) {
        abortar('No hay manifiesto de la carga en ' . $manifiestoRuta . '.', 2);
    }
    $r = verificarDemo($pdo, $m['preMax'], f($ahoraTs));
    $mal = 0;
    foreach ($r as $nombre => $n) {
        echo sprintf("  %s %s%s\n", $n === 0 ? '✓' : '✗', $nombre, $n === 0 ? '' : " ($n)");
        $mal += $n;
    }
    $h0 = $m['huellasAntes'];
    $h1 = huellas($pdo, $m['preMax']);
    $iguales = $h0 === $h1;
    echo $iguales ? "  ✓ los registros anteriores a la carga están intactos (huellas idénticas)\n" : "  ✗ LAS HUELLAS DE LOS REGISTROS ANTERIORES CAMBIARON\n";
    foreach (TABLAS as $t => $pk) {
        echo sprintf("  %-18s %d filas\n", $t, (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn());
    }
    exit($mal === 0 && $iguales ? 0 : 1);
}

// ═══ Marca de carga previa (repetibilidad) ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════
$yaHay = (int) $pdo->query("SELECT COUNT(*) FROM Usuario WHERE nombreUsuario = '" . MARCA . "'")->fetchColumn();
$otras = (int) $pdo->query("SELECT COUNT(*) FROM Usuario WHERE nombreUsuario LIKE '%.demo_'")->fetchColumn();
if ($yaHay > 0) {
    echo "La carga de demostración YA existe (cuenta " . MARCA . "). No se hace nada: no se duplica ninguna información.\n";
    exit(0);
}
if ($otras > 0) {
    abortar('Hay cuentas «.demoN» sin la marca de carga completa: estado inconsistente; revísalo antes de continuar.', 3);
}

// Datos existentes necesarios para no chocar ni solapar.
$sucursalIds = [];
foreach ($pdo->query('SELECT idSucursal, RTRIM(codigoSucursal) AS c FROM Sucursal') as $r) {
    $sucursalIds[$r['c']] = (int) $r['idSucursal'];
}
$sucursalCodigoPorId = array_flip($sucursalIds);
$rutasExistentes = [];
foreach ($pdo->query('SELECT idRuta, idSucursalOrigen o, idSucursalDestino d, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, CONVERT(VARCHAR(10), vigenteDesde, 23) AS desde, CONVERT(VARCHAR(10), vigenteHasta, 23) AS hasta FROM Ruta') as $r) {
    $rutasExistentes[$sucursalCodigoPorId[(int) $r['o']] . '|' . $sucursalCodigoPorId[(int) $r['d']] . '|' . $r['tipoEnvio'] . '|' . $r['desde']] = $r;
}
$vehiculosExistentes = [];
foreach ($pdo->query('SELECT idVehiculo, placa, capacidadKg FROM Vehiculo') as $r) {
    $vehiculosExistentes[$r['placa']] = ['id' => (int) $r['idVehiculo'], 'cap' => (float) $r['capacidadKg']];
}
$usuariosExistentes = [];
foreach ($pdo->query('SELECT idUsuario, nombreUsuario, rol, idSucursal FROM Usuario') as $r) {
    $usuariosExistentes[$r['nombreUsuario']] = ['id' => (int) $r['idUsuario'], 'rol' => $r['rol'], 'suc' => $r['idSucursal'] === null ? null : $sucursalCodigoPorId[(int) $r['idSucursal']]];
}
$documentosUsados = [];
foreach ($pdo->query('SELECT numeroDocumento FROM Persona') as $r) {
    $documentosUsados[$r['numeroDocumento']] = true;
}
$codigosEnvio = [];
foreach ($pdo->query('SELECT codigoSeguimiento FROM Envio') as $r) {
    $codigosEnvio[$r['codigoSeguimiento']] = true;
}
$codigosViaje = [];
foreach ($pdo->query('SELECT codigoViaje FROM Viaje') as $r) {
    $codigosViaje[$r['codigoViaje']] = true;
}
$maxReserva = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(codigoReserva, 2, 9) AS INT)), 0) FROM Reserva")->fetchColumn();
$ocupacion = []; // 'v:PLACA' / 't:usuario' => [[desde, hasta], ...] con los viajes existentes (en ruta = hasta el infinito)
$nombrePorIdUsuario = array_column(array_map(static fn (string $n, array $u): array => ['n' => $n, 'id' => $u['id']], array_keys($usuariosExistentes), $usuariosExistentes), 'n', 'id');
$placaPorId = array_column(array_map(static fn (string $p, array $v): array => ['p' => $p, 'id' => $v['id']], array_keys($vehiculosExistentes), $vehiculosExistentes), 'p', 'id');
foreach ($pdo->query('SELECT idVehiculo, idUsuarioTransportista, fechaHoraSalida, fechaHoraLlegada FROM Viaje WHERE fechaHoraSalida IS NOT NULL') as $r) {
    $a = strtotime((string) $r['fechaHoraSalida']);
    $b = $r['fechaHoraLlegada'] === null ? PHP_INT_MAX : strtotime((string) $r['fechaHoraLlegada']);
    $ocupacion['v:' . $placaPorId[(int) $r['idVehiculo']]][] = [$a, $b];
    $ocupacion['t:' . $nombrePorIdUsuario[(int) $r['idUsuarioTransportista']]][] = [$a, $b];
}
$huellasAntes = huellas($pdo, $preMax);
echo 'Existente: ', implode(', ', array_map(static fn (string $t): string => "$t {$huellasAntes[$t]['filas']}", array_keys(TABLAS))), "\n";

// ═══ Generación del conjunto (en memoria) ════════════════════════════════════════════════════════════════════════════════════════════════════════════════
require __DIR__ . '/datos-demo/generador.php';
$demo = generarDemo($ahoraTs, [
    'sucursalIds' => $sucursalIds, 'rutasExistentes' => $rutasExistentes, 'vehiculosExistentes' => $vehiculosExistentes, 'usuariosExistentes' => $usuariosExistentes,
    'documentosUsados' => $documentosUsados, 'codigosEnvio' => $codigosEnvio, 'codigosViaje' => $codigosViaje, 'maxReserva' => $maxReserva, 'ocupacion' => $ocupacion,
]);
$problemas = validarDemo($demo, $ahoraTs);
echo resumenDemo($demo);
if ($problemas !== []) {
    foreach (array_slice($problemas, 0, 25) as $p) {
        echo "  ✗ $p\n";
    }
    abortar(count($problemas) . ' problema(s) en el conjunto generado: no se escribe nada.');
}
echo "El conjunto generado supera todas las validaciones en memoria.\n";
if (!$ejecutar) {
    echo "Simulación: no se escribió nada. Para cargar: --ejecutar.\n";
    exit(0);
}

// ═══ Respaldo verificado ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
is_dir($salida) || mkdir($salida, 0700, true);
$archivoRespaldo = rtrim((string) $id['rutaRespaldo'], '\\') . '\\' . BD_DESTINO . '_antes-demo_' . date('Ymd_His', $ahoraTs) . '.bak';
echo "Respaldo (COPY_ONLY, CHECKSUM): $archivoRespaldo\n";
$pdo->exec("BACKUP DATABASE [" . BD_DESTINO . "] TO DISK = N'" . str_replace("'", "''", $archivoRespaldo) . "' WITH COPY_ONLY, CHECKSUM, INIT, NAME = N'JATSA antes de la carga demo'");
$pdo->exec("RESTORE VERIFYONLY FROM DISK = N'" . str_replace("'", "''", $archivoRespaldo) . "' WITH CHECKSUM");
$cabecera = $pdo->query("RESTORE HEADERONLY FROM DISK = N'" . str_replace("'", "''", $archivoRespaldo) . "'")->fetch();
if (!is_array($cabecera) || ($cabecera['DatabaseName'] ?? '') !== BD_DESTINO) {
    abortar('El respaldo no contiene la base esperada: no se escribe nada.', 5);
}
echo "Respaldo verificado (RESTORE VERIFYONLY con CHECKSUM; contiene " . $cabecera['DatabaseName'] . ").\n";

// ═══ Carga: UNA transacción ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
$pendiente = $salida . '\\credenciales-demo.pendiente.txt';
file_put_contents($pendiente, credencialesTexto($demo));
$cargado = false;
$pdo->beginTransaction();
try {
    insertarDemo($pdo, $demo, $preMax);
    $esperados = contarDemo($demo);
    foreach ($esperados as $t => $n) {
        $nuevas = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE " . TABLAS[$t] . ' > ' . $preMax[$t])->fetchColumn();
        if ($nuevas !== $n) {
            throw new RuntimeException("$t: se esperaban $n filas nuevas y hay $nuevas");
        }
    }
    $mal = array_filter(verificarDemo($pdo, $preMax, f($ahoraTs)));
    if ($mal !== []) {
        throw new RuntimeException('Verificación fallida antes de confirmar: ' . json_encode($mal, JSON_UNESCAPED_UNICODE));
    }
    if (huellas($pdo, $preMax) !== $huellasAntes) {
        throw new RuntimeException('Los registros anteriores cambiaron durante la carga.');
    }
    $pdo->commit();
    $cargado = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($pendiente);
    abortar('Carga revertida por completo: ' . $e->getMessage());
}
rename($pendiente, $salida . '\\credenciales-demo.txt');
$huellasDespues = huellas($pdo, $preMax);
file_put_contents($salida . '\\manifiesto-demo.json', json_encode(['cuando' => f($ahoraTs), 'preMax' => $preMax, 'huellasAntes' => $huellasAntes, 'respaldo' => $archivoRespaldo, 'insertadas' => contarDemo($demo)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Carga confirmada.\n";
foreach (TABLAS as $t => $pk) {
    echo sprintf("  %-18s %5d filas (nuevas: %d)\n", $t, (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), contarDemo($demo)[$t]);
}
echo $huellasDespues === $huellasAntes ? "Los registros anteriores están intactos (huellas idénticas).\n" : "ALERTA: las huellas de los registros anteriores cambiaron.\n";
echo "Credenciales de prueba (LOCAL, fuera de Git): {$salida}\\credenciales-demo.txt\n";
exit($huellasDespues === $huellasAntes ? 0 : 1);
