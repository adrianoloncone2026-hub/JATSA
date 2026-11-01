<?php
declare(strict_types=1);

namespace Jatsa\Modules\Usuarios;

use Jatsa\Core\Database;
use Jatsa\Shared\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Lee y escribe Usuario (con Persona y Sucursal) para la gestión de cuentas del GERENTE. Consultas parametrizadas;
 * las escrituras que dependen de otros datos van en transacción con las filas bloqueadas. No contiene reglas de negocio.
 */
final class UsuariosRepository implements RepositorioGestionUsuarios
{
    private const ERROR_CLAVE_DUPLICADA = [2601, 2627];

    private const SELECT_CUENTA = 'SELECT u.nombreUsuario, pe.nombreCompleto, u.rol, s.codigoSucursal AS sucursalCodigo, s.nombre AS sucursalNombre, u.activo
        FROM Usuario AS u
        INNER JOIN Persona AS pe ON pe.idPersona = u.idPersona
        LEFT JOIN Sucursal AS s ON s.idSucursal = u.idSucursal';

    public function listar(array $filtros, int $limite): array
    {
        $donde = [];
        $valores = [];
        if ($filtros['rol'] !== '') {
            $donde[] = 'u.rol = :rol';
            $valores[':rol'] = $filtros['rol'];
        }
        if ($filtros['estado'] !== '') {
            $donde[] = 'u.activo = :activo';
            $valores[':activo'] = $filtros['estado'] === 'ACTIVA' ? 1 : 0;
        }
        if ($filtros['q'] !== '') {
            $patron = '%' . strtr($filtros['q'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '[' => '\\[']) . '%';
            $donde[] = "(u.nombreUsuario LIKE :q1 ESCAPE '\\' OR pe.nombreCompleto LIKE :q2 ESCAPE '\\')";
            $valores[':q1'] = $patron;
            $valores[':q2'] = $patron;
        }

        // El tope es un entero que decide el servicio (nunca entrada del usuario); las partes opcionales salen de una lista cerrada.
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . ' ' . substr(self::SELECT_CUENTA, 7) . ($donde === [] ? '' : ' WHERE ' . implode(' AND ', $donde)) . ' ORDER BY u.nombreUsuario',
        );
        foreach ($valores as $nombre => $valor) {
            $sentencia->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $sentencia->execute();

        return array_map([self::class, 'aCuenta'], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function personaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array
    {
        $fila = $this->uno(
            Database::conexion(),
            'SELECT pe.idPersona, pe.nombreCompleto, CASE WHEN EXISTS (SELECT 1 FROM Usuario AS u WHERE u.idPersona = pe.idPersona) THEN 1 ELSE 0 END AS tieneUsuario
             FROM Persona AS pe WHERE pe.tipoDocumento = :tipo AND pe.numeroDocumento = :numero',
            [':tipo' => $tipoDocumento, ':numero' => $numeroDocumento],
        );

        return $fila === null ? null : ['idPersona' => (int) $fila['idPersona'], 'nombreCompleto' => (string) $fila['nombreCompleto'], 'tieneUsuario' => (bool) $fila['tieneUsuario']];
    }

    public function usuarioExiste(string $nombreUsuario): bool
    {
        return $this->uno(Database::conexion(), 'SELECT 1 AS existe FROM Usuario WHERE nombreUsuario = :usuario', [':usuario' => $nombreUsuario]) !== null;
    }

    public function idSucursalActiva(string $codigo): ?int
    {
        $fila = $this->uno(Database::conexion(), 'SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :codigo AND activa = 1', [':codigo' => $codigo]);

        return $fila === null ? null : (int) $fila['idSucursal'];
    }

    public function crearCuenta(int $idPersona, ?int $idSucursal, string $nombreUsuario, string $hashContrasena, string $rol): void
    {
        $pdo = Database::conexion();
        try {
            $alta = $pdo->prepare(
                'INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol, activo) VALUES (:persona, :sucursal, :usuario, :hash, :rol, 1)',
            );
            $alta->bindValue(':persona', $idPersona, PDO::PARAM_INT);
            $alta->bindValue(':sucursal', $idSucursal, $idSucursal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $alta->bindValue(':usuario', $nombreUsuario, PDO::PARAM_STR);
            $alta->bindValue(':hash', $hashContrasena, PDO::PARAM_STR);
            $alta->bindValue(':rol', $rol, PDO::PARAM_STR);
            $alta->execute();
        } catch (Throwable $e) {
            if ($e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), self::ERROR_CLAVE_DUPLICADA, true)) {
                if (str_contains($e->getMessage(), 'UQ_USUARIO_NOMBRE')) {
                    throw new HttpException(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', [], ['usuario' => 'Ese usuario ya está en uso. Elige otro.']);
                }
                throw new HttpException(409, 'cuenta_existente', 'Esa persona ya tiene una cuenta.', [], ['numeroDocumento' => 'Esa persona ya tiene una cuenta.']);
            }
            throw $e;
        }
    }

    public function cuenta(string $nombreUsuario): ?array
    {
        $fila = $this->uno(Database::conexion(), self::SELECT_CUENTA . ' WHERE u.nombreUsuario = :usuario', [':usuario' => $nombreUsuario]);

        return $fila === null ? null : self::aCuenta($fila);
    }

    public function cambiarPuesto(string $nombreUsuario, string $rol, ?int $idSucursal, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $fila = $this->uno($pdo, 'SELECT idUsuario, rol, activo FROM Usuario WITH (UPDLOCK, HOLDLOCK) WHERE nombreUsuario = :usuario', [':usuario' => $nombreUsuario]);
            if ($fila === null) {
                $pdo->rollBack();

                return null;
            }
            $id = (int) $fila['idUsuario'];
            $decidir(['rol' => (string) $fila['rol'], 'activo' => (bool) $fila['activo'], 'viajesAbiertos' => $this->viajesAbiertos($pdo, $id)]);

            $cambio = $pdo->prepare('UPDATE Usuario SET rol = :rol, idSucursal = :sucursal WHERE idUsuario = :id');
            $cambio->bindValue(':rol', $rol, PDO::PARAM_STR);
            $cambio->bindValue(':sucursal', $idSucursal, $idSucursal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $cambio->bindValue(':id', $id, PDO::PARAM_INT);
            $cambio->execute();
            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            // Orden constante de bloqueos: primero los gerentes (todos), luego la cuenta.
            $gerentes = $pdo->query("SELECT COUNT(*) FROM Usuario WITH (UPDLOCK, HOLDLOCK) WHERE rol = 'GERENTE' AND activo = 1");
            $gerentesActivos = (int) ($gerentes === false ? 0 : $gerentes->fetchColumn());
            $fila = $this->uno($pdo, 'SELECT idUsuario, rol, activo FROM Usuario WITH (UPDLOCK, HOLDLOCK) WHERE nombreUsuario = :usuario', [':usuario' => $nombreUsuario]);
            if ($fila === null) {
                $pdo->rollBack();

                return null;
            }
            $id = (int) $fila['idUsuario'];
            $decidir([
                'rol' => (string) $fila['rol'], 'activo' => (bool) $fila['activo'],
                'viajesAbiertos' => $this->viajesAbiertos($pdo, $id), 'gerentesActivos' => $gerentesActivos,
            ]);

            $cambio = $pdo->prepare('UPDATE Usuario SET activo = :activo WHERE idUsuario = :id');
            $cambio->bindValue(':activo', $activo ? 1 : 0, PDO::PARAM_INT);
            $cambio->bindValue(':id', $id, PDO::PARAM_INT);
            $cambio->execute();
            $pdo->commit();

            return $activo;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function viajesAbiertos(PDO $pdo, int $idUsuario): int
    {
        $sentencia = $pdo->prepare('SELECT COUNT(*) FROM Viaje WHERE idUsuarioTransportista = :id AND fechaHoraLlegada IS NULL');
        $sentencia->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $sentencia->execute();
        $total = (int) $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return $total;
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private static function aCuenta(array $f): array
    {
        return [
            'nombreUsuario' => (string) $f['nombreUsuario'], 'nombreCompleto' => (string) $f['nombreCompleto'], 'rol' => (string) $f['rol'],
            'sucursalCodigo' => $f['sucursalCodigo'] === null ? null : (string) $f['sucursalCodigo'],
            'sucursalNombre' => $f['sucursalNombre'] === null ? null : (string) $f['sucursalNombre'], 'activo' => (bool) $f['activo'],
        ];
    }

    /**
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>|null
     */
    private function uno(PDO $pdo, string $sql, array $parametros): ?array
    {
        $sentencia = $pdo->prepare($sql);
        foreach ($parametros as $nombre => $valor) {
            $sentencia->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();

        return $fila === false ? null : $fila;
    }
}
