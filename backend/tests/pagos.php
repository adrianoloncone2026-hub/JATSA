<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 6 (Pagos y Caja) con repositorios simulados, sin base de datos.
 * Se incluye desde ejecutar.php, que aporta el mini marco (prueba, afirmar, esperarError…) y, junto con envios.php,
 * los simulados de reservas y envíos y el escenario de ventanilla.
 */

use Jatsa\Core\Request;
use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Envios\EnvioService;
use Jatsa\Modules\Pagos\PagoController;
use Jatsa\Modules\Pagos\PagoService;
use Jatsa\Modules\Pagos\RepositorioPagos;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/**
 * Repositorio de pagos en memoria, enlazado a los de envíos y reservas. Reproduce lo que hace el SQL real: registrar
 * «bloquea» el envío, lee importe y pagado y llama a $decidir (que puede abortar sin escribir nada).
 */
final class PagosFalso implements RepositorioPagos
{
    /** @var array<int, array<string, mixed>> idPago => pago (con la forma que devuelve PagoRepository) */
    public array $pagos = [];
    public int $siguiente = 1;
    /** @var array<string, string> codigo => estado del envío (por defecto REGISTRADO) */
    public array $estados = [];
    public int $transaccionesDeshechas = 0;
    /** Se ejecuta con el envío «bloqueado» y antes de decidir: simula a otra caja que se adelantó. */
    public ?Closure $antesDeDecidir = null;
    private int $minuto = 0;

    public function __construct(private readonly EnviosFalso $envios, private readonly ReservasFalso $reservas)
    {
    }

    /** Alta directa (sin reglas): sirve para sembrar muchos pagos. */
    public function sembrar(string $codigo, string $codigoSucursal, string $monto, string $metodo = 'EFECTIVO', string $pagadoPor = 'REMITENTE', string $estado = 'REGISTRADO'): int
    {
        $id = $this->siguiente++;
        $agencias = new AgenciasFalso();
        $this->pagos[$id] = [
            'idPago' => $id, 'fechaHoraPago' => (new DateTimeImmutable('2026-10-31 10:00:00'))->modify('+' . $this->minuto++ . ' minutes')->format('Y-m-d H:i:s'),
            'monto' => $monto, 'metodoPago' => $metodo, 'pagadoPor' => $pagadoPor, 'estadoPago' => $estado, 'codigoSeguimiento' => $codigo,
            'idSucursal' => ReservasFalso::IDS_SUCURSAL[$codigoSucursal], 'sucursalCodigo' => $codigoSucursal,
            'sucursalNombre' => $agencias->buscarActivaPorCodigo($codigoSucursal)['nombre'],
        ];

        return $id;
    }

    /** @return array{importe: int, pagado: int} */
    private function sumas(string $codigo): array
    {
        $importe = 0;
        foreach ($this->envios->buscarPorCodigo($codigo)['bultos'] as $b) {
            $importe += (int) round(((float) $b['costo']) * 100);
        }
        $pagado = 0;
        foreach ($this->pagos as $p) {
            if ($p['codigoSeguimiento'] === $codigo && $p['estadoPago'] === 'REGISTRADO') {
                $pagado += (int) round(((float) $p['monto']) * 100);
            }
        }

        return ['importe' => $importe, 'pagado' => $pagado];
    }

    public function buscarEnvioParaCobro(string $codigoSeguimiento): ?array
    {
        $e = $this->envios->buscarPorCodigo($codigoSeguimiento);
        if ($e === null) {
            return null;
        }
        $s = $this->sumas($codigoSeguimiento);
        $pagos = array_values(array_filter($this->pagos, static fn (array $p): bool => $p['codigoSeguimiento'] === $codigoSeguimiento));
        usort($pagos, static fn (array $a, array $b): int => [$b['fechaHoraPago'], $b['idPago']] <=> [$a['fechaHoraPago'], $a['idPago']]);

        return [
            'codigoSeguimiento' => $codigoSeguimiento, 'estado' => $this->estados[$codigoSeguimiento] ?? 'REGISTRADO',
            'idSucursalOrigen' => $e['idSucursalOrigen'], 'idSucursalDestino' => $e['idSucursalDestino'],
            'origen' => ['codigo' => $e['reserva']['origen']['codigo'], 'nombre' => $e['reserva']['origen']['nombre']],
            'destino' => ['codigo' => $e['reserva']['destino']['codigo'], 'nombre' => $e['reserva']['destino']['nombre']],
            'remitente' => $e['reserva']['remitente']['nombreCompleto'], 'destinatario' => $e['reserva']['destinatario']['nombreCompleto'],
            'importe' => sprintf('%d.%02d', intdiv($s['importe'], 100), $s['importe'] % 100),
            'pagado' => sprintf('%d.%02d', intdiv($s['pagado'], 100), $s['pagado'] % 100),
            'pagos' => $pagos,
        ];
    }

