<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 7 (Despacho, Mis viajes y Detalle del viaje) con repositorios simulados, sin base de datos.
 * Se incluye desde ejecutar.php, que aporta el mini marco (prueba, afirmar, esperarError…), AgenciasFalso y los
 * ayudantes de sesión.
 */

use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Despacho\DespachoController;
use Jatsa\Modules\Despacho\DespachoService;
use Jatsa\Modules\Despacho\RepositorioDespacho;
use Jatsa\Modules\Viajes\RepositorioViajes;
use Jatsa\Modules\Viajes\ViajeController;
use Jatsa\Modules\Viajes\ViajeService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/**
 * Viajes, envíos y cargas en memoria. Un solo objeto hace de RepositorioViajes y de RepositorioDespacho y reproduce lo que
 * hace el SQL real: «bloquea», reúne el contexto, llama a $decidir (que puede abortar sin escribir) y entonces escribe.
 */
final class ViajesFalso implements RepositorioViajes, RepositorioDespacho
{
    public const SUCURSALES = ['LIM001' => 1, 'AQP001' => 2, 'MOQ001' => 3, 'ILO001' => 4];

    /** @var array<string, array<string, mixed>> codigo => viaje */
    public array $viajes = [];
    /** @var array<string, array<string, mixed>> codigoSeguimiento => envío */
    public array $envios = [];
    /** @var list<array{viaje: string, envio: string}> cargas, en orden de alta (idEnvioViaje creciente) */
    public array $cargas = [];
    /** @var array<string, array{id: int, tipo: string, capacidad: string}> */
    public array $vehiculos = ['C3M-956' => ['id' => 3, 'tipo' => 'CAMIONETA', 'capacidad' => '1500.00'], 'B7K-210' => ['id' => 2, 'tipo' => 'FURGON', 'capacidad' => '6500.00'], 'V2A-845' => ['id' => 1, 'tipo' => 'CAMION', 'capacidad' => '18000.00']];
    /** @var array<string, array{id: int, nombre: string, sucursal: string}> */
    public array $transportistas = ['pmamani' => ['id' => 5, 'nombre' => 'Pedro Mamani Huanca', 'sucursal' => 'AQP001'], 'jquispe' => ['id' => 6, 'nombre' => 'Juan Quispe Rojas', 'sucursal' => 'MOQ001']];
    public string $ahora = '2026-10-31 10:00:00';
    public int $siguienteViaje = 1;
    public ?Closure $antesDeDecidir = null;
    public int $transaccionesDeshechas = 0;
    private AgenciasFalso $agencias;

    public function __construct()
    {
        $this->agencias = new AgenciasFalso();
    }

    public function viaje(string $codigo, string $vehiculo, string $origen, string $destino, int $transportista, ?string $salida = null, ?string $llegada = null): void
    {
        $this->viajes[$codigo] = ['idViaje' => count($this->viajes) + 1, 'codigo' => $codigo, 'placa' => $vehiculo, 'origen' => $origen, 'destino' => $destino, 'transportista' => $transportista, 'salida' => $salida, 'llegada' => $llegada];
    }

    public function envio(string $codigo, string $origen, string $destino, string $estado, string $pesoKg, int $bultos = 1): void
    {
        $this->envios[$codigo] = ['idEnvio' => count($this->envios) + 1, 'codigo' => $codigo, 'origen' => $origen, 'destino' => $destino, 'estado' => $estado, 'pesoKg' => $pesoKg, 'bultos' => $bultos];
    }

    public function cargarDirecto(string $viaje, string $envio): void
    {
        $this->cargas[] = ['viaje' => $viaje, 'envio' => $envio];
    }

    private function nombre(string $codigoSucursal): array
    {
        return ['codigo' => $codigoSucursal, 'nombre' => $this->agencias->buscarActivaPorCodigo($codigoSucursal)['nombre']];
    }

    /** @return list<string> */
    private function enviosDe(string $codigoViaje): array
    {
        return array_values(array_map(static fn (array $c): string => $c['envio'], array_filter($this->cargas, static fn (array $c): bool => $c['viaje'] === $codigoViaje)));
    }

    private function pesoDe(string $codigoViaje): int
    {
        return array_sum(array_map(fn (string $e): int => (int) round(((float) $this->envios[$e]['pesoKg']) * 100), $this->enviosDe($codigoViaje)));
    }

    /** @return array<string, mixed> */
    private function fila(array $v, bool $conCarga = false): array
    {
        $transportista = null;
        foreach ($this->transportistas as $nombreUsuario => $t) {
            if ($t['id'] === $v['transportista']) {
                $transportista = $t['nombre'];
            }
        }
        $peso = $this->pesoDe($v['codigo']);
        $fila = [
            'idViaje' => $v['idViaje'], 'codigo' => $v['codigo'], 'idUsuarioTransportista' => $v['transportista'],
            'idSucursalOrigen' => self::SUCURSALES[$v['origen']], 'idSucursalDestino' => self::SUCURSALES[$v['destino']],
            'origen' => $this->nombre($v['origen']), 'destino' => $this->nombre($v['destino']),
            'vehiculo' => ['placa' => $v['placa'], 'tipo' => $this->vehiculos[$v['placa']]['tipo'], 'capacidadKg' => $this->vehiculos[$v['placa']]['capacidad']],
            'transportista' => $transportista, 'fechaHoraSalida' => $v['salida'], 'fechaHoraLlegada' => $v['llegada'],
            'cantidadEnvios' => count($this->enviosDe($v['codigo'])), 'pesoKg' => sprintf('%d.%02d', intdiv($peso, 100), $peso % 100),
            'horas' => ($v['origen'] === 'MOQ001' && $v['destino'] === 'AQP001') ? 4 : null,
        ];
        if ($conCarga) {
            $fila['carga'] = array_map(fn (string $e): array => [
                'codigoSeguimiento' => $e, 'bultos' => $this->envios[$e]['bultos'], 'pesoKg' => $this->envios[$e]['pesoKg'],
                'destinoFinal' => $this->nombre($this->envios[$e]['destino']), 'cargadoEn' => '2026-10-31 09:00:00',
            ], $this->enviosDe($v['codigo']));
        }

        return $fila;
    }

    private static function clave(array $v): array
    {
        $orden = $v['salida'] !== null && $v['llegada'] === null ? 0 : ($v['salida'] === null ? 1 : 2);

        return [$orden, $orden === 1 ? $v['codigo'] : '', $orden === 1 ? '' : strrev((string) $v['salida'])];
    }

    // ── RepositorioViajes ───────────────────────────────────────────────────
    public function listarDeTransportista(int $idUsuario, ?string $estado, int $limite, string $hoy): array
    {
        $filas = array_filter($this->viajes, static fn (array $v): bool => $v['transportista'] === $idUsuario && match ($estado) {
            'PROGRAMADO' => $v['salida'] === null,
            'EN_RUTA' => $v['salida'] !== null && $v['llegada'] === null,
            'TERMINADO' => $v['llegada'] !== null,
            default => true,
        });

        return array_map(fn (array $v): array => $this->fila($v), array_slice($this->ordenar(array_values($filas)), 0, $limite));
    }

