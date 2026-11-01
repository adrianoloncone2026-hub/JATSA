<?php
declare(strict_types=1);

namespace Jatsa\Modules\Usuarios;

use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;
use Jatsa\Shared\Validacion;

/**
 * Cuentas de usuario del GERENTE (diapositiva 30 del prototipo): lista, alta de personal, edición de puesto y desactivación.
 *
 * La cuenta de personal se crea para una PERSONA QUE YA EXISTE y todavía no tiene cuenta (la pantalla pide solo «Documento de la
 * persona»; Persona exige teléfono y la cuenta es 1:1 con la persona). Nunca se devuelve el hash ni documentos o teléfonos.
 * Las cuentas se desactivan, no se borran: la sesión de una cuenta desactivada deja de valer en la siguiente petición.
 */
final class UsuariosService
{
    public const ROLES = [Roles::GERENTE, Roles::ADMINISTRADOR, Roles::TRANSPORTISTA, Roles::CLIENTE, Roles::EMPRESARIO];
    /** Roles de personal de agencia: tienen sucursal obligatoria (CK_USUARIO_SUCURSAL). */
    public const ROLES_PERSONAL = [Roles::ADMINISTRADOR, Roles::TRANSPORTISTA];
    public const TIPOS_DOCUMENTO = ['DNI', 'CE', 'PAS'];
    public const MAX_LISTA = 200;
    public const MAX_BUSQUEDA = 50;
    private const LARGO_MINIMO_CONTRASENA = 8;
    private const BYTES_MAXIMOS_CONTRASENA = 72; // bcrypt ignora lo que pase de 72 bytes

    private readonly RepositorioGestionUsuarios $usuarios;

    public function __construct(?RepositorioGestionUsuarios $usuarios = null)
    {
        $this->usuarios = $usuarios ?? new UsuariosRepository();
    }

    /**
     * @param array<string, mixed> $sesion   usuario de la sesión (para marcar «esUsted»)
     * @param array<string, mixed> $consulta q, rol, estado
     * @return array{cuentas: list<array<string, mixed>>, hayMas: bool}
     */
    public function listar(array $sesion, array $consulta): array
    {
        $filtros = ['q' => Validacion::texto($consulta, 'q'), 'rol' => strtoupper(Validacion::texto($consulta, 'rol')), 'estado' => strtoupper(Validacion::texto($consulta, 'estado'))];
        $errores = [];
        if (!Validacion::largoMaximo($filtros['q'], self::MAX_BUSQUEDA)) {
            $errores['q'] = sprintf('La búsqueda admite hasta %d caracteres.', self::MAX_BUSQUEDA);
        }
        if ($filtros['rol'] !== '' && !in_array($filtros['rol'], self::ROLES, true)) {
            $errores['rol'] = 'Elige un rol válido.';
        }
        if ($filtros['estado'] !== '' && !in_array($filtros['estado'], ['ACTIVA', 'INACTIVA'], true)) {
            $errores['estado'] = 'Elige ACTIVA o INACTIVA.';
        }
        $this->exigirValidos($errores);

        $filas = $this->usuarios->listar($filtros, self::MAX_LISTA + 1);
        $yo = strtolower((string) ($sesion['nombreUsuario'] ?? ''));

        return [
            'cuentas' => array_map(fn (array $c): array => $this->presentar($c, $yo), array_slice($filas, 0, self::MAX_LISTA)),
            'hayMas' => count($filas) > self::MAX_LISTA,
        ];
    }

    /**
     * Para el formulario «Nueva cuenta de personal»: ¿la persona existe y puede recibir una cuenta?
     *
     * @param array<string, mixed> $consulta tipoDocumento, numeroDocumento
     * @return array{persona: array<string, mixed>}
     */
    public function consultarPersona(array $consulta): array
    {
        $errores = [];
        [$tipo, $numero] = $this->documento($consulta, $errores);
        $this->exigirValidos($errores);
        $persona = $this->usuarios->personaPorDocumento((string) $tipo, (string) $numero);

        return ['persona' => $persona === null ? ['existe' => false] : ['existe' => true, 'nombreCompleto' => $persona['nombreCompleto'], 'tieneCuenta' => $persona['tieneUsuario']]];
    }

