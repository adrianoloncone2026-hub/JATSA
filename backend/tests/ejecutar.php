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
use Jatsa\Shared\Credencial;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;
use Jatsa\Modules\Cuenta\CuentaController;
use Jatsa\Modules\Cuenta\CuentaRepository;
use Jatsa\Modules\Cuenta\CuentaService;
use Jatsa\Modules\Cuenta\RepositorioCuentas;
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
// Seguro: estas pruebas nunca deben tocar la base de datos. Con el nombre vacío, cualquier intento de
// conectarse (por ejemplo, un guardia que construya su servicio real) falla con 503 en lugar de conectar.
Config::fijar('db.nombre', '');
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

/**
 * Abre una sesión como lo hace el controlador de ingreso: con los datos públicos del usuario y la huella
 * de su contraseña actual (sin ella, RequiereSesion rechaza la sesión).
 */
function abrirSesion(int $idUsuario, bool $recordar = false): void
{
    global $repo, $servicio;
    Sesion::abrir($servicio->vigente($idUsuario), $recordar, Credencial::huella($repo->buscarPorId($idUsuario)['hashContrasena']));
}

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
    abrirSesion(1);
    afirmar(Sesion::usuario()['nombreUsuario'] === 'gerente1', 'usuario');
    afirmar($_SESSION['recordar'] === false, 'recordar');
    afirmar($_SESSION['expira'] - time() <= 8 * 3600, 'inactividad mayor a 8 h');
});

prueba('sesión con "Recordarme": inactividad de 14 días', function () use ($limpiar, $servicio) {
    $limpiar();
    abrirSesion(1, true);
    afirmar($_SESSION['recordar'] === true, 'recordar');
    afirmar($_SESSION['expira'] - time() > 13 * 86400, 'plazo demasiado corto');
});

prueba('sesión caducada por inactividad se descarta', function () use ($limpiar, $servicio) {
    $limpiar();
    abrirSesion(1);
    $_SESSION['expira'] = time() - 1;
    afirmar(Sesion::usuario() === null, 'debía haber caducado');
});

prueba('abrir una sesión nueva regenera el identificador', function () use ($limpiar, $servicio) {
    $limpiar();
    abrirSesion(1);
    $primero = session_id();
    abrirSesion(2);
    afirmar(session_id() !== $primero, 'mismo identificador (fijación de sesión)');
});

prueba('RequiereSesion: sin sesión → 401; con sesión pasa', function () use ($limpiar, $servicio) {
    $limpiar();
    $guardia = new RequiereSesion($servicio);
    esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'no_autenticado');
    abrirSesion(1);
    $guardia(peticion('GET', '/x'));
});

prueba('RequiereSesion: cuenta desactivada tras iniciar sesión → 401 y cierra', function () use ($limpiar, $servicio) {
    $limpiar();
    abrirSesion(1);
    $_SESSION['usuario']['id'] = 3; // la sesión apunta a una cuenta desactivada
    esperarError(fn () => (new RequiereSesion($servicio))(peticion('GET', '/x')), 401, 'sesion_invalida');
    afirmar(Sesion::usuario() === null, 'la sesión debía cerrarse');
});

prueba('RequiereRol: rol permitido pasa; otro rol → 403; sin sesión → 401', function () use ($limpiar, $servicio) {
    $limpiar();
    $soloAdmin = new RequiereRol([Roles::ADMINISTRADOR], new RequiereSesion($servicio));
    esperarError(fn () => $soloAdmin(peticion('GET', '/x')), 401, 'no_autenticado');
    abrirSesion(1); // gerente
    esperarError(fn () => $soloAdmin(peticion('GET', '/x')), 403, 'acceso_denegado');
    abrirSesion(2); // administrador
    $soloAdmin(peticion('GET', '/x'));
});

