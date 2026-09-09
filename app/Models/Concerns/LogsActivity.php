<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-writes an `audit_logs` row whenever the model is created, updated or
 * deleted (docs/PARITY_CHECKLIST.md §L). The action is
 * `<snake-cased basename>.<event>` (e.g. `company_setting.updated`) and the
 * context carries the list of changed columns.
 *
 * For action-style events that are not a model save (approve a remittance,
 * force-end a trip, …) call `App\Support\Audit::record()` directly instead.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn (Model $model) => Audit::record(
            self::auditKey($model).'.created',
            $model,
        ));

        static::updated(function (Model $model) {
            $changed = array_values(array_diff(
                array_keys($model->getChanges()),
                ['updated_at', 'created_at'],
            ));

            if ($changed === []) {
                return;
            }

            Audit::record(self::auditKey($model).'.updated', $model, ['changed' => $changed]);
        });

        static::deleted(fn (Model $model) => Audit::record(
            self::auditKey($model).'.deleted',
            $model,
        ));
    }

    private static function auditKey(Model $model): string
    {
        return str(class_basename($model))->snake()->toString();
    }
}
