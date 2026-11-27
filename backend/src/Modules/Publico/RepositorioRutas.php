<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/** Lectura de las tarifas vigentes entre dos sucursales. Cada tarifa es un arreglo con las claves: */
interface RepositorioRutas
{
    /**
     * @param string $fechaIso Fecha de consulta, formato AAAA-MM-DD.
     * @return list<array<string, mixed>>
     */
    public function vigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array;
}
