<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class VentanillaController
{
    private readonly VentanillaService $ventanilla;

    public function __construct(?VentanillaService $ventanilla = null)
    {
        $this->ventanilla = $ventanilla ?? new VentanillaService();
    }

    public function pendientes(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reservas' => $this->ventanilla->pendientes($this->usuario())]);
    }

    public function ver(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reserva' => $this->ventanilla->ver($this->usuario(), (string) ($parametros['codigo'] ?? ''))]);
    }

    public function crear(Request $peticion, array $parametros): Response
    {
        return Response::exito(['reserva' => $this->ventanilla->crear($this->usuario(), $peticion->json())], 201);
    }

    public function consultarPersona(Request $peticion, array $parametros): Response
    {
        $dato = static fn (string $clave): string => is_string($peticion->consulta[$clave] ?? null) ? $peticion->consulta[$clave] : '';

        return Response::exito($this->ventanilla->consultarPersona($dato('tipoDocumento'), $dato('numeroDocumento')));
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
