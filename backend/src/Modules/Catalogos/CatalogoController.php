<?php
declare(strict_types=1);

namespace Jatsa\Modules\Catalogos;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

/** Catálogos del GERENTE (diapositiva 29). Solo HTTP: las reglas están en CatalogoService. El rol lo exige el middleware de rutas.php. */
final class CatalogoController
{
    private readonly CatalogoService $catalogo;

    public function __construct(?CatalogoService $catalogo = null)
    {
        $this->catalogo = $catalogo ?? new CatalogoService();
    }

    public function sucursales(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->sucursales());
    }

    public function crearSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->crearSucursal($peticion->json())], 201);
    }

    public function actualizarSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->actualizarSucursal((string) ($parametros['codigo'] ?? ''), $peticion->json())]);
    }

    public function estadoSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->cambiarEstadoSucursal((string) ($parametros['codigo'] ?? ''), $peticion->json())]);
    }

    public function rutas(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->rutas());
    }

    public function crearRuta(Request $peticion, array $parametros): Response
    {
        return Response::exito(['ruta' => $this->catalogo->crearRuta($peticion->json())], 201);
    }

    public function nuevoPrecio(Request $peticion, array $parametros): Response
    {
        return Response::exito(['ruta' => $this->catalogo->nuevoPrecio((string) ($parametros['id'] ?? ''), $peticion->json())], 201);
    }

    public function vehiculos(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->vehiculos());
    }

    public function crearVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->crearVehiculo($peticion->json())], 201);
    }

    public function actualizarVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->actualizarVehiculo((string) ($parametros['placa'] ?? ''), $peticion->json())]);
    }

    public function estadoVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->cambiarEstadoVehiculo((string) ($parametros['placa'] ?? ''), $peticion->json())]);
    }
}
