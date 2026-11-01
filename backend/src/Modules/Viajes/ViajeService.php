<?php
declare(strict_types=1);

namespace Jatsa\Modules\Viajes;

use DateTimeImmutable;
use Closure;
use Jatsa\Modules\Reservas\VentanillaService;
use Jatsa\Shared\HttpException;
use Jatsa\Shared\Roles;

/**
 * Viajes del TRANSPORTISTA (diapositivas 24 y 25): lista de sus viajes, detalle con la carga, y registro de la salida y
 * la llegada. El ADMINISTRADOR también puede ver el detalle de los viajes que salen de su sucursal o llegan a ella
 * («Ver carga» de Despacho).
 *
 * El estado del viaje no se guarda: se deduce de las fechas (informe de BD). Sin salida = PROGRAMADO; con salida y sin
 * llegada = EN_RUTA; con llegada = TERMINADO. Las reglas se comprueban aquí con mensajes claros y VUELVEN A
 * COMPROBARSE dentro de la transacción de ViajeRepository, con el viaje (y su vehículo) bloqueados.
 */
final class ViajeService
{
    public const ESTADOS = ['PROGRAMADO', 'EN_RUTA', 'TERMINADO'];

    /** Máximo de viajes que devuelve «Mis viajes». */
    public const MAX_LISTA = 200;

    private readonly RepositorioViajes $viajes;
    private readonly Closure $hoy;

    /** @param (callable(): string)|null $hoy Fecha de hoy (AAAA-MM-DD) para elegir la ruta vigente; se inyecta para probar. */
    public function __construct(?RepositorioViajes $viajes = null, ?callable $hoy = null)
    {
        $this->viajes = $viajes ?? new ViajeRepository();
        $this->hoy = Closure::fromCallable($hoy ?? static fn (): string => date('Y-m-d'));
    }

    /**
     * «Mis viajes» del transportista de la sesión.
     *
     * @param array<string, mixed> $usuario
     * @return array{viajes: list<array<string, mixed>>, hayMas: bool}
     */
    public function misViajes(array $usuario, string $estado = ''): array
    {
        $estado = strtoupper(trim($estado));
        if ($estado !== '' && !in_array($estado, self::ESTADOS, true)) {
            throw new HttpException(422, 'datos_invalidos', 'Revisa el filtro.', [], ['estado' => 'Elige PROGRAMADO, EN_RUTA o TERMINADO.']);
        }

        $filas = $this->viajes->listarDeTransportista((int) $usuario['id'], $estado === '' ? null : $estado, self::MAX_LISTA + 1, ($this->hoy)());

        return [
            'viajes' => array_map(static fn (array $v): array => self::presentar($v), array_slice($filas, 0, self::MAX_LISTA)),
            'hayMas' => count($filas) > self::MAX_LISTA,
        ];
    }