    /** @param list<array<string, mixed>> $lista @return list<array<string, mixed>> */
    private function ordenar(array $lista): array
    {
        usort($lista, static function (array $a, array $b): int {
            $ka = self::clave($a);
            $kb = self::clave($b);
            if ($ka[0] !== $kb[0]) {
                return $ka[0] <=> $kb[0];
            }

            return $ka[0] === 1 ? strcmp($ka[1], $kb[1]) : strcmp((string) $b['salida'], (string) $a['salida']);
        });

        return $lista;
    }

    public function listarDeSucursalOrigen(int $idSucursal, int $limiteTerminados, string $hoy): array
    {
        $propios = array_filter($this->viajes, static fn (array $v): bool => self::SUCURSALES[$v['origen']] === $idSucursal);
        $activos = $this->ordenar(array_values(array_filter($propios, static fn (array $v): bool => $v['llegada'] === null)));
        $terminados = array_values(array_filter($propios, static fn (array $v): bool => $v['llegada'] !== null));
        usort($terminados, static fn (array $a, array $b): int => strcmp((string) $b['llegada'], (string) $a['llegada']));

        return array_map(fn (array $v): array => $this->fila($v), array_merge($activos, array_slice($terminados, 0, $limiteTerminados)));
    }

    public function buscarPorCodigo(string $codigoViaje, string $hoy): ?array
    {
        return isset($this->viajes[$codigoViaje]) ? $this->fila($this->viajes[$codigoViaje], true) : null;
    }

    private function crudo(array $v): array
    {
        return ['idViaje' => $v['idViaje'], 'idVehiculo' => $this->vehiculos[$v['placa']]['id'], 'idUsuarioTransportista' => $v['transportista'],
            'idSucursalOrigen' => self::SUCURSALES[$v['origen']], 'idSucursalDestino' => self::SUCURSALES[$v['destino']], 'fechaHoraSalida' => $v['salida'], 'fechaHoraLlegada' => $v['llegada']];
    }

    private function decidir(callable $decidir, array|callable $contexto): void
    {
        if ($this->antesDeDecidir !== null) {
            $hook = $this->antesDeDecidir;
            $this->antesDeDecidir = null;
            $hook($this);
        }
        if (is_callable($contexto)) {
            $contexto = $contexto();
        }
        try {
            $decidir($contexto);
        } catch (Throwable $t) {
            $this->transaccionesDeshechas++;
            throw $t;
        }
    }

    public function marcarSalida(string $codigoViaje, callable $decidir): ?bool
    {
        $v = $this->viajes[$codigoViaje] ?? null;
        if ($v === null) {
            return null;
        }
        // El contexto se calcula DESPUÉS del gancho de concurrencia, como el SQL real lo lee con los bloqueos ya tomados.
        $this->decidir($decidir, function () use ($codigoViaje): array {
            $v = $this->viajes[$codigoViaje];
            $vehiculoOcupado = false;
            $transportistaOcupado = false;
            foreach ($this->viajes as $otro) {
                $enRuta = $otro['codigo'] !== $codigoViaje && $otro['salida'] !== null && $otro['llegada'] === null;
                $vehiculoOcupado = $vehiculoOcupado || ($enRuta && $otro['placa'] === $v['placa']);
                $transportistaOcupado = $transportistaOcupado || ($enRuta && $otro['transportista'] === $v['transportista']);
            }

            return ['viaje' => $this->crudo($v), 'vehiculoOcupado' => $vehiculoOcupado, 'transportistaOcupado' => $transportistaOcupado];
        });
        if ($this->viajes[$codigoViaje]['salida'] !== null) {
            return false;
        }
        $this->viajes[$codigoViaje]['salida'] = $this->ahora;
        foreach ($this->enviosDe($codigoViaje) as $e) {
            if ($this->envios[$e]['estado'] === 'REGISTRADO') {
                $this->envios[$e]['estado'] = 'EN_TRANSITO';
            }
        }

        return true;
    }

    public function marcarLlegada(string $codigoViaje, callable $decidir): ?bool
    {
        $v = $this->viajes[$codigoViaje] ?? null;
        if ($v === null) {
            return null;
        }
        $this->decidir($decidir, ['viaje' => $this->crudo($v)]);
        $v = $this->viajes[$codigoViaje];
        if ($v['salida'] === null || $v['llegada'] !== null || $v['salida'] >= $this->ahora) {
            return false;
        }
        $this->viajes[$codigoViaje]['llegada'] = $this->ahora;
        foreach ($this->enviosDe($codigoViaje) as $e) {
            if ($this->envios[$e]['estado'] === 'EN_TRANSITO' && $this->envios[$e]['destino'] === $v['destino']) {
                $this->envios[$e]['estado'] = 'EN_DESTINO';
            }
        }

        return true;
    }

    // ── RepositorioDespacho ─────────────────────────────────────────────────
    private function ultimoViaje(string $envio): ?array
    {
        $ultimo = null;
        foreach ($this->cargas as $c) {
            if ($c['envio'] === $envio) {
                $ultimo = $this->viajes[$c['viaje']];
            }
        }

        return $ultimo;
    }

    public function enEsperaDeSucursal(int $idSucursal, int $limite): array
    {
        $filas = [];
        foreach ($this->envios as $e) {
            $ult = $this->ultimoViaje($e['codigo']);
            $espera = $ult === null
                ? ($e['estado'] === 'REGISTRADO' && self::SUCURSALES[$e['origen']] === $idSucursal)
                : ($e['estado'] === 'EN_TRANSITO' && self::SUCURSALES[$e['destino']] !== $idSucursal && $ult['llegada'] !== null && self::SUCURSALES[$ult['destino']] === $idSucursal);
            if ($espera) {
                $filas[] = ['codigoSeguimiento' => $e['codigo'], 'estado' => $e['estado'], 'bultos' => $e['bultos'], 'pesoKg' => $e['pesoKg'],
                    'idSucursalDestino' => self::SUCURSALES[$e['destino']], 'destino' => $this->nombre($e['destino'])];
            }
        }

        return array_slice($filas, 0, $limite);
    }

    public function vehiculosActivos(): array
    {
        $r = [];
        foreach ($this->vehiculos as $placa => $v) {
            $ocupado = false;
            foreach ($this->viajes as $viaje) {
                $ocupado = $ocupado || ($viaje['placa'] === $placa && $viaje['salida'] !== null && $viaje['llegada'] === null);
            }
            $r[] = ['placa' => $placa, 'tipo' => $v['tipo'], 'capacidadKg' => $v['capacidad'], 'ocupado' => $ocupado];
        }

        return $r;
    }

    public function transportistasActivos(): array
    {
        return array_values(array_map(fn (string $u): array => ['nombreUsuario' => $u, 'nombreCompleto' => $this->transportistas[$u]['nombre'], 'sucursal' => $this->nombre($this->transportistas[$u]['sucursal'])], array_keys($this->transportistas)));
    }

    /** Recursos desactivados por el gerente (placas, usuarios y códigos de sucursal). */
    public array $inactivos = ['vehiculos' => [], 'transportistas' => [], 'sucursales' => []];
    /** Si se asigna, se ejecuta DENTRO de crearViaje, antes de leer los recursos bloqueados: simula una desactivación que se intercala. */
    public mixed $alIniciarCrearViaje = null;

