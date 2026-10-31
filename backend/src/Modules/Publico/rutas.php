<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Modules\Publico\AgenciasController;
use Jatsa\Modules\Publico\CotizadorController;

// Rutas públicas (sin sesión). Rastreo y salidas llegarán en el módulo 9.
return static function (Router $router): void {
    $router->get('/api/agencias', [AgenciasController::class, 'listar']);
    $router->get('/api/cotizacion', [CotizadorController::class, 'cotizar']);
};
