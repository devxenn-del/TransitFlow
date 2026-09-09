<?php

namespace Database\Factories;

use App\Actions\SeedCompanyRoles;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'code' => strtoupper(Str::random(6)),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => fake()->companyEmail(),
            'phone' => fake()->numerify('09#########'),
            'address_line' => fake()->streetAddress(),
            'address_barangay' => 'Brgy. '.fake()->word(),
            'address_city' => fake()->city(),
            'address_province' => fake()->state(),
            'logo_path' => null,
            'status' => CompanyStatus::Active->value,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            // Mirror provisioning: every company owns an editable role set.
            // Only seed when the platform templates exist (RBAC seeded).
            if (Role::query()->templates()->exists()) {
                app(SeedCompanyRoles::class)->handle($company);
            }
        });
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Suspended->value]);
    }

    public function cannotCreateAccounts(): static
    {
        return $this->state(fn () => ['can_create_accounts' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Inactive->value]);
    }

    /**
     * Give the company its one settings row, as provisioning would.
     */
    public function withSettings(): static
    {
        return $this->afterCreating(function (Company $company): void {
            CompanySetting::factory()->for($company)->create([
                'receipt_org_name' => $company->name,
                'org_email' => $company->email ?? '',
            ]);
        });
    }
}
