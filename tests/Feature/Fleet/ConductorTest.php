<?php

namespace Tests\Feature\Fleet;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ConductorTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    private function admin(): User
    {
        $admin = User::factory()->companyAdmin($this->company)->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_company_admin_can_create_a_conductor(): void
    {
        $this->admin();

        $res = $this->postJson('/api/company/conductors', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@conductor.test',
            'password' => 'secret-password',
        ])->assertCreated();

        $this->assertSame('conductor', $res->json('data.access_role.key'));
        $this->assertDatabaseHas('users', [
            'email' => 'juan@conductor.test',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_conductors_are_hidden_from_the_general_users_list_but_appear_in_the_conductors_list(): void
    {
        $this->admin();
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        $users = $this->getJson('/api/company/users')->assertOk()->json('data');
        $this->assertFalse(collect($users)->contains('id', $conductor->id));

        $conductors = $this->getJson('/api/company/conductors')->assertOk()->json('data');
        $this->assertTrue(collect($conductors)->contains('id', $conductor->id));
    }

    public function test_office_role_can_view_conductors_but_not_create_one(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create());

        $this->getJson('/api/company/conductors')->assertOk();
        $this->postJson('/api/company/conductors', ['name' => 'X', 'email' => 'x@test.test', 'password' => 'secret-password'])
            ->assertForbidden();
    }

    public function test_another_companys_conductor_is_not_reachable(): void
    {
        $foreignCompany = Company::factory()->create();
        $foreign = User::factory()->forCompany($foreignCompany)->withRole('conductor')->create();
        $this->admin();

        $this->getJson("/api/company/conductors/{$foreign->id}")->assertNotFound();
    }

    public function test_admin_can_assign_buses_to_a_conductor(): void
    {
        $this->admin();
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $bus = Bus::factory()->for($this->company)->create();

        $this->putJson("/api/company/conductors/{$conductor->id}/buses", ['bus_ids' => [$bus->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $bus->id);

        $this->assertDatabaseHas('bus_user', ['user_id' => $conductor->id, 'bus_id' => $bus->id]);
    }

    public function test_admin_can_delete_a_conductor(): void
    {
        $this->admin();
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        $this->deleteJson("/api/company/conductors/{$conductor->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $conductor->id]);
    }
}
