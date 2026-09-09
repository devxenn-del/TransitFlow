<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a company's admin the moment their company is provisioned —
 * gives them the sign-in URL and their temporary credentials.
 */
class CompanyAdminWelcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $loginUrl;

    public function __construct(
        public Company $company,
        public User $admin,
        public string $temporaryPassword,
    ) {
        $this->loginUrl = rtrim((string) config('app.url'), '/').'/login';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' company account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.company-admin-welcome');
    }
}
