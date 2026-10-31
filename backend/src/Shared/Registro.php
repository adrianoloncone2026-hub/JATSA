<?php
declare(strict_types=1);

namespace Jatsa\Shared;

use Jatsa\Core\Config;
use Throwable;

/**
 * Registro de errores en backend/storage/logs. El detalle técnico va aquí, nunca en la respuesta HTTP.
 */
final class Registro
{
    public static function error(string $mensaje, ?Throwable $excepcion = null): void
    {
        $carpeta = Config::raiz() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        $linea = sprintf('[%s] ERROR %s', date('Y-m-d H:i:s'), $mensaje);

        if ($excepcion !== null) {
            $linea .= sprintf(
                ' | %s: %s en %s:%d',
                $excepcion::class,
                $excepcion->getMessage(),
                $excepcion->getFile(),
                $excepcion->getLine(),
            );
        }

        if (is_dir($carpeta)) {
            @file_put_contents($carpeta . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            error_log($linea);
        }
    }
}
