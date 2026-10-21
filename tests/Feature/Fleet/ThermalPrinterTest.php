<?php

namespace Tests\Feature\Fleet;

use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * The company's Thermal Printers page — a strictly read-only view of the
 * printers the Super Admin issued to it, isolated per company.
 */
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

    public function test_only_the_companys_own_printers_are_listed_with_their_bus(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $onBus = ThermalPrinter::factory()->onBus($bus)->create();
        $noBus = ThermalPrinter::factory()->assignedTo($this->company)->create();
        ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();
        ThermalPrinter::factory()->create(); // unissued inventory

        $rows = collect($this->getJson('/api/company/thermal-printers')->assertOk()->assertJsonCount(2, 'data')->json('data'))->keyBy('id');

        $this->assertSame($bus->bus_number, $rows[$onBus->id]['bus']['bus_number']);
        $this->assertNull($rows[$noBus->id]['bus']);
        $this->assertSame($onBus->thermal_id, $rows[$onBus->id]['thermal_id']);
        $this->assertArrayNotHasKey('remarks', $rows[$onBus->id]);
        $this->assertArrayNotHasKey('history', $rows[$onBus->id]);
    }

    public function test_search_never_returns_another_companys_printers(): void
    {
        $other = Company::factory()->create();
        $foreign = ThermalPrinter::factory()->assignedTo($other)->create(['model' => 'Shared Model']);
        ThermalPrinter::factory()->assignedTo($this->company)->create(['model' => 'Shared Model']);

        $this->getJson('/api/company/thermal-printers?q=Shared')->assertJsonCount(1, 'data');
        $this->getJson('/api/company/thermal-printers?q='.$foreign->thermal_id)->assertJsonCount(0, 'data');
        $this->getJson('/api/company/thermal-printers?q='.urlencode($other->name))->assertJsonCount(0, 'data');
    }

    public function test_another_companys_printer_cannot_be_opened_by_id(): void
    {
        $own = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $foreign = ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();
        $unissued = ThermalPrinter::factory()->create();

        $this->getJson("/api/company/thermal-printers/{$own->id}")->assertOk()->assertJsonPath('data.thermal_id', $own->thermal_id);
        $this->getJson("/api/company/thermal-printers/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/company/thermal-printers/{$unissued->id}")->assertNotFound();

        // Naming another company in the header is refused outright.
        $this->withHeader('X-Company-Id', (string) $foreign->company_id)
            ->getJson('/api/company/thermal-printers')->assertForbidden();
    }

    public function test_the_list_is_empty_when_nothing_was_issued(): void
    {
        ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();

        $this->getJson('/api/company/thermal-printers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_there_are_no_company_side_write_endpoints(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);

        $this->postJson('/api/company/thermal-printers', ['model' => 'X'])->assertMethodNotAllowed();
        $this->putJson("/api/company/thermal-printers/{$printer->id}", ['model' => 'X'])->assertMethodNotAllowed();
        $this->deleteJson("/api/company/thermal-printers/{$printer->id}")->assertMethodNotAllowed();
        $this->putJson("/api/company/thermal-printers/{$printer->id}/assign", ['bus_id' => $bus->id])->assertNotFound();

        $printer->refresh();
        $this->assertNull($printer->bus_id);
        $this->assertNotSame('X', $printer->model);
    }

    public function test_company_roles_hold_no_printer_write_permission(): void
    {
        $admin = User::factory()->companyAdmin($this->company)->create();

        $this->assertTrue($admin->hasPermissionTo('thermalprinters.view'));
        $this->assertFalse($admin->hasPermissionTo('printers.inventory.view'));
        $this->assertFalse($admin->hasPermissionTo('printers.inventory.manage'));
    }

    public function test_a_conductor_cannot_view_thermal_printers(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->withRole('conductor')->create());

        $this->getJson('/api/company/thermal-printers')->assertForbidden();
    }
}
