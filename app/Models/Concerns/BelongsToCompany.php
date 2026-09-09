<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks an Eloquent model as company-owned.
 *
 * Applying this trait:
 *   1. adds the CompanyScope global scope, so every query is automatically
 *      constrained to the current company (App\Support\CompanyContext);
 *   2. auto-fills `company_id` on create from the current company, so
 *      application code never has to remember to set it;
 *   3. exposes the `company()` relation.
 *
 * A model with a non-standard foreign key can override
 * `getCompanyForeignKey()`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            $key = $model->getCompanyForeignKey();

            if ($model->getAttribute($key) === null) {
                $model->setAttribute($key, app(CompanyContext::class)->companyId());
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, $this->getCompanyForeignKey());
    }

    public function getCompanyForeignKey(): string
    {
        return 'company_id';
    }
}
