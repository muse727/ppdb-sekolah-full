'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import libAxios from '@/lib/axios';
import type {
    AuthenticatedUser, DuStatus, DuStudentFilters, DuStudentListItem, DuStudentsResponse,
} from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import { JALUR_PENDAFTARAN_OPTIONS, jalurPendaftaranLabel } from '@/lib/jalur-pendaftaran';
import styles from './admin-reports.module.css';

// Daftar Ulang status is a domain concept kept distinct from payment status.
const duLabels: Record<DuStatus, string> = {
    MENUNGGU_PEMBAYARAN: 'Menunggu Pembayaran',
    MENUNGGU_VERIFIKASI: 'Menunggu Verifikasi',
    LUNAS: 'Lunas',
    MENGUNDURKAN_DIRI: 'Mengundurkan Diri',
};
const paymentLabels: Record<string, string> = {
    PENDING: 'Menunggu Pembayaran', PENDING_MANUAL: 'Menunggu Verifikasi', SUCCESS: 'Sudah Bayar',
    REJECTED: 'Ditolak', FAILED: 'Gagal', EXPIRED: 'Kedaluwarsa',
};
const emptyFilters: DuStudentFilters = { status_pembayaran: '', jenjang: '', jalur_pendaftaran: '', search: '' };
const date = (value: string | null | undefined) => value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';
const amount = (value: number | null | undefined) => value == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);

export default function DaftarUlangStudentsPage() {
    const [access, setAccess] = useState<'checking' | 'allowed' | 'denied'>('checking');
    const [filters, setFilters] = useState<DuStudentFilters>(emptyFilters);
    const [searchInput, setSearchInput] = useState('');
    const [page, setPage] = useState(1);
    const [response, setResponse] = useState<DuStudentsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        libAxios.get<AuthenticatedUser>('/api/user').then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => setAccess('denied'));
    }, []);

    useEffect(() => {
        if (access !== 'allowed') return;
        const controller = new AbortController();
        libAxios.get<DuStudentsResponse>('/api/admin/daftar-ulang/students', {
            signal: controller.signal,
            params: {
                status_pembayaran: filters.status_pembayaran || undefined,
                jenjang: filters.jenjang || undefined,
                jalur_pendaftaran: filters.jalur_pendaftaran || undefined,
                search: filters.search || undefined,
                page,
            },
        }).then(({ data }) => setResponse(data))
            .catch((err) => { if (!controller.signal.aborted) setError(httpErrorMessage(err, 'Data Daftar Ulang gagal dimuat.')); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [access, filters, page]);

    const change = <K extends keyof DuStudentFilters>(key: K, value: DuStudentFilters[K]) => {
        setLoading(true); setError(''); setPage(1); setFilters(prev => ({ ...prev, [key]: value }));
    };
    const submitSearch = (e: React.FormEvent) => { e.preventDefault(); setLoading(true); setError(''); change('search', searchInput); };

    if (access === 'checking') return null;
    if (access === 'denied') {
        return <div className={styles.page}><div className={styles.card}><p role="alert" className={styles.error}>Akses ditolak. Halaman ini khusus admin.</p></div></div>;
    }

    const rows = response?.data ?? [];
    const duBadge = (status: DuStatus) => status === 'LUNAS' ? styles.success : status === 'MENGUNDURKAN_DIRI' ? styles.rejected : styles.pending;
    const payBadge = (status: string | undefined) => status === 'SUCCESS' ? styles.success : status === 'REJECTED' || status === 'FAILED' ? styles.rejected : styles.pending;

    return (
        <div className={styles.page}>
            <div className={styles.card}>
                <div className={styles.header}>
                    <div>
                        <h1>Siswa Daftar Ulang</h1>
                        <p>Daftar siswa yang telah masuk alur Daftar Ulang (memiliki invoice).</p>
                    </div>
                </div>

                <form className={styles.filters} onSubmit={submitSearch}>
                    <div className={styles.field}>
                        <label htmlFor="du-search">Cari</label>
                        <input id="du-search" type="search" value={searchInput} onChange={e => setSearchInput(e.target.value)} placeholder="Nama / No. Invoice" />
                    </div>
                    <div className={styles.field}>
                        <label htmlFor="du-jenjang">Jenjang</label>
                        <select id="du-jenjang" value={filters.jenjang} onChange={e => change('jenjang', e.target.value as DuStudentFilters['jenjang'])}>
                            <option value="">Semua</option>
                            <option value="SD">SD</option>
                            <option value="SMP">SMP</option>
                        </select>
                    </div>
                    <div className={styles.field}>
                        <label htmlFor="du-jalur">Jalur</label>
                        <select id="du-jalur" value={filters.jalur_pendaftaran} onChange={e => change('jalur_pendaftaran', e.target.value as DuStudentFilters['jalur_pendaftaran'])}>
                            <option value="">Semua</option>
                            {JALUR_PENDAFTARAN_OPTIONS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </div>
                    <div className={styles.field}>
                        <label htmlFor="du-status">Status Pembayaran</label>
                        <select id="du-status" value={filters.status_pembayaran} onChange={e => change('status_pembayaran', e.target.value as DuStudentFilters['status_pembayaran'])}>
                            <option value="">Semua</option>
                            {Object.entries(paymentLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </div>
                    <button type="submit" className={styles.outline}>Terapkan</button>
                </form>

                {error && <p role="alert" className={styles.error}>{error}</p>}

                <div className={styles.tableWrapper}>
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                <th>Siswa</th>
                                <th>Jenjang</th>
                                <th>Jalur</th>
                                <th>Status Daftar Ulang</th>
                                <th>Invoice</th>
                                <th>Status Pembayaran</th>
                                <th>Total</th>
                                <th>Diperbarui</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={9}>Memuat data…</td></tr>}
                            {!loading && !error && rows.length === 0 && <tr><td colSpan={9}>Belum ada siswa Daftar Ulang.</td></tr>}
                            {!loading && rows.map((row: DuStudentListItem) => (
                                <tr key={row.id}>
                                    <td><strong>{row.name}</strong></td>
                                    <td>{row.jenjang ?? '—'}</td>
                                    <td>{jalurPendaftaranLabel(row.jalur_pendaftaran)}</td>
                                    <td><span className={`${styles.badge} ${duBadge(row.daftar_ulang.status)}`}>{duLabels[row.daftar_ulang.status] ?? row.daftar_ulang.status}</span></td>
                                    <td>{row.invoice.invoice_number}</td>
                                    <td>{row.payment ? <span className={`${styles.badge} ${payBadge(row.payment.status)}`}>{paymentLabels[row.payment.status] ?? row.payment.status}</span> : '—'}</td>
                                    <td>{amount(row.invoice.total)}</td>
                                    <td>{date(row.updated_at)}</td>
                                    <td><Link className={styles.outline} href={`/dashboard/daftar-ulang/${row.id}`}>Detail</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {response && response.meta.last_page > 1 && (
                    <div className={styles.pagination}>
                        <button className={styles.outline} disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}>Sebelumnya</button>
                        <span>Halaman {response.meta.current_page} dari {response.meta.last_page}</span>
                        <button className={styles.outline} disabled={page >= response.meta.last_page} onClick={() => setPage(p => p + 1)}>Berikutnya</button>
                    </div>
                )}
            </div>
        </div>
    );
}
