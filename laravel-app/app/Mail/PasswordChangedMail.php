<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Business Management Suite password was changed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-changed', with: [
            'name'      => $this->user->name,
            'email'     => $this->user->email,
            'workspace' => $this->user->tenant?->name,
            'when'      => now()->format('d M Y, H:i') . ' UTC',
        ]);
    }
}
