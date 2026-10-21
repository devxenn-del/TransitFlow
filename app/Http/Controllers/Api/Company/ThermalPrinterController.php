<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\ThermalPrinterStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThermalPrinterResource;
use App\Models\ThermalPrinter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The thermal printers issued to the caller's company — strictly read-only.
 * The inventory is managed by the Super Admin (Api\SuperAdmin\
 * ThermalPrinterController); there are no write routes here at all.
 * Every query runs through CompanyScope, so this controller never filters
 * by `company_id` itself, and another company's printer 404s at binding.
 */
class ThermalPrinterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ThermalPrinter::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ThermalPrinterStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ThermalPrinterResource::collection(
            ThermalPrinter::query()->with('bus')
                ->when($request->filled('q'), fn (Builder $q) => $q->search($request->string('q')->trim()->value()))
                ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->value()))
                ->orderBy('thermal_id')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function show(ThermalPrinter $printer): ThermalPrinterResource
    {
        $this->authorize('view', $printer);

        return ThermalPrinterResource::make($printer->load('bus'));
    }
}
