<?php
declare(strict_types=1);

/**
 * Pruebas del módulo 0/1 sin dependencias externas ni acceso a la base de datos.
 * Ejecutar:  C:\php\php.exe backend/tests/ejecutar.php
 * Sale con código 1 si alguna prueba falla.
 */

use Jatsa\Core\Config;
use Jatsa\Core\Request;
use Jatsa\Core\Response;
use Jatsa\Core\Router;
use Jatsa\Middleware\Csrf;
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Modules\Auth\AuthController;
use Jatsa\Modules\Auth\AuthService;
use Jatsa\Modules\Auth\RepositorioUsuarios;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;
use Jatsa\Modules\Publico\AgenciasController;
use Jatsa\Modules\Publico\CotizadorController;
use Jatsa\Modules\Publico\CotizadorService;
use Jatsa\Modules\Publico\RepositorioAgencias;
use Jatsa\Modules\Publico\RepositorioRutas;
use Jatsa\Shared\Sesion;
use Jatsa\Shared\Tarifas;

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

Config::cargar($raiz);
ob_start(); // Evita "headers already sent" mientras se prueban cabeceras y sesiones.

// ── Mini marco de pruebas ───────────────────────────────────────────────────
$resultados = ['ok' => 0, 'fallos' => []];

function prueba(string $nombre, callable $cuerpo): void
{
    global $resultados;
    try {
        $cuerpo();
        $resultados['ok']++;
    } catch (Throwable $e) {
        $resultados['fallos'][] = $nombre . ' → ' . $e->getMessage();
    }
}

function afirmar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function esperarError(callable $accion, int $estado, string $codigo): HttpException
{
    try {
        $accion();
    } catch (HttpException $e) {
        afirmar($e->estado === $estado && $e->codigo === $codigo, "esperaba $estado/$codigo y fue {$e->estado}/{$e->codigo}");
        return $e;
    }
    throw new RuntimeException("esperaba el error $estado/$codigo y no hubo error");
}

/** @return array{estado: int, cuerpo: array<string, mixed>} */
function leer(Response $respuesta): array
{
    ob_start();
    $respuesta->enviar();
    $texto = (string) ob_get_clean();

    return ['estado' => (int) http_response_code(), 'cuerpo' => json_decode($texto, true) ?? []];
}

function peticion(string $metodo, string $ruta, string $cuerpo = '', array $cabeceras = []): Request
{
    return new Request($metodo, $ruta, [], $cuerpo, $cabeceras);
}

// ── Repositorio falso (sin base de datos) ───────────────────────────────────
final class RepoFalso implements RepositorioUsuarios
{
    /** @param list<array<string, mixed>> $registros */
    public function __construct(public array $registros)
    {
    }

    public function buscarPorNombre(string $nombreUsuario): ?array
    {
        foreach ($this->registros as $r) {
            if ($r['nombreUsuario'] === $nombreUsuario) {
                return $r;
            }
        }
        return null;
    }

    public function buscarPorId(int $idUsuario): ?array
    {
        foreach ($this->registros as $r) {
            if ($r['idUsuario'] === $idUsuario) {
                return $r;
            }
        }
        return null;
    }
}

function registro(int $id, string $usuario, string $clave, string $rol, bool $activo = true, ?int $sucursal = null): array
{
    return [
        'idUsuario' => $id,
        'nombreUsuario' => $usuario,
        'hashContrasena' => password_hash($clave, PASSWORD_BCRYPT, ['cost' => 4]),
        'rol' => $rol,
        'activo' => $activo,
        'idSucursal' => $sucursal,
        'nombreCompleto' => 'Persona ' . $usuario,
        'codigoSucursal' => $sucursal === null ? null : 'MOQ001',
        'nombreSucursal' => $sucursal === null ? null : 'Agencia Moquegua',
    ];
}

