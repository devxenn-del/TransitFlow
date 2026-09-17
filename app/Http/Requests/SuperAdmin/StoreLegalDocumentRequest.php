<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\LegalDocument;
use Illuminate\Foundation\Http\FormRequest;

class StoreLegalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('legal.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.LegalDocument::TYPE_PRIVACY_POLICY.','.LegalDocument::TYPE_TERMS_OF_USE],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'effective_date' => ['required', 'date'],
        ];
    }
}
