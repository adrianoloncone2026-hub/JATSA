<?php
declare(strict_types=1);

// Pruebas del módulo 10 (Catálogos y cuentas del gerente). Se incluye desde ejecutar.php: usa su mini-marco, su $limpiar y sus ayudas.
// Sin base de datos: repositorios simulados en memoria que reproducen lo que el repositorio real hace dentro de la transacción
// (llamar a $decidir con las filas "bloqueadas" y solo entonces escribir).

use Jatsa\Core\Request;
use Jatsa\Core\Router;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Catalogos\CatalogoController;
use Jatsa\Modules\Cuenta\CuentaController;
use Jatsa\Modules\Cuenta\CuentaService;
use Jatsa\Shared\Credencial;
use Jatsa\Shared\Sesion;
use Jatsa\Modules\Catalogos\CatalogoService;
use Jatsa\Modules\Catalogos\RepositorioCatalogo;
use Jatsa\Modules\Usuarios\RepositorioGestionUsuarios;
use Jatsa\Modules\Usuarios\UsuariosController;
use Jatsa\Modules\Usuarios\UsuariosService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

// ── Catálogos: repositorio simulado ─────────────────────────────────────────
final class CatalogoFalso implements RepositorioCatalogo
{
    /** @var list<array<string, mixed>> */
    public array $sucursales = [];
    /** @var list<array<string, mixed>> */
    public array $rutas = [];
    /** @var list<array<string, mixed>> */
    public array $vehiculos = [];
    public int $personalActivo = 0;
    public int $viajesAbiertosSucursal = 0;
    public int $enviosEnCurso = 0;
    public string $cargaMaximaKg = '0';
    public int $siguienteRuta = 100;
    /** @var list<string> */
    public array $escrituras = [];

    public function sucursales(): array
    {
        return $this->sucursales;
    }

    public function crearSucursal(array $datos, array $telefonos): void
    {
        foreach ($this->sucursales as $s) {
            if ($s['codigoSucursal'] === $datos['codigoSucursal']) {
                throw new HttpException(409, 'sucursal_existente', 'Ya existe una sucursal con ese código.', [], ['codigoSucursal' => 'Ya existe una sucursal con ese código.']);
            }
        }
        $this->escrituras[] = 'crearSucursal';
        $this->sucursales[] = $datos + ['activa' => true, 'telefonos' => $telefonos];
    }

    public function actualizarSucursal(string $codigo, array $datos, array $telefonos): bool
    {
        foreach ($this->sucursales as $i => $s) {
            if ($s['codigoSucursal'] === $codigo) {
                $this->escrituras[] = 'actualizarSucursal';
                $this->sucursales[$i] = $datos + ['codigoSucursal' => $codigo, 'activa' => $s['activa'], 'telefonos' => $telefonos];

                return true;
            }
        }

        return false;
    }

    public function cambiarEstadoSucursal(string $codigo, bool $activa, callable $decidir): ?bool
    {
        foreach ($this->sucursales as $i => $s) {
            if ($s['codigoSucursal'] === $codigo) {
                $decidir(['activa' => $s['activa'], 'personalActivo' => $this->personalActivo, 'viajesAbiertos' => $this->viajesAbiertosSucursal, 'enviosEnCurso' => $this->enviosEnCurso]);
                $this->escrituras[] = 'estadoSucursal';
                $this->sucursales[$i]['activa'] = $activa;

                return $activa;
            }
        }

        return null;
    }

    public function rutas(): array
    {
        return $this->rutas;
    }

    /** Si se asigna, se ejecuta DENTRO de crearRuta antes de leer las sucursales bloqueadas: simula una desactivación que se intercala. */
    public mixed $alIniciarCrearRuta = null;

    private function sucursal(string $codigo): ?array
    {
        foreach ($this->sucursales as $s) {
            if ($s['codigoSucursal'] === $codigo) {
                return $s;
            }
        }

        return null;
    }

    public function crearRuta(string $codigoOrigen, string $codigoDestino, array $datos, string $hoy, callable $decidir): array
    {
        if ($this->alIniciarCrearRuta !== null) {
            ($this->alIniciarCrearRuta)($this);
        }
        $o = $this->sucursal($codigoOrigen);
        $d = $this->sucursal($codigoDestino);
        $vigentes = count(array_filter($this->rutas, static fn (array $r): bool => $r['origenCodigo'] === $codigoOrigen && $r['destinoCodigo'] === $codigoDestino && $r['tipoEnvio'] === $datos['tipoEnvio'] && $r['vigenteHasta'] === null));
        $decidir(['origenActiva' => $o !== null && $o['activa'], 'destinoActiva' => $d !== null && $d['activa'], 'vigentes' => $vigentes]);
        $this->escrituras[] = 'crearRuta';

        return $this->rutas[] = $this->fila($this->siguienteRuta++, $o, $d, $datos, $hoy, null);
    }
    public function nuevoPrecio(int $idRuta, array $datos, string $hoy, callable $decidir): ?array
    {
        foreach ($this->rutas as $i => $r) {
            if ($r['idRuta'] === $idRuta) {
                $decidir(['vigenteDesde' => $r['vigenteDesde'], 'vigenteHasta' => $r['vigenteHasta']]);
                $this->escrituras[] = 'nuevoPrecio';
                $this->rutas[$i]['vigenteHasta'] = date('Y-m-d', strtotime($hoy . ' -1 day'));

                return $this->rutas[] = $this->fila($this->siguienteRuta++, ['codigoSucursal' => $r['origenCodigo'], 'nombre' => $r['origenNombre']],
                    ['codigoSucursal' => $r['destinoCodigo'], 'nombre' => $r['destinoNombre']], $datos + ['tipoEnvio' => $r['tipoEnvio']], $hoy, null);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function fila(int $id, array $o, array $d, array $datos, string $desde, ?string $hasta): array
    {
        return ['idRuta' => $id, 'origenCodigo' => $o['codigoSucursal'], 'origenNombre' => $o['nombre'], 'destinoCodigo' => $d['codigoSucursal'], 'destinoNombre' => $d['nombre'],
            'tipoEnvio' => $datos['tipoEnvio'], 'precioBase' => $datos['precioBase'], 'precioPorKm' => $datos['precioPorKm'], 'distanciaKm' => $datos['distanciaKm'],
            'tiempoHoras' => $datos['tiempoHoras'], 'vigenteDesde' => $desde, 'vigenteHasta' => $hasta];
    }

    public function vehiculos(): array
    {
        return $this->vehiculos;
    }

    public function crearVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg): void
    {
        foreach ($this->vehiculos as $v) {
            if ($v['placa'] === $placa) {
                throw new HttpException(409, 'vehiculo_existente', 'Ya existe un vehículo con esa placa.', [], ['placa' => 'Ya existe un vehículo con esa placa.']);
            }
        }
        $this->escrituras[] = 'crearVehiculo';
        $this->vehiculos[] = ['placa' => $placa, 'tipoVehiculo' => $tipoVehiculo, 'capacidadKg' => $capacidadKg, 'activo' => true, 'viajesAbiertos' => 0];
    }

    public function actualizarVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg, callable $decidir): ?bool
    {
        foreach ($this->vehiculos as $i => $v) {
            if ($v['placa'] === $placa) {
                $decidir(['cargaMaximaKg' => $this->cargaMaximaKg]);
                $this->escrituras[] = 'actualizarVehiculo';
                $this->vehiculos[$i]['tipoVehiculo'] = $tipoVehiculo;
                $this->vehiculos[$i]['capacidadKg'] = $capacidadKg;

                return true;
            }
        }

        return null;
    }

    public function cambiarEstadoVehiculo(string $placa, bool $activo, callable $decidir): ?bool
    {
        foreach ($this->vehiculos as $i => $v) {
            if ($v['placa'] === $placa) {
                $decidir(['activo' => $v['activo'], 'viajesAbiertos' => $v['viajesAbiertos']]);
                $this->escrituras[] = 'estadoVehiculo';
                $this->vehiculos[$i]['activo'] = $activo;

                return $activo;
            }
        }

        return null;
    }
}

/** Las cinco sucursales de la diapositiva 4 (solo 4 activas con rutas) y las cinco tarifas de la diapositiva 29. */
function escenarioCatalogo(string $hoy = '2026-11-01'): array
{
    $r = new CatalogoFalso();
    $s = static fn (string $c, string $n, bool $activa = true, array $tel = ['953123456']): array => ['codigoSucursal' => $c, 'nombre' => $n, 'departamento' => 'X', 'provincia' => 'X', 'distrito' => 'X', 'direccion' => 'Av. Principal 100', 'activa' => $activa, 'telefonos' => $tel];
    $r->sucursales = [$s('AQP001', 'Agencia Arequipa Centro'), $s('ILO001', 'Agencia Ilo'), $s('LIM001', 'Agencia Lima Centro'), $s('MOQ001', 'Agencia Moquegua'), $s('TAC001', 'Agencia Tacna', false, [])];
    $f = static fn (int $id, string $o, string $on, string $d, string $dn, string $tipo, string $base, string $km, string $dist, int $h): array => $r->fila(
        $id, ['codigoSucursal' => $o, 'nombre' => $on], ['codigoSucursal' => $d, 'nombre' => $dn], ['tipoEnvio' => $tipo, 'precioBase' => $base, 'precioPorKm' => $km, 'distanciaKm' => $dist, 'tiempoHoras' => $h], '2026-09-01', null);
    $r->rutas = [
        $f(1, 'MOQ001', 'Agencia Moquegua', 'AQP001', 'Agencia Arequipa Centro', 'SOBRE', '8.00', '0.005', '230.0', 4),
        $f(2, 'MOQ001', 'Agencia Moquegua', 'AQP001', 'Agencia Arequipa Centro', 'PAQUETE', '10.00', '0.020', '230.0', 4),
        $f(3, 'AQP001', 'Agencia Arequipa Centro', 'LIM001', 'Agencia Lima Centro', 'PAQUETE', '10.00', '0.020', '1010.0', 18),
        $f(4, 'LIM001', 'Agencia Lima Centro', 'ILO001', 'Agencia Ilo', 'CARGA', '40.00', '0.080', '1200.0', 24),
        $f(5, 'ILO001', 'Agencia Ilo', 'MOQ001', 'Agencia Moquegua', 'SOBRE', '8.00', '0.005', '100.0', 2),
    ];
    $r->vehiculos = [['placa' => 'B7K-210', 'tipoVehiculo' => 'FURGON', 'capacidadKg' => '1500.00', 'activo' => true, 'viajesAbiertos' => 0], ['placa' => 'C3M-956', 'tipoVehiculo' => 'CAMION', 'capacidadKg' => '18000.00', 'activo' => true, 'viajesAbiertos' => 1]];

    return [$r, new CatalogoService($r, static fn (): string => $hoy)];
}

function sucursalValida(array $cambios = []): array
{
    return $cambios + ['codigoSucursal' => 'cus001', 'nombre' => '  Agencia   Cusco ', 'departamento' => 'Cusco', 'provincia' => 'Cusco', 'distrito' => 'Wanchaq', 'direccion' => 'Av. El Sol 123', 'telefonos' => ['984 111 222', '084-222333']];
}

// ── Catálogos: sucursales ───────────────────────────────────────────────────
prueba('catálogos: la lista de sucursales trae teléfonos y estado, e incluye las inactivas', function () {
    [, $servicio] = escenarioCatalogo();
    $lista = $servicio->sucursales()['sucursales'];
    afirmar(count($lista) === 5 && $lista[0]['codigo'] === 'AQP001' && $lista[0]['telefonos'] === ['953123456'] && $lista[4]['activa'] === false, 'cinco sucursales, la última inactiva');
    afirmar(array_keys($lista[0]) === ['codigo', 'nombre', 'departamento', 'provincia', 'distrito', 'direccion', 'telefonos', 'activa'], 'forma de la respuesta');
});

prueba('catálogos: crear sucursal normaliza (código en mayúsculas, espacios, teléfonos) y la deja activa', function () {
    [$r, $servicio] = escenarioCatalogo();
    $s = $servicio->crearSucursal(sucursalValida());
    afirmar($s['codigo'] === 'CUS001' && $s['nombre'] === 'Agencia Cusco' && $s['activa'] === true, 'normalizada');
    afirmar($s['telefonos'] === ['984111222', '084222333'], 'teléfonos sin espacios ni guiones: ' . json_encode($s['telefonos']));
    afirmar(count($r->sucursales) === 6, 'se guardó');
});

prueba('catálogos: crear sucursal valida cada campo (422 con el campo) y rechaza un código repetido (409)', function () {
    [$r, $servicio] = escenarioCatalogo();
    $casos = [['codigoSucursal', 'AB12', 'codigoSucursal'], ['codigoSucursal', 'ABCD12', 'codigoSucursal'], ['nombre', '   ', 'nombre'], ['nombre', str_repeat('a', 81), 'nombre'],
        ['departamento', '', 'departamento'], ['distrito', str_repeat('ñ', 61), 'distrito'], ['direccion', str_repeat('x', 151), 'direccion'], ['telefonos', ['12345'], 'telefonos'],
        ['telefonos', ['984111222', '984111222'], 'telefonos'], ['telefonos', '984111222', 'telefonos'], ['telefonos', ['111111111', '222222222', '333333333', '444444444', '555555555', '666666666'], 'telefonos'], ['nombre', 123, 'nombre']];
    foreach ($casos as [$campo, $valor, $esperado]) {
        $e = esperarError(fn () => $servicio->crearSucursal(sucursalValida([$campo => $valor])), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$esperado]), "marca $esperado con " . json_encode($valor));
    }
    afirmar($r->escrituras === [], 'ninguna escritura con datos inválidos');
    $e = esperarError(fn () => $servicio->crearSucursal(sucursalValida(['codigoSucursal' => 'MOQ001'])), 409, 'sucursal_existente');
    afirmar(isset($e->campos['codigoSucursal']), 'marca el código');
});

prueba('catálogos: editar sucursal cambia los datos y reemplaza los teléfonos, pero nunca el código; 404 si no existe', function () {
    [$r, $servicio] = escenarioCatalogo();
    $s = $servicio->actualizarSucursal('moq001', ['codigoSucursal' => 'XXX999', 'nombre' => 'Agencia Moquegua Plaza', 'departamento' => 'Moquegua', 'provincia' => 'Mariscal Nieto', 'distrito' => 'Moquegua', 'direccion' => 'Jr. Ancash 325', 'telefonos' => ['953000111']]);
    afirmar($s['codigo'] === 'MOQ001' && $s['nombre'] === 'Agencia Moquegua Plaza' && $s['telefonos'] === ['953000111'], 'datos y teléfonos reemplazados');
    afirmar(!in_array('XXX999', array_column($r->sucursales, 'codigoSucursal'), true), 'el código del cuerpo se ignora');
    esperarError(fn () => $servicio->actualizarSucursal('ZZZ999', sucursalValida()), 404, 'sucursal_no_encontrada');
    esperarError(fn () => $servicio->actualizarSucursal("MOQ001' OR 1=1", sucursalValida()), 404, 'sucursal_no_encontrada');
    esperarError(fn () => $servicio->actualizarSucursal('MOQ001', sucursalValida(['nombre' => ''])), 422, 'datos_invalidos');
});

