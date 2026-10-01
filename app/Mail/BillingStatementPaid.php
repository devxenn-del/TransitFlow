<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Envelope;

/**
 * Payment confirmation — sent to a company's email when the Super Admin
 * marks its billing statement as paid. Same layout and lines as the payment
 * request, with amount paid and date paid instead of amount to pay and due date.
 */
class BillingStatementPaid extends BillingStatementIssued
{
    public bool $paid = true;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Payment Received — Billing No. %d — %s — PHP%s',
                $this->statement->billing_number,
                $this->statement->company->code,
                number_format((float) $this->statement->total, 2),
            ),
        );
    }
}