    /**
     * @param array<string, mixed> $datos tipoDocumento, numeroDocumento, rol, sucursal, usuario, contrasena
     * @return array<string, mixed>
     */
    public function crear(array $sesion, array $datos): array
    {
        $errores = [];
        [$tipo, $numero] = $this->documento($datos, $errores);
        $rol = strtoupper(Validacion::texto($datos, 'rol'));
        if (!in_array($rol, self::ROLES_PERSONAL, true)) {
            $errores['rol'] = 'Elige ADMINISTRADOR o TRANSPORTISTA.';
        }
        $sucursal = strtoupper(Validacion::texto($datos, 'sucursal'));
        if (preg_match('/^[A-Z]{3}\d{3}$/', $sucursal) !== 1) {
            $errores['sucursal'] = 'La sucursal es obligatoria para este rol.';
        }
        $usuario = Validacion::texto($datos, 'usuario');
        if (preg_match('/^[A-Za-z0-9._-]{4,30}$/', $usuario) !== 1) {
            $errores['usuario'] = 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.';
        }
        $contrasena = Validacion::texto($datos, 'contrasena', false);
        $errorContrasena = self::errorDeContrasena($contrasena, $usuario);
        if ($errorContrasena !== null) {
            $errores['contrasena'] = $errorContrasena;
        }
        $this->exigirValidos($errores);

        $persona = $this->usuarios->personaPorDocumento((string) $tipo, (string) $numero);
        if ($persona === null) {
            throw new HttpException(404, 'persona_no_encontrada', 'No hay una persona registrada con ese documento. Debe existir antes de crearle una cuenta.', [], ['numeroDocumento' => 'No hay una persona registrada con ese documento.']);
        }
        if ($persona['tieneUsuario']) {
            throw new HttpException(409, 'cuenta_existente', 'Esa persona ya tiene una cuenta.', [], ['numeroDocumento' => 'Esa persona ya tiene una cuenta.']);
        }
        if ($this->usuarios->usuarioExiste($usuario)) {
            throw new HttpException(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', [], ['usuario' => 'Ese usuario ya está en uso. Elige otro.']);
        }

        // Que la sucursal exista y esté ACTIVA se decide DENTRO de la transacción del repositorio, con la sucursal bloqueada: su
        // desactivación (que cuenta el personal activo) no puede intercalarse entre esta validación y el alta de la cuenta.
        $this->usuarios->crearCuenta($persona['idPersona'], $sucursal, $usuario, password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 12]), $rol, static function (array $ctx): void {
            if (!$ctx['sucursalActiva']) {
                throw self::datosInvalidos(['sucursal' => 'Elige una sucursal activa.']);
            }
        });

