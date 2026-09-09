<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Support\Reports\BusIncomeReport;
use App\Support\Reports\CashCountReport;
use App\Support\Reports\DailyOperationsReport;
use App\Support\Reports\ExpenseReport;
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
 * `admin/dailyoperations.php`, `admin/expensereport.php`,
 * `admin/cashcountreport.php`, `admin/fuelenergyreport.php`
 * (docs/MIGRATION_MAP.md §J).
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

    public function dailyOperations(Request $request, DailyOperationsReport $report): JsonResponse|StreamedResponse
    {
        [$from, $to, $busId] = $this->rangeAndBus($request);

        $data = $report->generate($from, $to, $busId);

        return $this->deliver($request, 'daily-operations', 'Daily Operations Report', $data, function () use ($data) {
            $rows = array_map(fn (array $r) => [
                $r['bus_number'], $r['trips_count'], $r['passenger_total'],
                $r['terminal_income'], $r['pickup_income'], $r['gross_income'],
                $r['dispatch_total'], $r['operational_expenses'], $r['remaining_income'],
                $r['cash_counted'], $r['morning_net'], $r['evening_net'],
            ], $data['rows']);

            $s = $data['summary'];

            return [
                'headings' => ['Bus', 'Trips', 'Pax', 'Terminal', 'Pickup', 'Gross', 'Dispatch', 'Op. Exp.', 'Remaining', 'Cash Counted', 'AM Net', 'PM Net'],
                'rows' => $rows,
                'totals' => ['TOTAL', $s['trips_count'], $s['total_passenger'], $s['terminal'], $s['pickup'], $s['gross_income'], $s['dispatch'], $s['operational_expenses'], $s['remaining_income'], $s['cash_counted'], $s['morning_net'], $s['evening_net']],
            ];
        });
    }

    public function expenses(Request $request, ExpenseReport $report): JsonResponse|StreamedResponse
    {
        [$from, $to, $busId] = $this->rangeAndBus($request);

        $data = $report->generate($from, $to, $busId);

        return $this->deliver($request, 'expenses', 'Operational Expense Report', $data, function () use ($data) {
            $rows = [];
            foreach ($data['days'] as $day) {
                foreach ($day['items'] as $item) {
                    $rows[] = [
                        $day['op_date'], $item['bus_number'], $item['shift'],
                        $item['category'], $item['description'], $item['amount'], $item['recorded_by_name'],
                    ];
                }
            }

            return [
                'headings' => ['Op. Date', 'Bus', 'Shift', 'Category', 'Description', 'Amount', 'Recorded By'],
                'rows' => $rows,
                'totals' => ['GRAND TOTAL', '', '', '', '', $data['grand_total'], ''],
            ];
        });
    }

    public function cashCount(Request $request, CashCountReport $report): JsonResponse|StreamedResponse
    {
        [$from, $to, $busId] = $this->rangeAndBus($request);

        $data = $report->generate($from, $to, $busId);

        return $this->deliver($request, 'cash-count', 'Cash Count Report', $data, function () use ($data) {
            $rows = array_map(function (array $r) {
                $denoms = array_map(fn (int $d) => $r['denominations'][$d] ?? 0, CashCountReport::DENOMINATIONS);

                return array_merge(
                    [$r['op_date'], $r['bus_number'], $r['shift']],
                    $denoms,
                    [$r['counted_total'], $r['remitted_total'], $r['variance'], $r['net_cash']],
                );
            }, $data['rows']);

            $denomHeadings = array_map(fn (int $d) => (string) $d, CashCountReport::DENOMINATIONS);
            $denomTotals = array_map(fn (int $d) => $data['denom_totals'][$d] ?? 0, CashCountReport::DENOMINATIONS);

            return [
                'headings' => array_merge(['Op. Date', 'Bus', 'Shift'], $denomHeadings, ['Counted', 'Remitted', 'Variance', 'Net Cash']),
                'rows' => $rows,
                'totals' => array_merge(['TOTAL', '', ''], $denomTotals, [$data['grand_total'], $data['remitted_total'], '', $data['net_cash_total']]),
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
     * @return array{0: Carbon, 1: Carbon, 2: ?int}
     */
    private function rangeAndBus(Request $request): array
    {
        $from = $request->date('from') ?? Carbon::today();
        $to = $request->date('to') ?? $from->copy();
        $busId = $request->filled('bus_id') ? $request->integer('bus_id') : null;

        return [$from, $to, $busId];
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
