<?php

namespace App\Http\Requests\Company;

use App\Models\CompanyDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyDocumentRequest extends FormRequest
{
    /**
     * Accepted upload extensions — every kind in CompanyDocument::FILE_KINDS.
     *
     * @return list<string>
     */
    public static function fileTypes(): array
    {
        return collect(CompanyDocument::FILE_KINDS)->flatten()->all();
    }

    /** Max upload size, in kilobytes (25 MB). */
    public const MAX_FILE_KB = 25600;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CompanyDocument::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(CompanyDocument::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_important' => ['sometimes', 'boolean'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file' => ['required', 'file', 'extensions:'.implode(',', self::fileTypes()), 'max:'.self::MAX_FILE_KB],
        ];
    }
}
