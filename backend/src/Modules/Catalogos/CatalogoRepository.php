<?php
declare(strict_types=1);

namespace Jatsa\Modules\Catalogos;

use Jatsa\Core\Database;
use Jatsa\Shared\HttpException;
use PDO;
use PDOException;
use Throwable;

/** Lee y escribe Sucursal, Telefono_Sucursal, Ruta y Vehiculo (consultas parametrizadas; escrituras en transacción). */
final class CatalogoRepository implements RepositorioCatalogo
{
    private const ERROR_CLAVE_DUPLICADA = [2601, 2627];

    private const SELECT_RUTA = "SELECT r.idRuta, so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
            r.tipoEnvio, r.precioBase, r.precioPorKm, r.distanciaKm, r.tiempoHoras,
            CONVERT(VARCHAR(10), r.vigenteDesde, 23) AS vigenteDesde, CONVERT(VARCHAR(10), r.vigenteHasta, 23) AS vigenteHasta
        FROM Ruta AS r
        INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
        INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino";


    public function sucursales(): array
    {
        $pdo = Database::conexion();
        $filas = $pdo->query(
            'SELECT idSucursal, codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa FROM Sucursal ORDER BY nombre, codigoSucursal',
        )->fetchAll(PDO::FETCH_ASSOC);
        $telefonos = [];
        foreach ($pdo->query('SELECT idSucursal, numero FROM Telefono_Sucursal ORDER BY idSucursal, idTelefono')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $telefonos[(int) $t['idSucursal']][] = (string) $t['numero'];
        }

        return array_map(static fn (array $f): array => [
            'codigoSucursal' => (string) $f['codigoSucursal'], 'nombre' => (string) $f['nombre'], 'departamento' => (string) $f['departamento'],
            'provincia' => (string) $f['provincia'], 'distrito' => (string) $f['distrito'], 'direccion' => (string) $f['direccion'],
            'activa' => (bool) $f['activa'], 'telefonos' => $telefonos[(int) $f['idSucursal']] ?? [],
        ], $filas);
    }

