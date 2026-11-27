<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use DateTimeInterface;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/** Exportaciones Excel (.xlsx) de Reportes y plantillas: «Gestión Gerencial» y «Transporte y Logística». */
final class ExportacionesService
{
    private readonly RepositorioExportaciones $datos;

    private readonly string $dirPlantillas;

    public function __construct(?RepositorioExportaciones $datos = null, ?string $dirPlantillas = null)
    {
        $this->datos = $datos ?? new ExportacionesRepository();
        $this->dirPlantillas = $dirPlantillas ?? dirname(__DIR__, 3) . '/storage/plantillas';
    }

    /**
     * Paquete Power BI: ZIP con exactamente tres archivos en la raíz (.pbix, .xlsx con datos actuales y manual .pdf).
     * El .xlsx sale de los exportadores de siempre (mismo ámbito y permisos); .pbix y .pdf, de storage/plantillas (fuera de public).
     *
     * @param 'gerencia'|'logistica' $tipo @param array<string, mixed> $usuario @param array<string, mixed> $consulta @return array{nombre: string, contenido: string}
     */
    public function paquetePowerBi(string $tipo, array $usuario, array $consulta): array
    {
        $excel = $tipo === 'gerencia' ? $this->gerencia($usuario, $consulta) : $this->logistica($usuario, $consulta);
        $base = $tipo === 'gerencia' ? 'Gerencia' : 'Logistica';
        $zip = new EscritorZip();
        $zip->agregar("JATSA_{$base}.pbix", $this->plantilla("JATSA_{$base}.pbix"));
        $zip->agregar($excel['nombre'], $excel['contenido']);
        $zip->agregar("Manual_{$base}.pdf", $this->plantilla("Manual_{$base}.pdf"));

        return ['nombre' => "JATSA_{$base}.zip", 'contenido' => $zip->contenido()];
    }

    private function plantilla(string $nombre): string
    {
        $contenido = @file_get_contents($this->dirPlantillas . '/' . $nombre);
        if ($contenido === false || $contenido === '') {
            throw new HttpException(500, 'plantilla_no_disponible', 'La plantilla de Power BI no está disponible en el servidor.');
        }

        return $contenido;
    }

    /** @param array<string, mixed> $usuario @param array<string, mixed> $consulta @return array{nombre: string, contenido: string} */
    public function gerencia(array $usuario, array $consulta): array
    {
        $sucursal = $this->ambito($usuario, $consulta);
        $envios = [];
        foreach ($this->datos->enviosResumen($sucursal) as $e) {
            $importe = round((float) $e['importeEnvio'], 2);
            $cobrado = round((float) $e['cobradoRegistrado'], 2);
            $fecha = self::fechaHora($e['fechaHoraRegistro']);
            $envios[] = [(int) $e['idEnvio'], (string) $e['codigoSeguimiento'], $fecha, $fecha, (int) $e['idSucursalOrigen'], (int) $e['idSucursalDestino'],
                (string) $e['estadoEnvio'], (int) $e['cantidadBultos'], $importe, $cobrado, round($importe - $cobrado, 2)];
        }
        $pagos = [];
        foreach ($this->datos->pagos($sucursal) as $p) {
            $fecha = self::fechaHora($p['fechaHoraPago']);
            $pagos[] = [(int) $p['idPago'], (int) $p['idEnvio'], $fecha, $fecha, (int) $p['idSucursal'], round((float) $p['monto'], 2),
                (string) $p['metodoPago'], (string) $p['pagadoPor'], (string) $p['estadoPago']];
        }

        return $this->libro('JATSA_Gerencia.xlsx', [
            ['nombre' => 'EnviosResumen', 'columnas' => [['idEnvio', 'entero'], ['codigoSeguimiento', 'texto'], ['fechaHoraRegistro', 'fechahora'], ['FechaRegistro', 'fecha'],
                ['idSucursalOrigen', 'entero'], ['idSucursalDestino', 'entero'], ['estadoEnvio', 'texto'], ['cantidadBultos', 'entero'],
                ['importeEnvio', 'dinero'], ['cobradoRegistrado', 'dinero'], ['saldoActual', 'dinero']], 'filas' => $envios],
            ['nombre' => 'Pagos', 'columnas' => [['idPago', 'entero'], ['idEnvio', 'entero'], ['fechaHoraPago', 'fechahora'], ['FechaPago', 'fecha'],
                ['idSucursalCobro', 'entero'], ['monto', 'dinero'], ['metodoPago', 'texto'], ['pagadoPor', 'texto'], ['estadoPago', 'texto']], 'filas' => $pagos],
            $this->hojaSucursales(),
        ]);
    }

