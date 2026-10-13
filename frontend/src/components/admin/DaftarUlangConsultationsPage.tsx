'use client';

// src/components/admin/DaftarUlangConsultationsPage.tsx

import { useCallback, useEffect, useState, type FormEvent } from 'react';
import libAxios from '@/lib/axios';
import axios from 'axios';
import Swal from 'sweetalert2';
import type {
    AuthenticatedUser,
    DuAdminConsultationFilters,
    DuAdminConsultationStatusFilter,
    DuAdminConsultationsResponse,
    DuConsultation,
    DuConsultationResolveAction,
    DuConsultationScheduleResult,
    DuConsultationStatus,
} from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import { JALUR_PENDAFTARAN_OPTIONS, jalurPendaftaranLabel } from '@/lib/jalur-pendaftaran';
import AdminReportModal from './AdminReportModal';
import styles from './admin-reports.module.css';

/* ==========================================================================
   B.7.4-D — ADMIN CONSULTATION DESK ("KONSULTASI KE SEKOLAH")

   The staff side of the parent consultation surface. It is a desk, not a
   form: the ONLY mutations it can ever issue are schedule (which doubles as
   reschedule) and the two terminal resolutions. It never touches payments,
   agreements, quota, seats, status_kelulusan, status_siswa or continued_at —
   those live in other flows by design.

   Contract rules this component is built around (all verified against the
   live backend code):
   - GET  /api/admin/daftar-ulang/consultations
         -> { data, summary, meta }
         params allowlisted by the controller: status, jenjang,
         jalur_pendaftaran, search, page, per_page. Empty filter values are
         OMITTED, not sent as empty strings: the strict allowlist inspects
         $request->keys() which includes query params.
   - POST /api/admin/daftar-ulang/consultations/{id}/schedule
         -> { consultation, rescheduled }
         body allowlist: scheduled_date, scheduled_time, admin_note.
   - POST /api/admin/daftar-ulang/consultations/{id}/resolve
         -> { consultation }
         body allowlist: action (complete|cancel), admin_note. There is NO
         /complete or /cancel endpoint — the single resolve endpoint IS the
         terminal path, and the server translates the action itself.
   - Business edges (state machine): REQUESTED -> SCHEDULED | CANCELLED;
     SCHEDULED -> SCHEDULED (reschedule) | COMPLETED | CANCELLED;
     COMPLETED / CANCELLED are terminal and read-only. REQUESTED -> COMPLETED
     is refused with 409 ("nothing can be completed before it is scheduled").
   - There is NO per-id admin detail endpoint: the detail modal renders the
     row straight from the list (the admin present() shape is identical to
     the parent's, plus `participant`).
   - 409/403 domain conflicts arrive as { message, reason_code } and are
     handled by an AUTOMATIC RESYNC (the payments-page pattern): reload the
     list so the table shows the authoritative state, then inform the admin.
   - 422 arrives as Laravel ValidationException: field errors map inline in
     the schedule form; the allowlist guard answers errors.input.
   - History stays visible: the list endpoint returns terminal rows too, and
     this desk renders them read-only even if the parent has withdrawn or the
     student is already TERDAFTAR.
   ========================================================================== */

const statusLabels: Record<DuConsultationStatus, string> = {
    REQUESTED: 'Menunggu Konfirmasi',
    SCHEDULED: 'Terjadwal',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
};

const statusBadgeClass: Record<DuConsultationStatus, string> = {
    REQUESTED: styles.pending,
    SCHEDULED: styles.success,
    COMPLETED: styles.badge,
    CANCELLED: styles.rejected,
};

/** Tab vocabulary: the four literal statuses plus the OPEN alias the backend
 *  expands to REQUESTED|SCHEDULED server-side. '' means "semua status". */
type StatusTab = '' | DuAdminConsultationStatusFilter;

