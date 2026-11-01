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

    /**
     * Crea la cuenta de una persona que ya existe y todavía no tiene una. En UNA transacción bloquea la sucursal (UPDLOCK, HOLDLOCK) y
     * llama a $decidir con ['sucursalActiva' => bool] (false si no existe): una desactivación de la sucursal, que bloquea la misma fila
     * y cuenta el personal activo, no puede intercalarse entre la validación y el alta. Lanza HttpException 409 si el usuario ya existe
     * o la persona ya tiene cuenta (carrera entre dos altas).
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function crearCuenta(int $idPersona, string $codigoSucursal, string $nombreUsuario, string $hashContrasena, string $rol, callable $decidir): void;

    /**
     * Una cuenta por su usuario (sin hash), o null.
     *
     * @return array<string, mixed>|null nombreUsuario, nombreCompleto, rol, sucursalCodigo (?), sucursalNombre (?), activo (bool)
     */
    public function cuenta(string $nombreUsuario): ?array;

    /**
     * Cambia rol y sucursal de una cuenta. En UNA transacción bloquea, en este orden, la sucursal de destino y la cuenta (UPDLOCK, HOLDLOCK)
     * y llama a $decidir con lo leído YA BLOQUEADO: ['rolActual' => string, 'sucursalActual' => ?string (código), 'activo' => bool,
     * 'sucursalActiva' => bool (la de destino), 'viajesAbiertos' => int (viajes sin llegada como transportista)]. Quien decide
     * calcula la diferencia con esos valores, no con una lectura anterior. null si la cuenta no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarPuesto(string $nombreUsuario, string $rol, string $codigoSucursal, callable $decidir): ?bool;

    /**
     * Activa o desactiva una cuenta. En UNA transacción bloquea, en este orden, la sucursal de la cuenta (si tiene), los gerentes
     * activos y la cuenta (UPDLOCK, HOLDLOCK) y llama a $decidir con lo leído YA BLOQUEADO: ['rol' => string, 'activo' => bool,
     * 'sucursalCodigo' => ?string, 'sucursalActiva' => bool (false si no tiene sucursal o está inactiva), 'viajesAbiertos' => int,
     * 'gerentesActivos' => int]. Si la cuenta cambió de sucursal entre la lectura que eligió qué bloquear y el bloqueo de la cuenta,
     * reintenta con la nueva; tras varios intentos lanza HttpException 409. null si la cuenta no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool;
}
