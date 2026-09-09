<?php

namespace App\Http\Requests\Company;

use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('driver')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(Driver::STATUSES)],
        ];
    }
}
