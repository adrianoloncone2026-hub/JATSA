<?php
declare(strict_types=1);

/*
 * Generador de la carga de demostración (lo usa backend/bin/cargar-datos-demo.php). Solo construye datos en memoria; no accede a la base.
 *
 * Simulación de una empresa que opera desde noviembre de 2025 hasta «ahora»: red de seis agencias, tarifas con un cambio de precios el 2026-09-01, reservas
 * con su ciclo de vida (72 h de vigencia), envíos con bultos cobrados por la tarifa vigente, viajes que consolidan carga por tramo (con transbordos en la
 * red de dos saltos), llegadas, entregas, devoluciones y pagos (completos, parciales, contra entrega, anulados). Cada hecho respeta el orden cronológico.
 */

use Jatsa\Shared\Tarifas;

const NODOS = ['LIM001', 'AQP001', 'MOQ001', 'ILO001', 'CUS001', 'JUL001'];
const NOMBRES_M = ['Juan', 'Carlos', 'Luis', 'José', 'Miguel', 'Pedro', 'Jorge', 'Raúl', 'Víctor', 'Hugo', 'Diego', 'Andrés', 'Mario', 'Óscar', 'Fernando', 'Ricardo', 'Eduardo', 'Walter', 'César', 'Alberto', 'Edwin', 'Percy', 'Marco', 'Iván'];
const NOMBRES_F = ['María', 'Rosa', 'Carmen', 'Ana', 'Lucía', 'Elena', 'Sofía', 'Patricia', 'Gloria', 'Teresa', 'Julia', 'Silvia', 'Karla', 'Diana', 'Paola', 'Marta', 'Nelly', 'Yesenia', 'Milagros', 'Flor', 'Rocío', 'Sandra', 'Liliana', 'Verónica'];
const APELLIDOS = ['Quispe', 'Mamani', 'Huanca', 'Flores', 'Condori', 'Ccama', 'Choque', 'Apaza', 'Ramos', 'Torres', 'Vargas', 'Rojas', 'Salazar', 'Cáceres', 'Paredes', 'Gutiérrez', 'Mendoza', 'Chávez', 'Ticona', 'Pacheco',
    'Villanueva', 'Huamán', 'Cruz', 'Zegarra', 'Valdivia', 'Medina', 'Ortega', 'Soto', 'Cornejo', 'Bellido', 'Ccori', 'Nina', 'Layme', 'Churata', 'Yucra', 'Cutipa', 'Sucso', 'Larico', 'Pari', 'Coaquira'];
const EMPRESAS_A = ['Comercial', 'Distribuidora', 'Importaciones', 'Ferretería', 'Agroindustrial', 'Textiles', 'Inversiones', 'Corporación', 'Servicios', 'Repuestos', 'Minera', 'Librería'];
const EMPRESAS_B = ['Andina', 'del Pacífico', 'Altiplano', 'Los Andes', 'Misti', 'Cusco Imperial', 'Titicaca', 'Sol de Oro', 'Santa Rosa', 'El Roble', 'Nuevo Horizonte', 'San Cristóbal', 'Valle Verde', 'Costa Azul'];
const CALLES = ['Av. Los Olivos', 'Jr. Junín', 'Av. Ejército', 'Calle Mercaderes', 'Av. La Marina', 'Jr. Bolognesi', 'Av. Grau', 'Calle Real', 'Av. Independencia', 'Jr. Cusco', 'Av. Dolores', 'Calle San Francisco'];
const DESC_SOBRE = ['Documentos contables', 'Sobre con contratos', 'Certificados y diplomas', 'Sobre con facturas', 'Documentos legales'];
const DESC_PAQUETE = ['Caja con ropa', 'Repuestos pequeños', 'Medicinas y botiquín', 'Calzado en caja', 'Libros y útiles', 'Equipo electrónico', 'Artesanías', 'Herramientas manuales', 'Productos de belleza'];
const DESC_CARGA = ['Sacos de abarrotes', 'Repuestos de motor', 'Rollos de tela', 'Cajas de ferretería', 'Insumos agrícolas', 'Electrodomésticos', 'Material de construcción', 'Bidones de aceite', 'Cajas de mercadería'];

/** Parámetros de tarifa por tipo: [base, porKm] actuales y anteriores al 2026-09-01. */
const PRECIOS = ['A' => ['SOBRE' => [8.00, 0.005], 'PAQUETE' => [10.00, 0.020], 'CARGA' => [40.00, 0.080]], 'H' => ['SOBRE' => [7.50, 0.004], 'PAQUETE' => [9.00, 0.018], 'CARGA' => [36.00, 0.070]]];
const CAMBIO_TARIFA = '2026-09-01';

/** Contraseña de prueba aleatoria (random_int: NO depende de la semilla determinista del generador). */
function clavePrueba(): string
{
    $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = 'Demo-';
    for ($i = 0; $i < 8; $i++) {
        $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }

    return $c;
}

