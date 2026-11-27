<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Closure;
use DateTimeImmutable;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/** Rastreo público (diapositiva 3): estado, recorrido y llegada estimada de un envío a partir de su código de seguimiento. */
final class RastreoService
{
    public const ETAPAS = ['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO', 'ENTREGADO'];

    private readonly RepositorioRastreo $envios;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD) para elegir la ruta vigente; se inyecta para probar. */
    public function __construct(?RepositorioRastreo $envios = null, ?callable $hoy = null)
    {
        $this->envios = $envios ?? new RastreoRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /** @return array<string, mixed> */
    public function rastrear(string $codigoSeguimiento): array
    {
        $codigo = strtoupper(trim($codigoSeguimiento));
        $envio = preg_match('/^EN[A-Z0-9]{10}$/', $codigo) === 1 ? $this->envios->buscarEnvio($codigo, ($this->hoy)()) : null;
        if ($envio === null) {
            throw new HttpException(404, 'envio_no_encontrado', 'No encontramos un envío con ese código. Revísalo e inténtalo de nuevo.');
        }

        $estado = $envio['estado'];
        $recorrido = [['fechaHora' => $envio['fechaHoraRegistro'], 'tipo' => 'registro', 'texto' => sprintf('Recibido en %s (%s)', $envio['origen']['nombre'], $envio['origen']['codigo'])]];
        $viajeActual = null;
        $rutaId = $envio['origen']['codigo'] . '-' . $envio['destino']['codigo'];

        foreach ($envio['tramos'] as $tramo) {
            $recorrido[] = ['fechaHora' => $tramo['fechaHoraCarga'], 'tipo' => 'carga', 'texto' => 'Cargado en el viaje ' . $tramo['codigoViaje']];
            if ($tramo['fechaHoraSalida'] !== null) {
                $recorrido[] = ['fechaHora' => $tramo['fechaHoraSalida'], 'tipo' => 'salida', 'texto' => 'Salió hacia ' . $tramo['destino']['nombre']];
            }
            if ($tramo['fechaHoraLlegada'] !== null) {
                $recorrido[] = ['fechaHora' => $tramo['fechaHoraLlegada'], 'tipo' => 'llegada', 'texto' => 'Llegó a ' . $tramo['destino']['nombre']];
            }
            if ($tramo['fechaHoraSalida'] !== null && $tramo['fechaHoraLlegada'] === null && !in_array($estado, ['ENTREGADO', 'DEVUELTO', 'ANULADO'], true)) {
                $viajeActual = ['codigo' => $tramo['codigoViaje'], 'vehiculo' => $tramo['vehiculo'], 'fechaHoraSalida' => $tramo['fechaHoraSalida'],
                    'origen' => $tramo['origen'], 'destino' => $tramo['destino']];
                $rutaId = $tramo['origen']['codigo'] . '-' . $tramo['destino']['codigo'];
                if ($tramo['horas'] !== null) {
                    $estimada = (new DateTimeImmutable($tramo['fechaHoraSalida']))->modify('+' . $tramo['horas'] . ' hours')->format('Y-m-d H:i:s');
                    $recorrido[] = ['fechaHora' => $estimada, 'tipo' => 'estimada', 'texto' => sprintf('Llegada estimada (ruta de %d h)', $tramo['horas'])];
                }
            }
        }
        if ($envio['fechaHoraEntrega'] !== null) {
            $recorrido[] = ['fechaHora' => $envio['fechaHoraEntrega'], 'tipo' => 'entrega', 'texto' => 'Entregado en ' . $envio['destino']['nombre']];
        }
        usort($recorrido, static fn (array $a, array $b): int => strcmp($a['fechaHora'], $b['fechaHora']));

        $importe = self::centimos($envio['importe']);
        $pagado = self::centimos($envio['pagado']);
        $etapa = array_search($estado, self::ETAPAS, true);

        return [
            'codigoSeguimiento' => $envio['codigoSeguimiento'],
            'estado' => $estado,
            'etapas' => array_map(
                static fn (string $e, int $i): array => ['estado' => $e, 'cumplida' => $etapa !== false && $i <= $etapa, 'actual' => $etapa !== false && $i === $etapa],
                self::ETAPAS,
                array_keys(self::ETAPAS),
            ),
            'origen' => $envio['origen'],
            'destino' => $envio['destino'],
            'cantidadBultos' => $envio['bultos'],
            'pesoTotalKg' => self::kilos(self::centimos($envio['pesoKg'])),
            'saldo' => Tarifas::formatoSoles(max(0, $importe - $pagado)),
            'recorrido' => $recorrido,
            'viajeActual' => $viajeActual,
            'rutaId' => $rutaId,
        ];
    }

    private static function centimos(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function kilos(int $centesimas): string
    {
        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }
}
