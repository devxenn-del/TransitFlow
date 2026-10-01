<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Support\Reports\BusIncomeReport;
use App\Support\Reports\FuelEnergyReport;
use App\Support\Reports\PreparedBy;
use App\Support\Reports\ReportLetterhead;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportSpreadsheet;
use App\Support\Reports\TripIncomeReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports & Analytics for the current company — BITS `admin/reports.php`,
 * `admin/fuelenergyreport.php` (docs/MIGRATION_MAP.md §J).
 *
 * Every endpoint returns JSON by default; `?format=pdf` renders the report
 * through dompdf with the company letterhead, `?format=xlsx` streams a
 * spreadsheet. Each report is company-scoped through the models' global
 * `CompanyScope`; capability gated by `permission:` on the route.
 */
class ReportController extends Controller
{
    public function income(Request $request, BusIncomeReport $report): JsonResponse|StreamedResponse
    {
        $period = (string) $request->query('period', 'daily');
        $reference = $request->date('date') ?? Carbon::today();

        $data = $report->generate($period, $reference);

        return $this->deliver($request, 'income', 'Income Monitoring — Bus Income', $data, function () use ($data) {
            $rows = array_map(fn (array $r) => [
                $r['bus_number'], $r['plate_number'], $r['trip_count'], $r['ticket_count'], $r['income'],
            ], $data['rows']);

            return [
                'headings' => ['Bus', 'Plate', 'Trips', 'Tickets', 'Income'],
                'rows' => $rows,
                'totals' => ['TOTAL', '', $data['total_trips'], $data['total_tickets'], $data['total_income']],
            ];
        });
    }

    public function tripIncome(Request $request, TripIncomeReport $report): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'bus_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $data = $report->generate((int) $validated['bus_id'], $validated['date']);
        abort_if($data === null, 404, 'Bus not found.');

        return $this->deliver($request, 'trip-income', 'Income Monitoring — Trip Income', $data, function () use ($data) {
            $rows = array_map(fn (array $r) => [
                $r['reference'], $r['origin'], $r['destination'],
                $r['terminal_count'], $r['pickup_count'], $r['passenger_count'],
                $r['fare_total'], $r['dispatch_total'], $r['net_total'],
            ], $data['rows']);

            return [
                'headings' => ['Reference', 'Origin', 'Destination', 'Terminal', 'Pickup', 'Passengers', 'Fares', 'Dispatch', 'Net'],
                'rows' => $rows,
                'totals' => ['TOTAL', '', '', '', '', $data['total_passengers'], $data['total_received'], $data['total_dispatch'], $data['total_income']],
            ];
        });
    }

    public function fuelEnergy(Request $request, FuelEnergyReport $report): JsonResponse|StreamedResponse
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $busId = $request->filled('bus_id') ? $request->integer('bus_id') : null;
        $type = $request->filled('type') ? (string) $request->query('type') : null;

        $data = $report->generate($from, $to, $busId, $type);

        return $this->deliver($request, 'fuel-energy', 'Fuel & Energy Report', $data, function () use ($data) {
            $rows = array_map(fn (array $r) => [
                $r['fueled_at'], $r['bus_number'], $r['fuel_type'],
                $r['liters'], $r['price_per_liter'], $r['amount_paid'], $r['station'],
            ], $data['fuel']['rows']);

            $t = $data['fuel']['totals'];

            return [
                'headings' => ['Fueled At', 'Bus', 'Type', 'Litres', '₱/L', 'Amount', 'Station'],
                'rows' => $rows,
                'totals' => ['TOTAL', '', '', $t['total_liters'], $t['avg_price'], $t['total_cost'], ''],
            ];
        });
    }

    /**
     * Return the report as JSON, a dompdf PDF, or a streamed xlsx depending
     * on `?format=`.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(): array{headings: list<string>, rows: list<array<int, mixed>>, totals: ?list<mixed>}  $flatten
     */
    private function deliver(Request $request, string $slug, string $title, array $data, callable $flatten): JsonResponse|StreamedResponse
    {
        $format = strtolower((string) $request->query('format', 'json'));
        $user = $request->user();
        $company = $user->company;

        if ($format === 'pdf') {
            $settings = $company?->settings;
            $paperSize = $settings->pdf_paper_size ?? 'a4';
            $orientation = $settings->pdf_orientation ?? 'landscape';
            $marginMm = (int) ($settings->pdf_margin_mm ?? 10);

            $pdf = Pdf::loadView("reports.pdf.{$slug}", [
                'title' => $title,
                'report' => $data,
                'letterhead' => $company !== null ? ReportLetterhead::forCompany($company) : null,
                'preparedBy' => PreparedBy::forUser($user),
                'generatedAt' => ReportPeriod::resolve('daily')->start,
                'pageMarginMm' => $marginMm,
            ])->setPaper($paperSize, $orientation);

            return response()->streamDownload(
                fn () => print ($pdf->output()),
                "{$slug}.pdf",
                ['Content-Type' => 'application/pdf'],
            );
        }

        if ($format === 'xlsx') {
            $flat = $flatten();
            $spreadsheet = ReportSpreadsheet::make(
                $title,
                $flat['headings'],
                $flat['rows'],
                $flat['totals'] ?? null,
            );

            return response()->streamDownload(
                fn () => (new Xlsx($spreadsheet))->save('php://output'),
                "{$slug}.xlsx",
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            );
        }

        return response()->json(['data' => $data]);
    }
}
