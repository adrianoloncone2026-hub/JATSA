<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Envios\EnvioController;
use Jatsa\Modules\Envios\HistorialController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->post('/api/envios/cotizacion', [EnvioController::class, 'cotizar'], $administradores);
    $router->post('/api/envios', [EnvioController::class, 'formalizar'], $administradores);
    $router->get('/api/envios/{codigo}', [EnvioController::class, 'ver'], $administradores);

    $clientes = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO, Roles::GERENTE, Roles::ADMINISTRADOR, Roles::TRANSPORTISTA])];
    $router->get('/api/mis-envios/resumen', [HistorialController::class, 'resumen'], $clientes);
    $router->get('/api/mis-envios', [HistorialController::class, 'listar'], $clientes);
    $router->get('/api/mis-envios/{codigo}', [HistorialController::class, 'detalle'], $clientes);
};
