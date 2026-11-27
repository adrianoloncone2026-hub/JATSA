<?php
declare(strict_types=1);

namespace Jatsa\Core;

use Jatsa\Shared\HttpException;

final class Request
{
    /**
     * @param array<string, mixed>  $consulta
     * @param array<string, string> $cabeceras Claves en minúsculas.
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta,
        private readonly string $cuerpoCrudo,
        private readonly array $cabeceras,
    ) {
    }

    public static function desdeGlobales(): self
    {
        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $ruta = '/' . trim(rawurldecode($ruta), '/');

        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr($clave, 5)))] = (string) $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $cabeceras['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self($metodo, $ruta, $_GET, (string) file_get_contents('php://input'), $cabeceras);
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (trim($this->cuerpoCrudo) === '') {
            return [];
        }

        $datos = json_decode($this->cuerpoCrudo, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
            throw new HttpException(400, 'json_invalido', 'El cuerpo de la petición no es un JSON válido.');
        }

        return $datos;
    }
}
