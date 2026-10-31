<?php
declare(strict_types=1);

namespace Jatsa\Shared;

/**
 * Valores de Usuario.rol definidos por la restricción CK_USUARIO_ROL del script SQL.
 */
final class Roles
{
    public const GERENTE = 'GERENTE';
    public const ADMINISTRADOR = 'ADMINISTRADOR';
    public const TRANSPORTISTA = 'TRANSPORTISTA';
    public const CLIENTE = 'CLIENTE';
    public const EMPRESARIO = 'EMPRESARIO';
}
