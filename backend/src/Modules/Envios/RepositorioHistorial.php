<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

/** Lectura de los envíos de UNA persona (los que envió o recibió) para el historial del cliente (solo SELECT). */
interface RepositorioHistorial
{
    /**
     * @param array{rol: string, estado: string, desde: string, hasta: string, q: string} $filtros rol ''|ENVIE|RECIBI;
     * estado '' o un estado de Envio; desde y hasta '' o AAAA-MM-DD (fecha de registro, ambas incluidas); q '' o texto
     * que se busca en el código y en el nombre de la otra parte.
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, estado, fechaHoraRegistro, rol (ENVIE|RECIBI), otraParte, importe, pagado.
     */
    public function listar(int $idPersona, array $filtros, int $limite): array;

    /**
     * Resumen de «Mi panel». `enCurso` (cantidad de envíos REGISTRADO, EN_TRANSITO o EN_DESTINO de la persona como remitente o
     * @return array{enCurso: int, saldo: string, porRecoger: list<array<string, mixed>>} Cada fila de porRecoger: codigoSeguimiento,
     * estado, rol, otraParte, importe, pagado, destino{nombre, direccion}.
     */
    public function resumenEnCurso(int $idPersona, int $limitePorRecoger): array;

    /**
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, rol, otraParte, fechaHoraRegistro, fechaHoraEntrega (?),
     * origen y destino {codigo, nombre}, importe, pagado, bultos list<{numero, descripcion, pesoKg, tipo, costo}>,
     * pagos list<{monto, metodoPago, estado, sucursalCodigo, fechaHora}>, ultimoViaje (?){codigo, fechaHoraSalida (?), fechaHoraLlegada (?)}.
     */
    public function detalle(int $idPersona, string $codigoSeguimiento): ?array;
}
