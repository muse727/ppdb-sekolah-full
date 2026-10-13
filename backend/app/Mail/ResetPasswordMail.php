<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E.10 — password reset email on the Al-Mubarak base layout (E.8/E.9).
 * Carries ONLY the reset URL and recipient name — never the password,
 * never any other credential. The URL embeds the broker's single-use
 * 60-minute token; a newer request invalidates this one.
 */
class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nama,
        public readonly string $url,
        public readonly \Carbon\CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Password SPMB Al-Mubarak',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password',
            with: [
                'data' => [
                    'nama'    => $this->nama,
                    'url'     => $this->url,
                    'expiry'  => $this->expiresAt->copy()->timezone('Asia/Jakarta')->format('d M Y, H:i'),
                ],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
