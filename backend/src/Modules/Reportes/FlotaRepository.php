<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Jatsa\Core\Database;
use PDO;

/** Consultas agregadas de Viaje, Envio_Viaje, Bulto, Ruta y Vehiculo para «Viajes y flota». Parametrizadas y de solo lectura. */
final class FlotaRepository implements RepositorioFlota
{
    private const PESO_VIAJE = '(SELECT COALESCE(SUM(b.pesoKg), 0) FROM Envio_Viaje AS ev INNER JOIN Bulto AS b ON b.idEnvio = ev.idEnvio WHERE ev.idViaje = v.idViaje)';
    private const TERMINADO = 'v.fechaHoraLlegada IS NOT NULL AND v.fechaHoraSalida >= :desde AND v.fechaHoraSalida < :hasta';

    public function viajesAbiertos(array $filtros): array
    {
        $valores = [];
        $donde = $this->condiciones($filtros, $valores);
        $sentencia = Database::conexion()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN v.fechaHoraSalida IS NOT NULL THEN 1 ELSE 0 END), 0) AS enRuta,
                    COALESCE(SUM(CASE WHEN v.fechaHoraSalida IS NULL THEN 1 ELSE 0 END), 0) AS programados
             FROM Viaje AS v WHERE v.fechaHoraLlegada IS NULL{$donde}",
        );
        $sentencia->execute($valores);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC) ?: ['enRuta' => 0, 'programados' => 0];

        return ['enRuta' => (int) $fila['enRuta'], 'programados' => (int) $fila['programados']];
    }

    public function terminados(array $filtros, string $desde, string $hasta): array
    {
        $valores = [':desde' => $desde, ':hasta' => $hasta];
        $donde = $this->condiciones($filtros, $valores);
        $sentencia = Database::conexion()->prepare(
            'SELECT COUNT(*) AS viajes, COALESCE(SUM(t.pesoKg), 0) AS pesoKg,
                    AVG(CASE WHEN t.capacidadKg > 0 THEN CAST(t.pesoKg AS FLOAT) / CAST(t.capacidadKg AS FLOAT) END) AS usoMedio
             FROM (SELECT ve.capacidadKg, ' . self::PESO_VIAJE . ' AS pesoKg
                   FROM Viaje AS v INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
                   WHERE ' . self::TERMINADO . $donde . ') AS t',
        );
        $sentencia->execute($valores);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC) ?: ['viajes' => 0, 'pesoKg' => 0, 'usoMedio' => null];

        return [
            'viajes' => (int) $fila['viajes'],
            'pesoKg' => number_format((float) $fila['pesoKg'], 2, '.', ''),
            'usoMedio' => $fila['usoMedio'] === null ? null : (float) $fila['usoMedio'],
        ];
    }

    public function transbordos(array $filtros, string $desde, string $hasta): array
    {
        $valores = [':desde' => $desde, ':hasta' => $hasta];
        $donde = $this->condiciones($filtros, $valores);
        $sentencia = Database::conexion()->prepare(
            'SELECT COUNT(*) AS envios, COALESCE(SUM(CASE WHEN c.viajes > 1 THEN 1 ELSE 0 END), 0) AS conTransbordo
             FROM (SELECT x.idEnvio, (SELECT COUNT(*) FROM Envio_Viaje AS y WHERE y.idEnvio = x.idEnvio) AS viajes
                   FROM Envio_Viaje AS x INNER JOIN Viaje AS v ON v.idViaje = x.idViaje
                   WHERE ' . self::TERMINADO . $donde . ' GROUP BY x.idEnvio) AS c',
        );
        $sentencia->execute($valores);
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC) ?: ['envios' => 0, 'conTransbordo' => 0];

        return ['envios' => (int) $fila['envios'], 'conTransbordo' => (int) $fila['conTransbordo']];
    }

    public function viajesEnRuta(array $filtros, string $hoy): array
    {
        $valores = [':hoy1' => $hoy, ':hoy2' => $hoy];
        $donde = $this->condiciones($filtros, $valores);
        $sentencia = Database::conexion()->prepare(
            "SELECT v.codigoViaje, so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre,
                    sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre, ve.placa, pe.nombreCompleto AS transportista,
                    CONVERT(VARCHAR(19), v.fechaHoraSalida, 120) AS salida,
                    (SELECT MAX(r.tiempoHoras) FROM Ruta AS r
                      WHERE r.idSucursalOrigen = v.idSucursalOrigen AND r.idSucursalDestino = v.idSucursalDestino
                        AND r.vigenteDesde <= :hoy1 AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :hoy2)) AS horas
             FROM Viaje AS v
             INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
             INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
             INNER JOIN Usuario AS u ON u.idUsuario = v.idUsuarioTransportista
             INNER JOIN Persona AS pe ON pe.idPersona = u.idPersona
             WHERE v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL{$donde}
             ORDER BY v.fechaHoraSalida, v.idViaje",
        );
        $sentencia->execute($valores);

        return array_map(static fn (array $f): array => [
            'codigo' => trim((string) $f['codigoViaje']),
            'origen' => ['codigo' => trim((string) $f['origenCodigo']), 'nombre' => (string) $f['origenNombre']],
            'destino' => ['codigo' => trim((string) $f['destinoCodigo']), 'nombre' => (string) $f['destinoNombre']],
            'placa' => (string) $f['placa'],
            'transportista' => (string) $f['transportista'],
            'salida' => (string) $f['salida'],
            'horas' => $f['horas'] === null ? null : (int) $f['horas'],
        ], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function transportistas(array $filtros, string $desde, string $hasta): array
    {
        $valores = [':desde' => $desde, ':hasta' => $hasta];
        $donde = $this->condiciones($filtros, $valores);
        $sentencia = Database::conexion()->prepare(
            'SELECT t.transportista, t.sucursalCodigo, COUNT(*) AS viajes, COALESCE(SUM(t.pesoKg), 0) AS pesoKg
             FROM (SELECT v.idUsuarioTransportista, pe.nombreCompleto AS transportista, su.codigoSucursal AS sucursalCodigo, ' . self::PESO_VIAJE . ' AS pesoKg
                   FROM Viaje AS v
                   INNER JOIN Usuario AS u ON u.idUsuario = v.idUsuarioTransportista
                   INNER JOIN Persona AS pe ON pe.idPersona = u.idPersona
                   LEFT JOIN Sucursal AS su ON su.idSucursal = u.idSucursal
                   WHERE ' . self::TERMINADO . $donde . ') AS t
             GROUP BY t.idUsuarioTransportista, t.transportista, t.sucursalCodigo
             ORDER BY COUNT(*) DESC, t.transportista',
        );
        $sentencia->execute($valores);

        return array_map(static fn (array $f): array => [
            'transportista' => (string) $f['transportista'],
            'sucursalCodigo' => $f['sucursalCodigo'] === null ? null : trim((string) $f['sucursalCodigo']),
            'viajes' => (int) $f['viajes'],
            'pesoKg' => number_format((float) $f['pesoKg'], 2, '.', ''),
        ], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Condiciones de los filtros sobre Viaje (alias v). Cada una es un EXISTS: nunca multiplica filas, así que un viaje cuenta una vez.
     * @param array<string, ?string> $filtros
     * @param array<string, string> $valores
     */
    private function condiciones(array $filtros, array &$valores): string
    {
        $donde = '';
        if (($filtros['sucursal'] ?? null) !== null) {
            $donde .= ' AND EXISTS (SELECT 1 FROM Sucursal AS s WHERE s.codigoSucursal = :suc AND s.idSucursal IN (v.idSucursalOrigen, v.idSucursalDestino))';
            $valores[':suc'] = $filtros['sucursal'];
        }
        if (($filtros['placa'] ?? null) !== null) {
            $donde .= ' AND EXISTS (SELECT 1 FROM Vehiculo AS fv WHERE fv.idVehiculo = v.idVehiculo AND fv.placa = :placa)';
            $valores[':placa'] = $filtros['placa'];
        }
        if (($filtros['transportista'] ?? null) !== null) {
            $donde .= ' AND EXISTS (SELECT 1 FROM Usuario AS ft WHERE ft.idUsuario = v.idUsuarioTransportista AND ft.nombreUsuario = :transportista)';
            $valores[':transportista'] = $filtros['transportista'];
        }
        if (($filtros['tipoEnvio'] ?? null) !== null) {
            $donde .= ' AND EXISTS (SELECT 1 FROM Envio_Viaje AS fe INNER JOIN Bulto AS fb ON fb.idEnvio = fe.idEnvio
                INNER JOIN Ruta AS fr ON fr.idRuta = fb.idRuta WHERE fe.idViaje = v.idViaje AND fr.tipoEnvio = :tipo)';
            $valores[':tipo'] = $filtros['tipoEnvio'];
        }

        return $donde;
    }
}
