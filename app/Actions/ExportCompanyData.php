<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\DataOperation;
use App\Models\User;
use App\Support\CompanyDataTables;
use Illuminate\Support\Facades\DB;

/**
 * Builds a full per-company data export — every row this company owns across
 * its master and transactional tables — BITS "Backup" reworked for
 * multi-tenancy (docs/MIGRATION_MAP.md §K, open decision #3: per-company
 * export, not a full-DB dump).
 *
 * The controller streams the returned structure as a JSON download. A
 * `DataOperation` audit row (type `export`) is written with the per-table
 * row counts.
 */
class ExportCompanyData
{
    /**
     * @return array{
     *     company: array{id:int, code:string, name:string},
     *     exported_at: string,
     *     schema_version: int,
     *     tables: array<string, list<array<string, mixed>>>
     * }
     */
    public function handle(Company $company, User $actor): array
    {
        $tables = [];
        $counts = [];

        foreach ([...CompanyDataTables::MASTER, ...CompanyDataTables::TRANSACTIONAL] as $table) {
            $rows = DB::table($table)
                ->where('company_id', $company->id)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $tables[$table] = $rows;
            $counts[$table] = count($rows);
        }

        DataOperation::query()->create([
            'company_id' => $company->id,
            'type' => 'export',
            'performed_by' => $actor->id,
            'performed_by_name' => $actor->name,
            'summary' => $counts,
            'created_at' => now(),
        ]);

        return [
            'company' => ['id' => $company->id, 'code' => $company->code, 'name' => $company->name],
            'exported_at' => now()->toIso8601String(),
            'schema_version' => 1,
            'tables' => $tables,
        ];
    }
}
