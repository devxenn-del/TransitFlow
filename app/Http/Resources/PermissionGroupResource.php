<?php

namespace App\Http\Resources;

use App\Models\PermissionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PermissionGroup
 */
class PermissionGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($p) => [
                'id' => $p->id,
                'key' => $p->permission_key,
                'name' => $p->name,
                'description' => $p->description,
                'nav_label' => $p->nav_label,
                'nav_url' => $p->nav_url,
                'nav_icon' => $p->nav_icon,
            ])),
        ];
    }
}
