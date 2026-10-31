<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Reservas\ReservaController;
use Jatsa\Shared\Roles;

// Reservas del cliente y del empresario. La ruta fija /destinatario va antes que /{codigo}.
return static function (Router $router): void {
    $clientes = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];

    $router->get('/api/reservas/destinatario', [ReservaController::class, 'consultarDestinatario'], $clientes);
    $router->get('/api/reservas', [ReservaController::class, 'listar'], $clientes);
    $router->post('/api/reservas', [ReservaController::class, 'crear'], $clientes);
    $router->get('/api/reservas/{codigo}', [ReservaController::class, 'ver'], $clientes);
    $router->post('/api/reservas/{codigo}/cancelar', [ReservaController::class, 'cancelar'], $clientes);
};
