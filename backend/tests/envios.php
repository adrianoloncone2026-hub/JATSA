<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 5 (ventanilla y envíos) con repositorios simulados, sin base de datos.
 * Se incluye desde ejecutar.php, que aporta el mini marco (prueba, afirmar, esperarError…) y los simulados de reservas.
 */

use Jatsa\Core\Request;
use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Envios\EnvioController;
use Jatsa\Modules\Envios\EnvioService;
use Jatsa\Modules\Envios\RepositorioEnvios;
use Jatsa\Modules\Publico\CotizadorService;
use Jatsa\Modules\Reservas\VentanillaController;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/** Repositorio de envíos en memoria, enlazado al de reservas: formalizar reclama la reserva como lo hace el SQL real. */
final class EnviosFalso implements RepositorioEnvios
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tarifas = [];
    /** @var array<string, array<string, mixed>> */
    public array $envios = [];
    public int $siguiente = 1;
    public int $transaccionesDeshechas = 0;
    /** Se ejecuta justo antes de reclamar la reserva: sirve para simular a alguien que se adelanta. */
    public ?Closure $antesDeReclamar = null;
    /** Se ejecuta después de reclamar y antes de planificar: simula un cambio de tarifa dentro de la ventana. */
    public ?Closure $antesDePlanificar = null;

    public function __construct(private readonly ReservasFalso $reservas)
    {
        $t = static fn (int $id, string $tipo, string $base, string $km, string $dist): array => ['idRuta' => $id, 'tipoEnvio' => $tipo, 'precioBase' => $base, 'precioPorKm' => $km, 'distanciaKm' => $dist, 'tiempoHoras' => 4];
        $this->tarifas = [
            'MOQ001>AQP001' => [$t(1, 'SOBRE', '8.00', '0.005', '230.0'), $t(2, 'PAQUETE', '10.00', '0.020', '230.0')],
            'AQP001>LIM001' => [$t(3, 'PAQUETE', '10.00', '0.020', '1010.0')],
            'LIM001>ILO001' => [$t(4, 'CARGA', '40.00', '0.080', '1200.0')],
            'ILO001>MOQ001' => [$t(5, 'SOBRE', '8.00', '0.005', '100.0')],
        ];
    }

    public function tarifasVigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array
    {
        return $this->tarifas[$codigoOrigen . '>' . $codigoDestino] ?? [];
    }

    public function formalizar(string $codigoReserva, int $idSucursalOrigen, string $fechaIso, callable $planificar, ?DateTimeImmutable $referencia = null): ?string
    {
        if ($this->antesDeReclamar !== null) {
            ($this->antesDeReclamar)($this);
        }
        $r = $this->reservas->reservas[$codigoReserva] ?? null;
        if ($r === null || ReservasFalso::IDS_SUCURSAL[$r['origen']] !== $idSucursalOrigen || $r['estado'] !== 'PENDIENTE' || $r['fechaHora'] <= $this->reservas->limite($referencia)) {
            return null;
        }

        $copia = $this->reservas->reservas; // «transacción»: si algo falla, todo vuelve a como estaba
        $this->reservas->reservas[$codigoReserva]['estado'] = 'FORMALIZADA';
        try {
            if ($this->antesDePlanificar !== null) {
                ($this->antesDePlanificar)($this);
            }
            $plan = $planificar(
                ['idReserva' => 1, 'idSucursalOrigen' => $idSucursalOrigen, 'idSucursalDestino' => ReservasFalso::IDS_SUCURSAL[$r['destino']]],
                $this->tarifasVigentes($r['origen'], $r['destino'], $fechaIso),
            );
        } catch (Throwable $e) {
            $this->reservas->reservas = $copia;
            $this->transaccionesDeshechas++;
            throw $e;
        }

        $codigo = 'EN' . sprintf('%010d', $this->siguiente++);
        $this->envios[$codigo] = ['reserva' => $codigoReserva, 'plan' => $plan, 'fechaHoraRegistro' => '2026-10-31 09:20:00'];
        $this->reservas->reservas[$codigoReserva]['seguimiento'] = $codigo;

        return $codigo;
    }

    public function buscarPorCodigo(string $codigoSeguimiento): ?array
    {
        $e = $this->envios[$codigoSeguimiento] ?? null;
        if ($e === null) {
            return null;
        }
        $r = $this->reservas->reservas[$e['reserva']];
        $agencias = new AgenciasFalso();
        $persona = fn (int $id): array => ['tipoDocumento' => $this->reservas->personas[$id]['tipo'], 'numeroDocumento' => $this->reservas->personas[$id]['numero'], 'nombreCompleto' => $this->reservas->personas[$id]['nombre']];
        $sucursal = static fn (string $c): array => ['codigo' => $c, 'nombre' => $agencias->buscarActivaPorCodigo($c)['nombre'], 'direccion' => $agencias->buscarActivaPorCodigo($c)['direccion'], 'departamento' => $agencias->buscarActivaPorCodigo($c)['departamento']];
        $tipoPorRuta = [1 => 'SOBRE', 2 => 'PAQUETE', 3 => 'PAQUETE', 4 => 'CARGA', 5 => 'SOBRE'];

        return [
            'codigoSeguimiento' => $codigoSeguimiento, 'fechaHoraRegistro' => $e['fechaHoraRegistro'], 'tipoEntrega' => $e['plan']['tipoEntrega'],
            'direccionEntrega' => $e['plan']['direccionEntrega'], 'estado' => 'REGISTRADO',
            'idSucursalOrigen' => ReservasFalso::IDS_SUCURSAL[$r['origen']], 'idSucursalDestino' => ReservasFalso::IDS_SUCURSAL[$r['destino']],
            'reserva' => ['codigo' => $e['reserva'], 'contenido' => $r['contenido'], 'canal' => $r['canal'], 'remitente' => $persona($r['idRemitente']),
                'destinatario' => $persona($r['idDestinatario']), 'origen' => $sucursal($r['origen']), 'destino' => $sucursal($r['destino'])],
            'bultos' => array_map(static fn (array $b): array => ['numero' => $b['numero'], 'descripcion' => $b['descripcion'], 'pesoKg' => $b['pesoKg'], 'costo' => $b['costo'], 'tipo' => $tipoPorRuta[$b['idRuta']]], $e['plan']['bultos']),
        ];
    }
}

