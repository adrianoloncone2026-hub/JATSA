<?php
declare(strict_types=1);

// SOLO SELECT. Uso: php leer.php "<SELECT ...>"  â†’ imprime filas como JSON. Rechaza cualquier otra cosa.
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';

use Jatsa\Core\Config;
use Jatsa\Core\Database;

$sql = trim((string) ($argv[1] ?? ''));
if (!preg_match('/^SELECT\s/i', $sql) || preg_match('/;|\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|EXEC|MERGE|INTO)\b/i', $sql)) {
    fwrite(STDERR, "Rechazado: solo se permite un SELECT simple.\n");
    exit(2);
}
Config::cargar($raiz);
$filas = Database::conexion()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
