<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Reservas\ReservaController;
use Jatsa\Modules\Reservas\VentanillaController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $clientes = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO, Roles::GERENTE, Roles::ADMINISTRADOR, Roles::TRANSPORTISTA])];
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->get('/api/reservas/destinatario', [ReservaController::class, 'consultarDestinatario'], $clientes);
    $router->get('/api/reservas', [ReservaController::class, 'listar'], $clientes);
    $router->post('/api/reservas', [ReservaController::class, 'crear'], $clientes);
    $router->get('/api/reservas/{codigo}', [ReservaController::class, 'ver'], $clientes);
    $router->post('/api/reservas/{codigo}/cancelar', [ReservaController::class, 'cancelar'], $clientes);

    $router->get('/api/ventanilla/personas', [VentanillaController::class, 'consultarPersona'], $administradores);
    $router->get('/api/ventanilla/reservas', [VentanillaController::class, 'pendientes'], $administradores);
    $router->post('/api/ventanilla/reservas', [VentanillaController::class, 'crear'], $administradores);
    $router->get('/api/ventanilla/reservas/{codigo}', [VentanillaController::class, 'ver'], $administradores);
};
