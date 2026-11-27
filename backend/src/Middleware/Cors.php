<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Config;
use Jatsa\Core\Request;
use Jatsa\Core\Response;

/** CORS con lista blanca de orígenes (config 'cors.origenes'). */
final class Cors
{
    public static function aplicar(Request $peticion): ?Response
    {
        $origen = $peticion->cabecera('Origin');

        if ($origen === null) {
            return $peticion->metodo === 'OPTIONS' ? Response::vacia(204) : null;
        }

        header('Vary: Origin');

        /** @var list<string> $permitidos */
        $permitidos = Config::obtener('cors.origenes', []);

        if (in_array($origen, $permitidos, true)) {
            header('Access-Control-Allow-Origin: ' . $origen);
            header('Access-Control-Allow-Credentials: true');

            if ($peticion->metodo === 'OPTIONS') {
                header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With');
                header('Access-Control-Max-Age: 600');
            }
        }

        return $peticion->metodo === 'OPTIONS' ? Response::vacia(204) : null;
    }
}