    public function crearViaje(string $placa, string $nombreTransportista, int $idSucursalOrigen, string $codigoDestino, callable $decidir): string
    {
        if ($this->alIniciarCrearViaje !== null) {
            ($this->alIniciarCrearViaje)($this);
        }
        $origen = array_search($idSucursalOrigen, self::SUCURSALES, true);
        $destinoExiste = isset(self::SUCURSALES[$codigoDestino]);
        $decidir([
            'origenActiva' => $origen !== false && !in_array($origen, $this->inactivos['sucursales'], true),
            'destinoExiste' => $destinoExiste,
            'destinoActiva' => $destinoExiste && !in_array($codigoDestino, $this->inactivos['sucursales'], true),
            'vehiculoExiste' => isset($this->vehiculos[$placa]),
            'vehiculoActivo' => isset($this->vehiculos[$placa]) && !in_array($placa, $this->inactivos['vehiculos'], true),
            'transportistaExiste' => isset($this->transportistas[$nombreTransportista]),
            'transportistaActivo' => isset($this->transportistas[$nombreTransportista]) && !in_array($nombreTransportista, $this->inactivos['transportistas'], true),
        ]);
        $idTransportista = $this->transportistas[$nombreTransportista]['id'];
        $destino = $codigoDestino;
        $prefijo = 'VJ' . date('Ymd', (int) strtotime($this->ahora));
        $numero = 1;
        foreach (array_keys($this->viajes) as $c) {
            if (str_starts_with($c, $prefijo)) {
                $numero = max($numero, (int) substr($c, -2) + 1);
            }
        }
        if ($numero > 99) {
            throw new HttpException(409, 'limite_de_viajes', 'Se alcanzó el máximo de 99 viajes programados en el día.');
        }
        $codigo = $prefijo . sprintf('%02d', $numero);
        $this->viaje($codigo, $placa, (string) $origen, (string) $destino, $idTransportista);

        return $codigo;
    }
    public function cargar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool
    {
        $v = $this->viajes[$codigoViaje] ?? null;
        if ($v === null) {
            return null;
        }
        $this->decidir($decidir, fn (): array => $this->contexto($codigoViaje, $codigoSeguimiento));
        $this->cargas[] = ['viaje' => $codigoViaje, 'envio' => $codigoSeguimiento];

        return true;
    }

    /** @return array<string, mixed> */
    private function contexto(string $codigoViaje, string $codigoSeguimiento): array
    {
        $v = $this->viajes[$codigoViaje];
        $e = $this->envios[$codigoSeguimiento] ?? null;
        $contexto = ['viaje' => ['idViaje' => $v['idViaje'], 'idSucursalOrigen' => self::SUCURSALES[$v['origen']], 'idSucursalDestino' => self::SUCURSALES[$v['destino']],
            'fechaHoraSalida' => $v['salida'], 'capacidadKg' => $this->vehiculos[$v['placa']]['capacidad'], 'pesoCargadoKg' => sprintf('%d.%02d', intdiv($this->pesoDe($codigoViaje), 100), $this->pesoDe($codigoViaje) % 100)],
            'envio' => null, 'ultimoViaje' => null, 'visitadas' => []];
        if ($e !== null) {
            $contexto['envio'] = ['idEnvio' => $e['idEnvio'], 'estado' => $e['estado'], 'idSucursalOrigen' => self::SUCURSALES[$e['origen']], 'idSucursalDestino' => self::SUCURSALES[$e['destino']], 'pesoKg' => $e['pesoKg']];
            foreach ($this->cargas as $c) {
                if ($c['envio'] === $codigoSeguimiento) {
                    $vv = $this->viajes[$c['viaje']];
                    $contexto['visitadas'][] = self::SUCURSALES[$vv['destino']];
                    $contexto['ultimoViaje'] = ['idSucursalOrigen' => self::SUCURSALES[$vv['origen']], 'idSucursalDestino' => self::SUCURSALES[$vv['destino']], 'fechaHoraLlegada' => $vv['llegada']];
                }
            }
        }

        return $contexto;
    }

    public function quitar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool
    {
        $v = $this->viajes[$codigoViaje] ?? null;
        if ($v === null) {
            return null;
        }
        $this->decidir($decidir, ['viaje' => ['idViaje' => $v['idViaje'], 'idSucursalOrigen' => self::SUCURSALES[$v['origen']], 'fechaHoraSalida' => $v['salida']]]);
        foreach ($this->cargas as $i => $c) {
            if ($c['viaje'] === $codigoViaje && $c['envio'] === $codigoSeguimiento) {
                unset($this->cargas[$i]);
                $this->cargas = array_values($this->cargas);

                return true;
            }
        }

        return false;
    }
}

/**
 * Escenario de la diapositiva 21 en MOQ001: un viaje en ruta (FURGON B7K-210 con 18.50 kg), uno terminado, tres programados y
 * tres envíos esperando (9.20, 3.10 y 25.00 kg). El transportista Pedro Mamani (id 5) conduce los de la diapositiva 24.
 *
 * @return array{0: ViajesFalso, 1: DespachoService, 2: ViajeService}
 */
function escenarioViajes(): array
{
    $r = new ViajesFalso();
    $r->envio('ENZ5H0D3V7NJ', 'MOQ001', 'AQP001', 'EN_TRANSITO', '18.50');
    $r->envio('EN7K4Q2M9X1B', 'MOQ001', 'AQP001', 'ENTREGADO', '9.20', 2);
    $r->viaje('VJ2026101402', 'B7K-210', 'MOQ001', 'AQP001', 5, '2026-10-14 15:00:00');          // en ruta
    $r->viaje('VJ2026101001', 'C3M-956', 'MOQ001', 'AQP001', 5, '2026-10-10 14:00:00', '2026-10-10 18:30:00'); // terminado
    $r->viaje('VJ2026103101', 'C3M-956', 'MOQ001', 'AQP001', 5);                                // programados
    $r->viaje('VJ2026103102', 'B7K-210', 'MOQ001', 'AQP001', 5);
    $r->viaje('VJ2026103103', 'V2A-845', 'MOQ001', 'ILO001', 6);
    $r->cargarDirecto('VJ2026101402', 'ENZ5H0D3V7NJ');
    $r->cargarDirecto('VJ2026101001', 'EN7K4Q2M9X1B');
    $r->envio('EN4T7Q0P2LKX', 'MOQ001', 'AQP001', 'REGISTRADO', '9.20', 2);
    $r->envio('ENQ8W2E4R6TY', 'MOQ001', 'AQP001', 'REGISTRADO', '3.10');
    $r->envio('ENA1S3D5F7GH', 'MOQ001', 'ILO001', 'REGISTRADO', '25.00');
    $r->envio('ENLIMA000001', 'LIM001', 'AQP001', 'REGISTRADO', '1.00');                       // espera en OTRA agencia
    $hoy = static fn (): string => '2026-10-31';

    return [$r, new DespachoService($r, $r, new AgenciasFalso(), $hoy), new ViajeService($r, $hoy)];
}

