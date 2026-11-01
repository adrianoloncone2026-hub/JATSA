<?php
declare(strict_types=1);

namespace Jatsa\Modules\Catalogos;

use Jatsa\Core\Request;
use Jatsa\Core\Response;

/**
 * Catálogos del GERENTE (diapositiva 29). Solo HTTP: las reglas están en CatalogoService. El rol lo exige el middleware de rutas.php.
 */
final class CatalogoController
{
    private readonly CatalogoService $catalogo;

    public function __construct(?CatalogoService $catalogo = null)
    {
        $this->catalogo = $catalogo ?? new CatalogoService();
    }

    /** GET /api/catalogos/sucursales */
    public function sucursales(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->sucursales());
    }

    /** POST /api/catalogos/sucursales */
    public function crearSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->crearSucursal($peticion->json())], 201);
    }

    /** PUT /api/catalogos/sucursales/{codigo} */
    public function actualizarSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->actualizarSucursal((string) ($parametros['codigo'] ?? ''), $peticion->json())]);
    }

    /** POST /api/catalogos/sucursales/{codigo}/estado */
    public function estadoSucursal(Request $peticion, array $parametros): Response
    {
        return Response::exito(['sucursal' => $this->catalogo->cambiarEstadoSucursal((string) ($parametros['codigo'] ?? ''), $peticion->json())]);
    }

    /** GET /api/catalogos/rutas */
    public function rutas(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->rutas());
    }

    /** POST /api/catalogos/rutas — «Nueva ruta». */
    public function crearRuta(Request $peticion, array $parametros): Response
    {
        return Response::exito(['ruta' => $this->catalogo->crearRuta($peticion->json())], 201);
    }

    /** POST /api/catalogos/rutas/{id}/precio — «Nuevo precio». */
    public function nuevoPrecio(Request $peticion, array $parametros): Response
    {
        return Response::exito(['ruta' => $this->catalogo->nuevoPrecio((string) ($parametros['id'] ?? ''), $peticion->json())], 201);
    }

    /** GET /api/catalogos/vehiculos */
    public function vehiculos(Request $peticion, array $parametros): Response
    {
        return Response::exito($this->catalogo->vehiculos());
    }

    /** POST /api/catalogos/vehiculos */
    public function crearVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->crearVehiculo($peticion->json())], 201);
    }

    /** PUT /api/catalogos/vehiculos/{placa} */
    public function actualizarVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->actualizarVehiculo((string) ($parametros['placa'] ?? ''), $peticion->json())]);
    }

    /** POST /api/catalogos/vehiculos/{placa}/estado */
    public function estadoVehiculo(Request $peticion, array $parametros): Response
    {
        return Response::exito(['vehiculo' => $this->catalogo->cambiarEstadoVehiculo((string) ($parametros['placa'] ?? ''), $peticion->json())]);
    }
}
