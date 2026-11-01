<?php
declare(strict_types=1);

/*
 * ESCRITURA ACOTADA A LO CREADO POR ESTA EJECUCIÓN pm7 (solo con autorización expresa; ver validar-pm7.ps1).
 *
 * El orquestador lleva un MANIFIESTO (JSON) con lo que él mismo creó en esta ejecución:
 *   identAntes {Persona, Usuario, Reserva, Envio, Viaje, Envio_Viaje}  IDENT_CURRENT de la instantánea inicial: lo creado después tiene id mayor
 *   documentos [...]  documentos de las personas que creó (90000071-76)
 *   usuarios   [...]  nombres de las cuentas que creó (pm7_*)
 *   reservas   [...]  códigos de las reservas que creó
 *   envios     [...]  códigos de seguimiento de los envíos que creó
 *   viajes     [...]  códigos de los viajes que creó (por los endpoints)
 *
 *   crear-personal <manifiesto>   INSERT de las cuentas de personal: pm7_admin (ADMINISTRADOR de MOQ001), pm7_admin2 (ADMINISTRADOR de AQP001),
 *                                 pm7_trans1 (TRANSPORTISTA de AQP001) y pm7_trans2 (TRANSPORTISTA de MOQ001); aborta si ya existe algo.
 *                                 Claves en las variables de entorno JATSA_PM7_PW_ADMIN, _ADMIN2, _TRANS1 y _TRANS2 (nunca se imprimen).
 *   limpiar <manifiesto>          DELETE exacto de lo registrado (Envio_Viaje → Viaje → Bulto → Envio → Reserva → Usuario → Persona). Verifica
 *                                 ANTES que lo que existe coincide con el manifiesto (cantidades, ids posteriores a la instantánea, sin filas
 *                                 ajenas ni dependencias, y que las cargas Envio_Viaje sean SOLO entre viajes y envíos del manifiesto) y, al
 *                                 borrar, que cada DELETE afecte exactamente lo esperado; si algo diverge, no borra nada y pide revisión.
 * Cualquier otra operación se rechaza.
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

/** @return list<string> */
function textos(mixed $valores, string $patron): array
{
    $lista = is_array($valores) ? array_values($valores) : [];
    foreach ($lista as $v) {
        if (!is_string($v) || preg_match($patron, $v) !== 1) {
            abortar('El manifiesto contiene un valor con formato inesperado.', 2);
        }
    }

    return $lista;
}

function enLista(array $valores): string
{
    return "'" . implode("','", $valores) . "'"; // valores ya validados con una expresión estricta, nunca entrada libre
}

Config::cargar($raiz);
$op = $argv[1] ?? '';
$ruta = (string) ($argv[2] ?? '');
$manifiesto = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
if (!is_array($manifiesto) || !isset($manifiesto['identAntes']) || !is_array($manifiesto['identAntes'])) {
    abortar('Falta el manifiesto (o es inválido).', 2);
}
$ident = array_map('intval', $manifiesto['identAntes']);
foreach (['Persona', 'Usuario', 'Reserva', 'Envio', 'Viaje', 'Envio_Viaje'] as $t) {
    if (!isset($ident[$t])) {
        abortar("El manifiesto no trae IDENT_CURRENT de $t.", 2);
    }
}
$documentos = textos($manifiesto['documentos'] ?? [], '/^9000007[1-6]$/');
$usuarios = textos($manifiesto['usuarios'] ?? [], '/^pm7_(cliente|dest|admin|admin2|trans1|trans2)$/');
$reservas = textos($manifiesto['reservas'] ?? [], '/^R\d{9}$/');
$envios = textos($manifiesto['envios'] ?? [], '/^EN[A-Z0-9]{10}$/');
$viajes = textos($manifiesto['viajes'] ?? [], '/^VJ\d{10}$/');
$pdo = Database::conexion();

