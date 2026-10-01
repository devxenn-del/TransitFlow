<?php

namespace Tests\Feature\Fleet;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class BusCrewAssignmentTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
    }

    public function test_bus_can_be_given_a_driver_and_conductors(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $driver = Driver::factory()->create(['company_id' => $this->company->id]);
        $conductor = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        $this->putJson("/api/company/buses/{$bus->id}", [
            'driver_id' => $driver->id,
            'conductor_ids' => [$conductor->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.driver.id', $driver->id)
            ->assertJsonPath('data.conductors.0.id', $conductor->id);
    }

    public function test_a_driver_moves_off_their_previous_bus_when_reassigned(): void
    {
        $driver = Driver::factory()->create(['company_id' => $this->company->id]);
        $first = Bus::factory()->create(['company_id' => $this->company->id, 'driver_id' => $driver->id]);
        $second = Bus::factory()->create(['company_id' => $this->company->id]);

        $this->putJson("/api/company/drivers/{$driver->id}", ['bus_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.bus.id', $second->id);

        $this->assertNull($first->fresh()->driver_id);
        $this->assertSame($driver->id, $second->fresh()->driver_id);
    }

    public function test_another_companys_driver_or_non_conductor_cannot_be_assigned(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $foreignDriver = Driver::factory()->create(['company_id' => Company::factory()->create()->id]);
        $office = User::factory()->forCompany($this->company)->create();

        $this->putJson("/api/company/buses/{$bus->id}", ['driver_id' => $foreignDriver->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_id');
        $this->putJson("/api/company/buses/{$bus->id}", ['conductor_ids' => [$office->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conductor_ids.0');
    }
}
