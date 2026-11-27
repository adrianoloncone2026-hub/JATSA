<?php
declare(strict_types=1);

namespace Jatsa\Modules\Cuenta;

/** Acceso a Persona y Usuario para registrarse y gestionar la propia cuenta. */
interface RepositorioCuentas
{
    /** @return array{idPersona: int, tieneUsuario: bool}|null */
    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    public function usuarioExiste(string $nombreUsuario): bool;

    /**
     * Crea el usuario (y la persona, si $idPersona es null) en una sola transacción.
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string, telefonoAuxiliar: ?string, correo: ?string} $persona
     */
    public function registrar(?int $idPersona, array $persona, string $nombreUsuario, string $hashContrasena, string $rol): void;

    /** @return array<string, mixed>|null */
    public function buscarCuenta(int $idUsuario): ?array;

    public function actualizarContacto(int $idPersona, string $telefono, ?string $telefonoAuxiliar, ?string $correo): void;

    public function obtenerHash(int $idUsuario): ?string;

    /** Cambia el hash SOLO si la cuenta sigue teniendo `$hashAnterior` (actualización condicional, «compare-and-swap»): dos cambios */
    public function actualizarHash(int $idUsuario, string $hashAnterior, string $hashContrasena): bool;
}
