<?php

namespace Tests\Feature\Operations;

use App\Models\CashCountVoidAttempt;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class VoidSecurityTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->forCompany($this->company)->withRole('company_admin')->create();
        $this->manager = User::factory()->forCompany($this->company)->withRole('manager')->create();
    }

    public function test_console_lists_managers_with_their_void_pin_state(): void
    {
        $this->manager->forceFill([
            'void_pin_hash' => Hash::make('1234'),
            'void_pin_failed_count' => 3,
            'void_pin_locked_until' => now()->addMinutes(10),
        ])->save();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/company/void-security')
            ->assertOk()
            ->assertJsonPath('data.0.name', $this->manager->name)
            ->assertJsonPath('data.0.has_void_pin', true)
            ->assertJsonPath('data.0.failed_count', 3)
            ->assertJsonPath('data.0.locked', true);
    }

    public function test_admin_clears_a_lockout_without_removing_the_pin(): void
    {
        $this->manager->forceFill([
            'void_pin_hash' => Hash::make('1234'),
            'void_pin_failed_count' => 5,
            'void_pin_locked_until' => now()->addMinutes(15),
        ])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/company/void-security/{$this->manager->id}/unlock")->assertOk();

        $this->manager->refresh();
        $this->assertNull($this->manager->void_pin_locked_until);
        $this->assertSame(0, (int) $this->manager->void_pin_failed_count);
        $this->assertNotNull($this->manager->void_pin_hash); // PIN kept
    }

    public function test_admin_forces_a_pin_reset(): void
    {
        $this->manager->forceFill(['void_pin_hash' => Hash::make('1234')])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/company/void-security/{$this->manager->id}/reset")->assertOk();

        $this->assertNull($this->manager->fresh()->void_pin_hash);
    }

    public function test_attempts_feed_is_returned_paginated(): void
    {
        CashCountVoidAttempt::query()->create([
            'company_id' => $this->company->id, 'manager_id' => $this->manager->id, 'requested_by' => $this->manager->id,
            'subject' => 'remittance:9', 'success' => false, 'detail' => 'Wrong PIN', 'attempted_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/company/void-security/attempts')
            ->assertOk()
            ->assertJsonPath('data.0.manager', $this->manager->name)
            ->assertJsonPath('data.0.success', false)
            ->assertJsonPath('data.0.subject', 'remittance:9');
    }

    public function test_a_manager_cannot_open_the_console_or_reset_pins(): void
    {
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/company/void-security')->assertForbidden();
        $this->postJson("/api/company/void-security/{$this->manager->id}/reset")->assertForbidden();
    }

    public function test_it_is_company_scoped(): void
    {
        $otherManager = User::factory()->forCompany(Company::factory()->create())->withRole('manager')->create();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/company/void-security/{$otherManager->id}/reset")->assertNotFound();
    }
}
