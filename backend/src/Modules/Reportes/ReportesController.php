<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

/** Resumen y «Viajes y flota» del GERENTE (diapositivas 26 y 28). Solo HTTP; el rol lo exige el middleware de rutas.php. */
final class ReportesController
{
    private readonly ResumenService $resumen;
    private readonly FlotaService $flota;

    public function __construct(?ResumenService $resumen = null, ?FlotaService $flota = null)
    {
        $this->resumen = $resumen ?? new ResumenService();
        $this->flota = $flota ?? new FlotaService();
    }

    /** GET /api/reportes/resumen?mes=AAAA-MM&sucursal= */
    public function resumen(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->resumen->resumen($peticion->consulta));
    }

    /** GET /api/reportes/viajes-flota?anio=&mes=&sucursal=&vehiculo=&transportista=&tipoEnvio= */
    public function viajesFlota(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->flota->viajesYFlota($peticion->consulta));
    }
}
