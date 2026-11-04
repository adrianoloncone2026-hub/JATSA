<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Closure;
use DateTimeImmutable;
use Jatsa\Shared\HttpException;

/**
 * Resumen del GERENTE (diapositiva 26): envíos del mes, cobrado, por entregar y viajes abiertos.
 *
 * Reglas: «envíos registrados» cuenta los envíos del mes que siguen REGISTRADO, EN_TRANSITO, EN_DESTINO o ENTREGADO
 * (los cuatro grupos de «Dónde están los envíos del mes»; no cuenta ANULADO ni DEVUELTO). «Por entregar» = EN_TRANSITO + EN_DESTINO.
 * «Más de 3 días» = por entregar con fechaHoraRegistro anterior a hoy menos 3 días. «Cobrados» excluye pagos ANULADO y se
 * mide por la sucursal del pago; envíos y viajes cuentan origen o destino. La variación es contra el mes anterior
 * (null si el mes anterior fue 0).
 */
final class ResumenService
{
    public const ESTADOS = ['ENTREGADO', 'EN_TRANSITO', 'EN_DESTINO', 'REGISTRADO'];
    public const DIAS_DEMORA = 3;

    private readonly RepositorioResumen $resumen;
    private readonly Closure $ahora;

    /** @param (callable(): DateTimeImmutable)|null $ahora Momento actual; se inyecta para probar. */
    public function __construct(?RepositorioResumen $resumen = null, ?callable $ahora = null)
    {
        $this->resumen = $resumen ?? new ResumenRepository();
        $this->ahora = Closure::fromCallable($ahora ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
    }

    /**
     * @param array<string, mixed> $consulta mes (AAAA-MM, por defecto el actual) y sucursal (código; vacío = todas)
     * @return array<string, mixed>
     */
    public function resumen(array $consulta): array
    {
        $ahora = ($this->ahora)();
        $errores = [];
        $mes = $this->mes($consulta['mes'] ?? '', $ahora, $errores);
        $sucursal = Filtros::sucursal($consulta['sucursal'] ?? '', $errores);
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los filtros.', [], $errores);
        }

        $inicio = $mes->modify('first day of this month')->setTime(0, 0);
        $siguiente = $inicio->modify('+1 month');
        $anterior = $inicio->modify('-1 month');
        $f = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
        $limite = $ahora->modify('-' . self::DIAS_DEMORA . ' days');

        $actual = $this->resumen->enviosDelPeriodo($f($inicio), $f($siguiente), $sucursal, $f($limite));
        $previo = $this->resumen->enviosDelPeriodo($f($anterior), $f($inicio), $sucursal, $f($limite));
        $cobrado = $this->resumen->cobradoDelPeriodo($f($inicio), $f($siguiente), $sucursal);
        $cobradoPrevio = $this->resumen->cobradoDelPeriodo($f($anterior), $f($inicio), $sucursal);
        $viajes = $this->resumen->viajesAbiertos($sucursal);

        $porEstado = [];
        foreach (self::ESTADOS as $estado) {
            $porEstado[$estado] = (int) ($actual['porEstado'][$estado] ?? 0);
        }
        $registrados = array_sum($porEstado);
        $registradosPrevio = 0;
        foreach (self::ESTADOS as $estado) {
            $registradosPrevio += (int) ($previo['porEstado'][$estado] ?? 0);
        }

        return [
            'mes' => $inicio->format('Y-m'),
            'sucursal' => $sucursal,
            'enviosRegistrados' => ['total' => $registrados, 'variacionPct' => self::variacion((float) $registrados, (float) $registradosPrevio)],
            'cobrado' => ['total' => $cobrado, 'variacionPct' => self::variacion((float) $cobrado, (float) $cobradoPrevio)],
            'porEntregar' => ['total' => $porEstado['EN_TRANSITO'] + $porEstado['EN_DESTINO'], 'masDeTresDias' => $actual['antiguos']],
            'viajes' => $viajes,
            'dondeEstan' => $porEstado,
        ];
    }

    /** Variación porcentual entera respecto del periodo anterior; null si no hay base de comparación. */
    public static function variacion(float $actual, float $anterior): ?int
    {
        if ($anterior <= 0.0) {
            return null;
        }

        return (int) round(($actual - $anterior) / $anterior * 100);
    }

    /** @param array<string, string> $errores */
    private function mes(mixed $valor, DateTimeImmutable $ahora, array &$errores): DateTimeImmutable
    {
        $texto = is_string($valor) ? trim($valor) : '';
        if ($texto === '') {
            return $ahora;
        }
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $texto, $m) !== 1 || (int) $m[1] < 2000) {
            $errores['mes'] = 'Indica el mes con el formato AAAA-MM.';

            return $ahora;
        }

        return new DateTimeImmutable($texto . '-01 00:00:00');
    }
}