prueba('catálogos: desactivar una sucursal exige que no tenga personal activo, viajes sin llegada ni envíos en curso; reactivar siempre se puede', function () {
    [$r, $servicio] = escenarioCatalogo();
    $r->personalActivo = 2;
    esperarError(fn () => $servicio->cambiarEstadoSucursal('MOQ001', ['activa' => false]), 409, 'sucursal_con_personal');
    $r->personalActivo = 0;
    $r->viajesAbiertosSucursal = 1;
    esperarError(fn () => $servicio->cambiarEstadoSucursal('MOQ001', ['activa' => false]), 409, 'sucursal_con_viajes');
    $r->viajesAbiertosSucursal = 0;
    $r->enviosEnCurso = 3;
    esperarError(fn () => $servicio->cambiarEstadoSucursal('MOQ001', ['activa' => false]), 409, 'sucursal_con_envios');
    afirmar($r->escrituras === [], 'no se escribió nada mientras había bloqueos');
    $r->enviosEnCurso = 0;
    afirmar($servicio->cambiarEstadoSucursal('MOQ001', ['activa' => false])['activa'] === false, 'sin nada en uso se desactiva');
    $r->personalActivo = 5;
    afirmar($servicio->cambiarEstadoSucursal('MOQ001', ['activa' => true])['activa'] === true, 'reactivar no revisa nada');
    esperarError(fn () => $servicio->cambiarEstadoSucursal('MOQ001', ['activa' => 'no']), 422, 'datos_invalidos');
    esperarError(fn () => $servicio->cambiarEstadoSucursal('ZZZ999', ['activa' => false]), 404, 'sucursal_no_encontrada');
});

// ── Catálogos: rutas y precios (diapositiva 29) ─────────────────────────────
prueba('catálogos: «Rutas y precios» reproduce la diapositiva 29 (costo del bulto S/ 9.15, 14.60, 30.20, 136.00 y 8.50)', function () {
    [, $servicio] = escenarioCatalogo();
    $rutas = $servicio->rutas()['rutas'];
    afirmar(array_column($rutas, 'costoBulto') === ['9.15', '14.60', '30.20', '136.00', '8.50'], 'costos: ' . json_encode(array_column($rutas, 'costoBulto')));
    afirmar($rutas[0]['precioBase'] === '8.00' && $rutas[0]['precioPorKm'] === '0.005' && $rutas[0]['distanciaKm'] === '230.0' && $rutas[0]['tiempoHoras'] === 4, 'base, por km, km y horas');
    afirmar($rutas[0]['vigenteDesde'] === '2026-09-01' && $rutas[0]['vigenteHasta'] === null && $rutas[0]['vigente'] === true, 'desde 01/09/2026, vigente');
    afirmar($rutas[0]['origen'] === ['codigo' => 'MOQ001', 'nombre' => 'Agencia Moquegua'] && $rutas[0]['tipoEnvio'] === 'SOBRE', 'ruta y tipo');
});

prueba('catálogos: «Nueva ruta» crea la tarifa desde hoy; no admite origen = destino, agencias inactivas ni una tarifa vigente repetida', function () {
    [$r, $servicio] = escenarioCatalogo();
    $datos = ['origen' => 'ilo001', 'destino' => 'aqp001', 'tipoEnvio' => 'paquete', 'precioBase' => '12,50', 'precioPorKm' => '0.025', 'distanciaKm' => '330,5', 'tiempoHoras' => 5];
    $nueva = $servicio->crearRuta($datos);
    afirmar($nueva['vigenteDesde'] === '2026-11-01' && $nueva['vigenteHasta'] === null && $nueva['tipoEnvio'] === 'PAQUETE' && $nueva['precioBase'] === '12.50' && $nueva['distanciaKm'] === '330.5', 'desde hoy, normalizada');
    esperarError(fn () => $servicio->crearRuta($datos), 409, 'tarifa_vigente_existente');
    $e = esperarError(fn () => $servicio->crearRuta(array_merge($datos, ['origen' => 'AQP001', 'destino' => 'AQP001'])), 422, 'datos_invalidos');
    afirmar(isset($e->campos['destino']), 'origen = destino marca el destino');
    $e = esperarError(fn () => $servicio->crearRuta(['origen' => 'TAC001', 'destino' => 'MOQ001'] + $datos), 422, 'datos_invalidos');
    afirmar(isset($e->campos['origen']), 'una agencia inactiva no sirve de origen');
    $antes = count($r->rutas);
    foreach ([['tipoEnvio', 'MALETA', 'tipoEnvio'], ['precioBase', '0', 'precioBase'], ['precioBase', '-5', 'precioBase'], ['precioBase', '1234567', 'precioBase'], ['precioBase', '1.234', 'precioBase'], ['precioPorKm', '1.2345', 'precioPorKm'],
        ['precioPorKm', 'abc', 'precioPorKm'], ['distanciaKm', '0', 'distanciaKm'], ['distanciaKm', '10.55', 'distanciaKm'], ['tiempoHoras', 0, 'tiempoHoras'], ['tiempoHoras', 40000, 'tiempoHoras'], ['tiempoHoras', 2.5, 'tiempoHoras'], ['origen', '', 'origen']] as [$campo, $valor, $esperado]) {
        $e = esperarError(fn () => $servicio->crearRuta([$campo => $valor] + ['origen' => 'LIM001', 'destino' => 'MOQ001', 'tipoEnvio' => 'SOBRE', 'precioBase' => '8', 'precioPorKm' => '0.005', 'distanciaKm' => '10', 'tiempoHoras' => 1]), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$esperado]), "marca $esperado con " . json_encode($valor));
    }
    afirmar(count($r->rutas) === $antes, 'nada se guardó con datos inválidos');
});

prueba('catálogos: «Nuevo precio» cierra la vigencia de la fila actual (el día anterior) y crea otra desde hoy; la fila vieja conserva su precio', function () {
    [$r, $servicio] = escenarioCatalogo();
    $vieja = $r->rutas[0];
    $nueva = $servicio->nuevoPrecio('1', ['precioBase' => '9.00', 'precioPorKm' => '0.006', 'distanciaKm' => '230', 'tiempoHoras' => 4]);
    afirmar($nueva['id'] === 100 && $nueva['vigenteDesde'] === '2026-11-01' && $nueva['vigenteHasta'] === null && $nueva['vigente'] === true, 'fila nueva vigente desde hoy');
    afirmar($nueva['tipoEnvio'] === 'SOBRE' && $nueva['origen']['codigo'] === 'MOQ001' && $nueva['destino']['codigo'] === 'AQP001', 'origen, destino y tipo no cambian');
    afirmar($nueva['costoBulto'] === '10.38', 'costo nuevo: 9.00 + 0.006 × 230 = ' . $nueva['costoBulto']);
    $cerrada = $r->rutas[0];
    afirmar($cerrada['vigenteHasta'] === '2026-10-31' && $cerrada['precioBase'] === $vieja['precioBase'] && $cerrada['precioPorKm'] === $vieja['precioPorKm'], 'la fila vieja se cerró el 31/10 y conserva su tarifa (los bultos ya cobrados no cambian)');
    afirmar(count($r->rutas) === 6 && count(array_filter($r->rutas, static fn (array $x): bool => $x['origenCodigo'] === 'MOQ001' && $x['tipoEnvio'] === 'SOBRE' && $x['vigenteHasta'] === null)) === 1, 'queda UNA sola vigente para ese origen, destino y tipo');
    afirmar(array_column($servicio->rutas()['rutas'], 'vigente')[0] === false, 'la lista marca la cerrada como no vigente');
});

prueba('catálogos: «Nuevo precio» rechaza una tarifa cerrada, una que entró en vigor hoy, una inexistente o datos inválidos, sin escribir nada', function () {
    [$r, $servicio] = escenarioCatalogo();
    $datos = ['precioBase' => '9.00', 'precioPorKm' => '0.006', 'distanciaKm' => '230', 'tiempoHoras' => 4];
    $servicio->nuevoPrecio('1', $datos);
    esperarError(fn () => $servicio->nuevoPrecio('1', $datos), 409, 'tarifa_cerrada');
    esperarError(fn () => $servicio->nuevoPrecio('100', $datos), 409, 'tarifa_cambiada_hoy');
    $escrituras = count($r->escrituras);
    esperarError(fn () => $servicio->nuevoPrecio('9999', $datos), 404, 'ruta_no_encontrada');
    esperarError(fn () => $servicio->nuevoPrecio('abc', $datos), 404, 'ruta_no_encontrada');
    esperarError(fn () => $servicio->nuevoPrecio("2' OR '1'='1", $datos), 404, 'ruta_no_encontrada');
    esperarError(fn () => $servicio->nuevoPrecio('2', ['precioBase' => '0'] + $datos), 422, 'datos_invalidos');
    afirmar(count($r->escrituras) === $escrituras, 'ninguna escritura adicional');
});

// ── Catálogos: vehículos ────────────────────────────────────────────────────
prueba('catálogos: vehículos — alta con placa y tipo validados, 409 si la placa existe, y la lista cuenta los viajes abiertos', function () {
    [$r, $servicio] = escenarioCatalogo();
    $v = $servicio->crearVehiculo(['placa' => 'v2a-845', 'tipoVehiculo' => 'camioneta', 'capacidadKg' => '800,5']);
    afirmar($v === ['placa' => 'V2A-845', 'tipoVehiculo' => 'CAMIONETA', 'capacidadKg' => '800.50', 'activo' => true, 'viajesAbiertos' => 0], 'normalizado: ' . json_encode($v));
    esperarError(fn () => $servicio->crearVehiculo(['placa' => 'B7K-210', 'tipoVehiculo' => 'MOTO', 'capacidadKg' => '50']), 409, 'vehiculo_existente');
    foreach ([['placa', 'AB-1234', 'placa'], ['placa', 'ABCD123', 'placa'], ['placa', '', 'placa'], ['tipoVehiculo', 'BICICLETA', 'tipoVehiculo'], ['capacidadKg', '0', 'capacidadKg'], ['capacidadKg', '-1', 'capacidadKg'], ['capacidadKg', '12345678', 'capacidadKg'], ['capacidadKg', '10.123', 'capacidadKg']] as [$campo, $valor, $esperado]) {
        $e = esperarError(fn () => $servicio->crearVehiculo([$campo => $valor] + ['placa' => 'ZZZ-999', 'tipoVehiculo' => 'MOTO', 'capacidadKg' => '50']), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$esperado]), "marca $esperado con " . json_encode($valor));
    }
    afirmar(array_column($servicio->vehiculos()['vehiculos'], 'viajesAbiertos', 'placa')['C3M-956'] === 1, 'viajes abiertos en la lista');
    afirmar(count($r->vehiculos) === 3, 'solo se guardó el válido');
});

prueba('catálogos: editar un vehículo no baja la capacidad por debajo de la carga de sus viajes sin llegada; la placa no cambia', function () {
    [$r, $servicio] = escenarioCatalogo();
    $r->cargaMaximaKg = '900.00';
    $e = esperarError(fn () => $servicio->actualizarVehiculo('B7K-210', ['placa' => 'XXX-111', 'tipoVehiculo' => 'FURGON', 'capacidadKg' => '800']), 409, 'capacidad_menor_a_la_carga');
    afirmar(isset($e->campos['capacidadKg']), 'marca la capacidad');
    $v = $servicio->actualizarVehiculo('b7k-210', ['placa' => 'XXX-111', 'tipoVehiculo' => 'camion', 'capacidadKg' => '900']);
    afirmar($v['placa'] === 'B7K-210' && $v['tipoVehiculo'] === 'CAMION' && $v['capacidadKg'] === '900.00', 'igual a la carga se permite; la placa del cuerpo se ignora');
    esperarError(fn () => $servicio->actualizarVehiculo('ZZZ-999', ['tipoVehiculo' => 'MOTO', 'capacidadKg' => '50']), 404, 'vehiculo_no_encontrado');
    esperarError(fn () => $servicio->actualizarVehiculo('malo', ['tipoVehiculo' => 'MOTO', 'capacidadKg' => '50']), 404, 'vehiculo_no_encontrado');
});

prueba('catálogos: desactivar un vehículo con viajes programados o en ruta es 409; sin viajes se puede, y reactivar siempre', function () {
    [, $servicio] = escenarioCatalogo();
    esperarError(fn () => $servicio->cambiarEstadoVehiculo('C3M-956', ['activo' => false]), 409, 'vehiculo_con_viajes');
    afirmar($servicio->cambiarEstadoVehiculo('B7K-210', ['activo' => false])['activo'] === false, 'sin viajes se desactiva');
    afirmar($servicio->cambiarEstadoVehiculo('B7K-210', ['activo' => true])['activo'] === true, 'se reactiva');
    esperarError(fn () => $servicio->cambiarEstadoVehiculo('B7K-210', []), 422, 'datos_invalidos');
    esperarError(fn () => $servicio->cambiarEstadoVehiculo('ZZZ-999', ['activo' => false]), 404, 'vehiculo_no_encontrado');
});

// ── Cuentas: repositorio simulado ───────────────────────────────────────────
final class UsuariosFalso implements RepositorioGestionUsuarios
{
    /** @var list<array<string, mixed>> nombreUsuario, nombreCompleto, rol, sucursalCodigo, sucursalNombre, activo, viajesAbiertos */
    public array $cuentas = [];
    /** @var list<array<string, mixed>> idPersona, tipoDocumento, numeroDocumento, nombreCompleto, tieneUsuario */
    public array $personas = [];
    /** @var array<string, bool> código → activa (el id es la posición + 1) */
    public array $sucursales = ['AQP001' => true, 'MOQ001' => true];
    /** Se ejecutan DENTRO de crearCuenta / cambiarPuesto, antes de leer lo bloqueado: simulan un cambio de otro gerente que se intercala. */
    public mixed $alIniciarCrearCuenta = null;
    public mixed $alIniciarCambiarPuesto = null;
    /** @var list<array<string, mixed>> */
    public array $altas = [];
    public ?array $ultimosFiltros = null;
    public int $ultimoLimite = 0;

    public function listar(array $filtros, int $limite): array
    {
        $this->ultimosFiltros = $filtros;
        $this->ultimoLimite = $limite;
        $filas = array_filter($this->cuentas, static fn (array $c): bool => ($filtros['rol'] === '' || $c['rol'] === $filtros['rol'])
            && ($filtros['estado'] === '' || $c['activo'] === ($filtros['estado'] === 'ACTIVA'))
            && ($filtros['q'] === '' || stripos($c['nombreUsuario'], $filtros['q']) !== false || stripos($c['nombreCompleto'], $filtros['q']) !== false));

        return array_slice(array_values($filas), 0, $limite);
    }

    public function personaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array
    {
        foreach ($this->personas as $p) {
            if ($p['tipoDocumento'] === $tipoDocumento && $p['numeroDocumento'] === $numeroDocumento) {
                return ['idPersona' => $p['idPersona'], 'nombreCompleto' => $p['nombreCompleto'], 'tieneUsuario' => $p['tieneUsuario']];
            }
        }

        return null;
    }

