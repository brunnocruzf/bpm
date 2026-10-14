<?php

namespace App\Reports;

use RuntimeException;
use ZipArchive;

/**
 * CSV (UTF-8 com BOM, separador ";" para Excel pt-BR, proteção contra injeção de fórmula) e
 * XLSX mínimo (SpreadsheetML com inlineStr; células de texto nunca são avaliadas como fórmula).
 */
final class TabularWriter
{
    /**
     * @param  list<array{key:string,label:string}>  $columns
     * @param  list<array<string,mixed>>  $rows
     */
    public static function csv(array $columns, array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        $line = fn (array $cells) => implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', self::safeText($v)).'"', $cells))."\r\n";
        $out .= $line(array_column($columns, 'label'));
        foreach ($rows as $row) {
            $out .= $line(array_map(fn ($c) => $row[$c['key']] ?? '', $columns));
        }

        return $out;
    }

    /**
     * @param  list<array{key:string,label:string}>  $columns
     * @param  list<array<string,mixed>>  $rows
     */
    public static function xlsx(array $columns, array $rows, string $sheetName = 'Relatório'): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extensão zip indisponível para XLSX.');
        }
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + (($i - 1) % 26)).$s;
            }

            return $s;
        };
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        $all = [array_column($columns, 'label'), ...array_map(fn ($r) => array_map(fn ($c) => $r[$c['key']] ?? '', $columns), $rows)];
        foreach ($all as $ri => $cells) {
            $sheet .= '<row r="'.($ri + 1).'">';
            foreach ($cells as $ci => $v) {
                $ref = $col($ci).($ri + 1);
                $style = $ri === 0 ? ' s="1"' : '';
                if ($ri > 0 && (is_int($v) || is_float($v))) {
                    $sheet .= "<c r=\"$ref\"$style><v>".$v.'</v></c>';
                } else {
                    $sheet .= "<c r=\"$ref\" t=\"inlineStr\"$style><is><t xml:space=\"preserve\">".$x(self::clean((string) $v)).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'bpmx');
        $zip = new ZipArchive;
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Falha ao criar XLSX.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$x(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function clean(string $v): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    }

    /** Neutraliza injeção de fórmula em CSV (=, +, -, @, tab, CR no início). */
    public static function safeText(mixed $v): string
    {
        if (is_int($v) || is_float($v)) {
            return str_replace('.', ',', (string) $v);
        }
        $s = self::clean(is_bool($v) ? ($v ? 'Sim' : 'Não') : (string) $v);

        return preg_match('/^[=+\-@\t\r]/', $s) ? "'".$s : $s;
    }
}
