<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\BillingStatementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateBillingStatementStatusRequest extends FormRequest
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
        return [
            'status' => ['required', new Enum(BillingStatementStatus::class)],
            // What the company actually paid, when marking paid; defaults to the bill total.
            'amount_received' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'prohibited_unless:status,'.BillingStatementStatus::Paid->value],
        ];
    }

    public function amountReceived(): ?string
    {
        $amount = $this->validated('amount_received');

        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }

    public function status(): BillingStatementStatus
    {
        return BillingStatementStatus::from($this->validated('status'));
    }
}