    public function usuarioExiste(string $nombreUsuario): bool
    {
        return $this->cuenta($nombreUsuario) !== null;
    }

    public function crearCuenta(int $idPersona, string $codigoSucursal, string $nombreUsuario, string $hashContrasena, string $rol, callable $decidir): void
    {
        if ($this->alIniciarCrearCuenta !== null) {
            ($this->alIniciarCrearCuenta)($this);
        }
        $decidir(['sucursalActiva' => $this->sucursales[$codigoSucursal] ?? false]);
        $idSucursal = array_search($codigoSucursal, array_keys($this->sucursales), true) + 1;
        $this->altas[] = ['idPersona' => $idPersona, 'idSucursal' => $idSucursal, 'usuario' => $nombreUsuario, 'hash' => $hashContrasena, 'rol' => $rol];
        $this->cuentas[] = ['nombreUsuario' => $nombreUsuario, 'nombreCompleto' => 'Persona nueva', 'rol' => $rol, 'sucursalCodigo' => $codigoSucursal, 'sucursalNombre' => 'Agencia ' . $codigoSucursal, 'activo' => true, 'viajesAbiertos' => 0];
    }
    public function cuenta(string $nombreUsuario): ?array
    {
        foreach ($this->cuentas as $c) {
            if (strcasecmp($c['nombreUsuario'], $nombreUsuario) === 0) {
                return $c;
            }
        }

        return null;
    }

    public function cambiarPuesto(string $nombreUsuario, string $rol, string $codigoSucursal, callable $decidir): ?bool
    {
        if ($this->alIniciarCambiarPuesto !== null) {
            ($this->alIniciarCambiarPuesto)($this);
        }
        foreach ($this->cuentas as $i => $c) {
            if (strcasecmp($c['nombreUsuario'], $nombreUsuario) === 0) {
                $decidir(['rolActual' => $c['rol'], 'sucursalActual' => $c['sucursalCodigo'], 'activo' => $c['activo'], 'sucursalActiva' => $this->sucursales[$codigoSucursal] ?? false, 'viajesAbiertos' => $c['viajesAbiertos']]);
                $this->cuentas[$i]['rol'] = $rol;
                $this->cuentas[$i]['sucursalCodigo'] = $codigoSucursal;
                $this->cuentas[$i]['sucursalNombre'] = 'Agencia ' . $codigoSucursal;

                return true;
            }
        }

        return null;
    }
    /** Se ejecuta DENTRO de cambiarEstado, antes de leer lo bloqueado: simula un cambio de otro gerente (sucursal desactivada o cuenta movida). */
    public mixed $alIniciarCambiarEstado = null;

    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool
    {
        if ($this->alIniciarCambiarEstado !== null) {
            ($this->alIniciarCambiarEstado)($this);
        }
        $gerentes = count(array_filter($this->cuentas, static fn (array $c): bool => $c['rol'] === Roles::GERENTE && $c['activo']));
        foreach ($this->cuentas as $i => $c) {
            if (strcasecmp($c['nombreUsuario'], $nombreUsuario) === 0) {
                $decidir(['rol' => $c['rol'], 'activo' => $c['activo'], 'sucursalCodigo' => $c['sucursalCodigo'],
                    'sucursalActiva' => $c['sucursalCodigo'] !== null && ($this->sucursales[$c['sucursalCodigo']] ?? false),
                    'viajesAbiertos' => $c['viajesAbiertos'], 'gerentesActivos' => $gerentes]);
                $this->cuentas[$i]['activo'] = $activo;

                return $activo;
            }
        }

        return null;
    }
}

/** Las cuentas de la diapositiva 30 y personas sin cuenta (destinatarios que ya existen). */
function escenarioCuentas(): array
{
    $r = new UsuariosFalso();
    $c = static fn (string $u, string $n, string $rol, ?string $suc, bool $activo = true, int $viajes = 0): array => ['nombreUsuario' => $u, 'nombreCompleto' => $n, 'rol' => $rol, 'sucursalCodigo' => $suc,
        'sucursalNombre' => $suc === null ? null : 'Agencia ' . $suc, 'activo' => $activo, 'viajesAbiertos' => $viajes];
    $r->cuentas = [$c('rquispe', 'Rosa María Quispe Mamani', 'GERENTE', null), $c('lflores', 'Luis Alberto Flores Condori', 'ADMINISTRADOR', 'MOQ001'), $c('mrojas', 'María Elena Rojas Ccori', 'CLIENTE', null),
        $c('comsur', 'Comercial Sur S.A.C.', 'EMPRESARIO', null), $c('pmamani', 'Pedro Mamani Huanca', 'TRANSPORTISTA', 'AQP001', true, 1)];
    $r->personas = [['idPersona' => 74, 'tipoDocumento' => 'DNI', 'numeroDocumento' => '74561230', 'nombreCompleto' => 'Juan Condori Apaza', 'tieneUsuario' => false],
        ['idPersona' => 4, 'tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345', 'nombreCompleto' => 'María Elena Rojas Ccori', 'tieneUsuario' => true]];

    return [$r, new UsuariosService($r)];
}

const SESION_GERENTE = ['id' => 1, 'nombreUsuario' => 'rquispe', 'rol' => 'GERENTE'];

function cuentaPersonalValida(array $cambios = []): array
{
    return $cambios + ['tipoDocumento' => 'DNI', 'numeroDocumento' => '74561230', 'rol' => 'TRANSPORTISTA', 'sucursal' => 'AQP001', 'usuario' => 'jcondori', 'contrasena' => 'Temporal#2026'];
}

// ── Cuentas ─────────────────────────────────────────────────────────────────
prueba('cuentas: la lista reproduce la diapositiva 30 y no expone hash, documentos ni teléfonos', function () {
    [$r, $servicio] = escenarioCuentas();
    $l = $servicio->listar(SESION_GERENTE, []);
    afirmar(array_column($l['cuentas'], 'usuario') === ['rquispe', 'lflores', 'mrojas', 'comsur', 'pmamani'] && $l['hayMas'] === false, 'cinco cuentas');
    afirmar($l['cuentas'][1] === ['usuario' => 'lflores', 'persona' => 'Luis Alberto Flores Condori', 'rol' => 'ADMINISTRADOR', 'sucursal' => ['codigo' => 'MOQ001', 'nombre' => 'Agencia MOQ001'], 'activa' => true, 'esUsted' => false, 'editable' => true], 'forma de una fila');
    afirmar($l['cuentas'][0]['sucursal'] === null && $l['cuentas'][0]['esUsted'] === true && $l['cuentas'][2]['editable'] === false, 'sin sucursal para el gerente; «esUsted»; el cliente no es editable');
    $json = (string) json_encode($l);
    foreach (['hash', 'Contrasena', 'contrasena', 'numeroDocumento', 'telefono', 'correo', 'viajesAbiertos'] as $prohibido) {
        afirmar(!str_contains($json, $prohibido), "no contiene «{$prohibido}»");
    }
    afirmar($r->ultimoLimite === UsuariosService::MAX_LISTA + 1, 'pide un registro más que el tope');
});

prueba('cuentas: filtros por texto, rol y estado, y 422 con valores inválidos', function () {
    [$r, $servicio] = escenarioCuentas();
    afirmar(array_column($servicio->listar(SESION_GERENTE, ['rol' => 'administrador'])['cuentas'], 'usuario') === ['lflores'], 'rol en minúsculas');
    afirmar(array_column($servicio->listar(SESION_GERENTE, ['q' => 'ROJAS'])['cuentas'], 'usuario') === ['mrojas'], 'texto');
    $r->cuentas[2]['activo'] = false;
    afirmar(array_column($servicio->listar(SESION_GERENTE, ['estado' => 'inactiva'])['cuentas'], 'usuario') === ['mrojas'], 'estado');
    foreach ([['rol', 'REY'], ['estado', 'DORMIDA'], ['q', str_repeat('a', 51)]] as [$campo, $valor]) {
        $e = esperarError(fn () => $servicio->listar(SESION_GERENTE, [$campo => $valor]), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$campo]), "marca $campo");
    }
});

prueba('cuentas: consultar una persona por documento devuelve solo su nombre y si ya tiene cuenta', function () {
    [, $servicio] = escenarioCuentas();
    afirmar($servicio->consultarPersona(['tipoDocumento' => 'DNI', 'numeroDocumento' => '7456-1230']) === ['persona' => ['existe' => true, 'nombreCompleto' => 'Juan Condori Apaza', 'tieneCuenta' => false]], 'existe y puede recibir cuenta');
    afirmar($servicio->consultarPersona(['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345'])['persona']['tieneCuenta'] === true, 'ya tiene cuenta');
    afirmar($servicio->consultarPersona(['tipoDocumento' => 'DNI', 'numeroDocumento' => '00000000']) === ['persona' => ['existe' => false]], 'no existe');
    foreach ([['RUC', '20123456789'], ['DNI', '123'], ['DNI', "1' OR 1=1"], ['', '']] as [$t, $n]) {
        esperarError(fn () => $servicio->consultarPersona(['tipoDocumento' => $t, 'numeroDocumento' => $n]), 422, 'datos_invalidos');
    }
});

prueba('cuentas: «Crear cuenta» de personal (diapositiva 30) — TRANSPORTISTA en AQP001 con contraseña cifrada con bcrypt', function () {
    [$r, $servicio] = escenarioCuentas();
    $c = $servicio->crear(SESION_GERENTE, cuentaPersonalValida());
    afirmar($c['usuario'] === 'jcondori' && $c['rol'] === 'TRANSPORTISTA' && $c['sucursal']['codigo'] === 'AQP001' && $c['activa'] === true, 'cuenta creada: ' . json_encode($c));
    $alta = $r->altas[0];
    afirmar($alta['idPersona'] === 74 && $alta['idSucursal'] === 1 && $alta['rol'] === 'TRANSPORTISTA', 'ligada a la persona existente y a su sucursal');
    afirmar($alta['hash'] !== 'Temporal#2026' && str_starts_with($alta['hash'], '$2y$') && password_verify('Temporal#2026', $alta['hash']), 'la contraseña se guarda con password_hash (bcrypt)');
    afirmar(!str_contains((string) json_encode($c), 'Temporal') && !str_contains((string) json_encode($c), '$2y$'), 'la respuesta no devuelve la contraseña ni el hash');
});

prueba('cuentas: crear cuenta valida rol (solo personal), sucursal obligatoria y activa, usuario, contraseña y documento', function () {
    [$r, $servicio] = escenarioCuentas();
    $casos = [['rol', 'CLIENTE', 'rol'], ['rol', 'GERENTE', 'rol'], ['rol', '', 'rol'], ['sucursal', '', 'sucursal'], ['sucursal', 'ZZZ999', 'sucursal'], ['usuario', 'abc', 'usuario'], ['usuario', 'con espacio', 'usuario'],
        ['contrasena', 'corta', 'contrasena'], ['contrasena', 'jcondori', 'contrasena'], ['contrasena', str_repeat('a', 73), 'contrasena'], ['tipoDocumento', 'RUC', 'tipoDocumento'], ['numeroDocumento', '123', 'numeroDocumento'], ['usuario', 12345678, 'usuario']];
    foreach ($casos as [$campo, $valor, $esperado]) {
        $e = esperarError(fn () => $servicio->crear(SESION_GERENTE, cuentaPersonalValida([$campo => $valor])), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$esperado]), "marca $esperado con " . json_encode($valor));
    }
    afirmar($r->altas === [], 'ninguna alta con datos inválidos');
});

prueba('cuentas: crear cuenta exige una persona existente sin cuenta y un usuario libre (404/409 con el campo)', function () {
    [$r, $servicio] = escenarioCuentas();
    $e = esperarError(fn () => $servicio->crear(SESION_GERENTE, cuentaPersonalValida(['numeroDocumento' => '00000000'])), 404, 'persona_no_encontrada');
    afirmar(isset($e->campos['numeroDocumento']), 'marca el documento');
    $e = esperarError(fn () => $servicio->crear(SESION_GERENTE, cuentaPersonalValida(['numeroDocumento' => '71889345'])), 409, 'cuenta_existente');
    afirmar(isset($e->campos['numeroDocumento']), 'la persona ya tiene cuenta');
    $e = esperarError(fn () => $servicio->crear(SESION_GERENTE, cuentaPersonalValida(['usuario' => 'LFlores'])), 409, 'usuario_en_uso');
    afirmar(isset($e->campos['usuario']), 'usuario repetido (sin distinguir mayúsculas)');
    afirmar($r->altas === [], 'ninguna alta');
});

prueba('cuentas: «Editar» cambia rol y sucursal del personal; no toca clientes, empresarios ni gerentes; 404 si no existe', function () {
    [, $servicio] = escenarioCuentas();
    $c = $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'administrador', 'sucursal' => 'aqp001']);
    afirmar($c['rol'] === 'ADMINISTRADOR' && $c['sucursal']['codigo'] === 'AQP001', 'cambió de sucursal');
    foreach (['mrojas', 'comsur', 'rquispe'] as $u) {
        esperarError(fn () => $servicio->editar(SESION_GERENTE, $u, ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 409, 'cuenta_no_editable');
    }
    foreach ([['rol', 'CLIENTE', 'rol'], ['sucursal', '', 'sucursal']] as [$campo, $valor, $esperado]) {
        $e = esperarError(fn () => $servicio->editar(SESION_GERENTE, 'lflores', [$campo => $valor] + ['rol' => 'ADMINISTRADOR', 'sucursal' => 'MOQ001']), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$esperado]), "marca $esperado");
    }
    $e = esperarError(fn () => $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'ZZZ999']), 422, 'datos_invalidos');
    afirmar(isset($e->campos['sucursal']), 'sucursal inexistente o inactiva');
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'nadie12', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 404, 'cuenta_no_encontrada');
    esperarError(fn () => $servicio->editar(SESION_GERENTE, "x' OR 1=1", ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 404, 'cuenta_no_encontrada');
});

prueba('cuentas: un transportista con viajes sin llegada no cambia de puesto, pero guardar sin cambios no es error', function () {
    [, $servicio] = escenarioCuentas();
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'pmamani', ['rol' => 'TRANSPORTISTA', 'sucursal' => 'MOQ001']), 409, 'transportista_con_viajes');
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'pmamani', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 409, 'transportista_con_viajes');
    afirmar($servicio->editar(SESION_GERENTE, 'pmamani', ['rol' => 'TRANSPORTISTA', 'sucursal' => 'AQP001'])['sucursal']['codigo'] === 'AQP001', 'sin cambios');
});

