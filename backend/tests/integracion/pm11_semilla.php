<?php
declare(strict_types=1);

/*
 * SEMILLA SINTÉTICA y ORÁCULO de la validación real pm11 (módulo 11). Solo definiciones, sin efectos ni acceso a la base: se puede incluir desde las pruebas
 * sin SQL Server. Plan: docs/plan-pm11-validacion-sql.md. Nada de esto se ha ejecutado contra SQL Server.
 *
 * Fechas relativas al instante de carga $ahora (zona de la aplicación): M = mes anterior completo, M-1 = el anterior a M, «mes actual» y un par fijo que
 * CRUZA EL AÑO (diciembre de Y-1 y enero de Y, con Y = año de $ahora - 1) para que los casos de cruce de año valgan cualquier día de ejecución.
 * Los envíos de M y M-1 se registran entre los días 2 y 8, así que siempre llevan más de 3 días; los «recientes» del mes actual (hace ~1 hora) nunca.
 *
 * Resultados esperados: las clases RepoResumenOraculo y RepoFlotaOraculo implementan las MISMAS interfaces que los repositorios SQL pero calculan con bucles
 * PHP sobre esta semilla (sin SQL). El validador compara SQL contra oráculo método por método y, por HTTP, los servicios reales con cada uno.
 */

use Jatsa\Modules\Reportes\RepositorioFlota;
use Jatsa\Modules\Reportes\RepositorioResumen;

const PM11_PREFIJO_CLAVE = 'pm11_';

/**
 * @return array<string, mixed>
 */
