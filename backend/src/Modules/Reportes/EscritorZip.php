<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

/** Contenedor ZIP en memoria (PKZIP con DEFLATE). Usa solo zlib nativa: el servidor no tiene la extensión zip. */
final class EscritorZip
{
    /** @var list<array{nombre: string, crc: int, comprimido: string, tamano: int, desplazamiento: int}> */
    private array $entradas = [];
    private string $cuerpo = '';

    public function agregar(string $nombre, string $contenido): void
    {
        $comprimido = gzdeflate($contenido, 6);
        if ($comprimido === false) {
            throw new \RuntimeException('No se pudo comprimir el contenido.');
        }
        $crc = crc32($contenido) & 0xFFFFFFFF;
        $desplazamiento = strlen($this->cuerpo);

        $this->cuerpo .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, 0, 0x21, $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0)
            . $nombre . $comprimido;
        $this->entradas[] = ['nombre' => $nombre, 'crc' => $crc, 'comprimido' => $comprimido, 'tamano' => strlen($contenido), 'desplazamiento' => $desplazamiento];
    }

    public function contenido(): string
    {
        $directorio = '';
        foreach ($this->entradas as $e) {
            $directorio .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 8, 0, 0x21, $e['crc'], strlen($e['comprimido']), $e['tamano'], strlen($e['nombre']), 0, 0, 0, 0, 0, $e['desplazamiento'])
                . $e['nombre'];
        }
        $total = count($this->entradas);

        return $this->cuerpo . $directorio
            . pack('VvvvvVVv', 0x06054B50, 0, 0, $total, $total, strlen($directorio), strlen($this->cuerpo), 0);
    }
}
