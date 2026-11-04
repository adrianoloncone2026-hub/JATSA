<?php
declare(strict_types=1);

/*
 * TRABAJO CON BASE DE DATOS de pm11, SOLO desde la COPIA de trabajo (con su .env aislado). NO SE HA EJECUTADO. Lo lanza pm11.php; no se usa a mano.
 *
 *   php pm11_trabajo.php cargar  <base> <estructura-derivada.sql>   (fase B: estructura + semilla sintética + READ_ONLY)
 *   php pm11_trabajo.php validar <base>                             (fase C: SOLO lectura: SELECT y GET /api/reportes/*)
 *
 * Plan y autorizaciones: docs/plan-pm11-validacion-sql.md. Aislamiento por configuración y verificación de destino en CADA conexión (la cuenta es sysadmin: no hay
 * aislamiento por permisos). Nunca se conecta a JATSA_Encomiendas ni a la base de pm10.
 */
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';
require __DIR__ . '/pm11_comun.php';
require __DIR__ . '/pm11_semilla.php';

use Jatsa\Core\Config;
use Jatsa\Core\Database;
use Jatsa\Modules\Reportes\FlotaRepository;
use Jatsa\Modules\Reportes\FlotaService;
use Jatsa\Modules\Reportes\ResumenRepository;
use Jatsa\Modules\Reportes\ResumenService;

$orden = (string) ($argv[1] ?? '');
$base = (string) ($argv[2] ?? '');
if (!in_array($orden, ['cargar', 'validar'], true) || !pm11NombreValido($base)) {
    pm11Abortar('Uso: pm11_trabajo.php cargar|validar <base aislada pm11>.', 2);
}
// Solo se ejecuta desde la COPIA de trabajo, nunca desde el repositorio ni desde el backend habitual.
$dirTrabajo = pm11DirTrabajo($base);
$copiaReal = realpath($dirTrabajo . '\\backend');
if ($copiaReal === false || strcasecmp(str_replace('/', '\\', $raiz), $copiaReal) !== 0) {
    pm11Abortar('Este script solo corre desde la copia de trabajo ' . $dirTrabajo . '\\backend (corre desde otra ruta).', 5);
}
$manifiesto = pm11LeerManifiesto($base);
if ($manifiesto === null || ($manifiesto['base'] ?? '') !== $base || empty($manifiesto['fases']['A'])) {
    pm11Abortar('No hay manifiesto de la fase A para esta base.', 5);
}
$loginEsperado = (string) $manifiesto['loginEsperado'];
$problemasEnv = pm11EnvProblemas((string) file_get_contents($raiz . '/.env'), $base, (string) $manifiesto['servidor'], (string) $manifiesto['puerto']);
if ($problemasEnv !== []) {
    pm11Abortar('El .env de la copia no es válido: ' . implode(' | ', $problemasEnv), 5);
}
Config::cargar($raiz);
date_default_timezone_set((string) Config::obtener('app.zona_horaria', 'America/Lima'));
if (Config::obtener('db.nombre') !== $base || (string) Config::obtener('db.host') !== (string) $manifiesto['servidor'] || (string) Config::obtener('db.puerto', '') !== (string) $manifiesto['puerto']) {
    pm11Abortar('La configuración cargada (DB_NAME, DB_HOST, DB_PORT) no coincide con la base aislada y el servidor del manifiesto.', 5);
}

/** Conexión de la copia (su .env) con la identidad verificada: base aislada, cuenta Windows esperada. */
function pm11ConexionCopia(string $base, string $loginEsperado): PDO
{
    $pdo = Database::conexion();
    pm11ExigirDestino($pdo, $base, $loginEsperado);

    return $pdo;
}

