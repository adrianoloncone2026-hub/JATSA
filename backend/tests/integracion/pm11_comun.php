<?php
declare(strict_types=1);

/*
 * Funciones COMUNES de la validación real pm11 (módulo 11) en base SQL Server AISLADA. Solo definiciones: no se ejecuta nada al incluir este archivo
 * y ninguna función abre conexiones por sí sola salvo pm11ConexionServidor(), que solo usan las fases autorizadas (pm11.php y pm11_trabajo.php).
 * Plan: docs/plan-pm11-validacion-sql.md. NADA DE ESTO SE HA EJECUTADO CONTRA SQL SERVER.
 */

const PM11_PATRON_BASE = '/^JATSA_pm11_aislada_\d{8}_\d{4}$/';
const PM11_TABLAS = [
    'Persona' => 'idPersona', 'Sucursal' => 'idSucursal', 'Telefono_Sucursal' => 'idTelefono', 'Usuario' => 'idUsuario', 'Ruta' => 'idRuta', 'Vehiculo' => 'idVehiculo',
    'Reserva' => 'idReserva', 'Envio' => 'idEnvio', 'Bulto' => 'idBulto', 'Viaje' => 'idViaje', 'Envio_Viaje' => 'idEnvioViaje', 'Pago' => 'idPago',
];

function pm11Abortar(string $mensaje, int $codigo = 4): never
{
    fwrite(STDERR, "DETENIDO: $mensaje\n");
    exit($codigo);
}

/** Nombre de base aislada pm11: patrón exacto; nunca «Encomiendas» ni la base de pm10. */
function pm11NombreValido(string $base): bool
{
    return preg_match(PM11_PATRON_BASE, $base) === 1 && stripos($base, 'encomiendas') === false && stripos($base, 'pm10') === false;
}

/** Carpeta temporal de trabajo de una base (manifiesto, copia del backend, credenciales). Siempre dentro del directorio temporal del sistema. */
function pm11DirTrabajo(string $base): string
{
    if (!pm11NombreValido($base)) {
        throw new InvalidArgumentException('nombre de base no válido');
    }

    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'jatsa_pm11_' . substr($base, -13);
}

/** @return array<string, mixed>|null */
function pm11LeerManifiesto(string $base): ?array
{
    $ruta = pm11DirTrabajo($base) . DIRECTORY_SEPARATOR . 'manifiesto.json';
    if (!is_file($ruta)) {
        return null;
    }
    $datos = json_decode((string) file_get_contents($ruta), true);

    return is_array($datos) ? $datos : null;
}

