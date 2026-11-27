<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/** Lectura de datos para las exportaciones Excel. Sin límites de paginación; $sucursal = id de sucursal o null (global). */
interface RepositorioExportaciones
{
    public function idSucursalPorCodigo(string $codigo): ?int;

    /** @return list<array<string, mixed>> idSucursal, codigoSucursal, nombre, departamento, activa */
    public function sucursales(): array;

    /** @return list<array<string, mixed>> idEnvio, codigoSeguimiento, fechaHoraRegistro, idSucursalOrigen, idSucursalDestino, estadoEnvio, cantidadBultos, importeEnvio, cobradoRegistrado */
    public function enviosResumen(?int $sucursal): array;

    /** @return list<array<string, mixed>> idPago, idEnvio, fechaHoraPago, idSucursal, monto, metodoPago, pagadoPor, estadoPago */
    public function pagos(?int $sucursal): array;

    /** @return list<array<string, mixed>> idViaje, codigoViaje, idSucursalOrigen, idSucursalDestino, fechaHoraSalida, fechaHoraLlegada, placa, nombreUsuario, capacidadKg */
    public function viajes(?int $sucursal): array;

    /** @return list<array<string, mixed>> idEnvioViaje, idEnvio, idViaje, fechaHoraCarga */
    public function envioViaje(?int $sucursal): array;

    /** @return list<array<string, mixed>> idEnvio, codigoSeguimiento, fechaHoraRegistro, pesoTotalKg, estadoEnvio */
    public function enviosCarga(?int $sucursal): array;
}
