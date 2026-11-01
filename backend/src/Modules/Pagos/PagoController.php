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

    /** GET /api/pagos — «Mis pagos» del cliente o empresario de la sesión. */
    public function misPagos(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->misPagos($this->usuario()));
    }

    /** GET /api/caja/pagos — pagos cobrados en la sucursal de la sesión, con totales. */
    public function cobrados(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->cobradosEnLaSucursal($this->usuario()));
    }

    /** POST /api/caja/pagos — registra un pago. */
    public function registrar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->pagos->registrar($this->usuario(), $peticion->json()), 201);
    }

    /** POST /api/caja/pagos/{id}/anular */
    public function anular(Request $peticion, array $parametros): Response
    {
        return Response::exito(['pago' => $this->pagos->anular($this->usuario(), (string) ($parametros['id'] ?? ''))]);
    }

    /** GET /api/caja/envios/{codigo} — el envío a cobrar, con importe, pagado y saldo. */
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
