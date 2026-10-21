<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Actions\AssignThermalPrinterBus;
use App\Actions\AssignThermalPrinterCompany;
use App\Actions\ChangeThermalPrinterStatus;
use App\Actions\RegisterThermalPrinter;
use App\Enums\ThermalPrinterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AssignThermalPrinterBusRequest;
use App\Http\Requests\SuperAdmin\AssignThermalPrinterCompanyRequest;
use App\Http\Requests\SuperAdmin\StoreThermalPrinterRequest;
use App\Http\Requests\SuperAdmin\UpdateThermalPrinterRequest;
use App\Http\Requests\SuperAdmin\UpdateThermalPrinterStatusRequest;
use App\Http\Resources\ThermalPrinterResource;
use App\Models\Bus;
use App\Models\Company;
use App\Models\ThermalPrinter;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Thermal Printer Management — the platform-wide printer inventory, across
 * every company. Super-Admin-only via `permission:printers.inventory.*`.
 * Every query lifts CompanyScope explicitly, so an open company workspace
 * (`X-Company-Id`) can never narrow or misdirect it. There is no delete:
 * a printer leaves service by being Retired, and its history stays.
 */
class ThermalPrinterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ThermalPrinterStatus::class)],
            'company_id' => ['nullable', 'integer'],
            'bus_id' => ['nullable', 'integer'],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned', 'no_bus'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $printers = $this->printers()
            ->when($request->filled('q'), fn (Builder $q) => $q->search($request->string('q')->trim()->value()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('bus_id'), fn (Builder $q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->input('assignment') === 'assigned', fn (Builder $q) => $q->whereNotNull('company_id'))
            ->when($request->input('assignment') === 'unassigned', fn (Builder $q) => $q->whereNull('company_id'))
            ->when($request->input('assignment') === 'no_bus', fn (Builder $q) => $q->whereNotNull('company_id')->whereNull('bus_id'))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ThermalPrinterResource::collection($printers);
    }

    public function store(StoreThermalPrinterRequest $request, RegisterThermalPrinter $register): JsonResponse
    {
        $printer = $register->handle($request->validated());

        return ThermalPrinterResource::make($this->reload($printer))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(ThermalPrinter $inventoryPrinter): ThermalPrinterResource
    {
        return ThermalPrinterResource::make($this->reload($inventoryPrinter, withHistory: true));
    }

    public function update(UpdateThermalPrinterRequest $request, ThermalPrinter $inventoryPrinter): ThermalPrinterResource
    {
        $inventoryPrinter->fill($request->safe()->except('date_issued'));
        if ($request->has('date_issued')) {
            $inventoryPrinter->date_issued = $request->validated('date_issued');
        }
        $inventoryPrinter->save();

        if ($changes = Audit::changes($inventoryPrinter)) {
            Audit::record('thermal_printer.updated', $inventoryPrinter, $changes, company: $inventoryPrinter->company_id ? Company::query()->find($inventoryPrinter->company_id) : null);
        }

        return ThermalPrinterResource::make($this->reload($inventoryPrinter));
    }

    public function assignCompany(AssignThermalPrinterCompanyRequest $request, ThermalPrinter $inventoryPrinter, AssignThermalPrinterCompany $assign): ThermalPrinterResource
    {
        $printer = $assign->handle(
            $inventoryPrinter,
            Company::query()->findOrFail($request->integer('company_id')),
            $request->date('date_issued'),
            $request->validated('remarks'),
        );

        return ThermalPrinterResource::make($this->reload($printer));
    }

    public function assignBus(AssignThermalPrinterBusRequest $request, ThermalPrinter $inventoryPrinter, AssignThermalPrinterBus $assign): ThermalPrinterResource
    {
        $printer = $assign->handle(
            $inventoryPrinter,
            $request->filled('bus_id') ? $request->integer('bus_id') : null,
            $request->validated('remarks'),
        );

        return ThermalPrinterResource::make($this->reload($printer));
    }

    public function updateStatus(UpdateThermalPrinterStatusRequest $request, ThermalPrinter $inventoryPrinter, ChangeThermalPrinterStatus $change): ThermalPrinterResource
    {
        $printer = $change->handle($inventoryPrinter, $request->status(), $request->validated('remarks'));

        return ThermalPrinterResource::make($this->reload($printer));
    }

    /**
     * A company's buses, for the bus filter and the Assign Bus picker.
     * `assignable` marks the ones a printer can be put on (Active).
     */
    public function buses(Request $request): JsonResponse
    {
        $request->validate(['company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')]]);

        $buses = Bus::query()->withoutCompanyScope()
            ->where('company_id', $request->integer('company_id'))
            ->orderBy('bus_number')
            ->get(['id', 'bus_number', 'plate_number', 'status'])
            ->map(fn (Bus $bus) => [
                'id' => $bus->id,
                'bus_number' => $bus->bus_number,
                'plate_number' => $bus->plate_number,
                'status' => $bus->status,
                'assignable' => $bus->status === 'Active',
            ]);

        return response()->json(['data' => $buses]);
    }

    /**
     * @return Builder<ThermalPrinter>
     */
    private function printers(): Builder
    {
        return ThermalPrinter::query()->withoutCompanyScope()->with([
            'company:id,name,code',
            'bus' => fn ($q) => $q->withoutCompanyScope(),
        ]);
    }

    private function reload(ThermalPrinter $printer, bool $withHistory = false): ThermalPrinter
    {
        return $this->printers()
            ->when($withHistory, fn (Builder $q) => $q->with(['assignments' => fn ($a) => $a->with('fromCompany:id,name', 'toCompany:id,name')->latest('id')]))
            ->findOrFail($printer->id);
    }
}