/** @param array<string, mixed> $manifiesto */
function pm11GuardarManifiesto(string $base, array $manifiesto): void
{
    file_put_contents(pm11DirTrabajo($base) . DIRECTORY_SEPARATOR . 'manifiesto.json', json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/** Servidor (y puerto opcional) desde JATSA_PM11_SERVIDOR / JATSA_PM11_PUERTO, validados. @return array{0: string, 1: string} */
function pm11Servidor(): array
{
    $servidor = (string) getenv('JATSA_PM11_SERVIDOR');
    $puerto = (string) getenv('JATSA_PM11_PUERTO');
    if ($servidor === '' || preg_match('/^[A-Za-z0-9_.\-\\\\]+$/', $servidor) !== 1 || ($puerto !== '' && preg_match('/^\d{2,5}$/', $puerto) !== 1)) {
        pm11Abortar('Falta o es inválido JATSA_PM11_SERVIDOR / JATSA_PM11_PUERTO.', 2);
    }

    return [$servidor, $puerto];
}

/** Conexión con autenticación integrada de Windows (sin usuario ni contraseña) a $base del servidor. Solo para fases autorizadas. */
function pm11ConexionServidor(string $servidor, string $puerto, string $base): PDO
{
    if (!extension_loaded('pdo_sqlsrv')) {
        pm11Abortar('Falta la extensión pdo_sqlsrv.', 2);
    }
    $dsn = sprintf('sqlsrv:Server=%s;Database=%s;Encrypt=false;TrustServerCertificate=true;LoginTimeout=8', $servidor . ($puerto !== '' ? ',' . $puerto : ''), $base);

    return new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** Datos de la conexión actual: base, login y esquema de autenticación. @return array<string, mixed> */
function pm11Identidad(PDO $pdo): array
{
    return $pdo->query('SELECT DB_NAME() AS bd, ORIGINAL_LOGIN() AS login, (SELECT MAX(auth_scheme) FROM sys.dm_exec_connections WHERE session_id = @@SPID) AS auth')->fetch(PDO::FETCH_ASSOC);
}

/** ¿La identidad es la esperada? (base aislada exacta, la cuenta Windows del manifiesto y autenticación de Windows; nunca «Encomiendas»). @param array<string, mixed> $id */
function pm11IdentidadValida(array $id, string $base, string $loginEsperado): bool
{
    return $loginEsperado !== ''
        && pm11NombreValido($base)
        && ($id['bd'] ?? null) === $base
        && stripos((string) ($id['bd'] ?? ''), 'encomiendas') === false
        && strcasecmp((string) ($id['login'] ?? ''), $loginEsperado) === 0
        && in_array(strtoupper((string) ($id['auth'] ?? '')), ['NTLM', 'KERBEROS', 'NEGOTIATE'], true);
}

function pm11ExigirDestino(PDO $pdo, string $base, string $loginEsperado): void
{
    $id = pm11Identidad($pdo);
    if (!pm11IdentidadValida($id, $base, $loginEsperado)) {
        pm11Abortar('Esta conexión NO está en la base aislada con la cuenta Windows esperada (' . ($id['bd'] ?? '?') . ' / ' . ($id['login'] ?? '?') . ' / ' . ($id['auth'] ?? '?') . '). No se hace nada.', 5);
    }
}

/** .env de la copia: autenticación integrada, sin credenciales. */
function pm11ContenidoEnv(string $servidor, string $puerto, string $base): string
{
    foreach ([$servidor, $puerto, $base] as $valor) {
        if (preg_match('/[\r\n]/', $valor) === 1) {
            throw new InvalidArgumentException('un valor del .env contiene saltos de línea');
        }
    }
    if ($servidor === '' || !pm11NombreValido($base)) {
        throw new InvalidArgumentException('servidor o base no válidos');
    }

    return implode("\n", [
        '# Generado por pm11. COPIA DE TRABAJO TEMPORAL: no es el .env habitual. Autenticación integrada de Windows: sin credenciales.',
        'APP_ENV=prueba', 'APP_TZ=America/Lima', 'CORS_ORIGINS=http://localhost:5173', 'SESSION_HORAS=8', 'SESSION_DIAS_RECORDAR=14', 'SESSION_SECURE=false',
        "DB_HOST=$servidor", "DB_PORT=$puerto", "DB_NAME=$base", 'DB_USER=', 'DB_PASSWORD=', 'DB_ENCRYPT=false', 'DB_TRUST_CERT=true',
    ]);
}

/** Problemas del .env de la copia (lista vacía = válido). @return list<string> */
function pm11EnvProblemas(string $texto, string $base): array
{
    $valores = [];
    $repetidas = [];
    foreach (preg_split('/\r?\n/', $texto) ?: [] as $linea) {
        $l = trim($linea);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $l, 2);
        if (array_key_exists($k, $valores)) {
            $repetidas[] = $k;
        }
        $valores[$k] = $v;
    }
    $p = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $k) {
        if (!array_key_exists($k, $valores)) {
            $p[] = "falta $k (sin ella Env usaría las variables del proceso)";
        }
    }
    if ($repetidas !== []) {
        $p[] = 'claves repetidas: ' . implode(', ', $repetidas);
    }
    if (($valores['DB_NAME'] ?? null) !== $base) {
        $p[] = 'DB_NAME no es la base aislada';
    }
    if (stripos($valores['DB_NAME'] ?? '', 'encomiendas') !== false) {
        $p[] = 'DB_NAME apunta a la base habitual';
    }
    if (($valores['DB_USER'] ?? '') !== '' || ($valores['DB_PASSWORD'] ?? '') !== '') {
        $p[] = 'DB_USER y DB_PASSWORD deben estar vacíos (autenticación de Windows)';
    }

    return $p;
}

/**
 * Estructura derivada del script original (que solo se LEE): desde el lote posterior a «USE JATSA_Encomiendas» hasta el último GO anterior al primer INSERT,
 * precedida de «USE [base]; GO». Equivale a ConvertTo-DdlDerivado de pm10.
 */
function pm11DerivarDdl(string $original, string $base): string
{
    if (!pm11NombreValido($base)) {
        throw new InvalidArgumentException('nombre de base no válido');
    }
    $lineas = preg_split('/\r?\n/', $original) ?: [];
    $iUse = -1;
    $iInsert = -1;
    foreach ($lineas as $i => $linea) {
        if ($iUse < 0 && preg_match('/^\s*USE\s+JATSA_Encomiendas\s*;?\s*$/i', $linea) === 1) {
            $iUse = $i;
        }
        if ($iUse >= 0 && preg_match('/^\s*INSERT\s+INTO\b/i', $linea) === 1) {
            $iInsert = $i;
            break;
        }
    }
    if ($iUse < 0 || $iInsert < 0) {
        throw new RuntimeException('no se encontró USE JATSA_Encomiendas o el primer INSERT en el script original');
    }
    $ultimoGo = -1;
    for ($i = $iInsert - 1; $i > $iUse; $i--) {
        if (preg_match('/^\s*GO\s*$/i', $lineas[$i]) === 1) {
            $ultimoGo = $i;
            break;
        }
    }
    if ($ultimoGo < 0) {
        throw new RuntimeException('no se encontró el GO que cierra la estructura');
    }

    return implode("\n", array_merge(["USE [$base];", 'GO'], array_slice($lineas, $iUse + 1, $ultimoGo - $iUse)));
}

/** Lotes separados por líneas «GO»; sin lotes vacíos. @return list<string> */
function pm11Lotes(string $texto): array
{
    $lotes = [];
    $actual = [];
    foreach (preg_split('/\r?\n/', $texto) ?: [] as $linea) {
        if (preg_match('/^\s*GO\s*$/i', $linea) === 1) {
            if (trim(implode('', $actual)) !== '') {
                $lotes[] = implode("\n", $actual);
            }
            $actual = [];
        } else {
            $actual[] = $linea;
        }
    }
    if (trim(implode('', $actual)) !== '') {
        $lotes[] = implode("\n", $actual);
    }

    return $lotes;
}

/** Problemas de la estructura derivada (vacío = válida): solo 12 CREATE TABLE, sin INSERT ni DDL de bases/logins, sin «Encomiendas», un único USE inicial. @return list<string> */
function pm11DdlProblemas(string $ddl, string $base): array
{
    $p = [];
    $limpio = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $ddl);
    if (stripos($ddl, 'JATSA_Encomiendas') !== false) {
        $p[] = 'menciona JATSA_Encomiendas';
    }
    if (preg_match('/\b(INSERT|DROP|TRUNCATE|DBCC|EXEC|EXECUTE|UPDATE|DELETE|MERGE|ALTER)\b/i', $limpio) === 1 || preg_match('/\b(CREATE|ALTER|DROP)\s+(DATABASE|LOGIN|USER)\b/i', $limpio) === 1) {
        $p[] = 'contiene sentencias no estructurales o sobre bases/logins';
    }
    if (preg_match_all('/^\s*CREATE\s+TABLE\s+\[?\w+\]?/im', $limpio) !== 12) {
        $p[] = 'no tiene exactamente 12 CREATE TABLE';
    }
    $lotes = pm11Lotes($ddl);
    if (count($lotes) < 2 || trim($lotes[0]) !== "USE [$base];") {
        $p[] = 'el primer lote no es exactamente USE de la base aislada';
    }
    foreach (array_slice($lotes, 1) as $lote) {
        if (preg_match('/^\s*USE\b/im', $lote) === 1) {
            $p[] = 'hay un USE dentro de los lotes';
            break;
        }
    }

    return $p;
}

