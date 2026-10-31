<?php
declare(strict_types=1);

namespace Jatsa\Modules\Cuenta;

use Jatsa\Core\Database;
use Jatsa\Shared\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Lee y escribe Persona y Usuario con los nombres del script SQL definitivo. Siempre con parámetros.
 * Las escrituras son: alta de persona/usuario, actualización de contacto y cambio de hash.
 */
final class CuentaRepository implements RepositorioCuentas
{
    private const ERROR_CLAVE_DUPLICADA = [2627, 2601];

    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT p.idPersona,
                    CASE WHEN EXISTS (SELECT 1 FROM Usuario AS u WHERE u.idPersona = p.idPersona) THEN 1 ELSE 0 END AS tieneUsuario
             FROM Persona AS p
             WHERE p.tipoDocumento = :tipo AND p.numeroDocumento = :numero',
        );
        $sentencia->bindValue(':tipo', $tipoDocumento, PDO::PARAM_STR);
        $sentencia->bindValue(':numero', $numeroDocumento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : ['idPersona' => (int) $fila['idPersona'], 'tieneUsuario' => (int) $fila['tieneUsuario'] === 1];
    }

    public function usuarioExiste(string $nombreUsuario): bool
    {
        $sentencia = Database::conexion()->prepare('SELECT COUNT(*) FROM Usuario WHERE nombreUsuario = :nombre');
        $sentencia->bindValue(':nombre', $nombreUsuario, PDO::PARAM_STR);
        $sentencia->execute();

        return (int) $sentencia->fetchColumn() > 0;
    }

    public function registrar(?int $idPersona, array $persona, string $nombreUsuario, string $hashContrasena, string $rol): void
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            if ($idPersona === null) {
                $alta = $pdo->prepare(
                    'INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono, telefonoAuxiliar, correo)
                     VALUES (:tipo, :numero, :nombre, :telefono, :auxiliar, :correo)',
                );
                $alta->bindValue(':tipo', $persona['tipoDocumento'], PDO::PARAM_STR);
                $alta->bindValue(':numero', $persona['numeroDocumento'], PDO::PARAM_STR);
                $alta->bindValue(':nombre', $persona['nombreCompleto'], PDO::PARAM_STR);
                $alta->bindValue(':telefono', $persona['telefono'], PDO::PARAM_STR);
                $alta->bindValue(':auxiliar', $persona['telefonoAuxiliar'], $persona['telefonoAuxiliar'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $alta->bindValue(':correo', $persona['correo'], $persona['correo'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $alta->execute();

                $buscada = $pdo->prepare('SELECT idPersona FROM Persona WHERE tipoDocumento = :tipo AND numeroDocumento = :numero');
                $buscada->bindValue(':tipo', $persona['tipoDocumento'], PDO::PARAM_STR);
                $buscada->bindValue(':numero', $persona['numeroDocumento'], PDO::PARAM_STR);
                $buscada->execute();
                $idPersona = (int) $buscada->fetchColumn();
            }

            // Cliente y empresario no pertenecen a una sucursal (CK_USUARIO_SUCURSAL): idSucursal = NULL.
            $usuario = $pdo->prepare(
                'INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol, activo)
                 VALUES (:persona, NULL, :nombre, :hash, :rol, 1)',
            );
            $usuario->bindValue(':persona', $idPersona, PDO::PARAM_INT);
            $usuario->bindValue(':nombre', $nombreUsuario, PDO::PARAM_STR);
            $usuario->bindValue(':hash', $hashContrasena, PDO::PARAM_STR);
            $usuario->bindValue(':rol', $rol, PDO::PARAM_STR);
            $usuario->execute();

            $pdo->commit();
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->traducir($excepcion);
        }
    }

    public function buscarCuenta(int $idUsuario): ?array
    {
        $sentencia = Database::conexion()->prepare(
            'SELECT u.idUsuario, u.idPersona, u.nombreUsuario, u.rol, u.activo, u.fechaHoraCreacion,
                    p.tipoDocumento, p.numeroDocumento, p.nombreCompleto, p.telefono, p.telefonoAuxiliar, p.correo
             FROM Usuario AS u
             INNER JOIN Persona AS p ON p.idPersona = u.idPersona
             WHERE u.idUsuario = :id',
        );
        $sentencia->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        return [
            'idUsuario' => (int) $fila['idUsuario'],
            'idPersona' => (int) $fila['idPersona'],
            'nombreUsuario' => (string) $fila['nombreUsuario'],
            'rol' => (string) $fila['rol'],
            'activo' => (int) $fila['activo'] === 1,
            'fechaCreacion' => substr((string) $fila['fechaHoraCreacion'], 0, 10),
            'tipoDocumento' => (string) $fila['tipoDocumento'],
            'numeroDocumento' => (string) $fila['numeroDocumento'],
            'nombreCompleto' => (string) $fila['nombreCompleto'],
            'telefono' => (string) $fila['telefono'],
            'telefonoAuxiliar' => $fila['telefonoAuxiliar'] !== null ? (string) $fila['telefonoAuxiliar'] : null,
            'correo' => $fila['correo'] !== null ? (string) $fila['correo'] : null,
        ];
    }

    public function actualizarContacto(int $idPersona, string $telefono, ?string $telefonoAuxiliar, ?string $correo): void
    {
        $sentencia = Database::conexion()->prepare(
            'UPDATE Persona SET telefono = :telefono, telefonoAuxiliar = :auxiliar, correo = :correo WHERE idPersona = :id',
        );
        $sentencia->bindValue(':telefono', $telefono, PDO::PARAM_STR);
        $sentencia->bindValue(':auxiliar', $telefonoAuxiliar, $telefonoAuxiliar === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sentencia->bindValue(':correo', $correo, $correo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sentencia->bindValue(':id', $idPersona, PDO::PARAM_INT);
        $sentencia->execute();
    }

    public function obtenerHash(int $idUsuario): ?string
    {
        $sentencia = Database::conexion()->prepare('SELECT hashContrasena FROM Usuario WHERE idUsuario = :id');
        $sentencia->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $sentencia->execute();
        $hash = $sentencia->fetchColumn();

        return $hash === false ? null : (string) $hash;
    }

    public function actualizarHash(int $idUsuario, string $hashContrasena): void
    {
        $sentencia = Database::conexion()->prepare('UPDATE Usuario SET hashContrasena = :hash WHERE idUsuario = :id');
        $sentencia->bindValue(':hash', $hashContrasena, PDO::PARAM_STR);
        $sentencia->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $sentencia->execute();
    }

    /**
     * Convierte la violación de una clave única en un 409 comprensible; cualquier otro error se relanza.
     */
    private function traducir(Throwable $excepcion): Throwable
    {
        if ($excepcion instanceof PDOException) {
            $codigo = (int) ($excepcion->errorInfo[1] ?? 0);
            $mensaje = $excepcion->getMessage();

            if (in_array($codigo, self::ERROR_CLAVE_DUPLICADA, true)) {
                if (str_contains($mensaje, 'UQ_USUARIO_NOMBRE')) {
                    return new HttpException(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', [], ['usuario' => 'Ese usuario ya está en uso. Elige otro.']);
                }

                return new HttpException(409, 'cuenta_existente', CuentaService::MENSAJE_CUENTA_EXISTENTE);
            }
        }

        return $excepcion;
    }
}
