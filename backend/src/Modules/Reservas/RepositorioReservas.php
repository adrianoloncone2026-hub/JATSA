<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use DateTimeImmutable;

/**
 * Acceso a Reserva (y a Persona para el destinatario). Las reservas se devuelven como arreglos con las claves:
 * codigo, fechaHora (AAAA-MM-DD HH:MM:SS), canal, contenido, estado, destinatario (nombre completo),
 * origen y destino ({codigo, nombre, direccion, departamento}), codigoSeguimiento (?string, solo si ya nació
 * el envío) y plazoCumplido (bool: sigue PENDIENTE pero ya pasaron las horas de vigencia).
 *
 * Vigencia: una reserva PENDIENTE vence cuando pasan HORAS_DE_VIGENCIA desde fechaHoraReserva; en el instante
 * límite exacto ya vence. Los métodos que comparan con «ahora» reciben una referencia opcional: con null se usa
 * el reloj de SQL Server (el mismo que escribe fechaHoraReserva); con un valor, ese instante (pruebas y simulaciones).
 */
interface RepositorioReservas
{
    /** Horas que dura una reserva PENDIENTE antes de vencer (decisión del propietario). */
    public const HORAS_DE_VIGENCIA = 72;

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

    /**
     * Pasa a CANCELADA solo si sigue PENDIENTE, es del remitente y NO ha cumplido el plazo de vigencia.
     * Devuelve false si no cambió nada (otra operación se adelantó, ya venció, no es suya…).
     */
    public function cancelar(int $idPersona, string $codigo, ?DateTimeImmutable $referencia = null): bool;

    /**
     * Pasa a VENCIDA todas las reservas PENDIENTES que cumplieron el plazo de vigencia. Un UPDATE atómico condicionado
     * a que sigan PENDIENTES, así no pisa a una cancelación o formalización que se haya adelantado. Devuelve cuántas.
     */
    public function vencerPendientes(?DateTimeImmutable $referencia = null): int;

    /**
     * Código y fecha de las reservas PENDIENTES que ya cumplieron el plazo (lo que vencería un vencerPendientes).
     * Solo lectura.
     *
     * @return list<array{codigo: string, fechaHora: string}>
     */
    public function listarVencibles(?DateTimeImmutable $referencia = null): array;
}
