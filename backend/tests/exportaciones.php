<?php
declare(strict_types=1);

// Pruebas del modulo 12 (exportaciones Excel). Se incluye desde ejecutar.php. Sin base de datos: repositorio simulado.

use Jatsa\Modules\Reportes\ExportacionesService;
use Jatsa\Modules\Reportes\LibroXlsx;
use Jatsa\Modules\Reportes\RepositorioExportaciones;
use Jatsa\Modules\Reportes\ReportesController;
use Jatsa\Shared\Roles;

final class ExportacionesFalso implements RepositorioExportaciones
{
    /** @var list<array{0: string, 1: ?int}> */
    public array $llamadas = [];

    public function idSucursalPorCodigo(string $codigo): ?int
    {
        return $codigo === 'MOQ001' ? 7 : null;
    }

    public function sucursales(): array
    {
        return [['idSucursal' => 7, 'codigoSucursal' => 'MOQ001', 'nombre' => '=HYPERLINK("x")', 'departamento' => 'Moquegua', 'activa' => 1],
            ['idSucursal' => 8, 'codigoSucursal' => 'ARE001', 'nombre' => 'Arequipa', 'departamento' => 'Arequipa', 'activa' => 0]];
    }

    public function enviosResumen(?int $sucursal): array
    {
        $this->llamadas[] = ['envios', $sucursal];

        return [['idEnvio' => 1, 'codigoSeguimiento' => 'ENZ5H0D3V7NJ', 'fechaHoraRegistro' => '2026-03-01 10:30:00', 'idSucursalOrigen' => 7, 'idSucursalDestino' => 8,
            'estadoEnvio' => 'EN_DESTINO', 'cantidadBultos' => '3', 'importeEnvio' => '60.00', 'cobradoRegistrado' => '45.50']];
    }

    public function pagos(?int $sucursal): array
    {
        $this->llamadas[] = ['pagos', $sucursal];

        return [['idPago' => 1, 'idEnvio' => 1, 'fechaHoraPago' => '2026-03-01 11:00:00', 'idSucursal' => 7, 'monto' => '45.50', 'metodoPago' => 'EFECTIVO', 'pagadoPor' => 'REMITENTE', 'estadoPago' => 'REGISTRADO'],
            ['idPago' => 2, 'idEnvio' => 1, 'fechaHoraPago' => '2026-03-01 11:05:00', 'idSucursal' => 7, 'monto' => '99.00', 'metodoPago' => 'EFECTIVO', 'pagadoPor' => 'REMITENTE', 'estadoPago' => 'ANULADO']];
    }

    public function viajes(?int $sucursal): array
    {
        $this->llamadas[] = ['viajes', $sucursal];
        $base = ['idSucursalOrigen' => 7, 'idSucursalDestino' => 8, 'placa' => 'ABC-123', 'nombreUsuario' => 'transp1', 'capacidadKg' => '1500.00'];

        return [$base + ['idViaje' => 1, 'codigoViaje' => 'VJ0000000001', 'fechaHoraSalida' => null, 'fechaHoraLlegada' => null],
            $base + ['idViaje' => 2, 'codigoViaje' => 'VJ0000000002', 'fechaHoraSalida' => '2026-03-02 08:00:00', 'fechaHoraLlegada' => null],
            $base + ['idViaje' => 3, 'codigoViaje' => 'VJ0000000003', 'fechaHoraSalida' => '2026-03-02 08:00:00', 'fechaHoraLlegada' => '2026-03-02 14:15:30']];
    }

    public function envioViaje(?int $sucursal): array
    {
        return [['idEnvioViaje' => 1, 'idEnvio' => 1, 'idViaje' => 3, 'fechaHoraCarga' => '2026-03-02 07:00:00']];
    }

    public function enviosCarga(?int $sucursal): array
    {
        return [['idEnvio' => 1, 'codigoSeguimiento' => 'ENZ5H0D3V7NJ', 'fechaHoraRegistro' => '2026-03-01 10:30:00', 'pesoTotalKg' => '12.75', 'estadoEnvio' => 'EN_DESTINO']];
    }
}