const statusTabs: { value: StatusTab; label: string }[] = [
    { value: '', label: 'Semua Status' },
    { value: 'REQUESTED', label: 'Menunggu Konfirmasi' },
    { value: 'SCHEDULED', label: 'Terjadwal' },
    { value: 'COMPLETED', label: 'Selesai' },
    { value: 'CANCELLED', label: 'Dibatalkan' },
];

const emptyFilters = { status: '', jenjang: '', jalur_pendaftaran: '', search: '' } as const;

/** ISO or "YYYY-MM-DD HH:MM:SS" stamp -> id-ID locale. */
const stamp = (value: string | null | undefined): string =>
    value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';

/** "YYYY-MM-DD" -> short id-ID date, parsed as LOCAL midnight so a WIB date
 *  never shifts a day the way bare new Date('YYYY-MM-DD') (UTC) does. */
const shortDate = (value: string | null | undefined): string => {
    if (!value) return '—';
    const parsed = new Date(`${value}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

/** Today in LOCAL YYYY-MM-DD, for the schedule date input's `min` (mirrors
 *  the backend's after_or_equal:today). */
const todayLocal = (): string => {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');
    const day = `${now.getDate()}`.padStart(2, '0');
    return `${now.getFullYear()}-${month}-${day}`;
};

const NOTE_MAX = 1000;

/** Server 422 field errors, mirrored per schedule-form input. */
type ScheduleFieldErrors = {
    scheduled_date?: string;
    scheduled_time?: string;
    admin_note?: string;
    general?: string;
};

/** 422 body shape as emitted by ValidationException / the allowlist guard. */
type ValidationBody = {
    message?: string;
    errors?: Record<string, string[]>;
};

/** Seed the schedule form from the row: an SCHEDULED row gets its current
 *  window (a reschedule starts from the agreed values), anything else starts
 *  from the parent's REQUESTED window as a suggestion. */
const seedScheduleForm = (row: DuConsultation) => {
    if (row.status === 'SCHEDULED') {
        return {
            scheduled_date: row.schedule.scheduled_date ?? '',
            scheduled_time: row.schedule.scheduled_time ?? '',
            admin_note: row.schedule.admin_note ?? '',
        };
    }
    return {
        scheduled_date: row.request.preferred_date ?? '',
        scheduled_time: row.request.preferred_time ?? '',
        admin_note: row.schedule.admin_note ?? '',
    };
};

export default function DaftarUlangConsultationsPage() {
    const [access, setAccess] = useState<'checking' | 'allowed' | 'denied'>('checking');
    const [filters, setFilters] = useState<DuAdminConsultationFilters>(emptyFilters);
    const [searchInput, setSearchInput] = useState('');
    const [page, setPage] = useState(1);
    const [response, setResponse] = useState<DuAdminConsultationsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    /** Detail modal (read-only view of a list row; no per-id endpoint exists). */
    const [detailRow, setDetailRow] = useState<DuConsultation | null>(null);
    const [detailTrigger, setDetailTrigger] = useState<HTMLElement | null>(null);

    /** Schedule / reschedule modal. */
    const [scheduleRow, setScheduleRow] = useState<DuConsultation | null>(null);
    const [scheduleTrigger, setScheduleTrigger] = useState<HTMLElement | null>(null);
    const [scheduleForm, setScheduleForm] = useState({ scheduled_date: '', scheduled_time: '', admin_note: '' });
    const [scheduleErrors, setScheduleErrors] = useState<ScheduleFieldErrors>({});
    const [submitting, setSubmitting] = useState(false);

    /** Row id with an in-flight resolve, to disable its action buttons. */
    const [acting, setActing] = useState<number | null>(null);

    /* ------------------------------------------------------------------
     * B.7.4-E.2 (F-1) — stale-snapshot reconciliation. detailRow and
     * scheduleRow are snapshots of a LIST response; another admin (or the
     * parent) can move the consultation while a modal is open. There is no
     * per-id detail endpoint, so the list is the only authoritative view:
     * every successful list load reconciles the open modals against it.
     *   - row gone from the (current-filter) result  -> close the modal and
     *     explain, so a deleted/no-longer-visible record is never shown as
     *     live data. NOTE: the list is filtered, so "gone" may also mean the
     *     row simply left the current tab — the message says "changed or no
     *     longer available", never "deleted".
     *   - row present but now terminal -> the schedule form is closed (those
     *     rows are read-only); the detail modal keeps showing the FRESH row.
     *   - row present and still actionable -> the modals are updated to the
     *     fresh object so they never render an obsolete snapshot.
     * The schedule FORM INPUT is preserved across non-conflicting refreshes;
     * it is only reset when the row itself changed status, because the
     * seeded window is status-dependent.
     * This does not eliminate the race between refresh and submit: the
     * backend state machine remains authoritative and its 409/403 responses
     * still end in a full resync below.
     */
    const reconcileRow = useCallback(
        (current: DuConsultation | null, setCurrent: (row: DuConsultation | null) => void, isSchedule: boolean): boolean => {
            if (!current) return true;
            const fresh = response?.data.find(row => row.id === current.id) ?? null;
            if (!fresh) {
                setCurrent(null);
                void Swal.fire({
                    icon: 'info',
                    title: 'Data Berubah',
                    text: 'Data konsultasi ini telah berubah atau tidak lagi tersedia. Daftar dimuat ulang.',
                    confirmButtonColor: '#1a5f51',
                });
                return false;
            }
            if (isSchedule && fresh.is_terminal) {
                // Terminal rows are read-only: close the mutation form, keep
                // the fresh detail visible in the table only.
                setCurrent(null);
                void Swal.fire({
                    icon: 'info',
                    title: 'Status Konsultasi Berubah',
                    text: `Konsultasi ini sekarang berstatus "${statusLabels[fresh.status]}" dan hanya dapat dilihat.`,
                    confirmButtonColor: '#1a5f51',
                });
                return false;
            }
            if (fresh !== current) {
                setCurrent(fresh);
                // A status change invalidates the seeded schedule window
                // (REQUESTED seeds from the parent's request, SCHEDULED from
                // the agreed window), so reseed the form from the fresh row.
                if (isSchedule && fresh.status !== current.status) {
                    setScheduleForm(seedScheduleForm(fresh));
                }
            }
            return true;
        },
        [response],
    );

    useEffect(() => {
        if (!response) return;
        reconcileRow(detailRow, setDetailRow, false);
        reconcileRow(scheduleRow, setScheduleRow, true);
        // Deliberately keyed on `response` only: reconcile on every NEW list
        // result, not on every reconciliation (which would risk loops).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [response]);

    // PART 2 continues below (data loading, actions, render).

    // ------------------------------------------------------------------
    // Data loading

    useEffect(() => {
        const controller = new AbortController();
        libAxios.get<AuthenticatedUser>('/api/user', { signal: controller.signal }).then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => { if (!controller.signal.aborted) setAccess('denied'); });
        return () => controller.abort();
    }, []);

    /** Reload the list with the CURRENT filters/page. Called on mount, on
     *  filter change and after every mutation (and on every 409: the server is
     *  authoritative, so a conflict always ends in a resync). */
    const load = useCallback(async (signal?: AbortSignal) => {
        setLoading(true); setError('');
        try {
            const { data } = await libAxios.get<DuAdminConsultationsResponse>(
                '/api/admin/daftar-ulang/consultations',
                {
                    signal,
                    // Empty values are OMITTED: the controller allowlist inspects
                    // query keys, so an empty-but-present param is indistinguishable
                    // from a disallowed field to the strict guard.
                    params: {
                        status: filters.status || undefined,
                        jenjang: filters.jenjang || undefined,
                        jalur_pendaftaran: filters.jalur_pendaftaran || undefined,
                        search: filters.search || undefined,
                        page: page > 1 ? page : undefined,
                    },
                },
            );
            setResponse(data);
        } catch (err) {
            if (!signal?.aborted) {
                setResponse(null);
                setError(httpErrorMessage(err, 'Daftar konsultasi gagal dimuat. Silakan coba kembali.'));
            }
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, [filters, page]);

    useEffect(() => {
        if (access !== 'allowed') return;
        const controller = new AbortController();
        void load(controller.signal);
        return () => controller.abort();
    }, [access, load]);

    // Debounce the free-text search exactly like the report pages do.
    useEffect(() => {
        const timer = setTimeout(() => {
            setFilters(prev => (prev.search === searchInput ? prev : { ...prev, search: searchInput }));
            setPage(1);
        }, 250);
        return () => clearTimeout(timer);
    }, [searchInput]);

    /** Change a filter and reset pagination (a narrowed set may be shorter). */
    const changeFilter = (key: 'status' | 'jenjang' | 'jalur_pendaftaran', value: string) => {
        setPage(1);
        if (key === 'status') {
            setFilters(prev => ({ ...prev, status: value as DuAdminConsultationFilters['status'] }));
            return;
        }
        if (key === 'jenjang') {
            if (value === '' || value === 'SD' || value === 'SMP') {
                setFilters(prev => ({ ...prev, jenjang: value as DuAdminConsultationFilters['jenjang'] }));
            }
            return;
        }
        const option = JALUR_PENDAFTARAN_OPTIONS.find(o => o.value === value);
        setFilters(prev => ({ ...prev, jalur_pendaftaran: option?.value ?? '' }));
    };

    // ------------------------------------------------------------------
    // Mutations

    /** Client-side mirror of the server rules, so obvious mistakes never
     *  leave the browser. The server remains authoritative either way. */
    const validateSchedule = (): ScheduleFieldErrors => {
        const errors: ScheduleFieldErrors = {};
        const min = todayLocal();
        if (!scheduleForm.scheduled_date) {
            errors.scheduled_date = 'Tanggal konsultasi wajib diisi.';
        } else if (!/^\d{4}-\d{2}-\d{2}$/.test(scheduleForm.scheduled_date)) {
            errors.scheduled_date = 'Format tanggal tidak valid.';
        } else if (scheduleForm.scheduled_date < min) {
            errors.scheduled_date = 'Tanggal tidak boleh sebelum hari ini.';
        }
        if (!scheduleForm.scheduled_time) {
            errors.scheduled_time = 'Jam konsultasi wajib diisi.';
        } else if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(scheduleForm.scheduled_time)) {
            errors.scheduled_time = 'Format jam tidak valid (HH:MM).';
        }
        if (scheduleForm.admin_note.length > NOTE_MAX) {
            errors.admin_note = `Catatan maksimal ${NOTE_MAX} karakter.`;
        }
        return errors;
    };

    const openSchedule = (row: DuConsultation, trigger: HTMLElement) => {
        setScheduleErrors({});
        setScheduleForm(seedScheduleForm(row));
        setScheduleTrigger(trigger);
        setScheduleRow(row);
        // F-1: the row came from the list the admin is looking at, which may
        // be seconds old. Refresh authoritatively; the reconcile effect will
        // close/reset this modal if the row moved. No spinner: the modal is
        // already usable from the snapshot in the common case.
        void load();
    };

    const submitSchedule = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!scheduleRow || submitting) return;

        // F-1: never submit against an obsolete snapshot. The reconcile
        // effect normally replaces scheduleRow with the newest list row, but
        // if a NEWER response exists that no longer contains this row (or
        // shows it terminal), stop here instead of firing a doomed mutation.
        const latest = response?.data.find(row => row.id === scheduleRow.id) ?? null;
        if (!latest || latest.is_terminal) {
            setScheduleRow(null);
            await load();
            void Swal.fire({
                icon: 'info',
                title: 'Status Konsultasi Berubah',
                text: 'Data konsultasi ini telah berubah atau tidak lagi tersedia. Silakan periksa daftar terbaru.',
                confirmButtonColor: '#1a5f51',
            });
            return;
        }

        const errors = validateSchedule();
        setScheduleErrors(errors);
        if (Object.keys(errors).length > 0) return;

        setSubmitting(true);
        try {
            const payload = {
                scheduled_date: scheduleForm.scheduled_date,
                scheduled_time: scheduleForm.scheduled_time,
                // Blank note is OMITTED, not sent as '': the server preserves the
                // stored note when the key is absent (admin_note is sticky).
                ...(scheduleForm.admin_note.trim().length > 0 ? { admin_note: scheduleForm.admin_note.trim() } : {}),
            };
            const { data } = await libAxios.post<DuConsultationScheduleResult>(
                `/api/admin/daftar-ulang/consultations/${scheduleRow.id}/schedule`,
                payload,
            );
            setScheduleRow(null);
            await load();
            void Swal.fire({
                icon: 'success',
                title: data.rescheduled ? 'Jadwal Diperbarui' : 'Konsultasi Dijadwalkan',
                text: data.rescheduled
                    ? 'Jadwal konsultasi berhasil diubah (reschedule).'
                    : 'Konsultasi berhasil dijadwalkan.',
                confirmButtonColor: '#1a5f51',
            });
        } catch (err) {
            const status = axios.isAxiosError(err) ? err.response?.status : undefined;
            const body = (axios.isAxiosError(err) ? err.response?.data : null) as ValidationBody | null;
            if (status === 422 && body?.errors) {
                // Field validation (or the allowlist guard via errors.input).
                setScheduleErrors({
                    scheduled_date: body.errors.scheduled_date?.[0],
                    scheduled_time: body.errors.scheduled_time?.[0],
                    admin_note: body.errors.admin_note?.[0],
                    general: body.errors.input?.[0],
                });
            } else if (status === 409) {
                // Concurrent resolution (or a terminal row): resync so the table
                // shows the authoritative state, then explain what happened.
                setScheduleRow(null);
                await load();
                void Swal.fire({
                    icon: 'info',
                    title: 'Status Konsultasi Berubah',
                    text: body?.message ?? httpErrorMessage(err, 'Status konsultasi sudah berubah; data dimuat ulang.'),
                    confirmButtonColor: '#1a5f51',
                });
            } else {
                setScheduleErrors({ general: httpErrorMessage(err, 'Penjadwalan gagal. Silakan coba kembali.') });
            }
        } finally {
            setSubmitting(false);
        }
    };

    /** Resolve (complete | cancel). The client names an ACTION, never a status;
     *  the server translates it and owns every transition rule. */
    const resolve = async (row: DuConsultation, action: DuConsultationResolveAction, trigger: HTMLElement) => {
        const target = action === 'complete' ? 'Selesaikan' : 'Batalkan';
        const result = await Swal.fire({
            icon: 'question',
            title: `${target} Konsultasi?`,
            html: `${target} konsultasi <b>${row.participant?.name ?? 'siswa'}</b>?`
                + (action === 'complete' ? '<br/>Status akan menjadi <b>Selesai</b>.' : '<br/>Status akan menjadi <b>Dibatalkan</b>.'),
            input: 'textarea',
            inputLabel: 'Catatan admin (opsional)',
            showCancelButton: true,
            confirmButtonText: `Ya, ${target}`,
            cancelButtonText: 'Batal',
            confirmButtonColor: action === 'complete' ? '#1a5f51' : '#d33',
            cancelButtonColor: '#1a5f51',
        });
        if (!result.isConfirmed) return;

        setActing(row.id); setError('');
        try {
            const note = (result.value ?? '').toString().trim();
            const payload = {
                action,
                // Blank note is omitted: the server keeps the stored admin_note.
                ...(note.length > 0 ? { admin_note: note } : {}),
            };
            await libAxios.post(`/api/admin/daftar-ulang/consultations/${row.id}/resolve`, payload);
            await Swal.fire({
                icon: 'success',
                title: action === 'complete' ? 'Konsultasi Selesai' : 'Konsultasi Dibatalkan',
                confirmButtonColor: '#1a5f51',
            });
            // Close any open modal for this row, then resync from the server.
            if (detailRow?.id === row.id) setDetailRow(null);
            await load();
        } catch (err) {
            const status = axios.isAxiosError(err) ? err.response?.status : undefined;
            const message = httpErrorMessage(err, 'Aksi gagal. Silakan coba kembali.');
            if (status === 409) {
                // The row moved under us (e.g. parent cancelled it, or another
                // admin resolved it first). Resync, then show the real state.
                if (detailRow?.id === row.id) setDetailRow(null);
                await load();
                void Swal.fire({
                    icon: 'info',
                    title: 'Status Berubah',
                    text: message,
                    confirmButtonColor: '#1a5f51',
                });
            } else {
                // 422 / 403 / network: keep the row visible, surface the message.
                void Swal.fire({ icon: 'error', title: 'Gagal', text: message, confirmButtonColor: '#1a5f51' });
            }
        } finally {
            setActing(null);
            // Return focus to the trigger button for keyboard users.
            if (document.body.contains(trigger)) trigger.focus();
        }
    };

    // ------------------------------------------------------------------
    // Render

    if (access === 'checking') return null;
    if (access === 'denied') {
        return <div className={styles.page}><div className={styles.card}><p role="alert" className={styles.error}>Akses ditolak. Halaman ini khusus admin.</p></div></div>;
    }

    const summary = response?.summary;
    const rows = response?.data ?? [];
    const participant = (row: DuConsultation) => ({
        name: row.participant?.name ?? '—',
        invoice: row.participant?.invoice_number ?? '—',
        jenjang: row.participant?.jenjang ?? '—',
        jalur: row.participant?.jalur_pendaftaran
            ? jalurPendaftaranLabel(row.participant.jalur_pendaftaran as never)
            : '—',
    });

    return (
        <div className={styles.page}>
            <section className={styles.card}>
                <header className={styles.header}>
                    <div>
                        <h1>Konsultasi Daftar Ulang</h1>
                        <p>Permintaan &ldquo;Konsultasi ke Sekolah&rdquo; peserta Daftar Ulang. Permintaan hanya bisa dijadwalkan, dijadwalkan ulang, diselesaikan, atau dibatalkan; tidak mengubah pembayaran maupun status siswa.</p>
                    </div>
                </header>

                {/* Summary: the server computes it against the CURRENT filter set
                    minus `status`, so it always shows the whole distribution. */}
                {summary && (
                    <dl className={styles.summary}>
                        <div><dt>Menunggu Konfirmasi</dt><dd>{summary.requested}</dd></div>
                        <div><dt>Terjadwal</dt><dd>{summary.scheduled}</dd></div>
                        <div><dt>Selesai</dt><dd>{summary.completed}</dd></div>
                        <div><dt>Dibatalkan</dt><dd>{summary.cancelled}</dd></div>
                        <div><dt>Berjalan</dt><dd>{summary.open}</dd></div>
                    </dl>
                )}

                <div className={styles.tabs} aria-label="Filter status konsultasi">
                    {statusTabs.map(tab => (
                        <button key={tab.value || 'all'} type="button" className={styles.tab} aria-pressed={filters.status === tab.value} onClick={() => changeFilter('status', tab.value)}>{tab.label}</button>
                    ))}
                </div>

                <div className={styles.filters}>
                    <label className={styles.field}>Jenjang
                        <select value={filters.jenjang} onChange={e => changeFilter('jenjang', e.target.value)}>
                            <option value="">Semua Jenjang</option>
                            <option value="SD">SD</option>
                            <option value="SMP">SMP</option>
                        </select>
                    </label>
                    <label className={styles.field}>Jalur Pendaftaran
                        <select value={filters.jalur_pendaftaran} onChange={e => changeFilter('jalur_pendaftaran', e.target.value)}>
                            <option value="">Semua Jalur</option>
                            {JALUR_PENDAFTARAN_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </label>
                    <label className={styles.field}>Cari nama siswa atau nomor faktur
                        <input type="search" maxLength={100} value={searchInput} onChange={e => setSearchInput(e.target.value)} placeholder="Nama siswa atau nomor faktur" />
                    </label>
                </div>

                {error && <p role="alert" className={styles.error}>{error}</p>}

                <div className={styles.tableWrapper} aria-busy={loading}>
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                <th scope="col">Siswa</th>
                                <th scope="col">Jenjang</th>
                                <th scope="col">Jalur</th>
                                <th scope="col">Diminta</th>
                                <th scope="col">Jadwal Disepakati</th>
                                <th scope="col">Status</th>
                                <th scope="col">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={7}>Memuat data…</td></tr>}
                            {!loading && !error && rows.length === 0 && (
                                <tr><td colSpan={7}>Tidak ada permintaan konsultasi{filters.status || filters.jenjang || filters.jalur_pendaftaran || filters.search ? ' yang cocok dengan filter' : ''}.</td></tr>
                            )}
                            {!loading && rows.map(row => {
                                const info = participant(row);
                                return (
                                    <tr key={row.id}>
                                        <td>
                                            <strong>{info.name}</strong>
                                            {row.participant?.invoice_number && <span className={styles.email}>{row.participant.invoice_number}</span>}
                                        </td>
                                        <td>{info.jenjang}</td>
                                        <td>{info.jalur}</td>
                                        <td>
                                            {shortDate(row.request.preferred_date)}{row.request.preferred_time ? ` · ${row.request.preferred_time}` : ''}
                                            {row.request.note && <span className={styles.email}>{row.request.note}</span>}
                                        </td>
                                        <td>
                                            {row.schedule.scheduled_date
                                                ? `${shortDate(row.schedule.scheduled_date)}${row.schedule.scheduled_time ? ` · ${row.schedule.scheduled_time}` : ''}`
                                                : '—'}
                                            {row.schedule.admin_note && <span className={styles.email}>{row.schedule.admin_note}</span>}
                                        </td>
                                        <td><span className={`${styles.badge} ${statusBadgeClass[row.status]}`}>{statusLabels[row.status]}</span></td>
                                        <td>
                                            <button type="button" className={styles.outline} onClick={e => { setDetailTrigger(e.currentTarget); setDetailRow(row); void load(); }}>Detail</button>
                                            {row.is_terminal ? null : (
                                                <>
                                                    {' '}
                                                    <button type="button" className={styles.primary} disabled={acting === row.id} onClick={e => openSchedule(row, e.currentTarget)}>
                                                        {row.status === 'SCHEDULED' ? 'Jadwalkan Ulang' : 'Jadwalkan'}
                                                    </button>
                                                    {' '}
                                                    {row.status === 'SCHEDULED' && (
                                                        <button type="button" className={styles.primary} disabled={acting === row.id} onClick={e => { void resolve(row, 'complete', e.currentTarget); }}>Selesaikan</button>
                                                    )}
                                                    {' '}
                                                    <button type="button" className={styles.outline} disabled={acting === row.id} onClick={e => { void resolve(row, 'cancel', e.currentTarget); }}>Batalkan</button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {response && response.meta.last_page > 1 && (
                    <footer className={styles.pagination}>
                        <span role="status">{response.meta.total} konsultasi · Halaman {response.meta.current_page} dari {response.meta.last_page}</span>
                        <button type="button" className={styles.outline} disabled={loading || page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}>Sebelumnya</button>
                        <button type="button" className={styles.outline} disabled={loading || page >= response.meta.last_page} onClick={() => setPage(p => p + 1)}>Berikutnya</button>
                    </footer>
                )}
            </section>

            {detailRow && (
                <AdminReportModal title="Detail Konsultasi" onClose={() => setDetailRow(null)} returnFocusTo={detailTrigger}>
                    <dl className={styles.details}>
                        <dt>Nama Siswa</dt><dd>{participant(detailRow).name}</dd>
                        <dt>Nomor Faktur</dt><dd>{participant(detailRow).invoice}</dd>
                        <dt>Jenjang</dt><dd>{participant(detailRow).jenjang}</dd>
                        <dt>Jalur Pendaftaran</dt><dd>{participant(detailRow).jalur}</dd>
                        <dt>Status Kelulusan</dt><dd>{detailRow.participant?.status_kelulusan === 'LULUS' ? 'Lulus' : detailRow.participant?.status_kelulusan === 'TIDAK_LULUS' ? 'Tidak Lulus' : '—'}</dd>
                        <dt>Status Konsultasi</dt><dd><span className={`${styles.badge} ${statusBadgeClass[detailRow.status]}`}>{statusLabels[detailRow.status]}</span></dd>
                        <dt>Tanggal Diminta</dt><dd>{shortDate(detailRow.request.preferred_date)}{detailRow.request.preferred_time ? ` · ${detailRow.request.preferred_time}` : ''}</dd>
                        <dt>Catatan Orang Tua</dt><dd>{detailRow.request.note ?? '—'}</dd>
                        <dt>Jadwal Disepakati</dt><dd>{detailRow.schedule.scheduled_date ? `${shortDate(detailRow.schedule.scheduled_date)}${detailRow.schedule.scheduled_time ? ` · ${detailRow.schedule.scheduled_time}` : ''}` : '—'}</dd>
                        <dt>Catatan Admin</dt><dd>{detailRow.schedule.admin_note ?? '—'}</dd>
                        <dt>Waktu Selesai/Dibatalkan</dt><dd>{stamp(detailRow.resolved_at)}</dd>
                        <dt>Dibuat</dt><dd>{stamp(detailRow.created_at)}</dd>
                    </dl>
                </AdminReportModal>
            )}

            {scheduleRow && (
                <AdminReportModal title={scheduleRow.status === 'SCHEDULED' ? 'Jadwalkan Ulang Konsultasi' : 'Jadwalkan Konsultasi'} onClose={() => setScheduleRow(null)} returnFocusTo={scheduleTrigger}>
                    <form onSubmit={submitSchedule} noValidate>
                        <p>
                            Untuk <b>{participant(scheduleRow).name}</b> ({participant(scheduleRow).invoice}).
                            Permintaan orang tua: <b>{shortDate(scheduleRow.request.preferred_date)}{scheduleRow.request.preferred_time ? ` · ${scheduleRow.request.preferred_time}` : ''}</b>
                            {scheduleRow.request.note ? <> — &ldquo;{scheduleRow.request.note}&rdquo;</> : null}.
                        </p>
                        {scheduleErrors.general && <p role="alert" className={styles.error}>{scheduleErrors.general}</p>}
                        <div className={styles.filters}>
                            <label className={styles.field}>Tanggal Konsultasi
                                <input type="date" min={todayLocal()} value={scheduleForm.scheduled_date} onChange={e => setScheduleForm(prev => ({ ...prev, scheduled_date: e.target.value }))} required />
                                {scheduleErrors.scheduled_date && <span role="alert" className={styles.error}>{scheduleErrors.scheduled_date}</span>}
                            </label>
                            <label className={styles.field}>Jam Konsultasi
                                <input type="time" value={scheduleForm.scheduled_time} onChange={e => setScheduleForm(prev => ({ ...prev, scheduled_time: e.target.value }))} required />
                                {scheduleErrors.scheduled_time && <span role="alert" className={styles.error}>{scheduleErrors.scheduled_time}</span>}
                            </label>
                        </div>
                        <label className={styles.field}>Catatan Admin (opsional, maks {NOTE_MAX} karakter)
                            <textarea rows={3} maxLength={NOTE_MAX} value={scheduleForm.admin_note} onChange={e => setScheduleForm(prev => ({ ...prev, admin_note: e.target.value }))} placeholder="Contoh: datang ke ruang TU, bawa bukti pembayaran" />
                            {scheduleErrors.admin_note && <span role="alert" className={styles.error}>{scheduleErrors.admin_note}</span>}
                        </label>
                        <button type="submit" className={styles.primary} disabled={submitting}>{submitting ? 'Menyimpan…' : 'Simpan Jadwal'}</button>
                    </form>
                </AdminReportModal>
            )}
        </div>
    );
}
