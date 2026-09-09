<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Clones the platform role templates into a company so it starts with a
 * sensible set of editable roles — Company Admin, Manager, Chairman,
 * Office, Conductor (docs/MIGRATION_MAP.md §5). Each clone copies the
 * template's default permission grants. Idempotent.
 */
class SeedCompanyRoles
{
    public function handle(Company $company): void
    {
        $templates = Role::query()->templates()->where('is_platform', false)->orderBy('sort_order')->get();

        DB::transaction(function () use ($company, $templates): void {
            foreach ($templates as $template) {
                $role = Role::query()->firstOrCreate(
                    ['company_id' => $company->id, 'key' => $template->key],
                    [
                        'name' => $template->name,
                        'description' => $template->description,
                        'is_platform' => false,
                        'is_admin' => $template->is_admin,
                        'sort_order' => $template->sort_order,
                    ],
                );

                if ($role->wasRecentlyCreated) {
                    $grants = $template->permissions()
                        ->wherePivot('allowed', true)
                        ->pluck('permissions.id')
                        ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                        ->all();

                    $role->permissions()->sync($grants);
                }
            }
        });
    }
}
