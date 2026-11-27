<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

/** Resumen y «Viajes y flota» del GERENTE (diapositivas 26 y 28). Solo HTTP; el rol lo exige el middleware de rutas.php. */
final class ReportesController
{
    private readonly ResumenService $resumen;
    private readonly FlotaService $flota;

    private ?ExportacionesService $exportaciones;

    public function __construct(?ResumenService $resumen = null, ?FlotaService $flota = null, ?ExportacionesService $exportaciones = null)
    {
        $this->exportaciones = $exportaciones;
        $this->resumen = $resumen ?? new ResumenService();
        $this->flota = $flota ?? new FlotaService();
    }

    public function resumen(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->resumen->resumen($peticion->consulta));
    }

    public function exportarGerencia(Request $peticion, array $parametros): Response
    {
        return self::descarga($this->exportaciones()->gerencia($this->usuario(), $peticion->consulta));
    }

    public function exportarLogistica(Request $peticion, array $parametros): Response
    {
        return self::descarga($this->exportaciones()->logistica($this->usuario(), $peticion->consulta));
    }

    public function powerBiGerencia(Request $peticion, array $parametros): Response
    {
        return self::descarga($this->exportaciones()->paquetePowerBi('gerencia', $this->usuario(), $peticion->consulta), 'application/zip');
    }

    public function powerBiLogistica(Request $peticion, array $parametros): Response
    {
        return self::descarga($this->exportaciones()->paquetePowerBi('logistica', $this->usuario(), $peticion->consulta), 'application/zip');
    }

    /** @param array{nombre: string, contenido: string} $archivo */
    private static function descarga(array $archivo, string $tipo = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'): Response
    {
        return Response::archivo($archivo['contenido'], $tipo, $archivo['nombre']);
    }

    private function exportaciones(): ExportacionesService
    {
        return $this->exportaciones ??= new ExportacionesService();
    }

    /** @return array<string, mixed> */
    private function usuario(): array
    {
        return Sesion::usuario() ?? throw new HttpException(401, 'no_autenticado', 'Debes iniciar sesión.');
    }

    public function viajesFlota(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->flota->viajesYFlota($peticion->consulta));
    }
}
