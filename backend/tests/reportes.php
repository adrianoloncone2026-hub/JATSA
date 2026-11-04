<?php
declare(strict_types=1);

// Pruebas del modulo 11 (Resumen y Viajes y flota del gerente). Se incluye desde ejecutar.php: usa su mini-marco y sus ayudas.
// Sin base de datos: repositorios simulados que registran con que argumentos se les llama.

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Reportes\FlotaService;
use Jatsa\Modules\Reportes\RepositorioFlota;
use Jatsa\Modules\Reportes\RepositorioResumen;
use Jatsa\Modules\Reportes\ReportesController;
use Jatsa\Modules\Reportes\ResumenService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

final class ResumenFalso implements RepositorioResumen
{
    /** @var list<array<string, mixed>> */
    public array $llamadas = [];
    /** @var array<string, array{porEstado: array<string, int>, antiguos: int}> clave: fecha "desde" */
    public array $envios = [];
    /** @var array<string, string> */
    public array $cobrado = [];
    public array $abiertos = ['enRuta' => 2, 'programados' => 6];

    public function enviosDelPeriodo(string $desde, string $hasta, ?string $sucursal, string $limiteAntiguedad): array
    {
        $this->llamadas[] = ['envios', $desde, $hasta, $sucursal, $limiteAntiguedad];

        return $this->envios[substr($desde, 0, 7)] ?? ['porEstado' => [], 'antiguos' => 0];
    }

    public function cobradoDelPeriodo(string $desde, string $hasta, ?string $sucursal): string
    {
        $this->llamadas[] = ['cobrado', $desde, $hasta, $sucursal];

        return $this->cobrado[substr($desde, 0, 7)] ?? '0.00';
    }

    public function viajesAbiertos(?string $sucursal): array
    {
        $this->llamadas[] = ['abiertos', $sucursal];

        return $this->abiertos;
    }
}

final class FlotaFalsa implements RepositorioFlota
{
    /** @var list<array<string, mixed>> */
    public array $llamadas = [];
    public array $abiertos = ['enRuta' => 2, 'programados' => 6];
    public array $terminados = ['viajes' => 142, 'pesoKg' => '18640.00', 'usoMedio' => 0.374];
    public array $transbordos = ['envios' => 200, 'conTransbordo' => 18];
    public array $enRuta = [];
    public array $transportistas = [];

    public function viajesAbiertos(array $filtros): array
    {
        $this->llamadas[] = ['abiertos', $filtros];

        return $this->abiertos;
    }

    public function terminados(array $filtros, string $desde, string $hasta): array
    {
        $this->llamadas[] = ['terminados', $filtros, $desde, $hasta];

        return $this->terminados;
    }

    public function transbordos(array $filtros, string $desde, string $hasta): array
    {
        return $this->transbordos;
    }

    public function viajesEnRuta(array $filtros, string $hoy): array
    {
        $this->llamadas[] = ['enRuta', $hoy];

        return $this->enRuta;
    }

    public function transportistas(array $filtros, string $desde, string $hasta): array
    {
        return $this->transportistas;
    }
}

$ahoraM11 = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-20 12:00:00');

prueba('resumen: cuenta los cuatro grupos, por entregar y variacion contra el mes anterior', function () use ($ahoraM11) {
    $repo = new ResumenFalso();
    $repo->envios['2026-10'] = ['porEstado' => ['ENTREGADO' => 112, 'EN_TRANSITO' => 21, 'EN_DESTINO' => 25, 'REGISTRADO' => 9, 'ANULADO' => 4, 'DEVUELTO' => 2], 'antiguos' => 12];
    $repo->envios['2026-09'] = ['porEstado' => ['ENTREGADO' => 100, 'EN_TRANSITO' => 0, 'EN_DESTINO' => 0, 'REGISTRADO' => 0, 'ANULADO' => 7], 'antiguos' => 0];
    $repo->cobrado = ['2026-10' => '5511.00', '2026-09' => '4670.00'];
    $r = (new ResumenService($repo, $ahoraM11))->resumen(['mes' => '2026-10']);
    afirmar($r['mes'] === '2026-10' && $r['sucursal'] === null, 'mes y sucursal');
    afirmar($r['enviosRegistrados']['total'] === 167, 'registrados = 112+21+25+9; no cuenta ANULADO ni DEVUELTO');
    afirmar($r['enviosRegistrados']['variacionPct'] === 67, 'variacion 167 vs 100');
    afirmar($r['cobrado']['total'] === '5511.00' && $r['cobrado']['variacionPct'] === 18, 'cobrado y +18 %');
    afirmar($r['porEntregar'] === ['total' => 46, 'masDeTresDias' => 12], 'por entregar = en transito + en destino');
    afirmar($r['viajes'] === ['enRuta' => 2, 'programados' => 6], 'viajes abiertos');
    afirmar($r['dondeEstan'] === ['ENTREGADO' => 112, 'EN_TRANSITO' => 21, 'EN_DESTINO' => 25, 'REGISTRADO' => 9], 'dondeEstan');
});

