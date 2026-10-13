<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * B.7.4-B — a Daftar Ulang consultation request (KONSULTASI KE SEKOLAH).
 *
 * THE STATE MACHINE (the ONLY legal edges; everything else is a LogicException):
 *
 *   REQUESTED ──┬── CANCELLED        (parent or admin cancel)
 *               └── SCHEDULED        (admin schedule)
 *   SCHEDULED ──┬── SCHEDULED        (admin RESCHEDULE — same-state transition)
 *               ├── COMPLETED        (admin complete)
 *               └── CANCELLED        (parent or admin cancel)
 *   COMPLETED ──── (terminal; no outgoing edge)
 *   CANCELLED ──── (terminal; no outgoing edge)
 *
 * Explicitly FORBIDDEN (and therefore impossible to commit through the model):
 *   REQUESTED -> COMPLETED   (nothing can be completed before it is scheduled)
 *   SCHEDULED -> REQUESTED   (a schedule is never rolled back)
 *   COMPLETED -> anything
 *   CANCELLED -> anything
 *
 * This is a NON-immutable DU record, unlike its siblings: du_invoices,
 * du_withdrawals and du_pricing_revisions are write-once history, whereas a
 * consultation is an open request that an admin legitimately advances. So the
 * model does NOT extend ImmutableDaftarUlangRecord. It instead enforces
 * immutability of the HISTORICAL parts:
 *   - the request fields (preferred_date/preferred_time/note) are write-once at
 *     CREATE and frozen for life; a reschedule never rewrites what the parent asked
 *   - identity (user_id/invoice_id) is server-resolved and never mutated
 *   - scheduled_by / resolved_by / resolved_at are set-once and never rewritten
 *
 * PAYMENT / SEAT ISOLATION: this model holds no relation to payments or seat
 * allocations and never mutates them. The invoice relation is read-only and is
 * deliberately constrained to the DAFTAR_ULANG category.
 */
class DaftarUlangConsultation extends Model
{
    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_SCHEDULED = 'SCHEDULED';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Every legal status value (mirrors the DB CHECK constraint). */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Terminal states accept no further transition. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /**
     * ACTIVE states. Exactly one per invoice, enforced by the
     * du_consult_active_invoice_unique partial unique index — NOT by an
     * application-level count.
     */
    public const ACTIVE_STATUSES = [self::STATUS_REQUESTED, self::STATUS_SCHEDULED];

    /** Legal outgoing edges, keyed by source status. */
    private const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'du_consultations';

    /**
     * Strict allowlist. Privileged fields absent from this list can only ever be
     * written through forceFill() by the service layer, so a mass-assigned
     * request array can never set status, actor ids or resolved_at.
     *
     * NOTE: `status` is intentionally EXCLUDED even though the service does
     * advance it — the service is the only writer and always goes through
     * forceFill(), which makes the allowlist a hard boundary rather than a
     * convention.
     */
    protected $fillable = [
        'user_id',
        'invoice_id',
        'preferred_date',
        'preferred_time',
        'note',
        'admin_note',
        'scheduled_date',
        'scheduled_time',
    ];

    /** Privileged / server-owned columns: forceFill only. */
    protected $guarded = [
        'id',
        'status',
        'scheduled_by',
        'resolved_by',
        'resolved_at',
    ];

    public $timestamps = true;

    /**
     * Never serializable outward; every consumer maps an explicit payload. This
     * is defence in depth so a future `->toJson()` cannot leak actor ids or the
     * generated column.
     */
    protected $hidden = ['active_invoice'];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'preferred_time' => 'string',
            'scheduled_date' => 'date',
            'scheduled_time' => 'string',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $consultation) {
            if ($consultation->exists) {
                $consultation->assertUpdateIsLegal();
            } else {
                $consultation->assertCreateIsValid();
            }
        });

        // Consultation history is audit data: rows are cancelled/completed,
        // never deleted. Deleting would also silently free the ACTIVE slot.
        static::deleting(fn () => throw new \LogicException(
            'Daftar Ulang consultation records must be preserved; cancel them instead.'));
    }

    /** A new consultation always starts REQUESTED, with no resolution yet. */
    private function assertCreateIsValid(): void
    {
        throw_unless($this->status === self::STATUS_REQUESTED, new \LogicException(
            'A Daftar Ulang consultation must be created as REQUESTED.'));
        throw_unless($this->scheduled_by === null && $this->resolved_by === null
            && $this->resolved_at === null && $this->scheduled_date === null
            && $this->scheduled_time === null && $this->admin_note === null,
            new \LogicException('A new Daftar Ulang consultation carries no admin or resolution data.'));
    }

    private function assertUpdateIsLegal(): void
    {
        // 0. Terminal is terminal: COMPLETED / CANCELLED accept NO update at all,
        //    not even an admin_note edit. This is the model-level backstop for
        //    "do not rewrite historical terminal data".
        $from = $this->getOriginal('status');
        throw_if(in_array($from, self::TERMINAL_STATUSES, true), new \LogicException(
            "Daftar Ulang consultation status [{$from}] is terminal and accepts no update."));

        // 1. The parent's original request window is frozen for life, so an admin
        //    reschedule can never rewrite what the parent actually asked for.
        foreach (['preferred_date', 'preferred_time', 'note', 'user_id', 'invoice_id'] as $frozen) {
            throw_if($this->isDirty($frozen), new \LogicException(
                "Daftar Ulang consultation field [{$frozen}] is immutable after creation."));
        }

        // 2. Actor / resolution fields are set-once and never rewritten.
        foreach (['scheduled_by', 'resolved_by', 'resolved_at'] as $setOnce) {
            if ($this->isDirty($setOnce)) {
                throw_unless($this->getOriginal($setOnce) === null && $this->{$setOnce} !== null,
                    new \LogicException(
                        "Daftar Ulang consultation field [{$setOnce}] is set exactly once and never overwritten."));
            }
        }

        // 3. The state machine. A reschedule does not dirty `status` at all
        //    (SCHEDULED stays SCHEDULED) and is therefore permitted by step 0.
        if (! $this->isDirty('status')) {
            return;
        }

        $to = $this->status;

        throw_unless(in_array($to, self::STATUSES, true), new \LogicException(
            "Unknown Daftar Ulang consultation status [{$to}]."));

        throw_unless(in_array($to, self::TRANSITIONS[$from] ?? [], true), new \LogicException(
            "Illegal Daftar Ulang consultation transition [{$from} -> {$to}]."));
    }

    /**
     * Validate a transition WITHOUT persisting, so the service can answer an
     * illegal edge with HTTP 409 before it ever touches the row.
     */
    public static function isLegalTransition(string $from, string $to): bool
    {
        return in_array($from, self::STATUSES, true)
            && in_array($to, self::STATUSES, true)
            && ! in_array($from, self::TERMINAL_STATUSES, true)
            && in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Read-only link, re-scoped so a PPDB invoice can never be attached. */
    public function invoice()
    {
        return $this->belongsTo(DaftarUlangInvoice::class, 'invoice_id');
    }

    public function scheduledByUser()
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
