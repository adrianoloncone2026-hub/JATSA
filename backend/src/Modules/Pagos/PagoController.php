<?php
declare(strict_types=1);

namespace Jatsa\Modules\Pagos;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class PagoController
{
    private readonly PagoService $pagos;

    public function __construct(?PagoService $pagos = null)
    {
        $this->pagos = $pagos ?? new PagoService();
    }

    public function misPagos(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->misPagos($this->usuario()));
    }

    public function cobrados(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->cobradosEnLaSucursal($this->usuario()));
    }

    public function registrar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->registrar($this->usuario(), $peticion->json()), 201);
    }

    public function anular(Request $peticion, array $parametros): Response
    {
        return Response::exito(['pago' => $this->pagos->anular($this->usuario(), (string) ($parametros['id'] ?? ''))]);
    }

    public function envioParaCobro(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->pagos->envioParaCobro($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
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