prueba('resumen: periodos [inicio, siguiente), mes anterior, limite de 3 dias y sucursal sin mezclar reglas', function () use ($ahoraM11) {
    $repo = new ResumenFalso();
    (new ResumenService($repo, $ahoraM11))->resumen(['mes' => '2026-01', 'sucursal' => 'moq001']);
    $e = array_values(array_filter($repo->llamadas, fn ($l) => $l[0] === 'envios'));
    afirmar($e[0][1] === '2026-01-01 00:00:00' && $e[0][2] === '2026-02-01 00:00:00' && $e[0][3] === 'MOQ001', 'mes pedido, sucursal en mayusculas');
    afirmar($e[1][1] === '2025-12-01 00:00:00' && $e[1][2] === '2026-01-01 00:00:00', 'el mes anterior cruza el anio');
    afirmar($e[0][4] === '2026-10-17 12:00:00', 'limite = ahora - 3 dias (registro del envio, no otra fecha)');
    $c = array_values(array_filter($repo->llamadas, fn ($l) => $l[0] === 'cobrado'));
    afirmar($c[0][3] === 'MOQ001' && count($c) === 2, 'cobrado con la misma sucursal, consultado aparte');
});

prueba('resumen: sin datos devuelve ceros y variacion nula; mes por defecto = el actual', function () use ($ahoraM11) {
    $r = (new ResumenService(new ResumenFalso(), $ahoraM11))->resumen([]);
    afirmar($r['mes'] === '2026-10', 'mes por defecto');
    afirmar($r['enviosRegistrados'] === ['total' => 0, 'variacionPct' => null] && $r['cobrado']['variacionPct'] === null, 'sin base: null, no division por cero');
    afirmar($r['porEntregar']['total'] === 0 && $r['dondeEstan']['ENTREGADO'] === 0, 'ceros');
    afirmar(ResumenService::variacion(5.0, 0.0) === null && ResumenService::variacion(50.0, 100.0) === -50, 'variacion');
});

prueba('resumen: filtros invalidos -> 422 con campos, sin tocar el repositorio', function () use ($ahoraM11) {
    $repo = new ResumenFalso();
    $e = esperarError(fn () => (new ResumenService($repo, $ahoraM11))->resumen(['mes' => '2026-13', 'sucursal' => "x'; DROP"]), 422, 'datos_invalidos');
    afirmar(isset($e->campos['mes'], $e->campos['sucursal']) && $repo->llamadas === [], 'campos y sin consultas');
    esperarError(fn () => (new ResumenService($repo, $ahoraM11))->resumen(['mes' => 'octubre']), 422, 'datos_invalidos');
});

prueba('viajes y flota: indicadores, periodo (mes o anio completo) y filtros pasan al repositorio', function () use ($ahoraM11) {
    $repo = new FlotaFalsa();
    $r = (new FlotaService($repo, $ahoraM11))->viajesYFlota(['anio' => '2026', 'mes' => '10', 'sucursal' => 'aqp001', 'vehiculo' => 'b7k-210', 'transportista' => 'pmamani', 'tipoEnvio' => 'paquete']);
    afirmar($r['indicadores'] === ['viajesTerminados' => 142, 'enRutaAhora' => 2, 'programados' => 6, 'pesoKg' => '18640.00', 'usoMedioCapacidadPct' => 37, 'enviosConTransbordoPct' => 9], 'indicadores');
    $t = array_values(array_filter($repo->llamadas, fn ($l) => $l[0] === 'terminados'))[0];
    afirmar($t[2] === '2026-10-01 00:00:00' && $t[3] === '2026-11-01 00:00:00', 'periodo del mes');
    afirmar($t[1] === ['sucursal' => 'AQP001', 'placa' => 'B7K-210', 'transportista' => 'pmamani', 'tipoEnvio' => 'PAQUETE'], 'filtros normalizados');
    $repo2 = new FlotaFalsa();
    (new FlotaService($repo2, $ahoraM11))->viajesYFlota(['anio' => '2025']);
    $t = array_values(array_filter($repo2->llamadas, fn ($l) => $l[0] === 'terminados'))[0];
    afirmar($t[2] === '2025-01-01 00:00:00' && $t[3] === '2026-01-01 00:00:00' && $t[1]['tipoEnvio'] === null, 'sin mes: todo el anio; sin filtros = null');
    $repo3 = new FlotaFalsa();
    (new FlotaService($repo3, $ahoraM11))->viajesYFlota([]);
    $t = array_values(array_filter($repo3->llamadas, fn ($l) => $l[0] === 'terminados'))[0];
    afirmar($t[2] === '2026-01-01 00:00:00', 'anio por defecto = el actual');
});

