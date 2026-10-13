<?php

namespace App\Services;

use App\Mail\PaymentStatusMail;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * E.8 — unified payment notification dispatcher.
 *
 * SAFETY CONTRACT
 * ---------------
 * 1. Trigger sites are the AUTHORITY transitions only: the DB-transaction
 *    status updates inside PaymentController::verifyManual / rejectManual /
 *    manualPayment and DaftarUlangPaymentService::verify / reject. Every one
 *    of those already guards `status !== PENDING_MANUAL -> 409` inside a
 *    lockForUpdate transaction, so a transition can occur exactly once —
 *    idempotency of the EMAIL follows from idempotency of the TRANSITION.
 *    No webhooks exist in this app (manual-transfer flow), so there is no
 *    duplicate-event source to dedupe against; no schema change needed.
 * 2. A mail transport failure is swallowed here on purpose: the payment
 *    status is ALREADY authoritative and must not roll back because an
 *    email failed. It is `report()`ed for diagnosis. Nothing sensitive
 *    (token/credential) exists in these messages to leak into logs.
 * 3. Recipient and all amounts/references come from the server-side Payment
 *    row + Setting rows — never from request input.
 */
class PaymentNotificationService
{
    /**
     * Send the "proof received / instructions" email after a PENDING_MANUAL
     * row is created. Callers must be inside/after the successful DB commit.
     */
    public function notifyIssued(Payment $payment): void
    {
        $instructions = null;

        if ($payment->payment_category === Payment::CATEGORY_PPDB) {
            // Bank details are admin-configured Setting rows — the same
            // authoritative source as /payment/instructions. Never client input.
            $jenjang    = strtolower((string) $payment->jenjang);
            $instructions = [
                'bank_name' => (string) (Setting::where('key', 'bank_name')->value('value') ?? ''),
                'rekening'  => (string) (Setting::where('key', 'rekening_' . $jenjang)->value('value') ?? ''),
                'owner'     => (string) (Setting::where('key', 'bank_owner')->value('value') ?? ''),
            ];
        }

        $this->send($payment, 'issued', $instructions);
    }

    /** Send after the authoritative PENDING_MANUAL -> SUCCESS transition. */
    public function notifyVerified(Payment $payment): void
    {
        $this->send($payment, 'verified');
    }

    /** Send after the authoritative PENDING_MANUAL -> REJECTED transition. */
    public function notifyRejected(Payment $payment): void
    {
        $this->send($payment, 'rejected');
    }

    private function send(Payment $payment, string $kind, ?array $instructions = null): void
    {
        try {
            $to = $payment->user?->email;

            // No recipient resolved (should not happen: payment.user is
            // required) — report, never fake success.
            if (empty($to)) {
                report(new \RuntimeException(
                    "PaymentStatusMail not sent: payment {$payment->id} has no user email."
                ));

                return;
            }

            Mail::to($to)->send(new PaymentStatusMail($payment, $kind, $instructions));
        } catch (\Throwable $e) {
            // Status transition already committed — do NOT rethrow into the
            // admin verification response. Diagnose via report() instead.
            report($e);
        }
    }
}
