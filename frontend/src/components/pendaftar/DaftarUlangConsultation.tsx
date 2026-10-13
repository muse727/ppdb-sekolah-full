// src/components/pendaftar/DaftarUlangConsultation.tsx
'use client';

import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import axios from 'axios';
import type {
    DuConsultation,
    DuConsultationCreatePayload,
    DuConsultationListResult,
    DuConsultationMutationResult,
    DuConsultationStatus,
} from '@/lib/domain';
import { httpErrorMessage, httpHasFieldError } from '@/lib/domain';
import styles from './daftar-ulang-consultation.module.css';

/* ==========================================================================
   B.7.4-C — "KONSULTASI KE SEKOLAH" (parent surface)

   The third decision path beside MELANJUTKAN and MENGUNDURKAN DIRI, and it is
   deliberately inert: it never touches status_siswa, continued_at, the seat or
   the DU payment. A parent only PROPOSES a window here — staff confirms the
   real schedule — so no copy on this surface may imply a visit is booked.

   Contract rules this component is built around:
   - GET  /api/daftar-ulang/consultations              -> { data, active }
   - POST /api/daftar-ulang/consultations              -> 201 new | 200 replay
   - POST /api/daftar-ulang/consultations/{id}/cancel   -> { consultation }
   - The controller's strict allowlist rejects ANY extra key with 422
     `errors.input`, and it inspects `$request->keys()`, which includes query
     params. So the GET and the cancel are sent completely empty.
   - status / is_active / is_terminal are server-owned. Rendered as received
     and never recomputed here, so the client cannot drift from the DB.
   - The withdrawn / not-LULUS gate answers 422 with `errors.withdrawn` or
     `errors.eligibility` (verified against the live API), not an error code.
   ========================================================================== */

/** "YYYY-MM-DD" -> long Indonesian date. Parsed as LOCAL midnight so a WIB
 *  date never shifts a day the way bare `new Date('YYYY-MM-DD')` (UTC) does. */
