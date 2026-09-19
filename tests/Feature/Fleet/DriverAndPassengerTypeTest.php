<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\Driver;
use App\Models\PassengerType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class DriverAndPassengerTypeTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
    }

    /* ---------- Drivers ---------- */

    public function test_driver_is_created_with_a_generated_employee_id(): void
    {
        $this->admin();

        $res = $this->postJson('/api/company/drivers', ['name' => 'JUAN DELA CRUZ'])->assertCreated();

        $id = $res->json('data.id');
        $this->assertMatchesRegularExpression('/^E-\d{4}-\d{7}$/', $res->json('data.employee_id'));
        $this->assertDatabaseHas('drivers', ['id' => $id, 'company_id' => $this->company->id]);
    }

    public function test_driver_can_be_created_with_gender_email_and_address(): void
    {
        $this->admin();

        $res = $this->postJson('/api/company/drivers', [
            'name' => 'JUAN DELA CRUZ',
            'sex' => 'Male',
            'email' => 'juan@example.test',
            'address' => '123 Rizal St, Manila',
        ])->assertCreated();

        $res->assertJsonPath('data.sex', 'Male')
            ->assertJsonPath('data.email', 'juan@example.test')
            ->assertJsonPath('data.address', '123 Rizal St, Manila');
    }

    public function test_another_companys_driver_is_not_reachable(): void
    {
        $foreign = Driver::factory()->for(Company::factory())->create();
        $this->admin();

        $this->getJson("/api/company/drivers/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/company/drivers/{$foreign->id}", ['status' => 'Inactive'])->assertNotFound();
    }

    public function test_office_can_view_drivers_but_not_create(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create());

        $this->getJson('/api/company/drivers')->assertOk();
        $this->postJson('/api/company/drivers', ['name' => 'X'])->assertForbidden();
    }

    /* ---------- Passenger types + articles ---------- */

    public function test_passenger_type_crud_and_per_company_unique_name(): void
    {
        PassengerType::factory()->for(Company::factory())->create(['name' => 'Senior']);
        $this->admin();

        $this->postJson('/api/company/passenger-types', ['name' => 'Senior', 'fare_mode' => 'Fare Matrix', 'discount_percent' => 20])
            ->assertCreated();
        $this->postJson('/api/company/passenger-types', ['name' => 'Senior', 'fare_mode' => 'Fare Matrix'])
            ->assertJsonValidationErrorFor('name');
    }

    public function test_manual_amount_type_forces_zero_discount(): void
    {
        $this->admin();

        $this->postJson('/api/company/passenger-types', [
            'name' => 'Articles Sales', 'fare_mode' => 'Manual Amount', 'discount_percent' => 25,
        ])->assertCreated()->assertJsonPath('data.discount_percent', 0);
    }

    public function test_articles_only_attach_to_a_manual_amount_type(): void
    {
        $this->admin();
        $fareMatrixType = PassengerType::factory()->for($this->company)->create(['fare_mode' => 'Fare Matrix']);
        $manualType = PassengerType::factory()->for($this->company)->manualAmount()->create();

        $this->postJson("/api/company/passenger-types/{$fareMatrixType->id}/articles", ['label' => 'Student', 'amount' => 10])
            ->assertJsonValidationErrorFor('label');

        $this->postJson("/api/company/passenger-types/{$manualType->id}/articles", ['label' => 'Student', 'amount' => 10])
            ->assertCreated()
            ->assertJsonPath('data.amount', 10);
    }

    public function test_article_label_is_unique_within_a_type(): void
    {
        $this->admin();
        $type = PassengerType::factory()->for($this->company)->manualAmount()->create();

        $this->postJson("/api/company/passenger-types/{$type->id}/articles", ['label' => 'Student', 'amount' => 10])->assertCreated();
        $this->postJson("/api/company/passenger-types/{$type->id}/articles", ['label' => 'Student', 'amount' => 12])
            ->assertJsonValidationErrorFor('label');
    }

    public function test_deleting_a_passenger_type_cascades_its_articles(): void
    {
        $this->admin();
        $type = PassengerType::factory()->for($this->company)->manualAmount()->create();
        $this->postJson("/api/company/passenger-types/{$type->id}/articles", ['label' => 'Student', 'amount' => 10])->assertCreated();

        $this->deleteJson("/api/company/passenger-types/{$type->id}")->assertNoContent();
        $this->assertDatabaseMissing('passenger_type_articles', ['passenger_type_id' => $type->id]);
    }
}
