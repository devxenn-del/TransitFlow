<?php

namespace App\Http\Controllers\Api\Conductor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conductor\RegisterDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * Device registration + heartbeat from the mobile app — BITS
 * `api/devices/register.php` (docs/MIGRATION_MAP.md §K). Idempotent: the app
 * calls this on launch and periodically; each call upserts the row for
 * `(company, device_uuid)` and refreshes `last_seen_at`.
 */
class DeviceController extends Controller
{
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();

        $device = Device::query()->updateOrCreate(
            [
                'company_id' => $user->company_id,
                'device_uuid' => $request->string('device_uuid')->trim()->value(),
            ],
            array_filter([
                'user_id' => $user->id,
                'platform' => $request->input('platform', 'android'),
                'model' => $request->input('model'),
                'app_version' => $request->input('app_version'),
                'push_token' => $request->input('push_token'),
                'last_seen_at' => now(),
            ], fn ($value) => $value !== null),
        );

        if ($device->wasRecentlyCreated && $device->registered_at === null) {
            $device->forceFill(['registered_at' => now()])->save();
        }

        return response()->json([
            'data' => [
                'id' => $device->id,
                'device_uuid' => $device->device_uuid,
                'registered_at' => $device->registered_at?->toDateTimeString(),
                'last_seen_at' => $device->last_seen_at?->toDateTimeString(),
            ],
        ], $device->wasRecentlyCreated ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