prueba('viajes y flota: sin viajes devuelve nulos (sin division por cero) y listas vacias', function () use ($ahoraM11) {
    $repo = new FlotaFalsa();
    $repo->terminados = ['viajes' => 0, 'pesoKg' => '0.00', 'usoMedio' => null];
    $repo->transbordos = ['envios' => 0, 'conTransbordo' => 0];
    $r = (new FlotaService($repo, $ahoraM11))->viajesYFlota(['anio' => '2026']);
    afirmar($r['indicadores']['usoMedioCapacidadPct'] === null && $r['indicadores']['enviosConTransbordoPct'] === null, 'nulos');
    afirmar($r['viajesEnRuta'] === [] && $r['transportistas'] === [], 'listas vacias');
});

prueba('viajes y flota: tipo de envio solo SOBRE/PAQUETE/CARGA (no AGENCIA/DOMICILIO) y demas filtros validados', function () use ($ahoraM11) {
    $s = new FlotaService(new FlotaFalsa(), $ahoraM11);
    $e = esperarError(fn () => $s->viajesYFlota(['tipoEnvio' => 'DOMICILIO', 'mes' => '0', 'anio' => '99', 'vehiculo' => 'abc', 'transportista' => 'a b', 'sucursal' => '??']), 422, 'datos_invalidos');
    foreach (['tipoEnvio', 'mes', 'anio', 'vehiculo', 'transportista', 'sucursal'] as $campo) {
        afirmar(isset($e->campos[$campo]), "error en $campo");
    }
});

prueba('fechas extremas: 9999 se rechaza con 422 (no llega a SQL Server) y 9998 es el ultimo valido', function () use ($ahoraM11) {
    $repo = new ResumenFalso();
    $r = new ResumenService($repo, $ahoraM11);
    foreach (['9999-12', '9999-01', '10000-01', '1999-12', '2026-00'] as $mes) {
        $e = esperarError(fn () => $r->resumen(['mes' => $mes]), 422, 'datos_invalidos');
        afirmar(isset($e->campos['mes']), "mes $mes");
    }
    afirmar($repo->llamadas === [], 'no se consulto nada con un mes invalido');
    $limite = $r->resumen(['mes' => '9998-12']);
    $siguiente = array_values(array_filter($repo->llamadas, fn ($l) => $l[0] === 'envios'))[0][2];
    afirmar($limite['mes'] === '9998-12' && $siguiente === '9999-01-01 00:00:00', '9998-12 llega hasta 9999-01-01, dentro de datetime2');
    $repoF = new FlotaFalsa();
    $f = new FlotaService($repoF, $ahoraM11);
    foreach (['9999', '10000', '1999', '0'] as $anio) {
        $e = esperarError(fn () => $f->viajesYFlota(['anio' => $anio]), 422, 'datos_invalidos');
        afirmar(isset($e->campos['anio']), "anio $anio");
    }
    afirmar($repoF->llamadas === [], 'no se consulto nada con un anio invalido');
    $f->viajesYFlota(['anio' => '9998']);
    $t = array_values(array_filter($repoF->llamadas, fn ($l) => $l[0] === 'terminados'))[0];
    afirmar($t[2] === '9998-01-01 00:00:00' && $t[3] === '9999-01-01 00:00:00', 'anio 9998 completo termina en 9999-01-01');
    $f->viajesYFlota(['anio' => '9998', 'mes' => '12']);
});

prueba('viajes y flota: llega aprox. = salida + horas de la ruta, con +1 si cruza la medianoche; sin tiempo -> null', function () {
    $v = FlotaService::conLlegadaAproximada(['salida' => '2026-10-30 20:25:00', 'horas' => 18]);
    afirmar($v['llegadaAprox'] === '2026-10-31 14:25:00' && $v['diasDespues'] === 1, '+1');
    $v = FlotaService::conLlegadaAproximada(['salida' => '2026-10-14 15:00:00', 'horas' => 4]);
    afirmar($v['llegadaAprox'] === '2026-10-14 19:00:00' && $v['diasDespues'] === 0, 'mismo dia');
    $v = FlotaService::conLlegadaAproximada(['salida' => '2026-10-14 15:00:00', 'horas' => null]);
    afirmar($v['llegadaAprox'] === null && $v['diasDespues'] === null, 'sin ruta vigente');
});

