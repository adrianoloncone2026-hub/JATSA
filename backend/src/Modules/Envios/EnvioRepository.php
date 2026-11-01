<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

use DateTimeImmutable;
use Jatsa\Core\Database;
use Jatsa\Modules\Reservas\RepositorioReservas;
use Jatsa\Shared\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Lee y escribe Envio y Bulto con los nombres del script SQL definitivo. Siempre con parámetros.
 * La única escritura es la formalización (reserva → envío con sus bultos), en una transacción.
 */
final class EnvioRepository implements RepositorioEnvios
{
    private const ERROR_CLAVE_DUPLICADA = [2627, 2601];

    /** Letras y dígitos del código de seguimiento, sin los que se confunden al leer (I, O, 0, 1). */
    private const ALFABETO_CODIGO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const INTENTOS_CODIGO = 20;

    private const TARIFAS = <<<'SQL'
        SELECT r.idRuta, r.tipoEnvio, r.precioBase, r.precioPorKm, r.distanciaKm, r.tiempoHoras
        FROM Ruta AS r %SUGERENCIA%
        WHERE r.idSucursalOrigen = %ORIGEN%
          AND r.idSucursalDestino = %DESTINO%
          AND r.vigenteDesde <= :fecha1
          AND (r.vigenteHasta IS NULL OR r.vigenteHasta >= :fecha2)
        ORDER BY r.vigenteDesde DESC, r.idRuta DESC
        SQL;

