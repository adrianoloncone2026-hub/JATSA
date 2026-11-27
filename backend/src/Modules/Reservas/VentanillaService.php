<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use Jatsa\Modules\Publico\AgenciaRepository;
use Jatsa\Modules\Publico\CotizadorService;
use Jatsa\Modules\Publico\RepositorioAgencias;
use Jatsa\Shared\HttpException;

/** Ventanilla del Administrador (diapositiva 18): reservas pendientes de su agencia, búsqueda por código y */
final class VentanillaService
{
    private const TIPOS_DOCUMENTO = ['DNI', 'RUC', 'CE', 'PAS'];

    private readonly RepositorioReservas $reservas;
    private readonly RepositorioAgencias $agencias;
    private readonly CotizadorService $cotizador;

    public function __construct(?RepositorioReservas $reservas = null, ?RepositorioAgencias $agencias = null, ?CotizadorService $cotizador = null)
    {
        $this->reservas = $reservas ?? new ReservaRepository();
        $this->agencias = $agencias ?? new AgenciaRepository();
        $this->cotizador = $cotizador ?? new CotizadorService($this->agencias);
    }

    /**
     * @param array<string, mixed> $usuario Usuario de la sesión.
     * @return list<array<string, mixed>>
     */
    public function pendientes(array $usuario): array
    {
        $sucursal = self::sucursalDe($usuario);

        return array_map(fn (array $r): array => self::presentar($r), $this->reservas->listarPendientesDeSucursal($sucursal['id']));
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function ver(array $usuario, string $codigo): array
    {
        $sucursal = self::sucursalDe($usuario);
        $codigo = strtoupper(trim($codigo));
        $reserva = preg_match('/^R\d{9}$/', $codigo) === 1 ? $this->reservas->buscarDeSucursal($sucursal['id'], $codigo) : null;

        if ($reserva === null) {
            throw new HttpException(404, 'reserva_no_encontrada', 'No se encontró la reserva en esta agencia.');
        }

        return self::presentar($reserva);
    }

    /**
     * Busca a una persona por documento para autocompletar. Devuelve solo el nombre (nunca teléfono ni correo).
     * @return array{existe: bool, nombreCompleto?: string}
     */
    public function consultarPersona(string $tipoDocumento, string $numeroDocumento): array
    {
        $tipo = strtoupper(trim($tipoDocumento));
        $numero = self::normalizarNumero($tipo, $numeroDocumento);
        $error = self::errorDeDocumento($tipo, $numero);
        if ($error !== null) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa el documento.', [], ['numeroDocumento' => $error]);
        }

        $persona = $this->reservas->buscarPersonaPorDocumento($tipo, $numero);

        return $persona === null ? ['existe' => false] : ['existe' => true, 'nombreCompleto' => $persona['nombreCompleto']];
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos remitenteTipoDocumento, remitenteNumeroDocumento, remitenteNombre?, remitenteTelefono?,
     * destinatarioTipoDocumento, destinatarioNumeroDocumento, destinatarioNombre?,
     * destinatarioTelefono?, sucursalDestino, contenido
     * @return array<string, mixed>
     */
    public function crear(array $usuario, array $datos): array
    {
        $sucursal = self::sucursalDe($usuario);
        $errores = [];

        [$idRemitente, $remitenteNuevo] = $this->persona($datos, 'remitente', $errores);
        [$idDestinatario, $destinatarioNuevo] = $this->persona($datos, 'destinatario', $errores);

        $documentoRemitente = self::normalizarNumero(strtoupper(self::texto($datos, 'remitenteTipoDocumento')), self::texto($datos, 'remitenteNumeroDocumento'));
        $documentoDestinatario = self::normalizarNumero(strtoupper(self::texto($datos, 'destinatarioTipoDocumento')), self::texto($datos, 'destinatarioNumeroDocumento'));
        if (!isset($errores['destinatarioNumeroDocumento']) && !isset($errores['remitenteNumeroDocumento'])
            && strtoupper(self::texto($datos, 'remitenteTipoDocumento')) === strtoupper(self::texto($datos, 'destinatarioTipoDocumento'))
            && $documentoRemitente === $documentoDestinatario) {
            $errores['destinatarioNumeroDocumento'] = 'El destinatario no puede ser el mismo remitente.';
        }

        $destino = strtoupper(self::texto($datos, 'sucursalDestino'));
        if (preg_match('/^[A-Z]{3}[0-9]{3}$/', $destino) !== 1) {
            $errores['sucursalDestino'] = 'Elige la sucursal de destino.';
        } elseif ($destino === $sucursal['codigo']) {
            $errores['sucursalDestino'] = 'El origen y el destino deben ser distintos.';
        } elseif ($this->agencias->buscarActivaPorCodigo($destino) === null) {
            $errores['sucursalDestino'] = 'La sucursal de destino no está disponible.';
        } elseif (!$this->rutaTieneTarifaVigente($sucursal['codigo'], $destino)) {
            $errores['sucursalDestino'] = 'No hay tarifas vigentes entre estas sucursales. Elige otra ruta.';
        }

        $contenido = self::normalizarEspacios(self::texto($datos, 'contenido'));
        if ($contenido === '') {
            $errores['contenido'] = 'Declara el contenido del paquete.';
        } elseif (preg_match('/^.{1,200}$/su', $contenido) !== 1) {
            $errores['contenido'] = 'Puede tener hasta 200 caracteres.';
        }

        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los datos de la reserva.', [], $errores);
        }

        $codigo = $this->reservas->crearEnVentanilla($idRemitente, $remitenteNuevo, $idDestinatario, $destinatarioNuevo, $sucursal['codigo'], $destino, $contenido);

        return $this->ver($usuario, $codigo);
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, string> $errores Se completa por referencia.
     * @return array{0: ?int, 1: ?array<string, string>} [id de la persona existente, datos de la persona nueva]
     */
    private function persona(array $datos, string $rol, array &$errores): array
    {
        $tipo = strtoupper(self::texto($datos, $rol . 'TipoDocumento'));
        $numero = self::normalizarNumero($tipo, self::texto($datos, $rol . 'NumeroDocumento'));
        $campoNumero = $rol . 'NumeroDocumento';

        $error = self::errorDeDocumento($tipo, $numero);
        if ($error !== null) {
            $errores[$campoNumero] = $error;

            return [null, null];
        }

        $existente = $this->reservas->buscarPersonaPorDocumento($tipo, $numero);
        if ($existente !== null) {
            return [$existente['idPersona'], null];
        }

        $nombre = self::normalizarEspacios(self::texto($datos, $rol . 'Nombre'));
        $telefono = preg_replace('/[\s-]+/', '', self::texto($datos, $rol . 'Telefono')) ?? '';
        if ($nombre === '') {
            $errores[$rol . 'Nombre'] = 'Ingresa ' . ($tipo === 'RUC' ? 'la razón social' : 'los nombres y apellidos') . '.';
        } elseif (preg_match('/^.{1,150}$/su', $nombre) !== 1) {
            $errores[$rol . 'Nombre'] = 'Puede tener hasta 150 caracteres.';
        }
        if (preg_match('/^\d{9}$/', $telefono) !== 1) {
            $errores[$rol . 'Telefono'] = 'El teléfono tiene 9 dígitos.';
        }

        return [null, ['tipoDocumento' => $tipo, 'numeroDocumento' => $numero, 'nombreCompleto' => $nombre, 'telefono' => $telefono]];
    }

    private function rutaTieneTarifaVigente(string $origen, string $destino): bool
    {
        foreach ($this->cotizador->tarifas($origen, $destino)['tarifas'] as $tarifa) {
            if ($tarifa['disponible'] === true) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param array<string, mixed> $usuario
     * @return array{id: int, codigo: string, nombre: string}
     */
    public static function sucursalDe(array $usuario): array
    {
        $sucursal = $usuario['sucursal'] ?? null;
        if (!is_array($sucursal) || !isset($sucursal['id'], $sucursal['codigo'])) {
            throw new HttpException(403, 'sin_sucursal', 'Tu cuenta no tiene una sucursal asignada.');
        }

        return ['id' => (int) $sucursal['id'], 'codigo' => (string) $sucursal['codigo'], 'nombre' => (string) ($sucursal['nombre'] ?? '')];
    }

    /**
     * @param array<string, mixed> $reserva
     * @return array<string, mixed>
     */
    private static function presentar(array $reserva): array
    {
        return [
            'codigo' => $reserva['codigo'],
            'fecha' => substr((string) $reserva['fechaHora'], 0, 10),
            'fechaHora' => $reserva['fechaHora'],
            'canal' => $reserva['canal'],
            'contenido' => $reserva['contenido'],
            'estado' => $reserva['estado'],
            'remitente' => $reserva['remitente'],
            'destinatario' => $reserva['destinatario'],
            'origen' => $reserva['origen'],
            'destino' => $reserva['destino'],
            'codigoSeguimiento' => $reserva['codigoSeguimiento'],
            'venceEl' => $reserva['estado'] === 'PENDIENTE' ? Vigencia::fechaLimite((string) $reserva['fechaHora']) : null,
            'plazoCumplido' => $reserva['estado'] === 'PENDIENTE' && $reserva['plazoCumplido'],
        ];
    }

    public static function errorDeDocumento(string $tipo, string $numero): ?string
    {
        if (!in_array($tipo, self::TIPOS_DOCUMENTO, true)) {
            return 'Elige DNI, RUC, CE o PAS.';
        }
        if ($tipo === 'DNI' && preg_match('/^\d{8}$/', $numero) !== 1) {
            return 'El DNI tiene 8 dígitos.';
        }
        if ($tipo === 'RUC' && preg_match('/^\d{11}$/', $numero) !== 1) {
            return 'El RUC tiene 11 dígitos.';
        }
        if (($tipo === 'CE' || $tipo === 'PAS') && preg_match('/^[A-Z0-9]{6,12}$/', $numero) !== 1) {
            return 'El documento tiene de 6 a 12 letras o dígitos.';
        }

        return null;
    }

    public static function normalizarNumero(string $tipo, string $numero): string
    {
        $limpio = preg_replace('/[\s-]+/', '', $numero) ?? $numero;

        return $tipo === 'CE' || $tipo === 'PAS' ? strtoupper($limpio) : $limpio;
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }

    private static function normalizarEspacios(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
    }
}
