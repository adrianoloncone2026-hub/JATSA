<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateTimeImmutable;

/** Acceso a Reserva (y a Persona para el destinatario). Las reservas se devuelven como arreglos con las claves: */
interface RepositorioReservas
{
    public const HORAS_DE_VIGENCIA = 72;

    public function idPersonaDeUsuario(int $idUsuario): ?int;

    /** @return array{idPersona: int, nombreCompleto: string}|null */
    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    /**
     * Crea la reserva PENDIENTE por el canal WEB y devuelve su código (R + 9 dígitos). Si el destinatario no
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $destinatarioNuevo
     */
    public function crear(int $idRemitente, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string;

    /** @return list<array<string, mixed>> */
    public function listarDeRemitente(int $idPersona, ?string $estado): array;

    /** @return array<string, mixed>|null */
    public function buscarDeRemitente(int $idPersona, string $codigo): ?array;

    public function cancelar(int $idPersona, string $codigo, ?DateTimeImmutable $referencia = null): bool;

    public function vencerPendientes(?DateTimeImmutable $referencia = null): int;


    /**
     * Reservas PENDIENTES cuyo origen es la sucursal y que aún no cumplieron el plazo de vigencia (las que se pueden
     * @return list<array<string, mixed>> Claves: codigo, fechaHora, canal, contenido, estado, remitente y destinatario
     * ({tipoDocumento, numeroDocumento, nombreCompleto}), origen, destino,
     * codigoSeguimiento, plazoCumplido.
     */
    public function listarPendientesDeSucursal(int $idSucursal, ?DateTimeImmutable $referencia = null): array;

    /** @return array<string, mixed>|null */
    public function buscarDeSucursal(int $idSucursal, string $codigo): ?array;

    /**
     * Crea una reserva PENDIENTE con canal VENTANILLA desde la sucursal de origen. Remitente y destinatario pueden ser
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $remitenteNuevo
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $destinatarioNuevo
     */
    public function crearEnVentanilla(?int $idRemitente, ?array $remitenteNuevo, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string;

    /** @return list<array{codigo: string, fechaHora: string}> */
    public function listarVencibles(?DateTimeImmutable $referencia = null): array;
}