/** @param array<string, mixed> $ex lo existente en la base (ver cargar-datos-demo.php) @return array<string, mixed> */
function generarDemo(int $ahora, array $ex): array
{
    // ── Red, tarifas y flota ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $aristas = [['LIM001', 'AQP001', 1010.0, 18], ['AQP001', 'MOQ001', 230.0, 4], ['MOQ001', 'ILO001', 100.0, 2], ['AQP001', 'CUS001', 510.0, 9], ['AQP001', 'JUL001', 270.0, 5], ['JUL001', 'CUS001', 340.0, 6]];
    $dobles = [['LIM001', 'MOQ001', ['LIM001', 'AQP001', 'MOQ001']], ['LIM001', 'CUS001', ['LIM001', 'AQP001', 'CUS001']], ['LIM001', 'JUL001', ['LIM001', 'AQP001', 'JUL001']],
        ['AQP001', 'ILO001', ['AQP001', 'MOQ001', 'ILO001']], ['CUS001', 'MOQ001', ['CUS001', 'AQP001', 'MOQ001']]];
    $horasArista = [];
    $distArista = [];
    $pares = []; // "a|b" dirigido => ['dist','horas','ruta' => [nodos]]
    foreach ($aristas as [$a, $b, $km, $h]) {
        $horasArista["$a-$b"] = $h;
        $distArista["$a-$b"] = $km;
        $pares["$a|$b"] = ['dist' => $km, 'horas' => $h, 'ruta' => [$a, $b]];
        $pares["$b|$a"] = ['dist' => $km, 'horas' => $h, 'ruta' => [$b, $a]];
    }
    $arista = static fn (string $a, string $b): string => isset($horasArista["$a-$b"]) ? "$a-$b" : "$b-$a";
    foreach ($dobles as [$a, $b, $ruta]) {
        $km = $distArista[$arista($ruta[0], $ruta[1])] + $distArista[$arista($ruta[1], $ruta[2])];
        $h = $horasArista[$arista($ruta[0], $ruta[1])] + $horasArista[$arista($ruta[1], $ruta[2])] + 2;
        $pares["$a|$b"] = ['dist' => $km, 'horas' => $h, 'ruta' => $ruta];
        $pares["$b|$a"] = ['dist' => $km, 'horas' => $h, 'ruta' => array_reverse($ruta)];
    }
    $pesosPares = ['LIM001|AQP001' => 9, 'AQP001|LIM001' => 8, 'AQP001|MOQ001' => 8, 'MOQ001|AQP001' => 8, 'MOQ001|ILO001' => 4, 'ILO001|MOQ001' => 4, 'AQP001|CUS001' => 5, 'CUS001|AQP001' => 5,
        'AQP001|JUL001' => 4, 'JUL001|AQP001' => 4, 'JUL001|CUS001' => 2, 'CUS001|JUL001' => 2, 'LIM001|MOQ001' => 4, 'MOQ001|LIM001' => 4, 'LIM001|CUS001' => 3, 'CUS001|LIM001' => 2,
        'LIM001|JUL001' => 2, 'JUL001|LIM001' => 2, 'AQP001|ILO001' => 2, 'ILO001|AQP001' => 2, 'CUS001|MOQ001' => 1, 'MOQ001|CUS001' => 1];

    $tarifas = []; // "o|d|tipo|A/H" => parámetros
    $rutasNuevas = [];
    foreach ($pares as $clave => $par) {
        [$o, $d] = explode('|', $clave);
        foreach (['SOBRE', 'PAQUETE', 'CARGA'] as $tipo) {
            foreach (['A', 'H'] as $v) {
                $desde = $v === 'A' ? CAMBIO_TARIFA : '2025-10-01';
                $existente = $v === 'A' ? ($ex['rutasExistentes']["$o|$d|$tipo|$desde"] ?? null) : null;
                $fila = $existente !== null
                    ? ['base' => (float) $existente['precioBase'], 'km' => (float) $existente['precioPorKm'], 'dist' => (float) $existente['distanciaKm'], 'horas' => (int) $existente['tiempoHoras'], 'desde' => $desde, 'hasta' => null, 'existe' => true]
                    : ['base' => PRECIOS[$v][$tipo][0], 'km' => PRECIOS[$v][$tipo][1], 'dist' => $par['dist'], 'horas' => $par['horas'], 'desde' => $desde, 'hasta' => $v === 'A' ? null : '2026-08-31', 'existe' => false];
                $tarifas["$o|$d|$tipo|$v"] = $fila;
                if (!$fila['existe']) {
                    $rutasNuevas["$o|$d|$tipo|$desde"] = ['o' => $o, 'd' => $d, 'tipo' => $tipo] + $fila;
                }
            }
        }
    }

    $vehiculosNuevos = ['F4L-201' => ['FURGON', 8000.0], 'G5T-330' => ['CAMION', 12000.0], 'H2K-418' => ['FURGON', 4000.0], 'J6P-552' => ['CAMION', 15000.0], 'K9R-671' => ['FURGON', 8000.0], 'L3N-702' => ['CAMIONETA', 2500.0]];
    $capacidad = [];
    foreach ($ex['vehiculosExistentes'] as $placa => $v) {
        $capacidad[$placa] = $v['cap'];
    }
    foreach ($vehiculosNuevos as $placa => [$tipo, $cap]) {
        if (isset($capacidad[$placa])) {
            throw new RuntimeException("La placa $placa ya existe");
        }
        $capacidad[$placa] = $cap;
    }
    $flotaArista = ['LIM001-AQP001' => ['V2A-845', 'D8F-102', 'F4L-201'], 'AQP001-MOQ001' => ['C3M-956', 'B7K-210', 'G5T-330'], 'MOQ001-ILO001' => ['A1X-374', 'C3M-956', 'H2K-418'],
        'AQP001-CUS001' => ['K9R-671', 'J6P-552'], 'AQP001-JUL001' => ['L3N-702', 'K9R-671', 'B7K-210'], 'JUL001-CUS001' => ['L3N-702', 'H2K-418']];
    foreach ($flotaArista as $k => $placas) {
        usort($placas, static fn (string $x, string $y): int => $capacidad[$x] <=> $capacidad[$y]);
        $flotaArista[$k] = $placas;
        foreach ($placas as $p) {
            if (!isset($capacidad[$p])) {
                throw new RuntimeException("Falta el vehículo $p");
            }
        }
    }

    // ── Sucursales y teléfonos nuevos ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $sucursales = [];
    foreach ([['CUS001', 'Agencia Cusco', 'Cusco', 'Cusco', 'Wanchaq', 'Av. de la Cultura 1020'], ['JUL001', 'Agencia Juliaca', 'Puno', 'San Román', 'Juliaca', 'Jr. Lima 215']] as [$c, $n, $dep, $pro, $dis, $dir]) {
        if (isset($ex['sucursalIds'][$c])) {
            throw new RuntimeException("La sucursal $c ya existe");
        }
        $sucursales[$c] = ['nombre' => $n, 'departamento' => $dep, 'provincia' => $pro, 'distrito' => $dis, 'direccion' => $dir];
    }
    foreach (NODOS as $n) {
        if (!isset($ex['sucursalIds'][$n]) && !isset($sucursales[$n])) {
            throw new RuntimeException("Falta la sucursal $n");
        }
    }
    $telefonos = [['CUS001', '084231000'], ['CUS001', '084231001'], ['JUL001', '051321500'], ['JUL001', '051321501'], ['LIM001', '014251231'], ['MOQ001', '053461121'], ['ILO001', '053481561']];

    // ── Personas y usuarios ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $personas = [];
    $nombresUsados = [];
    $dniSig = 1000000;
    $rucSig = 100000;
    $telSig = 1000;
    $nuevoDni = static function () use (&$dniSig, $ex): string {
        do {
            $n = '9' . sprintf('%07d', ++$dniSig);
        } while (isset($ex['documentosUsados'][$n]));

        return $n;
    };
    $nuevoRuc = static function () use (&$rucSig, $ex): string {
        do {
            $n = '20999' . sprintf('%06d', ++$rucSig);
        } while (isset($ex['documentosUsados'][$n]));

        return $n;
    };
    $nuevoNombre = static function () use (&$nombresUsados): string {
        do {
            $mujer = azar() < 0.5;
            $nombre = elegir($mujer ? NOMBRES_F : NOMBRES_M) . (azar() < 0.3 ? ' ' . elegir($mujer ? NOMBRES_F : NOMBRES_M) : '') . ' ' . elegir(APELLIDOS) . ' ' . elegir(APELLIDOS);
        } while (isset($nombresUsados[$nombre]));
        $nombresUsados[$nombre] = true;

        return $nombre;
    };
    $nuevaEmpresa = static function () use (&$nombresUsados): string {
        do {
            $nombre = elegir(EMPRESAS_A) . ' ' . elegir(EMPRESAS_B) . ' S.A.C.';
        } while (isset($nombresUsados[$nombre]));
        $nombresUsados[$nombre] = true;

        return $nombre;
    };
    $quitarTildes = static fn (string $s): string => strtolower(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', ' ' => '.', '.' => '.']));
    $crearPersona = static function (string $tipo, string $nombre, ?bool $conCorreo = null) use (&$personas, &$telSig, $nuevoDni, $nuevoRuc, $quitarTildes): string {
        $num = $tipo === 'RUC' ? $nuevoRuc() : $nuevoDni();
        $tel = '99' . sprintf('%07d', ++$telSig);
        $correo = ($conCorreo ?? azar() < 0.55) ? preg_replace('/[^a-z0-9.]/', '', $quitarTildes($nombre)) . $num[-3] . $num[-2] . '@example.com' : null;
        $personas[$num] = ['tipo' => $tipo, 'num' => $num, 'nombre' => $nombre, 'tel' => $tel, 'correo' => $correo, 'primerUso' => null];

        return $num;
    };

    $usuarios = [];
    $inicioOperaciones = mktime(8, 0, 0, 10, 20, 2025);
    $nuevoUsuario = static function (string $usuario, string $rol, ?string $suc, string $docPersona) use (&$usuarios, $inicioOperaciones, $ex): void {
        if (isset($ex['usuariosExistentes'][$usuario])) {
            throw new RuntimeException("El usuario $usuario ya existe");
        }
        $usuarios[$usuario] = ['persona' => $docPersona, 'rol' => $rol, 'suc' => $suc, 'creacion' => $inicioOperaciones + 3600 * count($usuarios), 'clave' => clavePrueba()];
    };
    foreach (['gerente.demo1', 'gerente.demo2'] as $u) {
        $nuevoUsuario($u, 'GERENTE', null, $crearPersona('DNI', $nuevoNombre(), true));
    }
    foreach (['lim' => 'LIM001', 'aqp' => 'AQP001', 'moq' => 'MOQ001', 'ilo' => 'ILO001', 'cus' => 'CUS001', 'jul' => 'JUL001'] as $abrev => $suc) {
        $nuevoUsuario("admin.$abrev", 'ADMINISTRADOR', $suc, $crearPersona('DNI', $nuevoNombre(), true));
    }
    $transportistasPorSuc = ['LIM001' => ['trans.lim1', 'trans.lim2'], 'AQP001' => ['trans.aqp1', 'trans.aqp2'], 'MOQ001' => ['trans.moq1', 'trans.moq2'], 'ILO001' => ['trans.ilo1'], 'CUS001' => ['trans.cus1', 'trans.cus2'], 'JUL001' => ['trans.jul1']];
    foreach ($transportistasPorSuc as $suc => $lista) {
        foreach ($lista as $u) {
            $nuevoUsuario($u, 'TRANSPORTISTA', $suc, $crearPersona('DNI', $nuevoNombre(), false));
        }
    }
    $personasCliente = [];
    for ($i = 1; $i <= 6; $i++) {
        $personasCliente[] = $crearPersona('DNI', $nuevoNombre(), true);
        $nuevoUsuario("cliente.demo$i", 'CLIENTE', null, $personasCliente[$i - 1]);
    }
    $personasEmpresario = [];
    for ($i = 1; $i <= 3; $i++) {
        $personasEmpresario[] = $crearPersona('RUC', $nuevaEmpresa(), true);
        $nuevoUsuario("empresario.demo$i", 'EMPRESARIO', null, $personasEmpresario[$i - 1]);
    }
    // El marcador de repetibilidad es gerente.demo1: debe ser el primer usuario creado.
    $ocasionales = [];
    for ($i = 0; $i < 140; $i++) {
        $ocasionales[] = $crearPersona('DNI', $nuevoNombre());
    }
    $empresasOcasionales = [];
    for ($i = 0; $i < 14; $i++) {
        $empresasOcasionales[] = $crearPersona('RUC', $nuevaEmpresa());
    }

    // Transportistas disponibles: los nuevos y los existentes.
    $transportistas = [];
    foreach ($usuarios as $nombre => $u) {
        if ($u['rol'] === 'TRANSPORTISTA') {
            $transportistas[$nombre] = $u['suc'];
        }
    }
    foreach ($ex['usuariosExistentes'] as $nombre => $u) {
        if ($u['rol'] === 'TRANSPORTISTA') {
            $transportistas[$nombre] = $u['suc'];
        }
    }

    // ── Reservas: calendario de actividad ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $mesesActividad = ['2025-11' => 40, '2025-12' => 64, '2026-01' => 41, '2026-02' => 37, '2026-03' => 46, '2026-04' => 49, '2026-05' => 57, '2026-06' => 51, '2026-07' => 60, '2026-08' => 53, '2026-09' => 62, '2026-10' => 70, '2026-11' => 14];
    $marcas = [];
    $limiteReserva = $ahora - 12 * 60;
    foreach ($mesesActividad as $mes => $n) {
        [$anio, $m] = array_map('intval', explode('-', $mes));
        $dias = (int) date('t', mktime(0, 0, 0, $m, 1, $anio));
        for ($i = 0; $i < $n; $i++) {
            for ($intento = 0; $intento < 400; $intento++) {
                $ts = mktime(ponderado([8 => 6, 9 => 10, 10 => 12, 11 => 11, 12 => 8, 13 => 6, 14 => 9, 15 => 11, 16 => 10, 17 => 8, 18 => 4]), entre(0, 59), 0, $m, entre(1, $dias), $anio);
                $aceptar = [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 0.65, 7 => 0.3][(int) date('N', $ts)];
                if ($ts <= $limiteReserva && azar() < $aceptar) {
                    break;
                }
                $ts = null;
            }
            if ($ts !== null) {
                $marcas[] = $ts;
            }
        }
    }
    // Operación en curso: reservas de las últimas 40 horas para que haya envíos recién registrados, en tránsito y viajes en ruta o programados.
    for ($i = 0; $i < 14; $i++) {
        for ($intento = 0; $intento < 200; $intento++) {
            $ts = $ahora - entre(40 * 60, 40 * 3600);
            if ((int) date('G', $ts) >= 8 && (int) date('G', $ts) <= 18 && ($ts <= $limiteReserva)) {
                $marcas[] = $ts;
                break;
            }
        }
    }
    sort($marcas);

    $enviosPlan = [];
    $reservas = [];
    $codigoReserva = $ex['maxReserva'];
    $ids = array_keys($pesosPares);
    foreach ($marcas as $ts) {
        $par = ponderado($pesosPares);
        [$o, $d] = explode('|', (string) $par);
        $sorteo = azar();
        $tipoRem = $sorteo < 0.20 ? 'cliente' : ($sorteo < 0.45 ? 'empresario' : ($sorteo < 0.55 ? 'empresa' : 'ocasional'));
        $rem = match ($tipoRem) {
            'cliente' => elegir($personasCliente), 'empresario' => elegir($personasEmpresario), 'empresa' => elegir($empresasOcasionales), default => elegir($ocasionales),
        };
        do {
            $s2 = azar();
            $des = $s2 < 0.10 ? elegir($personasCliente) : ($s2 < 0.22 ? elegir($empresasOcasionales) : ($s2 < 0.28 ? elegir($personasEmpresario) : elegir($ocasionales)));
        } while ($des === $rem);
        $canal = ponderado(['WEB' => $tipoRem === 'cliente' || $tipoRem === 'empresario' ? 55 : 35, 'VENTANILLA' => 40, 'TELEFONO' => 15]);
        $edad = $ahora - $ts;
        $estado = null;
        if ($canal === 'VENTANILLA') {
            $estado = azar() < 0.97 ? 'FORMALIZADA' : 'CANCELADA';
        } elseif ($edad <= 72 * 3600) {
            $estado = ponderado(['PENDIENTE' => 55, 'FORMALIZADA' => 40, 'CANCELADA' => 5]);
        } else {
            $estado = ponderado(['FORMALIZADA' => 80, 'CANCELADA' => 8, 'VENCIDA' => 12]);
        }
        if ($estado === 'PENDIENTE' && $edad > 72 * 3600) {
            $estado = 'VENCIDA';
        }
        $perfil = $tipoRem === 'empresario' ? ponderado(['liviano' => 20, 'medio' => 35, 'pesado' => 45]) : ($tipoRem === 'empresa' ? ponderado(['liviano' => 35, 'medio' => 40, 'pesado' => 25]) : ponderado(['liviano' => 70, 'medio' => 25, 'pesado' => 5]));
        $reservas[] = ['ts' => $ts, 'rem' => $rem, 'des' => $des, 'o' => $o, 'd' => $d, 'canal' => $canal, 'estado' => $estado, 'perfil' => $perfil, 'tipoRem' => $tipoRem, 'codigo' => 'R' . sprintf('%09d', ++$codigoReserva)];
    }
    unset($ids);

    // ── Envíos y bultos de las reservas formalizadas ──────────────────────────────────────────────────────────────────────────────────────────────────
    $maxCapTramo = static function (array $ruta) use ($flotaArista, $capacidad, $arista): float {
        $m = PHP_FLOAT_MAX;
        for ($i = 0; $i < count($ruta) - 1; $i++) {
            $m = min($m, max(array_map(static fn (string $p): float => $capacidad[$p], $flotaArista[$arista($ruta[$i], $ruta[$i + 1])])));
        }

        return $m;
    };
    $mezclaBultos = static function (string $perfil, float $tope): array {
        $pesos = [];
        if ($perfil === 'liviano') {
            if (azar() < 0.5) {
                for ($i = entre(1, 3); $i > 0; $i--) {
                    $pesos[] = [round(0.1 + azar() * 0.85, 2), elegir(DESC_SOBRE)];
                }
            } else {
                for ($i = entre(1, 3); $i > 0; $i--) {
                    $pesos[] = [round(1.2 + azar() * 24, 2), elegir(DESC_PAQUETE)];
                }
            }
        } elseif ($perfil === 'medio') {
            for ($i = entre(1, 6); $i > 0; $i--) {
                $pesos[] = azar() < 0.6 ? [round(4 + azar() * 25.9, 2), elegir(DESC_PAQUETE)] : [round(31 + azar() * 120, 2), elegir(DESC_CARGA)];
            }
        } else {
            for ($i = entre(6, 38); $i > 0; $i--) {
                $pesos[] = azar() < 0.88 ? [round(80 + azar() * 220, 2), elegir(DESC_CARGA)] : [round(10 + azar() * 19, 2), elegir(DESC_PAQUETE)];
            }
        }
        while (array_sum(array_column($pesos, 0)) > $tope && count($pesos) > 1) {
            array_pop($pesos);
        }
        if (array_sum(array_column($pesos, 0)) > $tope) {
            $pesos = [[round(min($pesos[0][0], $tope * 0.9), 2), $pesos[0][1]]];
        }

        return $pesos;
    };
    $codigoEnvio = static function () use (&$ex): string {
        do {
            $c = 'EN';
            for ($i = 0; $i < 10; $i++) {
                $c .= 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'[entre(0, 33)];
            }
        } while (isset($ex['codigosEnvio'][$c]));
        $ex['codigosEnvio'][$c] = true;

        return $c;
    };
    $envios = [];
    foreach ($reservas as $i => &$r) {
        $r['desc'] = '';
        if ($r['estado'] !== 'FORMALIZADA') {
            $r['desc'] = elegir(['Documentos y paquete pequeño', 'Cajas con mercadería', 'Encomienda familiar', 'Repuestos', 'Productos para venta', 'Ropa y calzado', 'Material de oficina']);
            continue;
        }
        $ruta = $pares["{$r['o']}|{$r['d']}"]['ruta'];
        $tope = 0.7 * $maxCapTramo($ruta);
        $mezcla = $mezclaBultos($r['perfil'], $tope);
        $registro = $r['canal'] === 'VENTANILLA' ? $r['ts'] + entre(1, 12) * 60 : enHorario($r['ts'] + entre(2 * 3600, 56 * 3600));
        $registro = min($registro, $ahora - 8 * 60, $r['ts'] + 71 * 3600);
        $v = date('Y-m-d', $registro) >= CAMBIO_TARIFA ? 'A' : 'H';
        $bultos = [];
        $total = 0;
        foreach ($mezcla as [$peso, $desc]) {
            $tipo = Tarifas::tipoPorPeso((int) round($peso * 100));
            $t = $tarifas["{$r['o']}|{$r['d']}|$tipo|$v"] ?? throw new RuntimeException("Sin tarifa {$r['o']}|{$r['d']}|$tipo|$v");
            $costo = Tarifas::costoBultoCentimos($t['base'], $t['km'], $t['dist']);
            $bultos[] = ['desc' => $desc, 'peso' => $peso, 'tarifa' => "{$r['o']}|{$r['d']}|$tipo|$v", 'costo' => $costo];
            $total += $costo;
        }
        $r['desc'] = implode(' y ', array_unique(array_slice(array_column($bultos, 'desc'), 0, 2)));
        $domicilio = azar() < 0.3;
        $envios[$i] = ['reserva' => $i, 'codigo' => $codigoEnvio(), 'registro' => $registro, 'domicilio' => $domicilio,
            'direccion' => $domicilio ? elegir(CALLES) . ' ' . entre(100, 1999) . ', ' . elegir(['Urb. Las Flores', 'Urb. San Martín', 'Cercado', 'Zona Industrial', 'Barrio Centro']) : null,
            'bultos' => $bultos, 'costo' => $total, 'peso' => array_sum(array_column($bultos, 'peso')), 'anulado' => azar() < 0.015, 'ruta' => $ruta];
    }
    unset($r);
    uasort($envios, static fn (array $a, array $b): int => $a['registro'] <=> $b['registro']);

    // ── Viajes: consolidación de carga por tramo ──────────────────────────────────────────────────────────────────────────────────────────────────────
    $ocupacion = $ex['ocupacion'];
    $libre = static function (string $clave, int $a, int $b) use (&$ocupacion): bool {
        foreach ($ocupacion[$clave] ?? [] as [$x, $y]) {
            if ($a < $y + 3 * 3600 && $x < $b + 3 * 3600) {
                return false;
            }
        }

        return true;
    };
    $viajes = [];
    $porTramo = [];
    // Salidas regulares por tramo: días de la semana (1 = lunes) y hora. Los tramos de poco movimiento salen pocos días, así que la carga se consolida.
    $diasSalida = ['LIM001>AQP001' => [1, 3, 5], 'AQP001>LIM001' => [2, 4, 6], 'AQP001>MOQ001' => [1, 2, 4, 5], 'MOQ001>AQP001' => [1, 3, 4, 6], 'MOQ001>ILO001' => [2, 4, 6], 'ILO001>MOQ001' => [3, 5, 7],
        'AQP001>CUS001' => [2, 5], 'CUS001>AQP001' => [3, 6], 'AQP001>JUL001' => [1, 4], 'JUL001>AQP001' => [2, 5], 'JUL001>CUS001' => [3], 'CUS001>JUL001' => [4]];
    $horaSalida = ['LIM001>AQP001' => 16, 'AQP001>LIM001' => 20, 'AQP001>MOQ001' => 8, 'MOQ001>AQP001' => 7, 'MOQ001>ILO001' => 7, 'ILO001>MOQ001' => 9, 'AQP001>CUS001' => 8, 'CUS001>AQP001' => 6, 'AQP001>JUL001' => 8, 'JUL001>AQP001' => 7,
        'JUL001>CUS001' => 10, 'CUS001>JUL001' => 9];
    $siguienteSalida = static function (string $origen, string $destino, int $listo) use ($diasSalida, $horaSalida): int {
        $dia = mktime(0, 0, 0, (int) date('n', $listo), (int) date('j', $listo), (int) date('Y', $listo));
        for ($k = 0; $k < 21; $k++) {
            $ts = $dia + $k * 86400 + $horaSalida["$origen>$destino"] * 3600 + entre(0, 15) * 60;
            if (in_array((int) date('N', $ts), $diasSalida["$origen>$destino"], true) && $ts >= $listo + 30 * 60 && azar() < 0.93) {
                return $ts;
            }
        }

        return $listo + 36 * 3600;
    };
    $crearViaje = static function (string $o, string $d, int $listo, float $peso) use (&$viajes, &$porTramo, &$ocupacion, $libre, $siguienteSalida, $arista, $horasArista, $flotaArista, $capacidad, $transportistas): int {
        $ar = $arista($o, $d);
        $factorSorteo = azar();
        $factor = $factorSorteo < 0.78 ? 0.95 + azar() * 0.2 : ($factorSorteo < 0.94 ? 1.2 + azar() * 0.4 : 1.7 + azar() * 0.8);
        $candidatos = array_keys(array_filter($transportistas, static fn (?string $s): bool => $s === $o));
        $otros = array_keys(array_filter($transportistas, static fn (?string $s): bool => $s !== $o));
        shuffle($candidatos);
        shuffle($otros);
        $orden = array_merge($candidatos, $otros);
        $salida = $siguienteSalida($o, $d, $listo);
        for ($i = 0; $i < 160; $i++, $salida += 3 * 3600) {
            $llegada = $salida + (int) round($horasArista[$ar] * 3600 * $factor);
            foreach ($flotaArista[$ar] as $placa) {
                if ($capacidad[$placa] * 0.95 < $peso || !$libre('v:' . $placa, $salida, $llegada)) {
                    continue;
                }
                foreach ($orden as $t) {
                    if ($libre('t:' . $t, $salida, $llegada)) {
                        $id = count($viajes) + 1;
                        $viajes[$id] = ['o' => $o, 'd' => $d, 'veh' => $placa, 'trans' => $t, 'salida' => $salida, 'llegada' => $llegada, 'peso' => $peso, 'cargas' => []];
                        $ocupacion['v:' . $placa][] = [$salida, $llegada];
                        $ocupacion['t:' . $t][] = [$salida, $llegada];
                        $porTramo["$o>$d"][] = $id;

                        return $id;
                    }
                }
            }
        }
        throw new RuntimeException("No se pudo programar un viaje $o>$d para {$peso} kg");
    };
    $cargas = [];
    foreach ($envios as $i => &$e) {
        $e['tramos'] = [];
        if ($e['anulado']) {
            continue;
        }
        $listo = $e['registro'] + entre(40, 150) * 60;
        for ($k = 0; $k < count($e['ruta']) - 1; $k++) {
            if ($listo > $ahora - 5 * 60) {
                break;
            }
            [$o, $d] = [$e['ruta'][$k], $e['ruta'][$k + 1]];
            $viaje = null;
            foreach ($porTramo["$o>$d"] ?? [] as $vid) {
                $v = $viajes[$vid];
                if ($v['salida'] >= $listo + 20 * 60 && $v['salida'] <= $listo + 100 * 3600 && $v['peso'] + $e['peso'] <= $capacidad[$v['veh']] * 0.95 && ($viaje === null || $v['salida'] < $viajes[$viaje]['salida'])) {
                    $viaje = $vid;
                }
            }
            if ($viaje === null) {
                $viaje = $crearViaje($o, $d, $listo, $e['peso']);
            } else {
                $viajes[$viaje]['peso'] += $e['peso'];
            }
            $carga = max($listo, min($viajes[$viaje]['salida'] - entre(30, 120) * 60, $ahora - 3600));
            $cargas[] = ['envio' => $i, 'viaje' => $viaje, 'ts' => $carga];
            $viajes[$viaje]['cargas'][] = $i;
            $e['tramos'][] = $viaje;
            if ($viajes[$viaje]['llegada'] > $ahora - 10 * 60) {
                break;
            }
            $listo = $viajes[$viaje]['llegada'] + entre(60, 240) * 60;
        }
    }
    unset($e);
    // Viajes programados sin carga todavía (la salida se fija al despachar).
    $listos = [['AQP001', 'MOQ001'], ['LIM001', 'AQP001'], ['AQP001', 'CUS001'], ['MOQ001', 'ILO001']];
    foreach ($listos as $k => [$o, $d]) {
        $crearViaje($o, $d, $ahora + (10 + $k * 7) * 3600, 50.0);
    }

    // ── Estados finales, entregas y pagos ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    $metodoPonderado = static function (string $tipoRem, int $ts) use ($ahora): string {
        $meses = max(0, ($ts - mktime(0, 0, 0, 11, 1, 2025)) / (30 * 86400));
        $pesos = $tipoRem === 'empresario' || $tipoRem === 'empresa'
            ? ['TRANSFERENCIA' => 50, 'TARJETA' => 20, 'EFECTIVO' => 20, 'BILLETERA' => 10]
            : ['EFECTIVO' => 42 - $meses, 'BILLETERA' => 14 + 2.2 * $meses, 'TARJETA' => 20, 'TRANSFERENCIA' => 16];

        return (string) ponderado(array_map(static fn (float|int $p): float => max(1.0, (float) $p), $pesos));
    };
    $pagos = [];
    foreach ($envios as $i => &$e) {
        $r = $reservas[$i];
        $e['estado'] = 'REGISTRADO';
        $e['entrega'] = null;
        $llegadaFinal = null;
        $tramos = $e['tramos'];
        $completo = $tramos !== [] && count($tramos) === count($e['ruta']) - 1 && $viajes[end($tramos)]['llegada'] <= $ahora - 10 * 60;
        if ($e['anulado']) {
            $e['estado'] = 'ANULADO';
        } elseif ($completo) {
            $llegadaFinal = $viajes[end($tramos)]['llegada'];
            $e['estado'] = 'EN_DESTINO';
            $demora = $e['domicilio'] ? (azar() < 0.9 ? entre(3, 40) : entre(40, 120)) : ((($s = azar()) < 0.55) ? entre(1, 20) : ($s < 0.85 ? entre(20, 70) : entre(70, 260)));
            $entrega = enHorario($llegadaFinal + $demora * 3600 + entre(0, 59) * 60);
            $suerte = azar();
            if ($llegadaFinal < $ahora - 20 * 86400 && $suerte < 0.03) {
                $e['estado'] = 'EN_DESTINO'; // recogida muy demorada: sigue en la agencia de destino
            } elseif ($llegadaFinal < $ahora - 3 * 86400 && $suerte < 0.06) {
                $e['estado'] = 'DEVUELTO';
            } elseif ($entrega <= $ahora - 10 * 60) {
                $e['estado'] = 'ENTREGADO';
                $e['entrega'] = $entrega;
            }
        } elseif ($tramos !== [] && $viajes[$tramos[0]]['salida'] <= $ahora) {
            $e['estado'] = 'EN_TRANSITO';
        }
        $e['llegadaFinal'] = $llegadaFinal;
        // Pagos.
        $t0 = min($ahora - 60, $e['registro'] + entre(1, 40) * 60);
        $costo = $e['costo'];
        $tipoRem = $r['tipoRem'];
        $monto = static fn (int $c): string => soles($c);
        $agregar = static function (int $ts, string $suc, int $c, string $metodo, string $quien, string $estado = 'REGISTRADO') use (&$pagos, $i, $monto): void {
            $pagos[] = ['envio' => $i, 'suc' => $suc, 'ts' => $ts, 'monto' => $monto($c), 'metodo' => $metodo, 'quien' => $quien, 'estado' => $estado, 'centimos' => $c];
        };
        $perfilPago = ponderado(['completo' => 58, 'contra' => 14, 'parcial' => 10, 'dos' => 10, 'pendiente' => 8]);
        if ($e['estado'] === 'ANULADO') {
            if (azar() < 0.6) {
                $agregar($t0, $r['o'], $costo, $metodoPonderado($tipoRem, $t0), 'REMITENTE', 'ANULADO');
            }
        } else {
            $cobradoRemitente = 0;
            if ($perfilPago === 'completo' || $perfilPago === 'dos') {
                $metodo = $metodoPonderado($tipoRem, $t0);
                if (azar() < 0.07) { // un cobro mal registrado, anulado y repetido con otro método
                    $erroneo = $metodo === 'TARJETA' ? 'EFECTIVO' : 'TARJETA';
                    $agregar(max($e['registro'] + 60, $t0 - entre(3, 20) * 60), $r['o'], $costo, $erroneo, 'REMITENTE', 'ANULADO');
                }
                if ($perfilPago === 'completo' || $costo < 1000) {
                    $agregar($t0, $r['o'], $costo, $metodo, 'REMITENTE');
                } else {
                    $primero = (int) (round($costo * (0.4 + azar() * 0.2) / 10) * 10);
                    $agregar($t0, $r['o'], $primero, $metodo, 'REMITENTE');
                    $agregar(min($ahora - 30, $t0 + entre(1, 12) * 60), $r['o'], $costo - $primero, $metodo === 'EFECTIVO' ? 'TARJETA' : 'EFECTIVO', 'REMITENTE');
                }
                $cobradoRemitente = $costo;
            } elseif ($perfilPago === 'parcial' && $costo >= 200) {
                $primero = max(10, (int) (round($costo * (0.3 + azar() * 0.4) / 10) * 10));
                $agregar($t0, $r['o'], min($primero, $costo - 10), $metodoPonderado($tipoRem, $t0), 'REMITENTE');
                $cobradoRemitente = min($primero, $costo - 10);
            }
            if ($e['estado'] === 'ENTREGADO' && $cobradoRemitente < $costo) {
                $t = max($llegadaFinal + 60, $e['entrega'] - entre(5, 30) * 60);
                $agregar($t, $r['d'], $costo - $cobradoRemitente, $metodoPonderado('ocasional', $t), 'DESTINATARIO');
            }
        }
    }
    unset($e);
    usort($pagos, static fn (array $a, array $b): int => [$a['ts'], $a['envio']] <=> [$b['ts'], $b['envio']]);

    // Estado y marca de tiempo de cada viaje respecto de «ahora».
    foreach ($viajes as $id => &$v) {
        $v['estadoViaje'] = $v['salida'] > $ahora ? 'PROGRAMADO' : ($v['llegada'] > $ahora ? 'EN_RUTA' : 'TERMINADO');
    }
    unset($v);
    $secuenciaPorDia = [];
    foreach ($ex['codigosViaje'] as $codigo => $_) {
        $dia = substr((string) $codigo, 2, 8);
        $secuenciaPorDia[$dia] = max($secuenciaPorDia[$dia] ?? 0, (int) substr((string) $codigo, 10, 2));
    }
    uasort($viajes, static fn (array $a, array $b): int => $a['salida'] <=> $b['salida']);
    foreach ($viajes as $id => &$v) {
        $dia = date('Ymd', $v['estadoViaje'] === 'PROGRAMADO' ? $ahora : $v['salida']);
        $secuenciaPorDia[$dia] = ($secuenciaPorDia[$dia] ?? 0) + 1;
        if ($secuenciaPorDia[$dia] > 99) {
            throw new RuntimeException("Más de 99 viajes el $dia");
        }
        $v['codigo'] = 'VJ' . $dia . sprintf('%02d', $secuenciaPorDia[$dia]);
    }
    unset($v);

    // Primer uso de cada persona (para su fecha de registro).
    foreach ($reservas as $r) {
        foreach ([$r['rem'], $r['des']] as $p) {
            $personas[$p]['primerUso'] = min($personas[$p]['primerUso'] ?? PHP_INT_MAX, $r['ts']);
        }
    }

    return ['ahora' => $ahora, 'sucursales' => $sucursales, 'telefonos' => $telefonos, 'personas' => $personas, 'usuarios' => $usuarios, 'vehiculos' => $vehiculosNuevos, 'rutas' => $rutasNuevas, 'tarifas' => $tarifas,
        'reservas' => $reservas, 'envios' => $envios, 'viajes' => $viajes, 'cargas' => $cargas, 'pagos' => $pagos, 'capacidad' => $capacidad, 'ocupacionExistente' => $ex['ocupacion']];
}

