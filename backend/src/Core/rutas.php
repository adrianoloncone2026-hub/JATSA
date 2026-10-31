<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Core\SaludController;

// Rutas del núcleo (infraestructura). Las de cada módulo viven en Modules/<Modulo>/rutas.php.
return static function (Router $router): void {
    $router->get('/api/salud', [SaludController::class, 'estado']);
    $router->get('/api/salud/bd', [SaludController::class, 'baseDeDatos']);
};
