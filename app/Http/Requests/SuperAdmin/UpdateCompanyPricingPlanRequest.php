<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\PricingPlan;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * A company's pricing configuration and billing settings — any subset of:
 * pricing plan, billing cycle day, next billing number.
 */
class UpdateCompanyPricingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('fees.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        $lastBillingNumber = (int) $company->billingStatements()->withoutCompanyScope()->max('billing_number');

        return [
            'pricing_plan' => ['sometimes', new Enum(PricingPlan::class)],
            'billing_cycle_day' => ['sometimes', 'integer', 'min:1', 'max:'.Company::MAX_BILLING_CYCLE_DAY],
            'next_billing_number' => ['sometimes', 'integer', 'min:'.($lastBillingNumber + 1), 'max:4000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'next_billing_number.min' => 'The next billing number must be higher than the last one issued (:min or more).',
        ];
    }
}
