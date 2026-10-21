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
     * Regular: route + origin/destination (terminal ones when at_terminal)
     * + bus + driver. Special (charter / hire): an approved charter booking
     * (charter_booking_id) + the driver — the bus, pickup and destination come
     * from the booking; any route fields sent are ignored (see App\Actions\StartTrip).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $special = $this->input('trip_type') === 'Special';

        return [
            'bus_id' => $special ? ['nullable', 'integer'] : ['required', 'integer'],
            'charter_booking_id' => $special ? ['required', 'integer'] : ['prohibited'],
            // The route picked at Start Trip; optional for older app builds.
            'franchise_id' => ['sometimes', 'nullable', 'integer'],
            'driver_id' => ['required', 'integer'],
            'origin' => $special ? ['nullable', 'string', 'max:150'] : ['required', 'string', 'max:150'],
            'coverage_origin' => $special ? ['nullable', 'string', 'max:150'] : ['required', 'string', 'max:150'],
            'coverage_destination' => $special
                ? ['nullable', 'string', 'max:150']
                : ['required', 'string', 'max:150', 'different:coverage_origin'],
            'trip_type' => ['sometimes', 'string', 'in:Regular,Special'],
            'at_terminal' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'charter_booking_id.required' => 'Pick the approved charter booking this special trip is for.',
        ];
    }
}
