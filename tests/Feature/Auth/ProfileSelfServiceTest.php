<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ProfileSelfServiceTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_an_employee_id_is_auto_generated_on_create_in_bits_format(): void
    {
        $user = User::factory()->forCompany(Company::factory()->create())->create();

        $this->assertMatchesRegularExpression(
            '/^E-'.$user->created_at->format('ym').'-\d{7}$/',
            $user->employee_id,
        );
        $this->assertSame(
            sprintf('E-%s-%07d', $user->created_at->format('ym'), $user->id),
            $user->employee_id,
        );
    }

    public function test_employee_id_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        $a = User::factory()->forCompany($company)->create();
        $b = User::factory()->forCompany(Company::factory()->create())->create();

        $this->assertNotSame($a->employee_id, $b->employee_id);
    }

    public function test_a_user_can_edit_their_own_contact_details(): void
    {
        $user = User::factory()->forCompany(Company::factory()->create())->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/profile', [
            'name' => 'Jane Renamed',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '09171234567',
            'address' => '123 Rizal St.',
            'sex' => 'Female',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Jane Renamed')
            ->assertJsonPath('data.first_name', 'Jane')
            ->assertJsonPath('data.sex', 'Female');

        $this->assertSame('Jane Renamed', $user->fresh()->name);
        $this->assertSame('09171234567', $user->fresh()->phone);
    }

    public function test_sex_must_be_male_or_female(): void
    {
        Sanctum::actingAs(User::factory()->forCompany(Company::factory()->create())->create());

        $this->putJson('/api/auth/profile', ['sex' => 'Other'])
            ->assertJsonValidationErrorFor('sex');
    }
}
