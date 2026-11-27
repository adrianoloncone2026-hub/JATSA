<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use DateTimeImmutable;

/** Acceso a Envio y Bulto (y a la tarifa Ruta que se cobra en cada bulto). */
interface RepositorioEnvios
{
    /** @return list<array<string, mixed>> */
    public function tarifasVigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array;

    /**
     * Formaliza la reserva en UNA transacción. Primero la «reclama» con un UPDATE atómico (solo si es de esa sucursal de
     * @param callable(array<string, mixed>, list<array<string, mixed>>): array<string, mixed> $planificar
     * Recibe [idReserva, idSucursalOrigen, idSucursalDestino] y las tarifas vigentes. Devuelve
     * [tipoEntrega, direccionEntrega (?string), bultos => list<[numero, descripcion, pesoKg, costo, idRuta]>].
     * @return string|null Código de seguimiento, o null si la reserva ya no se podía formalizar.
     */
    public function formalizar(string $codigoReserva, int $idSucursalOrigen, string $fechaIso, callable $planificar, ?DateTimeImmutable $referencia = null): ?string;

    /**
     * @return array<string, mixed>|null Claves: codigoSeguimiento, fechaHoraRegistro, tipoEntrega, direccionEntrega,
     * estado, idSucursalOrigen, idSucursalDestino, reserva{codigo, contenido, canal, remitente, destinatario,
     * origen, destino}, bultos list<{numero, descripcion, pesoKg, costo, tipo}>.
     */
    public function buscarPorCodigo(string $codigoSeguimiento): ?array;
}
