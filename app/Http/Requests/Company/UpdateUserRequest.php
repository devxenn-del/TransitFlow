<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role_id' => ['sometimes', Rule::exists('roles', 'id')->where(function ($q) {
                $q->where('is_platform', 0)
                    ->where(fn ($w) => $w->where('company_id', $this->user()?->company_id)->orWhereNull('company_id'));
            })],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', Rule::in(['Male', 'Female'])],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'driver_id' => ['sometimes', 'nullable', Rule::exists('drivers', 'id')->where(
                fn ($q) => $q->where('company_id', $this->user()?->company_id)
            )],
        ];
    }
}
