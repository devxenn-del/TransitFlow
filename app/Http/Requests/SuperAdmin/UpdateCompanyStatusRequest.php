<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(CompanyStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function status(): CompanyStatus
    {
        return CompanyStatus::from($this->validated('status'));
    }
}
