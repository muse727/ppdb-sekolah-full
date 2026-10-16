<?php

namespace App\Http\Controllers;

use App\Exceptions\DaftarUlangConsultationException;
use App\Models\Biodata;
use App\Models\DaftarUlangConsultation;
use App\Models\DaftarUlangIndividualPricing;
use App\Models\DaftarUlangPricingRevision;
use App\Services\DaftarUlangAdminService;
use App\Services\DaftarUlangConsultationService;
use App\Services\DaftarUlangPaymentService;
use App\Services\DaftarUlangPricingService;
use App\Services\DaftarUlangService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DaftarUlangController extends Controller
{
    private function fields(Request $request, array $allowed): void
    {
        if (array_diff($request->keys(), $allowed)) {
            throw ValidationException::withMessages(['input' => 'Field tidak diizinkan.']);
        }
    }

    public function pricing(Request $request, DaftarUlangService $service)
    {
        $this->fields($request, []);
        return response()->json($service->resolve($request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    public function createInvoice(Request $request, DaftarUlangService $service)
    {
        $this->fields($request, []);
        return response()->json($service->createInvoice($request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    public function activeInvoice(Request $request, DaftarUlangService $service)
    {
        $this->fields($request, []);
        return response()->json($service->activeInvoice($request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    public function invoice(Request $request, int $id, DaftarUlangService $service)
    {
        $this->fields($request, []);
        return response()->json($service->invoice($request->user()->id, $id))->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, int $id, DaftarUlangService $service)
    {
        $this->fields($request, ['agree']);
        $request->validate(['agree' => 'required|accepted']);
        return response()->json($service->accept($request->user()->id, $id));
    }

    // ===================== B.7.1: DAFTAR ULANG WITHDRAWAL =====================
    // The withdrawal is bound to the authenticated users.id only. The request may
    // carry an optional note; any user_id / invoice_id / allocation_id / payment
    // field is rejected by the strict allowlist. Ownership and the seat to release
    // are resolved server-side inside DaftarUlangWithdrawalService.
    public function withdraw(Request $request, \App\Services\DaftarUlangWithdrawalService $service)
    {
        $this->fields($request, ['note']);
        $validated = $request->validate(['note' => 'nullable|string|max:500']);
        return $service->withdraw($request->user()->id, $validated['note'] ?? null);
    }

    // ===================== B.7.2: MELANJUTKAN (official-student completion) =====================
    // The continuation is bound to the authenticated users.id only. The request
    // body must be EMPTY: any user_id / invoice_id / payment_id / allocation_id /
    // status_siswa field is rejected by the strict allowlist. All canonical
    // entities (invoice, agreement snapshot, DU payment, seat) are resolved
    // server-side inside DaftarUlangCompletionService, which is the ONLY path
    // to status_siswa = TERDAFTAR.
    public function continue(Request $request, \App\Services\DaftarUlangCompletionService $service)
    {
        $this->fields($request, []);
        return $service->continue($request->user()->id);
    }

    // ===================== B.6A: DAFTAR ULANG PAYMENT SETTLEMENT =====================
    // The payment_category is always resolved server-side as DAFTAR_ULANG inside
    // DaftarUlangPaymentService. The request can only carry the upload payload;
    // any attempt to pass user_id / payment_category / jenjang / invoice_id /
    // amount / pricing / status / rekening is rejected by the strict allowlist.

    /** Parent: upload their own Daftar Ulang proof of payment. */
    public function submitProof(Request $request, DaftarUlangPaymentService $service)
    {
        $this->fields($request, ['bukti_bayar']);
        $request->validate([
            'bukti_bayar' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        return $service->submitProof($request->user()->id, $request);
    }

    /** Admin: list DAFTAR_ULANG manual payments awaiting verification. */
    public function pendingPayments(Request $request, DaftarUlangPaymentService $service)
    {
        $this->fields($request, []);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return response()->json($service->pendingManual());
    }

    /** Admin: stream a DAFTAR_ULANG payment's evidence from private storage. */
    public function evidence(Request $request, int $payment, DaftarUlangPaymentService $service)
    {
        $this->fields($request, []);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return $service->evidence($payment);
    }

    /** Admin: verify a DAFTAR_ULANG manual payment (financial-only). */
    public function verifyPayment(Request $request, int $payment, DaftarUlangPaymentService $service)
    {
        $this->fields($request, []);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return $service->verify($payment);
    }

    /** Admin: reject a DAFTAR_ULANG manual payment (financial-only). */
    public function rejectPayment(Request $request, int $payment, DaftarUlangPaymentService $service)
    {
        $this->fields($request, ['reason']);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $service->reject($payment, $data['reason'] ?? null);
    }

    // ===================== B.6A-C: READ-ONLY ADMIN PARTICIPANT CONTRACT =====================
    // These endpoints only READ existing DU domain records to back the admin UI.
    // They perform no settlement, no mutation, and no pricing recalculation.

    /** Admin: paginated Daftar Ulang participant list (grounded in du_invoices). */
    public function students(Request $request, DaftarUlangAdminService $service)
    {
        $this->fields($request, ['status_pembayaran', 'jenjang', 'jalur_pendaftaran', 'search', 'page', 'per_page']);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        $filters = $request->validate([
            'status_pembayaran' => ['nullable', Rule::in(['PENDING', 'PENDING_MANUAL', 'SUCCESS', 'REJECTED', 'FAILED', 'EXPIRED'])],
            'jenjang' => ['nullable', Rule::in(['SD', 'SMP'])],
            'jalur_pendaftaran' => ['nullable', Rule::in(Biodata::JALUR_PENDAFTARAN)],
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $page = $service->list([
            'payment_status' => $filters['status_pembayaran'] ?? null,
            'jenjang' => $filters['jenjang'] ?? null,
            'jalur_pendaftaran' => $filters['jalur_pendaftaran'] ?? null,
            'search' => $filters['search'] ?? null,
            'per_page' => $filters['per_page'] ?? 25,
        ]);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Admin: full Daftar Ulang detail for a participant, keyed by users.id. */
    public function student(Request $request, int $id, DaftarUlangAdminService $service)
    {
        $this->fields($request, []);
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);

        return response()->json($service->detail($id))->header('Cache-Control', 'private, no-store');
    }

    public function defaultPricing(Request $request, string $jenjang)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        abort_unless(in_array($jenjang, ['SD', 'SMP'], true), 422);
        $id = DB::table('du_default_pricings')->where('jenjang', $jenjang)->value('pricing_revision_id');
        return response()->json($id ? DaftarUlangPricingRevision::findOrFail($id) : null);
    }

    public function updateDefault(Request $request, string $jenjang, DaftarUlangPricingService $service)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        $this->fields($request, ['items', 'discount_nominal']);
        $data = $request->validate(['items' => 'required|array', 'discount_nominal' => 'required|integer']);
        return response()->json($service->updateDefault($request->user()->id, $jenjang, $data['items'], $data['discount_nominal']));
    }

    public function individual(Request $request, int $userId)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        $user = User::findOrFail($userId);
        $individual = DaftarUlangIndividualPricing::where('user_id', $userId)->with('revision')->first();
        $defaultPricingId = DB::table('du_default_pricings')->where('jenjang', $user->jenjang)->value('pricing_revision_id');
        $defaultRevision = $defaultPricingId ? DaftarUlangPricingRevision::find($defaultPricingId) : null;

        return response()->json([
            'individual' => $individual,
            'default_pricing' => $defaultRevision,
            'jenjang' => $user->jenjang,
            'student_name' => $user->name,
        ]);
    }

    public function updateIndividual(Request $request, int $userId, DaftarUlangPricingService $service)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'super_admin'], true), 403);
        $this->fields($request, ['items', 'discount_nominal', 'mode', 'active']);
        $data = $request->validate(['items' => 'sometimes|required|array', 'discount_nominal' => 'sometimes|required|integer',
            'mode' => 'sometimes|required|in:DEFAULT,CUSTOM', 'active' => 'sometimes|required|boolean']);
        if (!$data) {
            throw ValidationException::withMessages(['pricing' => 'Perubahan tidak boleh kosong.']);
        }
        return response()->json($service->updateIndividual($request->user()->id, $userId, $data));
    }

    // ===================== B.7.4-B: KONSULTASI KE SEKOLAH =====================
    // The third decision path beside MELANJUTKAN (B.7.2) and MENGUNDURKAN DIRI
    // (B.7.1). Like both of those, a consultation is bound to the authenticated
    // users.id only: no user_id / invoice_id / status / scheduled_by / resolved_by
    // field is accepted, and the strict allowlist rejects them with 422. The
    // canonical DU invoice is resolved server-side inside the service.
    //
    // A consultation mutates ONLY du_consultations rows. It never sets
    // status_siswa, never changes status_kelulusan, never touches payments or
    // seats, and never rewrites the DU pricing snapshot or agreement acceptance.

    /** Parent: request a consultation. Idempotent while one is still ACTIVE. */
    public function requestConsultation(Request $request, DaftarUlangConsultationService $service)
    {
        $this->fields($request, ['preferred_date', 'preferred_time', 'note']);

        $data = $request->validate([
            'preferred_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'preferred_time' => 'required|date_format:H:i',
            'note' => 'nullable|string|max:1000',
        ]);

        return $this->consultationResponse(fn () => $service->request($request->user()->id, $data), 201);
    }

    /** Parent: their own consultation history plus the currently ACTIVE id. */
    public function myConsultations(Request $request, DaftarUlangConsultationService $service)
    {
        $this->fields($request, []);

        return $this->consultationResponse(
            fn () => $service->mine($request->user()->id)
        );
    }

    /** Parent: cancel their OWN active consultation. */
    public function cancelMyConsultation(
        Request $request,
        int $id,
        DaftarUlangConsultationService $service
    ) {
        $this->fields($request, []);

        return $this->consultationResponse(
            fn () => $service->cancelMine($request->user()->id, $id)
        );
    }

    /** Admin: read-only consultation queue with filters, summary and pagination. */
    public function consultations(Request $request, DaftarUlangConsultationService $service)
    {
        $this->fields($request, ['status', 'jenjang', 'jalur_pendaftaran', 'search', 'page', 'per_page']);
        $this->assertConsultationAdmin($request);

        // Every filter value is allowlisted here, so the service query can treat
        // them as trusted and can never widen the row set.
        $filters = $request->validate([
            'status' => 'nullable|in:REQUESTED,SCHEDULED,COMPLETED,CANCELLED,OPEN,ACTIVE',
            'jenjang' => 'nullable|in:SD,SMP',
            'jalur_pendaftaran' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return $this->consultationResponse(fn () => $service->adminList($filters));
    }

    /**
     * Admin: schedule (or RESCHEDULE) an active consultation. This is the only
     * way into SCHEDULED, so REQUESTED -> COMPLETED is impossible by construction.
     */
    public function scheduleConsultation(
        Request $request,
        int $id,
        DaftarUlangConsultationService $service
    ) {
        $this->fields($request, ['scheduled_date', 'scheduled_time', 'admin_note']);
        $this->assertConsultationAdmin($request);

        $data = $request->validate([
            'scheduled_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'scheduled_time' => 'required|date_format:H:i',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        return $this->consultationResponse(
            fn () => $service->adminSchedule($request->user()->id, $id, $data)
        );
    }

    /** Admin: COMPLETED (from SCHEDULED only) or CANCELLED (from any ACTIVE). */
    public function resolveConsultation(
        Request $request,
        int $id,
        DaftarUlangConsultationService $service
    ) {
        $this->fields($request, ['action', 'admin_note']);
        $this->assertConsultationAdmin($request);

        $data = $request->validate([
            // `action` is the client vocabulary; it is translated to a status here
            // so no client can ever name a status directly (e.g. REQUESTED).
            'action' => 'required|in:complete,cancel',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $to = $data['action'] === 'complete'
            ? DaftarUlangConsultation::STATUS_COMPLETED
            : DaftarUlangConsultation::STATUS_CANCELLED;

        return $this->consultationResponse(
            fn () => $service->adminResolve($request->user()->id, $id, $to, $data['admin_note'] ?? null)
        );
    }

    /**
     * Both admin and super_admin may run the consultation desk. The role gate is
     * re-asserted in code (not only in routes) so the endpoints stay correct if
     * they are ever registered under a different middleware stack.
     */
    private function assertConsultationAdmin(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'super_admin'], true),
            403,
            'Hanya admin yang dapat mengelola konsultasi.'
        );
    }

    /**
     * Map a service call to an HTTP response.
     *
     * A domain conflict becomes 409/403 via the exception's own reasonCode -
     * never a 422 validation error and never a generic 500, because an illegal
     * transition is a conflict with concurrently-committed state rather than
     * malformed input. A LogicException from the model guard means an illegal
     * write reached the persistence layer, which is a programming error: it is
     * deliberately left to bubble up as a 500 instead of being masked as 409.
     */
    private function consultationResponse(callable $call, int $created = 200)
    {
        try {
            $payload = $call();
        } catch (DaftarUlangConsultationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason_code' => $e->reasonCode,
            ], $e->httpStatus());
        }

        // Idempotent replay of an already-active request is 200, not 201: nothing
        // was created, so the response must not claim a new resource.
        $status = ($payload['created'] ?? true) ? $created : 200;
        unset($payload['created']);

        return response()->json($payload, $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