function pm11Semilla(DateTimeImmutable $ahora): array
{
    $zona = $ahora->getTimezone();
    $fmt = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
    $en = static fn (DateTimeImmutable $mes, int $dia, string $hora): string => $mes->format('Y-m-') . sprintf('%02d', $dia) . ' ' . $hora;
    $mesActual = $ahora->modify('first day of this month')->setTime(0, 0);
    $m = $mesActual->modify('-1 month');
    $m1 = $mesActual->modify('-2 months');
    $y = (int) $ahora->format('Y') - 1;
    $ene = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $y), $zona);
    $dic = new DateTimeImmutable(sprintf('%04d-12-01 00:00:00', $y - 1), $zona);
    $ayer = $ahora->modify('-1 day')->format('Y-m-d');
    $reciente = max($mesActual, $ahora->modify('-1 hour'));

    $sucursales = [
        ['codigo' => 'TST001', 'nombre' => 'Agencia Sintética Uno'],
        ['codigo' => 'TST002', 'nombre' => 'Agencia Sintética Dos'],
        ['codigo' => 'TST003', 'nombre' => 'Agencia Sintética Tres'],
    ];
    $personas = [
        ['doc' => '99999901', 'nombre' => 'Gerente Sintético Uno'], ['doc' => '99999902', 'nombre' => 'Administrador Sintético Uno'],
        ['doc' => '99999903', 'nombre' => 'Transportista Sintético Uno'], ['doc' => '99999904', 'nombre' => 'Transportista Sintético Dos'],
        ['doc' => '99999905', 'nombre' => 'Cliente Sintético Uno'], ['doc' => '99999906', 'nombre' => 'Empresario Sintético Uno'],
        ['doc' => '99999907', 'nombre' => 'Remitente Sintético'], ['doc' => '99999908', 'nombre' => 'Destinatario Sintético'],
    ];
    $usuarios = [
        ['usuario' => 'pm11_gerente', 'doc' => '99999901', 'rol' => 'GERENTE', 'sucursal' => null],
        ['usuario' => 'pm11_admin', 'doc' => '99999902', 'rol' => 'ADMINISTRADOR', 'sucursal' => 'TST001'],
        ['usuario' => 'pm11_trans1', 'doc' => '99999903', 'rol' => 'TRANSPORTISTA', 'sucursal' => 'TST001'],
        ['usuario' => 'pm11_trans2', 'doc' => '99999904', 'rol' => 'TRANSPORTISTA', 'sucursal' => 'TST002'],
        ['usuario' => 'pm11_cliente', 'doc' => '99999905', 'rol' => 'CLIENTE', 'sucursal' => null],
        ['usuario' => 'pm11_empresa', 'doc' => '99999906', 'rol' => 'EMPRESARIO', 'sucursal' => null],
    ];
    $vehiculos = [
        ['placa' => 'TST-001', 'tipo' => 'FURGON', 'capacidadKg' => 1000.0],
        ['placa' => 'TST-002', 'tipo' => 'CAMIONETA', 'capacidadKg' => 500.0],
    ];
    // R4 está cerrada (2019): su 99 h no debe influir en el «Llega aprox.». TST003→TST001 no tiene ruta vigente (ETA nula).
    $rutas = [
        'R1' => ['origen' => 'TST001', 'destino' => 'TST002', 'tipo' => 'SOBRE', 'horas' => 4, 'desde' => '2020-01-01', 'hasta' => null],
        'R2' => ['origen' => 'TST001', 'destino' => 'TST002', 'tipo' => 'PAQUETE', 'horas' => 5, 'desde' => '2020-01-01', 'hasta' => null],
        'R3' => ['origen' => 'TST002', 'destino' => 'TST003', 'tipo' => 'CARGA', 'horas' => 18, 'desde' => '2020-01-01', 'hasta' => null],
        'R4' => ['origen' => 'TST001', 'destino' => 'TST002', 'tipo' => 'SOBRE', 'horas' => 99, 'desde' => '2019-01-01', 'hasta' => '2019-12-31'],
    ];

    $envios = [];
    $nuevo = static function (string $id, string $o, string $d, string $registro, string $estado, array $bultos) use (&$envios): void {
        $envios[$id] = ['id' => $id, 'origen' => $o, 'destino' => $d, 'registro' => $registro, 'estado' => $estado,
            'entrega' => $estado === 'ENTREGADO' ? date('Y-m-d H:i:s', strtotime($registro) + 86400) : null, 'bultos' => $bultos];
    };
    // Mes M: ocho envíos en todos los estados (ANULADO y DEVUELTO no cuentan).
    $nuevo('E01', 'TST001', 'TST002', $en($m, 2, '09:00:00'), 'ENTREGADO', [['R1', 200.0], ['R2', 100.0]]);
    $nuevo('E02', 'TST002', 'TST003', $en($m, 2, '09:10:00'), 'ENTREGADO', [['R3', 150.0]]);
    $nuevo('E03', 'TST001', 'TST002', $en($m, 3, '09:00:00'), 'EN_TRANSITO', [['R1', 100.0]]);
    $nuevo('E04', 'TST002', 'TST003', $en($m, 3, '09:10:00'), 'EN_TRANSITO', [['R3', 100.0]]);
    $nuevo('E05', 'TST001', 'TST003', $en($m, 4, '09:00:00'), 'EN_DESTINO', [['R2', 60.0]]);
    $nuevo('E06', 'TST001', 'TST002', $en($m, 5, '09:00:00'), 'REGISTRADO', [['R1', 10.0]]);
    $nuevo('E07', 'TST001', 'TST002', $en($m, 6, '09:00:00'), 'ANULADO', [['R1', 5.0]]);
    $nuevo('E08', 'TST002', 'TST001', $en($m, 7, '09:00:00'), 'DEVUELTO', [['R1', 5.0]]);
    // Mes M-1: base de la variación.
    $nuevo('F1', 'TST001', 'TST002', $en($m1, 2, '09:00:00'), 'ENTREGADO', [['R1', 100.0]]);
    $nuevo('F2', 'TST001', 'TST002', $en($m1, 3, '09:00:00'), 'ENTREGADO', [['R1', 20.0]]);
    $nuevo('F3', 'TST001', 'TST002', $en($m1, 4, '09:00:00'), 'ENTREGADO', [['R1', 20.0]]);
    $nuevo('F4', 'TST001', 'TST002', $en($m1, 5, '09:00:00'), 'ANULADO', [['R1', 5.0]]);
    // Cruce de año: diciembre de Y-1 y enero de Y.
    $nuevo('D1', 'TST001', 'TST002', $en($dic, 10, '09:00:00'), 'ENTREGADO', [['R2', 70.0]]);
    $nuevo('D2', 'TST001', 'TST002', $en($dic, 10, '09:10:00'), 'ENTREGADO', [['R1', 10.0]]);
    $nuevo('J1', 'TST002', 'TST003', $en($ene, 10, '09:00:00'), 'ENTREGADO', [['R3', 50.0]]);
    $nuevo('J2', 'TST001', 'TST002', $en($ene, 10, '09:10:00'), 'ENTREGADO', [['R1', 10.0]]);
    $nuevo('J3', 'TST001', 'TST002', $en($ene, 10, '09:20:00'), 'ENTREGADO', [['R1', 10.0]]);
    // Mes actual: dos envíos recientes (nunca «más de 3 días»).
    $nuevo('C1', 'TST001', 'TST002', $fmt($reciente), 'EN_TRANSITO', [['R1', 10.0]]);
    $nuevo('C2', 'TST002', 'TST003', $fmt($reciente), 'REGISTRADO', [['R3', 10.0]]);

    // Pagos: la sucursal del pago es independiente de origen/destino (P4: E01 pagado en TST003). P3 anulado. P7 en el último segundo de M; P8 en el primero del mes actual.
    $pagos = [
        ['envio' => 'E01', 'sucursal' => 'TST001', 'fecha' => $en($m, 5, '12:00:00'), 'monto' => '100.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'E02', 'sucursal' => 'TST002', 'fecha' => $en($m, 5, '12:10:00'), 'monto' => '50.50', 'estado' => 'REGISTRADO'],
        ['envio' => 'E03', 'sucursal' => 'TST001', 'fecha' => $en($m, 5, '12:20:00'), 'monto' => '70.00', 'estado' => 'ANULADO'],
        ['envio' => 'E01', 'sucursal' => 'TST003', 'fecha' => $en($m, 6, '12:00:00'), 'monto' => '20.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'F1', 'sucursal' => 'TST001', 'fecha' => $en($m1, 5, '12:00:00'), 'monto' => '80.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'F2', 'sucursal' => 'TST002', 'fecha' => $en($m1, 5, '12:10:00'), 'monto' => '40.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'E06', 'sucursal' => 'TST001', 'fecha' => $fmt($mesActual->modify('-1 second')), 'monto' => '10.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'C1', 'sucursal' => 'TST001', 'fecha' => $fmt($mesActual), 'monto' => '5.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'D1', 'sucursal' => 'TST001', 'fecha' => $en($dic, 10, '12:00:00'), 'monto' => '100.00', 'estado' => 'REGISTRADO'],
        ['envio' => 'J1', 'sucursal' => 'TST001', 'fecha' => $en($ene, 10, '12:00:00'), 'monto' => '150.00', 'estado' => 'REGISTRADO'],
    ];

    // Viajes: V01-V03 terminados en M (E03 viaja en V01 y en V03: transbordo), V04 en M-1, V05 en enero de Y y V06 en diciembre de Y-1 (cruce de año),
    // V07/V08/V11 en ruta (salieron ayer; ETA +0, +1 y sin ruta) y V09/V10 programados.
    $viajes = [
        'V01' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => $en($m, 3, '08:00:00'), 'llegada' => $en($m, 3, '12:00:00'), 'carga' => ['E01', 'E03']],
        'V02' => ['vehiculo' => 'TST-002', 'trans' => 'pm11_trans2', 'origen' => 'TST002', 'destino' => 'TST003', 'salida' => $en($m, 4, '06:00:00'), 'llegada' => $en($m, 5, '00:30:00'), 'carga' => ['E02', 'E04']],
        'V03' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => $en($m, 8, '09:00:00'), 'llegada' => $en($m, 8, '13:00:00'), 'carga' => ['E05', 'E03']],
        'V04' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => $en($m1, 6, '08:00:00'), 'llegada' => $en($m1, 6, '12:00:00'), 'carga' => ['F1']],
        'V05' => ['vehiculo' => 'TST-002', 'trans' => 'pm11_trans2', 'origen' => 'TST002', 'destino' => 'TST003', 'salida' => $en($ene, 15, '07:00:00'), 'llegada' => $en($ene, 15, '20:00:00'), 'carga' => ['J1']],
        'V06' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => $en($dic, 15, '07:00:00'), 'llegada' => $en($dic, 15, '11:00:00'), 'carga' => ['D1']],
        'V07' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => "$ayer 10:00:00", 'llegada' => null, 'carga' => []],
        'V08' => ['vehiculo' => 'TST-002', 'trans' => 'pm11_trans2', 'origen' => 'TST002', 'destino' => 'TST003', 'salida' => "$ayer 20:00:00", 'llegada' => null, 'carga' => []],
        'V09' => ['vehiculo' => 'TST-001', 'trans' => 'pm11_trans1', 'origen' => 'TST001', 'destino' => 'TST002', 'salida' => null, 'llegada' => null, 'carga' => []],
        'V10' => ['vehiculo' => 'TST-002', 'trans' => 'pm11_trans2', 'origen' => 'TST003', 'destino' => 'TST001', 'salida' => null, 'llegada' => null, 'carga' => []],
        'V11' => ['vehiculo' => 'TST-002', 'trans' => 'pm11_trans2', 'origen' => 'TST003', 'destino' => 'TST001', 'salida' => "$ayer 09:00:00", 'llegada' => null, 'carga' => []],
    ];

    return [
        'meta' => ['ahora' => $fmt($ahora), 'zona' => $zona->getName(), 'mes' => $m->format('Y-m'), 'mesAnterior' => $m1->format('Y-m'), 'mesActual' => $mesActual->format('Y-m'),
            'anioCruce' => $y, 'ayer' => $ayer],
        'sucursales' => $sucursales, 'personas' => $personas, 'usuarios' => $usuarios, 'vehiculos' => $vehiculos, 'rutas' => $rutas,
        'envios' => $envios, 'pagos' => $pagos, 'viajes' => $viajes,
    ];
}

