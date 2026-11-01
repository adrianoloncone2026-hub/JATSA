<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 9 (rastreo público, salidas entre sucursales e historial del cliente) con repositorios simulados, sin base de datos.
 * Se incluye desde ejecutar.php, que aporta el mini marco (prueba, afirmar, esperarError…), ReservasFalso y los ayudantes de sesión.
 */

use Jatsa\Core\Request;
use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Envios\HistorialController;
use Jatsa\Modules\Envios\HistorialService;
use Jatsa\Modules\Envios\RepositorioHistorial;
use Jatsa\Modules\Publico\RastreoController;
use Jatsa\Modules\Publico\RastreoService;
use Jatsa\Modules\Publico\RepositorioRastreo;
use Jatsa\Modules\Publico\RepositorioSalidas;
use Jatsa\Modules\Publico\SalidaController;
use Jatsa\Modules\Publico\SalidaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

// ── Rastreo público ─────────────────────────────────────────────────────────
final class RastreoFalso implements RepositorioRastreo
{
    /** @var array<string, array<string, mixed>> */
    public array $envios = [];

    public function buscarEnvio(string $codigoSeguimiento, string $hoy): ?array
    {
        return $this->envios[$codigoSeguimiento] ?? null;
    }
}

function tramo(string $viaje, string $carga, ?string $salida, ?string $llegada, string $origen = 'MOQ001', string $destino = 'AQP001', ?int $horas = 4): array
{
    $n = ['MOQ001' => 'Agencia Moquegua', 'AQP001' => 'Agencia Arequipa Centro', 'LIM001' => 'Agencia Lima Centro', 'ILO001' => 'Agencia Ilo'];

    return ['codigoViaje' => $viaje, 'fechaHoraCarga' => $carga, 'fechaHoraSalida' => $salida, 'fechaHoraLlegada' => $llegada,
        'origen' => ['codigo' => $origen, 'nombre' => $n[$origen]], 'destino' => ['codigo' => $destino, 'nombre' => $n[$destino]], 'vehiculo' => ['tipo' => 'FURGON', 'placa' => 'B7K-210'], 'horas' => $horas];
}

/** El envío de la diapositiva 3: en tránsito, cargado en el viaje VJ2026101402 que salió a las 15:00. */
function escenarioRastreo(array $cambios = []): array
{
    $r = new RastreoFalso();
    $r->envios['ENZ5H0D3V7NJ'] = $cambios + [
        'codigoSeguimiento' => 'ENZ5H0D3V7NJ', 'estado' => 'EN_TRANSITO', 'fechaHoraRegistro' => '2026-10-14 14:00:00', 'fechaHoraEntrega' => null,
        'origen' => ['codigo' => 'MOQ001', 'nombre' => 'Agencia Moquegua'], 'destino' => ['codigo' => 'AQP001', 'nombre' => 'Agencia Arequipa Centro'],
        'bultos' => 1, 'pesoKg' => '18.50', 'importe' => '14.60', 'pagado' => '14.60',
        'tramos' => [tramo('VJ2026101402', '2026-10-14 14:20:00', '2026-10-14 15:00:00', null)],
    ];

    return [$r, new RastreoService($r, static fn (): string => '2026-10-31')];
}

prueba('rastreo: reproduce la diapositiva 3 (en tránsito, recorrido, viaje en curso y llegada estimada ≈ 19:00)', function () {
    [, $servicio] = escenarioRastreo();
    $e = $servicio->rastrear('ENZ5H0D3V7NJ');
    afirmar($e['estado'] === 'EN_TRANSITO' && $e['cantidadBultos'] === 1 && $e['pesoTotalKg'] === '18.50' && $e['saldo'] === '0.00', 'estado, bultos, peso y saldo');
    afirmar($e['origen']['nombre'] === 'Agencia Moquegua' && $e['destino']['nombre'] === 'Agencia Arequipa Centro', 'ruta');
    afirmar(array_column($e['etapas'], 'cumplida') === [true, true, false, false] && array_column($e['etapas'], 'actual') === [false, true, false, false], 'etapas: 1 ✓ y 2 en tránsito');
    afirmar(array_map(static fn (array $h): string => $h['fechaHora'] . ' ' . $h['texto'], $e['recorrido']) === [
        '2026-10-14 14:00:00 Recibido en Agencia Moquegua (MOQ001)', '2026-10-14 14:20:00 Cargado en el viaje VJ2026101402',
        '2026-10-14 15:00:00 Salió hacia Agencia Arequipa Centro', '2026-10-14 19:00:00 Llegada estimada (ruta de 4 h)'], 'recorrido: ' . json_encode(array_column($e['recorrido'], 'texto')));
    afirmar($e['viajeActual']['codigo'] === 'VJ2026101402' && $e['viajeActual']['vehiculo'] === ['tipo' => 'FURGON', 'placa' => 'B7K-210'] && $e['viajeActual']['fechaHoraSalida'] === '2026-10-14 15:00:00', 'viaje en curso');
    afirmar($e['llegadaEstimada'] === '2026-10-14 19:00:00' && $e['horasRuta'] === 4 && $e['rutaId'] === 'MOQ001-AQP001', 'llegada estimada y ruta');
});

