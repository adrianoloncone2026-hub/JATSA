<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Cuenta\CuentaController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $router->post('/api/cuenta/registro', [CuentaController::class, 'registrar']);

    $propietarios = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];
    $router->get('/api/cuenta', [CuentaController::class, 'ver'], $propietarios);
    $router->patch('/api/cuenta', [CuentaController::class, 'actualizar'], $propietarios);

    // Siempre actúa sobre la cuenta de la sesión (nunca sobre la de un tercero) y exige la contraseña actual. Los datos personales
    $autenticados = [new RequiereSesion()];
    $router->post('/api/cuenta/contrasena', [CuentaController::class, 'cambiarContrasena'], $autenticados);
};