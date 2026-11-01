<?php
declare(strict_types=1);

namespace Jatsa\Modules\Pagos;

use Jatsa\Core\Database;
use PDO;
use Throwable;

/**
 * Lee y escribe Pago con los nombres del script SQL definitivo. Siempre con parámetros.
 * Las escrituras son dos: registrar un pago (INSERT, en transacción) y anularlo (UPDATE del estado; nunca se borra).
 */
final class PagoRepository implements RepositorioPagos
{
    private const COLUMNAS = 'p.idPago, p.fechaHoraPago, p.monto, p.metodoPago, p.pagadoPor, p.estadoPago, p.idSucursal,
               e.codigoSeguimiento, s.codigoSucursal AS sucursalCodigo, s.nombre AS sucursalNombre';

    private const ORIGEN = 'FROM Pago AS p
        INNER JOIN Envio AS e ON e.idEnvio = p.idEnvio
        INNER JOIN Sucursal AS s ON s.idSucursal = p.idSucursal';

    /** Pagos que hizo la persona: paga el remitente de su envío o el destinatario del suyo. */
    private const DE_PERSONA = <<<'SQL'
        INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
        WHERE (p.pagadoPor = 'REMITENTE' AND r.idPersonaRemitente = :remitente)
           OR (p.pagadoPor = 'DESTINATARIO' AND r.idPersonaDestinatario = :destinatario)
        SQL;

