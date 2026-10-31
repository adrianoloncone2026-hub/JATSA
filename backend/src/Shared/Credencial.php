<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/**
 * «Huella» de la contraseña de una cuenta. Se guarda en la sesión al ingresar y se compara en cada
 * petición con la que corresponde al hash actual de la base de datos: al cambiar la contraseña la huella
 * cambia y todas las demás sesiones de esa cuenta dejan de valer. No se envía nunca al cliente.
 */
final class Credencial
{
    public static function huella(string $hashContrasena): string
    {
        return hash('sha256', $hashContrasena);
    }
}
