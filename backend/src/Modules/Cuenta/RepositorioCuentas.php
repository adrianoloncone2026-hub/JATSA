<?php
declare(strict_types=1);

namespace Jatsa\Modules\Cuenta;

/**
 * Acceso a Persona y Usuario para registrarse y gestionar la propia cuenta.
 */
interface RepositorioCuentas
{
    /**
     * Persona con ese documento, si existe, y si ya tiene una cuenta de usuario.
     *
     * @return array{idPersona: int, tieneUsuario: bool}|null
     */
    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    public function usuarioExiste(string $nombreUsuario): bool;

    /**
     * Crea el usuario (y la persona, si $idPersona es null) en una sola transacción.
     * Lanza HttpException 409 si el usuario o el documento ya existen (carrera entre dos registros).
     *
     * @param array{tipoDocumento: string, numeroDocumento: string, nombreCompleto: string, telefono: string, telefonoAuxiliar: ?string, correo: ?string} $persona
     */
    public function registrar(?int $idPersona, array $persona, string $nombreUsuario, string $hashContrasena, string $rol): void;

    /**
     * Cuenta con los datos de su persona, o null si no existe. Claves: idUsuario, idPersona, nombreUsuario,
     * rol, activo (bool), fechaCreacion (AAAA-MM-DD), tipoDocumento, numeroDocumento, nombreCompleto,
     * telefono, telefonoAuxiliar (?string), correo (?string).
     *
     * @return array<string, mixed>|null
     */
    public function buscarCuenta(int $idUsuario): ?array;

    public function actualizarContacto(int $idPersona, string $telefono, ?string $telefonoAuxiliar, ?string $correo): void;

    public function obtenerHash(int $idUsuario): ?string;

    /**
     * Cambia el hash SOLO si la cuenta sigue teniendo `$hashAnterior` (actualización condicional, «compare-and-swap»): dos cambios
     * simultáneos que partieron de la misma contraseña no pueden confirmarse los dos. true si cambió una fila; false si otra
     * petición ya cambió la contraseña (o la cuenta no existe) y entonces no se escribe nada.
     */
    public function actualizarHash(int $idUsuario, string $hashAnterior, string $hashContrasena): bool;
}
