<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Modules\Publico\AgenciasController;
use Jatsa\Modules\Publico\CotizadorController;
use Jatsa\Modules\Publico\RastreoController;
use Jatsa\Modules\Publico\SalidaController;

return static function (Router $router): void {
    $router->get('/api/agencias', [AgenciasController::class, 'listar']);
    $router->get('/api/cotizacion', [CotizadorController::class, 'cotizar']);
    $router->get('/api/tarifas', [CotizadorController::class, 'tarifas']);
    $router->get('/api/rastreo/{codigo}', [RastreoController::class, 'rastrear']);
    $router->get('/api/salidas', [SalidaController::class, 'listar']);
    $router->get('/api/salidas/{id}', [SalidaController::class, 'detalle']);
};
