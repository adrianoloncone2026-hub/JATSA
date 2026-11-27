<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Closure;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/** Salidas entre sucursales (diapositivas 4 y 5): horario habitual de cada ruta calculado con las salidas REALES de los */
final class SalidaService
{
    public const TIPOS = ['SOBRE', 'PAQUETE', 'CARGA'];
    public const VIAJES_PARA_EL_HORARIO = 10;
    public const MAX_HORARIOS = 3;
    public const MAX_RECIENTES = 5;

    private readonly RepositorioSalidas $salidas;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD) para elegir las tarifas vigentes; se inyecta para probar. */
    public function __construct(?RepositorioSalidas $salidas = null, ?callable $hoy = null)
    {
        $this->salidas = $salidas ?? new SalidaRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /** @return array{salidas: list<array<string, mixed>>} */
    public function listar(string $origen = '', string $destino = '', string $tipo = ''): array
    {
        $origen = strtoupper(trim($origen));
        $destino = strtoupper(trim($destino));
        $tipo = strtoupper(trim($tipo));
        $errores = [];
        if ($origen !== '' && preg_match('/^[A-Z]{3}\d{3}$/', $origen) !== 1) {
            $errores['origen'] = 'Código de sucursal inválido.';
        }
        if ($destino !== '' && preg_match('/^[A-Z]{3}\d{3}$/', $destino) !== 1) {
            $errores['destino'] = 'Código de sucursal inválido.';
        }
        if ($tipo !== '' && !in_array($tipo, self::TIPOS, true)) {
            $errores['tipo'] = 'Elige SOBRE, PAQUETE o CARGA.';
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los filtros.', [], $errores);
        }

        $horarios = $this->horariosPorRuta();
        $programados = $this->salidas->programadosPorRuta();
        $salidas = [];
        foreach ($this->rutas() as $id => $ruta) {
            if (($origen !== '' && $ruta['origen']['codigo'] !== $origen) || ($destino !== '' && $ruta['destino']['codigo'] !== $destino)) {
                continue;
            }
            $desde = self::precioDesde($ruta['tarifas'], $tipo);
            if ($desde === null) {
                continue;
            }
            $horas = $horarios[$id] ?? [];
            $salidas[] = [
                'id' => $id, 'origen' => $ruta['origen'], 'destino' => $ruta['destino'],
                'horarios' => $horas, 'programado' => $horas === [] && ($programados[$id] ?? 0) > 0,
                'horas' => $ruta['horas'], 'desde' => Tarifas::formatoSoles($desde),
            ];
        }

        return ['salidas' => $salidas];
    }

    /** @return array<string, mixed> */
    public function detalle(string $id): array
    {
        $id = strtoupper(trim($id));
        $ruta = preg_match('/^[A-Z]{3}\d{3}-[A-Z]{3}\d{3}$/', $id) === 1 ? ($this->rutas()[$id] ?? null) : null;
        if ($ruta === null) {
            throw new HttpException(404, 'ruta_no_encontrada', 'No encontramos esa ruta.');
        }

        $horarios = $this->horariosPorRuta()[$id] ?? [];
        $llegada = null;
        if ($horarios !== []) {
            $primera = (int) substr($horarios[0], 0, 2);
            $ultima = (int) substr($horarios[count($horarios) - 1], 0, 2);
            $llegada = ['desde' => sprintf('%02d:00', ($primera + $ruta['horas']) % 24), 'hasta' => sprintf('%02d:00', ($ultima + $ruta['horas']) % 24)];
        }

        return [
            'id' => $id,
            'origen' => $ruta['origen'],
            'destino' => $ruta['destino'],
            'distanciaKm' => $ruta['distanciaKm'],
            'horas' => $ruta['horas'],
            'tarifas' => array_map(static fn (array $t): array => ['tipo' => $t['tipoEnvio'], 'precioPorBulto' => Tarifas::formatoSoles($t['centimos'])], $ruta['tarifas']),
            'horarios' => $horarios,
            'programado' => $horarios === [] && ($this->salidas->programadosPorRuta()[$id] ?? 0) > 0,
            'llegadaAproximada' => $llegada,
            'recientes' => array_map(
                static fn (array $v): array => ['codigo' => $v['codigo'], 'fechaHoraSalida' => $v['fechaHoraSalida'], 'fechaHoraLlegada' => $v['fechaHoraLlegada'], 'estado' => $v['fechaHoraLlegada'] === null ? 'EN_RUTA' : 'TERMINADO'],
                $this->salidas->viajesDeLaRuta($ruta['origen']['codigo'], $ruta['destino']['codigo'], self::MAX_RECIENTES),
            ),
        ];
    }


    /** @return array<string, array<string, mixed>> */
    private function rutas(): array
    {
        $rutas = [];
        foreach ($this->salidas->tarifasVigentes(($this->hoy)()) as $f) {
            $id = $f['origenCodigo'] . '-' . $f['destinoCodigo'];
            $rutas[$id] ??= [
                'origen' => ['codigo' => $f['origenCodigo'], 'nombre' => $f['origenNombre']], 'destino' => ['codigo' => $f['destinoCodigo'], 'nombre' => $f['destinoNombre']],
                'distanciaKm' => self::distancia($f['distanciaKm']), 'horas' => 0, 'tarifas' => [],
            ];
            if (!isset($rutas[$id]['tarifas'][$f['tipoEnvio']])) {
                $rutas[$id]['tarifas'][$f['tipoEnvio']] = ['tipoEnvio' => $f['tipoEnvio'], 'centimos' => Tarifas::costoBultoCentimos($f['precioBase'], $f['precioPorKm'], $f['distanciaKm'])];
                $rutas[$id]['horas'] = max($rutas[$id]['horas'], $f['tiempoHoras']);
            }
        }
        foreach ($rutas as $id => $ruta) {
            $rutas[$id]['tarifas'] = array_values(array_filter(
                array_map(static fn (string $t): ?array => $ruta['tarifas'][$t] ?? null, self::TIPOS),
            ));
        }

        return $rutas;
    }

    /** @param list<array<string, mixed>> $tarifas */
    private static function precioDesde(array $tarifas, string $tipo): ?int
    {
        $precios = [];
        foreach ($tarifas as $t) {
            if ($tipo === '' || $t['tipoEnvio'] === $tipo) {
                $precios[] = $t['centimos'];
            }
        }

        return $precios === [] ? null : min($precios);
    }

    /** @return array<string, list<string>> */
    private function horariosPorRuta(): array
    {
        $cuenta = [];
        foreach ($this->salidas->salidasRecientes(self::VIAJES_PARA_EL_HORARIO) as $s) {
            $id = $s['origenCodigo'] . '-' . $s['destinoCodigo'];
            $hora = substr($s['fechaHoraSalida'], 11, 2) . ':00';
            $cuenta[$id][$hora] = ($cuenta[$id][$hora] ?? 0) + 1;
        }

        $horarios = [];
        foreach ($cuenta as $id => $horas) {
            uksort($horas, static fn (string $a, string $b): int => [$horas[$b], $a] <=> [$horas[$a], $b]);
            $elegidas = array_slice(array_keys($horas), 0, self::MAX_HORARIOS);
            sort($elegidas);
            $horarios[$id] = $elegidas;
        }

        return $horarios;
    }

    private static function distancia(string $km): string
    {
        $texto = number_format((float) $km, 1, '.', '');

        return str_ends_with($texto, '.0') ? substr($texto, 0, -2) : $texto;
    }
}
