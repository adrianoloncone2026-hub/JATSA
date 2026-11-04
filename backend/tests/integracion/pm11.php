<?php
declare(strict_types=1);

/*
 * LANZADOR de la validación real pm11 (módulo 11) en una base SQL Server AISLADA. NO SE HA EJECUTADO NADA. Plan: docs/plan-pm11-validacion-sql.md.
 *
 *   php pm11.php A <base> --autorizo-A                    CREATE DATABASE (nada más)
 *   php pm11.php B <base> --solo-preparar                 copia de trabajo, .env y estructura derivada; NO toca SQL Server
 *   php pm11.php B <base> --autorizo-B                    estructura (12 CREATE TABLE) + semilla sintética + ALTER DATABASE … READ_ONLY
 *   php pm11.php C <base> --autorizo-C                    validación: SOLO SELECT y GET /api/reportes/* (la base está en READ_ONLY)
 *   php pm11.php D <base> --autorizo-D [--conservar-copia]  DROP DATABASE de ESTA base y borrado de la copia temporal
 *
 * Cada fase exige su PROPIA bandera, que solo debe usarse con la autorización expresa y separada del propietario para esa fase; una bandera no habilita otra.
 * Cada fase comprueba el manifiesto (la anterior debe estar hecha) y verifica el destino en cada conexión: base de patrón JATSA_pm11_aislada_AAAAMMDD_HHMM
 * (nunca «Encomiendas» ni pm10) y la misma cuenta Windows (autenticación integrada; sin credenciales) que creó la base. Datos no secretos por variables de
 * entorno: JATSA_PM11_SERVIDOR y, opcional, JATSA_PM11_PUERTO. No toca JATSA_Encomiendas, la base de pm10, backend/.env ni el script SQL original (solo se
 * lee; su SHA-256 y el de backend/.env se comparan antes y después).
 */
require __DIR__ . '/pm11_comun.php';

$fase = strtoupper((string) ($argv[1] ?? ''));
$base = (string) ($argv[2] ?? '');
$banderas = array_slice($argv, 3);
if (!in_array($fase, ['A', 'B', 'C', 'D'], true) || !pm11NombreValido($base)) {
    pm11Abortar('Uso: pm11.php <A|B|C|D> <JATSA_pm11_aislada_AAAAMMDD_HHMM> --autorizo-<fase>. El nombre debe cumplir el patrón y no contener «Encomiendas».', 2);
}
$autorizada = in_array("--autorizo-$fase", $banderas, true);
$soloPreparar = $fase === 'B' && in_array('--solo-preparar', $banderas, true);
if (!$autorizada && !$soloPreparar) {
    pm11Abortar("Rechazado: la fase $fase requiere la autorización expresa y separada del propietario (--autorizo-$fase). Nada se ejecutó.", 2);
}
if ($autorizada && $soloPreparar) {
    pm11Abortar('--autorizo-B y --solo-preparar son excluyentes.', 2);
}
$repo = dirname(__DIR__, 3);
$backendHabitual = $repo . DIRECTORY_SEPARATOR . 'backend';
$sqlOriginal = $repo . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'JATSA_Envios-SQLQuery1 (1).sql';
$dir = pm11DirTrabajo($base);
$copia = $dir . DIRECTORY_SEPARATOR . 'backend';

/** Huellas de lo que NO debe cambiar: el .env habitual (solo su hash, nunca su contenido) y el script SQL original. @return array<string, string> */
function pm11HuellasProtegidas(string $backendHabitual, string $sqlOriginal): array
{
    $env = $backendHabitual . DIRECTORY_SEPARATOR . '.env';

    return ['env' => is_file($env) ? hash_file('sha256', $env) : 'sin-env', 'sql' => is_file($sqlOriginal) ? hash_file('sha256', $sqlOriginal) : 'sin-sql'];
}

/** @return array<string, string> ruta relativa => sha256 */
function pm11Inventario(string $raiz, array $carpetas): array
{
    $h = [];
    foreach ($carpetas as $c) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . DIRECTORY_SEPARATOR . $c, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $h[strtolower(str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1)))] = hash_file('sha256', $f->getPathname());
            }
        }
    }

    return $h;
}

function pm11CopiarCarpeta(string $origen, string $destino): void
{
    is_dir($destino) || mkdir($destino, 0777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $rel = substr($f->getPathname(), strlen($origen) + 1);
        $f->isDir() ? (is_dir("$destino/$rel") || mkdir("$destino/$rel", 0777, true)) : copy($f->getPathname(), "$destino/$rel");
    }
}

/** Comprueba que la copia tiene EXACTAMENTE el mismo código que el repositorio. */
function pm11VerificarCopia(string $backendHabitual, string $copia, array $carpetas): void
{
    $a = pm11Inventario($backendHabitual, $carpetas);
    $b = pm11Inventario($copia, $carpetas);
    $d = array_merge(array_keys(array_diff_assoc($a, $b)), array_keys(array_diff_key($b, $a)));
    if ($d !== []) {
        pm11Abortar('La copia de trabajo no es idéntica al repositorio: ' . implode('; ', array_slice($d, 0, 5)));
    }
}