prueba('cuentas: «Desactivar seleccionada» — no a uno mismo, no al último gerente, no a un transportista con viajes; reactivar siempre', function () {
    [$r, $servicio] = escenarioCuentas();
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'rquispe', ['activo' => false]), 409, 'no_puede_desactivarse');
    esperarError(fn () => $servicio->cambiarEstado(['nombreUsuario' => 'otro', 'rol' => 'GERENTE'], 'rquispe', ['activo' => false]), 409, 'ultimo_gerente');
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'pmamani', ['activo' => false]), 409, 'transportista_con_viajes');
    afirmar($r->cuentas[0]['activo'] && $r->cuentas[4]['activo'], 'nada cambió');
    $c = $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    afirmar($c['activa'] === false && $r->cuentas[1]['activo'] === false, 'un administrador se desactiva');
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'y se reactiva');
    $r->cuentas[] = ['nombreUsuario' => 'gerente2', 'nombreCompleto' => 'Otra Gerente', 'rol' => 'GERENTE', 'sucursalCodigo' => null, 'sucursalNombre' => null, 'activo' => true, 'viajesAbiertos' => 0];
    afirmar($servicio->cambiarEstado(['nombreUsuario' => 'gerente2', 'rol' => 'GERENTE'], 'rquispe', ['activo' => false])['activa'] === false, 'con otro gerente activo, sí se puede');
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => 'si']), 422, 'datos_invalidos');
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'nadie12', ['activo' => false]), 404, 'cuenta_no_encontrada');
});

prueba('cuentas: una cuenta desactivada ya no sirve — AuthService::vigente la rechaza en cada petición', function () {
    $auth = new RepoFalso([registro(2, 'lflores', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(3, 'inactiva1', 'ClaveAdmin#2', Roles::ADMINISTRADOR, false, 3)]);
    $servicio = new AuthService($auth);
    afirmar($servicio->vigente(2) !== null, 'la activa vale');
    afirmar($servicio->vigente(3) === null, 'la desactivada ya no vale, aunque su sesión siga abierta');
});

// ── HTTP: solo el GERENTE ───────────────────────────────────────────────────
prueba('rutas de catálogos y cuentas: todas exigen sesión y rol GERENTE (401 sin sesión; 403 para los demás roles)', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE), registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3), registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE),
        registro(5, 'empresa1', 'ClaveEmpresa#1', Roles::EMPRESARIO), registro(6, 'transp1', 'ClaveTransp#1', Roles::TRANSPORTISTA, true, 2)]);
    $servicioAuth = new AuthService($auth);
    $guardia = new RequiereRol([Roles::GERENTE], new RequiereSesion($servicioAuth));
    $router = new Router();
    foreach (['Catalogos', 'Usuarios'] as $modulo) {
        (require dirname(__DIR__) . "/src/Modules/$modulo/rutas.php")($router);
    }
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Catalogos/rutas.php') . (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Usuarios/rutas.php');
    afirmar(preg_match_all('/\$router->(get|post|put|patch|delete)\(/', $fuente) === preg_match_all('/\$gerentes\);/', $fuente), 'todas las rutas llevan el middleware $gerentes');
    afirmar(str_contains($fuente, '[new RequiereRol([Roles::GERENTE])]') && !str_contains($fuente, 'Roles::ADMINISTRADOR'), 'solo GERENTE');
    esperarError(fn () => $guardia(peticion('GET', '/api/catalogos/rutas')), 401, 'no_autenticado');
    foreach ([2, 4, 5, 6] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        esperarError(fn () => $guardia(peticion('POST', '/api/usuarios')), 403, 'acceso_denegado');
    }
    $limpiar();
    sesionDe($auth, $servicioAuth, 1);
    $guardia(peticion('GET', '/api/catalogos/rutas'));
    // /persona antes que /{usuario}; ningún DELETE: nada se borra.
    afirmar(strpos($fuente, "'/api/usuarios/persona'") < strpos($fuente, "'/api/usuarios/{usuario}'"), '/persona se registra antes que /{usuario}');
    afirmar(!str_contains($fuente, '->delete('), 'no hay rutas DELETE: las cuentas, sucursales y vehículos se desactivan');
    $limpiar();
});

prueba('controladores del módulo 10: responden con el formato {data} y con los códigos HTTP correctos', function () use ($limpiar) {
    $limpiar();
    $auth = new RepoFalso([registro(1, 'rquispe', 'ClaveGerente#1', Roles::GERENTE)]);
    $servicioAuth = new AuthService($auth);
    sesionDe($auth, $servicioAuth, 1);
    [, $catalogo] = escenarioCatalogo();
    [, $cuentas] = escenarioCuentas();
    $cc = new CatalogoController($catalogo);
    $uc = new UsuariosController($cuentas);
    $r = leer($cc->sucursales(peticion('GET', '/x'), []));
    afirmar($r['estado'] === 200 && count($r['cuerpo']['data']['sucursales']) === 5, 'sucursales 200');
    $r = leer($cc->crearSucursal(new Request('POST', '/x', [], (string) json_encode(sucursalValida()), ['content-type' => 'application/json']), []));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data']['sucursal']['codigo'] === 'CUS001', 'crear sucursal 201');
    $r = leer($cc->nuevoPrecio(new Request('POST', '/x', [], (string) json_encode(['precioBase' => '9', 'precioPorKm' => '0.006', 'distanciaKm' => '230', 'tiempoHoras' => 4]), ['content-type' => 'application/json']), ['id' => '1']));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data']['ruta']['vigente'] === true, 'nuevo precio 201');
    $r = leer($cc->estadoVehiculo(new Request('POST', '/x', [], '{"activo":false}', ['content-type' => 'application/json']), ['placa' => 'B7K-210']));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['vehiculo']['activo'] === false, 'estado vehículo 200');
    $r = leer($uc->listar(new Request('GET', '/x', ['rol' => 'CLIENTE'], '', []), []));
    afirmar($r['estado'] === 200 && array_column($r['cuerpo']['data']['cuentas'], 'usuario') === ['mrojas'], 'listar cuentas con filtro de la URL');
    $r = leer($uc->persona(new Request('GET', '/x', ['tipoDocumento' => 'DNI', 'numeroDocumento' => '74561230'], '', []), []));
    afirmar($r['cuerpo']['data']['persona']['existe'] === true, 'persona');
    $r = leer($uc->crear(new Request('POST', '/x', [], (string) json_encode(cuentaPersonalValida()), ['content-type' => 'application/json']), []));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data']['cuenta']['usuario'] === 'jcondori', 'crear cuenta 201');
    esperarError(fn () => $uc->estado(new Request('POST', '/x', [], '{"activo":false}', ['content-type' => 'application/json']), ['usuario' => 'rquispe']), 409, 'no_puede_desactivarse');
    $limpiar();
    esperarError(fn () => $uc->listar(peticion('GET', '/x'), []), 401, 'no_autenticado');
});

prueba('repositorios reales: solo SQL parametrizado y sin borrados (revisión estática)', function () {
    $raiz = dirname(__DIR__) . '/src/Modules';
    foreach (['Catalogos/CatalogoRepository.php', 'Usuarios/UsuariosRepository.php'] as $archivo) {
        $fuente = (string) file_get_contents("$raiz/$archivo");
        afirmar(preg_match('/\b(DROP|ALTER|TRUNCATE|CREATE\s+TABLE|EXEC|DBCC|MERGE)\b/i', $fuente) === 0, "$archivo: sin DDL ni DBCC");
        afirmar(preg_match('/\$_(GET|POST|REQUEST)|\$peticion|->json\(\)/', $fuente) === 0, "$archivo: no lee la petición (eso es del controlador)");
        preg_match_all('/DELETE\s+FROM\s+(\w+)/i', $fuente, $borrados);
        afirmar($borrados[1] === [] || $borrados[1] === ['Telefono_Sucursal'], "$archivo: el único DELETE permitido es el de los teléfonos de la sucursal que se edita (" . implode(',', $borrados[1]) . ')');
        afirmar(substr_count($fuente, 'beginTransaction()') >= 1 || !str_contains($fuente, 'UPDATE '), "$archivo: las modificaciones van en transacción");
    }
    $catalogo = (string) file_get_contents("$raiz/Catalogos/CatalogoRepository.php");
    afirmar(substr_count($catalogo, 'UPDLOCK, HOLDLOCK') >= 5, 'los cambios que dependen de otras filas las bloquean');
    afirmar(!str_contains($catalogo, 'DELETE FROM Ruta') && !str_contains($catalogo, 'DELETE FROM Vehiculo') && !str_contains($catalogo, 'DELETE FROM Sucursal'), 'las tarifas, vehículos y sucursales nunca se borran');
    $usuarios = (string) file_get_contents("$raiz/Usuarios/UsuariosRepository.php");
    afirmar(!str_contains($usuarios, 'DELETE') && !str_contains($usuarios, 'hashContrasena AS') && !str_contains($usuarios, 'pe.numeroDocumento AS') && !str_contains($usuarios, 'pe.telefono'), 'las cuentas no se borran y las consultas de lista no seleccionan hash, documento ni teléfono');
});

// ── Carreras (revisión del PR #11): la validación de «activo» y la diferencia de puesto se deciden CON LAS FILAS BLOQUEADAS ──────────
// Los repositorios simulados ejecutan un gancho al empezar la «transacción» para reproducir un cambio de otro gerente que se
// intercala justo antes de leer lo bloqueado. Si el servicio decidiera con una lectura anterior, estas pruebas fallarían.

prueba('despacho: programar un viaje revalida DENTRO de la transacción — un vehículo, transportista o agencia desactivados a la vez lo impiden', function () {
    $casos = [
        'vehículo' => [static function (ViajesFalso $r): void { $r->inactivos['vehiculos'][] = 'B7K-210'; }, 'placa'],
        'transportista' => [static function (ViajesFalso $r): void { $r->inactivos['transportistas'][] = 'pmamani'; }, 'transportista'],
        'agencia de destino' => [static function (ViajesFalso $r): void { $r->inactivos['sucursales'][] = 'ILO001'; }, 'destino'],
    ];
    foreach ($casos as $nombre => [$desactivar, $campo]) {
        [$r, $despacho] = escenarioViajes();
        $antes = count($r->viajes);
        $r->alIniciarCrearViaje = $desactivar; // el gerente desactiva JUSTO después de que el servicio validó el formato y antes del alta
        $e = esperarError(fn () => $despacho->programar(personal(), ['placa' => 'B7K-210', 'transportista' => 'pmamani', 'destino' => 'ILO001']), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$campo]), "$nombre desactivado a la vez: marca $campo (" . implode(',', array_keys($e->campos)) . ')');
        afirmar(count($r->viajes) === $antes, "$nombre desactivado a la vez: no se creó ningún viaje");
    }
    [$r, $despacho] = escenarioViajes();
    $r->alIniciarCrearViaje = static function (ViajesFalso $r): void { $r->inactivos['sucursales'][] = 'MOQ001'; };
    esperarError(fn () => $despacho->programar(personal(), ['placa' => 'B7K-210', 'transportista' => 'pmamani', 'destino' => 'ILO001']), 409, 'sucursal_inactiva');
    [$r, $despacho] = escenarioViajes();
    $r->alIniciarCrearViaje = static function (ViajesFalso $r): void { /* nadie desactiva nada */ };
    $antes = count($r->viajes);
    $despacho->programar(personal(), ['placa' => 'B7K-210', 'transportista' => 'pmamani', 'destino' => 'ILO001']);
    afirmar(count($r->viajes) === $antes + 1, 'control: sin desactivaciones el viaje se crea');
});

prueba('despacho: los tres recursos y todas las agencias se bloquean en un orden constante y se validan antes del INSERT (revisión estática)', function () {
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Despacho/DespachoRepository.php');
    $cuerpo = substr($fuente, (int) strpos($fuente, 'function crearViaje'), (int) strpos($fuente, 'function cargar') - (int) strpos($fuente, 'function crearViaje'));
    $s = strpos($cuerpo, 'FROM Sucursal WITH (UPDLOCK, HOLDLOCK)');
    $v = strpos($cuerpo, 'FROM Vehiculo WITH (UPDLOCK, HOLDLOCK)');
    $u = strpos($cuerpo, 'FROM Usuario WITH (UPDLOCK, HOLDLOCK)');
    $d = strpos($cuerpo, '$decidir([');
    $i = strpos($cuerpo, 'INSERT INTO Viaje');
    afirmar($s !== false && $v !== false && $u !== false && $s < $v && $v < $u, 'orden de bloqueos: sucursales → vehículo → usuario');
    afirmar($d !== false && $u < $d && $d < $i, 'se decide con lo leído bloqueado y recién después se inserta');
    afirmar(str_contains($cuerpo, 'sort($ids)'), 'las sucursales se bloquean por id ascendente');
    afirmar(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Modules/Despacho/RepositorioDespacho.php'), 'idVehiculoActivo'), 'ya no existen las consultas de «activo» fuera de la transacción');
});

prueba('cuentas: crear cuenta revalida la sucursal DENTRO de la transacción — una sucursal desactivada a la vez no recibe la cuenta', function () {
    [$r, $servicio] = escenarioCuentas();
    $r->alIniciarCrearCuenta = static function (UsuariosFalso $r): void { $r->sucursales['AQP001'] = false; };
    $e = esperarError(fn () => $servicio->crear(SESION_GERENTE, cuentaPersonalValida()), 422, 'datos_invalidos');
    afirmar(isset($e->campos['sucursal']) && $r->altas === [], 'no se creó la cuenta y se marca la sucursal');
    $r->alIniciarCrearCuenta = null;
    $r->sucursales['AQP001'] = true;
    afirmar($servicio->crear(SESION_GERENTE, cuentaPersonalValida())['sucursal']['codigo'] === 'AQP001', 'control: con la sucursal activa se crea');
});

prueba('cuentas: editar calcula el cambio de puesto con la fila BLOQUEADA — datos obsoletos no deciden', function () {
    // A) Antes del bloqueo la cuenta estaba en AQP001 (sin viajes) y se pide AQP001 («sin cambios»), pero otro gerente la movió a MOQ001
    //    y le asignó un viaje: con la fila bloqueada SÍ hay cambio y el viaje lo impide. Con la lectura anterior se habría permitido.
    [$r, $servicio] = escenarioCuentas();
    $r->cuentas[4]['viajesAbiertos'] = 0;
    $r->alIniciarCambiarPuesto = static function (UsuariosFalso $r): void {
        $r->cuentas[4]['sucursalCodigo'] = 'MOQ001';
        $r->cuentas[4]['viajesAbiertos'] = 1;
    };
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'pmamani', ['rol' => 'TRANSPORTISTA', 'sucursal' => 'AQP001']), 409, 'transportista_con_viajes');
    afirmar($r->cuentas[4]['sucursalCodigo'] === 'MOQ001', 'la cuenta no se tocó');

    // B) Antes del bloqueo había un cambio (AQP001 → MOQ001) con un viaje abierto, pero otro gerente ya la dejó en MOQ001: con la fila
    //    bloqueada NO hay cambio y guardar no es un error. Con la lectura anterior se habría rechazado.
    [$r, $servicio] = escenarioCuentas();
    $r->alIniciarCambiarPuesto = static function (UsuariosFalso $r): void { $r->cuentas[4]['sucursalCodigo'] = 'MOQ001'; };
    $c = $servicio->editar(SESION_GERENTE, 'pmamani', ['rol' => 'TRANSPORTISTA', 'sucursal' => 'MOQ001']);
    afirmar($c['sucursal']['codigo'] === 'MOQ001' && $c['rol'] === 'TRANSPORTISTA', 'sin cambio real: se acepta aunque tenga un viaje abierto');

    // C) El rol también se compara con el bloqueado: otro gerente cambió a un administrador a TRANSPORTISTA con un viaje abierto.
    [$r, $servicio] = escenarioCuentas();
    $r->alIniciarCambiarPuesto = static function (UsuariosFalso $r): void {
        $r->cuentas[1]['rol'] = 'TRANSPORTISTA';
        $r->cuentas[1]['viajesAbiertos'] = 2;
    };
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'MOQ001']), 409, 'transportista_con_viajes');

    // D) Otro gerente convirtió la cuenta en no editable (un cliente no es personal) entre medias.
    [$r, $servicio] = escenarioCuentas();
    $r->alIniciarCambiarPuesto = static function (UsuariosFalso $r): void { $r->cuentas[1]['rol'] = 'CLIENTE'; };
    esperarError(fn () => $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 409, 'cuenta_no_editable');

    // E) La sucursal de destino se desactivó a la vez: solo importa si hay cambio de puesto.
    [$r, $servicio] = escenarioCuentas();
    $r->alIniciarCambiarPuesto = static function (UsuariosFalso $r): void { $r->sucursales['AQP001'] = false; };
    $e = esperarError(fn () => $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']), 422, 'datos_invalidos');
    afirmar(isset($e->campos['sucursal']) && $r->cuentas[1]['sucursalCodigo'] === 'MOQ001', 'sucursal inactiva: se rechaza el cambio y la cuenta no se mueve');
});

