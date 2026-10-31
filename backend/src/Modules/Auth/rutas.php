<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthController;

return static function (Router $router): void {
    $router->post('/api/auth/ingresar', [AuthController::class, 'ingresar']);
    $router->post('/api/auth/salir', [AuthController::class, 'salir']);
    $router->get('/api/auth/yo', [AuthController::class, 'yo'], [new RequiereSesion()]);
};
