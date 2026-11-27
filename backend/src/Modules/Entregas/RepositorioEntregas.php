<?php
declare(strict_types=1);

namespace Jatsa\Modules\Entregas;

/** Acceso a lo que el Administrador de la agencia de DESTINO necesita para recibir y entregar envíos: viajes que vienen en */
interface RepositorioEntregas
{
    /**
     * @return list<array<string, mixed>> Claves: codigo, origen{codigo, nombre}, destino{codigo, nombre}, fechaHoraSalida,
     * cantidadEnvios, horas (?int, tiempo estimado de la ruta directa vigente).
     */
    public function viajesEnCamino(int $idSucursal, string $hoy): array;

    /**
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, destinatario (nombre completo), tipoEntrega, bultos,
     * pesoKg (cadena), importe y pagado (cadenas con 2 decimales).
     */
    public function listosParaEntregar(int $idSucursal, int $limite): array;

    /**
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, tipoEntrega, direccionEntrega, fechaHoraEntrega,
     * idSucursalDestino, origen{codigo, nombre}, destino{codigo, nombre},
     * destinatario{tipoDocumento, numeroDocumento, nombreCompleto}, bultos list<{numero, descripcion, pesoKg}>,
     * importe y pagado (cadenas con 2 decimales).
     */
    public function buscarEnvio(string $codigoSeguimiento): ?array;

    /**
     * Confirma la entrega en UNA transacción: bloquea el envío (UPDLOCK + HOLDLOCK: un cobro simultáneo del mismo envío
     * @param callable(array<string, mixed>): void $decidir Recibe ['envio' => {idEnvio, estado, idSucursalDestino,
     * destinatario{tipoDocumento, numeroDocumento}, importe, pagado}] con importe y pagado en céntimos (enteros).
     * @return bool|null null si el envío no existe; false si ya no se pudo (otra operación se adelantó).
     */
    public function entregar(string $codigoSeguimiento, callable $decidir): ?bool;

    /**
     * Marca el envío como DEVUELTO en UNA transacción, con el mismo bloqueo y contexto que entregar(). No toca la fecha de entrega.
     * @param callable(array<string, mixed>): void $decidir Igual que en entregar().
     * @return bool|null null si el envío no existe; false si ya no se pudo.
     */
    public function devolver(string $codigoSeguimiento, callable $decidir): ?bool;
}