/** Filas esperadas por tabla tras cargar la semilla (control de conteos). @param array<string, mixed> $s @return array<string, int> */
function pm11Conteos(array $s): array
{
    $bultos = 0;
    $cargas = 0;
    foreach ($s['envios'] as $e) {
        $bultos += count($e['bultos']);
    }
    foreach ($s['viajes'] as $v) {
        $cargas += count($v['carga']);
    }

    return ['Persona' => count($s['personas']), 'Sucursal' => count($s['sucursales']), 'Telefono_Sucursal' => 0, 'Usuario' => count($s['usuarios']), 'Ruta' => count($s['rutas']),
        'Vehiculo' => count($s['vehiculos']), 'Reserva' => count($s['envios']), 'Envio' => count($s['envios']), 'Bulto' => $bultos, 'Viaje' => count($s['viajes']),
        'Envio_Viaje' => $cargas, 'Pago' => count($s['pagos'])];
}

/** Peso (kg) de un envío y su tipo de bulto. @param array<string, mixed> $s */
function pm11PesoEnvio(array $s, string $idEnvio): float
{
    return array_sum(array_map(static fn (array $b): float => $b[1], $s['envios'][$idEnvio]['bultos']));
}

/** @param array<string, mixed> $s @return array<string, string> codigo => nombre de persona del transportista */
function pm11NombreTransportista(array $s, string $usuario): string
{
    foreach ($s['usuarios'] as $u) {
        if ($u['usuario'] === $usuario) {
            foreach ($s['personas'] as $p) {
                if ($p['doc'] === $u['doc']) {
                    return $p['nombre'];
                }
            }
        }
    }

    return '';
}