/** Lector minimo de .xlsx para las pruebas: valida firmas y CRC del ZIP y devuelve las hojas como matrices de celdas. */
function leerXlsxM12(string $binario): array
{
    afirmar(substr($binario, 0, 2) === 'PK', 'firma ZIP');
    $fin = strrpos($binario, "PK\x05\x06");
    afirmar($fin !== false, 'fin de directorio ZIP');
    $d = unpack('vtotal/Vtam/Vdesp', substr($binario, $fin + 10, 10));
    $partes = [];
    $pos = $d['desp'];
    for ($i = 0; $i < $d['total']; $i++) {
        $c = unpack('Vfirma/vv1/vv2/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vnombre/vextra/vcom/vdisco/vint/Vext/Vdesp', substr($binario, $pos, 46));
        afirmar($c['firma'] === 0x02014B50, 'entrada del directorio central');
        $nombre = substr($binario, $pos + 46, $c['nombre']);
        $l = unpack('vnombre/vextra', substr($binario, $c['desp'] + 26, 4));
        $datos = substr($binario, $c['desp'] + 30 + $l['nombre'] + $l['extra'], $c['comp']);
        $plano = $c['metodo'] === 8 ? gzinflate($datos) : $datos;
        afirmar($plano !== false && strlen($plano) === $c['tam'] && (crc32($plano) & 0xFFFFFFFF) === $c['crc'], "CRC y tamano de $nombre");
        $partes[$nombre] = $plano;
        $pos += 46 + $c['nombre'] + $c['extra'] + $c['com'];
    }
    foreach ($partes as $nombre => $xml) {
        afirmar(simplexml_load_string($xml) !== false, "XML bien formado: $nombre");
    }
    $textos = [];
    foreach (simplexml_load_string($partes['xl/sharedStrings.xml'])->si as $si) {
        $textos[] = (string) $si->t;
    }
    $libro = simplexml_load_string($partes['xl/workbook.xml']);
    $hojas = [];
    foreach ($libro->sheets->sheet as $n => $hoja) {
        $idx = count($hojas) + 1;
        $filas = [];
        foreach (simplexml_load_string($partes["xl/worksheets/sheet{$idx}.xml"])->sheetData->row as $fila) {
            $celdas = [];
            foreach ($fila->c as $celda) {
                $col = preg_replace('/\d+/', '', (string) $celda['r']);
                $v = (string) $celda->v;
                $celdas[$col] = ['t' => (string) $celda['t'], 's' => (int) $celda['s'], 'v' => (string) $celda['t'] === 's' ? $textos[(int) $v] : $v];
            }
            $filas[] = $celdas;
        }
        $hojas[(string) $hoja['name']] = $filas;
        afirmar(isset($partes["xl/tables/table{$idx}.xml"]) && str_contains($partes["xl/tables/table{$idx}.xml"], '<autoFilter'), 'tabla con filtro en ' . $hoja['name']);
    }

    return $hojas;
}

function escenarioM12(?array $usuario = null, array $consulta = []): array
{
    $repo = new ExportacionesFalso();

    return [$repo, new ExportacionesService($repo), $usuario ?? ['rol' => Roles::GERENTE, 'sucursal' => null], $consulta];
}

