<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $companyA;

    private Company $companyB;

    private Bus $busA;

    private Bus $busB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::factory()->create(['name' => 'Alpha Lines']);
        $this->companyB = Company::factory()->create(['name' => 'Bravo Transit']);

        $this->busA = Bus::factory()->create([
            'company_id' => $this->companyA->id, 'bus_number' => 'A-1', 'plate_number' => 'AAA-1111',
        ]);
        $this->busB = Bus::factory()->create([
            'company_id' => $this->companyB->id, 'bus_number' => 'B-1', 'plate_number' => 'BBB-1111',
        ]);
    }

    public function test_a_company_user_only_sees_their_own_companys_buses(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $response = $this->getJson('/api/company/buses');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->busA->id));
        $this->assertFalse($ids->contains($this->busB->id));
    }

    public function test_fetching_another_companys_bus_by_id_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->getJson("/api/company/buses/{$this->busB->id}")->assertNotFound();
    }

    public function test_updating_another_companys_bus_by_id_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->patchJson("/api/company/buses/{$this->busB->id}", ['status' => 'inactive'])
            ->assertNotFound();

        $this->assertSame('Active', $this->busB->fresh()->status);
    }

    public function test_deleting_another_companys_bus_by_id_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->deleteJson("/api/company/buses/{$this->busB->id}")->assertNotFound();

        $this->assertDatabaseHas('buses', ['id' => $this->busB->id]);
    }

    public function test_created_bus_is_owned_by_the_callers_company_regardless_of_payload(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $response = $this->postJson('/api/company/buses', [
            'bus_number' => 'NEW-1',
            'plate_number' => 'NEW-0001',
            'company_id' => $this->companyB->id, // attempt to plant it in company B
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('buses', [
            'bus_number' => 'NEW-1',
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_bus_number_uniqueness_is_scoped_per_company(): void
    {
        // Company B already has bus "B-1"; company A may still create "B-1".
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->postJson('/api/company/buses', ['bus_number' => 'B-1', 'plate_number' => 'ZZZ-9999'])
            ->assertCreated();

        // ...but not a second "A-1" within company A.
        $this->postJson('/api/company/buses', ['bus_number' => 'A-1', 'plate_number' => 'ZZZ-0001'])
            ->assertJsonValidationErrorFor('bus_number');
    }

    public function test_super_admin_can_scope_into_one_company_with_a_header(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->withHeader('X-Company-Id', (string) $this->companyB->id)
            ->getJson('/api/company/buses');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->busB->id));
        $this->assertFalse($ids->contains($this->busA->id));
    }

    public function test_company_scope_can_be_lifted_deliberately(): void
    {
        $context = app(CompanyContext::class);
        $context->set($this->companyA->id);

        $this->assertSame(1, Bus::query()->count());

        $all = $context->actAcrossCompanies(fn () => Bus::query()->count());
        $this->assertSame(2, $all);

        // Restored afterwards.
        $this->assertSame(1, Bus::query()->count());
    }
}
