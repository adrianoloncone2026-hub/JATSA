<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use Closure;
use DateTimeImmutable;
use Jatsa\Shared\HttpException;

/**
 * «Viajes y flota» del GERENTE (diapositiva 28). Solo lectura.
 *
 * Periodo (año y mes opcional) sobre la salida de los viajes TERMINADOS. «En ruta ahora» y «Programados» son el estado actual
 * (no dependen del periodo) pero sí de los demás filtros. Estado de un viaje: sin salida = programado; con salida y sin llegada =
 * en ruta; con llegada = terminado. Uso de capacidad = promedio por viaje de peso cargado / capacidad del vehículo. Transbordo =
 * envío cargado en más de un viaje; el porcentaje es sobre los envíos distintos cargados en los viajes del periodo.
 */
final class FlotaService
{
    private readonly RepositorioFlota $flota;
    private readonly Closure $ahora;

    /** @param (callable(): DateTimeImmutable)|null $ahora Momento actual; se inyecta para probar. */
    public function __construct(?RepositorioFlota $flota = null, ?callable $ahora = null)
    {
        $this->flota = $flota ?? new FlotaRepository();
        $this->ahora = Closure::fromCallable($ahora ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
    }

    /**
     * @param array<string, mixed> $consulta anio (por defecto el actual), mes (1-12; vacío = todo el año), sucursal, vehiculo (placa),
     *                                       transportista (nombreUsuario) y tipoEnvio (SOBRE|PAQUETE|CARGA)
     * @return array<string, mixed>
     */
    public function viajesYFlota(array $consulta): array
    {
        $ahora = ($this->ahora)();
        $errores = [];
        $anio = $this->anio($consulta['anio'] ?? '', $ahora, $errores);
        $mes = $this->mes($consulta['mes'] ?? '', $errores);
        $filtros = [
            'sucursal' => Filtros::sucursal($consulta['sucursal'] ?? '', $errores),
            'placa' => Filtros::placa($consulta['vehiculo'] ?? '', $errores),
            'transportista' => Filtros::transportista($consulta['transportista'] ?? '', $errores),
            'tipoEnvio' => Filtros::tipoEnvio($consulta['tipoEnvio'] ?? '', $errores),
        ];
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los filtros.', [], $errores);
        }

        $inicio = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anio, $mes ?? 1));
        $fin = $inicio->modify($mes === null ? '+1 year' : '+1 month');
        $desde = $inicio->format('Y-m-d H:i:s');
        $hasta = $fin->format('Y-m-d H:i:s');

        $abiertos = $this->flota->viajesAbiertos($filtros);
        $terminados = $this->flota->terminados($filtros, $desde, $hasta);
        $transbordos = $this->flota->transbordos($filtros, $desde, $hasta);

        return [
            'anio' => $anio,
            'mes' => $mes,
            'filtros' => $filtros,
            'indicadores' => [
                'viajesTerminados' => $terminados['viajes'],
                'enRutaAhora' => $abiertos['enRuta'],
                'programados' => $abiertos['programados'],
                'pesoKg' => $terminados['pesoKg'],
                'usoMedioCapacidadPct' => $terminados['usoMedio'] === null ? null : (int) round($terminados['usoMedio'] * 100),
                'enviosConTransbordoPct' => $transbordos['envios'] === 0 ? null : (int) round($transbordos['conTransbordo'] / $transbordos['envios'] * 100),
            ],
            'viajesEnRuta' => array_map(static fn (array $v): array => self::conLlegadaAproximada($v), $this->flota->viajesEnRuta($filtros, $ahora->format('Y-m-d'))),
            'transportistas' => $this->flota->transportistas($filtros, $desde, $hasta),
        ];
    }

    /**
     * Añade llegadaAprox (salida + horas estimadas de la ruta) y diasDespues (0 = el mismo día; 1 = «+1»); null si la ruta no tiene tiempo.
     *
     * @param array<string, mixed> $viaje
     * @return array<string, mixed>
     */
    public static function conLlegadaAproximada(array $viaje): array
    {
        $viaje['llegadaAprox'] = null;
        $viaje['diasDespues'] = null;
        if ($viaje['horas'] !== null) {
            $salida = new DateTimeImmutable((string) $viaje['salida']);
            $llegada = $salida->modify('+' . (int) $viaje['horas'] . ' hours');
            $viaje['llegadaAprox'] = $llegada->format('Y-m-d H:i:s');
            $viaje['diasDespues'] = (int) $llegada->setTime(0, 0)->diff($salida->setTime(0, 0))->days;
        }

        return $viaje;
    }

    /** @param array<string, string> $errores */
    private function anio(mixed $valor, DateTimeImmutable $ahora, array &$errores): int
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';
        if ($texto === '') {
            return (int) $ahora->format('Y');
        }
        if (preg_match('/^\d{4}$/', $texto) !== 1 || (int) $texto < 2000 || (int) $texto > 9998) {
            // Tope 9998: el año siguiente debe seguir dentro del rango de datetime2 (hasta el año 9999); si no, SQL Server daría 500.
            $errores['anio'] = 'Indica un año entre 2000 y 9998.';

            return (int) $ahora->format('Y');
        }

        return (int) $texto;
    }

    /** @param array<string, string> $errores */
    private function mes(mixed $valor, array &$errores): ?int
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';
        if ($texto === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}$/', $texto) !== 1 || (int) $texto < 1 || (int) $texto > 12) {
            $errores['mes'] = 'El mes debe estar entre 1 y 12.';

            return null;
        }

        return (int) $texto;
    }
}