$repo = new RepoFalso([
    registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE),
    registro(2, 'admin1', 'ClaveAdmin#1', Roles::ADMINISTRADOR, true, 3),
    registro(3, 'inactivo1', 'ClaveInactivo#1', Roles::CLIENTE, false),
]);
$servicio = new AuthService($repo);

// ── AuthService ─────────────────────────────────────────────────────────────
prueba('ingreso correcto devuelve datos públicos sin hash', function () use ($servicio) {
    $datos = $servicio->ingresar('admin1', 'ClaveAdmin#1');
    afirmar($datos['rol'] === Roles::ADMINISTRADOR, 'rol');
    afirmar($datos['sucursal']['codigo'] === 'MOQ001', 'sucursal');
    afirmar(!str_contains(json_encode($datos), 'hash') && !str_contains(json_encode($datos), '$2y$'), 'filtra el hash');
});

prueba('contraseña incorrecta → 401 genérico', function () use ($servicio) {
    esperarError(fn () => $servicio->ingresar('admin1', 'mala'), 401, 'credenciales_invalidas');
});

prueba('usuario inexistente → mismo 401 y mismo mensaje', function () use ($servicio) {
    $a = esperarError(fn () => $servicio->ingresar('admin1', 'mala'), 401, 'credenciales_invalidas');
    $b = esperarError(fn () => $servicio->ingresar('noexiste', 'mala'), 401, 'credenciales_invalidas');
    afirmar($a->getMessage() === $b->getMessage(), 'los mensajes difieren y revelan qué usuarios existen');
});

prueba('cuenta desactivada con clave correcta → 403', function () use ($servicio) {
    $e = esperarError(fn () => $servicio->ingresar('inactivo1', 'ClaveInactivo#1'), 403, 'cuenta_desactivada');
    afirmar(str_contains($e->getMessage(), 'desactivada'), 'mensaje');
});

prueba('cuenta desactivada con clave incorrecta → 401 (no se revela el estado)', function () use ($servicio) {
    esperarError(fn () => $servicio->ingresar('inactivo1', 'mala'), 401, 'credenciales_invalidas');
});

prueba('campos vacíos → 422', function () use ($servicio) {
    esperarError(fn () => $servicio->ingresar('', 'x'), 422, 'datos_invalidos');
    esperarError(fn () => $servicio->ingresar('admin1', ''), 422, 'datos_invalidos');
});

prueba('entradas desmesuradas → 401 sin consultar', function () use ($servicio) {
    esperarError(fn () => $servicio->ingresar(str_repeat('a', 31), 'x'), 401, 'credenciales_invalidas');
    esperarError(fn () => $servicio->ingresar('admin1', str_repeat('a', 201)), 401, 'credenciales_invalidas');
});

prueba('vigente(): activa sí, desactivada y desconocida no', function () use ($servicio) {
    afirmar($servicio->vigente(1)['nombreUsuario'] === 'gerente1', 'activa');
    afirmar($servicio->vigente(3) === null, 'desactivada');
    afirmar($servicio->vigente(99) === null, 'inexistente');
});

// ── Router y middleware ─────────────────────────────────────────────────────
prueba('router: middleware se ejecuta en orden y antes del manejador', function () {
    $orden = [];
    $router = new Router();
    $router->get('/x', function () use (&$orden) {
        $orden[] = 'manejador';
        return Response::exito(['ok' => true]);
    }, [
        function () use (&$orden) { $orden[] = 'm1'; },
        function () use (&$orden) { $orden[] = 'm2'; },
    ]);
    $router->despachar(peticion('GET', '/x'));
    afirmar($orden === ['m1', 'm2', 'manejador'], 'orden: ' . implode(',', $orden));
});

prueba('router: un middleware que falla corta la petición', function () {
    $llamado = false;
    $router = new Router();
    $router->get('/x', function () use (&$llamado) {
        $llamado = true;
        return Response::exito([]);
    }, [function () { throw new HttpException(401, 'no_autenticado', 'x'); }]);
    esperarError(fn () => $router->despachar(peticion('GET', '/x')), 401, 'no_autenticado');
    afirmar(!$llamado, 'el manejador se ejecutó igualmente');
});

