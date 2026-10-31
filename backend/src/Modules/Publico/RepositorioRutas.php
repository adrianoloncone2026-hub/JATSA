<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/**
 * Lectura de las tarifas vigentes entre dos sucursales. Cada tarifa es un arreglo con las claves:
 * tipoEnvio, precioBase, precioPorKm, distanciaKm (números o cadenas decimales) y tiempoHoras (int).
 */
interface RepositorioRutas
{
    /**
     * Tarifas vigentes en la fecha dada para ir de una sucursal a otra, como máximo una por tipo de envío
     * (si hubiera dos vigentes, la de vigenteDesde más reciente).
     *
     * @param string $fechaIso Fecha de consulta, formato AAAA-MM-DD.
     * @return list<array<string, mixed>>
     */
    public function vigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array;
}