/** Ejecuta un script PHP de la COPIA y devuelve su código de salida. */
function pm11Ejecutar(string $script, array $args): int
{
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $codigo);

    return $codigo;
}

function pm11ComprobarProtegidas(array $antes, array $despues): void
{
    if ($antes !== $despues) {
        pm11Abortar('ALERTA: el .env habitual o el script SQL original CAMBIARON durante la operación.', 5);
    }
    echo "Comprobado: el SHA-256 de backend/.env y del script SQL original es el mismo que al empezar.\n";
}

$protegidas0 = pm11HuellasProtegidas($backendHabitual, $sqlOriginal);
$manifiesto = pm11LeerManifiesto($base);
$carpetasCopia = ['src', 'config', 'public', 'tests/integracion'];

// ═══ A: CREATE DATABASE ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($fase === 'A') {
    if ($manifiesto !== null && !empty($manifiesto['fases']['A'])) {
        pm11Abortar('Esta base ya tiene la fase A registrada: no se repite ni se reutiliza.', 3);
    }
    [$servidor, $puerto] = pm11Servidor();
    try {
        // La cuenta esperada es la cuenta Windows de ESTE proceso; la conexión debe ser «master» con esa cuenta y autenticación de Windows antes de seguir.
        $esperada = (string) getenv('USERDOMAIN') . '\\' . (string) getenv('USERNAME');
        $pdo = pm11ConexionMaster($servidor, $puerto, $esperada);
        $yo = $pdo->query("SELECT ORIGINAL_LOGIN() AS login, IS_SRVROLEMEMBER('sysadmin') AS sysadmin")->fetch(PDO::FETCH_ASSOC);
        if ((int) $yo['sysadmin'] !== 1) {
            pm11Abortar('La cuenta no es sysadmin: no puede crear la base.', 5);
        }
        $existe = $pdo->prepare('SELECT COUNT(*) FROM sys.databases WHERE name = :n');
        $existe->execute([':n' => $base]);
        if ((int) $existe->fetchColumn() !== 0) {
            pm11Abortar('Ya existe una base con ese nombre: no se reutiliza ni se pisa nada.', 3);
        }
        is_dir($dir) || mkdir($dir, 0777, true);
        $pdo->exec("CREATE DATABASE [$base]");
        $datos = $pdo->prepare("SELECT database_id, CONVERT(VARCHAR(30), create_date, 126) AS creada FROM sys.databases WHERE name = :n");
        $datos->execute([':n' => $base]);
        $fila = $datos->fetch(PDO::FETCH_ASSOC);
        $nueva = pm11Identidad(pm11ConexionServidor($servidor, $puerto, $base));
        if (!pm11IdentidadValida($nueva, $base, (string) $yo['login'])) {
            pm11Abortar('Una conexión nueva no entra a la base creada con la misma cuenta.');
        }
        pm11GuardarManifiesto($base, ['base' => $base, 'servidor' => $servidor, 'puerto' => $puerto, 'loginEsperado' => $yo['login'], 'databaseId' => (int) $fila['database_id'],
            'creadaEn' => $fila['creada'], 'fases' => ['A' => ['hecha' => true, 'cuando' => date('c')]]]);
        echo "Base «{$base}» creada y verificada (cuenta {$yo['login']}). Copia y manifiesto en $dir\n";
    } catch (PDOException $e) {
        pm11Abortar('Error de SQL Server: ' . $e->getMessage());
    }
    pm11ComprobarProtegidas($protegidas0, pm11HuellasProtegidas($backendHabitual, $sqlOriginal));
    exit(0);
}

// A partir de aquí la fase A debe estar hecha (salvo la preparación local de B).
if (!$soloPreparar && ($manifiesto === null || empty($manifiesto['fases']['A']))) {
    pm11Abortar('La fase A no está registrada para esta base (manifiesto ausente).', 3);
}

