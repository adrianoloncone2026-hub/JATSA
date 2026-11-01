<?php
declare(strict_types=1);

namespace Jatsa\Modules\Catalogos;

use Closure;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;
use Jatsa\Shared\Validacion;

/**
 * Catálogos del GERENTE (diapositiva 29 del prototipo): sucursales, rutas con precios y vigencia, y vehículos.
 *
 * Nada se borra: las sucursales y los vehículos se desactivan, y las tarifas se cierran y se reemplazan por una fila nueva («Nuevo
 * precio: se cierra la vigencia de la fila actual y se crea otra. Los bultos ya cobrados conservan su tarifa»).
 */
final class CatalogoService
{
    public const TIPOS_ENVIO = ['SOBRE', 'PAQUETE', 'CARGA'];
    public const TIPOS_VEHICULO = ['CAMION', 'FURGON', 'CAMIONETA', 'MOTO'];
    public const MAX_TELEFONOS = 5;

    private readonly RepositorioCatalogo $catalogo;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD); se inyecta para probar. */
    public function __construct(?RepositorioCatalogo $catalogo = null, ?callable $hoy = null)
    {
        $this->catalogo = $catalogo ?? new CatalogoRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    // ── Sucursales ──────────────────────────────────────────────────────────

    /** @return array{sucursales: list<array<string, mixed>>} */
    public function sucursales(): array
    {
        return ['sucursales' => array_map([self::class, 'presentarSucursal'], $this->catalogo->sucursales())];
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public function crearSucursal(array $datos): array
    {
        $errores = [];
        $codigo = strtoupper(Validacion::texto($datos, 'codigoSucursal'));
        if (preg_match('/^[A-Z]{3}\d{3}$/', $codigo) !== 1) {
            $errores['codigoSucursal'] = 'El código son tres letras y tres dígitos. Por ejemplo: AQP001.';
        }
        $campos = $this->validarSucursal($datos, $errores);
        $this->exigirValidos($errores);

        $this->catalogo->crearSucursal(['codigoSucursal' => $codigo] + $campos['datos'], $campos['telefonos']);

        return self::presentarSucursal($this->sucursalDe($codigo));
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    public function actualizarSucursal(string $codigo, array $datos): array
    {
        $codigo = $this->codigoSucursal($codigo);
        $errores = [];
        $campos = $this->validarSucursal($datos, $errores);
        $this->exigirValidos($errores);

        if (!$this->catalogo->actualizarSucursal($codigo, $campos['datos'], $campos['telefonos'])) {
            throw self::sucursalNoEncontrada();
        }

        return self::presentarSucursal($this->sucursalDe($codigo));
    }

    /**
     * @param array<string, mixed> $datos activa: bool
     * @return array<string, mixed>
     */
    public function cambiarEstadoSucursal(string $codigo, array $datos): array
    {
        $codigo = $this->codigoSucursal($codigo);
        $activa = Validacion::booleano($datos['activa'] ?? null) ?? throw self::datosInvalidos(['activa' => 'Indica true o false.']);

        $resultado = $this->catalogo->cambiarEstadoSucursal($codigo, $activa, static function (array $ctx) use ($activa): void {
            if ($activa) {
                return; // reactivar siempre se puede
            }
            if ($ctx['personalActivo'] > 0) {
                throw new HttpException(409, 'sucursal_con_personal', sprintf('No se puede desactivar: tiene %d cuenta(s) de personal activa(s). Desactívalas o muévelas a otra sucursal primero.', $ctx['personalActivo']));
            }
            if ($ctx['viajesAbiertos'] > 0) {
                throw new HttpException(409, 'sucursal_con_viajes', sprintf('No se puede desactivar: tiene %d viaje(s) programado(s) o en ruta.', $ctx['viajesAbiertos']));
            }
            if ($ctx['enviosEnCurso'] > 0) {
                throw new HttpException(409, 'sucursal_con_envios', sprintf('No se puede desactivar: tiene %d envío(s) en curso.', $ctx['enviosEnCurso']));
            }
        });
        if ($resultado === null) {
            throw self::sucursalNoEncontrada();
        }

        return self::presentarSucursal($this->sucursalDe($codigo));
    }

    // ── Rutas y precios ─────────────────────────────────────────────────────

    /** @return array{rutas: list<array<string, mixed>>} */
    public function rutas(): array
    {
        return ['rutas' => array_map([self::class, 'presentarRuta'], $this->catalogo->rutas())];
    }

    /**
     * «Nueva ruta»: origen, destino y tipo que todavía no tienen una tarifa vigente.
     *
     * @param array<string, mixed> $datos origen, destino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras
     * @return array<string, mixed>
     */
    public function crearRuta(array $datos): array
    {
        $errores = [];
        $origen = strtoupper(Validacion::texto($datos, 'origen'));
        $destino = strtoupper(Validacion::texto($datos, 'destino'));
        $tipo = strtoupper(Validacion::texto($datos, 'tipoEnvio'));
        if (preg_match('/^[A-Z]{3}\d{3}$/', $origen) !== 1) {
            $errores['origen'] = 'Elige la agencia de origen.';
        }
        if (preg_match('/^[A-Z]{3}\d{3}$/', $destino) !== 1) {
            $errores['destino'] = 'Elige la agencia de destino.';
        } elseif ($origen === $destino) {
            $errores['destino'] = 'El origen y el destino deben ser distintos.';
        }
        if (!in_array($tipo, self::TIPOS_ENVIO, true)) {
            $errores['tipoEnvio'] = 'Elige SOBRE, PAQUETE o CARGA.';
        }
        $precio = $this->validarPrecio($datos, $errores);
        $this->exigirValidos($errores);

        // Que las dos agencias existan y estén ACTIVAS, y que no haya ya una tarifa vigente, se decide DENTRO de la transacción del
        // repositorio con las filas bloqueadas: una desactivación simultánea no puede intercalarse entre la validación y el alta.
        $ruta = $this->catalogo->crearRuta($origen, $destino, ['tipoEnvio' => $tipo] + $precio, ($this->hoy)(), static function (array $ctx): void {
            $errores = [];
            if (!$ctx['origenActiva']) {
                $errores['origen'] = 'Elige una agencia activa.';
            }
            if (!$ctx['destinoActiva']) {
                $errores['destino'] = 'Elige una agencia activa.';
            }
            if ($errores !== []) {
                throw self::datosInvalidos($errores);
            }
            if ($ctx['vigentes'] > 0) {
                throw new HttpException(409, 'tarifa_vigente_existente', 'Esa ruta ya tiene una tarifa vigente para ese tipo. Usa «Nuevo precio» sobre la fila actual.');
            }
        });
        return self::presentarRuta($ruta);
    }

    /**
     * «Nuevo precio»: cierra la vigencia de la fila actual y crea otra desde hoy. Origen, destino y tipo no cambian.
     *
     * @param array<string, mixed> $datos precioBase, precioPorKm, distanciaKm, tiempoHoras
     * @return array<string, mixed> la fila nueva
     */
    public function nuevoPrecio(string $id, array $datos): array
    {
        $idRuta = preg_match('/^\d{1,9}$/', $id) === 1 ? (int) $id : 0;
        if ($idRuta < 1) {
            throw self::rutaNoEncontrada();
        }
        $errores = [];
        $precio = $this->validarPrecio($datos, $errores);
        $this->exigirValidos($errores);

        $hoy = ($this->hoy)();
        $nueva = $this->catalogo->nuevoPrecio($idRuta, $precio, $hoy, static function (array $actual) use ($hoy): void {
            if ($actual['vigenteHasta'] !== null) {
                throw new HttpException(409, 'tarifa_cerrada', 'Esa tarifa ya no está vigente. Cambia el precio de la fila vigente.');
            }
            if ($actual['vigenteDesde'] >= $hoy) {
                throw new HttpException(409, 'tarifa_cambiada_hoy', 'Esa tarifa entró en vigor hoy; el nuevo precio puede registrarse a partir de mañana.');
            }
        });
        if ($nueva === null) {
            throw self::rutaNoEncontrada();
        }

        return self::presentarRuta($nueva);
    }

    // ── Vehículos ───────────────────────────────────────────────────────────

    /** @return array{vehiculos: list<array<string, mixed>>} */
    public function vehiculos(): array
    {
        return ['vehiculos' => array_map([self::class, 'presentarVehiculo'], $this->catalogo->vehiculos())];
    }

    /**
     * @param array<string, mixed> $datos placa, tipoVehiculo, capacidadKg
     * @return array<string, mixed>
     */
    public function crearVehiculo(array $datos): array
    {
        $errores = [];
        $placa = strtoupper(Validacion::texto($datos, 'placa'));
        if (preg_match('/^[A-Z0-9]{3}-\d{3}$/', $placa) !== 1) {
            $errores['placa'] = 'La placa tiene el formato ABC-123.';
        }
        [$tipo, $capacidad] = $this->validarVehiculo($datos, $errores);
        $this->exigirValidos($errores);

        $this->catalogo->crearVehiculo($placa, (string) $tipo, (string) $capacidad);

        return self::presentarVehiculo($this->vehiculoDe($placa));
    }

    /**
     * La placa no cambia. No se baja la capacidad por debajo de lo ya cargado en sus viajes sin llegada.
     *
     * @param array<string, mixed> $datos tipoVehiculo, capacidadKg
     * @return array<string, mixed>
     */
    public function actualizarVehiculo(string $placa, array $datos): array
    {
        $placa = $this->placa($placa);
        $errores = [];
        [$tipo, $capacidad] = $this->validarVehiculo($datos, $errores);
        $this->exigirValidos($errores);

        $resultado = $this->catalogo->actualizarVehiculo($placa, (string) $tipo, (string) $capacidad, static function (array $ctx) use ($capacidad): void {
            if ((float) $capacidad < (float) $ctx['cargaMaximaKg']) {
                throw new HttpException(409, 'capacidad_menor_a_la_carga', sprintf('La capacidad no puede ser menor que la carga de sus viajes sin llegada (%s kg).', number_format((float) $ctx['cargaMaximaKg'], 2, '.', '')), [], ['capacidadKg' => 'Es menor que la carga ya asignada.']);
            }
        });
        if ($resultado === null) {
            throw self::vehiculoNoEncontrado();
        }

        return self::presentarVehiculo($this->vehiculoDe($placa));
    }

    /**
     * @param array<string, mixed> $datos activo: bool
     * @return array<string, mixed>
     */
    public function cambiarEstadoVehiculo(string $placa, array $datos): array
    {
        $placa = $this->placa($placa);
        $activo = Validacion::booleano($datos['activo'] ?? null) ?? throw self::datosInvalidos(['activo' => 'Indica true o false.']);

        $resultado = $this->catalogo->cambiarEstadoVehiculo($placa, $activo, static function (array $ctx) use ($activo): void {
            if (!$activo && $ctx['viajesAbiertos'] > 0) {
                throw new HttpException(409, 'vehiculo_con_viajes', sprintf('No se puede desactivar: tiene %d viaje(s) programado(s) o en ruta.', $ctx['viajesAbiertos']));
            }
        });
        if ($resultado === null) {
            throw self::vehiculoNoEncontrado();
        }

        return self::presentarVehiculo($this->vehiculoDe($placa));
    }

    // ── Validación ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     * @return array{datos: array<string, string>, telefonos: list<string>}
     */
    private function validarSucursal(array $datos, array &$errores): array
    {
        $limites = ['nombre' => [80, 'el nombre'], 'departamento' => [40, 'el departamento'], 'provincia' => [40, 'la provincia'], 'distrito' => [60, 'el distrito'], 'direccion' => [150, 'la dirección']];
        $campos = [];
        foreach ($limites as $campo => [$maximo, $etiqueta]) {
            $valor = Validacion::normalizarEspacios(Validacion::texto($datos, $campo));
            if ($valor === '') {
                $errores[$campo] = "Ingresa $etiqueta.";
            } elseif (!Validacion::largoMaximo($valor, $maximo)) {
                $errores[$campo] = "Puede tener hasta $maximo caracteres.";
            }
            $campos[$campo] = $valor;
        }

        $telefonos = [];
        $crudos = $datos['telefonos'] ?? [];
        if (!is_array($crudos) || count($crudos) > self::MAX_TELEFONOS) {
            $errores['telefonos'] = sprintf('Indica hasta %d teléfonos.', self::MAX_TELEFONOS);
        } else {
            foreach ($crudos as $crudo) {
                $numero = is_string($crudo) ? preg_replace('/[\s-]/', '', $crudo) : '';
                if (!is_string($numero) || preg_match('/^\d{9}$/', $numero) !== 1) {
                    $errores['telefonos'] = 'Cada teléfono tiene 9 dígitos.';
                    break;
                }
                if (in_array($numero, $telefonos, true)) {
                    $errores['telefonos'] = 'Hay un teléfono repetido.';
                    break;
                }
                $telefonos[] = $numero;
            }
        }

        return ['datos' => $campos, 'telefonos' => $telefonos];
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     * @return array{precioBase: string, precioPorKm: string, distanciaKm: string, tiempoHoras: int}
     */
    private function validarPrecio(array $datos, array &$errores): array
    {
        $base = Validacion::decimal($datos['precioBase'] ?? null, 6, 2);
        if ($base === null || (float) $base <= 0) {
            $errores['precioBase'] = 'El precio base es mayor que 0 (hasta 2 decimales).';
        }
        $porKm = Validacion::decimal($datos['precioPorKm'] ?? null, 3, 3);
        if ($porKm === null) {
            $errores['precioPorKm'] = 'El precio por km es 0 o más (hasta 3 decimales).';
        }
        $distancia = Validacion::decimal($datos['distanciaKm'] ?? null, 6, 1);
        if ($distancia === null || (float) $distancia <= 0) {
            $errores['distanciaKm'] = 'Los kilómetros son más de 0 (hasta 1 decimal).';
        }
        $horas = Validacion::entero($datos['tiempoHoras'] ?? null, 1, 32767);
        if ($horas === null) {
            $errores['tiempoHoras'] = 'Las horas son un entero de 1 en adelante.';
        }

        return ['precioBase' => (string) $base, 'precioPorKm' => (string) $porKm, 'distanciaKm' => (string) $distancia, 'tiempoHoras' => (int) $horas];
    }

    /**
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     * @return array{0: ?string, 1: ?string}
     */
    private function validarVehiculo(array $datos, array &$errores): array
    {
        $tipo = strtoupper(Validacion::texto($datos, 'tipoVehiculo'));
        if (!in_array($tipo, self::TIPOS_VEHICULO, true)) {
            $errores['tipoVehiculo'] = 'Elige CAMION, FURGON, CAMIONETA o MOTO.';
        }
        $capacidad = Validacion::decimal($datos['capacidadKg'] ?? null, 7, 2);
        if ($capacidad === null || (float) $capacidad <= 0) {
            $errores['capacidadKg'] = 'La capacidad es mayor que 0 kg (hasta 2 decimales).';
        }

        return [$tipo, $capacidad];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

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

    private function codigoSucursal(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        return preg_match('/^[A-Z]{3}\d{3}$/', $codigo) === 1 ? $codigo : throw self::sucursalNoEncontrada();
    }

    private function placa(string $placa): string
    {
        $placa = strtoupper(trim($placa));

        return preg_match('/^[A-Z0-9]{3}-\d{3}$/', $placa) === 1 ? $placa : throw self::vehiculoNoEncontrado();
    }

    /** @return array<string, mixed> */
    private function sucursalDe(string $codigo): array
    {
        foreach ($this->catalogo->sucursales() as $s) {
            if ($s['codigoSucursal'] === $codigo) {
                return $s;
            }
        }
        throw self::sucursalNoEncontrada();
    }

    /** @return array<string, mixed> */
    private function vehiculoDe(string $placa): array
    {
        foreach ($this->catalogo->vehiculos() as $v) {
            if ($v['placa'] === $placa) {
                return $v;
            }
        }
        throw self::vehiculoNoEncontrado();
    }

    private static function sucursalNoEncontrada(): HttpException
    {
        return new HttpException(404, 'sucursal_no_encontrada', 'No se encontró la sucursal.');
    }

    private static function rutaNoEncontrada(): HttpException
    {
        return new HttpException(404, 'ruta_no_encontrada', 'No se encontró la ruta.');
    }

    private static function vehiculoNoEncontrado(): HttpException
    {
        return new HttpException(404, 'vehiculo_no_encontrado', 'No se encontró el vehículo.');
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function presentarSucursal(array $s): array
    {
        return [
            'codigo' => $s['codigoSucursal'], 'nombre' => $s['nombre'], 'departamento' => $s['departamento'], 'provincia' => $s['provincia'],
            'distrito' => $s['distrito'], 'direccion' => $s['direccion'], 'telefonos' => $s['telefonos'], 'activa' => $s['activa'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function presentarRuta(array $r): array
    {
        return [
            'id' => $r['idRuta'],
            'origen' => ['codigo' => $r['origenCodigo'], 'nombre' => $r['origenNombre']],
            'destino' => ['codigo' => $r['destinoCodigo'], 'nombre' => $r['destinoNombre']],
            'tipoEnvio' => $r['tipoEnvio'],
            'precioBase' => number_format((float) $r['precioBase'], 2, '.', ''),
            'precioPorKm' => number_format((float) $r['precioPorKm'], 3, '.', ''),
            'distanciaKm' => number_format((float) $r['distanciaKm'], 1, '.', ''),
            'tiempoHoras' => $r['tiempoHoras'],
            'vigenteDesde' => $r['vigenteDesde'], 'vigenteHasta' => $r['vigenteHasta'], 'vigente' => $r['vigenteHasta'] === null,
            'costoBulto' => Tarifas::formatoSoles(Tarifas::costoBultoCentimos($r['precioBase'], $r['precioPorKm'], $r['distanciaKm'])),
        ];
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private static function presentarVehiculo(array $v): array
    {
        return [
            'placa' => $v['placa'], 'tipoVehiculo' => $v['tipoVehiculo'], 'capacidadKg' => number_format((float) $v['capacidadKg'], 2, '.', ''),
            'activo' => $v['activo'], 'viajesAbiertos' => $v['viajesAbiertos'],
        ];
    }
}
