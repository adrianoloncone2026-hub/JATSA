<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Viajes\ViajeController;
use Jatsa\Shared\Roles;

// Viajes (módulo 7). Mis viajes, salida y llegada son del TRANSPORTISTA; el detalle también lo ve el ADMINISTRADOR
// (el servicio limita a los viajes de su sucursal).
return static function (Router $router): void {
    $transportistas = [new RequiereRol([Roles::TRANSPORTISTA])];
    $detalle = [new RequiereRol([Roles::TRANSPORTISTA, Roles::ADMINISTRADOR])];

    $router->get('/api/viajes', [ViajeController::class, 'misViajes'], $transportistas);
    $router->get('/api/viajes/{codigo}', [ViajeController::class, 'detalle'], $detalle);
    $router->post('/api/viajes/{codigo}/salida', [ViajeController::class, 'marcarSalida'], $transportistas);
    $router->post('/api/viajes/{codigo}/llegada', [ViajeController::class, 'marcarLlegada'], $transportistas);
};
