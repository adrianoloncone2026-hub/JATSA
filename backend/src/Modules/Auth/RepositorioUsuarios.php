<?php
declare(strict_types=1);

namespace Jatsa\Modules\Auth;

/** Acceso de lectura a las cuentas de usuario. Cada registro es un arreglo con las claves: */
interface RepositorioUsuarios
{
    /** @return array<string, mixed>|null */
    public function buscarPorNombre(string $nombreUsuario): ?array;

    /** @return array<string, mixed>|null */
    public function buscarPorId(int $idUsuario): ?array;
}
