<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/**
 * Utilidades de validación de formularios (módulo 10). Funciones puras: no tocan la base de datos.
 */
final class Validacion
{
    /** Texto de un campo del cuerpo JSON: solo cadenas (un número o un arreglo se tratan como vacío). */
    public static function texto(array $datos, string $clave, bool $recortar = true): string
    {
        $valor = $datos[$clave] ?? '';
        if (!is_string($valor)) {
            return '';
        }

        return $recortar ? trim($valor) : $valor;
    }

    /** Espacios repetidos o al borde → un solo espacio. */
    public static function normalizarEspacios(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** Longitud en caracteres (UTF-8) sin depender de mbstring. */
    public static function largoMaximo(string $texto, int $maximo): bool
    {
        return preg_match('/^.{0,' . $maximo . '}$/su', $texto) === 1;
    }

    /**
     * Decimal positivo con coma o punto: «8,5» → «8.5». Devuelve null si el formato no es válido.
     *
     * @param int $enteros   máximo de dígitos enteros
     * @param int $decimales máximo de decimales
     */
    public static function decimal(mixed $valor, int $enteros, int $decimales): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        $texto = str_replace(',', '.', trim($valor));
        if (preg_match('/^\d{1,' . $enteros . '}(\.\d{1,' . $decimales . '})?$/', $texto) !== 1) {
            return null;
        }

        return $texto;
    }

    /** Entero positivo en un rango; null si no es un entero de ese rango. */
    public static function entero(mixed $valor, int $minimo, int $maximo): ?int
    {
        if (is_string($valor) && preg_match('/^\d{1,9}$/', trim($valor)) === 1) {
            $valor = (int) trim($valor);
        }
        if (!is_int($valor) || $valor < $minimo || $valor > $maximo) {
            return null;
        }

        return $valor;
    }

    /** Booleano estricto (true/false); cualquier otra cosa → null. */
    public static function booleano(mixed $valor): ?bool
    {
        return is_bool($valor) ? $valor : null;
    }
}
