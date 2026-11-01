<?php
declare(strict_types=1);

namespace Jatsa\Modules\Despacho;

use Closure;
use Jatsa\Modules\Publico\AgenciaRepository;
use Jatsa\Modules\Publico\RepositorioAgencias;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Modules\Viajes\RepositorioViajes;
use Jatsa\Modules\Viajes\ViajeRepository;
use Jatsa\Modules\Viajes\ViajeService;
use Jatsa\Shared\HttpException;

/**
 * Despacho del Administrador (diapositiva 21): viajes que salen de su agencia, programar un viaje y cargar en él los
 * envíos que esperan en la agencia. Todo se limita a la sucursal de la sesión: un viaje de otro origen es, para el
 * personal, como si no existiera.
 *
 * Las reglas de carga (viaje programado y de esta agencia, envío en espera aquí, destino no visitado, capacidad del
 * vehículo) se comprueban dentro de la transacción de DespachoRepository::cargar, con el viaje y el envío bloqueados.
 */
final class DespachoService
{
    /** Terminados que se muestran en la lista de salidas (los programados y en ruta se muestran todos). */
    public const MAX_TERMINADOS = 20;
    public const MAX_EN_ESPERA = 200;

    private readonly RepositorioDespacho $despacho;
    private readonly RepositorioViajes $viajes;
    private readonly RepositorioAgencias $agencias;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD); se inyecta para probar. */
    public function __construct(?RepositorioDespacho $despacho = null, ?RepositorioViajes $viajes = null, ?RepositorioAgencias $agencias = null, ?callable $hoy = null)
    {
        $this->despacho = $despacho ?? new DespachoRepository();
        $this->viajes = $viajes ?? new ViajeRepository();
        $this->agencias = $agencias ?? new AgenciaRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /**
     * «Despacho · salen de la agencia»: programados y en ruta, y los últimos terminados.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function salidas(array $usuario): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $filas = $this->viajes->listarDeSucursalOrigen($sucursal['id'], self::MAX_TERMINADOS + 1, ($this->hoy)());

        $viajes = [];
        $terminados = 0;
        $hayMas = false;
        foreach ($filas as $fila) {
            $presentado = ViajeService::presentar($fila);
            if ($presentado['estado'] === 'TERMINADO' && ++$terminados > self::MAX_TERMINADOS) {
                $hayMas = true;
                continue;
            }
            $viajes[] = $presentado;
        }

        return ['sucursal' => ['codigo' => $sucursal['codigo'], 'nombre' => $sucursal['nombre']], 'viajes' => $viajes, 'hayMasTerminados' => $hayMas];
    }

    /**
     * «Envíos en espera en esta agencia», cada uno con el viaje programado hacia su destino final (si hay).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function enEspera(array $usuario): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $filas = $this->despacho->enEsperaDeSucursal($sucursal['id'], self::MAX_EN_ESPERA + 1);
        $programados = array_values(array_filter(
            $this->viajes->listarDeSucursalOrigen($sucursal['id'], 1, ($this->hoy)()),
            static fn (array $v): bool => $v['fechaHoraSalida'] === null,
        ));

        $envios = [];
        foreach (array_slice($filas, 0, self::MAX_EN_ESPERA) as $fila) {
            $sugerido = null;
            foreach ($programados as $viaje) {
                if ($viaje['destino']['codigo'] === $fila['destino']['codigo']) {
                    $sugerido = $viaje['codigo'];
                    break;
                }
            }
            $envios[] = [
                'codigoSeguimiento' => $fila['codigoSeguimiento'],
                'estado' => $fila['estado'],
                'bultos' => $fila['bultos'],
                'pesoKg' => ViajeService::kilos($fila['pesoKg']),
                'destino' => $fila['destino'],
                'viajeSugerido' => $sugerido,
            ];
        }

        return ['envios' => $envios, 'hayMas' => count($filas) > self::MAX_EN_ESPERA];
    }

    /**
     * Lo que ofrece el formulario «Programar viaje»: vehículos, transportistas y destinos (las demás agencias activas).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function opciones(array $usuario): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $destinos = [];
        foreach ($this->agencias->listarActivas() as $agencia) {
            if ($agencia['codigo'] !== $sucursal['codigo']) {
                $destinos[] = ['codigo' => $agencia['codigo'], 'nombre' => $agencia['nombre']];
            }
        }

        return [
            'vehiculos' => array_map(
                static fn (array $v): array => ['capacidadKg' => ViajeService::kilos($v['capacidadKg'])] + $v,
                $this->despacho->vehiculosActivos(),
            ),
            'transportistas' => $this->despacho->transportistasActivos(),
            'destinos' => $destinos,
        ];
    }

    /**
     * Programa un viaje que sale de la agencia de la sesión: queda sin salida ni llegada (PROGRAMADO).
     *
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos placa, transportista (nombre de usuario), destino (código de sucursal)
     * @return array<string, mixed>
     */
    public function programar(array $usuario, array $datos): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);

        $errores = [];
        $placa = strtoupper(self::texto($datos, 'placa'));
        $transportista = self::texto($datos, 'transportista');
        $destino = strtoupper(self::texto($datos, 'destino'));
        if (preg_match('/^[A-Z0-9]{3}-\d{3}$/', $placa) !== 1) {
            $errores['placa'] = 'Elige un vehículo (placa con el formato ABC-123).';
        }
        if (preg_match('/^[A-Za-z0-9_.-]{4,30}$/', $transportista) !== 1) {
            $errores['transportista'] = 'Elige un transportista.';
        }
        if (preg_match('/^[A-Z]{3}\d{3}$/', $destino) !== 1) {
            $errores['destino'] = 'Elige la agencia de destino.';
        } elseif ($destino === $sucursal['codigo']) {
            $errores['destino'] = 'El destino debe ser otra agencia.';
        }

        $idVehiculo = isset($errores['placa']) ? null : $this->despacho->idVehiculoActivo($placa);
        $idTransportista = isset($errores['transportista']) ? null : $this->despacho->idTransportistaActivo($transportista);
        $idDestino = isset($errores['destino']) ? null : $this->despacho->idSucursalActiva($destino);
        if (!isset($errores['placa']) && $idVehiculo === null) {
            $errores['placa'] = 'No hay un vehículo activo con esa placa.';
        }
        if (!isset($errores['transportista']) && $idTransportista === null) {
            $errores['transportista'] = 'No hay un transportista activo con ese usuario.';
        }
        if (!isset($errores['destino']) && $idDestino === null) {
            $errores['destino'] = 'No hay una agencia activa con ese código.';
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los datos del viaje.', [], $errores);
        }

        $codigo = $this->despacho->crearViaje((int) $idVehiculo, (int) $idTransportista, $sucursal['id'], (int) $idDestino);

        return $this->detalle($codigo);
    }

    /**
     * Carga un envío en un viaje programado de la agencia.
     *
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $datos codigoSeguimiento
     * @return array<string, mixed> El viaje con su carga.
     */
    public function cargar(array $usuario, string $codigoViaje, array $datos): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigoViaje = self::codigoViaje($codigoViaje);
        $seguimiento = strtoupper(self::texto($datos, 'codigoSeguimiento'));
        if (preg_match('/^EN[A-Z0-9]{10}$/', $seguimiento) !== 1) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los datos de la carga.', [], ['codigoSeguimiento' => 'El código de seguimiento empieza con EN y tiene 12 caracteres.']);
        }

        $decidir = static function (array $contexto) use ($sucursal): void {
            $viaje = $contexto['viaje'];
            if ($viaje['idSucursalOrigen'] !== $sucursal['id']) {
                throw self::viajeNoEncontrado();
            }
            if ($viaje['fechaHoraSalida'] !== null) {
                throw new HttpException(409, 'viaje_no_programado', 'El viaje ya salió: no admite más carga.');
            }

            $envio = $contexto['envio'];
            if ($envio === null) {
                throw self::envioNoEncontrado();
            }
            $ultimo = $contexto['ultimoViaje'];
            $enEspera = $ultimo === null
                ? ($envio['idSucursalOrigen'] === $sucursal['id'] && $envio['estado'] === 'REGISTRADO')
                : ($ultimo['fechaHoraLlegada'] !== null && $ultimo['idSucursalDestino'] === $sucursal['id']
                    && $envio['idSucursalDestino'] !== $sucursal['id'] && $envio['estado'] === 'EN_TRANSITO');
            if (!$enEspera) {
                // Si ya está cargado en un viaje de ESTA agencia que no llegó, se dice; de lo contrario, igual que si no existiera.
                if ($ultimo !== null && $ultimo['fechaHoraLlegada'] === null && $ultimo['idSucursalOrigen'] === $sucursal['id']) {
                    throw new HttpException(409, 'envio_ya_cargado', 'El envío ya está cargado en un viaje que todavía no llegó.');
                }
                throw self::envioNoEncontrado();
            }

            if (in_array($viaje['idSucursalDestino'], [...$contexto['visitadas'], $envio['idSucursalOrigen']], true)) {
                throw new HttpException(409, 'destino_ya_visitado', 'El envío ya pasó por la agencia de destino de ese viaje.');
            }

            $capacidad = self::centesimas($viaje['capacidadKg']);
            $total = self::centesimas($viaje['pesoCargadoKg']) + self::centesimas($envio['pesoKg']);
            if ($total > $capacidad) {
                throw new HttpException(409, 'capacidad_excedida', sprintf('Con este envío el viaje llevaría %s kg y su vehículo admite %s kg.', ViajeService::kilos((string) ($total / 100)), ViajeService::kilos((string) ($capacidad / 100))));
            }
        };

        if ($this->despacho->cargar($codigoViaje, $seguimiento, $decidir) === null) {
            throw self::viajeNoEncontrado();
        }

        return $this->detalle($codigoViaje);
    }

    /**
     * Quita un envío de un viaje programado de la agencia (el envío vuelve a esperar).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed> El viaje con su carga.
     */
    public function quitar(array $usuario, string $codigoViaje, string $codigoSeguimiento): array
    {
        $sucursal = VentanillaService::sucursalDe($usuario);
        $codigoViaje = self::codigoViaje($codigoViaje);
        $seguimiento = strtoupper(trim($codigoSeguimiento));
        if (preg_match('/^EN[A-Z0-9]{10}$/', $seguimiento) !== 1) {
            throw new HttpException(404, 'envio_no_encontrado', 'El envío no estaba cargado en ese viaje.');
        }

        $decidir = static function (array $contexto) use ($sucursal): void {
            if ($contexto['viaje']['idSucursalOrigen'] !== $sucursal['id']) {
                throw self::viajeNoEncontrado();
            }
            if ($contexto['viaje']['fechaHoraSalida'] !== null) {
                throw new HttpException(409, 'viaje_no_programado', 'El viaje ya salió: no se puede quitar carga.');
            }
        };

        $quitado = $this->despacho->quitar($codigoViaje, $seguimiento, $decidir);
        if ($quitado === null) {
            throw self::viajeNoEncontrado();
        }
        if ($quitado === false) {
            throw new HttpException(404, 'envio_no_encontrado', 'El envío no estaba cargado en ese viaje.');
        }

        return $this->detalle($codigoViaje);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function detalle(string $codigo): array
    {
        $viaje = $this->viajes->buscarPorCodigo($codigo, ($this->hoy)()) ?? throw self::viajeNoEncontrado();

        return ViajeService::presentar($viaje, true);
    }

    private static function codigoViaje(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        if (preg_match('/^VJ\d{10}$/', $codigo) !== 1) {
            throw self::viajeNoEncontrado();
        }

        return $codigo;
    }

    private static function viajeNoEncontrado(): HttpException
    {
        return new HttpException(404, 'viaje_no_encontrado', 'No se encontró el viaje en esta agencia.');
    }

    private static function envioNoEncontrado(): HttpException
    {
        return new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío en espera en esta agencia.');
    }

    /** "18.50" → 1850 */
    private static function centesimas(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }
}
