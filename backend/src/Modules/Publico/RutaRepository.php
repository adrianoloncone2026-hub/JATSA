<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Database;
use PDO;

/** Lee Ruta (solo SELECT, con parámetros). Una ruta es vigente si ya empezó (vigenteDesde <= fecha) */
final class RutaRepository implements RepositorioRutas
{
    private const CONSULTA = <<<'SQL'
        SELECT r.tipoEnvio, r.precioBase, r.precioPorKm, r.distanciaKm, r.tiempoHoras, r.vigenteDesde
        FROM Ruta AS r
        INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
        INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
        WHERE so.codigoSucursal = :origen
          AND sd.codigoSucursal = :destino
          AND r.vigenteDesde <= :fecha1
          AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :fecha2)
        ORDER BY r.vigenteDesde DESC, r.idRuta DESC
        SQL;

    public function vigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array
    {
        $sentencia = Database::conexion()->prepare(self::CONSULTA);
        $sentencia->bindValue(':origen', $codigoOrigen, PDO::PARAM_STR);
        $sentencia->bindValue(':destino', $codigoDestino, PDO::PARAM_STR);
        $sentencia->bindValue(':fecha1', $fechaIso, PDO::PARAM_STR);
        $sentencia->bindValue(':fecha2', $fechaIso, PDO::PARAM_STR);
        $sentencia->execute();

        $porTipo = [];
        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $tipo = (string) $fila['tipoEnvio'];
            if (!isset($porTipo[$tipo])) {
                $porTipo[$tipo] = [
                    'tipoEnvio' => $tipo,
                    'precioBase' => (string) $fila['precioBase'],
                    'precioPorKm' => (string) $fila['precioPorKm'],
                    'distanciaKm' => (string) $fila['distanciaKm'],
                    'tiempoHoras' => (int) $fila['tiempoHoras'],
                ];
            }
        }

        return array_values($porTipo);
    }
}
