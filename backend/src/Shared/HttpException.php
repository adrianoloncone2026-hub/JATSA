<?php
declare(strict_types=1);

namespace Jatsa\Shared;

use RuntimeException;

/**
 * Error controlado que se traduce en una respuesta HTTP JSON.
 * El mensaje es el que verá el cliente: nunca debe contener SQL, rutas ni datos internos.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $cabeceras Cabeceras HTTP adicionales de la respuesta.
     */
    public function __construct(
        public readonly int $estado,
        public readonly string $codigo,
        string $mensaje,
        public readonly array $cabeceras = [],
    ) {
        parent::__construct($mensaje);
    }
}
