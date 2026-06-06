<?php

namespace App\Traits;

trait HandlesXlsx
{
    /**
     * Read an .xlsx file and return a 2D array (rows × columns).
     * Row 0 is the header row.
     */
    protected function readXlsx(string $path): array
    {
        $rows = [];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return [];

            // Shared strings
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = simplexml_load_string($ssXml);
                foreach ($ss->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $val = '';
                        foreach ($si->r as $r) $val .= (string)($r->t ?? '');
                        $sharedStrings[] = $val;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            if (!$sheetXml) return [];

            $sheet  = simplexml_load_string($sheetXml);
            $maxCol = 0;

            foreach ($sheet->sheetData->row as $row) {
                $rowData = [];
                $rowIdx  = (int)$row['r'] - 1;
                foreach ($row->c as $cell) {
                    preg_match('/^([A-Z]+)(\d+)$/', (string)$cell['r'], $m);
                    $colIdx = $this->xlsxColToIndex($m[1]);
                    $maxCol = max($maxCol, $colIdx);

                    $type = (string)($cell['t'] ?? '');
                    $v    = (string)($cell->v ?? '');

                    if ($type === 's') {
                        $rowData[$colIdx] = $sharedStrings[(int)$v] ?? '';
                    } elseif ($type === 'str' || $type === 'inlineStr') {
                        $rowData[$colIdx] = $v;
                    } else {
                        $rowData[$colIdx] = is_numeric($v)
                            ? (strpos($v, '.') !== false ? (float)$v : (int)$v)
                            : $v;
                    }
                }
                for ($i = 0; $i <= $maxCol; $i++) {
                    if (!array_key_exists($i, $rowData)) $rowData[$i] = '';
                }
                ksort($rowData);
                $rows[$rowIdx] = array_values($rowData);
            }
            ksort($rows);
            return array_values($rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build a minimal valid .xlsx binary from a 2D array.
     * First row is treated as the header.
     */
    protected function buildXlsx(array $data): string
    {
        $sharedStrings = [];
        $ssIndex       = [];

        foreach ($data as $row) {
            foreach ($row as $cell) {
                $s = (string)$cell;
                if (!is_numeric($cell) && $s !== '' && !isset($ssIndex[$s])) {
                    $ssIndex[$s]   = count($sharedStrings);
                    $sharedStrings[] = $s;
                }
            }
        }

        $sheetRows = '';
        foreach ($data as $ri => $row) {
            $rowNum = $ri + 1;
            $cells  = '';
            foreach ($row as $ci => $cell) {
                $col = $this->xlsxIndexToCol($ci);
                $ref = $col . $rowNum;
                $s   = (string)$cell;

                if ($s === '') {
                    $cells .= "<c r=\"{$ref}\"/>";
                } elseif (is_numeric($cell)) {
                    $cells .= "<c r=\"{$ref}\"><v>{$cell}</v></c>";
                } else {
                    $idx   = $ssIndex[$s];
                    $cells .= "<c r=\"{$ref}\" t=\"s\"><v>{$idx}</v></c>";
                }
            }
            $sheetRows .= "<row r=\"{$rowNum}\">{$cells}</row>";
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

        $ssCount  = count($sharedStrings);
        $ssEntries = '';
        foreach ($sharedStrings as $str) {
            $escaped   = htmlspecialchars($str, ENT_XML1, 'UTF-8');
            $ssEntries .= "<si><t>{$escaped}</t></si>";
        }
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$ssCount.'" uniqueCount="'.$ssCount.'">'
            . $ssEntries . '</sst>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $dotRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',       $contentTypes);
        $zip->addFromString('_rels/.rels',                $dotRels);
        $zip->addFromString('xl/workbook.xml',            $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml',   $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml',       $ssXml);
        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);
        return $content;
    }

    /**
     * Return an xlsx file download response.
     * Uses a temp file + response()->download() to avoid output buffer corruption.
     */
    protected function streamXlsx(string $filename, array $data)
    {
        $content = $this->buildXlsx($data);

        // Write to a temp file so Laravel can stream it cleanly
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_dl_');
        file_put_contents($tmp, $content);

        return response()->download($tmp, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length'      => strlen($content),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
        ])->deleteFileAfterSend(true);
    }

    private function xlsxColToIndex(string $col): int
    {
        $col = strtoupper($col);
        $idx = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $idx - 1;
    }

    private function xlsxIndexToCol(int $idx): string
    {
        $letter = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $letter = chr(65 + ($idx % 26)) . $letter;
            $idx    = intval($idx / 26);
        }
        return $letter;
    }
}