if ($op === 'crear-personal') {
    $claves = [
        'pm7_admin' => (string) getenv('JATSA_PM7_PW_ADMIN'), 'pm7_admin2' => (string) getenv('JATSA_PM7_PW_ADMIN2'),
        'pm7_trans1' => (string) getenv('JATSA_PM7_PW_TRANS1'), 'pm7_trans2' => (string) getenv('JATSA_PM7_PW_TRANS2'),
    ];
    foreach ($claves as $clave) {
        if (strlen($clave) < 12) {
            abortar('Faltan las contraseñas efímeras (JATSA_PM7_PW_*).', 2);
        }
    }
    $existe = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM Persona WHERE numeroDocumento IN ('90000072','90000073','90000075','90000076')) + (SELECT COUNT(*) FROM Usuario WHERE nombreUsuario IN ('pm7_admin','pm7_admin2','pm7_trans1','pm7_trans2'))")->fetchColumn();
    if ($existe !== 0) {
        abortar('Ya existe una persona o cuenta pm7 de personal: no se crea nada.', 3);
    }
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono) VALUES
        ('DNI', '90000072', N'Prueba Siete Admin', '900000072'), ('DNI', '90000073', N'Prueba Siete Admin Arequipa', '900000073'),
        ('DNI', '90000075', N'Prueba Siete Transportista Uno', '900000075'), ('DNI', '90000076', N'Prueba Siete Transportista Dos', '900000076')");
    $u = $pdo->prepare(
        "INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol)
         VALUES ((SELECT idPersona FROM Persona WHERE tipoDocumento = 'DNI' AND numeroDocumento = :documento),
                 (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :sucursal), :usuario, :h, :rol)",
    );
    foreach ([['90000072', 'MOQ001', 'pm7_admin', 'ADMINISTRADOR'], ['90000073', 'AQP001', 'pm7_admin2', 'ADMINISTRADOR'], ['90000075', 'AQP001', 'pm7_trans1', 'TRANSPORTISTA'], ['90000076', 'MOQ001', 'pm7_trans2', 'TRANSPORTISTA']] as [$documento, $sucursal, $usuario, $rol]) {
        $u->bindValue(':documento', $documento);
        $u->bindValue(':sucursal', $sucursal);
        $u->bindValue(':usuario', $usuario);
        $u->bindValue(':h', password_hash($claves[$usuario], PASSWORD_BCRYPT, ['cost' => 12]));
        $u->bindValue(':rol', $rol);
        $u->execute();
    }
    $pdo->commit();
    echo "pm7_admin (MOQ001), pm7_admin2 (AQP001), pm7_trans1 (AQP001) y pm7_trans2 (MOQ001) creadas\n";
    exit(0);
}

