<?php
declare(strict_types=1);

namespace Jatsa\Modules\Cuenta;

use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/**
 * Reglas de la cuenta de cliente/empresario (RF03, RF04, RF05): registro, consulta, edición de contacto
 * y cambio de contraseña. No toca HTTP ni sesiones.
 */
final class CuentaService
{
    public const MENSAJE_CUENTA_EXISTENTE = 'Ya existe una cuenta con ese documento. Si no recuerdas tu usuario o tu contraseña, acércate a tu agencia.';

    /** Tipo de documento con el que se registra → rol de la cuenta (diapositiva 10 del prototipo). */
    private const ROL_POR_DOCUMENTO = ['DNI' => Roles::CLIENTE, 'RUC' => Roles::EMPRESARIO];

    private const LARGO_MINIMO_CONTRASENA = 8;
    private const BYTES_MAXIMOS_CONTRASENA = 72; // bcrypt ignora lo que pase de 72 bytes

    private readonly RepositorioCuentas $cuentas;

    public function __construct(?RepositorioCuentas $cuentas = null)
    {
        $this->cuentas = $cuentas ?? new CuentaRepository();
    }

    /**
     * Crea una cuenta. Si ya hay una persona con ese documento y aún no tiene cuenta, se reutiliza con
     * sus datos guardados (RF04: «usamos tus datos guardados»); no se sobrescribe nada de ella.
     *
     * @param array<string, mixed> $datos
     * @return array{usuario: string, rol: string}
     */
    public function registrar(array $datos): array
    {
        $errores = [];
        $tipo = strtoupper(self::texto($datos, 'tipoDocumento'));
        $numero = self::sinSeparadores(self::texto($datos, 'numeroDocumento'));
        $nombreCompleto = self::normalizarEspacios(self::texto($datos, 'nombreCompleto'));
        $usuario = trim(self::texto($datos, 'usuario'));
        $contrasena = self::texto($datos, 'contrasena', recortar: false);

        if (!isset(self::ROL_POR_DOCUMENTO[$tipo])) {
            $errores['tipoDocumento'] = 'Elige DNI o RUC.';
        } elseif ($tipo === 'DNI' && preg_match('/^\d{8}$/', $numero) !== 1) {
            $errores['numeroDocumento'] = 'El DNI tiene 8 dígitos.';
        } elseif ($tipo === 'RUC' && preg_match('/^\d{11}$/', $numero) !== 1) {
            $errores['numeroDocumento'] = 'El RUC tiene 11 dígitos.';
        }

        $etiquetaNombre = $tipo === 'RUC' ? 'la razón social' : 'tus nombres y apellidos';
        if ($nombreCompleto === '') {
            $errores['nombreCompleto'] = "Ingresa $etiquetaNombre.";
        } elseif (preg_match('/^.{1,150}$/su', $nombreCompleto) !== 1) {
            $errores['nombreCompleto'] = 'Puede tener hasta 150 caracteres.';
        }

        $contacto = $this->validarContacto($datos, $errores);

        if (preg_match('/^[A-Za-z0-9._-]{4,30}$/', $usuario) !== 1) {
            $errores['usuario'] = 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.';
        }

        $errorContrasena = self::errorDeContrasena($contrasena, $usuario);
        if ($errorContrasena !== null) {
            $errores['contrasena'] = $errorContrasena;
        }

        if ($errores !== []) {
            throw self::datosInvalidos($errores);
        }

        $existente = $this->cuentas->buscarPersonaPorDocumento($tipo, $numero);
        if ($existente !== null && $existente['tieneUsuario']) {
            throw new HttpException(409, 'cuenta_existente', self::MENSAJE_CUENTA_EXISTENTE);
        }

        if ($this->cuentas->usuarioExiste($usuario)) {
            throw new HttpException(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', [], ['usuario' => 'Ese usuario ya está en uso. Elige otro.']);
        }

        $rol = self::ROL_POR_DOCUMENTO[$tipo];
        $this->cuentas->registrar(
            $existente['idPersona'] ?? null,
            [
                'tipoDocumento' => $tipo,
                'numeroDocumento' => $numero,
                'nombreCompleto' => $nombreCompleto,
                'telefono' => $contacto['telefono'],
                'telefonoAuxiliar' => $contacto['telefonoAuxiliar'],
                'correo' => $contacto['correo'],
            ],
            $usuario,
            password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 12]),
            $rol,
        );

