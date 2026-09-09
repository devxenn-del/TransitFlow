<?php

namespace App\Http\Requests\Company;

use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Assign (or clear, when `user_id` is omitted) the conductor holding one printer. */
class AssignThermalPrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('thermalprinters.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $companyId)],
        ];
    }
}
