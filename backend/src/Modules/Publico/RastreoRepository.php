<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Database;
use PDO;

/** Lee Envio, Reserva, Sucursal, Bulto, Pago, Envio_Viaje, Viaje y Vehiculo para el rastreo público (solo SELECT, con parámetros). */
final class RastreoRepository implements RepositorioRastreo
{
    public function buscarEnvio(string $codigoSeguimiento, string $hoy): ?array
    {
        $pdo = Database::conexion();
        $sentencia = $pdo->prepare(
            "SELECT e.idEnvio, e.codigoSeguimiento, e.estadoEnvio, e.fechaHoraRegistro, e.fechaHoraEntrega,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    t.cantidadBultos AS bultos, t.pesoKg, t.importe, t.pagado
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             INNER JOIN vw_EnvioTotales AS t ON t.idEnvio = e.idEnvio
             WHERE e.codigoSeguimiento = :codigo",
        );
        $sentencia->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();
        if ($fila === false) {
            return null;
        }

        $tramos = $pdo->prepare(
            'SELECT v.codigoViaje, ev.fechaHoraCarga, v.fechaHoraSalida, v.fechaHoraLlegada,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    ve.tipoVehiculo, ve.placa,
                    (SELECT MAX(r.tiempoHoras) FROM Ruta AS r
                      WHERE r.idSucursalOrigen = v.idSucursalOrigen AND r.idSucursalDestino = v.idSucursalDestino
                        AND r.vigenteDesde <= :hoy1 AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :hoy2)) AS horas
             FROM Envio_Viaje AS ev
             INNER JOIN Viaje AS v ON v.idViaje = ev.idViaje
             INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
             INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
             WHERE ev.idEnvio = :envio
             ORDER BY ev.idEnvioViaje',
        );
        $tramos->bindValue(':envio', (int) $fila['idEnvio'], PDO::PARAM_INT);
        $tramos->bindValue(':hoy1', $hoy, PDO::PARAM_STR);
        $tramos->bindValue(':hoy2', $hoy, PDO::PARAM_STR);
        $tramos->execute();

        $fecha = static fn (mixed $v): ?string => $v !== null ? substr((string) $v, 0, 19) : null;

        return [
            'codigoSeguimiento' => (string) $fila['codigoSeguimiento'],
            'estado' => (string) $fila['estadoEnvio'],
            'fechaHoraRegistro' => (string) $fecha($fila['fechaHoraRegistro']),
            'fechaHoraEntrega' => $fecha($fila['fechaHoraEntrega']),
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre']],
            'bultos' => (int) $fila['bultos'],
            'pesoKg' => (string) $fila['pesoKg'],
            'importe' => (string) $fila['importe'],
            'pagado' => (string) $fila['pagado'],
            'tramos' => array_map(
                static fn (array $t): array => [
                    'codigoViaje' => (string) $t['codigoViaje'],
                    'fechaHoraCarga' => (string) $fecha($t['fechaHoraCarga']),
                    'fechaHoraSalida' => $fecha($t['fechaHoraSalida']),
                    'fechaHoraLlegada' => $fecha($t['fechaHoraLlegada']),
                    'origen' => ['codigo' => (string) $t['origenCodigo'], 'nombre' => (string) $t['origenNombre']],
                    'destino' => ['codigo' => (string) $t['destinoCodigo'], 'nombre' => (string) $t['destinoNombre']],
                    'vehiculo' => ['tipo' => (string) $t['tipoVehiculo'], 'placa' => (string) $t['placa']],
                    'horas' => $t['horas'] !== null ? (int) $t['horas'] : null,
                ],
                $tramos->fetchAll(PDO::FETCH_ASSOC),
            ),
        ];
    }
}
