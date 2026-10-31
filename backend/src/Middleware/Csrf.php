<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Request;
use Jatsa\Shared\HttpException;

/**
 * Protección contra peticiones falsificadas desde otros sitios (CSRF).
 * Toda petición que modifica datos debe llevar la cabecera X-Requested-With: JATSA.
 * Un formulario de otro sitio no puede añadirla, y una petición AJAX desde otro origen
 * necesita pasar antes por CORS, que solo autoriza los orígenes de la lista blanca.
 */
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
