<?php
declare(strict_types=1);

namespace Jatsa\Modules\Entregas;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class EntregaController
{
    private readonly EntregaService $entregas;

    public function __construct(?EntregaService $entregas = null)
    {
        $this->entregas = $entregas ?? new EntregaService();
    }

    public function pendientes(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->entregas->pendientes($this->usuario()));
    }

    public function detalle(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->entregas->detalle($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
    }

    public function entregar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->entregas->entregar($this->usuario(), (string) ($parametros['codigo'] ?? ''), $peticion->json())]);
    }

    public function devolver(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->entregas->devolver($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
    }

    /** @return array<string, mixed> */
    private function usuario(): array
    {
        $usuario = Sesion::usuario();

        if ($usuario === null) {
            throw new HttpException(401, 'no_autenticado', 'Debes iniciar sesión.');
        }

        return $usuario;
    }
}
