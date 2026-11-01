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

    public function idSucursalActiva(string $codigo): ?int
    {
        foreach ($this->sucursales as $i => $s) {
            if ($s['codigoSucursal'] === $codigo && $s['activa']) {
                return $i + 1;
            }
        }

        return null;
    }

    public function rutas(): array
    {
        return $this->rutas;
    }

    private function sucursalPorId(int $id): array
    {
        return $this->sucursales[$id - 1];
    }

    public function crearRuta(int $idOrigen, int $idDestino, array $datos, string $hoy, callable $decidir): array
    {
        $o = $this->sucursalPorId($idOrigen);
        $d = $this->sucursalPorId($idDestino);
        $vigentes = count(array_filter($this->rutas, static fn (array $r): bool => $r['origenCodigo'] === $o['codigoSucursal'] && $r['destinoCodigo'] === $d['codigoSucursal'] && $r['tipoEnvio'] === $datos['tipoEnvio'] && $r['vigenteHasta'] === null));
        $decidir(['vigentes' => $vigentes]);
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
    /** @var array<string, int> código → id (solo sucursales activas) */
    public array $sucursales = ['AQP001' => 1, 'MOQ001' => 2];
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

    public function idSucursalActiva(string $codigo): ?int
    {
        return $this->sucursales[$codigo] ?? null;
    }

    public function crearCuenta(int $idPersona, ?int $idSucursal, string $nombreUsuario, string $hashContrasena, string $rol): void
    {
        $this->altas[] = ['idPersona' => $idPersona, 'idSucursal' => $idSucursal, 'usuario' => $nombreUsuario, 'hash' => $hashContrasena, 'rol' => $rol];
        $codigo = $idSucursal === null ? null : (array_search($idSucursal, $this->sucursales, true) ?: null);
        $this->cuentas[] = ['nombreUsuario' => $nombreUsuario, 'nombreCompleto' => 'Persona nueva', 'rol' => $rol, 'sucursalCodigo' => $codigo, 'sucursalNombre' => $codigo === null ? null : 'Agencia ' . $codigo, 'activo' => true, 'viajesAbiertos' => 0];
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

    public function cambiarPuesto(string $nombreUsuario, string $rol, ?int $idSucursal, callable $decidir): ?bool
    {
        foreach ($this->cuentas as $i => $c) {
            if (strcasecmp($c['nombreUsuario'], $nombreUsuario) === 0) {
                $decidir(['rol' => $c['rol'], 'activo' => $c['activo'], 'viajesAbiertos' => $c['viajesAbiertos']]);
                $codigo = array_search($idSucursal, $this->sucursales, true);
                $this->cuentas[$i]['rol'] = $rol;
                $this->cuentas[$i]['sucursalCodigo'] = $codigo === false ? null : $codigo;
                $this->cuentas[$i]['sucursalNombre'] = $codigo === false ? null : 'Agencia ' . $codigo;

                return true;
            }
        }

        return null;
    }

    public function cambiarEstado(string $nombreUsuario, bool $activo, callable $decidir): ?bool
    {
        $gerentes = count(array_filter($this->cuentas, static fn (array $c): bool => $c['rol'] === Roles::GERENTE && $c['activo']));
        foreach ($this->cuentas as $i => $c) {
            if (strcasecmp($c['nombreUsuario'], $nombreUsuario) === 0) {
                $decidir(['rol' => $c['rol'], 'activo' => $c['activo'], 'viajesAbiertos' => $c['viajesAbiertos'], 'gerentesActivos' => $gerentes]);
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