/** Personal de la agencia con la sucursal dada, como lo guarda la sesión. */
function personal(int $idSucursal = 3, string $codigo = 'MOQ001'): array
{
    return ['id' => 2, 'nombreUsuario' => 'admin1', 'rol' => Roles::ADMINISTRADOR, 'sucursal' => ['id' => $idSucursal, 'codigo' => $codigo, 'nombre' => 'Agencia ' . $codigo]];
}

/** @return array{0: ReservasFalso, 1: EnviosFalso, 2: EnvioService, 3: VentanillaService} */
function escenarioEnvios(): array
{
    $res = new ReservasFalso();
    // Pendientes de la agencia MOQ001 (origen) y de otra agencia.
    $res->sembrar('R000000020', 4, 50, 'MOQ001', 'AQP001', 'Documentos y ropa', 'PENDIENTE', '2026-10-31 08:00:00', null);
    $res->sembrar('R000000021', 4, 50, 'AQP001', 'LIM001', 'Repuestos', 'PENDIENTE', '2026-10-31 08:30:00', null);
    $res->sembrar('R000000022', 4, 50, 'MOQ001', 'AQP001', 'Plazo cumplido', 'PENDIENTE', '2026-10-28 09:15:00', null); // 72:00:00 exactos
    $res->sembrar('R000000023', 4, 50, 'MOQ001', 'AQP001', 'Plazo vigente', 'PENDIENTE', '2026-10-28 09:15:01', null);   // 71:59:59
    $res->siguienteReserva = 30;
    $env = new EnviosFalso($res);
    $agencias = new AgenciasFalso();
    $cotizador = new CotizadorService($agencias, new RutasFalso(), fn () => '2026-10-31');

    return [$res, $env, new EnvioService($env, $res, fn () => '2026-10-31'), new VentanillaService($res, $agencias, $cotizador)];
}

/** Bultos de la diapositiva 19: un sobre de 0.40 kg y una caja de 8.80 kg. */
function bultosDeLaDiapositiva(): array
{
    return [['descripcion' => 'Sobre con documentos', 'pesoKg' => '0.40'], ['descripcion' => 'Caja con ropa', 'pesoKg' => '8.80']];
}

function envioValido(array $cambios = []): array
{
    return $cambios + ['codigoReserva' => 'R000000020', 'tipoEntrega' => 'AGENCIA', 'direccionEntrega' => '', 'bultos' => bultosDeLaDiapositiva()];
}

// ── Ventanilla: reservas de la agencia ──────────────────────────────────────
prueba('ventanilla: lista solo las pendientes de SU agencia y que aún están en plazo', function () {
    [, , , $ventanilla] = escenarioEnvios();
    $codigos = array_column($ventanilla->pendientes(personal()), 'codigo');
    afirmar($codigos === ['R000000005', 'R000000023', 'R000000020'] || $codigos === ['R000000023', 'R000000020', 'R000000005'] || (count($codigos) === 3 && !in_array('R000000022', $codigos, true) && !in_array('R000000021', $codigos, true)), 'pendientes de MOQ001: ' . implode(',', $codigos));
    afirmar(!in_array('R000000022', $codigos, true), 'la que cumplió las 72 h exactas no aparece');
    afirmar(in_array('R000000023', $codigos, true), 'a 71:59:59 todavía aparece');
    afirmar(!in_array('R000000021', $codigos, true), 'las de otra agencia no aparecen');
    afirmar(array_column($ventanilla->pendientes(personal(2, 'AQP001')), 'codigo') === ['R000000021'], 'AQP001 solo ve la suya');
});