    /** @param array<string, mixed> $usuario @param array<string, mixed> $consulta @return array{nombre: string, contenido: string} */
    public function logistica(array $usuario, array $consulta): array
    {
        $sucursal = $this->ambito($usuario, $consulta);
        $viajes = [];
        foreach ($this->datos->viajes($sucursal) as $v) {
            $salida = self::fechaHora($v['fechaHoraSalida']);
            $llegada = self::fechaHora($v['fechaHoraLlegada']);
            $estado = $salida === null ? 'PROGRAMADO' : ($llegada === null ? 'EN_RUTA' : 'TERMINADO');
            $viajes[] = [(int) $v['idViaje'], (string) $v['codigoViaje'], (int) $v['idSucursalOrigen'], (int) $v['idSucursalDestino'], $salida, $salida, $llegada, $llegada,
                $estado, (string) $v['placa'], (string) $v['nombreUsuario'], round((float) $v['capacidadKg'], 2)];
        }
        $cargas = [];
        foreach ($this->datos->envioViaje($sucursal) as $c) {
            $fecha = self::fechaHora($c['fechaHoraCarga']);
            $cargas[] = [(int) $c['idEnvioViaje'], (int) $c['idEnvio'], (int) $c['idViaje'], $fecha, $fecha];
        }
        $envios = [];
        foreach ($this->datos->enviosCarga($sucursal) as $e) {
            $fecha = self::fechaHora($e['fechaHoraRegistro']);
            $envios[] = [(int) $e['idEnvio'], (string) $e['codigoSeguimiento'], $fecha, $fecha, round((float) $e['pesoTotalKg'], 2), (string) $e['estadoEnvio']];
        }

        return $this->libro('JATSA_Logistica.xlsx', [
            ['nombre' => 'Viajes', 'columnas' => [['idViaje', 'entero'], ['codigoViaje', 'texto'], ['idSucursalOrigen', 'entero'], ['idSucursalDestino', 'entero'],
                ['fechaHoraSalida', 'fechahora'], ['FechaSalida', 'fecha'], ['fechaHoraLlegada', 'fechahora'], ['FechaLlegada', 'fecha'], ['estadoCalculado', 'texto'],
                ['placa', 'texto'], ['transportista', 'texto'], ['capacidadKg', 'peso']], 'filas' => $viajes],
            ['nombre' => 'EnvioViaje', 'columnas' => [['idEnvioViaje', 'entero'], ['idEnvio', 'entero'], ['idViaje', 'entero'], ['fechaHoraCarga', 'fechahora'], ['FechaCarga', 'fecha']], 'filas' => $cargas],
            ['nombre' => 'EnviosCarga', 'columnas' => [['idEnvio', 'entero'], ['codigoSeguimiento', 'texto'], ['fechaHoraRegistro', 'fechahora'], ['FechaRegistro', 'fecha'],
                ['pesoTotalKg', 'peso'], ['estadoEnvio', 'texto']], 'filas' => $envios],
            $this->hojaSucursales(),
        ]);
    }

    /** @return array{nombre: string, columnas: list<array{0: string, 1: string}>, filas: list<list<mixed>>} */
    private function hojaSucursales(): array
    {
        $filas = [];
        foreach ($this->datos->sucursales() as $s) {
            $filas[] = [(int) $s['idSucursal'], trim((string) $s['codigoSucursal']), (string) $s['nombre'], (string) $s['departamento'], (bool) (int) $s['activa']];
        }

        return ['nombre' => 'Sucursales', 'columnas' => [['idSucursal', 'entero'], ['codigoSucursal', 'texto'], ['nombre', 'texto'], ['departamento', 'texto'], ['activa', 'booleano']], 'filas' => $filas];
    }

    /** @param list<array<string, mixed>> $hojas @return array{nombre: string, contenido: string} */
    private function libro(string $nombre, array $hojas): array
    {
        return ['nombre' => $nombre, 'contenido' => (new LibroXlsx())->generar($hojas)];
    }

    /** @param array<string, mixed> $usuario @param array<string, mixed> $consulta Id de la sucursal que limita la exportación, o null = todas. */
    private function ambito(array $usuario, array $consulta): ?int
    {
        $rol = $usuario['rol'] ?? null;
        if ($rol === Roles::ADMINISTRADOR) {
            $id = $usuario['sucursal']['id'] ?? null;
            if ($id === null) {
                throw new HttpException(403, 'acceso_denegado', 'Tu cuenta no tiene una sucursal asignada.');
            }

            return (int) $id;
        }
        if ($rol !== Roles::GERENTE) {
            throw new HttpException(403, 'acceso_denegado', 'No tienes permiso para realizar esta acción.');
        }
        $errores = [];
        $codigo = Filtros::sucursal($consulta['sucursal'] ?? '', $errores);
        $id = null;
        if ($codigo !== null && $errores === []) {
            $id = $this->datos->idSucursalPorCodigo($codigo);
            if ($id === null) {
                $errores['sucursal'] = 'La sucursal no existe.';
            }
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los filtros.', [], $errores);
        }

        return $id;
    }

    private static function fechaHora(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? substr($valor, 0, 19) : null;
    }
}