function transportista(int $id = 5): array
{
    return ['id' => $id, 'nombreUsuario' => $id === 5 ? 'pmamani' : 'jquispe', 'rol' => Roles::TRANSPORTISTA, 'sucursal' => ['id' => 2, 'codigo' => 'AQP001', 'nombre' => 'Agencia AQP001']];
}

/** Pedro (id 5) ya conduce VJ2026101402; para que salga de otro viaje hay que terminar ese primero (un solo viaje en ruta por conductor). */
function liberarAPedro(ViajesFalso $r, ViajeService $servicio): void
{
    $r->ahora = '2026-10-31 09:00:00';
    $servicio->marcarLlegada(transportista(), 'VJ2026101402');
    $r->ahora = '2026-10-31 10:00:00';
}

// ── Mis viajes ──────────────────────────────────────────────────────────────
prueba('mis viajes: en ruta primero, luego programados por código y luego terminados (diapositiva 24)', function () {
    [, , $servicio] = escenarioViajes();
    $r = $servicio->misViajes(transportista());
    afirmar(array_column($r['viajes'], 'codigo') === ['VJ2026101402', 'VJ2026103101', 'VJ2026103102', 'VJ2026101001'], 'orden: ' . implode(',', array_column($r['viajes'], 'codigo')));
    afirmar(array_column($r['viajes'], 'estado') === ['EN_RUTA', 'PROGRAMADO', 'PROGRAMADO', 'TERMINADO'], 'estados deducidos de las fechas');
    afirmar($r['hayMas'] === false, 'sin truncar');
});

prueba('mis viajes: filtros por estado, solo los propios y filtro inválido → 422', function () {
    [, , $servicio] = escenarioViajes();
    afirmar(count($servicio->misViajes(transportista(), 'programado')['viajes']) === 2, 'programados (acepta minúsculas)');
    afirmar(array_column($servicio->misViajes(transportista(), 'EN_RUTA')['viajes'], 'codigo') === ['VJ2026101402'], 'en ruta');
    afirmar(array_column($servicio->misViajes(transportista(), 'TERMINADO')['viajes'], 'codigo') === ['VJ2026101001'], 'terminados');
    afirmar(array_column($servicio->misViajes(transportista(6))['viajes'], 'codigo') === ['VJ2026103103'], 'otro transportista ve solo el suyo');
    $e = esperarError(fn () => $servicio->misViajes(transportista(), 'CANCELADO'), 422, 'datos_invalidos');
    afirmar(isset($e->campos['estado']), 'marca el campo estado');
});

prueba('mis viajes: llegada estimada = salida + horas de la ruta (15:00 + 4 h = 19:00); solo en ruta', function () {
    [, , $servicio] = escenarioViajes();
    $v = array_column($servicio->misViajes(transportista())['viajes'], null, 'codigo');
    afirmar($v['VJ2026101402']['llegadaEstimada'] === '2026-10-14 19:00:00', 'en ruta');
    afirmar($v['VJ2026103101']['llegadaEstimada'] === null && $v['VJ2026101001']['llegadaEstimada'] === null, 'programado y terminado: sin estimada');
    afirmar($v['VJ2026101402']['vehiculo'] === ['placa' => 'B7K-210', 'tipo' => 'FURGON', 'capacidadKg' => '6500.00'] && $v['VJ2026101402']['pesoCargadoKg'] === '18.50', 'vehículo y peso (18.50 de 6500 kg)');
});

// ── Detalle del viaje ───────────────────────────────────────────────────────
prueba('detalle: el transportista ve su viaje con la carga (diapositiva 25) y sin documentos ni teléfonos', function () {
    [, , $servicio] = escenarioViajes();
    $d = $servicio->detalle(transportista(), 'vj2026101402');
    afirmar($d['estado'] === 'EN_RUTA' && $d['origen']['nombre'] === 'Agencia Moquegua' && $d['destino']['nombre'] === 'Agencia Arequipa Centro', 'ruta y estado');
    afirmar($d['carga'] === [['pesoKg' => '18.50', 'codigoSeguimiento' => 'ENZ5H0D3V7NJ', 'bultos' => 1, 'destinoFinal' => ['codigo' => 'AQP001', 'nombre' => 'Agencia Arequipa Centro'], 'cargadoEn' => '2026-10-31 09:00:00']], 'carga: ' . json_encode($d['carga']));
    $json = json_encode($d);
    foreach (['numeroDocumento', 'telefono', '987654'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "no debe exponer $prohibido");
    }
});