prueba('rutas del modulo 11: solo GET y solo GERENTE (401 sin sesion; 403 al resto de roles)', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE),
        registro(5, 'empresa1', 'ClaveEmpresa#1', Roles::EMPRESARIO), registro(6, 'transp1', 'ClaveTransp#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    $guardia = new RequiereRol([Roles::GERENTE], new RequiereSesion($servicioAuth));
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Reportes/rutas.php')($router);
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Reportes/rutas.php');
    afirmar(preg_match_all('/\$router->(get|post|put|patch|delete)\(/', $fuente) === 2 && preg_match_all('/\$router->get\(/', $fuente) === 2, 'dos rutas, ambas GET');
    afirmar(preg_match_all('/\$gerentes\);/', $fuente) === 2 && !str_contains($fuente, 'Roles::ADMINISTRADOR'), 'ambas con $gerentes; solo GERENTE');
    esperarError(fn () => $guardia(peticion('GET', '/api/reportes/resumen')), 401, 'no_autenticado');
    foreach ([2, 4, 5, 6] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $guardia(peticion('GET', '/api/reportes/viajes-flota')), 403, 'acceso_denegado');
    }
    $limpiar();
    sesionDe($auth, $servicioAuth, 1);
    $guardia(peticion('GET', '/api/reportes/resumen'));
    $limpiar();
});

