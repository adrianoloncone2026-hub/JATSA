<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateTimeImmutable;

/** Vencimiento de las reservas: una reserva PENDIENTE pasa a VENCIDA cuando pasan RepositorioReservas::HORAS_DE_VIGENCIA */
final class VencimientoService
{
    private readonly RepositorioReservas $reservas;

    public function __construct(?RepositorioReservas $reservas = null)
    {
        $this->reservas = $reservas ?? new ReservaRepository();
    }

    /** @return list<array{codigo: string, fechaHora: string}> */
    public function vencibles(?DateTimeImmutable $referencia = null): array
    {
        return $this->reservas->listarVencibles($referencia);
    }

    public function vencer(?DateTimeImmutable $referencia = null): int
    {
        return $this->reservas->vencerPendientes($referencia);
    }
}
