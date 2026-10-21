<?php

namespace App\Actions;

use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts an Assigned printer on one of its own company's buses, moves it to
 * another of them, or (with a null bus) takes it off its bus. The bus is
 * looked up within the printer's company as stored in the database, so a
 * bus ID from any other company is refused whatever the request claims.
 * One printer sits on at most one bus; a bus may carry several printers.
 * Only Active buses can be picked (the same rule as starting a trip).
 */
class AssignThermalPrinterBus
{
    public function handle(ThermalPrinter $printer, ?int $busId, ?string $remarks = null): ThermalPrinter
    {
        return DB::transaction(function () use ($printer, $busId, $remarks): ThermalPrinter {
            $locked = ThermalPrinter::query()->withoutCompanyScope()->lockForUpdate()->findOrFail($printer->id);
            $previousBus = $locked->bus_id ? Bus::query()->withoutCompanyScope()->find($locked->bus_id) : null;

            if ($busId === null) {
                if ($previousBus === null) {
                    throw ValidationException::withMessages(['bus_id' => 'This printer is not assigned to a bus.']);
                }
                $newBus = null;
            } else {
                $newBus = $this->eligibleBus($locked, $busId, $previousBus);
            }

            $locked->forceFill(['bus_id' => $newBus?->id])->save();

            $action = match (true) {
                $newBus === null => 'bus_removed',
                $previousBus === null => 'bus_assigned',
                default => 'bus_changed',
            };

            $locked->recordHistory(
                $action,
                ['company_id' => $locked->company_id, 'bus' => $previousBus, 'status' => $locked->status],
                ['company_id' => $locked->company_id, 'bus' => $newBus, 'status' => $locked->status],
                $remarks,
            );

            Audit::record("thermal_printer.{$action}", $locked, [
                'thermal_id' => $locked->thermal_id,
                'from_bus' => $previousBus?->bus_number,
                'to_bus' => $newBus?->bus_number,
            ], company: Company::query()->find($locked->company_id));

            return $locked;
        });
    }

    private function eligibleBus(ThermalPrinter $printer, int $busId, ?Bus $currentBus): Bus
    {
        if ($printer->company_id === null || ! $printer->status->acceptsBus()) {
            throw ValidationException::withMessages([
                'bus_id' => 'Only a printer that is Assigned to a company can be put on a bus.',
            ]);
        }

        $bus = Bus::query()->withoutCompanyScope()
            ->where('company_id', $printer->company_id)
            ->sharedLock()
            ->find($busId);

        if ($bus === null) {
            throw ValidationException::withMessages(['bus_id' => "That bus does not belong to this printer's company."]);
        }

        if ($bus->is($currentBus)) {
            throw ValidationException::withMessages(['bus_id' => 'This printer is already assigned to that bus.']);
        }

        if ($bus->status !== 'Active') {
            throw ValidationException::withMessages(['bus_id' => "Bus {$bus->bus_number} is {$bus->status}. Only Active buses can be assigned a printer."]);
        }

        return $bus;
    }
}
