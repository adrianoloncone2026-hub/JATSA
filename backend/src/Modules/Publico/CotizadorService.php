<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Closure;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/**
 * Cotizador público (diapositiva 7 del prototipo): dado origen, destino y peso, devuelve el tipo de
 * envío que corresponde, el precio por bulto y las tarifas de todos los tipos para esa ruta.
 *
 * Los importes se devuelven como cadenas con sus decimales ("14.60") para no perder formato.
 */
final class CotizadorService
{
    private readonly RepositorioAgencias $agencias;
    private readonly RepositorioRutas $rutas;
    private readonly Closure $hoy;

    /**
     * @param (callable(): string)|null $hoy Devuelve la fecha de hoy (AAAA-MM-DD). Se inyecta para poder probar.
     */
    public function __construct(?RepositorioAgencias $agencias = null, ?RepositorioRutas $rutas = null, ?callable $hoy = null)
    {
        $this->agencias = $agencias ?? new AgenciaRepository();
        $this->rutas = $rutas ?? new RutaRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    public function cotizar(string $origen, string $destino, string $peso): array
    {
        $origen = strtoupper(trim($origen));
        $destino = strtoupper(trim($destino));

        if ($origen === '' || preg_match('/^[A-Z]{3}[0-9]{3}$/', $origen) !== 1) {
            throw new HttpException(422, 'datos_invalidos', 'Elige la sucursal de origen.');
        }
        if ($destino === '' || preg_match('/^[A-Z]{3}[0-9]{3}$/', $destino) !== 1) {
            throw new HttpException(422, 'datos_invalidos', 'Elige la sucursal de destino.');
        }
        if ($origen === $destino) {
            throw new HttpException(422, 'datos_invalidos', 'El origen y el destino deben ser distintos.');
        }

        $centesimas = $this->pesoEnCentesimas($peso);
        $tipoAplicable = Tarifas::tipoPorPeso($centesimas);
        if ($tipoAplicable === null) {
            throw new HttpException(
                422,
                'peso_excedido',
                sprintf('El peso máximo que se cotiza es %d kg.', max(Tarifas::LIMITES_KG)),
            );
        }

        $sucursalOrigen = $this->agencias->buscarActivaPorCodigo($origen);
        $sucursalDestino = $this->agencias->buscarActivaPorCodigo($destino);
        if ($sucursalOrigen === null || $sucursalDestino === null) {
            throw new HttpException(404, 'sucursal_no_encontrada', 'No se encontró una de las sucursales elegidas.');
        }

        $vigentes = [];
        foreach ($this->rutas->vigentes($origen, $destino, ($this->hoy)()) as $ruta) {
            $vigentes[(string) $ruta['tipoEnvio']] = $ruta;
        }

        $tarifas = [];
        foreach (Tarifas::LIMITES_KG as $tipo => $maximoKg) {
            $tarifas[] = self::tarifa($tipo, $maximoKg, $vigentes[$tipo] ?? null);
        }

        $cotizacion = null;
        foreach ($tarifas as $tarifa) {
            if ($tarifa['tipo'] === $tipoAplicable) {
                $cotizacion = $tarifa;
            }
        }

        return [
            'origen' => ['codigo' => $origen, 'nombre' => $sucursalOrigen['nombre']],
            'destino' => ['codigo' => $destino, 'nombre' => $sucursalDestino['nombre']],
            'pesoKg' => sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100),
            'tipo' => $tipoAplicable,
            'cotizacion' => $cotizacion,
            'tarifas' => $tarifas,
        ];
    }

    /** "8.8" o "8,80" → 880. Hasta 2 decimales, mayor que 0 y dentro del tamaño de Bulto.pesoKg. */
    private function pesoEnCentesimas(string $peso): int
    {
        $peso = str_replace(',', '.', trim($peso));

        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $peso) !== 1) {
            throw new HttpException(422, 'datos_invalidos', 'Ingresa el peso en kilogramos, con hasta 2 decimales.');
        }

        $centesimas = (int) round(((float) $peso) * 100);

        if ($centesimas <= 0) {
            throw new HttpException(422, 'datos_invalidos', 'El peso debe ser mayor que 0.');
        }

        return $centesimas;
    }

    /**
     * @param array<string, mixed>|null $ruta
     * @return array<string, mixed>
     */
    private static function tarifa(string $tipo, int $pesoMaximoKg, ?array $ruta): array
    {
        if ($ruta === null) {
            return [
                'tipo' => $tipo,
                'pesoMaximoKg' => $pesoMaximoKg,
                'disponible' => false,
                'precioPorBulto' => null,
                'tiempoHoras' => null,
                'precioBase' => null,
                'precioPorKm' => null,
                'distanciaKm' => null,
            ];
        }

        $centimos = Tarifas::costoBultoCentimos($ruta['precioBase'], $ruta['precioPorKm'], $ruta['distanciaKm']);

        return [
            'tipo' => $tipo,
            'pesoMaximoKg' => $pesoMaximoKg,
            'disponible' => true,
            'precioPorBulto' => Tarifas::formatoSoles($centimos),
            'tiempoHoras' => (int) $ruta['tiempoHoras'],
            'precioBase' => number_format((float) $ruta['precioBase'], 2, '.', ''),
            'precioPorKm' => number_format((float) $ruta['precioPorKm'], 3, '.', ''),
            'distanciaKm' => self::sinCeroFinal((float) $ruta['distanciaKm']),
        ];
    }

    /** 230.0 → "230", 230.5 → "230.5" */
    private static function sinCeroFinal(float $kilometros): string
    {
        $texto = number_format($kilometros, 1, '.', '');

        return str_ends_with($texto, '.0') ? substr($texto, 0, -2) : $texto;
    }
}
