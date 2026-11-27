<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Database;
use PDO;

/** Lee Ruta, Sucursal y Viaje para las salidas entre sucursales (solo SELECT, con parámetros). No toca Persona, Envio ni Pago. */
final class SalidaRepository implements RepositorioSalidas
{
    public function tarifasVigentes(string $hoy): array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    r.tipoEnvio, r.precioBase, r.precioPorKm, r.distanciaKm, r.tiempoHoras
             FROM Ruta AS r
             INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE so.activa = 1 AND sd.activa = 1
               AND r.vigenteDesde <= :hoy1 AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :hoy2)
             ORDER BY so.nombre, sd.nombre, r.vigenteDesde DESC, r.idRuta DESC',
        );
        $sentencia->bindValue(':hoy1', $hoy, PDO::PARAM_STR);
        $sentencia->bindValue(':hoy2', $hoy, PDO::PARAM_STR);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'origenCodigo' => (string) $f['origenCodigo'], 'origenNombre' => (string) $f['origenNombre'],
                'destinoCodigo' => (string) $f['destinoCodigo'], 'destinoNombre' => (string) $f['destinoNombre'],
                'tipoEnvio' => (string) $f['tipoEnvio'], 'precioBase' => (string) $f['precioBase'], 'precioPorKm' => (string) $f['precioPorKm'],
                'distanciaKm' => (string) $f['distanciaKm'], 'tiempoHoras' => (int) $f['tiempoHoras'],
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function salidasRecientes(int $porRuta): array
    {
        // El tope es un entero que decide el servicio (nunca entrada del usuario).
        $sentencia = Database::conexion()->query(
            'SELECT t.origenCodigo, t.destinoCodigo, t.fechaHoraSalida FROM (
                 SELECT so.codigoSucursal AS origenCodigo, sd.codigoSucursal AS destinoCodigo, v.fechaHoraSalida,
                        ROW_NUMBER() OVER (PARTITION BY v.idSucursalOrigen, v.idSucursalDestino ORDER BY v.fechaHoraSalida DESC, v.idViaje DESC) AS n
                 FROM Viaje AS v
                 INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
                 INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
                 WHERE v.fechaHoraSalida IS NOT NULL
             ) AS t WHERE t.n <= ' . max(1, $porRuta),
        );

        return array_map(
            static fn (array $f): array => ['origenCodigo' => (string) $f['origenCodigo'], 'destinoCodigo' => (string) $f['destinoCodigo'], 'fechaHoraSalida' => substr((string) $f['fechaHoraSalida'], 0, 19)],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function programadosPorRuta(): array
    {
        $sentencia = Database::conexion()->query(
            'SELECT so.codigoSucursal AS origenCodigo, sd.codigoSucursal AS destinoCodigo, COUNT(*) AS n
             FROM Viaje AS v
             INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
             WHERE v.fechaHoraSalida IS NULL
             GROUP BY so.codigoSucursal, sd.codigoSucursal',
        );
        $cuenta = [];
        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $cuenta[$f['origenCodigo'] . '-' . $f['destinoCodigo']] = (int) $f['n'];
        }

        return $cuenta;
    }

    public function viajesDeLaRuta(string $codigoOrigen, string $codigoDestino, int $limite): array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . ' v.codigoViaje, v.fechaHoraSalida, v.fechaHoraLlegada
             FROM Viaje AS v
             INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
             WHERE so.codigoSucursal = :origen AND sd.codigoSucursal = :destino AND v.fechaHoraSalida IS NOT NULL
             ORDER BY v.fechaHoraSalida DESC, v.idViaje DESC',
        );
        $sentencia->bindValue(':origen', $codigoOrigen, PDO::PARAM_STR);
        $sentencia->bindValue(':destino', $codigoDestino, PDO::PARAM_STR);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'codigo' => (string) $f['codigoViaje'],
                'fechaHoraSalida' => substr((string) $f['fechaHoraSalida'], 0, 19),
                'fechaHoraLlegada' => $f['fechaHoraLlegada'] !== null ? substr((string) $f['fechaHoraLlegada'], 0, 19) : null,
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
