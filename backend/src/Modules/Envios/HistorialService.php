<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use DateTimeImmutable;
use Jatsa\Modules\Reservas\RepositorioReservas;
use Jatsa\Modules\Reservas\ReservaRepository;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Tarifas;

/**
 * Historial de envíos del CLIENTE y del EMPRESARIO (diapositivas 14 y 11): los envíos que la persona de la sesión envió o recibió,
 * su detalle y el resumen de «Mi panel». Un envío ajeno o inexistente es el mismo 404. De la otra parte solo se devuelve el nombre:
 * nunca documentos ni teléfonos (regla 6 de CLAUDE.md).
 */
final class HistorialService
{
    public const ESTADOS = ['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO', 'ENTREGADO', 'DEVUELTO', 'ANULADO'];
    public const ROLES = ['ENVIE', 'RECIBI'];
    public const MAX_LISTA = 200;
    public const MAX_BUSQUEDA = 50;
    public const MAX_POR_RECOGER = 50;

    private readonly RepositorioHistorial $historial;
    private readonly RepositorioReservas $reservas;

    public function __construct(?RepositorioHistorial $historial = null, ?RepositorioReservas $reservas = null)
    {
        $this->historial = $historial ?? new HistorialRepository();
        $this->reservas = $reservas ?? new ReservaRepository();
    }

    /**
     * @param array<string, mixed> $usuario
     * @param array<string, mixed> $consulta q, rol, estado, desde, hasta
     * @return array{envios: list<array<string, mixed>>, hayMas: bool}
     */
    public function listar(array $usuario, array $consulta): array
    {
        $idPersona = $this->personaDe($usuario);
        $filtros = ['q' => self::texto($consulta, 'q'), 'rol' => strtoupper(self::texto($consulta, 'rol')), 'estado' => strtoupper(self::texto($consulta, 'estado')),
            'desde' => self::texto($consulta, 'desde'), 'hasta' => self::texto($consulta, 'hasta')];

        $errores = [];
        if (preg_match('/^.{0,' . self::MAX_BUSQUEDA . '}$/su', $filtros['q']) !== 1) {
            $errores['q'] = sprintf('La búsqueda admite hasta %d caracteres.', self::MAX_BUSQUEDA);
        }
        if ($filtros['rol'] !== '' && !in_array($filtros['rol'], self::ROLES, true)) {
            $errores['rol'] = 'Elige ENVIE o RECIBI.';
        }
        if ($filtros['estado'] !== '' && !in_array($filtros['estado'], self::ESTADOS, true)) {
            $errores['estado'] = 'Elige un estado válido.';
        }
        foreach (['desde', 'hasta'] as $campo) {
            if ($filtros[$campo] !== '' && !self::esFecha($filtros[$campo])) {
                $errores[$campo] = 'Usa el formato AAAA-MM-DD.';
            }
        }
        if ($errores === [] && $filtros['desde'] !== '' && $filtros['hasta'] !== '' && $filtros['desde'] > $filtros['hasta']) {
            $errores['hasta'] = 'La fecha final no puede ser anterior a la inicial.';
        }
        if ($errores !== []) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa los filtros.', [], $errores);
        }

        $filas = $this->historial->listar($idPersona, $filtros, self::MAX_LISTA + 1);

