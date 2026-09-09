<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MobileAppSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileAppSetting>
 */
class MobileAppSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'api_base_url' => null,
            'latest_version' => '1.0.0',
            'latest_version_code' => 1,
            'minimum_version' => '1.0.0',
            'force_update' => false,
            'download_url' => null,
            'apk_path' => null,
            'release_notes' => null,
            'published_at' => now(),
        ];
    }
}
