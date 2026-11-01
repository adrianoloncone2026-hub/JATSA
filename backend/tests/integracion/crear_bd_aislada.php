<?php
declare(strict_types=1);

/*
 * CREA la base SQL Server AISLADA para validar pm10 (autorización A del plan; docs/plan-pm10-bd-aislada.md). NO SE HA EJECUTADO.
 *
 * Uso: php crear_bd_aislada.php <JATSA_pm10_aislada_AAAAMMDD_HHMM>      (lo invoca crear_bd_aislada.ps1; no se ejecuta sin autorización expresa)
 *
 * AUTENTICACIÓN INTEGRADA DE WINDOWS: la instancia solo admite Windows (SoloWindows = 1), así que NO se crea ningún login ni usuario SQL, no se asigna
 * ningún permiso y no se necesita ninguna credencial: la conexión usa la cuenta Windows con la que corre este proceso, que es SYSADMIN. Por eso NO existe
 * aislamiento mediante permisos: el aislamiento depende de la configuración y de las verificaciones de DB_NAME() de los demás scripts.
 *
 * Datos (no secretos), por variables de entorno del proceso: JATSA_PM10_SERVIDOR y, opcional, JATSA_PM10_PUERTO.
 *
 * Qué hace, y nada más:
 *   1) valida el nombre (patrón de base aislada; nunca «Encomiendas»);
 *   2) conecta a «master» con la cuenta Windows y comprueba: autenticación Windows (NTLM/KERBEROS) y pertenencia a sysadmin (se informa; es lo que SSMS ya mostró);
 *   3) comprueba que la base NO existe (si existe, se detiene: no se reutiliza ni se pisa nada);
 *   4) CREATE DATABASE <base>;
 *   5) abre una conexión nueva a la base creada y comprueba DB_NAME() y la misma cuenta.
 * No crea logins ni usuarios, no concede permisos, no cambia la configuración de la instancia (ni el modo de autenticación), no toca ninguna otra base, no
 * escribe en JATSA_Encomiendas, no ejecuta el script SQL original y no borra nada.
 */
function abortar(string $mensaje, int $codigo = 4): never
{
    fwrite(STDERR, "DETENIDO: $mensaje\n");
    exit($codigo);
}

function conectar(string $servidor, string $base): PDO
{
    if (!extension_loaded('pdo_sqlsrv')) {
        abortar('Falta la extensión pdo_sqlsrv.', 2);
    }

    // Sin usuario ni contraseña: autenticación integrada de Windows.
    return new PDO(sprintf('sqlsrv:Server=%s;Database=%s;Encrypt=false;TrustServerCertificate=true;LoginTimeout=8', $servidor, $base), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$base = (string) ($argv[1] ?? '');
if (preg_match('/^JATSA_pm10_aislada_\d{8}_\d{4}$/', $base) !== 1 || stripos($base, 'encomiendas') !== false) {
    abortar('El nombre no es el de una base aislada válida.', 2);
}
$servidor = (string) getenv('JATSA_PM10_SERVIDOR');
$puerto = (string) getenv('JATSA_PM10_PUERTO');
if ($servidor === '' || preg_match('/^[A-Za-z0-9_.\-\\\\]+$/', $servidor) !== 1 || ($puerto !== '' && preg_match('/^\d{2,5}$/', $puerto) !== 1)) {
    abortar('Falta o es inválido JATSA_PM10_SERVIDOR / JATSA_PM10_PUERTO.', 2);
}
$servidorDsn = $servidor . ($puerto !== '' ? ',' . $puerto : '');

try {
    $pdo = conectar($servidorDsn, 'master');
    $yo = $pdo->query("SELECT ORIGINAL_LOGIN() AS login, IS_SRVROLEMEMBER('sysadmin') AS sysadmin, CAST(SERVERPROPERTY('IsIntegratedSecurityOnly') AS INT) AS soloWindows, (SELECT auth_scheme FROM sys.dm_exec_connections WHERE session_id = @@SPID) AS auth")->fetch(PDO::FETCH_ASSOC);
    if (!in_array(strtoupper((string) $yo['auth']), ['NTLM', 'KERBEROS', 'NEGOTIATE'], true)) {
        abortar('La conexión NO usa autenticación de Windows (auth_scheme ' . $yo['auth'] . '). No se continúa.', 5);
    }
    if ((int) $yo['sysadmin'] !== 1) {
        abortar('La cuenta ' . $yo['login'] . ' no es sysadmin: no puede crear la base. No se continúa.', 5);
    }
    echo 'Cuenta Windows: ', $yo['login'], ' (sysadmin; SoloWindows=', (int) $yo['soloWindows'], "). No existe aislamiento por permisos: solo la configuración y las verificaciones de DB_NAME().\n";

    $existe = (int) $pdo->query("SELECT COUNT(*) FROM sys.databases WHERE name = '$base'")->fetchColumn();
    if ($existe !== 0) {
        abortar('Ya existe la base aislada: no se reutiliza ni se pisa nada. Elige otro nombre.', 3);
    }
    $pdo->exec("CREATE DATABASE [$base]");
    echo "Base «$base» creada.\n";

    $nueva = conectar($servidorDsn, $base)->query('SELECT DB_NAME() AS bd, ORIGINAL_LOGIN() AS login')->fetch(PDO::FETCH_ASSOC);
    if ($nueva['bd'] !== $base || strcasecmp((string) $nueva['login'], (string) $yo['login']) !== 0) {
        abortar('La conexión nueva no quedó en la base creada con la misma cuenta.');
    }
    echo "Verificado: una conexión nueva con la misma cuenta Windows entra a «$base».\n";
} catch (PDOException $e) {
    abortar('Error de SQL Server: ' . $e->getMessage());
}
exit(0);
