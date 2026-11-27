<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use Closure;
use Jatsa\Modules\Reservas\RepositorioReservas;
use Jatsa\Modules\Reservas\ReservaRepository;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/** Formalización de envíos en ventanilla (diapositiva 19 y proceso P2): pesa cada bulto, define su tipo por el peso, */
final class EnvioService
{
    public const TIPOS_ENTREGA = ['AGENCIA', 'DOMICILIO'];

    public const MAX_BULTOS = 50;

    private readonly RepositorioEnvios $envios;
    private readonly RepositorioReservas $reservas;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD); la misma fuente que el cotizador. Se inyecta para probar. */
    public function __construct(?RepositorioEnvios $envios = null, ?RepositorioReservas $reservas = null, ?callable $hoy = null)
    {
        $this->envios = $envios ?? new EnvioRepository();
        $this->reservas = $reservas ?? new ReservaRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos codigoReserva, bultos
     * @return array<string, mixed>
     */
    public function cotizar(array $usuario, array $datos): array
    {
        $reserva = $this->reservaFormalizable($usuario, self::texto($datos, 'codigoReserva'));
        $tarifas = $this->envios->tarifasVigentes($reserva['origen']['codigo'], $reserva['destino']['codigo'], ($this->hoy)());

        $entrada = $datos['bultos'] ?? [];
        $entrada = is_array($entrada) ? array_values($entrada) : [];
        if (count($entrada) > self::MAX_BULTOS) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los bultos.', [], ['bultos' => sprintf('Un envío admite hasta %d bultos.', self::MAX_BULTOS)]);
        }

        $filas = [];
        $centimos = 0;
        $centesimas = 0;
        $valido = $entrada !== [];
        foreach ($entrada as $i => $bulto) {
            $numero = $i + 1;
            $evaluado = $this->evaluarBulto(is_array($bulto) ? $bulto : [], $numero, $tarifas, $reserva['origen']['codigo'], $reserva['destino']['codigo']);
            $filas[] = $evaluado['fila'];
            if ($evaluado['error'] !== null || $evaluado['errorDescripcion'] !== null) {
                $valido = false;
            }
            if ($evaluado['error'] === null) {
                $centimos += $evaluado['centimos'];
                $centesimas += $evaluado['centesimas'] ?? 0;
            }
        }

        return [
            'reserva' => ['codigo' => $reserva['codigo'], 'origen' => $reserva['origen'], 'destino' => $reserva['destino']],
            'distanciaKm' => self::distancia($tarifas),
            'bultos' => $filas,
            'cantidadBultos' => count($filas),
            'pesoTotalKg' => self::kilos($centesimas),
            'importe' => Tarifas::formatoSoles($centimos),
            'valido' => $valido,
        ];
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos codigoReserva, tipoEntrega, direccionEntrega?, bultos
     * @return array<string, mixed>
     */
    public function formalizar(array $usuario, array $datos): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $reserva = $this->reservaFormalizable($usuario, self::texto($datos, 'codigoReserva'));

        $errores = [];
        $tipoEntrega = strtoupper(self::texto($datos, 'tipoEntrega'));
        $direccion = self::normalizarEspacios(self::texto($datos, 'direccionEntrega'));
        if (!in_array($tipoEntrega, self::TIPOS_ENTREGA, true)) {
            $errores['tipoEntrega'] = 'Elige AGENCIA o DOMICILIO.';
        } elseif ($tipoEntrega === 'DOMICILIO') {
            if ($direccion === '') {
                $errores['direccionEntrega'] = 'Ingresa la dirección de entrega.';
            } elseif (preg_match('/^.{1,150}$/su', $direccion) !== 1) {
                $errores['direccionEntrega'] = 'Puede tener hasta 150 caracteres.';
            }
        }

        $entrada = $datos['bultos'] ?? [];
        $entrada = is_array($entrada) ? array_values($entrada) : [];
        if ($entrada === []) {
            $errores['bultos'] = 'Agrega al menos un bulto.';
        } elseif (count($entrada) > self::MAX_BULTOS) {
            $errores['bultos'] = sprintf('Un envío admite hasta %d bultos.', self::MAX_BULTOS);
        }

        // Comprobación previa (con mensajes por bulto). La autoritativa se repite dentro de la transacción.
        if ($errores === []) {
            $tarifas = $this->envios->tarifasVigentes($reserva['origen']['codigo'], $reserva['destino']['codigo'], ($this->hoy)());
            $errores = $this->erroresDeBultos($entrada, $tarifas, $reserva['origen']['codigo'], $reserva['destino']['codigo']);
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los datos del envío.', [], $errores);
        }

        $planificar = function (array $ids, array $tarifasEnTransaccion) use ($entrada, $tipoEntrega, $direccion, $reserva): array {
            $codigoOrigen = $reserva['origen']['codigo'];
            $codigoDestino = $reserva['destino']['codigo'];
            $errores = $this->erroresDeBultos($entrada, $tarifasEnTransaccion, $codigoOrigen, $codigoDestino);
            if ($errores !== []) {
                throw new HttpException(422, 'datos_invalidos', 'Revisa los datos del envío.', [], $errores);
            }

            $bultos = [];
            foreach ($entrada as $i => $bulto) {
                $e = $this->evaluarBulto($bulto, $i + 1, $tarifasEnTransaccion, $codigoOrigen, $codigoDestino);
                $bultos[] = [
                    'numero' => $i + 1,
                    'descripcion' => $e['fila']['descripcion'],
                    'pesoKg' => $e['fila']['pesoKg'],
                    'costo' => Tarifas::formatoSoles($e['centimos']),
                    'idRuta' => $e['idRuta'],
                ];
            }

            return ['tipoEntrega' => $tipoEntrega, 'direccionEntrega' => $tipoEntrega === 'DOMICILIO' ? $direccion : null, 'bultos' => $bultos];
        };

        $codigoSeguimiento = $this->envios->formalizar($reserva['codigo'], $sucursal['id'], ($this->hoy)(), $planificar);

        if ($codigoSeguimiento === null) {
            $this->reservaFormalizable($usuario, $reserva['codigo']);
            throw new HttpException(409, 'reserva_no_formalizable', 'La reserva ya no se puede formalizar. Actualiza la pantalla.');
        }

        return $this->presentar($this->envios->buscarPorCodigo($codigoSeguimiento) ?? throw new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío.'));
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function ver(array $usuario, string $codigoSeguimiento): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = strtoupper(trim($codigoSeguimiento));
        $envio = preg_match('/^EN[A-Z0-9]{10}$/', $codigo) === 1 ? $this->envios->buscarPorCodigo($codigo) : null;

        if ($envio === null || !in_array($sucursal['id'], [$envio['idSucursalOrigen'], $envio['idSucursalDestino']], true)) {
            throw new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío.');
        }

        return $this->presentar($envio);
    }


    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    private function reservaFormalizable(array $usuario, string $codigo): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigo = strtoupper(trim($codigo));
        $reserva = preg_match('/^R\d{9}$/', $codigo) === 1 ? $this->reservas->buscarDeSucursal($sucursal['id'], $codigo) : null;

        if ($reserva === null) {
            throw new HttpException(404, 'reserva_no_encontrada', 'No se encontró la reserva en esta agencia.');
        }

        switch ($reserva['estado']) {
            case 'FORMALIZADA':
                throw new HttpException(409, 'reserva_formalizada', 'La reserva ya fue formalizada' . ($reserva['codigoSeguimiento'] !== null ? ' (envío ' . $reserva['codigoSeguimiento'] . ')' : '') . '.');
            case 'CANCELADA':
                throw new HttpException(409, 'reserva_no_formalizable', 'La reserva fue cancelada: no se puede formalizar.');
            case 'VENCIDA':
                throw self::reservaVencida();
        }

        if ($reserva['plazoCumplido']) {
            throw self::reservaVencida();
        }

        return $reserva;
    }

    private static function reservaVencida(): HttpException
    {
        return new HttpException(
            409,
            'reserva_vencida',
            sprintf('Pasaron las %d horas de vigencia de la reserva: ya no se puede formalizar. Crea una nueva.', RepositorioReservas::HORAS_DE_VIGENCIA),
        );
    }

    /**
     * @param list<mixed> $entrada
     * @param list<array<string, mixed>> $tarifas
     * @return array<string, string>
     */
    private function erroresDeBultos(array $entrada, array $tarifas, string $codigoOrigen, string $codigoDestino): array
    {
        $errores = [];
        foreach ($entrada as $i => $bulto) {
            $evaluado = $this->evaluarBulto(is_array($bulto) ? $bulto : [], $i + 1, $tarifas, $codigoOrigen, $codigoDestino);
            if ($evaluado['error'] !== null) {
                $errores[$evaluado['campo']] = $evaluado['error'];
            }
            if ($evaluado['errorDescripcion'] !== null) {
                $errores['bultos.' . ($i + 1) . '.descripcion'] = $evaluado['errorDescripcion'];
            }
        }

        return $errores;
    }

    /**
     * @param array<string, mixed> $bulto
     * @param list<array<string, mixed>> $tarifas
     * @return array{fila: array<string, mixed>, error: ?string, errorDescripcion: ?string, campo: string, centimos: int, centesimas: ?int, idRuta: ?int}
     */
    private function evaluarBulto(array $bulto, int $numero, array $tarifas, string $codigoOrigen, string $codigoDestino): array
    {
        $campo = 'bultos.' . $numero . '.pesoKg';
        $descripcion = self::normalizarEspacios(self::texto($bulto, 'descripcion'));
        $errorDescripcion = null;
        if ($descripcion === '') {
            $errorDescripcion = 'Describe el bulto.';
        } elseif (preg_match('/^.{1,150}$/su', $descripcion) !== 1) {
            $errorDescripcion = 'Puede tener hasta 150 caracteres.';
        }

        $fila = ['numero' => $numero, 'descripcion' => $descripcion, 'pesoKg' => null, 'tipo' => null, 'costo' => null, 'error' => null, 'errorDescripcion' => $errorDescripcion];
        $estado = ['centimos' => 0, 'centesimas' => null, 'idRuta' => null];
        $terminar = static function (?string $error) use (&$fila, &$estado, $campo, $errorDescripcion): array {
            $fila['error'] = $error;

            return ['fila' => $fila, 'error' => $error, 'errorDescripcion' => $errorDescripcion, 'campo' => $campo] + $estado;
        };

        $peso = $bulto['pesoKg'] ?? '';
        $peso = is_int($peso) || is_float($peso) ? (string) $peso : (is_string($peso) ? str_replace(',', '.', trim($peso)) : '');
        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $peso) !== 1) {
            return $terminar('Ingresa el peso en kilogramos, con hasta 2 decimales.');
        }
        $centesimas = (int) round(((float) $peso) * 100);
        if ($centesimas <= 0) {
            return $terminar('El peso debe ser mayor que 0.');
        }
        $fila['pesoKg'] = self::kilos($centesimas);
        $estado['centesimas'] = $centesimas;

        $tipo = Tarifas::tipoPorPeso($centesimas);
        if ($tipo === null) {
            return $terminar(sprintf('El peso máximo por bulto es %d kg.', max(Tarifas::LIMITES_KG)));
        }
        $fila['tipo'] = $tipo;

        $tarifa = null;
        foreach ($tarifas as $t) {
            if ($t['tipoEnvio'] === $tipo) {
                $tarifa = $t;
            }
        }
        if ($tarifa === null) {
            return $terminar(sprintf('%s: sin ruta vigente %s→%s', $tipo, substr($codigoOrigen, 0, 3), substr($codigoDestino, 0, 3)));
        }

        $centimos = Tarifas::costoBultoCentimos($tarifa['precioBase'], $tarifa['precioPorKm'], $tarifa['distanciaKm']);
        $fila['costo'] = Tarifas::formatoSoles($centimos);
        $estado['centimos'] = $centimos;
        $estado['idRuta'] = (int) $tarifa['idRuta'];

        return $terminar(null);
    }

    /** @param list<array<string, mixed>> $tarifas */
    private static function distancia(array $tarifas): ?string
    {
        if ($tarifas === []) {
            return null;
        }
        $texto = number_format((float) $tarifas[0]['distanciaKm'], 1, '.', '');

        return str_ends_with($texto, '.0') ? substr($texto, 0, -2) : $texto;
    }

    /**
     * @param array<string, mixed> $envio
     * @return array<string, mixed>
     */
    private function presentar(array $envio): array
    {
        $centimos = 0;
        $centesimas = 0;
        $bultos = [];
        foreach ($envio['bultos'] as $b) {
            $costo = (int) round(((float) $b['costo']) * 100);
            $peso = (int) round(((float) $b['pesoKg']) * 100);
            $centimos += $costo;
            $centesimas += $peso;
            $bultos[] = [
                'numero' => $b['numero'],
                'descripcion' => $b['descripcion'],
                'pesoKg' => self::kilos($peso),
                'tipo' => $b['tipo'],
                'costo' => Tarifas::formatoSoles($costo),
            ];
        }

        return [
            'codigoSeguimiento' => $envio['codigoSeguimiento'],
            'fechaHoraRegistro' => $envio['fechaHoraRegistro'],
            'estado' => $envio['estado'],
            'tipoEntrega' => $envio['tipoEntrega'],
            'direccionEntrega' => $envio['direccionEntrega'],
            'reserva' => $envio['reserva'],
            'bultos' => $bultos,
            'cantidadBultos' => count($bultos),
            'pesoTotalKg' => self::kilos($centesimas),
            'importe' => Tarifas::formatoSoles($centimos),
        ];
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

    private static function normalizarEspacios(string $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
    }
}
