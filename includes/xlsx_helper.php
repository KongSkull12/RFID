<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Stream an XLSX file to the browser.
 *
 * @param string   $filename  Filename without extension (e.g. "students_export")
 * @param string[] $headers   Column header labels
 * @param array[]  $rows      Data rows (each row is a sequential array matching headers)
 * @param int[]    $textCols  0-based column indexes that must be forced to text
 *                            (prevents Excel from converting phone/LRN/RFID to numbers)
 * @param int[]    $dateCols  0-based column indexes formatted as date (dd/mm/yyyy)
 * @param int[]    $widths    Optional per-column width overrides keyed by 0-based index
 */
function xlsxExport(
    string $filename,
    array $headers,
    array $rows,
    array $textCols = [],
    array $dateCols = [],
    array $widths   = []
): never {
    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    /* ── Header row styling ─────────────────────────────────── */
    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F46E5']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE2E8F0']]],
    ];

    foreach ($headers as $colIdx => $label) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
        $cell      = $sheet->getCell($colLetter . '1');
        $cell->setValue($label);
        $sheet->getStyle($colLetter . '1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(22);
    }

    /* ── Data rows ──────────────────────────────────────────── */
    $textColSet = array_flip($textCols);
    $dateColSet = array_flip($dateCols);
    $rowNum     = 2;

    foreach ($rows as $rowData) {
        $rowData = array_values($rowData);
        foreach ($rowData as $colIdx => $value) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $cellAddr  = $colLetter . $rowNum;
            $cell      = $sheet->getCell($cellAddr);
            $strVal    = trim((string) $value);

            if (isset($textColSet[$colIdx])) {
                // Force text — prevents scientific notation for phone/LRN/RFID
                $cell->setValueExplicit($strVal, DataType::TYPE_STRING);
                $sheet->getStyle($cellAddr)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            } elseif (isset($dateColSet[$colIdx]) && $strVal !== '' && $strVal !== '-') {
                // Parse date and set as Excel date value with dd/mm/yyyy display
                $ts = strtotime($strVal);
                if ($ts !== false) {
                    $cell->setValue(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
                    $sheet->getStyle($cellAddr)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
                } else {
                    $cell->setValue($strVal);
                }
            } else {
                $cell->setValue($strVal !== '' ? $strVal : '');
            }
        }

        // Alternating row background
        if ($rowNum % 2 === 0) {
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($rowData));
            $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)
                  ->getFill()
                  ->setFillType(Fill::FILL_SOLID)
                  ->getStartColor()->setARGB('FFF8FAFC');
        }

        $rowNum++;
    }

    /* ── Auto-width columns ─────────────────────────────────── */
    $colCount = count($headers);
    for ($i = 0; $i < $colCount; $i++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        if (isset($widths[$i])) {
            $sheet->getColumnDimension($colLetter)->setWidth($widths[$i]);
        } else {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    /* ── Freeze header row & enable auto-filter ─────────────── */
    $sheet->freezePane('A2');
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
    $sheet->setAutoFilter('A1:' . $lastColLetter . '1');

    /* ── Output ─────────────────────────────────────────────── */
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Read an uploaded XLSX or CSV file and return all rows as arrays.
 * The first row (header) is returned as row index 0.
 *
 * @return array<int, array<int, string>>
 */
function xlsxImportRows(string $filePath): array
{
    $spreadsheet = IOFactory::load($filePath);
    $sheet       = $spreadsheet->getActiveSheet();
    $result      = [];

    foreach ($sheet->getRowIterator() as $row) {
        $cells = [];
        foreach ($row->getCellIterator() as $cell) {
            // Use formatted value so dates look like "2010-01-15" not a serial number
            $cells[] = trim((string) $cell->getFormattedValue());
        }
        // Pad short rows to at least 24 columns (matches student template)
        while (count($cells) < 24) {
            $cells[] = '';
        }
        $result[] = $cells;
    }

    return $result;
}
