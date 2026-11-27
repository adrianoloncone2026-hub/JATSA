<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Despacho\DespachoController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $administradores = [new RequiereRol([Roles::ADMINISTRADOR])];

    $router->get('/api/despacho/viajes', [DespachoController::class, 'salidas'], $administradores);
    $router->post('/api/despacho/viajes', [DespachoController::class, 'programar'], $administradores);
    $router->post('/api/despacho/viajes/{codigo}/carga', [DespachoController::class, 'cargar'], $administradores);
    $router->delete('/api/despacho/viajes/{codigo}/carga/{seguimiento}', [DespachoController::class, 'quitar'], $administradores);
    $router->get('/api/despacho/envios-en-espera', [DespachoController::class, 'enEspera'], $administradores);
    $router->get('/api/despacho/opciones', [DespachoController::class, 'opciones'], $administradores);
};
