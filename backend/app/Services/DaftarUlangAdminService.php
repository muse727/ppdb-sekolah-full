<?php

namespace App\Services;

use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only admin Daftar Ulang participant reporting (B.6B / B.6A-C).
 *
 * This service is ADDITIVE to B.6A settlement. It performs NO mutation of any
 * kind — it only reads existing DU domain records to back the admin UI.
 *
 * Participant definition: a student is a Daftar Ulang participant precisely
 * when an authoritative DU domain record exists — a `du_invoices` row. We never
 * treat "users.status_kelulusan = LULUS" alone as participation; the invoice is
 * the canonical entry point into the flow (one canonical invoice per student).
 *
 * Immutability: every invoice amount/snapshot shown comes from
 * du_invoices.pricing_snapshot and the stored financial columns. Current pricing
 * configuration is NEVER consulted, so historical invoices are stable.
 *
 * Category isolation: the linked payment is always re-scoped to DAFTAR_ULANG.
 * A malformed relation pointing at PPDB resolves to null (fail-safe) instead of
 * silently mixing categories.
 */
class DaftarUlangAdminService
{
    /** Base query: DU participants (have a du_invoices row), with identity. */
    private function participants(array $filters = []): Builder
    {
        // Latest biodata per user, matching AdminReportService's jalur convention.
        $latestBiodata = DB::table('biodatas')
            ->selectRaw('user_id, MAX(id) as biodata_id')
            ->groupBy('user_id');

        return DaftarUlangInvoice::query()
            ->join('users', 'users.id', '=', 'du_invoices.user_id')
            ->leftJoinSub($latestBiodata, 'bio_latest', 'bio_latest.user_id', '=', 'du_invoices.user_id')
            ->leftJoin('biodatas as b', 'b.id', '=', 'bio_latest.biodata_id')
            ->select([
                'du_invoices.*',
                'users.name as user_name',
                'users.jenjang as user_jenjang',
                'users.status_kelulusan',
                'b.jalur_pendaftaran as jalur',
            ])
            ->when(! empty($filters['jenjang']), fn (Builder $q) => $q->where('du_invoices.jenjang', $filters['jenjang']))
            ->when(! empty($filters['jalur_pendaftaran']), fn (Builder $q) => $q->where('b.jalur_pendaftaran', $filters['jalur_pendaftaran']))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('users.name', 'like', $term)
                    ->orWhere('du_invoices.invoice_number', 'like', $term)
                    ->orWhere('du_invoices.student_name', 'like', $term));
            })
            ->when(! empty($filters['payment_status']), function (Builder $q) use ($filters) {
                $q->whereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('payments')
                    ->whereColumn('payments.id', 'du_invoices.payment_id')
                    ->where('payments.payment_category', Payment::CATEGORY_DAFTAR_ULANG)
                    ->where('payments.status', $filters['payment_status']));
            })
            ->orderByDesc('du_invoices.updated_at')
            ->orderByDesc('du_invoices.id');
    }

    /** Paginated participant list for the admin index. */
    public function list(array $filters): LengthAwarePaginator
    {
        $page = $this->participants($filters)->paginate($filters['per_page'] ?? 25);

        $page->getCollection()->transform(fn ($row) => $this->presentList($row));

        return $page;
    }

    /** A single participant's full detail, keyed by canonical users.id. */
    public function detail(int $userId): ?array
    {
        $row = $this->participants()->where('du_invoices.user_id', $userId)->first();
        abort_unless($row, 404);

        return $this->presentDetail($row);
    }

    /**
     * Resolve the DAFTAR_ULANG payment linked to an invoice, re-scoped by
     * category. Returns null for a corrupt/PPDB relation (fail-safe, never mix).
     */
    private function duPayment(DaftarUlangInvoice $invoice): ?Payment
    {
        if (! $invoice->payment_id) {
            return null;
        }

        return Payment::daftarUlang()
            ->whereKey($invoice->payment_id)
            ->first();
    }

    private function presentList(DaftarUlangInvoice $invoice): array
    {
        $payment = $this->duPayment($invoice);
        $snapshot = $invoice->pricing_snapshot ?? [];
        $agreement = $snapshot['agreement'] ?? null;
        $accepted = $this->agreementAcceptance($invoice);

        return [
            'id' => $invoice->user_id, // canonical users.id
            'name' => $invoice->user_name,
            'jenjang' => $invoice->user_jenjang,
            'jalur_pendaftaran' => $invoice->jalur,
            'status_kelulusan' => $invoice->status_kelulusan,
            'daftar_ulang' => [
                'status' => $invoice->withdrawn_at ? 'MENGUNDURKAN_DIRI' : $invoice->financial_status,
                'agreement_accepted' => $accepted !== null,
                'agreement_version' => $accepted?->agreement_version ?? ($agreement['version'] ?? null),
                'accepted_at' => $accepted?->agreed_at?->toIso8601String(),
            ],
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'created_at' => $invoice->created_at?->toIso8601String(),
                'subtotal' => $invoice->subtotal,
                'discount_nominal' => $invoice->discount_nominal,
                'total' => $invoice->total,
                'snapshot_at' => $invoice->snapshot_at?->toIso8601String(),
            ],
            'payment' => $this->presentPayment($payment),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }

    private function presentDetail(DaftarUlangInvoice $invoice): array
    {
        $payment = $this->duPayment($invoice);
        $snapshot = $invoice->pricing_snapshot ?? [];
        $agreement = $snapshot['agreement'] ?? null;
        $accepted = $this->agreementAcceptance($invoice);

        return [
            'id' => $invoice->user_id, // canonical users.id
            'name' => $invoice->user_name,
            'jenjang' => $invoice->user_jenjang,
            'jalur_pendaftaran' => $invoice->jalur,
            'status_kelulusan' => $invoice->status_kelulusan,
            'daftar_ulang' => [
                'status' => $invoice->withdrawn_at ? 'MENGUNDURKAN_DIRI' : $invoice->financial_status,
                'agreement_accepted' => $accepted !== null,
                'agreement_version' => $accepted?->agreement_version ?? ($agreement['version'] ?? null),
                'agreement_sha256' => $accepted?->agreement_sha256 ?? ($agreement['sha256'] ?? null),
                'accepted_at' => $accepted?->agreed_at?->toIso8601String(),
            ],
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'created_at' => $invoice->created_at?->toIso8601String(),
                'jenjang' => $invoice->jenjang,
                'student_name' => $invoice->student_name,
                'jalur_pendaftaran' => $invoice->jalur_pendaftaran,
                'pricing_revision_id' => $invoice->pricing_revision_id,
                'pricing_source' => $snapshot['source'] ?? null,
                'pricing_snapshot' => $snapshot,
                'items' => $snapshot['items'] ?? [],
                'subtotal' => $invoice->subtotal,
                'discount_nominal' => $invoice->discount_nominal,
                'total' => $invoice->total,
                'snapshot_at' => $invoice->snapshot_at?->toIso8601String(),
            ],
            'payment' => $this->presentPayment($payment),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }

    /** Present a DU payment for admin; never exposes storage paths. */
    private function presentPayment(?Payment $payment): ?array
    {
        if (! $payment) {
            return null;
        }

        // Resolve the verifier as a read-only lookup; no storage paths exposed.
        $verifier = $payment->verifier_id
            ? \App\Models\User::query()->select(['id', 'name'])->find($payment->verifier_id)
            : null;

        return [
            'id' => $payment->id,
            'payment_category' => $payment->payment_category,
            'invoice_number' => $payment->invoice_number,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'created_at' => $payment->created_at?->toIso8601String(),
            'verified_at' => $payment->verified_at?->toIso8601String(),
            'verifier' => $verifier ? ['id' => $verifier->id, 'name' => $verifier->name] : null,
            'rejection_reason' => $payment->rejection_reason,
            'has_proof' => ! empty($payment->proof_of_payment),
        ];
    }

    /** The recorded agreement acceptance for this invoice (latest by version). */
    private function agreementAcceptance(DaftarUlangInvoice $invoice)
    {
        return \App\Models\DaftarUlangAgreementAcceptance::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('agreed_at')
            ->orderByDesc('id')
            ->first();
    }
}
