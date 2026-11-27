<?php
declare(strict_types=1);

namespace Jatsa\Core;

/** Comprobaciones de estado de la API. */
final class SaludController
{
    public function estado(Request $peticion, array $parametros): Response
    {
        return Response::exito([
            'estado' => 'ok',
            'servicio' => 'JATSA API',
            'version' => Config::obtener('app.version'),
            'hora_servidor' => date('c'),
        ]);
    }

    public function baseDeDatos(Request $peticion, array $parametros): Response
    {
        Database::conexion();

        return Response::exito(['conectado' => true]);
    }
}
