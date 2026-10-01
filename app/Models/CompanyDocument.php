<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\LogsActivity;
use App\Models\Scopes\CompanyScope;
use Database\Factories\CompanyDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file in a company's document library — any official or internal paper
 * the company keeps: franchises and fare matrices, registration and
 * permits, insurance, vehicle OR/CR, contracts, financial and HR records.
 * The file lives on the private `local` disk; it is only reachable through
 * the authenticated, company-scoped download endpoint.
 */
class CompanyDocument extends Model
{
    /** @use HasFactory<CompanyDocumentFactory> */
    use BelongsToCompany, HasFactory, LogsActivity;

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'franchise' => 'Franchise / CPC',
        'fare_matrix' => 'Fare Matrix',
        'registration' => 'Business Registration',
        'permit' => 'Permits & Licenses',
        'vehicle' => 'Vehicle Records (OR/CR)',
        'insurance' => 'Insurance',
        'contract' => 'Contracts & Agreements',
        'financial' => 'Financial & Tax',
        'hr' => 'HR & Personnel',
        'policy' => 'Policies & Memos',
        'other' => 'Other',
    ];

    /**
     * File kind => extensions, for the library's "file type" filter.
     *
     * @var array<string, list<string>>
     */
    public const FILE_KINDS = [
        'pdf' => ['pdf'],
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'document' => ['doc', 'docx', 'txt', 'rtf', 'odt'],
        'spreadsheet' => ['xls', 'xlsx', 'csv', 'ods'],
        'presentation' => ['ppt', 'pptx', 'odp'],
    ];

    /** Library views that filter by expiration. */
    public const STATUSES = ['valid', 'expiring', 'expired', 'no_expiry'];

    /** A document this many days or fewer from its expiration date is flagged "expiring". */
    public const EXPIRING_WITHIN_DAYS = 30;

    protected $fillable = [
        'company_id',
        'name',
        'category',
        'description',
        'is_important',
        'reference_number',
        'issued_at',
        'expires_at',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_important' => 'boolean',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'size' => 'integer',
        ];
    }

    /**
     * Unscoped: the uploader may be a Super Admin, who belongs to no company.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * Derived from the expiration date: `valid`, `expiring` (within
     * EXPIRING_WITHIN_DAYS) or `expired`. A document with no expiration is
     * always `valid`.
     */
    public function status(): string
    {
        if ($this->expires_at === null) {
            return 'valid';
        }

        if ($this->expires_at->isPast() && ! $this->expires_at->isToday()) {
            return 'expired';
        }

        return now()->startOfDay()->diffInDays($this->expires_at) <= self::EXPIRING_WITHIN_DAYS ? 'expiring' : 'valid';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    /**
     * The file's kind (pdf / image / document / spreadsheet / presentation /
     * other), from its original extension.
     */
    public function fileKind(): string
    {
        $extension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        foreach (self::FILE_KINDS as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * Filter by an expiration view — see STATUSES.
     *
     * @param  Builder<CompanyDocument>  $query
     */
    public function scopeWithStatus(Builder $query, string $status): void
    {
        $today = today();
        $soon = today()->addDays(self::EXPIRING_WITHIN_DAYS);

        match ($status) {
            'expired' => $query->whereDate('expires_at', '<', $today),
            'expiring' => $query->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $soon),
            'valid' => $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>', $soon)),
            'no_expiry' => $query->whereNull('expires_at'),
            default => null,
        };
    }

    /**
     * Filter by file kind — see FILE_KINDS.
     *
     * @param  Builder<CompanyDocument>  $query
     */
    public function scopeOfFileKind(Builder $query, string $kind): void
    {
        $known = collect(self::FILE_KINDS)->flatten();
        $extensions = self::FILE_KINDS[$kind] ?? null;

        $query->where(function (Builder $q) use ($extensions, $known): void {
            if ($extensions !== null) {
                foreach ($extensions as $extension) {
                    $q->orWhere('original_name', 'like', "%.{$extension}");
                }

                return;
            }

            // "other": none of the known extensions.
            foreach ($known as $extension) {
                $q->where('original_name', 'not like', "%.{$extension}");
            }
        });
    }
}
