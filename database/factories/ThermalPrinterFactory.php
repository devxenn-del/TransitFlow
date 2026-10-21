<?php

namespace Database\Factories;

use App\Enums\ThermalPrinterStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * An Available (unissued) printer with real generated IDs. Use the
 * assignedTo() / onBus() states for issued ones.
 *
 * @extends Factory<ThermalPrinter>
 */
class ThermalPrinterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model' => fake()->randomElement(['Epson TM-P20', 'Star SM-L200', 'Xprinter XP-P300']),
            'mac_address' => implode(':', str_split(strtoupper(fake()->unique()->regexify('[0-9A-F]{12}')), 2)),
            'status' => ThermalPrinterStatus::Available,
            'company_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThermalPrinter $printer): void {
            if ($printer->thermal_id === null) {
                DB::transaction(fn () => $printer->generateIdentifiers());
            }
        });
    }

    public function assignedTo(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
            'status' => ThermalPrinterStatus::Assigned,
            'date_issued' => today(),
        ]);
    }

    public function onBus(Bus $bus): static
    {
        return $this->state(fn () => [
            'company_id' => $bus->company_id,
            'bus_id' => $bus->id,
            'status' => ThermalPrinterStatus::Assigned,
            'date_issued' => today(),
        ]);
    }

    public function withStatus(ThermalPrinterStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