prueba('detalle: ajeno e inexistente dan el mismo 404; el administrador ve los de su origen y destino, no los de otra agencia', function () {
    [, , $servicio] = escenarioViajes();
    $ajeno = esperarError(fn () => $servicio->detalle(transportista(6), 'VJ2026101402'), 404, 'viaje_no_encontrado');
    $inexistente = esperarError(fn () => $servicio->detalle(transportista(), 'VJ2026109999'), 404, 'viaje_no_encontrado');
    esperarError(fn () => $servicio->detalle(transportista(), "VJ'; DROP"), 404, 'viaje_no_encontrado');
    afirmar($ajeno->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
    afirmar($servicio->detalle(personal(), 'VJ2026101402')['codigo'] === 'VJ2026101402', 'origen MOQ001');
    afirmar($servicio->detalle(personal(2, 'AQP001'), 'VJ2026101402')['codigo'] === 'VJ2026101402', 'destino AQP001');
    esperarError(fn () => $servicio->detalle(personal(1, 'LIM001'), 'VJ2026101402'), 404, 'viaje_no_encontrado');
});

// ── Salida y llegada ────────────────────────────────────────────────────────
prueba('salida: queda EN_RUTA con la hora de la base y los envíos REGISTRADO pasan a EN_TRANSITO', function () {
    [$r, $despacho, $servicio] = escenarioViajes();
    liberarAPedro($r, $servicio);
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']);
    $d = $servicio->marcarSalida(transportista(), 'VJ2026103101');
    afirmar($d['estado'] === 'EN_RUTA' && $d['fechaHoraSalida'] === '2026-10-31 10:00:00' && $d['llegadaEstimada'] === '2026-10-31 14:00:00', 'estado, hora y estimada');
    afirmar($r->envios['EN4T7Q0P2LKX']['estado'] === 'EN_TRANSITO', 'el envío salió');
    esperarError(fn () => $servicio->marcarSalida(transportista(), 'VJ2026103101'), 409, 'viaje_ya_salio');
});

prueba('salida: un viaje ajeno o inexistente → 404 sin escribir; el administrador no puede marcarla', function () {
    [$r, , $servicio] = escenarioViajes();
    esperarError(fn () => $servicio->marcarSalida(transportista(6), 'VJ2026103101'), 404, 'viaje_no_encontrado');
    esperarError(fn () => $servicio->marcarSalida(transportista(), 'VJ2026109999'), 404, 'viaje_no_encontrado');
    esperarError(fn () => $servicio->marcarSalida(personal(), 'VJ2026103101'), 404, 'viaje_no_encontrado');
    afirmar($r->viajes['VJ2026103101']['salida'] === null, 'no escribió');
});

prueba('salida: un vehículo con otro viaje en ruta está ocupado (409) y no cambia nada; al terminar el otro, sale', function () {
    [$r, $despacho, $servicio] = escenarioViajes();
    $despacho->cargar(personal(), 'VJ2026103102', ['codigoSeguimiento' => 'ENQ8W2E4R6TY']); // B7K-210 está en ruta en VJ2026101402
    esperarError(fn () => $servicio->marcarSalida(transportista(), 'VJ2026103102'), 409, 'vehiculo_ocupado');
    afirmar($r->viajes['VJ2026103102']['salida'] === null && $r->envios['ENQ8W2E4R6TY']['estado'] === 'REGISTRADO' && $r->transaccionesDeshechas === 1, 'sin cambios y transacción deshecha');
    $r->ahora = '2026-10-31 18:00:00';
    $servicio->marcarLlegada(transportista(), 'VJ2026101402');
    $r->ahora = '2026-10-31 18:05:00';
    afirmar($servicio->marcarSalida(transportista(), 'VJ2026103102')['estado'] === 'EN_RUTA', 'ya sale');
});

prueba('salida: un transportista no puede tener dos viajes en ruta (vehículos distintos, mismo conductor) → 409 y sin cambios', function () {
    [$r, $despacho, $servicio] = escenarioViajes();
    // Pedro (id 5) ya conduce VJ2026101402 (B7K-210). VJ2026103101 usa otra camioneta (C3M-956): el vehículo está libre.
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']);
    $e = esperarError(fn () => $servicio->marcarSalida(transportista(), 'VJ2026103101'), 409, 'transportista_ocupado');
    afirmar(str_contains($e->getMessage(), 'llegada'), 'dice qué hacer: ' . $e->getMessage());
    afirmar($r->viajes['VJ2026103101']['salida'] === null && $r->envios['EN4T7Q0P2LKX']['estado'] === 'REGISTRADO' && $r->transaccionesDeshechas === 1, 'sin cambios y transacción deshecha');
    afirmar($servicio->marcarSalida(transportista(6), 'VJ2026103103')['estado'] === 'EN_RUTA', 'otro conductor sí sale (su vehículo y él están libres)');
    $r->ahora = '2026-10-31 18:00:00';
    $servicio->marcarLlegada(transportista(), 'VJ2026101402');
    $r->ahora = '2026-10-31 18:05:00';
    afirmar($servicio->marcarSalida(transportista(), 'VJ2026103101')['estado'] === 'EN_RUTA', 'al terminar su viaje en ruta, ya puede salir');
});

prueba('salida concurrente: dos viajes del MISMO conductor con vehículos DISTINTOS — gana uno solo y el otro ve su salida (409)', function () {
    [$r, , $servicio] = escenarioViajes();
    $r->viaje('VJ2026103201', 'C3M-956', 'MOQ001', 'AQP001', 6); // Juan (id 6): dos viajes con vehículos distintos
    $r->viaje('VJ2026103202', 'V2A-845', 'MOQ001', 'ILO001', 6);
    // La primera salida está a punto de confirmar cuando se evalúa la segunda: el gancho la marca justo antes de la decisión.
    $r->antesDeDecidir = function (ViajesFalso $falso): void { $falso->viajes['VJ2026103201']['salida'] = '2026-10-31 09:59:59'; };
    esperarError(fn () => $servicio->marcarSalida(transportista(6), 'VJ2026103202'), 409, 'transportista_ocupado');
    afirmar($r->viajes['VJ2026103201']['salida'] === '2026-10-31 09:59:59' && $r->viajes['VJ2026103202']['salida'] === null, 'queda un solo viaje en ruta del conductor');
    $enRuta = array_filter($r->viajes, static fn (array $v): bool => $v['transportista'] === 6 && $v['salida'] !== null && $v['llegada'] === null);
    afirmar(count($enRuta) === 1 && $r->transaccionesDeshechas === 1, 'como máximo uno EN_RUTA por transportista');
});

prueba('llegada: TERMINADO; los envíos cuyo destino final es esa agencia pasan a EN_DESTINO y los de transbordo siguen EN_TRANSITO', function () {
    [$r, $despacho, $servicio] = escenarioViajes();
    liberarAPedro($r, $servicio);
    $r->envio('ENTRANSBORDO', 'MOQ001', 'LIM001', 'REGISTRADO', '2.00');
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']);
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENTRANSBORDO']);
    $servicio->marcarSalida(transportista(), 'VJ2026103101');
    $r->ahora = '2026-10-31 14:00:00';
    $d = $servicio->marcarLlegada(transportista(), 'VJ2026103101');
    afirmar($d['estado'] === 'TERMINADO' && $d['fechaHoraLlegada'] === '2026-10-31 14:00:00' && $d['llegadaEstimada'] === null, 'terminado');
    afirmar($r->envios['EN4T7Q0P2LKX']['estado'] === 'EN_DESTINO', 'destino final: EN_DESTINO');
    afirmar($r->envios['ENTRANSBORDO']['estado'] === 'EN_TRANSITO', 'transbordo: sigue EN_TRANSITO');
    esperarError(fn () => $servicio->marcarLlegada(transportista(), 'VJ2026103101'), 409, 'viaje_ya_llego');
});

prueba('llegada: sin salida → 409; ajeno → 404; si pasó menos de un segundo desde la salida → 409 llegada_muy_pronto', function () {
    [$r, , $servicio] = escenarioViajes();
    liberarAPedro($r, $servicio);
    esperarError(fn () => $servicio->marcarLlegada(transportista(), 'VJ2026103101'), 409, 'viaje_no_salio');
    esperarError(fn () => $servicio->marcarLlegada(transportista(6), 'VJ2026101402'), 404, 'viaje_no_encontrado');
    $servicio->marcarSalida(transportista(), 'VJ2026103101');
    esperarError(fn () => $servicio->marcarLlegada(transportista(), 'VJ2026103101'), 409, 'llegada_muy_pronto'); // mismo instante
    afirmar($r->viajes['VJ2026103101']['llegada'] === null, 'no escribió');
});

prueba('salida: si otra operación se adelanta entre la lectura y el bloqueo, el segundo ve la salida ya registrada (409)', function () {
    [$r, , $servicio] = escenarioViajes();
    $r->antesDeDecidir = function (ViajesFalso $falso): void { $falso->viajes['VJ2026103101']['salida'] = '2026-10-31 09:59:00'; };
    esperarError(fn () => $servicio->marcarSalida(transportista(), 'VJ2026103101'), 409, 'viaje_ya_salio');
    afirmar($r->viajes['VJ2026103101']['salida'] === '2026-10-31 09:59:00', 'se conserva la salida del primero');
});

// ── Despacho: lista, espera y programar ─────────────────────────────────────
prueba('despacho: salen de la agencia (diapositiva 21): los de otra agencia no aparecen y el estado se deduce de las fechas', function () {
    [$r, $despacho] = escenarioViajes();
    $r->viaje('VJ2026102001', 'V2A-845', 'AQP001', 'LIM001', 5);
    $s = $despacho->salidas(personal());
    afirmar($s['sucursal']['codigo'] === 'MOQ001' && $s['hayMasTerminados'] === false, 'sucursal');
    afirmar(array_column($s['viajes'], 'codigo') === ['VJ2026101402', 'VJ2026103101', 'VJ2026103102', 'VJ2026103103', 'VJ2026101001'], 'orden: ' . implode(',', array_column($s['viajes'], 'codigo')));
    afirmar(array_column($despacho->salidas(personal(2, 'AQP001'))['viajes'], 'codigo') === ['VJ2026102001'], 'la otra agencia ve los suyos');
});

prueba('despacho: solo se muestran los 20 terminados más recientes y se avisa de que hay más', function () {
    [$r, $despacho] = escenarioViajes();
    for ($i = 1; $i <= 22; $i++) {
        $r->viaje(sprintf('VJ20260901%02d', $i), 'C3M-956', 'MOQ001', 'AQP001', 5, sprintf('2026-09-%02d 08:00:00', $i), sprintf('2026-09-%02d 12:00:00', $i));
    }
    $s = $despacho->salidas(personal());
    $terminados = array_filter($s['viajes'], static fn (array $v): bool => $v['estado'] === 'TERMINADO');
    afirmar(count($terminados) === 20 && $s['hayMasTerminados'] === true, 'tope 20: ' . count($terminados));
    afirmar(count($s['viajes']) === 4 + 20, 'los activos se muestran todos');
});

prueba('envíos en espera: solo los de la agencia sin carga, con el viaje programado hacia su destino', function () {
    [, $despacho] = escenarioViajes();
    $e = $despacho->enEspera(personal());
    $porCodigo = array_column($e['envios'], null, 'codigoSeguimiento');
    afirmar(array_keys($porCodigo) === ['EN4T7Q0P2LKX', 'ENQ8W2E4R6TY', 'ENA1S3D5F7GH'], 'esperan: ' . implode(',', array_keys($porCodigo)));
    afirmar($porCodigo['EN4T7Q0P2LKX']['bultos'] === 2 && $porCodigo['EN4T7Q0P2LKX']['pesoKg'] === '9.20' && $porCodigo['EN4T7Q0P2LKX']['destino']['codigo'] === 'AQP001', '2 bultos · 9.20 kg');
    afirmar($porCodigo['EN4T7Q0P2LKX']['viajeSugerido'] === 'VJ2026103101' && $porCodigo['ENA1S3D5F7GH']['viajeSugerido'] === 'VJ2026103103', 'viaje sugerido por destino');
    afirmar(!isset($porCodigo['ENLIMA000001']) && !isset($porCodigo['ENZ5H0D3V7NJ']), 'no aparecen el de otra agencia ni el que va en ruta');
    afirmar($despacho->enEspera(personal(1, 'LIM001'))['envios'][0]['codigoSeguimiento'] === 'ENLIMA000001', 'cada agencia ve los suyos');
});

prueba('programar viaje: queda PROGRAMADO con código VJ + fecha + número del día, y el vehículo ocupado se puede programar', function () {
    [$r, $despacho] = escenarioViajes();
    $v = $despacho->programar(personal(), ['placa' => 'b7k-210', 'transportista' => 'pmamani', 'destino' => 'ilo001']); // B7K-210 está en ruta
    afirmar($v['codigo'] === 'VJ2026103101' || preg_match('/^VJ20261031\d{2}$/', $v['codigo']) === 1, 'formato: ' . $v['codigo']);
    afirmar($v['estado'] === 'PROGRAMADO' && $v['origen']['codigo'] === 'MOQ001' && $v['destino']['codigo'] === 'ILO001' && $v['fechaHoraSalida'] === null && $v['carga'] === [], 'estado, origen de la sesión y sin carga');
    $otro = $despacho->programar(personal(), ['placa' => 'C3M-956', 'transportista' => 'jquispe', 'destino' => 'AQP001']);
    afirmar($otro['codigo'] !== $v['codigo'] && preg_match('/^VJ20261031\d{2}$/', $otro['codigo']) === 1, 'los códigos del día no se repiten');
});

prueba('programar viaje: valida todos los campos a la vez (422) y no crea nada; el origen es siempre la agencia de la sesión', function () {
    [$r, $despacho] = escenarioViajes();
    $antes = count($r->viajes);
    $e = esperarError(fn () => $despacho->programar(personal(), ['placa' => 'XX', 'transportista' => 'a', 'destino' => 'MOQ001']), 422, 'datos_invalidos');
    $campos = array_keys($e->campos);
    sort($campos);
    afirmar($campos === ['destino', 'placa', 'transportista'], 'campos: ' . implode(',', $campos));
    $e = esperarError(fn () => $despacho->programar(personal(), ['placa' => 'ZZZ-999', 'transportista' => 'nadie123', 'destino' => 'TAC001']), 422, 'datos_invalidos');
    afirmar(count($e->campos) === 3, 'inexistentes: ' . json_encode($e->campos));
    foreach ([[], ['placa' => ['C3M-956']], ['placa' => 'C3M-956', 'transportista' => 5, 'destino' => null]] as $cuerpo) {
        esperarError(fn () => $despacho->programar(personal(), $cuerpo), 422, 'datos_invalidos');
    }
    $v = $despacho->programar(personal(), ['placa' => 'C3M-956', 'transportista' => 'pmamani', 'destino' => 'AQP001', 'origen' => 'LIM001', 'sucursalOrigen' => 'LIM001']);
    afirmar($v['origen']['codigo'] === 'MOQ001' && count($r->viajes) === $antes + 1, 'el cuerpo no fija el origen');
});

prueba('opciones para programar: vehículos con ocupado, transportistas y destinos sin la propia agencia', function () {
    [, $despacho] = escenarioViajes();
    $o = $despacho->opciones(personal());
    $v = array_column($o['vehiculos'], null, 'placa');
    afirmar($v['B7K-210']['ocupado'] === true && $v['C3M-956']['ocupado'] === false && $v['C3M-956']['capacidadKg'] === '1500.00', 'ocupado y capacidad');
    afirmar(array_column($o['destinos'], 'codigo') === ['AQP001', 'ILO001', 'LIM001'] || count($o['destinos']) === 3, 'destinos');
    afirmar(!in_array('MOQ001', array_column($o['destinos'], 'codigo'), true), 'sin la propia agencia');
    afirmar(count($o['transportistas']) === 2 && $o['transportistas'][0]['sucursal']['codigo'] !== '', 'transportistas');
});

// ── Despacho: cargar y quitar ───────────────────────────────────────────────
prueba('cargar: el envío entra al viaje (9.20 de 1500 kg) y deja de estar en espera; el estado del envío aún no cambia', function () {
    [$r, $despacho] = escenarioViajes();
    $v = $despacho->cargar(personal(), 'vj2026103101', ['codigoSeguimiento' => 'en4t7q0p2lkx']);
    afirmar($v['cantidadEnvios'] === 1 && $v['pesoCargadoKg'] === '9.20' && $v['vehiculo']['capacidadKg'] === '1500.00' && $v['carga'][0]['codigoSeguimiento'] === 'EN4T7Q0P2LKX', 'carga');
    afirmar(!in_array('EN4T7Q0P2LKX', array_column($despacho->enEspera(personal())['envios'], 'codigoSeguimiento'), true), 'ya no espera');
    afirmar($r->envios['EN4T7Q0P2LKX']['estado'] === 'REGISTRADO', 'sigue REGISTRADO hasta la salida');
});

prueba('cargar: capacidad del vehículo — 25 kg caben en 1500 kg, pero no sobrepasarla (409 con las cifras)', function () {
    [$r, $despacho] = escenarioViajes();
    $r->envio('ENPESADO0001', 'MOQ001', 'AQP001', 'REGISTRADO', '1495.00');
    $r->envio('ENPESADO0002', 'MOQ001', 'AQP001', 'REGISTRADO', '10.00');
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENPESADO0001']);
    $e = esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENPESADO0002']), 409, 'capacidad_excedida');
    afirmar(str_contains($e->getMessage(), '1505.00') && str_contains($e->getMessage(), '1500.00'), 'dice las cifras: ' . $e->getMessage());
    afirmar(count(array_filter($r->cargas, static fn (array $c): bool => $c['viaje'] === 'VJ2026103101')) === 1 && $r->transaccionesDeshechas === 1, 'el rechazado no se cargó y se deshizo la transacción');
    $r->envios['ENPESADO0002']['pesoKg'] = '5.00';
    afirmar($despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENPESADO0002'])['pesoCargadoKg'] === '1500.00', 'justo en la capacidad sí cabe');
});

prueba('cargar: un envío de otra agencia o inexistente da el mismo 404; uno ya cargado, 409; y no se carga en viajes ajenos ni que ya salieron', function () {
    [$r, $despacho] = escenarioViajes();
    $otra = esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENLIMA000001']), 404, 'envio_no_encontrado');
    $inexistente = esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENZZZZZZZZZZ']), 404, 'envio_no_encontrado');
    afirmar($otra->getMessage() === $inexistente->getMessage(), 'mismo mensaje');
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENZ5H0D3V7NJ']), 409, 'envio_ya_cargado'); // ya va en un viaje de la agencia que no llegó
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']);
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103102', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']), 409, 'envio_ya_cargado');
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026101402', ['codigoSeguimiento' => 'ENQ8W2E4R6TY']), 409, 'viaje_no_programado');
    esperarError(fn () => $despacho->cargar(personal(2, 'AQP001'), 'VJ2026103101', ['codigoSeguimiento' => 'ENQ8W2E4R6TY']), 404, 'viaje_no_encontrado');
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026109999', ['codigoSeguimiento' => 'ENQ8W2E4R6TY']), 404, 'viaje_no_encontrado');
    afirmar($r->transaccionesDeshechas >= 4, 'los rechazos dentro de la transacción la deshacen');
});

