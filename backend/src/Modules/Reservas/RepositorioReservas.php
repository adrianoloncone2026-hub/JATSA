<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

/**
 * Acceso a Reserva (y a Persona para el destinatario). Las reservas se devuelven como arreglos con las claves:
 * codigo, fechaHora (AAAA-MM-DD HH:MM:SS), canal, contenido, estado, destinatario (nombre completo),
 * origen y destino ({codigo, nombre, direccion, departamento}) y codigoSeguimiento (?string, solo si ya nació el envío).
 */
interface RepositorioReservas
{
    /** Persona ligada a la cuenta de usuario, o null si no existe. */
    public function idPersonaDeUsuario(int $idUsuario): ?int;

    /** @return array{idPersona: int, nombreCompleto: string}|null */
    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    /**
     * Crea la reserva PENDIENTE por el canal WEB y devuelve su código (R + 9 dígitos). Si el destinatario no
     * existe ($idDestinatario null) crea también su persona. Todo en una transacción.
     *
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $destinatarioNuevo
     */
    public function crear(int $idRemitente, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string;

    /**
     * Reservas de la persona como remitente, de la más reciente a la más antigua.
     *
     * @return list<array<string, mixed>>
     */
    public function listarDeRemitente(int $idPersona, ?string $estado): array;

    /** @return array<string, mixed>|null */
    public function buscarDeRemitente(int $idPersona, string $codigo): ?array;

    /** Pasa a CANCELADA solo si sigue PENDIENTE y es del remitente. Devuelve false si no cambió nada. */
    public function cancelar(int $idPersona, string $codigo): bool;
}
