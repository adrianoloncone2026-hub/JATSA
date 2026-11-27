<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/** «Huella» de la contraseña de una cuenta. Se guarda en la sesión al ingresar y se compara en cada */
final class Credencial
{
    public static function huella(string $hashContrasena): string
    {
        return hash('sha256', $hashContrasena);
    }
}