prueba('cerrar sesión elimina al usuario', function () use ($limpiar, $servicio) {
    $limpiar();
    abrirSesion(1, true);
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

// ── Módulo 3: huella de sesión, errores por campo y cuenta (repositorios simulados) ─────────
/**
 * Repositorio de cuentas en memoria, enlazado al de autenticación: lo que se registra aquí puede iniciar
 * sesión con AuthService y un cambio de contraseña se refleja en el mismo hash que usa la sesión.
 */
final class CuentasFalso implements RepositorioCuentas
{
    /** @var array<int, array<string, mixed>> */
    public array $personas = [];
    /** @var array<int, int> idUsuario => idPersona */
    public array $usuarioPersona = [];
    public int $siguientePersona = 100;
    public int $siguienteUsuario = 200;
    public int $altas = 0;

    public function __construct(private readonly RepoFalso $auth)
    {
        $this->personas[4] = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '71889345', 'nombreCompleto' => 'María Elena Rojas Ccori', 'telefono' => '987654323', 'telefonoAuxiliar' => null, 'correo' => null];
        $this->personas[5] = ['tipoDocumento' => 'RUC', 'numeroDocumento' => '20123456789', 'nombreCompleto' => 'Comercial Sur S.A.C.', 'telefono' => '987654324', 'telefonoAuxiliar' => null, 'correo' => 'ventas@comercialsur.pe'];
        $this->usuarioPersona = [4 => 4, 5 => 5];
        // Persona que ya envió/recibió algo pero aún no tiene cuenta.
        $this->personas[50] = ['tipoDocumento' => 'DNI', 'numeroDocumento' => '70234561', 'nombreCompleto' => 'Luis Alberto Flores Condori', 'telefono' => '987654322', 'telefonoAuxiliar' => null, 'correo' => 'luis.flores@mail.com'];
    }

    public function buscarPersonaPorDocumento(string $tipoDocumento, string $numeroDocumento): ?array
    {
        foreach ($this->personas as $id => $p) {
            if ($p['tipoDocumento'] === $tipoDocumento && $p['numeroDocumento'] === $numeroDocumento) {
                return ['idPersona' => $id, 'tieneUsuario' => in_array($id, $this->usuarioPersona, true)];
            }
        }
        return null;
    }

    public function usuarioExiste(string $nombreUsuario): bool
    {
        foreach ($this->auth->registros as $r) {
            if (strcasecmp($r['nombreUsuario'], $nombreUsuario) === 0) {
                return true;
            }
        }
        return false;
    }

    public function registrar(?int $idPersona, array $persona, string $nombreUsuario, string $hashContrasena, string $rol): void
    {
        $this->altas++;
        if ($idPersona === null) {
            $idPersona = $this->siguientePersona++;
            $this->personas[$idPersona] = $persona;
        }
        $idUsuario = $this->siguienteUsuario++;
        $this->usuarioPersona[$idUsuario] = $idPersona;
        $this->auth->registros[] = [
            'idUsuario' => $idUsuario, 'nombreUsuario' => $nombreUsuario, 'hashContrasena' => $hashContrasena, 'rol' => $rol,
            'activo' => true, 'idSucursal' => null, 'nombreCompleto' => $this->personas[$idPersona]['nombreCompleto'],
            'codigoSucursal' => null, 'nombreSucursal' => null,
        ];
    }

    public function buscarCuenta(int $idUsuario): ?array
    {
        $idPersona = $this->usuarioPersona[$idUsuario] ?? null;
        $usuario = $this->auth->buscarPorId($idUsuario);
        if ($idPersona === null || $usuario === null) {
            return null;
        }
        return ['idUsuario' => $idUsuario, 'idPersona' => $idPersona, 'nombreUsuario' => $usuario['nombreUsuario'], 'rol' => $usuario['rol'],
            'activo' => $usuario['activo'], 'fechaCreacion' => '2026-03-01'] + $this->personas[$idPersona];
    }

    public function actualizarContacto(int $idPersona, string $telefono, ?string $telefonoAuxiliar, ?string $correo): void
    {
        $this->personas[$idPersona]['telefono'] = $telefono;
        $this->personas[$idPersona]['telefonoAuxiliar'] = $telefonoAuxiliar;
        $this->personas[$idPersona]['correo'] = $correo;
    }

    public function obtenerHash(int $idUsuario): ?string
    {
        return $this->auth->buscarPorId($idUsuario)['hashContrasena'] ?? null;
    }

    public function actualizarHash(int $idUsuario, string $hashContrasena): void
    {
        foreach ($this->auth->registros as $i => $r) {
            if ($r['idUsuario'] === $idUsuario) {
                $this->auth->registros[$i]['hashContrasena'] = $hashContrasena;
            }
        }
    }
}

/** @return array{0: RepoFalso, 1: CuentasFalso, 2: CuentaService, 3: AuthService} */
function escenarioCuenta(): array
{
    $auth = new RepoFalso([
        registro(1, 'gerente1', 'ClaveGerente#1', Roles::GERENTE),
        registro(4, 'cliente1', 'ClaveCliente#1', Roles::CLIENTE),
        registro(5, 'empresa1', 'ClaveEmpresa#1', Roles::EMPRESARIO),
    ]);
    $cuentas = new CuentasFalso($auth);

    return [$auth, $cuentas, new CuentaService($cuentas), new AuthService($auth)];
}

/** Abre sesión como el controlador de ingreso (usuario público + huella de su contraseña actual). */
function sesionDe(RepoFalso $auth, AuthService $servicioAuth, int $idUsuario, bool $recordar = false): void
{
    Sesion::abrir($servicioAuth->vigente($idUsuario), $recordar, Credencial::huella($auth->buscarPorId($idUsuario)['hashContrasena']));
}

function registroValido(array $cambios = []): array
{
    return $cambios + [
        'tipoDocumento' => 'DNI', 'numeroDocumento' => '45678912', 'nombreCompleto' => 'Ana Torres Díaz', 'telefono' => '911222333',
        'telefonoAuxiliar' => '', 'correo' => '', 'usuario' => 'anatorres', 'contrasena' => 'Segura#2026',
    ];
}

/** Ejecuta una acción que debe fallar con 422 y devuelve los campos con error. */
function camposConError(callable $accion): array
{
    try {
        $accion();
    } catch (HttpException $e) {
        afirmar($e->estado === 422 && $e->codigo === 'datos_invalidos', "esperaba 422/datos_invalidos y fue {$e->estado}/{$e->codigo}");
        return array_keys($e->campos);
    }
    throw new RuntimeException('esperaba un 422 y no hubo error');
}

prueba('errores por campo: la respuesta de error incluye "campos" solo cuando los hay', function () {
    $con = leer(Response::error('datos_invalidos', 'Revisa.', 422, [], ['telefono' => 'El teléfono tiene 9 dígitos.']));
    afirmar($con['cuerpo']['error']['campos']['telefono'] === 'El teléfono tiene 9 dígitos.', 'campos presentes');
    $sin = leer(Response::error('ruta_no_encontrada', 'No.', 404));
    afirmar(!array_key_exists('campos', $sin['cuerpo']['error']), 'sin campos no debe aparecer la clave');
    $porExcepcion = leer(Jatsa\Core\ManejadorErrores::aRespuesta(new HttpException(422, 'datos_invalidos', 'x', [], ['usuario' => 'mal'])));
    afirmar($porExcepcion['estado'] === 422 && $porExcepcion['cuerpo']['error']['campos']['usuario'] === 'mal', 'una HttpException conserva sus campos');
});

prueba('huella: ingresarConHuella devuelve la huella de la contraseña y vigente() la exige', function () {
    [$auth, , , $servicioAuth] = escenarioCuenta();
    $acceso = $servicioAuth->ingresarConHuella('cliente1', 'ClaveCliente#1');
    afirmar($acceso['huella'] === Credencial::huella($auth->buscarPorId(4)['hashContrasena']), 'huella del hash actual');
    afirmar(!str_contains(json_encode($acceso['usuario']), $acceso['huella']), 'la huella no debe ir en los datos públicos');
    afirmar($servicioAuth->vigente(4, $acceso['huella']) !== null, 'huella correcta');
    afirmar($servicioAuth->vigente(4) !== null, 'sin huella indicada no se compara');
    afirmar($servicioAuth->vigente(4, 'huella-vieja') === null, 'huella distinta');
    afirmar($servicioAuth->vigente(4, '') === null, 'huella vacía no vale cuando se exige');
});

prueba('huella: una sesión abierta sin huella o con la huella anterior es rechazada', function () use ($limpiar) {
    [$auth, , , $servicioAuth] = escenarioCuenta();
    $guardia = new RequiereSesion($servicioAuth);

    $limpiar();
    Sesion::abrir($servicioAuth->vigente(4), false); // sin huella
    esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'sesion_invalida');

    $limpiar();
    sesionDe($auth, $servicioAuth, 4);
    $guardia(peticion('GET', '/x')); // con huella correcta pasa
    $auth->registros[1]['hashContrasena'] = password_hash('OtraClave#9', PASSWORD_BCRYPT, ['cost' => 4]); // cambió la contraseña
    esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'sesion_invalida');
});