prueba('ventanilla: la reserva trae remitente y destinatario con su documento (uso interno)', function () {
    [, , , $ventanilla] = escenarioEnvios();
    $r = $ventanilla->ver(personal(), 'r000000020');
    afirmar($r['remitente'] === ['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345', 'nombreCompleto' => 'María Elena Rojas Ccori'], 'remitente');
    afirmar($r['destinatario']['numeroDocumento'] === '70234561' && $r['estado'] === 'PENDIENTE' && $r['canal'] === 'WEB', 'destinatario, estado y canal');
    afirmar($r['venceEl'] === '2026-11-03 08:00:00' && $r['plazoCumplido'] === false, 'vence 72 h después');
    afirmar(!str_contains(json_encode($r), 'telefono'), 'nunca expone teléfonos');
    afirmar($ventanilla->ver(personal(), 'R000000022')['plazoCumplido'] === true, 'avisa si el plazo se cumplió');
});

prueba('ventanilla: una reserva de otra agencia o inexistente da el mismo 404', function () {
    [, , , $ventanilla] = escenarioEnvios();
    $ajena = esperarError(fn () => $ventanilla->ver(personal(), 'R000000021'), 404, 'reserva_no_encontrada');
    $inexistente = esperarError(fn () => $ventanilla->ver(personal(), 'R000000999'), 404, 'reserva_no_encontrada');
    $malFormada = esperarError(fn () => $ventanilla->ver(personal(), "R0';--"), 404, 'reserva_no_encontrada');
    afirmar($ajena->getMessage() === $inexistente->getMessage() && $inexistente->getMessage() === $malFormada->getMessage(), 'mismo mensaje');
});

prueba('ventanilla: personal sin sucursal → 403', function () {
    [, , , $ventanilla] = escenarioEnvios();
    esperarError(fn () => $ventanilla->pendientes(['id' => 2, 'sucursal' => null]), 403, 'sin_sucursal');
});

prueba('ventanilla: consulta de persona devuelve solo el nombre', function () {
    [, , , $ventanilla] = escenarioEnvios();
    $p = $ventanilla->consultarPersona('DNI', '702-345 61');
    afirmar($p === ['existe' => true, 'nombreCompleto' => 'Luis Alberto Flores Condori'], 'existente');
    afirmar($ventanilla->consultarPersona('DNI', '90000001') === ['existe' => false], 'inexistente');
    esperarError(fn () => $ventanilla->consultarPersona('XX', '1'), 422, 'datos_invalidos');
});

// ── Ventanilla: «Cliente sin reserva» ───────────────────────────────────────
function reservaDeVentanilla(array $cambios = []): array
{
    return $cambios + [
        'remitenteTipoDocumento' => 'DNI', 'remitenteNumeroDocumento' => '71889345', 'remitenteNombre' => '', 'remitenteTelefono' => '',
        'destinatarioTipoDocumento' => 'DNI', 'destinatarioNumeroDocumento' => '70234561', 'destinatarioNombre' => '', 'destinatarioTelefono' => '',
        'sucursalDestino' => 'AQP001', 'contenido' => 'Cajas con herramientas',
    ];
}

prueba('sin reserva: crea una reserva VENTANILLA con origen en la agencia de la sesión', function () {
    [$res, , , $ventanilla] = escenarioEnvios();
    $r = $ventanilla->crear(personal(), reservaDeVentanilla());
    afirmar($r['codigo'] === 'R000000030' && $r['canal'] === 'VENTANILLA' && $r['estado'] === 'PENDIENTE', 'código, canal y estado');
    afirmar($r['origen']['codigo'] === 'MOQ001' && $r['destino']['codigo'] === 'AQP001', 'el origen es la agencia del personal');
    afirmar($res->personasCreadas === 0, 'las dos personas ya existían: no se crean');
    afirmar($r['remitente']['numeroDocumento'] === '71889345', 'remitente');
});

prueba('sin reserva: el origen no se puede elegir (se ignora lo que mande el cuerpo)', function () {
    [$res, , , $ventanilla] = escenarioEnvios();
    $ventanilla->crear(personal(), reservaDeVentanilla(['sucursalOrigen' => 'LIM001', 'canal' => 'WEB', 'estado' => 'FORMALIZADA']));
    $creada = $res->reservas['R000000030'];
    afirmar($creada['origen'] === 'MOQ001' && $creada['canal'] === 'VENTANILLA' && $creada['estado'] === 'PENDIENTE', 'fijados por el servidor');
});

prueba('sin reserva: personas nuevas se crean con nombre y teléfono; las existentes no se modifican', function () {
    [$res, , , $ventanilla] = escenarioEnvios();
    $r = $ventanilla->crear(personal(), reservaDeVentanilla([
        'remitenteNumeroDocumento' => '48484848', 'remitenteNombre' => "  Ana   Torres\tDíaz ", 'remitenteTelefono' => '911 222-333',
        'destinatarioNombre' => 'NOMBRE QUE NO DEBE GUARDARSE', 'destinatarioTelefono' => '900000000',
    ]));
    afirmar($res->personasCreadas === 1, 'solo se crea la remitente nueva');
    $nueva = $res->personas[$res->siguientePersona - 1];
    afirmar($nueva['nombre'] === 'Ana Torres Díaz' && $nueva['telefono'] === '911222333' && $nueva['numero'] === '48484848', 'normalizada');
    afirmar($res->personas[50]['nombre'] === 'Luis Alberto Flores Condori', 'la persona existente no cambió');
    afirmar($r['remitente']['nombreCompleto'] === 'Ana Torres Díaz', 'queda asociada');
});

