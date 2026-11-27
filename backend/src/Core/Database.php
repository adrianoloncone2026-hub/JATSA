<?php
declare(strict_types=1);

namespace Jatsa\Core;

use Jatsa\Shared\HttpException;
use Jatsa\Shared\Registro;
use PDO;
use PDOException;

/** Conexión perezosa a SQL Server con PDO_SQLSRV. No se conecta hasta que alguien la pide. */
final class Database
{
    private static ?PDO $conexion = null;

    public static function conexion(): PDO
    {
        if (self::$conexion !== null) {
            return self::$conexion;
        }

        /** @var array<string, mixed> $c */
        $c = Config::obtener('db', []);

        if (($c['host'] ?? '') === '' || ($c['nombre'] ?? '') === '') {
            throw new HttpException(503, 'bd_no_configurada', 'La conexión a la base de datos no está configurada.');
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            Registro::error('La extensión pdo_sqlsrv no está cargada');
            throw new HttpException(503, 'bd_no_disponible', 'La base de datos no está disponible.');
        }

        $servidor = (string) $c['host'] . ($c['puerto'] !== '' ? ',' . $c['puerto'] : '');
        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=5',
            $servidor,
            $c['nombre'],
            $c['cifrar'] ? 'true' : 'false',
            $c['confiar_certificado'] ? 'true' : 'false',
        );

        $usuario = $c['usuario'] !== '' ? (string) $c['usuario'] : null;
        $clave = $usuario !== null ? (string) $c['clave'] : null;

        try {
            self::$conexion = new PDO($dsn, $usuario, $clave, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $excepcion) {
            Registro::error('No se pudo conectar a SQL Server', $excepcion);
            throw new HttpException(503, 'bd_no_disponible', 'La base de datos no está disponible.');
        }

        return self::$conexion;
    }
}
