<?php

namespace Tests\Feature\Operations;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Trip;
use App\Models\User;
use App\Support\AccountLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ConfirmShiftEndTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private function conductor(Company $company): User
    {
        return User::factory()->forCompany($company)->withRole('conductor')->create([
            'pin_hash' => Hash::make('1234'),
        ]);
    }

    public function test_confirming_shift_end_locks_the_account_and_clocks_out(): void
    {
        $company = Company::factory()->create();
        $conductor = $this->conductor($company);
        $period = Attendance::factory()->create(['company_id' => $company->id, 'user_id' => $conductor->id]);
        Sanctum::actingAs($conductor);

        $this->postJson('/api/conductor/shift-end/confirm', ['pin' => '1234'])->assertOk();

        $fresh = $conductor->fresh();
        $this->assertSame('shift_end', $fresh->lock_type);
        $this->assertTrue(AccountLock::isLocked($fresh, false));
        $this->assertNotNull($period->fresh()->clock_out_at);
    }

    public function test_confirming_shift_end_rejects_the_wrong_pin(): void
    {
        $company = Company::factory()->create();
        $conductor = $this->conductor($company);
        Sanctum::actingAs($conductor);

        $this->postJson('/api/conductor/shift-end/confirm', ['pin' => '0000'])
            ->assertJsonValidationErrorFor('pin');

        $this->assertNull($conductor->fresh()->locked_at);
    }

    public function test_confirming_shift_end_is_blocked_while_a_trip_is_active(): void
    {
        $company = Company::factory()->create();
        $conductor = $this->conductor($company);
        Trip::factory()->create(['company_id' => $company->id, 'conductor_id' => $conductor->id, 'status' => 'OnTrip']);
        Sanctum::actingAs($conductor);

        $this->postJson('/api/conductor/shift-end/confirm', ['pin' => '1234'])
            ->assertJsonValidationErrorFor('trip');

        $this->assertNull($conductor->fresh()->locked_at);
    }
}
