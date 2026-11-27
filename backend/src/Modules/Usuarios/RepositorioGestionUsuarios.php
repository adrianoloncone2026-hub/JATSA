<?php
declare(strict_types=1);

namespace Jatsa\Modules\Usuarios;

/** Acceso a Usuario, Persona y Sucursal para que el GERENTE gestione las cuentas (módulo 10). */
interface RepositorioGestionUsuarios
{
    /**
     * @param array{q: string, rol: string, estado: string} $filtros q busca en el usuario y en el nombre; rol '' o un rol; estado '' | ACTIVA | INACTIVA
     * @return list<array<string, mixed>> nombreUsuario, nombreCompleto, rol, sucursalCodigo (?), sucursalNombre (?), activo (bool)
     */
    public function listar(array $filtros, int $limite): array;

    /** @return array{idPersona: int, nombreCompleto: string, tieneUsuario: bool}|null */
    public function personaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    public function usuarioExiste(string $nombreUsuario): bool;

    /**
     * Crea la cuenta de una persona que ya existe y todavía no tiene una. En UNA transacción bloquea la sucursal (UPDLOCK, HOLDLOCK) y
     * @param callable(array<string, mixed>): void $decidir
     */
    public function crearCuenta(int $idPersona, string $codigoSucursal, string $nombreUsuario, string $hashContrasena, string $rol, callable $decidir): void;

    /**
     * Una cuenta por su usuario (sin hash), o null.
     * @return array<string, mixed>|null nombreUsuario, nombreCompleto, rol, sucursalCodigo (?), sucursalNombre (?), activo (bool)
     */
    public function cuenta(string $nombreUsuario): ?array;

    /**
     * Cambia rol y sucursal de una cuenta. En UNA transacción bloquea, en este orden, la sucursal de destino y la cuenta (UPDLOCK, HOLDLOCK)
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarPuesto(string $nombreUsuario, string $rol, string $codigoSucursal, callable $decidir): ?bool;

    /**
     * Activa o desactiva una cuenta. En UNA transacción bloquea, en este orden, la sucursal de la cuenta (si tiene), los gerentes
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool;
}
