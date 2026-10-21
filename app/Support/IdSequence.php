<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use OverflowException;

/**
 * Concurrency-safe named counters (`id_sequences`). next() locks the
 * sequence's row for the rest of the caller's transaction, so two
 * simultaneous callers are serialized and can never be handed the same
 * number — unlike `MAX(id) + 1`. Must be called inside a DB transaction;
 * if that transaction rolls back, the number is given back too.
 */
class IdSequence
{
    /**
     * The next value of `$name`, or OverflowException once `$max` has been
     * handed out (the caller's identifier format has run out of room).
     */
    public static function next(string $name, int $max): int
    {
        $row = DB::table('id_sequences')->where('name', $name)->lockForUpdate()->first();

        if ($row === null) {
            DB::table('id_sequences')->insertOrIgnore(['name' => $name, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('id_sequences')->where('name', $name)->lockForUpdate()->first();
        }

        $next = (int) $row->last_value + 1;

        if ($next > $max) {
            throw new OverflowException("The {$name} sequence has reached its limit of {$max}.");
        }

        DB::table('id_sequences')->where('name', $name)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }
}
