<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateInterval;
use DateTimeImmutable;

/**
 * Cálculo de la fecha límite de una reserva pendiente (fechaHoraReserva + RepositorioReservas::HORAS_DE_VIGENCIA).
 * Compartido por las reservas del cliente y la ventanilla.
 */
final class Vigencia
{
    /** fechaHoraReserva + horas de vigencia, en el mismo formato y reloj en que se guardó (sin conversiones de zona). */
    public static function fechaLimite(string $fechaHora): string
    {
        $inicio = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($fechaHora, 0, 19));

        return $inicio === false
            ? $fechaHora
            : $inicio->add(new DateInterval('PT' . RepositorioReservas::HORAS_DE_VIGENCIA . 'H'))->format('Y-m-d H:i:s');
    }
}
