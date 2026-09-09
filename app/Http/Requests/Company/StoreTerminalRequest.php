<?php

namespace App\Http\Requests\Company;

use App\Models\Terminal;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Terminal::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('terminals', 'name')->where('company_id', $companyId),
            ],
            'default_route_origin' => ['nullable', 'string', 'max:150'],
            'boarding_mode' => ['sometimes', Rule::in(Terminal::BOARDING_MODES)],
            'status' => ['sometimes', Rule::in(Terminal::STATUSES)],
        ];
    }
}
