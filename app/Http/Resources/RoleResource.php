<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_platform' => $this->is_platform,
            'is_admin' => $this->is_admin,
            'sort_order' => $this->sort_order,
            'user_count' => $this->whenCounted('users'),
            'default_permissions' => $this->when(
                $this->relationLoaded('permissions'),
                fn () => $this->permissions
                    ->filter(fn ($p) => (bool) $p->pivot->allowed)
                    ->pluck('permission_key')
                    ->values(),
            ),
        ];
    }
}
