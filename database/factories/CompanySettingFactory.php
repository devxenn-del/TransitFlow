<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'logo_path' => null,
            'qr_payment_path' => null,
            'color_accent' => '#0d6efd',
            'color_accent_dark' => '#0b5ed7',
            'receipt_width_mm' => 54.0,
            'receipt_org_name' => fake()->company(),
            'ticket_footer' => 'Keep this ticket for your trip.',
            'registration_number' => '',
            'otc_accreditation_number' => '',
            'org_email' => fake()->companyEmail(),
            'org_contact_number' => fake()->numerify('09#########'),
            'pdf_paper_size' => 'a4',
            'pdf_orientation' => 'landscape',
            'pdf_margin_mm' => 10,
        ];
    }
}
