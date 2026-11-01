<?php
declare(strict_types=1);

namespace Jatsa\Modules\Envios;

/**
 * Lectura de los envíos de UNA persona (los que envió o recibió) para el historial del cliente (solo SELECT).
 * De la otra parte solo se devuelve el nombre: nunca documentos ni teléfonos.
 */
interface RepositorioHistorial
{
    /**
     * Envíos de la persona, el más reciente primero.
     *
     * @param array{rol: string, estado: string, desde: string, hasta: string, q: string} $filtros rol ''|ENVIE|RECIBI;
     *        estado '' o un estado de Envio; desde y hasta '' o AAAA-MM-DD (fecha de registro, ambas incluidas); q '' o texto
     *        que se busca en el código y en el nombre de la otra parte.
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, estado, fechaHoraRegistro, rol (ENVIE|RECIBI), otraParte, importe, pagado.
     */
    public function listar(int $idPersona, array $filtros, int $limite): array;

    /**
     * Envíos en curso (REGISTRADO, EN_TRANSITO o EN_DESTINO) de la persona, con lo que hace falta para el resumen de «Mi panel».
     *
     * @return list<array<string, mixed>> Claves: codigoSeguimiento, estado, rol, otraParte, importe, pagado, destino{nombre, direccion}.
     */
    public function enCurso(int $idPersona, int $limite): array;

    /**
     * Un envío de la persona con todo su detalle, o null si no es suyo (ni como remitente ni como destinatario).
     *
     * @return array<string, mixed>|null Claves: codigoSeguimiento, estado, rol, otraParte, fechaHoraRegistro, fechaHoraEntrega (?),
     *         origen y destino {codigo, nombre}, importe, pagado, bultos list<{numero, descripcion, pesoKg, tipo, costo}>,
     *         pagos list<{monto, metodoPago, estado, sucursalCodigo, fechaHora}>, ultimoViaje (?){codigo, fechaHoraSalida (?), fechaHoraLlegada (?)}.
     */
    public function detalle(int $idPersona, string $codigoSeguimiento): ?array;
}