prueba('rastreo: el código se acepta en minúsculas; inexistente y mal formado dan el MISMO 404', function () {
    [, $servicio] = escenarioRastreo();
    afirmar($servicio->rastrear(' enz5h0d3v7nj ')['codigoSeguimiento'] === 'ENZ5H0D3V7NJ', 'minúsculas y espacios');
    $inexistente = esperarError(fn () => $servicio->rastrear('ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    foreach (['', 'XX', "EN'; DROP TABLE Envio;--", 'ENZ5H0D3V7N', 'ENZ5H0D3V7NJX', 'R000000001'] as $raro) {
        $e = esperarError(fn () => $servicio->rastrear($raro), 404, 'envio_no_encontrado');
        afirmar($e->getMessage() === $inexistente->getMessage(), "mismo mensaje para «{$raro}»");
    }
});

prueba('rastreo: no expone documentos, teléfonos ni nombres de personas (regla 6)', function () {
    [, $servicio] = escenarioRastreo();
    $json = json_encode($servicio->rastrear('ENZ5H0D3V7NJ'));
    foreach (['numeroDocumento', 'tipoDocumento', 'telefono', 'nombreCompleto', 'remitente', 'destinatario', 'correo', '9000'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "no debe contener $prohibido");
    }
});

prueba('rastreo: envío recién registrado (sin viajes) y envío entregado', function () {
    [$r, $servicio] = escenarioRastreo(['estado' => 'REGISTRADO', 'tramos' => [], 'pagado' => '0.00']);
    $e = $servicio->rastrear('ENZ5H0D3V7NJ');
    afirmar(count($e['recorrido']) === 1 && $e['viajeActual'] === null && $e['llegadaEstimada'] === null, 'solo «Recibido en…»');
    afirmar(array_column($e['etapas'], 'cumplida') === [true, false, false, false] && $e['saldo'] === '14.60' && $e['rutaId'] === 'MOQ001-AQP001', 'etapa 1 y saldo pendiente');
    [, $servicio2] = escenarioRastreo(['estado' => 'ENTREGADO', 'fechaHoraEntrega' => '2026-10-14 20:10:00', 'tramos' => [tramo('VJ2026101402', '2026-10-14 14:20:00', '2026-10-14 15:00:00', '2026-10-14 19:05:00')]]);
    $e = $servicio2->rastrear('ENZ5H0D3V7NJ');
    afirmar(array_column($e['etapas'], 'cumplida') === [true, true, true, true] && $e['viajeActual'] === null && $e['llegadaEstimada'] === null, 'cuatro etapas y sin viaje en curso');
    afirmar(end($e['recorrido'])['texto'] === 'Entregado en Agencia Arequipa Centro' && $e['recorrido'][3]['texto'] === 'Llegó a Agencia Arequipa Centro', 'llegada y entrega al final, en orden');
});

prueba('rastreo: transbordo — con el primer tramo terminado y el segundo en ruta, el viaje en curso es el segundo y la ruta sigue a ese tramo', function () {
    [, $servicio] = escenarioRastreo(['destino' => ['codigo' => 'LIM001', 'nombre' => 'Agencia Lima Centro'], 'tramos' => [
        tramo('VJ2026101001', '2026-10-10 09:00:00', '2026-10-10 10:00:00', '2026-10-10 14:00:00', 'MOQ001', 'AQP001', 4),
        tramo('VJ2026101101', '2026-10-10 16:00:00', '2026-10-10 20:00:00', null, 'AQP001', 'LIM001', 18)]]);
    $e = $servicio->rastrear('ENZ5H0D3V7NJ');
    afirmar($e['viajeActual']['codigo'] === 'VJ2026101101' && $e['rutaId'] === 'AQP001-LIM001' && $e['llegadaEstimada'] === '2026-10-11 14:00:00' && $e['horasRuta'] === 18, 'segundo tramo, ruta AQP→LIM y estimada del día siguiente');
    afirmar(count(array_filter($e['recorrido'], static fn (array $h): bool => $h['tipo'] === 'estimada')) === 1, 'una sola llegada estimada');
});

prueba('rastreo: DEVUELTO y ANULADO no avanzan por las etapas ni muestran viaje en curso ni llegada estimada', function () {
    foreach (['DEVUELTO', 'ANULADO'] as $estado) {
        [, $servicio] = escenarioRastreo(['estado' => $estado]);
        $e = $servicio->rastrear('ENZ5H0D3V7NJ');
        afirmar($e['estado'] === $estado && !in_array(true, array_column($e['etapas'], 'actual'), true) && $e['viajeActual'] === null && $e['llegadaEstimada'] === null, $estado);
    }
});

prueba('rastreo: sin tiempo de ruta no se inventa una llegada estimada', function () {
    [, $servicio] = escenarioRastreo(['tramos' => [tramo('VJ2026101402', '2026-10-14 14:20:00', '2026-10-14 15:00:00', null, 'MOQ001', 'AQP001', null)]]);
    $e = $servicio->rastrear('ENZ5H0D3V7NJ');
    afirmar($e['llegadaEstimada'] === null && $e['viajeActual'] !== null, 'sin estimada pero con viaje en curso');
});

// ── Salidas entre sucursales ────────────────────────────────────────────────
final class SalidasFalso implements RepositorioSalidas
{
    /** @var list<array<string, mixed>> */
    public array $tarifas = [];
    /** @var list<array{origenCodigo: string, destinoCodigo: string, fechaHoraSalida: string}> */
    public array $recientes = [];
    /** @var array<string, int> */
    public array $programados = [];
    /** @var array<string, list<array<string, mixed>>> */
    public array $viajes = [];
    public ?string $hoyRecibido = null;

    public function tarifasVigentes(string $hoy): array
    {
        $this->hoyRecibido = $hoy;

        return $this->tarifas;
    }

    public function salidasRecientes(int $porRuta): array
    {
        return $this->recientes;
    }

    public function programadosPorRuta(): array
    {
        return $this->programados;
    }

    public function viajesDeLaRuta(string $codigoOrigen, string $codigoDestino, int $limite): array
    {
        return array_slice($this->viajes[$codigoOrigen . '-' . $codigoDestino] ?? [], 0, $limite);
    }
}

/** Las cinco tarifas del script SQL y los viajes de ejemplo. */
function escenarioSalidas(): array
{
    $r = new SalidasFalso();
    $t = static fn (string $oc, string $on, string $dc, string $dn, string $tipo, string $base, string $km, string $dist, int $h): array => [
        'origenCodigo' => $oc, 'origenNombre' => $on, 'destinoCodigo' => $dc, 'destinoNombre' => $dn, 'tipoEnvio' => $tipo, 'precioBase' => $base, 'precioPorKm' => $km, 'distanciaKm' => $dist, 'tiempoHoras' => $h];
    $r->tarifas = [
        $t('AQP001', 'Agencia Arequipa Centro', 'LIM001', 'Agencia Lima Centro', 'PAQUETE', '10.00', '0.020', '1010.0', 18),
        $t('ILO001', 'Agencia Ilo', 'MOQ001', 'Agencia Moquegua', 'SOBRE', '8.00', '0.005', '100.0', 2),
        $t('LIM001', 'Agencia Lima Centro', 'ILO001', 'Agencia Ilo', 'CARGA', '40.00', '0.080', '1200.0', 24),
        $t('MOQ001', 'Agencia Moquegua', 'AQP001', 'Agencia Arequipa Centro', 'SOBRE', '8.00', '0.005', '230.0', 4),
        $t('MOQ001', 'Agencia Moquegua', 'AQP001', 'Agencia Arequipa Centro', 'PAQUETE', '10.00', '0.020', '230.0', 4),
        $t('MOQ001', 'Agencia Moquegua', 'AQP001', 'Agencia Arequipa Centro', 'PAQUETE', '99.00', '0.500', '230.0', 9), // una tarifa vieja del mismo tipo (la vigente más reciente va primero)
    ];
    $r->recientes = [
        ['origenCodigo' => 'MOQ001', 'destinoCodigo' => 'AQP001', 'fechaHoraSalida' => '2026-10-14 15:00:00'], ['origenCodigo' => 'MOQ001', 'destinoCodigo' => 'AQP001', 'fechaHoraSalida' => '2026-10-10 14:00:00'],
        ['origenCodigo' => 'ILO001', 'destinoCodigo' => 'MOQ001', 'fechaHoraSalida' => '2026-10-13 07:00:00'],
    ];
    $r->programados = ['LIM001-ILO001' => 1];
    $r->viajes = ['MOQ001-AQP001' => [
        ['codigo' => 'VJ2026101402', 'fechaHoraSalida' => '2026-10-14 15:00:00', 'fechaHoraLlegada' => null],
        ['codigo' => 'VJ2026101001', 'fechaHoraSalida' => '2026-10-10 14:00:00', 'fechaHoraLlegada' => '2026-10-10 18:30:00']]];

    return [$r, new SalidaService($r, static fn (): string => '2026-10-31')];
}

prueba('salidas: la lista reproduce la diapositiva 4 con los datos reales (horarios, tiempo y precio «desde»)', function () {
    [$r, $servicio] = escenarioSalidas();
    $lista = array_column($servicio->listar()['salidas'], null, 'id');
    afirmar(array_keys($lista) === ['AQP001-LIM001', 'ILO001-MOQ001', 'LIM001-ILO001', 'MOQ001-AQP001'], 'rutas: ' . implode(',', array_keys($lista)));
    afirmar($lista['MOQ001-AQP001']['horarios'] === ['14:00', '15:00'] && $lista['MOQ001-AQP001']['horas'] === 4 && $lista['MOQ001-AQP001']['desde'] === '9.15', 'Moquegua → Arequipa: 14:00 · 15:00 · 4 h · desde S/ 9.15');
    afirmar($lista['ILO001-MOQ001']['horarios'] === ['07:00'] && $lista['ILO001-MOQ001']['horas'] === 2 && $lista['ILO001-MOQ001']['desde'] === '8.50', 'Ilo → Moquegua: 07:00 · 2 h · S/ 8.50');
    afirmar($lista['LIM001-ILO001']['horarios'] === [] && $lista['LIM001-ILO001']['programado'] === true && $lista['LIM001-ILO001']['desde'] === '136.00', 'Lima → Ilo: «programado» · desde S/ 136.00');
    afirmar($lista['AQP001-LIM001']['horarios'] === [] && $lista['AQP001-LIM001']['programado'] === false && $lista['AQP001-LIM001']['desde'] === '30.20', 'Arequipa → Lima sin salidas aún');
    afirmar($r->hoyRecibido === '2026-10-31', 'las tarifas se piden con la fecha de hoy');
});

prueba('salidas: solo cuenta la tarifa vigente más reciente de cada tipo y el tiempo es el mayor de las vigentes', function () {
    [$r, $servicio] = escenarioSalidas();
    $r->tarifas[] = $r->tarifas[0];
    $d = $servicio->detalle('MOQ001-AQP001');
    afirmar($d['tarifas'] === [['tipo' => 'SOBRE', 'precioPorBulto' => '9.15'], ['tipo' => 'PAQUETE', 'precioPorBulto' => '14.60']], 'precios: ' . json_encode($d['tarifas']));
    afirmar($d['horas'] === 4, 'la tarifa vieja (9 h) no cuenta');
});

prueba('salidas: filtros por origen, destino y tipo (con un tipo, solo las rutas que lo tienen y su precio)', function () {
    [, $servicio] = escenarioSalidas();
    afirmar(array_column($servicio->listar('moq001')['salidas'], 'id') === ['MOQ001-AQP001'], 'origen en minúsculas');
    afirmar(array_column($servicio->listar('', 'MOQ001')['salidas'], 'id') === ['ILO001-MOQ001'], 'destino');
    $sobres = array_column($servicio->listar('', '', 'sobre')['salidas'], 'desde', 'id');
    afirmar($sobres === ['ILO001-MOQ001' => '8.50', 'MOQ001-AQP001' => '9.15'], 'SOBRE: ' . json_encode($sobres));
    $paquetes = array_column($servicio->listar('', '', 'PAQUETE')['salidas'], 'desde', 'id');
    afirmar($paquetes === ['AQP001-LIM001' => '30.20', 'MOQ001-AQP001' => '14.60'], 'PAQUETE: ' . json_encode($paquetes));
    afirmar(array_column($servicio->listar('', '', 'CARGA')['salidas'], 'id') === ['LIM001-ILO001'], 'CARGA');
    afirmar($servicio->listar('MOQ001', 'ILO001')['salidas'] === [], 'sin coincidencias: lista vacía, no error');
});

prueba('salidas: filtros inválidos → 422 con el campo; nunca un error interno', function () {
    [, $servicio] = escenarioSalidas();
    foreach ([['XX', '', '', 'origen'], ['', "MOQ001' OR 1=1", '', 'destino'], ['', '', 'CAMION', 'tipo'], ['MOQ00', 'AQP', 'x', 'origen']] as [$o, $d, $t, $campo]) {
        $e = esperarError(fn () => $servicio->listar($o, $d, $t), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$campo]), "marca $campo");
    }
});