prueba('router: parámetros, 404 y 405', function () {
    $router = new Router();
    $router->get('/a/{id}', fn ($p, $params) => Response::exito($params));
    $r = leer($router->despachar(peticion('GET', '/a/EN123')));
    afirmar($r['cuerpo']['data']['id'] === 'EN123', 'parámetro');
    esperarError(fn () => $router->despachar(peticion('GET', '/b')), 404, 'ruta_no_encontrada');
    esperarError(fn () => $router->despachar(peticion('POST', '/a/1')), 405, 'metodo_no_permitido');
});

// ── CSRF ────────────────────────────────────────────────────────────────────
prueba('csrf: GET libre; POST/DELETE exigen X-Requested-With', function () {
    Csrf::verificar(peticion('GET', '/x'));
    Csrf::verificar(peticion('OPTIONS', '/x'));
    esperarError(fn () => Csrf::verificar(peticion('POST', '/x')), 403, 'solicitud_no_permitida');
    esperarError(fn () => Csrf::verificar(peticion('DELETE', '/x', '', ['x-requested-with' => 'otro'])), 403, 'solicitud_no_permitida');
    Csrf::verificar(peticion('POST', '/x', '', ['x-requested-with' => 'JATSA']));
});

// ── Sesión, RequiereSesion y RequiereRol ────────────────────────────────────
$nombreCookie = (string) Config::obtener('sesion.nombre');
$limpiar = function () use ($nombreCookie) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    unset($_COOKIE[$nombreCookie]);
    header_remove();
};

prueba('sesión sin cookie: no hay usuario y no se crea sesión', function () use ($limpiar) {
    $limpiar();
    afirmar(Sesion::usuario() === null, 'debía ser null');
    afirmar(session_status() !== PHP_SESSION_ACTIVE, 'se inició una sesión anónima');
});

// Nota: en PHP de consola headers_list() siempre está vacío, por eso los atributos de la cookie
// (HttpOnly, SameSite, Max-Age, borrado) se verifican por HTTP real, no aquí.
prueba('sesión normal: guarda el usuario y no recuerda', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), false);
    afirmar(Sesion::usuario()['nombreUsuario'] === 'gerente1', 'usuario');
    afirmar($_SESSION['recordar'] === false, 'recordar');
    afirmar($_SESSION['expira'] - time() <= 8 * 3600, 'inactividad mayor a 8 h');
});

prueba('sesión con "Recordarme": inactividad de 14 días', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), true);
    afirmar($_SESSION['recordar'] === true, 'recordar');
    afirmar($_SESSION['expira'] - time() > 13 * 86400, 'plazo demasiado corto');
});

prueba('sesión caducada por inactividad se descarta', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), false);
    $_SESSION['expira'] = time() - 1;
    afirmar(Sesion::usuario() === null, 'debía haber caducado');
});

prueba('abrir una sesión nueva regenera el identificador', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), false);
    $primero = session_id();
    Sesion::abrir($servicio->vigente(2), false);
    afirmar(session_id() !== $primero, 'mismo identificador (fijación de sesión)');
});

prueba('RequiereSesion: sin sesión → 401; con sesión pasa', function () use ($limpiar, $servicio) {
    $limpiar();
    $guardia = new RequiereSesion($servicio);
    esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'no_autenticado');
    Sesion::abrir($servicio->vigente(1), false);
    $guardia(peticion('GET', '/x'));
});

prueba('RequiereSesion: cuenta desactivada tras iniciar sesión → 401 y cierra', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), false);
    $_SESSION['usuario']['id'] = 3; // la sesión apunta a una cuenta desactivada
    esperarError(fn () => (new RequiereSesion($servicio))(peticion('GET', '/x')), 401, 'sesion_invalida');
    afirmar(Sesion::usuario() === null, 'la sesión debía cerrarse');
});

