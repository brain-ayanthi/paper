<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Confirm your new email address" — the second half of the two-step email
 * change (SEC #10). Sent to the NEW address only: opening the link is the
 * proof that whoever asked for the change can actually receive mail there.
 *
 * Sent synchronously, like every other mail in this product — shared hosting
 * has no queue worker running by default.
 */
class ConfirmEmailChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('account.email_change.mail.subject', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.confirm-email-change',
            with: [
                'user'      => $this->user,
                'newEmail'  => $this->user->pending_email,
                'appName'   => config('app.name'),
                'confirmUrl' => route('admin.profile.email.confirm', [
                    'user'  => $this->user->getKey(),
                    'token' => $this->token,
                ]),
                'hours' => \App\Actions\Users\StartEmailChange::EXPIRES_HOURS,
            ],
        );
    }
}