prueba('salidas: el horario usa las 3 horas más frecuentes de los últimos viajes, en orden de reloj', function () {
    [$r, $servicio] = escenarioSalidas();
    $r->recientes = [];
    foreach ([14 => 6, 15 => 3, 16 => 2, 9 => 1] as $hora => $veces) {
        for ($i = 0; $i < $veces; $i++) {
            $r->recientes[] = ['origenCodigo' => 'MOQ001', 'destinoCodigo' => 'AQP001', 'fechaHoraSalida' => sprintf('2026-10-%02d %02d:%02d:00', 1 + $i, $hora, 10 + $i)];
        }
    }
    afirmar(array_column($servicio->listar('MOQ001')['salidas'], 'horarios')[0] === ['14:00', '15:00', '16:00'], 'sin la hora poco frecuente y con los minutos descartados');
});

prueba('salidas: detalle de la ruta (diapositiva 5): precios, horarios, llegada aproximada 18:00–19:00 y salidas recientes', function () {
    [, $servicio] = escenarioSalidas();
    $d = $servicio->detalle('moq001-aqp001');
    afirmar($d['origen']['codigo'] === 'MOQ001' && $d['destino']['nombre'] === 'Agencia Arequipa Centro' && $d['distanciaKm'] === '230' && $d['horas'] === 4, 'ruta, 230 km, 4 h');
    afirmar($d['horarios'] === ['14:00', '15:00'] && $d['llegadaAproximada'] === ['desde' => '18:00', 'hasta' => '19:00'], 'sale 14:00 y 15:00 → llega 18:00–19:00');
    afirmar(array_column($d['recientes'], 'estado', 'codigo') === ['VJ2026101402' => 'EN_RUTA', 'VJ2026101001' => 'TERMINADO'], 'recientes con su estado');
    afirmar($d['recientes'][0]['fechaHoraLlegada'] === null && $d['recientes'][1]['fechaHoraLlegada'] === '2026-10-10 18:30:00', 'llegada vacía mientras está en ruta');
    $sin = $servicio->detalle('AQP001-LIM001');
    afirmar($sin['horarios'] === [] && $sin['llegadaAproximada'] === null && $sin['recientes'] === [], 'sin salidas: sin horario ni llegada');
    afirmar($servicio->detalle('LIM001-ILO001')['programado'] === true, 'programado');
});

