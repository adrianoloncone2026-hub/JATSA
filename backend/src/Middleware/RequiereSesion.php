<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Request;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

/** Exige una sesión abierta y comprueba en la base de datos que la cuenta siga activa. */
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

        $vigente = ($this->auth ?? new AuthService())->vigente((int) $guardado['id'], Sesion::huella());

        if ($vigente === null) {
            Sesion::cerrar();
            throw new HttpException(401, 'sesion_invalida', 'Tu sesión ya no es válida. Ingresa de nuevo.');
        }

        Sesion::actualizar($vigente);
    }
}