    public function registrar(string $codigoSeguimiento, int $idSucursal, string $monto, string $metodoPago, string $pagadoPor, callable $decidir): ?int
    {
        $e = $this->envios->buscarPorCodigo($codigoSeguimiento);
        if ($e === null) {
            return null;
        }
        if ($this->antesDeDecidir !== null) {
            ($this->antesDeDecidir)($this);
        }
        $s = $this->sumas($codigoSeguimiento);
        try {
            $decidir(['idEnvio' => 1, 'estado' => $this->estados[$codigoSeguimiento] ?? 'REGISTRADO', 'idSucursalOrigen' => $e['idSucursalOrigen'],
                'idSucursalDestino' => $e['idSucursalDestino'], 'importe' => $s['importe'], 'pagado' => $s['pagado']]);
        } catch (Throwable $t) {
            $this->transaccionesDeshechas++;
            throw $t;
        }

        return $this->sembrar($codigoSeguimiento, array_search($idSucursal, ReservasFalso::IDS_SUCURSAL, true), $monto, $metodoPago, $pagadoPor);
    }

    public function buscarPago(int $idPago): ?array
    {
        return $this->pagos[$idPago] ?? null;
    }

    /** Simula que el UPDATE condicional final no cambia ninguna fila (otra caja se adelantó justo después de decidir). */
    public bool $anulacionPerdida = false;

    public function anular(int $idPago, int $idSucursal, callable $decidir): ?bool
    {
        if (!isset($this->pagos[$idPago])) {
            return null;
        }
        // «Bloquea» el envío y lee el pago con el bloqueo tomado, como el SQL real; después decide.
        if ($this->antesDeDecidir !== null) {
            ($this->antesDeDecidir)($this);
        }
        $p = $this->pagos[$idPago];
        try {
            $decidir(['pago' => ['idPago' => $idPago, 'idSucursal' => $p['idSucursal'], 'estadoPago' => $p['estadoPago']], 'estadoEnvio' => $this->estados[$p['codigoSeguimiento']] ?? 'REGISTRADO']);
        } catch (Throwable $t) {
            $this->transaccionesDeshechas++;
            throw $t;
        }
        if ($this->anulacionPerdida || $p['idSucursal'] !== $idSucursal || $this->pagos[$idPago]['estadoPago'] !== 'REGISTRADO') {
            $this->transaccionesDeshechas++;

            return false;
        }
        $this->pagos[$idPago]['estadoPago'] = 'ANULADO';

        return true;
    }

    public function listarDeSucursal(int $idSucursal, int $limite): array
    {
        $filas = array_values(array_filter($this->pagos, static fn (array $p): bool => $p['idSucursal'] === $idSucursal));
        usort($filas, static fn (array $a, array $b): int => [$b['fechaHoraPago'], $b['idPago']] <=> [$a['fechaHoraPago'], $a['idPago']]);

        return array_slice($filas, 0, $limite);
    }

    public function totalesDeSucursal(int $idSucursal): array
    {
        $totales = [];
        foreach ($this->pagos as $p) {
            if ($p['idSucursal'] === $idSucursal && $p['estadoPago'] === 'REGISTRADO') {
                $totales[$p['metodoPago']] = ($totales[$p['metodoPago']] ?? 0) + (int) round(((float) $p['monto']) * 100);
            }
        }

        return $totales;
    }

    /** @return list<array<string, mixed>> */
    private function deLaPersona(int $idPersona): array
    {
        return array_values(array_filter($this->pagos, function (array $p) use ($idPersona): bool {
            $reserva = $this->reservas->reservas[$this->envios->envios[$p['codigoSeguimiento']]['reserva']];

            return ($p['pagadoPor'] === 'REMITENTE' && $reserva['idRemitente'] === $idPersona)
                || ($p['pagadoPor'] === 'DESTINATARIO' && $reserva['idDestinatario'] === $idPersona);
        }));
    }

    public function listarDePersona(int $idPersona, int $limite): array
    {
        $filas = $this->deLaPersona($idPersona);
        usort($filas, static fn (array $a, array $b): int => [$b['fechaHoraPago'], $b['idPago']] <=> [$a['fechaHoraPago'], $a['idPago']]);

        return array_slice($filas, 0, $limite);
    }

    public function totalPagadoDePersona(int $idPersona): int
    {
        $total = 0;
        foreach ($this->deLaPersona($idPersona) as $p) {
            if ($p['estadoPago'] === 'REGISTRADO') {
                $total += (int) round(((float) $p['monto']) * 100);
            }
        }

        return $total;
    }
}

/**
 * Escenario: el envío del caso de la diapositiva 19 (S/ 23.75, MOQ001 → AQP001, remitente María y destinatario Luis)
 * ya formalizado, con el servicio de pagos enlazado a los simulados.
 *
 * @return array{0: ReservasFalso, 1: EnviosFalso, 2: PagosFalso, 3: PagoService, 4: string, 5: EnvioService}
 */
function escenarioPagos(): array
{
    [$res, $env, $servicioEnvios] = escenarioEnvios();
    $codigo = $servicioEnvios->formalizar(personal(), envioValido())['codigoSeguimiento'];
    $pagos = new PagosFalso($env, $res);

    return [$res, $env, $pagos, new PagoService($pagos, $res), $codigo, $servicioEnvios];
}

function pagoValido(string $codigo, array $cambios = []): array
{
    return $cambios + ['codigoSeguimiento' => $codigo, 'monto' => '23.75', 'pagadoPor' => 'REMITENTE', 'metodoPago' => 'EFECTIVO'];
}

