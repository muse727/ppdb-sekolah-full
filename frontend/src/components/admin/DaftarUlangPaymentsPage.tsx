'use client';

import { useCallback, useEffect, useState } from 'react';
import Image from 'next/image';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import type { AuthenticatedUser, DuPendingPayment } from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import styles from './admin-reports.module.css';

const paymentLabels: Record<string, string> = {
    PENDING: 'Menunggu Pembayaran', PENDING_MANUAL: 'Menunggu Verifikasi', SUCCESS: 'Sudah Bayar',
    REJECTED: 'Ditolak', FAILED: 'Gagal', EXPIRED: 'Kedaluwarsa',
};
const date = (value: string | null | undefined) => value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';
const amount = (value: number | null | undefined) => value == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);

export default function DaftarUlangPaymentsPage() {
    const [access, setAccess] = useState<'checking' | 'allowed' | 'denied'>('checking');
    const [payments, setPayments] = useState<DuPendingPayment[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [proofUrl, setProofUrl] = useState<string | null>(null);
    const [proofFor, setProofFor] = useState<number | null>(null);
    const [proofLoading, setProofLoading] = useState<number | null>(null);
    const [acting, setActing] = useState<number | null>(null);

    useEffect(() => {
        libAxios.get<AuthenticatedUser>('/api/user').then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => setAccess('denied'));
    }, []);

    const load = useCallback(async () => {
        setLoading(true); setError('');
        try {
            const { data } = await libAxios.get<DuPendingPayment[]>('/api/admin/daftar-ulang/payments/pending');
            setPayments(data);
        } catch (err) {
            setError(httpErrorMessage(err, 'Daftar pembayaran Daftar Ulang gagal dimuat.'));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { if (access === 'allowed') load(); }, [access, load]);

    useEffect(() => () => { if (proofUrl) URL.revokeObjectURL(proofUrl); }, [proofUrl]);

    const openProof = async (payment: DuPendingPayment) => {
        setProofLoading(payment.id);
        try {
            const { data } = await libAxios.get<Blob>(`/api/admin/daftar-ulang/payments/${payment.id}/evidence`, { responseType: 'blob' });
            if (!['image/jpeg', 'image/png'].includes(data.type)) throw new Error('Format bukti tidak didukung.');
            setProofUrl(prev => { if (prev) URL.revokeObjectURL(prev); return URL.createObjectURL(data); });
            setProofFor(payment.id);
        } catch {
            Swal.fire({ icon: 'error', title: 'Bukti tidak tersedia', text: 'Bukti tidak tersedia atau akses ditolak.', confirmButtonColor: '#1a5f51' });
        } finally {
            setProofLoading(null);
        }
    };

    // Verify: never optimistically set SUCCESS; refresh from the server on success.
    const verify = async (payment: DuPendingPayment) => {
        const result = await Swal.fire({
            icon: 'question', title: 'Verifikasi Pembayaran?',
            text: `Verifikasi pembayaran ${payment.user?.name ?? 'siswa'} sebesar ${amount(payment.amount)}?`,
            showCancelButton: true, confirmButtonText: 'Ya, Verifikasi', cancelButtonText: 'Batal',
            confirmButtonColor: '#1a5f51', cancelButtonColor: '#d33',
        });
        if (!result.isConfirmed) return;
        setActing(payment.id); setError('');
        try {
            await libAxios.post(`/api/admin/daftar-ulang/payments/${payment.id}/verify`);
            await Swal.fire({ icon: 'success', title: 'Terverifikasi', text: 'Pembayaran berhasil diverifikasi.', confirmButtonColor: '#1a5f51' });
            await load();
        } catch (err) {
            const status = (err as { response?: { status?: number } })?.response?.status;
            const message = httpErrorMessage(err, 'Verifikasi gagal.');
            if (status === 409) {
                await Swal.fire({ icon: 'warning', title: 'Status Berubah', text: message, confirmButtonColor: '#1a5f51' });
                await load();
            } else {
                await Swal.fire({ icon: 'error', title: 'Gagal', text: message, confirmButtonColor: '#1a5f51' });
            }
        } finally {
            setActing(null);
        }
    };

    // Reject: sends ONLY the fields the backend accepts ({ reason }).
    const reject = async (payment: DuPendingPayment) => {
        const result = await Swal.fire({
            icon: 'warning', title: 'Tolak Pembayaran?', input: 'textarea', inputLabel: 'Alasan penolakan (opsional)',
            showCancelButton: true, confirmButtonText: 'Ya, Tolak', cancelButtonText: 'Batal',
            confirmButtonColor: '#d33', cancelButtonColor: '#1a5f51',
        });
        if (!result.isConfirmed) return;
        setActing(payment.id); setError('');
        try {
            const reason = typeof result.value === 'string' && result.value.trim() !== '' ? { reason: result.value.trim() } : {};
            await libAxios.post(`/api/admin/daftar-ulang/payments/${payment.id}/reject`, reason);
            await Swal.fire({ icon: 'success', title: 'Ditolak', text: 'Pembayaran ditolak.', confirmButtonColor: '#1a5f51' });
            await load();
        } catch (err) {
            const status = (err as { response?: { status?: number } })?.response?.status;
            const message = httpErrorMessage(err, 'Penolakan gagal.');
            if (status === 409) {
                await Swal.fire({ icon: 'warning', title: 'Status Berubah', text: message, confirmButtonColor: '#1a5f51' });
                await load();
            } else {
                await Swal.fire({ icon: 'error', title: 'Gagal', text: message, confirmButtonColor: '#1a5f51' });
            }
        } finally {
            setActing(null);
        }
    };

    if (access === 'checking') return null;
    if (access === 'denied') {
        return <div className={styles.page}><div className={styles.card}><p role="alert" className={styles.error}>Akses ditolak. Halaman ini khusus admin.</p></div></div>;
    }

    return (
        <div className={styles.page}>
            <div className={styles.card}>
                <div className={styles.header}>
                    <div>
                        <h1>Pembayaran Daftar Ulang</h1>
                        <p>Pembayaran Daftar Ulang yang menunggu verifikasi.</p>
                    </div>
                </div>

                {error && <p role="alert" className={styles.error}>{error}</p>}

                <div className={styles.tableWrapper}>
                    <table className={styles.table}>
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Siswa</th>
                                <th>Jenjang</th>
                                <th>Nominal</th>
                                <th>Status Pembayaran</th>
                                <th>Bukti</th>
                                <th>Dikirim</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={8}>Memuat data…</td></tr>}
                            {!loading && !error && payments.length === 0 && <tr><td colSpan={8}>Tidak ada pembayaran Daftar Ulang yang menunggu verifikasi.</td></tr>}
                            {!loading && payments.map((payment) => (
                                <tr key={payment.id}>
                                    <td>{payment.invoice_number ?? '—'}</td>
                                    <td>
                                        <strong>{payment.user?.name ?? '—'}</strong>
                                        {payment.user?.email && <span className={styles.email}>{payment.user.email}</span>}
                                    </td>
                                    <td>{payment.jenjang ?? '—'}</td>
                                    <td>{amount(payment.amount)}</td>
                                    <td><span className={`${styles.badge} ${styles.pending}`}>{paymentLabels[payment.status] ?? payment.status}</span></td>
                                    <td>
                                        {payment.proof_of_payment
                                            ? <button className={styles.outline} disabled={proofLoading === payment.id} onClick={() => openProof(payment)}>{proofLoading === payment.id ? 'Memuat…' : 'Lihat Bukti'}</button>
                                            : '—'}
                                    </td>
                                    <td>{date(payment.created_at)}</td>
                                    <td>
                                        <button className={styles.primary} disabled={acting === payment.id} onClick={() => verify(payment)}>Verifikasi</button>{' '}
                                        <button className={styles.outline} disabled={acting === payment.id} onClick={() => reject(payment)}>Tolak</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {proofUrl && proofFor !== null && (
                    <section aria-label="Bukti Pembayaran">
                        <h2>Bukti Pembayaran</h2>
                        <Image src={proofUrl} alt={`Bukti pembayaran ${payments.find(p => p.id === proofFor)?.user?.name ?? ''}`} width={1000} height={1000} unoptimized className={styles.proof} />
                    </section>
                )}
            </div>
        </div>
    );
}
