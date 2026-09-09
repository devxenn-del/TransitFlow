<?php

namespace App\Http\Resources;

use App\Models\SystemSettingHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SystemSettingHistory
 */
class SystemSettingHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'setting_key' => $this->setting_key,
            'previous_value' => $this->previous_value,
            'new_value' => $this->new_value,
            'status' => $this->status,
            'reason' => $this->reason,
            'changed_by' => $this->changed_by_name,
            'created_at' => $this->created_at,
            'can_rollback' => in_array($this->status, ['validated', 'rolled_back'], true),
        ];
    }
}
