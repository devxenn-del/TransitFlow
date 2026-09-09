<?php

namespace App\Actions;

use App\Models\PassengerType;
use App\Models\Route;
use App\Models\Ticket;
use App\Models\Trip;
use App\Support\FareCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues one or more tickets on a live trip, applying BITS' pricing and
 * validation (docs/MIGRATION_MAP.md §4.1 — api/tickets/issue.php):
 *
 *  - the trip must be live; once On-Trip only Pickup boarding is allowed
 *  - the fare is ALWAYS resolved here from DB rows, never trusted from the
 *    client (passenger-type discount, fare-matrix amount, or a Manual
 *    Amount type's article preset / typed amount)
 *  - `client_uuid` makes this idempotent: the same uuid returns the
 *    original ticket(s) instead of re-charging
 */
class IssueTicket
{
    /**
     * @param  array{passenger_type_id:int, route_id?:int|null, manual_amount?:float|null, article_label?:string|null, boarding_type:string, payment_method:string, qr_reference?:string|null, quantity?:int, client_uuid?:string|null}  $input
     * @return Collection<int, Ticket>
     */
    public function handle(Trip $trip, array $input): Collection
    {
        if (! $trip->isLive()) {
            throw ValidationException::withMessages(['trip' => 'This trip is not accepting tickets.']);
        }

        $clientUuid = trim((string) ($input['client_uuid'] ?? '')) ?: null;
        if ($clientUuid !== null) {
            $existing = $trip->tickets()->where('client_uuid', $clientUuid)->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                return $existing; // replay — never double-issue
            }
        }

        $boardingType = ($input['boarding_type'] ?? 'Terminal') === 'Pickup' ? 'Pickup' : 'Terminal';
        if ($boardingType === 'Terminal' && $trip->hasLeftTerminal()) {
            throw ValidationException::withMessages([
                'boarding_type' => 'This trip has already left the terminal — issue as Via Pick-up.',
            ]);
        }

        $paymentMethod = in_array($input['payment_method'] ?? 'Cash', Ticket::PAYMENT_METHODS, true)
            ? $input['payment_method'] : 'Cash';

        $qrReference = null;
        if ($paymentMethod === 'QR') {
            $qrReference = strtoupper(trim((string) ($input['qr_reference'] ?? '')));
            if (strlen($qrReference) !== 6) {
                throw ValidationException::withMessages([
                    'qr_reference' => 'Enter the last 6 characters of the payment reference.',
                ]);
            }
        }

        $passengerType = PassengerType::query()
            ->where('id', $input['passenger_type_id'] ?? 0)
            ->where('status', 'Active')
            ->first();
        if ($passengerType === null) {
            throw ValidationException::withMessages(['passenger_type_id' => 'Pick a valid passenger type.']);
        }

        [$routeId, $articleLabel, $fare] = $passengerType->isManualAmount()
            ? $this->resolveManual($passengerType, $input)
            : $this->resolveFareMatrix($passengerType, $trip, $boardingType, $input);

        $quantity = max(1, min(50, (int) ($input['quantity'] ?? 1)));

        return DB::transaction(function () use ($trip, $quantity, $routeId, $passengerType, $boardingType, $paymentMethod, $qrReference, $articleLabel, $fare, $clientUuid): Collection {
            $tickets = new Collection;
            for ($i = 0; $i < $quantity; $i++) {
                $tickets->push($trip->tickets()->create([
                    'company_id' => $trip->company_id,
                    'route_id' => $routeId,
                    'passenger_type_id' => $passengerType->id,
                    'boarding_type' => $boardingType,
                    'payment_method' => $paymentMethod,
                    'qr_reference' => $qrReference,
                    'article_label' => $articleLabel,
                    'fare' => $fare,
                    'issued_at' => now(),
                    'client_uuid' => $clientUuid,
                ]));
            }

            return $tickets;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:null, 1:string|null, 2:float}
     */
    private function resolveManual(PassengerType $type, array $input): array
    {
        $activeArticles = $type->articles()->where('status', 'Active')->get();

        if ($activeArticles->isNotEmpty()) {
            $wanted = trim((string) ($input['article_label'] ?? ''));
            $article = $activeArticles->first(fn ($a) => strcasecmp(trim($a->label), $wanted) === 0);
            if ($article === null) {
                throw ValidationException::withMessages(['article_label' => 'Pick a valid article for this sale.']);
            }

            return [null, $article->label, (float) $article->amount];
        }

        $amount = (float) ($input['manual_amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['manual_amount' => 'Enter an amount greater than zero.']);
        }

        return [null, null, round($amount, 2)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:int, 1:null, 2:float}
     */
    private function resolveFareMatrix(PassengerType $type, Trip $trip, string $boardingType, array $input): array
    {
        $query = Route::query()
            ->where('id', $input['route_id'] ?? 0)
            ->priced()
            ->with('fareMatrix');

        // A Via-Terminal sale can only start from where the trip originates.
        if ($boardingType === 'Terminal') {
            $query->where('origin', $trip->coverage_origin !== '' ? $trip->coverage_origin : $trip->origin);
        }

        $route = $query->first();
        if ($route === null) {
            throw ValidationException::withMessages(['route_id' => 'Pick a valid, priced origin and destination.']);
        }

        $fare = FareCalculator::compute(
            (float) $route->fareMatrix->amount,
            $route->fareMatrix->discounted_amount !== null ? (float) $route->fareMatrix->discounted_amount : null,
            (float) $type->discount_percent,
        );

        return [$route->id, null, $fare];
    }
}