// ═══ FASE B: estructura + semilla + READ_ONLY ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($orden === 'cargar') {
    $rutaDdl = (string) ($argv[3] ?? '');
    if (!is_file($rutaDdl) || empty($manifiesto['fases']['B']['preparada']) || !empty($manifiesto['fases']['B']['hecha'])) {
        pm11Abortar('Falta la estructura derivada, la copia no está preparada o la carga ya se hizo.', 2);
    }
    $ddl = (string) file_get_contents($rutaDdl);
    $problemas = pm11DdlProblemas($ddl, $base);
    if ($problemas !== []) {
        pm11Abortar('La estructura derivada no es válida: ' . implode('; ', $problemas), 2);
    }
    $pdo = pm11ConexionCopia($base, $loginEsperado);
    if ((int) $pdo->query('SELECT COUNT(*) FROM sys.tables')->fetchColumn() !== 0) {
        pm11Abortar('La base aislada NO está vacía: no se carga nada sobre algo existente.', 3);
    }
    foreach (array_slice(pm11Lotes($ddl), 1) as $lote) {
        $pdo->exec($lote);
    }
    echo "Estructura aplicada (12 tablas).\n";

    $ahora = new DateTimeImmutable('now');
    $s = pm11Semilla($ahora);
    $clave = bin2hex(random_bytes(16)); // una contraseña aleatoria por ejecución; solo en un archivo temporal de la copia (se borra en la fase D)
    $hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 10]);
    $pdo->beginTransaction();
    try {
        $q = static fn (string $sql, array $v) => $pdo->prepare($sql)->execute($v);
        $mapa = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($s['sucursales'] as $x) {
            $q('INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa) VALUES (:c, :n, :d, :p, :di, :dir, 1)',
                [':c' => $x['codigo'], ':n' => $x['nombre'], ':d' => 'Prueba', ':p' => 'Prueba', ':di' => 'Prueba', ':dir' => 'Av. Sintética 100']);
        }
        $suc = array_map('intval', $mapa('SELECT RTRIM(codigoSucursal), idSucursal FROM Sucursal'));
        foreach ($s['personas'] as $i => $p) {
            $q("INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono) VALUES ('DNI', :d, :n, :t)", [':d' => $p['doc'], ':n' => $p['nombre'], ':t' => sprintf('999%06d', $i + 1)]);
        }
        $per = array_map('intval', $mapa("SELECT numeroDocumento, idPersona FROM Persona"));
        foreach ($s['usuarios'] as $u) {
            $q('INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol, activo) VALUES (:p, :s, :u, :h, :r, 1)',
                [':p' => $per[$u['doc']], ':s' => $u['sucursal'] === null ? null : $suc[$u['sucursal']], ':u' => $u['usuario'], ':h' => $hash, ':r' => $u['rol']]);
        }
        $usu = array_map('intval', $mapa('SELECT nombreUsuario, idUsuario FROM Usuario'));
        foreach ($s['vehiculos'] as $v) {
            $q('INSERT INTO Vehiculo (placa, tipoVehiculo, capacidadKg, activo) VALUES (:p, :t, :c, 1)', [':p' => $v['placa'], ':t' => $v['tipo'], ':c' => number_format($v['capacidadKg'], 2, '.', '')]);
        }
        $veh = array_map('intval', $mapa('SELECT placa, idVehiculo FROM Vehiculo'));
        $rut = [];
        foreach ($s['rutas'] as $clave2 => $r) {
            $q('INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta) VALUES (:o, :d, :t, 8.00, 0.005, 100.0, :h, :vd, :vh)',
                [':o' => $suc[$r['origen']], ':d' => $suc[$r['destino']], ':t' => $r['tipo'], ':h' => $r['horas'], ':vd' => $r['desde'], ':vh' => $r['hasta']]);
            $rut[$clave2] = (int) $pdo->query('SELECT MAX(idRuta) FROM Ruta')->fetchColumn();
        }

        $n = 0;
        $env = [];
        foreach ($s['envios'] as $id => $e) {
            $n++;
            $codigoReserva = 'R' . sprintf('%09d', 990000000 + $n);
            $q("INSERT INTO Reserva (idPersonaRemitente, idPersonaDestinatario, idSucursalOrigen, idSucursalDestino, codigoReserva, fechaHoraReserva, canal, descripcion, estadoReserva) VALUES (:r, :d, :o, :de, :c, :f, 'VENTANILLA', 'Reserva sintética pm11', 'FORMALIZADA')",
                [':r' => $per['99999907'], ':d' => $per['99999908'], ':o' => $suc[$e['origen']], ':de' => $suc[$e['destino']], ':c' => $codigoReserva, ':f' => $e['registro']]);
            $codigoEnvio = 'EN' . sprintf('%010d', 990000000 + $n);
            $q("INSERT INTO Envio (idReserva, codigoSeguimiento, fechaHoraRegistro, tipoEntrega, estadoEnvio, fechaHoraEntrega) VALUES ((SELECT idReserva FROM Reserva WHERE codigoReserva = :c), :s, :f, 'AGENCIA', :e, :fe)",
                [':c' => $codigoReserva, ':s' => $codigoEnvio, ':f' => $e['registro'], ':e' => $e['estado'], ':fe' => $e['entrega']]);
            $env[$id] = (int) $pdo->query("SELECT idEnvio FROM Envio WHERE codigoSeguimiento = '$codigoEnvio'")->fetchColumn();
            foreach ($e['bultos'] as $i => [$rutaClave, $kg]) {
                $q('INSERT INTO Bulto (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo) VALUES (:e, :r, :n, :d, :k, 10.00)',
                    [':e' => $env[$id], ':r' => $rut[$rutaClave], ':n' => $i + 1, ':d' => 'Bulto sintético', ':k' => number_format($kg, 2, '.', '')]);
            }
        }
        foreach ($s['pagos'] as $p) {
            $q("INSERT INTO Pago (idEnvio, idSucursal, fechaHoraPago, monto, metodoPago, pagadoPor, estadoPago) VALUES (:e, :s, :f, :m, 'EFECTIVO', 'REMITENTE', :est)",
                [':e' => $env[$p['envio']], ':s' => $suc[$p['sucursal']], ':f' => $p['fecha'], ':m' => $p['monto'], ':est' => $p['estado']]);
        }
        foreach ($s['viajes'] as $id => $v) {
            $codigo = RepoFlotaOraculo::codigoViaje($id);
            $q('INSERT INTO Viaje (idVehiculo, idUsuarioTransportista, idSucursalOrigen, idSucursalDestino, codigoViaje, fechaHoraSalida, fechaHoraLlegada) VALUES (:v, :u, :o, :d, :c, :s, :l)',
                [':v' => $veh[$v['vehiculo']], ':u' => $usu[$v['trans']], ':o' => $suc[$v['origen']], ':d' => $suc[$v['destino']], ':c' => $codigo, ':s' => $v['salida'], ':l' => $v['llegada']]);
            $idViaje = (int) $pdo->query("SELECT idViaje FROM Viaje WHERE codigoViaje = '$codigo'")->fetchColumn();
            foreach ($v['carga'] as $e) {
                $q('INSERT INTO Envio_Viaje (idEnvio, idViaje, fechaHoraCarga) VALUES (:e, :v, :f)', [':e' => $env[$e], ':v' => $idViaje, ':f' => date('Y-m-d H:i:s', strtotime((string) $v['salida']) - 3600)]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        pm11Abortar('La semilla se deshizo: ' . $e->getMessage());
    }

    $fallos = [];
    foreach (pm11Conteos($s) as $tabla => $esperadas) {
        $real = (int) $pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
        echo sprintf("%-18s %d fila(s)%s\n", $tabla, $real, $real === $esperadas ? '' : "  <-- se esperaban $esperadas");
        $real === $esperadas || $fallos[] = $tabla;
    }
    if ($fallos !== []) {
        pm11Abortar('Los conteos no coinciden en: ' . implode(', ', $fallos));
    }
    file_put_contents($dirTrabajo . '\\credenciales_pm11.json', json_encode(['contrasena' => $clave, 'usuarios' => array_column($s['usuarios'], 'rol', 'usuario')]));
    $pdo = null;

    // La base pasa a SOLO LECTURA (desde «master», con una conexión verificada): cualquier escritura accidental de la fase C fallará.
    $master = pm11ConexionMaster((string) $manifiesto['servidor'], (string) $manifiesto['puerto'], $loginEsperado);
    $master->exec("ALTER DATABASE [$base] SET READ_ONLY WITH ROLLBACK IMMEDIATE");
    if ((int) $master->query("SELECT is_read_only FROM sys.databases WHERE name = '$base'")->fetchColumn() !== 1) {
        pm11Abortar('La base no quedó en READ_ONLY.');
    }
    $manifiesto['fases']['B'] = ['preparada' => true, 'hecha' => true, 'ahoraSemilla' => $s['meta']['ahora'], 'soloLectura' => true];
    pm11GuardarManifiesto($base, $manifiesto);
    echo "Semilla sintética cargada y base «{$base}» en READ_ONLY.\n";
    exit(0);
}

// ═══ FASE C: validación SOLO lectura ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (empty($manifiesto['fases']['B']['hecha']) || empty($manifiesto['fases']['B']['soloLectura'])) {
    pm11Abortar('La fase B no se completó (semilla y READ_ONLY).', 2);
}
$credenciales = json_decode((string) @file_get_contents($dirTrabajo . '\\credenciales_pm11.json'), true);
if (!is_array($credenciales) || empty($credenciales['contrasena'])) {
    pm11Abortar('Faltan las credenciales sintéticas de la semilla.', 2);
}
$pdo = pm11ConexionCopia($base, $loginEsperado);
$antes = pm11Instantanea($pdo);
if ($antes['soloLectura'] !== 1) {
    pm11Abortar('La base NO está en READ_ONLY: no se valida.', 5);
}
$ahoraSemilla = new DateTimeImmutable((string) $manifiesto['fases']['B']['ahoraSemilla']);
$s = pm11Semilla($ahoraSemilla);
$ahora = new DateTimeImmutable('now');
$reloj = static fn (): DateTimeImmutable => $ahora;
$resultados = ['ok' => 0, 'fallos' => []];
$anotar = static function (bool $ok, string $nombre) use (&$resultados): void {
    $ok ? $resultados['ok']++ : $resultados['fallos'][] = $nombre;
};
$normalizar = static function (mixed $x) use (&$normalizar): mixed {
    if (is_float($x)) {
        return round($x, 9);
    }
    if (is_array($x)) {
        $n = array_map($normalizar, $x);
        array_is_list($n) || ksort($n);

        return $n;
    }

    return $x;
};
$igual = static fn (mixed $a, mixed $b): bool => json_encode($normalizar($a), JSON_PRESERVE_ZERO_FRACTION) === json_encode($normalizar($b), JSON_PRESERVE_ZERO_FRACTION);

// Periodos y filtros de los casos.
$mes = static fn (string $aaaamm): array => [$aaaamm . '-01 00:00:00', (new DateTimeImmutable($aaaamm . '-01 00:00:00'))->modify('+1 month')->format('Y-m-d H:i:s')];
$y = $s['meta']['anioCruce'];
$periodos = [
    'M' => $mes($s['meta']['mes']), 'M-1' => $mes($s['meta']['mesAnterior']), 'actual' => $mes($s['meta']['mesActual']), 'enero Y' => $mes(sprintf('%04d-01', $y)),
    'diciembre Y-1' => $mes(sprintf('%04d-12', $y - 1)), 'año Y' => [sprintf('%04d-01-01 00:00:00', $y), sprintf('%04d-01-01 00:00:00', $y + 1)], 'vacío' => ['2000-01-01 00:00:00', '2000-02-01 00:00:00'],
];
$vacio = ['sucursal' => null, 'placa' => null, 'transportista' => null, 'tipoEnvio' => null];
$filtrosFlota = [
    'todos' => $vacio, 'sucursal TST003' => ['sucursal' => 'TST003'] + $vacio, 'sucursal TST002' => ['sucursal' => 'TST002'] + $vacio, 'tipo SOBRE' => ['tipoEnvio' => 'SOBRE'] + $vacio,
    'tipo PAQUETE' => ['tipoEnvio' => 'PAQUETE'] + $vacio, 'tipo CARGA' => ['tipoEnvio' => 'CARGA'] + $vacio, 'placa TST-002' => ['placa' => 'TST-002'] + $vacio,
    'transportista pm11_trans1' => ['transportista' => 'pm11_trans1'] + $vacio, 'combinación vacía' => ['sucursal' => 'TST003', 'placa' => 'TST-001'] + $vacio,
    'combinación 3 filtros' => ['sucursal' => 'TST001', 'tipoEnvio' => 'PAQUETE', 'transportista' => 'pm11_trans1'] + $vacio,
];
$oR = new RepoResumenOraculo($s);
$oF = new RepoFlotaOraculo($s);
$rR = new ResumenRepository();
$rF = new FlotaRepository();
$limite = $ahora->modify('-3 days')->format('Y-m-d H:i:s');
$hoy = $ahora->format('Y-m-d');
$cubiertos = [];

// 1) Los 8 métodos reales de consulta, SQL contra oráculo.
foreach ([null, 'TST001', 'TST002', 'TST003'] as $sucursal) {
    foreach ($periodos as $nombre => [$d, $h]) {
        $anotar($igual($rR->enviosDelPeriodo($d, $h, $sucursal, $limite), $oR->enviosDelPeriodo($d, $h, $sucursal, $limite)), "ResumenRepository::enviosDelPeriodo [$nombre, " . ($sucursal ?? 'todas') . ']');
        $anotar($igual($rR->cobradoDelPeriodo($d, $h, $sucursal), $oR->cobradoDelPeriodo($d, $h, $sucursal)), "ResumenRepository::cobradoDelPeriodo [$nombre, " . ($sucursal ?? 'todas') . ']');
    }
    $anotar($igual($rR->viajesAbiertos($sucursal), $oR->viajesAbiertos($sucursal)), 'ResumenRepository::viajesAbiertos [' . ($sucursal ?? 'todas') . ']');
}
array_push($cubiertos, 'ResumenRepository::enviosDelPeriodo', 'ResumenRepository::cobradoDelPeriodo', 'ResumenRepository::viajesAbiertos');
foreach ($filtrosFlota as $fn => $f) {
    $anotar($igual($rF->viajesAbiertos($f), $oF->viajesAbiertos($f)), "FlotaRepository::viajesAbiertos [$fn]");
    $anotar($igual($rF->viajesEnRuta($f, $hoy), $oF->viajesEnRuta($f, $hoy)), "FlotaRepository::viajesEnRuta [$fn]");
    foreach ($periodos as $nombre => [$d, $h]) {
        $anotar($igual($rF->terminados($f, $d, $h), $oF->terminados($f, $d, $h)), "FlotaRepository::terminados [$nombre, $fn]");
        $anotar($igual($rF->transbordos($f, $d, $h), $oF->transbordos($f, $d, $h)), "FlotaRepository::transbordos [$nombre, $fn]");
        $anotar($igual($rF->transportistas($f, $d, $h), $oF->transportistas($f, $d, $h)), "FlotaRepository::transportistas [$nombre, $fn]");
    }
}
array_push($cubiertos, 'FlotaRepository::viajesAbiertos', 'FlotaRepository::terminados', 'FlotaRepository::transbordos', 'FlotaRepository::viajesEnRuta', 'FlotaRepository::transportistas');
// Pruebas de que el oráculo no es trivial: hay datos reales en los casos clave (si no, comparar vacío con vacío no probaría nada).
$anotar($oR->enviosDelPeriodo($periodos['M'][0], $periodos['M'][1], null, $limite)['porEstado'] !== [] && $oF->terminados($vacio, ...$periodos['M'])['viajes'] === 3, 'el escenario M tiene datos (3 viajes terminados)');

// 2) Servidor HTTP de la copia: sonda de destino, login por rol, 401/403/422 y respuestas 200 contra los servicios con el oráculo.
$publico = $raiz . '\\public';
copy(__DIR__ . '\\sonda_db.plantilla', $publico . '\\sonda_db.php');
$puerto = 18111;
if (@fsockopen('127.0.0.1', $puerto, $en, $es, 0.3) !== false) {
    @unlink($publico . '\\sonda_db.php');
    pm11Abortar("El puerto $puerto ya está en uso: no se arranca el servidor.", 3);
}
$proceso = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', $publico], [0 => ['pipe', 'r'], 1 => ['file', $dirTrabajo . '\\servidor.log', 'a'], 2 => ['file', $dirTrabajo . '\\servidor.log', 'a']], $tuberias, $raiz);
$pid = $proceso === false ? 0 : (int) (proc_get_status($proceso)['pid'] ?? 0);
try {
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $puerto, $e1, $e2, 0.2) === false; $i++) {
        usleep(100000);
    }
    $http = static function (string $metodo, string $ruta, ?string $cookie = null, ?array $json = null) use ($puerto): array {
        $cab = ['Accept: application/json', 'X-Requested-With: JATSA'] + ($json !== null ? [2 => 'Content-Type: application/json'] : []);
        $cookie === null || $cab[] = 'Cookie: ' . $cookie;
        $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => implode("\r\n", $cab), 'content' => $json !== null ? json_encode($json) : '', 'ignore_errors' => true, 'timeout' => 30]]);
        $cuerpo = @file_get_contents("http://127.0.0.1:$puerto$ruta", false, $ctx);
        $cabeceras = $http_response_header ?? [];
        $estado = isset($cabeceras[0]) && preg_match('~\s(\d{3})\s~', $cabeceras[0], $m) === 1 ? (int) $m[1] : 0;
        $galleta = null;
        foreach ($cabeceras as $c) {
            if (stripos($c, 'Set-Cookie:') === 0) {
                $galleta = trim(explode(';', substr($c, 11), 2)[0]);
            }
        }

        return ['estado' => $estado, 'json' => json_decode((string) $cuerpo, true), 'cookie' => $galleta];
    };

    $sonda = $http('GET', '/sonda_db.php')['json'];
    if (!is_array($sonda) || !pm11IdentidadValida($sonda, $base, $loginEsperado)) {
        // Sin identidad verificada del proceso HTTP no se hace NINGUNA otra petición: se cae al bloque finally (servidor y sonda se limpian) y se aborta.
        throw new RuntimeException('El proceso HTTP NO está en la base aislada con la cuenta esperada (sonda): ' . json_encode($sonda));
    }

    $cookies = [];
    foreach ($credenciales['usuarios'] as $usuario => $rol) {
        $r = $http('POST', '/api/auth/ingresar', null, ['usuario' => $usuario, 'contrasena' => $credenciales['contrasena']]);
        $anotar($r['estado'] === 200 && $r['cookie'] !== null, "ingreso de $rol");
        $cookies[$rol] = $r['cookie'];
    }
    $consultas = [
        ['resumen M', '/api/reportes/resumen?mes=' . $s['meta']['mes'], ['mes' => $s['meta']['mes']]],
        ['resumen M sucursal TST003', '/api/reportes/resumen?mes=' . $s['meta']['mes'] . '&sucursal=TST003', ['mes' => $s['meta']['mes'], 'sucursal' => 'TST003']],
        ['resumen M sucursal tst001 (minúsculas)', '/api/reportes/resumen?mes=' . $s['meta']['mes'] . '&sucursal=tst001', ['mes' => $s['meta']['mes'], 'sucursal' => 'tst001']],
        ['resumen mes actual', '/api/reportes/resumen?mes=' . $s['meta']['mesActual'], ['mes' => $s['meta']['mesActual']]],
        ['resumen enero Y (cruce de año)', sprintf('/api/reportes/resumen?mes=%04d-01', $y), ['mes' => sprintf('%04d-01', $y)]],
        ['resumen diciembre Y-1', sprintf('/api/reportes/resumen?mes=%04d-12', $y - 1), ['mes' => sprintf('%04d-12', $y - 1)]],
        ['resumen sin datos', '/api/reportes/resumen?mes=2000-01', ['mes' => '2000-01']],
        ['resumen límite 9998-12', '/api/reportes/resumen?mes=9998-12', ['mes' => '9998-12']],
    ];
    $mm = (int) substr($s['meta']['mes'], 5, 2);
    $aa = (int) substr($s['meta']['mes'], 0, 4);
    foreach ([['todos', []], ['sucursal TST003', ['sucursal' => 'TST003']], ['tipo SOBRE', ['tipoEnvio' => 'SOBRE']], ['tipo CARGA', ['tipoEnvio' => 'CARGA']], ['tipo paquete (minúsculas)', ['tipoEnvio' => 'paquete']],
        ['placa TST-002', ['vehiculo' => 'TST-002']], ['transportista pm11_trans1', ['transportista' => 'pm11_trans1']], ['combinación vacía', ['sucursal' => 'TST003', 'vehiculo' => 'TST-001']]] as [$n, $f]) {
        $q = ['anio' => (string) $aa, 'mes' => (string) $mm] + $f;
        $consultas[] = ["flota M $n", '/api/reportes/viajes-flota?' . http_build_query($q), $q];
    }
    foreach ([['año Y completo', ['anio' => (string) $y]], ['enero Y (cruce de año)', ['anio' => (string) $y, 'mes' => '1']], ['diciembre Y-1', ['anio' => (string) ($y - 1), 'mes' => '12']], ['sin datos', ['anio' => '2000']], ['límite 9998', ['anio' => '9998']]] as [$n, $q]) {
        $consultas[] = ["flota $n", '/api/reportes/viajes-flota?' . http_build_query($q), $q];
    }
    $servicioResumen = new ResumenService($oR, $reloj);
    $servicioFlota = new FlotaService($oF, $reloj);
    foreach ($consultas as [$nombre, $ruta, $q]) {
        $r = $http('GET', $ruta, $cookies['GERENTE']);
        $esperado = str_contains($ruta, '/resumen') ? $servicioResumen->resumen($q) : $servicioFlota->viajesYFlota($q);
        $anotar($r['estado'] === 200 && $igual($r['json']['data'] ?? null, $esperado), "GET 200 y datos esperados: $nombre");
    }
    foreach (['/api/reportes/resumen', '/api/reportes/viajes-flota'] as $ruta) {
        $anotar($http('GET', $ruta)['estado'] === 401, "401 sin sesión: $ruta");
        foreach (['ADMINISTRADOR', 'TRANSPORTISTA', 'CLIENTE', 'EMPRESARIO'] as $rol) {
            $anotar($http('GET', $ruta, $cookies[$rol])['estado'] === 403, "403 para $rol: $ruta");
        }
    }
    foreach ([['/api/reportes/resumen?mes=9999-12', 'mes'], ['/api/reportes/resumen?mes=2026-13', 'mes'], ['/api/reportes/resumen?sucursal=%27%3B--', 'sucursal'], ['/api/reportes/viajes-flota?anio=9999', 'anio'],
        ['/api/reportes/viajes-flota?mes=13', 'mes'], ['/api/reportes/viajes-flota?tipoEnvio=DOMICILIO', 'tipoEnvio']] as [$ruta, $campo]) {
        $r = $http('GET', $ruta, $cookies['GERENTE']);
        $anotar($r['estado'] === 422 && isset($r['json']['error']['campos'][$campo]), "422 con campo $campo: $ruta");
    }
} catch (RuntimeException $detenido) {
    $detencion = $detenido->getMessage();
} finally {
    if ($pid > 0) {
        @exec("taskkill /F /T /PID $pid 2>&1");
    }
    is_resource($proceso) && proc_close($proceso);
    @unlink($publico . '\\sonda_db.php');
}

