<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateTimeImmutable;
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
               e.codigoSeguimiento,
               CASE WHEN r.estadoReserva = 'PENDIENTE'
                     AND r.fechaHoraReserva <= DATEADD(HOUR, -%HORAS%, SYSDATETIME()) THEN 1 ELSE 0 END AS plazoCumplido
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
        return $this->insertarReserva($idRemitente, null, $idDestinatario, $destinatarioNuevo, $codigoOrigen, $codigoDestino, $contenido, 'WEB');
    }

    public function crearEnVentanilla(?int $idRemitente, ?array $remitenteNuevo, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string
    {
        return $this->insertarReserva($idRemitente, $remitenteNuevo, $idDestinatario, $destinatarioNuevo, $codigoOrigen, $codigoDestino, $contenido, 'VENTANILLA');
    }

    /**
     * Persona (si es nueva) + reserva en una sola transacción. El canal es siempre un literal del código
     * ('WEB' o 'VENTANILLA'), nunca entrada del usuario.
     *
     * @param array<string, string>|null $remitenteNuevo
     * @param array<string, string>|null $destinatarioNuevo
     */
    private function insertarReserva(?int $idRemitente, ?array $remitenteNuevo, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido, string $canal): string
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            $idRemitente ??= $this->insertarPersona($pdo, (array) $remitenteNuevo);
            $idDestinatario ??= $this->insertarPersona($pdo, (array) $destinatarioNuevo);

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
                         '" . ($canal === 'VENTANILLA' ? 'VENTANILLA' : 'WEB') . "', :contenido)",
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

    /**
     * Inserta una persona nueva y devuelve su id. Va dentro de la transacción de quien la llama.
     *
     * @param array<string, string> $persona tipoDocumento, numeroDocumento, nombreCompleto, telefono
     */
    private function insertarPersona(PDO $pdo, array $persona): int
    {
        $alta = $pdo->prepare(
            'INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono)
             VALUES (:tipo, :numero, :nombre, :telefono)',
        );
        $alta->bindValue(':tipo', $persona['tipoDocumento'], PDO::PARAM_STR);
        $alta->bindValue(':numero', $persona['numeroDocumento'], PDO::PARAM_STR);
        $alta->bindValue(':nombre', $persona['nombreCompleto'], PDO::PARAM_STR);
        $alta->bindValue(':telefono', $persona['telefono'], PDO::PARAM_STR);
        $alta->execute();

        $buscada = $pdo->prepare('SELECT idPersona FROM Persona WHERE tipoDocumento = :tipo AND numeroDocumento = :numero');
        $buscada->bindValue(':tipo', $persona['tipoDocumento'], PDO::PARAM_STR);
        $buscada->bindValue(':numero', $persona['numeroDocumento'], PDO::PARAM_STR);
        $buscada->execute();

        return (int) $buscada->fetchColumn();
    }

    public function listarPendientesDeSucursal(int $idSucursal, ?DateTimeImmutable $referencia = null): array
    {
        $sql = self::consultaDeSucursal() . " AND r.estadoReserva = 'PENDIENTE' AND r.fechaHoraReserva > " . self::limite($referencia)
            . ' ORDER BY r.fechaHoraReserva, r.idReserva';
        $sentencia = Database::conexion()->prepare($sql);
        $sentencia->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        self::enlazarReferencia($sentencia, $referencia);
        $sentencia->execute();

        return array_map(fn (array $fila): array => $this->aReservaDeSucursal($fila), $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function buscarDeSucursal(int $idSucursal, string $codigo): ?array
    {
        $sentencia = Database::conexion()->prepare(self::consultaDeSucursal() . ' AND r.codigoReserva = :codigo');
        $sentencia->bindValue(':sucursal', $idSucursal, PDO::PARAM_INT);
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $this->aReservaDeSucursal($fila);
    }

    private const CONSULTA_DE_SUCURSAL = <<<'SQL'
        SELECT r.codigoReserva, r.fechaHoraReserva, r.canal, r.descripcion, r.estadoReserva,
               pr.tipoDocumento AS remitenteTipo, pr.numeroDocumento AS remitenteNumero, pr.nombreCompleto AS remitenteNombre,
               pd.tipoDocumento AS destinatarioTipo, pd.numeroDocumento AS destinatarioNumero, pd.nombreCompleto AS destinatarioNombre,
               so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, so.direccion AS origenDireccion, so.departamento AS origenDepartamento,
               sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre, sd.direccion AS destinoDireccion, sd.departamento AS destinoDepartamento,
               e.codigoSeguimiento,
               CASE WHEN r.estadoReserva = 'PENDIENTE'
                     AND r.fechaHoraReserva <= DATEADD(HOUR, -%HORAS%, SYSDATETIME()) THEN 1 ELSE 0 END AS plazoCumplido
        FROM Reserva AS r
        INNER JOIN Persona AS pr ON pr.idPersona = r.idPersonaRemitente
        INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
        INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
        INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
        LEFT JOIN Envio AS e ON e.idReserva = r.idReserva
        WHERE r.idSucursalOrigen = :sucursal
        SQL;

    private static function consultaDeSucursal(): string
    {
        return str_replace('%HORAS%', (string) self::HORAS_DE_VIGENCIA, self::CONSULTA_DE_SUCURSAL);
    }

    /**
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function aReservaDeSucursal(array $fila): array
    {
        $persona = static fn (string $prefijo): array => [
            'tipoDocumento' => (string) $fila[$prefijo . 'Tipo'],
            'numeroDocumento' => (string) $fila[$prefijo . 'Numero'],
            'nombreCompleto' => (string) $fila[$prefijo . 'Nombre'],
        ];

        return [
            'codigo' => (string) $fila['codigoReserva'],
            'fechaHora' => (string) $fila['fechaHoraReserva'],
            'canal' => (string) $fila['canal'],
            'contenido' => (string) $fila['descripcion'],
            'estado' => (string) $fila['estadoReserva'],
            'remitente' => $persona('remitente'),
            'destinatario' => $persona('destinatario'),
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre'], 'direccion' => (string) $fila['origenDireccion'], 'departamento' => (string) $fila['origenDepartamento']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre'], 'direccion' => (string) $fila['destinoDireccion'], 'departamento' => (string) $fila['destinoDepartamento']],
            'codigoSeguimiento' => $fila['codigoSeguimiento'] !== null ? (string) $fila['codigoSeguimiento'] : null,
            'plazoCumplido' => (int) $fila['plazoCumplido'] === 1,
        ];
    }

    public function listarDeRemitente(int $idPersona, ?string $estado): array
    {
        $sql = self::consulta() . ($estado !== null ? ' AND r.estadoReserva = :estado' : '')
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
        $sentencia = Database::conexion()->prepare(self::consulta() . ' AND r.codigoReserva = :codigo');
        $sentencia->bindValue(':persona', $idPersona, PDO::PARAM_INT);
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $this->aReserva($fila);
    }

    public function cancelar(int $idPersona, string $codigo, ?DateTimeImmutable $referencia = null): bool
    {
        // Condicionado a que siga PENDIENTE y dentro del plazo: una reserva que ya cumplió las horas de vigencia no
        // se cancela como pendiente (en el instante límite ya vence). Atómico frente a vencerPendientes().
        $sentencia = Database::conexion()->prepare(
            "UPDATE Reserva SET estadoReserva = 'CANCELADA'
             WHERE codigoReserva = :codigo AND idPersonaRemitente = :persona AND estadoReserva = 'PENDIENTE'
               AND fechaHoraReserva > " . self::limite($referencia),
        );
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->bindValue(':persona', $idPersona, PDO::PARAM_INT);
        self::enlazarReferencia($sentencia, $referencia);
        $sentencia->execute();

        return $sentencia->rowCount() > 0;
    }

    public function vencerPendientes(?DateTimeImmutable $referencia = null): int
    {
        $sentencia = Database::conexion()->prepare(
            "UPDATE Reserva SET estadoReserva = 'VENCIDA'
             WHERE estadoReserva = 'PENDIENTE' AND fechaHoraReserva <= " . self::limite($referencia),
        );
        self::enlazarReferencia($sentencia, $referencia);
        $sentencia->execute();

        return $sentencia->rowCount();
    }

    public function listarVencibles(?DateTimeImmutable $referencia = null): array
    {
        $sentencia = Database::conexion()->prepare(
            "SELECT codigoReserva, fechaHoraReserva FROM Reserva
             WHERE estadoReserva = 'PENDIENTE' AND fechaHoraReserva <= " . self::limite($referencia) . '
             ORDER BY fechaHoraReserva, idReserva',
        );
        self::enlazarReferencia($sentencia, $referencia);
        $sentencia->execute();

        return array_map(
            static fn (array $fila): array => ['codigo' => (string) $fila['codigoReserva'], 'fechaHora' => (string) $fila['fechaHoraReserva']],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** Consulta base con las horas de vigencia (constante) ya sustituidas. */
    private static function consulta(): string
    {
        return str_replace('%HORAS%', (string) self::HORAS_DE_VIGENCIA, self::CONSULTA);
    }

    /**
     * Expresión SQL del instante límite: «ahora − vigencia». Con referencia usa ese instante (parámetro :referencia);
     * sin ella, el reloj de SQL Server. Las horas son una constante, nunca entrada del usuario.
     */
    private static function limite(?DateTimeImmutable $referencia): string
    {
        $ahora = $referencia === null ? 'SYSDATETIME()' : 'CAST(:referencia AS DATETIME2(0))';

        return 'DATEADD(HOUR, -' . self::HORAS_DE_VIGENCIA . ', ' . $ahora . ')';
    }

    private static function enlazarReferencia(\PDOStatement $sentencia, ?DateTimeImmutable $referencia): void
    {
        if ($referencia !== null) {
            $sentencia->bindValue(':referencia', $referencia->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        }
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
            'plazoCumplido' => (int) $fila['plazoCumplido'] === 1,
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
