<?php

namespace App\Http\Requests\Conductor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A conductor's device reporting its GPS position during a live trip — BITS
 * `api/trips/updateLocation.php` (docs/MIGRATION_MAP.md §H). The trip is
 * resolved server-side from the authenticated conductor; the request only
 * carries which bus the fix is for (asserted against the live trip) and the
 * coordinates.
 */
class RecordBusLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('tracking.ping') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bus_id' => ['required', 'integer'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed_kph' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'heading' => ['nullable', 'integer', 'between:0,359'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
