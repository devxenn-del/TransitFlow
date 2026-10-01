<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Actions\GenerateBillingStatement;
use App\Actions\UpdateBillingStatementStatus;
use App\Enums\BillingStatementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\GenerateBillingStatementRequest;
use App\Http\Requests\SuperAdmin\UpdateBillingStatementStatusRequest;
use App\Http\Resources\BillingStatementResource;
use App\Mail\BillingStatementIssued;
use App\Mail\BillingStatementPaid;
use App\Models\BillingStatement;
use App\Models\Company;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Generating a company's billing statement on demand (the monthly
 * `billing:generate` run does the same for every company) and recording its
 * payment state. Super Admin only. Listing / viewing statements is the
 * shared company endpoint (`company/billing/statements`).
 */
class CompanyBillingStatementController extends Controller
{
    public function store(GenerateBillingStatementRequest $request, Company $company, GenerateBillingStatement $generator): JsonResponse
    {
        $statement = $generator->handle($company, $request->period(), $request->user());

        return BillingStatementResource::make($statement->load(['items', 'company:id,code', 'carriedTo:id,billing_number']))
            ->additional(['email' => $this->emailCompany($company, $statement)])
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Re-prices an unpaid statement with the current fees and special rates.
     * Not emailed automatically — the Super Admin reviews it, then uses
     * "Send email" to send the corrected bill.
     */
    public function recalculate(Request $request, Company $company, BillingStatement $billingStatement, GenerateBillingStatement $generator): BillingStatementResource
    {
        $this->authorize('fees.manage');

        $statement = $generator->recalculate($billingStatement, $request->user());

        return BillingStatementResource::make($statement->load(['items', 'company:id,code', 'carriedTo:id,billing_number']));
    }

    /**
     * (Re)sends the email matching the statement's status — the payment
     * request while unpaid, the payment confirmation once paid — e.g. after
     * the company's email address was missing or a send failed.
     */
    public function email(Company $company, BillingStatement $billingStatement): JsonResponse
    {
        $this->authorize('fees.manage');

        return response()->json(['email' => $this->emailCompany($company, $billingStatement)]);
    }

    /**
     * Emails the statement to the company's email address: the payment
     * request (unpaid) or the payment confirmation (paid); a void statement
     * is never emailed. Best-effort: a missing address or a mail-transport
     * problem never undoes the action — the result just says so, for the
     * Super Admin to follow up.
     *
     * @return array{sent: bool, to: ?string, error: ?string}
     */
    private function emailCompany(Company $company, BillingStatement $statement): array
    {
        $mail = match ($statement->status) {
            BillingStatementStatus::Unpaid => new BillingStatementIssued($statement),
            BillingStatementStatus::Paid => new BillingStatementPaid($statement),
            BillingStatementStatus::Void => null,
        };

        if ($mail === null) {
            return ['sent' => false, 'to' => null, 'error' => 'A void statement is not emailed.'];
        }

        if (blank($company->email)) {
            return ['sent' => false, 'to' => null, 'error' => 'The company has no email address.'];
        }

        try {
            Mail::to($company->email)->send($mail);
        } catch (Throwable $e) {
            Log::warning('Billing statement email failed', [
                'company_id' => $company->id,
                'billing_statement_id' => $statement->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'to' => $company->email, 'error' => 'The email could not be sent.'];
        }

        Audit::record('billing.statement.emailed', $statement, [
            'billing_number' => $statement->billing_number,
            'email' => $statement->status === BillingStatementStatus::Paid ? 'payment_confirmation' : 'payment_request',
            'to' => $company->email,
        ], company: $company);

        return ['sent' => true, 'to' => $company->email, 'error' => null];
    }

    /**
     * Marks a statement paid (recording the amount received — any excess or
     * shortage goes to the next bill), unpaid, or void.
     */
    public function updateStatus(UpdateBillingStatementStatusRequest $request, Company $company, BillingStatement $billingStatement, UpdateBillingStatementStatus $updater): BillingStatementResource
    {
        $to = $request->status();
        $changed = $updater->handle($billingStatement, $to, $request->amountReceived(), $request->user());

        // Confirm the payment to the company — only on the change to paid, so
        // a repeated "mark paid" never sends a second confirmation.
        $email = $changed && $to === BillingStatementStatus::Paid
            ? $this->emailCompany($company, $billingStatement)
            : null;

        return BillingStatementResource::make($billingStatement->load(['items', 'company:id,code', 'carriedTo:id,billing_number']))
            ->additional(['email' => $email]);
    }
}