prueba('RequiereRol: rol permitido pasa; otro rol → 403; sin sesión → 401', function () use ($limpiar, $servicio) {
    $limpiar();
    $soloAdmin = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicio));
    esperarError(fn () => $soloAdmin(peticion('GET', '/x')), 401, 'no_autenticado');
    Sesion::abrir($servicio->vigente(1), false); // gerente
    esperarError(fn () => $soloAdmin(peticion('GET', '/x')), 403, 'acceso_denegado');
    Sesion::abrir($servicio->vigente(2), false); // administrador
    $soloAdmin(peticion('GET', '/x'));
});

prueba('cerrar sesión elimina al usuario', function () use ($limpiar, $servicio) {
    $limpiar();
    Sesion::abrir($servicio->vigente(1), true);
    Sesion::cerrar();
    afirmar(Sesion::usuario() === null, 'seguía habiendo usuario');
});

// ── AuthController ──────────────────────────────────────────────────────────
prueba('controlador: ingresar → 200 y deja sesión abierta', function () use ($limpiar, $servicio) {
    $limpiar();
    $controlador = new AuthController($servicio);
    $r = leer($controlador->ingresar(peticion('POST', '/api/auth/ingresar', json_encode(['usuario' => 'admin1', 'contrasena' => 'ClaveAdmin#1', 'recordarme' => false])), []));
    afirmar($r['estado'] === 200, 'estado ' . $r['estado']);
    afirmar($r['cuerpo']['data']['usuario']['rol'] === Roles::ADMINISTRADOR, 'rol');
    afirmar(Sesion::usuario()['nombreUsuario'] === 'admin1', 'sin sesión');
});

prueba('controlador: credenciales malas no abren sesión', function () use ($limpiar, $servicio) {
    $limpiar();
    $controlador = new AuthController($servicio);
    esperarError(fn () => $controlador->ingresar(peticion('POST', '/api/auth/ingresar', json_encode(['usuario' => 'admin1', 'contrasena' => 'mala'])), []), 401, 'credenciales_invalidas');
    afirmar(Sesion::usuario() === null, 'hay sesión tras un fallo');
});

prueba('controlador: cuerpo con tipos raros se trata como vacío (422)', function () use ($limpiar, $servicio) {
    $limpiar();
    $controlador = new AuthController($servicio);
    esperarError(fn () => $controlador->ingresar(peticion('POST', '/x', json_encode(['usuario' => ['a'], 'contrasena' => 123])), []), 422, 'datos_invalidos');
    esperarError(fn () => $controlador->ingresar(peticion('POST', '/x', '{roto'), []), 400, 'json_invalido');
});

prueba('controlador: salir es idempotente', function () use ($limpiar, $servicio) {
    $limpiar();
    $controlador = new AuthController($servicio);
    $r = leer($controlador->salir(peticion('POST', '/x'), []));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data']['sesionCerrada'] === true, 'sin sesión previa');
});

$limpiar();

// ── Módulo 2: tarifas, cotizador y agencias (repositorios simulados, sin base de datos) ──────
final class AgenciasFalso implements RepositorioAgencias
{
    /** @var list<array<string, mixed>> */
    public array $lista;

    public function __construct()
    {
        $this->lista = [
            ['codigo' => 'AQP001', 'nombre' => 'Agencia Arequipa Centro', 'departamento' => 'Arequipa', 'provincia' => 'Arequipa', 'distrito' => 'Cercado', 'direccion' => 'Av. Ejército 512', 'telefonos' => ['054201450', '054201451']],
            ['codigo' => 'ILO001', 'nombre' => 'Agencia Ilo', 'departamento' => 'Moquegua', 'provincia' => 'Ilo', 'distrito' => 'Ilo', 'direccion' => 'Av. Costanera 120', 'telefonos' => ['053481560']],
            ['codigo' => 'LIM001', 'nombre' => 'Agencia Lima Centro', 'departamento' => 'Lima', 'provincia' => 'Lima', 'distrito' => 'Cercado de Lima', 'direccion' => 'Av. Grau 450', 'telefonos' => ['014251230']],
            ['codigo' => 'MOQ001', 'nombre' => 'Agencia Moquegua', 'departamento' => 'Moquegua', 'provincia' => 'Mariscal Nieto', 'distrito' => 'Moquegua', 'direccion' => 'Jr. Ancash 325', 'telefonos' => ['053461120']],
        ];
    }

