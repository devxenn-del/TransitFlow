<?php

namespace App\Http\Controllers\Api\Company;

use App\Actions\RecordFuel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreFuelRecordRequest;
use App\Http\Resources\FuelRecordResource;
use App\Models\FuelRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Fuel purchases for the current company — BITS `admin/fuel.php` /
 * conductor fuel logging (docs/MIGRATION_MAP.md §2.3). Auto-scoped by
 * CompanyScope; capability gated by `permission:fuel.*`.
 */
class FuelRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = FuelRecord::query()
            ->with('bus')
            ->when($request->filled('bus_id'), fn ($q) => $q->where('bus_id', $request->integer('bus_id')))
            ->when($request->filled('fuel_type'), fn ($q) => $q->where('fuel_type', $request->string('fuel_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('fueled_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('fueled_at', '<=', $request->date('to')))
            ->orderByDesc('fueled_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return FuelRecordResource::collection($records);
    }

    public function store(StoreFuelRecordRequest $request, RecordFuel $action): JsonResponse
    {
        $record = $action->handle($request->user(), $request->validated());

        return FuelRecordResource::make($record->load('bus'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(FuelRecord $fuelRecord): FuelRecordResource
    {
        return FuelRecordResource::make($fuelRecord->load('bus'));
    }

    public function destroy(Request $request, FuelRecord $fuelRecord): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('fuel.delete'), 403);

        $fuelRecord->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