prueba('cargar: tipos raros en el cuerpo → 422 (nunca un error interno); el código del cuerpo se normaliza', function () {
    [, $despacho] = escenarioViajes();
    foreach ([[], ['codigoSeguimiento' => ['EN4T7Q0P2LKX']], ['codigoSeguimiento' => null], ['codigoSeguimiento' => "EN'; DROP TABLE Viaje;--"], ['codigoSeguimiento' => 'XX']] as $cuerpo) {
        $e = esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', $cuerpo), 422, 'datos_invalidos');
        afirmar(isset($e->campos['codigoSeguimiento']), 'marca el campo');
    }
});

prueba('cargar: dos cajas a la vez — la que llega segunda ve el envío ya cargado y no se duplica', function () {
    [$r, $despacho] = escenarioViajes();
    $r->antesDeDecidir = function (ViajesFalso $falso): void { $falso->cargarDirecto('VJ2026103102', 'EN4T7Q0P2LKX'); };
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']), 409, 'envio_ya_cargado');
    afirmar(count(array_filter($r->cargas, static fn (array $c): bool => $c['envio'] === 'EN4T7Q0P2LKX')) === 1, 'una sola carga');
});

prueba('transbordo: tras llegar a AQP001, el envío espera allí y se carga en un viaje hacia su destino final; no vuelve a donde ya estuvo', function () {
    [$r, $despacho, $servicio] = escenarioViajes();
    liberarAPedro($r, $servicio);
    $r->envio('ENTRANSBORDO', 'MOQ001', 'LIM001', 'REGISTRADO', '2.00');
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'ENTRANSBORDO']);
    $servicio->marcarSalida(transportista(), 'VJ2026103101');
    afirmar(!in_array('ENTRANSBORDO', array_column($despacho->enEspera(personal(2, 'AQP001'))['envios'], 'codigoSeguimiento'), true), 'en ruta: todavía no espera en AQP001');
    $r->ahora = '2026-10-31 14:00:00';
    $servicio->marcarLlegada(transportista(), 'VJ2026103101');
    $espera = array_column($despacho->enEspera(personal(2, 'AQP001'))['envios'], null, 'codigoSeguimiento');
    afirmar(isset($espera['ENTRANSBORDO']) && $espera['ENTRANSBORDO']['estado'] === 'EN_TRANSITO' && $espera['ENTRANSBORDO']['destino']['codigo'] === 'LIM001', 'espera en AQP001 con destino final LIM001');
    afirmar(!in_array('ENTRANSBORDO', array_column($despacho->enEspera(personal())['envios'], 'codigoSeguimiento'), true), 'ya no espera en MOQ001');
    $r->viaje('VJ2026110101', 'V2A-845', 'AQP001', 'LIM001', 5);
    $r->viaje('VJ2026110102', 'V2A-845', 'AQP001', 'MOQ001', 5); // volver al origen
    esperarError(fn () => $despacho->cargar(personal(2, 'AQP001'), 'VJ2026110102', ['codigoSeguimiento' => 'ENTRANSBORDO']), 409, 'destino_ya_visitado');
    esperarError(fn () => $despacho->cargar(personal(), 'VJ2026103102', ['codigoSeguimiento' => 'ENTRANSBORDO']), 404, 'envio_no_encontrado'); // MOQ001 ya no lo tiene
    $v = $despacho->cargar(personal(2, 'AQP001'), 'VJ2026110101', ['codigoSeguimiento' => 'ENTRANSBORDO']);
    afirmar($v['cantidadEnvios'] === 1 && $v['destino']['codigo'] === 'LIM001', 'cargado en el viaje AQP→LIM');
});

