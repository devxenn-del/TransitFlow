<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single published version of the Privacy Policy or Terms of Use. See
 * App\Support\LegalDocuments for the publish/versioning business rules.
 */
class LegalDocument extends Model
{
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';

    public const TYPE_TERMS_OF_USE = 'terms_of_use';

    protected $fillable = [
        'type',
        'version',
        'title',
        'content',
        'effective_date',
        'is_active',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_date' => 'date',
        ];
    }

    /**
     * @param  Builder<LegalDocument>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public static function activeVersion(string $type): ?self
    {
        return static::query()->where('type', $type)->active()->latest('version')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
