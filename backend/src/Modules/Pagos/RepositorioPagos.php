<?php
declare(strict_types=1);

namespace Jatsa\Modules\Pagos;

/** Acceso a Pago (y a lo que hace falta leer de Envio, Bulto, Reserva, Persona y Sucursal para cobrar). */
interface RepositorioPagos
{
    /**
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, idSucursalOrigen, idSucursalDestino,
     * origen{codigo, nombre}, destino{codigo, nombre}, remitente, destinatario (solo nombres), importe y
     * pagado (cadenas con 2 decimales), pagos (lista de pagos, el más reciente primero).
     */
    public function buscarEnvioParaCobro(string $codigoSeguimiento): ?array;

    /**
     * Registra un pago en UNA transacción. Primero bloquea la fila del envío (UPDLOCK + HOLDLOCK): dos cobros
     * @param callable(array<string, mixed>): void $decidir Recibe idEnvio, estado, idSucursalOrigen,
     * idSucursalDestino, importe y pagado (estos dos en céntimos, enteros).
     * @return int|null idPago, o null si el envío no existe.
     */
    public function registrar(string $codigoSeguimiento, int $idSucursal, string $monto, string $metodoPago, string $pagadoPor, callable $decidir): ?int;

    /** @return array<string, mixed>|null */
    public function buscarPago(int $idPago): ?array;

    /**
     * Anula un pago en UNA transacción. Primero bloquea el ENVÍO del pago (UPDLOCK + HOLDLOCK, el mismo bloqueo que toman
     * @param callable(array<string, mixed>): void $decidir Recibe ['pago' => {idPago, idSucursal, estadoPago}, 'estadoEnvio' => string].
     * @return bool|null null si el pago no existe; false si el UPDATE no cambió ninguna fila (otra caja se adelantó).
     */
    public function anular(int $idPago, int $idSucursal, callable $decidir): ?bool;

    /** @return list<array<string, mixed>> */
    public function listarDeSucursal(int $idSucursal, int $limite): array;

    /** @return array<string, int> */
    public function totalesDeSucursal(int $idSucursal): array;

    /** @return list<array<string, mixed>> */
    public function listarDePersona(int $idPersona, int $limite): array;

    public function totalPagadoDePersona(int $idPersona): int;
}
