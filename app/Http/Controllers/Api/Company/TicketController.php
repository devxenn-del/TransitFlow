<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every ticket the bound company has issued, across all trips — the office
 * counterpart of a conductor's per-trip ticket list. Auto-scoped by
 * CompanyScope; gated by `permission:tripmonitoring.view` (the same
 * oversight key that already exposes a trip's tickets in Trip Monitoring).
 */
class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = Ticket::query()
            ->with(['route', 'passengerType', 'trip.conductor'])
            ->when($request->date('from'), fn ($q, $from) => $q->where('issued_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('issued_at', '<=', $to->endOfDay()))
            ->when($request->string('payment_method')->isNotEmpty(), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->whereHas('trip', fn ($trip) => $trip
                ->where('reference', 'like', "%{$request->string('q')}%")
                ->orWhere('bus_number', 'like', "%{$request->string('q')}%")))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return TicketResource::collection($tickets);
    }
}
