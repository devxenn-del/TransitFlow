<?php

namespace App\Support\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Builds a single-sheet `.xlsx` for a report export from a title, a heading
 * row, and pre-flattened data rows — the Excel counterpart to the report PDF
 * Blade views (docs/MIGRATION_MAP.md §J uses `phpspreadsheet` for report
 * exports).
 */
class ReportSpreadsheet
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<string|int|float|null>|null  $totalsRow  optional bold footer row
     */
    public static function make(string $title, array $headings, array $rows, ?array $totalsRow = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $lastColIndex = max(1, count($headings));
        $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);

        // Title row.
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Heading row.
        $headingRow = 3;
        foreach ($headings as $i => $heading) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$headingRow}", $heading);
        }
        $sheet->getStyle("A{$headingRow}:{$lastCol}{$headingRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headingRow}:{$lastCol}{$headingRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5A623');
        $sheet->getStyle("A{$headingRow}:{$lastCol}{$headingRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Data rows.
        $row = $headingRow + 1;
        foreach ($rows as $dataRow) {
            foreach (array_values($dataRow) as $i => $value) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$row}", $value);
            }
            $row++;
        }

        if ($totalsRow !== null) {
            foreach (array_values($totalsRow) as $i => $value) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$row}", $value);
            }
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        }

        foreach (range(1, $lastColIndex) as $colIndex) {
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