function pm11NombreSucursal(array $s, string $codigo): string
{
    foreach ($s['sucursales'] as $x) {
        if ($x['codigo'] === $codigo) {
            return $x['nombre'];
        }
    }

    return '';
}

final class RepoResumenOraculo implements RepositorioResumen
{
    /** @param array<string, mixed> $s */
    public function __construct(private readonly array $s)
    {
    }

    public function enviosDelPeriodo(string $desde, string $hasta, ?string $sucursal, string $limiteAntiguedad): array
    {
        $porEstado = [];
        $antiguos = 0;
        foreach ($this->s['envios'] as $e) {
            if ($e['registro'] < $desde || $e['registro'] >= $hasta || ($sucursal !== null && $sucursal !== $e['origen'] && $sucursal !== $e['destino'])) {
                continue;
            }
            $porEstado[$e['estado']] = ($porEstado[$e['estado']] ?? 0) + 1;
            if (in_array($e['estado'], ['EN_TRANSITO', 'EN_DESTINO'], true) && $e['registro'] < $limiteAntiguedad) {
                $antiguos++;
            }
        }

        return ['porEstado' => $porEstado, 'antiguos' => $antiguos];
    }

    public function cobradoDelPeriodo(string $desde, string $hasta, ?string $sucursal): string
    {
        $centavos = 0;
        foreach ($this->s['pagos'] as $p) {
            if ($p['estado'] === 'REGISTRADO' && $p['fecha'] >= $desde && $p['fecha'] < $hasta && ($sucursal === null || $sucursal === $p['sucursal'])) {
                $centavos += (int) round((float) $p['monto'] * 100);
            }
        }

        return number_format($centavos / 100, 2, '.', '');
    }

