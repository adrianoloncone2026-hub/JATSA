<?php
declare(strict_types=1);

namespace Jatsa\Modules\Viajes;

/**
 * Acceso a Viaje (con su vehículo, transportista, sucursales y carga en Envio_Viaje).
 *
 * Un viaje es un arreglo con: idViaje, codigo, idUsuarioTransportista, idSucursalOrigen, idSucursalDestino (int),
 * origen y destino {codigo, nombre}, vehiculo {placa, tipo, capacidadKg}, transportista (nombre completo),
 * fechaHoraSalida y fechaHoraLlegada (?string), cantidadEnvios (int), pesoKg (cadena con 2 decimales),
 * horas (?int: tiempo estimado de la ruta directa vigente) y, solo en buscarPorCodigo, carga: lista de
 * {codigoSeguimiento, bultos, pesoKg, destinoFinal{codigo, nombre}, cargadoEn}.
 * El estado (PROGRAMADO, EN_RUTA, TERMINADO) NO se guarda: se deduce de las fechas (informe de BD).
 */
interface RepositorioViajes
{
    /**
     * Viajes del transportista, en el orden de la diapositiva 24: en ruta, programados y terminados.
     *
     * @param string|null $estado PROGRAMADO, EN_RUTA o TERMINADO (null = todos).
     * @return list<array<string, mixed>>
     */
    public function listarDeTransportista(int $idUsuario, ?string $estado, int $limite, string $hoy): array;

    /**
     * Viajes que salen de la sucursal: todos los programados y en ruta, y los $limiteTerminados terminados más recientes
     * (se piden uno más para saber si hay más).
     *
     * @return list<array<string, mixed>>
     */
    public function listarDeSucursalOrigen(int $idSucursal, int $limiteTerminados, string $hoy): array;

    /** @return array<string, mixed>|null Un viaje con su carga. */
    public function buscarPorCodigo(string $codigoViaje, string $hoy): ?array;

    /**
     * Marca la salida en UNA transacción: bloquea el viaje, su vehículo y su transportista (siempre en ese orden), lee si
     * el vehículo o el transportista ya tienen otro viaje en ruta y llama a $decidir, que aplica las reglas y lanza
     * HttpException si no procede (todo se
     * deshace). Después fija la salida con la hora de la base y pasa a EN_TRANSITO los envíos cargados que seguían
     * REGISTRADO.
     *
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => fila del viaje bloqueado, 'vehiculoOcupado' => bool, 'transportistaOcupado' => bool].
     * @return bool|null null si el viaje no existe; false si ya no se pudo (otra operación se adelantó).
     */
    public function marcarSalida(string $codigoViaje, callable $decidir): ?bool;

    /**
     * Marca la llegada en UNA transacción: bloquea el viaje, llama a $decidir y fija la llegada con la hora de la base
     * (estrictamente posterior a la salida, como exige CK_VIAJE_FECHAS). Los envíos cargados cuyo destino final es la
     * sucursal de llegada pasan a EN_DESTINO; los demás siguen EN_TRANSITO (esperan un transbordo).
     *
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => fila del viaje bloqueado].
     * @return bool|null null si el viaje no existe; false si todavía no pasó un segundo desde la salida.
     */
    public function marcarLlegada(string $codigoViaje, callable $decidir): ?bool;
}
