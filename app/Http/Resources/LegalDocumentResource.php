<?php

namespace App\Http\Resources;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LegalDocument
 */
class LegalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'title' => $this->title,
            'content' => $this->content,
            'effective_date' => $this->effective_date->toDateString(),
            'is_active' => $this->is_active,
            'published_by' => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'created_at' => $this->created_at,
        ];
    }
}
