<?php
declare(strict_types=1);

namespace Jatsa\Modules\Pagos;

use Jatsa\Modules\Reservas\RepositorioReservas;
use Jatsa\Modules\Reservas\ReservaRepository;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/**
 * Pagos y Caja (diapositivas 20 y 16 y proceso de pago del informe).
 *
 * - El ADMINISTRADOR cobra en la caja de SU sucursal los envíos de los que es origen o destino (el pago en destino se
 *   registra en la agencia de destino), consulta lo cobrado allí y anula un pago con error. Un pago nunca se borra.
 * - El CLIENTE y el EMPRESARIO ven los pagos que ellos hicieron («Mis pagos»).
 *
 * El importe de un envío es la suma de sus bultos (no se guarda aparte). Saldo = importe − pagos REGISTRADO.
 * Las reglas se comprueban aquí con mensajes claros y VUELVEN A COMPROBARSE dentro de la transacción de
 * PagoRepository::registrar, que es la autoridad (el envío queda bloqueado mientras se decide).
 */
final class PagoService
{
    public const METODOS = ['EFECTIVO', 'TARJETA', 'BILLETERA', 'TRANSFERENCIA'];
    public const PAGADO_POR = ['REMITENTE', 'DESTINATARIO'];

    /** Máximo de pagos que devuelve una lista (los totales cuentan todos). */
    public const MAX_LISTA = 200;

    private readonly RepositorioPagos $pagos;
    private readonly RepositorioReservas $reservas;

    public function __construct(?RepositorioPagos $pagos = null, ?RepositorioReservas $reservas = null)
    {
        $this->pagos = $pagos ?? new PagoRepository();
        $this->reservas = $reservas ?? new ReservaRepository();
    }

    // ── Caja (ADMINISTRADOR) ────────────────────────────────────────────────