// ── Caja: el envío a cobrar ─────────────────────────────────────────────────
prueba('caja: el envío a cobrar muestra importe, pagado y saldo (diapositiva 20) y solo nombres, sin documentos ni teléfonos', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    $e = $servicio->envioParaCobro(personal(), strtolower($codigo));
    afirmar($e['codigoSeguimiento'] === $codigo && $e['importe'] === '23.75' && $e['pagado'] === '0.00' && $e['saldo'] === '23.75', 'importe, pagado y saldo');
    afirmar($e['origen']['codigo'] === 'MOQ001' && $e['destino']['codigo'] === 'AQP001' && $e['pagos'] === [], 'ruta y sin pagos');
    afirmar($e['remitente'] === 'María Elena Rojas Ccori' && $e['destinatario'] === 'Luis Alberto Flores Condori', 'nombres');
    $json = json_encode($e);
    foreach (['71889345', '70234561', '987654', 'telefono', 'numeroDocumento'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "no debe exponer $prohibido");
    }
});

prueba('caja: el envío lo cobra el personal de origen y de destino; otra sucursal e inexistente dan el mismo 404', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    afirmar($servicio->envioParaCobro(personal(2, 'AQP001'), $codigo)['saldo'] === '23.75', 'destino (AQP001)');
    $otro = esperarError(fn () => $servicio->envioParaCobro(personal(1, 'LIM001'), $codigo), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->envioParaCobro(personal(), 'ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->envioParaCobro(personal(), "EN'; DROP TABLE"), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->envioParaCobro(personal(), ''), 404, 'envio_no_encontrado');
    afirmar($otro->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
});

prueba('caja: sugiere quién paga según dónde está el cajero (origen → remitente; solo destino → destinatario)', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    afirmar($servicio->envioParaCobro(personal(), $codigo)['pagadoPorSugerido'] === 'REMITENTE', 'en origen');
    afirmar($servicio->envioParaCobro(personal(2, 'AQP001'), $codigo)['pagadoPorSugerido'] === 'DESTINATARIO', 'en destino');
});

// ── Caja: registrar un pago ─────────────────────────────────────────────────
prueba('registrar: el pago completo deja saldo 0, usa la sucursal de la sesión y devuelve pago y envío', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $r = $servicio->registrar(personal(), pagoValido($codigo));
    afirmar($r['pago']['monto'] === '23.75' && $r['pago']['estado'] === 'REGISTRADO' && $r['pago']['metodoPago'] === 'EFECTIVO' && $r['pago']['pagadoPor'] === 'REMITENTE', 'pago');
    afirmar($r['pago']['sucursal']['codigo'] === 'MOQ001' && $r['pago']['codigoSeguimiento'] === $codigo && is_int($r['pago']['id']), 'sucursal, envío e id');
    afirmar($r['envio']['pagado'] === '23.75' && $r['envio']['saldo'] === '0.00' && count($r['envio']['pagos']) === 1, 'envío actualizado');
    afirmar(count($repo->pagos) === 1, 'un solo pago');
});

prueba('registrar: pagos parciales hasta completar; el sobrepago y el pago de un envío ya pagado se rechazan', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '10.00', 'metodoPago' => 'TARJETA']));
    $e = $servicio->envioParaCobro(personal(), $codigo);
    afirmar($e['pagado'] === '10.00' && $e['saldo'] === '13.75', 'saldo tras el parcial');
    $error = esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '13.76'])), 422, 'datos_invalidos');
    afirmar(str_contains($error->campos['monto'], '13.75'), 'el mensaje dice el saldo: ' . ($error->campos['monto'] ?? ''));
    afirmar(count($repo->pagos) === 1, 'el sobrepago no escribe');
    $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '13.75', 'pagadoPor' => 'DESTINATARIO', 'metodoPago' => 'BILLETERA']));
    afirmar($servicio->envioParaCobro(personal(), $codigo)['saldo'] === '0.00', 'saldo 0');
    esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '0.01'])), 409, 'envio_pagado');
    afirmar(count($repo->pagos) === 2, 'ningún pago más');
});

prueba('registrar: valida todos los campos a la vez (422) y no escribe nada', function () {
    [, , $repo, $servicio] = escenarioPagos();
    $e = esperarError(fn () => $servicio->registrar(personal(), ['codigoSeguimiento' => 'XX', 'monto' => 'abc', 'pagadoPor' => 'OTRO', 'metodoPago' => 'CHEQUE']), 422, 'datos_invalidos');
    $campos = array_keys($e->campos);
    sort($campos);
    afirmar($campos === ['codigoSeguimiento', 'metodoPago', 'monto', 'pagadoPor'], 'campos: ' . implode(',', $campos));
    afirmar($repo->pagos === [], 'sin escritura');
});

prueba('registrar: montos inválidos (cero, negativo, 3 decimales, vacío, notación científica, texto) → 422', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    foreach (['0', '0.00', '-5', '10.999', '', ' ', '1e2', '12,5,5', '1 000', '99999999999', null, [], true, 0, 0.0, -1.5] as $monto) {
        $e = esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo, ['monto' => $monto])), 422, 'datos_invalidos');
        afirmar(isset($e->campos['monto']), 'marca monto para ' . json_encode($monto));
    }
    afirmar($repo->pagos === [], 'ninguno escribe');
});