    public function listarActivas(): array
    {
        return $this->lista;
    }

    public function buscarActivaPorCodigo(string $codigo): ?array
    {
        foreach ($this->lista as $a) {
            if ($a['codigo'] === $codigo) {
                return $a;
            }
        }
        return null;
    }
}

final class RutasFalso implements RepositorioRutas
{
    public ?string $fechaRecibida = null;

    public function vigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array
    {
        $this->fechaRecibida = $fechaIso;
        $tabla = [
            'MOQ001>AQP001' => [
                ['tipoEnvio' => 'SOBRE', 'precioBase' => '8.00', 'precioPorKm' => '0.005', 'distanciaKm' => '230.0', 'tiempoHoras' => 4],
                ['tipoEnvio' => 'PAQUETE', 'precioBase' => '10.00', 'precioPorKm' => '0.020', 'distanciaKm' => '230.0', 'tiempoHoras' => 4],
            ],
            'AQP001>LIM001' => [['tipoEnvio' => 'PAQUETE', 'precioBase' => '10.00', 'precioPorKm' => '0.020', 'distanciaKm' => '1010.0', 'tiempoHoras' => 18]],
            'LIM001>ILO001' => [['tipoEnvio' => 'CARGA', 'precioBase' => '40.00', 'precioPorKm' => '0.080', 'distanciaKm' => '1200.0', 'tiempoHoras' => 24]],
            'ILO001>MOQ001' => [['tipoEnvio' => 'SOBRE', 'precioBase' => '8.00', 'precioPorKm' => '0.005', 'distanciaKm' => '100.0', 'tiempoHoras' => 2]],
        ];
        return $tabla[$codigoOrigen . '>' . $codigoDestino] ?? [];
    }
}

$rutasFalso = new RutasFalso();
$cotizador = new CotizadorService(new AgenciasFalso(), $rutasFalso, fn () => '2026-10-31');

prueba('tarifas: costo del bulto con los valores del informe y del prototipo', function () {
    $casos = [
        ['8.00', '0.005', '230.0', 915],     // SOBRE MOQ→AQP  S/ 9.15  (diapositiva 7)
        ['10.00', '0.020', '230.0', 1460],   // PAQUETE MOQ→AQP S/ 14.60 (diapositiva 7)
        ['10.00', '0.020', '1010.0', 3020],  // PAQUETE AQP→LIM S/ 30.20
        ['40.00', '0.080', '1200.0', 13600], // CARGA LIM→ILO   S/ 136.00
        ['8.00', '0.005', '100.0', 850],     // SOBRE ILO→MOQ   S/ 8.50
        ['8.00', '0.005', '1010.0', 1305],   // informe, tabla 19: SOBRE 1010 km S/ 13.05
        ['40.00', '0.080', '1010.0', 12080], // informe, tabla 19: CARGA 1010 km S/ 120.80
    ];
    foreach ($casos as [$base, $km, $dist, $esperado]) {
        $obtenido = Tarifas::costoBultoCentimos($base, $km, $dist);
        afirmar($obtenido === $esperado, "($base, $km, $dist) → esperaba $esperado y fue $obtenido");
    }
    afirmar(Tarifas::costoBultoCentimos(10, 0.02, 230) === 1460, 'también acepta números');
});

prueba('tarifas: redondeo medio hacia arriba como DECIMAL', function () {
    afirmar(Tarifas::costoBultoCentimos('0.00', '0.005', '1.0') === 1, '0.5 céntimo debe subir a 1');
    afirmar(Tarifas::costoBultoCentimos('0.00', '0.005', '0.9') === 0, '0.45 céntimo debe bajar a 0');
});