prueba('sin reserva: CE y PAS son válidos para el personal (se guardan en mayúsculas)', function () {
    [$res, , , $ventanilla] = escenarioEnvios();
    $ventanilla->crear(personal(), reservaDeVentanilla(['remitenteTipoDocumento' => 'ce', 'remitenteNumeroDocumento' => 'x1234567', 'remitenteNombre' => 'Visitante Extranjero', 'remitenteTelefono' => '955111222']));
    $p = $res->personas[$res->siguientePersona - 1];
    afirmar($p['tipo'] === 'CE' && $p['numero'] === 'X1234567', 'CE en mayúsculas');
});

prueba('sin reserva: todos los errores por campo a la vez y no se crea nada', function () {
    [$res, , , $ventanilla] = escenarioEnvios();
    $campos = camposConError(fn () => $ventanilla->crear(personal(), [
        'remitenteTipoDocumento' => 'DNI', 'remitenteNumeroDocumento' => '123', 'destinatarioTipoDocumento' => 'XX', 'destinatarioNumeroDocumento' => '',
        'sucursalDestino' => 'x', 'contenido' => '',
    ]));
    foreach (['remitenteNumeroDocumento', 'destinatarioNumeroDocumento', 'sucursalDestino', 'contenido'] as $c) {
        afirmar(in_array($c, $campos, true), "falta el error de $c");
    }
    $campos = camposConError(fn () => $ventanilla->crear(personal(), reservaDeVentanilla(['remitenteNumeroDocumento' => '48484848', 'destinatarioNumeroDocumento' => '47474747'])));
    sort($campos);
    afirmar($campos === ['destinatarioNombre', 'destinatarioTelefono', 'remitenteNombre', 'remitenteTelefono'], 'personas nuevas sin nombre ni teléfono: ' . implode(',', $campos));
    afirmar(count($res->reservas) === 9 && $res->personasCreadas === 0, 'no se escribió nada');
});

prueba('sin reserva: remitente = destinatario, destino = origen, destino inactivo y ruta sin tarifa → 422', function () {
    [, , , $ventanilla] = escenarioEnvios();
    afirmar(camposConError(fn () => $ventanilla->crear(personal(), reservaDeVentanilla(['destinatarioNumeroDocumento' => '71889345']))) === ['destinatarioNumeroDocumento'], 'mismo documento');
    afirmar(camposConError(fn () => $ventanilla->crear(personal(), reservaDeVentanilla(['sucursalDestino' => 'MOQ001']))) === ['sucursalDestino'], 'destino = origen');
    afirmar(camposConError(fn () => $ventanilla->crear(personal(), reservaDeVentanilla(['sucursalDestino' => 'TAC001']))) === ['sucursalDestino'], 'sucursal inactiva');
    $sinTarifa = camposConError(fn () => $ventanilla->crear(personal(), reservaDeVentanilla(['sucursalDestino' => 'LIM001']))); // MOQ→LIM no tiene tarifa
    afirmar($sinTarifa === ['sucursalDestino'], 'sin ninguna tarifa vigente');
    afirmar(camposConError(fn () => $ventanilla->crear(personal(2, 'AQP001'), reservaDeVentanilla(['sucursalDestino' => 'MOQ001']))) === ['sucursalDestino'], 'la inversa AQP→MOQ no tiene tarifa');
});

// ── Cotización de bultos (no escribe nada) ──────────────────────────────────
prueba('cotizar: bultos de la diapositiva 19 (S/ 9.15 + S/ 14.60 = S/ 23.75; 9.20 kg)', function () {
    [$res, , $servicio] = escenarioEnvios();
    $c = $servicio->cotizar(personal(), ['codigoReserva' => 'R000000020', 'bultos' => bultosDeLaDiapositiva()]);
    afirmar($c['bultos'][0]['tipo'] === 'SOBRE' && $c['bultos'][0]['costo'] === '9.15' && $c['bultos'][1]['tipo'] === 'PAQUETE' && $c['bultos'][1]['costo'] === '14.60', 'tipo y costo por bulto');
    afirmar($c['importe'] === '23.75' && $c['pesoTotalKg'] === '9.20' && $c['cantidadBultos'] === 2 && $c['valido'] === true, 'importe, peso y cantidad');
    afirmar($c['distanciaKm'] === '230' && $c['reserva']['origen']['codigo'] === 'MOQ001', 'ruta y distancia');
    afirmar($res->reservas['R000000020']['estado'] === 'PENDIENTE', 'no escribe nada');
});