prueba('registro: DNI crea CLIENTE con la persona normalizada y una contraseña con hash bcrypt', function () {
    [$auth, $cuentas, $servicio] = escenarioCuenta();
    $r = $servicio->registrar(registroValido(['telefono' => '911 222-333', 'nombreCompleto' => "  Ana   María\tTorres  Díaz ", 'telefonoAuxiliar' => '922333444', 'correo' => ' ana@mail.com ']));
    afirmar($r === ['usuario' => 'anatorres', 'rol' => Roles::CLIENTE], 'resultado');
    $persona = $cuentas->personas[$cuentas->siguientePersona - 1];
    afirmar($persona['nombreCompleto'] === 'Ana María Torres Díaz' && $persona['telefono'] === '911222333', 'espacios y separadores normalizados');
    afirmar($persona['telefonoAuxiliar'] === '922333444' && $persona['correo'] === 'ana@mail.com', 'opcionales guardados');
    $hash = $auth->buscarPorNombre('anatorres')['hashContrasena'];
    afirmar(str_starts_with($hash, '$2y$12$') && password_verify('Segura#2026', $hash), 'bcrypt coste 12 y verificable');
    afirmar($hash !== 'Segura#2026', 'nunca se guarda en claro');
});

prueba('registro: RUC crea EMPRESARIO; los opcionales vacíos se guardan como NULL', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $r = $servicio->registrar(registroValido(['tipoDocumento' => 'ruc', 'numeroDocumento' => '20987654321', 'nombreCompleto' => 'Transportes del Sur S.A.C.', 'usuario' => 'transur']));
    afirmar($r['rol'] === Roles::EMPRESARIO, 'rol EMPRESARIO');
    $persona = $cuentas->personas[$cuentas->siguientePersona - 1];
    afirmar($persona['tipoDocumento'] === 'RUC' && $persona['telefonoAuxiliar'] === null && $persona['correo'] === null, 'tipo en mayúsculas y opcionales NULL');
});

