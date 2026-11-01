<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/**
 * Lectura de las rutas, tarifas y viajes con los que se calculan las «salidas entre sucursales» (solo SELECT).
 */
interface RepositorioSalidas
{
    /**
     * Tarifas vigentes hoy de todas las rutas entre sucursales activas, la de vigenteDesde más reciente primero dentro de cada
     * (origen, destino, tipo). Claves: origenCodigo, origenNombre, destinoCodigo, destinoNombre, tipoEnvio, precioBase,
     * precioPorKm, distanciaKm (cadenas con decimales) y tiempoHoras (int).
     *
     * @return list<array<string, mixed>>
     */
    public function tarifasVigentes(string $hoy): array;

    /**
     * Las salidas REALES de los últimos viajes de cada ruta (hasta $porRuta por ruta), la más reciente primero.
     *
     * @return list<array{origenCodigo: string, destinoCodigo: string, fechaHoraSalida: string}>
     */
    public function salidasRecientes(int $porRuta): array;

    /**
     * Cuántos viajes PROGRAMADOS (sin salida) tiene cada ruta, por «ORIGEN-DESTINO».
     *
     * @return array<string, int>
     */
    public function programadosPorRuta(): array;

    /**
     * Viajes de una ruta que ya salieron, el más reciente primero.
     *
     * @return list<array{codigo: string, fechaHoraSalida: string, fechaHoraLlegada: ?string}>
     */
    public function viajesDeLaRuta(string $codigoOrigen, string $codigoDestino, int $limite): array;
}
