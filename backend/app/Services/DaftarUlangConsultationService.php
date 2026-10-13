<?php

namespace App\Services;

use App\Exceptions\DaftarUlangConsultationException as Conflict;
use App\Models\DaftarUlangConsultation as Consultation;
use App\Models\DaftarUlangInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B.7.4-B — Daftar Ulang consultation (KONSULTASI KE SEKOLAH).
 *
 * SCOPE OF THE MUTATION SURFACE
 * -----------------------------
 * The ONLY rows this service ever writes are `du_consultations` rows. It never
 * touches users.status_siswa, users.continued_at, users.status_kelulusan,
 * payments, ppdb_capacities, ppdb_seat_allocations, du_invoices,
 * du_agreement_acceptances, du_pricing_revisions or any PPDB-domain record.
 *
 * So a consultation NEVER makes a student TERDAFTAR and NEVER makes them
 * MENGUNDURKAN_DIRI. Those stay the exclusive outcomes of
 * DaftarUlangCompletionService (B.7.2) and DaftarUlangWithdrawalService (B.7.1).
 * A parent who only asks for a consultation keeps status_siswa = NULL, and the
 * request is accepted regardless of DU payment status - it exists precisely for
 * parents who are still unsure BEFORE paying.
 *
 * IDENTITY
 * --------
 * Canonical identity is the authenticated users.id; the invoice is resolved
 * server-side from that id, so no invoice_id / user_id is ever taken from a
 * request. The parent side is scoped to role orang_tua (as in B.7.1/B.7.2); the
 * admin side accepts admin and super_admin (super_admin already passes the admin
 * CheckRole gate through its VIP branch, so it is honoured rather than rejected).
 *
 * EXACTLY ONE ACTIVE CONSULTATION PER INVOICE
 * -------------------------------------------
 * Guaranteed by the du_consult_active_invoice_unique index, NOT by a count. The
 * application-level pre-check only answers fast with a friendly 409; the index is
 * the real invariant, and a racer that loses gets a 1062 duplicate-key error
 * which is translated into the same CONSULTATION_ACTIVE_EXISTS conflict.
 *
 * LOCK ORDER
 * ----------
 * users row FIRST, then the DU invoice row, then consultation rows - the order
 * already established by DaftarUlangWithdrawalService and
 * DaftarUlangCompletionService. A consultation therefore never deadlocks against
 * a withdrawal or a continuation.
 *
 * INTERACTION WITH WITHDRAWAL (important, and deliberately asymmetric)
 * -------------------------------------------------------------------
 * DaftarUlangWithdrawalService does NOT know about consultations and does not
 * cancel them - it is the owner of the seat/kelulusan domain and stays untouched
 * here. Instead the consultation side defers to the withdrawal:
 *   - parentContext() re-reads du_invoices.withdrawn_at under the users lock,
 *     so once a withdrawal has committed, EVERY parent-side consultation call
 *     (request, list, cancel) is refused with 422. No new request can be raised
 *     against a withdrawn invoice, and no request can slip in mid-withdrawal.
 *   - an already-open consultation is left exactly as it stands. It is never
 *     silently cancelled and never rewritten, because it is audit history.
 * The admin desk keeps working on those rows (it is a back-office queue and is
 * gated on the users lock only), which is what lets staff close out an abandoned
 * request after a family withdrew.
 */
class DaftarUlangConsultationService
{
    /** Roles allowed to schedule / complete / cancel any consultation. */
    private const ADMIN_ROLES = ['admin', 'super_admin'];

    // ============================================================ PARENT ====