prueba('registro: una persona que ya existe sin cuenta se reutiliza SIN sobrescribir sus datos guardados', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $antes = $cuentas->personas[50];
    $personasAntes = count($cuentas->personas);
    $servicio->registrar(registroValido(['numeroDocumento' => '70234561', 'nombreCompleto' => 'Otro Nombre Distinto', 'telefono' => '999000111', 'correo' => 'otro@mail.com', 'usuario' => 'lflores2']));
    afirmar(count($cuentas->personas) === $personasAntes, 'no se crea una persona nueva');
    afirmar($cuentas->personas[50] === $antes, 'los datos guardados de la persona no cambian');
    afirmar($cuentas->usuarioPersona[$cuentas->siguienteUsuario - 1] === 50, 'la cuenta nueva apunta a la persona existente');
});

prueba('registro: documento que ya tiene cuenta → 409; usuario en uso → 409 con campo', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $e = esperarError(fn () => $servicio->registrar(registroValido(['numeroDocumento' => '71889345', 'usuario' => 'nuevo123'])), 409, 'cuenta_existente');
    afirmar(str_contains($e->getMessage(), 'agencia'), 'orienta a acudir a la agencia');
    $e = esperarError(fn () => $servicio->registrar(registroValido(['usuario' => 'CLIENTE1'])), 409, 'usuario_en_uso'); // sin distinguir mayúsculas
    afirmar(isset($e->campos['usuario']), 'el error señala el campo usuario');
    afirmar($cuentas->altas === 0, 'no se crea nada en ninguno de los dos casos');
});

prueba('registro: valida cada campo y devuelve todos los errores a la vez', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $campos = camposConError(fn () => $servicio->registrar([
        'tipoDocumento' => 'CE', 'numeroDocumento' => '12', 'nombreCompleto' => '   ', 'telefono' => '12345678', 'telefonoAuxiliar' => '1',
        'correo' => 'no-es-correo', 'usuario' => 'a b', 'contrasena' => 'corta',
    ]));
    sort($campos);
    afirmar($campos === ['contrasena', 'correo', 'nombreCompleto', 'telefono', 'telefonoAuxiliar', 'tipoDocumento', 'usuario'], 'campos: ' . implode(',', $campos));
    afirmar($cuentas->altas === 0, 'un registro inválido no escribe nada');
});

