<?php
declare(strict_types=1);

/*
 * ESCRITURA ACOTADA A LO CREADO POR ESTA EJECUCIÓN pm10 (solo con autorización expresa; ver validar-pm10.ps1). NO SE HA EJECUTADO.
 *
 * El orquestador lleva un MANIFIESTO (JSON) con lo que él mismo creó en esta ejecución. Es lo único que este script puede borrar:
 *   idsAntes {Sucursal, Telefono_Sucursal, Ruta, Vehiculo, Persona, Usuario, Viaje, Envio_Viaje}   TODOS los ids de cada tabla en la instantánea
 *                                       inicial (listas, posiblemente vacías). Una fila es de la prueba solo si su clave está en el manifiesto Y su id
 *                                       NO estaba en esa lista. (No se usa «id > IDENT_CURRENT»: con una tabla inicialmente vacía el contador vale la
 *                                       semilla y la primera fila creada puede recibir ese mismo id; ver pm10_reglas.php.)
 *   identAntes {…}                      IDENT_CURRENT inicial: solo informativo (el orquestador informa el avance irreversible).
 *   sucursales [...]  códigos PMX9nn          vehiculos [...]  placas PM1-9nn
 *   documentos [...]  90000101-90000108 y 20000000107       usuarios [...]  pm10_*       viajes [...]  códigos VJ
 *
 *   preparar <manifiesto>      INSERT de 6 personas temporales (90000101-90000105 y 90000108) y de las cuentas de GERENTE pm10_gerente y pm10_gerente2
 *                              (contraseñas efímeras en JATSA_PM10_PW_G1 y _G2; nunca se imprimen). Aborta sin escribir si ya existe algo pm10.
 *   reactivar-semilla <manifiesto>
 *                              UPDATE de UNA fila de la SEMILLA sintética: reactiva a «seed_gerente» (la carrera del «último gerente» lo desactiva a propósito y
 *                              debe quedar restaurado para que los datos originales queden idénticos). Idempotente.
 *   ruta-antigua <manifiesto> <origen> <destino> <tipo>
 *                              INSERT de UNA tarifa temporal con vigenteDesde = anteayer entre dos sucursales temporales del manifiesto, para poder
 *                              probar «Nuevo precio» (que se rechaza el mismo día). Imprime su idRuta.
 *   limpiar <manifiesto>       DELETE exacto de lo registrado, en una transacción y respetando las claves foráneas:
 *                              Viaje → Telefono_Sucursal → Ruta → Usuario → Persona → Vehiculo → Sucursal.
 *                              ANTES de borrar clasifica cada tabla (propias / originales / ajenas) con el conjunto real de ids de la instantánea
 *                              inicial: si una clave del manifiesto coincide con una fila ORIGINAL, o existe cualquier fila nueva que no sea del
 *                              manifiesto (otro proceso, o algo sin anotar), no borra nada (código 4). Verifica además que ninguna fila ajena
 *                              depende de lo temporal y que cada DELETE afecta exactamente lo contado.
 * TODAS las operaciones exigen que el manifiesto traiga baseAislada y que ESTA conexión (la de la copia de trabajo) sea esa base, con el login
 * aislado: si DB_NAME() es otra, o contiene «Encomiendas», se detiene (código 5) sin escribir nada. pm10 ya no se ejecuta contra la base habitual.
 * Cualquier otra operación se rechaza. Sin DDL, sin DBCC CHECKIDENT: los contadores IDENTITY NO se restauran (se informan en el orquestador).
 */
