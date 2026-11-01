<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class EnvioController
{
    private readonly EnvioService $envios;

    public function __construct(?EnvioService $envios = null)
    {
        $this->envios = $envios ?? new EnvioService();
    }

    /** POST /api/envios/cotizacion — cotiza los bultos de una reserva sin escribir nada. */
    public function cotizar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->envios->cotizar($this->usuario(), $peticion->json()));
    }

    /** POST /api/envios — formaliza la reserva: crea el envío con sus bultos. */
    public function formalizar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->envios->formalizar($this->usuario(), $peticion->json())], 201);
    }

    /** GET /api/envios/{codigo} — un envío (para la etiqueta) del personal de su sucursal de origen o destino. */
    public function ver(Request $peticion, array $parametros): Response
    {
        return Response::exito(['envio' => $this->envios->ver($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
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
