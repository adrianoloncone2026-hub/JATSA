<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Envios\EnvioController;
use Jatsa\Shared\Roles;

// Formalización de envíos en ventanilla (módulo 5). Solo el ADMINISTRADOR; la ruta fija /cotizacion va antes que /{codigo}.
return static function (Router $router): void {
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->post('/api/envios/cotizacion', [EnvioController::class, 'cotizar'], $administradores);
    $router->post('/api/envios', [EnvioController::class, 'formalizar'], $administradores);
    $router->get('/api/envios/{codigo}', [EnvioController::class, 'ver'], $administradores);
};