prueba('cotizar: un bulto de 31 kg es CARGA y MOQ→AQP no tiene esa tarifa (como en la diapositiva)', function () {
    [, , $servicio] = escenarioEnvios();
    $b = bultosDeLaDiapositiva();
    $b[] = ['descripcion' => 'Caja con herramientas', 'pesoKg' => '31.00'];
    $c = $servicio->cotizar(personal(), ['codigoReserva' => 'R000000020', 'bultos' => $b]);
    afirmar($c['bultos'][2]['tipo'] === 'CARGA' && $c['bultos'][2]['costo'] === null && $c['bultos'][2]['error'] === 'CARGA: sin ruta vigente MOQ→AQP', 'mensaje del prototipo: ' . $c['bultos'][2]['error']);
    afirmar($c['importe'] === '23.75' && $c['pesoTotalKg'] === '9.20' && $c['valido'] === false, 'el total cuenta solo los bultos válidos y no se puede guardar');
});

prueba('cotizar: límites de peso (1.00 / 1.01 / 30.00 / 30.01 / 300.00 / 300.01) y pesos inválidos', function () {
    [, $env, $servicio] = escenarioEnvios();
    $env->tarifas['MOQ001>AQP001'][] = ['idRuta' => 9, 'tipoEnvio' => 'CARGA', 'precioBase' => '40.00', 'precioPorKm' => '0.080', 'distanciaKm' => '230.0', 'tiempoHoras' => 4];
    $tipos = [];
    foreach (['1.00', '1.01', '30.00', '30.01', '300.00', '300.01', '0', '0.00', '-1', 'abc', '1.234', '', '1,5'] as $peso) {
        $c = $servicio->cotizar(personal(), ['codigoReserva' => 'R000000020', 'bultos' => [['descripcion' => 'x', 'pesoKg' => $peso]]]);
        $tipos[$peso] = $c['bultos'][0]['tipo'] . '|' . ($c['bultos'][0]['error'] === null ? 'ok' : 'error');
    }
    afirmar($tipos['1.00'] === 'SOBRE|ok' && $tipos['1.01'] === 'PAQUETE|ok' && $tipos['30.00'] === 'PAQUETE|ok' && $tipos['30.01'] === 'CARGA|ok' && $tipos['300.00'] === 'CARGA|ok', 'tipos por peso: ' . json_encode($tipos));
    afirmar($tipos['300.01'] === 'CARGA|error' || $tipos['300.01'] === '|error', '300.01 kg supera el máximo');
    foreach (['0', '0.00', '-1', 'abc', '1.234', ''] as $malo) {
        afirmar(str_ends_with($tipos[$malo], '|error'), "peso inválido «{$malo}»");
    }
    afirmar($tipos['1,5'] === 'PAQUETE|ok', 'la coma decimal se acepta');
});

prueba('cotizar: sin bultos no es válido; más de 50 bultos → 422; reserva ajena → 404', function () {
    [, , $servicio] = escenarioEnvios();
    $c = $servicio->cotizar(personal(), ['codigoReserva' => 'R000000020', 'bultos' => []]);
    afirmar($c['valido'] === false && $c['importe'] === '0.00' && $c['cantidadBultos'] === 0, 'vacío');
    $muchos = array_fill(0, EnvioService::MAX_BULTOS + 1, ['descripcion' => 'x', 'pesoKg' => '1']);
    afirmar(camposConError(fn () => $servicio->cotizar(personal(), ['codigoReserva' => 'R000000020', 'bultos' => $muchos])) === ['bultos'], 'tope de bultos');
    esperarError(fn () => $servicio->cotizar(personal(), ['codigoReserva' => 'R000000021', 'bultos' => []]), 404, 'reserva_no_encontrada');
});

// ── Formalizar ──────────────────────────────────────────────────────────────
prueba('formalizar: crea el envío, marca la reserva FORMALIZADA y devuelve código, bultos e importe', function () {
    [$res, $env, $servicio] = escenarioEnvios();
    $e = $servicio->formalizar(personal(), envioValido());
    afirmar(preg_match('/^EN[A-Z0-9]{10}$/', $e['codigoSeguimiento']) === 1, 'código de seguimiento con el formato de la tabla');
    afirmar($e['estado'] === 'REGISTRADO' && $e['tipoEntrega'] === 'AGENCIA' && $e['direccionEntrega'] === null, 'estado y entrega');
    afirmar($e['cantidadBultos'] === 2 && $e['pesoTotalKg'] === '9.20' && $e['importe'] === '23.75', '2 bultos · 9.20 kg · S/ 23.75');
    afirmar($e['bultos'][0] === ['numero' => 1, 'descripcion' => 'Sobre con documentos', 'pesoKg' => '0.40', 'tipo' => 'SOBRE', 'costo' => '9.15'], 'bulto 1');
    afirmar($e['bultos'][1]['numero'] === 2 && $e['bultos'][1]['tipo'] === 'PAQUETE' && $e['bultos'][1]['costo'] === '14.60', 'bulto 2');
    afirmar($res->reservas['R000000020']['estado'] === 'FORMALIZADA' && $res->reservas['R000000020']['seguimiento'] === $e['codigoSeguimiento'], 'la reserva quedó formalizada y enlazada');
    afirmar($env->envios[$e['codigoSeguimiento']]['plan']['bultos'][0]['idRuta'] === 1, 'cada bulto guarda la ruta con la que se cobra');
    afirmar($e['reserva']['remitente']['numeroDocumento'] === '71889345', 'datos de la reserva para la etiqueta');
});