prueba('registrar: acepta coma decimal, espacios, números y mayúsculas/minúsculas; el monto exacto no pierde céntimos', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    $r = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => ' 0,10 ', 'metodoPago' => 'transferencia', 'pagadoPor' => 'remitente', 'codigoSeguimiento' => strtolower($codigo)]));
    afirmar($r['pago']['monto'] === '0.10' && $r['pago']['metodoPago'] === 'TRANSFERENCIA' && $r['pago']['pagadoPor'] === 'REMITENTE', 'normalizado');
    $r = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => 10]));
    afirmar($r['pago']['monto'] === '10.00', 'entero');
    $r = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => 13.65]));
    afirmar($r['pago']['monto'] === '13.65' && $r['envio']['saldo'] === '0.00', '23.75 = 0.10 + 10.00 + 13.65 sin error de coma flotante');
});

prueba('registrar: el cuerpo no puede fijar sucursal, estado ni fecha (se ignoran)', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $r = $servicio->registrar(personal(), pagoValido($codigo, ['idSucursal' => 1, 'sucursal' => 'LIM001', 'estado' => 'ANULADO', 'estadoPago' => 'ANULADO', 'fechaHoraPago' => '2020-01-01 00:00:00', 'monto' => '5.00']));
    afirmar($r['pago']['sucursal']['codigo'] === 'MOQ001' && $r['pago']['estado'] === 'REGISTRADO' && !str_starts_with($r['pago']['fechaHora'], '2020'), 'sucursal de la sesión, REGISTRADO y fecha de la base');
});

prueba('registrar: el personal de la agencia de destino cobra en SU caja; el de otra agencia, 404', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $r = $servicio->registrar(personal(2, 'AQP001'), pagoValido($codigo, ['pagadoPor' => 'DESTINATARIO', 'monto' => '23.75']));
    afirmar($r['pago']['sucursal']['codigo'] === 'AQP001' && $r['pago']['pagadoPor'] === 'DESTINATARIO', 'pago en destino registrado en AQP001');
    [, , $repo2, $servicio2, $codigo2] = escenarioPagos();
    $otro = esperarError(fn () => $servicio2->registrar(personal(1, 'LIM001'), pagoValido($codigo2)), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio2->registrar(personal(), pagoValido('ENZZZZZZZZZZ')), 404, 'envio_no_encontrado');
    afirmar($otro->getMessage() === $inexistente->getMessage() && $repo2->pagos === [], 'mismo mensaje y sin escritura');
    afirmar($repo2->transaccionesDeshechas === 1, 'el rechazo dentro de la transacción la deshace');
});

prueba('registrar: un envío ANULADO no admite pagos', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $repo->estados[$codigo] = 'ANULADO';
    esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo)), 409, 'envio_anulado');
    afirmar($repo->pagos === [], 'sin escritura');
    foreach (['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO'] as $estado) {
        $repo->estados[$codigo] = $estado;
        $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '1.00'])); // el pago en destino ocurre con el envío en curso o ya en destino
    }
});

prueba('registrar: un envío ENTREGADO o DEVUELTO ya no admite cobros (409 sin escritura); los reembolsos quedan fuera de alcance', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '10.00']));
    $antes = count($repo->pagos);
    $repo->estados[$codigo] = 'ENTREGADO';
    $e = esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '1.00'])), 409, 'envio_entregado');
    afirmar(str_contains($e->getMessage(), 'entregado'), 'mensaje');
    $repo->estados[$codigo] = 'DEVUELTO';
    $e = esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '1.00'])), 409, 'envio_devuelto');
    afirmar(str_contains($e->getMessage(), 'devuelto'), 'mensaje');
    afirmar(count($repo->pagos) === $antes && $repo->transaccionesDeshechas === 2, 'sin escritura y transacciones deshechas');
    // El envío sigue visible para consultar (saldo pendiente incluido): cerrarlo no oculta lo que se debe.
    afirmar($servicio->envioParaCobro(personal(), $codigo)['saldo'] === '13.75', 'se puede consultar');
});

prueba('registrar: si el envío se devuelve entre la lectura y el cobro, la decisión usa el estado bloqueado (409) y no se cobra', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $repo->antesDeDecidir = function (PagosFalso $r) use ($codigo): void { $r->antesDeDecidir = null; $r->estados[$codigo] = 'DEVUELTO'; };
    esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo)), 409, 'envio_devuelto');
    afirmar($repo->pagos === [], 'sin escritura');
});

prueba('registrar: si otra caja se adelanta entre la lectura y el cobro, no hay sobrepago (la decisión usa lo bloqueado)', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    // Dos cajeros intentan cobrar los 23.75 a la vez: el segundo, ya con el envío bloqueado, ve lo que pagó el primero.
    $repo->antesDeDecidir = function (PagosFalso $r) use ($codigo): void {
        $r->antesDeDecidir = null;
        $r->sembrar($codigo, 'MOQ001', '23.75');
    };
    esperarError(fn () => $servicio->registrar(personal(), pagoValido($codigo)), 409, 'envio_pagado');
    afirmar(count($repo->pagos) === 1 && $repo->transaccionesDeshechas === 1, 'queda un solo pago y se deshizo la transacción');
});