prueba('catálogos: «Nueva ruta» revalida las dos agencias DENTRO de la transacción — una desactivada a la vez impide el alta', function () {
    foreach (['ILO001' => 'origen', 'AQP001' => 'destino'] as $desactivada => $campo) {
        [$r, $servicio] = escenarioCatalogo();
        $antes = count($r->rutas);
        $r->alIniciarCrearRuta = static function (CatalogoFalso $r) use ($desactivada): void {
            foreach ($r->sucursales as $i => $s) {
                if ($s['codigoSucursal'] === $desactivada) {
                    $r->sucursales[$i]['activa'] = false;
                }
            }
        };
        $e = esperarError(fn () => $servicio->crearRuta(['origen' => 'ILO001', 'destino' => 'AQP001', 'tipoEnvio' => 'PAQUETE', 'precioBase' => '12', 'precioPorKm' => '0.02', 'distanciaKm' => '330', 'tiempoHoras' => 5]), 422, 'datos_invalidos');
        afirmar(isset($e->campos[$campo]) && count($r->rutas) === $antes, "agencia $desactivada desactivada a la vez: marca $campo y no se crea la ruta");
    }
});

prueba('orden de bloqueos del sistema: sucursales → vehículo → usuario → rutas; cada operación del gerente lo respeta (revisión estática)', function () {
    $raiz = dirname(__DIR__) . '/src/Modules';
    $cuerpoDe = static function (string $archivo, string $desde, string $hasta) use ($raiz): string {
        $f = (string) file_get_contents("$raiz/$archivo");
        $i = (int) strpos($f, $desde);
        $j = strpos($f, $hasta, $i + 1);

        return substr($f, $i, ($j === false ? strlen($f) : $j) - $i);
    };
    $crearCuenta = $cuerpoDe('Usuarios/UsuariosRepository.php', 'function crearCuenta', 'function cuenta(');
    afirmar(strpos($crearCuenta, 'FROM Sucursal WITH (UPDLOCK, HOLDLOCK)') < strpos($crearCuenta, '$decidir(') && strpos($crearCuenta, '$decidir(') < strpos($crearCuenta, 'INSERT INTO Usuario'), 'crearCuenta: bloquea la sucursal, decide y recién inserta');
    $puesto = $cuerpoDe('Usuarios/UsuariosRepository.php', 'function cambiarPuesto', 'function cambiarEstado');
    afirmar(strpos($puesto, 'FROM Sucursal WITH (UPDLOCK, HOLDLOCK)') < strpos($puesto, 'FROM Usuario AS u WITH (UPDLOCK, HOLDLOCK)') && strpos($puesto, 'FROM Usuario AS u WITH (UPDLOCK, HOLDLOCK)') < strpos($puesto, '$decidir(') && strpos($puesto, '$decidir(') < strpos($puesto, 'UPDATE Usuario'), 'cambiarPuesto: sucursal → cuenta → decide → UPDATE');
    $ruta = $cuerpoDe('Catalogos/CatalogoRepository.php', 'function crearRuta', 'function nuevoPrecio');
    afirmar(strpos($ruta, 'FROM Sucursal WITH (UPDLOCK, HOLDLOCK)') < strpos($ruta, 'FROM Ruta WITH (UPDLOCK, HOLDLOCK)') && strpos($ruta, 'FROM Ruta WITH (UPDLOCK, HOLDLOCK)') < strpos($ruta, '$decidir(') && str_contains($ruta, 'asort($ordenados)'), 'crearRuta: sucursales por id → rutas → decide → INSERT');
    foreach (['Usuarios/UsuariosService.php', 'Catalogos/CatalogoService.php', 'Despacho/DespachoService.php'] as $servicio) {
        afirmar(!str_contains((string) file_get_contents("$raiz/$servicio"), 'idSucursalActiva') && !str_contains((string) file_get_contents("$raiz/$servicio"), 'idVehiculoActivo'), "$servicio: no consulta «activo» fuera de la transacción");
    }
});

// ── Reactivar personal en una sucursal INACTIVA (revisión del PR #11) ───────────────────────────────────────────────────────────
prueba('cuentas: desactivar cuenta → desactivar sucursal → reactivar es 409; tras moverla a una agencia activa, se reactiva', function () {
    [$r, $servicio] = escenarioCuentas();
    $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    $r->sucursales['MOQ001'] = false; // el gerente desactiva la sucursal (ya sin personal activo ni operaciones)
    $e = esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true]), 409, 'sucursal_inactiva');
    afirmar(str_contains($e->getMessage(), 'MOQ001') && str_contains($e->getMessage(), 'Editar'), 'el mensaje dice cuál sucursal y qué hacer: ' . $e->getMessage());
    afirmar($r->cuentas[1]['activo'] === false, 'la cuenta sigue desactivada');
    $c = $servicio->editar(SESION_GERENTE, 'lflores', ['rol' => 'ADMINISTRADOR', 'sucursal' => 'AQP001']); // mover una cuenta desactivada sí se puede
    afirmar($c['sucursal']['codigo'] === 'AQP001' && $c['activa'] === false, 'movida a una agencia activa, sigue desactivada');
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'ahora sí se reactiva');
    // y reactivando la sucursal también se puede reactivar sin moverla
    $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    $r->sucursales['AQP001'] = false;
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true]), 409, 'sucursal_inactiva');
    $r->sucursales['AQP001'] = true;
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'con la sucursal reactivada, se reactiva');
});

prueba('cuentas: también aplica al TRANSPORTISTA; sin viajes abiertos se desactiva y su reactivación depende de la sucursal', function () {
    [$r, $servicio] = escenarioCuentas();
    $r->cuentas[4]['viajesAbiertos'] = 0;
    $servicio->cambiarEstado(SESION_GERENTE, 'pmamani', ['activo' => false]);
    $r->sucursales['AQP001'] = false;
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'pmamani', ['activo' => true]), 409, 'sucursal_inactiva');
    afirmar($r->cuentas[4]['activo'] === false, 'sigue desactivado');
});

prueba('cuentas: las reactivaciones de gerente, cliente y empresario no dependen de ninguna sucursal; desactivar nunca se bloquea por ello', function () {
    [$r, $servicio] = escenarioCuentas();
    $r->sucursales = ['AQP001' => false, 'MOQ001' => false]; // ninguna sucursal activa
    foreach (['mrojas', 'comsur'] as $usuario) {
        $servicio->cambiarEstado(SESION_GERENTE, $usuario, ['activo' => false]);
        afirmar($servicio->cambiarEstado(SESION_GERENTE, $usuario, ['activo' => true])['activa'] === true, "$usuario se reactiva");
    }
    $r->cuentas[] = ['nombreUsuario' => 'gerente2', 'nombreCompleto' => 'Otra Gerente', 'rol' => 'GERENTE', 'sucursalCodigo' => null, 'sucursalNombre' => null, 'activo' => false, 'viajesAbiertos' => 0];
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'gerente2', ['activo' => true])['activa'] === true, 'un gerente (sin sucursal) se reactiva');
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false])['activa'] === false, 'desactivar personal de una sucursal inactiva se permite');
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false])['activa'] === false, 'repetir la desactivación no es un error');
    $r->cuentas[1]['activo'] = true; // una cuenta ya activa
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'una cuenta ya activa no se bloquea al «reactivarla»');
});

prueba('cuentas: carrera reactivar / desactivar la sucursal — se decide con la sucursal leída bloqueada', function () {
    // A) La sucursal se desactiva justo antes de que la reactivación lea lo bloqueado: se rechaza y la cuenta no cambia.
    [$r, $servicio] = escenarioCuentas();
    $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    $r->alIniciarCambiarEstado = static function (UsuariosFalso $r): void { $r->sucursales['MOQ001'] = false; };
    esperarError(fn () => $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true]), 409, 'sucursal_inactiva');
    afirmar($r->cuentas[1]['activo'] === false, 'A: sigue desactivada');
    // B) Otro gerente la mueve a una agencia activa justo antes del bloqueo: con la sucursal NUEVA ya puede reactivarse.
    [$r, $servicio] = escenarioCuentas();
    $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    $r->sucursales['MOQ001'] = false;
    $r->alIniciarCambiarEstado = static function (UsuariosFalso $r): void { $r->cuentas[1]['sucursalCodigo'] = 'AQP001'; };
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'B: se decide con la sucursal que la cuenta tiene al bloquearla');
    // C) Control: sin ninguna intercalación el resultado es el esperado.
    [$r, $servicio] = escenarioCuentas();
    $servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => false]);
    $r->alIniciarCambiarEstado = static function (UsuariosFalso $r): void { /* nadie cambia nada */ };
    afirmar($servicio->cambiarEstado(SESION_GERENTE, 'lflores', ['activo' => true])['activa'] === true, 'C: control');
});

prueba('cuentas: cambiarEstado bloquea sucursal → gerentes → cuenta, reintenta si la cuenta se movió y decide antes del UPDATE (revisión estática)', function () {
    $f = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Usuarios/UsuariosRepository.php');
    $c = substr($f, (int) strpos($f, 'function cambiarEstado'), (int) strpos($f, '// ── Utilidades') - (int) strpos($f, 'function cambiarEstado'));
    $s = strpos($c, 'FROM Sucursal WITH (UPDLOCK, HOLDLOCK)');
    $g = strpos($c, "rol = 'GERENTE' AND activo = 1");
    $u = strpos($c, 'FROM Usuario WITH (UPDLOCK, HOLDLOCK) WHERE nombreUsuario');
    $d = strpos($c, '$decidir([');
    $up = strpos($c, 'UPDATE Usuario SET activo');
    afirmar($s !== false && $g !== false && $u !== false && $s < $g && $g < $u && $u < $d && $d < $up, 'orden: sucursal → gerentes → cuenta → decide → UPDATE');
    afirmar(str_contains($c, '$idActual !== $idCandidata') && str_contains($c, 'continue;') && str_contains($c, "'cuenta_modificada'"), 'reintenta si la cuenta se movió de sucursal y termina con 409 si no se estabiliza');
    afirmar(str_contains($c, "'sucursalActiva' =>"), 'entrega el estado de la sucursal bloqueada');
});

// ── Cambiar la PROPIA contraseña: todos los roles, sin ampliar la edición de datos personales ──────────────────────────────────
/** Un escenario con una cuenta de cada rol y su persona. */
function escenarioContrasenas(): array
{
    [$auth, $cuentas, , $servicioAuth] = escenarioCuenta();
    $claves = [2 => ['admin1', Roles::ADMINISTRADOR, 'ClaveAdmin#1'], 6 => ['transp1', Roles::TRANSPORTISTA, 'ClaveTransp#1']];
    foreach ($claves as $id => [$usuario, $rol, $clave]) {
        $auth->registros[] = registro($id, $usuario, $clave, $rol, true, 3);
        $cuentas->personas[60 + $id] = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '4000000' . $id, 'nombreCompleto' => 'Persona ' . $usuario, 'telefono' => '90000000' . $id, 'telefonoAuxiliar' => null, 'correo' => null];
        $cuentas->usuarioPersona[$id] = 60 + $id;
    }
    $cuentas->personas[61] = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '45781236', 'nombreCompleto' => 'Rosa María Quispe Mamani', 'telefono' => '987654321', 'telefonoAuxiliar' => null, 'correo' => null];
    $cuentas->usuarioPersona[1] = 61;
    $auth->registros[] = registro(7, 'inactivo1', 'ClaveInact#1', Roles::ADMINISTRADOR, false, 3);

    return [$auth, $cuentas, new CuentaService($cuentas), $servicioAuth];
}

prueba('contraseña propia: el backend deja cambiarla a los cinco roles con sesión vigente, y solo a ellos (sin sesión 401; cuenta desactivada 401)', function () use ($limpiar) {
    $limpiar();
    [$auth, , , $servicioAuth] = escenarioContrasenas();
    $guardia = new RequiereSesion($servicioAuth); // igual que en Cuenta/rutas.php para POST /api/cuenta/contrasena
    esperarError(fn () => $guardia(peticion('POST', '/api/cuenta/contrasena')), 401, 'no_autenticado');
    foreach ([1 => 'gerente', 2 => 'administrador', 6 => 'transportista', 4 => 'cliente', 5 => 'empresario'] as $id => $rol) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        $guardia(peticion('POST', '/api/cuenta/contrasena')); // no lanza: el rol «' . $rol . '» puede cambiar SU contraseña
    }
    $limpiar();
    Sesion::abrir(['id' => 7, 'nombreUsuario' => 'inactivo1', 'rol' => Roles::ADMINISTRADOR, 'nombreCompleto' => 'X', 'sucursal' => null], false, Credencial::huella($auth->buscarPorId(7)['hashContrasena']));
    esperarError(fn () => $guardia(peticion('POST', '/api/cuenta/contrasena')), 401, 'sesion_invalida');
    $limpiar();
});

prueba('contraseña propia: los datos personales (GET y PATCH /api/cuenta) SIGUEN limitados a cliente y empresario — administrador, transportista y gerente reciben 403', function () use ($limpiar) {
    $limpiar();
    [$auth, , , $servicioAuth] = escenarioContrasenas();
    $propietarios = new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO], new RequiereSesion($servicioAuth)); // igual que en Cuenta/rutas.php
    foreach ([1, 2, 6] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        foreach (['GET', 'PATCH'] as $metodo) {
            esperarError(fn () => $propietarios(peticion($metodo, '/api/cuenta')), 403, 'acceso_denegado');
        }
    }
    foreach ([4, 5] as $id) {
        $limpiar();
        sesionDe($auth, $servicioAuth, $id);
        $propietarios(peticion('GET', '/api/cuenta')); // regresión: cliente y empresario conservan Mi cuenta
    }
    $limpiar();
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Cuenta/rutas.php');
    afirmar(preg_match("/post\('\/api\/cuenta\/contrasena', \[CuentaController::class, 'cambiarContrasena'\], \\\$autenticados\)/", $fuente) === 1, 'rutas.php: la contraseña usa solo $autenticados');
    afirmar(str_contains($fuente, '$autenticados = [new RequiereSesion()];') && preg_match("/get\('\/api\/cuenta', .*\\\$propietarios\)/", $fuente) === 1 && preg_match("/patch\('\/api\/cuenta', .*\\\$propietarios\)/", $fuente) === 1, 'rutas.php: GET y PATCH siguen con $propietarios');
});

