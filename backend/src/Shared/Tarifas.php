<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/**
 * Reglas de tarifa del informe de BD (sección 8.5) y del prototipo:
 *   costo del bulto = precioBase + precioPorKm × distanciaKm
 *   el peso decide el tipo de envío.
 *
 * El dinero se calcula con enteros (céntimos) para no arrastrar errores de coma flotante y
 * redondear como lo haría SQL Server con DECIMAL (medio hacia arriba).
 */
final class Tarifas
{
    /** Peso máximo (kg) de cada tipo, en orden de menor a mayor. */
    public const LIMITES_KG = ['SOBRE' => 1, 'PAQUETE' => 30, 'CARGA' => 300];

    /**
     * Tipo de envío que corresponde a un peso, o null si supera el máximo.
     * Se compara en centésimas de kg (Bulto.pesoKg es DECIMAL(8,2)).
     */
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
     * Costo de un bulto en céntimos.
     *
     * @param numeric-string|int|float $precioBase   Ruta.precioBase      DECIMAL(8,2)
     * @param numeric-string|int|float $precioPorKm  Ruta.precioPorKm     DECIMAL(6,3)
     * @param numeric-string|int|float $distanciaKm  Ruta.distanciaKm     DECIMAL(7,1)
     */
    public static function costoBultoCentimos(string|int|float $precioBase, string|int|float $precioPorKm, string|int|float $distanciaKm): int
    {
        $baseCentimos = self::escalar($precioBase, 2);
        $porKmMilesimas = self::escalar($precioPorKm, 3);
        $distanciaDecimas = self::escalar($distanciaKm, 1);

        // (milésimas de sol × décimas de km) / 100 = céntimos, redondeando medio hacia arriba.
        $variableCentimos = intdiv($porKmMilesimas * $distanciaDecimas + 50, 100);

        return $baseCentimos + $variableCentimos;
    }

    /** Céntimos → "14.60". */
    public static function formatoSoles(int $centimos): string
    {
        return sprintf('%d.%02d', intdiv($centimos, 100), $centimos % 100);
    }

    /**
     * Convierte un decimal (cadena, entero o float) en entero con la escala indicada: ("0.020", 3) → 20.
     */
    private static function escalar(string|int|float $valor, int $decimales): int
    {
        return (int) round(((float) $valor) * (10 ** $decimales));
    }
}
