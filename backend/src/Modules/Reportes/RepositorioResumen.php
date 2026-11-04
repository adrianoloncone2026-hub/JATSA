<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/**
 * Lectura agregada para el Resumen del GERENTE (diapositiva 26). Solo lee; no contiene reglas de negocio.
 *
 * $sucursal es el código de sucursal o null (todas). Para envíos y viajes cuenta la participación como origen o destino
 * (sin duplicar); para el dinero cobrado usa la sucursal donde se registró el pago.
 */
interface RepositorioResumen
{
    /**
     * Envíos registrados en [$desde, $hasta) agrupados por su estado actual, y cuántos de los pendientes de entrega
     * (EN_TRANSITO o EN_DESTINO) se registraron antes de $limiteAntiguedad.
     *
     * @param string $desde AAAA-MM-DD HH:MM:SS (incluido)
     * @param string $hasta AAAA-MM-DD HH:MM:SS (excluido)
     * @return array{porEstado: array<string, int>, antiguos: int}
     */
    public function enviosDelPeriodo(string $desde, string $hasta, ?string $sucursal, string $limiteAntiguedad): array;

    /** Suma de pagos NO anulados (estadoPago = REGISTRADO) cobrados en [$desde, $hasta), como cadena con 2 decimales. */
    public function cobradoDelPeriodo(string $desde, string $hasta, ?string $sucursal): string;

    /** Viajes abiertos ahora: en ruta (salieron y no llegaron) y programados (sin salida). @return array{enRuta: int, programados: int} */
    public function viajesAbiertos(?string $sucursal): array;
}
