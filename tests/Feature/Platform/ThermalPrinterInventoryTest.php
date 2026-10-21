<?php

namespace Tests\Feature\Platform;

use App\Enums\ThermalPrinterStatus;
use App\Http\Controllers\Api\SuperAdmin\ThermalPrinterController;
use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Thermal Printer Management — the Super Admin's platform-wide inventory:
 * ID generation, company and bus assignment, status rules, history.
 */
class ThermalPrinterInventoryTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private const BASE = '/api/super-admin/thermal-printers';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 21));
        $this->company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_registering_generates_both_ids_in_the_required_format(): void
    {
        $first = $this->postJson(self::BASE, ['model' => 'Epson TM-P20', 'mac_address' => 'aa-bb-cc-dd-ee-01'])
            ->assertCreated()
            ->assertJsonPath('data.thermal_id', 'TFTP-211026-000001')
            ->assertJsonPath('data.device_id', 'TFD-211026-000001')
            ->assertJsonPath('data.mac_address', 'AA:BB:CC:DD:EE:01')
            ->assertJsonPath('data.status', 'Available')
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.bus', null);

        $this->assertMatchesRegularExpression(ThermalPrinter::THERMAL_ID_PATTERN, $first->json('data.thermal_id'));
        $this->assertMatchesRegularExpression(ThermalPrinter::DEVICE_ID_PATTERN, $first->json('data.device_id'));

        $this->postJson(self::BASE, ['model' => 'Star SM-L200'])
            ->assertCreated()
            ->assertJsonPath('data.thermal_id', 'TFTP-211026-000002')
            ->assertJsonPath('data.device_id', 'TFD-211026-000002');

        $this->assertDatabaseHas('thermal_printer_assignments', ['action' => 'registered', 'to_status' => 'Available']);
    }

    public function test_ids_submitted_by_the_client_are_refused(): void
    {
        $this->postJson(self::BASE, ['model' => 'X', 'thermal_id' => 'TFTP-010101-999999', 'device_id' => 'TFD-010101-999999'])
            ->assertJsonValidationErrors(['thermal_id', 'device_id']);

        $this->assertDatabaseCount('thermal_printers', 0);
    }

    public function test_generated_ids_are_unique_in_the_database(): void
    {
        $printer = ThermalPrinter::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('thermal_printers')->insert([
            'thermal_id' => $printer->thermal_id,
            'device_id' => 'TFD-211026-999999',
            'model' => 'Dup',
            'status' => 'Available',
        ]);
    }

    public function test_the_six_digit_limit_is_never_exceeded(): void
    {
        DB::table('id_sequences')->where('name', ThermalPrinter::SEQUENCE)->update(['last_value' => ThermalPrinter::MAX_SEQUENCE]);

        $this->postJson(self::BASE, ['model' => 'One too many'])->assertJsonValidationErrorFor('model');

        $this->assertDatabaseCount('thermal_printers', 0);
        $this->assertSame(ThermalPrinter::MAX_SEQUENCE, DB::table('id_sequences')->where('name', ThermalPrinter::SEQUENCE)->value('last_value'));
    }

    public function test_mac_addresses_are_validated_and_unique(): void
    {
        $this->postJson(self::BASE, ['model' => 'X', 'mac_address' => 'not-a-mac'])->assertJsonValidationErrorFor('mac_address');

        $this->postJson(self::BASE, ['model' => 'X', 'mac_address' => '00:1A:2B:3C:4D:5E'])->assertCreated();
        $this->postJson(self::BASE, ['model' => 'Y', 'mac_address' => '001a2b3c4d5e'])->assertJsonValidationErrorFor('mac_address');
    }

    public function test_editing_never_changes_the_ids(): void
    {
        $printer = ThermalPrinter::factory()->create();

        $this->putJson(self::BASE."/{$printer->id}", ['model' => 'Renamed', 'remarks' => 'Spare unit'])
            ->assertOk()
            ->assertJsonPath('data.model', 'Renamed')
            ->assertJsonPath('data.thermal_id', $printer->thermal_id)
            ->assertJsonPath('data.device_id', $printer->device_id);

        $this->putJson(self::BASE."/{$printer->id}", ['thermal_id' => 'TFTP-010101-000009'])->assertJsonValidationErrorFor('thermal_id');
        $this->putJson(self::BASE."/{$printer->id}", ['company_id' => $this->company->id])->assertJsonValidationErrorFor('company_id');
        $this->putJson(self::BASE."/{$printer->id}", ['status' => 'Retired'])->assertJsonValidationErrorFor('status');
    }

    public function test_a_printer_is_assigned_to_a_company_without_getting_a_bus(): void
    {
        $printer = ThermalPrinter::factory()->create();
        Bus::factory()->create(['company_id' => $this->company->id]);

        $this->putJson(self::BASE."/{$printer->id}/company", ['company_id' => $this->company->id, 'date_issued' => '2026-10-20'])
            ->assertOk()
            ->assertJsonPath('data.company.id', $this->company->id)
            ->assertJsonPath('data.bus', null)
            ->assertJsonPath('data.status', 'Assigned')
            ->assertJsonPath('data.date_issued', '2026-10-20')
            ->assertJsonPath('data.thermal_id', $printer->thermal_id);
    }

    public function test_a_printer_is_assigned_to_a_bus_of_its_company_and_reassigned(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $busA = Bus::factory()->create(['company_id' => $this->company->id]);
        $busB = Bus::factory()->create(['company_id' => $this->company->id]);

        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $busA->id])
            ->assertOk()
            ->assertJsonPath('data.bus.id', $busA->id);

        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $busB->id, 'remarks' => 'Moved'])
            ->assertOk()
            ->assertJsonPath('data.bus.id', $busB->id)
            ->assertJsonPath('data.thermal_id', $printer->thermal_id);

        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => null])
            ->assertOk()
            ->assertJsonPath('data.bus', null)
            ->assertJsonPath('data.company.id', $this->company->id);

        $history = $this->getJson(self::BASE."/{$printer->id}")->assertOk()->json('data.history');
        $this->assertSame(['bus_removed', 'bus_changed', 'bus_assigned'], array_column($history, 'action'));
        $this->assertSame($busA->bus_number, $history[1]['from_bus']);
        $this->assertSame($busB->bus_number, $history[1]['to_bus']);
        $this->assertSame('Moved', $history[1]['remarks']);
    }

    public function test_several_printers_can_share_a_bus(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        ThermalPrinter::factory()->onBus($bus)->create();
        $second = ThermalPrinter::factory()->assignedTo($this->company)->create();

        $this->putJson(self::BASE."/{$second->id}/bus", ['bus_id' => $bus->id])->assertOk();

        $this->assertSame(2, ThermalPrinter::query()->where('bus_id', $bus->id)->count());
    }

    public function test_a_bus_from_another_company_is_rejected(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $foreignBus = Bus::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $foreignBus->id])->assertJsonValidationErrorFor('bus_id');
        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $foreignBus->id, 'company_id' => $foreignBus->company_id])->assertJsonValidationErrorFor('company_id');

        $this->assertNull($printer->fresh()->bus_id);
    }

    public function test_bus_assignment_requires_an_assigned_printer_and_an_active_bus(): void
    {
        $unissued = ThermalPrinter::factory()->create();
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $this->putJson(self::BASE."/{$unissued->id}/bus", ['bus_id' => $bus->id])->assertJsonValidationErrorFor('bus_id');

        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $inactive = Bus::factory()->create(['company_id' => $this->company->id, 'status' => 'Inactive']);
        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $inactive->id])->assertJsonValidationErrorFor('bus_id');
    }

    public function test_changing_company_clears_the_old_companys_bus(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $printer = ThermalPrinter::factory()->onBus($bus)->create();
        $other = Company::factory()->create();

        $this->putJson(self::BASE."/{$printer->id}/company", ['company_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.company.id', $other->id)
            ->assertJsonPath('data.bus', null);

        $this->assertDatabaseHas('thermal_printer_assignments', [
            'thermal_printer_id' => $printer->id,
            'action' => 'company_changed',
            'from_company_id' => $this->company->id,
            'to_company_id' => $other->id,
            'from_bus_id' => $bus->id,
            'to_bus_id' => null,
        ]);
    }

    public function test_status_rules_keep_links_consistent(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $printer = ThermalPrinter::factory()->onBus($bus)->create();

        // Defective keeps the company but never a bus.
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Defective'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Defective')
            ->assertJsonPath('data.bus', null)
            ->assertJsonPath('data.company.id', $this->company->id);

        // A Defective printer cannot be put on a bus or retired while still issued.
        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $bus->id])->assertJsonValidationErrorFor('bus_id');
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Retired'])->assertJsonValidationErrorFor('status');

        // Returned clears the company.
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Returned'])
            ->assertOk()
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.date_issued', null);

        // Retired is final.
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Retired'])->assertOk();
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Available'])->assertJsonValidationErrorFor('status');
        $this->putJson(self::BASE."/{$printer->id}/company", ['company_id' => $this->company->id])->assertJsonValidationErrorFor('company_id');

        $this->assertSame(ThermalPrinterStatus::Retired, $printer->fresh()->status);
    }

    public function test_an_available_printer_cannot_be_marked_assigned_directly(): void
    {
        $printer = ThermalPrinter::factory()->create();

        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Assigned'])->assertJsonValidationErrorFor('status');
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Bogus'])->assertJsonValidationErrorFor('status');
    }

    public function test_status_changes_are_audited(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();

        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Under Maintenance', 'remarks' => 'Paper feed jam'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'thermal_printer.status_changed',
            'subject_id' => $printer->id,
            'subject_label' => $printer->thermal_id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_search_filters_and_pagination_work_together(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id, 'bus_number' => 'BUS-777', 'plate_number' => 'ABC-1234']);
        $onBus = ThermalPrinter::factory()->onBus($bus)->create(['model' => 'Epson TM-P20']);
        ThermalPrinter::factory()->assignedTo($this->company)->create(['model' => 'Star SM-L200']);
        ThermalPrinter::factory()->count(3)->create(['model' => 'Xprinter XP-P300']);
        ThermalPrinter::factory()->withStatus(ThermalPrinterStatus::Defective)->create(['model' => 'Broken one']);

        $this->getJson(self::BASE.'?q=BUS-777')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $onBus->id);
        $this->getJson(self::BASE.'?q=ABC-1234')->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?q='.$onBus->device_id)->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?q='.urlencode($this->company->name))->assertJsonCount(2, 'data');
        $this->getJson(self::BASE."?company_id={$this->company->id}")->assertJsonCount(2, 'data');
        $this->getJson(self::BASE."?bus_id={$bus->id}")->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?assignment=unassigned')->assertJsonCount(4, 'data');
        $this->getJson(self::BASE.'?assignment=no_bus')->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?assignment=on_bus')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $onBus->id);
        $this->getJson(self::BASE.'?status=Defective')->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'?assignment=unassigned&q=Xprinter&per_page=2&page=2')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_search_input_is_treated_as_text(): void
    {
        ThermalPrinter::factory()->create(['model' => "O'Brien <script>alert(1)</script>"]);

        $this->getJson(self::BASE.'?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'?q='.urlencode('<script>'))
            ->assertOk()
            ->assertJsonPath('data.0.model', "O'Brien <script>alert(1)</script>");
    }

    public function test_the_bus_picker_lists_only_that_companys_buses(): void
    {
        $own = Bus::factory()->create(['company_id' => $this->company->id]);
        Bus::factory()->create(['company_id' => $this->company->id, 'status' => 'Maintenance']);
        Bus::factory()->create(['company_id' => Company::factory()->create()->id]);

        $buses = $this->getJson(self::BASE."/buses?company_id={$this->company->id}")->assertOk()->json('data');

        $this->assertCount(2, $buses);
        $this->assertContains($own->id, array_column($buses, 'id'));
        $this->assertSame([true, false], array_column(collect($buses)->sortBy(fn ($b) => ! $b['assignable'])->values()->all(), 'assignable'));
    }

    public function test_an_open_company_workspace_does_not_narrow_the_inventory(): void
    {
        ThermalPrinter::factory()->create();
        ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();
        $printer = ThermalPrinter::factory()->create();

        $this->withHeader('X-Company-Id', (string) $this->company->id);

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(self::BASE."/{$printer->id}")->assertOk();
        $this->postJson(self::BASE, ['model' => 'New'])->assertCreated()->assertJsonPath('data.company', null);
    }

    public function test_deleting_a_bus_takes_its_printers_off_it_with_history(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $printer = ThermalPrinter::factory()->onBus($bus)->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->deleteJson("/api/company/buses/{$bus->id}")->assertNoContent();

        $this->assertNull($printer->fresh()->bus_id);
        $this->assertSame($this->company->id, $printer->fresh()->company_id);
        $this->assertDatabaseHas('thermal_printer_assignments', [
            'thermal_printer_id' => $printer->id,
            'action' => 'bus_removed',
            'from_bus_label' => $bus->bus_number,
        ]);
    }

    public function test_company_users_cannot_reach_the_inventory(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->getJson(self::BASE)->assertForbidden();
        $this->getJson(self::BASE."/{$printer->id}")->assertForbidden();
        $this->postJson(self::BASE, ['model' => 'X'])->assertForbidden();
        $this->putJson(self::BASE."/{$printer->id}", ['model' => 'X'])->assertForbidden();
        $this->putJson(self::BASE."/{$printer->id}/company", ['company_id' => $this->company->id])->assertForbidden();
        $this->putJson(self::BASE."/{$printer->id}/bus", ['bus_id' => $bus->id])->assertForbidden();
        $this->patchJson(self::BASE."/{$printer->id}/status", ['status' => 'Returned'])->assertForbidden();
        $this->getJson(self::BASE."/buses?company_id={$this->company->id}")->assertForbidden();

        $this->assertNull($printer->fresh()->bus_id);
        $this->assertSame(ThermalPrinterStatus::Assigned, $printer->fresh()->status);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::BASE)->assertUnauthorized();
        $this->postJson(self::BASE, ['model' => 'X'])->assertUnauthorized();
    }

    public function test_labels_are_returned_for_the_selected_printers_across_companies(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        $onBus = ThermalPrinter::factory()->onBus($bus)->create();
        $elsewhere = ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();
        ThermalPrinter::factory()->create(); // not selected

        $labels = $this->getJson(self::BASE.'/labels?'.http_build_query(['ids' => [$elsewhere->id, $onBus->id]]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        $byId = collect($labels)->keyBy('id');
        $this->assertSame($onBus->mac_address, $byId[$onBus->id]['mac_address']);
        $this->assertSame($bus->bus_number, $byId[$onBus->id]['bus']['bus_number']);
        $this->assertSame($this->company->name, $byId[$onBus->id]['company']['name']);
        $this->assertSame($elsewhere->company_id, $byId[$elsewhere->id]['company']['id']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'thermal_printer.labels_printed']);
    }

    public function test_labels_for_all_matching_use_the_same_filters_as_the_list(): void
    {
        ThermalPrinter::factory()->count(2)->assignedTo($this->company)->create();
        ThermalPrinter::factory()->count(3)->create();

        $listed = collect($this->getJson(self::BASE."?company_id={$this->company->id}")->json('data'))->pluck('id')->sort()->values();
        $labelled = collect($this->getJson(self::BASE."/labels?all=1&company_id={$this->company->id}")->assertOk()->json('data'))->pluck('id')->sort()->values();

        $this->assertCount(2, $labelled);
        $this->assertEquals($listed, $labelled);
    }

    public function test_label_requests_are_validated_and_capped(): void
    {
        $this->getJson(self::BASE.'/labels')->assertJsonValidationErrorFor('ids');
        $this->getJson(self::BASE.'/labels?'.http_build_query(['ids' => range(1, ThermalPrinterController::MAX_LABELS + 1)]))->assertJsonValidationErrorFor('ids');
        $this->getJson(self::BASE.'/labels?'.http_build_query(['ids' => [999999]]))->assertJsonValidationErrorFor('ids');
    }

    public function test_selected_printers_are_assigned_to_a_company_in_bulk(): void
    {
        $other = Company::factory()->create();
        $bus = Bus::factory()->create(['company_id' => $other->id]);
        $fresh = ThermalPrinter::factory()->count(2)->create();
        $moving = ThermalPrinter::factory()->onBus($bus)->create();
        $already = ThermalPrinter::factory()->assignedTo($this->company)->create();

        $this->putJson(self::BASE.'/bulk/company', [
            'ids' => [...$fresh->pluck('id'), $moving->id, $already->id],
            'company_id' => $this->company->id,
            'remarks' => 'Batch delivery',
        ])->assertOk()->assertJsonPath('data.updated', 3)->assertJsonPath('data.skipped', 1);

        foreach ([...$fresh, $moving] as $printer) {
            $printer->refresh();
            $this->assertSame($this->company->id, $printer->company_id);
            $this->assertSame(ThermalPrinterStatus::Assigned, $printer->status);
            $this->assertNull($printer->bus_id);
        }
        $this->assertDatabaseHas('thermal_printer_assignments', ['thermal_printer_id' => $moving->id, 'action' => 'company_changed', 'remarks' => 'Batch delivery']);
    }

    public function test_a_bulk_change_is_all_or_nothing(): void
    {
        $ok = ThermalPrinter::factory()->create();
        $retired = ThermalPrinter::factory()->withStatus(ThermalPrinterStatus::Retired)->create();

        $this->putJson(self::BASE.'/bulk/company', ['ids' => [$ok->id, $retired->id], 'company_id' => $this->company->id])
            ->assertUnprocessable()
            ->assertJsonPath('failures.0.id', $retired->id)
            ->assertJsonCount(1, 'failures');

        $this->assertNull($ok->fresh()->company_id);
        $this->assertSame(ThermalPrinterStatus::Available, $ok->fresh()->status);
        $this->assertDatabaseMissing('thermal_printer_assignments', ['thermal_printer_id' => $ok->id, 'action' => 'company_assigned']);
    }

    public function test_statuses_are_updated_in_bulk_for_all_matching_printers(): void
    {
        ThermalPrinter::factory()->count(2)->assignedTo($this->company)->create();
        $elsewhere = ThermalPrinter::factory()->assignedTo(Company::factory()->create())->create();
        $alreadyThere = ThermalPrinter::factory()->assignedTo($this->company)->withStatus(ThermalPrinterStatus::UnderMaintenance)->create();

        $this->patchJson(self::BASE.'/bulk/status', [
            'all' => true,
            'filter' => ['company_id' => $this->company->id],
            'status' => 'Under Maintenance',
        ])->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped', 1);

        $this->assertSame(3, ThermalPrinter::query()->where('company_id', $this->company->id)->where('status', 'Under Maintenance')->count());
        $this->assertSame(ThermalPrinterStatus::Assigned, $elsewhere->fresh()->status);
        $this->assertSame(ThermalPrinterStatus::UnderMaintenance, $alreadyThere->fresh()->status);
    }

    public function test_company_admins_cannot_bulk_update(): void
    {
        $printer = ThermalPrinter::factory()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->putJson(self::BASE.'/bulk/company', ['ids' => [$printer->id], 'company_id' => $this->company->id])->assertForbidden();
        $this->patchJson(self::BASE.'/bulk/status', ['ids' => [$printer->id], 'status' => 'Retired'])->assertForbidden();
        $this->assertNull($printer->fresh()->company_id);
    }

    public function test_the_summary_counts_the_whole_inventory(): void
    {
        $bus = Bus::factory()->create(['company_id' => $this->company->id]);
        ThermalPrinter::factory()->onBus($bus)->create();
        ThermalPrinter::factory()->assignedTo($this->company)->create();
        ThermalPrinter::factory()->count(2)->create();
        ThermalPrinter::factory()->withStatus(ThermalPrinterStatus::Defective)->create();

        $this->getJson(self::BASE.'/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.by_status.Available', 2)
            ->assertJsonPath('data.by_status.Assigned', 2)
            ->assertJsonPath('data.by_status.Defective', 1)
            ->assertJsonPath('data.by_status.Retired', 0)
            ->assertJsonPath('data.on_bus', 1)
            ->assertJsonPath('data.awaiting_bus', 1);

        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());
        $this->getJson(self::BASE.'/summary')->assertForbidden();
    }

    public function test_company_admins_cannot_print_inventory_labels(): void
    {
        $printer = ThermalPrinter::factory()->assignedTo($this->company)->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        $this->getJson(self::BASE.'/labels?'.http_build_query(['ids' => [$printer->id]]))->assertForbidden();
        $this->getJson(self::BASE.'/labels?all=1')->assertForbidden();
    }
}
