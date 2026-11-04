<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/** Validación de los filtros comunes de las pantallas del GERENTE. Cada método devuelve null si el filtro viene vacío («Todas»). */
final class Filtros
{
    public const TIPOS_ENVIO = ['SOBRE', 'PAQUETE', 'CARGA'];

    /** @param array<string, string> $errores */
    public static function sucursal(mixed $valor, array &$errores): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';
        if ($texto === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9]{3,10}$/', $texto) !== 1) {
            $errores['sucursal'] = 'La sucursal no es válida.';

            return null;
        }

        return strtoupper($texto);
    }

    /** @param array<string, string> $errores */
    public static function placa(mixed $valor, array &$errores): ?string
    {
        $texto = is_string($valor) ? strtoupper(trim($valor)) : '';
        if ($texto === '') {
            return null;
        }
        if (preg_match('/^[A-Z0-9]{3}-[0-9]{3}$/', $texto) !== 1) {
            $errores['vehiculo'] = 'La placa no es válida.';

            return null;
        }

        return $texto;
    }

    /** @param array<string, string> $errores */
    public static function transportista(mixed $valor, array &$errores): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';
        if ($texto === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,30}$/', $texto) !== 1) {
            $errores['transportista'] = 'El transportista no es válido.';

            return null;
        }

        return $texto;
    }

    /** @param array<string, string> $errores */
    public static function tipoEnvio(mixed $valor, array &$errores): ?string
    {
        $texto = is_string($valor) ? strtoupper(trim($valor)) : '';
        if ($texto === '') {
            return null;
        }
        if (!in_array($texto, self::TIPOS_ENVIO, true)) {
            $errores['tipoEnvio'] = 'El tipo de envío debe ser SOBRE, PAQUETE o CARGA.';

            return null;
        }

        return $texto;
    }
}
