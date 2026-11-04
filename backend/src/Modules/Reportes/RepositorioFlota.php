<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/**
 * Lectura agregada para «Viajes y flota» del GERENTE (diapositiva 28). Solo lee; no contiene reglas de negocio.
 *
 * $filtros: sucursal (código; el viaje sale de ella o llega a ella), placa, transportista (nombreUsuario) y tipoEnvio
 * (SOBRE, PAQUETE o CARGA: el viaje lleva al menos un bulto de ese tipo, según Ruta.tipoEnvio; un viaje cuenta una sola vez).
 * Cada uno es null si no se filtra. El periodo [$desde, $hasta) se aplica a fechaHoraSalida de los viajes terminados.
 * El peso de un viaje es la suma de los bultos de todos sus envíos cargados (Envio_Viaje).
 */
interface RepositorioFlota
{
    /** Viajes abiertos ahora (sin periodo). @param array<string, ?string> $filtros @return array{enRuta: int, programados: int} */
    public function viajesAbiertos(array $filtros): array;

    /**
     * Viajes terminados del periodo, su peso total y el promedio de uso de capacidad (peso cargado / capacidadKg de cada viaje,
     * entre 0 y 1; null si no hay viajes).
     *
     * @param array<string, ?string> $filtros
     * @return array{viajes: int, pesoKg: string, usoMedio: ?float}
     */
    public function terminados(array $filtros, string $desde, string $hasta): array;

    /**
     * Envíos distintos cargados en los viajes terminados del periodo, y cuántos de ellos viajaron en más de un viaje.
     *
     * @param array<string, ?string> $filtros
     * @return array{envios: int, conTransbordo: int}
     */
    public function transbordos(array $filtros, string $desde, string $hasta): array;

    /**
     * Viajes en ruta ahora: codigo, origen{codigo,nombre}, destino{codigo,nombre}, placa, transportista, salida (AAAA-MM-DD HH:MM:SS)
     * y horas (?int: tiempo estimado de la ruta vigente en $hoy).
     *
     * @param array<string, ?string> $filtros
     * @return list<array<string, mixed>>
     */
    public function viajesEnRuta(array $filtros, string $hoy): array;

    /**
     * Transportistas con viajes terminados en el periodo: transportista (nombre), sucursalCodigo (?string), viajes, pesoKg.
     * Orden: más viajes primero.
     *
     * @param array<string, ?string> $filtros
     * @return list<array<string, mixed>>
     */
    public function transportistas(array $filtros, string $desde, string $hasta): array;
}
