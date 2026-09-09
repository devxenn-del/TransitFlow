<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Ticket;
use App\Models\Trip;
use App\Support\Receipts\ReceiptFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Thermal receipt DTOs — BITS `receipt/conductor/*.php`. Shared by the
 * conductor portal (its own trips) and the office Remittance / Trip
 * Monitoring screens (`tripmonitoring.view`). The rendering happens on the
 * client at `receipt_width_mm`.
 */
class ReceiptController extends Controller
{
    private const TRIP_KINDS = ['departure', 'arrival', 'remittance'];

    public function __construct(private readonly ReceiptFactory $receipts) {}

    /** GET .../{trip}/receipt/{kind} — kind: departure | arrival | remittance. */
    public function trip(Request $request, Trip $trip, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::TRIP_KINDS, true), 404);
        $this->authorize('view', $trip);

        $doc = match ($kind) {
            'departure' => $this->receipts->departure($trip),
            'arrival' => $this->receipts->arrival($trip),
            'remittance' => $this->receipts->remittance($trip),
        };

        return response()->json(['data' => $doc->toArray()]);
    }

    /** GET /api/conductor/tickets/{ticket}/receipt?qty= */
    public function ticket(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket->trip);

        $doc = $this->receipts->ticket($ticket, $request->integer('qty', 1));

        return response()->json(['data' => $doc->toArray()]);
    }

    /** GET /api/conductor/dispatches/{dispatch}/receipt */
    public function dispatch(Request $request, Dispatch $dispatch): JsonResponse
    {
        $this->authorize('view', $dispatch->trip);

        return response()->json(['data' => $this->receipts->dispatch($dispatch)->toArray()]);
    }

    /** GET /api/conductor/receipts/shift-summary?date=YYYY-MM-DD */
    public function shiftSummary(Request $request): JsonResponse
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->date('date'))
            : now();

        return response()->json([
            'data' => $this->receipts->shiftSummary($request->user(), $date)->toArray(),
        ]);
    }
}