/** Validación en memoria del conjunto generado (las mismas reglas del sistema). @param array<string, mixed> $d @return list<string> */
function validarDemo(array $d, int $ahora): array
{
    $mal = [];
    $reservas = $d['reservas'];
    foreach ($reservas as $i => $r) {
        $tieneEnvio = isset($d['envios'][$i]);
        if (($r['estado'] === 'FORMALIZADA') !== $tieneEnvio) {
            $mal[] = "reserva {$r['codigo']}: estado {$r['estado']} y envío " . ($tieneEnvio ? 'presente' : 'ausente');
        }
        if ($r['o'] === $r['d'] || $r['rem'] === $r['des'] || $r['ts'] > $ahora) {
            $mal[] = "reserva {$r['codigo']}: sucursales/personas iguales o futura";
        }
        $edad = $ahora - $r['ts'];
        if (($r['estado'] === 'PENDIENTE' && $edad > 72 * 3600) || ($r['estado'] === 'VENCIDA' && $edad <= 72 * 3600)) {
            $mal[] = "reserva {$r['codigo']}: estado {$r['estado']} incoherente con las 72 h ($edad s)";
        }
    }
    $pagado = [];
    foreach ($d['pagos'] as $p) {
        $e = $d['envios'][$p['envio']];
        if ($p['ts'] < $e['registro'] || $p['ts'] > $ahora || $p['centimos'] <= 0 || $p['centimos'] > $e['costo']) {
            $mal[] = "pago del envío {$e['codigo']}: fecha o monto inválidos";
        }
        if ($p['estado'] === 'REGISTRADO') {
            $pagado[$p['envio']] = ($pagado[$p['envio']] ?? 0) + $p['centimos'];
        }
        if ($e['estado'] === 'ANULADO' && $p['estado'] === 'REGISTRADO') {
            $mal[] = "envío anulado {$e['codigo']} con pago REGISTRADO";
        }
    }
    foreach ($d['envios'] as $i => $e) {
        $r = $reservas[$i];
        $costo = 0;
        if ($e['registro'] < $r['ts'] || $e['registro'] > $r['ts'] + 72 * 3600 || $e['registro'] > $ahora) {
            $mal[] = "envío {$e['codigo']}: registro fuera de la vigencia de su reserva";
        }
        if ($e['bultos'] === [] || count($e['bultos']) > 50) {
            $mal[] = "envío {$e['codigo']}: cantidad de bultos inválida";
        }
        foreach ($e['bultos'] as $b) {
            $tipo = Tarifas::tipoPorPeso((int) round($b['peso'] * 100));
            [$o, $dd, $t, $v] = explode('|', $b['tarifa']);
            $t2 = $d['tarifas'][$b['tarifa']] ?? null;
            $vigente = $t2 !== null && date('Y-m-d', $e['registro']) >= $t2['desde'] && ($t2['hasta'] === null || date('Y-m-d', $e['registro']) <= $t2['hasta']);
            if ($tipo !== $t || $o !== $r['o'] || $dd !== $r['d'] || !$vigente || $b['peso'] <= 0
                || $b['costo'] !== Tarifas::costoBultoCentimos($t2['base'], $t2['km'], $t2['dist'])) {
                $mal[] = "envío {$e['codigo']}: bulto con tipo, ruta, vigencia o costo incorrectos";
            }
            $costo += $b['costo'];
        }
        if ($costo !== $e['costo'] || ($pagado[$i] ?? 0) > $costo) {
            $mal[] = "envío {$e['codigo']}: costo {$costo} / pagado " . ($pagado[$i] ?? 0);
        }
        if ($e['estado'] === 'ENTREGADO' && (($pagado[$i] ?? 0) !== $costo || $e['entrega'] === null || $e['entrega'] < ($e['llegadaFinal'] ?? PHP_INT_MAX) || $e['entrega'] > $ahora)) {
            $mal[] = "envío {$e['codigo']}: ENTREGADO con saldo o fechas incoherentes";
        }
        if ($e['estado'] !== 'ENTREGADO' && $e['entrega'] !== null) {
            $mal[] = "envío {$e['codigo']}: fecha de entrega sin estar ENTREGADO";
        }
        if ($e['domicilio'] && $e['direccion'] === null) {
            $mal[] = "envío {$e['codigo']}: DOMICILIO sin dirección";
        }
        if (in_array($e['estado'], ['EN_DESTINO', 'ENTREGADO', 'DEVUELTO'], true) && ($e['llegadaFinal'] === null || count($e['tramos']) !== count($e['ruta']) - 1)) {
            $mal[] = "envío {$e['codigo']}: estado {$e['estado']} sin haber completado sus tramos";
        }
    }
    $peso = [];
    foreach ($d['cargas'] as $c) {
        $e = $d['envios'][$c['envio']];
        $v = $d['viajes'][$c['viaje']];
        if ($c['ts'] < $e['registro'] || $c['ts'] > $ahora || $c['ts'] > $v['salida']) {
            $mal[] = "carga del envío {$e['codigo']} en {$v['codigo']}: cronología inválida";
        }
        $peso[$c['viaje']] = ($peso[$c['viaje']] ?? 0) + $e['peso'];
    }
    $intervalos = [];
    foreach ($d['viajes'] as $id => $v) {
        if (($peso[$id] ?? 0) > $d['capacidad'][$v['veh']]) {
            $mal[] = "viaje {$v['codigo']}: supera la capacidad";
        }
        if ($v['llegada'] <= $v['salida']) {
            $mal[] = "viaje {$v['codigo']}: llegada anterior a la salida";
        }
        if ($v['estadoViaje'] !== 'PROGRAMADO') {
            foreach (['v:' . $v['veh'], 't:' . $v['trans']] as $clave) {
                foreach ($intervalos[$clave] ?? [] as [$a, $b]) {
                    if ($v['salida'] < $b && $a < $v['llegada']) {
                        $mal[] = "viaje {$v['codigo']}: solapado ($clave)";
                    }
                }
                foreach ($d['ocupacionExistente'][$clave] ?? [] as [$a, $b]) {
                    if ($v['salida'] < $b && $a < $v['llegada']) {
                        $mal[] = "viaje {$v['codigo']}: solapado con un viaje existente ($clave)";
                    }
                }
                $intervalos[$clave][] = [$v['salida'], $v['llegada']];
            }
        }
    }
    foreach ($d['usuarios'] as $nombre => $u) {
        if (!isset($d['personas'][$u['persona']]) || strlen($nombre) < 4) {
            $mal[] = "usuario $nombre inválido";
        }
    }
    foreach (['GERENTE', 'ADMINISTRADOR', 'TRANSPORTISTA', 'CLIENTE', 'EMPRESARIO'] as $rol) {
        if (count(array_filter($d['usuarios'], static fn (array $u): bool => $u['rol'] === $rol)) < 2) {
            $mal[] = "menos de dos cuentas $rol";
        }
    }
    $conViajes = array_unique(array_column($d['viajes'], 'trans'));
    foreach ($d['usuarios'] as $nombre => $u) {
        if ($u['rol'] === 'TRANSPORTISTA' && !in_array($nombre, $conViajes, true)) {
            $mal[] = "transportista $nombre sin viajes asignados";
        }
    }
    if (array_key_first($d['usuarios']) !== 'gerente.demo1') {
        $mal[] = 'gerente.demo1 debe ser el primer usuario';
    }

    return $mal;
}

