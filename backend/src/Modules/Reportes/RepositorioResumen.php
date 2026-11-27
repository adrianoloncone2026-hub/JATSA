<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/** Lectura agregada para el Resumen del GERENTE (diapositiva 26). Solo lee; no contiene reglas de negocio. */
interface RepositorioResumen
{
    /**
     * @param string $desde AAAA-MM-DD HH:MM:SS (incluido)
     * @param string $hasta AAAA-MM-DD HH:MM:SS (excluido)
     * @return array{porEstado: array<string, int>, antiguos: int}
     */
    public function enviosDelPeriodo(string $desde, string $hasta, ?string $sucursal, string $limiteAntiguedad): array;

    public function cobradoDelPeriodo(string $desde, string $hasta, ?string $sucursal): string;

    public function viajesAbiertos(?string $sucursal): array;
}