    public function viajesAbiertos(?string $sucursal): array
    {
        $ruta = 0;
        $programados = 0;
        foreach ($this->s['viajes'] as $v) {
            if ($v['llegada'] !== null || ($sucursal !== null && $sucursal !== $v['origen'] && $sucursal !== $v['destino'])) {
                continue;
            }
            $v['salida'] === null ? $programados++ : $ruta++;
        }

        return ['enRuta' => $ruta, 'programados' => $programados];
    }
}

final class RepoFlotaOraculo implements RepositorioFlota
{
    /** @param array<string, mixed> $s */
    public function __construct(private readonly array $s)
    {
    }

    public function viajesAbiertos(array $filtros): array
    {
        $ruta = 0;
        $programados = 0;
        foreach ($this->s['viajes'] as $v) {
            if ($v['llegada'] === null && $this->coincide($v, $filtros)) {
                $v['salida'] === null ? $programados++ : $ruta++;
            }
        }

        return ['enRuta' => $ruta, 'programados' => $programados];
    }

    public function terminados(array $filtros, string $desde, string $hasta): array
    {
        $n = 0;
        $peso = 0.0;
        $usos = [];
        foreach ($this->terminadosDelPeriodo($filtros, $desde, $hasta) as $v) {
            $p = $this->pesoViaje($v);
            $n++;
            $peso += $p;
            $usos[] = $p / $this->capacidad($v['vehiculo']);
        }

        return ['viajes' => $n, 'pesoKg' => number_format($peso, 2, '.', ''), 'usoMedio' => $usos === [] ? null : array_sum($usos) / count($usos)];
    }

    public function transbordos(array $filtros, string $desde, string $hasta): array
    {
        $envios = [];
        foreach ($this->terminadosDelPeriodo($filtros, $desde, $hasta) as $v) {
            foreach ($v['carga'] as $e) {
                $envios[$e] = true;
            }
        }
        $con = 0;
        foreach (array_keys($envios) as $e) {
            $cuantos = 0;
            foreach ($this->s['viajes'] as $v) {
                $cuantos += in_array($e, $v['carga'], true) ? 1 : 0;
            }
            $con += $cuantos > 1 ? 1 : 0;
        }

        return ['envios' => count($envios), 'conTransbordo' => $con];
    }