prueba('contraseña propia: cada rol cambia SOLO la suya, con bcrypt, y las demás sesiones de esa cuenta dejan de valer', function () use ($limpiar) {
    foreach ([[1, 'gerente1', 'ClaveGerente#1'], [2, 'admin1', 'ClaveAdmin#1'], [6, 'transp1', 'ClaveTransp#1'], [4, 'cliente1', 'ClaveCliente#1'], [5, 'empresa1', 'ClaveEmpresa#1']] as [$id, $usuario, $actual]) {
        $limpiar();
        [$auth, , $servicio, $servicioAuth] = escenarioContrasenas();
        $c = new CuentaController($servicio);
        $guardia = new RequiereSesion($servicioAuth);
        $hashesAntes = array_column($auth->registros, 'hashContrasena', 'idUsuario');

        sesionDe($auth, $servicioAuth, $id);
        $huellaVieja = Sesion::huella(); // la de una sesión abierta en otro dispositivo
        // El cuerpo intenta apuntar a otra cuenta: se ignora, solo cuenta la de la sesión.
        $r = leer($c->cambiarContrasena(peticion('POST', '/api/cuenta/contrasena', json_encode(['contrasenaActual' => $actual, 'contrasenaNueva' => 'NuevaClave#2026', 'idUsuario' => 1, 'usuario' => 'gerente1'])), []));
        afirmar($r['estado'] === 200 && $r['cuerpo']['data'] === ['contrasenaCambiada' => true], "$usuario: respuesta 200");
        $despues = array_column($auth->registros, 'hashContrasena', 'idUsuario');
        foreach ($despues as $idOtro => $hash) {
            if ($idOtro === $id) {
                afirmar($hash !== $hashesAntes[$id] && str_starts_with($hash, '$2y$12$') && password_verify('NuevaClave#2026', $hash), "$usuario: hash bcrypt nuevo");
            } else {
                afirmar($hash === $hashesAntes[$idOtro], "$usuario: no tocó la contraseña de la cuenta $idOtro");
            }
        }
        $guardia(peticion('GET', '/x')); // esta sesión sigue válida (huella renovada)
        afirmar(Sesion::huella() === Credencial::huella($auth->buscarPorId($id)['hashContrasena']), "$usuario: esta sesión adopta la huella nueva");
        $limpiar();
        Sesion::abrir($servicioAuth->vigente($id), false, $huellaVieja);
        esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'sesion_invalida');
        esperarError(fn () => $servicioAuth->ingresar($usuario, $actual), 401, 'credenciales_invalidas');
        afirmar($servicioAuth->ingresar($usuario, 'NuevaClave#2026')['nombreUsuario'] === $usuario, "$usuario: ingresa con la nueva");
    }
    $limpiar();
});

prueba('contraseña propia: exige la actual y cumple las reglas de la nueva para el personal igual que para el cliente', function () use ($limpiar) {
    $limpiar();
    [$auth, , $servicio, $servicioAuth] = escenarioContrasenas();
    $c = new CuentaController($servicio);
    sesionDe($auth, $servicioAuth, 2);
    $hash = $auth->buscarPorId(2)['hashContrasena'];
    $huella = Sesion::huella();
    $e = esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => 'Equivocada#1', 'contrasenaNueva' => 'NuevaClave#2026'])), []), 422, 'contrasena_incorrecta');
    afirmar(isset($e->campos['contrasenaActual']), 'señala contrasenaActual');
    esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaNueva' => 'NuevaClave#2026'])), []), 422, 'contrasena_incorrecta');
    foreach ([['corta', 'corta'], ['igual a la actual', 'ClaveAdmin#1'], ['igual al usuario', 'ADMIN1'], ['de 73 caracteres', str_repeat('x', 73)]] as [$cuando, $nueva]) {
        $e = esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => 'ClaveAdmin#1', 'contrasenaNueva' => $nueva])), []), 422, 'datos_invalidos');
        afirmar(array_keys($e->campos) === ['contrasenaNueva'], "$cuando: marca contrasenaNueva");
    }
    afirmar($auth->buscarPorId(2)['hashContrasena'] === $hash && Sesion::huella() === $huella, 'ningún intento fallido cambió el hash ni la huella');
    $limpiar();
    esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => 'ClaveAdmin#1', 'contrasenaNueva' => 'NuevaClave#2026'])), []), 401, 'no_autenticado');
});

// ── Cambio de contraseña: dos solicitudes con la MISMA contraseña anterior no pueden confirmarse las dos (revisión del PR #11) ──────
prueba('contraseña propia: dos cambios simultáneos con la misma contraseña anterior — solo uno confirma; el otro recibe 409 y no escribe ni renueva la huella', function () use ($limpiar) {
    foreach ([2 => ['admin1', 'ClaveAdmin#1'], 4 => ['cliente1', 'ClaveCliente#1'], 1 => ['gerente1', 'ClaveGerente#1']] as $id => [$usuario, $actual]) {
        $limpiar();
        [$auth, $cuentas, $servicio, $servicioAuth] = escenarioContrasenas();
        $c = new CuentaController($servicio);
        sesionDe($auth, $servicioAuth, $id);
        $huellaA = Sesion::huella();
        // La petición B (otro dispositivo, misma contraseña anterior) lee, verifica y CONFIRMA entre la verificación de A y su UPDATE.
        $cuentas->antesDeActualizarHash = static function (CuentasFalso $c) use ($servicio, $id, $actual): void {
            $c->antesDeActualizarHash = null;
            $servicio->cambiarContrasena($id, $actual, 'ClaveDeB#2026');
        };
        $e = esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => $actual, 'contrasenaNueva' => 'ClaveDeA#2026'])), []), 409, 'contrasena_modificada');
        afirmar(!isset($e->campos['contrasenaActual']), "$usuario: es un conflicto, no una contraseña incorrecta");
        $hash = $auth->buscarPorId($id)['hashContrasena'];
        afirmar(password_verify('ClaveDeB#2026', $hash) && !password_verify('ClaveDeA#2026', $hash), "$usuario: quedó la contraseña de quien confirmó primero (B), no la de A");
        afirmar(Sesion::huella() === $huellaA, "$usuario: la petición perdedora NO renovó la huella de su sesión");
        esperarError(fn () => $servicioAuth->ingresar($usuario, 'ClaveDeA#2026'), 401, 'credenciales_invalidas');
        afirmar($servicioAuth->ingresar($usuario, 'ClaveDeB#2026')['nombreUsuario'] === $usuario, "$usuario: la ganadora sirve para ingresar");
        // Reintentar con la contraseña VIGENTE ya funciona (el conflicto es recuperable).
        $r = leer($c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => 'ClaveDeB#2026', 'contrasenaNueva' => 'ClaveDeA#2026'])), []));
        afirmar($r['estado'] === 200, "$usuario: repetir con la contraseña vigente confirma");
    }
    $limpiar();
});

prueba('contraseña propia: el segundo cambio con la contraseña anterior, ya confirmado el primero, sigue siendo «contraseña incorrecta» (422) y no un conflicto', function () use ($limpiar) {
    $limpiar();
    [$auth, , $servicio, $servicioAuth] = escenarioContrasenas();
    $servicio->cambiarContrasena(2, 'ClaveAdmin#1', 'ClaveDeB#2026');
    $hash = $auth->buscarPorId(2)['hashContrasena'];
    esperarError(fn () => $servicio->cambiarContrasena(2, 'ClaveAdmin#1', 'ClaveDeA#2026'), 422, 'contrasena_incorrecta');
    afirmar($auth->buscarPorId(2)['hashContrasena'] === $hash, 'no se escribió nada');
    $limpiar();
});

prueba('contraseña propia: el UPDATE es condicional sobre el hash anterior, con comparación binaria, y el servicio comprueba el resultado (revisión estática)', function () {
    $repo = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Cuenta/CuentaRepository.php');
    $c = substr($repo, (int) strpos($repo, 'function actualizarHash'), 1400);
    afirmar(str_contains($c, 'UPDATE Usuario SET hashContrasena = :nuevo WHERE idUsuario = :id AND hashContrasena COLLATE Latin1_General_BIN2 = :anterior'), 'UPDATE condicional por hash anterior (comparación binaria)');
    afirmar(str_contains($c, 'rowCount() === 1'), 'devuelve si afectó exactamente una fila');
    $svc = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Cuenta/CuentaService.php');
    afirmar(preg_match('/if \(!\$this->cuentas->actualizarHash\(\$idUsuario, \$hashActual, \$hashNuevo\)\) \{\s+throw new HttpException\(409, \'contrasena_modificada\'/', $svc) === 1, 'el servicio lanza 409 si el UPDATE no afectó la fila');
    $ctl = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Cuenta/CuentaController.php');
    afirmar(strpos($ctl, 'cambiarContrasena($this->idUsuarioDeLaSesion()') < strpos($ctl, 'Sesion::renovarHuella('), 'la huella solo se renueva después de que el servicio confirmó el cambio');
});

// ── Validador real pm10: revisión estática (NO se ejecuta; no hay SQL en estas pruebas) ───────────────────────────────────────────
prueba('pm10 (revisión estática): exige la autorización y UNA BASE AISLADA válida antes de hacer nada, y no contiene DDL ni DBCC ejecutables', function () {
    $d = dirname(__DIR__) . '/tests/integracion';
    $ps = (string) file_get_contents("$d/validar-pm10.ps1");
    $sinComentarios = preg_replace(['/<#.*?#>/s', '/^\s*#.*$/m'], '', $ps);
    $g1 = strpos($sinComentarios, 'if (-not $AutorizadoPorElPropietario)');
    $g2 = strpos($sinComentarios, "BaseAislada -cnotmatch '^JATSA_pm10_aislada_");
    $primeraAccion = strpos($sinComentarios, '. "$aqui\api.ps1"');
    afirmar($g1 !== false && $g2 !== false && $g1 < $g2 && $g2 < $primeraAccion, 'la autorización y una base aislada válida se exigen antes de cualquier acción');
    afirmar(substr_count($sinComentarios, 'exit 2') === 2 && str_contains($sinComentarios, "-match '(?i)encomiendas'"), 'ambas rechazan con exit 2 y todo nombre con «Encomiendas» se rechaza: pm10 ya no corre contra la base habitual');    $sinTextos = preg_replace(["/'[^'\n]*'/", '/"[^"\n]*"/'], '', (string) $sinComentarios); // los mensajes de «Rechazado» mencionan DBCC solo para prohibirlo
    afirmar(preg_match('/\b(DROP|ALTER|TRUNCATE|DBCC|CHECKIDENT|CREATE\s+TABLE)\b/i', (string) $sinTextos) === 0, 'el PowerShell no ejecuta DDL ni DBCC');
    afirmar(preg_match('/Leer\s+"[^"]*\b(INSERT|UPDATE|DELETE|MERGE)\b/i', $ps) === 0, 'toda consulta directa del orquestador es SELECT');
    $php = (string) file_get_contents("$d/escritura_pm10.php");
    $php = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $php);
    afirmar(preg_match('/\b(DROP|ALTER|TRUNCATE|DBCC|CHECKIDENT|CREATE\s+TABLE)\b/i', (string) $php) === 0, 'el PHP de escritura no ejecuta DDL ni DBCC');
    afirmar(str_contains((string) $php, 'abortar(\'Operación no permitida'), 'rechaza cualquier operación que no sea preparar, ruta-antigua o limpiar');
});

prueba('pm10 (revisión estática): la limpieza solo borra tablas que el módulo escribe, con listas del manifiesto, en una transacción y verificando cada DELETE', function () {
    $php = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/escritura_pm10.php');
    preg_match_all('/DELETE FROM (\w+)/', $php, $m);
    afirmar($m[1] === ['Viaje', 'Telefono_Sucursal', 'Ruta', 'Usuario', 'Persona', 'Vehiculo', 'Sucursal'], 'DELETE solo sobre ' . implode(',', $m[1]) . ' y en orden hijo → padre');
    foreach (['Pago', 'Envio', 'Reserva', 'Bulto', 'Envio_Viaje'] as $tabla) {
        afirmar(!preg_match("/DELETE FROM $tabla\b/", $php), "nunca borra de $tabla");
    }
    afirmar(substr_count($php, 'DELETE FROM') === 7 && preg_match_all('/DELETE FROM \w+ WHERE [^\']*IN \(/', $php) === 7, 'cada DELETE está acotado por una lista (IN) del manifiesto');
    afirmar(strpos($php, '$pdo->beginTransaction();', (int) strpos($php, "if (\$op === 'limpiar')")) < strpos($php, '$afectadas !== $esperadas') && str_contains($php, 'rollBack()'), 'borra dentro de una transacción y la deshace si algún DELETE no afecta lo esperado');
    afirmar(!preg_match('/<=\s*\{\$ident/', $php) && substr_count($php, 'pm10_clasificarFilas(') >= 8, 'ya NO decide la autoría con «id > IDENT_CURRENT»: clasifica cada tabla con el conjunto real de ids de la instantánea inicial');
    afirmar(str_contains($php, "\$clasificacion['originales'] !== []") && str_contains($php, "\$clasificacion['ajenas'] !== []"), 'aborta si una clave del manifiesto coincide con una fila original o si hay filas nuevas ajenas');
    afirmar(str_contains($php, 'no se borra nada') || str_contains($php, 'No se borra nada'), 'ante cualquier divergencia no borra nada');
    afirmar(preg_match("/'(9000010\\\\d\|2000000010\\\\d)'/", $php) === 0 || str_contains($php, '9000010'), 'solo reconoce documentos temporales pm10');
});

prueba('pm10 (revisión estática): separa DATOS ORIGINALES de CONTADORES IDENTITY y no promete restaurar IDENTITY', function () {
    $d = dirname(__DIR__) . '/tests/integracion';
    $f = (string) file_get_contents("$d/pm10-funciones.ps1");
    $i = strpos($f, 'function Get-ComparacionOriginales');
    $cuerpo = substr($f, (int) $i, (int) strpos($f, '# Clasifica el IDENT_CURRENT') - (int) $i);
    afirmar(!str_contains($cuerpo, '.ident') && !str_contains($cuerpo, 'IDENT_CURRENT'), 'la comparación de datos originales NO mira IDENT_CURRENT');
    afirmar(str_contains($f, 'RETROCEDIO') && str_contains($f, 'Inesperado') && str_contains($f, 'Avanzo'), 'los contadores se clasifican (avanzó / retrocedió / inesperado), no se exige que coincidan');
    $ps = (string) file_get_contents("$d/validar-pm10.ps1");
    afirmar(str_contains($ps, 'NO se promete que coincidan') && str_contains($ps, 'carreras perdidas') && str_contains($ps, 'DESCARTABLE'), 'el informe y la cabecera explican que el avance es irreversible, puede superar las altas y que se prefiere una base aislada');
    afirmar(str_contains($ps, '$codigoSalida = if ($mal -eq 0 -and $limpio) { 0 } else { 1 }'), 'exit 0 solo sin fallos y con los datos originales comprobados');
    afirmar(str_contains($ps, 'interbloqueos') && str_contains($ps, '1205'), 'cuenta e informa los interbloqueos (1205) sin suponerlos imposibles');
});

