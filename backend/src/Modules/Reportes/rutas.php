<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Reportes\ReportesController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $gerentes = [new RequiereRol([Roles::GERENTE])];

    $router->get('/api/reportes/resumen', [ReportesController::class, 'resumen'], $gerentes);
    $router->get('/api/reportes/viajes-flota', [ReportesController::class, 'viajesFlota'], $gerentes);

    $exportadores = [new RequiereRol([Roles::GERENTE, Roles::ADMINISTRADOR])];
    $router->get('/api/reportes/exportar/gerencia', [ReportesController::class, 'exportarGerencia'], $exportadores);
    $router->get('/api/reportes/exportar/logistica', [ReportesController::class, 'exportarLogistica'], $exportadores);
    $router->get('/api/reportes/exportar/gerencia-powerbi', [ReportesController::class, 'powerBiGerencia'], $exportadores);
    $router->get('/api/reportes/exportar/logistica-powerbi', [ReportesController::class, 'powerBiLogistica'], $exportadores);
};
