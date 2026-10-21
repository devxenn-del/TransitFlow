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
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Thermal Printer Management — the platform-wide printer inventory, across
 * every company. Super-Admin-only via `permission:printers.inventory.*`.
 * Every query lifts CompanyScope explicitly, so an open company workspace
 * (`X-Company-Id`) can never narrow or misdirect it. There is no delete:
 * a printer leaves service by being Retired, and its history stays.
 */
class ThermalPrinterController extends Controller
{
    /** The most labels one print job may produce. */
    public const MAX_LABELS = 500;

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([...$this->filterRules(), 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return ThermalPrinterResource::collection(
            $this->filtered($request->all())->orderByDesc('id')->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Inventory totals for the page's summary cards: the count per status,
     * and how many Assigned printers are on a bus vs. still waiting for one.
     */
    public function summary(): JsonResponse
    {
        $byStatus = ThermalPrinter::query()->withoutCompanyScope()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = collect(ThermalPrinterStatus::cases())
            ->mapWithKeys(fn (ThermalPrinterStatus $status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)]);

        $onBus = ThermalPrinter::query()->withoutCompanyScope()
            ->where('status', ThermalPrinterStatus::Assigned)
            ->whereNotNull('bus_id')
            ->count();

        return response()->json(['data' => [
            'total' => $statuses->sum(),
            'by_status' => $statuses,
            'on_bus' => $onBus,
            'awaiting_bus' => $statuses[ThermalPrinterStatus::Assigned->value] - $onBus,
        ]]);
    }

    /**
     * The printers to print asset labels for — either the given `ids`, or
     * (`all=1`) every printer matching the same filters as index(). Capped
     * at MAX_LABELS per print job.
     */
    public function labels(Request $request): AnonymousResourceCollection
    {
        $printers = $this->selection($request, 'labels can be printed');

        Audit::record('thermal_printer.labels_printed', null, [
            'count' => $printers->count(),
            'thermal_ids' => $printers->take(50)->pluck('thermal_id')->all(),
        ]);

        return ThermalPrinterResource::collection($printers);
    }

    /**
     * Issue every selected printer to one company. Printers already in that
     * company are skipped; if any other printer can't be issued (e.g. it is
     * Retired), nothing is saved and each blocked printer is reported.
     */
    public function bulkAssignCompany(Request $request, AssignThermalPrinterCompany $assign): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'date_issued' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $company = Company::query()->findOrFail($data['company_id']);
        $printers = $this->selection($request, 'printers can be updated', nested: true);

        return $this->applyToEach(
            $printers,
            fn (ThermalPrinter $printer) => $printer->company_id === $company->id,
            fn (ThermalPrinter $printer) => $assign->handle($printer, $company, $request->date('date_issued'), $data['remarks'] ?? null),
        );
    }

    /**
     * Move every selected printer to one status. Printers already in it are
     * skipped; if the change isn't allowed for any other printer, nothing is
     * saved and each blocked printer is reported.
     */
    public function bulkUpdateStatus(Request $request, ChangeThermalPrinterStatus $change): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ThermalPrinterStatus::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $target = ThermalPrinterStatus::from($data['status']);
        $printers = $this->selection($request, 'printers can be updated', nested: true);

        return $this->applyToEach(
            $printers,
            fn (ThermalPrinter $printer) => $printer->status === $target,
            fn (ThermalPrinter $printer) => $change->handle($printer, $target, $data['remarks'] ?? null),
        );
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
     * The printers a bulk request is about: either `ids`, or (`all=1`) every
     * printer matching the same filters as index() — given at the top level
     * (label printing) or under `filter` (`$nested`, for bulk updates). At
     * most MAX_LABELS.
     *
     * @return Collection<int, ThermalPrinter>
     */
    private function selection(Request $request, string $limitNoun, bool $nested = false): Collection
    {
        $request->validate([
            ...$this->filterRules($nested ? 'filter.' : ''),
            'filter' => [$nested ? 'nullable' : 'prohibited', 'array'],
            'all' => ['boolean'],
            'ids' => ['required_without:all', 'prohibited_if:all,1,true', 'array', 'min:1', 'max:'.self::MAX_LABELS],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $query = $request->boolean('all')
            ? $this->filtered($nested ? (array) $request->input('filter', []) : $request->all())
            : $this->printers()->whereKey($request->collect('ids')->map(fn ($id) => (int) $id)->all());

        $printers = $query->orderBy('thermal_id')->limit(self::MAX_LABELS + 1)->get();

        if ($printers->isEmpty()) {
            throw ValidationException::withMessages(['ids' => 'None of the selected printers were found.']);
        }

        if ($printers->count() > self::MAX_LABELS) {
            throw ValidationException::withMessages([
                'all' => 'Up to '.self::MAX_LABELS." {$limitNoun} at once. Narrow the filters and work in batches.",
            ]);
        }

        return $printers;
    }

    /**
     * Run `$apply` on each printer inside one transaction, skipping those
     * `$alreadyDone` says need no change. Every failure is collected; if
     * there are any, the whole batch is rolled back and they are returned
     * per printer (422), so a batch is either fully applied or not at all.
     *
     * @param  Collection<int, ThermalPrinter>  $printers
     */
    private function applyToEach(Collection $printers, Closure $alreadyDone, Closure $apply): JsonResponse
    {
        $failures = [];
        $updated = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($printers, $alreadyDone, $apply, &$failures, &$updated, &$skipped): void {
                foreach ($printers as $printer) {
                    if ($alreadyDone($printer)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $apply($printer);
                        $updated++;
                    } catch (ValidationException $e) {
                        $failures[] = [
                            'id' => $printer->id,
                            'device_id' => $printer->device_id,
                            'message' => collect($e->errors())->flatten()->first(),
                        ];
                    }
                }

                if ($failures) {
                    throw ValidationException::withMessages(['printers' => 'Nothing was saved: '.count($failures).' of the selected printers can\'t take this change.']);
                }
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'failures' => $failures,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['updated' => $updated, 'skipped' => $skipped]]);
    }

    /**
     * Validation for the inventory filters, optionally nested under a key
     * (bulk updates send them as `filter[...]`, because their own `status`
     * field is the new status, not a filter).
     *
     * @return array<string, list<mixed>>
     */
    private function filterRules(string $prefix = ''): array
    {
        return [
            "{$prefix}q" => ['nullable', 'string', 'max:100'],
            "{$prefix}status" => ['nullable', Rule::enum(ThermalPrinterStatus::class)],
            "{$prefix}company_id" => ['nullable', 'integer'],
            "{$prefix}bus_id" => ['nullable', 'integer'],
            "{$prefix}assignment" => ['nullable', Rule::in(['assigned', 'unassigned', 'on_bus', 'no_bus'])],
        ];
    }

    /**
     * The inventory filters shared by the list, label printing and bulk
     * updates, so "all matching" always means exactly what the list shows.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ThermalPrinter>
     */
    private function filtered(array $filters): Builder
    {
        $value = fn (string $key): ?string => filled($filters[$key] ?? null) ? trim((string) $filters[$key]) : null;
        $assignment = $value('assignment');

        return $this->printers()
            ->when($value('q'), fn (Builder $q, string $term) => $q->search($term))
            ->when($value('status'), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($value('company_id'), fn (Builder $q, string $id) => $q->where('company_id', (int) $id))
            ->when($value('bus_id'), fn (Builder $q, string $id) => $q->where('bus_id', (int) $id))
            ->when($assignment === 'assigned', fn (Builder $q) => $q->whereNotNull('company_id'))
            ->when($assignment === 'unassigned', fn (Builder $q) => $q->whereNull('company_id'))
            ->when($assignment === 'on_bus', fn (Builder $q) => $q->whereNotNull('bus_id'))
            ->when($assignment === 'no_bus', fn (Builder $q) => $q->where('status', ThermalPrinterStatus::Assigned)->whereNull('bus_id'));
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
