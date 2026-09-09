<?php

namespace App\Http\Requests\Company;

use App\Models\Route;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Route::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->companyId();

        return [
            'origin' => ['required', 'string', 'max:150'],
            'destination' => ['required', 'string', 'max:150', 'different:origin'],
            'name' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(Route::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $companyId = app(CompanyContext::class)->companyId();
            $exists = Route::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('origin', $this->string('origin'))
                ->where('destination', $this->string('destination'))
                ->exists();

            if ($exists) {
                $v->errors()->add('destination', 'A route for this origin and destination already exists.');
            }
        });
    }

    /**
     * Default the display name to "ORIGIN - DESTINATION" when not given.
     */
    public function resolvedName(): string
    {
        return $this->filled('name')
            ? $this->string('name')->value()
            : $this->string('origin')->value().' - '.$this->string('destination')->value();
    }
}
