<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

final class CotizadorController
{
    private readonly CotizadorService $cotizador;

    public function __construct(?CotizadorService $cotizador = null)
    {
        $this->cotizador = $cotizador ?? new CotizadorService();
    }

    /** GET /api/cotizacion?origen=MOQ001&destino=AQP001&peso=8.80 (público, sin sesión). */
    public function cotizar(Request $peticion, array $parametros): Response
    {
        $texto = static fn (string $clave): string => is_string($peticion->consulta[$clave] ?? null)
            ? $peticion->consulta[$clave]
            : '';

        return Response::exito($this->cotizador->cotizar($texto('origen'), $texto('destino'), $texto('peso')));
    }

    /** GET /api/tarifas?origen=MOQ001&destino=AQP001 — tarifas vigentes de la ruta, sin peso (público). */
    public function tarifas(Request $peticion, array $parametros): Response
    {
        $texto = static fn (string $clave): string => is_string($peticion->consulta[$clave] ?? null)
            ? $peticion->consulta[$clave]
            : '';

        return Response::exito($this->cotizador->tarifas($texto('origen'), $texto('destino')));
    }
}
