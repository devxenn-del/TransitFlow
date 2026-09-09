<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Device monitoring for the current company — BITS
 * `admin/deviceMonitoring.php` (docs/MIGRATION_MAP.md §K). Read + deregister
 * only; devices register themselves through the conductor endpoint. Company-
 * scoped by `Device`'s global `CompanyScope`; gated by `permission:devices.*`.
 */
class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::query()
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->string('platform')))
            ->when($request->boolean('stale'), fn ($q) => $q->stale())
            ->orderByRaw('last_seen_at is null')
            ->orderByDesc('last_seen_at')
            ->paginate($request->integer('per_page', 20));

        return DeviceResource::collection($devices);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
