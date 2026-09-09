<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\DataOperation;
use App\Models\User;
use App\Support\CompanyDataTables;
use Illuminate\Support\Facades\DB;

/**
 * Wipes a company's transactional rows while keeping its structure and
 * master data — BITS "Clean Data" (docs/MIGRATION_MAP.md §K).
 *
 * Only the tables in `CompanyDataTables::TRANSACTIONAL` are touched, and only
 * rows for `$company`. Runs in one transaction and writes a `DataOperation`
 * audit row with a per-table count of what was removed.
 */
class CleanCompanyData
{
    /**
     * @return array<string, int> table => rows deleted
     */
    public function handle(Company $company, User $actor): array
    {
        return DB::transaction(function () use ($company, $actor) {
            $summary = [];

            foreach (CompanyDataTables::TRANSACTIONAL as $table) {
                $summary[$table] = DB::table($table)->where('company_id', $company->id)->delete();
            }

            DataOperation::query()->create([
                'company_id' => $company->id,
                'type' => 'clean',
                'performed_by' => $actor->id,
                'performed_by_name' => $actor->name,
                'summary' => $summary,
                'created_at' => now(),
            ]);

            return $summary;
        });
    }

    /**
     * The row counts a clean run would remove, without removing anything.
     *
     * @return array<string, int>
     */
    public function preview(Company $company): array
    {
        $counts = [];

        foreach (CompanyDataTables::TRANSACTIONAL as $table) {
            $counts[$table] = DB::table($table)->where('company_id', $company->id)->count();
        }

        return $counts;
    }
}