/**
 * Huella del contenido de las 12 tablas + metadatos, para comparar antes y después: nada debe cambiar durante la fase C.
 *
 * @return array<string, mixed>
 */
function pm11Instantanea(PDO $pdo): array
{
    $tablas = [];
    foreach (PM11_TABLAS as $tabla => $pk) {
        $filas = $pdo->query("SELECT * FROM $tabla ORDER BY $pk")->fetchAll(PDO::FETCH_ASSOC);
        $ident = $pdo->query("SELECT IDENT_CURRENT('$tabla')")->fetchColumn();
        $tablas[$tabla] = ['filas' => count($filas), 'identActual' => (string) $ident, 'sha256' => hash('sha256', (string) json_encode($filas, JSON_UNESCAPED_UNICODE))];
    }
    $nombres = $pdo->query('SELECT name FROM sys.tables ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

    return [
        'tablasEnBase' => $nombres,
        'objetosDeUsuario' => (int) $pdo->query('SELECT COUNT(*) FROM sys.objects WHERE is_ms_shipped = 0')->fetchColumn(),
        'soloLectura' => (int) $pdo->query('SELECT is_read_only FROM sys.databases WHERE name = DB_NAME()')->fetchColumn(),
        'tablas' => $tablas,
    ];
}

/** Diferencias entre dos instantáneas (vacío = idénticas) y que la base tenga exactamente las 12 tablas. @param array<string, mixed> $a @param array<string, mixed> $b @return list<string> */
function pm11DiferenciasInstantaneas(array $a, array $b): array
{
    $d = [];
    $esperadas = array_keys(PM11_TABLAS);
    sort($esperadas);
    foreach (['antes' => $a, 'después' => $b] as $momento => $s) {
        $nombres = $s['tablasEnBase'];
        sort($nombres);
        if ($nombres !== $esperadas) {
            $d[] = "$momento: la base no tiene exactamente las 12 tablas";
        }
    }
    foreach (['tablasEnBase', 'objetosDeUsuario', 'soloLectura', 'tablas'] as $clave) {
        if ($a[$clave] !== $b[$clave]) {
            $d[] = "cambió $clave";
            if ($clave === 'tablas') {
                foreach ($a['tablas'] as $tabla => $x) {
                    if ($x !== ($b['tablas'][$tabla] ?? null)) {
                        $d[] = "  tabla $tabla distinta";
                    }
                }
            }
        }
    }

    return $d;
}

/** Borra recursivamente SOLO una carpeta de trabajo pm11 (dentro del directorio temporal y con el nombre esperado). */
function pm11BorrarDirTrabajo(string $dir): void
{
    $temporal = realpath(sys_get_temp_dir());
    $real = realpath($dir);
    if ($real === false || $temporal === false || !str_starts_with($real, $temporal . DIRECTORY_SEPARATOR) || preg_match('/^jatsa_pm11_\d{8}_\d{4}$/', basename($real)) !== 1) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterador as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($real);
}
