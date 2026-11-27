<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

final class RastreoController
{
    private readonly RastreoService $rastreo;

    public function __construct(?RastreoService $rastreo = null)
    {
        $this->rastreo = $rastreo ?? new RastreoService();
    }

    public function rastrear(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->rastreo->rastrear((string) ($parametros['codigo'] ?? ''))]);
    }
}
