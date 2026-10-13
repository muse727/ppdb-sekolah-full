'use client';

import { useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import libAxios from '@/lib/axios';
import type { AdminReportDetail, AdminReportFilters, AdminReportResponse, AdminReportRow, AuthenticatedUser, ReportKind, ReportPaymentStatus, ReportStatusFilter } from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import { JALUR_PENDAFTARAN_OPTIONS, jalurPendaftaranLabel } from '@/lib/jalur-pendaftaran';
import { downloadAdminReport } from '@/lib/admin-report-download';
import AdminReportModal from './AdminReportModal';
import styles from './admin-reports.module.css';

const paymentLabels: Record<ReportPaymentStatus, string> = {
    PENDING_MANUAL: 'Menunggu Verifikasi', SUCCESS: 'Sudah Bayar', REJECTED: 'Ditolak',
    FAILED: 'Gagal', EXPIRED: 'Kedaluwarsa', PENDING: 'Menunggu Pembayaran',
};
const emptyFilters: AdminReportFilters = { status: '', jenjang: '', jalur_pendaftaran: '', search: '' };
const date = (value: string | null | undefined) => value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';
const amount = (value: number | null | undefined) => value == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const graduation = (value: AdminReportRow['status_kelulusan']) => value === 'LULUS' ? 'Lulus' : value === 'TIDAK_LULUS' ? 'Tidak Lulus' : 'Belum Final';

export default function AdminReportPage({ kind }: { kind: ReportKind }) {
    const isPayment = kind === 'pembayaran';
    const title = isPayment ? 'Data Pembayaran' : 'Data Kelulusan';
    const [access, setAccess] = useState<'checking' | 'allowed' | 'denied'>('checking');
    const [filters, setFilters] = useState<AdminReportFilters>(emptyFilters);
    const [page, setPage] = useState(1);
    const [response, setResponse] = useState<AdminReportResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [exporting, setExporting] = useState(false);
    const [detail, setDetail] = useState<AdminReportDetail | null>(null);
    const [detailTrigger, setDetailTrigger] = useState<HTMLElement | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [proofUrl, setProofUrl] = useState<string | null>(null);
    const [proofLoading, setProofLoading] = useState(false);
    const [proofError, setProofError] = useState('');
    const requests = useRef({ detail: 0, proof: 0 });

    useEffect(() => {
        const controller = new AbortController();
        libAxios.get<AuthenticatedUser>('/api/user', { signal: controller.signal }).then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => { if (!controller.signal.aborted) setAccess('denied'); });
        const requestState = requests.current;
        return () => { controller.abort(); requestState.detail++; requestState.proof++; };
    }, []);

    useEffect(() => {
        if (access !== 'allowed') return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true); setError('');
            libAxios.get<AdminReportResponse>(`/api/admin/data-${kind}`, { params: { ...filters, page }, signal: controller.signal })
                .then(({ data }) => setResponse(data))
                .catch(err => { if (!controller.signal.aborted) { setResponse(null); setError(httpErrorMessage(err, 'Data gagal dimuat. Silakan coba kembali.')); } })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [access, filters, kind, page]);

    useEffect(() => () => { if (proofUrl) URL.revokeObjectURL(proofUrl); }, [proofUrl]);

    const change = <K extends keyof AdminReportFilters>(key: K, value: AdminReportFilters[K]) => {
        setLoading(true); setPage(1); setFilters(prev => ({ ...prev, [key]: value }));
    };
    const closeDetail = () => {
        requests.current.detail++; requests.current.proof++;
        setDetail(null); setDetailTrigger(null); setDetailLoading(false); setProofLoading(false); setProofUrl(null); setProofError('');
    };
    const openDetail = async (row: AdminReportRow, trigger: HTMLButtonElement) => {
        const request = ++requests.current.detail;
        setDetailTrigger(trigger);
        setDetailLoading(true); setError('');
        try {
            const { data } = await libAxios.get<AdminReportDetail>(`/api/admin/data-${kind}/${row.id}`);
            if (request === requests.current.detail) setDetail(data);
        } catch (err) { if (request === requests.current.detail) setError(httpErrorMessage(err, 'Detail gagal dimuat.')); }
        finally { if (request === requests.current.detail) setDetailLoading(false); }
    };
    const openProof = async () => {
        if (!detail?.payment) return;
        const request = ++requests.current.proof;
        setProofLoading(true); setProofError('');
        try {
            const { data } = await libAxios.get<Blob>(`/api/admin/data-pembayaran/${detail.id}/payments/${detail.payment.id}/proof`, { responseType: 'blob' });
            if (!['image/jpeg', 'image/png'].includes(data.type)) throw new Error('Format bukti tidak didukung.');
            if (request === requests.current.proof) setProofUrl(URL.createObjectURL(data));
        } catch { if (request === requests.current.proof) setProofError('Bukti tidak tersedia atau akses ditolak.'); }
        finally { if (request === requests.current.proof) setProofLoading(false); }
    };
    const exportData = async () => {
        setExporting(true); setError('');
        try { await downloadAdminReport(kind, filters); }
        catch { setError('Export gagal. Silakan coba kembali.'); }
        finally { setExporting(false); }
    };
    const statuses: { value: ReportStatusFilter; label: string }[] = isPayment
        ? [{ value: '', label: 'Semua' }, { value: 'NO_PAYMENT', label: 'Belum Bayar' }, ...(response?.payment_statuses ?? ['PENDING_MANUAL', 'SUCCESS', 'REJECTED']).map(value => ({ value, label: paymentLabels[value] }))]
        : [{ value: '', label: 'Semua' }, { value: 'LULUS', label: 'Lulus' }, { value: 'TIDAK_LULUS', label: 'Tidak Lulus' }];
    const badge = (row: AdminReportRow) => {
        const state = isPayment ? row.payment?.status : row.status_kelulusan;
        const color = state === 'SUCCESS' || state === 'LULUS' ? styles.success : state === 'REJECTED' || state === 'TIDAK_LULUS' ? styles.rejected : state === 'PENDING_MANUAL' ? styles.pending : '';
        return <span className={`${styles.badge} ${color}`}>{isPayment ? row.payment_label : graduation(row.status_kelulusan)}</span>;
    };

    if (access !== 'allowed') return <div className={styles.page} role="status">{access === 'checking' ? 'Memverifikasi akses…' : 'Akses ditolak. Halaman ini khusus admin.'}</div>;
    return (
        <div className={styles.page}>
            <section className={styles.card}>
                <header className={styles.header}>
                    <div><h1>{title}</h1><p>{isPayment ? 'Status pembayaran terbaru seluruh pendaftar.' : 'Data peserta dengan hasil seleksi yang sudah ditetapkan.'}</p></div>
                    <button className={styles.primary} onClick={exportData} disabled={exporting}>{exporting ? 'Mengekspor…' : 'Export Data'}</button>
                </header>
                <div className={styles.filters}>
                    {isPayment ? (
                        <label className={styles.field}>Status Pembayaran<select value={filters.status} aria-label="Filter status pembayaran" onChange={e => { const option = statuses.find(s => s.value === e.target.value); change('status', option?.value ?? ''); }}>{statuses.map(status => <option key={status.value || 'all'} value={status.value}>{status.value === '' ? 'Semua Status' : status.label}</option>)}</select></label>
                    ) : (
                        <div className={styles.tabs} aria-label="Filter status">
                            {statuses.map(status => <button key={status.value} className={styles.tab} aria-pressed={filters.status === status.value} onClick={() => change('status', status.value)}>{status.label}</button>)}
                        </div>
                    )}
                    <label className={styles.field}>Jenjang<select value={filters.jenjang} onChange={e => { const value = e.target.value; if (value === '' || value === 'SD' || value === 'SMP') change('jenjang', value); }}><option value="">Semua Jenjang</option><option value="SD">SD</option><option value="SMP">SMP</option></select></label>
                    <label className={styles.field}>Jalur Pendaftaran<select value={filters.jalur_pendaftaran} onChange={e => { const option = JALUR_PENDAFTARAN_OPTIONS.find(o => o.value === e.target.value); change('jalur_pendaftaran', option?.value ?? ''); }}><option value="">Semua Jalur</option>{JALUR_PENDAFTARAN_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select></label>
                    <label className={styles.field}>Cari nama atau email<input type="search" maxLength={255} value={filters.search} onChange={e => change('search', e.target.value)} placeholder="Nama atau email" /></label>
                </div>
                {error && <p role="alert" className={styles.error}>{error}</p>}
                <div className={styles.tableWrapper} aria-busy={loading}>
                    <table className={styles.table}>
                        <thead><tr><th scope="col">Nama Peserta</th><th scope="col">Jenjang</th><th scope="col">Jalur Pendaftaran</th><th scope="col">{isPayment ? 'Status Pembayaran' : 'Status Kelulusan'}</th>{isPayment && <><th scope="col">Nominal</th><th scope="col">Tanggal Pencatatan</th><th scope="col">Bukti</th></>}<th scope="col">Aksi</th></tr></thead>
                        <tbody>
                            {loading ? <tr><td colSpan={isPayment ? 8 : 5}>Memuat data…</td></tr> : !response?.data.length ? <tr><td colSpan={isPayment ? 8 : 5}>Tidak ada data sesuai filter.</td></tr> : response.data.map(row => (
                                <tr key={row.id}><td>{row.name}<span className={styles.email}>{row.email}</span></td><td>{row.jenjang ?? '—'}</td><td>{jalurPendaftaranLabel(row.jalur_pendaftaran)}</td><td>{badge(row)}</td>{isPayment && <><td>{amount(row.payment?.amount)}</td><td>{date(row.payment?.created_at)}</td><td>{row.payment?.has_proof ? 'Ada' : 'Tidak Ada'}</td></>}<td><button className={styles.outline} aria-label={`Detail ${row.name}`} onClick={e => openDetail(row, e.currentTarget)} disabled={detailLoading}>Detail</button></td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <footer className={styles.pagination}><span role="status">{response?.meta.total ?? 0} peserta · Halaman {page} / {response?.meta.last_page ?? 1}</span><button className={styles.outline} disabled={loading || page <= 1} onClick={() => { setLoading(true); setPage(p => p - 1); }}>Sebelumnya</button><button className={styles.outline} disabled={loading || !response || page >= response.meta.last_page} onClick={() => { setLoading(true); setPage(p => p + 1); }}>Berikutnya</button></footer>
            </section>
            {detail && <AdminReportModal title={isPayment ? 'Detail Pembayaran' : 'Detail Kelulusan'} onClose={closeDetail} returnFocusTo={detailTrigger}>
                <div className={styles.modalBody}>
                    <dl className={styles.details}>
                        <div><dt>Nama Peserta</dt><dd>{detail.name}</dd></div><div><dt>Nama Akun</dt><dd>{detail.account_name}</dd></div><div><dt>Email</dt><dd>{detail.email}</dd></div><div><dt>Jenjang</dt><dd>{detail.jenjang ?? '—'}</dd></div><div><dt>Jalur Pendaftaran</dt><dd>{jalurPendaftaranLabel(detail.jalur_pendaftaran)}</dd></div><div><dt>Status Kelulusan</dt><dd>{graduation(detail.status_kelulusan)}</dd></div><div><dt>Asal Sekolah</dt><dd>{detail.biodata.asal_sekolah ?? '—'}</dd></div><div><dt>Nomor Referensi</dt><dd>{detail.payment?.invoice_number ?? '—'}</dd></div><div><dt>Nominal</dt><dd>{amount(detail.payment?.amount)}</dd></div><div><dt>Tanggal Pencatatan</dt><dd>{date(detail.payment?.created_at)}</dd></div><div><dt>Tanggal Review</dt><dd>{date(detail.payment?.verified_at)}</dd></div><div><dt>Status Pembayaran</dt><dd><span className={`${styles.badge} ${detail.payment?.status === 'SUCCESS' ? styles.success : detail.payment?.status === 'REJECTED' || detail.payment?.status === 'FAILED' || detail.payment?.status === 'EXPIRED' ? styles.rejected : detail.payment?.status === 'PENDING_MANUAL' || detail.payment?.status === 'PENDING' ? styles.pending : ''}`}>{detail.payment_label}</span></dd></div>
                        {detail.payment?.rejection_reason && <div><dt>Alasan Penolakan</dt><dd>{detail.payment.rejection_reason}</dd></div>}
                    </dl>
                    {isPayment && (
                        <div className={styles.modalActions}>
                            {detail.payment?.has_proof ? <button className={styles.primary} disabled={proofLoading || !!proofUrl} onClick={openProof}>{proofLoading ? 'Memuat bukti…' : 'Lihat Bukti Pembayaran'}</button> : <p className={styles.noProof}>Bukti pembayaran tidak tersedia.</p>}
                        </div>
                    )}
                    {proofError && <p role="alert" className={styles.error}>{proofError}</p>}
                </div>
                {proofUrl && <section aria-label="Bukti Pembayaran"><h3>Bukti Pembayaran</h3><Image src={proofUrl} alt={`Bukti pembayaran ${detail.name}`} width={1000} height={1000} unoptimized className={styles.proof} /></section>}
            </AdminReportModal>}
        </div>
    );
}
