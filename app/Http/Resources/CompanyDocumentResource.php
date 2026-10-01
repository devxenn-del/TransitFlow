<?php

namespace App\Http\Resources;

use App\Models\CompanyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyDocument
 */
class CompanyDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'description' => $this->description,
            'is_important' => $this->is_important,
            'reference_number' => $this->reference_number,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'status' => $this->status(),
            'original_name' => $this->original_name,
            'extension' => strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION)),
            'file_kind' => $this->fileKind(),
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
