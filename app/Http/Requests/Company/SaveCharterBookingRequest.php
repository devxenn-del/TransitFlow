<?php

namespace App\Http\Requests\Company;

use App\Models\Role;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / edit a charter booking (App\Http\Controllers\Api\Company\CharterBookingController).
 * Route-gated by charters.create / charters.edit; the bus, driver and
 * conductor must be this company's, Active, and (checked in the controller,
 * App\Support\CharterConflicts) free for the window.
 */
class SaveCharterBookingRequest extends FormRequest
{
    /** The route's permission middleware (charters.create / charters.edit) is the gate; CompanyScope keeps it to one company. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'client_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['required', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'pickup_point' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'departure_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after:departure_at'],
            'passenger_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'agreed_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'bus_id' => ['required', 'integer', Rule::exists('buses', 'id')->where('company_id', $companyId)->where('status', 'Active')],
            'driver_id' => ['required', 'integer', Rule::exists('drivers', 'id')->where('company_id', $companyId)->where('status', 'Active')],
            'conductor_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereIn('role_id', Role::query()->forCompany($companyId)->where('key', 'conductor')->select('id'))),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bus_id.exists' => 'Pick an active bus.',
            'driver_id.exists' => 'Pick an active driver.',
            'conductor_id.exists' => 'Pick a conductor account.',
            'return_at.after' => 'The return must be after the departure.',
        ];
    }
}
