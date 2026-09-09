<?php

namespace App\Http\Requests\Company;

use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Driver::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(Driver::STATUSES)],
        ];
    }
}
