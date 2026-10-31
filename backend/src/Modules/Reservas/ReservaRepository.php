<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use Jatsa\Core\Database;
use Jatsa\Shared\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Lee y escribe Reserva y Persona con los nombres del script SQL definitivo. Siempre con parámetros.
 * Escrituras: alta de reserva (y de la persona destinataria si es nueva) y cancelación.
 */
final class ReservaRepository implements RepositorioReservas
{
    private const ERROR_CLAVE_DUPLICADA = [2627, 2601];

    private const CONSULTA = <<<'SQL'
        SELECT r.codigoReserva, r.fechaHoraReserva, r.canal, r.descripcion, r.estadoReserva,
               d.nombreCompleto AS destinatario,
               so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, so.direccion AS origenDireccion, so.departamento AS origenDepartamento,
               sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre, sd.direccion AS destinoDireccion, sd.departamento AS destinoDepartamento,
               e.codigoSeguimiento
        FROM Reserva AS r
        INNER JOIN Persona AS d ON d.idPersona = r.idPersonaDestinatario
        INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
        INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
        LEFT JOIN Envio AS e ON e.idReserva = r.idReserva
        WHERE r.idPersonaRemitente = :persona
        SQL;

    public function idPersonaDeUsuario(int $idUsuario): ?int
    {
        $sentencia = Database::conexion()->prepare('SELECT idPersona FROM Usuario WHERE idUsuario = :id');
        $sentencia->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $sentencia->execute();
        $id = $sentencia->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT idPersona, nombreCompleto FROM Persona WHERE tipoDocumento = :tipo AND numeroDocumento = :numero',
        );
        $sentencia->bindValue(':tipo', $tipoDocumento, PDO::PARAM_STR);
        $sentencia->bindValue(':numero', $numeroDocumento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : ['idPersona' => (int) $fila['idPersona'], 'nombreCompleto' => (string) $fila['nombreCompleto']];
    }

    public function crear(int $idRemitente, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            if ($idDestinatario === null) {
                $persona = $pdo->prepare(
                    'INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono)
                     VALUES (:tipo, :numero, :nombre, :telefono)',
                );
                $persona->bindValue(':tipo', $destinatarioNuevo['tipoDocumento'], PDO::PARAM_STR);
                $persona->bindValue(':numero', $destinatarioNuevo['numeroDocumento'], PDO::PARAM_STR);
                $persona->bindValue(':nombre', $destinatarioNuevo['nombreCompleto'], PDO::PARAM_STR);
                $persona->bindValue(':telefono', $destinatarioNuevo['telefono'], PDO::PARAM_STR);
                $persona->execute();

                $buscada = $pdo->prepare('SELECT idPersona FROM Persona WHERE tipoDocumento = :tipo AND numeroDocumento = :numero');
                $buscada->bindValue(':tipo', $destinatarioNuevo['tipoDocumento'], PDO::PARAM_STR);
                $buscada->bindValue(':numero', $destinatarioNuevo['numeroDocumento'], PDO::PARAM_STR);
                $buscada->execute();
                $idDestinatario = (int) $buscada->fetchColumn();
            }

            // Siguiente código R + 9 dígitos. UPDLOCK + HOLDLOCK serializan a quienes reservan a la vez,
            // de modo que dos reservas simultáneas no obtienen el mismo número.
            $siguiente = (int) $pdo
                ->query('SELECT ISNULL(MAX(CAST(SUBSTRING(codigoReserva, 2, 9) AS INT)), 0) + 1 FROM Reserva WITH (UPDLOCK, HOLDLOCK)')
                ->fetchColumn();
            $codigo = sprintf('R%09d', $siguiente);

            // Estado PENDIENTE y fecha: valores por defecto de la tabla. Las sucursales se resuelven por código y solo si están activas.
            $reserva = $pdo->prepare(
                "INSERT INTO Reserva (codigoReserva, idPersonaRemitente, idPersonaDestinatario, idSucursalOrigen, idSucursalDestino, canal, descripcion)
                 VALUES (:codigo, :remitente, :destinatario,
                         (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :origen AND activa = 1),
                         (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :destino AND activa = 1),
                         'WEB', :contenido)",
            );
            $reserva->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $reserva->bindValue(':remitente', $idRemitente, PDO::PARAM_INT);
            $reserva->bindValue(':destinatario', $idDestinatario, PDO::PARAM_INT);
            $reserva->bindValue(':origen', $codigoOrigen, PDO::PARAM_STR);
            $reserva->bindValue(':destino', $codigoDestino, PDO::PARAM_STR);
            $reserva->bindValue(':contenido', $contenido, PDO::PARAM_STR);
            $reserva->execute();

            $pdo->commit();

            return $codigo;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->traducir($excepcion);
        }
    }

    public function listarDeRemitente(int $idPersona, ?string $estado): array
    {
        $sql = self::CONSULTA . ($estado !== null ? ' AND r.estadoReserva = :estado' : '')
            . ' ORDER BY r.fechaHoraReserva DESC, r.idReserva DESC';
        $sentencia = Database::conexion()->prepare($sql);
        $sentencia->bindValue(':persona', $idPersona, PDO::PARAM_INT);
        if ($estado !== null) {
            $sentencia->bindValue(':estado', $estado, PDO::PARAM_STR);
        }
        $sentencia->execute();

        return array_map(fn (array $fila): array => $this->aReserva($fila), $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function buscarDeRemitente(int $idPersona, string $codigo): ?array
    {
        $sentencia = Database::conexion()->prepare(self::CONSULTA . ' AND r.codigoReserva = :codigo');
        $sentencia->bindValue(':persona', $idPersona, PDO::PARAM_INT);
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $this->aReserva($fila);
    }

    public function cancelar(int $idPersona, string $codigo): bool
    {
        $sentencia = Database::conexion()->prepare(
            "UPDATE Reserva SET estadoReserva = 'CANCELADA'
             WHERE codigoReserva = :codigo AND idPersonaRemitente = :persona AND estadoReserva = 'PENDIENTE'",
        );
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->bindValue(':persona', $idPersona, PDO::PARAM_INT);
        $sentencia->execute();

        return $sentencia->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aReserva(array $fila): array
    {
        return [
            'codigo' => (string) $fila['codigoReserva'],
            'fechaHora' => (string) $fila['fechaHoraReserva'],
            'canal' => (string) $fila['canal'],
            'contenido' => (string) $fila['descripcion'],
            'estado' => (string) $fila['estadoReserva'],
            'destinatario' => (string) $fila['destinatario'],
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre'], 'direccion' => (string) $fila['origenDireccion'], 'departamento' => (string) $fila['origenDepartamento']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre'], 'direccion' => (string) $fila['destinoDireccion'], 'departamento' => (string) $fila['destinoDepartamento']],
            'codigoSeguimiento' => $fila['codigoSeguimiento'] !== null ? (string) $fila['codigoSeguimiento'] : null,
        ];
    }

    /** Una clave duplicada (otra persona o reserva creada a la vez) se vuelve un 409 comprensible. */
    private function traducir(Throwable $excepcion): Throwable
    {
        if ($excepcion instanceof PDOException && in_array((int) ($excepcion->errorInfo[1] ?? 0), self::ERROR_CLAVE_DUPLICADA, true)) {
            return new HttpException(409, 'conflicto', 'No se pudo registrar la reserva porque otra operación se cruzó. Inténtalo de nuevo.');
        }

        return $excepcion;
    }
}
