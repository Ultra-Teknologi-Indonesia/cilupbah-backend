<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $otp,
        public string $userName,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode Reset Kata Sandi '.config('app.name', 'Cilupbah'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-otp',
            with: [
                'otp' => $this->otp,
                'userName' => $this->userName,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