prueba('registrar: tipos raros en el cuerpo nunca rompen nada (siempre 422 o 404, jamás un error interno)', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    foreach ([
        [], ['codigoSeguimiento' => [$codigo]], ['codigoSeguimiento' => $codigo, 'monto' => ['1'], 'pagadoPor' => ['REMITENTE'], 'metodoPago' => ['EFECTIVO']],
        ['codigoSeguimiento' => null, 'monto' => null], ['codigoSeguimiento' => $codigo, 'monto' => '1', 'pagadoPor' => 1, 'metodoPago' => false],
        ['codigoSeguimiento' => "EN'; DROP TABLE Pago;--", 'monto' => '1', 'pagadoPor' => 'REMITENTE', 'metodoPago' => 'EFECTIVO'],
    ] as $cuerpo) {
        try {
            $servicio->registrar(personal(), $cuerpo);
            afirmar(false, 'debía rechazarse: ' . json_encode($cuerpo));
        } catch (HttpException $e) {
            afirmar(in_array($e->estado, [404, 422], true), 'estado ' . $e->estado . ' para ' . json_encode($cuerpo));
        }
    }
});

prueba('registrar: sin sucursal asignada → 403', function () {
    [, , , $servicio, $codigo] = escenarioPagos();
    esperarError(fn () => $servicio->registrar(['id' => 9, 'rol' => Roles::ADMINISTRADOR, 'sucursal' => null], pagoValido($codigo)), 403, 'sin_sucursal');
});

// ── Caja: anular ────────────────────────────────────────────────────────────
prueba('anular: el pago queda ANULADO (no se borra), el saldo se recupera y se puede cobrar de nuevo', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $id = $servicio->registrar(personal(), pagoValido($codigo))['pago']['id'];
    $anulado = $servicio->anular(personal(), (string) $id);
    afirmar($anulado['estado'] === 'ANULADO' && $anulado['id'] === $id && isset($repo->pagos[$id]), 'queda guardado como ANULADO');
    $e = $servicio->envioParaCobro(personal(), $codigo);
    afirmar($e['pagado'] === '0.00' && $e['saldo'] === '23.75' && $e['pagos'][0]['estado'] === 'ANULADO', 'el saldo vuelve y el pago anulado sigue visible');
    $servicio->registrar(personal(), pagoValido($codigo, ['metodoPago' => 'TARJETA']));
    afirmar($servicio->envioParaCobro(personal(), $codigo)['saldo'] === '0.00', 'cobrado otra vez');
    $e = esperarError(fn () => $servicio->anular(personal(), (string) $id), 409, 'pago_anulado');
    afirmar(str_contains($e->getMessage(), 'anulado'), 'mensaje');
});

prueba('anular: solo en la caja que cobró; un pago de otra sucursal o inexistente da el mismo 404', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $id = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '5.00']))['pago']['id'];
    $otra = esperarError(fn () => $servicio->anular(personal(2, 'AQP001'), (string) $id), 404, 'pago_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->anular(personal(), '999'), 404, 'pago_no_encontrado');
    foreach (['abc', '0', '-1', '1.5', '', "1'; DROP", '99999999999'] as $raro) {
        esperarError(fn () => $servicio->anular(personal(), $raro), 404, 'pago_no_encontrado');
    }
    afirmar($otra->getMessage() === $inexistente->getMessage() && $repo->pagos[$id]['estadoPago'] === 'REGISTRADO', 'mismo mensaje y el pago sigue REGISTRADO');
});

prueba('anular: si otra caja se adelantó, se detecta bajo bloqueo (409) y, si el UPDATE condicional no cambia nada, también (409)', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $id = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '5.00']))['pago']['id'];
    // Otra caja lo anula justo antes de que esta decida: con el envío bloqueado, la decisión ya lo ve ANULADO.
    $repo->antesDeDecidir = function (PagosFalso $r) use ($id): void { $r->antesDeDecidir = null; $r->pagos[$id]['estadoPago'] = 'ANULADO'; };
    esperarError(fn () => $servicio->anular(personal(), (string) $id), 409, 'pago_anulado');
    // Y si, pese a todo, el UPDATE condicional final no cambia ninguna fila, la respuesta es la misma.
    $id2 = $servicio->registrar(personal(), pagoValido($codigo, ['monto' => '5.00']))['pago']['id'];
    $repo->anulacionPerdida = true;
    esperarError(fn () => $servicio->anular(personal(), (string) $id2), 409, 'pago_anulado');
    afirmar($repo->pagos[$id2]['estadoPago'] === 'REGISTRADO', 'el pago sigue REGISTRADO');
});

prueba('anular: después de la ENTREGA (o la devolución) del envío → 409 sin escritura; un pago ajeno sigue siendo 404 (no revela el estado del envío)', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $id = $servicio->registrar(personal(), pagoValido($codigo))['pago']['id'];
    $repo->estados[$codigo] = 'ENTREGADO';
    $e = esperarError(fn () => $servicio->anular(personal(), (string) $id), 409, 'envio_entregado');
    afirmar(str_contains($e->getMessage(), 'reembolsos'), 'aclara que los reembolsos no se gestionan: ' . $e->getMessage());
    afirmar($repo->pagos[$id]['estadoPago'] === 'REGISTRADO' && $repo->transaccionesDeshechas === 1, 'el pago sigue REGISTRADO y la transacción se deshizo');
    $repo->estados[$codigo] = 'DEVUELTO';
    esperarError(fn () => $servicio->anular(personal(), (string) $id), 409, 'envio_devuelto');
    afirmar($repo->pagos[$id]['estadoPago'] === 'REGISTRADO', 'sigue REGISTRADO');
    afirmar($servicio->envioParaCobro(personal(), $codigo)['saldo'] === '0.00', 'el envío conserva su saldo en cero (nunca queda con saldo por una anulación posterior)');
    $otra = esperarError(fn () => $servicio->anular(personal(2, 'AQP001'), (string) $id), 404, 'pago_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->anular(personal(), '999'), 404, 'pago_no_encontrado');
    afirmar($otra->getMessage() === $inexistente->getMessage(), 'la caja ajena recibe el mismo 404 aunque el envío esté cerrado');
});

