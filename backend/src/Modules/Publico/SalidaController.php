<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

final class SalidaController
{
    private readonly SalidaService $salidas;

    public function __construct(?SalidaService $salidas = null)
    {
        $this->salidas = $salidas ?? new SalidaService();
    }

    /** GET /api/salidas?origen=&destino=&tipo= — público, sin sesión. */
    public function listar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->salidas->listar($this->consulta($peticion, 'origen'), $this->consulta($peticion, 'destino'), $this->consulta($peticion, 'tipo')));
    }

    /** GET /api/salidas/{id} — id = «MOQ001-AQP001». */
    public function detalle(Request $peticion, array $parametros): Response
    {
        return Response::exito(['ruta' => $this->salidas->detalle((string) ($parametros['id'] ?? ''))]);
    }

    private function consulta(Request $peticion, string $clave): string
    {
        return is_string($peticion->consulta[$clave] ?? null) ? $peticion->consulta[$clave] : '';
    }
}