    /**
     * Create a consultation request against the authenticated parent's canonical
     * DU invoice. Idempotent by design: when an ACTIVE consultation already exists
     * it is returned unchanged instead of being duplicated, so the parent's
     * original preferred window is never rewritten by a repeated submit.
     */
    public function request(int $authenticatedUserId, array $data): array
    {
        return DB::transaction(function () use ($authenticatedUserId, $data) {
            [$user, $invoice] = $this->parentContext($authenticatedUserId, true, true);

            // B.7.4-E.1 — the decision-phase gate. Consultation exists ONLY for
            // a parent who is still deciding BEFORE a successful DU payment; a
            // family that has paid, registered officially, or withdrawn has
            // left that phase and must not open a NEW request. The check runs
            // inside the existing users+invoice transaction, under the same
            // locks, so a payment verification or a withdrawal committing
            // concurrently is ordered against this write.
            //
            // Reads only users.status_siswa and the canonical DU payment; it
            // never mutates them, and history stays untouched.
            $this->assertStillDeciding($user, $invoice);

            // Idempotent replay of an open request: return it, mutate nothing.
            $active = $this->activeForInvoice($invoice->id, false);
            if ($active) {
                return ['consultation' => $this->present($active), 'created' => false];
            }

            $consultation = new Consultation([
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'preferred_date' => $data['preferred_date'],
                'preferred_time' => $data['preferred_time'],
                'note' => $data['note'] ?? null,
            ]);

            // `status` is deliberately absent from $fillable, so forceFill is the
            // only way in - that keeps the allowlist a hard boundary rather than a
            // convention a mass-assigned request array could slip through.
            $consultation->forceFill(['status' => Consultation::STATUS_REQUESTED]);

            try {
                $consultation->save();
            } catch (QueryException $e) {
                // Lost the race against a concurrent request for the same invoice.
                if ($this->isActiveSlotViolation($e)) {
                    throw new Conflict(
                        Conflict::CONSULTATION_ACTIVE_EXISTS,
                        'Sudah ada permintaan konsultasi aktif untuk faktur ini.'
                    );
                }

                throw $e;
            }

            return ['consultation' => $this->present($consultation->refresh()), 'created' => true];
        });
    }

    /**
     * B.7.4-E.1 — the decision-phase gate for CREATING a consultation.
     *
     * Canonical server-side source of truth, in precedence order:
     *   1. users.status_siswa — TERDAFTAR / MENGUNDURKAN_DIRI are terminal
     *      lifecycle states owned by the continuation / withdrawal services.
     *   2. The canonical DU payment of THIS invoice (the invoice->payment()
     *      relation is category-scoped to DAFTAR_ULANG), whose SUCCESS is
     *      exactly what DaftarUlangCompletionService accepts as LUNAS. Unrelated
     *      PPDB payments are structurally invisible to this relation.
     *
     * Deliberately NOT applied to mine()/cancelMine(): history stays readable
     * and an open request stays cancellable, so the parent is never trapped
     * with a request they can no longer retract. The admin desk is untouched
     * by design — historical rows remain actionable there after eligibility
     * ends. Read-only: this method never writes anything.
     */
    private function assertStillDeciding(User $user, DaftarUlangInvoice $invoice): void
    {
        if (in_array($user->status_siswa, User::SISWA_STATES, true)) {
            throw ValidationException::withMessages([
                'eligibility' => $user->status_siswa === User::SISWA_TERDAFTAR
                    ? 'Konsultasi hanya tersedia sebelum pembayaran Daftar Ulang. Siswa sudah terdaftar resmi.'
                    : 'Konsultasi hanya tersedia sebelum pembayaran Daftar Ulang. Siswa sudah mengundurkan diri.',
            ]);
        }

        // Category-scoped, so only THIS invoice's Daftar Ulang payment counts.
        if ($invoice->payment()->first()?->status === 'SUCCESS') {
            throw ValidationException::withMessages([
                'eligibility' => 'Konsultasi hanya tersedia sebelum pembayaran Daftar Ulang. Pembayaran Anda sudah terverifikasi.',
            ]);
        }
    }

