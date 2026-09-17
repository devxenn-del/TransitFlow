<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's acceptance of the Privacy Policy / Terms of Use versions that
 * were active at the time. Append-only — see the creating migration.
 */
class LegalDocumentAcceptance extends Model
{
    protected $fillable = [
        'user_id',
        'privacy_policy_version',
        'terms_version',
        'accepted_at',
        'application_version',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
