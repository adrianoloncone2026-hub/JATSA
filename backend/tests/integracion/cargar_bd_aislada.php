<?php
declare(strict_types=1);

/*
 * CARGA la estructura y la semilla SINTÉTICA en la base AISLADA (autorización B del plan; docs/plan-pm10-bd-aislada.md). NO SE HA EJECUTADO.
 *
 * Uso (desde la COPIA de trabajo del backend, con su .env aislado): php cargar_bd_aislada.php <base> <estructura-derivada.sql> <cuenta-windows-esperada>
 *
 *   1) comprueba que ESTA conexión (la de la copia) está en la base aislada, con la cuenta Windows esperada y autenticación de Windows, y que NO es JATSA_Encomiendas
 *      (la cuenta es sysadmin: no hay aislamiento por permisos, solo esta verificación y la configuración de la copia);
 *   2) comprueba que la base está VACÍA (ninguna tabla): no carga nada sobre algo existente;
 *   3) revalida la estructura derivada (sin JATSA_Encomiendas, sin INSERT, sin DDL de bases/logins, 12 CREATE TABLE, USE de la base aislada) y la aplica
 *      lote por lote («GO»); el «USE» inicial se omite porque la conexión ya está en la base aislada;
 *   4) inserta una semilla SINTÉTICA MÍNIMA en una transacción: 2 personas, 2 sucursales (TST001, TST002) con un teléfono cada una, un vehículo,
 *      una tarifa y un gerente «seed_gerente» ACTIVO (contraseña aleatoria que nadie conoce: no se imprime ni se guarda). Quedan VACÍAS a propósito
 *      Reserva, Envio, Bulto, Viaje, Envio_Viaje y Pago (ejercitan la identificación de ids en tablas vacías);
 *   5) comprueba los conteos.
 * Ningún dato personal real. No toca el script SQL original (solo recibe la copia derivada).
 */
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';

use Jatsa\Core\Config;
use Jatsa\Core\Database;

function abortar(string $mensaje, int $codigo = 4): never
{
    fwrite(STDERR, "DETENIDO: $mensaje\n");
    exit($codigo);
}

$base = (string) ($argv[1] ?? '');
$ruta = (string) ($argv[2] ?? '');
$loginEsperado = (string) ($argv[3] ?? '');
if ($loginEsperado === '') {
    abortar('Falta la cuenta Windows esperada.', 2);
}
if (preg_match('/^JATSA_pm10_aislada_\d{8}_\d{4}$/', $base) !== 1 || stripos($base, 'encomiendas') !== false) {
    abortar('El nombre no es el de una base aislada válida.', 2);
}
if (!is_file($ruta)) {
    abortar('Falta la estructura derivada.', 2);
}
Config::cargar($raiz);
$pdo = Database::conexion();

$actual = $pdo->query('SELECT DB_NAME() AS bd, ORIGINAL_LOGIN() AS login, (SELECT MAX(auth_scheme) FROM sys.dm_exec_connections WHERE session_id = @@SPID) AS auth')->fetch(PDO::FETCH_ASSOC);
if ($actual['bd'] !== $base || strcasecmp((string) $actual['login'], $loginEsperado) !== 0 || !in_array(strtoupper((string) $actual['auth']), ['NTLM', 'KERBEROS', 'NEGOTIATE'], true) || stripos((string) $actual['bd'], 'encomiendas') !== false) {
    abortar('Esta conexión NO está en la base aislada con la cuenta Windows esperada y autenticación de Windows (' . $actual['bd'] . ' / ' . $actual['login'] . ' / ' . $actual['auth'] . '). No se carga nada.', 5);
}
if ((int) $pdo->query('SELECT COUNT(*) FROM sys.tables')->fetchColumn() !== 0) {
    abortar('La base aislada NO está vacía: no se carga nada sobre algo existente.', 3);
}