if (isset($detencion)) {
    pm11Abortar($detencion . ' Las pruebas se detuvieron sin consultar nada más.', 5);
}

// 3) Las 12 tablas quedaron idénticas y la base sigue en READ_ONLY.
$despues = pm11Instantanea($pdo);
$diferencias = pm11DiferenciasInstantaneas($antes, $despues);
$anotar($diferencias === [] && $despues['soloLectura'] === 1, 'las 12 tablas idénticas antes y después, sin objetos nuevos y base en READ_ONLY' . ($diferencias === [] ? '' : ': ' . implode('; ', $diferencias)));

echo 'Métodos de consulta cubiertos (' . count($cubiertos) . '/8): ', implode(', ', $cubiertos), "\n";
echo "Comprobaciones correctas: {$resultados['ok']}\n";
foreach ($resultados['fallos'] as $f) {
    echo "FALLO: $f\n";
}
$manifiesto['fases']['C'] = ['ok' => $resultados['ok'], 'fallos' => count($resultados['fallos']), 'metodosCubiertos' => count($cubiertos)];
pm11GuardarManifiesto($base, $manifiesto);
echo $resultados['fallos'] === [] && count($cubiertos) === 8 ? "RESULTADO: todas correctas\n" : "RESULTADO: hay fallos\n";
exit($resultados['fallos'] === [] && count($cubiertos) === 8 ? 0 : 1);
