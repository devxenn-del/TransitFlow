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
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(Driver::STATUSES)],
            // Left blank, this auto-generates (DR-####) — see Driver::booted().
            'driver_code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('drivers', 'driver_code')->where('company_id', $this->user()?->company_id),
            ],
        ];
    }
}
