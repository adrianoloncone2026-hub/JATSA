<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Cuenta\CuentaController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    // Registro público: crea una cuenta CLIENTE (DNI) o EMPRESARIO (RUC).
    $router->post('/api/cuenta/registro', [CuentaController::class, 'registrar']);

    // Mi cuenta: solo cliente y empresario, como en el prototipo.
    $propietarios = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];
    $router->get('/api/cuenta', [CuentaController::class, 'ver'], $propietarios);
    $router->patch('/api/cuenta', [CuentaController::class, 'actualizar'], $propietarios);
    $router->post('/api/cuenta/contrasena', [CuentaController::class, 'cambiarContrasena'], $propietarios);
};