        return $this->presentar($this->cuentaDe($usuario), strtolower((string) ($sesion['nombreUsuario'] ?? '')));
    }

    /**
     * «Editar»: el puesto del personal (rol y sucursal). Las demás cuentas solo se activan o desactivan.
     *
     * @param array<string, mixed> $datos rol, sucursal
     * @return array<string, mixed>
     */
    public function editar(array $sesion, string $nombreUsuario, array $datos): array
    {
        $nombreUsuario = $this->nombreValido($nombreUsuario);
        $errores = [];
        $rol = strtoupper(Validacion::texto($datos, 'rol'));
        if (!in_array($rol, self::ROLES_PERSONAL, true)) {
            $errores['rol'] = 'Elige ADMINISTRADOR o TRANSPORTISTA.';
        }
        $sucursal = strtoupper(Validacion::texto($datos, 'sucursal'));
        if (preg_match('/^[A-Z]{3}\d{3}$/', $sucursal) !== 1) {
            $errores['sucursal'] = 'La sucursal es obligatoria para este rol.';
        }
        $this->exigirValidos($errores);

        // TODO lo que decide sale de la fila y de la sucursal BLOQUEADAS por el repositorio: si hay cambio de puesto se calcula aquí, con
        // el rol y la sucursal que la cuenta tiene en ese momento (no con una lectura anterior que otro gerente pudo dejar obsoleta).
        $resultado = $this->usuarios->cambiarPuesto($nombreUsuario, $rol, $sucursal, static function (array $ctx) use ($rol, $sucursal): void {
            if (!in_array($ctx['rolActual'], self::ROLES_PERSONAL, true)) {
                throw new HttpException(409, 'cuenta_no_editable', 'Solo se edita el puesto del personal (administradores y transportistas).');
            }
            if ($rol === $ctx['rolActual'] && $sucursal === $ctx['sucursalActual']) {
                return; // sin cambios: guardar no es un error
            }
            if (!$ctx['sucursalActiva']) {
                throw self::datosInvalidos(['sucursal' => 'Elige una sucursal activa.']);
            }
            if ($ctx['viajesAbiertos'] > 0) {
                throw new HttpException(409, 'transportista_con_viajes', sprintf('No se puede cambiar el puesto: tiene %d viaje(s) programado(s) o en ruta.', $ctx['viajesAbiertos']));
            }
        });
        if ($resultado === null) {
            throw self::cuentaNoEncontrada();
        }

        return $this->presentar($this->cuentaDe($nombreUsuario), strtolower((string) ($sesion['nombreUsuario'] ?? '')));
    }
    /**
     * «Desactivar seleccionada» (y volver a activar). No puede desactivarse a sí mismo ni al último gerente activo.
     *
     * @param array<string, mixed> $datos activo: bool
     * @return array<string, mixed>
     */
    public function cambiarEstado(array $sesion, string $nombreUsuario, array $datos): array
    {
        $activo = Validacion::booleano($datos['activo'] ?? null) ?? throw self::datosInvalidos(['activo' => 'Indica true o false.']);
        $actual = $this->cuentaDe($nombreUsuario);
        $yo = strtolower((string) ($sesion['nombreUsuario'] ?? ''));
        $esUsted = strtolower($actual['nombreUsuario']) === $yo;

        $resultado = $this->usuarios->cambiarEstado($actual['nombreUsuario'], $activo, static function (array $ctx) use ($activo, $esUsted): void {
            if ($activo) {
                return; // volver a activar siempre se puede
            }
            if ($esUsted) {
                throw new HttpException(409, 'no_puede_desactivarse', 'No puedes desactivar tu propia cuenta.');
            }
            if ($ctx['rol'] === Roles::GERENTE && $ctx['activo'] && $ctx['gerentesActivos'] <= 1) {
                throw new HttpException(409, 'ultimo_gerente', 'No se puede desactivar al último gerente activo.');
            }
            if ($ctx['viajesAbiertos'] > 0) {
                throw new HttpException(409, 'transportista_con_viajes', sprintf('No se puede desactivar: tiene %d viaje(s) programado(s) o en ruta.', $ctx['viajesAbiertos']));
            }
        });
        if ($resultado === null) {
            throw self::cuentaNoEncontrada();
        }

        return $this->presentar($this->cuentaDe($nombreUsuario), $yo);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     * @return array{0: ?string, 1: ?string}
     */
    private function documento(array $datos, array &$errores): array
    {
        $tipo = strtoupper(Validacion::texto($datos, 'tipoDocumento'));
        $numero = strtoupper((string) preg_replace('/[\s-]/', '', Validacion::texto($datos, 'numeroDocumento')));
        if (!in_array($tipo, self::TIPOS_DOCUMENTO, true)) {
            $errores['tipoDocumento'] = 'Elige DNI, CE o PAS.';
        } elseif ($tipo === 'DNI' && preg_match('/^\d{8}$/', $numero) !== 1) {
            $errores['numeroDocumento'] = 'El DNI tiene 8 dígitos.';
        } elseif ($tipo !== 'DNI' && preg_match('/^[A-Z0-9]{1,12}$/', $numero) !== 1) {
            $errores['numeroDocumento'] = 'El documento tiene hasta 12 letras o números.';
        }

        return [$tipo, $numero];
    }

    private static function errorDeContrasena(string $contrasena, string $usuario): ?string
    {
        if (strlen($contrasena) < self::LARGO_MINIMO_CONTRASENA) {
            return 'La contraseña debe tener al menos ' . self::LARGO_MINIMO_CONTRASENA . ' caracteres.';
        }
        if (strlen($contrasena) > self::BYTES_MAXIMOS_CONTRASENA) {
            return 'La contraseña es demasiado larga (máximo ' . self::BYTES_MAXIMOS_CONTRASENA . ' caracteres).';
        }
        if ($usuario !== '' && strcasecmp($contrasena, $usuario) === 0) {
            return 'La contraseña no puede ser igual al usuario.';
        }

        return null;
    }

    private function nombreValido(string $nombreUsuario): string
    {
        $nombreUsuario = trim($nombreUsuario);

        return preg_match('/^[A-Za-z0-9._-]{4,30}$/', $nombreUsuario) === 1 ? $nombreUsuario : throw self::cuentaNoEncontrada();
    }
    /** @return array<string, mixed> */
    private function cuentaDe(string $nombreUsuario): array
    {
        $nombreUsuario = trim($nombreUsuario);
        $cuenta = preg_match('/^[A-Za-z0-9._-]{4,30}$/', $nombreUsuario) === 1 ? $this->usuarios->cuenta($nombreUsuario) : null;

        return $cuenta ?? throw self::cuentaNoEncontrada();
    }

    private static function cuentaNoEncontrada(): HttpException
    {
        return new HttpException(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.');
    }

    /** @param array<string, string> $errores */
    private function exigirValidos(array $errores): void
    {
        if ($errores !== []) {
            throw self::datosInvalidos($errores);
        }
    }

    /** @param array<string, string> $errores */
    private static function datosInvalidos(array $errores): HttpException
    {
        return new HttpException(422, 'datos_invalidos', 'Revisa los campos marcados.', [], $errores);
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function presentar(array $c, string $yo): array
    {
        return [
            'usuario' => $c['nombreUsuario'], 'persona' => $c['nombreCompleto'], 'rol' => $c['rol'],
            'sucursal' => $c['sucursalCodigo'] === null ? null : ['codigo' => $c['sucursalCodigo'], 'nombre' => $c['sucursalNombre']],
            'activa' => $c['activo'], 'esUsted' => strtolower($c['nombreUsuario']) === $yo, 'editable' => in_array($c['rol'], self::ROLES_PERSONAL, true),
        ];
    }
}
