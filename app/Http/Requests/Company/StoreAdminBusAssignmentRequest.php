<?php

namespace App\Http\Requests\Company;

use App\Models\AdminBusAssignment;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminBusAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('adminassignments.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'bus_id' => [
                'required',
                Rule::exists('buses', 'id')->where('company_id', $companyId)->where('status', 'Active'),
            ],
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', $companyId)->where('status', 'active'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $target = User::query()->with('accessRole')->find($value);
                    if ($target?->accessRole?->key === 'conductor') {
                        $fail('A bus assignment goes to an office/management account, not a conductor.');
                    }
                },
            ],
            'shift' => ['required', Rule::in(AdminBusAssignment::SHIFTS)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
