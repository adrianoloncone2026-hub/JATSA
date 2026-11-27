<?php
declare(strict_types=1);

namespace Jatsa\Modules\Viajes;

/** Acceso a Viaje (con su vehículo, transportista, sucursales y carga en Envio_Viaje). */
interface RepositorioViajes
{
    /**
     * @param string|null $estado PROGRAMADO, EN_RUTA o TERMINADO (null = todos).
     * @return list<array<string, mixed>>
     */
    public function listarDeTransportista(int $idUsuario, ?string $estado, int $limite, string $hoy): array;

    /** @return list<array<string, mixed>> */
    public function listarDeSucursalOrigen(int $idSucursal, int $limiteTerminados, string $hoy): array;

    /** @return array<string, mixed>|null Un viaje con su carga. */
    public function buscarPorCodigo(string $codigoViaje, string $hoy): ?array;

    /**
     * Marca la salida en UNA transacción: bloquea el viaje, su vehículo y su transportista (siempre en ese orden), lee si
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => fila del viaje bloqueado, 'vehiculoOcupado' => bool, 'transportistaOcupado' => bool].
     * @return bool|null null si el viaje no existe; false si ya no se pudo (otra operación se adelantó).
     */
    public function marcarSalida(string $codigoViaje, callable $decidir): ?bool;

    /**
     * Marca la llegada en UNA transacción: bloquea el viaje, llama a $decidir y fija la llegada con la hora de la base
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => fila del viaje bloqueado].
     * @return bool|null null si el viaje no existe; false si todavía no pasó un segundo desde la salida.
     */
    public function marcarLlegada(string $codigoViaje, callable $decidir): ?bool;
}
