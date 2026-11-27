<?php
declare(strict_types=1);

namespace Jatsa\Core;

use Jatsa\Middleware\Cors;
use Jatsa\Middleware\Csrf;
use Throwable;

/** Arranque de la API: configuración, manejo de errores, CORS, rutas y despacho. */
final class Aplicacion
{
    public function __construct(private readonly string $raiz)
    {
    }

    public function ejecutar(): void
    {
        Config::cargar($this->raiz);
        date_default_timezone_set((string) Config::obtener('app.zona_horaria', 'America/Lima'));
        ManejadorErrores::registrar();

        try {
            $peticion = Request::desdeGlobales();

            $previa = Cors::aplicar($peticion);
            if ($previa !== null) {
                $previa->enviar();
                return;
            }

            Csrf::verificar($peticion);

            $router = new Router();
            $this->registrarRutas($router);

            $router->despachar($peticion)->enviar();
        } catch (Throwable $excepcion) {
            ManejadorErrores::aRespuesta($excepcion)->enviar();
        }
    }

    private function registrarRutas(Router $router): void
    {
        $nucleo = require __DIR__ . DIRECTORY_SEPARATOR . 'rutas.php';
        $nucleo($router);

        $archivos = glob($this->raiz . '/src/Modules/*/rutas.php') ?: [];
        foreach ($archivos as $archivo) {
            $registrar = require $archivo;
            $registrar($router);
        }
    }
}
