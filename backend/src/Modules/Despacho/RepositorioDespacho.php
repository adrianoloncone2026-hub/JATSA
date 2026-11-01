<?php
declare(strict_types=1);

namespace Jatsa\Modules\Despacho;

/**
 * Acceso a lo que el Administrador necesita para despachar: envíos en espera, vehículos y transportistas disponibles,
 * alta de viajes y carga de envíos en un viaje (Envio_Viaje).
 */
interface RepositorioDespacho
{
    /**
     * Envíos que esperan en la sucursal para salir: los recién registrados en su origen que aún no se cargaron en
     * ningún viaje y los que llegaron en tránsito (transbordo) y todavía no siguen. Más antiguo primero. Solo lectura.
     *
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, estado, bultos, pesoKg, idSucursalDestino, destino{codigo, nombre}.
     */
    public function enEsperaDeSucursal(int $idSucursal, int $limite): array;

    /**
     * Vehículos activos con su capacidad y si están ocupados (tienen un viaje en ruta).
     *
     * @return list<array{placa: string, tipo: string, capacidadKg: string, ocupado: bool}>
     */
    public function vehiculosActivos(): array;

    /**
     * Transportistas activos.
     *
     * @return list<array{nombreUsuario: string, nombreCompleto: string, sucursal: array{codigo: string, nombre: string}}>
     */
    public function transportistasActivos(): array;

    public function idVehiculoActivo(string $placa): ?int;

    /** Id de un usuario activo con rol TRANSPORTISTA. */
    public function idTransportistaActivo(string $nombreUsuario): ?int;

    public function idSucursalActiva(string $codigoSucursal): ?int;

    /**
     * Crea el viaje (sin salida ni llegada) y devuelve su código «VJ» + fecha de la base (AAAAMMDD) + número del día (01 a
     * 99), calculado dentro de la transacción con el rango de códigos del día bloqueado.
     */
    public function crearViaje(int $idVehiculo, int $idTransportista, int $idSucursalOrigen, int $idSucursalDestino): string;

    /**
     * Carga un envío en un viaje en UNA transacción: bloquea el viaje y luego el envío (siempre en ese orden), reúne lo
     * que hace falta para decidir y llama a $decidir, que aplica las reglas y lanza HttpException si no procede (todo se
     * deshace). Si procede, inserta Envio_Viaje (la fecha de carga la pone la base).
     *
     * @param callable(array<string, mixed>): void $decidir Recibe:
     *        viaje {idViaje, idSucursalOrigen, idSucursalDestino, fechaHoraSalida, capacidadKg, pesoCargadoKg},
     *        envio null o {idEnvio, estado, idSucursalOrigen, idSucursalDestino, pesoKg},
     *        ultimoViaje null o {idSucursalOrigen, idSucursalDestino, fechaHoraLlegada} (el último en el que se cargó el envío),
     *        visitadas list<int> (destinos de los viajes anteriores del envío).
     * @return bool|null null si el viaje no existe.
     */
    public function cargar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool;

    /**
     * Quita un envío de un viaje que aún no salió (DELETE de la fila de Envio_Viaje), con el viaje bloqueado.
     *
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => {idViaje, idSucursalOrigen, fechaHoraSalida}].
     * @return bool|null null si el viaje no existe; false si el envío no estaba cargado en él.
     */
    public function quitar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool;
}
