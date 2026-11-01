<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 8 (Llegadas y entregas) con un repositorio simulado, sin base de datos.
 * Se incluye desde ejecutar.php, que aporta el mini marco (prueba, afirmar, esperarError…) y los ayudantes de sesión.
 */

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Entregas\EntregaController;
use Jatsa\Modules\Entregas\EntregaService;
use Jatsa\Modules\Entregas\RepositorioEntregas;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/**
 * Envíos y viajes en memoria. Reproduce lo que hace el SQL real: «bloquea» el envío, lee estado, destinatario y lo pagado,
 * llama a $decidir (que puede abortar sin escribir) y solo entonces cambia el estado.
 */
final class EntregasFalso implements RepositorioEntregas
{
    public const SUCURSALES = ['LIM001' => 1, 'AQP001' => 2, 'MOQ001' => 3, 'ILO001' => 4];
    private const NOMBRES = ['LIM001' => 'Agencia Lima Centro', 'AQP001' => 'Agencia Arequipa Centro', 'MOQ001' => 'Agencia Moquegua', 'ILO001' => 'Agencia Ilo'];

    /** @var array<string, array<string, mixed>> */
    public array $envios = [];
    /** @var list<array<string, mixed>> */
    public array $viajes = [];
    public string $ahora = '2026-10-31 09:30:00';
    public int $transaccionesDeshechas = 0;
    public ?Closure $antesDeDecidir = null;

    public function envio(string $codigo, string $destino, string $estado, string $tipoDoc, string $numeroDoc, string $nombre, string $importe, string $pagado, string $origen = 'ILO001', string $tipoEntrega = 'AGENCIA', ?string $direccion = null): void
    {
        $this->envios[$codigo] = ['idEnvio' => count($this->envios) + 1, 'codigo' => $codigo, 'destino' => $destino, 'origen' => $origen, 'estado' => $estado,
            'doc' => [$tipoDoc, $numeroDoc, $nombre], 'importe' => $importe, 'pagado' => $pagado, 'tipoEntrega' => $tipoEntrega, 'direccion' => $direccion, 'entrega' => null,
            'bultos' => [['numero' => 1, 'descripcion' => 'Sobre con documentos', 'pesoKg' => '0.60']]];
    }

    private function sucursal(string $c): array
    {
        return ['codigo' => $c, 'nombre' => self::NOMBRES[$c]];
    }

    public function viajesEnCamino(int $idSucursal, string $hoy): array
    {
        $r = [];
        foreach ($this->viajes as $v) {
            if (self::SUCURSALES[$v['destino']] === $idSucursal && $v['salida'] !== null && $v['llegada'] === null) {
                $r[] = ['codigo' => $v['codigo'], 'origen' => $this->sucursal($v['origen']), 'destino' => $this->sucursal($v['destino']), 'fechaHoraSalida' => $v['salida'], 'cantidadEnvios' => $v['envios'], 'horas' => $v['horas']];
            }
        }

        return $r;
    }

    public function listosParaEntregar(int $idSucursal, int $limite): array
    {
        $r = [];
        foreach ($this->envios as $e) {
            if ($e['estado'] === 'EN_DESTINO' && self::SUCURSALES[$e['destino']] === $idSucursal) {
                $r[] = ['codigoSeguimiento' => $e['codigo'], 'destinatario' => $e['doc'][2], 'tipoEntrega' => $e['tipoEntrega'], 'bultos' => count($e['bultos']),
                    'pesoKg' => $e['bultos'][0]['pesoKg'], 'importe' => $e['importe'], 'pagado' => $e['pagado']];
            }
        }

        return array_slice($r, 0, $limite);
    }

