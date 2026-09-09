<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => [
                'line' => $this->address_line,
                'barangay' => $this->address_barangay,
                'city' => $this->address_city,
                'province' => $this->address_province,
            ],
            'logo_path' => $this->logo_path,
            'status' => $this->status->value,
            'can_create_accounts' => $this->can_create_accounts,
            'users_count' => $this->whenCounted('users'),
            'settings' => CompanySettingResource::make($this->whenLoaded('settings')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
