<?php
declare(strict_types=1);

namespace Jatsa\Modules\Auth;

use Jatsa\Shared\Credencial;
use Jatsa\Shared\HttpException;

/**
 * Reglas de autenticación. No toca HTTP ni sesiones: recibe un repositorio y devuelve datos públicos.
 */
final class AuthService
{
    /**
     * Hash bcrypt (coste 12) de una contraseña aleatoria ya descartada. Se verifica cuando el usuario
     * no existe para que la respuesta tarde lo mismo y no se pueda averiguar qué usuarios existen.
     */
    private const HASH_DE_RELLENO = '$2y$12$qZgqOoM9kiG.0/w2h/f1AeZVdRipRcO/JoxSbI/6slw7Qr61xoPva';

    private readonly RepositorioUsuarios $usuarios;

    public function __construct(?RepositorioUsuarios $usuarios = null)
    {
        $this->usuarios = $usuarios ?? new UsuarioRepository();
    }

    /**
     * Valida usuario y contraseña. La contraseña se comprueba antes del estado de la cuenta,
     * para no revelar a un desconocido que una cuenta existe o está desactivada.
     *
     * @return array<string, mixed> Datos públicos del usuario.
     */
    public function ingresar(string $nombreUsuario, string $contrasena): array
    {
        return $this->ingresarConHuella($nombreUsuario, $contrasena)['usuario'];
    }

    /**
     * Igual que ingresar(), y además devuelve la huella de la credencial para guardarla en la sesión.
     *
     * @return array{usuario: array<string, mixed>, huella: string}
     */
    public function ingresarConHuella(string $nombreUsuario, string $contrasena): array
    {
        $nombreUsuario = trim($nombreUsuario);

        if ($nombreUsuario === '' || $contrasena === '') {
            throw new HttpException(422, 'datos_invalidos', 'Ingresa tu usuario y tu contraseña.');
        }

        if (strlen($nombreUsuario) > 30 || strlen($contrasena) > 200) {
            throw $this->credencialesInvalidas();
        }

        $registro = $this->usuarios->buscarPorNombre($nombreUsuario);

        $coincide = password_verify($contrasena, $registro['hashContrasena'] ?? self::HASH_DE_RELLENO);

        if ($registro === null || !$coincide) {
            throw $this->credencialesInvalidas();
        }

        if (!$registro['activo']) {
            throw new HttpException(
                403,
                'cuenta_desactivada',
                'Esta cuenta está desactivada. Acércate a tu agencia para reactivarla.',
            );
        }

        return ['usuario' => self::datosPublicos($registro), 'huella' => Credencial::huella($registro['hashContrasena'])];
    }

    /**
     * Datos actuales de una cuenta activa, o null si ya no existe o está desactivada.
     * Si se indica la huella con la que se abrió la sesión, también debe seguir siendo la de la
     * contraseña actual: así, cambiar la contraseña cierra las demás sesiones de la cuenta.
     *
     * @return array<string, mixed>|null
     */
    public function vigente(int $idUsuario, ?string $huella = null): ?array
    {
        $registro = $this->usuarios->buscarPorId($idUsuario);

        if ($registro === null || !$registro['activo']) {
            return null;
        }

        if ($huella !== null && !hash_equals(Credencial::huella($registro['hashContrasena']), $huella)) {
            return null;
        }

        return self::datosPublicos($registro);
    }

    /**
     * Nunca incluye el hash de la contraseña.
     *
     * @param array<string, mixed> $registro
     * @return array<string, mixed>
     */
    public static function datosPublicos(array $registro): array
    {
        return [
            'id' => $registro['idUsuario'],
            'nombreUsuario' => $registro['nombreUsuario'],
            'nombreCompleto' => $registro['nombreCompleto'],
            'rol' => $registro['rol'],
            'sucursal' => $registro['idSucursal'] === null ? null : [
                'id' => $registro['idSucursal'],
                'codigo' => $registro['codigoSucursal'],
                'nombre' => $registro['nombreSucursal'],
            ],
        ];
    }

    private function credencialesInvalidas(): HttpException
    {
        return new HttpException(401, 'credenciales_invalidas', 'Usuario o contraseña incorrectos.');
    }
}
