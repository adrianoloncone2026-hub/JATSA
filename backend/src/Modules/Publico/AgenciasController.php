<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

final class AgenciasController
{
    private readonly RepositorioAgencias $agencias;

    public function __construct(?RepositorioAgencias $agencias = null)
    {
        $this->agencias = $agencias ?? new AgenciaRepository();
    }

    /** GET /api/agencias — sucursales activas con sus teléfonos (público, sin sesión). */
    public function listar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['agencias' => $this->agencias->listarActivas()]);
    }
}
