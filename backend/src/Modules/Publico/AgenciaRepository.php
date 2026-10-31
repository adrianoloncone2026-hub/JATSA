<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

use Jatsa\Core\Database;
use PDO;

/**
 * Lee Sucursal y Telefono_Sucursal (solo SELECT, con parámetros). Solo sucursales activas.
 */
final class AgenciaRepository implements RepositorioAgencias
{
    private const CONSULTA = <<<'SQL'
        SELECT s.codigoSucursal, s.nombre, s.departamento, s.provincia, s.distrito, s.direccion,
               t.numero AS telefono
        FROM Sucursal AS s
        LEFT JOIN Telefono_Sucursal AS t ON t.idSucursal = s.idSucursal
        WHERE s.activa = 1
        SQL;

    public function listarActivas(): array
    {
        $sentencia = Database::conexion()->prepare(self::CONSULTA . ' ORDER BY s.nombre, s.codigoSucursal, t.numero');
        $sentencia->execute();

        return $this->agrupar($sentencia->fetchAll(PDO::FETCH_ASSOC));
    }

    public function buscarActivaPorCodigo(string $codigo): ?array
    {
        $sentencia = Database::conexion()->prepare(self::CONSULTA . ' AND s.codigoSucursal = :codigo ORDER BY t.numero');
        $sentencia->bindValue(':codigo', $codigo, PDO::PARAM_STR);
        $sentencia->execute();

        return $this->agrupar($sentencia->fetchAll(PDO::FETCH_ASSOC))[0] ?? null;
    }

    /**
     * Une las filas (una por teléfono) en una agencia con su lista de teléfonos.
     *
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    private function agrupar(array $filas): array
    {
        $agencias = [];

        foreach ($filas as $fila) {
            $codigo = (string) $fila['codigoSucursal'];

            if (!isset($agencias[$codigo])) {
                $agencias[$codigo] = [
                    'codigo' => $codigo,
                    'nombre' => (string) $fila['nombre'],
                    'departamento' => (string) $fila['departamento'],
                    'provincia' => (string) $fila['provincia'],
                    'distrito' => (string) $fila['distrito'],
                    'direccion' => (string) $fila['direccion'],
                    'telefonos' => [],
                ];
            }

            if ($fila['telefono'] !== null) {
                $agencias[$codigo]['telefonos'][] = (string) $fila['telefono'];
            }
        }

        return array_values($agencias);
    }
}
