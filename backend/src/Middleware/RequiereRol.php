<?php
declare(strict_types=1);

namespace Jatsa\Middleware;

use Jatsa\Core\Request;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

/** Exige sesión abierta y que el rol del usuario esté entre los permitidos. */
final class RequiereRol
{
    /** @param list<string> $roles */
    public function __construct(
        private readonly array $roles,
        private readonly ?RequiereSesion $sesion = null,
    ) {
    }

    public function __invoke(Request $peticion): void
    {
        ($this->sesion ?? new RequiereSesion())($peticion);

        $usuario = Sesion::usuario();

        if ($usuario === null || !in_array($usuario['rol'] ?? null, $this->roles, true)) {
            throw new HttpException(403, 'acceso_denegado', 'No tienes permiso para realizar esta acción.');
        }
    }
}
