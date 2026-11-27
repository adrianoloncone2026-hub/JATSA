<?php
declare(strict_types=1);

namespace Jatsa\Core;

/** Lector mínimo de archivos .env (CLAVE=valor). Sin dependencias externas. */
final class Env
{
    /** @var array<string, string> */
    private static array $valores = [];

    public static function cargar(string $ruta): void
    {
        if (!is_file($ruta)) {
            return;
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '' || $linea[0] === '#') {
                continue;
            }

            $posicion = strpos($linea, '=');
            if ($posicion === false) {
                continue;
            }

            $clave = trim(substr($linea, 0, $posicion));
            $valor = trim(substr($linea, $posicion + 1));

            $largo = strlen($valor);
            if ($largo >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[$largo - 1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }

            self::$valores[$clave] = $valor;
        }
    }

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        if (array_key_exists($clave, self::$valores)) {
            return self::$valores[$clave];
        }

        $entorno = getenv($clave);

        return $entorno !== false ? $entorno : $porDefecto;
    }
}
