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

    protected function prepareForValidation(): void
    {
        if ($this->has('driver_code')) {
            $this->merge(['driver_code' => Driver::normalizeCode($this->input('driver_code'))]);
        }
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
            'sex' => ['sometimes', 'nullable', Rule::in(['Male', 'Female'])],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Driver::STATUSES)],
            'driver_code' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('drivers', 'driver_code')
                    ->where('company_id', $this->user()?->company_id)
                    ->ignore($this->route('driver')),
            ],
        ];
    }
}
