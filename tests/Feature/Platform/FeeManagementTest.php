<?php

namespace Tests\Feature\Platform;

use App\Enums\PricingPlan;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyFeeRate;
use App\Models\Fee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Super Admin fee management: the fee catalogue (standard pricing), which
 * companies a fee is assigned to, and company-specific special pricing —
 * with the pricing precedence enforced server-side and a special rate for
 * one company never affecting another.
 */
class FeeManagementTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    public function test_super_admin_creates_a_fee_and_the_creation_is_audited(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/super-admin/fees', [
            'name' => 'System Fee',
            'description' => 'Platform usage',
            'amount' => 5000,
            'billing_frequency' => 'monthly',
            'effective_date' => '2026-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '5000.00')
            ->assertJsonPath('data.frequency_label', 'Monthly')
            ->assertJsonPath('data.applies_to_all_companies', true);

        $log = AuditLog::query()->where('action', 'billing.fee.created')->sole();
        $this->assertSame($this->superAdmin->id, $log->user_id);
        $this->assertSame('5000.00', $log->context['new']['amount']);
    }

    public function test_a_custom_frequency_requires_an_interval(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/super-admin/fees', [
            'name' => 'Maintenance Fee',
            'amount' => 1200,
            'billing_frequency' => 'custom',
            'effective_date' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_interval_months');
    }

    public function test_changing_the_standard_amount_records_previous_and_new_values(): void
    {
        $fee = Fee::factory()->create(['amount' => 5000]);
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/super-admin/fees/{$fee->id}", [
            'name' => $fee->name,
            'amount' => 5500,
            'billing_frequency' => 'monthly',
            'effective_date' => $fee->effective_date->toDateString(),
        ])->assertOk()->assertJsonPath('data.amount', '5500.00');

        $log = AuditLog::query()->where('action', 'billing.fee.standard_amount_changed')->sole();
        $this->assertSame(['from' => '5000.00', 'to' => '5500.00'], $log->context['changes']['amount']);
        $this->assertFalse(AuditLog::query()->where('action', 'billing.fee.updated')->exists());
    }

    public function test_disabling_a_fee_is_audited(): void
    {
        $fee = Fee::factory()->create();
        Sanctum::actingAs($this->superAdmin);

        $this->patchJson("/api/super-admin/fees/{$fee->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(AuditLog::query()->where('action', 'billing.fee.disabled')->exists());
    }

    public function test_a_special_rate_applies_only_to_its_company(): void
    {
        [$companyA, $companyB, $companyC] = Company::factory()->count(3)->create();
        $companyB->update(['pricing_plan' => PricingPlan::Special]);
        $fee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 5000]);
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/super-admin/companies/{$companyB->id}/special-rates", [
            'fee_id' => $fee->id,
            'amount' => 3500,
            'starts_on' => today()->subDay()->toDateString(),
            'internal_notes' => 'Pilot partner discount',
        ])->assertCreated();

        $this->assertSame('5000.00', (string) $fee->fresh()->amount);

        $companies = collect($this->getJson("/api/super-admin/fees/{$fee->id}")->assertOk()->json('companies'))->keyBy('id');
        $this->assertSame('5000.00', $companies[$companyA->id]['pricing']['amount']);
        $this->assertSame('3500.00', $companies[$companyB->id]['pricing']['amount']);
        $this->assertSame('special', $companies[$companyB->id]['pricing']['pricing_type']);
        $this->assertSame('5000.00', $companies[$companyC->id]['pricing']['amount']);

        $log = AuditLog::query()->where('action', 'billing.special_rate.created')->sole();
        $this->assertSame($companyB->id, $log->company_id);
    }

    public function test_special_rates_are_ignored_while_the_company_is_on_standard_pricing(): void
    {
        $company = Company::factory()->create();
        $fee = Fee::factory()->create(['amount' => 5000]);
        CompanyFeeRate::factory()->create(['company_id' => $company->id, 'fee_id' => $fee->id, 'amount' => 3500]);
        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/super-admin/companies/{$company->id}/pricing")
            ->assertOk()
            ->assertJsonPath('pricing_plan', 'standard')
            ->assertJsonPath('fees.0.amount', '5000.00')
            ->assertJsonPath('special_rates.0.internal_notes', fn ($notes) => $notes !== null);

        $this->putJson("/api/super-admin/companies/{$company->id}/pricing", ['pricing_plan' => 'special'])
            ->assertOk()
            ->assertJsonPath('fees.0.amount', '3500.00')
            ->assertJsonPath('fees.0.rate_label', 'Special Company Rate');

        $log = AuditLog::query()->where('action', 'billing.pricing_plan.changed')->sole();
        $this->assertEquals(['from' => 'standard', 'to' => 'special'], $log->context['changes']['pricing_plan']);
    }

    public function test_an_expired_special_rate_falls_back_to_the_standard_amount(): void
    {
        $company = Company::factory()->create(['pricing_plan' => PricingPlan::Special]);
        $fee = Fee::factory()->create(['amount' => 5000]);
        CompanyFeeRate::factory()->expired()->create(['company_id' => $company->id, 'fee_id' => $fee->id, 'amount' => 3500]);
        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/super-admin/companies/{$company->id}/pricing")
            ->assertOk()
            ->assertJsonPath('fees.0.amount', '5000.00')
            ->assertJsonPath('fees.0.pricing_type', 'standard')
            ->assertJsonPath('special_rates.0.state', 'expired');
    }

    public function test_a_fee_assigned_to_selected_companies_is_billed_only_to_them(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $fee = Fee::factory()->create(['name' => 'Rental Fee']);
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/super-admin/fees/{$fee->id}/companies", [
            'applies_to_all_companies' => false,
            'company_ids' => [$companyA->id],
        ])->assertOk()->assertJsonPath('data.assigned_companies_count', 1);

        $this->getJson("/api/super-admin/companies/{$companyA->id}/pricing")->assertJsonCount(1, 'fees');
        $this->getJson("/api/super-admin/companies/{$companyB->id}/pricing")->assertJsonCount(0, 'fees');

        $this->assertTrue(AuditLog::query()->where('action', 'billing.fee.assigned')->where('company_id', $companyA->id)->exists());
    }

    public function test_a_special_rate_cannot_be_edited_through_another_companys_url(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $rate = CompanyFeeRate::factory()->create(['company_id' => $companyA->id]);
        Sanctum::actingAs($this->superAdmin);

        $this->putJson("/api/super-admin/companies/{$companyB->id}/special-rates/{$rate->id}", [
            'fee_id' => $rate->fee_id,
            'amount' => 1,
            'starts_on' => today()->toDateString(),
        ])->assertNotFound();
    }

    public function test_company_admin_cannot_manage_fees_or_pricing(): void
    {
        $company = Company::factory()->create();
        $fee = Fee::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());

        $this->getJson('/api/super-admin/fees')->assertForbidden();
        $this->postJson('/api/super-admin/fees', ['name' => 'X', 'amount' => 1, 'billing_frequency' => 'monthly', 'effective_date' => '2026-01-01'])->assertForbidden();
        $this->putJson("/api/super-admin/fees/{$fee->id}", ['amount' => 1])->assertForbidden();
        $this->getJson("/api/super-admin/companies/{$company->id}/pricing")->assertForbidden();
        $this->putJson("/api/super-admin/companies/{$company->id}/pricing", ['pricing_plan' => 'special'])->assertForbidden();
        $this->postJson("/api/super-admin/companies/{$company->id}/special-rates", [
            'fee_id' => $fee->id, 'amount' => 1, 'starts_on' => '2026-01-01',
        ])->assertForbidden();

        $this->assertSame(PricingPlan::Standard, $company->fresh()->pricing_plan);
        $this->assertSame(0, CompanyFeeRate::query()->count());
    }
}