prueba('tarifas: tipo por peso en los límites (1, 30 y 300 kg)', function () {
    $casos = [[1, 'SOBRE'], [100, 'SOBRE'], [101, 'PAQUETE'], [3000, 'PAQUETE'], [3001, 'CARGA'], [30000, 'CARGA'], [30001, null]];
    foreach ($casos as [$centesimas, $esperado]) {
        afirmar(Tarifas::tipoPorPeso($centesimas) === $esperado, "peso $centesimas centésimas");
    }
});

prueba('tarifas: formato de soles', function () {
    afirmar(Tarifas::formatoSoles(1460) === '14.60' && Tarifas::formatoSoles(5) === '0.05' && Tarifas::formatoSoles(100) === '1.00', 'formato');
});

prueba('cotizador: MOQ001 → AQP001 con 8.80 kg reproduce la diapositiva 7', function () use ($cotizador) {
    $r = $cotizador->cotizar('MOQ001', 'AQP001', '8.80');
    afirmar($r['tipo'] === 'PAQUETE' && $r['cotizacion']['precioPorBulto'] === '14.60', 'tipo o precio');
    afirmar($r['cotizacion']['tiempoHoras'] === 4, 'tiempo');
    afirmar($r['cotizacion']['precioBase'] === '10.00' && $r['cotizacion']['precioPorKm'] === '0.020' && $r['cotizacion']['distanciaKm'] === '230', 'fórmula');
    afirmar($r['pesoKg'] === '8.80', 'peso normalizado');
    afirmar($r['origen']['nombre'] === 'Agencia Moquegua' && $r['destino']['nombre'] === 'Agencia Arequipa Centro', 'nombres');
    $t = array_column($r['tarifas'], null, 'tipo');
    afirmar($t['SOBRE']['precioPorBulto'] === '9.15' && $t['SOBRE']['pesoMaximoKg'] === 1, 'SOBRE');
    afirmar($t['PAQUETE']['pesoMaximoKg'] === 30, 'PAQUETE');
    afirmar($t['CARGA']['disponible'] === false && $t['CARGA']['precioPorBulto'] === null && $t['CARGA']['tiempoHoras'] === null, 'CARGA sin ruta vigente');
    afirmar(array_column($r['tarifas'], 'tipo') === ['SOBRE', 'PAQUETE', 'CARGA'], 'orden de tarifas');
});

prueba('cotizador: el peso decide el tipo (0.40 → SOBRE, 31 → CARGA sin ruta)', function () use ($cotizador) {
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', '0.40')['cotizacion']['precioPorBulto'] === '9.15', 'sobre');
    $carga = $cotizador->cotizar('MOQ001', 'AQP001', '31');
    afirmar($carga['tipo'] === 'CARGA' && $carga['cotizacion']['disponible'] === false, 'CARGA no disponible en esa ruta');
    $otra = $cotizador->cotizar('LIM001', 'ILO001', '85');
    afirmar($otra['cotizacion']['precioPorBulto'] === '136.00', 'CARGA de LIM a ILO');
});

prueba('cotizador: límites del peso (300 sí, 300.01 no)', function () use ($cotizador) {
    afirmar($cotizador->cotizar('LIM001', 'ILO001', '300')['tipo'] === 'CARGA', '300 kg');
    esperarError(fn () => $cotizador->cotizar('LIM001', 'ILO001', '300.01'), 422, 'peso_excedido');
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', '1')['tipo'] === 'SOBRE', '1 kg');
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', '1.01')['tipo'] === 'PAQUETE', '1.01 kg');
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', '30')['tipo'] === 'PAQUETE', '30 kg');
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', '30.01')['tipo'] === 'CARGA', '30.01 kg');
});

