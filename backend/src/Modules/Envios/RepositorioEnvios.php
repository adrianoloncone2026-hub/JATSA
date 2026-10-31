<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use DateTimeImmutable;

/**
 * Acceso a Envio y Bulto (y a la tarifa Ruta que se cobra en cada bulto).
 *
 * Una tarifa vigente es un arreglo con: idRuta (int), tipoEnvio, precioBase, precioPorKm, distanciaKm (cadenas con sus
 * decimales) y tiempoHoras (int); como mucho una por tipo (la de vigenteDesde más reciente).
 */
interface RepositorioEnvios
{
    /**
     * Tarifas vigentes en la fecha para la ruta origen → destino (por código de sucursal). Solo lectura.
     *
     * @return list<array<string, mixed>>
     */
    public function tarifasVigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array;

    /**
     * Formaliza la reserva en UNA transacción. Primero la «reclama» con un UPDATE atómico (solo si es de esa sucursal de
     * origen, sigue PENDIENTE y no cumplió el plazo de vigencia); si no cambia ninguna fila, deshace y devuelve null.
     * Con la reserva ya reclamada lee las tarifas vigentes dentro de la misma transacción y llama a $planificar, que
     * aplica las reglas de negocio y devuelve qué guardar (o lanza HttpException, y todo se deshace). Después genera un
     * código de seguimiento libre, inserta el Envio y sus Bulto y confirma.
     *
     * @param callable(array<string, mixed>, list<array<string, mixed>>): array<string, mixed> $planificar
     *        Recibe [idReserva, idSucursalOrigen, idSucursalDestino] y las tarifas vigentes. Devuelve
     *        [tipoEntrega, direccionEntrega (?string), bultos => list<[numero, descripcion, pesoKg, costo, idRuta]>].
     * @return string|null Código de seguimiento, o null si la reserva ya no se podía formalizar.
     */
    public function formalizar(string $codigoReserva, int $idSucursalOrigen, string $fechaIso, callable $planificar, ?DateTimeImmutable $referencia = null): ?string;

    /**
     * Envío con su reserva (remitente y destinatario con documento: uso interno del personal) y sus bultos.
     *
     * @return array<string, mixed>|null Claves: codigoSeguimiento, fechaHoraRegistro, tipoEntrega, direccionEntrega,
     *         estado, idSucursalOrigen, idSucursalDestino, reserva{codigo, contenido, canal, remitente, destinatario,
     *         origen, destino}, bultos list<{numero, descripcion, pesoKg, costo, tipo}>.
     */
    public function buscarPorCodigo(string $codigoSeguimiento): ?array;
}