prueba('quitar: un viaje programado devuelve el envío a la espera; uno que ya salió, ajeno o sin ese envío, no', function () {
    [$r, $despacho] = escenarioViajes();
    $despacho->cargar(personal(), 'VJ2026103101', ['codigoSeguimiento' => 'EN4T7Q0P2LKX']);
    $v = $despacho->quitar(personal(), 'VJ2026103101', 'en4t7q0p2lkx');
    afirmar($v['cantidadEnvios'] === 0 && $v['pesoCargadoKg'] === '0.00', 'sin carga');
    afirmar(in_array('EN4T7Q0P2LKX', array_column($despacho->enEspera(personal())['envios'], 'codigoSeguimiento'), true), 'vuelve a esperar');
    esperarError(fn () => $despacho->quitar(personal(), 'VJ2026103101', 'EN4T7Q0P2LKX'), 404, 'envio_no_encontrado');
    esperarError(fn () => $despacho->quitar(personal(), 'VJ2026101402', 'ENZ5H0D3V7NJ'), 409, 'viaje_no_programado');
    esperarError(fn () => $despacho->quitar(personal(2, 'AQP001'), 'VJ2026103101', 'EN4T7Q0P2LKX'), 404, 'viaje_no_encontrado');
    esperarError(fn () => $despacho->quitar(personal(), 'VJ2026109999', 'EN4T7Q0P2LKX'), 404, 'viaje_no_encontrado');
    esperarError(fn () => $despacho->quitar(personal(), 'VJ2026103101', "x'; DROP"), 404, 'envio_no_encontrado');
    afirmar(count(array_filter($r->cargas, static fn (array $c): bool => $c['viaje'] === 'VJ2026101402')) === 1, 'la carga del viaje en ruta sigue intacta');
});

