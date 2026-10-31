<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class ReservaController
{
    private readonly ReservaService $reservas;

    public function __construct(?ReservaService $reservas = null)
    {
        $this->reservas = $reservas ?? new ReservaService();
    }

    /** GET /api/reservas?estado=PENDIENTE — reservas del cliente de la sesión. */
    public function listar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reservas' => $this->reservas->listar($this->idUsuarioDeLaSesion(), $this->consulta($peticion, 'estado'))]);
    }

    /** POST /api/reservas — crea una reserva PENDIENTE (canal WEB). */
    public function crear(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reserva' => $this->reservas->crear($this->idUsuarioDeLaSesion(), $peticion->json())], 201);
    }

    /** GET /api/reservas/{codigo} */
    public function ver(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reserva' => $this->reservas->ver($this->idUsuarioDeLaSesion(), (string) ($parametros['codigo'] ?? ''))]);
    }

    /** POST /api/reservas/{codigo}/cancelar */
    public function cancelar(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reserva' => $this->reservas->cancelar($this->idUsuarioDeLaSesion(), (string) ($parametros['codigo'] ?? ''))]);
    }

    /** GET /api/reservas/destinatario?tipoDocumento=DNI&numeroDocumento=70234561 — autocompleta el nombre. */
    public function consultarDestinatario(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->reservas->consultarDestinatario(
            $this->idUsuarioDeLaSesion(),
            $this->consulta($peticion, 'tipoDocumento'),
            $this->consulta($peticion, 'numeroDocumento'),
        ));
    }

    private function consulta(Request $peticion, string $clave): string
    {
        return is_string($peticion->consulta[$clave] ?? null) ? $peticion->consulta[$clave] : '';
    }

    private function idUsuarioDeLaSesion(): int
    {
        $usuario = Sesion::usuario();

        if ($usuario === null) {
            throw new HttpException(401, 'no_autenticado', 'Debes iniciar sesión.');
        }

        return (int) $usuario['id'];
    }
}