    public function crearSucursal(array $datos, array $telefonos): void
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $alta = $pdo->prepare(
                'INSERT INTO Sucursal (codigoSucursal, nombre, departamento, provincia, distrito, direccion, activa)
                 VALUES (:codigo, :nombre, :departamento, :provincia, :distrito, :direccion, 1)',
            );
            foreach (['codigo' => 'codigoSucursal', 'nombre' => 'nombre', 'departamento' => 'departamento', 'provincia' => 'provincia', 'distrito' => 'distrito', 'direccion' => 'direccion'] as $parametro => $clave) {
                $alta->bindValue(':' . $parametro, $datos[$clave], PDO::PARAM_STR);
            }
            $alta->execute();
            $this->insertarTelefonos($pdo, $datos['codigoSucursal'], $telefonos);
            $pdo->commit();
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $this->traducir($e, 'sucursal_existente', 'Ya existe una sucursal con ese código.', 'codigoSucursal');
        }
    }

    public function actualizarSucursal(string $codigo, array $datos, array $telefonos): bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $cambio = $pdo->prepare(
                'UPDATE Sucursal SET nombre = :nombre, departamento = :departamento, provincia = :provincia, distrito = :distrito, direccion = :direccion
                 WHERE codigoSucursal = :codigo',
            );
            foreach (['nombre', 'departamento', 'provincia', 'distrito', 'direccion'] as $clave) {
                $cambio->bindValue(':' . $clave, $datos[$clave], PDO::PARAM_STR);
            }
            $cambio->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $cambio->execute();
            if ($cambio->rowCount() === 0) {
                $pdo->rollBack();

                return false;
            }
            $borrar = $pdo->prepare('DELETE FROM Telefono_Sucursal WHERE idSucursal = (SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :codigo)');
            $borrar->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $borrar->execute();
            $this->insertarTelefonos($pdo, $codigo, $telefonos);
            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }

    public function cambiarEstadoSucursal(string $codigo, bool $activa, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $fila = $this->uno($pdo, 'SELECT idSucursal, activa FROM Sucursal WITH (UPDLOCK, HOLDLOCK) WHERE codigoSucursal = :codigo', [':codigo' => $codigo]);
            if ($fila === null) {
                $pdo->rollBack();

                return null;
            }
            $id = (int) $fila['idSucursal'];
            $decidir([
                'activa' => (bool) $fila['activa'],
                'personalActivo' => $this->contar($pdo, "SELECT COUNT(*) FROM Usuario WHERE idSucursal = :s AND activo = 1 AND rol IN ('ADMINISTRADOR', 'TRANSPORTISTA')", [':s' => $id]),
                'viajesAbiertos' => $this->contar($pdo, 'SELECT COUNT(*) FROM Viaje WHERE (idSucursalOrigen = :s1 OR idSucursalDestino = :s2) AND fechaHoraLlegada IS NULL', [':s1' => $id, ':s2' => $id]),
                'enviosEnCurso' => $this->contar(
                    $pdo,
                    "SELECT COUNT(*) FROM Envio AS e INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
                     WHERE (r.idSucursalOrigen = :s1 OR r.idSucursalDestino = :s2) AND e.estadoEnvio IN ('REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO')",
                    [':s1' => $id, ':s2' => $id],
                ),
            ]);
            $cambio = $pdo->prepare('UPDATE Sucursal SET activa = :activa WHERE idSucursal = :id');
            $cambio->bindValue(':activa', $activa ? 1 : 0, PDO::PARAM_INT);
            $cambio->bindValue(':id', $id, PDO::PARAM_INT);
            $cambio->execute();
            $pdo->commit();

            return $activa;
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }


    public function rutas(): array
    {
        return array_map([self::class, 'aRuta'], Database::conexion()->query(self::SELECT_RUTA . ' ORDER BY so.nombre, sd.nombre, r.tipoEnvio, r.vigenteDesde DESC, r.idRuta DESC')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function crearRuta(string $codigoOrigen, string $codigoDestino, array $datos, string $hoy, callable $decidir): array
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            // Orden de bloqueos constante en todo el sistema: sucursales por id ascendente, luego las filas de Ruta. Las sucursales se
            // leen YA BLOQUEADAS: si el gerente desactiva una a la vez, o este alta ve la sucursal inactiva, o la desactivación espera.
            $ids = [];
            foreach ([$codigoOrigen, $codigoDestino] as $codigo) {
                $fila = $this->uno($pdo, 'SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :codigo', [':codigo' => $codigo]);
                $ids[$codigo] = $fila === null ? null : (int) $fila['idSucursal'];
            }
            $activas = [];
            $ordenados = array_filter($ids, static fn (?int $id): bool => $id !== null);
            asort($ordenados);
            foreach ($ordenados as $id) {
                $activas[$id] = (bool) ($this->uno($pdo, 'SELECT activa FROM Sucursal WITH (UPDLOCK, HOLDLOCK) WHERE idSucursal = :id', [':id' => $id])['activa'] ?? false);
            }
            $idOrigen = $ids[$codigoOrigen];
            $idDestino = $ids[$codigoDestino];
            $vigentes = $idOrigen === null || $idDestino === null ? 0 : $this->contar(
                $pdo,
                'SELECT COUNT(*) FROM Ruta WITH (UPDLOCK, HOLDLOCK) WHERE idSucursalOrigen = :o AND idSucursalDestino = :d AND tipoEnvio = :t AND vigenteHasta IS NULL',
                [':o' => $idOrigen, ':d' => $idDestino, ':t' => $datos['tipoEnvio']],
            );
            $decidir([
                'origenActiva' => $idOrigen !== null && ($activas[$idOrigen] ?? false),
                'destinoActiva' => $idDestino !== null && ($activas[$idDestino] ?? false),
                'vigentes' => $vigentes,
            ]);
            $id = $this->insertarRuta($pdo, (int) $idOrigen, (int) $idDestino, $datos, $hoy);
            $pdo->commit();

            return self::aRuta($this->uno($pdo, self::SELECT_RUTA . ' WHERE r.idRuta = :id', [':id' => $id]) ?? []);
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }
    public function nuevoPrecio(int $idRuta, array $datos, string $hoy, callable $decidir): ?array
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $actual = $this->uno(
                $pdo,
                "SELECT idSucursalOrigen, idSucursalDestino, tipoEnvio, CONVERT(VARCHAR(10), vigenteDesde, 23) AS vigenteDesde, CONVERT(VARCHAR(10), vigenteHasta, 23) AS vigenteHasta
                 FROM Ruta WITH (UPDLOCK, HOLDLOCK) WHERE idRuta = :id",
                [':id' => $idRuta],
            );
            if ($actual === null) {
                $pdo->rollBack();

                return null;
            }
            $decidir(['vigenteDesde' => (string) $actual['vigenteDesde'], 'vigenteHasta' => $actual['vigenteHasta'] === null ? null : (string) $actual['vigenteHasta']]);

            $cierre = $pdo->prepare('UPDATE Ruta SET vigenteHasta = DATEADD(DAY, -1, CAST(:hoy AS DATE)) WHERE idRuta = :id');
            $cierre->bindValue(':hoy', $hoy, PDO::PARAM_STR);
            $cierre->bindValue(':id', $idRuta, PDO::PARAM_INT);
            $cierre->execute();
            $nuevo = $this->insertarRuta($pdo, (int) $actual['idSucursalOrigen'], (int) $actual['idSucursalDestino'], $datos + ['tipoEnvio' => (string) $actual['tipoEnvio']], $hoy);
            $pdo->commit();

            return self::aRuta($this->uno($pdo, self::SELECT_RUTA . ' WHERE r.idRuta = :id', [':id' => $nuevo]) ?? []);
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }


    public function vehiculos(): array
    {
        return array_map(static fn (array $f): array => [
            'placa' => (string) $f['placa'], 'tipoVehiculo' => (string) $f['tipoVehiculo'], 'capacidadKg' => (string) $f['capacidadKg'],
            'activo' => (bool) $f['activo'], 'viajesAbiertos' => (int) $f['viajesAbiertos'],
        ], Database::conexion()->query(
            'SELECT ve.placa, ve.tipoVehiculo, ve.capacidadKg, ve.activo,
                    (SELECT COUNT(*) FROM Viaje AS v WHERE v.idVehiculo = ve.idVehiculo AND v.fechaHoraLlegada IS NULL) AS viajesAbiertos
             FROM Vehiculo AS ve ORDER BY ve.placa',
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    public function crearVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg): void
    {
        $pdo = Database::conexion();
        try {
            $alta = $pdo->prepare('INSERT INTO Vehiculo (placa, tipoVehiculo, capacidadKg, activo) VALUES (:placa, :tipo, :capacidad, 1)');
            $alta->bindValue(':placa', $placa, PDO::PARAM_STR);
            $alta->bindValue(':tipo', $tipoVehiculo, PDO::PARAM_STR);
            $alta->bindValue(':capacidad', $capacidadKg, PDO::PARAM_STR);
            $alta->execute();
        } catch (Throwable $e) {
            throw $this->traducir($e, 'vehiculo_existente', 'Ya existe un vehículo con esa placa.', 'placa');
        }
    }

    public function actualizarVehiculo(string $placa, string $tipoVehiculo, string $capacidadKg, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $fila = $this->uno($pdo, 'SELECT idVehiculo FROM Vehiculo WITH (UPDLOCK, HOLDLOCK) WHERE placa = :placa', [':placa' => $placa]);
            if ($fila === null) {
                $pdo->rollBack();

                return null;
            }
            $id = (int) $fila['idVehiculo'];
            $carga = $this->uno(
                $pdo,
                'SELECT COALESCE(MAX(t.peso), 0) AS maxima FROM (
                     SELECT SUM(b.pesoKg) AS peso FROM Viaje AS v
                     INNER JOIN Envio_Viaje AS ev ON ev.idViaje = v.idViaje
                     INNER JOIN Bulto AS b ON b.idEnvio = ev.idEnvio
                     WHERE v.idVehiculo = :v AND v.fechaHoraLlegada IS NULL GROUP BY v.idViaje) AS t',
                [':v' => $id],
            );
            $decidir(['cargaMaximaKg' => (string) ($carga['maxima'] ?? '0')]);
            $cambio = $pdo->prepare('UPDATE Vehiculo SET tipoVehiculo = :tipo, capacidadKg = :capacidad WHERE idVehiculo = :id');
            $cambio->bindValue(':tipo', $tipoVehiculo, PDO::PARAM_STR);
            $cambio->bindValue(':capacidad', $capacidadKg, PDO::PARAM_STR);
            $cambio->bindValue(':id', $id, PDO::PARAM_INT);
            $cambio->execute();
            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }

    public function cambiarEstadoVehiculo(string $placa, bool $activo, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $fila = $this->uno($pdo, 'SELECT idVehiculo, activo FROM Vehiculo WITH (UPDLOCK, HOLDLOCK) WHERE placa = :placa', [':placa' => $placa]);
            if ($fila === null) {
                $pdo->rollBack();

                return null;
            }
            $id = (int) $fila['idVehiculo'];
            $decidir([
                'activo' => (bool) $fila['activo'],
                'viajesAbiertos' => $this->contar($pdo, 'SELECT COUNT(*) FROM Viaje WHERE idVehiculo = :v AND fechaHoraLlegada IS NULL', [':v' => $id]),
            ]);
            $cambio = $pdo->prepare('UPDATE Vehiculo SET activo = :activo WHERE idVehiculo = :id');
            $cambio->bindValue(':activo', $activo ? 1 : 0, PDO::PARAM_INT);
            $cambio->bindValue(':id', $id, PDO::PARAM_INT);
            $cambio->execute();
            $pdo->commit();

            return $activo;
        } catch (Throwable $e) {
            $this->deshacer($pdo);
            throw $e;
        }
    }


    /** @param array<string, mixed> $datos */
    private function insertarRuta(PDO $pdo, int $idOrigen, int $idDestino, array $datos, string $hoy): int
    {
        $alta = $pdo->prepare(
            'INSERT INTO Ruta (idSucursalOrigen, idSucursalDestino, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta)
             OUTPUT INSERTED.idRuta
             VALUES (:o, :d, :tipo, :base, :km, :dist, :horas, CAST(:hoy AS DATE), NULL)',
        );
        $alta->bindValue(':o', $idOrigen, PDO::PARAM_INT);
        $alta->bindValue(':d', $idDestino, PDO::PARAM_INT);
        $alta->bindValue(':tipo', $datos['tipoEnvio'], PDO::PARAM_STR);
        $alta->bindValue(':base', $datos['precioBase'], PDO::PARAM_STR);
        $alta->bindValue(':km', $datos['precioPorKm'], PDO::PARAM_STR);
        $alta->bindValue(':dist', $datos['distanciaKm'], PDO::PARAM_STR);
        $alta->bindValue(':horas', $datos['tiempoHoras'], PDO::PARAM_INT);
        $alta->bindValue(':hoy', $hoy, PDO::PARAM_STR);
        $alta->execute();

        // sentencia devuelve NULL. Sin un id positivo no se confirma nada: nunca se devuelve un 201 con una ruta vacía.
        $id = $alta->fetchColumn();
        $alta->closeCursor();
        if (!is_numeric($id) || (int) $id <= 0) {
            throw new \RuntimeException('No se obtuvo el id de la ruta insertada.');
        }

        return (int) $id;
    }

    /** @param list<string> $telefonos */
    private function insertarTelefonos(PDO $pdo, string $codigo, array $telefonos): void
    {
        $alta = $pdo->prepare('INSERT INTO Telefono_Sucursal (idSucursal, numero) SELECT idSucursal, :numero FROM Sucursal WHERE codigoSucursal = :codigo');
        foreach ($telefonos as $numero) {
            $alta->bindValue(':numero', $numero, PDO::PARAM_STR);
            $alta->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $alta->execute();
        }
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private static function aRuta(array $f): array
    {
        return [
            'idRuta' => (int) ($f['idRuta'] ?? 0),
            'origenCodigo' => (string) ($f['origenCodigo'] ?? ''), 'origenNombre' => (string) ($f['origenNombre'] ?? ''),
            'destinoCodigo' => (string) ($f['destinoCodigo'] ?? ''), 'destinoNombre' => (string) ($f['destinoNombre'] ?? ''),
            'tipoEnvio' => (string) ($f['tipoEnvio'] ?? ''), 'precioBase' => (string) ($f['precioBase'] ?? '0'), 'precioPorKm' => (string) ($f['precioPorKm'] ?? '0'),
            'distanciaKm' => (string) ($f['distanciaKm'] ?? '0'), 'tiempoHoras' => (int) ($f['tiempoHoras'] ?? 0),
            'vigenteDesde' => (string) ($f['vigenteDesde'] ?? ''), 'vigenteHasta' => ($f['vigenteHasta'] ?? null) === null ? null : (string) $f['vigenteHasta'],
        ];
    }

    /**
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>|null
     */
    private function uno(PDO $pdo, string $sql, array $parametros): ?array
    {
        $sentencia = $pdo->prepare($sql);
        foreach ($parametros as $nombre => $valor) {
            $sentencia->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();

        return $fila === false ? null : $fila;
    }

    /** @param array<string, mixed> $parametros */
    private function contar(PDO $pdo, string $sql, array $parametros): int
    {
        $sentencia = $pdo->prepare($sql);
        foreach ($parametros as $nombre => $valor) {
            $sentencia->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $sentencia->execute();
        $total = (int) $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return $total;
    }

    private function deshacer(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** Una clave única duplicada (carrera entre dos altas) se traduce en un 409; cualquier otro error se relanza. */
    private function traducir(Throwable $e, string $codigo, string $mensaje, string $campo): Throwable
    {
        if ($e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), self::ERROR_CLAVE_DUPLICADA, true)) {
            return new HttpException(409, $codigo, $mensaje, [], [$campo => $mensaje]);
        }

        return $e;
    }
}
