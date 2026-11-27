<?php
declare(strict_types=1);

// Pruebas de «Mis encomiendas»: el personal (GERENTE, ADMINISTRADOR, TRANSPORTISTA) usa las funciones personales con su propia identidad.
// Sin base de datos. Se incluye desde ejecutar.php.

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Shared\Roles;

/** @return array<string, list<string>> «METODO /patron» => roles permitidos, leídos de las rutas reales (todos los módulos). */
function rolesPorRutaMisEncomiendas(): array
{
    $router = new Router();
    foreach (glob(dirname(__DIR__) . '/src/Modules/*/rutas.php') ?: [] as $archivo) {
        (require $archivo)($router);
    }
    $rutas = (new ReflectionProperty($router, 'rutas'))->getValue($router);
    $mapa = [];
    foreach ($rutas as $ruta) {
        $roles = [];
        foreach ($ruta['middleware'] as $guardia) {
            if ($guardia instanceof RequiereRol) {
                $roles = (new ReflectionProperty($guardia, 'roles'))->getValue($guardia);
            }
        }
        $mapa[$ruta['metodo'] . ' ' . str_replace('\\', '', $ruta['regex'])] = $roles;
    }

    return $mapa;
}

prueba('mis encomiendas: las rutas personales admiten a los cinco roles; las laborales y los datos personales no cambian', function () {
    $mapa = rolesPorRutaMisEncomiendas();
    $todos = [Roles::CLIENTE, Roles::EMPRESARIO, Roles::GERENTE, Roles::ADMINISTRADOR, Roles::TRANSPORTISTA];
    $claves = static fn (string $trozo): array => array_values(array_filter(array_keys($mapa), static fn (string $k): bool => str_contains($k, $trozo)));
    $personales = array_merge($claves('/api/reservas'), $claves('/api/mis-envios'), $claves('/api/pagos$'));
    $personales = array_values(array_filter($personales, static fn (string $k): bool => !str_contains($k, 'ventanilla') && !str_contains($k, 'pagos/')));
    afirmar(count($personales) >= 9, 'se encontraron las rutas personales: ' . count($personales));
    foreach ($personales as $clave) {
        $roles = $mapa[$clave];
        sort($roles);
        $esperado = $todos;
        sort($esperado);
        afirmar($roles === $esperado, "personal $clave admite los cinco roles");
    }
    // Funciones laborales: cada una sigue limitada a su rol.
    foreach ($mapa as $clave => $roles) {
        if (str_contains($clave, '/api/ventanilla') || str_contains($clave, '/api/despacho') || str_contains($clave, '/api/entregas')) {
            afirmar(in_array(Roles::CLIENTE, $roles, true) === false && in_array(Roles::EMPRESARIO, $roles, true) === false, "laboral $clave sigue sin clientes");
        }
        if (str_contains($clave, '/api/cuenta') && !str_contains($clave, 'registro') && !str_contains($clave, 'contrasena')) {
            afirmar($roles === [Roles::CLIENTE, Roles::EMPRESARIO], "datos personales $clave siguen solo para cliente y empresario");
        }
        if (str_contains($clave, '/api/catalogos') && !str_starts_with($clave, 'GET /api/catalogos/sucursales') || str_contains($clave, '/api/usuarios')) {
            afirmar(in_array(Roles::ADMINISTRADOR, $roles, true) === false && in_array(Roles::TRANSPORTISTA, $roles, true) === false && !in_array(Roles::CLIENTE, $roles, true), "$clave no se amplia a administrador, transportista ni cliente");
        }
    }
    foreach (['/api/reportes/resumen', '/api/reportes/viajes-flota'] as $trozo) {
        $clave = array_values(array_filter(array_keys($mapa), static fn (string $k): bool => str_starts_with($k, 'GET') && str_contains($k, $trozo)))[0] ?? '';
        afirmar($clave !== '' && $mapa[$clave] === [Roles::GERENTE], "$trozo sigue solo para el gerente");
    }
});

prueba('mis encomiendas: con la sesion de un empleado las reservas son solo suyas (identidad de la sesion, sin ver las ajenas)', function () {
    [$repo, $servicio] = escenarioReservas();
    // Ids de usuario 5 (p. ej. un administrador) y 4 (un cliente): el servicio solo conoce el id de la sesion, no el rol.
    $propia = $servicio->crear(5, reservaValida());
    $ajena = $servicio->crear(4, reservaValida());
    $mias = array_column($servicio->listar(5)['reservas'] ?? $servicio->listar(5), 'codigo');
    afirmar(in_array($propia['codigo'], $mias, true) && !in_array($ajena['codigo'], $mias, true), 'listar devuelve solo las del usuario de la sesion');
    esperarError(fn () => $servicio->ver(5, $ajena['codigo']), 404, 'reserva_no_encontrada');
    esperarError(fn () => $servicio->cancelar(5, $ajena['codigo']), 404, 'reserva_no_encontrada');
    afirmar($servicio->cancelar(5, $propia['codigo'])['estado'] === 'CANCELADA', 'puede cancelar la propia');
});