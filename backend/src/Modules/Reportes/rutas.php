<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Reportes\ReportesController;
use Jatsa\Shared\Roles;

// Resumen y Viajes y flota (módulo 11). Solo el GERENTE y solo lectura; la autorización se aplica aquí, en el backend.
return static function (Router $router): void {
    $gerentes = [new RequiereRol([Roles::GERENTE])];

    $router->get('/api/reportes/resumen', [ReportesController::class, 'resumen'], $gerentes);
    $router->get('/api/reportes/viajes-flota', [ReportesController::class, 'viajesFlota'], $gerentes);
};
