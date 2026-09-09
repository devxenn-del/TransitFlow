<?php

namespace App\Models;

use Database\Factories\DataOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audit record for a per-company data tool run — export or clean-data
 * (docs/MIGRATION_MAP.md §K). Append-only.
 *
 * Not using BelongsToCompany: it is written and read with an explicit
 * `company_id` from the authenticated user, and must survive a clean-data
 * run that clears the transactional tables around it.
 */
class DataOperation extends Model
{
    /** @use HasFactory<DataOperationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPES = ['export', 'clean'];

    protected $fillable = [
        'company_id', 'type', 'performed_by', 'performed_by_name', 'summary', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