prueba('registro: reglas de documento, teléfonos, usuario y contraseña', function () {
    [, , $servicio] = escenarioCuenta();
    $debeFallar = [
        ['DNI de 7 dígitos', ['numeroDocumento' => '1234567'], 'numeroDocumento'],
        ['DNI con letras', ['numeroDocumento' => '1234567A'], 'numeroDocumento'],
        ['RUC de 10 dígitos', ['tipoDocumento' => 'RUC', 'numeroDocumento' => '2012345678'], 'numeroDocumento'],
        ['CE no se registra desde la web', ['tipoDocumento' => 'CE'], 'tipoDocumento'],
        ['teléfono con letras', ['telefono' => '98765432A'], 'telefono'],
        ['teléfono auxiliar igual al teléfono', ['telefono' => '911222333', 'telefonoAuxiliar' => '911222333'], 'telefonoAuxiliar'],
        ['usuario de 3 caracteres', ['usuario' => 'abc'], 'usuario'],
        ['usuario de 31 caracteres', ['usuario' => str_repeat('a', 31)], 'usuario'],
        ['usuario con símbolos', ['usuario' => 'ana@torres'], 'usuario'],
        ['contraseña de 7 caracteres', ['contrasena' => 'Abc#123'], 'contrasena'],
        ['contraseña de 73 caracteres', ['contrasena' => str_repeat('a', 73)], 'contrasena'],
        ['contraseña igual al usuario', ['usuario' => 'anatorres', 'contrasena' => 'ANATORRES'], 'contrasena'],
        ['nombre de 151 caracteres', ['nombreCompleto' => str_repeat('ñ', 151)], 'nombreCompleto'],
        ['correo de 121 caracteres', ['correo' => str_repeat('a', 112) . '@mail.com'], 'correo'],
    ];
    foreach ($debeFallar as [$descripcion, $cambios, $campo]) {
        $campos = camposConError(fn () => $servicio->registrar(registroValido($cambios)));
        afirmar(in_array($campo, $campos, true), "$descripcion: debía marcar $campo y marcó " . implode(',', $campos));
    }
    // Límites que sí valen: DNI de 8, usuario de 4 y de 30, contraseña de 8 y de 72, nombre de 150.
    $servicio->registrar(registroValido(['usuario' => 'abcd', 'contrasena' => '12345678']));
    $servicio->registrar(registroValido(['numeroDocumento' => '45678913', 'usuario' => str_repeat('b', 30), 'contrasena' => str_repeat('c', 72)]));
    $servicio->registrar(registroValido(['numeroDocumento' => '45678914', 'usuario' => 'largo150', 'nombreCompleto' => str_repeat('ñ', 150)]));
});

prueba('registro: valores que no son texto no rompen el servicio (422)', function () {
    [, , $servicio] = escenarioCuenta();
    $campos = camposConError(fn () => $servicio->registrar(['tipoDocumento' => ['DNI'], 'numeroDocumento' => 123, 'nombreCompleto' => null, 'telefono' => [], 'usuario' => true, 'contrasena' => 1]));
    afirmar(in_array('tipoDocumento', $campos, true) && in_array('contrasena', $campos, true), 'marca los campos inválidos');
});

prueba('registro y luego ingreso: la cuenta recién creada puede iniciar sesión con su contraseña', function () {
    [, , $servicio, $servicioAuth] = escenarioCuenta();
    $servicio->registrar(registroValido());
    $datos = $servicioAuth->ingresar('anatorres', 'Segura#2026');
    afirmar($datos['rol'] === Roles::CLIENTE && $datos['sucursal'] === null, 'ingreso correcto como CLIENTE sin sucursal');
    esperarError(fn () => $servicioAuth->ingresar('anatorres', 'Incorrecta#1'), 401, 'credenciales_invalidas');
});

prueba('mi cuenta: devuelve persona y usuario (el documento es de solo lectura); sin cuenta → 404', function () {
    [, , $servicio] = escenarioCuenta();
    $c = $servicio->obtener(4);
    afirmar($c['persona']['numeroDocumento'] === '71889345' && $c['persona']['nombreCompleto'] === 'María Elena Rojas Ccori', 'persona');
    afirmar($c['usuario'] === ['nombreUsuario' => 'cliente1', 'rol' => Roles::CLIENTE, 'activo' => true, 'fechaCreacion' => '2026-03-01'], 'usuario');
    afirmar(!str_contains(json_encode($c), '$2y$') && !str_contains(json_encode($c), 'hash'), 'no expone el hash');
    esperarError(fn () => $servicio->obtener(999), 404, 'cuenta_no_encontrada');
});

