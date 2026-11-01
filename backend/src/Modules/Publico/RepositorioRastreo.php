<?php
declare(strict_types=1);

namespace Jatsa\Modules\Publico;

/**
 * Lectura de un envío para el rastreo público (solo SELECT). Nunca devuelve documentos, teléfonos ni nombres de personas.
 */
interface RepositorioRastreo
{
    /**
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, fechaHoraRegistro, fechaHoraEntrega (?string),
     *         origen y destino {codigo, nombre}, bultos (int), pesoKg, importe y pagado (cadenas con decimales) y
     *         tramos: lista, en el orden en que se cargó el envío, de {codigoViaje, fechaHoraCarga, fechaHoraSalida (?),
     *         fechaHoraLlegada (?), origen{codigo, nombre}, destino{codigo, nombre}, vehiculo{tipo, placa}, horas (?int)}.
     */
    public function buscarEnvio(string $codigoSeguimiento, string $hoy): ?array;
}
