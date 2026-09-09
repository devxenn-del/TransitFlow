<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writes rows to the activity / audit trail (`audit_logs`) —
 * docs/PARITY_CHECKLIST.md §L.
 *
 * Best-effort: a failure here is logged-and-swallowed, never propagated, so
 * auditing can't break the action it is recording. The actor and company are
 * taken from the authenticated request unless passed explicitly.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?Company $company = null,
        ?User $actor = null,
    ): void {
        try {
            $actor ??= Auth::user();
            $companyId = $company?->id ?? $actor?->company_id;

            AuditLog::query()->create([
                'company_id' => $companyId,
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? self::label($subject) : null,
                'context' => $context ?: null,
                'ip' => self::ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A short human label for the subject — its `name` / `title` / `reference`
     * / `code` if it has one, else `Type #id`.
     */
    private static function label(Model $subject): string
    {
        foreach (['name', 'title', 'reference', 'code', 'bus_number'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }

    private static function ip(): ?string
    {
        try {
            return Request::ip();
        } catch (Throwable) {
            return null;
        }
    }
}
