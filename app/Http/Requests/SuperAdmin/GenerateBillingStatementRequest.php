<?php

namespace App\Http\Requests\SuperAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class GenerateBillingStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('fees.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
        ];
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->validated('period'));
    }
}
