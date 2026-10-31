<?php
declare(strict_types=1);

/*
 * ESCRITURA ACOTADA A LO CREADO POR ESTA EJECUCIÓN pm5 (solo con autorización expresa; ver validar-pm5.ps1).
 *
 * El orquestador lleva un MANIFIESTO (JSON) con lo que él mismo creó en esta ejecución:
 *   identAntes {Persona, Usuario, Reserva, Envio}  IDENT_CURRENT de la instantánea inicial: lo creado después tiene id mayor
 *   documentos  [...]  documentos de las personas que creó
 *   usuarios    [...]  nombres de las cuentas que creó
 *   reservas    [...]  códigos de las reservas que creó
 *   envios      [...]  códigos de seguimiento de los envíos que creó
 *
 *   crear-personal <manifiesto>             INSERT de pm5_admin (ADMINISTRADOR de MOQ001); aborta si ya existe algo. Clave en env JATSA_PM5_PW.
 *   fecha <manifiesto> <codigo> "<fecha>"   UPDATE de fechaHoraReserva de UNA reserva del manifiesto (PENDIENTE y con id posterior a la instantánea).
 *   limpiar <manifiesto>                    DELETE exacto de lo registrado. Verifica ANTES que lo que existe coincide con el manifiesto
 *                                           (cantidades, ids posteriores a la instantánea, sin filas ajenas ni dependencias) y, al borrar,
 *                                           que cada DELETE afecte exactamente lo esperado; si algo diverge, no borra nada y pide revisión.
 * Cualquier otra operación se rechaza. No imprime contraseñas.
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
foreach (['Persona', 'Usuario', 'Reserva', 'Envio'] as $t) {
    if (!isset($ident[$t])) {
        abortar("El manifiesto no trae IDENT_CURRENT de $t.", 2);
    }
}
$documentos = textos($manifiesto['documentos'] ?? [], '/^9000005[1-4]$/');
$usuarios = textos($manifiesto['usuarios'] ?? [], '/^pm5_(cliente|admin)$/');
$reservas = textos($manifiesto['reservas'] ?? [], '/^R\d{9}$/');
$envios = textos($manifiesto['envios'] ?? [], '/^EN[A-Z0-9]{10}$/');
$pdo = Database::conexion();

if ($op === 'crear-personal') {
    $clave = (string) getenv('JATSA_PM5_PW');
    if (strlen($clave) < 12) {
        abortar('Falta JATSA_PM5_PW.', 2);
    }
    $existe = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM Persona WHERE numeroDocumento = '90000052') + (SELECT COUNT(*) FROM Usuario WHERE nombreUsuario = 'pm5_admin')")->fetchColumn();
    if ($existe !== 0) {
        abortar('Ya existe una persona o cuenta pm5_admin: no se crea nada.', 3);
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
    $codigo = (string) ($argv[3] ?? '');
    $fecha = (string) ($argv[4] ?? '');
    if (!in_array($codigo, $reservas, true) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha)) {
        abortar('La reserva no está en el manifiesto o la fecha es inválida.', 2);
    }
    $s = $pdo->prepare(
        "UPDATE Reserva SET fechaHoraReserva = :f
         WHERE codigoReserva = :c AND idReserva > :ident AND estadoReserva = 'PENDIENTE'",
    );
    $s->bindValue(':f', $fecha);
    $s->bindValue(':c', $codigo);
    $s->bindValue(':ident', $ident['Reserva'], PDO::PARAM_INT);
    $s->execute();
    echo "$codigo -> $fecha; filas afectadas: " . $s->rowCount() . "\n";
    exit($s->rowCount() === 1 ? 0 : 3);
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

    // 2) Reservas: exactamente las del manifiesto, posteriores a la instantánea; y ninguna otra que use esas personas.
    $idsReserva = $reservas === [] ? [] : array_map('intval', $filas("SELECT idReserva FROM Reserva WHERE codigoReserva IN (" . enLista($reservas) . ") AND idReserva > {$ident['Reserva']}"));
    if (count($idsReserva) !== count($reservas)) {
        abortar('Las reservas existentes no coinciden con el manifiesto.');
    }
    $listaReservas = $idsReserva === [] ? '0' : implode(',', $idsReserva);
    $ajenas = $uno("SELECT COUNT(*) FROM Reserva WHERE (idPersonaRemitente IN ($listaPersonas) OR idPersonaDestinatario IN ($listaPersonas)) AND idReserva NOT IN ($listaReservas)");
    $conPersonasAjenas = $uno("SELECT COUNT(*) FROM Reserva WHERE idReserva IN ($listaReservas) AND idPersonaRemitente NOT IN ($listaPersonas)");
    if ($ajenas !== 0 || $conPersonasAjenas !== 0) {
        abortar("Hay reservas ajenas a esta ejecución ligadas a las personas de prueba ($ajenas) o reservas del manifiesto de otro remitente ($conPersonasAjenas).");
    }

    // 3) Envíos: exactamente los del manifiesto, de esas reservas y posteriores a la instantánea.
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

    // 4) Dependencias que no son de esta prueba.
    $pagos = $uno("SELECT COUNT(*) FROM Pago WHERE idEnvio IN ($listaEnvios)");
    $viajes = $uno("SELECT COUNT(*) FROM Envio_Viaje WHERE idEnvio IN ($listaEnvios)");
    if ($pagos !== 0 || $viajes !== 0) {
        abortar("Hay dependencias (pagos=$pagos, viajes=$viajes).");
    }

    // 5) Cuentas: las del manifiesto, ligadas a esas personas y posteriores a la instantánea.
    $idsUsuario = $usuarios === [] ? [] : array_map('intval', $filas("SELECT idUsuario FROM Usuario WHERE nombreUsuario IN (" . enLista($usuarios) . ") AND idUsuario > {$ident['Usuario']} AND idPersona IN ($listaPersonas)"));
    if (count($idsUsuario) !== count($usuarios)) {
        abortar('Las cuentas existentes no coinciden con el manifiesto.');
    }
    $listaUsuarios = $idsUsuario === [] ? '0' : implode(',', $idsUsuario);

    $bultos = $uno("SELECT COUNT(*) FROM Bulto WHERE idEnvio IN ($listaEnvios)");
    echo 'Se borrará exactamente: ' . json_encode(['Bulto' => $bultos, 'Envio' => count($idsEnvio), 'Reserva' => count($idsReserva), 'Usuario' => count($idsUsuario), 'Persona' => count($idsPersona)]) . "\n";
    echo 'reservas: ' . json_encode($reservas) . ' envios: ' . json_encode($envios) . "\n";

    // 6) Borrado exacto en una transacción: cada DELETE debe afectar justo lo verificado; si no, se deshace todo.
    $pdo->beginTransaction();
    $hecho = [
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
