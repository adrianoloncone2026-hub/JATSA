<?php
declare(strict_types=1);

namespace Jatsa\Core;

/**
 * Acceso a la configuración con notación de puntos: Config::obtener('db.host').
 */
final class Config
{
    private static string $raiz = '';

    /** @var array<string, mixed> */
    private static array $datos = [];

    public static function cargar(string $raiz): void
    {
        self::$raiz = $raiz;
        Env::cargar($raiz . DIRECTORY_SEPARATOR . '.env');
        self::$datos = require $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
    }

    public static function raiz(): string
    {
        return self::$raiz;
    }

    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        $valor = self::$datos;

        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $porDefecto;
            }
            $valor = $valor[$parte];
        }

        return $valor;
    }
}
