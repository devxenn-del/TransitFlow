<?php

namespace Database\Seeders;

use App\Enums\BillingFrequency;
use App\Enums\UserRole;
use App\Models\Fee;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard platform fee catalogue — System, Maintenance and Device
 * fees, billed monthly to every company. Idempotent: a fee that already
 * exists (matched by name) is left untouched, so amounts later changed in
 * the Super Admin "Fees" page are never overwritten by a re-run.
 */
class FeeSeeder extends Seeder
{
    private const EFFECTIVE_DATE = '2026-10-05';

    public function run(): void
    {
        $actorId = User::query()->where('role', UserRole::SuperAdmin->value)->value('id');

        foreach ($this->fees() as $fee) {
            Fee::query()->firstOrCreate(['name' => $fee['name']], [
                ...$fee,
                'billing_frequency' => BillingFrequency::Monthly->value,
                'billing_interval_months' => null,
                'applies_to_all_companies' => true,
                'is_active' => true,
                'effective_date' => self::EFFECTIVE_DATE,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    /**
     * @return list<array{name: string, description: string, amount: int}>
     */
    private function fees(): array
    {
        return [
            [
                'name' => 'System Fee',
                'description' => 'A recurring fee for access to and use of the TransitFlow system, including core software features, system management, and platform services.',
                'amount' => 2000,
            ],
            [
                'name' => 'Maintenance Fee',
                'description' => 'A recurring fee that covers system maintenance, updates, bug fixes, performance improvements, and ongoing technical support to keep the system reliable and operational.',
                'amount' => 500,
            ],
            [
                'name' => 'Device Fee',
                'description' => 'A fee for the provision and use of the thermal printer device, including device support and maintenance.',
                'amount' => 0,
            ],
        ];
    }
}
