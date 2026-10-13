<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E.8 — payment notification email (manual-transfer flow).
 *
 * One Mailable covers the two authoritative terminal transitions of the
 * manual payment lifecycle, plus the "awaiting verification" notice:
 *   - issued    : proof uploaded, payment now PENDING_MANUAL
 *   - verified  : admin verified  -> SUCCESS
 *   - rejected  : admin rejected  -> REJECTED (includes reason)
 *
 * All data comes from the Payment model / Setting rows read SERVER-side at
 * dispatch time (never from client input). Mail sending is synchronous
 * (no queue), matching the existing architecture. Delivery failures are
 * reported by the caller — the mail transport must never fake success.
 */
class PaymentStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly string $kind, // 'issued' | 'verified' | 'rejected'
        public readonly ?array $instructions = null, // bank/rekening for 'issued' only
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->kind) {
                'verified' => 'Pembayaran SPMB Telah Diverifikasi',
                'rejected' => 'Pembayaran SPMB Ditolak — Tindakan Diperlukan',
                default    => 'Instruksi Pembayaran SPMB Al-Mubarak',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_status',
            with: [
                'data' => [
                    'kind'         => $this->kind,
                    'nama'         => $this->payment->user?->name ?? '',
                    'invoice'      => $this->payment->invoice_number,
                    'category'     => $this->payment->payment_category === Payment::CATEGORY_PPDB
                                        ? 'Biaya Pendaftaran (PPDB)' : 'Daftar Ulang',
                    'jenjang'      => $this->payment->jenjang,
                    'amount'       => $this->payment->amount,
                    'reason'       => $this->kind === 'rejected'
                                        ? ($this->payment->rejection_reason ?: 'Tidak disertakan alasan.')
                                        : null,
                    'instructions' => $this->instructions,
                ],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
