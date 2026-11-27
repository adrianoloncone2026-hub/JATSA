<?php
declare(strict_types=1);

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Modules\Usuarios\UsuariosController;
use Jatsa\Shared\Roles;

return static function (Router $router): void {
    $gerentes = [new RequiereRol([Roles::GERENTE])];

    $router->get('/api/usuarios', [UsuariosController::class, 'listar'], $gerentes);
    $router->get('/api/usuarios/persona', [UsuariosController::class, 'persona'], $gerentes);
    $router->post('/api/usuarios', [UsuariosController::class, 'crear'], $gerentes);
    $router->put('/api/usuarios/{usuario}', [UsuariosController::class, 'editar'], $gerentes);
    $router->post('/api/usuarios/{usuario}/estado', [UsuariosController::class, 'estado'], $gerentes);
};