<?php

namespace App\Actions;

use App\Enums\ThermalPrinterStatus;
use App\Models\ThermalPrinter;
use App\Support\Audit;
use App\Support\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OverflowException;

/**
 * Adds a new printer to the platform inventory as Available (no company,
 * no bus), with a freshly generated Thermal ID / Device ID pair. IDs are
 * never taken from the request.
 */
class RegisterThermalPrinter
{
    /**
     * @param  array{model: string, mac_address?: ?string, serial_number?: ?string, remarks?: ?string}  $input
     */
    public function handle(array $input): ThermalPrinter
    {
        try {
            $printer = DB::transaction(function () use ($input): ThermalPrinter {
                $printer = new ThermalPrinter($input);
                $printer->status = ThermalPrinterStatus::Available;
                $printer->generateIdentifiers();

                // Never let a Super Admin's open company workspace stamp it onto a company.
                app(CompanyContext::class)->actAsCompany(null, fn () => $printer->save());

                $printer->recordHistory('registered', [], ['status' => ThermalPrinterStatus::Available], $input['remarks'] ?? null);

                return $printer;
            });
        } catch (OverflowException) {
            throw ValidationException::withMessages([
                'model' => 'The Thermal ID / Device ID sequence has reached 999999, its six-digit limit. No more printers can be registered until the ID format is extended.',
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['mac_address' => 'A printer with this MAC address is already registered.']);
        }

        Audit::record('thermal_printer.registered', $printer, [
            'thermal_id' => $printer->thermal_id,
            'device_id' => $printer->device_id,
            'model' => $printer->model,
        ]);

        return $printer;
    }
}
