<?php
declare(strict_types=1);

namespace Jatsa\Core;

final class Response
{
    /**
     * @param array<string, string> $cabeceras
     */
    private function __construct(
        private readonly int $estado,
        private readonly ?string $cuerpo,
        private readonly array $cabeceras = [],
    ) {
    }

    /** Respuesta correcta: { "data": ... } */
    public static function exito(mixed $datos, int $estado = 200): self
    {
        return self::json(['data' => $datos], $estado);
    }

    /**
     * Respuesta de error: { "error": { "codigo": "...", "mensaje": "...", "campos": { "telefono": "..." } } }
     * "campos" solo aparece cuando hay errores por campo (validaciones de formularios).
     *
     * @param array<string, string> $cabeceras
     * @param array<string, string> $campos
     */
    public static function error(string $codigo, string $mensaje, int $estado, array $cabeceras = [], array $campos = []): self
    {
        $error = ['codigo' => $codigo, 'mensaje' => $mensaje];
        if ($campos !== []) {
            $error['campos'] = $campos;
        }

        return self::json(['error' => $error], $estado, $cabeceras);
    }

    public static function vacia(int $estado = 204): self
    {
        return new self($estado, null);
    }

    /**
     * @param array<string, string> $cabeceras
     */
    private static function json(mixed $contenido, int $estado, array $cabeceras = []): self
    {
        $cuerpo = json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return new self($estado, $cuerpo === false ? '{}' : $cuerpo, $cabeceras + [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public function enviar(): void
    {
        http_response_code($this->estado);
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');

        foreach ($this->cabeceras as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }

        if ($this->cuerpo !== null) {
            echo $this->cuerpo;
        }
    }
}
