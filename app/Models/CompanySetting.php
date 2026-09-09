<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Per-company branding / receipt / organization-identity settings — the
 * multi-tenant replacement for BITS' single global `system_settings` row.
 *
 * Deliberately not using BelongsToCompany: it is always loaded via
 * `$company->settings` or explicitly by `company_id`, and the Super Admin
 * needs to read any company's row while provisioning.
 */
class CompanySetting extends Model
{
    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'company_id',
        'logo_path',
        'qr_payment_path',
        'color_accent',
        'color_accent_dark',
        'receipt_width_mm',
        'receipt_org_name',
        'ticket_footer',
        'registration_number',
        'otc_accreditation_number',
        'org_email',
        'org_contact_number',
        'void_feature_enabled',
        'void_pin_required',
        'pdf_paper_size',
        'pdf_orientation',
        'pdf_margin_mm',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_width_mm' => 'decimal:1',
            'void_feature_enabled' => 'boolean',
            'void_pin_required' => 'boolean',
            'pdf_margin_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The company's branding logo as a public URL — plain branding, so
     * readable by any authenticated member of the company (not gated
     * behind company.settings.view/manage, which controls who may
     * change it, not who may see it).
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