prueba('formalizar: entrega a DOMICILIO exige dirección; AGENCIA descarta la que llegue', function () {
    [, , $servicio] = escenarioEnvios();
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['tipoEntrega' => 'DOMICILIO']))) === ['direccionEntrega'], 'domicilio sin dirección');
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['tipoEntrega' => 'DOMICILIO', 'direccionEntrega' => str_repeat('a', 151)]))) === ['direccionEntrega'], 'más de 150');
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['tipoEntrega' => 'CAMION']))) === ['tipoEntrega'], 'tipo no válido');
    $e = $servicio->formalizar(personal(), envioValido(['tipoEntrega' => 'domicilio', 'direccionEntrega' => '  Av. Siempre   Viva 742 ']));
    afirmar($e['tipoEntrega'] === 'DOMICILIO' && $e['direccionEntrega'] === 'Av. Siempre Viva 742', 'dirección normalizada');
    [, , $otro] = escenarioEnvios();
    afirmar($otro->formalizar(personal(), envioValido(['direccionEntrega' => 'Calle que se ignora']))['direccionEntrega'] === null, 'AGENCIA guarda NULL');
});

prueba('formalizar: un bulto sin ruta vigente (CARGA 31 kg) → 422, nada se guarda y la reserva sigue PENDIENTE', function () {
    [$res, $env, $servicio] = escenarioEnvios();
    $b = bultosDeLaDiapositiva();
    $b[] = ['descripcion' => 'Caja con herramientas', 'pesoKg' => '31.00'];
    $campos = camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => $b])));
    afirmar($campos === ['bultos.3.pesoKg'], 'el error apunta al bulto 3: ' . implode(',', $campos));
    afirmar($res->reservas['R000000020']['estado'] === 'PENDIENTE' && $env->envios === [], 'sin envío y sin cambio de estado');
});

prueba('formalizar: valida todos los bultos a la vez (descripción, peso, máximo) y exige al menos uno', function () {
    [, , $servicio] = escenarioEnvios();
    $campos = camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => [['descripcion' => '', 'pesoKg' => '1'], ['descripcion' => 'x', 'pesoKg' => 'abc'], ['descripcion' => 'y', 'pesoKg' => '400']]])));
    sort($campos);
    afirmar($campos === ['bultos.1.descripcion', 'bultos.2.pesoKg', 'bultos.3.pesoKg'], implode(',', $campos));
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => []]))) === ['bultos'], 'sin bultos');
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => 'no es lista']))) === ['bultos'], 'bultos no es una lista');
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => [['descripcion' => str_repeat('a', 151), 'pesoKg' => '1']]]))) === ['bultos.1.descripcion'], 'descripción de más de 150');
    afirmar(camposConError(fn () => $servicio->formalizar(personal(), envioValido(['bultos' => ['texto suelto']]))) !== [], 'un bulto que no es objeto se rechaza');
});

prueba('formalizar: la reserva debe ser de la agencia del personal (otra agencia o inexistente → 404 igual)', function () {
    [$res, , $servicio] = escenarioEnvios();
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000021'])), 404, 'reserva_no_encontrada');
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000999'])), 404, 'reserva_no_encontrada');
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => ''])), 404, 'reserva_no_encontrada');
    afirmar($res->reservas['R000000021']['estado'] === 'PENDIENTE', 'la de otra agencia no se tocó');
    afirmar($servicio->formalizar(personal(2, 'AQP001'), envioValido(['codigoReserva' => 'R000000021', 'bultos' => [['descripcion' => 'x', 'pesoKg' => '5']]]))['bultos'][0]['costo'] === '30.20', 'su propia agencia sí (AQP→LIM, 1010 km)');
});

prueba('formalizar: solo reservas PENDIENTES (formalizada, cancelada, vencida) → 409 con el motivo', function () {
    [$res, , $servicio] = escenarioEnvios();
    $res->sembrar('R000000024', 4, 50, 'MOQ001', 'AQP001', 'x', 'CANCELADA', '2026-10-31 08:00:00', null);
    $res->sembrar('R000000025', 4, 50, 'MOQ001', 'AQP001', 'x', 'VENCIDA', '2026-10-20 08:00:00', null);
    $res->sembrar('R000000026', 4, 50, 'MOQ001', 'AQP001', 'x', 'FORMALIZADA', '2026-10-31 08:00:00', 'ENABCDEFGH23');
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000024'])), 409, 'reserva_no_formalizable');
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000025'])), 409, 'reserva_vencida');
    $e = esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000026'])), 409, 'reserva_formalizada');
    afirmar(str_contains($e->getMessage(), 'ENABCDEFGH23'), 'indica el envío existente');
});