prueba('cotizador: pesos inválidos → 422', function () use ($cotizador) {
    foreach (['', 'abc', '-5', '0', '0.00', '1.234', '1e3', '8,8,8', ' ', '1234567', "8.8\n9"] as $peso) {
        esperarError(fn () => $cotizador->cotizar('MOQ001', 'AQP001', $peso), 422, 'datos_invalidos');
    }
});

prueba('cotizador: acepta coma decimal y espacios', function () use ($cotizador) {
    afirmar($cotizador->cotizar('MOQ001', 'AQP001', ' 8,80 ')['pesoKg'] === '8.80', 'coma decimal');
});

prueba('cotizador: sucursales inválidas', function () use ($cotizador) {
    esperarError(fn () => $cotizador->cotizar('MOQ001', 'MOQ001', '5'), 422, 'datos_invalidos');
    esperarError(fn () => $cotizador->cotizar('', 'AQP001', '5'), 422, 'datos_invalidos');
    $e = esperarError(fn () => $cotizador->cotizar('MOQ001', 'xx', '5'), 422, 'datos_invalidos');
    afirmar(str_contains($e->getMessage(), 'destino'), 'el mensaje debe hablar del destino');
    esperarError(fn () => $cotizador->cotizar('MOQ001', 'TAC001', '5'), 404, 'sucursal_no_encontrada'); // inactiva
    esperarError(fn () => $cotizador->cotizar('MOQ001', 'ZZZ999', '5'), 404, 'sucursal_no_encontrada');
});

prueba('cotizador: normaliza mayúsculas y la ruta es direccional', function () use ($cotizador, $rutasFalso) {
    afirmar($cotizador->cotizar(' moq001 ', 'aqp001', '5')['cotizacion']['precioPorBulto'] === '14.60', 'minúsculas');
    $inversa = $cotizador->cotizar('AQP001', 'MOQ001', '5');
    afirmar($inversa['cotizacion']['disponible'] === false && !in_array(true, array_column($inversa['tarifas'], 'disponible'), true), 'AQP→MOQ no tiene tarifas');
    afirmar($rutasFalso->fechaRecibida === '2026-10-31', 'la vigencia se consulta con la fecha de hoy');
});

prueba('controlador de cotización: lee la consulta, ignora tipos raros y responde 200', function () use ($cotizador) {
    $c = new CotizadorController($cotizador);
    $ok = leer($c->cotizar(new Request('GET', '/api/cotizacion', ['origen' => 'MOQ001', 'destino' => 'AQP001', 'peso' => '8.80'], '', []), []));
    afirmar($ok['estado'] === 200 && $ok['cuerpo']['data']['cotizacion']['precioPorBulto'] === '14.60', 'respuesta correcta');
    esperarError(fn () => $c->cotizar(new Request('GET', '/x', ['origen' => ['MOQ001'], 'destino' => 'AQP001', 'peso' => '8'], '', []), []), 422, 'datos_invalidos');
});

prueba('controlador de agencias: lista las activas con teléfonos', function () {
    $r = leer((new AgenciasController(new AgenciasFalso()))->listar(new Request('GET', '/api/agencias', [], '', []), []));
    $agencias = $r['cuerpo']['data']['agencias'];
    afirmar($r['estado'] === 200 && count($agencias) === 4, 'cantidad');
    afirmar($agencias[0]['codigo'] === 'AQP001' && $agencias[0]['telefonos'] === ['054201450', '054201451'], 'teléfonos múltiples');
    afirmar(!str_contains(json_encode($agencias), 'TAC001'), 'no debe aparecer la sucursal inactiva');
});

// ── Informe ─────────────────────────────────────────────────────────────────
ob_end_clean();
echo "Pruebas correctas: {$resultados['ok']}" . PHP_EOL;
foreach ($resultados['fallos'] as $fallo) {
    echo "FALLO: $fallo" . PHP_EOL;
}
echo count($resultados['fallos']) === 0 ? 'RESULTADO: todas correctas' . PHP_EOL : 'RESULTADO: hay fallos' . PHP_EOL;
exit(count($resultados['fallos']) === 0 ? 0 : 1);