    public function buscarEnvio(string $codigoSeguimiento): ?array
    {
        $e = $this->envios[$codigoSeguimiento] ?? null;
        if ($e === null) {
            return null;
        }

        return ['codigoSeguimiento' => $e['codigo'], 'estado' => $e['estado'], 'tipoEntrega' => $e['tipoEntrega'], 'direccionEntrega' => $e['direccion'], 'fechaHoraEntrega' => $e['entrega'],
            'idSucursalDestino' => self::SUCURSALES[$e['destino']], 'origen' => $this->sucursal($e['origen']), 'destino' => $this->sucursal($e['destino']),
            'destinatario' => ['tipoDocumento' => $e['doc'][0], 'numeroDocumento' => $e['doc'][1], 'nombreCompleto' => $e['doc'][2]],
            'bultos' => $e['bultos'], 'importe' => $e['importe'], 'pagado' => $e['pagado']];
    }

    private function cambiar(string $codigo, callable $decidir, string $nuevo): ?bool
    {
        if (!isset($this->envios[$codigo])) {
            return null;
        }
        if ($this->antesDeDecidir !== null) {
            $hook = $this->antesDeDecidir;
            $this->antesDeDecidir = null;
            $hook($this);
        }
        $e = $this->envios[$codigo];
        try {
            $decidir(['envio' => ['idEnvio' => $e['idEnvio'], 'estado' => $e['estado'], 'idSucursalDestino' => self::SUCURSALES[$e['destino']],
                'destinatario' => ['tipoDocumento' => $e['doc'][0], 'numeroDocumento' => $e['doc'][1]],
                'importe' => (int) round(((float) $e['importe']) * 100), 'pagado' => (int) round(((float) $e['pagado']) * 100)]]);
        } catch (Throwable $t) {
            $this->transaccionesDeshechas++;
            throw $t;
        }
        if ($this->envios[$codigo]['estado'] !== 'EN_DESTINO') {
            return false;
        }
        $this->envios[$codigo]['estado'] = $nuevo;
        if ($nuevo === 'ENTREGADO') {
            $this->envios[$codigo]['entrega'] = $this->ahora;
        }

        return true;
    }

    public function entregar(string $codigoSeguimiento, callable $decidir): ?bool
    {
        return $this->cambiar($codigoSeguimiento, $decidir, 'ENTREGADO');
    }

    public function devolver(string $codigoSeguimiento, callable $decidir): ?bool
    {
        return $this->cambiar($codigoSeguimiento, $decidir, 'DEVUELTO');
    }
}

/**
 * Escenario de la diapositiva 22 en MOQ001.
 *
 * @return array{0: EntregasFalso, 1: EntregaService}
 */
function escenarioEntregas(): array
{
    $r = new EntregasFalso();
    $r->envio('ENM2P6T8R1WQ', 'MOQ001', 'EN_DESTINO', 'DNI', '71889345', 'María Elena Rojas Ccori', '8.50', '8.50');
    $r->envio('ENR5T6Y7U8I9', 'MOQ001', 'EN_DESTINO', 'RUC', '20123456789', 'Comercial Sur S.A.C.', '14.60', '0.00');
    $r->envio('ENDOMICILIO1', 'MOQ001', 'EN_DESTINO', 'CE', 'AB123456', 'Carlos Pérez Díaz', '9.15', '9.15', 'AQP001', 'DOMICILIO', 'Av. Siempre Viva 742');
    $r->envio('ENTRANSITO01', 'MOQ001', 'EN_TRANSITO', 'DNI', '70234561', 'Luis Alberto Flores Condori', '9.15', '9.15');
    $r->envio('ENOTRAAGENC1', 'AQP001', 'EN_DESTINO', 'DNI', '72004518', 'Pedro Mamani Huanca', '8.50', '8.50');
    $r->envio('ENYAENTREGA1', 'MOQ001', 'ENTREGADO', 'DNI', '45781236', 'Rosa María Quispe Mamani', '8.50', '8.50');
    $r->envios['ENYAENTREGA1']['entrega'] = '2026-10-12 11:30:00';
    $r->envio('ENDEVUELTO01', 'MOQ001', 'DEVUELTO', 'DNI', '45781236', 'Rosa María Quispe Mamani', '8.50', '8.50');
    $r->envio('ENANULADO001', 'MOQ001', 'ANULADO', 'DNI', '45781236', 'Rosa María Quispe Mamani', '8.50', '0.00');
    $r->viajes = [
        ['codigo' => 'VJ2026103104', 'origen' => 'ILO001', 'destino' => 'MOQ001', 'salida' => '2026-10-31 07:15:00', 'llegada' => null, 'envios' => 2, 'horas' => 2],
        ['codigo' => 'VJ2026103105', 'origen' => 'MOQ001', 'destino' => 'AQP001', 'salida' => '2026-10-31 07:00:00', 'llegada' => null, 'envios' => 1, 'horas' => 4],   // sale de aquí: no viene hacia aquí
        ['codigo' => 'VJ2026103106', 'origen' => 'ILO001', 'destino' => 'MOQ001', 'salida' => null, 'llegada' => null, 'envios' => 1, 'horas' => 2],                       // programado: aún no sale
        ['codigo' => 'VJ2026103107', 'origen' => 'ILO001', 'destino' => 'MOQ001', 'salida' => '2026-10-30 07:00:00', 'llegada' => '2026-10-30 09:00:00', 'envios' => 1, 'horas' => 2], // ya llegó
    ];

    return [$r, new EntregaService($r, static fn (): string => '2026-10-31')];
}

