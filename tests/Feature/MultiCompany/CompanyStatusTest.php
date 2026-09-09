<?php

namespace Tests\Feature\MultiCompany;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanyStatusTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_users_of_a_suspended_company_are_locked_out(): void
    {
        $company = Company::factory()->suspended()->create();
        Sanctum::actingAs(User::factory()->forCompany($company)->create());

        $this->getJson('/api/company/buses')->assertForbidden();
        $this->getJson('/api/company/profile')->assertForbidden();
    }

    public function test_users_of_an_inactive_company_are_locked_out(): void
    {
        $company = Company::factory()->inactive()->create();
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());

        $this->getJson('/api/company/buses')->assertForbidden();
    }

    public function test_super_admin_is_not_affected_by_company_status(): void
    {
        Company::factory()->suspended()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/super-admin/companies')->assertOk();
    }

    public function test_access_returns_once_a_company_is_reactivated(): void
    {
        $company = Company::factory()->suspended()->create();
        Sanctum::actingAs(User::factory()->forCompany($company)->create());

        $this->getJson('/api/company/buses')->assertForbidden();

        $company->update(['status' => 'active']);

        $this->getJson('/api/company/buses')->assertOk();
    }
}
