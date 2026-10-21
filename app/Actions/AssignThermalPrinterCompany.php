<?php

namespace App\Actions;

use App\Enums\ThermalPrinterStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a printer to a company, or moves it to another one. The printer
 * becomes Assigned. Its bus is always cleared, because that bus belongs to
 * the previous company — a cross-company printer/bus pair can never be
 * saved. Assigning a company never picks a bus; that is a separate step
 * (AssignThermalPrinterBus).
 */
class AssignThermalPrinterCompany
{
    public function handle(ThermalPrinter $printer, Company $company, ?CarbonInterface $dateIssued = null, ?string $remarks = null): ThermalPrinter
    {
        return DB::transaction(function () use ($printer, $company, $dateIssued, $remarks): ThermalPrinter {
            $locked = ThermalPrinter::query()->withoutCompanyScope()->lockForUpdate()->findOrFail($printer->id);

            if (! $locked->status->acceptsCompany()) {
                throw ValidationException::withMessages([
                    'company_id' => "A {$locked->status->value} printer cannot be issued to a company. Change its status first.",
                ]);
            }

            if ($locked->company_id === $company->id) {
                throw ValidationException::withMessages(['company_id' => 'This printer is already assigned to that company.']);
            }

            $previousCompanyId = $locked->company_id;
            $previousBus = $locked->bus_id ? Bus::query()->withoutCompanyScope()->find($locked->bus_id) : null;
            $previousStatus = $locked->status;

            $locked->forceFill([
                'company_id' => $company->id,
                'bus_id' => null,
                'status' => ThermalPrinterStatus::Assigned,
                'date_issued' => $dateIssued ?? today(),
            ])->save();

            $locked->recordHistory(
                $previousCompanyId === null ? 'company_assigned' : 'company_changed',
                ['company_id' => $previousCompanyId, 'bus' => $previousBus, 'status' => $previousStatus],
                ['company_id' => $company->id, 'status' => ThermalPrinterStatus::Assigned],
                $remarks,
            );

            Audit::record('thermal_printer.company_assigned', $locked, [
                'thermal_id' => $locked->thermal_id,
                'from_company_id' => $previousCompanyId,
                'to_company_id' => $company->id,
                'bus_cleared' => $previousBus?->bus_number,
            ], company: $company);

            return $locked;
        });
    }
}