prueba('mi cuenta: edita teléfono, auxiliar y correo sin tocar documento ni nombre', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $c = $servicio->actualizarContacto(4, ['telefono' => '900 111 222', 'telefonoAuxiliar' => '933444555', 'correo' => 'maria.rojas@mail.com', 'numeroDocumento' => '00000000', 'nombreCompleto' => 'Intruso']);
    afirmar($c['persona']['telefono'] === '900111222' && $c['persona']['telefonoAuxiliar'] === '933444555' && $c['persona']['correo'] === 'maria.rojas@mail.com', 'contacto actualizado');
    afirmar($c['persona']['numeroDocumento'] === '71889345' && $c['persona']['nombreCompleto'] === 'María Elena Rojas Ccori', 'documento y nombre intactos');
    $c = $servicio->actualizarContacto(4, ['telefono' => '900111222', 'telefonoAuxiliar' => '', 'correo' => '']);
    afirmar($c['persona']['telefonoAuxiliar'] === null && $c['persona']['correo'] === null, 'se pueden quitar los opcionales');
    afirmar($cuentas->personas[5]['telefono'] === '987654324', 'otras personas no cambian');
});

prueba('mi cuenta: la edición valida y no guarda nada si hay un error', function () {
    [, $cuentas, $servicio] = escenarioCuenta();
    $antes = $cuentas->personas[4];
    $campos = camposConError(fn () => $servicio->actualizarContacto(4, ['telefono' => '12', 'telefonoAuxiliar' => '987654323', 'correo' => 'x']));
    afirmar(in_array('telefono', $campos, true) && in_array('correo', $campos, true), 'marca teléfono y correo');
    $campos = camposConError(fn () => $servicio->actualizarContacto(4, ['telefono' => '987654323', 'telefonoAuxiliar' => '987654323']));
    afirmar($campos === ['telefonoAuxiliar'], 'auxiliar igual al teléfono');
    afirmar($cuentas->personas[4] === $antes, 'ningún error modificó los datos');
    esperarError(fn () => $servicio->actualizarContacto(999, ['telefono' => '987654323']), 404, 'cuenta_no_encontrada');
});

prueba('cambiar contraseña: exige la actual correcta y cumple las reglas de la nueva', function () {
    [$auth, , $servicio] = escenarioCuenta();
    $hashAntes = $auth->buscarPorId(4)['hashContrasena'];
    $e = esperarError(fn () => $servicio->cambiarContrasena(4, 'Equivocada#1', 'NuevaClave#1'), 422, 'contrasena_incorrecta');
    afirmar(isset($e->campos['contrasenaActual']), 'señala contrasenaActual');
    esperarError(fn () => $servicio->cambiarContrasena(4, '', 'NuevaClave#1'), 422, 'contrasena_incorrecta');
    foreach ([['corta', 'corta'], ['igual a la actual', 'ClaveCliente#1'], ['igual al usuario', 'CLIENTE1'], ['de 73 caracteres', str_repeat('x', 73)]] as [$descripcion, $nueva]) {
        $campos = camposConError(fn () => $servicio->cambiarContrasena(4, 'ClaveCliente#1', $nueva));
        afirmar($campos === ['contrasenaNueva'], "$descripcion: debía marcar contrasenaNueva");
    }
    afirmar($auth->buscarPorId(4)['hashContrasena'] === $hashAntes, 'ningún intento fallido cambió el hash');
});

prueba('cambiar contraseña: guarda un hash bcrypt nuevo; la vieja deja de valer y la nueva sirve para ingresar', function () {
    [$auth, , $servicio, $servicioAuth] = escenarioCuenta();
    $hash = $servicio->cambiarContrasena(4, 'ClaveCliente#1', 'NuevaClave#2026');
    afirmar($hash === $auth->buscarPorId(4)['hashContrasena'] && str_starts_with($hash, '$2y$12$'), 'se guarda el hash devuelto');
    esperarError(fn () => $servicioAuth->ingresar('cliente1', 'ClaveCliente#1'), 401, 'credenciales_invalidas');
    afirmar($servicioAuth->ingresar('cliente1', 'NuevaClave#2026')['nombreUsuario'] === 'cliente1', 'la nueva contraseña funciona');
});

