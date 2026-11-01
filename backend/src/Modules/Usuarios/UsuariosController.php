<?php
declare(strict_types=1);

namespace Jatsa\Modules\Usuarios;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

/**
 * Cuentas de usuario del GERENTE (diapositiva 30). Solo HTTP: las reglas están en UsuariosService. El rol lo exige el middleware de rutas.php.
 */
final class UsuariosController
{
    private readonly UsuariosService $usuarios;

    public function __construct(?UsuariosService $usuarios = null)
    {
        $this->usuarios = $usuarios ?? new UsuariosService();
    }

    /** GET /api/usuarios?q=&rol=&estado= */
    public function listar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->usuarios->listar($this->sesion(), $peticion->consulta));
    }

    /** GET /api/usuarios/persona?tipoDocumento=&numeroDocumento= — para el formulario «Nueva cuenta de personal». */
    public function persona(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->usuarios->consultarPersona($peticion->consulta));
    }

    /** POST /api/usuarios — «Crear cuenta». */
    public function crear(Request $peticion, array $parametros): Response
    {
        return Response::exito(['cuenta' => $this->usuarios->crear($this->sesion(), $peticion->json())], 201);
    }

    /** PUT /api/usuarios/{usuario} — «Editar». */
    public function editar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['cuenta' => $this->usuarios->editar($this->sesion(), (string) ($parametros['usuario'] ?? ''), $peticion->json())]);
    }

    /** POST /api/usuarios/{usuario}/estado — «Desactivar seleccionada» y volver a activar. */
    public function estado(Request $peticion, array $parametros): Response
    {
        return Response::exito(['cuenta' => $this->usuarios->cambiarEstado($this->sesion(), (string) ($parametros['usuario'] ?? ''), $peticion->json())]);
    }

    /** @return array<string, mixed> */
    private function sesion(): array
    {
        $usuario = Sesion::usuario();
        if ($usuario === null) {
            throw new HttpException(401, 'no_autenticado', 'Debes iniciar sesión.');
        }

        return $usuario;
    }
}