$raiz = dirname(__DIR__, 2);
require $raiz . '/src/autoload.php';
require __DIR__ . '/pm10_reglas.php';

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
if (!is_array($manifiesto) || !isset($manifiesto['idsAntes']) || !is_array($manifiesto['idsAntes'])) {
    abortar('Falta el manifiesto (o es inválido).', 2);
}
$idsAntes = [];
foreach (['Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje', 'Envio_Viaje'] as $t) {
    $lista = pm10_idsValidos($manifiesto['idsAntes'][$t] ?? null);
    if ($lista === null) {
        abortar("El manifiesto no trae la lista de ids iniciales de $t (puede estar vacía, pero debe existir).", 2);
    }
    $idsAntes[$t] = $lista;
}
$sucursales = textos($manifiesto['sucursales'] ?? [], '/^PMX9\d{2}$/', 'sucursales');
$vehiculos = textos($manifiesto['vehiculos'] ?? [], '/^PM1-9\d{2}$/', 'vehículos');
$documentos = textos($manifiesto['documentos'] ?? [], '/^(9000010\d|2000000010\d)$/', 'documentos');
$usuarios = textos($manifiesto['usuarios'] ?? [], '/^pm10_[a-z0-9]{3,20}$/', 'usuarios');
$viajes = textos($manifiesto['viajes'] ?? [], '/^VJ\d{10}$/', 'viajes');
$baseAislada = (string) ($manifiesto['baseAislada'] ?? '');
if (preg_match('/^JATSA_pm10_aislada_\d{8}_\d{4}$/', $baseAislada) !== 1 || stripos($baseAislada, 'encomiendas') !== false) {
    abortar('El manifiesto no trae una base aislada válida (baseAislada).', 2);
}
$pdo = Database::conexion();
$destino = $pdo->query('SELECT DB_NAME() AS bd, SUSER_SNAME() AS login')->fetch(PDO::FETCH_ASSOC);
if ($destino['bd'] !== $baseAislada || $destino['login'] !== 'jatsa_pm10_aislado' || stripos((string) $destino['bd'], 'encomiendas') !== false) {
    abortar('Esta conexión NO es la base aislada del manifiesto con el login aislado (' . $destino['bd'] . ' / ' . $destino['login'] . '). No se escribe nada.', 5);
}
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

if ($op === 'reactivar-semilla') {
    $afectadas = $pdo->exec("UPDATE Usuario SET activo = 1 WHERE nombreUsuario = 'seed_gerente' AND rol = 'GERENTE' AND activo = 0");
    echo (int) $afectadas === 0 ? "seed_gerente ya estaba activo
" : "seed_gerente reactivado
";
    exit(0);
}

