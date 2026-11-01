<?php
declare(strict_types=1);

namespace Jatsa\Modules\Catalogos;

/**
 * Acceso a Sucursal, Telefono_Sucursal, Ruta y Vehiculo para los catálogos del GERENTE (módulo 10).
 *
 * Las operaciones que dependen de datos que pueden cambiar a la vez (cerrar una vigencia, desactivar algo en uso) reciben
 * un `$decidir`: el repositorio abre la transacción, bloquea las filas implicadas, reúne lo que hace falta y llama a
 * `$decidir`, que aplica las reglas y lanza HttpException si no procede (todo se deshace). El repositorio no decide nada.
 */
interface RepositorioCatalogo
{
    // ── Sucursales ──────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>> codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa (bool), telefonos list<string>.
     */
    public function sucursales(): array;

    /**
     * Crea la sucursal con sus teléfonos en una transacción. Lanza HttpException 409 si el código ya existe.
     *
     * @param array<string, string> $datos codigoSucursal, nombre, departamento, provincia, distrito, direccion
     * @param list<string>          $telefonos
     */
    public function crearSucursal(array $datos, array $telefonos): void;

    /**
     * Actualiza los datos (no el código) y reemplaza los teléfonos, en una transacción. false si la sucursal no existe.
     *
     * @param array<string, string> $datos nombre, departamento, provincia, distrito, direccion
     * @param list<string>          $telefonos
     */
    public function actualizarSucursal(string $codigo, array $datos, array $telefonos): bool;

    /**
     * Cambia `activa`. Bloquea la sucursal y llama a $decidir con ['activa' => bool, 'personalActivo' => int,
     * 'viajesAbiertos' => int, 'enviosEnCurso' => int]. null si no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarEstadoSucursal(string $codigo, bool $activa, callable $decidir): ?bool;

    // ── Rutas y precios ─────────────────────────────────────────────────────

    /**
     * Todas las filas de Ruta (vigentes y cerradas), por origen, destino, tipo y vigencia descendente.
     *
     * @return list<array<string, mixed>> idRuta, origenCodigo, origenNombre, destinoCodigo, destinoNombre, tipoEnvio, precioBase,
     *         precioPorKm, distanciaKm, tiempoHoras, vigenteDesde (AAAA-MM-DD), vigenteHasta (?AAAA-MM-DD)
     */
    public function rutas(): array;

    /**
     * Inserta una ruta nueva con vigenteDesde = $hoy. En UNA transacción bloquea las dos sucursales (por id ascendente, UPDLOCK, HOLDLOCK)
     * y las filas de ese origen, destino y tipo, y llama a $decidir con ['origenActiva' => bool, 'destinoActiva' => bool (false si no
     * existen) , 'vigentes' => int (filas con vigenteHasta NULL)]. Así una desactivación de una de las sucursales, que bloquea la misma
     * fila, no puede intercalarse entre la validación y el INSERT. Devuelve la fila creada.
     *
     * @param array{tipoEnvio: string, precioBase: string, precioPorKm: string, distanciaKm: string, tiempoHoras: int} $datos
     * @param callable(array<string, mixed>): void $decidir
     * @return array<string, mixed>
     */
    public function crearRuta(string $codigoOrigen, string $codigoDestino, array $datos, string $hoy, callable $decidir): array;
    /**
     * «Nuevo precio»: en UNA transacción bloquea la fila, llama a $decidir con ['vigenteDesde' => string, 'vigenteHasta' => ?string],
     * cierra la vigencia (vigenteHasta = el día anterior a $hoy) e inserta la fila nueva con vigenteDesde = $hoy. Los bultos ya
     * cobrados siguen apuntando a la fila cerrada y conservan su costo. null si la ruta no existe.
     *
     * @param array{precioBase: string, precioPorKm: string, distanciaKm: string, tiempoHoras: int} $datos
     * @param callable(array<string, mixed>): void $decidir
     * @return array<string, mixed>|null
     */
    public function nuevoPrecio(int $idRuta, array $datos, string $hoy, callable $decidir): ?array;

    // ── Vehículos ───────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>> placa, tipoVehiculo, capacidadKg, activo (bool), viajesAbiertos (int)
     */
    public function vehiculos(): array;

    /** Crea el vehículo. Lanza HttpException 409 si la placa ya existe. */
    public function crearVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg): void;

    /**
     * Cambia tipo y capacidad. Bloquea el vehículo y llama a $decidir con ['cargaMaximaKg' => string] (el mayor peso cargado en
     * sus viajes sin llegada). null si no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function actualizarVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg, callable $decidir): ?bool;

    /**
     * Cambia `activo`. Bloquea el vehículo y llama a $decidir con ['activo' => bool, 'viajesAbiertos' => int]. null si no existe.
     *
     * @param callable(array<string, mixed>): void $decidir
     */
    public function cambiarEstadoVehiculo(string $placa, bool $activo, callable $decidir): ?bool;
}