const longDate = (value: string | null | undefined): string => {
    if (!value) return '—';
    const parsed = new Date(`${value}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};

/** ISO timestamps, formatted the way the DU page already does. */
const stamp = (value: string | null | undefined): string =>
    value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';

/** Today in LOCAL YYYY-MM-DD, for the date input's `min` and the client-side
 *  mirror of `after_or_equal:today`. */
const todayLocal = (): string => {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');
    const day = `${now.getDate()}`.padStart(2, '0');
    return `${now.getFullYear()}-${month}-${day}`;
};

const STATUS_LABEL: Record<DuConsultationStatus, string> = {
    REQUESTED: 'Menunggu Konfirmasi',
    SCHEDULED: 'Terjadwal',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
};

const BADGE_CLASS: Record<DuConsultationStatus, string> = {
    REQUESTED: styles.duBadgeRequested,
    SCHEDULED: styles.duBadgeScheduled,
    COMPLETED: styles.duBadgeCompleted,
    CANCELLED: styles.duBadgeCancelled,
};

/** Server 422 field errors, mirrored per input. */
type FieldErrors = {
    preferred_date?: string;
    preferred_time?: string;
    note?: string;
    general?: string;
};

/** 422 body shape, as emitted by ValidationException / the allowlist guard. */
type ValidationBody = {
    message?: string;
    errors?: Record<string, string[]>;
};

const NOTE_MAX = 1000;

export default function DaftarUlangConsultation({ enabled, invoiceNumber }: {
    /** Render + fetch only once the canonical DU invoice exists. A POST needs
     *  an invoice to attach to (404 without one), so offering the form earlier
     *  would only produce a guaranteed failure. */
    enabled: boolean;
    /** Optional context line; the parent-side payload never carries it. */
    invoiceNumber?: string | null;
}) {
    const sheetRef = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    // useId contains characters that are awkward inside an id fragment, so
    // derive a safe prefix for the label htmlFor / input id pairing.
    const fieldPrefix = `du-cons-${titleId.replace(/[^a-zA-Z0-9_-]/g, '')}`;

    const [rows, setRows] = useState<DuConsultation[]>([]);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    /** Set when the server refuses the whole surface (not LULUS / withdrawn). */
    const [blockedReason, setBlockedReason] = useState<string | null>(null);

    const [sheetOpen, setSheetOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [form, setForm] = useState({ preferred_date: '', preferred_time: '', note: '' });

    // The one open request. `active` comes from the server; `is_active` is the
    // fallback so a stale id can never render a terminal row as open.
    const active = rows.find(row => row.id === activeId && row.is_active)
        ?? rows.find(row => row.is_active)
        ?? null;
    // Terminal rows are immutable audit history: newest first, read-only.
    const history = rows.filter(row => row.is_terminal);

    /** Apply a list payload, keeping the server's ordering intact. */
    const applyList = useCallback((data: DuConsultationListResult) => {
        setRows(data.data ?? []);
        setActiveId(data.active ?? null);
        setLoadError(null);
        setBlockedReason(null);
    }, []);

    /** Pull the gate reason out of a 422 eligibility/withdrawn response. */
    const gateReason = (err: unknown): string | null => {
        const body = (axios.isAxiosError(err) ? err.response?.data : null) as ValidationBody | null;
        return body?.errors?.eligibility?.[0] ?? body?.errors?.withdrawn?.[0] ?? null;
    };

    /** True when the error is the "surface unavailable" gate rather than a bug. */
    const isGateError = (err: unknown): boolean =>
        httpHasFieldError(err, 'eligibility') || httpHasFieldError(err, 'withdrawn');

    /**
     * Refresh the list. Never sends query params: the strict allowlist
     * inspects `$request->keys()`, which includes the query string, so even one
     * param would turn this read into a 422.
     */
    const loadList = useCallback(async (silent = false): Promise<void> => {
        if (!silent) setLoading(true);
        try {
            const { data } = await libAxios.get<DuConsultationListResult>('/api/daftar-ulang/consultations');
            applyList(data);
        } catch (err) {
            if (isGateError(err)) {
                setBlockedReason(gateReason(err));
                setRows([]);
                setActiveId(null);
                return;
            }
            // A failed background refresh must not wipe rows the parent already
            // sees; only the very first load has nothing to fall back on.
            setLoadError(httpErrorMessage(err, 'Riwayat konsultasi gagal dimuat.'));
        } finally {
            if (!silent) setLoading(false);
        }
    }, [applyList]);

    // First load once the DU invoice exists. `alive` guards the setState calls
    // so a parent navigating away mid-flight cannot update an unmounted tree.
    useEffect(() => {
        if (!enabled) return;
        let alive = true;
        const run = async () => {
            setLoading(true);
            try {
                const { data } = await libAxios.get<DuConsultationListResult>('/api/daftar-ulang/consultations');
                if (alive) applyList(data);
            } catch (err) {
                if (!alive) return;
                if (isGateError(err)) {
                    setBlockedReason(gateReason(err));
                    return;
                }
                setLoadError(httpErrorMessage(err, 'Riwayat konsultasi gagal dimuat.'));
            } finally {
                if (alive) setLoading(false);
            }
        };
        void run();
        return () => { alive = false; };
    }, [enabled, applyList]);

    /* ------------------------------------------------------------ sheet --- */

    // Native <dialog> lifecycle, mirroring AdminReportModal: lock body scroll,
    // restore focus to whatever opened it, and never leave it open on unmount.
    useEffect(() => {
        const element = sheetRef.current;
        if (!element || !sheetOpen) return;
        const previous = document.activeElement;
        const overflow = document.body.style.overflow;
        element.showModal();
        document.body.style.overflow = 'hidden';
        return () => {
            element.close();
            document.body.style.overflow = overflow;
            if (previous instanceof HTMLElement) previous.focus();
        };
    }, [sheetOpen]);

    const openSheet = () => {
        setFieldErrors({});
        setForm({ preferred_date: '', preferred_time: '', note: '' });
        setSheetOpen(true);
    };

    /** Client-side mirror of the POST rules, so obvious mistakes never round-trip. */
    const validate = (): FieldErrors => {
        const errors: FieldErrors = {};
        const min = todayLocal();
        if (!form.preferred_date) {
            errors.preferred_date = 'Tanggal kunjungan wajib diisi.';
        } else if (!/^\d{4}-\d{2}-\d{2}$/.test(form.preferred_date)) {
            errors.preferred_date = 'Format tanggal tidak valid.';
        } else if (form.preferred_date < min) {
            // YYYY-MM-DD compares correctly as a plain string.
            errors.preferred_date = 'Tanggal tidak boleh sebelum hari ini.';
        }
        if (!form.preferred_time) {
            errors.preferred_time = 'Jam kunjungan wajib diisi.';
        } else if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(form.preferred_time)) {
            errors.preferred_time = 'Format jam tidak valid (HH:MM).';
        }
        if (form.note.length > NOTE_MAX) {
            errors.note = `Catatan maksimal ${NOTE_MAX} karakter.`;
        }
        return errors;
    };

    const submitRequest = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (submitting) return;

        const errors = validate();
        setFieldErrors(errors);
        if (Object.keys(errors).length > 0) return;

        setSubmitting(true);
        try {
            // Only allowlisted keys are ever sent. `note` is omitted entirely
            // when blank so the server stores NULL rather than an empty string.
            const payload: DuConsultationCreatePayload = {
                preferred_date: form.preferred_date,
                preferred_time: form.preferred_time,
            };
            const note = form.note.trim();
            if (note.length > 0) payload.note = note;

            // 201 = created, 200 = idempotent replay of an already-ACTIVE row.
            // Both are success here; the refreshed list is the source of truth.
            await libAxios.post<DuConsultationMutationResult>('/api/daftar-ulang/consultations', payload);
            setSheetOpen(false);
            await loadList(true);
            void Swal.fire({
                icon: 'success',
                title: 'Permintaan Terkirim',
                html: 'Permintaan konsultasi Anda telah diterima.<br/>'
                    + 'Sekolah akan <b>menentukan jadwal</b> dan mengabari Anda.',
                confirmButtonColor: '#1a5f51',
                confirmButtonText: 'Mengerti',
            });
        } catch (err) {
            const status = axios.isAxiosError(err) ? err.response?.status : undefined;
            const body = (axios.isAxiosError(err) ? err.response?.data : null) as ValidationBody | null;

            // Gate closed under us (e.g. a concurrent withdrawal).
            if (status === 422 && (body?.errors?.eligibility || body?.errors?.withdrawn)) {
                setSheetOpen(false);
                setBlockedReason(body?.errors?.eligibility?.[0] ?? body?.errors?.withdrawn?.[0] ?? null);
                return;
            }
            // Field validation: show it inline next to the offending input.
            if (status === 422 && body?.errors) {
                setFieldErrors({
                    preferred_date: body.errors.preferred_date?.[0],
                    preferred_time: body.errors.preferred_time?.[0],
                    note: body.errors.note?.[0],
                    general: body.errors.input?.[0],
                });
                return;
            }
            // Lost the race against another open request. Nothing was created,
            // so resync rather than retry.
            if (status === 409) {
                setSheetOpen(false);
                await loadList(true);
                void Swal.fire({
                    icon: 'info',
                    title: 'Sudah Ada Permintaan Aktif',
                    text: body?.message,
                    confirmButtonColor: '#1a5f51',
                    confirmButtonText: 'Mengerti',
                });
                return;
            }
            setFieldErrors({ general: httpErrorMessage(err, 'Permintaan konsultasi gagal dikirim.') });
        } finally {
            setSubmitting(false);
        }
    };

    /* ----------------------------------------------------------- cancel --- */

    const cancelActive = useCallback(async (target: DuConsultation): Promise<void> => {
        // Belt-and-braces alongside the button's `disabled={cancelling}`: the
        // confirmation dialog is async, so a second confirm must not double-post.
        if (cancelling) return;
        setCancelling(true);
        try {
            // Empty body by contract: the strict allowlist permits no keys here.
            await libAxios.post<DuConsultationMutationResult>(
                `/api/daftar-ulang/consultations/${target.id}/cancel`,
                {}
            );
            await loadList(true);
            void Swal.fire({
                icon: 'success',
                title: 'Permintaan Dibatalkan',
                text: 'Permintaan konsultasi Anda telah dibatalkan.',
                confirmButtonColor: '#1a5f51',
                confirmButtonText: 'Mengerti',
            });
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 409) {
                // Already resolved by staff (or already terminal): resync and
                // show the authoritative state instead of a misleading error.
                await loadList(true);
                void Swal.fire({
                    icon: 'info',
                    title: 'Status Berubah',
                    text: (err.response?.data as ValidationBody | null)?.message,
                    confirmButtonColor: '#1a5f51',
                    confirmButtonText: 'Mengerti',
                });
                return;
            }
            void Swal.fire({
                icon: 'error',
                title: 'Gagal Membatalkan',
                text: httpErrorMessage(err, 'Permintaan konsultasi gagal dibatalkan.'),
                confirmButtonColor: '#1a5f51',
                confirmButtonText: 'Mengerti',
            });
        } finally {
            setCancelling(false);
        }
    }, [cancelling, loadList]);

    /** Destructive and not reversible by the parent, so it gets the same
     *  explicit confirmation dialog the withdrawal action already uses. */
    const confirmCancel = (target: DuConsultation) => {
        Swal.fire({
            icon: 'warning',
            title: 'Batalkan Permintaan?',
            html: 'Permintaan konsultasi ini akan <b>ditutup</b>.<br/>'
                + 'Anda masih dapat mengajukan permintaan baru setelahnya.',
            showCancelButton: true,
            confirmButtonColor: '#e53e3e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Batalkan',
            cancelButtonText: 'Tidak',
            focusCancel: true,
        }).then((result) => {
            if (result.isConfirmed) void cancelActive(target);
        });
    };

    if (!enabled) return null;

    /* ------------------------------------------------------------ view --- */

    // Plain render helpers rather than nested components: a component defined
    // inside this body would remount on every render and steal focus from the
    // sheet's inputs mid-typing.
    const badge = (status: DuConsultationStatus) => (
        <span className={`${styles.duBadge} ${BADGE_CLASS[status]}`}>{STATUS_LABEL[status]}</span>
    );

    const meta = (label: string, value: string | null) => (
        value ? (
            <div className={styles.duMetaRow}>
                <span className={styles.duMetaKey}>{label}</span>
                <span className={styles.duMetaValue}>{value}</span>
            </div>
        ) : null
    );

    /** Read-only detail rows shared by the open card and the history rows. */
    const details = (row: DuConsultation, showResolved: boolean) => (
        <div className={styles.duCardMeta}>
            {meta(
                'Permintaan',
                `${longDate(row.request.preferred_date)} · ${row.request.preferred_time ?? '—'}`
            )}

            {/* Only present once staff confirms; the request window itself is
                frozen and never rewritten by a reschedule. */}
            {meta(
                'Jadwal',
                row.schedule.scheduled_date
                    ? `${longDate(row.schedule.scheduled_date)} · ${row.schedule.scheduled_time ?? '—'}`
                    : null
            )}

            {meta('Diajukan', stamp(row.created_at))}
            {showResolved ? meta('Ditutup', stamp(row.resolved_at)) : null}

            {row.request.note ? (
                <blockquote className={styles.duQuote}>
                    <span className={styles.duQuoteLabel}>Catatan Anda</span>
                    {row.request.note}
                </blockquote>
            ) : null}

            {/* The admin reply is visually distinct so it is never mistaken for
                the parent's own request. */}
            {row.schedule.admin_note ? (
                <blockquote className={`${styles.duQuote} ${styles.duQuoteAdmin}`}>
                    <span className={styles.duQuoteLabel}>Catatan Sekolah</span>
                    {row.schedule.admin_note}
                </blockquote>
            ) : null}
        </div>
    );

    return (
        <section className={styles.duSection}>
            <h3 className={styles.duSectionTitle}>Konsultasi ke Sekolah</h3>

            {/* The whole surface is closed by the server gate: say so plainly
                instead of offering a form that can only fail. */}
            {blockedReason ? (
                <div className={styles.duNotice}>
                    <strong>Tidak Tersedia</strong>
                    {blockedReason}
                </div>
            ) : (
                <>
                    {loadError ? (
                        <div className={styles.duError}>
                            <span>{loadError}</span>
                            <button
                                type="button"
                                className={styles.duBtnGhost}
                                style={{ marginTop: '10px' }}
                                disabled={loading}
                                onClick={() => void loadList()}
                            >
                                {loading ? 'Memuat…' : 'Coba Lagi'}
                            </button>
                        </div>
                    ) : active ? (
                        <div className={`${styles.duCard} ${styles.duCardActive}`}>
                            <div className={styles.duCardHeader}>
                                <span className={styles.duCardTitle}>Permintaan Konsultasi</span>
                                {badge(active.status)}
                            </div>
                            {details(active, false)}
                            {/* REQUESTED still waits on staff; SCHEDULED means the
                                school already set the meeting. */}
                            <p className={styles.duHint}>
                                {active.status === 'SCHEDULED'
                                    ? 'Sekolah telah menentukan jadwal di atas. Mohon hadir sesuai jadwal.'
                                    : 'Sekolah akan mengonfirmasi jadwal kunjungan Anda.'}
                            </p>
                            <div className={styles.duActions}>
                                <button
                                    type="button"
                                    className={styles.duBtnDangerGhost}
                                    disabled={cancelling}
                                    onClick={() => confirmCancel(active)}
                                >
                                    {cancelling ? 'Memproses…' : 'Batalkan Permintaan'}
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className={styles.duForm}>
                            <p className={styles.duIntro}>
                                Perlu berdiskusi langsung dengan pihak sekolah sebelum memutuskan?
                                Ajukan permintaan kunjungan{invoiceNumber ? ` untuk faktur ${invoiceNumber}` : ''},
                                lalu sekolah akan menentukan jadwalnya.
                            </p>
                            <button type="button" className={styles.duBtnPrimary} onClick={openSheet}>
                                Ajukan Permintaan Konsultasi
                            </button>
                        </div>
                    )}

                    {history.length > 0 && (
                        <>
                            <h4 className={styles.duHistoryTitle}>Riwayat</h4>
                            <div className={styles.duHistory}>
                                {history.map(row => (
                                    <div key={row.id} className={`${styles.duCard} ${styles.duCardMuted}`}>
                                        <div className={styles.duCardHeader}>
                                            <span className={styles.duCardTitle}>Konsultasi</span>
                                            {badge(row.status)}
                                        </div>
                                        {details(row, true)}
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}

            {/* Request sheet */}
            <dialog
                ref={sheetRef}
                className={styles.duSheet}
                aria-labelledby={titleId}
                onClose={() => setSheetOpen(false)}
                onKeyDown={e => {
                    // Keep Tab inside the sheet, per the existing modal convention.
                    if (e.key !== 'Tab') return;
                    const controls = Array.from(
                        e.currentTarget.querySelectorAll<HTMLElement>(
                            'button:not(:disabled), input:not(:disabled), textarea:not(:disabled), [tabindex="0"]'
                        )
                    );
                    const first = controls[0];
                    const last = controls[controls.length - 1];
                    if (first && ((e.shiftKey && document.activeElement === first)
                        || (!e.shiftKey && document.activeElement === last))) {
                        e.preventDefault();
                        (e.shiftKey ? last : first).focus();
                    }
                }}
            >
                <div className={styles.duSheetHeader}>
                    <h2 className={styles.duSheetTitle} id={titleId}>Ajukan Konsultasi</h2>
                    <button
                        type="button"
                        className={styles.duCloseBtn}
                        aria-label="Tutup"
                        disabled={submitting}
                        onClick={() => setSheetOpen(false)}
                    >
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{flexShrink: 0}}><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <form className={styles.duForm} onSubmit={submitRequest} noValidate>
                    <p className={styles.duHint}>
                        Ini adalah <strong>permintaan</strong>, bukan jadwal yang sudah pasti.
                        Sekolah akan mengonfirmasi waktu kunjungannya.
                    </p>

                    <div className={styles.duRow}>
                        <div className={styles.duField}>
                            <label className={styles.duLabel} htmlFor={`${fieldPrefix}-date`}>Tanggal Kunjungan</label>
                            <input
                                id={`${fieldPrefix}-date`}
                                type="date"
                                className={`${styles.duInput} ${fieldErrors.preferred_date ? styles.duInputInvalid : ''}`}
                                min={todayLocal()}
                                value={form.preferred_date}
                                disabled={submitting}
                                aria-invalid={!!fieldErrors.preferred_date}
                                onChange={e => setForm(prev => ({ ...prev, preferred_date: e.target.value }))}
                            />
                            {fieldErrors.preferred_date
                                ? <span className={styles.duError}>{fieldErrors.preferred_date}</span>
                                : <span className={styles.duHint}>Pilih hari ini atau setelahnya.</span>}
                        </div>

                        <div className={styles.duField}>
                            <label className={styles.duLabel} htmlFor={`${fieldPrefix}-time`}>Jam Kunjungan</label>
                            <input
                                id={`${fieldPrefix}-time`}
                                type="time"
                                className={`${styles.duInput} ${fieldErrors.preferred_time ? styles.duInputInvalid : ''}`}
                                value={form.preferred_time}
                                disabled={submitting}
                                aria-invalid={!!fieldErrors.preferred_time}
                                onChange={e => setForm(prev => ({ ...prev, preferred_time: e.target.value }))}
                            />
                            {fieldErrors.preferred_time
                                ? <span className={styles.duError}>{fieldErrors.preferred_time}</span>
                                : <span className={styles.duHint}>Format 24 jam.</span>}
                        </div>
                    </div>

                    <div className={styles.duField}>
                        <label className={styles.duLabel} htmlFor={`${fieldPrefix}-note`}>
                            Catatan <span className={styles.duLabelOptional}>(opsional)</span>
                        </label>
                        <textarea
                            id={`${fieldPrefix}-note`}
                            className={`${styles.duInput} ${styles.duTextarea} ${fieldErrors.note ? styles.duInputInvalid : ''}`}
                            rows={4}
                            maxLength={NOTE_MAX}
                            placeholder="Misalnya: ingin bertanya soal cicilan biaya daftar ulang."
                            value={form.note}
                            disabled={submitting}
                            aria-invalid={!!fieldErrors.note}
                            onChange={e => setForm(prev => ({ ...prev, note: e.target.value }))}
                        />
                        <div className={styles.duHintRow}>
                            {fieldErrors.note
                                ? <span className={styles.duError}>{fieldErrors.note}</span>
                                : <span className={styles.duHint}>Bantu sekolah menyiapkan jawaban Anda.</span>}
                            <span className={`${styles.duHint} ${styles.duHintRight}`}>
                                {form.note.length}/{NOTE_MAX}
                            </span>
                        </div>
                    </div>

                    {fieldErrors.general ? (
                        <div className={styles.duError} role="alert">{fieldErrors.general}</div>
                    ) : null}

                    <div className={styles.duActions}>
                        <button type="submit" className={styles.duBtnPrimary} disabled={submitting}>
                            {submitting ? 'Mengirim…' : 'Kirim Permintaan'}
                        </button>
                        <button
                            type="button"
                            className={styles.duBtnGhost}
                            disabled={submitting}
                            onClick={() => setSheetOpen(false)}
                        >
                            Batal
                        </button>
                    </div>
                </form>
            </dialog>
        </section>
    );
}