if ($op === 'ruta-antigua') {
    $origen = (string) ($argv[3] ?? '');
    $destino = (string) ($argv[4] ?? '');
    $tipo = (string) ($argv[5] ?? '');
    if (!in_array($origen, $sucursales, true) || !in_array($destino, $sucursales, true) || $origen === $destino || !in_array($tipo, ['SOBRE', 'PAQUETE', 'CARGA'], true)) {
        abortar('La tarifa antigua solo se crea entre dos sucursales temporales del manifiesto y un tipo válido.', 2);
    }
    $ids = $columna("SELECT idSucursal FROM Sucursal WHERE codigoSucursal IN (" . enLista([$origen, $destino]) . ')');
    $clasificadas = pm10_clasificarFilas($idsAntes['Sucursal'], $columna('SELECT idSucursal FROM Sucursal'), $ids);
    if (count($clasificadas['propias']) !== 2 || $clasificadas['originales'] !== []) {
        abortar('Las sucursales temporales no existen, o alguna ya existía antes de la prueba.', 3);
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
    // 1) Clasificación de cada tabla con el CONJUNTO REAL de ids de la instantánea inicial. Puede haber menos filas de las anotadas (el
    //    manifiesto se anota antes de crear), nunca una fila ORIGINAL que coincida con una clave del manifiesto ni filas nuevas AJENAS.
    $c = [];
    $c['Sucursal'] = pm10_clasificarFilas($idsAntes['Sucursal'], $columna('SELECT idSucursal FROM Sucursal'), $columna('SELECT idSucursal FROM Sucursal WHERE codigoSucursal IN (' . enLista($sucursales) . ')'));
    $idsSucursal = $c['Sucursal']['propias'];
    $lSuc = enEnteros($idsSucursal);
    $c['Persona'] = pm10_clasificarFilas($idsAntes['Persona'], $columna('SELECT idPersona FROM Persona'), $columna('SELECT idPersona FROM Persona WHERE numeroDocumento IN (' . enLista($documentos) . ')'));
    $idsPersona = $c['Persona']['propias'];
    $c['Usuario'] = pm10_clasificarFilas($idsAntes['Usuario'], $columna('SELECT idUsuario FROM Usuario'), $columna('SELECT idUsuario FROM Usuario WHERE nombreUsuario IN (' . enLista($usuarios) . ')'));
    $idsUsuario = $c['Usuario']['propias'];
    $c['Vehiculo'] = pm10_clasificarFilas($idsAntes['Vehiculo'], $columna('SELECT idVehiculo FROM Vehiculo'), $columna('SELECT idVehiculo FROM Vehiculo WHERE placa IN (' . enLista($vehiculos) . ')'));
    $idsVehiculo = $c['Vehiculo']['propias'];
    $c['Viaje'] = pm10_clasificarFilas($idsAntes['Viaje'], $columna('SELECT idViaje FROM Viaje'), $columna('SELECT idViaje FROM Viaje WHERE codigoViaje IN (' . enLista($viajes) . ')'));
    $idsViaje = $c['Viaje']['propias'];
    $c['Ruta'] = pm10_clasificarFilas($idsAntes['Ruta'], $columna('SELECT idRuta FROM Ruta'), $columna("SELECT idRuta FROM Ruta WHERE idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)"));
    $c['Telefono_Sucursal'] = pm10_clasificarFilas($idsAntes['Telefono_Sucursal'], $columna('SELECT idTelefono FROM Telefono_Sucursal'), $columna("SELECT idTelefono FROM Telefono_Sucursal WHERE idSucursal IN ($lSuc)"));
    $c['Envio_Viaje'] = pm10_clasificarFilas($idsAntes['Envio_Viaje'], $columna('SELECT idEnvioViaje FROM Envio_Viaje'), []); // la prueba no carga envíos: no debe haber ninguna nueva
    foreach ($c as $tabla => $clasificacion) {
        if ($clasificacion['originales'] !== []) {
            abortar("$tabla: una clave del manifiesto coincide con una fila que YA existía antes de la prueba (dato original). No se borra nada.");
        }
        if ($clasificacion['ajenas'] !== []) {
            abortar("$tabla: hay filas nuevas que no son del manifiesto (ids " . implode(',', array_slice($clasificacion['ajenas'], 0, 10)) . '): otro proceso escribió durante la prueba o quedó algo sin anotar. No se borra nada.');
        }
    }

    // 2) Ninguna fila ajena puede depender de lo temporal; las tarifas deben unir SOLO sucursales temporales.
    $lVeh = enEnteros($idsVehiculo);
    $lUsu = enEnteros($idsUsuario);
    $lVia = enEnteros($idsViaje);
    $lPer = enEnteros($idsPersona);
    $ajenos = [
        'viajes que usan un vehículo, conductor o sucursal temporal y no están en el manifiesto' => "SELECT COUNT(*) FROM Viaje WHERE (idVehiculo IN ($lVeh) OR idUsuarioTransportista IN ($lUsu) OR idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)) AND idViaje NOT IN ($lVia)",
        'cuentas en una sucursal temporal que no son del manifiesto' => "SELECT COUNT(*) FROM Usuario WHERE idSucursal IN ($lSuc) AND idUsuario NOT IN ($lUsu)",
        'cuentas del manifiesto ligadas a personas que no son del manifiesto' => "SELECT COUNT(*) FROM Usuario WHERE idUsuario IN ($lUsu) AND idPersona NOT IN ($lPer)",
        'cargas de envíos en los viajes temporales' => "SELECT COUNT(*) FROM Envio_Viaje WHERE idViaje IN ($lVia)",
        'reservas en una sucursal temporal o de personas temporales' => "SELECT COUNT(*) FROM Reserva WHERE idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc) OR idPersonaRemitente IN ($lPer) OR idPersonaDestinatario IN ($lPer)",
        'bultos con una tarifa de una sucursal temporal' => "SELECT COUNT(*) FROM Bulto WHERE idRuta IN (SELECT idRuta FROM Ruta WHERE idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc))",
        'tarifas que unen una sucursal temporal con una original' => "SELECT COUNT(*) FROM Ruta WHERE (idSucursalOrigen IN ($lSuc) OR idSucursalDestino IN ($lSuc)) AND (idSucursalOrigen NOT IN ($lSuc) OR idSucursalDestino NOT IN ($lSuc))",
    ];
    foreach ($ajenos as $descripcion => $sql) {
        if ($uno($sql) !== 0) {
            abortar("Hay filas que dependen de lo temporal y no son del manifiesto: $descripcion. No se borra nada.");
        }
    }

    // 3) Conteo previo de lo que se borrará (las filas PROPIAS ya clasificadas) y borrado en UNA transacción verificando cada DELETE.
    $plan = [
        ['Viaje', 'DELETE FROM Viaje WHERE idViaje IN (' . $lVia . ')', count($idsViaje)],
        ['Telefono_Sucursal', 'DELETE FROM Telefono_Sucursal WHERE idTelefono IN (' . enEnteros($c['Telefono_Sucursal']['propias']) . ')', count($c['Telefono_Sucursal']['propias'])],
        ['Ruta', 'DELETE FROM Ruta WHERE idRuta IN (' . enEnteros($c['Ruta']['propias']) . ')', count($c['Ruta']['propias'])],
        ['Usuario', "DELETE FROM Usuario WHERE idUsuario IN ($lUsu)", count($idsUsuario)],
        ['Persona', 'DELETE FROM Persona WHERE idPersona IN (' . $lPer . ')', count($idsPersona)],
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

abortar('Operación no permitida: solo preparar, reactivar-semilla, ruta-antigua y limpiar.', 2);
