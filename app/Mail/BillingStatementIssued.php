<?php

namespace App\Mail;

use App\Models\BillingStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Payment request — sent to a company's email when a billing statement is
 * generated for it (status UNPAID): company code, billing period, billing
 * number, amount to pay and due date, plus the lines. Only what the company
 * may see — no standard amounts or internal notes.
 */
class BillingStatementIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $billingUrl;

    public bool $paid = false;

    public function __construct(public BillingStatement $statement)
    {
        $this->statement->loadMissing(['company', 'items']);
        $this->billingUrl = rtrim((string) config('app.url'), '/').'/company/billing';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Billing No. %d — %s — Amount to Pay PHP%s',
                $this->statement->billing_number,
                $this->statement->company->code,
                number_format((float) $this->statement->total, 2),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing-statement');
    }
}
