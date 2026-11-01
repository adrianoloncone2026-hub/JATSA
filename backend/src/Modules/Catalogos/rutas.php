<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Catalogos\CatalogoController;
use Jatsa\Shared\Roles;

// Catálogos (módulo 10). Solo el GERENTE; la autorización se aplica aquí, en el backend.
return static function (Router $router): void {
    $gerentes = [new RequiereRol([Roles::GERENTE])];

    $router->get('/api/catalogos/sucursales', [CatalogoController::class, 'sucursales'], $gerentes);
    $router->post('/api/catalogos/sucursales', [CatalogoController::class, 'crearSucursal'], $gerentes);
    $router->put('/api/catalogos/sucursales/{codigo}', [CatalogoController::class, 'actualizarSucursal'], $gerentes);
    $router->post('/api/catalogos/sucursales/{codigo}/estado', [CatalogoController::class, 'estadoSucursal'], $gerentes);

    $router->get('/api/catalogos/rutas', [CatalogoController::class, 'rutas'], $gerentes);
    $router->post('/api/catalogos/rutas', [CatalogoController::class, 'crearRuta'], $gerentes);
    $router->post('/api/catalogos/rutas/{id}/precio', [CatalogoController::class, 'nuevoPrecio'], $gerentes);

    $router->get('/api/catalogos/vehiculos', [CatalogoController::class, 'vehiculos'], $gerentes);
    $router->post('/api/catalogos/vehiculos', [CatalogoController::class, 'crearVehiculo'], $gerentes);
    $router->put('/api/catalogos/vehiculos/{placa}', [CatalogoController::class, 'actualizarVehiculo'], $gerentes);
    $router->post('/api/catalogos/vehiculos/{placa}/estado', [CatalogoController::class, 'estadoVehiculo'], $gerentes);
};
