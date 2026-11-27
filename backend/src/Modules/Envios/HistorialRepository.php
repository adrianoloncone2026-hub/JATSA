<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use Jatsa\Core\Database;
use PDO;

/** Lee Envio, Reserva, Persona (solo el nombre), Sucursal, Bulto, Ruta, Pago, Envio_Viaje y Viaje para el historial de UNA persona */
final class HistorialRepository implements RepositorioHistorial
{
    private const COLUMNAS = "e.codigoSeguimiento, e.estadoEnvio, e.fechaHoraRegistro,
        CASE WHEN r.idPersonaRemitente = :rol1 THEN 'ENVIE' ELSE 'RECIBI' END AS rol,
        CASE WHEN r.idPersonaRemitente = :rol2 THEN pd.nombreCompleto ELSE pr.nombreCompleto END AS otraParte,
        t.importe, t.pagado";

    private const ORIGEN = 'FROM Envio AS e
        INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
        INNER JOIN Persona AS pr ON pr.idPersona = r.idPersonaRemitente
        INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
        INNER JOIN vw_EnvioTotales AS t ON t.idEnvio = e.idEnvio';

    public function listar(int $idPersona, array $filtros, int $limite): array
    {
        $donde = ['(r.idPersonaRemitente = :p1 OR r.idPersonaDestinatario = :p2)'];
        $valores = [];
        if ($filtros['rol'] === 'ENVIE') {
            $donde[] = 'r.idPersonaRemitente = :p3';
        } elseif ($filtros['rol'] === 'RECIBI') {
            $donde[] = 'r.idPersonaDestinatario = :p3';
        }
        if ($filtros['estado'] !== '') {
            $donde[] = 'e.estadoEnvio = :estado';
            $valores[':estado'] = $filtros['estado'];
        }
        if ($filtros['desde'] !== '') {
            $donde[] = 'e.fechaHoraRegistro >= CAST(:desde AS DATETIME2(0))';
            $valores[':desde'] = $filtros['desde'];
        }
        if ($filtros['hasta'] !== '') {
            $donde[] = 'e.fechaHoraRegistro < DATEADD(DAY, 1, CAST(:hasta AS DATETIME2(0)))';
            $valores[':hasta'] = $filtros['hasta'];
        }
        if ($filtros['q'] !== '') {
            $patron = '%' . strtr($filtros['q'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '[' => '\\[']) . '%';
            $donde[] = "(e.codigoSeguimiento LIKE :q1 ESCAPE '\\' OR (CASE WHEN r.idPersonaRemitente = :p4 THEN pd.nombreCompleto ELSE pr.nombreCompleto END) LIKE :q2 ESCAPE '\\')";
            $valores[':q1'] = $patron;
            $valores[':q2'] = $patron;
        }

        $sentencia = Database::conexion()->prepare(
            // El tope es un entero que decide el servicio (nunca entrada del usuario).
            'SELECT TOP ' . max(1, $limite) . ' ' . self::COLUMNAS . ' ' . self::ORIGEN . ' WHERE ' . implode(' AND ', $donde) . ' ORDER BY e.fechaHoraRegistro DESC, e.idEnvio DESC',
        );
        foreach ([':rol1', ':rol2', ':p1', ':p2'] as $nombre) {
            $sentencia->bindValue($nombre, $idPersona, PDO::PARAM_INT);
        }
        if ($filtros['rol'] !== '') {
            $sentencia->bindValue(':p3', $idPersona, PDO::PARAM_INT);
        }
        if ($filtros['q'] !== '') {
            $sentencia->bindValue(':p4', $idPersona, PDO::PARAM_INT);
        }
        foreach ($valores as $nombre => $valor) {
            $sentencia->bindValue($nombre, $valor, PDO::PARAM_STR);
        }
        $sentencia->execute();

        return array_map([self::class, 'aFila'], $sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function resumenEnCurso(int $idPersona, int $limitePorRecoger): array
    {
        $pdo = Database::conexion();

        $totales = $pdo->prepare(
            "SELECT COUNT(*) AS enCurso,
                    COALESCE(SUM(CASE WHEN t.importe > t.pagado THEN t.importe - t.pagado ELSE 0 END), 0) AS saldo
             FROM vw_EnvioTotales AS t
             INNER JOIN Envio AS e ON e.idEnvio = t.idEnvio
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             WHERE (r.idPersonaRemitente = :p1 OR r.idPersonaDestinatario = :p2)
               AND e.estadoEnvio IN ('REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO')",
        );
        $totales->bindValue(':p1', $idPersona, PDO::PARAM_INT);
        $totales->bindValue(':p2', $idPersona, PDO::PARAM_INT);
        $totales->execute();
        $fila = $totales->fetch(PDO::FETCH_ASSOC);
        $totales->closeCursor();

        $lista = $pdo->prepare(
            'SELECT TOP ' . max(1, $limitePorRecoger) . ' ' . self::COLUMNAS . ', sd.nombre AS destinoNombre, sd.direccion AS destinoDireccion ' . self::ORIGEN . '
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE r.idPersonaDestinatario = :p1 AND r.idPersonaRemitente <> :p2 AND e.estadoEnvio = \'EN_DESTINO\'
             ORDER BY e.fechaHoraRegistro DESC, e.idEnvio DESC',
        );
        foreach ([':rol1', ':rol2', ':p1', ':p2'] as $nombre) {
            $lista->bindValue($nombre, $idPersona, PDO::PARAM_INT);
        }
        $lista->execute();

        return [
            'enCurso' => (int) ($fila['enCurso'] ?? 0),
            'saldo' => (string) ($fila['saldo'] ?? '0'),
            'porRecoger' => array_map(
                static fn (array $f): array => self::aFila($f) + ['destino' => ['nombre' => (string) $f['destinoNombre'], 'direccion' => (string) $f['destinoDireccion']]],
                $lista->fetchAll(PDO::FETCH_ASSOC),
            ),
        ];
    }
    public function detalle(int $idPersona, string $codigoSeguimiento): ?array
    {
        $pdo = Database::conexion();
        $sentencia = $pdo->prepare(
            'SELECT e.idEnvio, e.fechaHoraEntrega, so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre, '
            . self::COLUMNAS . ' ' . self::ORIGEN . '
             INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE e.codigoSeguimiento = :codigo AND (r.idPersonaRemitente = :p1 OR r.idPersonaDestinatario = :p2)',
        );
        foreach ([':rol1', ':rol2', ':p1', ':p2'] as $nombre) {
            $sentencia->bindValue($nombre, $idPersona, PDO::PARAM_INT);
        }
        $sentencia->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();
        if ($fila === false) {
            return null;
        }
        $idEnvio = (int) $fila['idEnvio'];

        $bultos = $pdo->prepare(
            'SELECT b.numeroBulto, b.descripcion, b.pesoKg, b.costo, ru.tipoEnvio
             FROM Bulto AS b INNER JOIN Ruta AS ru ON ru.idRuta = b.idRuta WHERE b.idEnvio = :envio ORDER BY b.numeroBulto',
        );
        $bultos->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
        $bultos->execute();

        $pagos = $pdo->prepare(
            'SELECT p.monto, p.metodoPago, p.estadoPago, p.fechaHoraPago, s.codigoSucursal
             FROM Pago AS p INNER JOIN Sucursal AS s ON s.idSucursal = p.idSucursal WHERE p.idEnvio = :envio ORDER BY p.fechaHoraPago, p.idPago',
        );
        $pagos->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
        $pagos->execute();

        $viaje = $pdo->prepare(
            'SELECT TOP 1 v.codigoViaje, v.fechaHoraSalida, v.fechaHoraLlegada
             FROM Envio_Viaje AS ev INNER JOIN Viaje AS v ON v.idViaje = ev.idViaje WHERE ev.idEnvio = :envio ORDER BY ev.idEnvioViaje DESC',
        );
        $viaje->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
        $viaje->execute();
        $ultimo = $viaje->fetch(PDO::FETCH_ASSOC);
        $viaje->closeCursor();

        $fecha = static fn (mixed $v): ?string => $v !== null ? substr((string) $v, 0, 19) : null;

        return self::aFila($fila) + [
            'fechaHoraEntrega' => $fecha($fila['fechaHoraEntrega']),
            'origen' => ['codigo' => (string) $fila['origenCodigo'], 'nombre' => (string) $fila['origenNombre']],
            'destino' => ['codigo' => (string) $fila['destinoCodigo'], 'nombre' => (string) $fila['destinoNombre']],
            'bultos' => array_map(
                static fn (array $b): array => ['numero' => (int) $b['numeroBulto'], 'descripcion' => (string) $b['descripcion'], 'pesoKg' => (string) $b['pesoKg'], 'tipo' => (string) $b['tipoEnvio'], 'costo' => (string) $b['costo']],
                $bultos->fetchAll(PDO::FETCH_ASSOC),
            ),
            'pagos' => array_map(
                static fn (array $p): array => ['monto' => (string) $p['monto'], 'metodoPago' => (string) $p['metodoPago'], 'estado' => (string) $p['estadoPago'], 'sucursalCodigo' => (string) $p['codigoSucursal'], 'fechaHora' => (string) $fecha($p['fechaHoraPago'])],
                $pagos->fetchAll(PDO::FETCH_ASSOC),
            ),
            'ultimoViaje' => $ultimo === false ? null : ['codigo' => (string) $ultimo['codigoViaje'], 'fechaHoraSalida' => $fecha($ultimo['fechaHoraSalida']), 'fechaHoraLlegada' => $fecha($ultimo['fechaHoraLlegada'])],
        ];
    }

    /** @param array<string, mixed> $f */
    private static function aFila(array $f): array
    {
        return [
            'codigoSeguimiento' => (string) $f['codigoSeguimiento'],
            'estado' => (string) $f['estadoEnvio'],
            'fechaHoraRegistro' => substr((string) $f['fechaHoraRegistro'], 0, 19),
            'rol' => (string) $f['rol'],
            'otraParte' => (string) $f['otraParte'],
            'importe' => (string) $f['importe'],
            'pagado' => (string) $f['pagado'],
        ];
    }
}