// ── HTTP: permisos, controladores y rutas ───────────────────────────────────
prueba('permisos de viajes y despacho: despacho solo ADMINISTRADOR; mis viajes, salida y llegada solo TRANSPORTISTA; detalle ambos; sin sesión 401', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3),
        registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE), registro(6, 'transp1', 'ClaveTransp#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    $admin = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicioAuth));
    $transp = new RequiereRol([Roles::TRANSPORTISTA], new RequiereSesion($servicioAuth));
    $ambos = new RequiereRol([Roles::TRANSPORTISTA, Roles::ADMINISTRADOR], new RequiereSesion($servicioAuth));
    foreach ([$admin, $transp, $ambos] as $g) {
        esperarError(fn () => $g(peticion('GET', '/x')), 401, 'no_autenticado');
    }
    foreach ([1, 4, 6] as $id) { // gerente, cliente y transportista no despachan
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $admin(peticion('GET', '/api/despacho/viajes')), 403, 'acceso_denegado');
    }
    foreach ([1, 2, 4] as $id) { // gerente, administrador y cliente no usan «Mis viajes»
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $transp(peticion('GET', '/api/viajes')), 403, 'acceso_denegado');
    }
    foreach ([1, 4] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $ambos(peticion('GET', '/api/viajes/VJ2026101402')), 403, 'acceso_denegado');
    }
    foreach ([2, 6] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        $ambos(peticion('GET', '/api/viajes/VJ2026101402'));
    }
    $limpiar();
});

prueba('controladores: usan el usuario y la sucursal de la sesión, no los del cuerpo', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(5, 'pmamani', 'ClavePedro#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    [$r, $despacho, $servicio] = escenarioViajes();
    $d = new DespachoController($despacho);
    $v = new ViajeController($servicio);

    sesionDe($auth, $servicioAuth, 2);
    $res = leer($d->salidas(peticion('GET', '/x'), []));
    afirmar($res['estado'] === 200 && $res['cuerpo']['data']['sucursal']['codigo'] === 'MOQ001' && count($res['cuerpo']['data']['viajes']) === 5, 'salidas de la agencia');
    $res = leer($d->programar(peticion('POST', '/x', json_encode(['placa' => 'C3M-956', 'transportista' => 'pmamani', 'destino' => 'AQP001', 'sucursalOrigen' => 'LIM001'])), []));
    afirmar($res['estado'] === 201 && $res['cuerpo']['data']['viaje']['origen']['codigo'] === 'MOQ001', 'programa con 201 y origen de la sesión');
    $nuevo = $res['cuerpo']['data']['viaje']['codigo'];
    $res = leer($d->cargar(peticion('POST', '/x', json_encode(['codigoSeguimiento' => 'EN4T7Q0P2LKX'])), ['codigo' => $nuevo]));
    afirmar($res['estado'] === 201 && $res['cuerpo']['data']['viaje']['cantidadEnvios'] === 1, 'carga con 201');
    $res = leer($d->quitar(peticion('DELETE', '/x'), ['codigo' => $nuevo, 'seguimiento' => 'EN4T7Q0P2LKX']));
    afirmar($res['estado'] === 200 && $res['cuerpo']['data']['viaje']['cantidadEnvios'] === 0, 'quita');
    afirmar(leer($d->enEspera(peticion('GET', '/x'), []))['cuerpo']['data']['envios'] !== [], 'en espera');
    afirmar(count(leer($d->opciones(peticion('GET', '/x'), []))['cuerpo']['data']['vehiculos']) === 3, 'opciones');
    esperarError(fn () => $d->programar(peticion('POST', '/x', '{roto'), []), 400, 'json_invalido');

    $limpiar();
    sesionDe($auth, $servicioAuth, 5);
    $res = leer($v->misViajes(new Jatsa\Core\Request('GET', '/x', ['estado' => 'EN_RUTA'], '', []), []));
    afirmar($res['estado'] === 200 && array_column($res['cuerpo']['data']['viajes'], 'codigo') === ['VJ2026101402'], 'mis viajes con filtro');
    liberarAPedro($r, $servicio); // un conductor no sale de otro viaje mientras tenga uno en ruta
    $res = leer($v->marcarSalida(peticion('POST', '/x'), ['codigo' => 'VJ2026103101']));
    afirmar($res['estado'] === 200 && $res['cuerpo']['data']['viaje']['estado'] === 'EN_RUTA' || $res['estado'] === 200, 'salida');
    $limpiar();
    esperarError(fn () => $v->misViajes(peticion('GET', '/x'), []), 401, 'no_autenticado');
    esperarError(fn () => $d->salidas(peticion('GET', '/x'), []), 401, 'no_autenticado');
});

prueba('rutas de viajes y despacho: todas exigen sesión y no hay forma de borrar un viaje', function () use ($limpiar) {
    $limpiar();
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Despacho/rutas.php')($router);
    (require dirname(__DIR__) . '/src/Modules/Viajes/rutas.php')($router);
    foreach ([['GET', '/api/despacho/viajes'], ['POST', '/api/despacho/viajes'], ['POST', '/api/despacho/viajes/VJ2026103101/carga'], ['DELETE', '/api/despacho/viajes/VJ2026103101/carga/EN4T7Q0P2LKX'],
              ['GET', '/api/despacho/envios-en-espera'], ['GET', '/api/despacho/opciones'], ['GET', '/api/viajes'], ['GET', '/api/viajes/VJ2026103101'],
              ['POST', '/api/viajes/VJ2026103101/salida'], ['POST', '/api/viajes/VJ2026103101/llegada']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    foreach ([['DELETE', '/api/despacho/viajes/VJ2026103101'], ['DELETE', '/api/viajes/VJ2026103101'], ['PUT', '/api/despacho/viajes'], ['GET', '/api/viajes/VJ2026103101/salida']] as [$metodo, $ruta]) {
        $e = null;
        try {
            $router->despachar(peticion($metodo, $ruta));
        } catch (HttpException $e) {
        }
        afirmar($e !== null && in_array($e->estado, [404, 405], true), "$metodo $ruta no debe existir");
    }
});