// ── Llegadas y entregas: lo pendiente (diapositiva 22) ──────────────────────
prueba('entregas: «en camino hacia aquí» muestra solo los viajes en ruta con destino la agencia y su llegada aproximada (09:15)', function () {
    [, $servicio] = escenarioEntregas();
    $p = $servicio->pendientes(personal());
    afirmar(array_column($p['enCamino'], 'codigo') === ['VJ2026103104'], 'viajes: ' . implode(',', array_column($p['enCamino'], 'codigo')));
    $v = $p['enCamino'][0];
    afirmar($v['origen']['nombre'] === 'Agencia Ilo' && $v['destino']['nombre'] === 'Agencia Moquegua' && $v['llegadaEstimada'] === '2026-10-31 09:15:00' && $v['cantidadEnvios'] === 2, 'Ilo → Moquegua · llega aprox. 09:15');
    afirmar($p['sucursal']['codigo'] === 'MOQ001', 'sucursal de la sesión');
});

prueba('entregas: «listos para entregar» son solo los EN_DESTINO de la agencia, con su saldo; sin documentos en la lista', function () {
    [, $servicio] = escenarioEntregas();
    $listos = array_column($servicio->pendientes(personal())['listos'], null, 'codigoSeguimiento');
    afirmar(array_keys($listos) === ['ENM2P6T8R1WQ', 'ENR5T6Y7U8I9', 'ENDOMICILIO1'], 'listos: ' . implode(',', array_keys($listos)));
    afirmar($listos['ENM2P6T8R1WQ']['destinatario'] === 'María Elena Rojas Ccori' && $listos['ENM2P6T8R1WQ']['saldo'] === '0.00', 'María: saldo 0.00');
    afirmar($listos['ENR5T6Y7U8I9']['destinatario'] === 'Comercial Sur S.A.C.' && $listos['ENR5T6Y7U8I9']['saldo'] === '14.60' && $listos['ENR5T6Y7U8I9']['pagado'] === '0.00', 'Comercial Sur: saldo 14.60');
    afirmar($listos['ENDOMICILIO1']['tipoEntrega'] === 'DOMICILIO', 'domicilio');
    $json = json_encode($servicio->pendientes(personal())['listos']);
    foreach (['71889345', '20123456789', 'numeroDocumento'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "la lista no debe traer $prohibido");
    }
    afirmar(array_column($servicio->pendientes(personal(2, 'AQP001'))['listos'], 'codigoSeguimiento') === ['ENOTRAAGENC1'], 'cada agencia ve los suyos');
});

