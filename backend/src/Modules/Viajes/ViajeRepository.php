<?php
declare(strict_types=1);

namespace Jatsa\Modules\Viajes;

use Jatsa\Core\Database;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Lee y escribe Viaje con los nombres del script SQL definitivo. Siempre con parámetros.
 * Las únicas escrituras son la salida y la llegada (UPDATE de Viaje y del estado de los envíos cargados), en transacción.
 */
final class ViajeRepository implements RepositorioViajes
{
    private const COLUMNAS = 'v.idViaje, v.codigoViaje, v.idUsuarioTransportista, v.idSucursalOrigen, v.idSucursalDestino,
               v.fechaHoraSalida, v.fechaHoraLlegada,
               so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
               ve.placa, ve.tipoVehiculo, ve.capacidadKg, pe.nombreCompleto AS transportista,
               (SELECT COUNT(*) FROM Envio_Viaje AS ev WHERE ev.idViaje = v.idViaje) AS cantidadEnvios,
               (SELECT COALESCE(SUM(b.pesoKg), 0) FROM Envio_Viaje AS ev INNER JOIN Bulto AS b ON b.idEnvio = ev.idEnvio WHERE ev.idViaje = v.idViaje) AS pesoKg,
               (SELECT MAX(r.tiempoHoras) FROM Ruta AS r
                 WHERE r.idSucursalOrigen = v.idSucursalOrigen AND r.idSucursalDestino = v.idSucursalDestino
                   AND r.vigenteDesde <= :hoy1 AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :hoy2)) AS horas';

    private const ORIGEN = 'FROM Viaje AS v
        INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
        INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
        INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
        INNER JOIN Usuario AS u ON u.idUsuario = v.idUsuarioTransportista
        INNER JOIN Persona AS pe ON pe.idPersona = u.idPersona';

    /** En ruta primero, luego programados por código y luego terminados, del más reciente al más antiguo (diapositiva 24). */
    private const ORDEN = 'ORDER BY CASE WHEN v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL THEN 0
                                         WHEN v.fechaHoraSalida IS NULL THEN 1 ELSE 2 END,
                                    CASE WHEN v.fechaHoraSalida IS NULL THEN v.codigoViaje END ASC,
                                    CASE WHEN v.fechaHoraSalida IS NOT NULL THEN v.fechaHoraSalida END DESC, v.idViaje DESC';

    public function listarDeTransportista(int $idUsuario, ?string $estado, int $limite, string $hoy): array
    {
        // El filtro sale de una lista cerrada: nunca se concatena entrada del usuario.
        $filtro = match ($estado) {
            'PROGRAMADO' => ' AND v.fechaHoraSalida IS NULL',
            'EN_RUTA' => ' AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL',
            'TERMINADO' => ' AND v.fechaHoraLlegada IS NOT NULL',
            default => '',
        };
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . ' ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE v.idUsuarioTransportista = :usuario' . $filtro . ' ' . self::ORDEN,
        );
        $sentencia->bindValue(':usuario', $idUsuario, PDO::PARAM_INT);

        return $this->filas($sentencia, $hoy);
    }

    public function listarDeSucursalOrigen(int $idSucursal, int $limiteTerminados, string $hoy): array
    {
        $pdo = Database::conexion();
        $activos = $pdo->prepare('SELECT ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE v.idSucursalOrigen = :sucursal AND v.fechaHoraLlegada IS NULL ' . self::ORDEN);
        $activos->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        $terminados = $pdo->prepare(
            'SELECT TOP ' . max(1, $limiteTerminados) . ' ' . self::COLUMNAS . ' ' . self::ORIGEN
            . ' WHERE v.idSucursalOrigen = :sucursal AND v.fechaHoraLlegada IS NOT NULL ORDER BY v.fechaHoraLlegada DESC, v.idViaje DESC',
        );
        $terminados->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);

        return array_merge($this->filas($activos, $hoy), $this->filas($terminados, $hoy));
    }

    public function buscarPorCodigo(string $codigoViaje, string $hoy): ?array
    {
        $sentencia = Database::conexion()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE v.codigoViaje = :codigo');
        $sentencia->bindValue(':codigo', $codigoViaje, PDO::PARAM_STR);
        $viajes = $this->filas($sentencia, $hoy);
        if ($viajes === []) {
            return null;
        }

        $carga = Database::conexion()->prepare(
            'SELECT e.codigoSeguimiento, ev.fechaHoraCarga, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    (SELECT COUNT(*) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS bultos,
                    (SELECT COALESCE(SUM(b.pesoKg), 0) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS pesoKg
             FROM Envio_Viaje AS ev
             INNER JOIN Envio AS e ON e.idEnvio = ev.idEnvio
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE ev.idViaje = :viaje
             ORDER BY ev.fechaHoraCarga, ev.idEnvioViaje',
        );
        $carga->bindValue(':viaje', $viajes[0]['idViaje'], PDO::PARAM_INT);
        $carga->execute();
        $viajes[0]['carga'] = array_map(
            static fn (array $f): array => [
                'codigoSeguimiento' => (string) $f['codigoSeguimiento'],
                'bultos' => (int) $f['bultos'],
                'pesoKg' => (string) $f['pesoKg'],
                'destinoFinal' => ['codigo' => (string) $f['destinoCodigo'], 'nombre' => (string) $f['destinoNombre']],
                'cargadoEn' => substr((string) $f['fechaHoraCarga'], 0, 19),
            ],
            $carga->fetchAll(PDO::FETCH_ASSOC),
        );

        return $viajes[0];
    }

    public function marcarSalida(string $codigoViaje, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            $viaje = $this->bloquearViaje($pdo, $codigoViaje);
            if ($viaje === null) {
                $pdo->rollBack();

                return null;
            }

            // Bloquear el vehículo (después del viaje, siempre en el mismo orden: no hay interbloqueo) y ver si ya está en ruta.
            $bloqueo = $pdo->prepare('SELECT idVehiculo FROM Vehiculo WITH (UPDLOCK, HOLDLOCK) WHERE idVehiculo = :vehiculo');
            $bloqueo->bindValue(':vehiculo', $viaje['idVehiculo'], PDO::PARAM_INT);
            $bloqueo->execute();
            $bloqueo->closeCursor();
            $ocupado = $pdo->prepare(
                'SELECT COUNT(*) FROM Viaje
                 WHERE idVehiculo = :vehiculo AND fechaHoraSalida IS NOT NULL AND fechaHoraLlegada IS NULL AND idViaje <> :viaje',
            );
            $ocupado->bindValue(':vehiculo', $viaje['idVehiculo'], PDO::PARAM_INT);
            $ocupado->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $ocupado->execute();
            $vehiculoOcupado = (int) $ocupado->fetchColumn() > 0;
            $ocupado->closeCursor();

            // Bloquear al transportista (tercero en el orden: viaje → vehículo → transportista) y ver si ya conduce otro viaje en ruta.
            // Así dos salidas del mismo conductor con vehículos distintos se ponen en fila y la segunda ya ve la primera.
            $conductor = $pdo->prepare('SELECT idUsuario FROM Usuario WITH (UPDLOCK, HOLDLOCK) WHERE idUsuario = :usuario');
            $conductor->bindValue(':usuario', $viaje['idUsuarioTransportista'], PDO::PARAM_INT);
            $conductor->execute();
            $conductor->closeCursor();
            $enRuta = $pdo->prepare(
                'SELECT COUNT(*) FROM Viaje
                 WHERE idUsuarioTransportista = :usuario AND fechaHoraSalida IS NOT NULL AND fechaHoraLlegada IS NULL AND idViaje <> :viaje',
            );
            $enRuta->bindValue(':usuario', $viaje['idUsuarioTransportista'], PDO::PARAM_INT);
            $enRuta->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $enRuta->execute();
            $transportistaOcupado = (int) $enRuta->fetchColumn() > 0;
            $enRuta->closeCursor();

            $decidir(['viaje' => $viaje, 'vehiculoOcupado' => $vehiculoOcupado, 'transportistaOcupado' => $transportistaOcupado]);

            $salida = $pdo->prepare('UPDATE Viaje SET fechaHoraSalida = SYSDATETIME() WHERE idViaje = :viaje AND fechaHoraSalida IS NULL');
            $salida->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $salida->execute();
            if ($salida->rowCount() !== 1) {
                $pdo->rollBack();

                return false;
            }

            // El estado del envío se actualiza: lo que estaba REGISTRADO sale de la agencia. Un envío en transbordo ya está EN_TRANSITO.
            $envios = $pdo->prepare(
                "UPDATE Envio SET estadoEnvio = 'EN_TRANSITO'
                 WHERE estadoEnvio = 'REGISTRADO' AND idEnvio IN (SELECT idEnvio FROM Envio_Viaje WHERE idViaje = :viaje)",
            );
            $envios->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $envios->execute();

            $pdo->commit();

            return true;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    public function marcarLlegada(string $codigoViaje, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            $viaje = $this->bloquearViaje($pdo, $codigoViaje);
            if ($viaje === null) {
                $pdo->rollBack();

                return null;
            }

            $decidir(['viaje' => $viaje]);

            // CK_VIAJE_FECHAS exige llegada > salida: con columnas DATETIME2(0), hace falta que haya pasado al menos un segundo.
            $llegada = $pdo->prepare(
                'UPDATE Viaje SET fechaHoraLlegada = SYSDATETIME()
                 WHERE idViaje = :viaje AND fechaHoraSalida IS NOT NULL AND fechaHoraLlegada IS NULL
                   AND fechaHoraSalida < CAST(SYSDATETIME() AS DATETIME2(0))',
            );
            $llegada->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $llegada->execute();
            if ($llegada->rowCount() !== 1) {
                $pdo->rollBack();

                return false;
            }

            $envios = $pdo->prepare(
                "UPDATE e SET e.estadoEnvio = 'EN_DESTINO'
                 FROM Envio AS e
                 INNER JOIN Envio_Viaje AS ev ON ev.idEnvio = e.idEnvio
                 INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
                 WHERE ev.idViaje = :viaje AND e.estadoEnvio = 'EN_TRANSITO' AND r.idSucursalDestino = :destino",
            );
            $envios->bindValue(':viaje', $viaje['idViaje'], PDO::PARAM_INT);
            $envios->bindValue(':destino', $viaje['idSucursalDestino'], PDO::PARAM_INT);
            $envios->execute();

            $pdo->commit();

            return true;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    /** @return array<string, mixed>|null */
    private function bloquearViaje(PDO $pdo, string $codigoViaje): ?array
    {
        $sentencia = $pdo->prepare(
            'SELECT idViaje, idVehiculo, idUsuarioTransportista, idSucursalOrigen, idSucursalDestino, fechaHoraSalida, fechaHoraLlegada
             FROM Viaje WITH (UPDLOCK, HOLDLOCK) WHERE codigoViaje = :codigo',
        );
        $sentencia->bindValue(':codigo', $codigoViaje, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();
        if ($fila === false) {
            return null;
        }

        return [
            'idViaje' => (int) $fila['idViaje'],
            'idVehiculo' => (int) $fila['idVehiculo'],
            'idUsuarioTransportista' => (int) $fila['idUsuarioTransportista'],
            'idSucursalOrigen' => (int) $fila['idSucursalOrigen'],
            'idSucursalDestino' => (int) $fila['idSucursalDestino'],
            'fechaHoraSalida' => $fila['fechaHoraSalida'] !== null ? substr((string) $fila['fechaHoraSalida'], 0, 19) : null,
            'fechaHoraLlegada' => $fila['fechaHoraLlegada'] !== null ? substr((string) $fila['fechaHoraLlegada'], 0, 19) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filas(PDOStatement $sentencia, string $hoy): array
    {
        $sentencia->bindValue(':hoy1', $hoy, PDO::PARAM_STR);
        $sentencia->bindValue(':hoy2', $hoy, PDO::PARAM_STR);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'idViaje' => (int) $f['idViaje'],
                'codigo' => (string) $f['codigoViaje'],
                'idUsuarioTransportista' => (int) $f['idUsuarioTransportista'],
                'idSucursalOrigen' => (int) $f['idSucursalOrigen'],
                'idSucursalDestino' => (int) $f['idSucursalDestino'],
                'origen' => ['codigo' => (string) $f['origenCodigo'], 'nombre' => (string) $f['origenNombre']],
                'destino' => ['codigo' => (string) $f['destinoCodigo'], 'nombre' => (string) $f['destinoNombre']],
                'vehiculo' => ['placa' => (string) $f['placa'], 'tipo' => (string) $f['tipoVehiculo'], 'capacidadKg' => (string) $f['capacidadKg']],
                'transportista' => (string) $f['transportista'],
                'fechaHoraSalida' => $f['fechaHoraSalida'] !== null ? substr((string) $f['fechaHoraSalida'], 0, 19) : null,
                'fechaHoraLlegada' => $f['fechaHoraLlegada'] !== null ? substr((string) $f['fechaHoraLlegada'], 0, 19) : null,
                'cantidadEnvios' => (int) $f['cantidadEnvios'],
                'pesoKg' => (string) $f['pesoKg'],
                'horas' => $f['horas'] !== null ? (int) $f['horas'] : null,
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