prueba('formalizar: plazo de 72 h — 71:59:59 se puede, 72:00:00 ya no (aunque siga PENDIENTE)', function () {
    [$res, , $servicio] = escenarioEnvios();
    esperarError(fn () => $servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000022'])), 409, 'reserva_vencida');
    afirmar($res->reservas['R000000022']['estado'] === 'PENDIENTE', 'no se modifica al rechazar');
    afirmar($servicio->formalizar(personal(), envioValido(['codigoReserva' => 'R000000023']))['estado'] === 'REGISTRADO', 'a 71:59:59 sí');
    $res->ahora = new DateTimeImmutable('2026-11-03 08:00:00'); // R000000020 cumple 72:00:00 exactos
    esperarError(fn () => $servicio->formalizar(personal(), envioValido()), 409, 'reserva_vencida');
});

prueba('formalizar: nadie se cuela por una reserva ya formalizada (segunda ventanilla)', function () {
    [, $env, $servicio] = escenarioEnvios();
    $servicio->formalizar(personal(), envioValido());
    esperarError(fn () => $servicio->formalizar(personal(), envioValido()), 409, 'reserva_formalizada');
    afirmar(count($env->envios) === 1, 'un solo envío por reserva');
});

prueba('formalizar: si otra operación se adelanta entre la lectura y la reclamación → 409 y no se guarda nada', function () {
    [$res, $env, $servicio] = escenarioEnvios();
    $env->antesDeReclamar = function (EnviosFalso $e) use ($res): void {
        $res->reservas['R000000020']['estado'] = 'CANCELADA'; // el cliente canceló justo en ese instante
    };
    esperarError(fn () => $servicio->formalizar(personal(), envioValido()), 409, 'reserva_no_formalizable');
    afirmar($env->envios === [] && $res->reservas['R000000020']['estado'] === 'CANCELADA', 'sin envío; gana la cancelación');

    [$res2, $env2, $servicio2] = escenarioEnvios();
    $env2->antesDeReclamar = function (EnviosFalso $e) use ($res2): void {
        $res2->ahora = new DateTimeImmutable('2026-11-03 08:00:00'); // el plazo se cumple en ese instante
    };
    esperarError(fn () => $servicio2->formalizar(personal(), envioValido()), 409, 'reserva_vencida');
    afirmar($env2->envios === [] && $res2->reservas['R000000020']['estado'] === 'PENDIENTE', 'sin envío; la reserva queda como estaba');
});

prueba('formalizar: la tarifa se vuelve a validar DENTRO de la transacción (si caduca, todo se deshace)', function () {
    [$res, $env, $servicio] = escenarioEnvios();
    $env->antesDePlanificar = function (EnviosFalso $e): void {
        $e->tarifas['MOQ001>AQP001'] = array_values(array_filter($e->tarifas['MOQ001>AQP001'], fn ($t) => $t['tipoEnvio'] !== 'PAQUETE')); // el gerente cerró la tarifa PAQUETE
    };
    $campos = camposConError(fn () => $servicio->formalizar(personal(), envioValido()));
    afirmar($campos === ['bultos.2.pesoKg'], 'el bulto de PAQUETE queda sin ruta: ' . implode(',', $campos));
    afirmar($res->reservas['R000000020']['estado'] === 'PENDIENTE' && $env->envios === [] && $env->transaccionesDeshechas === 1, 'transacción deshecha: reserva PENDIENTE, sin envío');
});

prueba('formalizar: el estado y el plazo que muestre el frontend no cuentan (se ignoran campos extra del cuerpo)', function () {
    [$res, , $servicio] = escenarioEnvios();
    $e = $servicio->formalizar(personal(), envioValido(['estado' => 'FORMALIZADA', 'importe' => '0.01', 'costo' => '0', 'idSucursalOrigen' => 99, 'bultos' => [['descripcion' => 'Caja', 'pesoKg' => '8.80', 'costo' => '0.01', 'tipo' => 'SOBRE', 'idRuta' => 1]]]));
    afirmar($e['importe'] === '14.60' && $e['bultos'][0]['tipo'] === 'PAQUETE' && $e['bultos'][0]['costo'] === '14.60', 'el costo y el tipo los calcula el servidor');
    afirmar($res->reservas['R000000020']['estado'] === 'FORMALIZADA', 'estado fijado por el servidor');
});

// ── Ver envío (etiqueta) ────────────────────────────────────────────────────
prueba('ver envío: lo ve el personal de la sucursal de origen y de destino; otros, 404 igual que inexistente', function () {
    [, , $servicio] = escenarioEnvios();
    $codigo = $servicio->formalizar(personal(), envioValido())['codigoSeguimiento'];
    afirmar($servicio->ver(personal(), strtolower($codigo))['importe'] === '23.75', 'origen (MOQ001)');
    afirmar($servicio->ver(personal(2, 'AQP001'), $codigo)['cantidadBultos'] === 2, 'destino (AQP001)');
    $otro = esperarError(fn () => $servicio->ver(personal(1, 'LIM001'), $codigo), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->ver(personal(), 'ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->ver(personal(), "EN'; DROP"), 404, 'envio_no_encontrado');
    afirmar($otro->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
});

// ── HTTP: permisos, controladores y rutas ───────────────────────────────────
prueba('permisos de ventanilla y envíos: solo ADMINISTRADOR (cliente y gerente 403; sin sesión 401)', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE)]);
    $servicioAuth = new AuthService($auth);
    $guardia = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicioAuth)); // igual que en rutas.php
    esperarError(fn () => $guardia(peticion('GET', '/api/ventanilla/reservas')), 401, 'no_autenticado');
    foreach ([4, 1] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $guardia(peticion('GET', '/api/ventanilla/reservas')), 403, 'acceso_denegado');
    }
    $limpiar();
    sesionDe($auth, $servicioAuth, 2);
    $guardia(peticion('GET', '/api/ventanilla/reservas'));
    $limpiar();
});