prueba('controlador de cuenta: registro → 201 sin abrir sesión', function () use ($limpiar) {
    $limpiar();
    [, , $servicio] = escenarioCuenta();
    $c = new CuentaController($servicio);
    $r = leer($c->registrar(peticion('POST', '/api/cuenta/registro', json_encode(registroValido())), []));
    afirmar($r['estado'] === 201 && $r['cuerpo']['data'] === ['usuario' => 'anatorres', 'rol' => Roles::CLIENTE], 'respuesta 201');
    afirmar(Sesion::usuario() === null, 'registrarse no abre sesión');
    $e = esperarError(fn () => $c->registrar(peticion('POST', '/x', json_encode(registroValido(['telefono' => '1']))), []), 422, 'datos_invalidos');
    afirmar(isset($e->campos['telefono']), 'el 422 trae el campo');
    esperarError(fn () => $c->registrar(peticion('POST', '/x', '{roto'), []), 400, 'json_invalido');
});

prueba('controlador de cuenta: ver y editar usan la cuenta de la sesión, no la que diga el cuerpo', function () use ($limpiar) {
    $limpiar();
    [$auth, $cuentas, $servicio, $servicioAuth] = escenarioCuenta();
    $c = new CuentaController($servicio);
    esperarError(fn () => $c->ver(peticion('GET', '/api/cuenta'), []), 401, 'no_autenticado');

    sesionDe($auth, $servicioAuth, 4);
    $ver = leer($c->ver(peticion('GET', '/api/cuenta'), []));
    afirmar($ver['estado'] === 200 && $ver['cuerpo']['data']['usuario']['nombreUsuario'] === 'cliente1', 've su propia cuenta');
    $c->actualizar(peticion('PATCH', '/api/cuenta', json_encode(['telefono' => '900111222', 'idUsuario' => 5, 'idPersona' => 5])), []);
    afirmar($cuentas->personas[4]['telefono'] === '900111222' && $cuentas->personas[5]['telefono'] === '987654324', 'solo cambia la persona de la sesión');
});

prueba('controlador de cuenta: cambiar la contraseña mantiene válida esta sesión y cierra las demás', function () use ($limpiar) {
    $limpiar();
    [$auth, , $servicio, $servicioAuth] = escenarioCuenta();
    $c = new CuentaController($servicio);
    $guardia = new RequiereSesion($servicioAuth);

    sesionDe($auth, $servicioAuth, 4);
    $huellaVieja = Sesion::huella(); // la que tendría otra sesión abierta en otro dispositivo
    $r = leer($c->cambiarContrasena(peticion('POST', '/api/cuenta/contrasena', json_encode(['contrasenaActual' => 'ClaveCliente#1', 'contrasenaNueva' => 'NuevaClave#2026'])), []));
    afirmar($r['estado'] === 200 && $r['cuerpo']['data'] === ['contrasenaCambiada' => true], 'respuesta 200');
    afirmar(Sesion::huella() === Credencial::huella($auth->buscarPorId(4)['hashContrasena']), 'esta sesión adopta la huella nueva');
    $guardia(peticion('GET', '/x')); // sigue válida

    $limpiar();
    Sesion::abrir($servicioAuth->vigente(4), false, $huellaVieja); // la sesión «del otro dispositivo»
    esperarError(fn () => $guardia(peticion('GET', '/x')), 401, 'sesion_invalida');
});

prueba('controlador de cuenta: contraseña actual incorrecta → 422 y la sesión no cambia', function () use ($limpiar) {
    $limpiar();
    [$auth, , $servicio, $servicioAuth] = escenarioCuenta();
    $c = new CuentaController($servicio);
    sesionDe($auth, $servicioAuth, 4);
    $huella = Sesion::huella();
    esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => 'mala', 'contrasenaNueva' => 'NuevaClave#2026'])), []), 422, 'contrasena_incorrecta');
    esperarError(fn () => $c->cambiarContrasena(peticion('POST', '/x', json_encode(['contrasenaActual' => ['x'], 'contrasenaNueva' => 5])), []), 422, 'contrasena_incorrecta');
    afirmar(Sesion::huella() === $huella, 'la huella de la sesión no cambió');
});

