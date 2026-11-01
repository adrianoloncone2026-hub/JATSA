<?php
declare(strict_types=1);

namespace Jatsa\Modules\Despacho;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class DespachoController
{
    private readonly DespachoService $despacho;

    public function __construct(?DespachoService $despacho = null)
    {
        $this->despacho = $despacho ?? new DespachoService();
    }

    /** GET /api/despacho/viajes — viajes que salen de la agencia de la sesión. */
    public function salidas(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->despacho->salidas($this->usuario()));
    }

    /** GET /api/despacho/envios-en-espera */
    public function enEspera(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->despacho->enEspera($this->usuario()));
    }

    /** GET /api/despacho/opciones — vehículos, transportistas y destinos para programar un viaje. */
    public function opciones(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->despacho->opciones($this->usuario()));
    }

    /** POST /api/despacho/viajes — programa un viaje. */
    public function programar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->despacho->programar($this->usuario(), $peticion->json())], 201);
    }

    /** POST /api/despacho/viajes/{codigo}/carga — carga un envío en el viaje. */
    public function cargar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->despacho->cargar($this->usuario(), (string) ($parametros['codigo'] ?? ''), $peticion->json())], 201);
    }

    /** DELETE /api/despacho/viajes/{codigo}/carga/{seguimiento} — quita un envío de un viaje que no salió. */
    public function quitar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->despacho->quitar($this->usuario(), (string) ($parametros['codigo'] ?? ''), (string) ($parametros['seguimiento'] ?? ''))]);
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