prueba('controlador del modulo 11: formato {data}; errores de filtro -> 422', function () use ($ahoraM11) {
    $c = new ReportesController(new ResumenService(new ResumenFalso(), $ahoraM11), new FlotaService(new FlotaFalsa(), $ahoraM11));
    $r = leer($c->resumen(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && isset($r['cuerpo']['data']['enviosRegistrados']), 'resumen 200');
    $r = leer($c->viajesFlota(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && isset($r['cuerpo']['data']['indicadores']), 'viajes-flota 200');
    $q = new Jatsa\Core\Request('GET', '/x', ['mes' => '99'], '', []);
    esperarError(fn () => $c->viajesFlota($q, []), 422, 'datos_invalidos');
});

prueba('repositorios del modulo 11: solo lectura y parametrizados (sin INSERT/UPDATE/DELETE ni filtros concatenados)', function () {
    foreach (['ResumenRepository', 'FlotaRepository'] as $archivo) {
        $f = (string) file_get_contents(dirname(__DIR__) . "/src/Modules/Reportes/$archivo.php");
        afirmar(preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER)\b/', $f) === 0, "$archivo: solo SELECT");
        afirmar(!str_contains($f, '$filtros[\'') || !preg_match('/\.\s*\$filtros\[/', $f), "$archivo: ningun filtro se concatena");
    }
    $f = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Reportes/FlotaRepository.php');
    afirmar(str_contains($f, 'fr.tipoEnvio = :tipo') && substr_count($f, 'EXISTS (SELECT 1 FROM Envio_Viaje AS fe') === 1, 'tipo de envio por EXISTS sobre Bulto->Ruta: un viaje cuenta una vez');
    afirmar(str_contains($f, "estadoPago") === false, 'la flota no mezcla pagos');
});

// ── pm11: bibliotecas de la validacion real (sin base de datos; no se ejecuta ninguna fase) ─────────────────────────────────────────────
require_once __DIR__ . '/integracion/pm11_comun.php';
require_once __DIR__ . '/integracion/pm11_semilla.php';

prueba('pm11: solo se aceptan bases JATSA_pm11_aislada_AAAAMMDD_HHMM; nunca Encomiendas ni pm10', function () {
    afirmar(pm11NombreValido('JATSA_pm11_aislada_20261105_0930'), 'nombre valido');
    foreach (['JATSA_Encomiendas', 'JATSA_pm10_aislada_20261105_0930', 'JATSA_pm11_aislada_20261105', 'jatsa_pm11_aislada_20261105_0930x', 'master', '', 'JATSA_pm11_aislada_20261105_0930; DROP', 'JATSA_pm11_aislada_Encomiendas_0930'] as $malo) {
        afirmar(!pm11NombreValido($malo), "rechaza '{$malo}'");
    }
    afirmar(str_ends_with(pm11DirTrabajo('JATSA_pm11_aislada_20261105_0930'), 'jatsa_pm11_20261105_0930'), 'carpeta de trabajo por base');
    $rechazo = false;
    try {
        pm11DirTrabajo('JATSA_Encomiendas');
    } catch (InvalidArgumentException) {
        $rechazo = true;
    }
    afirmar($rechazo, 'ninguna ruta se calcula para una base no valida');
});

prueba('pm11: la identidad de cada conexion debe ser la base aislada exacta, la cuenta Windows esperada y autenticacion de Windows', function () {
    $b = 'JATSA_pm11_aislada_20261105_0930';
    $ok = ['bd' => $b, 'login' => 'PC\\Usuario', 'auth' => 'NTLM'];
    afirmar(pm11IdentidadValida($ok, $b, 'pc\\usuario'), 'valida (cuenta sin distinguir mayusculas)');
    afirmar(!pm11IdentidadValida(['bd' => 'JATSA_Encomiendas'] + $ok, $b, 'PC\\Usuario'), 'JATSA_Encomiendas rechazada');
    afirmar(!pm11IdentidadValida(['bd' => 'JATSA_pm10_aislada_20261105_0930'] + $ok, $b, 'PC\\Usuario'), 'otra base rechazada');
    afirmar(!pm11IdentidadValida(['login' => 'sa'] + $ok, $b, 'PC\\Usuario'), 'otra cuenta rechazada');
    afirmar(!pm11IdentidadValida(['auth' => 'SQL'] + $ok, $b, 'PC\\Usuario'), 'sin autenticacion de Windows rechazada');
    afirmar(!pm11IdentidadValida($ok, $b, ''), 'sin cuenta esperada rechazada');
});

prueba('pm11: el .env de la copia es aislado, sin credenciales, completo y sin claves repetidas', function () {
    $b = 'JATSA_pm11_aislada_20261105_0930';
    $env = pm11ContenidoEnv('localhost\\SQLEXPRESS', '', $b);
    afirmar(pm11EnvProblemas($env, $b) === [], 'el .env generado es valido');
    afirmar(pm11EnvProblemas(str_replace("DB_NAME=$b", 'DB_NAME=JATSA_Encomiendas', $env), $b) !== [], 'DB_NAME habitual rechazado');
    afirmar(pm11EnvProblemas(str_replace('DB_USER=', 'DB_USER=sa', $env), $b) !== [], 'DB_USER con valor rechazado');
    afirmar(pm11EnvProblemas(str_replace('DB_PASSWORD=', 'DB_PASSWORD=x', $env), $b) !== [], 'DB_PASSWORD con valor rechazada');
    afirmar(pm11EnvProblemas(str_replace("DB_PORT=\n", '', $env), $b) !== [], 'falta DB_PORT: rechazado');
    afirmar(pm11EnvProblemas($env . "\nDB_NAME=otra", $b) !== [], 'clave repetida rechazada');
    $rechazo = false;
    try {
        pm11ContenidoEnv("srv\nDB_NAME=x", '', $b);
    } catch (InvalidArgumentException) {
        $rechazo = true;
    }
    afirmar($rechazo, 'saltos de linea en un valor rechazados');
});

prueba('pm11: la estructura derivada del script original (solo lectura) son 12 CREATE TABLE sin INSERT ni referencias a la base real', function () {
    $b = 'JATSA_pm11_aislada_20261105_0930';
    $ruta = dirname(__DIR__, 2) . '/docs/JATSA_Envios-SQLQuery1 (1).sql';
    $ddl = pm11DerivarDdl((string) file_get_contents($ruta), $b);
    afirmar(pm11DdlProblemas($ddl, $b) === [], 'derivada valida: ' . implode(';', pm11DdlProblemas($ddl, $b)));
    afirmar(!str_contains($ddl, 'JATSA_Encomiendas') && str_starts_with($ddl, "USE [$b];\nGO") && stripos($ddl, 'INSERT') === false, 'usa la base aislada y no inserta datos');
    afirmar(preg_match_all('/CREATE TABLE/i', $ddl) === 12, '12 tablas');
    afirmar(pm11DdlProblemas($ddl . "\nINSERT INTO Persona VALUES (1);", $b) !== [], 'un INSERT se rechaza');
    afirmar(pm11DdlProblemas(str_replace("USE [$b];", 'USE [JATSA_Encomiendas];', $ddl), $b) !== [], 'USE de la base real se rechaza');
    afirmar(pm11DdlProblemas($ddl . "\nGO\nCREATE DATABASE X;", $b) !== [], 'CREATE DATABASE se rechaza');
    afirmar(pm11DdlProblemas($ddl . "\nGO\nALTER TABLE Pago ADD x INT;", $b) !== [], 'ALTER se rechaza');
});

prueba('pm11: la instantanea detecta cualquier cambio en las 12 tablas y exige exactamente esas 12', function () {
    $t = array_map(static fn () => ['filas' => 1, 'identActual' => '1', 'sha256' => 'a'], PM11_TABLAS);
    $a = ['tablasEnBase' => array_keys(PM11_TABLAS), 'objetosDeUsuario' => 60, 'soloLectura' => 1, 'tablas' => $t];
    afirmar(pm11DiferenciasInstantaneas($a, $a) === [], 'identicas');
    $b = $a;
    $b['tablas']['Pago']['sha256'] = 'b';
    afirmar(count(pm11DiferenciasInstantaneas($a, $b)) >= 2, 'una fila distinta en Pago se detecta');
    $c = $a;
    $c['tablasEnBase'][] = 'Extra';
    afirmar(pm11DiferenciasInstantaneas($a, $c) !== [], 'una tabla extra se detecta');
    $d = $a;
    $d['soloLectura'] = 0;
    afirmar(pm11DiferenciasInstantaneas($a, $d) !== [], 'perder READ_ONLY se detecta');
    afirmar(count(PM11_TABLAS) === 12, 'son 12 tablas');
});

prueba('pm11: la semilla es coherente con las restricciones del esquema (codigos, fechas, pesos, FK)', function () {
    foreach ([new DateTimeImmutable('2026-11-04 12:00:00'), new DateTimeImmutable('2027-01-01 00:30:00'), new DateTimeImmutable('2026-03-31 23:59:59')] as $ahora) {
        $s = pm11Semilla($ahora);
        $marca = $ahora->format('c');
        afirmar(count(PM11_TABLAS) === count(pm11Conteos($s)), "$marca: una cifra por tabla");
        $n = 0;
        foreach ($s['envios'] as $id => $e) {
            $n++;
            afirmar($e['origen'] !== $e['destino'] && $e['bultos'] !== [], "$marca: envio $id con origen distinto de destino y al menos un bulto");
            afirmar(($e['estado'] === 'ENTREGADO') === ($e['entrega'] !== null), "$marca: fechaHoraEntrega solo si ENTREGADO ($id)");
            afirmar(preg_match('/^EN[A-Z0-9]{10}$/', 'EN' . sprintf('%010d', 990000000 + $n)) === 1 && preg_match('/^R\d{9}$/', 'R' . sprintf('%09d', 990000000 + $n)) === 1, "$marca: codigos de envio y reserva");
            foreach ($e['bultos'] as [$ruta, $kg]) {
                afirmar(isset($s['rutas'][$ruta]) && $kg > 0, "$marca: bulto de $id con ruta existente y peso > 0");
            }
        }
        foreach ($s['viajes'] as $id => $v) {
            afirmar($v['origen'] !== $v['destino'] && preg_match('/^VJ\d{10}$/', RepoFlotaOraculo::codigoViaje($id)) === 1, "$marca: viaje $id");
            afirmar($v['llegada'] === null || ($v['salida'] !== null && $v['llegada'] > $v['salida']), "$marca: CK_VIAJE_FECHAS en $id");
            foreach ($v['carga'] as $e) {
                afirmar(isset($s['envios'][$e]), "$marca: carga de $id con envio existente");
            }
        }
        foreach ($s['pagos'] as $p) {
            afirmar(isset($s['envios'][$p['envio']]) && (float) $p['monto'] > 0 && in_array($p['estado'], ['REGISTRADO', 'ANULADO'], true), "$marca: pago valido");
        }
        foreach ($s['usuarios'] as $u) {
            $conSucursal = in_array($u['rol'], ['ADMINISTRADOR', 'TRANSPORTISTA'], true);
            afirmar($conSucursal === ($u['sucursal'] !== null) && strlen($u['usuario']) >= 4, "$marca: usuario {$u['usuario']} cumple CK_USUARIO_SUCURSAL");
        }
        afirmar(array_keys($s['vehiculos']) === [0, 1] && preg_match('/^[A-Z0-9]{3}-\d{3}$/', $s['vehiculos'][0]['placa']) === 1, "$marca: placas");
        afirmar($s['meta']['anioCruce'] === (int) $ahora->format('Y') - 1, "$marca: el cruce de año usa el año anterior");
        // Las fechas de la semilla jamas estan en el futuro, salvo nada: todo <= ahora.
        $limite = $ahora->format('Y-m-d H:i:s');
        foreach ($s['envios'] as $e) {
            afirmar($e['registro'] <= $limite, "$marca: envio {$e['id']} no esta en el futuro");
        }
        foreach ($s['viajes'] as $id => $v) {
            afirmar(($v['salida'] ?? '') <= $limite, "$marca: viaje $id no sale en el futuro");
        }
    }
});

prueba('pm11: el oraculo de la semilla da los resultados calculados a mano (periodos, filtros, cruce de año)', function () {
    $ahora = new DateTimeImmutable('2026-11-04 12:00:00');
    $s = pm11Semilla($ahora);
    afirmar($s['meta']['mes'] === '2026-10' && $s['meta']['mesAnterior'] === '2026-09' && $s['meta']['anioCruce'] === 2025, 'meses y año de cruce');
    $reloj = static fn (): DateTimeImmutable => $ahora;
    $res = new ResumenService(new RepoResumenOraculo($s), $reloj);
    $flo = new FlotaService(new RepoFlotaOraculo($s), $reloj);

    $r = $res->resumen(['mes' => '2026-10']);
    afirmar($r['enviosRegistrados'] === ['total' => 6, 'variacionPct' => 100], 'M: 6 envios (sin ANULADO/DEVUELTO), +100 % contra 3');
    afirmar($r['cobrado'] === ['total' => '180.50', 'variacionPct' => 50], 'M: cobrado 100+50.50+20+10 (sin el anulado), +50 % contra 120');
    afirmar($r['porEntregar'] === ['total' => 3, 'masDeTresDias' => 3] && $r['dondeEstan'] === ['ENTREGADO' => 2, 'EN_TRANSITO' => 2, 'EN_DESTINO' => 1, 'REGISTRADO' => 1], 'M: por entregar y grupos');
    afirmar($r['viajes'] === ['enRuta' => 3, 'programados' => 2], 'viajes abiertos');
    $r = $res->resumen(['mes' => '2026-10', 'sucursal' => 'TST003']);
    afirmar($r['enviosRegistrados'] === ['total' => 3, 'variacionPct' => null] && $r['cobrado'] === ['total' => '20.00', 'variacionPct' => null], 'TST003: origen/destino para envios, sucursal del pago para el cobro; sin base = null');
    afirmar($r['viajes'] === ['enRuta' => 2, 'programados' => 1], 'TST003: viajes que salen o llegan');
    $r = $res->resumen(['mes' => '2026-11']);
    afirmar($r['enviosRegistrados'] === ['total' => 2, 'variacionPct' => -67] && $r['cobrado'] === ['total' => '5.00', 'variacionPct' => -97] && $r['porEntregar'] === ['total' => 1, 'masDeTresDias' => 0], 'mes actual: el pago de las 00:00 del dia 1 entra, el de las 23:59:59 del mes anterior no; recientes no son «mas de 3 dias»');
    $r = $res->resumen(['mes' => '2025-01']);
    afirmar($r['enviosRegistrados'] === ['total' => 3, 'variacionPct' => 50] && $r['cobrado'] === ['total' => '150.00', 'variacionPct' => 50], 'enero 2025 contra diciembre 2024 (cruce de año)');
    $r = $res->resumen(['mes' => '2024-12']);
    afirmar($r['enviosRegistrados'] === ['total' => 2, 'variacionPct' => null], 'diciembre 2024: sin noviembre = null');

    $f = $flo->viajesYFlota(['anio' => '2026', 'mes' => '10']);
    afirmar($f['indicadores'] === ['viajesTerminados' => 3, 'enRutaAhora' => 3, 'programados' => 2, 'pesoKg' => '810.00', 'usoMedioCapacidadPct' => 35, 'enviosConTransbordoPct' => 20], 'flota M: 3 viajes, 810 kg, uso 35 %, transbordo 1 de 5');
    afirmar(array_column($f['viajesEnRuta'], 'diasDespues', 'codigo') === ['VJ0990000011' => null, 'VJ0990000007' => 0, 'VJ0990000008' => 1], 'ETA: sin ruta vigente = null (11), mismo dia (7, usa MAX 5 h e ignora la tarifa cerrada de 99 h), +1 (8)');
    afirmar(array_column($f['transportistas'], 'viajes', 'transportista') === ['Transportista Sintético Uno' => 2, 'Transportista Sintético Dos' => 1], 'transportistas del mes');
    $f = $flo->viajesYFlota(['anio' => '2026', 'mes' => '10', 'tipoEnvio' => 'SOBRE']);
    afirmar($f['indicadores']['viajesTerminados'] === 2 && $f['indicadores']['pesoKg'] === '560.00' && $f['indicadores']['usoMedioCapacidadPct'] === 28 && $f['indicadores']['enviosConTransbordoPct'] === 33, 'tipo SOBRE: V01 y V03 una sola vez cada uno (V01 tiene 2 bultos SOBRE)');
    $f = $flo->viajesYFlota(['anio' => '2026', 'mes' => '10', 'tipoEnvio' => 'CARGA']);
    afirmar($f['indicadores']['viajesTerminados'] === 1 && $f['indicadores']['pesoKg'] === '250.00' && $f['indicadores']['usoMedioCapacidadPct'] === 50 && $f['indicadores']['enviosConTransbordoPct'] === 0, 'tipo CARGA');
    $f = $flo->viajesYFlota(['anio' => '2026', 'mes' => '10', 'sucursal' => 'TST003', 'vehiculo' => 'TST-001']);
    afirmar($f['indicadores']['viajesTerminados'] === 0 && $f['indicadores']['usoMedioCapacidadPct'] === null && $f['indicadores']['enviosConTransbordoPct'] === null && $f['viajesEnRuta'] === [] && $f['transportistas'] === [], 'combinacion vacia: nulos y listas vacias');
    $f = $flo->viajesYFlota(['anio' => '2025']);
    afirmar($f['indicadores']['viajesTerminados'] === 1 && $f['indicadores']['pesoKg'] === '50.00' && $f['indicadores']['usoMedioCapacidadPct'] === 10, 'año 2025 completo: solo el viaje de enero (el de diciembre 2024 queda fuera)');
    $f = $flo->viajesYFlota(['anio' => '2024', 'mes' => '12']);
    afirmar($f['indicadores']['viajesTerminados'] === 1 && $f['indicadores']['pesoKg'] === '70.00' && $f['indicadores']['usoMedioCapacidadPct'] === 7, 'diciembre 2024 (cruce de año)');
    // La semilla relativa a otras fechas de carga (cruce de año natural: enero) sigue dando mes anterior = diciembre.
    $enero = pm11Semilla(new DateTimeImmutable('2027-01-15 10:00:00'));
    afirmar($enero['meta']['mes'] === '2026-12' && $enero['meta']['mesAnterior'] === '2026-11' && $enero['meta']['anioCruce'] === 2026, 'cargada en enero: M = diciembre, M-1 = noviembre');
});
prueba('pm11: la conexion administrativa debe ser master con la cuenta Windows esperada, y DB_HOST/DB_PORT deben coincidir con el manifiesto', function () {
    $ok = ['bd' => 'master', 'login' => 'PC\\Usuario', 'auth' => 'KERBEROS'];
    afirmar(pm11MasterValido($ok, 'pc\\usuario'), 'master valida');
    afirmar(!pm11MasterValido(['bd' => 'JATSA_Encomiendas'] + $ok, 'PC\\Usuario'), 'otra base rechazada');
    afirmar(!pm11MasterValido(['bd' => 'JATSA_pm11_aislada_20261105_0930'] + $ok, 'PC\\Usuario'), 'la base aislada no es master');
    afirmar(!pm11MasterValido(['login' => 'sa'] + $ok, 'PC\\Usuario'), 'otra cuenta rechazada');
    afirmar(!pm11MasterValido(['auth' => 'SQL'] + $ok, 'PC\\Usuario'), 'sin autenticacion de Windows rechazada');
    afirmar(!pm11MasterValido($ok, ''), 'sin cuenta esperada rechazada');
    $b = 'JATSA_pm11_aislada_20261105_0930';
    $env = pm11ContenidoEnv('localhost\\SQLEXPRESS', '1433', $b);
    afirmar(pm11EnvProblemas($env, $b, 'localhost\\SQLEXPRESS', '1433') === [], 'host y puerto del manifiesto: valido');
    afirmar(pm11EnvProblemas($env, $b, 'otro-servidor', '1433') !== [], 'DB_HOST distinto del manifiesto: rechazado');
    afirmar(pm11EnvProblemas($env, $b, 'localhost\\SQLEXPRESS', '1434') !== [], 'DB_PORT distinto del manifiesto: rechazado');
    afirmar(pm11EnvProblemas(pm11ContenidoEnv('localhost\\SQLEXPRESS', '', $b), $b, 'localhost\\SQLEXPRESS', '1433') !== [], 'puerto vacio frente a manifiesto con puerto: rechazado');
    afirmar(pm11EnvProblemas($env, $b) === [], 'sin manifiesto (null) no se compara: comportamiento anterior');
});

prueba('pm11: el codigo de las fases verifica master, host/puerto y detiene las pruebas si falla la sonda (revision estatica)', function () {
    $dir = __DIR__ . '/integracion/';
    $l = (string) file_get_contents($dir . 'pm11.php');
    $t = (string) file_get_contents($dir . 'pm11_trabajo.php');
    afirmar(substr_count($l, "pm11ConexionMaster(") === 2 && !preg_match("/pm11ConexionServidor\([^)]*'master'\)/", $l . $t), 'A y D abren master solo con pm11ConexionMaster (verificada)');
    afirmar(str_contains($t, 'pm11ConexionMaster((string) $manifiesto'), 'el READ_ONLY de B abre master verificada');
    afirmar(str_contains($l, '$base, $servidor, $puerto)') && str_contains($t, "(string) \$manifiesto['servidor'], (string) \$manifiesto['puerto'])"), 'B y el trabajo comparan DB_HOST/DB_PORT con el manifiesto');
    afirmar(str_contains($t, "Config::obtener('db.host')") && str_contains($t, "Config::obtener('db.puerto', '')"), 'la configuracion cargada tambien se compara');
    $posSonda = strpos($t, 'throw new RuntimeException');
    afirmar($posSonda !== false && $posSonda < strpos($t, "'/api/auth/ingresar'") && str_contains($t, 'catch (RuntimeException $detenido)') && str_contains($t, 'pm11Abortar($detencion'), 'sonda fallida: se lanza antes del primer login, se limpia en finally y se aborta');
    afirmar(strpos($t, 'pm11Abortar($detencion') < strpos($t, 'pm11DiferenciasInstantaneas($antes'), 'el aborto ocurre antes de dar nada por validado');
});