prueba('entregas: el envío a atender muestra el documento del destinatario para el personal, sus bultos y el saldo; otra agencia = mismo 404 que inexistente', function () {
    [, $servicio] = escenarioEntregas();
    $e = $servicio->detalle(personal(), strtolower('ENM2P6T8R1WQ'));
    afirmar($e['estado'] === 'EN_DESTINO' && $e['destinatario'] === ['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345', 'nombreCompleto' => 'María Elena Rojas Ccori'], 'destinatario registrado (DNI 71889345)');
    afirmar($e['cantidadBultos'] === 1 && $e['pesoTotalKg'] === '0.60' && $e['bultos'][0]['descripcion'] === 'Sobre con documentos' && $e['pagado'] === '8.50' && $e['saldo'] === '0.00', '1 bulto · 0.60 kg · pagado 8.50 · saldo 0.00');
    afirmar($servicio->detalle(personal(), 'ENDOMICILIO1')['direccionEntrega'] === 'Av. Siempre Viva 742', 'dirección de entrega a domicilio');
    afirmar($servicio->detalle(personal(), 'ENYAENTREGA1')['fechaHoraEntrega'] === '2026-10-12 11:30:00', 'un envío ya entregado se puede consultar');
    $otra = esperarError(fn () => $servicio->detalle(personal(), 'ENOTRAAGENC1'), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->detalle(personal(), 'ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->detalle(personal(), "EN'; DROP"), 404, 'envio_no_encontrado');
    afirmar($otra->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
});

// ── Confirmar la entrega ────────────────────────────────────────────────────
prueba('entregar: con el documento que coincide queda ENTREGADO con la fecha y hora de la base; acepta espacios, guiones y minúsculas', function () {
    [$r, $servicio] = escenarioEntregas();
    $e = $servicio->entregar(personal(), 'ENM2P6T8R1WQ', ['tipoDocumento' => 'dni', 'numeroDocumento' => ' 7188-9345 ']);
    afirmar($e['estado'] === 'ENTREGADO' && $e['fechaHoraEntrega'] === '2026-10-31 09:30:00', 'estado y fecha: ' . $e['estado'] . ' ' . ($e['fechaHoraEntrega'] ?? 'null'));
    afirmar($r->envios['ENM2P6T8R1WQ']['entrega'] === '2026-10-31 09:30:00', 'guardada');
    afirmar(!in_array('ENM2P6T8R1WQ', array_column($servicio->pendientes(personal())['listos'], 'codigoSeguimiento'), true), 'sale de «listos»');
    $r->ahora = '2026-10-31 10:00:00';
    $ce = $servicio->entregar(personal(), 'ENDOMICILIO1', ['tipoDocumento' => 'CE', 'numeroDocumento' => 'ab-123456']); // CE en minúsculas se guarda en mayúsculas
    afirmar($ce['estado'] === 'ENTREGADO' && $ce['fechaHoraEntrega'] === '2026-10-31 10:00:00', 'CE normalizado');
});

prueba('entregar: documento que no coincide (número o tipo) → 422 sin escribir; no revela el documento registrado', function () {
    [$r, $servicio] = escenarioEntregas();
    foreach ([['DNI', '71889346'], ['RUC', '20123456789'], ['CE', 'AB123456']] as [$tipo, $numero]) {
        $e = esperarError(fn () => $servicio->entregar(personal(), 'ENM2P6T8R1WQ', ['tipoDocumento' => $tipo, 'numeroDocumento' => $numero]), 422, 'datos_invalidos');
        afirmar(isset($e->campos['numeroDocumento']) && !str_contains(json_encode($e->campos) . $e->getMessage(), '71889345'), "$tipo $numero: no coincide y no revela");
    }
    afirmar($r->envios['ENM2P6T8R1WQ']['estado'] === 'EN_DESTINO' && $r->envios['ENM2P6T8R1WQ']['entrega'] === null && $r->transaccionesDeshechas === 3, 'sin cambios y transacciones deshechas');
});

prueba('entregar: documento mal formado o campos con tipos raros → 422 (nunca un error interno) antes de tocar nada', function () {
    [$r, $servicio] = escenarioEntregas();
    foreach ([['XX', '71889345', 'tipoDocumento'], ['DNI', '1234567', 'numeroDocumento'], ['RUC', '2012345678', 'numeroDocumento'], ['CE', '12', 'numeroDocumento'], ['DNI', '', 'numeroDocumento'], [['DNI'], '71889345', 'tipoDocumento'], ['DNI', ['71889345'], 'numeroDocumento'], [null, null, 'tipoDocumento']] as [$tipo, $numero, $campo]) {
        $e = esperarError(fn () => $servicio->entregar(personal(), 'ENM2P6T8R1WQ', ['tipoDocumento' => $tipo, 'numeroDocumento' => $numero]), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$campo]), 'marca ' . $campo);
    }
    esperarError(fn () => $servicio->entregar(personal(), 'ENM2P6T8R1WQ', []), 422, 'datos_invalidos');
    afirmar($r->transaccionesDeshechas === 0 && $r->envios['ENM2P6T8R1WQ']['estado'] === 'EN_DESTINO', 'ni siquiera se abrió la transacción');
});

prueba('entregar: con saldo pendiente → 409 con el monto (hay que cobrar en Caja); una vez pagado, se entrega a la empresa con su RUC', function () {
    [$r, $servicio] = escenarioEntregas();
    $e = esperarError(fn () => $servicio->entregar(personal(), 'ENR5T6Y7U8I9', ['tipoDocumento' => 'RUC', 'numeroDocumento' => '20123456789']), 409, 'saldo_pendiente');
    afirmar(str_contains($e->getMessage(), '14.60') && str_contains($e->getMessage(), 'Caja'), 'dice cuánto y dónde: ' . $e->getMessage());
    afirmar($r->envios['ENR5T6Y7U8I9']['estado'] === 'EN_DESTINO', 'no cambia');
    $r->envios['ENR5T6Y7U8I9']['pagado'] = '14.60'; // el cobro en Caja
    afirmar($servicio->entregar(personal(), 'ENR5T6Y7U8I9', ['tipoDocumento' => 'RUC', 'numeroDocumento' => '20123456789'])['estado'] === 'ENTREGADO', 'entregado');
});

prueba('entregar: si se cobra el saldo justo antes de confirmar, la decisión ya usa lo pagado (el envío bloqueado se lee al decidir)', function () {
    [$r, $servicio] = escenarioEntregas();
    $r->antesDeDecidir = function (EntregasFalso $falso): void { $falso->envios['ENR5T6Y7U8I9']['pagado'] = '14.60'; };
    afirmar($servicio->entregar(personal(), 'ENR5T6Y7U8I9', ['tipoDocumento' => 'RUC', 'numeroDocumento' => '20123456789'])['saldo'] === '0.00', 'entregado sin saldo');
});

prueba('entregar: estados que no se pueden entregar y envíos de otra agencia (409 / 404 igual que inexistente)', function () {
    [$r, $servicio] = escenarioEntregas();
    $dni = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '70234561'];
    $e = esperarError(fn () => $servicio->entregar(personal(), 'ENTRANSITO01', $dni), 409, 'envio_no_esta_en_destino');
    afirmar(str_contains($e->getMessage(), 'todavía no llegó'), 'en tránsito');
    $dniRosa = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '45781236'];
    esperarError(fn () => $servicio->entregar(personal(), 'ENYAENTREGA1', $dniRosa), 409, 'envio_entregado');
    esperarError(fn () => $servicio->entregar(personal(), 'ENDEVUELTO01', $dniRosa), 409, 'envio_devuelto');
    esperarError(fn () => $servicio->entregar(personal(), 'ENANULADO001', $dniRosa), 409, 'envio_anulado');
    $otra = esperarError(fn () => $servicio->entregar(personal(), 'ENOTRAAGENC1', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '72004518']), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->entregar(personal(), 'ENZZZZZZZZZZ', $dni), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->entregar(personal(), 'xx', $dni), 404, 'envio_no_encontrado');
    afirmar($otra->getMessage() === $inexistente->getMessage() && $r->envios['ENOTRAAGENC1']['estado'] === 'EN_DESTINO' && $r->envios['ENYAENTREGA1']['entrega'] === '2026-10-12 11:30:00', 'mismo mensaje y nada cambió');
    // La agencia de DESTINO sí puede: el personal de AQP001 entrega el suyo.
    afirmar($servicio->entregar(personal(2, 'AQP001'), 'ENOTRAAGENC1', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '72004518'])['estado'] === 'ENTREGADO', 'AQP001 entrega el suyo');
});

