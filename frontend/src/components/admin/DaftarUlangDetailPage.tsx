'use client';

import { useEffect, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import libAxios from '@/lib/axios';
import type { AuthenticatedUser, DuStatus, DuStudentDetail } from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import { jalurPendaftaranLabel } from '@/lib/jalur-pendaftaran';
import styles from './admin-reports.module.css';

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
const graduation = (value: string | null | undefined) => value === 'LULUS' ? 'Lulus' : value === 'TIDAK_LULUS' ? 'Tidak Lulus' : 'Belum Final';
const date = (value: string | null | undefined) => value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';
const amount = (value: number | null | undefined) => value == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);

export default function DaftarUlangDetailPage({ id }: { id: number }) {
    const [access, setAccess] = useState<'checking' | 'allowed' | 'denied'>('checking');
    const [detail, setDetail] = useState<DuStudentDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notFound, setNotFound] = useState(false);
    const [proofUrl, setProofUrl] = useState<string | null>(null);
    const [proofLoading, setProofLoading] = useState(false);
    const [proofError, setProofError] = useState('');

    useEffect(() => {
        libAxios.get<AuthenticatedUser>('/api/user').then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => setAccess('denied'));
    }, []);

    useEffect(() => {
        if (access !== 'allowed') return;
        const controller = new AbortController();
        setLoading(true); setError(''); setNotFound(false);
        libAxios.get<DuStudentDetail>(`/api/admin/daftar-ulang/students/${id}`, { signal: controller.signal })
            .then(({ data }) => setDetail(data))
            .catch((err) => {
                if (controller.signal.aborted) return;
                const status = (err as { response?: { status?: number } })?.response?.status;
                if (status === 404) setNotFound(true);
                else setError(httpErrorMessage(err, 'Detail Daftar Ulang gagal dimuat.'));
            })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [access, id]);

    useEffect(() => () => { if (proofUrl) URL.revokeObjectURL(proofUrl); }, [proofUrl]);

    const openProof = async () => {
        if (!detail?.payment) return;
        setProofLoading(true); setProofError('');
        try {
            const { data } = await libAxios.get<Blob>(`/api/admin/daftar-ulang/payments/${detail.payment.id}/evidence`, { responseType: 'blob' });
            if (!['image/jpeg', 'image/png'].includes(data.type)) throw new Error('Format bukti tidak didukung.');
            setProofUrl(prev => { if (prev) URL.revokeObjectURL(prev); return URL.createObjectURL(data); });
        } catch { setProofError('Bukti tidak tersedia atau akses ditolak.'); }
        finally { setProofLoading(false); }
    };

    if (access === 'checking') return null;
    if (access === 'denied') {
        return <div className={styles.page}><div className={styles.card}><p role="alert" className={styles.error}>Akses ditolak. Halaman ini khusus admin.</p></div></div>;
    }

    const invoice = detail?.invoice;
    const payment = detail?.payment;
    const items = invoice?.items ?? [];

    return (
        <div className={styles.page}>
            <div className={styles.card}>
                <div className={styles.header}>
                    <div>
                        <h1>Detail Daftar Ulang</h1>
                        <p>{detail ? detail.name : ''}</p>
                    </div>
                    <Link className={styles.outline} href="/dashboard/daftar-ulang">Kembali</Link>
                </div>

                {loading && <p>Memuat detail…</p>}
                {notFound && <p role="alert" className={styles.error}>Data Daftar Ulang tidak ditemukan.</p>}
                {error && <p role="alert" className={styles.error}>{error}</p>}

                {!loading && detail && invoice && (
                    <>
                        <h2>Identitas</h2>
                        <dl className={styles.details}>
                            <dt>Nama Siswa</dt><dd>{detail.name}</dd>
                            <dt>Jenjang</dt><dd>{detail.jenjang ?? '—'}</dd>
                            <dt>Jalur Pendaftaran</dt><dd>{jalurPendaftaranLabel(detail.jalur_pendaftaran)}</dd>
                            <dt>Status Kelulusan</dt><dd>{graduation(detail.status_kelulusan)}</dd>
                        </dl>

                        <h2>Daftar Ulang</h2>
                        <dl className={styles.details}>
                            <dt>Status Daftar Ulang</dt><dd>{duLabels[detail.daftar_ulang.status] ?? detail.daftar_ulang.status}</dd>
                            <dt>Persetujuan</dt><dd>{detail.daftar_ulang.agreement_accepted ? 'Sudah Menyetujui' : 'Belum Menyetujui'}</dd>
                            <dt>Versi Persetujuan</dt><dd>{detail.daftar_ulang.agreement_version ?? '—'}</dd>
                            <dt>Disetujui Pada</dt><dd>{date(detail.daftar_ulang.accepted_at)}</dd>
                        </dl>

                        <h2>Invoice</h2>
                        <dl className={styles.details}>
                            <dt>Nomor Invoice</dt><dd>{invoice.invoice_number}</dd>
                            <dt>Tanggal Dibuat</dt><dd>{date(invoice.created_at)}</dd>
                            <dt>Sumber Harga</dt><dd>{invoice.pricing_source ?? '—'}</dd>
                            <dt>Snapshot</dt><dd>{date(invoice.snapshot_at)}</dd>
                        </dl>

                        <div className={styles.tableWrapper}>
                            <table className={styles.table}>
                                <thead>
                                    <tr><th>Item</th><th>Qty</th><th>Harga Satuan</th><th>Total Baris</th></tr>
                                </thead>
                                <tbody>
                                    {items.map((item, index) => (
                                        <tr key={item.id ?? index}>
                                            <td>{item.name ?? item.id ?? '—'}</td>
                                            <td>{item.quantity ?? '—'}</td>
                                            <td>{amount(item.unit_amount)}</td>
                                            <td>{amount(item.line_total ?? (typeof item.unit_amount === 'number' && typeof item.quantity === 'number' ? item.unit_amount * item.quantity : null))}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className={styles.details}>
                            <dt>Subtotal</dt><dd>{amount(invoice.subtotal)}</dd>
                            <dt>Diskon</dt><dd>{amount(invoice.discount_nominal)}</dd>
                            <dt>Total</dt><dd>{amount(invoice.total)}</dd>
                        </dl>

                        <h2>Pembayaran</h2>
                        {payment ? (
                            <>
                                <dl className={styles.details}>
                                    <dt>Kategori</dt><dd>{payment.payment_category}</dd>
                                    <dt>Status</dt><dd>{paymentLabels[payment.status] ?? payment.status}</dd>
                                    <dt>Nominal</dt><dd>{amount(payment.amount)}</dd>
                                    <dt>Dibuat</dt><dd>{date(payment.created_at)}</dd>
                                    <dt>Diverifikasi</dt><dd>{date(payment.verified_at)}</dd>
                                    <dt>Verifikator</dt><dd>{payment.verifier?.name ?? '—'}</dd>
                                    {payment.rejection_reason && <><dt>Alasan Penolakan</dt><dd>{payment.rejection_reason}</dd></>}
                                </dl>
                                {payment.has_proof
                                    ? <button className={styles.primary} disabled={proofLoading} onClick={openProof}>{proofLoading ? 'Memuat bukti…' : 'Lihat Bukti Pembayaran'}</button>
                                    : <p>Bukti pembayaran tidak tersedia.</p>}
                                {proofError && <p role="alert" className={styles.error}>{proofError}</p>}
                                {proofUrl && <section aria-label="Bukti Pembayaran"><h3>Bukti Pembayaran</h3><Image src={proofUrl} alt={`Bukti pembayaran ${detail.name}`} width={1000} height={1000} unoptimized className={styles.proof} /></section>}
                            </>
                        ) : <p>Belum ada pembayaran Daftar Ulang.</p>}
                    </>
                )}
            </div>
        </div>
    );
}