prueba('salidas: una ruta que no existe (o un id mal formado) da 404; la llegada pasa de medianoche sin romperse', function () {
    [$r, $servicio] = escenarioSalidas();
    foreach (['MOQ001-LIM001', 'ZZZ999-AAA111', 'MOQ001', "MOQ001-AQP001'; DROP", '', 'MOQ001-AQP001-X'] as $raro) {
        esperarError(fn () => $servicio->detalle($raro), 404, 'ruta_no_encontrada');
    }
    $r->recientes[] = ['origenCodigo' => 'AQP001', 'destinoCodigo' => 'LIM001', 'fechaHoraSalida' => '2026-10-14 20:25:00'];
    afirmar($servicio->detalle('AQP001-LIM001')['llegadaAproximada'] === ['desde' => '14:00', 'hasta' => '14:00'], '20:00 + 18 h = 14:00 del día siguiente');
});

// ── Historial del cliente (diapositivas 14 y 11) ────────────────────────────
final class HistorialFalso implements RepositorioHistorial
{
    /** @var array<int, list<array<string, mixed>>> idPersona => envíos (con su detalle) */
    public array $porPersona = [];

    public function listar(int $idPersona, array $filtros, int $limite): array
    {
        $filas = array_filter($this->porPersona[$idPersona] ?? [], static function (array $e) use ($filtros): bool {
            $fecha = substr($e['fechaHoraRegistro'], 0, 10);

            return ($filtros['rol'] === '' || $e['rol'] === $filtros['rol']) && ($filtros['estado'] === '' || $e['estado'] === $filtros['estado'])
                && ($filtros['desde'] === '' || $fecha >= $filtros['desde']) && ($filtros['hasta'] === '' || $fecha <= $filtros['hasta'])
                && ($filtros['q'] === '' || stripos($e['codigoSeguimiento'], $filtros['q']) !== false || stripos($e['otraParte'], $filtros['q']) !== false);
        });
        usort($filas, static fn (array $a, array $b): int => strcmp($b['fechaHoraRegistro'], $a['fechaHoraRegistro']));

        return array_slice(array_values($filas), 0, $limite);
    }

