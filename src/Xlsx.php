<?php
declare(strict_types=1);

/** Минимальная запись/чтение XLSX без внешних библиотек (нужно расширение zip). */
final class Xlsx
{
    /**
     * @param array<string, array<int, array<int, mixed>>> $sheets имя листа => строки
     * @param array<string, array<int, float>> $widths имя листа => ширины колонок
     */
    public static function write(string $path, array $sheets, array $widths = []): void
    {
        $shared = [];
        $sharedIdx = [];
        $sheetXml = [];
        foreach ($sheets as $name => $rows) {
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            if (!empty($widths[$name])) {
                $x .= '<cols>';
                foreach ($widths[$name] as $i => $w) {
                    $c = $i + 1;
                    $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
                }
                $x .= '</cols>';
            }
            $x .= '<sheetData>';
            foreach (array_values($rows) as $ri => $row) {
                $r = $ri + 1;
                $x .= '<row r="' . $r . '">';
                foreach (array_values($row) as $ci => $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $ref = self::col($ci) . $r;
                    $style = $ri === 0 ? ' s="1"' : '';
                    if (is_int($v) || is_float($v)) {
                        $x .= '<c r="' . $ref . '"' . $style . '><v>' . self::num($v) . '</v></c>';
                    } else {
                        $v = (string)$v;
                        if (!isset($sharedIdx[$v])) {
                            $sharedIdx[$v] = count($shared);
                            $shared[] = $v;
                        }
                        $x .= '<c r="' . $ref . '" t="s"' . $style . '><v>' . $sharedIdx[$v] . '</v></c>';
                    }
                }
                $x .= '</row>';
            }
            $x .= '</sheetData></worksheet>';
            $sheetXml[] = [$name, $x];
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать xlsx');
        }
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        foreach ($sheetXml as $i => [$name, $xml]) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet$n.xml", $xml);
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::esc(mb_substr($name, 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $n = count($sheetXml);
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $ct .= '</Types>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $ss .= '<si><t xml:space="preserve">' . self::esc($s) . '</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', $ss . '</sst>');
        $zip->close();
    }

    /** @return array<int, array<int, string>> */
    public static function readFirstSheet(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть xlsx');
        }
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = simplexml_load_string($ssXml);
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                    $shared[] = $t;
                }
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $wb = $zip->getFromName('xl/workbook.xml');
        if ($rels !== false && $wb !== false) {
            $wbx = simplexml_load_string($wb);
            $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $first = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
            if ($first) {
                $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $rx = simplexml_load_string($rels);
                foreach ($rx->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $t = ltrim((string)$rel['Target'], '/');
                        $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('В xlsx не найден лист');
        }
        $sx = simplexml_load_string($xml);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $ci = self::colIndex(preg_replace('/\d+/', '', $ref));
                $type = (string)$c['t'];
                if ($type === 's') {
                    $v = $shared[(int)$c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $v = (string)$c->is->t;
                } else {
                    $v = (string)$c->v;
                }
                $r[$ci] = $v;
            }
            if ($r) {
                $max = max(array_keys($r));
                $full = [];
                for ($i = 0; $i <= $max; $i++) {
                    $full[] = $r[$i] ?? '';
                }
                $rows[] = $full;
            }
        }
        return $rows;
    }

    private static function col(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private static function num($v): string
    {
        if (is_int($v)) {
            return (string)$v;
        }
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
