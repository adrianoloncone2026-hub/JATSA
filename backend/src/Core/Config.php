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

    /**
     * Solo para pruebas: reemplaza un valor de configuración (notación de puntos). Las pruebas del
     * backend vacían db.nombre para que cualquier acceso accidental a la base falle con 503 en vez de conectarse.
     */
    public static function fijar(string $clave, mixed $valor): void
    {
        $partes = explode('.', $clave);
        $destino = &self::$datos;

        foreach ($partes as $parte) {
            if (!isset($destino[$parte]) || !is_array($destino[$parte])) {
                $destino[$parte] = [];
            }
            $destino = &$destino[$parte];
        }

        $destino = $valor;
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
