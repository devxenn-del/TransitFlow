<?php

namespace Database\Seeders;

use App\Actions\ProvisionCompany;
use App\Actions\RollUpBusDayCashCount;
use App\Actions\SaveFareCell;
use App\Actions\SyncUserRolePermissions;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\PassengerTypeArticle;
use App\Models\RemittanceCashCount;
use App\Models\Role;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Terminal;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Support\Denominations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two demo companies, each with an admin, a plain user and a few buses —
 * enough to see multi-company isolation working locally. Idempotent.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $this->makeCompany('Perjoda Transit Corporation', 'PERJODA', 'perjoda');
        $this->makeCompany('Southline Bus Company', 'SOUTHLINE', 'southline');
    }

    private function makeCompany(string $name, string $code, string $handle): void
    {
        $company = Company::query()->where('code', $code)->first();

        if ($company === null) {
            $company = app(ProvisionCompany::class)->handle(
                [
                    'name' => $name,
                    'code' => $code,
                    'email' => "ops@{$handle}.test",
                    'phone' => '09170000000',
                    'address_city' => 'Dasmariñas',
                    'address_province' => 'Cavite',
                ],
                [
                    'name' => ucfirst($handle).' Admin',
                    'email' => "admin@{$handle}.test",
                    'password' => Hash::make('password'),
                ],
            );
        }

        $staff = User::query()->updateOrCreate(
            ['email' => "staff@{$handle}.test"],
            [
                'company_id' => $company->id,
                'name' => ucfirst($handle).' Staff',
                'password' => Hash::make('password'),
                'role' => UserRole::CompanyUser->value,
                'role_id' => Role::query()->forCompany($company->id)->where('key', 'office')->value('id'),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        app(SyncUserRolePermissions::class)->handle($staff, reset: true);

        $manager = User::query()->updateOrCreate(
            ['email' => "manager@{$handle}.test"],
            [
                'company_id' => $company->id,
                'name' => ucfirst($handle).' Manager',
                'password' => Hash::make('password'),
                'role' => UserRole::CompanyUser->value,
                'role_id' => Role::query()->forCompany($company->id)->where('key', 'manager')->value('id'),
                'status' => 'active',
                'email_verified_at' => now(),
                'void_pin_hash' => Hash::make('1234'), // demo void PIN
            ],
        );
        app(SyncUserRolePermissions::class)->handle($manager, reset: true);

        $conductor = User::query()->updateOrCreate(
            ['email' => "conductor@{$handle}.test"],
            [
                'company_id' => $company->id,
                'name' => ucfirst($handle).' Conductor',
                'password' => Hash::make('password'),
                'role' => UserRole::CompanyUser->value,
                'role_id' => Role::query()->forCompany($company->id)->where('key', 'conductor')->value('id'),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
        app(SyncUserRolePermissions::class)->handle($conductor, reset: true);

        $buses = [];
        for ($i = 1; $i <= 3; $i++) {
            $buses[] = Bus::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'bus_number' => sprintf('%s-%03d', $code, $i)],
                ['plate_number' => sprintf('%s-%04d', substr($code, 0, 3), 1000 + $i), 'status' => 'Active'],
            );
        }

        // Assign the first two buses to the demo conductor.
        $conductor->buses()->syncWithoutDetaching([$buses[0]->id, $buses[1]->id]);

        // Keep the demo conductor clocked in so the start-trip flow is usable.
        Attendance::query()->firstOrCreate(
            ['user_id' => $conductor->id, 'clock_out_at' => null],
            ['company_id' => $company->id, 'clock_in_at' => now()->subHours(3), 'clock_in_source' => 'web'],
        );

        $this->seedFleet($company->id);
        $this->seedOperations($company->id, $conductor, $buses[0], $staff);
    }

    /**
     * A couple of trips so the admin Trip Monitoring + Remittance screens
     * have content: one live OnTrip; one Arrived trip pending receipt; one
     * Arrived + received (rolled into a cash count) pending approval.
     * Idempotent.
     */
    private function seedOperations(int $companyId, User $conductor, Bus $bus, User $cashier): void
    {
        if (Trip::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        $driverId = Driver::withoutGlobalScopes()->where('company_id', $companyId)->value('id');
        $route = Route::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('origin', 'SM PALA-PALA')->where('destination', 'EPZA (ROSARIO)')->first();
        $regularId = PassengerType::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Regular')->value('id');

        // DatabaseSeeder uses WithoutModelEvents, so the Trip model's
        // reference-generating hook does not run here — build it explicitly.
        $ref = fn () => Trip::makeReference($bus->bus_number, now()->subDay());

        $base = [
            'company_id' => $companyId,
            'conductor_id' => $conductor->id,
            'bus_id' => $bus->id,
            'driver_id' => $driverId,
            'bus_number' => $bus->bus_number,
            'origin' => 'SM PALA-PALA',
            'coverage_origin' => 'SM PALA-PALA',
            'coverage_destination' => 'EPZA (ROSARIO)',
            'op_date' => now()->subDay()->toDateString(),
            'shift' => 'Morning',
        ];

        $sellTickets = function (Trip $trip) use ($companyId, $route, $regularId): void {
            foreach ([21, 21, 19, 15, 21, 21, 19, 21, 15, 21] as $i => $fare) {
                Ticket::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'trip_id' => $trip->id,
                    'route_id' => $route?->id,
                    'passenger_type_id' => $regularId,
                    'boarding_type' => $i % 3 === 0 ? 'Pickup' : 'Terminal',
                    'payment_method' => $i % 4 === 0 ? 'QR' : 'Cash',
                    'qr_reference' => $i % 4 === 0 ? strtoupper(fake()->bothify('??##??')) : null,
                    'fare' => $fare,
                    'issued_at' => now()->subHours(3),
                ]);
            }
        };

        // Arrived — remitted, still awaiting receipt at the desk.
        $pending = Trip::withoutGlobalScopes()->create([
            ...$base,
            'reference' => $ref(),
            'status' => 'Arrived',
            'started_at' => now()->subDay()->setHour(6),
            'ended_at' => now()->subDay()->setHour(8),
            'remitted_amount' => 194,
        ]);
        $sellTickets($pending);
        $pending->dispatches()->create([
            'company_id' => $companyId, 'barker_name' => 'Mang Tomas', 'amount' => 30, 'dispatched_at' => now()->subDay(),
        ]);

        // Arrived — received (cash counted, rolled into a bus/day/shift cash count), awaiting approval.
        $received = Trip::withoutGlobalScopes()->create([
            ...$base,
            'reference' => $ref(),
            'status' => 'Arrived',
            'started_at' => now()->subDay()->setHour(9),
            'ended_at' => now()->subDay()->setHour(11),
            'remitted_amount' => 190,
            'remittance_received_at' => now()->subDay()->setHour(12),
            'remittance_received_by' => $cashier->id,
        ]);
        $sellTickets($received);
        $den = ['q1000' => 0, 'q500' => 0, 'q200' => 0, 'q100' => 1, 'q50' => 1, 'q20' => 1, 'q10' => 1, 'q5' => 2, 'q1' => 6]; // ₱186, ₱4 short
        RemittanceCashCount::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'trip_id' => $received->id,
            'bus_id' => $bus->id,
            'op_date' => $base['op_date'],
            'shift' => 'Morning',
            ...$den,
            'counted_total' => Denominations::total($den),
            'expected_amount' => 190,
            'variance' => Denominations::total($den) - 190,
            'status' => 'Received',
            'received_by' => $cashier->id,
            'received_by_name' => $cashier->name,
            'received_at' => now()->subDay()->setHour(12),
        ]);
        app(RollUpBusDayCashCount::class)->handle($companyId, $bus->id, $base['op_date'], 'Morning');

        // Live trip currently on the road (today).
        Trip::withoutGlobalScopes()->create([
            ...$base,
            'reference' => Trip::makeReference($bus->bus_number, now()),
            'op_date' => now()->toDateString(),
            'status' => 'OnTrip',
            'started_at' => now()->subMinutes(40),
            'marked_on_trip_at' => now()->subMinutes(25),
        ]);
    }

    /**
     * A terminal set + one LTFRB franchise with an ordered stop list and a
     * populated fare-matrix grid. Idempotent.
     */
    private function seedFleet(int $companyId): void
    {
        $stops = ['SM PALA-PALA', 'LANGKAAN', 'FCIE', 'DE FUEGO', 'EPZA (ROSARIO)', 'TEJERO'];

        foreach (['SM PALA-PALA', 'EPZA (ROSARIO)'] as $name) {
            Terminal::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['boarding_mode' => 'Both', 'status' => 'Active'],
            );
        }

        foreach (['JUAN DELA CRUZ', 'PEDRO SANTOS', 'MARIO REYES'] as $name) {
            Driver::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['status' => 'Active', 'license_number' => 'N01-'.fake()->numerify('##-######')],
            );
        }

        $seniorPct = 20;
        $types = [
            ['Regular', 'Fare Matrix', 0, 10],
            ['Senior', 'Fare Matrix', $seniorPct, 20],
            ['PWD', 'Fare Matrix', $seniorPct, 30],
            ['Articles Sales', 'Manual Amount', 0, 40],
        ];
        foreach ($types as [$name, $mode, $pct, $order]) {
            $pt = PassengerType::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['fare_mode' => $mode, 'discount_percent' => $pct, 'sort_order' => $order, 'status' => 'Active'],
            );

            if ($name === 'Articles Sales') {
                PassengerTypeArticle::withoutGlobalScopes()->updateOrCreate(
                    ['passenger_type_id' => $pt->id, 'label' => 'Student'],
                    ['company_id' => $companyId, 'amount' => 10, 'sort_order' => 10, 'status' => 'Active'],
                );
            }
        }

        $franchise = Franchise::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'case_no' => 'CASE 2019-0417'],
            [
                'applicant_name' => 'Demo Transport Cooperative',
                'route_description' => 'SM PALA-PALA - EPZA (ROSARIO) VIA GEN. TRIAS AND VICE VERSA',
                'route_origin' => 'SM PALA-PALA',
                'route_destination' => 'EPZA (ROSARIO)',
                'status' => 'Active',
            ],
        );

        foreach ($stops as $i => $name) {
            RouteStop::withoutGlobalScopes()->updateOrCreate(
                ['franchise_id' => $franchise->id, 'name' => $name],
                ['company_id' => $companyId, 'sort_order' => $i + 1, 'status' => 'Active'],
            );
        }

        // Forward fares along the ordered stop list: ₱13 base + ₱2/leg.
        $save = app(SaveFareCell::class);
        for ($i = 0; $i < count($stops); $i++) {
            for ($j = $i + 1; $j < count($stops); $j++) {
                $save->handle($franchise, $stops[$i], $stops[$j], 13 + ($j - $i - 1) * 2);
            }
        }
    }
}
