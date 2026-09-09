<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_uuid' => $this->device_uuid,
            'platform' => $this->platform,
            'model' => $this->model,
            'app_version' => $this->app_version,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ]),
            'registered_at' => $this->registered_at,
            'last_seen_at' => $this->last_seen_at,
            'online' => $this->isOnline(),
        ];
    }
}