    public function enCurso(int $idPersona, int $limite): array
    {
        return array_values(array_filter($this->porPersona[$idPersona] ?? [], static fn (array $e): bool => in_array($e['estado'], ['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO'], true)));
    }

    public function detalle(int $idPersona, string $codigoSeguimiento): ?array
    {
        foreach ($this->porPersona[$idPersona] ?? [] as $e) {
            if ($e['codigoSeguimiento'] === $codigoSeguimiento) {
                return $e;
            }
        }

        return null;
    }
}

/** María (persona 4, usuario 4) envió el 1 y recibió el 2 (diapositivas 14 y 11); Rosa (persona 51, usuario 1) tiene uno propio. */
function escenarioHistorial(): array
{
    $res = new ReservasFalso();
    $h = new HistorialFalso();
    $base = static fn (string $c, string $estado, string $rol, string $otra, string $fecha, string $importe, string $pagado, array $mas = []): array => $mas + [
        'codigoSeguimiento' => $c, 'estado' => $estado, 'rol' => $rol, 'otraParte' => $otra, 'fechaHoraRegistro' => $fecha, 'fechaHoraEntrega' => null, 'importe' => $importe, 'pagado' => $pagado,
        'origen' => ['codigo' => 'MOQ001', 'nombre' => 'Agencia Moquegua'],
        'destino' => ['codigo' => 'AQP001', 'nombre' => 'Agencia Arequipa Centro', 'direccion' => 'Av. Ejército 512'], 'bultos' => [], 'pagos' => [], 'ultimoViaje' => null,
    ];
    $h->porPersona[4] = [
        $base('EN7K4Q2M9X1B', 'ENTREGADO', 'ENVIE', 'Luis Alberto Flores Condori', '2026-10-10 10:02:00', '23.75', '23.75', [
            'fechaHoraEntrega' => '2026-10-12 11:30:00',
            'bultos' => [['numero' => 1, 'descripcion' => 'Sobre con documentos', 'pesoKg' => '0.40', 'tipo' => 'SOBRE', 'costo' => '9.15'], ['numero' => 2, 'descripcion' => 'Caja con ropa', 'pesoKg' => '8.80', 'tipo' => 'PAQUETE', 'costo' => '14.60']],
            'pagos' => [['monto' => '23.75', 'metodoPago' => 'EFECTIVO', 'estado' => 'REGISTRADO', 'sucursalCodigo' => 'MOQ001', 'fechaHora' => '2026-10-10 10:04:00']],
            'ultimoViaje' => ['codigo' => 'VJ2026101001', 'fechaHoraSalida' => '2026-10-10 14:00:00', 'fechaHoraLlegada' => '2026-10-10 18:30:00']]),
        $base('ENM2P6T8R1WQ', 'EN_DESTINO', 'RECIBI', 'Pedro Mamani Huanca', '2026-10-13 07:20:00', '8.50', '8.50', [
            'destino' => ['codigo' => 'MOQ001', 'nombre' => 'Agencia Moquegua', 'direccion' => 'Jr. Ancash 325']]),
    ];
    $h->porPersona[51] = [$base('ENB3F8L0Q2ZD', 'EN_TRANSITO', 'ENVIE', 'Rosa Quispe', '2026-10-11 09:00:00', '30.20', '0.00')];

    return [$h, new HistorialService($h, $res), $res];
}

