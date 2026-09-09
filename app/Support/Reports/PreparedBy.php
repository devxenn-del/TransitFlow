<?php

namespace App\Support\Reports;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The single "Prepared By" signatory block every report carries — PDF and
 * on-screen alike (BITS `App\PreparedBy`, docs/MIGRATION_MAP.md §J).
 *
 * Shows four things and nothing else: name, a signature line, role, and the
 * moment the report was generated (app timezone, `config/app.php`). Name and
 * role come from the account that requested the report.
 */
class PreparedBy
{
    /**
     * @return array{name: string, role: string, date: string, time: string, when: string}
     */
    public static function forUser(User $user): array
    {
        $name = trim((string) $user->name);
        $roleName = $user->accessRole?->name ?? $user->role;
        $role = ucwords(str_replace('_', ' ', trim((string) $roleName)));

        $now = Carbon::now();
        $date = $now->format('F d, Y');
        $time = $now->format('g:i A');

        return [
            'name' => $name,
            'role' => $role,
            'date' => $date,
            'time' => $time,
            'when' => $date.' – '.$time,
        ];
    }
}
