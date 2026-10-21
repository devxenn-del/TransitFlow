<?php

namespace App\Actions;

use App\Enums\ThermalPrinterStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a printer to another inventory status, following
 * ThermalPrinterStatus::allowedTransitions(). Each status keeps the
 * links it allows and drops the others, so no status is ever saved with a
 * link it forbids:
 * - Returned clears the company, the bus and the issue date;
 * - Defective / Under Maintenance clear the bus but keep the company;
 * - Available and Retired require that the printer has no company (Return it first);
 * - Assigned (back in service) requires that it still has its company.
 */
class ChangeThermalPrinterStatus
{
    public function handle(ThermalPrinter $printer, ThermalPrinterStatus $target, ?string $remarks = null): ThermalPrinter
    {
        return DB::transaction(function () use ($printer, $target, $remarks): ThermalPrinter {
            $locked = ThermalPrinter::query()->withoutCompanyScope()->lockForUpdate()->findOrFail($printer->id);
            $current = $locked->status;

            if ($current === $target) {
                throw ValidationException::withMessages(['status' => "This printer is already {$target->value}."]);
            }

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => "A {$current->value} printer cannot be changed to {$target->value}."]);
            }

            if (in_array($target, [ThermalPrinterStatus::Available, ThermalPrinterStatus::Retired], true) && $locked->company_id !== null) {
                throw ValidationException::withMessages(['status' => "This printer is still issued to a company. Mark it Returned before making it {$target->value}."]);
            }

            if ($target === ThermalPrinterStatus::Assigned && $locked->company_id === null) {
                throw ValidationException::withMessages(['status' => 'This printer has no company. Issue it to a company with Assign Company instead.']);
            }

            $previousCompanyId = $locked->company_id;
            $previousBus = $locked->bus_id ? Bus::query()->withoutCompanyScope()->find($locked->bus_id) : null;

            $locked->status = $target;
            if (! $target->keepsCompany()) {
                $locked->forceFill(['company_id' => null, 'date_issued' => null]);
            }
            if (! $target->acceptsBus()) {
                $locked->bus_id = null;
            }
            $locked->save();

            $keptBus = $locked->bus_id !== null ? $previousBus : null;

            $locked->recordHistory(
                'status_changed',
                ['company_id' => $previousCompanyId, 'bus' => $previousBus, 'status' => $current],
                ['company_id' => $locked->company_id, 'bus' => $keptBus, 'status' => $target],
                $remarks,
            );

            Audit::record('thermal_printer.status_changed', $locked, [
                'thermal_id' => $locked->thermal_id,
                'from' => $current->value,
                'to' => $target->value,
                'company_cleared' => $previousCompanyId !== null && $locked->company_id === null,
                'bus_cleared' => $previousBus !== null && $keptBus === null ? $previousBus->bus_number : null,
            ], company: $previousCompanyId ? Company::query()->find($previousCompanyId) : null);

            return $locked;
        });
    }
}
