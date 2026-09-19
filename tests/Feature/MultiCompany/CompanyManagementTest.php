<?php

namespace Tests\Feature\MultiCompany;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Mail\CompanyAdminWelcome;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    public function test_super_admin_can_provision_a_company_with_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/super-admin/companies', [
            'name' => 'Cavite Star Express',
            'code' => 'CVSTAR',
            'email' => 'ops@cvstar.test',
            'admin' => [
                'name' => 'Cvstar Admin',
                'email' => 'admin@cvstar.test',
                'password' => 'secret-password',
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.code', 'CVSTAR');

        $company = Company::query()->where('code', 'CVSTAR')->firstOrFail();
        $this->assertDatabaseHas('company_settings', ['company_id' => $company->id]);
        $this->assertDatabaseHas('users', [
            'email' => 'admin@cvstar.test',
            'company_id' => $company->id,
            'role' => UserRole::CompanyAdmin->value,
        ]);
    }

    public function test_provisioning_emails_the_admin_and_forces_a_first_sign_in_password_change(): void
    {
        Mail::fake();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/companies', [
            'name' => 'Batangas Rapid',
            'email' => 'office@batangasrapid.test',
            'admin' => ['name' => 'BR Admin', 'email' => 'admin@br.test', 'password' => 'temp-password-1'],
        ])->assertCreated();

        $admin = User::query()->where('email', 'admin@br.test')->firstOrFail();
        $this->assertTrue($admin->must_change_password);

        // Credentials go to the COMPANY email, not the admin's login email.
        Mail::assertQueued(CompanyAdminWelcome::class, fn (CompanyAdminWelcome $mail) => $mail->hasTo('office@batangasrapid.test')
            && ! $mail->hasTo('admin@br.test')
            && $mail->temporaryPassword === 'temp-password-1'
            && $mail->admin->is($admin));
    }

    public function test_super_admin_sets_and_flips_the_account_creation_flag(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/super-admin/companies', ['name' => 'Locked Lines', 'can_create_accounts' => false])
            ->assertCreated()
            ->assertJsonPath('data.can_create_accounts', false);

        $company = Company::query()->where('name', 'Locked Lines')->firstOrFail();
        $this->assertFalse($company->can_create_accounts);

        $this->patchJson("/api/super-admin/companies/{$company->id}", ['can_create_accounts' => true])
            ->assertOk()
            ->assertJsonPath('data.can_create_accounts', true);
    }

    public function test_a_company_admin_cannot_list_all_companies(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin()->create());

        $this->getJson('/api/super-admin/companies')->assertForbidden();
    }

    public function test_a_company_admin_cannot_create_a_company(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin()->create());

        $this->postJson('/api/super-admin/companies', ['name' => 'Rogue Co'])->assertForbidden();
        $this->assertDatabaseMissing('companies', ['name' => 'Rogue Co']);
    }

    public function test_a_company_admin_can_update_their_own_company_profile(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());

        $this->patchJson('/api/company/profile', ['name' => 'New Name', 'phone' => '09171234567'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $company->fresh()->name);
    }

    public function test_a_company_admin_cannot_edit_another_company_via_the_super_admin_endpoint(): void
    {
        $other = Company::factory()->create(['name' => 'Untouchable']);
        Sanctum::actingAs(User::factory()->companyAdmin()->create());

        $this->patchJson("/api/super-admin/companies/{$other->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Untouchable', $other->fresh()->name);
    }

    public function test_a_plain_company_user_cannot_edit_the_company_profile(): void
    {
        $company = Company::factory()->create(['name' => 'Stable']);
        Sanctum::actingAs(User::factory()->forCompany($company)->create());

        $this->patchJson('/api/company/profile', ['name' => 'Changed'])->assertForbidden();
        $this->assertSame('Stable', $company->fresh()->name);
    }

    public function test_deleting_a_company_also_deletes_its_users(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin($company)->create();
        $staff = User::factory()->forCompany($company)->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->deleteJson("/api/super-admin/companies/{$company->id}")->assertNoContent();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_only_super_admin_can_change_company_status(): void
    {
        $company = Company::factory()->create();

        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $this->patchJson("/api/super-admin/companies/{$company->id}/status", [
            'status' => CompanyStatus::Suspended->value,
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->patchJson("/api/super-admin/companies/{$company->id}/status", [
            'status' => CompanyStatus::Suspended->value,
        ])->assertOk();

        $this->assertSame(CompanyStatus::Suspended, $company->fresh()->status);
    }
}
