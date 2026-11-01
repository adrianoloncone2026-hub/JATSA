<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Cuenta\CuentaController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    // Registro público: crea una cuenta CLIENTE (DNI) o EMPRESARIO (RUC).
    $router->post('/api/cuenta/registro', [CuentaController::class, 'registrar']);

    // Mi cuenta (datos personales): solo cliente y empresario, como en el prototipo.
    $propietarios = [new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO])];
    $router->get('/api/cuenta', [CuentaController::class, 'ver'], $propietarios);
    $router->patch('/api/cuenta', [CuentaController::class, 'actualizar'], $propietarios);

    // Cambiar la PROPIA contraseña: cualquier rol con sesión vigente (GERENTE, ADMINISTRADOR, TRANSPORTISTA, CLIENTE y EMPRESARIO).
    // Siempre actúa sobre la cuenta de la sesión (nunca sobre la de un tercero) y exige la contraseña actual. Los datos personales
    // de arriba siguen limitados a cliente y empresario: ampliar esta ruta no da al personal edición de teléfonos ni correo.
    $autenticados = [new RequiereSesion()];
    $router->post('/api/cuenta/contrasena', [CuentaController::class, 'cambiarContrasena'], $autenticados);
};