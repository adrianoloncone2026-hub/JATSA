<?php
declare(strict_types=1);

namespace Jatsa\Modules\Viajes;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class ViajeController
{
    private readonly ViajeService $viajes;

    public function __construct(?ViajeService $viajes = null)
    {
        $this->viajes = $viajes ?? new ViajeService();
    }

    public function misViajes(Request $peticion, array $parametros): Response
    {
        $estado = is_string($peticion->consulta['estado'] ?? null) ? $peticion->consulta['estado'] : '';

        return Response::exito($this->viajes->misViajes($this->usuario(), $estado));
    }

    public function detalle(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->viajes->detalle($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
    }

    public function marcarSalida(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->viajes->marcarSalida($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
    }

    public function marcarLlegada(Request $peticion, array $parametros): Response
    {
        return Response::exito(['viaje' => $this->viajes->marcarLlegada($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
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
