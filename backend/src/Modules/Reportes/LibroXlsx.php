<?php
declare(strict_types=1);

namespace Jatsa\Modules\Reportes;

use DateTimeImmutable;
use DateTimeZone;

/** Generador mínimo de libros .xlsx (Office Open XML) sin dependencias. Cada hoja es una tabla de Excel con filtros. */
final class LibroXlsx
{
    private const ESTILO = ['texto' => 0, 'entero' => 0, 'fecha' => 1, 'fechahora' => 2, 'dinero' => 3, 'peso' => 4, 'booleano' => 0];

    /** @var list<string> */
    private array $textos = [];
    /** @var array<string, int> */
    private array $indiceTextos = [];

    /** @param list<array{nombre: string, columnas: list<array{0: string, 1: string}>, filas: list<list<mixed>>}> $hojas */
    public function generar(array $hojas): string
    {
        $zip = new EscritorZip();
        $cuantas = count($hojas);

        $tipos = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $hojasXml = '';
        $relaciones = '';
        $partes = [];
        foreach ($hojas as $i => $hoja) {
            $n = $i + 1;
            $nombre = self::atributo($hoja['nombre']);
            $tipos .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>"
                . "<Override PartName=\"/xl/tables/table{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml\"/>";
            $hojasXml .= "<sheet name=\"{$nombre}\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $relaciones .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $partes[$n] = [$this->hoja($hoja), $this->tabla($hoja, $n)];
        }
        $relaciones .= '<Relationship Id="rId' . ($cuantas + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId' . ($cuantas + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $cabecera = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $zip->agregar('[Content_Types].xml', $cabecera . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $tipos . '</Types>');
        $zip->agregar('_rels/.rels', $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->agregar('xl/workbook.xml', $cabecera . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $hojasXml . '</sheets></workbook>');
        $zip->agregar('xl/_rels/workbook.xml.rels', $cabecera . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relaciones . '</Relationships>');
        $zip->agregar('xl/styles.xml', $cabecera . $this->estilos());
        foreach ($partes as $n => [$hojaXml, $tablaXml]) {
            $zip->agregar("xl/worksheets/sheet{$n}.xml", $cabecera . $hojaXml);
            $zip->agregar("xl/worksheets/_rels/sheet{$n}.xml.rels", $cabecera . "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/table\" Target=\"../tables/table{$n}.xml\"/></Relationships>");
            $zip->agregar("xl/tables/table{$n}.xml", $cabecera . $tablaXml);
        }
        $zip->agregar('xl/sharedStrings.xml', $cabecera . $this->textosXml());

        return $zip->contenido();
    }

    public static function serie(string $texto, bool $conHora): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/', $texto, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $dia = (new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]}", new DateTimeZone('UTC')))->getTimestamp() / 86400 + 25569;
        $segundos = $conHora && isset($m[4]) ? ((int) $m[4] * 3600 + (int) $m[5] * 60 + (int) $m[6]) : 0;

        return $segundos === 0 ? (string) (int) $dia : rtrim(rtrim(number_format((int) $dia + $segundos / 86400, 10, '.', ''), '0'), '.');
    }

    /** @param array{nombre: string, columnas: list<array{0: string, 1: string}>, filas: list<list<mixed>>} $hoja */
    private function hoja(array $hoja): string
    {
        $columnas = $hoja['columnas'];
        $ultima = self::letra(count($columnas) - 1);
        $filas = '<row r="1">';
        foreach ($columnas as $c => [$nombre]) {
            $filas .= '<c r="' . self::letra($c) . '1" t="s" s="5"><v>' . $this->texto($nombre) . '</v></c>';
        }
        $filas .= '</row>';
        foreach ($hoja['filas'] as $f => $fila) {
            $r = $f + 2;
            $filas .= "<row r=\"{$r}\">";
            foreach ($columnas as $c => [, $tipo]) {
                $filas .= $this->celda(self::letra($c) . $r, $tipo, $fila[$c] ?? null);
            }
            $filas .= '</row>';
        }
        $ancho = '';
        foreach ($columnas as $c => [$nombre, $tipo]) {
            $w = max(12, strlen($nombre) + 4, in_array($tipo, ['fechahora'], true) ? 20 : 0, $tipo === 'texto' ? 18 : 0);
            $ancho .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . "\" width=\"{$w}\" customWidth=\"1\"/>";
        }
        $fin = max(2, count($hoja['filas']) + 1);

        return '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<dimension ref=\"A1:{$ultima}{$fin}\"/>"
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $ancho . '</cols><sheetData>' . $filas . '</sheetData><tableParts count="1"><tablePart r:id="rId1"/></tableParts></worksheet>';
    }

    private function celda(string $ref, string $tipo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        $estilo = self::ESTILO[$tipo] ?? 0;
        switch ($tipo) {
            case 'entero':
            case 'dinero':
            case 'peso':
                if (!is_numeric($valor)) {
                    return '';
                }

                return "<c r=\"{$ref}\" s=\"{$estilo}\"><v>" . (is_int($valor) ? $valor : rtrim(rtrim(number_format((float) $valor, 4, '.', ''), '0'), '.')) . '</v></c>';
            case 'fecha':
            case 'fechahora':
                $serie = self::serie((string) $valor, $tipo === 'fechahora');

                return $serie === null ? '' : "<c r=\"{$ref}\" s=\"{$estilo}\"><v>{$serie}</v></c>";
            case 'booleano':
                return "<c r=\"{$ref}\" t=\"b\"><v>" . ($valor ? 1 : 0) . '</v></c>';
            default:
                return "<c r=\"{$ref}\" t=\"s\"><v>" . $this->texto($this->protegido((string) $valor)) . '</v></c>';
        }
    }

    private function protegido(string $texto): string
    {
        return $texto !== '' && strpbrk($texto[0], "=+-@\t\r\n") !== false ? "'" . $texto : $texto;
    }

    private function texto(string $texto): int
    {
        if (!isset($this->indiceTextos[$texto])) {
            $this->indiceTextos[$texto] = count($this->textos);
            $this->textos[] = $texto;
        }

        return $this->indiceTextos[$texto];
    }

    private function textosXml(): string
    {
        $items = '';
        foreach ($this->textos as $t) {
            $items .= '<si><t xml:space="preserve">' . self::contenido($t) . '</t></si>';
        }
        $n = count($this->textos);

        return "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$n}\" uniqueCount=\"{$n}\">{$items}</sst>";
    }

    /** @param array{nombre: string, columnas: list<array{0: string, 1: string}>, filas: list<list<mixed>>} $hoja */
    private function tabla(array $hoja, int $n): string
    {
        $ref = 'A1:' . self::letra(count($hoja['columnas']) - 1) . max(2, count($hoja['filas']) + 1);
        $nombre = 'tbl' . preg_replace('/[^A-Za-z0-9]/', '', $hoja['nombre']) . $n;
        $columnas = '';
        foreach ($hoja['columnas'] as $c => [$titulo]) {
            $columnas .= '<tableColumn id="' . ($c + 1) . '" name="' . self::atributo($titulo) . '"/>';
        }

        return "<table xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" id=\"{$n}\" name=\"{$nombre}\" displayName=\"{$nombre}\" ref=\"{$ref}\" totalsRowShown=\"0\">"
            . "<autoFilter ref=\"{$ref}\"/><tableColumns count=\"" . count($hoja['columnas']) . "\">{$columnas}</tableColumns>"
            . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>';
    }

    private function estilos(): string
    {
        return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function letra(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26) . $letras;
        }

        return $letras;
    }

    private static function contenido(string $texto): string
    {
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function atributo(string $texto): string
    {
        return self::contenido($texto);
    }
}