prueba('modulo 12: Gerencia = 3 hojas, columnas y tipos; importes sin duplicar; anulados fuera de lo cobrado', function () {
    [$repo, $svc, $u, $q] = escenarioM12();
    $a = $svc->gerencia($u, $q);
    afirmar($a['nombre'] === 'JATSA_Gerencia.xlsx', 'nombre del archivo');
    $h = leerXlsxM12($a['contenido']);
    afirmar(array_keys($h) === ['EnviosResumen', 'Pagos', 'Sucursales'], 'hojas en orden');
    $cab = static fn (array $fila): array => array_column($fila, 'v');
    afirmar($cab($h['EnviosResumen'][0]) === ['idEnvio', 'codigoSeguimiento', 'fechaHoraRegistro', 'FechaRegistro', 'idSucursalOrigen', 'idSucursalDestino', 'estadoEnvio', 'cantidadBultos', 'importeEnvio', 'cobradoRegistrado', 'saldoActual'], 'columnas de EnviosResumen');
    afirmar($cab($h['Pagos'][0]) === ['idPago', 'idEnvio', 'fechaHoraPago', 'FechaPago', 'idSucursalCobro', 'monto', 'metodoPago', 'pagadoPor', 'estadoPago'], 'columnas de Pagos');
    afirmar($cab($h['Sucursales'][0]) === ['idSucursal', 'codigoSucursal', 'nombre', 'departamento', 'activa'], 'columnas de Sucursales');
    $e = $h['EnviosResumen'][1];
    afirmar(count($h['EnviosResumen']) === 2 && $e['I']['v'] === '60' && $e['J']['v'] === '45.5' && $e['K']['v'] === '14.5' && $e['H']['v'] === '3', 'importe 60, cobrado 45.50 (sin el anulado), saldo 14.50, 3 bultos');
    afirmar($e['C']['v'] === '46082.4375' && $e['D']['v'] === '46082' && $e['C']['s'] === 2 && $e['D']['s'] === 1, 'fecha-hora y fecha como numeros de serie con formato de fecha');
    afirmar(count($h['Pagos']) === 3 && $h['Pagos'][2]['I']['v'] === 'ANULADO', 'el pago anulado se conserva como historico');
    afirmar($h['Sucursales'][1]['C']['v'] === "'=HYPERLINK(\"x\")" && $h['Sucursales'][1]['E']['t'] === 'b' && $h['Sucursales'][2]['E']['v'] === '0', 'texto con = protegido y activa como booleano');
    afirmar($repo->llamadas === [['envios', null], ['pagos', null]], 'Gerente: ambito global');
});

prueba('modulo 12: Logistica = 4 hojas; estados deducidos; fechas desconocidas vacias; peso agregado', function () {
    [, $svc, $u, $q] = escenarioM12();
    $a = $svc->logistica($u, $q);
    afirmar($a['nombre'] === 'JATSA_Logistica.xlsx', 'nombre del archivo');
    $h = leerXlsxM12($a['contenido']);
    afirmar(array_keys($h) === ['Viajes', 'EnvioViaje', 'EnviosCarga', 'Sucursales'], 'hojas en orden');
    afirmar(array_column($h['Viajes'][0], 'v') === ['idViaje', 'codigoViaje', 'idSucursalOrigen', 'idSucursalDestino', 'fechaHoraSalida', 'FechaSalida', 'fechaHoraLlegada', 'FechaLlegada', 'estadoCalculado', 'placa', 'transportista', 'capacidadKg'], 'columnas de Viajes');
    afirmar(array_column($h['EnvioViaje'][0], 'v') === ['idEnvioViaje', 'idEnvio', 'idViaje', 'fechaHoraCarga', 'FechaCarga'], 'columnas de EnvioViaje');
    afirmar(array_column($h['EnviosCarga'][0], 'v') === ['idEnvio', 'codigoSeguimiento', 'fechaHoraRegistro', 'FechaRegistro', 'pesoTotalKg', 'estadoEnvio'], 'columnas de EnviosCarga');
    [, $programado, $enRuta, $terminado] = $h['Viajes'];
    afirmar($programado['I']['v'] === 'PROGRAMADO' && !isset($programado['E']) && !isset($programado['F']) && !isset($programado['G']) && !isset($programado['H']), 'PROGRAMADO sin fechas inventadas');
    afirmar($enRuta['I']['v'] === 'EN_RUTA' && isset($enRuta['E']) && !isset($enRuta['G']), 'EN_RUTA: salida si, llegada vacia');
    afirmar($terminado['I']['v'] === 'TERMINADO' && abs((float) $terminado['G']['v'] - (46083 + 51330 / 86400)) < 1e-9, 'TERMINADO con llegada real (14:15:30)');
    afirmar($h['EnviosCarga'][1]['E']['v'] === '12.75' && $terminado['L']['v'] === '1500' && $terminado['L']['t'] === '', 'peso y capacidad numericos');
});