prueba('carrera ENTREGA ↔ ANULACIÓN del mismo envío: gana una sola y nunca queda ENTREGADO con saldo', function () {
    // Caso A: la entrega ocurre justo antes de decidir la anulación → la anulación se rechaza y el saldo sigue en cero.
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $id = $servicio->registrar(personal(), pagoValido($codigo))['pago']['id'];
    $repo->antesDeDecidir = function (PagosFalso $r) use ($codigo): void { $r->antesDeDecidir = null; $r->estados[$codigo] = 'ENTREGADO'; };
    esperarError(fn () => $servicio->anular(personal(), (string) $id), 409, 'envio_entregado');
    afirmar($repo->pagos[$id]['estadoPago'] === 'REGISTRADO' && $servicio->envioParaCobro(personal(), $codigo)['saldo'] === '0.00', 'caso A: entregado y sin saldo');
    // Caso B: la anulación gana → el envío queda con saldo; la entrega (módulo 8) lo rechaza por saldo pendiente (ver entregas.php).
    [, , $repo2, $servicio2, $codigo2] = escenarioPagos();
    $id2 = $servicio2->registrar(personal(), pagoValido($codigo2))['pago']['id'];
    $servicio2->anular(personal(), (string) $id2);
    afirmar($servicio2->envioParaCobro(personal(), $codigo2)['saldo'] === '23.75' && ($repo2->estados[$codigo2] ?? 'REGISTRADO') !== 'ENTREGADO', 'caso B: saldo pendiente y no entregado');
});
/**
 * Crea una reserva PENDIENTE de la agencia de origen y la formaliza con un solo bulto; devuelve el código del envío.
 */
function envioNuevo(ReservasFalso $res, EnvioService $servicioEnvios, string $reserva, int $remitente, int $destinatario, string $origen, string $destino, string $pesoKg): string
{
    $res->sembrar($reserva, $remitente, $destinatario, $origen, $destino, 'Contenido de prueba', 'PENDIENTE', '2026-10-31 08:00:00', null);

    return $servicioEnvios->formalizar(personal(ReservasFalso::IDS_SUCURSAL[$origen], $origen), [
        'codigoReserva' => $reserva, 'tipoEntrega' => 'AGENCIA', 'bultos' => [['descripcion' => 'Bulto', 'pesoKg' => $pesoKg]],
    ])['codigoSeguimiento'];
}

function cobro(string $codigo, string $monto, string $metodo = 'EFECTIVO', string $pagadoPor = 'REMITENTE'): array
{
    return ['codigoSeguimiento' => $codigo, 'monto' => $monto, 'metodoPago' => $metodo, 'pagadoPor' => $pagadoPor];
}

// ── Caja: pagos cobrados aquí ───────────────────────────────────────────────
prueba('pagos cobrados aquí: solo los de la sucursal, el más reciente primero, y los totales de la diapositiva 20 (el anulado no suma)', function () {
    [$res, , , $servicio, $codigo, $servicioEnvios] = escenarioPagos();
    $servicio->registrar(personal(), cobro($codigo, '23.75'));                                                   // 23.75 EFECTIVO
    $ilo = envioNuevo($res, $servicioEnvios, 'R000000024', 50, 4, 'ILO001', 'MOQ001', '0.60');                   // 8.50
    $servicio->registrar(personal(), cobro($ilo, '8.50', 'EFECTIVO', 'DESTINATARIO'));                           // 8.50 EFECTIVO
    $paquete = envioNuevo($res, $servicioEnvios, 'R000000025', 4, 50, 'MOQ001', 'AQP001', '18.5');               // 14.60
    $servicio->registrar(personal(), cobro($paquete, '14.60', 'TARJETA'));                                       // 14.60 TARJETA
    $conError = envioNuevo($res, $servicioEnvios, 'R000000026', 4, 50, 'MOQ001', 'AQP001', '18.5');
    $idError = $servicio->registrar(personal(), cobro($conError, '14.60', 'BILLETERA'))['pago']['id'];
    $servicio->anular(personal(), (string) $idError);                                                            // anulado: no suma
    $lejos = envioNuevo($res, $servicioEnvios, 'R000000027', 4, 50, 'AQP001', 'LIM001', '18.5');                 // 30.20 en otra caja
    $servicio->registrar(personal(2, 'AQP001'), cobro($lejos, '30.20', 'TRANSFERENCIA'));

    $moq = $servicio->cobradosEnLaSucursal(personal());
    afirmar($moq['sucursal'] === ['codigo' => 'MOQ001', 'nombre' => 'Agencia MOQ001'] && $moq['hayMas'] === false, 'sucursal de la sesión y sin truncar');
    afirmar(count($moq['pagos']) === 4 && $moq['pagos'][0]['id'] === $idError && $moq['pagos'][0]['estado'] === 'ANULADO', 'cuatro pagos, el más reciente primero (el anulado sigue visible)');
    afirmar(array_unique(array_column(array_column($moq['pagos'], 'sucursal'), 'codigo')) === ['MOQ001'], 'solo de esta caja');
    afirmar($moq['totales'] === ['EFECTIVO' => '32.25', 'TARJETA' => '14.60', 'BILLETERA' => '0.00', 'TRANSFERENCIA' => '0.00', 'total' => '46.85'], 'totales: ' . json_encode($moq['totales']));
    $aqp = $servicio->cobradosEnLaSucursal(personal(2, 'AQP001'));
    afirmar(count($aqp['pagos']) === 1 && $aqp['totales']['total'] === '30.20' && $aqp['totales']['TRANSFERENCIA'] === '30.20', 'la otra caja ve solo lo suyo');
    afirmar($servicio->cobradosEnLaSucursal(personal(1, 'LIM001'))['pagos'] === [] && $servicio->cobradosEnLaSucursal(personal(1, 'LIM001'))['totales']['total'] === '0.00', 'caja vacía');
});

