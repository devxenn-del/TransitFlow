<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class ThermalPrinterTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
    }

    public function test_a_printer_is_created_and_listed(): void
    {
        $this->postJson('/api/company/thermal-printers', ['device_id' => 'TP-001', 'model' => 'Epson TM-P20'])
            ->assertCreated()
            ->assertJsonPath('data.device_id', 'TP-001')
            ->assertJsonPath('data.status', 'Active');

        $this->getJson('/api/company/thermal-printers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_device_id_is_unique_per_company_only(): void
    {
        ThermalPrinter::query()->create(['company_id' => $this->company->id, 'device_id' => 'TP-DUP', 'model' => 'X']);

        $this->postJson('/api/company/thermal-printers', ['device_id' => 'TP-DUP', 'model' => 'Y'])
            ->assertJsonValidationErrorFor('device_id');

        // Same device_id, different company — fine.
        $other = Company::factory()->create();
        ThermalPrinter::query()->create(['company_id' => $other->id, 'device_id' => 'TP-DUP', 'model' => 'Z']);
        $this->assertDatabaseCount('thermal_printers', 2);
    }

    public function test_a_printer_is_assigned_to_a_conductor_and_reassigning_frees_the_previous_holder(): void
    {
        $printer = ThermalPrinter::query()->create(['company_id' => $this->company->id, 'device_id' => 'TP-002', 'model' => 'Star SM-L200']);
        $conductorA = User::factory()->forCompany($this->company)->withRole('conductor')->create();
        $conductorB = User::factory()->forCompany($this->company)->withRole('conductor')->create();

        $this->putJson("/api/company/thermal-printers/{$printer->id}/assign", ['user_id' => $conductorA->id])
            ->assertOk()
            ->assertJsonPath('data.holder.id', $conductorA->id);

        $this->assertSame($printer->id, $conductorA->fresh()->thermal_printer_id);

        // Reassigning to B frees A.
        $this->putJson("/api/company/thermal-printers/{$printer->id}/assign", ['user_id' => $conductorB->id])
            ->assertOk()
            ->assertJsonPath('data.holder.id', $conductorB->id);

        $this->assertNull($conductorA->fresh()->thermal_printer_id);
        $this->assertSame($printer->id, $conductorB->fresh()->thermal_printer_id);

        // Clearing.
        $this->putJson("/api/company/thermal-printers/{$printer->id}/assign", [])
            ->assertOk()
            ->assertJsonPath('data.holder', null);

        $this->assertNull($conductorB->fresh()->thermal_printer_id);
    }

    public function test_a_conductor_cannot_manage_thermal_printers(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('conductor')->create());

        $this->getJson('/api/company/thermal-printers')->assertForbidden();
        $this->postJson('/api/company/thermal-printers', ['device_id' => 'X', 'model' => 'Y'])->assertForbidden();
    }
}
