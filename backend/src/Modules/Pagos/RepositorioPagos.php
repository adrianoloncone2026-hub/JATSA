<?php
declare(strict_types=1);

namespace Jatsa\Modules\Pagos;

/**
 * Acceso a Pago (y a lo que hace falta leer de Envio, Bulto, Reserva, Persona y Sucursal para cobrar).
 *
 * Un pago es un arreglo con: idPago (int), fechaHoraPago, monto (cadena con 2 decimales), metodoPago, pagadoPor,
 * estadoPago, codigoSeguimiento, idSucursal (int), sucursalCodigo y sucursalNombre.
 */
interface RepositorioPagos
{
    /**
     * Envío con lo que hace falta para cobrarlo: importe (suma de sus bultos), lo ya pagado (solo pagos REGISTRADO)
     * y todos sus pagos. Solo lectura.
     *
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, idSucursalOrigen, idSucursalDestino,
     *         origen{codigo, nombre}, destino{codigo, nombre}, remitente, destinatario (solo nombres), importe y
     *         pagado (cadenas con 2 decimales), pagos (lista de pagos, el más reciente primero).
     */
    public function buscarEnvioParaCobro(string $codigoSeguimiento): ?array;

    /**
     * Registra un pago en UNA transacción. Primero bloquea la fila del envío (UPDLOCK + HOLDLOCK): dos cobros
     * simultáneos del mismo envío se ponen en fila y el segundo ya ve lo que pagó el primero. Con el bloqueo
     * tomado lee el importe y lo pagado y llama a $decidir, que aplica las reglas de negocio y lanza HttpException
     * si el pago no procede (y todo se deshace). Si procede, inserta el Pago y confirma.
     *
     * @param callable(array<string, mixed>): void $decidir Recibe idEnvio, estado, idSucursalOrigen,
     *        idSucursalDestino, importe y pagado (estos dos en céntimos, enteros).
     * @return int|null idPago, o null si el envío no existe.
     */
    public function registrar(string $codigoSeguimiento, int $idSucursal, string $monto, string $metodoPago, string $pagadoPor, callable $decidir): ?int;

    /** @return array<string, mixed>|null */
    public function buscarPago(int $idPago): ?array;

    /**
     * Anula un pago en UNA transacción. Primero bloquea el ENVÍO del pago (UPDLOCK + HOLDLOCK, el mismo bloqueo que toman
     * registrar() y la entrega del módulo 8: cobrar, anular y entregar quedan en fila), vuelve a leer el pago con el envío
     * bloqueado y llama a $decidir, que aplica las reglas y lanza HttpException si no procede (todo se deshace). Si procede,
     * hace el UPDATE condicional (sucursal y REGISTRADO). Un pago nunca se borra.
     *
     * @param callable(array<string, mixed>): void $decidir Recibe ['pago' => {idPago, idSucursal, estadoPago}, 'estadoEnvio' => string].
     * @return bool|null null si el pago no existe; false si el UPDATE no cambió ninguna fila (otra caja se adelantó).
     */
    public function anular(int $idPago, int $idSucursal, callable $decidir): ?bool;

    /**
     * Pagos cobrados en la sucursal, el más reciente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function listarDeSucursal(int $idSucursal, int $limite): array;

    /**
     * Suma de los pagos REGISTRADO de la sucursal por método de pago, en céntimos (solo los métodos con pagos).
     *
     * @return array<string, int>
     */
    public function totalesDeSucursal(int $idSucursal): array;

    /**
     * Pagos que hizo la persona: los de envíos donde es remitente y pagó el REMITENTE, y los de envíos donde es
     * destinataria y pagó el DESTINATARIO. El más reciente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function listarDePersona(int $idPersona, int $limite): array;

    /** Suma, en céntimos, de los pagos REGISTRADO que hizo la persona (mismo criterio que listarDePersona). */
    public function totalPagadoDePersona(int $idPersona): int;
}
