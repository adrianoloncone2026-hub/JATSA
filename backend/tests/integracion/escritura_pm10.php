<?php
declare(strict_types=1);

/*
 * ESCRITURA ACOTADA A LO CREADO POR ESTA EJECUCIÓN pm10 (solo con autorización expresa; ver validar-pm10.ps1). NO SE HA EJECUTADO.
 *
 * El orquestador lleva un MANIFIESTO (JSON) con lo que él mismo creó en esta ejecución. Es lo único que este script puede borrar:
 *   identAntes {Sucursal, Telefono_Sucursal, Ruta, Vehiculo, Persona, Usuario, Viaje, Envio_Viaje}   IDENT_CURRENT de la instantánea inicial:
 *                                       toda fila creada por la prueba tiene un id MAYOR; una fila con id menor o igual es ORIGINAL y nunca se toca
 *   sucursales [...]  códigos PMX9nn          vehiculos [...]  placas PM1-9nn
 *   documentos [...]  90000101-90000108 y 20000000107       usuarios [...]  pm10_*       viajes [...]  códigos VJ
 *
 *   preparar <manifiesto>      INSERT de 6 personas temporales (90000101-90000105 y 90000108) y de las cuentas de GERENTE pm10_gerente y pm10_gerente2
 *                              (contraseñas efímeras en JATSA_PM10_PW_G1 y _G2; nunca se imprimen). Aborta sin escribir si ya existe algo pm10.
 *   ruta-antigua <manifiesto> <origen> <destino> <tipo>
 *                              INSERT de UNA tarifa temporal con vigenteDesde = anteayer entre dos sucursales temporales del manifiesto, para poder
 *                              probar «Nuevo precio» (que se rechaza el mismo día). Imprime su idRuta.
 *   limpiar <manifiesto>       DELETE exacto de lo registrado, en una transacción y respetando las claves foráneas:
 *                              Viaje → Telefono_Sucursal → Ruta → Usuario → Persona → Vehiculo → Sucursal.
 *                              ANTES de borrar verifica que todo lo que existe con esas claves es posterior a la instantánea (id > identAntes), que
 *                              ninguna fila ORIGINAL ni ajena depende de lo temporal (viajes, cuentas, bultos, reservas, cargas) y que cada DELETE
 *                              afectará exactamente lo contado; si algo diverge, no borra nada (código 4) y pide revisión.
 * Cualquier otra operación se rechaza. Sin DDL, sin DBCC CHECKIDENT: los contadores IDENTITY NO se restauran (se informan en el orquestador).
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
function textos(mixed $valores, string $patron, string $que): array
{
    $lista = is_array($valores) ? array_values($valores) : [];
    foreach ($lista as $v) {
        if (!is_string($v) || preg_match($patron, $v) !== 1) {
            abortar("El manifiesto contiene un valor de $que con formato inesperado (¿un dato original?).", 2);
        }
    }

    return array_values(array_unique($lista));
}

/** Lista SQL de valores ya validados con una expresión estricta (nunca entrada libre). */
function enLista(array $valores): string
{
    return $valores === [] ? "'~'" : "'" . implode("','", $valores) . "'";
}

function enEnteros(array $ids): string
{
    return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
}

Config::cargar($raiz);
$op = (string) ($argv[1] ?? '');
$ruta = (string) ($argv[2] ?? '');
$manifiesto = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
if (!is_array($manifiesto) || !isset($manifiesto['identAntes']) || !is_array($manifiesto['identAntes'])) {
    abortar('Falta el manifiesto (o es inválido).', 2);
}
$ident = array_map('intval', $manifiesto['identAntes']);
foreach (['Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje', 'Envio_Viaje'] as $t) {
    if (!isset($ident[$t])) {
        abortar("El manifiesto no trae IDENT_CURRENT de $t.", 2);
    }
}
$sucursales = textos($manifiesto['sucursales'] ?? [], '/^PMX9\d{2}$/', 'sucursales');
$vehiculos = textos($manifiesto['vehiculos'] ?? [], '/^PM1-9\d{2}$/', 'vehículos');
$documentos = textos($manifiesto['documentos'] ?? [], '/^(9000010\d|2000000010\d)$/', 'documentos');
$usuarios = textos($manifiesto['usuarios'] ?? [], '/^pm10_[a-z0-9]{3,20}$/', 'usuarios');
$viajes = textos($manifiesto['viajes'] ?? [], '/^VJ\d{10}$/', 'viajes');
$pdo = Database::conexion();
$uno = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$columna = static fn (string $sql): array => array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));

