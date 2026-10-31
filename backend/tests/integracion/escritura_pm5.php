<?php
declare(strict_types=1);

/*
 * ESCRITURA ACOTADA A REGISTROS pm5 (solo se usa con autorización expresa del propietario; ver validar-pm5.ps1).
 *   crear-personal             INSERT de la persona y la cuenta pm5_admin (ADMINISTRADOR de MOQ001). Clave en env JATSA_PM5_PW.
 *   fecha <codigo> "<fecha>"   UPDATE de fechaHoraReserva, solo de reservas PENDIENTES cuyo remitente es una persona pm5.
 *   limpiar                    DELETE de Bulto → Envio → Reserva → Usuario → Persona, solo de lo creado por pm5.
 * Cualquier otra operación se rechaza. No imprime contraseñas.
 */
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';

use Jatsa\Core\Config;
use Jatsa\Core\Database;

const DOCUMENTOS_PM5 = ['90000051', '90000052', '90000053', '90000054'];

Config::cargar($raiz);
$pdo = Database::conexion();
$op = $argv[1] ?? '';
$lista = "'" . implode("','", DOCUMENTOS_PM5) . "'"; // constantes del script, no entrada del usuario

if ($op === 'crear-personal') {
    $clave = (string) getenv('JATSA_PM5_PW');
    if (strlen($clave) < 12) {
        fwrite(STDERR, "Falta JATSA_PM5_PW.\n");
        exit(2);
    }
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono) VALUES ('DNI', '90000052', N'Prueba Cinco Admin', '900000052')");
    $u = $pdo->prepare(
        "INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol)
         VALUES ((SELECT idPersona FROM Persona WHERE tipoDocumento = 'DNI' AND numeroDocumento = '90000052'),
                 (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = 'MOQ001'), 'pm5_admin', :h, 'ADMINISTRADOR')",
    );
    $u->bindValue(':h', password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]));
    $u->execute();
    $pdo->commit();
    echo "pm5_admin creada\n";
    exit(0);
}

if ($op === 'fecha') {
    $codigo = (string) ($argv[2] ?? '');
    $fecha = (string) ($argv[3] ?? '');
    if (!preg_match('/^R\d{9}$/', $codigo) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
        fwrite(STDERR, "Argumentos inválidos.\n");
        exit(2);
    }
    $s = $pdo->prepare(
        "UPDATE r SET r.fechaHoraReserva = :f
         FROM Reserva AS r INNER JOIN Persona AS p ON p.idPersona = r.idPersonaRemitente
         WHERE r.codigoReserva = :c AND r.estadoReserva = 'PENDIENTE' AND p.numeroDocumento IN ($lista)",
    );
    $s->bindValue(':f', $fecha);
    $s->bindValue(':c', $codigo);
    $s->execute();
    echo "$codigo -> $fecha; filas afectadas: " . $s->rowCount() . "\n";
    exit($s->rowCount() === 1 ? 0 : 3);
}

if ($op === 'limpiar') {
    $personas = $pdo->query("SELECT idPersona FROM Persona WHERE numeroDocumento IN ($lista)")->fetchAll(PDO::FETCH_COLUMN);
    $ids = implode(',', array_map('intval', $personas)) ?: '0';
    $reservas = $pdo->query("SELECT idReserva, codigoReserva FROM Reserva WHERE idPersonaRemitente IN ($ids) OR idPersonaDestinatario IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
    $idsReserva = implode(',', array_map(static fn ($r) => (int) $r['idReserva'], $reservas)) ?: '0';
    $envios = $pdo->query("SELECT idEnvio, codigoSeguimiento FROM Envio WHERE idReserva IN ($idsReserva)")->fetchAll(PDO::FETCH_ASSOC);
    $idsEnvio = implode(',', array_map(static fn ($e) => (int) $e['idEnvio'], $envios)) ?: '0';
    echo 'personas: ' . json_encode($personas) . ' reservas: ' . json_encode(array_column($reservas, 'codigoReserva')) . ' envios: ' . json_encode(array_column($envios, 'codigoSeguimiento')) . "\n";

    // Dependencias que NO son de esta prueba: pagos o viajes sobre estos envíos, o reservas ajenas que usen personas pm5.
    $pagos = (int) $pdo->query("SELECT COUNT(*) FROM Pago WHERE idEnvio IN ($idsEnvio)")->fetchColumn();
    $viajes = (int) $pdo->query("SELECT COUNT(*) FROM Envio_Viaje WHERE idEnvio IN ($idsEnvio)")->fetchColumn();
    echo "dependencias: pagos=$pagos viajes=$viajes\n";
    if ($pagos !== 0 || $viajes !== 0) {
        fwrite(STDERR, "Hay dependencias: no se borra nada.\n");
        exit(4);
    }
    $pdo->beginTransaction();
    $b = $pdo->exec("DELETE FROM Bulto WHERE idEnvio IN ($idsEnvio)");
    $e = $pdo->exec("DELETE FROM Envio WHERE idEnvio IN ($idsEnvio)");
    $r = $pdo->exec("DELETE FROM Reserva WHERE idReserva IN ($idsReserva)");
    $u = $pdo->exec("DELETE FROM Usuario WHERE nombreUsuario IN ('pm5_cliente', 'pm5_admin') AND idPersona IN ($ids)");
    $p = $pdo->exec("DELETE FROM Persona WHERE idPersona IN ($ids) AND numeroDocumento IN ($lista)");
    $pdo->commit();
    echo "borradas: Bulto=$b Envio=$e Reserva=$r Usuario=$u Persona=$p\n";
    exit(0);
}

fwrite(STDERR, "Operación no permitida.\n");
exit(2);
