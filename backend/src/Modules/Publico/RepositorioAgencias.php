<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/** Lectura de las sucursales activas. Cada agencia es un arreglo con las claves: */
interface RepositorioAgencias
{
    /** @return list<array<string, mixed>> Solo sucursales activas, ordenadas por nombre. */
    public function listarActivas(): array;

    /** @return array<string, mixed>|null Una sucursal activa por su código (p. ej. MOQ001). */
    public function buscarActivaPorCodigo(string $codigo): ?array;
}