// ═══ B: preparar la copia (+ cargar) ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($fase === 'B') {
    if (!is_file($sqlOriginal)) {
        pm11Abortar('No se encuentra el script SQL original (solo se LEE).', 2);
    }
    [$servidor, $puerto] = $manifiesto !== null ? [(string) $manifiesto['servidor'], (string) $manifiesto['puerto']] : pm11Servidor();
    is_dir($dir) || mkdir($dir, 0777, true);
    $manifiesto ??= ['base' => $base, 'servidor' => $servidor, 'puerto' => $puerto, 'fases' => []];
    if (!is_dir($copia)) {
        foreach ($carpetasCopia as $c) { // src, config, public y tests/integracion; NUNCA el .env habitual
            pm11CopiarCarpeta($backendHabitual . DIRECTORY_SEPARATOR . $c, $copia . DIRECTORY_SEPARATOR . $c);
        }
        mkdir($copia . '/storage/sessions', 0777, true);
        mkdir($copia . '/storage/logs', 0777, true);
        file_put_contents($copia . '/.env', pm11ContenidoEnv($servidor, $puerto, $base));
    }
    pm11VerificarCopia($backendHabitual, $copia, $carpetasCopia);
    $problemas = pm11EnvProblemas((string) file_get_contents($copia . '/.env'), $base, $servidor, $puerto);
    if ($problemas !== [] || is_file($copia . '/public/sonda_db.php')) {
        pm11Abortar('El .env de la copia no es válido o la copia trae una sonda: ' . implode(' | ', $problemas));
    }
    $ddl = pm11DerivarDdl((string) file_get_contents($sqlOriginal), $base);
    $problemas = pm11DdlProblemas($ddl, $base);
    if ($problemas !== []) {
        pm11Abortar('La estructura derivada no es válida: ' . implode('; ', $problemas));
    }
    $rutaDdl = $dir . DIRECTORY_SEPARATOR . 'estructura_derivada.sql';
    file_put_contents($rutaDdl, $ddl);
    $manifiesto['fases']['B'] = ($manifiesto['fases']['B'] ?? []) + ['preparada' => true];
    pm11GuardarManifiesto($base, $manifiesto);
    echo "Copia verificada (mismo código que el repositorio), .env aislado sin credenciales y estructura derivada (12 tablas) en $dir\n";

    $codigo = 0;
    if ($soloPreparar) {
        echo "--solo-preparar: NO se tocó SQL Server. La carga requiere la autorización B.\n";
    } else {
        if (empty($manifiesto['fases']['A'])) {
            pm11Abortar('La fase A no está registrada.', 3);
        }
        $codigo = pm11Ejecutar($copia . '/tests/integracion/pm11_trabajo.php', ['cargar', $base, $rutaDdl]);
    }
    pm11ComprobarProtegidas($protegidas0, pm11HuellasProtegidas($backendHabitual, $sqlOriginal));
    exit($codigo);
}

// ═══ C: validación de solo lectura ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if ($fase === 'C') {
    if (empty($manifiesto['fases']['B']['hecha']) || !is_dir($copia)) {
        pm11Abortar('La fase B no está hecha (o falta la copia de trabajo).', 3);
    }
    pm11VerificarCopia($backendHabitual, $copia, $carpetasCopia);
    $codigo = pm11Ejecutar($copia . '/tests/integracion/pm11_trabajo.php', ['validar', $base]);
    pm11ComprobarProtegidas($protegidas0, pm11HuellasProtegidas($backendHabitual, $sqlOriginal));
    exit($codigo);
}

// ═══ D: DROP DATABASE de ESTA base ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
try {
    $pdo = pm11ConexionMaster((string) $manifiesto['servidor'], (string) $manifiesto['puerto'], (string) $manifiesto['loginEsperado']);
    $fila = $pdo->prepare("SELECT database_id, CONVERT(VARCHAR(30), create_date, 126) AS creada FROM sys.databases WHERE name = :n");
    $fila->execute([':n' => $base]);
    $f = $fila->fetch(PDO::FETCH_ASSOC);
    if ($f === false) {
        echo "La base ya no existe en el servidor.\n";
    } elseif ((int) $f['database_id'] !== (int) $manifiesto['databaseId'] || $f['creada'] !== $manifiesto['creadaEn']) {
        pm11Abortar('La base con ese nombre NO es la que creó la fase A (database_id / fecha de creación distintos): no se borra.', 5);
    } else {
        echo 'Resumen antes de borrar: ', json_encode($manifiesto['fases'], JSON_UNESCAPED_UNICODE), "\n";
        $pdo->exec("DROP DATABASE [$base]");
        $queda = $pdo->prepare('SELECT COUNT(*) FROM sys.databases WHERE name = :n');
        $queda->execute([':n' => $base]);
        if ((int) $queda->fetchColumn() !== 0) {
            pm11Abortar('La base sigue existiendo tras el DROP.');
        }
        echo "Base «{$base}» eliminada.\n";
    }
} catch (PDOException $e) {
    pm11Abortar('Error de SQL Server: ' . $e->getMessage());
}
if (!in_array('--conservar-copia', $banderas, true)) {
    pm11BorrarDirTrabajo($dir); // copia, estructura, credenciales sintéticas y manifiesto (solo dentro del directorio temporal y con el nombre jatsa_pm11_AAAAMMDD_HHMM)
    echo "Copia de trabajo y credenciales sintéticas borradas.\n";
}
pm11ComprobarProtegidas($protegidas0, pm11HuellasProtegidas($backendHabitual, $sqlOriginal));
exit(0);
