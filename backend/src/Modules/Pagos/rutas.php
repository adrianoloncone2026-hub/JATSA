<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Pagos\PagoController;
use Jatsa\Shared\Roles;

// Pagos y Caja (módulo 6). Caja es del ADMINISTRADOR; «Mis pagos» es del CLIENTE y del EMPRESARIO.
return static function (Router $router): void {
    $clientes = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->get('/api/pagos', [PagoController::class, 'misPagos'], $clientes);

    $router->get('/api/caja/pagos', [PagoController::class, 'cobrados'], $administradores);
    $router->post('/api/caja/pagos', [PagoController::class, 'registrar'], $administradores);
    $router->post('/api/caja/pagos/{id}/anular', [PagoController::class, 'anular'], $administradores);
    $router->get('/api/caja/envios/{codigo}', [PagoController::class, 'envioParaCobro'], $administradores);
};
