<?php
declare(strict_types=1);

namespace Jatsa\Modules\Entregas;

use Jatsa\Core\Database;
use PDO;
use Throwable;

/** Lee y escribe Envio (estado y fecha de entrega) con los nombres del script SQL definitivo. Siempre con parámetros. */
final class EntregaRepository implements RepositorioEntregas
{
    public function viajesEnCamino(int $idSucursal, string $hoy): array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT v.codigoViaje, v.fechaHoraSalida,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    (SELECT COUNT(*) FROM Envio_Viaje AS ev WHERE ev.idViaje = v.idViaje) AS cantidadEnvios,
                    (SELECT MAX(r.tiempoHoras) FROM Ruta AS r
                      WHERE r.idSucursalOrigen = v.idSucursalOrigen AND r.idSucursalDestino = v.idSucursalDestino
                        AND r.vigenteDesde <= :hoy1 AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :hoy2)) AS horas
             FROM Viaje AS v
             INNER JOIN Sucursal AS so ON so.idSucursal = v.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = v.idSucursalDestino
             WHERE v.idSucursalDestino = :destino AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL
             ORDER BY v.fechaHoraSalida, v.idViaje',
        );
        $sentencia->bindValue(':destino', $idSucursal, PDO::PARAM_INT);
        $sentencia->bindValue(':hoy1', $hoy, PDO::PARAM_STR);
        $sentencia->bindValue(':hoy2', $hoy, PDO::PARAM_STR);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'codigo' => (string) $f['codigoViaje'],
                'origen' => ['codigo' => (string) $f['origenCodigo'], 'nombre' => (string) $f['origenNombre']],
                'destino' => ['codigo' => (string) $f['destinoCodigo'], 'nombre' => (string) $f['destinoNombre']],
                'fechaHoraSalida' => substr((string) $f['fechaHoraSalida'], 0, 19),
                'cantidadEnvios' => (int) $f['cantidadEnvios'],
                'horas' => $f['horas'] !== null ? (int) $f['horas'] : null,
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listosParaEntregar(int $idSucursal, int $limite): array
    {
        // El límite es un entero que decide el servicio (nunca entrada del usuario).
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . " e.codigoSeguimiento, e.tipoEntrega, pd.nombreCompleto AS destinatario,
                    t.cantidadBultos AS bultos, t.pesoKg, t.importe, t.pagado
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
             INNER JOIN vw_EnvioTotales AS t ON t.idEnvio = e.idEnvio
             WHERE e.estadoEnvio = 'EN_DESTINO' AND r.idSucursalDestino = :destino
             ORDER BY e.fechaHoraRegistro, e.idEnvio",
        );
        $sentencia->bindValue(':destino', $idSucursal, PDO::PARAM_INT);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'codigoSeguimiento' => (string) $f['codigoSeguimiento'],
                'destinatario' => (string) $f['destinatario'],
                'tipoEntrega' => (string) $f['tipoEntrega'],
                'bultos' => (int) $f['bultos'],
                'pesoKg' => (string) $f['pesoKg'],
                'importe' => (string) $f['importe'],
                'pagado' => (string) $f['pagado'],
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function buscarEnvio(string $codigoSeguimiento): ?array
    {
        $pdo = Database::conexion();
        $sentencia = $pdo->prepare(
            "SELECT e.idEnvio, e.codigoSeguimiento, e.estadoEnvio, e.tipoEntrega, e.direccionEntrega, e.fechaHoraEntrega, r.idSucursalDestino,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    pd.tipoDocumento, pd.numeroDocumento, pd.nombreCompleto,
                    t.importe, t.pagado
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
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

        $bultos = $pdo->prepare('SELECT numeroBulto, descripcion, pesoKg FROM Bulto WHERE idEnvio = :envio ORDER BY numeroBulto');
        $bultos->bindValue(':envio', (int) $fila['idEnvio'], PDO::PARAM_INT);
        $bultos->execute();

        return [
            'codigoSeguimiento' => (string) $fila['codigoSeguimiento'],
            'estado' => (string) $fila['estadoEnvio'],
            'tipoEntrega' => (string) $fila['tipoEntrega'],
            'direccionEntrega' => $fila['direccionEntrega'] !== null ? (string) $fila['direccionEntrega'] : null,
            'fechaHoraEntrega' => $fila['fechaHoraEntrega'] !== null ? substr((string) $fila['fechaHoraEntrega'], 0, 19) : null,
            'idSucursalDestino' => (int) $fila['idSucursalDestino'],
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre']],
            'destinatario' => ['tipoDocumento' => (string) $fila['tipoDocumento'], 'numeroDocumento' => (string) $fila['numeroDocumento'], 'nombreCompleto' => (string) $fila['nombreCompleto']],
            'bultos' => array_map(
                static fn (array $b): array => ['numero' => (int) $b['numeroBulto'], 'descripcion' => (string) $b['descripcion'], 'pesoKg' => (string) $b['pesoKg']],
                $bultos->fetchAll(PDO::FETCH_ASSOC),
            ),
            'importe' => (string) $fila['importe'],
            'pagado' => (string) $fila['pagado'],
        ];
    }

    public function entregar(string $codigoSeguimiento, callable $decidir): ?bool
    {
        return $this->cambiarEstado(
            $codigoSeguimiento,
            $decidir,
            "UPDATE Envio SET estadoEnvio = 'ENTREGADO', fechaHoraEntrega = SYSDATETIME() WHERE idEnvio = :envio AND estadoEnvio = 'EN_DESTINO'",
        );
    }

    public function devolver(string $codigoSeguimiento, callable $decidir): ?bool
    {
        return $this->cambiarEstado(
            $codigoSeguimiento,
            $decidir,
            "UPDATE Envio SET estadoEnvio = 'DEVUELTO' WHERE idEnvio = :envio AND estadoEnvio = 'EN_DESTINO'",
        );
    }

    /** La sentencia es una constante de esta clase: nunca entrada del usuario. */
    private function cambiarEstado(string $codigoSeguimiento, callable $decidir, string $actualizacion): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            // 1) Bloquear el envío: un cobro simultáneo (Pagos) toma el mismo bloqueo y espera, y viceversa.
            $bloqueo = $pdo->prepare(
                'SELECT e.idEnvio, e.estadoEnvio, r.idSucursalDestino, pd.tipoDocumento, pd.numeroDocumento
                 FROM Envio AS e WITH (UPDLOCK, HOLDLOCK)
                 INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
                 INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
                 WHERE e.codigoSeguimiento = :codigo',
            );
            $bloqueo->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
            $bloqueo->execute();
            $envio = $bloqueo->fetch(PDO::FETCH_ASSOC);
            $bloqueo->closeCursor();
            if ($envio === false) {
                $pdo->rollBack();

                return null;
            }
            $idEnvio = (int) $envio['idEnvio'];

            // 2) Importe y lo pagado, leídos con el envío bloqueado.
            $importe = $this->suma($pdo, 'SELECT COALESCE(SUM(costo), 0) FROM Bulto WHERE idEnvio = :envio', $idEnvio);
            $pagado = $this->suma($pdo, "SELECT COALESCE(SUM(monto), 0) FROM Pago WHERE idEnvio = :envio AND estadoPago = 'REGISTRADO'", $idEnvio);

            $decidir(['envio' => [
                'idEnvio' => $idEnvio,
                'estado' => (string) $envio['estadoEnvio'],
                'idSucursalDestino' => (int) $envio['idSucursalDestino'],
                'destinatario' => ['tipoDocumento' => (string) $envio['tipoDocumento'], 'numeroDocumento' => (string) $envio['numeroDocumento']],
                'importe' => $importe,
                'pagado' => $pagado,
            ]]);

            $cambio = $pdo->prepare($actualizacion);
            $cambio->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
            $cambio->execute();
            if ($cambio->rowCount() !== 1) {
                $pdo->rollBack();

                return false;
            }

            $pdo->commit();

            return true;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    private function suma(PDO $pdo, string $sql, int $idEnvio): int
    {
        $sentencia = $pdo->prepare($sql);
        $sentencia->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
        $sentencia->execute();
        $suma = $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return (int) round(((float) (string) $suma) * 100);
    }
}
