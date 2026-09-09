<?php

namespace App\Http\Requests\Company;

use App\Models\Route;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('route')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin' => ['sometimes', 'string', 'max:150'],
            'destination' => ['sometimes', 'string', 'max:150'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(Route::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $route = $this->route('route');
            $origin = $this->input('origin', $route->origin);
            $destination = $this->input('destination', $route->destination);

            if ($origin === $destination) {
                $v->errors()->add('destination', 'Origin and destination must differ.');

                return;
            }

            $clash = Route::withoutGlobalScopes()
                ->where('company_id', app(CompanyContext::class)->companyId())
                ->where('origin', $origin)
                ->where('destination', $destination)
                ->whereKeyNot($route->id)
                ->exists();

            if ($clash) {
                $v->errors()->add('destination', 'Another route already covers this origin and destination.');
            }
        });
    }
}
