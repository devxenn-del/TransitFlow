<?php

namespace App\Http\Requests\Company;

use App\Models\AdminBusAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminBusAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('adminassignments.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'shift' => ['sometimes', Rule::in(AdminBusAssignment::SHIFTS)],
            'status' => ['sometimes', Rule::in(AdminBusAssignment::STATUSES)],
        ];
    }
}
