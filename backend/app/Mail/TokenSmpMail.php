<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P3-C: delivers the SMP email-token secret to the BOUND candidate address.
 *
 * The raw code exists only inside this message. It is never logged, never
 * stored raw, and never returned by any API. If the configured mailer is
 * `log`, the message body (including the code) is written to the application
 * log — that mailer is a development-only transport and must not be used in
 * production; the service treats any transport failure as an error rather
 * than faking delivery.
 */
class TokenSmpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nama,
        public readonly string $code,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode Token Pendaftaran SMPIT Al-Mubarak',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.token_smp',
            with: [
                'data' => [
                    'nama'   => $this->nama,
                    'token'  => $this->code,
                    'expiry' => $this->expiresAt->format('d M Y H:i'),
                ],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
