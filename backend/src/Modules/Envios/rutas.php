<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Envios\EnvioController;
use Jatsa\Modules\Envios\HistorialController;
use Jatsa\Shared\Roles;

// Formalización de envíos en ventanilla (módulo 5). Solo el ADMINISTRADOR; la ruta fija /cotizacion va antes que /{codigo}.
return static function (Router $router): void {
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->post('/api/envios/cotizacion', [EnvioController::class, 'cotizar'], $administradores);
    $router->post('/api/envios', [EnvioController::class, 'formalizar'], $administradores);
    $router->get('/api/envios/{codigo}', [EnvioController::class, 'ver'], $administradores);

    // Historial del CLIENTE y del EMPRESARIO (módulo 9): lo que envió o recibió. La ruta fija /resumen va antes que /{codigo}.
    $clientes = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];
    $router->get('/api/mis-envios/resumen', [HistorialController::class, 'resumen'], $clientes);
    $router->get('/api/mis-envios', [HistorialController::class, 'listar'], $clientes);
    $router->get('/api/mis-envios/{codigo}', [HistorialController::class, 'detalle'], $clientes);
};