function clienteMaria(): array
{
    return ['id' => 4, 'nombreUsuario' => 'cliente1', 'rol' => Roles::CLIENTE];
}

prueba('historial: lista los envíos que envié y recibí, el más reciente primero (diapositiva 14), sin documentos ni teléfonos', function () {
    [, $servicio] = escenarioHistorial();
    $l = $servicio->listar(clienteMaria(), []);
    afirmar(array_column($l['envios'], 'codigoSeguimiento') === ['ENM2P6T8R1WQ', 'EN7K4Q2M9X1B'] && $l['hayMas'] === false, 'orden');
    afirmar($l['envios'][1] === ['codigoSeguimiento' => 'EN7K4Q2M9X1B', 'estado' => 'ENTREGADO', 'rol' => 'ENVIE', 'fecha' => '2026-10-10', 'otraParte' => 'Luis Alberto Flores Condori', 'importe' => '23.75'], 'Envié · 10/10 · S/ 23.75');
    afirmar($l['envios'][0]['rol'] === 'RECIBI' && $l['envios'][0]['otraParte'] === 'Pedro Mamani Huanca', 'Recibí · de Pedro Mamani');
    foreach (['numeroDocumento', 'telefono', 'correo', '71889345'] as $prohibido) {
        afirmar(!str_contains(json_encode($l), $prohibido), "no debe contener $prohibido");
    }
});

prueba('historial: filtros por rol, estado, fechas (ambas incluidas) y búsqueda por código o nombre', function () {
    [, $servicio] = escenarioHistorial();
    $codigos = static fn (array $q): array => array_column($servicio->listar(clienteMaria(), $q)['envios'], 'codigoSeguimiento');
    afirmar($codigos(['rol' => 'envie']) === ['EN7K4Q2M9X1B'] && $codigos(['rol' => 'RECIBI']) === ['ENM2P6T8R1WQ'], 'rol');
    afirmar($codigos(['estado' => 'entregado']) === ['EN7K4Q2M9X1B'], 'estado');
    afirmar($codigos(['desde' => '2026-10-10', 'hasta' => '2026-10-10']) === ['EN7K4Q2M9X1B'] && $codigos(['desde' => '2026-10-11']) === ['ENM2P6T8R1WQ'] && $codigos(['hasta' => '2026-10-09']) === [], 'fechas');
    afirmar($codigos(['q' => 'm2p6']) === ['ENM2P6T8R1WQ'] && $codigos(['q' => 'luis alberto']) === ['EN7K4Q2M9X1B'] && $codigos(['q' => 'no existe']) === [], 'código y nombre');
});

prueba('historial: filtros inválidos → 422 con el campo (búsqueda larga, rol, estado, fechas mal escritas o invertidas)', function () {
    [, $servicio] = escenarioHistorial();
    foreach ([['q' => str_repeat('a', 51)], ['rol' => 'TODOS'], ['estado' => 'LISTO'], ['desde' => '10/10/2026'], ['hasta' => '2026-02-30'], ['desde' => '2026-10-20', 'hasta' => '2026-10-10']] as $consulta) {
        $e = esperarError(fn () => $servicio->listar(clienteMaria(), $consulta), 422, 'datos_invalidos');
        afirmar($e->campos !== [], 'marca un campo: ' . json_encode($consulta));
    }
    foreach ([['q' => ['x']], ['rol' => ['ENVIE']], ['desde' => 5]] as $raro) {
        $servicio->listar(clienteMaria(), $raro); // tipos raros se tratan como vacíos
    }
    $servicio->listar(clienteMaria(), ['q' => "'; DROP TABLE Envio;--%_[x"]); // los comodines y comillas viajan como texto
});

