<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Request;
use Jatsa\Shared\HttpException;

/** Protección contra peticiones falsificadas desde otros sitios (CSRF). */
final class Csrf
{
    public static function verificar(Request $peticion): void
    {
        if (in_array($peticion->metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if ($peticion->cabecera('X-Requested-With') !== 'JATSA') {
            throw new HttpException(403, 'solicitud_no_permitida', 'Solicitud no permitida.');
        }
    }
}
