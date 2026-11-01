<?php
declare(strict_types=1);

namespace Jatsa\Modules\Despacho;

use Jatsa\Core\Database;
use Jatsa\Shared\HttpException;
use PDO;
use Throwable;

/**
 * Lee y escribe lo del despacho con los nombres del script SQL definitivo. Siempre con parámetros.
 * Escrituras: alta de Viaje y alta/baja de Envio_Viaje, cada una en una transacción.
 */
final class DespachoRepository implements RepositorioDespacho
{
    public function enEsperaDeSucursal(int $idSucursal, int $limite): array
    {
        // Espera en la sucursal: (a) registrado en su origen y sin ningún viaje, o (b) su último viaje llegó aquí y su destino final es otro.
        $sentencia = Database::conexion()->prepare(
            'SELECT TOP ' . max(1, $limite) . " e.codigoSeguimiento, e.estadoEnvio, r.idSucursalDestino, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre,
                    (SELECT COUNT(*) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS bultos,
                    (SELECT COALESCE(SUM(b.pesoKg), 0) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS pesoKg
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE e.estadoEnvio IN ('REGISTRADO', 'EN_TRANSITO')
               AND (
                    (e.estadoEnvio = 'REGISTRADO' AND r.idSucursalOrigen = :origen
                     AND NOT EXISTS (SELECT 1 FROM Envio_Viaje AS x WHERE x.idEnvio = e.idEnvio))
                 OR (e.estadoEnvio = 'EN_TRANSITO' AND r.idSucursalDestino <> :destinoFinal
                     AND EXISTS (SELECT 1 FROM Envio_Viaje AS ult INNER JOIN Viaje AS vl ON vl.idViaje = ult.idViaje
                                 WHERE ult.idEnvio = e.idEnvio AND vl.fechaHoraLlegada IS NOT NULL AND vl.idSucursalDestino = :aqui
                                   AND ult.idEnvioViaje = (SELECT MAX(z.idEnvioViaje) FROM Envio_Viaje AS z WHERE z.idEnvio = e.idEnvio)))
               )
             ORDER BY e.fechaHoraRegistro, e.idEnvio",
        );
        $sentencia->bindValue(':origen', $idSucursal, PDO::PARAM_INT);
        $sentencia->bindValue(':destinoFinal', $idSucursal, PDO::PARAM_INT);
        $sentencia->bindValue(':aqui', $idSucursal, PDO::PARAM_INT);
        $sentencia->execute();

        return array_map(
            static fn (array $f): array => [
                'codigoSeguimiento' => (string) $f['codigoSeguimiento'],
                'estado' => (string) $f['estadoEnvio'],
                'bultos' => (int) $f['bultos'],
                'pesoKg' => (string) $f['pesoKg'],
                'idSucursalDestino' => (int) $f['idSucursalDestino'],
                'destino' => ['codigo' => (string) $f['destinoCodigo'], 'nombre' => (string) $f['destinoNombre']],
            ],
            $sentencia->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function vehiculosActivos(): array
    {
        $filas = Database::conexion()->query(
            'SELECT ve.placa, ve.tipoVehiculo, ve.capacidadKg,
                    CASE WHEN EXISTS (SELECT 1 FROM Viaje AS v WHERE v.idVehiculo = ve.idVehiculo AND v.fechaHoraSalida IS NOT NULL AND v.fechaHoraLlegada IS NULL)
                         THEN 1 ELSE 0 END AS ocupado
             FROM Vehiculo AS ve WHERE ve.activo = 1 ORDER BY ve.placa',
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $f): array => ['placa' => (string) $f['placa'], 'tipo' => (string) $f['tipoVehiculo'], 'capacidadKg' => (string) $f['capacidadKg'], 'ocupado' => (int) $f['ocupado'] === 1],
            $filas,
        );
    }

    public function transportistasActivos(): array
    {
        $filas = Database::conexion()->query(
            "SELECT u.nombreUsuario, pe.nombreCompleto, s.codigoSucursal, s.nombre AS sucursalNombre
             FROM Usuario AS u
             INNER JOIN Persona AS pe ON pe.idPersona = u.idPersona
             INNER JOIN Sucursal AS s ON s.idSucursal = u.idSucursal
             WHERE u.rol = 'TRANSPORTISTA' AND u.activo = 1 ORDER BY pe.nombreCompleto",
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $f): array => [
                'nombreUsuario' => (string) $f['nombreUsuario'],
                'nombreCompleto' => (string) $f['nombreCompleto'],
                'sucursal' => ['codigo' => (string) $f['codigoSucursal'], 'nombre' => (string) $f['sucursalNombre']],
            ],
            $filas,
        );
    }

    public function idVehiculoActivo(string $placa): ?int
    {
        return $this->unId('SELECT idVehiculo FROM Vehiculo WHERE placa = :valor AND activo = 1', $placa);
    }

    public function idTransportistaActivo(string $nombreUsuario): ?int
    {
        return $this->unId("SELECT idUsuario FROM Usuario WHERE nombreUsuario = :valor AND rol = 'TRANSPORTISTA' AND activo = 1", $nombreUsuario);
    }

    public function idSucursalActiva(string $codigoSucursal): ?int
    {
        return $this->unId('SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :valor AND activa = 1', $codigoSucursal);
    }

    public function crearViaje(int $idVehiculo, int $idTransportista, int $idSucursalOrigen, int $idSucursalDestino): string
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            // Prefijo del día con la fecha de la base. El rango de códigos del día queda bloqueado hasta confirmar.
            $prefijo = (string) $pdo->query("SELECT 'VJ' + CONVERT(CHAR(8), SYSDATETIME(), 112)")->fetchColumn();
            $ultimo = $pdo->prepare('SELECT MAX(codigoViaje) FROM Viaje WITH (UPDLOCK, HOLDLOCK) WHERE codigoViaje LIKE :patron');
            $ultimo->bindValue(':patron', $prefijo . '__', PDO::PARAM_STR);
            $ultimo->execute();
            $maximo = $ultimo->fetchColumn();
            $ultimo->closeCursor();
            $numero = $maximo === false || $maximo === null ? 1 : (int) substr((string) $maximo, -2) + 1;
            if ($numero > 99) {
                throw new HttpException(409, 'limite_de_viajes', 'Se alcanzó el máximo de 99 viajes programados en el día.');
            }
            $codigo = $prefijo . sprintf('%02d', $numero);

            $alta = $pdo->prepare(
                'INSERT INTO Viaje (idVehiculo, idUsuarioTransportista, idSucursalOrigen, idSucursalDestino, codigoViaje)
                 VALUES (:vehiculo, :transportista, :origen, :destino, :codigo)',
            );
            $alta->bindValue(':vehiculo', $idVehiculo, PDO::PARAM_INT);
            $alta->bindValue(':transportista', $idTransportista, PDO::PARAM_INT);
            $alta->bindValue(':origen', $idSucursalOrigen, PDO::PARAM_INT);
            $alta->bindValue(':destino', $idSucursalDestino, PDO::PARAM_INT);
            $alta->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $alta->execute();

            $pdo->commit();

            return $codigo;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    public function cargar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            // 1) Bloquear el viaje (sirve para serializar cargas y la salida del mismo viaje) y leer lo ya cargado.
            $viaje = $pdo->prepare(
                'SELECT v.idViaje, v.idSucursalOrigen, v.idSucursalDestino, v.fechaHoraSalida, ve.capacidadKg
                 FROM Viaje AS v WITH (UPDLOCK, HOLDLOCK)
                 INNER JOIN Vehiculo AS ve ON ve.idVehiculo = v.idVehiculo
                 WHERE v.codigoViaje = :codigo',
            );
            $viaje->bindValue(':codigo', $codigoViaje, PDO::PARAM_STR);
            $viaje->execute();
            $v = $viaje->fetch(PDO::FETCH_ASSOC);
            $viaje->closeCursor();
            if ($v === false) {
                $pdo->rollBack();

                return null;
            }
            $idViaje = (int) $v['idViaje'];
            $cargado = $pdo->prepare('SELECT COALESCE(SUM(b.pesoKg), 0) FROM Envio_Viaje AS ev INNER JOIN Bulto AS b ON b.idEnvio = ev.idEnvio WHERE ev.idViaje = :viaje');
            $cargado->bindValue(':viaje', $idViaje, PDO::PARAM_INT);
            $cargado->execute();
            $pesoCargado = (string) $cargado->fetchColumn();
            $cargado->closeCursor();

            // 2) Bloquear el envío (después del viaje, siempre en ese orden: no hay interbloqueo).
            $envio = $pdo->prepare(
                'SELECT e.idEnvio, e.estadoEnvio, r.idSucursalOrigen, r.idSucursalDestino,
                        (SELECT COALESCE(SUM(b.pesoKg), 0) FROM Bulto AS b WHERE b.idEnvio = e.idEnvio) AS pesoKg
                 FROM Envio AS e WITH (UPDLOCK, HOLDLOCK)
                 INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
                 WHERE e.codigoSeguimiento = :codigo',
            );
            $envio->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
            $envio->execute();
            $e = $envio->fetch(PDO::FETCH_ASSOC);
            $envio->closeCursor();

            $contexto = [
                'viaje' => [
                    'idViaje' => $idViaje,
                    'idSucursalOrigen' => (int) $v['idSucursalOrigen'],
                    'idSucursalDestino' => (int) $v['idSucursalDestino'],
                    'fechaHoraSalida' => $v['fechaHoraSalida'] !== null ? substr((string) $v['fechaHoraSalida'], 0, 19) : null,
                    'capacidadKg' => (string) $v['capacidadKg'],
                    'pesoCargadoKg' => $pesoCargado,
                ],
                'envio' => null,
                'ultimoViaje' => null,
                'visitadas' => [],
            ];

            if ($e !== false) {
                $idEnvio = (int) $e['idEnvio'];
                $contexto['envio'] = [
                    'idEnvio' => $idEnvio,
                    'estado' => (string) $e['estadoEnvio'],
                    'idSucursalOrigen' => (int) $e['idSucursalOrigen'],
                    'idSucursalDestino' => (int) $e['idSucursalDestino'],
                    'pesoKg' => (string) $e['pesoKg'],
                ];
                $viajes = $pdo->prepare(
                    'SELECT ev.idEnvioViaje, vv.idSucursalOrigen, vv.idSucursalDestino, vv.fechaHoraLlegada
                     FROM Envio_Viaje AS ev INNER JOIN Viaje AS vv ON vv.idViaje = ev.idViaje
                     WHERE ev.idEnvio = :envio ORDER BY ev.idEnvioViaje',
                );
                $viajes->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
                $viajes->execute();
                foreach ($viajes->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                    $contexto['visitadas'][] = (int) $fila['idSucursalDestino'];
                    $contexto['ultimoViaje'] = [
                        'idSucursalOrigen' => (int) $fila['idSucursalOrigen'],
                        'idSucursalDestino' => (int) $fila['idSucursalDestino'],
                        'fechaHoraLlegada' => $fila['fechaHoraLlegada'] !== null ? substr((string) $fila['fechaHoraLlegada'], 0, 19) : null,
                    ];
                }
            }

            // 3) Reglas de negocio: las aplica el servicio y puede abortar.
            $decidir($contexto);

            // 4) Alta de la carga. La fecha y hora de carga las pone la base.
            $alta = $pdo->prepare('INSERT INTO Envio_Viaje (idEnvio, idViaje) VALUES (:envio, :viaje)');
            $alta->bindValue(':envio', $contexto['envio']['idEnvio'], PDO::PARAM_INT);
            $alta->bindValue(':viaje', $idViaje, PDO::PARAM_INT);
            $alta->execute();

            $pdo->commit();

            return true;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    public function quitar(string $codigoViaje, string $codigoSeguimiento, callable $decidir): ?bool
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            $viaje = $pdo->prepare('SELECT idViaje, idSucursalOrigen, fechaHoraSalida FROM Viaje WITH (UPDLOCK, HOLDLOCK) WHERE codigoViaje = :codigo');
            $viaje->bindValue(':codigo', $codigoViaje, PDO::PARAM_STR);
            $viaje->execute();
            $v = $viaje->fetch(PDO::FETCH_ASSOC);
            $viaje->closeCursor();
            if ($v === false) {
                $pdo->rollBack();

                return null;
            }

            $decidir(['viaje' => [
                'idViaje' => (int) $v['idViaje'],
                'idSucursalOrigen' => (int) $v['idSucursalOrigen'],
                'fechaHoraSalida' => $v['fechaHoraSalida'] !== null ? substr((string) $v['fechaHoraSalida'], 0, 19) : null,
            ]]);

            $baja = $pdo->prepare(
                'DELETE FROM Envio_Viaje WHERE idViaje = :viaje AND idEnvio = (SELECT idEnvio FROM Envio WHERE codigoSeguimiento = :codigo)',
            );
            $baja->bindValue(':viaje', (int) $v['idViaje'], PDO::PARAM_INT);
            $baja->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
            $baja->execute();
            $quitado = $baja->rowCount() === 1;

            $pdo->commit();

            return $quitado;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $excepcion;
        }
    }

    private function unId(string $sql, string $valor): ?int
    {
        $sentencia = Database::conexion()->prepare($sql);
        $sentencia->bindValue(':valor', $valor, PDO::PARAM_STR);
        $sentencia->execute();
        $id = $sentencia->fetchColumn();
        $sentencia->closeCursor();

        return $id === false ? null : (int) $id;
    }
}
