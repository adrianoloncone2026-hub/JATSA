<?php
declare(strict_types=1);

namespace Jatsa\Modules\Despacho;

/** Acceso a lo que el Administrador necesita para despachar: envíos en espera, vehículos y transportistas disponibles, */
interface RepositorioDespacho
{
    /** @return list<array<string, mixed>> Claves: codigoSeguimiento, estado, bultos, pesoKg, idSucursalDestino, destino{codigo, nombre}. */
    public function enEsperaDeSucursal(int $idSucursal, int $limite): array;

    /** @return list<array{placa: string, tipo: string, capacidadKg: string, ocupado: bool}> */
    public function vehiculosActivos(): array;

    /** @return list<array{nombreUsuario: string, nombreCompleto: string, sucursal: array{codigo: string, nombre: string}}> */
    public function transportistasActivos(): array;

    /**
     * Crea el viaje (sin salida ni llegada) y devuelve su código «VJ» + fecha de la base (AAAAMMDD) + número del día (01 a 99),
     * @param callable(array<string, mixed>): void $decidir Recibe origenActiva, destinoExiste, destinoActiva, vehiculoExiste,
     * vehiculoActivo, transportistaExiste y transportistaActivo (rol TRANSPORTISTA y cuenta activa), todos bool.
     */
    public function crearViaje(string $placa, string $nombreTransportista, int $idSucursalOrigen, string $codigoDestino, callable $decidir): string;
    /**
     * Carga un envío en un viaje en UNA transacción: bloquea el viaje y luego el envío (siempre en ese orden), reúne lo
     * @param callable(array<string, mixed>): void $decidir Recibe:
     * viaje {idViaje, idSucursalOrigen, idSucursalDestino, fechaHoraSalida, capacidadKg, pesoCargadoKg},
     * envio null o {idEnvio, estado, idSucursalOrigen, idSucursalDestino, pesoKg},
     * ultimoViaje null o {idSucursalOrigen, idSucursalDestino, fechaHoraLlegada} (el último en el que se cargó el envío),
     * visitadas list<int> (destinos de los viajes anteriores del envío).
     * @return bool|null null si el viaje no existe.
     */
    public function cargar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool;

    /**
     * Quita un envío de un viaje que aún no salió (DELETE de la fila de Envio_Viaje), con el viaje bloqueado.
     * @param callable(array<string, mixed>): void $decidir Recibe ['viaje' => {idViaje, idSucursalOrigen, fechaHoraSalida}].
     * @return bool|null null si el viaje no existe; false si el envío no estaba cargado en él.
     */
    public function quitar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool;
}
