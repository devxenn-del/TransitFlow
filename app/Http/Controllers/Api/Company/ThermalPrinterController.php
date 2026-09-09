<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AssignThermalPrinterRequest;
use App\Http\Requests\Company\StoreThermalPrinterRequest;
use App\Http\Requests\Company\UpdateThermalPrinterRequest;
use App\Http\Resources\ThermalPrinterResource;
use App\Models\ThermalPrinter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * A company's thermal printer inventory — BITS `admin/thermalprinters.php`
 * (docs/MIGRATION_MAP.md §2.2). Every query runs through CompanyScope, so
 * this controller never filters by `company_id` itself.
 */
class ThermalPrinterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ThermalPrinter::class);

        return ThermalPrinterResource::collection(
            ThermalPrinter::query()->with('holder')->orderBy('device_id')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreThermalPrinterRequest $request): JsonResponse
    {
        // company_id is filled in by the BelongsToCompany trait.
        $printer = ThermalPrinter::query()->create($request->validated())->fresh();

        return ThermalPrinterResource::make($printer)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(ThermalPrinter $printer): ThermalPrinterResource
    {
        $this->authorize('view', $printer);

        return ThermalPrinterResource::make($printer->load('holder'));
    }

    public function update(UpdateThermalPrinterRequest $request, ThermalPrinter $printer): ThermalPrinterResource
    {
        $printer->update($request->validated());

        return ThermalPrinterResource::make($printer->fresh()->load('holder'));
    }

    public function destroy(ThermalPrinter $printer): JsonResponse
    {
        $this->authorize('delete', $printer);

        $printer->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Assign (or, with no `user_id`, clear) the conductor holding this
     * printer. `users.thermal_printer_id` is unique, so handing it to a new
     * conductor first frees whoever currently holds it.
     */
    public function assign(AssignThermalPrinterRequest $request, ThermalPrinter $printer): ThermalPrinterResource
    {
        DB::transaction(function () use ($request, $printer) {
            User::query()->where('thermal_printer_id', $printer->id)->update(['thermal_printer_id' => null]);

            if ($request->filled('user_id')) {
                User::query()->whereKey($request->integer('user_id'))->update(['thermal_printer_id' => $printer->id]);
            }
        });

        return ThermalPrinterResource::make($printer->fresh()->load('holder'));
    }
}
