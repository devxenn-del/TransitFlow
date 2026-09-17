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
        $data = $request->validated();

        // Left blank on a driver that has never had one — generate it now,
        // same as a new driver would get on create, rather than leaving
        // this driver permanently unable to sign a conductor in.
        if (array_key_exists('driver_code', $data) && $data['driver_code'] === null && $driver->driver_code === null) {
            $data['driver_code'] = Driver::generateCode($driver->company_id, now());
        }

        $driver->update($data);

        return DriverResource::make($driver->fresh());
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $this->authorize('delete', $driver);

        $driver->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