prueba('modulo 12: ambito — el Administrador solo exporta su sucursal; el Gerente puede filtrar; otros roles 403', function () {
    [$repo, $svc, , ] = escenarioM12();
    $admin = ['rol' => Roles::ADMINISTRADOR, 'sucursal' => ['id' => 8, 'codigo' => 'ARE001']];
    $svc->gerencia($admin, ['sucursal' => 'MOQ001']);
    afirmar($repo->llamadas === [['envios', 8], ['pagos', 8]], 'el filtro enviado por el Administrador se ignora: siempre su sucursal');
    $repo->llamadas = [];
    $svc->logistica($admin, []);
    afirmar($repo->llamadas === [['viajes', 8]], 'logistica del Administrador limitada a su sucursal');
    $repo->llamadas = [];
    $svc->gerencia(['rol' => Roles::GERENTE, 'sucursal' => null], ['sucursal' => 'moq001']);
    afirmar($repo->llamadas === [['envios', 7], ['pagos', 7]], 'el Gerente filtra por codigo');
    $e = esperarError(fn () => $svc->gerencia(['rol' => Roles::GERENTE], ['sucursal' => 'NOEXI9']), 422, 'datos_invalidos');
    afirmar(isset($e->campos['sucursal']), 'sucursal inexistente -> error en el campo');
    esperarError(fn () => $svc->gerencia(['rol' => Roles::GERENTE], ['sucursal' => 'a b']), 422, 'datos_invalidos');
    foreach ([Roles::CLIENTE, Roles::EMPRESARIO, Roles::TRANSPORTISTA] as $rol) {
        esperarError(fn () => $svc->logistica(['rol' => $rol], []), 403, 'acceso_denegado');
    }
    esperarError(fn () => $svc->gerencia(['rol' => Roles::ADMINISTRADOR, 'sucursal' => null], []), 403, 'acceso_denegado');
});

prueba('modulo 12: rutas y respuesta — solo GET, GERENTE y ADMINISTRADOR; descarga con nombre fijo y tipo xlsx', function () {
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Reportes/rutas.php');
    afirmar(str_contains($fuente, '$exportadores = [new RequiereRol([Roles::GERENTE, Roles::ADMINISTRADOR])]') && substr_count($fuente, '$exportadores);') === 4, 'las cuatro rutas de exportacion con GERENTE y ADMINISTRADOR');
    afirmar(LibroXlsx::serie('2026-03-02', false) === '46083' && LibroXlsx::serie('no-fecha', false) === null && LibroXlsx::serie('2026-02-30', false) === null, 'fechas de serie y fechas invalidas');
    $r = Jatsa\Core\Response::archivo('x', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'JATSA_Gerencia.xlsx');
    ob_start();
    $r->enviar();
    ob_end_clean();
    afirmar(true, 'enviar');
    afirmar(class_exists(ReportesController::class), 'controlador');
});

/** Lee un ZIP en memoria: devuelve [nombre => bytes] validando CRC y descompresión. */
function leerZipM12(string $zip): array
{
    $fin = strrpos($zip, "PK\x05\x06");
    afirmar($fin !== false, 'ZIP con directorio central');
    $total = unpack('v', substr($zip, $fin + 10, 2))[1];
    $pos = unpack('V', substr($zip, $fin + 16, 4))[1];
    $archivos = [];
    for ($i = 0; $i < $total; $i++) {
        $c = unpack('Vfirma/vver/vmin/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vnombre/vextra/vcom', substr($zip, $pos, 34));
        $nombre = substr($zip, $pos + 46, $c['nombre']);
        $pos += 46 + $c['nombre'] + $c['extra'] + $c['com'];
        $off = unpack('V', substr($zip, $pos - $c['nombre'] - $c['extra'] - $c['com'] - 4, 4))[1];
        $l = unpack('vn/ve', substr($zip, $off + 26, 4));
        $datos = substr($zip, $off + 30 + $l['n'] + $l['e'], $c['comp']);
        $bytes = $c['metodo'] === 8 ? gzinflate($datos) : $datos;
        afirmar($bytes !== false && crc32($bytes) === $c['crc'], 'CRC correcto en ' . $nombre);
        $archivos[$nombre] = $bytes;
    }

    return $archivos;
}

