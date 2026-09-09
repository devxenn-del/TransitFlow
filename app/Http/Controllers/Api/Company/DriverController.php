<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreDriverRequest;
use App\Http\Requests\Company\UpdateDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Company drivers — a login-free record chosen per trip. Auto-scoped by
 * CompanyScope; capability gated by `permission:drivers.*`.
 */
class DriverController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Driver::class);

        return DriverResource::collection(
            Driver::query()
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->string('q')->isNotEmpty(), fn ($q) => $q->where(
                    fn ($s) => $s->where('name', 'like', "%{$request->string('q')}%")
                        ->orWhere('employee_id', 'like', "%{$request->string('q')}%")
                        ->orWhere('license_number', 'like', "%{$request->string('q')}%")
                ))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $driver = Driver::query()->create($request->validated());

        return DriverResource::make($driver->refresh()) // pick up the generated employee_id
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Driver $driver): DriverResource
    {
        $this->authorize('view', $driver);

        return DriverResource::make($driver);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): DriverResource
    {
        $driver->update($request->validated());

        return DriverResource::make($driver->fresh());
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $this->authorize('delete', $driver);

        $driver->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
