<?php
declare(strict_types=1);

namespace Jatsa\Modules\Cuenta;

use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Shared\Credencial;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Sesion;

final class CuentaController
{
    private readonly CuentaService $cuentas;

    public function __construct(?CuentaService $cuentas = null)
    {
        $this->cuentas = $cuentas ?? new CuentaService();
    }

    public function registrar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->cuentas->registrar($peticion->json()), 201);
    }

    public function ver(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->cuentas->obtener($this->idUsuarioDeLaSesion()));
    }

    public function actualizar(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->cuentas->actualizarContacto($this->idUsuarioDeLaSesion(), $peticion->json()));
    }

    public function cambiarContrasena(Request $peticion, array $parametros): Response
    {
        $cuerpo = $peticion->json();
        $actual = is_string($cuerpo['contrasenaActual'] ?? null) ? $cuerpo['contrasenaActual'] : '';
        $nueva = is_string($cuerpo['contrasenaNueva'] ?? null) ? $cuerpo['contrasenaNueva'] : '';

        $hashNuevo = $this->cuentas->cambiarContrasena($this->idUsuarioDeLaSesion(), $actual, $nueva);

        Sesion::renovarHuella(Credencial::huella($hashNuevo));

        return Response::exito(['contrasenaCambiada' => true]);
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
