<?php
declare(strict_types=1);

namespace Jatsa\Modules\Usuarios;

/**
 * Acceso a Usuario, Persona y Sucursal para que el GERENTE gestione las cuentas (módulo 10).
 *
 * Nunca devuelve el hash de la contraseña ni documentos o teléfonos de las personas. Las operaciones que dependen de datos que
 * pueden cambiar a la vez reciben un `$decidir`: el repositorio abre la transacción, bloquea las filas implicadas, reúne lo que
 * hace falta y llama a `$decidir`, que aplica las reglas y lanza HttpException si no procede (todo se deshace).
 */
interface RepositorioGestionUsuarios
{
    /**
     * Cuentas, por usuario. Se piden `$limite` filas como máximo.
     *
     * @param array{q: string, rol: string, estado: string} $filtros q busca en el usuario y en el nombre; rol '' o un rol; estado '' | ACTIVA | INACTIVA
     * @return list<array<string, mixed>> nombreUsuario, nombreCompleto, rol, sucursalCodigo (?), sucursalNombre (?), activo (bool)
     */
    public function listar(array $filtros, int $limite): array;

    /**
     * Persona con ese documento, si existe, y si ya tiene una cuenta. Solo el nombre: sin teléfono ni correo.
     *
     * @return array{idPersona: int, nombreCompleto: string, tieneUsuario: bool}|null
     */
    public function personaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array;

    public function usuarioExiste(string $nombreUsuario): bool;

    /** id de la sucursal ACTIVA con ese código, o null. */
    public function idSucursalActiva(string $codigo): ?int;

    /**
     * Crea la cuenta de una persona que ya existe y todavía no tiene una. Lanza HttpException 409 si el usuario ya existe o la
     * persona ya tiene cuenta (carrera entre dos altas).
     */
    public function crearCuenta(int $idPersona, ?int $idSucursal, string $nombreUsuario, string $hashContrasena, string $rol): void;

    /**
     * Una cuenta por su usuario (sin hash), o null.
     *
     * @return array<string, mixed>|null nombreUsuario, nombreCompleto, rol, sucursalCodigo (?), sucursalNombre (?), activo (bool)
     */
    public function cuenta(string $nombreUsuario): ?array;

    /**
     * Cambia rol y sucursal de una cuenta. Bloquea la cuenta y llama a $decidir con ['rol' => string, 'activo' => bool,
     * 'viajesAbiertos' => int] (viajes sin llegada como transportista). null si la cuenta no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarPuesto(string $nombreUsuario, string $rol, ?int $idSucursal, callable $decidir): ?bool;

    /**
     * Activa o desactiva una cuenta. Bloquea la cuenta y los gerentes activos y llama a $decidir con ['rol' => string,
     * 'activo' => bool, 'viajesAbiertos' => int, 'gerentesActivos' => int]. null si la cuenta no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool;
}