if ($op === 'preparar') {
    $claves = ['pm10_gerente' => (string) getenv('JATSA_PM10_PW_G1'), 'pm10_gerente2' => (string) getenv('JATSA_PM10_PW_G2')];
    foreach ($claves as $clave) {
        if (strlen($clave) < 12) {
            abortar('Faltan las contraseñas efímeras (JATSA_PM10_PW_G1 y G2).', 2);
        }
    }
    $docs = ['90000101', '90000102', '90000103', '90000104', '90000105', '90000108'];
    $existe = $uno("SELECT (SELECT COUNT(*) FROM Persona WHERE numeroDocumento IN (" . enLista($docs) . ")) + (SELECT COUNT(*) FROM Usuario WHERE nombreUsuario LIKE 'pm10[_]%') + (SELECT COUNT(*) FROM Sucursal WHERE codigoSucursal LIKE 'PMX9%') + (SELECT COUNT(*) FROM Vehiculo WHERE placa LIKE 'PM1-9%')");
    if ($existe !== 0) {
        abortar('Ya existe algo pm10 (personas, cuentas, sucursales o vehículos): no se crea nada.', 3);
    }
    $pdo->beginTransaction();
    $alta = $pdo->prepare("INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono) VALUES ('DNI', :documento, :nombre, :telefono)");
    foreach ($docs as $i => $documento) {
        $alta->bindValue(':documento', $documento);
        $alta->bindValue(':nombre', 'Prueba Diez Persona ' . ($i + 1));
        $alta->bindValue(':telefono', '9000000' . substr($documento, -2));
        $alta->execute();
    }
    $u = $pdo->prepare(
        "INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol)
         VALUES ((SELECT idPersona FROM Persona WHERE tipoDocumento = 'DNI' AND numeroDocumento = :documento), NULL, :usuario, :h, 'GERENTE')",
    );
    foreach ([['90000101', 'pm10_gerente'], ['90000102', 'pm10_gerente2']] as [$documento, $usuario]) {
        $u->bindValue(':documento', $documento);
        $u->bindValue(':usuario', $usuario);
        $u->bindValue(':h', password_hash($claves[$usuario], PASSWORD_BCRYPT, ['cost' => 12]));
        $u->execute();
    }
    $pdo->commit();
    echo "6 personas temporales y 2 cuentas de gerente (pm10_gerente, pm10_gerente2) creadas\n";
    exit(0);
}

if ($op === 'ruta-antigua') {
    $origen = (string) ($argv[3] ?? '');
    $destino = (string) ($argv[4] ?? '');
    $tipo = (string) ($argv[5] ?? '');
    if (!in_array($origen, $sucursales, true) || !in_array($destino, $sucursales, true) || $origen === $destino || !in_array($tipo, ['SOBRE', 'PAQUETE', 'CARGA'], true)) {
        abortar('La tarifa antigua solo se crea entre dos sucursales temporales del manifiesto y un tipo válido.', 2);
    }
    $ids = $columna("SELECT idSucursal FROM Sucursal WHERE codigoSucursal IN (" . enLista([$origen, $destino]) . ") AND idSucursal > {$ident['Sucursal']}");
    if (count($ids) !== 2) {
        abortar('Las sucursales temporales no existen o no son posteriores a la instantánea.', 3);
    }
    $alta = $pdo->prepare(
        'INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta)
         VALUES ((SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :o), (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :d), :t, 8.00, 0.005, 100.0, 2, DATEADD(DAY, -2, CAST(GETDATE() AS DATE)), NULL)',
    );
    $alta->bindValue(':o', $origen);
    $alta->bindValue(':d', $destino);
    $alta->bindValue(':t', $tipo);
    $alta->execute();
    echo (int) $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS INT)')->fetchColumn(), "\n";
    exit(0);
}