/** @param array<string, mixed> $d @return array<string, int> */
function contarDemo(array $d): array
{
    $bultos = 0;
    foreach ($d['envios'] as $e) {
        $bultos += count($e['bultos']);
    }

    return ['Persona' => count($d['personas']), 'Sucursal' => count($d['sucursales']), 'Telefono_Sucursal' => count($d['telefonos']), 'Usuario' => count($d['usuarios']), 'Ruta' => count($d['rutas']),
        'Vehiculo' => count($d['vehiculos']), 'Reserva' => count($d['reservas']), 'Envio' => count($d['envios']), 'Bulto' => $bultos, 'Viaje' => count($d['viajes']), 'Envio_Viaje' => count($d['cargas']), 'Pago' => count($d['pagos'])];
}

/** @param array<string, mixed> $d */
function resumenDemo(array $d): string
{
    $linea = static fn (string $titulo, array $cuenta): string => "  $titulo: " . implode(', ', array_map(static fn (string $k, int $n): string => "$k $n", array_keys($cuenta), $cuenta)) . "\n";
    $contar = static fn (array $filas, string $campo): array => array_count_values(array_column($filas, $campo));
    $porMes = [];
    foreach ($d['envios'] as $e) {
        $m = date('Y-m', $e['registro']);
        $porMes[$m] = ($porMes[$m] ?? 0) + 1;
    }
    ksort($porMes);
    $ingreso = 0;
    foreach ($d['pagos'] as $p) {
        $ingreso += $p['estado'] === 'REGISTRADO' ? $p['centimos'] : 0;
    }
    $s = "Conjunto generado (a insertar):\n  " . implode(', ', array_map(static fn (string $k, int $n): string => "$k $n", array_keys(contarDemo($d)), contarDemo($d))) . "\n";
    $s .= $linea('reservas', $contar($d['reservas'], 'estado')) . $linea('envíos', $contar($d['envios'], 'estado')) . $linea('viajes', $contar($d['viajes'], 'estadoViaje')) . $linea('pagos', $contar($d['pagos'], 'estado'));
    $s .= $linea('métodos', $contar($d['pagos'], 'metodo')) . $linea('envíos por mes', $porMes) . '  cobrado REGISTRADO: S/ ' . soles($ingreso) . "\n";

    return $s;
}

