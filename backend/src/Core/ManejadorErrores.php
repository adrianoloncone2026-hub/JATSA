<?php
declare(strict_types=1);

namespace Jatsa\Core;

use ErrorException;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Registro;
use Throwable;

/**
 * Convierte cualquier error en una respuesta JSON uniforme.
 * El detalle técnico se registra en storage/logs; el cliente solo recibe un mensaje genérico.
 */
final class ManejadorErrores
{
    public static function registrar(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }
            throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        set_exception_handler(static function (Throwable $excepcion): void {
            self::aRespuesta($excepcion)->enviar();
        });
    }

    public static function aRespuesta(Throwable $excepcion): Response
    {
        if ($excepcion instanceof HttpException) {
            return Response::error($excepcion->codigo, $excepcion->getMessage(), $excepcion->estado, $excepcion->cabeceras, $excepcion->campos);
        }

        Registro::error('Error no controlado', $excepcion);

        return Response::error('error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.', 500);
    }
}
