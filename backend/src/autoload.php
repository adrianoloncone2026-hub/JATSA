<?php
declare(strict_types=1);

// Autoloader PSR-4 manual: el espacio de nombres Jatsa\ corresponde a backend/src/.
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'Jatsa\\';

    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }

    $relativa = substr($clase, strlen($prefijo));
    $archivo = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativa) . '.php';

    if (is_file($archivo)) {
        require $archivo;
    }
});
