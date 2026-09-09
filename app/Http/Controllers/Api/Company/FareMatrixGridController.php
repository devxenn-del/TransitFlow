<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\SaveFareCell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SaveFareCellRequest;
use App\Http\Resources\FranchiseResource;
use App\Models\Franchise;
use App\Models\Route;

/**
 * The franchise fare-matrix grid — the BITS "Fare Matrix" screen, per
 * franchise. `show` returns the axes (ordered stop names) and the sparse
 * cell map; `saveCell` upserts one cell (route + fare) and saves instantly,
 * exactly like BITS' per-input autosave.
 */
class FareMatrixGridController extends Controller
{
    /**
     * GET /api/company/franchises/{franchise}/fare-matrix
     */
    public function show(Franchise $franchise): array
    {
        $this->authorize('view', $franchise);

        $stops = $franchise->stops()->pluck('name')->values();

        $routes = Route::withoutGlobalScopes()
            ->where('franchise_id', $franchise->id)
            ->with('fareMatrix')
            ->get();

        // cells[origin][destination] => { route_id, amount, discounted_amount, status }
        $cells = [];
        foreach ($routes as $route) {
            $fare = $route->fareMatrix;
            $cells[$route->origin][$route->destination] = [
                'route_id' => $route->id,
                'amount' => $fare ? (float) $fare->amount : null,
                'discounted_amount' => $fare && $fare->discounted_amount !== null ? (float) $fare->discounted_amount : null,
                'status' => $fare?->status ?? 'Unpriced',
            ];
        }

        return [
            'franchise' => FranchiseResource::make($franchise->loadCount(['stops', 'routes'])),
            'stops' => $stops,
            'cells' => (object) $cells, // object even when empty
        ];
    }

    /**
     * PUT /api/company/franchises/{franchise}/fare-matrix/cell
     * body: { origin, destination, amount|null, discounted_amount?, touch_discount? }
     */
    public function saveCell(SaveFareCellRequest $request, Franchise $franchise, SaveFareCell $save): array
    {
        $cell = $save->handle(
            franchise: $franchise,
            origin: $request->string('origin')->value(),
            destination: $request->string('destination')->value(),
            amount: $request->has('amount') && $request->input('amount') !== null ? (float) $request->input('amount') : null,
            discountedAmount: $request->input('discounted_amount') !== null ? (float) $request->input('discounted_amount') : null,
            touchDiscount: $request->boolean('touch_discount'),
        );

        return ['cell' => $cell];
    }
}
