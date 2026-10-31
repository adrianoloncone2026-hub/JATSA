<?php
declare(strict_types=1);

// Front controller: único punto de entrada de la API.
$raiz = dirname(__DIR__);

require $raiz . '/src/autoload.php';

(new Jatsa\Core\Aplicacion($raiz))->ejecutar();