prueba('controladores: usan la sucursal de la sesión y no la del cuerpo', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3)]);
    $servicioAuth = new AuthService($auth);
    sesionDe($auth, $servicioAuth, 2);
    [$res, , $servicio, $ventanilla] = escenarioEnvios();

    $v = new VentanillaController($ventanilla);
    $r = leer($v->pendientes(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && count($r['cuerpo']['data']['reservas']) === 3, 'lista de la agencia de la sesión');
    $r = leer($v->crear(peticion('POST', '/x', json_encode(reservaDeVentanilla(['sucursalOrigen' => 'LIM001']))), []));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data']['reserva']['origen']['codigo'] === 'MOQ001', 'crea con origen MOQ001');
    esperarError(fn () => $v->ver(peticion('GET', '/x'), ['codigo' => 'R000000021']), 404, 'reserva_no_encontrada');
    $r = leer($v->consultarPersona(new Request('GET', '/x', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '70234561'], '', []), []));
    afirmar($r['cuerpo']['data']['existe'] === true, 'consulta de persona');
    esperarError(fn () => $v->crear(peticion('POST', '/x', '{roto'), []), 400, 'json_invalido');

    $e = new EnvioController($servicio);
    $r = leer($e->cotizar(peticion('POST', '/x', json_encode(['codigoReserva' => 'R000000020', 'bultos' => bultosDeLaDiapositiva()])), []));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['importe'] === '23.75', 'cotiza');
    $r = leer($e->formalizar(peticion('POST', '/x', json_encode(envioValido())), []));
    afirmar($r['estado'] === 201 && preg_match('/^EN[A-Z0-9]{10}$/', $r['cuerpo']['data']['envio']['codigoSeguimiento']) === 1, 'formaliza con 201');
    $r = leer($e->ver(peticion('GET', '/x'), ['codigo' => $r['cuerpo']['data']['envio']['codigoSeguimiento']]));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['cantidadBultos'] === 2, 'ver');
    $limpiar();
    esperarError(fn () => $e->formalizar(peticion('POST', '/x', json_encode(envioValido())), []), 401, 'no_autenticado');
});

prueba('rutas de ventanilla y envíos: todas exigen sesión; /personas y /cotizacion van antes de /{codigo}', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Reservas/rutas.php')($router);
    (require dirname(__DIR__) . '/src/Modules/Envios/rutas.php')($router);
    foreach ([['GET', '/api/ventanilla/reservas'], ['POST', '/api/ventanilla/reservas'], ['GET', '/api/ventanilla/reservas/R000000001'], ['GET', '/api/ventanilla/personas'],
              ['POST', '/api/envios'], ['POST', '/api/envios/cotizacion'], ['GET', '/api/envios/ENABCDEFGH23']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    esperarError(fn () => $router->despachar(peticion('DELETE', '/api/envios/ENABCDEFGH23')), 405, 'metodo_no_permitido');
    $rutas = (new ReflectionProperty(Router::class, 'rutas'))->getValue($router);
    $posicion = static function (string $metodo, string $fragmento) use ($rutas): int {
        foreach ($rutas as $i => $r) {
            if ($r['metodo'] === $metodo && str_contains($r['regex'], $fragmento)) {
                return $i;
            }
        }
        return -1;
    };
    afirmar($posicion('POST', 'cotizacion') >= 0, '/api/envios/cotizacion registrada');
    afirmar($posicion('GET', 'personas') >= 0 && $posicion('GET', 'personas') < $posicion('GET', 'ventanilla/reservas/'), '/personas antes de /reservas/{codigo}');
});

prueba('el cuerpo de una formalización con tipos raros no rompe nada (siempre 422/404, nunca un error interno)', function () {
    [, , $servicio] = escenarioEnvios();
    foreach ([
        ['codigoReserva' => ['R000000020']], ['codigoReserva' => 'R000000020', 'tipoEntrega' => ['AGENCIA'], 'bultos' => []],
        ['codigoReserva' => 'R000000020', 'tipoEntrega' => 'AGENCIA', 'bultos' => [['descripcion' => ['x'], 'pesoKg' => ['1']]]],
        ['codigoReserva' => 'R000000020', 'tipoEntrega' => 'AGENCIA', 'bultos' => [['descripcion' => 'x', 'pesoKg' => null]]],
        ['codigoReserva' => 'R000000020', 'tipoEntrega' => 'AGENCIA', 'bultos' => [null, 5]],
    ] as $cuerpo) {
        try {
            $servicio->formalizar(personal(), $cuerpo);
            afirmar(false, 'debía rechazarse: ' . json_encode($cuerpo));
        } catch (HttpException $e) {
            afirmar(in_array($e->estado, [404, 422], true), 'estado ' . $e->estado . ' para ' . json_encode($cuerpo));
        }
    }
});
