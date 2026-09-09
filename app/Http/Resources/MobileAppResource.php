<?php

namespace App\Http\Resources;

use App\Models\MobileAppSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin MobileAppSetting
 */
class MobileAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'api_base_url' => $this->api_base_url,
            'latest_version' => $this->latest_version,
            'latest_version_code' => (int) $this->latest_version_code,
            'minimum_version' => $this->minimum_version,
            'force_update' => (bool) $this->force_update,
            'download_url' => $this->download_url,
            'apk_path' => $this->apk_path,
            'apk_url' => $this->apk_path ? Storage::disk('public')->url($this->apk_path) : null,
            'release_notes' => $this->release_notes,
            'published_at' => $this->published_at,
        ];
    }
}
