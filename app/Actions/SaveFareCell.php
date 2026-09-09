<?php

namespace App\Actions;

use App\Models\FareMatrix;
use App\Models\Franchise;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

/**
 * One grid cell = one (origin → destination) pair within a franchise.
 * Writing a fare into a cell upserts the underlying route AND its
 * fare_matrix row (BITS' updateFare.php is an upsert too). Passing
 * amount = null clears the fare (the route stays, becomes "unpriced") —
 * matching BITS' deleteFare.php.
 */
class SaveFareCell
{
    /**
     * @return array{route_id:int, origin:string, destination:string, amount:float|null, discounted_amount:float|null, status:string}
     */
    public function handle(
        Franchise $franchise,
        string $origin,
        string $destination,
        ?float $amount,
        ?float $discountedAmount = null,
        bool $touchDiscount = false,
    ): array {
        return DB::transaction(function () use ($franchise, $origin, $destination, $amount, $discountedAmount, $touchDiscount): array {
            $route = Route::withoutGlobalScopes()->firstOrCreate(
                [
                    'franchise_id' => $franchise->id,
                    'origin' => $origin,
                    'destination' => $destination,
                ],
                [
                    'company_id' => $franchise->company_id,
                    'name' => "{$origin} - {$destination}",
                    'status' => 'Active',
                ],
            );

            $fare = FareMatrix::withoutGlobalScopes()->firstOrNew(['route_id' => $route->id]);
            $fare->company_id = $franchise->company_id;

            if ($amount === null) {
                // Clear the fare — route goes back to unpriced.
                $fare->exists && $fare->delete();

                return $this->cell($route, null);
            }

            $fare->amount = round($amount, 2);
            $fare->status ??= 'Active';

            if ($touchDiscount) {
                $fare->discounted_amount = $discountedAmount !== null ? round($discountedAmount, 2) : null;
            }

            $fare->save();

            return $this->cell($route, $fare);
        });
    }

    /**
     * @return array{route_id:int, origin:string, destination:string, amount:float|null, discounted_amount:float|null, status:string}
     */
    private function cell(Route $route, ?FareMatrix $fare): array
    {
        return [
            'route_id' => $route->id,
            'origin' => $route->origin,
            'destination' => $route->destination,
            'amount' => $fare ? (float) $fare->amount : null,
            'discounted_amount' => $fare && $fare->discounted_amount !== null ? (float) $fare->discounted_amount : null,
            'status' => $fare->status ?? 'Unpriced',
        ];
    }
}