    public function buscarEnvioParaCobro(string $codigoSeguimiento): ?array
    {
        $pdo = Database::conexion();
        $sentencia = $pdo->prepare(
            'SELECT e.idEnvio, e.codigoSeguimiento, e.estadoEnvio, r.idSucursalOrigen, r.idSucursalDestino,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre,
                    sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    pr.nombreCompleto AS remitenteNombre, pd.nombreCompleto AS destinatarioNombre,
                    (SELECT COALESCE(SUM(b.costo), 0) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS importe,
                    (SELECT COALESCE(SUM(p.monto), 0) FROM Pago AS p WHERE p.idEnvio = e.idEnvio AND p.estadoPago = \'REGISTRADO\') AS pagado
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Persona AS pr ON pr.idPersona = r.idPersonaRemitente
             INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
             INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE e.codigoSeguimiento = :codigo',
        );
        $sentencia->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();
        if ($fila === false) {
            return null;
        }

        $pagos = $pdo->prepare('SELECT ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE p.idEnvio = :envio ORDER BY p.fechaHoraPago DESC, p.idPago DESC');
        $pagos->bindValue(':envio', (int) $fila['idEnvio'], PDO::PARAM_INT);
        $pagos->execute();

        return [
            'codigoSeguimiento' => (string) $fila['codigoSeguimiento'],
            'estado' => (string) $fila['estadoEnvio'],
            'idSucursalOrigen' => (int) $fila['idSucursalOrigen'],
            'idSucursalDestino' => (int) $fila['idSucursalDestino'],
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre']],
            'remitente' => (string) $fila['remitenteNombre'],
            'destinatario' => (string) $fila['destinatarioNombre'],
            'importe' => (string) $fila['importe'],
            'pagado' => (string) $fila['pagado'],
            'pagos' => array_map([self::class, 'aPago'], $pagos->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    public function registrar(string $codigoSeguimiento, int $idSucursal, string $monto, string $metodoPago, string $pagadoPor, callable $decidir): ?int
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            // 1) Bloquear el envío. Mientras dure la transacción, otro cobro del MISMO envío espera aquí.
            $bloqueo = $pdo->prepare(
                'SELECT e.idEnvio, e.estadoEnvio, r.idSucursalOrigen, r.idSucursalDestino
                 FROM Envio AS e WITH (UPDLOCK, HOLDLOCK)
                 INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
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

            // 2) Importe y lo ya pagado, leídos con el envío bloqueado.
            $importe = $this->sumaDelEnvio($pdo, 'SELECT COALESCE(SUM(costo), 0) FROM Bulto WHERE idEnvio = :envio', $idEnvio);
            $pagado = $this->sumaDelEnvio($pdo, "SELECT COALESCE(SUM(monto), 0) FROM Pago WHERE idEnvio = :envio AND estadoPago = 'REGISTRADO'", $idEnvio);

            // 3) Reglas de negocio (sucursal, estado, saldo): las aplica el servicio y puede abortar.
            $decidir([
                'idEnvio' => $idEnvio,
                'estado' => (string) $envio['estadoEnvio'],
                'idSucursalOrigen' => (int) $envio['idSucursalOrigen'],
                'idSucursalDestino' => (int) $envio['idSucursalDestino'],
                'importe' => $importe,
                'pagado' => $pagado,
            ]);

            // 4) Alta del pago. La fecha y el estado (REGISTRADO) los pone la base por defecto.
            $alta = $pdo->prepare(
                'INSERT INTO Pago (idEnvio, idSucursal, monto, metodoPago, pagadoPor)
                 OUTPUT INSERTED.idPago
                 VALUES (:envio, :sucursal, :monto, :metodo, :pagadoPor)',
            );
            $alta->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
            $alta->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
            $alta->bindValue(':monto', $monto, PDO::PARAM_STR);
            $alta->bindValue(':metodo', $metodoPago, PDO::PARAM_STR);
            $alta->bindValue(':pagadoPor', $pagadoPor, PDO::PARAM_STR);
            $alta->execute();
            $idPago = (int) $alta->fetchColumn();
            $alta->closeCursor();

            $pdo->commit();

            return $idPago;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    public function buscarPago(int $idPago): ?array
    {
        $sentencia = Database::conexion()->prepare('SELECT ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE p.idPago = :id');
        $sentencia->bindValue(':id', $idPago, PDO::PARAM_INT);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();

        return $fila === false ? null : self::aPago($fila);
    }

    public function anular(int $idPago, int $idSucursal): bool
    {
        $sentencia = Database::conexion()->prepare(
            "UPDATE Pago SET estadoPago = 'ANULADO'
             WHERE idPago = :id AND idSucursal = :sucursal AND estadoPago = 'REGISTRADO'",
        );
        $sentencia->bindValue(':id', $idPago, PDO::PARAM_INT);
        $sentencia->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        $sentencia->execute();

        return $sentencia->rowCount() > 0;
    }

    public function listarDeSucursal(int $idSucursal, int $limite): array
    {
        // El límite es un entero que decide el servicio (nunca entrada del usuario).
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . ' ' . self::COLUMNAS . ' ' . self::ORIGEN
            . ' WHERE p.idSucursal = :sucursal ORDER BY p.fechaHoraPago DESC, p.idPago DESC',
        );
        $sentencia->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        $sentencia->execute();

        return array_map([self::class, 'aPago'], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function totalesDeSucursal(int $idSucursal): array
    {
        $sentencia = Database::conexion()->prepare(
            "SELECT metodoPago, SUM(monto) AS total FROM Pago
             WHERE idSucursal = :sucursal AND estadoPago = 'REGISTRADO'
             GROUP BY metodoPago",
        );
        $sentencia->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        $sentencia->execute();

        $totales = [];
        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $totales[(string) $fila['metodoPago']] = self::aCentimos((string) $fila['total']);
        }

        return $totales;
    }

    public function listarDePersona(int $idPersona, int $limite): array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . ' ' . self::COLUMNAS . ' ' . self::ORIGEN . ' ' . self::DE_PERSONA
            . ' ORDER BY p.fechaHoraPago DESC, p.idPago DESC',
        );
        $sentencia->bindValue(':remitente', $idPersona, PDO::PARAM_INT);
        $sentencia->bindValue(':destinatario', $idPersona, PDO::PARAM_INT);
        $sentencia->execute();

        return array_map([self::class, 'aPago'], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function totalPagadoDePersona(int $idPersona): int
    {
        $sentencia = Database::conexion()->prepare(
            "SELECT COALESCE(SUM(p.monto), 0) FROM Pago AS p
             INNER JOIN Envio AS e ON e.idEnvio = p.idEnvio
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             WHERE p.estadoPago = 'REGISTRADO'
               AND ((p.pagadoPor = 'REMITENTE' AND r.idPersonaRemitente = :remitente)
                 OR (p.pagadoPor = 'DESTINATARIO' AND r.idPersonaDestinatario = :destinatario))",
        );
        $sentencia->bindValue(':remitente', $idPersona, PDO::PARAM_INT);
        $sentencia->bindValue(':destinatario', $idPersona, PDO::PARAM_INT);
        $sentencia->execute();
        $total = $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return self::aCentimos((string) $total);
    }

    private function sumaDelEnvio(PDO $pdo, string $sql, int $idEnvio): int
    {
        $sentencia = $pdo->prepare($sql);
        $sentencia->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
        $sentencia->execute();
        $suma = $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return self::aCentimos((string) $suma);
    }

    /** @param array<string, mixed> $fila */
    private static function aPago(array $fila): array
    {
        return [
            'idPago' => (int) $fila['idPago'],
            'fechaHoraPago' => substr((string) $fila['fechaHoraPago'], 0, 19),
            'monto' => (string) $fila['monto'],
            'metodoPago' => (string) $fila['metodoPago'],
            'pagadoPor' => (string) $fila['pagadoPor'],
            'estadoPago' => (string) $fila['estadoPago'],
            'codigoSeguimiento' => (string) $fila['codigoSeguimiento'],
            'idSucursal' => (int) $fila['idSucursal'],
            'sucursalCodigo' => (string) $fila['sucursalCodigo'],
            'sucursalNombre' => (string) $fila['sucursalNombre'],
        ];
    }

    /** "23.75" → 2375 (DECIMAL de la base, con a lo sumo 2 decimales). */
    private static function aCentimos(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
