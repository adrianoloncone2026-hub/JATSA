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
