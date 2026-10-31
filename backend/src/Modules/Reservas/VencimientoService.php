<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateTimeImmutable;

/**
 * Vencimiento de las reservas: una reserva PENDIENTE pasa a VENCIDA cuando pasan RepositorioReservas::HORAS_DE_VIGENCIA
 * (72 h) desde fechaHoraReserva. En el instante límite exacto ya vence; un segundo antes, no.
 *
 * Solo toca reservas PENDIENTES: las FORMALIZADAS, CANCELADAS y VENCIDAS no cambian. La transición se persiste con un
 * único UPDATE condicionado, por lo que es atómica frente a una cancelación o formalización simultánea: gana la que
 * llegue primero y la otra no modifica nada.
 *
 * No se ejecuta sola. Lo invoca el comando backend/bin/vencer-reservas.php, que el propietario debe programar
 * (ver docs/api/reservas.md).
 */
final class VencimientoService
{
    private readonly RepositorioReservas $reservas;

    public function __construct(?RepositorioReservas $reservas = null)
    {
        $this->reservas = $reservas ?? new ReservaRepository();
    }

    /**
     * Reservas que vencerían ahora (solo lectura).
     *
     * @return list<array{codigo: string, fechaHora: string}>
     */
    public function vencibles(?DateTimeImmutable $referencia = null): array
    {
        return $this->reservas->listarVencibles($referencia);
    }

    /** Persiste el vencimiento y devuelve cuántas reservas pasaron a VENCIDA. */
    public function vencer(?DateTimeImmutable $referencia = null): int
    {
        return $this->reservas->vencerPendientes($referencia);
    }
}
