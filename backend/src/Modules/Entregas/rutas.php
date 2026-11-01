<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Entregas\EntregaController;
use Jatsa\Shared\Roles;

// Llegadas y entregas (módulo 8). Solo el ADMINISTRADOR de la agencia de destino; la ruta fija /pendientes va antes que /envios/{codigo}.
return static function (Router $router): void {
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->get('/api/entregas/pendientes', [EntregaController::class, 'pendientes'], $administradores);
    $router->get('/api/entregas/envios/{codigo}', [EntregaController::class, 'detalle'], $administradores);
    $router->post('/api/entregas/envios/{codigo}/entrega', [EntregaController::class, 'entregar'], $administradores);
    $router->post('/api/entregas/envios/{codigo}/devolucion', [EntregaController::class, 'devolver'], $administradores);
};
