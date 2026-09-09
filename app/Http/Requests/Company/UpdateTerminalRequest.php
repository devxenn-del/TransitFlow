<?php

namespace App\Http\Requests\Company;

use App\Models\Terminal;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('terminal')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $id = $this->route('terminal')->id;

        return [
            'name' => [
                'sometimes', 'string', 'max:150',
                Rule::unique('terminals', 'name')->where('company_id', $companyId)->ignore($id),
            ],
            'default_route_origin' => ['nullable', 'string', 'max:150'],
            'boarding_mode' => ['sometimes', Rule::in(Terminal::BOARDING_MODES)],
            'status' => ['sometimes', Rule::in(Terminal::STATUSES)],
        ];
    }
}
