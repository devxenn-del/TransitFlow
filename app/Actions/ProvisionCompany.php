<?php

namespace App\Actions;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Mail\CompanyAdminWelcome;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates a company together with the things a company can't function
 * without: its one settings row, its editable role set and (optionally) its
 * first Company Admin account. Runs in a transaction so a half-provisioned
 * company is never left behind; the welcome email is sent only after commit.
 */
class ProvisionCompany
{
    public function __construct(
        private SyncUserRolePermissions $syncPermissions,
        private SeedCompanyRoles $seedRoles,
    ) {}

    /**
     * @param  array{name: string, code?: string|null, email?: string|null, phone?: string|null, address_line?: string|null, address_barangay?: string|null, address_city?: string|null, address_province?: string|null, can_create_accounts?: bool}  $attributes
     * @param  array{name: string, email: string, password: string}|null  $admin
     */
    public function handle(array $attributes, ?array $admin = null): Company
    {
        /** @var array{user: User, password: string}|null $welcome */
        $welcome = null;

        $company = DB::transaction(function () use ($attributes, $admin, &$welcome): Company {
            $name = $attributes['name'];

            $company = Company::query()->create([
                'name' => $name,
                'code' => $attributes['code'] ?? $this->uniqueCode(),
                'slug' => $this->uniqueSlug($name),
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address_line' => $attributes['address_line'] ?? null,
                'address_barangay' => $attributes['address_barangay'] ?? null,
                'address_city' => $attributes['address_city'] ?? null,
                'address_province' => $attributes['address_province'] ?? null,
                'status' => CompanyStatus::Active->value,
                'can_create_accounts' => $attributes['can_create_accounts'] ?? true,
            ]);

            CompanySetting::query()->create([
                'company_id' => $company->id,
                'receipt_org_name' => $company->name,
                'org_email' => $company->email ?? '',
                'org_contact_number' => $company->phone ?? '',
            ]);

            // Give the company its own editable copy of the default roles.
            $this->seedRoles->handle($company);

            if ($admin !== null) {
                // A brand-new company admin must set their own password on first sign-in.
                $plaintextGiven = ! $this->looksHashed($admin['password']);

                $adminUser = User::query()->create([
                    'company_id' => $company->id,
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'password' => $admin['password'],
                    'role' => UserRole::CompanyAdmin->value,
                    'role_id' => Role::query()->forCompany($company->id)->where('is_admin', true)->value('id'),
                    'status' => 'active',
                    'must_change_password' => $plaintextGiven,
                ]);

                $this->syncPermissions->handle($adminUser, reset: true);

                if ($plaintextGiven) {
                    $welcome = ['user' => $adminUser, 'password' => $admin['password']];
                }
            }

            return $company;
        });

        if ($welcome !== null) {
            $this->sendWelcome($company, $welcome['user'], $welcome['password']);
        }

        return $company;
    }

    private function sendWelcome(Company $company, User $admin, string $password): void
    {
        // Credentials go to the company's own email address (entered when the
        // company was created); fall back to the admin's login email only if
        // the company has no address on file.
        $recipient = $company->email ?: $admin->email;

        try {
            Mail::to($recipient)->send(new CompanyAdminWelcome($company, $admin, $password));
        } catch (\Throwable $e) {
            // Never let a mail-transport problem fail company provisioning.
            Log::warning('Company admin welcome email failed', [
                'company_id' => $company->id,
                'admin_email' => $admin->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function looksHashed(string $value): bool
    {
        return (bool) (password_get_info($value)['algo'] ?? false);
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Company::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