    /**
     * The parent's own consultation history, newest first, plus the open one.
     *
     * A GET never takes a lock, but it still honours the same withdrawn gate as
     * the rest of the parent-side DU surface (see DaftarUlangService::resolve(),
     * which refuses /pricing once withdrawn_at is set). That keeps the contract
     * uniform: after a withdrawal, every parent-side consultation call is 422.
     */
    public function mine(int $authenticatedUserId): array
    {
        // Read path: same gate, but $lock=false (no write lock) and
        // $requireInvoice=false (no invoice yet just means nothing to show).
        // The returned pair is deliberately unused - the call is for its guard.
        $this->parentContext($authenticatedUserId, false, false);

        $rows = Consultation::query()
            ->where('user_id', $authenticatedUserId)
            ->orderByDesc('id')
            ->get();

        return [
            'data' => $rows->map(fn ($c) => $this->present($c))->all(),
            'active' => $rows->first(fn ($c) => $c->isActive())?->id,
        ];
    }

    /**
     * A parent may cancel only their OWN active consultation. A terminal one
     * cannot be cancelled again (409), and an id owned by another parent is
     * reported as 404 rather than 403 so this endpoint never confirms the
     * existence of somebody else's record.
     */
    public function cancelMine(int $authenticatedUserId, int $consultationId): array
    {
        return DB::transaction(function () use ($authenticatedUserId, $consultationId) {
            [$user, $invoice] = $this->parentContext($authenticatedUserId, true, true);

            $consultation = Consultation::whereKey($consultationId)
                ->where('user_id', $user->id)
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            abort_unless($consultation, 404, 'Permintaan konsultasi tidak ditemukan.');

            $to = Consultation::STATUS_CANCELLED;
            $resolved = $this->transition($consultation, $to, $user);

            return ['consultation' => $this->present($resolved)];
        });
    }

    // ============================================================== ADMIN ===