prueba('pagos cobrados aquí: la lista se acota a 200 pero los totales cuentan todos', function () {
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    for ($i = 0; $i < 205; $i++) {
        $repo->sembrar($codigo, 'MOQ001', '0.10');
    }
    $r = $servicio->cobradosEnLaSucursal(personal());
    afirmar(count($r['pagos']) === PagoService::MAX_LISTA && $r['hayMas'] === true, 'acotada y avisa');
    afirmar($r['totales']['total'] === '20.50', 'el total incluye los 205: ' . $r['totales']['total']);
    afirmar($r['pagos'][0]['id'] === 205, 'empieza por el más reciente');
    $repo->pagos = array_slice($repo->pagos, 5, null, true); // quedan exactamente 200
    afirmar($servicio->cobradosEnLaSucursal(personal())['hayMas'] === false, 'con exactamente 200 no hay más');
});

// ── Mis pagos (cliente / empresario) ────────────────────────────────────────
/** @return array{0: PagoService, 1: ReservasFalso, 2: PagosFalso, 3: string, 4: string, 5: string} servicio, reservas, pagos y los códigos de los tres envíos */
function escenarioMisPagos(): array
{
    [$res, , $repo, $servicio, $codigo, $servicioEnvios] = escenarioPagos(); // $codigo: R000000020 (María → Luis, MOQ001 → AQP001, 23.75)
    $res->usuarios[7] = 50; // la cuenta de Luis
    $primero = $servicio->registrar(personal(), cobro($codigo, '10.00'))['pago']['id'];
    $servicio->anular(personal(), (string) $primero);                                                             // un pago con error de María
    $servicio->registrar(personal(), cobro($codigo, '23.75'));                                                    // María paga como remitente
    $ilo = envioNuevo($res, $servicioEnvios, 'R000000024', 50, 4, 'ILO001', 'MOQ001', '0.60');                    // Luis → María, 8.50
    $servicio->registrar(personal(), cobro($ilo, '8.50', 'EFECTIVO', 'DESTINATARIO'));                            // María paga como destinataria
    $paquete = envioNuevo($res, $servicioEnvios, 'R000000025', 4, 50, 'MOQ001', 'AQP001', '18.5');                // María → Luis, 14.60
    $servicio->registrar(personal(2, 'AQP001'), cobro($paquete, '14.60', 'TARJETA', 'DESTINATARIO'));             // lo paga Luis, no María

    return [$servicio, $res, $repo, $codigo, $ilo, $paquete];
}

prueba('mis pagos: la cliente ve lo que ella pagó (diapositiva 16: S/ 32.25) y no lo que pagó la otra parte', function () {
    [$servicio, , , $codigo, $ilo, $paquete] = escenarioMisPagos();
    $r = $servicio->misPagos(['id' => 4, 'rol' => Roles::CLIENTE]);
    afirmar($r['totalPagado'] === '32.25' && $r['hayMas'] === false, 'total pagado: ' . $r['totalPagado']);
    $codigos = array_column($r['pagos'], 'codigoSeguimiento');
    afirmar(in_array($codigo, $codigos, true) && in_array($ilo, $codigos, true) && !in_array($paquete, $codigos, true), 've su envío y el que recibe, no el que pagó Luis');
    afirmar(count($r['pagos']) === 3, 'incluye el pago anulado (3 filas)');
    afirmar($r['pagos'][0]['codigoSeguimiento'] === $ilo && $r['pagos'][0]['pagadoPor'] === 'DESTINATARIO' && $r['pagos'][0]['monto'] === '8.50' && $r['pagos'][0]['sucursal']['nombre'] === 'Agencia Moquegua', 'el más reciente primero, con sucursal y pagador');
    $estados = array_column($r['pagos'], 'estado');
    sort($estados);
    afirmar($estados === ['ANULADO', 'REGISTRADO', 'REGISTRADO'], 'el anulado se muestra pero no suma');
});

prueba('mis pagos: el destinatario que pagó en destino ve su pago; el remitente de ese envío no', function () {
    [$servicio, , , , , $paquete] = escenarioMisPagos();
    $luis = $servicio->misPagos(['id' => 7, 'rol' => Roles::CLIENTE]);
    afirmar(array_column($luis['pagos'], 'codigoSeguimiento') === [$paquete] && $luis['totalPagado'] === '14.60', 'Luis ve el paquete que pagó en Arequipa');
    afirmar($luis['pagos'][0]['sucursal']['codigo'] === 'AQP001' && $luis['pagos'][0]['metodoPago'] === 'TARJETA', 'sucursal y método');
});

