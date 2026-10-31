<?php
declare(strict_types=1);

namespace Jatsa\Modules\Auth;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\Sesion;

final class AuthController
{
    private readonly AuthService $auth;

    public function __construct(?AuthService $auth = null)
    {
        $this->auth = $auth ?? new AuthService();
    }

    /** POST /api/auth/ingresar — { usuario, contrasena, recordarme? } */
    public function ingresar(Request $peticion, array $parametros): Response
    {
        $cuerpo = $peticion->json();

        $usuario = is_string($cuerpo['usuario'] ?? null) ? $cuerpo['usuario'] : '';
        $contrasena = is_string($cuerpo['contrasena'] ?? null) ? $cuerpo['contrasena'] : '';
        $recordarme = ($cuerpo['recordarme'] ?? false) === true;

        $acceso = $this->auth->ingresarConHuella($usuario, $contrasena);
        Sesion::abrir($acceso['usuario'], $recordarme, $acceso['huella']);

        return Response::exito(['usuario' => $acceso['usuario']]);
    }

    /** POST /api/auth/salir — cierra la sesión (si no había, no hace nada). */
    public function salir(Request $peticion, array $parametros): Response
    {
        Sesion::cerrar();

        return Response::exito(['sesionCerrada' => true]);
    }

    /** GET /api/auth/yo — usuario de la sesión actual (la ruta exige sesión). */
    public function yo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['usuario' => Sesion::usuario()]);
    }
}