prueba('permisos de /api/cuenta: cliente y empresario pasan; gerente 403; sin sesión 401', function () use ($limpiar) {
    $limpiar();
    [$auth, , , $servicioAuth] = escenarioCuenta();
    // Misma configuración que backend/src/Modules/Cuenta/rutas.php, con el servicio simulado.
    $guardia = new RequiereRol([Roles::CLIENTE, Roles::EMPRESARIO], new RequiereSesion($servicioAuth));
    esperarError(fn () => $guardia(peticion('GET', '/api/cuenta')), 401, 'no_autenticado');
    sesionDe($auth, $servicioAuth, 4);
    $guardia(peticion('GET', '/api/cuenta'));
    $limpiar();
    sesionDe($auth, $servicioAuth, 5);
    $guardia(peticion('GET', '/api/cuenta'));
    $limpiar();
    sesionDe($auth, $servicioAuth, 1); // gerente
    esperarError(fn () => $guardia(peticion('GET', '/api/cuenta')), 403, 'acceso_denegado');
});

prueba('seguro de las pruebas: sin base de datos configurada, cualquier acceso a ella falla', function () {
    esperarError(fn () => Jatsa\Core\Database::conexion(), 503, 'bd_no_configurada');
});

prueba('rutas de cuenta: el registro es público y el resto exige rol (registradas en el módulo)', function () use ($limpiar) {
    $limpiar(); // sin sesión: los guardias responden 401 antes de llegar a ningún servicio
    $router = new Router();
    (require dirname(__DIR__) . '/src/Modules/Cuenta/rutas.php')($router);
    // Sin sesión: el registro llega al controlador (falla por datos, no por permisos) y las demás dan 401.
    esperarError(fn () => $router->despachar(peticion('POST', '/api/cuenta/registro', '{roto')), 400, 'json_invalido');
    foreach ([['GET', '/api/cuenta'], ['PATCH', '/api/cuenta'], ['POST', '/api/cuenta/contrasena']] as [$metodo, $ruta]) {
        esperarError(fn () => $router->despachar(peticion($metodo, $ruta)), 401, 'no_autenticado');
    }
    esperarError(fn () => $router->despachar(peticion('DELETE', '/api/cuenta')), 405, 'metodo_no_permitido');
});

prueba('repositorio de cuentas: traduce las claves duplicadas a 409 y deja pasar otros errores', function () {
    $traducir = new ReflectionMethod(CuentaRepository::class, 'traducir');
    $repositorio = new CuentaRepository();
    $duplicada = static function (int $codigo, string $mensaje): PDOException {
        $e = new PDOException($mensaje);
        $e->errorInfo = ['23000', $codigo, $mensaje];
        return $e;
    };
    $usuario = $traducir->invoke($repositorio, $duplicada(2627, "Violation of UNIQUE KEY constraint 'UQ_USUARIO_NOMBRE'. Cannot insert duplicate key"));
    afirmar($usuario instanceof HttpException && $usuario->estado === 409 && $usuario->codigo === 'usuario_en_uso' && isset($usuario->campos['usuario']), 'usuario duplicado');
    $documento = $traducir->invoke($repositorio, $duplicada(2601, "Cannot insert duplicate key row ... 'UQ_PERSONA_DOCUMENTO'"));
    afirmar($documento instanceof HttpException && $documento->codigo === 'cuenta_existente', 'documento o persona duplicados');
    $otro = $duplicada(547, 'The INSERT statement conflicted with a CHECK constraint');
    afirmar($traducir->invoke($repositorio, $otro) === $otro, 'cualquier otro error se relanza tal cual');
    afirmar(!str_contains($documento->getMessage(), 'UQ_') && !str_contains($usuario->getMessage(), 'UQ_'), 'el mensaje al cliente no expone nombres internos');
});

$limpiar();

// ── Informe ─────────────────────────────────────────────────────────────────
ob_end_clean();
echo "Pruebas correctas: {$resultados['ok']}" . PHP_EOL;
foreach ($resultados['fallos'] as $fallo) {
    echo "FALLO: $fallo" . PHP_EOL;
}
echo count($resultados['fallos']) === 0 ? 'RESULTADO: todas correctas' . PHP_EOL : 'RESULTADO: hay fallos' . PHP_EOL;
exit(count($resultados['fallos']) === 0 ? 0 : 1);
