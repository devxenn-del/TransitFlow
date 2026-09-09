<?php

namespace Database\Factories;

use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'company_id' => null,
            'role' => UserRole::CompanyUser->value,
            'role_id' => null,
            'status' => 'active',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform account: no company, Super Admin role + grants.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'company_id' => null,
            'role' => UserRole::SuperAdmin->value,
        ])->withRole('super_admin');
    }

    /**
     * A plain company user. Defaults to the `office` role (grants
     * buses.view / accounts.view) unless {@see withRole()} overrides it.
     */
    public function forCompany(Company|int|null $company = null): static
    {
        return $this->state(fn () => [
            'company_id' => $company instanceof Company ? $company->id : ($company ?? Company::factory()),
            'role' => UserRole::CompanyUser->value,
        ])->withRole('office');
    }

    /**
     * A company's administrator: `company_admin` role + its full grants.
     */
    public function companyAdmin(Company|int|null $company = null): static
    {
        return $this->state(fn () => [
            'company_id' => $company instanceof Company ? $company->id : ($company ?? Company::factory()),
            'role' => UserRole::CompanyAdmin->value,
        ])->withRole('company_admin');
    }

    /**
     * Attach a fine-grained role by key and seed the user's
     * `user_permissions` from that role's defaults. No-op if the roles
     * table has not been seeded (RbacSeeder).
     */
    public function withRole(string $roleKey): static
    {
        return $this->afterCreating(function (User $user) use ($roleKey): void {
            // Prefer the user's own company role; fall back to the platform template.
            $role = Role::query()
                ->where('key', $roleKey)
                ->where(fn ($q) => $q->where('company_id', $user->company_id)->orWhereNull('company_id'))
                ->orderByRaw('company_id is null')
                ->first();

            if ($role === null) {
                return;
            }

            $user->forceFill(['role_id' => $role->id])->save();
            app(SyncUserRolePermissions::class)->handle($user->refresh(), reset: true);
        });
    }

    /**
     * A user with a role but no permission grants at all.
     */
    public function withoutPermissions(): static
    {
        return $this->afterCreating(fn (User $user) => $user->permissions()->detach());
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
