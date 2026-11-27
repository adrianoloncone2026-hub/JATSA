<?php
declare(strict_types=1);

namespace Jatsa\Modules\Entregas;

use Closure;
use DateTimeImmutable;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/** Llegadas y entregas del Administrador (diapositiva 22): viajes que vienen en camino a su agencia, envíos listos para */
final class EntregaService
{
    public const TIPOS_DOCUMENTO = ['DNI', 'RUC', 'CE', 'PAS'];
    public const MAX_LISTOS = 200;

    private readonly RepositorioEntregas $entregas;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD) para la ruta vigente; se inyecta para probar. */
    public function __construct(?RepositorioEntregas $entregas = null, ?callable $hoy = null)
    {
        $this->entregas = $entregas ?? new EntregaRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function pendientes(array $usuario): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);

        $enCamino = array_map(static function (array $v): array {
            $estimada = null;
            if ($v['horas'] !== null) {
                $estimada = (new DateTimeImmutable($v['fechaHoraSalida']))->modify('+' . $v['horas'] . ' hours')->format('Y-m-d H:i:s');
            }

            return ['codigo' => $v['codigo'], 'origen' => $v['origen'], 'destino' => $v['destino'], 'fechaHoraSalida' => $v['fechaHoraSalida'],
                'llegadaEstimada' => $estimada, 'cantidadEnvios' => $v['cantidadEnvios']];
        }, $this->entregas->viajesEnCamino($sucursal['id'], ($this->hoy)()));

        $filas = $this->entregas->listosParaEntregar($sucursal['id'], self::MAX_LISTOS + 1);
        $listos = array_map(static function (array $e): array {
            $importe = self::centimos($e['importe']);
            $pagado = self::centimos($e['pagado']);

            return [
                'codigoSeguimiento' => $e['codigoSeguimiento'], 'destinatario' => $e['destinatario'], 'tipoEntrega' => $e['tipoEntrega'],
                'bultos' => $e['bultos'], 'pesoKg' => self::kilos(self::centimos($e['pesoKg'])),
                'importe' => Tarifas::formatoSoles($importe), 'pagado' => Tarifas::formatoSoles($pagado), 'saldo' => Tarifas::formatoSoles(max(0, $importe - $pagado)),
            ];
        }, array_slice($filas, 0, self::MAX_LISTOS));

        return ['sucursal' => ['codigo' => $sucursal['codigo'], 'nombre' => $sucursal['nombre']], 'enCamino' => $enCamino, 'listos' => $listos, 'hayMas' => count($filas) > self::MAX_LISTOS];
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function detalle(array $usuario, string $codigoSeguimiento): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = self::normalizarCodigo($codigoSeguimiento);
        $envio = $codigo !== null ? $this->entregas->buscarEnvio($codigo) : null;

        if ($envio === null || $envio['idSucursalDestino'] !== $sucursal['id']) {
            throw self::noEncontrado();
        }

        return self::presentar($envio);
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos tipoDocumento, numeroDocumento
     * @return array<string, mixed> El envío ya ENTREGADO.
     */
    public function entregar(array $usuario, string $codigoSeguimiento, array $datos): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = self::normalizarCodigo($codigoSeguimiento) ?? throw self::noEncontrado();

        $tipo = strtoupper(self::texto($datos, 'tipoDocumento'));
        $numero = VentanillaService::normalizarNumero($tipo, self::texto($datos, 'numeroDocumento'));
        $errores = [];
        if (!in_array($tipo, self::TIPOS_DOCUMENTO, true)) {
            $errores['tipoDocumento'] = 'Elige DNI, RUC, CE o PAS.';
        } else {
            $error = VentanillaService::errorDeDocumento($tipo, $numero);
            if ($error !== null) {
                $errores['numeroDocumento'] = $error;
            }
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa el documento presentado.', [], $errores);
        }

        $decidir = static function (array $contexto) use ($sucursal, $tipo, $numero): void {
            $envio = self::aptoParaCerrar($contexto['envio'], $sucursal['id']);
            $registrado = $envio['destinatario'];
            if ($registrado['tipoDocumento'] !== $tipo || $registrado['numeroDocumento'] !== $numero) {
                throw new HttpException(422, 'datos_invalidos', 'El documento presentado no coincide con el del destinatario registrado.', [], [
                    'numeroDocumento' => 'El documento no coincide con el del destinatario registrado.',
                ]);
            }
            $saldo = $envio['importe'] - $envio['pagado'];
            if ($saldo > 0) {
                throw new HttpException(409, 'saldo_pendiente', sprintf('El envío tiene un saldo pendiente de S/ %s: cóbralo en Caja antes de entregarlo.', Tarifas::formatoSoles($saldo)));
            }
        };

        return $this->cerrar($this->entregas->entregar($codigo, $decidir), $usuario, $codigo);
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed> El envío ya DEVUELTO.
     */
    public function devolver(array $usuario, string $codigoSeguimiento): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = self::normalizarCodigo($codigoSeguimiento) ?? throw self::noEncontrado();

        $decidir = static function (array $contexto) use ($sucursal): void {
            self::aptoParaCerrar($contexto['envio'], $sucursal['id']);
        };

        return $this->cerrar($this->entregas->devolver($codigo, $decidir), $usuario, $codigo);
    }


    /**
     * @param array<string, mixed> $envio
     * @return array<string, mixed>
     */
    private static function aptoParaCerrar(array $envio, int $idSucursal): array
    {
        if ($envio['idSucursalDestino'] !== $idSucursal) {
            throw self::noEncontrado();
        }
        switch ($envio['estado']) {
            case 'EN_DESTINO':
                return $envio;
            case 'ENTREGADO':
                throw new HttpException(409, 'envio_entregado', 'El envío ya fue entregado.');
            case 'DEVUELTO':
                throw new HttpException(409, 'envio_devuelto', 'El envío ya fue marcado como devuelto.');
            case 'ANULADO':
                throw new HttpException(409, 'envio_anulado', 'El envío está anulado.');
            default:
                throw new HttpException(409, 'envio_no_esta_en_destino', 'El envío todavía no llegó a la agencia de destino.');
        }
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    private function cerrar(?bool $hecho, array $usuario, string $codigo): array
    {
        if ($hecho === null) {
            throw self::noEncontrado();
        }
        if ($hecho === false) {
            throw new HttpException(409, 'envio_no_esta_en_destino', 'El envío cambió de estado mientras se atendía. Actualiza la pantalla.');
        }

        return $this->detalle($usuario, $codigo);
    }

    private static function noEncontrado(): HttpException
    {
        return new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío en esta agencia.');
    }

    private static function normalizarCodigo(string $codigo): ?string
    {
        $codigo = strtoupper(trim($codigo));

        return preg_match('/^EN[A-Z0-9]{10}$/', $codigo) === 1 ? $codigo : null;
    }

    /**
     * @param array<string, mixed> $envio
     * @return array<string, mixed>
     */
    private static function presentar(array $envio): array
    {
        $importe = self::centimos($envio['importe']);
        $pagado = self::centimos($envio['pagado']);
        $peso = 0;
        $bultos = [];
        foreach ($envio['bultos'] as $b) {
            $centesimas = self::centimos($b['pesoKg']);
            $peso += $centesimas;
            $bultos[] = ['numero' => $b['numero'], 'descripcion' => $b['descripcion'], 'pesoKg' => self::kilos($centesimas)];
        }

        return [
            'codigoSeguimiento' => $envio['codigoSeguimiento'],
            'estado' => $envio['estado'],
            'tipoEntrega' => $envio['tipoEntrega'],
            'direccionEntrega' => $envio['direccionEntrega'],
            'fechaHoraEntrega' => $envio['fechaHoraEntrega'],
            'origen' => $envio['origen'],
            'destino' => $envio['destino'],
            'destinatario' => $envio['destinatario'],
            'bultos' => $bultos,
            'cantidadBultos' => count($bultos),
            'pesoTotalKg' => self::kilos($peso),
            'importe' => Tarifas::formatoSoles($importe),
            'pagado' => Tarifas::formatoSoles($pagado),
            'saldo' => Tarifas::formatoSoles(max(0, $importe - $pagado)),
        ];
    }

    private static function centimos(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function kilos(int $centesimas): string
    {
        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }
}