prueba('historial: cada persona ve solo lo suyo y un usuario sin persona da 404', function () {
    [, $servicio] = escenarioHistorial();
    afirmar(array_column($servicio->listar(['id' => 1, 'rol' => Roles::CLIENTE], [])['envios'], 'codigoSeguimiento') === ['ENB3F8L0Q2ZD'], 'Rosa ve el suyo');
    afirmar($servicio->listar(['id' => 5, 'rol' => Roles::EMPRESARIO], [])['envios'] === [], 'la empresa no tiene envíos: lista vacía');
    esperarError(fn () => $servicio->listar(['id' => 999, 'rol' => Roles::CLIENTE], []), 404, 'cuenta_no_encontrada');
});

prueba('historial: el detalle reproduce la diapositiva 14 (entrega, viaje, pago, bultos con tipo y costo, total y saldo)', function () {
    [, $servicio] = escenarioHistorial();
    $e = $servicio->detalle(clienteMaria(), strtolower('EN7K4Q2M9X1B'));
    afirmar($e['estado'] === 'ENTREGADO' && $e['rol'] === 'ENVIE' && $e['otraParte'] === 'Luis Alberto Flores Condori' && $e['fechaHoraEntrega'] === '2026-10-12 11:30:00', 'Para Luis Alberto · Entregado 12/10 11:30');
    afirmar($e['destino']['nombre'] === 'Agencia Arequipa Centro' && $e['viaje'] === ['codigo' => 'VJ2026101001', 'fechaHoraSalida' => '2026-10-10 14:00:00', 'fechaHoraLlegada' => '2026-10-10 18:30:00'], 'agencia y viaje: salió 14:00, llegó 18:30');
    afirmar($e['pagos'] === [['monto' => '23.75', 'metodoPago' => 'EFECTIVO', 'estado' => 'REGISTRADO', 'sucursal' => 'MOQ001', 'fechaHora' => '2026-10-10 10:04:00']], 'Pagado S/ 23.75 · EFECTIVO · MOQ001');
    afirmar($e['bultos'][0] === ['numero' => 1, 'descripcion' => 'Sobre con documentos', 'pesoKg' => '0.40', 'tipo' => 'SOBRE', 'costo' => '9.15'] && $e['bultos'][1]['tipo'] === 'PAQUETE' && $e['bultos'][1]['costo'] === '14.60', 'bultos');
    afirmar($e['importe'] === '23.75' && $e['saldo'] === '0.00' && $e['pesoTotalKg'] === '9.20' && $e['cantidadBultos'] === 2, 'Total S/ 23.75 · Saldo S/ 0.00');
});

