<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reservas;

use Jatsa\Modules\Publico\AgenciaRepository;
use Jatsa\Modules\Publico\CotizadorService;
use Jatsa\Modules\Publico\RepositorioAgencias;
use Jatsa\Shared\HttpException;

/** Reglas de las reservas del cliente (RF06, RF08 y proceso P1): crear, consultar, listar y cancelar. */
final class ReservaService
{
    public const ESTADOS = ['PENDIENTE', 'FORMALIZADA', 'CANCELADA', 'VENCIDA'];

    private const TIPOS_DOCUMENTO = ['DNI', 'RUC'];

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
     * @param array<string, mixed> $datos tipoDocumento, numeroDocumento, nombreDestinatario?, telefonoDestinatario?,
     * sucursalOrigen, sucursalDestino, contenido
     * @return array<string, mixed> La reserva creada.
     */
    public function crear(int $idUsuario, array $datos): array
    {
        $idRemitente = $this->personaDe($idUsuario);
        $errores = [];

        $tipo = strtoupper(self::texto($datos, 'tipoDocumento'));
        $numero = self::sinSeparadores(self::texto($datos, 'numeroDocumento'));
        $errorDocumento = self::errorDeDocumento($tipo, $numero);
        $destinatario = null;
        if ($errorDocumento !== null) {
            $errores['numeroDocumento'] = $errorDocumento;
        } else {
            $destinatario = $this->reservas->buscarPersonaPorDocumento($tipo, $numero);
            if ($destinatario !== null && $destinatario['idPersona'] === $idRemitente) {
                $errores['numeroDocumento'] = 'El destinatario no puede ser el mismo remitente.';
            }
        }

        $nuevo = null;
        if ($errorDocumento === null && $destinatario === null) {
            $nombre = self::normalizarEspacios(self::texto($datos, 'nombreDestinatario'));
            $telefono = self::sinSeparadores(self::texto($datos, 'telefonoDestinatario'));
            if ($nombre === '') {
                $errores['nombreDestinatario'] = 'Ingresa ' . ($tipo === 'RUC' ? 'la razón social' : 'los nombres y apellidos') . ' del destinatario.';
            } elseif (preg_match('/^.{1,150}$/su', $nombre) !== 1) {
                $errores['nombreDestinatario'] = 'Puede tener hasta 150 caracteres.';
            }
            if (preg_match('/^\d{9}$/', $telefono) !== 1) {
                $errores['telefonoDestinatario'] = 'El teléfono tiene 9 dígitos.';
            }
            $nuevo = ['tipoDocumento' => $tipo, 'numeroDocumento' => $numero, 'nombreCompleto' => $nombre, 'telefono' => $telefono];
        }

        $origen = strtoupper(trim(self::texto($datos, 'sucursalOrigen')));
        $destino = strtoupper(trim(self::texto($datos, 'sucursalDestino')));
        if (!self::esCodigoDeSucursal($origen)) {
            $errores['sucursalOrigen'] = 'Elige la sucursal de origen.';
        } elseif ($this->agencias->buscarActivaPorCodigo($origen) === null) {
            $errores['sucursalOrigen'] = 'La sucursal de origen no está disponible.';
        }
        if (!self::esCodigoDeSucursal($destino)) {
            $errores['sucursalDestino'] = 'Elige la sucursal de destino.';
        } elseif ($this->agencias->buscarActivaPorCodigo($destino) === null) {
            $errores['sucursalDestino'] = 'La sucursal de destino no está disponible.';
        } elseif ($origen === $destino) {
            $errores['sucursalDestino'] = 'El origen y el destino deben ser distintos.';
        } elseif (!isset($errores['sucursalOrigen']) && !$this->rutaTieneTarifaVigente($origen, $destino)) {
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

        $codigo = $this->reservas->crear($idRemitente, $destinatario['idPersona'] ?? null, $nuevo, $origen, $destino, $contenido);

        return $this->presentar($this->reservaPropia($idRemitente, $codigo));
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $idUsuario, string $estado = ''): array
    {
        $estado = strtoupper(trim($estado));
        if ($estado !== '' && !in_array($estado, self::ESTADOS, true)) {
            throw new HttpException(422, 'datos_invalidos', 'Estado no válido.', [], ['estado' => 'Elige uno de: ' . implode(', ', self::ESTADOS) . '.']);
        }

        $reservas = $this->reservas->listarDeRemitente($this->personaDe($idUsuario), $estado === '' ? null : $estado);

        return array_map(fn (array $r): array => $this->presentar($r), $reservas);
    }

    /** @return array<string, mixed> */
    public function ver(int $idUsuario, string $codigo): array
    {
        return $this->presentar($this->reservaPropia($this->personaDe($idUsuario), $codigo));
    }

    /** @return array<string, mixed> La reserva ya cancelada. */
    public function cancelar(int $idUsuario, string $codigo): array
    {
        $idPersona = $this->personaDe($idUsuario);
        $reserva = $this->reservaPropia($idPersona, $codigo);

        if ($reserva['estado'] === 'PENDIENTE' && $reserva['plazoCumplido']) {
            throw self::reservaVencida();
        }
        if ($reserva['estado'] !== 'PENDIENTE') {
            throw new HttpException(409, 'reserva_no_cancelable', 'Solo se pueden cancelar las reservas pendientes.');
        }

        if (!$this->reservas->cancelar($idPersona, $reserva['codigo'])) {
            $actual = $this->reservaPropia($idPersona, $codigo);
            throw $actual['estado'] === 'VENCIDA' || ($actual['estado'] === 'PENDIENTE' && $actual['plazoCumplido'])
                ? self::reservaVencida()
                : new HttpException(409, 'reserva_no_cancelable', 'Solo se pueden cancelar las reservas pendientes.');
        }

        return $this->presentar($this->reservaPropia($idPersona, $codigo));
    }

    private static function reservaVencida(): HttpException
    {
        return new HttpException(
            409,
            'reserva_vencida',
            sprintf('Pasaron las %d horas de vigencia de la reserva: ya no puede cancelarse.', RepositorioReservas::HORAS_DE_VIGENCIA),
        );
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
     * Busca a un posible destinatario por documento para autocompletar el formulario. Devuelve solo el nombre
     * @return array{existe: bool, nombreCompleto?: string, esUsted?: bool}
     */
    public function consultarDestinatario(int $idUsuario, string $tipoDocumento, string $numeroDocumento): array
    {
        $tipo = strtoupper(trim($tipoDocumento));
        $numero = self::sinSeparadores($numeroDocumento);
        $error = self::errorDeDocumento($tipo, $numero);
        if ($error !== null) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa el documento.', [], ['numeroDocumento' => $error]);
        }

        $persona = $this->reservas->buscarPersonaPorDocumento($tipo, $numero);
        if ($persona === null) {
            return ['existe' => false];
        }

        return ['existe' => true, 'nombreCompleto' => $persona['nombreCompleto'], 'esUsted' => $persona['idPersona'] === $this->personaDe($idUsuario)];
    }


    private function personaDe(int $idUsuario): int
    {
        $id = $this->reservas->idPersonaDeUsuario($idUsuario);

        if ($id === null) {
            throw new HttpException(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function reservaPropia(int $idPersona, string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        $reserva = preg_match('/^R\d{9}$/', $codigo) === 1 ? $this->reservas->buscarDeRemitente($idPersona, $codigo) : null;

        if ($reserva === null) {
            throw new HttpException(404, 'reserva_no_encontrada', 'No se encontró la reserva.');
        }

        return $reserva;
    }

    /**
     * @param array<string, mixed> $reserva
     * @return array<string, mixed>
     */
    private function presentar(array $reserva): array
    {
        return [
            'codigo' => $reserva['codigo'],
            'fecha' => substr((string) $reserva['fechaHora'], 0, 10),
            'fechaHora' => $reserva['fechaHora'],
            'canal' => $reserva['canal'],
            'contenido' => $reserva['contenido'],
            'estado' => $reserva['estado'],
            'destinatario' => $reserva['destinatario'],
            'origen' => $reserva['origen'],
            'destino' => $reserva['destino'],
            'codigoSeguimiento' => $reserva['codigoSeguimiento'],
            'venceEl' => $reserva['estado'] === 'PENDIENTE' ? Vigencia::fechaLimite((string) $reserva['fechaHora']) : null,
            'plazoCumplido' => $reserva['estado'] === 'PENDIENTE' && $reserva['plazoCumplido'],
        ];
    }

    private static function errorDeDocumento(string $tipo, string $numero): ?string
    {
        if (!in_array($tipo, self::TIPOS_DOCUMENTO, true)) {
            return 'Elige DNI o RUC.';
        }
        if ($tipo === 'DNI' && preg_match('/^\d{8}$/', $numero) !== 1) {
            return 'El DNI tiene 8 dígitos.';
        }
        if ($tipo === 'RUC' && preg_match('/^\d{11}$/', $numero) !== 1) {
            return 'El RUC tiene 11 dígitos.';
        }

        return null;
    }

    private static function esCodigoDeSucursal(string $codigo): bool
    {
        return preg_match('/^[A-Z]{3}[0-9]{3}$/', $codigo) === 1;
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }

    private static function sinSeparadores(string $valor): string
    {
        return preg_replace('/[\s-]+/', '', $valor) ?? $valor;
    }

    private static function normalizarEspacios(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
    }
}