    /**
     * Read-only admin queue. No mutation of any kind and no pricing
     * recalculation - every amount/status shown comes from stored rows.
     *
     * Ordering is actionable-first: REQUESTED, then SCHEDULED, then terminal,
     * newest first inside each bucket.
     */
    public function adminList(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));

        $columns = [
            'du_consultations.*',
            'users.name as user_name',
            'users.jenjang as user_jenjang',
            'users.status_kelulusan',
            'du_invoices.invoice_number',
            'du_invoices.jenjang as invoice_jenjang',
            'b.jalur_pendaftaran as jalur',
        ];

        $paginator = $this->adminQuery($filters, true)
            ->orderByRaw("FIELD(du_consultations.status, 'REQUESTED', 'SCHEDULED', 'COMPLETED', 'CANCELLED')")
            ->orderByDesc('du_consultations.id')
            ->paginate($perPage, $columns, 'page', $page);

        return [
            'data' => $paginator->getCollection()->map(fn ($c) => $this->present($c))->all(),
            'summary' => $this->adminSummary($filters),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * Admin schedule - the ONLY legal way into SCHEDULED. Calling it on an
     * already-SCHEDULED consultation is a RESCHEDULE: it overwrites the agreed
     * window while keeping scheduled_by at the first admin who took it (that
     * field is set-once) and never touching the parent's requested window.
     */
    public function adminSchedule(int $adminId, int $consultationId, array $data): array
    {
        return DB::transaction(function () use ($adminId, $consultationId, $data) {
            $admin = $this->lockAdmin($adminId);
            $consultation = $this->lockForAdmin($consultationId);

            $wasScheduled = $consultation->status === Consultation::STATUS_SCHEDULED;

            $consultation->forceFill([
                'status' => Consultation::STATUS_SCHEDULED,
                'scheduled_date' => $data['scheduled_date'],
                'scheduled_time' => $data['scheduled_time'],
                'admin_note' => $data['admin_note'] ?? $consultation->admin_note,
            ]);

            // set-once: a reschedule keeps the admin who first took the request.
            if ($consultation->scheduled_by === null) {
                $consultation->forceFill(['scheduled_by' => $admin->id]);
            }

            $this->persist($consultation);

            return [
                'consultation' => $this->present($consultation->refresh()),
                'rescheduled' => $wasScheduled,
            ];
        });
    }

    /**
     * Admin resolve to a TERMINAL state: COMPLETED (only from SCHEDULED) or
     * CANCELLED (from any ACTIVE state). REQUESTED -> COMPLETED is refused with
     * 409 because nothing can be completed before it is scheduled.
     */
    public function adminResolve(int $adminId, int $consultationId, string $to, ?string $note): array
    {
        // Only a TERMINAL target is a "resolution". This closes the one gap where
        // SCHEDULED would otherwise pass the state machine here (REQUESTED ->
        // SCHEDULED is a legal edge) while stamping resolved_by/resolved_at and
        // leaving the schedule columns empty - which the
        // du_consultations_schedule_consistency_check would then reject as a 500.
        // Scheduling has exactly one entry point: adminSchedule().
        if (! in_array($to, Consultation::TERMINAL_STATUSES, true)) {
            throw new Conflict(
                Conflict::CONSULTATION_ILLEGAL_MOVE,
                'Penyelesaian konsultasi hanya dapat menuju COMPLETED atau CANCELLED.'
            );
        }

        return DB::transaction(function () use ($adminId, $consultationId, $to, $note) {
            $admin = $this->lockAdmin($adminId);
            $consultation = $this->lockForAdmin($consultationId);

            if ($note !== null) {
                $consultation->forceFill(['admin_note' => $note]);
            }

            $resolved = $this->transition($consultation, $to, $admin);

            return ['consultation' => $this->present($resolved)];
        });
    }

    // ================================================== state machine core ==

    /**
     * The single funnel for every status change. It refuses illegal edges with
     * 409 BEFORE any write, then stamps the server-owned resolution audit and
     * lets the model guard re-validate on save (defence in depth).
     */
    private function transition(Consultation $consultation, string $to, User $actor): Consultation
    {
        $from = $consultation->status;

        if ($consultation->isTerminal()) {
            throw new Conflict(
                Conflict::CONSULTATION_NOT_ACTIVE,
                "Permintaan konsultasi sudah berstatus {$from} dan tidak dapat diubah lagi."
            );
        }

        if (! Consultation::isLegalTransition($from, $to)) {
            throw new Conflict(
                Conflict::CONSULTATION_ILLEGAL_MOVE,
                "Transisi konsultasi {$from} ke {$to} tidak diizinkan."
            );
        }

        $consultation->forceFill([
            'status' => $to,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);

        $this->persist($consultation);

        return $consultation->refresh();
    }

    private function persist(Consultation $consultation): void
    {
        try {
            $consultation->save();
        } catch (QueryException $e) {
            // A concurrent writer may have moved the ACTIVE slot; the unique index
            // is authoritative, so surface the conflict instead of a 500.
            if ($this->isActiveSlotViolation($e)) {
                throw new Conflict(
                    Conflict::CONSULTATION_ACTIVE_EXISTS,
                    'Permintaan konsultasi aktif untuk faktur ini berubah; muat ulang.'
                );
            }

            throw $e;
        }
    }

    // ==================================================== private helpers ===

    /**
     * The parent eligibility gate, shared by the mutating and read paths.
     *
     * Locks users FIRST, then the canonical DU invoice, and proves the parent is
     * inside the Daftar Ulang domain at all (LULUS, not withdrawn). This is the
     * same gate DaftarUlangService::eligibleLocked() applies, so a consultation
     * cannot be raised by a TIDAK_LULUS candidate or by someone who already
     * withdrew - but it is deliberately NOT gated on payment status, and it
     * deliberately does NOT check status_siswa: an already-TERDAFTAR student may
     * still want to consult, and asking must not disturb their terminal state.
     *
     * $lock=false is for reads only. A GET must not take a write lock on the
     * canonical user row, or a parent merely opening their consultation list
     * would serialize against a concurrent withdrawal.
     *
     * Keeping both paths in ONE method is deliberate: the eligibility rules must
     * not be able to drift apart between "can I look" and "can I act".
     *
     * $requireInvoice distinguishes them, because DU invoices are created LAZILY
     * (DaftarUlangService::createInvoice()) and its own eligibleLocked() does not
     * demand one. A write path needs a canonical invoice to attach the row to, so
     * a missing one is a genuine 404. A read path does not: a LULUS parent who has
     * not opened the DU page yet simply has no consultations, and a list endpoint
     * must answer with an EMPTY LIST rather than a 404 that looks like an error.
     *
     * @return array{0: User, 1: DaftarUlangInvoice|null}
     */
    private function parentContext(int $authenticatedUserId, bool $lock, bool $requireInvoice): array
    {
        $user = User::whereKey($authenticatedUserId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->firstOrFail();

        abort_unless($user->role === 'orang_tua', 403);

        if ($user->status_kelulusan !== 'LULUS') {
            throw ValidationException::withMessages([
                'eligibility' => 'Konsultasi Daftar Ulang hanya untuk peserta LULUS.',
            ]);
        }

        $invoice = DaftarUlangInvoice::where('user_id', $user->id)
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($invoice === null) {
            abort_if($requireInvoice, 404, 'Faktur Daftar Ulang tidak ditemukan.');

            return [$user, null];
        }

        if ($invoice->withdrawn_at !== null) {
            throw ValidationException::withMessages([
                'withdrawn' => 'Faktur Daftar Ulang telah ditarik; konsultasi tidak tersedia.',
            ]);
        }

        return [$user, $invoice];
    }

    private function lockAdmin(int $adminId): User
    {
        $admin = User::whereKey($adminId)->lockForUpdate()->firstOrFail();

        if (! in_array($admin->role, self::ADMIN_ROLES, true)) {
            throw new Conflict(
                Conflict::CONSULTATION_FORBIDDEN_ROLE,
                'Hanya admin yang dapat mengubah permintaan konsultasi.'
            );
        }

        return $admin;
    }

    /**
     * Lock one consultation for an admin action; 404 when unknown. The parent's
     * users row is locked first so admin actions serialize against a concurrent
     * withdrawal / continuation on the same canonical user.
     */
    private function lockForAdmin(int $consultationId): Consultation
    {
        $consultation = Consultation::whereKey($consultationId)->lockForUpdate()->first();
        abort_unless($consultation, 404, 'Permintaan konsultasi tidak ditemukan.');

        User::whereKey($consultation->user_id)->lockForUpdate()->first();

        if ($consultation->isTerminal()) {
            throw new Conflict(
                Conflict::CONSULTATION_NOT_ACTIVE,
                "Permintaan konsultasi sudah berstatus {$consultation->status} dan tidak dapat diubah lagi."
            );
        }

        return $consultation;
    }

    private function activeForInvoice(int $invoiceId, bool $lock): ?Consultation
    {
        return Consultation::query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', Consultation::ACTIVE_STATUSES)
            ->orderBy('id')
            ->when($lock, fn (Builder $q) => $q->lockForUpdate())
            ->first();
    }

    /** True when the DB rejected the write on the one-ACTIVE-per-invoice index. */
    private function isActiveSlotViolation(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'du_consult_active_invoice_unique');
    }

    /**
     * Normalize a MySQL TIME value to the HH:MM the API accepts.
     *
     * MySQL stores TIME as HH:MM:SS, so a client that submitted "10:30" would
     * otherwise read back "10:30:00" and could not round-trip its own value
     * through a resubmit or a form prefill. Truncating the (always zero) seconds
     * component here makes the contract symmetric.
     */
    private function clockTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_contains($value, ':')
            ? implode(':', array_slice(explode(':', $value), 0, 2))
            : $value;
    }

    /**
     * Admin queue base query. Filters are additive, allowlisted by the caller,
     * and can never widen the row set.
     */
    private function adminQuery(array $filters, bool $applyStatusFilter): Builder
    {
        // Latest biodata per user, matching DaftarUlangAdminService's convention.
        $latestBiodata = DB::table('biodatas')
            ->selectRaw('user_id, MAX(id) as biodata_id')
            ->groupBy('user_id');

        return Consultation::query()
            ->join('users', 'users.id', '=', 'du_consultations.user_id')
            ->join('du_invoices', 'du_invoices.id', '=', 'du_consultations.invoice_id')
            ->leftJoinSub($latestBiodata, 'bio_latest', 'bio_latest.user_id', '=', 'du_consultations.user_id')
            ->leftJoin('biodatas as b', 'b.id', '=', 'bio_latest.biodata_id')
            ->when(! empty($filters['jenjang']),
                fn (Builder $q) => $q->where('du_invoices.jenjang', $filters['jenjang']))
            ->when(! empty($filters['jalur_pendaftaran']),
                fn (Builder $q) => $q->where('b.jalur_pendaftaran', $filters['jalur_pendaftaran']))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('users.name', 'like', $term)
                    ->orWhere('du_invoices.invoice_number', 'like', $term)
                    ->orWhere('du_invoices.student_name', 'like', $term));
            })
            ->when($applyStatusFilter && ! empty($filters['status']),
                fn (Builder $q) => $q->whereIn('du_consultations.status', $this->expandStatus($filters['status'])));
    }

    /** OPEN/ACTIVE are the two live states; anything else is a literal status. */
    private function expandStatus(string $status): array
    {
        return in_array($status, ['OPEN', 'ACTIVE'], true)
            ? Consultation::ACTIVE_STATUSES
            : [$status];
    }

    /**
     * Uncounted totals for the same filter set as the list (status deliberately
     * ignored so the admin sees the whole distribution). Read-only.
     */
    private function adminSummary(array $filters): array
    {
        $base = fn () => $this->adminQuery($filters, false);

        return [
            'requested' => (clone $base())->where('du_consultations.status', Consultation::STATUS_REQUESTED)->count(),
            'scheduled' => (clone $base())->where('du_consultations.status', Consultation::STATUS_SCHEDULED)->count(),
            'completed' => (clone $base())->where('du_consultations.status', Consultation::STATUS_COMPLETED)->count(),
            'cancelled' => (clone $base())->where('du_consultations.status', Consultation::STATUS_CANCELLED)->count(),
            'open' => (clone $base())->whereIn('du_consultations.status', Consultation::ACTIVE_STATUSES)->count(),
        ];
    }

    /**
     * The single explicit response shape. No model is ever serialized directly,
     * so actor ids and the generated active_invoice column can never leak.
     */
    private function present(Consultation $consultation): array
    {
        return [
            'id' => (int) $consultation->id,
            'invoice_id' => (int) $consultation->invoice_id,
            'status' => $consultation->status,
            'is_active' => $consultation->isActive(),
            'is_terminal' => $consultation->isTerminal(),
            'request' => [
                'preferred_date' => optional($consultation->preferred_date)->toDateString(),
                'preferred_time' => $this->clockTime($consultation->preferred_time),
                'note' => $consultation->note,
            ],
            'schedule' => [
                'scheduled_date' => optional($consultation->scheduled_date)->toDateString(),
                'scheduled_time' => $this->clockTime($consultation->scheduled_time),
                'admin_note' => $consultation->admin_note,
            ],
            // Identity columns read off the admin-list joins; null on the parent
            // side, where only the authenticated user's own row is ever present.
            'participant' => $consultation->getAttribute('invoice_number') === null ? null : [
                'name' => $consultation->getAttribute('user_name'),
                'invoice_number' => $consultation->getAttribute('invoice_number'),
                'jenjang' => $consultation->getAttribute('invoice_jenjang'),
                'jalur_pendaftaran' => $consultation->getAttribute('jalur'),
                'status_kelulusan' => $consultation->getAttribute('status_kelulusan'),
            ],
            'resolved_at' => optional($consultation->resolved_at)->toIso8601String(),
            'created_at' => optional($consultation->created_at)->toIso8601String(),
        ];
    }
}
