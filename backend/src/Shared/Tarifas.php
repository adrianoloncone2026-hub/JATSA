<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/** Reglas de tarifa del informe de BD (sección 8.5) y del prototipo: */
final class Tarifas
{
    public const LIMITES_KG = ['SOBRE' => 1, 'PAQUETE' => 30, 'CARGA' => 300];

    public static function tipoPorPeso(int $centesimasKg): ?string
    {
        foreach (self::LIMITES_KG as $tipo => $maximoKg) {
            if ($centesimasKg <= $maximoKg * 100) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * @param numeric-string|int|float $precioBase   Ruta.precioBase      DECIMAL(8,2)
     * @param numeric-string|int|float $precioPorKm  Ruta.precioPorKm     DECIMAL(6,3)
     * @param numeric-string|int|float $distanciaKm  Ruta.distanciaKm     DECIMAL(7,1)
     */
    public static function costoBultoCentimos(string|int|float $precioBase, string|int|float $precioPorKm, string|int|float $distanciaKm): int
    {
        $baseCentimos = self::escalar($precioBase, 2);
        $porKmMilesimas = self::escalar($precioPorKm, 3);
        $distanciaDecimas = self::escalar($distanciaKm, 1);

        $variableCentimos = intdiv($porKmMilesimas * $distanciaDecimas + 50, 100);

        return $baseCentimos + $variableCentimos;
    }

    public static function formatoSoles(int $centimos): string
    {
        return sprintf('%d.%02d', intdiv($centimos, 100), $centimos % 100);
    }

    private static function escalar(string|int|float $valor, int $decimales): int
    {
        return (int) round(((float) $valor) * (10 ** $decimales));
    }
}
