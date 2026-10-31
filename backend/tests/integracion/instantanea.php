<?php
declare(strict_types=1);

// SOLO LECTURA (Ãºnicamente SELECT). Uso: php instantanea.php <archivo_salida.json>
// Toma por tabla: filas, suma de verificaciÃ³n del contenido, IDENT_CURRENT y un hash por fila; y busca restos pm4/pm3.
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';

use Jatsa\Core\Config;
use Jatsa\Core\Database;

Config::cargar($raiz);
$pdo = Database::conexion();
$salida = [];

$tablas = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablas as $tabla) {
    $t = '[' . str_replace(']', ']]', (string) $tabla) . ']'; // nombre sale del catÃ¡logo del servidor, no de entrada de usuario
    $fila = ['tabla' => $tabla];
    $fila['filas'] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    try {
        $fila['checksum'] = (string) $pdo->query("SELECT CHECKSUM_AGG(BINARY_CHECKSUM(*)) FROM $t")->fetchColumn();
    } catch (Throwable) {
        $fila['checksum'] = 'no-disponible';
    }
    $ident = $pdo->query("SELECT IDENT_CURRENT('dbo.' + " . $pdo->quote((string) $tabla) . ')')->fetchColumn();
    $fila['ident'] = $ident === null || $ident === false ? null : (string) $ident;
    // hash por fila (para comparar fila a fila las originales)
    $filas = $pdo->query("SELECT CAST(BINARY_CHECKSUM(*) AS VARCHAR(20)) AS h FROM $t")->fetchAll(PDO::FETCH_COLUMN);
    sort($filas);
    $fila['hashes'] = $filas;
    $salida['tablas'][$tabla] = $fila;
}

// Restos de pruebas: cuentas y personas pm3/pm4 y documentos de prueba.
$salida['restos'] = [
    'usuarios_pm' => $pdo->query("SELECT nombreUsuario FROM Usuario WHERE nombreUsuario LIKE 'pm%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN),
    'personas_prueba' => $pdo->query("SELECT numeroDocumento FROM Persona WHERE numeroDocumento IN ('90000051','90000052','90000053','90000054') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN),
    'reservas_total' => (int) $pdo->query('SELECT COUNT(*) FROM Reserva')->fetchColumn(),
];
$salida['reloj_sql'] = (string) $pdo->query('SELECT CONVERT(VARCHAR(19), SYSDATETIME(), 120)')->fetchColumn();

file_put_contents($argv[1], json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
foreach ($salida['tablas'] as $f) {
    printf("%-22s filas=%-4d ident=%-6s checksum=%s\n", $f['tabla'], $f['filas'], $f['ident'] ?? '-', $f['checksum']);
}
echo 'restos: ' . json_encode($salida['restos'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'reloj SQL: ' . $salida['reloj_sql'] . "\n";
