<?php

namespace App\Http\Requests\Company;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            // Must be one of THIS company's own roles (or a shared template).
            'role_id' => ['required', Rule::exists('roles', 'id')->where(function ($q) {
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
            // The driver this conductor must supply a Driver Code for at
            // login (see AuthController::login()). Left blank here is
            // allowed — the account just can't sign in until one is set.
            'driver_id' => ['nullable', Rule::exists('drivers', 'id')->where(
                fn ($q) => $q->where('company_id', $this->user()?->company_id)
            )],
        ];
    }
}