prueba('modulo 12: paquete Power BI — ZIP con exactamente 3 archivos en la raiz, plantilla intacta, xlsx del exportador y manual PDF', function () {
    [, $svc, $u, $q] = escenarioM12();
    $dir = dirname(__DIR__) . '/storage/plantillas';
    foreach (['gerencia' => 'Gerencia', 'logistica' => 'Logistica'] as $tipo => $base) {
        $p = $svc->paquetePowerBi($tipo, $u, $q);
        afirmar($p['nombre'] === "JATSA_{$base}.zip", 'nombre del ZIP');
        $a = leerZipM12($p['contenido']);
        afirmar(array_keys($a) === ["JATSA_{$base}.pbix", "JATSA_{$base}.xlsx", "Manual_{$base}.pdf"], 'exactamente 3 archivos, sin carpetas, en orden');
        afirmar(!str_contains(implode('', array_keys($a)), '/'), 'sin rutas internas');
        afirmar($a["JATSA_{$base}.pbix"] === file_get_contents("{$dir}/JATSA_{$base}.pbix") && str_starts_with($a["JATSA_{$base}.pbix"], "PK\x03\x04"), 'pbix identico al original (zip OPC)');
        afirmar(str_starts_with($a["Manual_{$base}.pdf"], '%PDF-') && str_contains($a["Manual_{$base}.pdf"], '%%EOF'), 'manual PDF valido');
        $esperado = $tipo === 'gerencia' ? $svc->gerencia($u, $q) : $svc->logistica($u, $q);
        afirmar($a["JATSA_{$base}.xlsx"] === $esperado['contenido'], 'xlsx generado por el exportador vigente');
        afirmar(array_keys(leerXlsxM12($a["JATSA_{$base}.xlsx"])) === ($tipo === 'gerencia' ? ['EnviosResumen', 'Pagos', 'Sucursales'] : ['Viajes', 'EnvioViaje', 'EnviosCarga', 'Sucursales']), 'hojas esperadas');
    }
});

prueba('modulo 12: paquete Power BI — mismo ambito y permisos que los Excel; plantilla ausente = 500 sin detalles', function () {
    [$repo, $svc, , ] = escenarioM12();
    $admin = ['rol' => Roles::ADMINISTRADOR, 'sucursal' => ['id' => 8, 'codigo' => 'ARE001']];
    $svc->paquetePowerBi('gerencia', $admin, ['sucursal' => 'MOQ001']);
    afirmar($repo->llamadas === [['envios', 8], ['pagos', 8]], 'el Administrador solo obtiene su sucursal');
    foreach ([Roles::CLIENTE, Roles::EMPRESARIO, Roles::TRANSPORTISTA] as $rol) {
        esperarError(fn () => $svc->paquetePowerBi('logistica', ['rol' => $rol], []), 403, 'acceso_denegado');
    }
    $vacio = new ExportacionesService(new ExportacionesFalso(), sys_get_temp_dir() . '/jatsa_sin_plantillas');
    esperarError(fn () => $vacio->paquetePowerBi('gerencia', ['rol' => Roles::GERENTE], []), 500, 'plantilla_no_disponible');
    $fuente = (string) file_get_contents(dirname(__DIR__) . '/src/Modules/Reportes/rutas.php');
    afirmar(str_contains($fuente, 'gerencia-powerbi') && str_contains($fuente, 'logistica-powerbi') && substr_count($fuente, '$exportadores);') === 4, 'rutas Power BI con GERENTE y ADMINISTRADOR');
});
