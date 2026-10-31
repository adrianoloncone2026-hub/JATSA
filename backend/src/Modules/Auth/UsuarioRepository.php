<?php
declare(strict_types=1);

namespace Jatsa\Modules\Auth;

use Jatsa\Core\Database;
use PDO;

/**
 * Lee Usuario, Persona y Sucursal (solo SELECT, siempre con parámetros).
 * Usa los nombres de tabla y columna del script SQL definitivo.
 */
final class UsuarioRepository implements RepositorioUsuarios
{
    private const CONSULTA = <<<'SQL'
        SELECT u.idUsuario, u.nombreUsuario, u.hashContrasena, u.rol, u.activo, u.idSucursal,
               p.nombreCompleto, s.codigoSucursal, s.nombre AS nombreSucursal
        FROM Usuario AS u
        INNER JOIN Persona AS p ON p.idPersona = u.idPersona
        LEFT JOIN Sucursal AS s ON s.idSucursal = u.idSucursal
        SQL;

    public function buscarPorNombre(string $nombreUsuario): ?array
    {
        return $this->uno(self::CONSULTA . ' WHERE u.nombreUsuario = :valor', $nombreUsuario, PDO::PARAM_STR);
    }

    public function buscarPorId(int $idUsuario): ?array
    {
        return $this->uno(self::CONSULTA . ' WHERE u.idUsuario = :valor', $idUsuario, PDO::PARAM_INT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function uno(string $sql, string|int $valor, int $tipo): ?array
    {
        $sentencia = Database::conexion()->prepare($sql);
        $sentencia->bindValue(':valor', $valor, $tipo);
        $sentencia->execute();

        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        return [
            'idUsuario' => (int) $fila['idUsuario'],
            'nombreUsuario' => (string) $fila['nombreUsuario'],
            'hashContrasena' => (string) $fila['hashContrasena'],
            'rol' => (string) $fila['rol'],
            'activo' => (int) $fila['activo'] === 1,
            'idSucursal' => $fila['idSucursal'] !== null ? (int) $fila['idSucursal'] : null,
            'nombreCompleto' => (string) $fila['nombreCompleto'],
            'codigoSucursal' => $fila['codigoSucursal'] !== null ? (string) $fila['codigoSucursal'] : null,
            'nombreSucursal' => $fila['nombreSucursal'] !== null ? (string) $fila['nombreSucursal'] : null,
        ];
    }
}
