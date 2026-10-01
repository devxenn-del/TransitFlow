<?php

namespace App\Http\Requests\Company;

use App\Models\CompanyDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a document's details and, optionally, replace its file. Sent as
 * multipart (POST) so a replacement file can ride along.
 */
class UpdateCompanyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'category' => ['sometimes', Rule::in(array_keys(CompanyDocument::CATEGORIES))],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_important' => ['sometimes', 'boolean'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_at'],
            'file' => [
                'sometimes', 'file',
                'extensions:'.implode(',', StoreCompanyDocumentRequest::fileTypes()),
                'max:'.StoreCompanyDocumentRequest::MAX_FILE_KB,
            ],
        ];
    }
}