    /**
     * El envío a cobrar: importe, pagado, saldo y sus pagos. Visible para el personal de su sucursal de origen o de
     * destino; para otro personal, igual que si no existiera.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function envioParaCobro(array $usuario, string $codigoSeguimiento): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = strtoupper(trim($codigoSeguimiento));
        $envio = preg_match('/^EN[A-Z0-9]{10}$/', $codigo) === 1 ? $this->pagos->buscarEnvioParaCobro($codigo) : null;

        if ($envio === null || !self::esDeLaSucursal($envio, $sucursal['id'])) {
            throw self::envioNoEncontrado();
        }

        return self::presentarEnvio($envio, $sucursal['id']);
    }

    /**
     * Registra un pago en la caja de la sucursal de la sesión.
     *
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos codigoSeguimiento, monto, pagadoPor, metodoPago
     * @return array{pago: array<string, mixed>, envio: array<string, mixed>}
     */
    public function registrar(array $usuario, array $datos): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);

        $errores = [];
        $codigo = strtoupper(self::texto($datos, 'codigoSeguimiento'));
        if (preg_match('/^EN[A-Z0-9]{10}$/', $codigo) !== 1) {
            $errores['codigoSeguimiento'] = 'El código de seguimiento empieza con EN y tiene 12 caracteres.';
        }

        $centimos = self::montoEnCentimos($datos['monto'] ?? null);
        if ($centimos === null) {
            $errores['monto'] = 'Ingresa el monto en soles, con hasta 2 decimales.';
        } elseif ($centimos <= 0) {
            $errores['monto'] = 'El monto debe ser mayor que 0.';
        }

        $pagadoPor = strtoupper(self::texto($datos, 'pagadoPor'));
        if (!in_array($pagadoPor, self::PAGADO_POR, true)) {
            $errores['pagadoPor'] = 'Elige REMITENTE o DESTINATARIO.';
        }

        $metodo = strtoupper(self::texto($datos, 'metodoPago'));
        if (!in_array($metodo, self::METODOS, true)) {
            $errores['metodoPago'] = 'Elige EFECTIVO, TARJETA, BILLETERA o TRANSFERENCIA.';
        }

        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los datos del pago.', [], $errores);
        }

        $decidir = static function (array $estado) use ($sucursal, $centimos): void {
            if (!self::esDeLaSucursal($estado, $sucursal['id'])) {
                throw self::envioNoEncontrado();
            }
            if ($estado['estado'] === 'ANULADO') {
                throw new HttpException(409, 'envio_anulado', 'El envío está anulado: no admite pagos.');
            }
            // Un envío ya cerrado no admite cobros (los reembolsos y devoluciones de dinero están fuera del alcance del sistema).
            if ($estado['estado'] === 'ENTREGADO') {
                throw new HttpException(409, 'envio_entregado', 'El envío ya fue entregado: no admite más pagos.');
            }
            if ($estado['estado'] === 'DEVUELTO') {
                throw new HttpException(409, 'envio_devuelto', 'El envío fue devuelto: no admite pagos.');
            }
            $saldo = $estado['importe'] - $estado['pagado'];
            if ($saldo <= 0) {
                throw new HttpException(409, 'envio_pagado', 'El envío ya está pagado por completo.');
            }
            if ($centimos > $saldo) {
                throw new HttpException(422, 'datos_invalidos', 'Revisa los datos del pago.', [], [
                    'monto' => sprintf('El monto supera el saldo del envío (S/ %s).', Tarifas::formatoSoles($saldo)),
                ]);
            }
        };

        $idPago = $this->pagos->registrar($codigo, $sucursal['id'], Tarifas::formatoSoles((int) $centimos), $metodo, $pagadoPor, $decidir);
        if ($idPago === null) {
            throw self::envioNoEncontrado();
        }

        $pago = $this->pagos->buscarPago($idPago) ?? throw new HttpException(404, 'pago_no_encontrado', 'No se encontró el pago.');
        $envio = $this->pagos->buscarEnvioParaCobro($codigo) ?? throw self::envioNoEncontrado();

        return ['pago' => self::presentarPago($pago), 'envio' => self::presentarEnvio($envio, $sucursal['id'])];
    }

    /**
     * Anula un pago cobrado en esta sucursal (un pago con error se anula, no se borra). Un pago de otra sucursal o
     * inexistente: el mismo 404.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function anular(array $usuario, string $id): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        if (preg_match('/^[1-9]\d{0,8}$/', $id) !== 1) {
            throw self::pagoNoEncontrado();
        }

        // Las reglas se aplican DENTRO de la transacción, con el envío bloqueado: así la entrega y la anulación no se cruzan
        // (un envío ENTREGADO nunca queda con saldo por una anulación posterior).
        $decidir = static function (array $contexto) use ($sucursal): void {
            if ($contexto['pago']['idSucursal'] !== $sucursal['id']) {
                throw self::pagoNoEncontrado(); // el pago de otra caja es, para esta, como si no existiera
            }
            if ($contexto['pago']['estadoPago'] === 'ANULADO') {
                throw new HttpException(409, 'pago_anulado', 'El pago ya estaba anulado.');
            }
            if ($contexto['estadoEnvio'] === 'ENTREGADO') {
                throw new HttpException(409, 'envio_entregado', 'El envío ya fue entregado: sus pagos ya no se anulan. Los reembolsos no se gestionan en este sistema.');
            }
            if ($contexto['estadoEnvio'] === 'DEVUELTO') {
                throw new HttpException(409, 'envio_devuelto', 'El envío fue devuelto: sus pagos ya no se anulan. Los reembolsos no se gestionan en este sistema.');
            }
        };

        $hecho = $this->pagos->anular((int) $id, $sucursal['id'], $decidir);
        if ($hecho === null) {
            throw self::pagoNoEncontrado();
        }
        if ($hecho === false) {
            throw new HttpException(409, 'pago_anulado', 'El pago ya estaba anulado.'); // el UPDATE condicional no cambió nada: otra caja se adelantó
        }

        $anulado = $this->pagos->buscarPago((int) $id) ?? throw self::pagoNoEncontrado();

        return self::presentarPago($anulado);
    }

    /**
     * «Pagos cobrados aquí»: los de la sucursal de la sesión, el más reciente primero, y los totales por método
     * (solo pagos REGISTRADO, de todos los pagos de la sucursal aunque la lista se acote).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function cobradosEnLaSucursal(array $usuario): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $filas = $this->pagos->listarDeSucursal($sucursal['id'], self::MAX_LISTA + 1);
        $hayMas = count($filas) > self::MAX_LISTA;
        $porMetodo = $this->pagos->totalesDeSucursal($sucursal['id']);

        $totales = [];
        $suma = 0;
        foreach (self::METODOS as $metodo) {
            $centimos = $porMetodo[$metodo] ?? 0;
            $totales[$metodo] = Tarifas::formatoSoles($centimos);
            $suma += $centimos;
        }
        $totales['total'] = Tarifas::formatoSoles($suma);

        return [
            'sucursal' => ['codigo' => $sucursal['codigo'], 'nombre' => $sucursal['nombre']],
            'pagos' => array_map([self::class, 'presentarPago'], array_slice($filas, 0, self::MAX_LISTA)),
            'hayMas' => $hayMas,
            'totales' => $totales,
        ];
    }

    // ── Mis pagos (CLIENTE / EMPRESARIO) ────────────────────────────────────

    /**
     * Los pagos que hizo la persona de la sesión y el total pagado (solo REGISTRADO).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function misPagos(array $usuario): array
    {
        $idPersona = $this->reservas->idPersonaDeUsuario((int) ($usuario['id'] ?? 0));
        if ($idPersona === null) {
            throw new HttpException(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.');
        }

        $filas = $this->pagos->listarDePersona($idPersona, self::MAX_LISTA + 1);

        return [
            'pagos' => array_map([self::class, 'presentarPago'], array_slice($filas, 0, self::MAX_LISTA)),
            'hayMas' => count($filas) > self::MAX_LISTA,
            'totalPagado' => Tarifas::formatoSoles($this->pagos->totalPagadoDePersona($idPersona)),
        ];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * El personal solo cobra envíos de su sucursal de origen o de destino.
     *
     * @param array<string, mixed> $envio
     */
    private static function esDeLaSucursal(array $envio, int $idSucursal): bool
    {
        return in_array($idSucursal, [$envio['idSucursalOrigen'], $envio['idSucursalDestino']], true);
    }

    private static function pagoNoEncontrado(): HttpException
    {
        return new HttpException(404, 'pago_no_encontrado', 'No se encontró el pago en esta caja.');
    }

    private static function envioNoEncontrado(): HttpException
    {
        return new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío.');
    }

    /**
     * «23.75», «23,75», 23.75 o 24 → céntimos. Null si no es un monto en soles con hasta 2 decimales. Sin coma flotante.
     */
    private static function montoEnCentimos(mixed $monto): ?int
    {
        if (is_int($monto) || is_float($monto)) {
            $monto = (string) $monto;
        }
        if (!is_string($monto)) {
            return null;
        }
        $monto = str_replace(',', '.', trim($monto));
        if (preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $monto, $partes) !== 1) {
            return null;
        }

        return (int) $partes[1] * 100 + (int) str_pad($partes[2] ?? '', 2, '0');
    }

    /**
     * @param array<string, mixed> $envio
     * @return array<string, mixed>
     */
    private static function presentarEnvio(array $envio, int $idSucursal): array
    {
        $importe = self::aCentimos($envio['importe']);
        $pagado = self::aCentimos($envio['pagado']);
        // Pago en destino: si el cajero está en la agencia de destino (y no en la de origen) lo habitual es que pague el destinatario.
        $enDestino = $idSucursal === $envio['idSucursalDestino'] && $idSucursal !== $envio['idSucursalOrigen'];

        return [
            'codigoSeguimiento' => $envio['codigoSeguimiento'],
            'estado' => $envio['estado'],
            'origen' => $envio['origen'],
            'destino' => $envio['destino'],
            'remitente' => $envio['remitente'],
            'destinatario' => $envio['destinatario'],
            'importe' => Tarifas::formatoSoles($importe),
            'pagado' => Tarifas::formatoSoles($pagado),
            'saldo' => Tarifas::formatoSoles(max(0, $importe - $pagado)),
            'pagadoPorSugerido' => $enDestino ? 'DESTINATARIO' : 'REMITENTE',
            'pagos' => array_map([self::class, 'presentarPago'], $envio['pagos']),
        ];
    }

    /**
     * @param array<string, mixed> $pago
     * @return array<string, mixed>
     */
    private static function presentarPago(array $pago): array
    {
        return [
            'id' => $pago['idPago'],
            'fechaHora' => $pago['fechaHoraPago'],
            'codigoSeguimiento' => $pago['codigoSeguimiento'],
            'monto' => Tarifas::formatoSoles(self::aCentimos($pago['monto'])),
            'metodoPago' => $pago['metodoPago'],
            'pagadoPor' => $pago['pagadoPor'],
            'estado' => $pago['estadoPago'],
            'sucursal' => ['codigo' => $pago['sucursalCodigo'], 'nombre' => $pago['sucursalNombre']],
        ];
    }

    private static function aCentimos(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }
}
