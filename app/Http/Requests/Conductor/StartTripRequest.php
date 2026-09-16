<?php

namespace App\Http\Requests\Conductor;

use Illuminate\Foundation\Http\FormRequest;

class StartTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('trips.start') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bus_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'origin' => ['required', 'string', 'max:150'],
            'coverage_origin' => ['required', 'string', 'max:150'],
            'coverage_destination' => ['required', 'string', 'max:150', 'different:coverage_origin'],
            'trip_type' => ['sometimes', 'string', 'in:Regular,Special'],
            'at_terminal' => ['sometimes', 'boolean'],
        ];
    }
}
