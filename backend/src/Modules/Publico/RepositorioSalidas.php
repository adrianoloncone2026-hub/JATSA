<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/** Lectura de las rutas, tarifas y viajes con los que se calculan las «salidas entre sucursales» (solo SELECT). */
interface RepositorioSalidas
{
    /** @return list<array<string, mixed>> */
    public function tarifasVigentes(string $hoy): array;

    /** @return list<array{origenCodigo: string, destinoCodigo: string, fechaHoraSalida: string}> */
    public function salidasRecientes(int $porRuta): array;

    /** @return array<string, int> */
    public function programadosPorRuta(): array;

    /** @return list<array{codigo: string, fechaHoraSalida: string, fechaHoraLlegada: ?string}> */
    public function viajesDeLaRuta(string $codigoOrigen, string $codigoDestino, int $limite): array;
}
