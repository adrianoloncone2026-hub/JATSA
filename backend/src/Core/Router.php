<?php
declare(strict_types=1);

namespace Jatsa\Core;

use Jatsa\Shared\HttpException;

/** Enrutador mínimo. Patrones con parámetros: /api/envios/{id}. */
final class Router
{
    /** @var list<array{metodo: string, regex: string, manejador: callable|array, middleware: list<callable>}> */
    private array $rutas = [];

    /**
     * @param list<callable> $middleware Se ejecutan en orden antes del manejador, reciben la Request
     * y lanzan HttpException para cortar la petición.
     */
    public function agregar(string $metodo, string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->rutas[] = [
            'metodo' => strtoupper($metodo),
            'regex' => $this->compilar($patron),
            'manejador' => $manejador,
            'middleware' => $middleware,
        ];
    }

    public function get(string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->agregar('GET', $patron, $manejador, $middleware);
    }

    public function post(string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->agregar('POST', $patron, $manejador, $middleware);
    }

    public function put(string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->agregar('PUT', $patron, $manejador, $middleware);
    }

    public function patch(string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->agregar('PATCH', $patron, $manejador, $middleware);
    }

    public function delete(string $patron, callable|array $manejador, array $middleware = []): void
    {
        $this->agregar('DELETE', $patron, $manejador, $middleware);
    }

    public function despachar(Request $peticion): Response
    {
        $metodosPermitidos = [];

        foreach ($this->rutas as $ruta) {
            if (preg_match($ruta['regex'], $peticion->ruta, $coincidencias) !== 1) {
                continue;
            }

            if ($ruta['metodo'] !== $peticion->metodo) {
                $metodosPermitidos[] = $ruta['metodo'];
                continue;
            }

            $parametros = array_filter($coincidencias, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ($ruta['middleware'] as $middleware) {
                $middleware($peticion);
            }

            return $this->invocar($ruta['manejador'], $peticion, $parametros);
        }

        if ($metodosPermitidos !== []) {
            throw new HttpException(
                405,
                'metodo_no_permitido',
                'Método no permitido para este recurso.',
                ['Allow' => implode(', ', array_unique($metodosPermitidos))],
            );
        }

        throw new HttpException(404, 'ruta_no_encontrada', 'Recurso no encontrado.');
    }

    private function compilar(string $patron): string
    {
        $partes = preg_split('#\{(\w+)\}#', $patron, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';

        foreach ($partes as $indice => $parte) {
            $regex .= $indice % 2 === 0
                ? preg_quote($parte, '#')
                : '(?P<' . $parte . '>[^/]+)';
        }

        return '#^' . $regex . '$#';
    }

    /**
     * @param callable|array<int, string> $manejador
     * @param array<string, string>       $parametros
     */
    private function invocar(callable|array $manejador, Request $peticion, array $parametros): Response
    {
        if (is_array($manejador) && is_string($manejador[0])) {
            $manejador = [new $manejador[0](), $manejador[1]];
        }

        $respuesta = $manejador($peticion, $parametros);

        if (!$respuesta instanceof Response) {
            throw new \LogicException('El manejador de la ruta debe devolver una Response.');
        }

        return $respuesta;
    }
}
