<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class HistorialController
{
    private readonly HistorialService $historial;

    public function __construct(?HistorialService $historial = null)
    {
        $this->historial = $historial ?? new HistorialService();
    }

    /** GET /api/mis-envios?q=&rol=&estado=&desde=&hasta= — historial del cliente o empresario de la sesión. */
    public function listar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->historial->listar($this->usuario(), $peticion->consulta));
    }

    /** GET /api/mis-envios/resumen — «Mi panel»: envíos en curso, saldo por pagar y por recoger. */
    public function resumen(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->historial->resumen($this->usuario()));
    }

    /** GET /api/mis-envios/{codigo} — un envío propio con su detalle. */
    public function detalle(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->historial->detalle($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
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
