<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Core\SaludController;

return static function (Router $router): void {
    $router->get('/api/salud', [SaludController::class, 'estado']);
    $router->get('/api/salud/bd', [SaludController::class, 'baseDeDatos']);
};
