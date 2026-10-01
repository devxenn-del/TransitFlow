<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the activity / audit trail (docs/PARITY_CHECKLIST.md §L).
 * Append-only. Written through `App\Support\Audit`.
 *
 * Not using BelongsToCompany: a platform action has `company_id = null`, and
 * the log must survive a company-scoped clean-up.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** Action prefix of the Super-Admin-only fee / pricing / billing entries. */
    public const PLATFORM_BILLING_PREFIX = 'billing.';

    protected $fillable = [
        'company_id', 'user_id', 'user_name', 'action',
        'subject_type', 'subject_id', 'subject_label', 'context', 'ip', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Hides Super-Admin-only entries from company users: fee and pricing
     * changes (`billing.*`) carry standard vs special amounts and internal
     * pricing notes, which a company must never see — even its own.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isSuperAdmin()) {
            $query->where('action', 'not like', self::PLATFORM_BILLING_PREFIX.'%');
        }
    }
}