        return [
            'envios' => array_map(static fn (array $f): array => [
                'codigoSeguimiento' => $f['codigoSeguimiento'], 'estado' => $f['estado'], 'rol' => $f['rol'], 'fecha' => substr($f['fechaHoraRegistro'], 0, 10),
                'otraParte' => $f['otraParte'], 'importe' => Tarifas::formatoSoles(self::centimos($f['importe'])),
            ], array_slice($filas, 0, self::MAX_LISTA)),
            'hayMas' => count($filas) > self::MAX_LISTA,
        ];
    }

    /**
     * Un envío de la persona con su detalle (diapositiva 14).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function detalle(array $usuario, string $codigoSeguimiento): array
    {
        $idPersona = $this->personaDe($usuario);
        $codigo = strtoupper(trim($codigoSeguimiento));
        $e = preg_match('/^EN[A-Z0-9]{10}$/', $codigo) === 1 ? $this->historial->detalle($idPersona, $codigo) : null;
        if ($e === null) {
            throw new HttpException(404, 'envio_no_encontrado', 'No se encontró el envío.');
        }

        $importe = self::centimos($e['importe']);
        $pagado = self::centimos($e['pagado']);
        $peso = 0;
        $bultos = [];
        foreach ($e['bultos'] as $b) {
            $centesimas = self::centimos($b['pesoKg']);
            $peso += $centesimas;
            $bultos[] = ['numero' => $b['numero'], 'descripcion' => $b['descripcion'], 'pesoKg' => self::kilos($centesimas), 'tipo' => $b['tipo'], 'costo' => Tarifas::formatoSoles(self::centimos($b['costo']))];
        }

        return [
            'codigoSeguimiento' => $e['codigoSeguimiento'],
            'estado' => $e['estado'],
            'rol' => $e['rol'],
            'otraParte' => $e['otraParte'],
            'fechaHoraRegistro' => $e['fechaHoraRegistro'],
            'fechaHoraEntrega' => $e['fechaHoraEntrega'],
            'origen' => $e['origen'],
            'destino' => $e['destino'],
            'viaje' => $e['ultimoViaje'],
            'bultos' => $bultos,
            'cantidadBultos' => count($bultos),
            'pesoTotalKg' => self::kilos($peso),
            'importe' => Tarifas::formatoSoles($importe),
            'pagado' => Tarifas::formatoSoles($pagado),
            'saldo' => Tarifas::formatoSoles(max(0, $importe - $pagado)),
            'pagos' => array_map(static fn (array $p): array => ['monto' => Tarifas::formatoSoles(self::centimos($p['monto'])), 'metodoPago' => $p['metodoPago'], 'estado' => $p['estado'], 'sucursal' => $p['sucursalCodigo'], 'fechaHora' => $p['fechaHora']], $e['pagos']),
        ];
    }

    /**
     * Resumen de «Mi panel»: envíos en curso, saldo pendiente de esos envíos y los envíos listos para recoger.
     *
     * `enCurso` y `saldoPendiente` cubren TODOS los envíos en curso de la persona (como remitente o como destinataria), calculados en
     * la base de datos sin tope. Solo la lista `porRecoger` se acota (`MAX_POR_RECOGER`) y avisa con `hayMasPorRecoger`.
     *
     * Es el saldo pendiente de los ENVÍOS, no una deuda personal: el sistema no sabe quién pagará un envío hasta que se cobra
     * (`Pago.pagadoPor`), así que no lo reparte entre remitente y destinatario.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function resumen(array $usuario): array
    {
        $r = $this->historial->resumenEnCurso($this->personaDe($usuario), self::MAX_POR_RECOGER + 1);

        $porRecoger = array_map(
            static fn (array $f): array => ['codigoSeguimiento' => $f['codigoSeguimiento'], 'estado' => $f['estado'], 'de' => $f['otraParte'],
                'agencia' => ['nombre' => $f['destino']['nombre'], 'direccion' => $f['destino']['direccion']]],
            array_slice($r['porRecoger'], 0, self::MAX_POR_RECOGER),
        );

        return [
            'enCurso' => $r['enCurso'],
            'saldoPendiente' => Tarifas::formatoSoles(max(0, self::centimos($r['saldo']))),
            'porRecoger' => $porRecoger,
            'hayMasPorRecoger' => count($r['porRecoger']) > self::MAX_POR_RECOGER,
        ];
    }
    // ── Utilidades ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $usuario */
    private function personaDe(array $usuario): int
    {
        return $this->reservas->idPersonaDeUsuario((int) ($usuario['id'] ?? 0)) ?? throw new HttpException(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.');
    }

    private static function esFecha(string $texto): bool
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $fecha !== false && $fecha->format('Y-m-d') === $texto;
    }

    private static function centimos(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private static function kilos(int $centesimas): string
    {
        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }

    /** @param array<string, mixed> $datos */
    private static function texto(array $datos, string $clave): string
    {
        $valor = $datos[$clave] ?? '';

        return is_string($valor) ? trim($valor) : '';
    }
}
