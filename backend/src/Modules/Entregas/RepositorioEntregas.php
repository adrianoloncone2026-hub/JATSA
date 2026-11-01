<?php
declare(strict_types=1);

namespace Jatsa\Modules\Entregas;

/**
 * Acceso a lo que el Administrador de la agencia de DESTINO necesita para recibir y entregar envíos: viajes que vienen en
 * camino, envíos listos para entregar y el cambio de estado a ENTREGADO o DEVUELTO (Envio.estadoEnvio y fechaHoraEntrega).
 */
interface RepositorioEntregas
{
    /**
     * Viajes EN_RUTA cuyo destino es la sucursal, el que sale más tarde al final. Solo lectura.
     *
     * @return list<array<string, mixed>> Claves: codigo, origen{codigo, nombre}, destino{codigo, nombre}, fechaHoraSalida,
     *         cantidadEnvios, horas (?int, tiempo estimado de la ruta directa vigente).
     */
    public function viajesEnCamino(int $idSucursal, string $hoy): array;

    /**
     * Envíos EN_DESTINO cuya reserva tiene como destino la sucursal (listos para entregar), el más antiguo primero. Solo lectura.
     *
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, destinatario (nombre completo), tipoEntrega, bultos,
     *         pesoKg (cadena), importe y pagado (cadenas con 2 decimales).
     */
    public function listosParaEntregar(int $idSucursal, int $limite): array;

    /**
     * Un envío con lo que hace falta para atenderlo (destinatario con su documento: uso interno del personal, bultos e
     * importe/pagado). Solo lectura; la visibilidad la decide el servicio.
     *
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, tipoEntrega, direccionEntrega, fechaHoraEntrega,
     *         idSucursalDestino, origen{codigo, nombre}, destino{codigo, nombre},
     *         destinatario{tipoDocumento, numeroDocumento, nombreCompleto}, bultos list<{numero, descripcion, pesoKg}>,
     *         importe y pagado (cadenas con 2 decimales).
     */
    public function buscarEnvio(string $codigoSeguimiento): ?array;

    /**
     * Confirma la entrega en UNA transacción: bloquea el envío (UPDLOCK + HOLDLOCK: un cobro simultáneo del mismo envío
     * espera), lee su estado, su destinatario y lo pagado, y llama a $decidir, que aplica las reglas y lanza HttpException
     * si no procede (todo se deshace). Si procede, fija ENTREGADO y la fecha y hora de entrega de la base en el mismo
     * UPDATE (CK_ENVIO_ENTREGA exige que vayan juntos), y solo si seguía EN_DESTINO.
     *
     * @param callable(array<string, mixed>): void $decidir Recibe ['envio' => {idEnvio, estado, idSucursalDestino,
     *        destinatario{tipoDocumento, numeroDocumento}, importe, pagado}] con importe y pagado en céntimos (enteros).
     * @return bool|null null si el envío no existe; false si ya no se pudo (otra operación se adelantó).
     */
    public function entregar(string $codigoSeguimiento, callable $decidir): ?bool;

    /**
     * Marca el envío como DEVUELTO en UNA transacción, con el mismo bloqueo y contexto que entregar(). No toca la fecha de entrega.
     *
     * @param callable(array<string, mixed>): void $decidir Igual que en entregar().
     * @return bool|null null si el envío no existe; false si ya no se pudo.
     */
    public function devolver(string $codigoSeguimiento, callable $decidir): ?bool;
}
