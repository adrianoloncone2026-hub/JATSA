<?php
declare(strict_types=1);

namespace Jatsa\Shared;

use RuntimeException;

/** Error controlado que se traduce en una respuesta HTTP JSON. */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $cabeceras Cabeceras HTTP adicionales de la respuesta.
     * @param array<string, string> $campos    Mensaje de error por campo del formulario (validaciones 422).
     */
    public function __construct(
        public readonly int $estado,
        public readonly string $codigo,
        string $mensaje,
        public readonly array $cabeceras = [],
        public readonly array $campos = [],
    ) {
        parent::__construct($mensaje);
    }
}