if ($op === 'limpiar') {
    $uno = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    $filas = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    // 1) Personas: las del manifiesto, y solo si son posteriores a la instantánea.
    $idsPersona = $documentos === [] ? [] : array_map('intval', $filas("SELECT idPersona FROM Persona WHERE numeroDocumento IN (" . enLista($documentos) . ") AND idPersona > {$ident['Persona']}"));
    if (count($idsPersona) !== count($documentos)) {
        abortar('Las personas existentes no coinciden con el manifiesto (cantidad o ids anteriores a la prueba).');
    }
    $listaPersonas = $idsPersona === [] ? '0' : implode(',', $idsPersona);

    // 2) Cuentas: las del manifiesto, ligadas a esas personas y posteriores a la instantánea.
    $idsUsuario = $usuarios === [] ? [] : array_map('intval', $filas("SELECT idUsuario FROM Usuario WHERE nombreUsuario IN (" . enLista($usuarios) . ") AND idUsuario > {$ident['Usuario']} AND idPersona IN ($listaPersonas)"));
    if (count($idsUsuario) !== count($usuarios)) {
        abortar('Las cuentas existentes no coinciden con el manifiesto.');
    }
    $listaUsuarios = $idsUsuario === [] ? '0' : implode(',', $idsUsuario);

    // 3) Reservas: exactamente las del manifiesto, posteriores a la instantánea; todas entre personas de prueba; y ninguna otra que use esas personas.
    $idsReserva = $reservas === [] ? [] : array_map('intval', $filas("SELECT idReserva FROM Reserva WHERE codigoReserva IN (" . enLista($reservas) . ") AND idReserva > {$ident['Reserva']}"));
    if (count($idsReserva) !== count($reservas)) {
        abortar('Las reservas existentes no coinciden con el manifiesto.');
    }
    $listaReservas = $idsReserva === [] ? '0' : implode(',', $idsReserva);
    $ajenas = $uno("SELECT COUNT(*) FROM Reserva WHERE (idPersonaRemitente IN ($listaPersonas) OR idPersonaDestinatario IN ($listaPersonas)) AND idReserva NOT IN ($listaReservas)");
    $conPersonasAjenas = $uno("SELECT COUNT(*) FROM Reserva WHERE idReserva IN ($listaReservas) AND (idPersonaRemitente NOT IN ($listaPersonas) OR idPersonaDestinatario NOT IN ($listaPersonas))");
    if ($ajenas !== 0 || $conPersonasAjenas !== 0) {
        abortar("Hay reservas ajenas a esta ejecución ligadas a las personas de prueba ($ajenas) o reservas del manifiesto con personas que no son de prueba ($conPersonasAjenas).");
    }

    // 4) Envíos: exactamente los del manifiesto, de esas reservas y posteriores a la instantánea.
    $existentes = $filas("SELECT codigoSeguimiento FROM Envio WHERE idReserva IN ($listaReservas)");
    sort($existentes);
    $esperados = $envios;
    sort($esperados);
    if ($existentes !== $esperados) {
        abortar('Los envíos existentes de esas reservas no coinciden con los registrados: existentes=' . json_encode($existentes) . ' registrados=' . json_encode($esperados));
    }
    $idsEnvio = $envios === [] ? [] : array_map('intval', $filas("SELECT idEnvio FROM Envio WHERE codigoSeguimiento IN (" . enLista($envios) . ") AND idEnvio > {$ident['Envio']}"));
    if (count($idsEnvio) !== count($envios)) {
        abortar('Hay envíos del manifiesto con id anterior a la prueba.');
    }
    $listaEnvios = $idsEnvio === [] ? '0' : implode(',', $idsEnvio);

    // 5) Viajes: exactamente los del manifiesto, posteriores a la instantánea, conducidos por cuentas de prueba; y ningún otro viaje de esas cuentas.
    $idsViaje = $viajes === [] ? [] : array_map('intval', $filas("SELECT idViaje FROM Viaje WHERE codigoViaje IN (" . enLista($viajes) . ") AND idViaje > {$ident['Viaje']} AND idUsuarioTransportista IN ($listaUsuarios)"));
    if (count($idsViaje) !== count($viajes)) {
        abortar('Los viajes existentes no coinciden con el manifiesto (cantidad, ids anteriores a la prueba o transportista que no es de prueba).');
    }
    $listaViajes = $idsViaje === [] ? '0' : implode(',', $idsViaje);
    $viajesAjenos = $uno("SELECT COUNT(*) FROM Viaje WHERE idUsuarioTransportista IN ($listaUsuarios) AND idViaje NOT IN ($listaViajes)");
    if ($viajesAjenos !== 0) {
        abortar("Hay viajes ajenos a esta ejecución conducidos por las cuentas de prueba ($viajesAjenos).");
    }

    // 6) Cargas (Envio_Viaje): todas las que tocan un viaje o un envío del manifiesto deben ser ENTRE viajes y envíos del manifiesto y posteriores a la instantánea.
    $cargas = $uno("SELECT COUNT(*) FROM Envio_Viaje WHERE idViaje IN ($listaViajes) OR idEnvio IN ($listaEnvios)");
    $cargasPropias = $uno("SELECT COUNT(*) FROM Envio_Viaje WHERE idViaje IN ($listaViajes) AND idEnvio IN ($listaEnvios) AND idEnvioViaje > {$ident['Envio_Viaje']}");
    if ($cargas !== $cargasPropias) {
        abortar("Hay cargas ajenas ligadas a viajes o envíos de prueba (tocan={$cargas}, propias={$cargasPropias}).");
    }

    // 7) Dependencias que no son de esta prueba.
    $pagos = $uno("SELECT COUNT(*) FROM Pago WHERE idEnvio IN ($listaEnvios)");
    if ($pagos !== 0) {
        abortar("Hay dependencias (pagos=$pagos).");
    }

    $bultos = $uno("SELECT COUNT(*) FROM Bulto WHERE idEnvio IN ($listaEnvios)");
    echo 'Se borrará exactamente: ' . json_encode(['Envio_Viaje' => $cargas, 'Viaje' => count($idsViaje), 'Bulto' => $bultos, 'Envio' => count($idsEnvio), 'Reserva' => count($idsReserva), 'Usuario' => count($idsUsuario), 'Persona' => count($idsPersona)]) . "\n";
    echo 'reservas: ' . json_encode($reservas) . ' envios: ' . json_encode($envios) . ' viajes: ' . json_encode($viajes) . "\n";

    // 8) Borrado exacto en una transacción: cada DELETE debe afectar justo lo verificado; si no, se deshace todo.
    $pdo->beginTransaction();
    $hecho = [
        'Envio_Viaje' => [$pdo->exec("DELETE FROM Envio_Viaje WHERE idViaje IN ($listaViajes) AND idEnvio IN ($listaEnvios)"), $cargas],
        'Viaje' => [$pdo->exec("DELETE FROM Viaje WHERE idViaje IN ($listaViajes)"), count($idsViaje)],
        'Bulto' => [$pdo->exec("DELETE FROM Bulto WHERE idEnvio IN ($listaEnvios)"), $bultos],
        'Envio' => [$pdo->exec("DELETE FROM Envio WHERE idEnvio IN ($listaEnvios)"), count($idsEnvio)],
        'Reserva' => [$pdo->exec("DELETE FROM Reserva WHERE idReserva IN ($listaReservas)"), count($idsReserva)],
        'Usuario' => [$pdo->exec("DELETE FROM Usuario WHERE idUsuario IN ($listaUsuarios)"), count($idsUsuario)],
        'Persona' => [$pdo->exec("DELETE FROM Persona WHERE idPersona IN ($listaPersonas)"), count($idsPersona)],
    ];
    foreach ($hecho as $tabla => [$real, $esperado]) {
        if ($real !== $esperado) {
            $pdo->rollBack();
            abortar("DELETE de $tabla afectó $real filas y se esperaban $esperado: se deshizo todo.");
        }
    }
    $pdo->commit();
    echo 'borradas: ' . json_encode(array_map(static fn ($p) => $p[0], $hecho)) . "\n";
    exit(0);
}

abortar('Operación no permitida.', 2);