prueba('entregar: dos cajas a la vez — la segunda ve el envío ya entregado (409) y la fecha de la primera se conserva', function () {
    [$r, $servicio] = escenarioEntregas();
    $r->antesDeDecidir = function (EntregasFalso $falso): void { $falso->envios['ENM2P6T8R1WQ']['estado'] = 'ENTREGADO'; $falso->envios['ENM2P6T8R1WQ']['entrega'] = '2026-10-31 09:29:59'; };
    esperarError(fn () => $servicio->entregar(personal(), 'ENM2P6T8R1WQ', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345']), 409, 'envio_entregado');
    afirmar($r->envios['ENM2P6T8R1WQ']['entrega'] === '2026-10-31 09:29:59', 'conserva la fecha de la primera');
});

// ── Marcar devuelto ─────────────────────────────────────────────────────────
prueba('devolver: EN_DESTINO → DEVUELTO sin fecha de entrega; después ya no se puede entregar ni devolver otra vez', function () {
    [$r, $servicio] = escenarioEntregas();
    $e = $servicio->devolver(personal(), 'ENR5T6Y7U8I9'); // aun con saldo pendiente: no se entrega, se devuelve
    afirmar($e['estado'] === 'DEVUELTO' && $e['fechaHoraEntrega'] === null, 'DEVUELTO y sin fecha de entrega (CK_ENVIO_ENTREGA)');
    esperarError(fn () => $servicio->devolver(personal(), 'ENR5T6Y7U8I9'), 409, 'envio_devuelto');
    esperarError(fn () => $servicio->entregar(personal(), 'ENR5T6Y7U8I9', ['tipoDocumento' => 'RUC', 'numeroDocumento' => '20123456789']), 409, 'envio_devuelto');
    afirmar(!in_array('ENR5T6Y7U8I9', array_column($servicio->pendientes(personal())['listos'], 'codigoSeguimiento'), true), 'sale de «listos»');
});

prueba('devolver: un envío entregado, en tránsito, de otra agencia o inexistente no se puede devolver', function () {
    [$r, $servicio] = escenarioEntregas();
    esperarError(fn () => $servicio->devolver(personal(), 'ENYAENTREGA1'), 409, 'envio_entregado');
    esperarError(fn () => $servicio->devolver(personal(), 'ENTRANSITO01'), 409, 'envio_no_esta_en_destino');
    esperarError(fn () => $servicio->devolver(personal(), 'ENOTRAAGENC1'), 404, 'envio_no_encontrado');
    esperarError(fn () => $servicio->devolver(personal(), 'ENZZZZZZZZZZ'), 404, 'envio_no_encontrado');
    afirmar($r->envios['ENOTRAAGENC1']['estado'] === 'EN_DESTINO' && $r->envios['ENTRANSITO01']['estado'] === 'EN_TRANSITO', 'nada cambió');
});

prueba('devolver y entregar a la vez: gana uno solo (el segundo ve el estado ya cerrado)', function () {
    [$r, $servicio] = escenarioEntregas();
    $r->antesDeDecidir = function (EntregasFalso $falso): void { $falso->envios['ENM2P6T8R1WQ']['estado'] = 'DEVUELTO'; };
    esperarError(fn () => $servicio->entregar(personal(), 'ENM2P6T8R1WQ', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345']), 409, 'envio_devuelto');
    afirmar($r->envios['ENM2P6T8R1WQ']['entrega'] === null, 'sin fecha de entrega');
});

prueba('sin sucursal asignada → 403', function () {
    [, $servicio] = escenarioEntregas();
    esperarError(fn () => $servicio->pendientes(['id' => 9, 'rol' => Roles::ADMINISTRADOR, 'sucursal' => null]), 403, 'sin_sucursal');
});

// ── HTTP: permisos, controladores y rutas ───────────────────────────────────
prueba('permisos de entregas: solo ADMINISTRADOR (cliente, transportista y gerente 403; sin sesión 401)', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3),
        registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE), registro(6, 'transp1', 'ClaveTransp#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    $guardia = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicioAuth)); // igual que en rutas.php
    esperarError(fn () => $guardia(peticion('GET', '/api/entregas/pendientes')), 401, 'no_autenticado');
    foreach ([4, 6, 1] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $guardia(peticion('POST', '/api/entregas/envios/ENM2P6T8R1WQ/entrega')), 403, 'acceso_denegado');
    }
    $limpiar();
    sesionDe($auth, $servicioAuth, 2);
    $guardia(peticion('GET', '/api/entregas/pendientes'));
    $limpiar();
});

prueba('controladores: usan la sucursal de la sesión; entregar y devolver responden 200', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3)]);
    $servicioAuth = new AuthService($auth);
    [, $servicio] = escenarioEntregas();
    $c = new EntregaController($servicio);
    sesionDe($auth, $servicioAuth, 2);
    $r = leer($c->pendientes(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && count($r['cuerpo']['data']['listos']) === 3 && count($r['cuerpo']['data']['enCamino']) === 1, 'pendientes');
    $r = leer($c->detalle(peticion('GET', '/x'), ['codigo' => 'ENM2P6T8R1WQ']));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['destinatario']['numeroDocumento'] === '71889345', 'detalle');
    esperarError(fn () => $c->detalle(peticion('GET', '/x'), ['codigo' => 'ENOTRAAGENC1']), 404, 'envio_no_encontrado');
    $r = leer($c->entregar(peticion('POST', '/x', json_encode(['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345'])), ['codigo' => 'ENM2P6T8R1WQ']));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['estado'] === 'ENTREGADO', 'entregar');
    $r = leer($c->devolver(peticion('POST', '/x'), ['codigo' => 'ENDOMICILIO1']));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['envio']['estado'] === 'DEVUELTO', 'devolver');
    esperarError(fn () => $c->entregar(peticion('POST', '/x', '{roto'), ['codigo' => 'ENR5T6Y7U8I9']), 400, 'json_invalido');
    $limpiar();
    esperarError(fn () => $c->pendientes(peticion('GET', '/x'), []), 401, 'no_autenticado');
});

prueba('rutas de entregas: todas exigen sesión; /pendientes va antes que /envios/{codigo}; no hay forma de borrar', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Entregas/rutas.php')($router);
    foreach ([['GET', '/api/entregas/pendientes'], ['GET', '/api/entregas/envios/ENM2P6T8R1WQ'], ['POST', '/api/entregas/envios/ENM2P6T8R1WQ/entrega'], ['POST', '/api/entregas/envios/ENM2P6T8R1WQ/devolucion']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    foreach ([['DELETE', '/api/entregas/envios/ENM2P6T8R1WQ'], ['PUT', '/api/entregas/pendientes'], ['GET', '/api/entregas/envios/ENM2P6T8R1WQ/entrega']] as [$metodo, $ruta]) {
        $e = null;
        try {
            $router->despachar(peticion($metodo, $ruta));
        } catch (HttpException $e) {
        }
        afirmar($e !== null && in_array($e->estado, [404, 405], true), "$metodo $ruta no debe existir");
    }
});
