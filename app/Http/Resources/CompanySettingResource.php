<?php

namespace App\Http\Resources;

use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin CompanySetting
 */
class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'logo_path' => $this->logo_path,
            'logo_url' => $this->logo_url,
            'qr_payment_path' => $this->qr_payment_path,
            'qr_payment_url' => $this->publicUrl($this->qr_payment_path),
            'color_accent' => $this->color_accent,
            'color_accent_dark' => $this->color_accent_dark,
            'receipt_width_mm' => $this->receipt_width_mm,
            'receipt_org_name' => $this->receipt_org_name,
            'ticket_footer' => $this->ticket_footer,
            'registration_number' => $this->registration_number,
            'otc_accreditation_number' => $this->otc_accreditation_number,
            'org_email' => $this->org_email,
            'org_contact_number' => $this->org_contact_number,
            'pdf_paper_size' => $this->pdf_paper_size,
            'pdf_orientation' => $this->pdf_orientation,
            'pdf_margin_mm' => $this->pdf_margin_mm,
        ];
    }

    private function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
