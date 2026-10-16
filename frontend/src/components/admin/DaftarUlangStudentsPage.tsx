'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import Swal from 'sweetalert2';
import libAxios from '@/lib/axios';
import type {
    AuthenticatedUser, DuStatus, DuStudentFilters, DuStudentListItem, DuStudentsResponse,
} from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import { JALUR_PENDAFTARAN_OPTIONS, jalurPendaftaranLabel } from '@/lib/jalur-pendaftaran';
import styles from './admin-reports.module.css';

interface PricingItem {
    id: string;
    name: string;
    quantity: number;
    unit_amount: number;
}

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

    // Modal Custom Biaya / Diskon Khusus Siswa
    const [selectedStudent, setSelectedStudent] = useState<DuStudentListItem | null>(null);
    const [pricingModalLoading, setPricingModalLoading] = useState(false);
    const [pricingSubmitting, setPricingSubmitting] = useState(false);
    const [pricingMode, setPricingMode] = useState<'DEFAULT' | 'CUSTOM'>('DEFAULT');
    const [pricingItems, setPricingItems] = useState<PricingItem[]>([]);
    const [discountNominal, setDiscountNominal] = useState<number>(0);
    const [customActive, setCustomActive] = useState<boolean>(true);

    const loadData = (signal?: AbortSignal) => {
        setLoading(true);
        libAxios.get<DuStudentsResponse>('/api/admin/daftar-ulang/students', {
            signal,
            params: {
                status_pembayaran: filters.status_pembayaran || undefined,
                jenjang: filters.jenjang || undefined,
                jalur_pendaftaran: filters.jalur_pendaftaran || undefined,
                search: filters.search || undefined,
                page,
            },
        }).then(({ data }) => setResponse(data))
            .catch((err) => { if (!signal?.aborted) setError(httpErrorMessage(err, 'Data Daftar Ulang gagal dimuat.')); })
            .finally(() => { if (!signal?.aborted) setLoading(false); });
    };

    useEffect(() => {
        libAxios.get<AuthenticatedUser>('/api/user').then(({ data }) => {
            setAccess(data.role === 'admin' || data.role === 'super_admin' ? 'allowed' : 'denied');
        }).catch(() => setAccess('denied'));
    }, []);

    useEffect(() => {
        if (access !== 'allowed') return;
        const controller = new AbortController();
        loadData(controller.signal);
        return () => controller.abort();
    }, [access, filters, page]);

    const change = <K extends keyof DuStudentFilters>(key: K, value: DuStudentFilters[K]) => {
        setLoading(true); setError(''); setPage(1); setFilters(prev => ({ ...prev, [key]: value }));
    };
    const submitSearch = (e: React.FormEvent) => { e.preventDefault(); setLoading(true); setError(''); change('search', searchInput); };

    // Buka Modal Atur Biaya / Diskon Siswa
    const handleOpenPricingModal = async (student: DuStudentListItem) => {
        setSelectedStudent(student);
        setPricingModalLoading(true);
        try {
            const { data } = await libAxios.get<{
                individual: any;
                default_pricing: any;
                jenjang: string;
                student_name: string;
            }>(`/api/admin/daftar-ulang/pricing/individual/${student.id}`);

            if (data.individual && data.individual.mode === 'CUSTOM' && data.individual.revision) {
                setPricingMode('CUSTOM');
                setCustomActive(data.individual.active ?? true);
                setPricingItems(data.individual.revision.items?.map((it: any) => ({
                    id: it.id,
                    name: it.name,
                    quantity: Number(it.quantity) || 1,
                    unit_amount: Number(it.unit_amount) || 0,
                })) || []);
                setDiscountNominal(Number(data.individual.revision.discount_nominal) || 0);
            } else {
                setPricingMode('DEFAULT');
                setCustomActive(true);
                if (data.default_pricing?.items) {
                    setPricingItems(data.default_pricing.items.map((it: any) => ({
                        id: it.id,
                        name: it.name,
                        quantity: Number(it.quantity) || 1,
                        unit_amount: Number(it.unit_amount) || 0,
                    })));
                    setDiscountNominal(Number(data.default_pricing.discount_nominal) || 0);
                } else {
                    setPricingItems([
                        { id: 'biaya_pokok', name: 'Biaya Pokok Daftar Ulang', quantity: 1, unit_amount: Number(student.invoice.subtotal) || 0 }
                    ]);
                    setDiscountNominal(0);
                }
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Memuat Tarif',
                text: httpErrorMessage(err, 'Gagal mengambil detail tarif siswa.'),
                confirmButtonColor: '#1a5f51',
            });
            setSelectedStudent(null);
        } finally {
            setPricingModalLoading(false);
        }
    };

    // Kalkulasi subtotal & total di modal
    const subtotalCustom = pricingItems.reduce((acc, curr) => acc + (curr.quantity * curr.unit_amount), 0);
    const grandTotalCustom = Math.max(0, subtotalCustom - discountNominal);

    // Simpan Pengaturan Biaya Khusus Siswa
    const handleSavePricing = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedStudent) return;

        if (pricingMode === 'CUSTOM') {
            if (pricingItems.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Komponen rincian biaya minimal harus ada 1 item.', confirmButtonColor: '#1a5f51' });
                return;
            }
            if (discountNominal > subtotalCustom) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Nominal diskon tidak boleh melebihi subtotal biaya.', confirmButtonColor: '#1a5f51' });
                return;
            }
        }

        setPricingSubmitting(true);
        try {
            const payload: any = {
                mode: pricingMode,
                active: customActive,
            };

            if (pricingMode === 'CUSTOM') {
                payload.items = pricingItems;
                payload.discount_nominal = discountNominal;
            }

            await libAxios.put(`/api/admin/daftar-ulang/pricing/individual/${selectedStudent.id}`, payload);

            await Swal.fire({
                icon: 'success',
                title: 'Berhasil Disimpan!',
                text: pricingMode === 'CUSTOM'
                    ? `Tarif & potongan khusus untuk ${selectedStudent.name} berhasil diterapkan.`
                    : `Tarif untuk ${selectedStudent.name} dikembalikan ke tarif standar default.`,
                confirmButtonColor: '#1a5f51',
            });

            setSelectedStudent(null);
            loadData();
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Menyimpan',
                text: httpErrorMessage(err, 'Terjadi kendala saat menyimpan rincian tarif.'),
                confirmButtonColor: '#1a5f51',
            });
        } finally {
            setPricingSubmitting(false);
        }
    };

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
                                <tr>
                                    <td><strong>{row.name}</strong></td>
                                    <td>{row.jenjang ?? '—'}</td>
                                    <td>{jalurPendaftaranLabel(row.jalur_pendaftaran)}</td>
                                    <td><span className={`${styles.badge} ${duBadge(row.daftar_ulang.status)}`}>{duLabels[row.daftar_ulang.status] ?? row.daftar_ulang.status}</span></td>
                                    <td>{row.invoice.invoice_number}</td>
                                    <td>{row.payment ? <span className={`${styles.badge} ${payBadge(row.payment.status)}`}>{paymentLabels[row.payment.status] ?? row.payment.status}</span> : '—'}</td>
                                    <td>{amount(row.invoice.total)}</td>
                                    <td>{date(row.updated_at)}</td>
                                    <td>
                                        <div className={styles.btnActionGroup}>
                                            <Link className={styles.outline} href={`/dashboard/daftar-ulang/${row.id}`}>Detail</Link>
                                            <button 
                                                type="button" 
                                                className={styles.btnDiscount} 
                                                title="Atur Diskon / Custom Biaya Siswa"
                                                onClick={() => handleOpenPricingModal(row)}
                                            >
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                                Atur Biaya
                                            </button>
                                        </div>
                                    </td>
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

            {/* MODAL ATUR BIAYA / DISKON KHUSUS SISWA */}
            {selectedStudent && (
                <div style={{
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    backgroundColor: 'rgba(15, 23, 42, 0.65)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 9999,
                    padding: '20px',
                    backdropFilter: 'blur(3px)'
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: '20px',
                        width: '100%',
                        maxWidth: '720px',
                        maxHeight: '90vh',
                        display: 'flex',
                        flexDirection: 'column',
                        boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
                        overflow: 'hidden'
                    }}>
                        {/* Header Modal */}
                        <div style={{
                            padding: '20px 24px',
                            borderBottom: '1px solid #e2e8f0',
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center'
                        }}>
                            <div>
                                <h2 style={{ margin: 0, fontSize: '18px', fontWeight: 800, color: '#1a5f51', display: 'flex', alignItems: 'center', gap: '8px' }}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                    Atur Biaya & Diskon Siswa
                                </h2>
                                <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#64748b' }}>
                                    {selectedStudent.name} ({selectedStudent.jenjang ?? '—'} · {selectedStudent.invoice.invoice_number})
                                </p>
                            </div>
                            <button 
                                type="button" 
                                onClick={() => setSelectedStudent(null)}
                                style={{
                                    background: '#f1f5f9',
                                    border: 'none',
                                    borderRadius: '50%',
                                    width: '32px',
                                    height: '32px',
                                    cursor: 'pointer',
                                    color: '#64748b',
                                    fontSize: '16px',
                                    fontWeight: 'bold',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center'
                                }}
                            >✕</button>
                        </div>

                        {/* Body Modal */}
                        <div style={{ padding: '24px', overflowY: 'auto', flex: 1 }}>
                            {pricingModalLoading ? (
                                <div style={{ textAlign: 'center', padding: '40px', color: '#1a5f51', fontWeight: 700 }}>
                                    Memuat konfigurasi tarif siswa…
                                </div>
                            ) : (
                                <form onSubmit={handleSavePricing}>
                                    {/* Pilihan Mode Skema Tarif */}
                                    <div style={{ marginBottom: '20px' }}>
                                        <label style={{ display: 'block', fontSize: '12px', fontWeight: 700, color: '#475569', marginBottom: '8px', textTransform: 'uppercase' }}>
                                            Skema Tarif Siswa
                                        </label>
                                        <div style={{ display: 'flex', gap: '10px', background: '#f1f5f9', padding: '5px', borderRadius: '12px' }}>
                                            <button
                                                type="button"
                                                onClick={() => setPricingMode('DEFAULT')}
                                                style={{
                                                    flex: 1,
                                                    padding: '10px',
                                                    fontSize: '13px',
                                                    fontWeight: 700,
                                                    border: 'none',
                                                    borderRadius: '8px',
                                                    cursor: 'pointer',
                                                    transition: 'all 0.2s',
                                                    background: pricingMode === 'DEFAULT' ? '#ffffff' : 'transparent',
                                                    color: pricingMode === 'DEFAULT' ? '#1a5f51' : '#64748b',
                                                    boxShadow: pricingMode === 'DEFAULT' ? '0 2px 6px rgba(0,0,0,0.06)' : 'none'
                                                }}
                                            >
                                                Tarif Standar (Default Jenjang)
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setPricingMode('CUSTOM')}
                                                style={{
                                                    flex: 1,
                                                    padding: '10px',
                                                    fontSize: '13px',
                                                    fontWeight: 700,
                                                    border: 'none',
                                                    borderRadius: '8px',
                                                    cursor: 'pointer',
                                                    transition: 'all 0.2s',
                                                    background: pricingMode === 'CUSTOM' ? '#1a5f51' : 'transparent',
                                                    color: pricingMode === 'CUSTOM' ? '#ffffff' : '#64748b',
                                                    boxShadow: pricingMode === 'CUSTOM' ? '0 2px 6px rgba(26,95,81,0.2)' : 'none'
                                                }}
                                            >
                                                Tarif Khusus / Diskon Mandiri
                                            </button>
                                        </div>
                                    </div>

                                    {pricingMode === 'DEFAULT' ? (
                                        <div style={{
                                            background: '#f8fafc',
                                            border: '1px solid #e2e8f0',
                                            borderRadius: '12px',
                                            padding: '16px',
                                            fontSize: '13px',
                                            color: '#475569',
                                            lineHeight: 1.6
                                        }}>
                                            <p style={{ margin: 0, fontWeight: 600 }}>ℹ️ Siswa ini akan menggunakan tarif standar resmi jenjang <b>{selectedStudent.jenjang}</b>.</p>
                                            <p style={{ margin: '6px 0 0', color: '#64748b' }}>
                                                Setiap komponen biaya dan diskon umum yang diatur di menu <i>Biaya & Rincian Daftar Ulang</i> akan otomatis diterapkan ke siswa ini.
                                            </p>
                                        </div>
                                    ) : (
                                        <div>
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
                                                <label style={{ fontSize: '12px', fontWeight: 700, color: '#475569', textTransform: 'uppercase' }}>
                                                    Rincian Komponen Biaya Siswa
                                                </label>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        const id = 'custom_' + Date.now();
                                                        setPricingItems([...pricingItems, { id, name: '', quantity: 1, unit_amount: 0 }]);
                                                    }}
                                                    style={{
                                                        background: '#e6f4ea',
                                                        border: 'none',
                                                        borderRadius: '6px',
                                                        padding: '6px 12px',
                                                        color: '#137333',
                                                        fontSize: '12px',
                                                        fontWeight: 700,
                                                        cursor: 'pointer'
                                                    }}
                                                >
                                                    + Tambah Komponen
                                                </button>
                                            </div>

                                            {/* List Item Biaya */}
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '16px' }}>
                                                {pricingItems.map((item, idx) => (
                                                    <div key={item.id || idx} style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                                                        <input
                                                            type="text"
                                                            placeholder="Nama Biaya (contoh: SPP, Uang Pangkal)"
                                                            value={item.name}
                                                            required
                                                            onChange={(e) => {
                                                                const copy = [...pricingItems];
                                                                copy[idx].name = e.target.value;
                                                                setPricingItems(copy);
                                                            }}
                                                            style={{
                                                                flex: 3,
                                                                padding: '8px 12px',
                                                                borderRadius: '8px',
                                                                border: '1px solid #cbd5e1',
                                                                fontSize: '13px'
                                                            }}
                                                        />
                                                        <input
                                                            type="number"
                                                            placeholder="Qty"
                                                            min={1}
                                                            value={item.quantity}
                                                            required
                                                            onChange={(e) => {
                                                                const copy = [...pricingItems];
                                                                copy[idx].quantity = Math.max(1, parseInt(e.target.value) || 1);
                                                                setPricingItems(copy);
                                                            }}
                                                            style={{
                                                                width: '60px',
                                                                padding: '8px',
                                                                borderRadius: '8px',
                                                                border: '1px solid #cbd5e1',
                                                                fontSize: '13px',
                                                                textAlign: 'center'
                                                            }}
                                                        />
                                                        <input
                                                            type="number"
                                                            placeholder="Biaya Satuan (Rp)"
                                                            min={0}
                                                            step={5000}
                                                            value={item.unit_amount || ''}
                                                            required
                                                            onChange={(e) => {
                                                                const copy = [...pricingItems];
                                                                copy[idx].unit_amount = Math.max(0, parseInt(e.target.value) || 0);
                                                                setPricingItems(copy);
                                                            }}
                                                            style={{
                                                                flex: 2,
                                                                padding: '8px 12px',
                                                                borderRadius: '8px',
                                                                border: '1px solid #cbd5e1',
                                                                fontSize: '13px'
                                                            }}
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setPricingItems(pricingItems.filter((_, i) => i !== idx));
                                                            }}
                                                            disabled={pricingItems.length <= 1}
                                                            style={{
                                                                background: '#fee2e2',
                                                                border: 'none',
                                                                borderRadius: '8px',
                                                                width: '32px',
                                                                height: '32px',
                                                                color: '#ef4444',
                                                                cursor: pricingItems.length <= 1 ? 'not-allowed' : 'pointer',
                                                                fontWeight: 'bold',
                                                                opacity: pricingItems.length <= 1 ? 0.4 : 1
                                                            }}
                                                            title="Hapus baris"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>

                                            {/* Input Potongan / Diskon Khusus */}
                                            <div style={{
                                                background: '#f8fafc',
                                                border: '1px solid #e2e8f0',
                                                borderRadius: '12px',
                                                padding: '16px',
                                                marginBottom: '16px'
                                            }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                                                    <span style={{ fontSize: '13px', fontWeight: 600, color: '#475569' }}>Subtotal Komponen Biaya:</span>
                                                    <span style={{ fontSize: '14px', fontWeight: 700, color: '#1e293b' }}>{amount(subtotalCustom)}</span>
                                                </div>
                                                
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '16px', paddingTop: '10px', borderTop: '1px dashed #e2e8f0' }}>
                                                    <div>
                                                        <label htmlFor="custom-discount" style={{ display: 'block', fontSize: '13px', fontWeight: 700, color: '#0f766e' }}>
                                                            🏷️ Potongan / Diskon Khusus Siswa (Rp):
                                                        </label>
                                                        <small style={{ color: '#64748b', fontSize: '11px' }}>Keringanan, beasiswa yayasan, atau saudara kandung</small>
                                                    </div>
                                                    <input
                                                        id="custom-discount"
                                                        type="number"
                                                        min={0}
                                                        max={subtotalCustom}
                                                        step={10000}
                                                        value={discountNominal || ''}
                                                        onChange={(e) => setDiscountNominal(Math.max(0, parseInt(e.target.value) || 0))}
                                                        placeholder="0"
                                                        style={{
                                                            width: '180px',
                                                            padding: '8px 12px',
                                                            borderRadius: '8px',
                                                            border: '1.5px solid #0d9488',
                                                            fontSize: '14px',
                                                            fontWeight: 700,
                                                            color: '#0f766e',
                                                            textAlign: 'right'
                                                        }}
                                                    />
                                                </div>

                                                <div style={{
                                                    display: 'flex',
                                                    justifyContent: 'space-between',
                                                    alignItems: 'center',
                                                    paddingTop: '12px',
                                                    marginTop: '12px',
                                                    borderTop: '2px solid #cbd5e1'
                                                }}>
                                                    <span style={{ fontSize: '15px', fontWeight: 800, color: '#1a5f51' }}>TOTAL TAGIHAN SISWA:</span>
                                                    <span style={{ fontSize: '18px', fontWeight: 900, color: '#1a5f51' }}>{amount(grandTotalCustom)}</span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Action Buttons */}
                                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px', marginTop: '20px' }}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedStudent(null)}
                                            style={{
                                                padding: '10px 20px',
                                                borderRadius: '10px',
                                                border: '1px solid #cbd5e1',
                                                background: '#ffffff',
                                                color: '#64748b',
                                                fontSize: '13px',
                                                fontWeight: 700,
                                                cursor: 'pointer'
                                            }}
                                        >
                                            Batal
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={pricingSubmitting}
                                            style={{
                                                padding: '10px 24px',
                                                borderRadius: '10px',
                                                border: 'none',
                                                background: '#1a5f51',
                                                color: '#ffffff',
                                                fontSize: '13px',
                                                fontWeight: 800,
                                                cursor: pricingSubmitting ? 'not-allowed' : 'pointer',
                                                boxShadow: '0 4px 12px rgba(26,95,81,0.25)',
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '8px'
                                            }}
                                        >
                                            {pricingSubmitting ? 'Menyimpan…' : 'Simpan Perubahan Biaya'}
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