// ── pm10: identificación segura de filas temporales (revisión del PR #11) ───────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/tests/integracion/pm10_reglas.php';

prueba('pm10: una tabla INICIALMENTE VACÍA — su primera fila (id = semilla) se reconoce como propia; el criterio anterior «id > IDENT_CURRENT» la habría rechazado', function () {
    $identSemilla = 1; // IDENT_CURRENT de una tabla vacía que nunca recibió una fila
    $creadas = [1, 2, 3];
    afirmar(array_values(array_filter($creadas, static fn (int $id): bool => $id > $identSemilla)) === [2, 3], 'demostración: con el criterio viejo la fila id 1 NO se habría reconocido (la limpieza fallaría con una fila de prueba viva)');
    $r = pm10_clasificarFilas([], [1, 2, 3], [1, 2, 3]);
    afirmar($r === ['propias' => [1, 2, 3], 'originales' => [], 'ajenas' => []], 'con el conjunto real de ids (vacío) las tres filas son propias: ' . json_encode($r));
    $r = pm10_clasificarFilas([], [], []);
    afirmar($r === ['propias' => [], 'originales' => [], 'ajenas' => []], 'tabla vacía antes y después (nada se creó): nada que hacer');
});

prueba('pm10: una clave del manifiesto que coincide con una fila ORIGINAL se detecta y no se confunde con una propia', function () {
    $r = pm10_clasificarFilas([1, 2, 3], [1, 2, 3, 4], [2, 4]);
    afirmar($r['originales'] === [2] && $r['propias'] === [4] && $r['ajenas'] === [], 'el id 2 ya existía: original; el 4 es propio: ' . json_encode($r));
    $r = pm10_clasificarFilas([5, 9], [5, 9], [9]);
    afirmar($r['originales'] === [9] && $r['propias'] === [], 'una fila original con la clave del manifiesto no es borrable');
    // El hueco de una fila borrada antes de la prueba no cambia nada: la decisión no depende del orden de los ids.
    $r = pm10_clasificarFilas([1, 5], [1, 5, 3], [3]);
    afirmar($r['propias'] === [3] && $r['originales'] === [], 'un id MENOR que el máximo original, pero que no existía, es propio (hueco reutilizado o semilla)');
});

prueba('pm10: cualquier fila nueva que no sea del manifiesto es AJENA (otro proceso escribió, o algo quedó sin anotar) y detiene la limpieza', function () {
    $r = pm10_clasificarFilas([1, 2], [1, 2, 3, 4, 5], [3, 4]);
    afirmar($r['propias'] === [3, 4] && $r['ajenas'] === [5], 'el id 5 es nuevo y no está anotado: ' . json_encode($r));
    $r = pm10_clasificarFilas([], [1, 2], [1]);
    afirmar($r['propias'] === [1] && $r['ajenas'] === [2], 'también en una tabla inicialmente vacía');
    $r = pm10_clasificarFilas([1, 2], [1, 2, 3], []);
    afirmar($r['propias'] === [] && $r['ajenas'] === [3], 'sin claves anotadas, una fila nueva es ajena (p. ej. una carga Envio_Viaje inesperada)');
    $r = pm10_clasificarFilas([1, 2], [1, 2], [7]);
    afirmar($r === ['propias' => [], 'originales' => [], 'ajenas' => []], 'una clave anotada que nunca llegó a crearse (el manifiesto se anota antes) no es un problema');
    $r = pm10_clasificarFilas([1], [1, 2, 2], [2, 2]);
    afirmar($r['propias'] === [2], 'los ids repetidos en la consulta no duplican');
});

prueba('pm10: las listas de ids del manifiesto se validan (vacía sí; textos, ceros, negativos y repetidos no)', function () {
    afirmar(pm10_idsValidos([]) === [] && pm10_idsValidos([3, 7]) === [3, 7], 'listas válidas, incluida la vacía');
    foreach ([null, 'x', [1, '2'], [0], [-4], [1, 1], [1.5], [[1]]] as $malo) {
        afirmar(pm10_idsValidos($malo) === null, 'rechaza ' . json_encode($malo));
    }
});

prueba('pm10 (revisión estática): el orquestador se detiene ante un paso fundamental fallido, distingue 500 genéricos de interbloqueos y no suma tablas hash con claves repetidas', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    afirmar(preg_match('/function Critico\(.*?throw "Se detiene/s', $ps) === 1, 'Critico lanza para detener la validación (la limpieza y el informe siguen en finally)');
    afirmar(substr_count($ps, "Critico '") >= 10, 'las creaciones y los inicios de sesión de los que dependen las fases siguientes son críticos');
    afirmar(!preg_match('/\(\$ruta \+ @\{/', $ps) && str_contains($ps, 'Get-ConCambios $ruta @{ destino'), 'la variante origen = destino se construye con una copia, no con «$ruta + @{ destino = … }»');
    afirmar(str_contains($ps, 'Evaluar500') && substr_count($ps, 'Evaluar500 $res') >= 5 && str_contains($ps, 'Get-Clasificacion500'), 'todas las carreras clasifican sus 500 con la evidencia del registro');
    afirmar(!preg_match('/Estado -eq 500 \}\) \{ \$script:interbloqueos\+\+/', $ps) && !str_contains($ps, '$interbloqueos++'), 'ya no se cuenta cada 500 como interbloqueo');
    afirmar(str_contains($ps, 'Get-ResultadoCarreraContrasena $res') && str_contains($ps, "if (\$entraA) { \$pw.cliente = \$nA }"), 'C1 evalúa la carrera con quién confirmó y qué contraseña sirve');
    afirmar(str_contains($ps, '$manifiesto.idsAntes[$t] = $ids') && !str_contains($ps, 'identAntes.Viaje') && !str_contains($ps, 'identAntes.Ruta'), 'la autoría ya no usa IDENT_CURRENT (solo se anota para informar el avance)');
});

prueba('pm10 (revisión estática): los trabajos paralelos exigen finalización confirmada y respuesta por tarea, y sin ella NO hay limpieza automática', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    $enParalelo = substr($ps, (int) strpos($ps, 'function EnParalelo('), (int) strpos($ps, 'function Tarea(') - (int) strpos($ps, 'function EnParalelo('));
    afirmar(!str_contains($enParalelo, '| Receive-Job') && !preg_match('/Wait-Job[^\n]*\|\s*Receive-Job/', $enParalelo), 'ya no hace «Wait-Job -Timeout | Receive-Job» (que descartaba en silencio los trabajos sin terminar)');
    afirmar(str_contains($enParalelo, 'Get-EvaluacionTrabajos $infos') && str_contains($enParalelo, 'if (-not $evaluacion.Confirmado)') && str_contains($enParalelo, 'throw "INCIDENCIA'), 'evalúa todos los trabajos y lanza una incidencia si no están confirmados');
    $final = substr($ps, (int) strpos($ps, "finally {\n    # 1)"));
    $final = $final === '' ? substr($ps, (int) strpos($ps, 'finally {')) : $final;
    $stop = strpos($final, 'Stop-Process -Id $s.Id');
    $espera = strpos($final, 'WaitForExit(20000)');
    $decision = strpos($final, 'Get-DecisionLimpieza');
    $bloqueo = strpos($final, 'LIMPIEZA AUTOMÁTICA DETENIDA');
    $limpiar = strpos($final, "escritura_pm10.php\" limpiar");
    afirmar($stop !== false && $espera !== false && $decision !== false && $stop < $espera && $espera < $decision, 'detiene los servidores PHP, confirma que terminaron y solo entonces decide');
    afirmar($bloqueo !== false && $limpiar !== false && $decision < $bloqueo && $bloqueo < $limpiar, 'la rama «limpieza detenida» precede a la que ejecuta el DELETE');
    afirmar(str_contains($final, 'if ($escrituraIniciada -and -not $decision.Permitida)') && str_contains($final, 'NO se ejecutó ningún DELETE'), 'sin permiso explícito no se llama a escritura_pm10.php limpiar y se explica la recuperación controlada');
    afirmar(str_contains($final, 'Get-Job') && str_contains($final, '$incidenciasParalelas'), 'también cuenta los trabajos aún vivos y las incidencias registradas');
});

prueba('plan de la base aislada (documento): cubre Env.php, la copia de trabajo, las sondas, el script original con USE/CREATE DATABASE y las autorizaciones', function () {
    $d = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/plan-pm10-bd-aislada.md');
    foreach (['prioriza `backend/.env`', 'copia de trabajo', 'sonda_db.php', 'SELECT DB_NAME()', 'USE JATSA_Encomiendas', 'copia derivada', 'SHA-256', 'jatsa_pm10_aislado', 'Win32_Process', 'Autorizaciones que se pedirían', 'hoy ninguna está concedida'] as $clave) {
        afirmar(str_contains($d, $clave) || stripos($d, $clave) !== false, "el plan menciona «{$clave}»");
    }
    afirmar(!str_contains($d, 'CREATE DATABASE JATSA_pm10') && !preg_match('/^\s*(CREATE|DROP|ALTER)\s+(DATABASE|LOGIN)/mi', $d), 'el plan no contiene sentencias SQL ejecutables: es solo un plan');
});

// ── pm10: aislamiento en una base SQL Server descartable (revisión estática; NADA de esto se ha ejecutado) ───────────────────────
prueba('pm10 aislado (revisión estática): la fase 0 verifica nombre, .env de la copia, DB_NAME() y cuenta Windows por CLI y por cada servidor y procesos; NO lee la base habitual (la cuenta es sysadmin)', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    $tokens = ['Test-NombreBdAislada $BaseAislada', 'jatsa_pm10_\d{8}_\d{6}', 'Test-EnvDeCopia', 'Test-DestinoAislado $destinoCli.bd', "HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW SERVER STATE')", "seed_gerente", 'sonda_db.plantilla', 'Get-ResultadoSondas', 'Test-LineaDeComandosEnCopia'];
    $anterior = -1;
    foreach ($tokens as $token) {
        $pos = strpos($ps, $token);
        afirmar($pos !== false, "el orquestador contiene «{$token}»");
        afirmar($pos > $anterior || $token === 'seed_gerente', "«{$token}» aparece después de la verificación anterior");
        $anterior = max($anterior, (int) $pos);
    }
    $escribe = strpos($ps, '$escrituraIniciada = $true');
    afirmar($escribe !== false && strpos($ps, 'Test-DestinoAislado $destinoCli.bd') < $escribe && strpos($ps, 'Test-EnvDeCopia') < $escribe, 'las verificaciones de .env y de CLI van ANTES de la primera escritura');
    $sinComentariosPs = (string) preg_replace('/<#.*?#>/s', '', $ps);
    afirmar(!str_contains($sinComentariosPs, 'JATSA_Encomiendas.dbo') && !str_contains($sinComentariosPs, 'NO puede leer la base habitual'), 'ya no se intenta leer la base habitual (con una cuenta sysadmin no tendría sentido y sería tocarla)');
    afirmar(!str_contains($ps, 'jatsa_pm10_aislado') && !str_contains($ps, 'LOGIN_AISLADO') && !str_contains($ps, 'JATSA_PM10_CLAVE'), 'el orquestador no usa ni conoce ningún login SQL ni contraseñas');
    afirmar(str_contains($ps, '$loginEsperado = Get-LoginWindowsActual') && str_contains($ps, 'loginEsperado = $loginEsperado') && str_contains($ps, '9b. Reverificación del destino tras las carreras'), 'la cuenta Windows esperada entra al manifiesto y el destino se reverifica tras las carreras');
    afirmar(preg_match("/-ArgumentList '-S', \"localhost:\\\$p\", '-t', \"\\\$backend\\\\public\"/", $ps) === 1 && !str_contains($ps, "'backend/public'") && !str_contains($ps, "'backend\\public"), 'los servidores se lanzan desde el backend de LA COPIA, no desde backend/public relativo');
    afirmar(str_contains($ps, 'Critico "los 4 servidores PHP están conectados SOLO a la base aislada') && str_contains($ps, "Critico 'ningún php.exe de la prueba se lanzó fuera de la copia de trabajo'"), 'la sonda y los procesos son condiciones críticas: si fallan, se detiene antes de operar');
    afirmar(str_contains($ps, '$aqui = $PSScriptRoot') && str_contains($ps, '$backend = Split-Path (Split-Path $aqui -Parent) -Parent'), 'todo se resuelve desde donde está el script (la copia), no desde C:\Jatsa');
});

prueba('pm10 aislado (revisión estática): antes de limpiar se comprueban procesos PHP, trabajos y SESIONES/TRANSACCIONES SQL; sin certeza no hay DELETE; la sonda se retira', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    $final = substr($ps, (int) strrpos($ps, 'finally {'));
    $pos = [];
    foreach (['Stop-Process -Id $s.Id', 'WaitForExit(20000)', 'sonda_db.php', 'sys.dm_tran_session_transactions', 'sys.dm_exec_sessions', 'Get-EvaluacionSesionesSql', 'Get-DecisionLimpieza $incidenciasParalelas $procesosVivos $trabajosVivos $problemasSql', 'LIMPIEZA AUTOMÁTICA DETENIDA', 'reactivar-semilla', 'escritura_pm10.php" limpiar'] as $token) {
        $pos[$token] = strpos($final, $token);
        afirmar($pos[$token] !== false, "el bloque final contiene «{$token}»");
    }
    afirmar($pos['Stop-Process -Id $s.Id'] < $pos['WaitForExit(20000)'] && $pos['WaitForExit(20000)'] < $pos['sys.dm_tran_session_transactions'] && $pos['Get-EvaluacionSesionesSql'] < $pos['Get-DecisionLimpieza $incidenciasParalelas $procesosVivos $trabajosVivos $problemasSql'], 'orden: detener PHP → confirmar que terminó → preguntar a SQL Server → decidir');
    afirmar($pos['Get-DecisionLimpieza $incidenciasParalelas $procesosVivos $trabajosVivos $problemasSql'] < $pos['LIMPIEZA AUTOMÁTICA DETENIDA'] && $pos['LIMPIEZA AUTOMÁTICA DETENIDA'] < $pos['reactivar-semilla'] && $pos['reactivar-semilla'] < $pos['escritura_pm10.php" limpiar'], 'la rama «detenida» precede a la que restaura la semilla y ejecuta el DELETE');
    afirmar(str_contains($final, 's.database_id = DB_ID()') && str_contains($final, 's.session_id <> @@SPID') && str_contains($final, 'is_user_process = 1') && !str_contains($final, 'login_name = SUSER_SNAME()'), 'consulta las sesiones de usuario en la base aislada (no por login: la cuenta Windows es la del propietario) distintas de la propia');
    afirmar(str_contains($final, 'catch { $evSql = [pscustomobject]@{ Segura = $false'), 'si no puede consultar a SQL Server, el resultado es «no seguro»');
    $f = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/pm10-aislamiento.ps1');
    afirmar(str_contains($f, '"$puedeVer" -ne \'1\'') && str_contains($f, 'no se puede verificar'), 'sin VIEW SERVER STATE la ausencia de filas no se toma como prueba');
});