    public function tarifasVigentes(string $codigoOrigen, string $codigoDestino, string $fechaIso): array
    {
        $sql = str_replace(
            ['%SUGERENCIA%', '%ORIGEN%', '%DESTINO%'],
            ['', '(SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :origen)', '(SELECT idSucursal FROM Sucursal WHERE codigoSucursal = :destino)'],
            self::TARIFAS,
        );
        $sentencia = Database::conexion()->prepare($sql);
        $sentencia->bindValue(':origen', $codigoOrigen, PDO::PARAM_STR);
        $sentencia->bindValue(':destino', $codigoDestino, PDO::PARAM_STR);
        $sentencia->bindValue(':fecha1', $fechaIso, PDO::PARAM_STR);
        $sentencia->bindValue(':fecha2', $fechaIso, PDO::PARAM_STR);
        $sentencia->execute();

        return self::unaPorTipo($sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function formalizar(string $codigoReserva, int $idSucursalOrigen, string $fechaIso, callable $planificar, ?DateTimeImmutable $referencia = null): ?string
    {
        $pdo = Database::conexion();
        $pdo->beginTransaction();

        try {
            // 1) Reclamar la reserva. El UPDATE vuelve a comprobar en SQL todo lo que importa: que sea de esta sucursal,
            //    que siga PENDIENTE y que no haya cumplido el plazo. Si una cancelación, un vencimiento o un segundo
            //    cajero se adelantó, no cambia ninguna fila.
            $reclamo = $pdo->prepare(
                "UPDATE Reserva SET estadoReserva = 'FORMALIZADA'
                 WHERE codigoReserva = :codigo AND idSucursalOrigen = :sucursal AND estadoReserva = 'PENDIENTE'
                   AND fechaHoraReserva > " . self::limite($referencia),
            );
            $reclamo->bindValue(':codigo', $codigoReserva, PDO::PARAM_STR);
            $reclamo->bindValue(':sucursal', $idSucursalOrigen, PDO::PARAM_INT);
            self::enlazarReferencia($reclamo, $referencia);
            $reclamo->execute();

            if ($reclamo->rowCount() === 0) {
                $pdo->rollBack();

                return null;
            }

            $datos = $pdo->prepare('SELECT idReserva, idSucursalOrigen, idSucursalDestino FROM Reserva WHERE codigoReserva = :codigo');
            $datos->bindValue(':codigo', $codigoReserva, PDO::PARAM_STR);
            $datos->execute();
            $reserva = $datos->fetch(PDO::FETCH_ASSOC);
            $datos->closeCursor();
            $idReserva = (int) $reserva['idReserva'];

            // 2) Tarifas vigentes leídas DENTRO de la transacción (HOLDLOCK: no cambian hasta confirmar).
            $sql = str_replace(['%SUGERENCIA%', '%ORIGEN%', '%DESTINO%'], ['WITH (HOLDLOCK)', ':origen', ':destino'], self::TARIFAS);
            $tarifas = $pdo->prepare($sql);
            $tarifas->bindValue(':origen', (int) $reserva['idSucursalOrigen'], PDO::PARAM_INT);
            $tarifas->bindValue(':destino', (int) $reserva['idSucursalDestino'], PDO::PARAM_INT);
            $tarifas->bindValue(':fecha1', $fechaIso, PDO::PARAM_STR);
            $tarifas->bindValue(':fecha2', $fechaIso, PDO::PARAM_STR);
            $tarifas->execute();

            // 3) Reglas de negocio (tipo por peso, tarifa del tipo, costo): las aplica el servicio.
            $plan = $planificar(
                ['idReserva' => $idReserva, 'idSucursalOrigen' => (int) $reserva['idSucursalOrigen'], 'idSucursalDestino' => (int) $reserva['idSucursalDestino']],
                self::unaPorTipo($tarifas->fetchAll(PDO::FETCH_ASSOC)),
            );

            // 4) Código de seguimiento libre y alta del envío.
            $codigo = $this->codigoLibre($pdo);
            $envio = $pdo->prepare(
                'INSERT INTO Envio (idReserva, codigoSeguimiento, tipoEntrega, direccionEntrega)
                 VALUES (:reserva, :codigo, :tipo, :direccion)',
            );
            $envio->bindValue(':reserva', $idReserva, PDO::PARAM_INT);
            $envio->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $envio->bindValue(':tipo', (string) $plan['tipoEntrega'], PDO::PARAM_STR);
            $envio->bindValue(':direccion', $plan['direccionEntrega'], $plan['direccionEntrega'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $envio->execute();

            $idEnvio = $pdo->prepare('SELECT idEnvio FROM Envio WHERE idReserva = :reserva');
            $idEnvio->bindValue(':reserva', $idReserva, PDO::PARAM_INT);
            $idEnvio->execute();
            $idEnvio = (int) $idEnvio->fetchColumn();
            // (el cursor se libera solo al reasignar la variable)

            // 5) Los bultos, cada uno con la ruta (tarifa) con la que se cobra.
            $bulto = $pdo->prepare(
                'INSERT INTO Bulto (idEnvio, idRuta, numeroBulto, descripcion, pesoKg, costo)
                 VALUES (:envio, :ruta, :numero, :descripcion, :peso, :costo)',
            );
            foreach ($plan['bultos'] as $b) {
                $bulto->bindValue(':envio', $idEnvio, PDO::PARAM_INT);
                $bulto->bindValue(':ruta', (int) $b['idRuta'], PDO::PARAM_INT);
                $bulto->bindValue(':numero', (int) $b['numero'], PDO::PARAM_INT);
                $bulto->bindValue(':descripcion', (string) $b['descripcion'], PDO::PARAM_STR);
                $bulto->bindValue(':peso', (string) $b['pesoKg'], PDO::PARAM_STR);
                $bulto->bindValue(':costo', (string) $b['costo'], PDO::PARAM_STR);
                $bulto->execute();
            }

            $pdo->commit();

            return $codigo;
        } catch (Throwable $excepcion) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $this->traducir($excepcion);
        }
    }

    public function buscarPorCodigo(string $codigoSeguimiento): ?array
    {
        $pdo = Database::conexion();
        $sentencia = $pdo->prepare(
            'SELECT e.idEnvio, e.codigoSeguimiento, e.fechaHoraRegistro, e.tipoEntrega, e.direccionEntrega, e.estadoEnvio,
                    r.codigoReserva, r.descripcion, r.canal, r.idSucursalOrigen, r.idSucursalDestino,
                    pr.tipoDocumento AS remitenteTipo, pr.numeroDocumento AS remitenteNumero, pr.nombreCompleto AS remitenteNombre,
                    pd.tipoDocumento AS destinatarioTipo, pd.numeroDocumento AS destinatarioNumero, pd.nombreCompleto AS destinatarioNombre,
                    so.codigoSucursal AS origenCodigo, so.nombre AS origenNombre, so.direccion AS origenDireccion, so.departamento AS origenDepartamento,
                    sd.codigoSucursal AS destinoCodigo, sd.nombre AS destinoNombre, sd.direccion AS destinoDireccion, sd.departamento AS destinoDepartamento
             FROM Envio AS e
             INNER JOIN Reserva AS r ON r.idReserva = e.idReserva
             INNER JOIN Persona AS pr ON pr.idPersona = r.idPersonaRemitente
             INNER JOIN Persona AS pd ON pd.idPersona = r.idPersonaDestinatario
             INNER JOIN Sucursal AS so ON so.idSucursal = r.idSucursalOrigen
             INNER JOIN Sucursal AS sd ON sd.idSucursal = r.idSucursalDestino
             WHERE e.codigoSeguimiento = :codigo',
        );
        $sentencia->bindValue(':codigo', $codigoSeguimiento, PDO::PARAM_STR);
        $sentencia->execute();
        $fila = $sentencia->fetch(PDO::FETCH_ASSOC);
        $sentencia->closeCursor();
        if ($fila === false) {
            return null;
        }

        $bultos = $pdo->prepare(
            'SELECT b.numeroBulto, b.descripcion, b.pesoKg, b.costo, ru.tipoEnvio
             FROM Bulto AS b
             INNER JOIN Ruta AS ru ON ru.idRuta = b.idRuta
             WHERE b.idEnvio = :envio
             ORDER BY b.numeroBulto',
        );
        $bultos->bindValue(':envio', (int) $fila['idEnvio'], PDO::PARAM_INT);
        $bultos->execute();

        $persona = static fn (string $p): array => [
            'tipoDocumento' => (string) $fila[$p . 'Tipo'],
            'numeroDocumento' => (string) $fila[$p . 'Numero'],
            'nombreCompleto' => (string) $fila[$p . 'Nombre'],
        ];
        $sucursal = static fn (string $p): array => [
            'codigo' => (string) $fila[$p . 'Codigo'],
            'nombre' => (string) $fila[$p . 'Nombre'],
            'direccion' => (string) $fila[$p . 'Direccion'],
            'departamento' => (string) $fila[$p . 'Departamento'],
        ];

        return [
            'codigoSeguimiento' => (string) $fila['codigoSeguimiento'],
            'fechaHoraRegistro' => (string) $fila['fechaHoraRegistro'],
            'tipoEntrega' => (string) $fila['tipoEntrega'],
            'direccionEntrega' => $fila['direccionEntrega'] !== null ? (string) $fila['direccionEntrega'] : null,
            'estado' => (string) $fila['estadoEnvio'],
            'idSucursalOrigen' => (int) $fila['idSucursalOrigen'],
            'idSucursalDestino' => (int) $fila['idSucursalDestino'],
            'reserva' => [
                'codigo' => (string) $fila['codigoReserva'],
                'contenido' => (string) $fila['descripcion'],
                'canal' => (string) $fila['canal'],
                'remitente' => $persona('remitente'),
                'destinatario' => $persona('destinatario'),
                'origen' => $sucursal('origen'),
                'destino' => $sucursal('destino'),
            ],
            'bultos' => array_map(
                static fn (array $b): array => [
                    'numero' => (int) $b['numeroBulto'],
                    'descripcion' => (string) $b['descripcion'],
                    'pesoKg' => (string) $b['pesoKg'],
                    'costo' => (string) $b['costo'],
                    'tipo' => (string) $b['tipoEnvio'],
                ],
                $bultos->fetchAll(PDO::FETCH_ASSOC),
            ),
        ];
    }

    /**
     * Instante límite de vigencia de una reserva pendiente (la misma expresión que usa ReservaRepository).
     * Las horas son una constante, nunca entrada del usuario.
     */
    private static function limite(?DateTimeImmutable $referencia): string
    {
        $ahora = $referencia === null ? 'SYSDATETIME()' : 'CAST(:referencia AS DATETIME2(0))';

        return 'DATEADD(HOUR, -' . RepositorioReservas::HORAS_DE_VIGENCIA . ', ' . $ahora . ')';
    }

    private static function enlazarReferencia(\PDOStatement $sentencia, ?DateTimeImmutable $referencia): void
    {
        if ($referencia !== null) {
            $sentencia->bindValue(':referencia', $referencia->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        }
    }

    /**
     * La consulta ya viene con la vigente más reciente primero: se conserva una tarifa por tipo.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    private static function unaPorTipo(array $filas): array
    {
        $porTipo = [];
        foreach ($filas as $fila) {
            $tipo = (string) $fila['tipoEnvio'];
            if (!isset($porTipo[$tipo])) {
                $porTipo[$tipo] = [
                    'idRuta' => (int) $fila['idRuta'],
                    'tipoEnvio' => $tipo,
                    'precioBase' => (string) $fila['precioBase'],
                    'precioPorKm' => (string) $fila['precioPorKm'],
                    'distanciaKm' => (string) $fila['distanciaKm'],
                    'tiempoHoras' => (int) $fila['tiempoHoras'],
                ];
            }
        }

        return array_values($porTipo);
    }

    /**
     * 'EN' + 10 caracteres al azar que no estén en uso. La comprobación lleva UPDLOCK + HOLDLOCK para que dos
     * formalizaciones simultáneas no elijan el mismo; la restricción UNIQUE es la red de seguridad.
     */
    private function codigoLibre(PDO $pdo): string
    {
        $existe = $pdo->prepare('SELECT 1 FROM Envio WITH (UPDLOCK, HOLDLOCK) WHERE codigoSeguimiento = :codigo');

        for ($intento = 0; $intento < self::INTENTOS_CODIGO; $intento++) {
            $codigo = 'EN';
            for ($i = 0; $i < 10; $i++) {
                $codigo .= self::ALFABETO_CODIGO[random_int(0, strlen(self::ALFABETO_CODIGO) - 1)];
            }
            $existe->bindValue(':codigo', $codigo, PDO::PARAM_STR);
            $existe->execute();
            $ocupado = $existe->fetchColumn() !== false;
            $existe->closeCursor();
            if (!$ocupado) {
                return $codigo;
            }
        }

        throw new HttpException(503, 'servicio_no_disponible', 'No se pudo generar el código de seguimiento. Inténtalo de nuevo.');
    }

    private function traducir(Throwable $excepcion): Throwable
    {
        if ($excepcion instanceof PDOException && in_array((int) ($excepcion->errorInfo[1] ?? 0), self::ERROR_CLAVE_DUPLICADA, true)) {
            return new HttpException(409, 'conflicto', 'No se pudo formalizar el envío porque otra operación se cruzó. Inténtalo de nuevo.');
        }

        return $excepcion;
    }
}
