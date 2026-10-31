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

    // ── Ventanilla (módulo 5): el personal de una agencia ve y crea reservas de SU sucursal de origen ──

    /**
     * Reservas PENDIENTES cuyo origen es la sucursal y que aún no cumplieron el plazo de vigencia (las que se pueden
     * formalizar), de la más antigua a la más reciente. Incluyen los datos de remitente y destinatario con su documento
     * (uso interno del personal: nunca para el público).
     *
     * @return list<array<string, mixed>> Claves: codigo, fechaHora, canal, contenido, estado, remitente y destinatario
     *                                    ({tipoDocumento, numeroDocumento, nombreCompleto}), origen, destino,
     *                                    codigoSeguimiento, plazoCumplido.
     */
    public function listarPendientesDeSucursal(int $idSucursal, ?DateTimeImmutable $referencia = null): array;

    /**
     * Una reserva de la sucursal de origen (en cualquier estado) con las mismas claves que listarPendientesDeSucursal.
     * Si no existe o es de otro origen, null.
     *
     * @return array<string, mixed>|null
     */
    public function buscarDeSucursal(int $idSucursal, string $codigo): ?array;

    /**
     * Crea una reserva PENDIENTE con canal VENTANILLA desde la sucursal de origen. Remitente y destinatario pueden ser
     * personas existentes (id) o nuevas (datos); las nuevas se crean en la misma transacción. Devuelve el código.
     *
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $remitenteNuevo
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string}|null $destinatarioNuevo
     */
    public function crearEnVentanilla(?int $idRemitente, ?array $remitenteNuevo, ?int $idDestinatario, ?array $destinatarioNuevo, string $codigoOrigen, string $codigoDestino, string $contenido): string;

    /**
     * Código y fecha de las reservas PENDIENTES que ya cumplieron el plazo (lo que vencería un vencerPendientes).
     * Solo lectura.
     *
     * @return list<array{codigo: string, fechaHora: string}>
     */
    public function listarVencibles(?DateTimeImmutable $referencia = null): array;
}