/** @param array<string, mixed> $d */
function credencialesTexto(array $d): string
{
    $t = "JATSA — cuentas de DEMOSTRACIÓN (datos ficticios). Archivo LOCAL: no lo subas a Git ni lo compartas.\nCreado: " . date('Y-m-d H:i:s', $d['ahora']) . "\n\n";
    $t .= sprintf("%-20s %-14s %-16s %-10s %s\n", 'USUARIO', 'CONTRASEÑA', 'ROL', 'SUCURSAL', 'PERSONA');
    foreach ($d['usuarios'] as $nombre => $u) {
        $t .= sprintf("%-20s %-14s %-16s %-10s %s\n", $nombre, $u['clave'], $u['rol'], $u['suc'] ?? '-', $d['personas'][$u['persona']]['nombre']);
    }

    return $t . "\nCuentas anteriores a la carga (rquispe, lflores, mrojas, comsur, pmamani): sin cambios; sus contraseñas no se conocen ni se modificaron.\n";
}

/** Inserta todo el conjunto (dentro de la transacción abierta por quien llama). @param array<string, mixed> $d @param array<string, int> $pre */
function insertarDemo(PDO $pdo, array $d, array $pre): void
{
    $ts = static fn (?int $t): ?string => $t === null ? null : date('Y-m-d H:i:s', $t);
    $mapa = static function (string $sql, array $valores = []) use ($pdo): array {
        $q = $pdo->prepare($sql);
        $q->execute($valores);

        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    // Sucursales y teléfonos.
    $q = $pdo->prepare('INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa) VALUES (:c, :n, :d, :p, :di, :dir, 1)');
    foreach ($d['sucursales'] as $c => $s) {
        $q->execute([':c' => $c, ':n' => $s['nombre'], ':d' => $s['departamento'], ':p' => $s['provincia'], ':di' => $s['distrito'], ':dir' => $s['direccion']]);
    }
    $suc = array_map('intval', $mapa('SELECT RTRIM(codigoSucursal), idSucursal FROM Sucursal'));
    $q = $pdo->prepare('INSERT INTO Telefono_Sucursal (idSucursal, numero) VALUES (:s, :n)');
    foreach ($d['telefonos'] as [$c, $n]) {
        $q->execute([':s' => $suc[$c], ':n' => $n]);
    }
    // Personas y usuarios.
    $cuentas = array_flip(array_column($d['usuarios'], 'persona'));
    $q = $pdo->prepare('INSERT INTO Persona (tipoDocumento, numeroDocumento, nombreCompleto, telefono, correo, fechaHoraRegistro) VALUES (:t, :n, :nom, :tel, :c, :f)');
    foreach ($d['personas'] as $p) {
        $base = mktime(8, 0, 0, 10, 20, 2025);
        $registro = isset($cuentas[$p['num']]) || $p['primerUso'] === null ? $base : $p['primerUso'] - 60;
        $q->execute([':t' => $p['tipo'], ':n' => $p['num'], ':nom' => $p['nombre'], ':tel' => $p['tel'], ':c' => $p['correo'], ':f' => $ts($registro)]);
    }
    $per = array_map('intval', $mapa('SELECT numeroDocumento, idPersona FROM Persona WHERE idPersona > :m', [':m' => $pre['Persona']]));
    $q = $pdo->prepare('INSERT INTO Usuario (idPersona, idSucursal, nombreUsuario, hashContrasena, rol, activo, fechaHoraCreacion) VALUES (:p, :s, :u, :h, :r, 1, :f)');
    foreach ($d['usuarios'] as $nombre => $u) {
        $q->execute([':p' => $per[$u['persona']], ':s' => $u['suc'] === null ? null : $suc[$u['suc']], ':u' => $nombre, ':h' => password_hash($u['clave'], PASSWORD_BCRYPT, ['cost' => 10]), ':r' => $u['rol'], ':f' => $ts($u['creacion'])]);
    }
    $usu = array_map('intval', $mapa('SELECT nombreUsuario, idUsuario FROM Usuario'));
    // Vehículos y rutas.
    $q = $pdo->prepare('INSERT INTO Vehiculo (placa, tipoVehiculo, capacidadKg, activo) VALUES (:p, :t, :c, 1)');
    foreach ($d['vehiculos'] as $placa => [$tipo, $cap]) {
        $q->execute([':p' => $placa, ':t' => $tipo, ':c' => number_format($cap, 2, '.', '')]);
    }
    $veh = array_map('intval', $mapa('SELECT placa, idVehiculo FROM Vehiculo'));
    $q = $pdo->prepare('INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta) VALUES (:o, :d, :t, :b, :k, :km, :h, :vd, :vh)');
    foreach ($d['rutas'] as $r) {
        $q->execute([':o' => $suc[$r['o']], ':d' => $suc[$r['d']], ':t' => $r['tipo'], ':b' => number_format($r['base'], 2, '.', ''), ':k' => number_format($r['km'], 3, '.', ''), ':km' => number_format($r['dist'], 1, '.', ''),
            ':h' => $r['horas'], ':vd' => $r['desde'], ':vh' => $r['hasta']]);
    }
    $rutaId = [];
    $sucPorId = array_flip($suc);
    foreach ($pdo->query('SELECT idRuta, idSucursalOrigen o, idSucursalDestino d, tipoEnvio, CONVERT(VARCHAR(10), vigenteDesde, 23) AS desde FROM Ruta') as $r) {
        $rutaId[$sucPorId[(int) $r['o']] . '|' . $sucPorId[(int) $r['d']] . '|' . $r['tipoEnvio'] . '|' . $r['desde']] = (int) $r['idRuta'];
    }
    $idTarifa = static function (string $clave) use ($d, $rutaId): int {
        [$o, $dd, $t, $v] = explode('|', $clave);

        return $rutaId["$o|$dd|$t|" . $d['tarifas'][$clave]['desde']] ?? throw new RuntimeException("Ruta no encontrada: $clave");
    };
    // Reservas y envíos.
    $q = $pdo->prepare('INSERT INTO Reserva (idPersonaRemitente, idPersonaDestinatario, idSucursalOrigen, idSucursalDestino, codigoReserva, fechaHoraReserva, canal, descripcion, estadoReserva) VALUES (:r, :d, :o, :de, :c, :f, :ca, :des, :e)');
    foreach ($d['reservas'] as $r) {
        $q->execute([':r' => $per[$r['rem']], ':d' => $per[$r['des']], ':o' => $suc[$r['o']], ':de' => $suc[$r['d']], ':c' => $r['codigo'], ':f' => $ts($r['ts']), ':ca' => $r['canal'], ':des' => $r['desc'], ':e' => $r['estado']]);
    }
    $res = array_map('intval', $mapa('SELECT codigoReserva, idReserva FROM Reserva WHERE idReserva > :m', [':m' => $pre['Reserva']]));
    $q = $pdo->prepare('INSERT INTO Envio (idReserva, codigoSeguimiento, fechaHoraRegistro, tipoEntrega, direccionEntrega, estadoEnvio, fechaHoraEntrega) VALUES (:r, :c, :f, :t, :dir, :e, :fe)');
    foreach ($d['envios'] as $i => $e) {
        $q->execute([':r' => $res[$d['reservas'][$i]['codigo']], ':c' => $e['codigo'], ':f' => $ts($e['registro']), ':t' => $e['domicilio'] ? 'DOMICILIO' : 'AGENCIA', ':dir' => $e['direccion'], ':e' => $e['estado'], ':fe' => $ts($e['entrega'])]);
    }
    $env = array_map('intval', $mapa('SELECT codigoSeguimiento, idEnvio FROM Envio WHERE idEnvio > :m', [':m' => $pre['Envio']]));
    $q = $pdo->prepare('INSERT INTO Bulto (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo) VALUES (:e, :r, :n, :d, :p, :c)');
    foreach ($d['envios'] as $e) {
        foreach ($e['bultos'] as $n => $b) {
            $q->execute([':e' => $env[$e['codigo']], ':r' => $idTarifa($b['tarifa']), ':n' => $n + 1, ':d' => $b['desc'], ':p' => number_format($b['peso'], 2, '.', ''), ':c' => soles($b['costo'])]);
        }
    }
    // Viajes y cargas.
    $q = $pdo->prepare('INSERT INTO Viaje (idVehiculo, idUsuarioTransportista, idSucursalOrigen, idSucursalDestino, codigoViaje, fechaHoraSalida, fechaHoraLlegada) VALUES (:v, :u, :o, :d, :c, :s, :l)');
    foreach ($d['viajes'] as $v) {
        $salida = $v['estadoViaje'] === 'PROGRAMADO' ? null : $v['salida'];
        $llegada = $v['estadoViaje'] === 'TERMINADO' ? $v['llegada'] : null;
        $q->execute([':v' => $veh[$v['veh']], ':u' => $usu[$v['trans']], ':o' => $suc[$v['o']], ':d' => $suc[$v['d']], ':c' => $v['codigo'], ':s' => $ts($salida), ':l' => $ts($llegada)]);
    }
    $via = array_map('intval', $mapa('SELECT codigoViaje, idViaje FROM Viaje WHERE idViaje > :m', [':m' => $pre['Viaje']]));
    $q = $pdo->prepare('INSERT INTO Envio_Viaje (idEnvio, idViaje, fechaHoraCarga) VALUES (:e, :v, :f)');
    usort($d['cargas'], static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
    foreach ($d['cargas'] as $c) {
        $q->execute([':e' => $env[$d['envios'][$c['envio']]['codigo']], ':v' => $via[$d['viajes'][$c['viaje']]['codigo']], ':f' => $ts($c['ts'])]);
    }
    // Pagos.
    $q = $pdo->prepare('INSERT INTO Pago (idEnvio, idSucursal, fechaHoraPago, monto, metodoPago, pagadoPor, estadoPago) VALUES (:e, :s, :f, :m, :me, :q, :es)');
    foreach ($d['pagos'] as $p) {
        $q->execute([':e' => $env[$d['envios'][$p['envio']]['codigo']], ':s' => $suc[$p['suc']], ':f' => $ts($p['ts']), ':m' => $p['monto'], ':me' => $p['metodo'], ':q' => $p['quien'], ':es' => $p['estado']]);
    }
}
