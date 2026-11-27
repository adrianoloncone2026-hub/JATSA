<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/** Lectura agregada para «Viajes y flota» del GERENTE (diapositiva 28). Solo lee; no contiene reglas de negocio. */
interface RepositorioFlota
{
    public function viajesAbiertos(array $filtros): array;

    /**
     * @param array<string, ?string> $filtros
     * @return array{viajes: int, pesoKg: string, usoMedio: ?float}
     */
    public function terminados(array $filtros, string $desde, string $hasta): array;

    /**
     * @param array<string, ?string> $filtros
     * @return array{envios: int, conTransbordo: int}
     */
    public function transbordos(array $filtros, string $desde, string $hasta): array;

    /**
     * @param array<string, ?string> $filtros
     * @return list<array<string, mixed>>
     */
    public function viajesEnRuta(array $filtros, string $hoy): array;

    /**
     * @param array<string, ?string> $filtros
     * @return list<array<string, mixed>>
     */
    public function transportistas(array $filtros, string $desde, string $hasta): array;
}
