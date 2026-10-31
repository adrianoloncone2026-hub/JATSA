<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Request;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

/**
 * Exige una sesión abierta y comprueba en la base de datos que la cuenta siga activa.
 * Si el rol cambió desde que se abrió la sesión, actualiza los datos guardados.
 *
 * Uso: $router->get('/api/algo', [...], [new RequiereSesion()]);
 */
final class RequiereSesion
{
    public function __construct(private readonly ?AuthService $auth = null)
    {
    }

    public function __invoke(Request $peticion): void
    {
        $guardado = Sesion::usuario();

        if ($guardado === null) {
            throw new HttpException(401, 'no_autenticado', 'Debes iniciar sesión.');
        }

        // La huella de la sesión debe coincidir con la de la contraseña actual (ver Shared\Credencial).
        $vigente = ($this->auth ?? new AuthService())->vigente((int) $guardado['id'], Sesion::huella());

        if ($vigente === null) {
            Sesion::cerrar();
            throw new HttpException(401, 'sesion_invalida', 'Tu sesión ya no es válida. Ingresa de nuevo.');
        }

        Sesion::actualizar($vigente);
    }
}
