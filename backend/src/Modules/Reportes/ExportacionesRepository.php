<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Jatsa\Core\Database;
use PDO;

/** SELECT parametrizados para las exportaciones. Los totales por envío salen de vw_EnvioTotales (bultos y pagos agregados aparte: ningún JOIN repite importes). */
final class ExportacionesRepository implements RepositorioExportaciones
{
    private const ENVIO_EN_SUCURSAL = 'EXISTS (SELECT 1 FROM Reserva AS rs WHERE rs.idReserva = e.idReserva AND :suc IN (rs.idSucursalOrigen, rs.idSucursalDestino))';

    public function idSucursalPorCodigo(string $codigo): ?int
    {
        $s = Database::conexion()->prepare('SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :codigo');
        $s->execute([':codigo' => $codigo]);
        $id = $s->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function sucursales(): array
    {
        return $this->filas('SELECT idSucursal, codigoSucursal, nombre, departamento, activa FROM Sucursal ORDER BY idSucursal');
    }

    public function enviosResumen(?int $sucursal): array
    {
        return $this->filas(
            'SELECT e.idEnvio, e.codigoSeguimiento, e.fechaHoraRegistro, r.idSucursalOrigen, r.idSucursalDestino, e.estadoEnvio,
                    t.cantidadBultos, t.importe AS importeEnvio, t.pagado AS cobradoRegistrado
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN vw_EnvioTotales AS t ON t.idEnvio = e.idEnvio'
            . ($sucursal === null ? '' : ' WHERE ' . self::ENVIO_EN_SUCURSAL) . ' ORDER BY e.idEnvio',
            $sucursal,
        );
    }

    public function pagos(?int $sucursal): array
    {
        return $this->filas(
            'SELECT pg.idPago, pg.idEnvio, pg.fechaHoraPago, pg.idSucursal, pg.monto, pg.metodoPago, pg.pagadoPor, pg.estadoPago
             FROM Pago AS pg INNER JOIN Envio AS e ON e.idEnvio = pg.idEnvio'
            . ($sucursal === null ? '' : ' WHERE ' . self::ENVIO_EN_SUCURSAL) . ' ORDER BY pg.idPago',
            $sucursal,
        );
    }

    public function viajes(?int $sucursal): array
    {
        return $this->filas(
            'SELECT v.idViaje, v.codigoViaje, v.idSucursalOrigen, v.idSucursalDestino, v.fechaHoraSalida, v.fechaHoraLlegada,
                    ve.placa, u.nombreUsuario, ve.capacidadKg
             FROM Viaje AS v
             INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
             INNER JOIN Usuario AS u ON u.idUsuario = v.idUsuarioTransportista'
            . ($sucursal === null ? '' : ' WHERE :suc IN (v.idSucursalOrigen, v.idSucursalDestino)') . ' ORDER BY v.idViaje',
            $sucursal,
        );
    }

    public function envioViaje(?int $sucursal): array
    {
        return $this->filas(
            'SELECT ev.idEnvioViaje, ev.idEnvio, ev.idViaje, ev.fechaHoraCarga
             FROM Envio_Viaje AS ev INNER JOIN Viaje AS v ON v.idViaje = ev.idViaje'
            . ($sucursal === null ? '' : ' WHERE :suc IN (v.idSucursalOrigen, v.idSucursalDestino)') . ' ORDER BY ev.idEnvioViaje',
            $sucursal,
        );
    }

    public function enviosCarga(?int $sucursal): array
    {
        return $this->filas(
            'SELECT e.idEnvio, e.codigoSeguimiento, e.fechaHoraRegistro, t.pesoKg AS pesoTotalKg, e.estadoEnvio
             FROM Envio AS e
             INNER JOIN vw_EnvioTotales AS t ON t.idEnvio = e.idEnvio'
            . ($sucursal === null ? '' : ' WHERE ' . self::ENVIO_EN_SUCURSAL
                . ' OR EXISTS (SELECT 1 FROM Envio_Viaje AS ev INNER JOIN Viaje AS v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = e.idEnvio AND :suc2 IN (v.idSucursalOrigen, v.idSucursalDestino))')
            . ' ORDER BY e.idEnvio',
            $sucursal,
        );
    }

    /** @return list<array<string, mixed>> */
    private function filas(string $sql, ?int $sucursal = null): array
    {
        $sentencia = Database::conexion()->prepare($sql);
        $valores = [];
        if ($sucursal !== null) {
            $valores[':suc'] = $sucursal;
            if (str_contains($sql, ':suc2')) {
                $valores[':suc2'] = $sucursal;
            }
        }
        $sentencia->execute($valores);

        return $sentencia->fetchAll(PDO::FETCH_ASSOC);
    }
}
