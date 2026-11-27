<?php
declare(strict_types=1);

namespace Jatsa\Shared;

use Jatsa\Core\Config;

/** Sesión del usuario con las sesiones nativas de PHP (cookie HttpOnly, SameSite=Lax). */
final class Sesion
{
    /** @param array<string, mixed> $usuario Datos públicos del usuario (sin hash de contraseña). */
    public static function abrir(array $usuario, bool $recordar, string $huella = ''): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $dias = (int) Config::obtener('sesion.dias_recordar', 14);
        self::configurar($recordar ? $dias * 86400 : 0);
        session_start();
        session_regenerate_id(true);

        $_SESSION['usuario'] = $usuario;
        $_SESSION['huella'] = $huella;
        $_SESSION['recordar'] = $recordar;
        $_SESSION['expira'] = time() + self::duracionInactividad($recordar);
    }

    public static function huella(): string
    {
        return self::iniciarSiExiste() ? (string) ($_SESSION['huella'] ?? '') : '';
    }

    public static function renovarHuella(string $huella): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['huella'] = $huella;
            session_regenerate_id(true);
        }
    }

    /** @return array<string, mixed>|null */
    public static function usuario(): ?array
    {
        if (!self::iniciarSiExiste()) {
            return null;
        }

        $usuario = $_SESSION['usuario'] ?? null;
        $expira = (int) ($_SESSION['expira'] ?? 0);

        if (!is_array($usuario) || $expira < time()) {
            self::cerrar();
            return null;
        }

        $_SESSION['expira'] = time() + self::duracionInactividad((bool) ($_SESSION['recordar'] ?? false));

        return $usuario;
    }

    /** @param array<string, mixed> $usuario */
    public static function actualizar(array $usuario): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['usuario'] = $usuario;
        }
    }

    public static function cerrar(): void
    {
        if (!self::iniciarSiExiste() && session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $nombre = session_name();
        session_destroy();

        setcookie($nombre, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool) Config::obtener('sesion.segura', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function iniciarSiExiste(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $nombre = (string) Config::obtener('sesion.nombre', 'JATSA_SESION');
        if (!isset($_COOKIE[$nombre])) {
            return false;
        }

        self::configurar(0);

        return session_start();
    }

    private static function configurar(int $duracionCookie): void
    {
        $carpeta = Config::raiz() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (is_dir($carpeta)) {
            session_save_path($carpeta);
        }

        session_name((string) Config::obtener('sesion.nombre', 'JATSA_SESION'));

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) ((int) Config::obtener('sesion.dias_recordar', 14) * 86400));

        session_set_cookie_params([
            'lifetime' => $duracionCookie,
            'path' => '/',
            'secure' => (bool) Config::obtener('sesion.segura', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function duracionInactividad(bool $recordar): int
    {
        return $recordar
            ? (int) Config::obtener('sesion.dias_recordar', 14) * 86400
            : (int) Config::obtener('sesion.horas_inactividad', 8) * 3600;
    }
}