prueba('historial: un envío ajeno, inexistente o mal formado da el MISMO 404', function () {
    [, $servicio] = escenarioHistorial();
    $ajeno = esperarError(fn () => $servicio->detalle(clienteMaria(), 'ENB3F8L0Q2ZD'), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->detalle(clienteMaria(), 'ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->detalle(clienteMaria(), "EN'; DROP"), 404, 'envio_no_encontrado');
    afirmar($ajeno->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
});

prueba('historial: el resumen de «Mi panel» (diapositiva 11): en curso, saldo por pagar y por recoger', function () {
    [$h, $servicio] = escenarioHistorial();
    $r = $servicio->resumen(clienteMaria());
    afirmar($r['enCurso'] === 1 && $r['saldoPorPagar'] === '0.00', '1 envío en curso y saldo S/ 0.00');
    afirmar($r['porRecoger'] === [['codigoSeguimiento' => 'ENM2P6T8R1WQ', 'estado' => 'EN_DESTINO', 'de' => 'Pedro Mamani Huanca', 'agencia' => ['nombre' => 'Agencia Moquegua', 'direccion' => 'Jr. Ancash 325']]], 'por recoger: de Pedro Mamani en Agencia Moquegua, Jr. Ancash 325');
    $h->porPersona[4][1]['pagado'] = '3.00'; // le falta pagar 5.50
    afirmar($servicio->resumen(clienteMaria())['saldoPorPagar'] === '5.50', 'saldo por pagar');
    $h->porPersona[4][] = $h->porPersona[4][0] + ['codigoSeguimiento' => 'ENAAAAAAAAAA'];
    $r = $servicio->resumen(clienteMaria());
    afirmar($r['enCurso'] === 1 && count($r['porRecoger']) === 1, 'un envío entregado no cuenta como en curso ni por recoger; uno que ENVIÉ en destino tampoco se recoge');
    $h->porPersona[1][0]['estado'] = 'EN_DESTINO';
    afirmar($servicio->resumen(['id' => 1, 'rol' => Roles::CLIENTE])['porRecoger'] === [], 'lo que yo envié, aunque esté en destino, no es «por recoger»');
});

// ── HTTP: rutas públicas y de cliente ───────────────────────────────────────
prueba('rastreo y salidas son públicos: sin sesión no piden nada; solo admiten GET', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Publico/rutas.php')($router);
    foreach ([['POST', '/api/rastreo/ENZ5H0D3V7NJ'], ['DELETE', '/api/rastreo/ENZ5H0D3V7NJ'], ['POST', '/api/salidas'], ['PUT', '/api/salidas/MOQ001-AQP001']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 405, 'metodo_no_permitido');
    }
    [, $rast] = escenarioRastreo();
    [, $sal] = escenarioSalidas();
    $r = leer((new RastreoController($rast))->rastrear(peticion('GET', '/x'), ['codigo' => 'ENZ5H0D3V7NJ']));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['estado'] === 'EN_TRANSITO', 'rastreo 200');
    esperarError(fn () => (new RastreoController($rast))->rastrear(peticion('GET', '/x'), ['codigo' => 'ENZZZZZZZZZZ']), 404, 'envio_no_encontrado');
    $c = new SalidaController($sal);
    $r = leer($c->listar(new Request('GET', '/x', ['origen' => 'MOQ001', 'tipo' => 'SOBRE'], '', []), []));
    afirmar($r['estado'] === 200 && array_column($r['cuerpo']['data']['salidas'], 'id') === ['MOQ001-AQP001'], 'lista con filtros de la URL');
    afirmar(count(leer($c->listar(new Request('GET', '/x', ['origen' => ['MOQ001'], 'tipo' => 5], '', []), []))['cuerpo']['data']['salidas']) === 4, 'tipos raros en la URL se tratan como vacíos');
});

prueba('historial: solo CLIENTE y EMPRESARIO (admin, transportista y gerente 403; sin sesión 401); controlador con la sesión', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3),
        registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE), registro(5, 'empresa1', 'ClaveEmpresa#1', Roles::EMPRESARIO), registro(6, 'transp1', 'ClaveTransp#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    $guardia = new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO], new RequiereSesion($servicioAuth)); // igual que en rutas.php
    esperarError(fn () => $guardia(peticion('GET', '/api/mis-envios')), 401, 'no_autenticado');
    foreach ([2, 6, 1] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $guardia(peticion('GET', '/api/mis-envios')), 403, 'acceso_denegado');
    }
    foreach ([4, 5] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        $guardia(peticion('GET', '/api/mis-envios'));
    }
    $limpiar();
    [, $servicio] = escenarioHistorial();
    $c = new HistorialController($servicio);
    sesionDe($auth, $servicioAuth, 4);
    $r = leer($c->listar(new Request('GET', '/x', ['rol' => 'ENVIE'], '', []), []));
    afirmar($r['estado'] === 200 && array_column($r['cuerpo']['data']['envios'], 'codigoSeguimiento') === ['EN7K4Q2M9X1B'], 'listar con filtros de la URL');
    afirmar(leer($c->resumen(peticion('GET', '/x'), []))['cuerpo']['data']['enCurso'] === 1, 'resumen');
    afirmar(leer($c->detalle(peticion('GET', '/x'), ['codigo' => 'EN7K4Q2M9X1B']))['cuerpo']['data']['envio']['saldo'] === '0.00', 'detalle');
    esperarError(fn () => $c->detalle(peticion('GET', '/x'), ['codigo' => 'ENB3F8L0Q2ZD']), 404, 'envio_no_encontrado');
    $limpiar();
    esperarError(fn () => $c->listar(peticion('GET', '/x'), []), 401, 'no_autenticado');
});

prueba('rutas del historial: todas exigen sesión; /resumen va antes que /{codigo}; ninguna escribe', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Envios/rutas.php')($router);
    foreach ([['GET', '/api/mis-envios'], ['GET', '/api/mis-envios/resumen'], ['GET', '/api/mis-envios/ENZ5H0D3V7NJ']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    foreach ([['POST', '/api/mis-envios'], ['DELETE', '/api/mis-envios/ENZ5H0D3V7NJ'], ['PUT', '/api/mis-envios/resumen']] as [$metodo, $ruta]) {
        $e = null;
        try {
            $router->despachar(peticion($metodo, $ruta));
        } catch (HttpException $e) {
        }
        afirmar($e !== null && in_array($e->estado, [404, 405], true), "$metodo $ruta no debe existir");
    }
    $rutas = (new ReflectionProperty(Router::class, 'rutas'))->getValue($router);
    $pos = static function (string $fragmento, string $y = '') use ($rutas): int {
        foreach ($rutas as $i => $r) {
            if ($r['metodo'] === 'GET' && str_contains($r['regex'], 'mis') && str_contains($r['regex'], 'envios') && str_contains($r['regex'], $fragmento) && ($y === '' || str_contains($r['regex'], $y))) {
                return $i;
            }
        }

        return -1;
    };
    afirmar($pos('resumen') >= 0 && $pos('resumen') < $pos('codigo'), '/resumen antes de /{codigo}');
});