// Revalidación de la estructura derivada (la misma que hace el PowerShell; aquí, otra vez, por separado).
$ddl = (string) file_get_contents($ruta);
$sinComentarios = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $ddl);
if (stripos($ddl, 'JATSA_Encomiendas') !== false) {
    abortar('La estructura derivada menciona JATSA_Encomiendas.', 2);
}
if (preg_match('/\b(INSERT|DROP|TRUNCATE|DBCC|EXEC|EXECUTE|UPDATE|DELETE|MERGE)\b/i', $sinComentarios) === 1 || preg_match('/\b(CREATE|ALTER|DROP)\s+(DATABASE|LOGIN|USER)\b/i', $sinComentarios) === 1) {
    abortar('La estructura derivada contiene sentencias no estructurales o sobre bases/logins.', 2);
}
if (preg_match_all('/^\s*CREATE\s+TABLE\s+\[?\w+\]?/im', $sinComentarios) !== 12) {
    abortar('La estructura derivada no tiene exactamente 12 CREATE TABLE.', 2);
}
$lotes = [];
$actualLote = [];
foreach (preg_split('/\r?\n/', $ddl) ?: [] as $linea) {
    if (preg_match('/^\s*GO\s*$/i', $linea) === 1) {
        if (trim(implode("\n", $actualLote)) !== '') {
            $lotes[] = implode("\n", $actualLote);
        }
        $actualLote = [];
    } else {
        $actualLote[] = $linea;
    }
}
if (trim(implode("\n", $actualLote)) !== '') {
    $lotes[] = implode("\n", $actualLote);
}
if (count($lotes) < 2 || trim($lotes[0]) !== "USE [$base];") {
    abortar('El primer lote de la estructura debe ser exactamente USE de la base aislada.', 2);
}
foreach (array_slice($lotes, 1) as $lote) {
    if (preg_match('/^\s*USE\b/im', $lote) === 1) {
        abortar('Hay un USE dentro de los lotes de estructura.', 2);
    }
}

// 3) Estructura.
foreach (array_slice($lotes, 1) as $i => $lote) {
    $pdo->exec($lote);
}
echo count($lotes) - 1, " lote(s) de estructura aplicados\n";

// 4) Semilla sintética mínima.
$pdo->beginTransaction();
try {
    $persona = $pdo->prepare("INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono) VALUES ('DNI', :d, :n, :t)");
    foreach ([['99999901', 'Gerente Sintético Semilla', '999999901'], ['99999902', 'Persona Sintética Sin Cuenta', '999999902']] as [$d, $n, $t]) {
        $persona->execute([':d' => $d, ':n' => $n, ':t' => $t]);
    }
    $sucursal = $pdo->prepare('INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa) VALUES (:c, :n, :d, :p, :di, :dir, 1)');
    foreach ([['TST001', 'Agencia Sintética Uno'], ['TST002', 'Agencia Sintética Dos']] as [$c, $n]) {
        $sucursal->execute([':c' => $c, ':n' => $n, ':d' => 'Prueba', ':p' => 'Prueba', ':di' => 'Prueba', ':dir' => 'Av. Sintética 100']);
    }
    $telefono = $pdo->prepare('INSERT INTO Telefono_Sucursal (idSucursal, numero) VALUES ((SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :c), :n)');
    foreach ([['TST001', '999000001'], ['TST002', '999000002']] as [$c, $n]) {
        $telefono->execute([':c' => $c, ':n' => $n]);
    }
    $clave = bin2hex(random_bytes(24)); // nadie la conoce: la cuenta semilla no inicia sesión
    $usuario = $pdo->prepare("INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol, activo) VALUES ((SELECT idPersona FROM Persona WHERE numeroDocumento = '99999901'), NULL, 'seed_gerente', :h, 'GERENTE', 1)");
    $usuario->execute([':h' => password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12])]);
    $pdo->exec("INSERT INTO Vehiculo (placa, tipoVehiculo, capacidadKg, activo) VALUES ('TST-001', 'FURGON', 1000.00, 1)");
    $pdo->exec("INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta)
                VALUES ((SELECT idSucursal FROM Sucursal WHERE codigoSucursal = 'TST001'), (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = 'TST002'), 'SOBRE', 8.00, 0.005, 100.0, 2, '2026-01-01', NULL)");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    abortar('La semilla se deshizo: ' . $e->getMessage());
}

// 5) Conteos esperados: lo no vacío y lo vacío a propósito.
$esperado = ['Persona' => 2, 'Sucursal' => 2, 'Telefono_Sucursal' => 2, 'Usuario' => 1, 'Vehiculo' => 1, 'Ruta' => 1, 'Reserva' => 0, 'Envio' => 0, 'Bulto' => 0, 'Viaje' => 0, 'Envio_Viaje' => 0, 'Pago' => 0];
$fallos = [];
foreach ($esperado as $tabla => $n) {
    $real = (int) $pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
    echo sprintf("%-18s %d fila(s)%s\n", $tabla, $real, $real === $n ? '' : "  <-- se esperaban $n");
    if ($real !== $n) {
        $fallos[] = $tabla;
    }
}
if ($fallos !== []) {
    abortar('Los conteos no coinciden en: ' . implode(', ', $fallos));
}
echo "Estructura y semilla sintética cargadas en «{$base}».\n";
exit(0);