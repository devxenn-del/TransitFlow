<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SaveFareCell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ImportFareMatrixRequest;
use App\Models\Franchise;
use App\Models\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel round-trip for a franchise's fare matrix.
 *
 * The template is the same grid shape shown on screen — origins down
 * column A, destinations across row 1, one fare per cell — so filling it in
 * and re-importing is obvious. Import is additive by default: a blank cell
 * is left untouched (pass clear_blanks=1 to wipe those fares instead).
 */
class FareMatrixImportExportController extends Controller
{
    private const DIAGONAL = '—';

    /**
     * GET /api/company/franchises/{franchise}/fare-matrix/template
     */
    public function template(Franchise $franchise): StreamedResponse
    {
        $this->authorize('view', $franchise);

        $stops = $franchise->stops()->pluck('name')->values()->all();
        $cells = $this->cellMap($franchise);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Fare Matrix');

        $sheet->setCellValue('A1', 'Origin \\ Destination');
        foreach ($stops as $c => $dest) {
            $sheet->setCellValueExplicit(
                [$c + 2, 1], $dest, DataType::TYPE_STRING
            );
        }

        foreach ($stops as $r => $origin) {
            $row = $r + 2;
            $sheet->setCellValueExplicit(
                [1, $row], $origin, DataType::TYPE_STRING
            );

            foreach ($stops as $c => $dest) {
                $col = $c + 2;
                if ($origin === $dest) {
                    $sheet->setCellValue([$col, $row], self::DIAGONAL);

                    continue;
                }
                $amount = $cells[$origin][$dest] ?? null;
                if ($amount !== null) {
                    $sheet->setCellValue([$col, $row], $amount);
                }
            }
        }

        // Header styling + frozen panes + autosize.
        $lastCol = Coordinate::stringFromColumnIndex(count($stops) + 1);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle('A1:A'.(count($stops) + 1))->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8A33D');
        $sheet->getStyle("A1:{$lastCol}".(count($stops) + 1))->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('B2');
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setWidth($col === 'A' ? 26 : 14);
        }

        $filename = 'fare-matrix-'.Str::slug($franchise->applicant_name).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * POST /api/company/franchises/{franchise}/fare-matrix/import
     */
    public function import(ImportFareMatrixRequest $request, Franchise $franchise, SaveFareCell $save): array
    {
        $stopSet = $franchise->stops()->pluck('name')->flip(); // name => index, for O(1) membership
        $clearBlanks = $request->boolean('clear_blanks');

        $rows = IOFactory::load($request->file('file')->getRealPath())
            ->getActiveSheet()
            ->toArray(null, true, false, false);

        if (count($rows) < 2) {
            return ['updated' => 0, 'cleared' => 0, 'skipped' => 0, 'errors' => ['The sheet is empty.']];
        }

        // Row 0 = header: [ "Origin \ Destination", dest1, dest2, ... ]
        $destinations = array_slice($rows[0], 1);

        $updated = $cleared = $skipped = 0;
        $errors = [];

        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            $origin = trim((string) ($row[0] ?? ''));
            if ($origin === '') {
                continue;
            }
            if (! $stopSet->has($origin)) {
                $errors[] = 'Row '.($rowIndex + 2).": unknown stop \"{$origin}\" — skipped.";

                continue;
            }

            foreach ($destinations as $i => $destination) {
                $destination = trim((string) $destination);
                $raw = $row[$i + 1] ?? null;
                $value = is_string($raw) ? trim($raw) : $raw;

                if ($destination === '' || $origin === $destination || $value === self::DIAGONAL) {
                    continue;
                }
                if (! $stopSet->has($destination)) {
                    continue; // header column for an unknown stop
                }

                if ($value === null || $value === '') {
                    if ($clearBlanks) {
                        $save->handle($franchise, $origin, $destination, null);
                        $cleared++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                if (! is_numeric($value) || (float) $value < 0) {
                    $errors[] = "{$origin} → {$destination}: \"{$value}\" is not a valid fare — skipped.";
                    $skipped++;

                    continue;
                }

                $save->handle($franchise, $origin, $destination, (float) $value);
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function cellMap(Franchise $franchise): array
    {
        $map = [];
        $routes = Route::withoutGlobalScopes()
            ->where('franchise_id', $franchise->id)
            ->with('fareMatrix')
            ->get();

        foreach ($routes as $route) {
            if ($route->fareMatrix) {
                $map[$route->origin][$route->destination] = (float) $route->fareMatrix->amount;
            }
        }

        return $map;
    }
}
