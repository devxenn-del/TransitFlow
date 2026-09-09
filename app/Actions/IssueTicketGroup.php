<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\TicketGroup;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a group ticket — one printed ticket / one payment for a party
 * boarding together (BITS `ticket_groups`, docs/MIGRATION_MAP.md §4.1).
 *
 *  - 1–30 passenger lines; each line resolves + creates its own `tickets`
 *    rows via App\Actions\IssueTicket (fare always server-resolved)
 *  - all-or-nothing: one line failing rolls the whole group back
 *  - payment method / QR reference / boarding type are shared by the group
 *  - `client_uuid` replay returns the original group, never re-charges
 */
class IssueTicketGroup
{
    private const MAX_LINES = 30;

    public function __construct(private readonly IssueTicket $issueTicket) {}

    /**
     * @param  array{lines:list<array<string,mixed>>, boarding_type:string, payment_method:string, qr_reference?:string|null, client_uuid?:string|null}  $input
     */
    public function handle(User $conductor, Trip $trip, array $input): TicketGroup
    {
        if (! $trip->isLive()) {
            throw ValidationException::withMessages(['trip' => 'This trip is not accepting tickets.']);
        }

        $clientUuid = trim((string) ($input['client_uuid'] ?? '')) ?: null;
        if ($clientUuid !== null) {
            $existing = $trip->ticketGroups()->where('client_uuid', $clientUuid)->first();
            if ($existing !== null) {
                return $existing->load('tickets');
            }
        }

        $lines = array_values($input['lines'] ?? []);
        if (count($lines) < 1 || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'A group ticket needs 1 to '.self::MAX_LINES.' passenger lines.']);
        }

        $shared = [
            'boarding_type' => $input['boarding_type'] ?? 'Terminal',
            'payment_method' => $input['payment_method'] ?? 'Cash',
            'qr_reference' => $input['qr_reference'] ?? null,
        ];

        return DB::transaction(function () use ($conductor, $trip, $lines, $shared, $clientUuid): TicketGroup {
            $group = TicketGroup::query()->create([
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                ...$shared,
                'issued_by' => $conductor->id,
                'issued_at' => now(),
                'client_uuid' => $clientUuid,
            ]);

            $passengerCount = 0;
            $totalFare = 0.0;

            foreach ($lines as $i => $line) {
                try {
                    $tickets = $this->issueTicket->handle($trip, [
                        ...$line,
                        ...$shared,
                        'client_uuid' => null,
                    ]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(
                        collect($e->errors())
                            ->mapWithKeys(fn (array $messages, string $key) => ["lines.{$i}.{$key}" => $messages])
                            ->all()
                    );
                }

                Ticket::query()->whereIn('id', $tickets->pluck('id'))->update(['ticket_group_id' => $group->id]);
                $passengerCount += $tickets->count();
                $totalFare += (float) $tickets->sum('fare');
            }

            $group->update([
                'line_count' => count($lines),
                'passenger_count' => $passengerCount,
                'total_fare' => round($totalFare, 2),
            ]);

            return $group->fresh()->load('tickets');
        });
    }
}