if ($op === 'limpiar') {
    // 1) Todo lo que existe con las claves del manifiesto debe ser POSTERIOR a la instantánea (id > identAntes). Puede haber menos de
    //    lo anotado (el manifiesto se anota antes de crear), nunca una fila original ni ajena.
    $idsSucursal = $columna("SELECT idSucursal FROM Sucursal WHERE codigoSucursal IN (" . enLista($sucursales) . ')');
    if ($uno('SELECT COUNT(*) FROM Sucursal WHERE idSucursal IN (' . enEnteros($idsSucursal) . ") AND idSucursal <= {$ident['Sucursal']}") !== 0) {
        abortar('Una sucursal del manifiesto es anterior a la prueba (¿dato original?).');
    }
    $idsPersona = $columna("SELECT idPersona FROM Persona WHERE numeroDocumento IN (" . enLista($documentos) . ')');
    if ($uno('SELECT COUNT(*) FROM Persona WHERE idPersona IN (' . enEnteros($idsPersona) . ") AND idPersona <= {$ident['Persona']}") !== 0) {
        abortar('Una persona del manifiesto es anterior a la prueba (¿dato original?).');
    }
    $idsUsuario = $columna("SELECT idUsuario FROM Usuario WHERE nombreUsuario IN (" . enLista($usuarios) . ')');
    if ($uno('SELECT COUNT(*) FROM Usuario WHERE idUsuario IN (' . enEnteros($idsUsuario) . ") AND (idUsuario <= {$ident['Usuario']} OR idPersona NOT IN (" . enEnteros($idsPersona) . '))') !== 0) {
        abortar('Una cuenta del manifiesto es anterior a la prueba o no pertenece a una persona del manifiesto.');
    }
    $idsVehiculo = $columna("SELECT idVehiculo FROM Vehiculo WHERE placa IN (" . enLista($vehiculos) . ')');
    if ($uno('SELECT COUNT(*) FROM Vehiculo WHERE idVehiculo IN (' . enEnteros($idsVehiculo) . ") AND idVehiculo <= {$ident['Vehiculo']}") !== 0) {
        abortar('Un vehículo del manifiesto es anterior a la prueba (¿dato original?).');
    }
    $idsViaje = $columna("SELECT idViaje FROM Viaje WHERE codigoViaje IN (" . enLista($viajes) . ')');
    if ($uno('SELECT COUNT(*) FROM Viaje WHERE idViaje IN (' . enEnteros($idsViaje) . ") AND idViaje <= {$ident['Viaje']}") !== 0) {
        abortar('Un viaje del manifiesto es anterior a la prueba (¿dato original?).');
    }

    // 2) Ninguna fila ajena puede depender de lo temporal: viajes con un vehículo, conductor o sucursal temporal que NO estén en el manifiesto,
    //    cuentas de personal en una sucursal temporal que no sean del manifiesto, cargas, reservas o bultos.
    $lSuc = enEnteros($idsSucursal);
    $lVeh = enEnteros($idsVehiculo);
    $lUsu = enEnteros($idsUsuario);
    $lVia = enEnteros($idsViaje);
    $ajenos = [
        'viajes que usan un vehículo, conductor o sucursal temporal y no están en el manifiesto' => "SELECT COUNT(*) FROM Viaje WHERE (idVehiculo IN ($lVeh) OR idUsuarioTransportista IN ($lUsu) OR idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)) AND idViaje NOT IN ($lVia)",
        'cuentas en una sucursal temporal que no son del manifiesto' => "SELECT COUNT(*) FROM Usuario WHERE idSucursal IN ($lSuc) AND idUsuario NOT IN ($lUsu)",
        'cargas de envíos en los viajes temporales' => "SELECT COUNT(*) FROM Envio_Viaje WHERE idViaje IN ($lVia)",
        'reservas en una sucursal temporal' => "SELECT COUNT(*) FROM Reserva WHERE idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)",
        'bultos con una tarifa de una sucursal temporal' => "SELECT COUNT(*) FROM Bulto WHERE idRuta IN (SELECT idRuta FROM Ruta WHERE idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc))",
        'tarifas que unen una sucursal temporal con una original o anteriores a la prueba' => "SELECT COUNT(*) FROM Ruta WHERE (idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)) AND (idRuta <= {$ident['Ruta']} OR idSucursalOrigen NOT IN ($lSuc) OR idSucursalDestino NOT IN ($lSuc))",
        'teléfonos de una sucursal temporal anteriores a la prueba' => "SELECT COUNT(*) FROM Telefono_Sucursal WHERE idSucursal IN ($lSuc) AND idTelefono <= {$ident['Telefono_Sucursal']}",
        'personas del manifiesto con reservas' => 'SELECT COUNT(*) FROM Reserva WHERE idPersonaRemitente IN (' . enEnteros($idsPersona) . ') OR idPersonaDestinatario IN (' . enEnteros($idsPersona) . ')',
    ];
    foreach ($ajenos as $descripcion => $sql) {
        if ($uno($sql) !== 0) {
            abortar("Hay filas que dependen de lo temporal y no son del manifiesto: $descripcion. No se borra nada.");
        }
    }

    // 3) Conteo previo de lo que se borrará, y borrado en UNA transacción verificando que cada DELETE afecta exactamente eso.
    $plan = [
        ['Viaje', 'DELETE FROM Viaje WHERE idViaje IN (' . $lVia . ')', count($idsViaje)],
        ['Telefono_Sucursal', "DELETE FROM Telefono_Sucursal WHERE idSucursal IN ($lSuc)", $uno("SELECT COUNT(*) FROM Telefono_Sucursal WHERE idSucursal IN ($lSuc)")],
        ['Ruta', "DELETE FROM Ruta WHERE idSucursalOrigen IN ($lSuc) AND idSucursalDestino IN ($lSuc)", $uno("SELECT COUNT(*) FROM Ruta WHERE idSucursalOrigen IN ($lSuc) AND idSucursalDestino IN ($lSuc)")],
        ['Usuario', "DELETE FROM Usuario WHERE idUsuario IN ($lUsu)", count($idsUsuario)],
        ['Persona', 'DELETE FROM Persona WHERE idPersona IN (' . enEnteros($idsPersona) . ')', count($idsPersona)],
        ['Vehiculo', "DELETE FROM Vehiculo WHERE idVehiculo IN ($lVeh)", count($idsVehiculo)],
        ['Sucursal', "DELETE FROM Sucursal WHERE idSucursal IN ($lSuc)", count($idsSucursal)],
    ];
    $pdo->beginTransaction();
    try {
        foreach ($plan as [$tabla, $sql, $esperadas]) {
            $afectadas = $pdo->exec($sql);
            if ($afectadas !== $esperadas) {
                throw new RuntimeException("$tabla: el DELETE afectó $afectadas fila(s) y se esperaban $esperadas");
            }
            echo sprintf("%-18s %d fila(s) borrada(s)\n", $tabla, $afectadas);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        abortar('La limpieza se deshizo: ' . $e->getMessage());
    }
    exit(0);
}

abortar('Operación no permitida: solo preparar, ruta-antigua y limpiar.', 2);
