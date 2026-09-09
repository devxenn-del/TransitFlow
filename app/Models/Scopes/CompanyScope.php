<?php

namespace App\Models\Scopes;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied to every model that uses App\Models\Concerns\BelongsToCompany.
 *
 * It constrains all queries to the company bound in App\Support\CompanyContext.
 * When no company is bound — an unauthenticated request, or a Super Admin
 * who has not scoped into one company — it does nothing, so the caller sees
 * every row. That "sees everything" case is only ever reachable by
 * platform-level code; a company user always has a company bound by
 * ResolveCompanyContext before any query runs.
 *
 * Escape hatches, all explicit:
 *   Model::withoutGlobalScope(CompanyScope::class)      // one query
 *   Model::query()->withoutCompanyScope()               // one query, readable
 *   app(CompanyContext::class)->actAcrossCompanies(fn () => …)  // a block
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if (! $context->shouldScopeQueries()) {
            return;
        }

        $builder->where(
            $model->getTable().'.'.$model->getCompanyForeignKey(),
            $context->companyId(),
        );
    }

    /**
     * Adds the ->withoutCompanyScope() query builder macro-style helper.
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutCompanyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}
