<?php
declare(strict_types=1);

/*
 * CREA la base SQL Server AISLADA y su login dedicado para validar pm10 (autorización A del plan; docs/plan-pm10-bd-aislada.md). NO SE HA EJECUTADO.
 *
 * Uso: php crear_bd_aislada.php <JATSA_pm10_aislada_AAAAMMDD_HHMM>      (lo invoca crear_bd_aislada.ps1; no se ejecuta sin autorización expresa)
 *
 * Se conecta a «master» con una cuenta ADMINISTRADORA que da el propietario por variables de entorno del proceso (nunca guardadas ni impresas):
 *   JATSA_PM10_ADMIN_SERVIDOR, JATSA_PM10_ADMIN_PUERTO (opcional), JATSA_PM10_ADMIN_USUARIO, JATSA_PM10_ADMIN_CLAVE
 * y la contraseña del login aislado (la elige el propietario; solo vive en su entorno): JATSA_PM10_CLAVE_AISLADA.
 *
 * Qué hace, y nada más:
 *   1) valida el nombre (patrón de base aislada; nunca «Encomiendas») y la contraseña (16-64 caracteres seguros, porque el DDL no admite parámetros);
 *   2) comprueba que NO existen ni la base ni el login (si existe cualquiera, se detiene: no se reutiliza ni se pisa nada);
 *   3) CREATE DATABASE <base>;  CREATE LOGIN jatsa_pm10_aislado;  en la base nueva: CREATE USER, db_owner, y VIEW SERVER STATE al login
 *      (para poder comprobar, antes de limpiar, que no quedan sesiones ni transacciones de la prueba);
 *   4) comprueba con el LOGIN AISLADO (una conexión nueva) que entra a la base nueva y que NO puede abrir la base habitual JATSA_Encomiendas.
 * No toca ninguna otra base, no escribe en JATSA_Encomiendas, no ejecuta el script SQL original, no borra nada.
 */
function abortar(string $mensaje, int $codigo = 4): never
{
    fwrite(STDERR, "DETENIDO: $mensaje\n");
    exit($codigo);
}

function conectar(string $servidor, string $base, string $usuario, string $clave): PDO
{
    if (!extension_loaded('pdo_sqlsrv')) {
        abortar('Falta la extensión pdo_sqlsrv.', 2);
    }

    return new PDO(sprintf('sqlsrv:Server=%s;Database=%s;Encrypt=false;TrustServerCertificate=true;LoginTimeout=8', $servidor, $base), $usuario, $clave, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$base = (string) ($argv[1] ?? '');
$login = 'jatsa_pm10_aislado';
if (preg_match('/^JATSA_pm10_aislada_\d{8}_\d{4}$/', $base) !== 1 || stripos($base, 'encomiendas') !== false) {
    abortar('El nombre no es el de una base aislada válida.', 2);
}
$claveLogin = (string) getenv('JATSA_PM10_CLAVE_AISLADA');
if (preg_match('/^[A-Za-z0-9#_.\-]{16,64}$/', $claveLogin) !== 1) {
    abortar('JATSA_PM10_CLAVE_AISLADA debe tener 16-64 caracteres entre letras, números y # _ . -', 2);
}
$servidor = (string) getenv('JATSA_PM10_ADMIN_SERVIDOR');
$puerto = (string) getenv('JATSA_PM10_ADMIN_PUERTO');
$admin = (string) getenv('JATSA_PM10_ADMIN_USUARIO');
$claveAdmin = (string) getenv('JATSA_PM10_ADMIN_CLAVE');
if ($servidor === '' || $admin === '' || $claveAdmin === '' || preg_match('/^[A-Za-z0-9_.\-\\\\]+$/', $servidor) !== 1 || ($puerto !== '' && preg_match('/^\d{2,5}$/', $puerto) !== 1)) {
    abortar('Faltan o son inválidos los datos de la cuenta administradora (JATSA_PM10_ADMIN_*).', 2);
}
$servidorDsn = $servidor . ($puerto !== '' ? ',' . $puerto : '');

try {
    $pdo = conectar($servidorDsn, 'master', $admin, $claveAdmin);
    $existe = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM sys.databases WHERE name = '$base') + (SELECT COUNT(*) FROM sys.server_principals WHERE name = '$login')")->fetchColumn();
    if ($existe !== 0) {
        abortar('Ya existe la base o el login aislado: no se reutiliza ni se pisa nada. Elige otro nombre de base o pide al propietario que retire lo anterior.', 3);
    }
    $pdo->exec("CREATE DATABASE [$base]");
    $pdo->exec("CREATE LOGIN [$login] WITH PASSWORD = '$claveLogin', CHECK_POLICY = OFF, DEFAULT_DATABASE = [$base]");
    $pdo->exec("GRANT VIEW SERVER STATE TO [$login]");
    $enBase = conectar($servidorDsn, $base, $admin, $claveAdmin);
    $enBase->exec("CREATE USER [$login] FOR LOGIN [$login]");
    $enBase->exec("ALTER ROLE db_owner ADD MEMBER [$login]");
    echo "Base «$base» y login «$login» creados.\n";

    // Verificación con el login aislado: entra a la base nueva y NO puede abrir la base habitual.
    $aislado = conectar($servidorDsn, $base, $login, $claveLogin);
    $actual = $aislado->query('SELECT DB_NAME() AS bd, SUSER_SNAME() AS login')->fetch(PDO::FETCH_ASSOC);
    if ($actual['bd'] !== $base || $actual['login'] !== $login) {
        abortar('El login aislado no quedó conectado a la base nueva.');
    }
    $accesoARealidad = true;
    try {
        conectar($servidorDsn, 'JATSA_Encomiendas', $login, $claveLogin);
    } catch (PDOException $e) {
        $accesoARealidad = false; // lo esperado: acceso denegado
    }
    if ($accesoARealidad) {
        abortar('ATENCIÓN: el login aislado PUEDE abrir JATSA_Encomiendas. Retira el login y la base antes de continuar.', 5);
    }
    echo "Verificado: el login aislado entra a la base nueva y NO puede abrir JATSA_Encomiendas.\n";
} catch (PDOException $e) {
    abortar('Error de SQL Server: ' . $e->getMessage());
}
exit(0);