prueba('mis pagos: no expone documentos ni teléfonos; sin cuenta → 404; sin pagos → lista vacía', function () {
    [$servicio] = escenarioMisPagos();
    $json = json_encode($servicio->misPagos(['id' => 4, 'rol' => Roles::CLIENTE]));
    foreach (['71889345', '70234561', '987654', 'numeroDocumento', 'telefono'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "no debe exponer $prohibido");
    }
    esperarError(fn () => $servicio->misPagos(['id' => 999, 'rol' => Roles::CLIENTE]), 404, 'cuenta_no_encontrada');
    $vacio = $servicio->misPagos(['id' => 5, 'rol' => Roles::EMPRESARIO]); // la empresa no ha pagado nada
    afirmar($vacio['pagos'] === [] && $vacio['totalPagado'] === '0.00', 'sin pagos');
});

// ── HTTP: permisos, controladores y rutas ───────────────────────────────────
prueba('permisos de caja y mis pagos: caja solo ADMINISTRADOR; mis pagos solo CLIENTE y EMPRESARIO; sin sesión 401', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3),
        registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE), registro(5, 'empresa1', 'ClaveEmpresa#1', Roles::EMPRESARIO)]);
    $servicioAuth = new AuthService($auth);
    $soloAdmin = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicioAuth)); // igual que en rutas.php
    $soloClientes = new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO], new RequiereSesion($servicioAuth));

    esperarError(fn () => $soloAdmin(peticion('GET', '/api/caja/pagos')), 401, 'no_autenticado');
    esperarError(fn () => $soloClientes(peticion('GET', '/api/pagos')), 401, 'no_autenticado');
    foreach ([4, 5, 1] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $soloAdmin(peticion('POST', '/api/caja/pagos')), 403, 'acceso_denegado');
    }
    foreach ([2, 1] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $soloClientes(peticion('GET', '/api/pagos')), 403, 'acceso_denegado');
    }
    foreach ([4, 5] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        $soloClientes(peticion('GET', '/api/pagos'));
    }
    $limpiar();
    sesionDe($auth, $servicioAuth, 2);
    $soloAdmin(peticion('POST', '/api/caja/pagos'));
    $limpiar();
});

prueba('controladores de pagos: usan la sucursal y el usuario de la sesión, no los del cuerpo', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE)]);
    $servicioAuth = new AuthService($auth);
    [, , $repo, $servicio, $codigo] = escenarioPagos();
    $c = new PagoController($servicio);

    sesionDe($auth, $servicioAuth, 2);
    $r = leer($c->envioParaCobro(peticion('GET', '/x'), ['codigo' => $codigo]));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['saldo'] === '23.75', 'envío a cobrar');
    $r = leer($c->registrar(peticion('POST', '/x', json_encode(cobro($codigo, '23.75') + ['idSucursal' => 1, 'sucursal' => 'LIM001'])), []));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data']['pago']['sucursal']['codigo'] === 'MOQ001' && $r['cuerpo']['data']['envio']['saldo'] === '0.00', 'registra con 201 en la caja de la sesión');
    $id = $r['cuerpo']['data']['pago']['id'];
    $r = leer($c->cobrados(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && count($r['cuerpo']['data']['pagos']) === 1 && $r['cuerpo']['data']['totales']['total'] === '23.75', 'cobrados');
    $r = leer($c->anular(peticion('POST', '/x'), ['id' => (string) $id]));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['pago']['estado'] === 'ANULADO', 'anula');
    esperarError(fn () => $c->registrar(peticion('POST', '/x', '{roto'), []), 400, 'json_invalido');
    esperarError(fn () => $c->registrar(peticion('POST', '/x', json_encode(cobro($codigo, '0'))), []), 422, 'datos_invalidos');

    $limpiar();
    sesionDe($auth, $servicioAuth, 4);
    $r = leer($c->misPagos(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['totalPagado'] === '0.00', 'mis pagos (María no pagó nada todavía)');
    $limpiar();
    esperarError(fn () => $c->misPagos(peticion('GET', '/x'), []), 401, 'no_autenticado');
    esperarError(fn () => $c->registrar(peticion('POST', '/x', json_encode(cobro($codigo, '1'))), []), 401, 'no_autenticado');
});

prueba('rutas de pagos: todas exigen sesión y solo admiten su método', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Pagos/rutas.php')($router);
    foreach ([['GET', '/api/pagos'], ['GET', '/api/caja/pagos'], ['POST', '/api/caja/pagos'], ['POST', '/api/caja/pagos/12/anular'], ['GET', '/api/caja/envios/ENABCDEFGH23']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    foreach ([['DELETE', '/api/caja/pagos/12'], ['PUT', '/api/caja/pagos'], ['GET', '/api/caja/pagos/12/anular'], ['POST', '/api/pagos']] as [$metodo, $ruta]) {
        $e = null;
        try {
            $router->despachar(peticion($metodo, $ruta));
        } catch (HttpException $e) {
        }
        afirmar($e !== null && in_array($e->estado, [404, 405], true), "$metodo $ruta no debe existir");
    }
    esperarError(fn () => $router->despachar(peticion('DELETE', '/api/caja/pagos')), 405, 'metodo_no_permitido'); // un pago no se borra
});