    /**
     * Un viaje con su carga. El transportista solo ve los suyos; el administrador, los de su sucursal de origen o de
     * destino. Otro viaje o uno inexistente: el mismo 404.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function detalle(array $usuario, string $codigo): array
    {
        $viaje = $this->buscar($usuario, $codigo, true);

        return self::presentar($viaje, true);
    }

    /**
     * El transportista marca la salida de su viaje.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function marcarSalida(array $usuario, string $codigo): array
    {
        $this->buscar($usuario, $codigo, false); // 404 igual para ajeno e inexistente, antes de tocar nada
        $codigo = self::normalizar($codigo);

        $decidir = static function (array $contexto): void {
            $viaje = $contexto['viaje'];
            if ($viaje['fechaHoraSalida'] !== null) {
                throw new HttpException(409, 'viaje_ya_salio', 'El viaje ya tiene registrada su salida.');
            }
            if ($contexto['vehiculoOcupado']) {
                throw new HttpException(409, 'vehiculo_ocupado', 'El vehículo tiene otro viaje en ruta: debe terminar antes de que salga este.');
            }
            if ($contexto['transportistaOcupado']) {
                throw new HttpException(409, 'transportista_ocupado', 'Ya conduces otro viaje en ruta: marca su llegada antes de que salga este.');
            }
        };
        $hecho = $this->viajes->marcarSalida($codigo, $this->conPropietario($usuario, $decidir));
        if ($hecho === null) {
            throw self::noEncontrado();
        }
        if ($hecho === false) {
            throw new HttpException(409, 'viaje_ya_salio', 'El viaje ya tiene registrada su salida.');
        }

        return $this->detalle($usuario, $codigo);
    }

    /**
     * El transportista marca la llegada de su viaje.
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    public function marcarLlegada(array $usuario, string $codigo): array
    {
        $this->buscar($usuario, $codigo, false);
        $codigo = self::normalizar($codigo);

        $decidir = static function (array $contexto): void {
            $viaje = $contexto['viaje'];
            if ($viaje['fechaHoraSalida'] === null) {
                throw new HttpException(409, 'viaje_no_salio', 'El viaje todavía no salió: marca primero la salida.');
            }
            if ($viaje['fechaHoraLlegada'] !== null) {
                throw new HttpException(409, 'viaje_ya_llego', 'El viaje ya tiene registrada su llegada.');
            }
        };
        $hecho = $this->viajes->marcarLlegada($codigo, $this->conPropietario($usuario, $decidir));
        if ($hecho === null) {
            throw self::noEncontrado();
        }
        if ($hecho === false) {
            throw new HttpException(409, 'llegada_muy_pronto', 'La salida se registró hace un instante: espera un segundo y vuelve a marcar la llegada.');
        }

        return $this->detalle($usuario, $codigo);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * Viaje visible para el usuario de la sesión, o 404 (el mismo para ajeno e inexistente).
     *
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    private function buscar(array $usuario, string $codigo, bool $soloLectura): array
    {
        $codigo = self::normalizar($codigo);
        $viaje = preg_match('/^VJ\d{10}$/', $codigo) === 1 ? $this->viajes->buscarPorCodigo($codigo, ($this->hoy)()) : null;
        if ($viaje === null) {
            throw self::noEncontrado();
        }

        $rol = $usuario['rol'] ?? '';
        if ($rol === Roles::TRANSPORTISTA) {
            $visible = $viaje['idUsuarioTransportista'] === (int) $usuario['id'];
        } elseif ($rol === Roles::ADMINISTRADOR && $soloLectura) {
            $sucursal = VentanillaService::sucursalDe($usuario);
            $visible = in_array($sucursal['id'], [$viaje['idSucursalOrigen'], $viaje['idSucursalDestino']], true);
        } else {
            $visible = false;
        }
        if (!$visible) {
            throw self::noEncontrado();
        }

        return $viaje;
    }

    /**
     * Dentro de la transacción (viaje ya bloqueado) vuelve a comprobar que el viaje es del transportista de la sesión.
     *
     * @param array<string, mixed> $usuario
     */
    private function conPropietario(array $usuario, callable $reglas): callable
    {
        return static function (array $contexto) use ($usuario, $reglas): void {
            if ($contexto['viaje']['idUsuarioTransportista'] !== (int) $usuario['id']) {
                throw self::noEncontrado();
            }
            $reglas($contexto);
        };
    }

    private static function noEncontrado(): HttpException
    {
        return new HttpException(404, 'viaje_no_encontrado', 'No se encontró el viaje.');
    }

    private static function normalizar(string $codigo): string
    {
        return strtoupper(trim($codigo));
    }

    /** PROGRAMADO, EN_RUTA o TERMINADO, deducido de las fechas. */
    public static function estadoDe(?string $salida, ?string $llegada): string
    {
        if ($salida === null) {
            return 'PROGRAMADO';
        }

        return $llegada === null ? 'EN_RUTA' : 'TERMINADO';
    }

    /**
     * @param array<string, mixed> $viaje
     * @return array<string, mixed>
     */
    public static function presentar(array $viaje, bool $conCarga = false): array
    {
        $estado = self::estadoDe($viaje['fechaHoraSalida'], $viaje['fechaHoraLlegada']);
        $estimada = null;
        if ($estado === 'EN_RUTA' && $viaje['horas'] !== null) {
            $estimada = (new DateTimeImmutable((string) $viaje['fechaHoraSalida']))->modify('+' . (int) $viaje['horas'] . ' hours')->format('Y-m-d H:i:s');
        }

        $datos = [
            'codigo' => $viaje['codigo'],
            'estado' => $estado,
            'origen' => $viaje['origen'],
            'destino' => $viaje['destino'],
            'vehiculo' => ['placa' => $viaje['vehiculo']['placa'], 'tipo' => $viaje['vehiculo']['tipo'], 'capacidadKg' => self::kilos($viaje['vehiculo']['capacidadKg'])],
            'transportista' => $viaje['transportista'],
            'fechaHoraSalida' => $viaje['fechaHoraSalida'],
            'fechaHoraLlegada' => $viaje['fechaHoraLlegada'],
            'llegadaEstimada' => $estimada,
            'cantidadEnvios' => $viaje['cantidadEnvios'],
            'pesoCargadoKg' => self::kilos($viaje['pesoKg']),
        ];
        if ($conCarga) {
            $datos['carga'] = array_map(
                static fn (array $c): array => ['pesoKg' => self::kilos($c['pesoKg'])] + $c,
                $viaje['carga'] ?? [],
            );
        }

        return $datos;
    }

    /** "18.5000" o "6500" → "18.50" / "6500.00" */
    public static function kilos(string $decimal): string
    {
        $centesimas = (int) round(((float) $decimal) * 100);

        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }
}