    public function viajesEnRuta(array $filtros, string $hoy): array
    {
        $filas = [];
        foreach ($this->s['viajes'] as $id => $v) {
            if ($v['salida'] === null || $v['llegada'] !== null || !$this->coincide($v, $filtros)) {
                continue;
            }
            $horas = null;
            foreach ($this->s['rutas'] as $r) {
                if ($r['origen'] === $v['origen'] && $r['destino'] === $v['destino'] && $r['desde'] <= $hoy && ($r['hasta'] === null || $r['hasta'] >= $hoy)) {
                    $horas = max($horas ?? 0, $r['horas']);
                }
            }
            $filas[] = ['orden' => $v['salida'] . '#' . $id, 'codigo' => self::codigoViaje($id),
                'origen' => ['codigo' => $v['origen'], 'nombre' => pm11NombreSucursal($this->s, $v['origen'])],
                'destino' => ['codigo' => $v['destino'], 'nombre' => pm11NombreSucursal($this->s, $v['destino'])],
                'placa' => $v['vehiculo'], 'transportista' => pm11NombreTransportista($this->s, $v['trans']), 'salida' => $v['salida'], 'horas' => $horas];
        }
        usort($filas, static fn (array $a, array $b): int => strcmp($a['orden'], $b['orden']));

        return array_map(static function (array $f): array {
            unset($f['orden']);

            return $f;
        }, $filas);
    }

    public function transportistas(array $filtros, string $desde, string $hasta): array
    {
        $grupos = [];
        foreach ($this->terminadosDelPeriodo($filtros, $desde, $hasta) as $v) {
            $g = &$grupos[$v['trans']];
            $g['viajes'] = ($g['viajes'] ?? 0) + 1;
            $g['peso'] = ($g['peso'] ?? 0.0) + $this->pesoViaje($v);
            unset($g);
        }
        $filas = [];
        foreach ($grupos as $usuario => $g) {
            $sucursal = null;
            foreach ($this->s['usuarios'] as $u) {
                $sucursal = $u['usuario'] === $usuario ? $u['sucursal'] : $sucursal;
            }
            $filas[] = ['transportista' => pm11NombreTransportista($this->s, $usuario), 'sucursalCodigo' => $sucursal, 'viajes' => $g['viajes'], 'pesoKg' => number_format($g['peso'], 2, '.', '')];
        }
        usort($filas, static fn (array $a, array $b): int => [$b['viajes'], $a['transportista']] <=> [$a['viajes'], $b['transportista']]);

        return $filas;
    }

    /** Código del viaje: VJ + 10 dígitos (V01 → VJ0990000001). */
    public static function codigoViaje(string $id): string
    {
        return 'VJ' . sprintf('%010d', 990000000 + (int) substr($id, 1));
    }

    /** @param array<string, mixed> $v @param array<string, ?string> $f */
    private function coincide(array $v, array $f): bool
    {
        if (($f['sucursal'] ?? null) !== null && $f['sucursal'] !== $v['origen'] && $f['sucursal'] !== $v['destino']) {
            return false;
        }
        if (($f['placa'] ?? null) !== null && $f['placa'] !== $v['vehiculo']) {
            return false;
        }
        if (($f['transportista'] ?? null) !== null && $f['transportista'] !== $v['trans']) {
            return false;
        }
        if (($f['tipoEnvio'] ?? null) !== null) {
            foreach ($v['carga'] as $e) {
                foreach ($this->s['envios'][$e]['bultos'] as [$ruta]) {
                    if ($this->s['rutas'][$ruta]['tipo'] === $f['tipoEnvio']) {
                        return true;
                    }
                }
            }

            return false;
        }

        return true;
    }

    /** @param array<string, ?string> $f @return list<array<string, mixed>> */
    private function terminadosDelPeriodo(array $f, string $desde, string $hasta): array
    {
        return array_values(array_filter($this->s['viajes'], fn (array $v): bool => $v['llegada'] !== null && $v['salida'] >= $desde && $v['salida'] < $hasta && $this->coincide($v, $f)));
    }

    /** @param array<string, mixed> $v */
    private function pesoViaje(array $v): float
    {
        return array_sum(array_map(fn (string $e): float => pm11PesoEnvio($this->s, $e), $v['carga']));
    }

    private function capacidad(string $placa): float
    {
        foreach ($this->s['vehiculos'] as $x) {
            if ($x['placa'] === $placa) {
                return $x['capacidadKg'];
            }
        }

        return 1.0;
    }
}
