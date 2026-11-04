<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Jatsa\Core\Database;
use PDO;

/** Consultas agregadas de Envio, Reserva, Pago y Viaje para el Resumen. Parametrizadas y de solo lectura. */
final class ResumenRepository implements RepositorioResumen
{
    public function enviosDelPeriodo(string $desde, string $hasta, ?string $sucursal, string $limiteAntiguedad): array
    {
        $valores = [':desde' => $desde, ':hasta' => $hasta, ':limite' => $limiteAntiguedad];
        $filtro = '';
        if ($sucursal !== null) {
            $filtro = ' AND EXISTS (SELECT 1 FROM Sucursal AS s WHERE s.codigoSucursal = :suc AND s.idSucursal IN (r.idSucursalOrigen, r.idSucursalDestino))';
            $valores[':suc'] = $sucursal;
        }
        $sentencia = Database::conexion()->prepare(
            "SELECT e.estadoEnvio, COUNT(*) AS cantidad,
                    SUM(CASE WHEN e.estadoEnvio IN ('EN_TRANSITO', 'EN_DESTINO') AND e.fechaHoraRegistro < :limite THEN 1 ELSE 0 END) AS antiguos
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             WHERE e.fechaHoraRegistro >= :desde AND e.fechaHoraRegistro < :hasta{$filtro}
             GROUP BY e.estadoEnvio",
        );
        $sentencia->execute($valores);

        $porEstado = [];
        $antiguos = 0;
        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porEstado[(string) $fila['estadoEnvio']] = (int) $fila['cantidad'];
            $antiguos += (int) $fila['antiguos'];
        }

        return ['porEstado' => $porEstado, 'antiguos' => $antiguos];
    }

    public function cobradoDelPeriodo(string $desde, string $hasta, ?string $sucursal): string
    {
        $valores = [':desde' => $desde, ':hasta' => $hasta];
        $filtro = '';
        if ($sucursal !== null) {
            $filtro = ' AND EXISTS (SELECT 1 FROM Sucursal AS s WHERE s.idSucursal = p.idSucursal AND s.codigoSucursal = :suc)';
            $valores[':suc'] = $sucursal;
        }
        $sentencia = Database::conexion()->prepare(
            "SELECT COALESCE(SUM(p.monto), 0) FROM Pago AS p
             WHERE p.estadoPago = 'REGISTRADO' AND p.fechaHoraPago >= :desde AND p.fechaHoraPago < :hasta{$filtro}",
        );
        $sentencia->execute($valores);

        return number_format((float) $sentencia->fetchColumn(), 2, '.', '');
    }

    public function viajesAbiertos(?string $sucursal): array
    {
        $valores = [];
        $filtro = '';
        if ($sucursal !== null) {
            $filtro = ' AND EXISTS (SELECT 1 FROM Sucursal AS s WHERE s.codigoSucursal = :suc AND s.idSucursal IN (v.idSucursalOrigen, v.idSucursalDestino))';
            $valores[':suc'] = $sucursal;
        }
        $sentencia = Database::conexion()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN v.fechaHoraSalida IS NOT NULL THEN 1 ELSE 0 END), 0) AS enRuta,
                    COALESCE(SUM(CASE WHEN v.fechaHoraSalida IS NULL THEN 1 ELSE 0 END), 0) AS programados
             FROM Viaje AS v WHERE v.fechaHoraLlegada IS NULL{$filtro}",
        );
        $sentencia->execute($valores);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC) ?: ['enRuta' => 0, 'programados' => 0];

        return ['enRuta' => (int) $fila['enRuta'], 'programados' => (int) $fila['programados']];
    }
}
