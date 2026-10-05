<?php

namespace Tests\Feature\Platform;

use App\Enums\BillingFrequency;
use App\Models\Fee;
use Database\Seeders\FeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The standard fee catalogue seeder: seeds the monthly System, Maintenance
 * and Device fees for every company and never overwrites a fee edited
 * after seeding.
 */
class FeeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_standard_monthly_fees_for_every_company(): void
    {
        $this->seed(FeeSeeder::class);

        $this->assertSame(
            ['Device Fee' => '0.00', 'Maintenance Fee' => '500.00', 'System Fee' => '2000.00'],
            Fee::query()->orderBy('name')->pluck('amount', 'name')->all(),
        );

        Fee::query()->get()->each(function (Fee $fee): void {
            $this->assertSame(BillingFrequency::Monthly, $fee->billing_frequency);
            $this->assertTrue($fee->applies_to_all_companies);
            $this->assertTrue($fee->is_active);
            $this->assertSame('2026-10-05', $fee->effective_date->toDateString());
        });
    }

    public function test_rerunning_it_keeps_fees_edited_after_seeding(): void
    {
        $this->seed(FeeSeeder::class);
        Fee::query()->where('name', 'System Fee')->update(['amount' => 2500]);

        $this->seed(FeeSeeder::class);

        $this->assertSame(3, Fee::query()->count());
        $this->assertSame('2500.00', Fee::query()->where('name', 'System Fee')->value('amount'));
    }
}
