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
        if (! $this->logo_path) {
            return null;
        }

        return self::rehostOntoCurrentRequest(Storage::disk('public')->url($this->logo_path));
    }

    /**
     * Storage::url() always bakes in config('app.url') — often `localhost`,
     * which resolves to the CLIENT itself on a phone/emulator, not this
     * server (the same trap Api\Mobile\WebSessionController hit). Rehost
     * the generated URL onto whatever address the current request actually
     * came in on, so a mobile client can always reach the image it was
     * just given.
     */
    private static function rehostOntoCurrentRequest(string $url): string
    {
        $request = request();
        if ($request === null) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return $request->getScheme().'://'.$request->getHttpHost().$path;
    }
}