prueba('pm10 aislado (revisión estática): escritura_pm10.php exige la base aislada del manifiesto y que ESTA conexión sea esa base, antes de cualquier operación', function () {
    $php = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/escritura_pm10.php');
    $guarda = strpos($php, "if (\$destino['bd'] !== \$baseAislada");
    $primeraOperacion = strpos($php, "if (\$op === 'preparar')");
    afirmar($guarda !== false && $primeraOperacion !== false && strpos($php, "\$manifiesto['baseAislada']") < $guarda && $guarda < $primeraOperacion, 'comprueba baseAislada y DB_NAME()/ORIGINAL_LOGIN() antes de preparar, reactivar-semilla, ruta-antigua o limpiar');
    afirmar(str_contains($php, "stripos((string) \$destino['bd'], 'encomiendas') !== false") && str_contains($php, "\$manifiesto['loginEsperado']") && str_contains($php, 'auth_scheme') && !str_contains($php, 'jatsa_pm10_aislado') && str_contains($php, 'exit') && str_contains($php, ', 5)') || str_contains($php, 'abortar(\'Esta conexión NO es la base aislada'), 'rechaza cualquier base con «Encomiendas» y exige la cuenta Windows esperada con autenticación de Windows');
    afirmar(preg_match("/UPDATE Usuario SET activo = 1 WHERE nombreUsuario = 'seed_gerente' AND rol = 'GERENTE' AND activo = 0/", $php) === 1, 'reactivar-semilla solo toca a «seed_gerente»');
    preg_match_all('/\bUPDATE\s+(\w+)/i', (string) preg_replace(['~/\*.*?\*/~s', '~^\s*(//|\*).*$~m'], '', $php), $u);
    afirmar($u[1] === ['Usuario'], 'el único UPDATE del script es el de la semilla: ' . implode(',', $u[1]));
});

prueba('pm10 aislado (revisión estática): crear_bd_aislada solo ejecuta CREATE DATABASE con autenticación de Windows: sin logins, sin usuarios, sin permisos, sin contraseñas', function () {
    $d = dirname(__DIR__) . '/tests/integracion';
    $ps = (string) file_get_contents("$d/crear_bd_aislada.ps1");
    $php = (string) file_get_contents("$d/crear_bd_aislada.php");
    $sinComentarios = preg_replace(['/<#.*?#>/s', '/^\s*#.*$/m'], '', $ps);
    afirmar(strpos($sinComentarios, 'if (-not $AutorizadoCrearBase)') < strpos($sinComentarios, 'Test-NombreBdAislada') && str_contains($sinComentarios, 'exit 2'), 'sin -AutorizadoCrearBase no hace nada (exit 2) y antes de leer ninguna variable');
    $php = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $php);
    preg_match_all('/exec\("([A-Z ]+)/', $php, $m);
    afirmar($m[1] === ['CREATE DATABASE '], 'la única sentencia de escritura es CREATE DATABASE: ' . implode('|', $m[1]));
    afirmar(!preg_match('/\b(CREATE|ALTER|DROP)\s+(LOGIN|USER|ROLE)\b|\bGRANT\b|\bsp_configure\b|\bxp_instance_regwrite\b|LoginMode/i', $php . $sinComentarios), 'no crea logins ni usuarios, no concede permisos y no toca el modo de autenticación');
    afirmar(!preg_match('/\bDROP\b/i', $php) && !preg_match('/\bDELETE\b|\bUPDATE\b|\bINSERT\b/i', $php), 'no borra ni modifica nada');
    afirmar(strpos($php, 'Ya existe la base aislada') < strpos($php, 'CREATE DATABASE'), 'si la base ya existe, se detiene antes de crear nada');
    afirmar(str_contains($php, 'new PDO(sprintf(\'sqlsrv:Server=%s;Database=%s;') && str_contains($php, ', null, null,'), 'conecta sin usuario ni contraseña (autenticación integrada de Windows)');
    afirmar(str_contains($php, "IS_SRVROLEMEMBER('sysadmin')") && str_contains($php, 'auth_scheme') && str_contains($php, 'NO usa autenticación de Windows'), 'comprueba que la conexión es de Windows y que la cuenta es sysadmin');
    afirmar(!str_contains($php, "'JATSA_Encomiendas'") && !str_contains($php, 'JATSA_Encomiendas.dbo') && !str_contains($ps, 'CLAVE') && !str_contains($php, 'CLAVE') && !str_contains($php, 'jatsa_pm10_aislado'), 'no abre la base habitual y no maneja claves ni el login SQL anterior');
    afirmar(!str_contains($ps, 'docs\\JATSA_Envios') && !str_contains($php, 'JATSA_Envios-SQLQuery1'), 'no usa ni menciona el script SQL original');
});

prueba('altas de Ruta: el id sale del MISMO INSERT (OUTPUT INSERTED.idRuta), se exige positivo y no queda ningún SCOPE_IDENTITY() en una sentencia aparte', function () {
    $raiz = dirname(__DIR__);
    $repo = (string) file_get_contents($raiz . '/src/Modules/Catalogos/CatalogoRepository.php');
    $esc = (string) file_get_contents($raiz . '/tests/integracion/escritura_pm10.php');
    $quitar = static fn (string $t): string => (string) preg_replace(['~/\*.*?\*/~s', '~^\s*(//|\*).*$~m'], '', $t);
    $repoSin = $quitar($repo);
    $escSin = $quitar($esc);
    afirmar(preg_match('/INSERT INTO Ruta[^\']*OUTPUT INSERTED\.idRuta\s+VALUES/s', $repoSin) === 1, 'insertarRuta: OUTPUT INSERTED.idRuta en el mismo INSERT, antes de VALUES');
    afirmar(preg_match('/INSERT INTO Ruta[^\']*OUTPUT INSERTED\.idRuta\s+VALUES/s', $escSin) === 1, 'ruta-antigua de pm10: OUTPUT INSERTED.idRuta en el mismo INSERT');
    afirmar(str_contains($repoSin, '$alta->fetchColumn()') && str_contains($repoSin, '$alta->closeCursor()') && str_contains($repoSin, '(int) $id <= 0'), 'insertarRuta: lee con fetchColumn(), cierra el cursor y rechaza un id no positivo');
    afirmar(str_contains($escSin, '$alta->fetchColumn()') && str_contains($escSin, '$alta->closeCursor()') && str_contains($escSin, '(int) $idRutaAntigua <= 0'), 'ruta-antigua: lee con fetchColumn(), cierra el cursor y aborta con un id no positivo');
    // Sin id positivo, insertarRuta lanza ANTES del commit (se deshace la transacción): nunca un 201 con una ruta vacía.
    afirmar(strpos($repoSin, 'No se obtuvo el id de la ruta insertada') !== false && strpos($repoSin, 'insertarRuta(') < strpos($repoSin, 'No se obtuvo el id de la ruta insertada'), 'el id inválido provoca una excepción dentro de la transacción');
    // Búsqueda estática de casos equivalentes en el alcance del PR: backend/src y backend/tests/integracion.
    $hallazgos = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $archivo) {
        $ruta = str_replace('\\', '/', (string) $archivo);
        if (!str_ends_with($ruta, '.php') || str_contains($ruta, '/tests/catalogos.php') || str_contains($ruta, '/vendor/')) {
            continue;
        }
        if (preg_match('/SCOPE_IDENTITY\s*\(|->lastInsertId\s*\(|@@IDENTITY/i', $quitar((string) file_get_contents($ruta))) === 1) {
            $hallazgos[] = $ruta;
        }
    }
    afirmar($hallazgos === [], 'no queda SCOPE_IDENTITY()/lastInsertId()/@@IDENTITY en el backend: ' . implode(', ', $hallazgos));
    $ps = (string) file_get_contents($raiz . '/tests/integracion/validar-pm10.ps1');
    afirmar(str_contains($ps, '[int]$r.Json.data.ruta.id -gt 0') && str_contains($ps, "ruta.origen.codigo -eq 'PMX901'") && str_contains($ps, '[int]$r.Json.data.ruta.id -ne $idAntigua'), 'pm10 exige id positivo y origen/destino correctos en Nueva ruta y en Nuevo precio');
});
prueba('pm10 (validador): adopta la cookie renovada tras cambiar la contraseña y todos los conteos de tuberías van con @(...)', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    $cambio = strpos($ps, "cambia su propia contraseña (200)");
    $cookie = strpos($ps, '$cookieNueva = GalletaDe $r');
    $usa = strpos($ps, "la sesión que cambió la contraseña sigue válida");
    afirmar($cambio !== false && $cookie > $cambio && $usa > $cookie, 'la cookie renovada se toma de la respuesta del cambio, antes de usar esa sesión');
    afirmar(str_contains($ps, '$a = $cookieNueva') && str_contains($ps, 'el servidor entrega la cookie renovada'), 'la sesión que cambió la contraseña pasa a usar la cookie nueva y se exige que el servidor la entregue');
    // Ningún ".Count" sobre una tubería con filtro sin envolver en @(...): un único resultado no tiene .Count en PowerShell 5.1.
    $sinEnvolver = [];
    foreach (preg_split('/\R/', $ps) as $n => $linea) {
        if (preg_match('/(?<!@)\(\s*\$\w+\s*\|\s*(\?|Where-Object)\b[^()]*(\{[^{}]*\})?\s*\)\.Count/', $linea) === 1) {
            $sinEnvolver[] = $n + 1;
        }
    }
    afirmar($sinEnvolver === [], 'todos los conteos de tuberías con filtro usan @(...): líneas ' . implode(',', $sinEnvolver));
    afirmar(str_contains($ps, '(@($res | ? { $_.Estado -in 200, 201 }).Count -ge 1)') && str_contains($ps, '(@($res | ? { $_.Estado -notin 200, 409, 401, 500 }).Count -eq 0)'), 'C2–C4 y C7 cuentan con @(...)');
});
prueba('pm10 aislado (revisión estática): cargar_bd_aislada nunca ejecuta el script original, no toca backend/.env, valida la copia y la estructura y carga solo una semilla sintética en una base vacía', function () {
    $d = dirname(__DIR__) . '/tests/integracion';
    $ps = (string) file_get_contents("$d/cargar_bd_aislada.ps1");
    $php = (string) file_get_contents("$d/cargar_bd_aislada.php");
    $sin = preg_replace(['/<#.*?#>/s', '/^\s*#.*$/m'], '', $ps);
    afirmar(strpos($sin, 'if (-not $AutorizadoCargarEstructura -and -not $SoloPreparar)') !== false && strpos($sin, 'if (-not $AutorizadoCargarEstructura -and -not $SoloPreparar)') < strpos($sin, 'Test-NombreBdAislada'), 'sin autorización B (o -SoloPreparar) no hace nada');
    afirmar(preg_match('/sqlcmd|Invoke-Sqlcmd|osql/i', $sin) === 0, 'no usa sqlcmd ni nada que ejecute el script original');
    preg_match_all('/\$sqlOriginal/', $sin, $usos);
    afirmar(substr_count($sin, 'ReadAllText($sqlOriginal') === 1 && substr_count($sin, 'Get-FileHash -LiteralPath $sqlOriginal') === 2 && str_contains($sin, '$hashSql0 -ne $hashSql1'), 'el original solo se LEE (una vez) y se compara su SHA-256 antes y después');
    afirmar(str_contains($sin, 'Get-FileHash -LiteralPath $envHabitual') && !preg_match('/ReadAllText\(\$envHabitual|Get-Content[^\n]*\$envHabitual|Copy-Item[^\n]*\.env/', $sin) && str_contains($sin, '$hashEnv0 -ne $hashEnv1'), 'del backend/.env habitual solo se calcula el hash (nunca se lee ni se copia) y se comprueba que no cambió');
    afirmar(str_contains($sin, 'Get-DiferenciasHashes') && str_contains($sin, 'la copia no es idéntica al repositorio') && str_contains($sin, 'Test-DdlDerivado $derivada $Base'), 'verifica la copia archivo a archivo y la estructura derivada antes de aplicarla');
    afirmar(strpos($sin, 'cargar_bd_aislada.php') > strpos($sin, 'Test-DdlDerivado $derivada $Base') && str_contains($sin, "Join-Path \$backendCopia 'tests\\integracion\\cargar_bd_aislada.php'"), 'el PHP de carga se ejecuta DESDE LA COPIA (con el .env aislado), después de validar');
    $php = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $php);
    afirmar(strpos($php, "if (\$actual['bd'] !== \$base") < strpos($php, 'sys.tables') && strpos($php, 'sys.tables') < strpos($php, '$pdo->exec($lote)'), 'orden: confirma base y login, confirma que está VACÍA y recién aplica la estructura');
    afirmar(preg_match('/(exec|query)\(\s*["\x27]\s*(CREATE|DROP|ALTER)\s+(DATABASE|LOGIN|USER)/i', $php) === 0 && substr_count($php, 'JATSA_Encomiendas') <= 2, 'no crea ni borra bases (solo menciona JATSA_Encomiendas para rechazarla)');
    afirmar(str_contains($php, "'99999901'") && str_contains($php, 'Sintético') && str_contains($php, 'TST001') && str_contains($php, 'random_bytes(24)'), 'la semilla es sintética (documentos 9999990x, nombres «Sintético», sucursales TST…) y la contraseña del gerente semilla es aleatoria y desconocida');
    afirmar(preg_match("/'Reserva' => 0, 'Envio' => 0, 'Bulto' => 0, 'Viaje' => 0, 'Envio_Viaje' => 0, 'Pago' => 0/", $php) === 1, 'Reserva, Envio, Bulto, Viaje, Envio_Viaje y Pago quedan VACÍAS a propósito (tablas inicialmente vacías)');
});

prueba('pm10 aislado: lo que NO se declara — la cobertura de una base completamente vacía y de datos históricos reales queda fuera, y el plan/código lo dicen', function () {
    $ps = (string) file_get_contents(dirname(__DIR__) . '/tests/integracion/validar-pm10.ps1');
    afirmar(str_contains($ps, 'NO se ejercita') && str_contains($ps, 'base completamente vacía'), 'el validador declara que no cubre una base completamente vacía');
    $plan = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/plan-pm10-bd-aislada.md');
    afirmar(stripos($plan, '**una sola** base') !== false && str_contains($plan, 'seed_gerente') && stripos($plan, 'no se declara') !== false, 'el plan empieza con UNA base con semilla (seed_gerente) y no declara cobertura de casos no ejecutados');
});