        return ['usuario' => $usuario, 'rol' => $rol];
    }

    /**
     * Datos de la cuenta y de su persona (el documento es solo de lectura).
     *
     * @return array<string, mixed>
     */
    public function obtener(int $idUsuario): array
    {
        return self::presentar($this->cuenta($idUsuario));
    }

    /**
     * Cambia teléfono, teléfono auxiliar y correo de la persona de la cuenta.
     *
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public function actualizarContacto(int $idUsuario, array $datos): array
    {
        $cuenta = $this->cuenta($idUsuario);

        $errores = [];
        $contacto = $this->validarContacto($datos, $errores);
        if ($errores !== []) {
            throw self::datosInvalidos($errores);
        }

        $this->cuentas->actualizarContacto($cuenta['idPersona'], $contacto['telefono'], $contacto['telefonoAuxiliar'], $contacto['correo']);

        return self::presentar($this->cuenta($idUsuario));
    }

    /**
     * Cambia la contraseña tras comprobar la actual. Devuelve el hash nuevo para renovar la huella de la sesión.
     */
    public function cambiarContrasena(int $idUsuario, string $contrasenaActual, string $contrasenaNueva): string
    {
        $cuenta = $this->cuenta($idUsuario);
        $hashActual = $this->cuentas->obtenerHash($idUsuario);

        if ($hashActual === null || $contrasenaActual === '' || !password_verify($contrasenaActual, $hashActual)) {
            throw new HttpException(422, 'contrasena_incorrecta', 'La contraseña actual no es correcta.', [], ['contrasenaActual' => 'La contraseña actual no es correcta.']);
        }

        $error = self::errorDeContrasena($contrasenaNueva, $cuenta['nombreUsuario']);
        if ($error === null && hash_equals($contrasenaActual, $contrasenaNueva)) {
            $error = 'La contraseña nueva debe ser distinta de la actual.';
        }
        if ($error !== null) {
            throw self::datosInvalidos(['contrasenaNueva' => $error]);
        }

        $hashNuevo = password_hash($contrasenaNueva, PASSWORD_BCRYPT, ['cost' => 12]);
        // Compare-and-swap: solo cambia si la contraseña sigue siendo la que se acaba de verificar. Si otra petición ganó, no se
        // escribe nada (y el controlador no renueva la huella): el cliente recibe un conflicto y debe repetir con la contraseña vigente.
        if (!$this->cuentas->actualizarHash($idUsuario, $hashActual, $hashNuevo)) {
            throw new HttpException(409, 'contrasena_modificada', 'Tu contraseña cambió mientras se procesaba esta solicitud. Vuelve a intentarlo con tu contraseña actual.');
        }

        return $hashNuevo;
    }

    // ── Validaciones compartidas ────────────────────────────────────────────

    /**
     * Teléfono (9 dígitos), teléfono auxiliar opcional (9 dígitos y distinto) y correo opcional.
     *
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores Se completa por referencia.
     * @return array{telefono: string, telefonoAuxiliar: ?string, correo: ?string}
     */
    private function validarContacto(array $datos, array &$errores): array
    {
        $telefono = self::sinSeparadores(self::texto($datos, 'telefono'));
        $auxiliar = self::sinSeparadores(self::texto($datos, 'telefonoAuxiliar'));
        $correo = trim(self::texto($datos, 'correo'));

        if (preg_match('/^\d{9}$/', $telefono) !== 1) {
            $errores['telefono'] = 'El teléfono tiene 9 dígitos.';
        }

        if ($auxiliar === '') {
            $auxiliar = null;
        } elseif (preg_match('/^\d{9}$/', $auxiliar) !== 1) {
            $errores['telefonoAuxiliar'] = 'El teléfono auxiliar tiene 9 dígitos.';
        } elseif ($auxiliar === $telefono) {
            $errores['telefonoAuxiliar'] = 'El teléfono auxiliar debe ser distinto del teléfono.';
        }

        if ($correo === '') {
            $correo = null;
        } elseif (strlen($correo) > 120 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errores['correo'] = 'Ingresa un correo válido (hasta 120 caracteres).';
        }

        return ['telefono' => $telefono, 'telefonoAuxiliar' => $auxiliar, 'correo' => $correo];
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

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function cuenta(int $idUsuario): array
    {
        $cuenta = $this->cuentas->buscarCuenta($idUsuario);

        if ($cuenta === null) {
            throw new HttpException(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.');
        }

        return $cuenta;
    }

    /**
     * @param array<string, mixed> $cuenta
     * @return array<string, mixed>
     */
    private static function presentar(array $cuenta): array
    {
        return [
            'persona' => [
                'tipoDocumento' => $cuenta['tipoDocumento'],
                'numeroDocumento' => $cuenta['numeroDocumento'],
                'nombreCompleto' => $cuenta['nombreCompleto'],
                'telefono' => $cuenta['telefono'],
                'telefonoAuxiliar' => $cuenta['telefonoAuxiliar'],
                'correo' => $cuenta['correo'],
            ],
            'usuario' => [
                'nombreUsuario' => $cuenta['nombreUsuario'],
                'rol' => $cuenta['rol'],
                'activo' => $cuenta['activo'],
                'fechaCreacion' => $cuenta['fechaCreacion'],
            ],
        ];
    }

    /** @param array<string, string> $errores */
    private static function datosInvalidos(array $errores): HttpException
    {
        return new HttpException(422, 'datos_invalidos', 'Revisa los datos del formulario.', [], $errores);
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave, bool $recortar = true): string
    {
        $valor = $datos[$clave] ?? '';
        if (!is_string($valor)) {
            return '';
        }

        return $recortar ? trim($valor) : $valor;
    }

    /** Quita espacios y guiones: «987 654 323» → «987654323». */
    private static function sinSeparadores(string $valor): string
    {
        return preg_replace('/[\s-]+/', '', $valor) ?? $valor;
    }

    /** «María   Elena» → «María Elena». */
    private static function normalizarEspacios(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
    }
}
