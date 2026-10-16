// src/app/pendaftar/daftar-ulang/page.tsx
'use client';

import { useState, useEffect, useRef, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import axios from 'axios';
import type { AuthenticatedUser, DuContinuationResult, DuParentInvoice, DuParentPricing, DuWithdrawalResult, OfficialStudentStatus } from '@/lib/domain';
import DaftarUlangConsultation from '@/components/pendaftar/DaftarUlangConsultation';
import styles from '../pembayaran/pembayaran.module.css';

// Server-authoritative flow states (derived from the linked DU payment status).
type ViewState = 'INTRO' | 'AGREEMENT' | 'INVOICE' | 'PENDING_MANUAL' | 'SUCCESS' | 'REJECTED';

const rupiah = (value: number | null | undefined) =>
    value == null ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const date = (value: string | null | undefined) => value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '—';

/** Server-authoritative withdrawal timestamp: the POST /withdraw response marker. */
const withdrawnAt = (inv: DuParentInvoice | null, state: string | null): string | null => state ?? inv?.withdrawn_at ?? null;

export default function DaftarUlangPage() {
    const router = useRouter();

    const [isLoading, setIsLoading] = useState(true);
    const [notEligible, setNotEligible] = useState<string | null>(null);
    const [pricing, setPricing] = useState<DuParentPricing | null>(null);
    const [invoice, setInvoice] = useState<DuParentInvoice | null>(null);
    const [viewState, setViewState] = useState<ViewState>('INTRO');
    const [agreed, setAgreed] = useState(false);
    const [isActing, setIsActing] = useState(false);

    // Upload state (mirrors the Pembayaran page upload pattern).
    const [manualFile, setManualFile] = useState<File | null>(null);
    const [filePreview, setFilePreview] = useState<string | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [isCopied, setIsCopied] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // B.7.3: canonical official-student state (server-owned; NULL until the
    // parent explicitly continues or withdraws).
    const [statusSiswa, setStatusSiswa] = useState<OfficialStudentStatus | null>(null);
    const [continuedAt, setContinuedAt] = useState<string | null>(null);
    const [withdrawnAtState, setWithdrawnAtState] = useState<string | null>(null);
    const [isDeciding, setIsDeciding] = useState(false);

    const applyInvoice = useCallback((inv: DuParentInvoice) => {
        setInvoice(inv);
        const status = inv.payment?.status;
        if (status === 'SUCCESS') setViewState('SUCCESS');
        else if (status === 'PENDING_MANUAL') setViewState('PENDING_MANUAL');
        else if (status === 'REJECTED') setViewState('REJECTED');
        else if (!inv.is_agreement_accepted) setViewState('AGREEMENT');
        else setViewState('INVOICE');
    }, []);

    const handleError = useCallback((err: unknown, fallback: string) => {
        if (axios.isAxiosError(err)) {
            const status = err.response?.status;
            const message = (err.response?.data as { message?: string } | undefined)?.message;
            if (status === 401) { router.push('/login'); return; }
            if (status === 422 && (err.response?.data as { errors?: { eligibility?: string[] } })?.errors?.eligibility) {
                setNotEligible('Daftar Ulang hanya tersedia untuk peserta yang dinyatakan LULUS.');
                return;
            }
            Swal.fire({ icon: status === 422 ? 'warning' : 'error', title: 'Gagal', text: message ?? fallback, confirmButtonColor: '#1a5f51' });
            return;
        }
        Swal.fire({ icon: 'error', title: 'Gagal', text: fallback, confirmButtonColor: '#1a5f51' });
    }, [router]);

    // B.7.3: load the canonical official-student state from the server.
    // The frontend never decides eligibility; it only renders server state.
    const loadOfficialState = useCallback(async (): Promise<AuthenticatedUser | null> => {
        try {
            const { data } = await libAxios.get<AuthenticatedUser>('/api/user');
            setStatusSiswa(data.status_siswa ?? null);
            setContinuedAt(data.continued_at ?? null);
            return data;
        } catch (err) {
            handleError(err, 'Status siswa gagal dimuat.');
            return null;
        }
    }, [handleError]);

    useEffect(() => {
        let active = true;
        const run = async () => {
            try {
                const { data } = await libAxios.get<AuthenticatedUser>('/api/user');
                if (active) {
                    setStatusSiswa(data.status_siswa ?? null);
                    setContinuedAt(data.continued_at ?? null);
                }
            } catch { /* 401 handled by the existing interceptor convention */ }
        };
        run();
        return () => { active = false; };
    }, []);

    // B.7.3: MELANJUTKAN. POST /api/daftar-ulang/continue with an EMPTY body —
    // canonical identity is derived server-side from the authenticated user.
    // After success we apply the server response, then re-GET the canonical
    // invoice so payment/withdrawal markers can never diverge.
    const submitContinue = useCallback(async () => {
        if (isDeciding) return;
        setIsDeciding(true);
        try {
            const { data } = await libAxios.post<DuContinuationResult>('/api/daftar-ulang/continue', {});
            setStatusSiswa(data.status_siswa);
            setContinuedAt(data.continued_at ?? null);
            if (invoice) {
                const refreshed = await libAxios.get<DuParentInvoice>(`/api/daftar-ulang/invoices/${invoice.id}`);
                applyInvoice(refreshed.data);
            }
            Swal.fire({ icon: 'success', title: 'Tercatat', text: 'Anda telah memilih untuk melanjutkan.', confirmButtonColor: '#1a5f51' });
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 409) {
                await loadOfficialState();
                Swal.fire({ icon: 'warning', title: 'Status Berubah', text: 'Status Anda telah berubah. Menampilkan status terbaru.', confirmButtonColor: '#1a5f51' });
            } else {
                handleError(err, 'Konfirmasi melanjutkan gagal diproses.');
            }
        } finally {
            setIsDeciding(false);
        }
    }, [isDeciding, invoice, applyInvoice, handleError, loadOfficialState]);

    const confirmContinue = useCallback(() => {
        Swal.fire({
            icon: 'question',
            title: 'Lanjutkan Pendaftaran?',
            html: 'Anda akan dicatat sebagai siswa yang <b>melanjutkan</b>.<br/>'
                + 'Tindakan ini <b>tidak dapat dibatalkan</b> melalui sistem saat ini.<br/>'
                + 'Pembayaran dan kursi Anda tidak berubah oleh tindakan ini.',
            showCancelButton: true,
            confirmButtonColor: '#1a5f51',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Lanjutkan',
            cancelButtonText: 'Batal',
            focusCancel: true,
        }).then((result) => {
            if (result.isConfirmed) void submitContinue();
        });
    }, [submitContinue]);

    // B.7.3: MENGUNDURKAN DIRI. POST /api/daftar-ulang/withdraw with {} —
    // no user_id / invoice_id / allocation_id / payment_id is ever sent.
    const submitWithdraw = useCallback(async () => {
        if (isDeciding) return;
        setIsDeciding(true);
        try {
            const { data } = await libAxios.post<DuWithdrawalResult>('/api/daftar-ulang/withdraw', {});
            // Server-authoritative terminal state. The response carries the
            // committed withdrawn_at; DU reads are intentionally blocked for
            // withdrawn users, so do NOT re-GET the invoice here.
            setStatusSiswa('MENGUNDURKAN_DIRI');
            setWithdrawnAtState(data.withdrawn_at);
            Swal.fire({ icon: 'success', title: 'Tercatat', text: 'Anda telah mengundurkan diri.', confirmButtonColor: '#1a5f51' });
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 409) {
                await loadOfficialState();
                Swal.fire({ icon: 'warning', title: 'Status Berubah', text: 'Status Anda telah berubah. Menampilkan status terbaru.', confirmButtonColor: '#1a5f51' });
            } else {
                handleError(err, 'Pengunduran diri gagal diproses.');
            }
        } finally {
            setIsDeciding(false);
        }
    }, [isDeciding, handleError, loadOfficialState]);

    const confirmWithdraw = useCallback(() => {
        Swal.fire({
            icon: 'warning',
            title: 'Mengundurkan Diri?',
            html: 'Anda akan dicatat sebagai siswa yang <b>mengundurkan diri</b>.<br/>'
                + 'Tindakan ini bersifat <b>final</b> menurut sistem saat ini.<br/>'
                + 'Kursi pendaftaran awal Anda akan dibatalkan oleh sekolah.<br/>'
                + 'Catatan pembayaran Anda tidak dihapus atau diubah oleh tindakan ini.',
            showCancelButton: true,
            confirmButtonColor: '#e53e3e',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Mengundurkan Diri',
            cancelButtonText: 'Batal',
            focusCancel: true,
        }).then((result) => {
            if (result.isConfirmed) void submitWithdraw();
        });
    }, [submitWithdraw]);

    // Initial load: resolve identity + current pricing + server-derived account + active invoice.
    useEffect(() => {
        let active = true;
        const load = async () => {
            try {
                const [pricingRes, invoiceRes] = await Promise.all([
                    libAxios.get<DuParentPricing>('/api/daftar-ulang/pricing'),
                    libAxios.get<DuParentInvoice | null>('/api/daftar-ulang/invoices/active').catch(() => ({ data: null })),
                ]);
                if (!active) return;
                setPricing(pricingRes.data);
                if (invoiceRes.data) {
                    applyInvoice(invoiceRes.data);
                }
            } catch (err) {
                if (!active) return;
                if (axios.isAxiosError(err) && err.response?.status === 422
                    && (err.response?.data as { errors?: { eligibility?: string[] } })?.errors?.eligibility) {
                    // DU reads are blocked after withdrawal BY DESIGN. Ask the
                    // server for the authoritative status: a withdrawn parent
                    // must see the terminal view, not the ineligibility wall.
                    const me = await libAxios.get<AuthenticatedUser>('/api/user').then(r => r.data).catch(() => null);
                    if (me?.status_siswa !== 'MENGUNDURKAN_DIRI' && me?.status_siswa !== 'TERDAFTAR') {
                        setNotEligible('Daftar Ulang hanya tersedia untuk peserta yang dinyatakan LULUS.');
                    } else if (me) {
                        setStatusSiswa(me.status_siswa);
                        setContinuedAt(me.continued_at ?? null);
                    }
                } else {
                    handleError(err, 'Data Daftar Ulang gagal dimuat.');
                }
            } finally {
                if (active) setIsLoading(false);
            }
        };
        load();
        return () => { active = false; };
    }, [applyInvoice, handleError]);

    // Create (idempotent) or retrieve the canonical invoice, then show its immutable snapshot.
    const loadInvoice = useCallback(async (): Promise<DuParentInvoice | null> => {
        setIsActing(true);
        try {
            const { data } = await libAxios.post<DuParentInvoice>('/api/daftar-ulang/invoices', {});
            applyInvoice(data);
            return data;
        } catch (err) {
            handleError(err, 'Invoice gagal dibuat.');
            return null;
        } finally {
            setIsActing(false);
        }
    }, [applyInvoice, handleError]);

    const proceedToAgreement = async () => {
        const inv = await loadInvoice();
        if (inv) applyInvoice(inv);
    };

    // Agreement acceptance: the backend binds the snapshot version/hash itself.
    const acceptAgreement = async () => {
        if (!invoice || !agreed) return;
        setIsActing(true);
        try {
            await libAxios.post(`/api/daftar-ulang/invoices/${invoice.id}/agreement`, { agree: true });
            // Re-fetch server state; never assume persistence client-side.
            const { data } = await libAxios.get<DuParentInvoice>(`/api/daftar-ulang/invoices/${invoice.id}`);
            applyInvoice(data);
            Swal.fire({ icon: 'success', title: 'Disetujui', text: 'Ketentuan daftar ulang telah disetujui.', confirmButtonColor: '#1a5f51' });
        } catch (err) {
            handleError(err, 'Persetujuan gagal diproses.');
        } finally {
            setIsActing(false);
        }
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] ?? null;
        setManualFile(file);
        if (filePreview) URL.revokeObjectURL(filePreview);
        setFilePreview(file ? URL.createObjectURL(file) : null);
    };
    const removeSelectedFile = () => {
        setManualFile(null);
        if (filePreview) URL.revokeObjectURL(filePreview);
        setFilePreview(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handleCopyRekening = async () => {
        const account = pricing?.account.rekening;
        if (!account) return;
        try {
            await navigator.clipboard.writeText(account);
            setIsCopied(true);
            setTimeout(() => setIsCopied(false), 2000);
        } catch { /* clipboard unavailable */ }
    };

    // Proof upload: only the file field the backend accepts (bukti_bayar).
    const submitProof = async () => {
        if (!manualFile) return;
        setIsUploading(true);
        try {
            const form = new FormData();
            form.append('bukti_bayar', manualFile);
            await libAxios.post('/api/daftar-ulang/payments/proof', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            if (invoice) {
                const { data } = await libAxios.get<DuParentInvoice>(`/api/daftar-ulang/invoices/${invoice.id}`);
                applyInvoice(data);
            }
            removeSelectedFile();
            Swal.fire({ icon: 'success', title: 'Terkirim', text: 'Bukti pembayaran terkirim dan menunggu verifikasi.', confirmButtonColor: '#1a5f51' });
        } catch (err) {
            handleError(err, 'Bukti pembayaran gagal diunggah.');
        } finally {
            setIsUploading(false);
        }
    };

    if (isLoading) {
        return (
            <div className={styles.mobileContainer}>
                <div className={styles.headerMini}>
                    <div><h2 className={styles.headerTitle}>Daftar Ulang</h2><p className={styles.headerSubtitle}>Memuat data…</p></div>
                </div>
            </div>
        );
    }

    if (notEligible) {
        return (
            <div className={styles.mobileContainer}>
                <div className={styles.headerMini}>
                    <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>
                    </div>
                    <div><h2 className={styles.headerTitle}>Daftar Ulang</h2><p className={styles.headerSubtitle}>Tidak Tersedia</p></div>
                </div>
                <div className={styles.contentWrap}>
                    <div className={styles.guideBox}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span>
                        <div className={styles.guideText}><strong>Belum Dapat Melanjutkan</strong>{notEligible}</div>
                    </div>
                </div>
            </div>
        );
    }

    const items = invoice?.pricing_snapshot.items ?? pricing?.items ?? [];
    const subtotal = invoice ? invoice.subtotal : pricing?.subtotal;
    const discount = invoice ? invoice.discount_nominal : pricing?.discount_nominal;
    const total = invoice ? invoice.total : pricing?.total;
    const source = invoice?.pricing_snapshot.source ?? pricing?.source;
    const agreement = invoice?.pricing_snapshot.agreement ?? null;

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.headerMini}>
                <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>
                </div>
                <div>
                    <h2 className={styles.headerTitle}>Daftar Ulang</h2>
                    <p className={styles.headerSubtitle}>{invoice?.student_name ?? 'Selesaikan proses daftar ulang'}</p>
                </div>
            </div>

            <div className={styles.contentWrap}>
                {/* Pricing snapshot is hidden once DU reads are blocked (terminal
                    withdrawal state) — the server refuses the pricing read by design. */}
                {(pricing || invoice) && (
                    <>
                        <h3 className={styles.sectionTitle}>Rincian Biaya</h3>
                        <div className={styles.guideBox}>
                            <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20"/></svg></span>
                            <div className={styles.guideText}>
                                <strong>Sumber: {source === 'CUSTOM' ? 'Harga Khusus' : 'Harga Umum'}</strong>
                                {items.map((item, index) => (
                                    <div key={item.id ?? index}>{item.name} — {item.quantity} x {rupiah(item.unit_amount)} = {rupiah(item.line_total)}</div>
                                ))}
                                <div>Subtotal: {rupiah(subtotal)}</div>
                                <div>Diskon: {rupiah(discount)}</div>
                                <div><b>Total: {rupiah(total)}</b></div>
                            </div>
                        </div>
                    </>
                )}

                {viewState === 'INTRO' && statusSiswa === null && (
                    <button type="button" className={styles.submitModalBtn} disabled={isActing} onClick={proceedToAgreement}>
                        {isActing ? 'Memproses…' : 'Lanjutkan Daftar Ulang'}
                    </button>
                )}

                {viewState === 'AGREEMENT' && agreement && (
                    <>
                        <h3 className={styles.sectionTitle}>Ketentuan Daftar Ulang</h3>
                        <div className={styles.guideBox}>
                            <div className={styles.guideText}>
                                <strong>Versi {agreement.version}</strong>
                                <p style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{agreement.text}</p>
                            </div>
                        </div>
                        <label htmlFor="du-agree" style={{ display: 'flex', gap: '10px', alignItems: 'flex-start', fontSize: '13px', color: '#1e293b', marginBottom: '16px' }}>
                            <input id="du-agree" type="checkbox" checked={agreed} onChange={e => setAgreed(e.target.checked)} style={{ marginTop: '2px' }} />
                            <span>Saya telah membaca dan menyetujui ketentuan daftar ulang.</span>
                        </label>
                        <button type="button" className={styles.submitModalBtn} disabled={!agreed || isActing} onClick={acceptAgreement}>
                            {isActing ? 'Memproses…' : 'Setujui & Lanjutkan'}
                        </button>
                    </>
                )}

                {(viewState === 'INVOICE' || viewState === 'REJECTED') && statusSiswa !== 'MENGUNDURKAN_DIRI' && invoice && (
                    <>
                        <h3 className={styles.sectionTitle}>Invoice</h3>
                        <div className={styles.guideBox}>
                            <div className={styles.guideText}>
                                <strong>{invoice.invoice_number}</strong>
                                <div>Dibuat: {date(invoice.created_at)}</div>
                                <div>Siswa: {invoice.student_name}</div>
                                <div>Jenjang: {invoice.jenjang}</div>
                                <div>Total: <b>{rupiah(invoice.total)}</b></div>
                            </div>
                        </div>

                        <h3 className={styles.sectionTitle}>Instruksi Pembayaran</h3>
                        <div className={styles.guideBox}>
                            <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4"/></svg></span>
                            <div className={styles.guideText}>
                                <strong>{pricing?.account.bank_name ?? 'Bank'}</strong>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                    <b style={{ wordBreak: 'break-all' }}>{pricing?.account.rekening ?? '—'}</b>
                                    <button type="button" className={styles.copyBtn} disabled={!pricing?.account.rekening} onClick={handleCopyRekening}>{isCopied ? 'Tersalin' : 'Salin'}</button>
                                </div>
                                <div>a.n. {pricing?.account.bank_owner ?? '—'}</div>
                            </div>
                        </div>

                        {viewState === 'REJECTED' && invoice.payment?.rejection_reason && (
                            <div className={styles.guideBox} style={{ background: '#fde9e8', borderColor: '#f5c6c4' }}>
                                <div className={styles.guideText}><strong>Pembayaran Ditolak</strong>{invoice.payment.rejection_reason}</div>
                            </div>
                        )}

                        <h3 className={styles.sectionTitle}>Upload Bukti Pembayaran</h3>
                        <div className={styles.uploadArea} onClick={() => fileInputRef.current?.click()} role="button" tabIndex={0} onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') fileInputRef.current?.click(); }}>
                            {filePreview ? (
                                <div className={styles.previewArea}>
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    <img src={filePreview} alt="Pratinjau bukti pembayaran" />
                                </div>
                            ) : (
                                <span style={{ fontSize: '13px', color: '#64748b' }}>Ketuk untuk memilih gambar bukti (JPG/PNG, maks 2MB)</span>
                            )}
                        </div>
                        {manualFile && (
                            <div style={{ marginTop: '8px' }}>
                                <span style={{ fontSize: '12px', wordBreak: 'break-all' }}>{manualFile.name}</span>
                                <button type="button" className={styles.changeImgBtn} onClick={removeSelectedFile} style={{ position: 'static', transform: 'none', marginLeft: '8px' }}>Ganti Gambar</button>
                            </div>
                        )}
                        <input type="file" accept="image/*" ref={fileInputRef} style={{ display: 'none' }} onChange={handleFileSelect} aria-label="Pilih bukti pembayaran" />
                        <button type="button" className={styles.submitModalBtn} disabled={!manualFile || isUploading} onClick={submitProof}>
                            {isUploading ? 'Mengunggah…' : 'Kirim Bukti Pembayaran'}
                        </button>
                    </>
                )}

                {viewState === 'PENDING_MANUAL' && invoice && (
                    <div className={styles.guideBox} style={{ background: '#e4f4eb', borderColor: '#a7dcc0' }}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#146039" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
                        <div className={styles.guideText}><strong>Menunggu Verifikasi</strong>Bukti pembayaran Anda sedang diverifikasi oleh admin.</div>
                    </div>
                )}

                {viewState === 'SUCCESS' && invoice && (
                    <div className={styles.guideBox} style={{ background: '#e4f4eb', borderColor: '#a7dcc0' }}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#146039" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg></span>
                        <div className={styles.guideText}>
                            <strong>Pembayaran Terverifikasi</strong>
                            Pembayaran Daftar Ulang Anda telah diverifikasi pada {date(invoice.payment?.verified_at)}.
                        </div>
                    </div>
                )}

                {/* B.7.3 — official-student continuation decision (NULL state only). */}
                {statusSiswa === null && viewState === 'SUCCESS' && (
                    <>
                        <h3 className={styles.sectionTitle}>Konfirmasi Keputusan</h3>
                        <button type="button" className={styles.submitModalBtn} disabled={isDeciding} onClick={confirmContinue}>
                            {isDeciding ? 'Memproses…' : 'Melanjutkan'}
                        </button>
                        <button
                            type="button"
                            className={styles.submitModalBtn}
                            style={{ background: '#e53e3e', marginTop: '0.5rem' }}
                            disabled={isDeciding}
                            onClick={confirmWithdraw}
                        >
                            {isDeciding ? 'Memproses…' : 'Mengundurkan Diri'}
                        </button>
                    </>
                )}

                {/* B.7.4-C — third decision path: ask to consult the school.
                    B.7.4-E.1: consultation exists ONLY while the parent is
                    still deciding BEFORE payment success, so the section is
                    hidden once the DU payment is SUCCESS or the official
                    lifecycle state is terminal (TERDAFTAR / MENGUNDURKAN_DIRI);
                    the backend enforces the same rule at mutation time.
                    `enabled` still waits for the canonical DU invoice because
                    the POST needs one to attach to (404 without). */}
                {statusSiswa !== 'MENGUNDURKAN_DIRI' && statusSiswa !== 'TERDAFTAR' && viewState !== 'SUCCESS' && (
                    <DaftarUlangConsultation
                        enabled={invoice !== null}
                        invoiceNumber={invoice?.invoice_number ?? null}
                    />
                )}

                {/* B.7.4-E.1 — payment success closed the consultation phase:
                    the parent already has the school's attention via the
                    verification flow, so a concise explanation replaces the
                    (backend-refused) request form. */}
                {statusSiswa === null && viewState === 'SUCCESS' && (
                    <div className={styles.guideBox}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span>
                        <div className={styles.guideText}>
                            <strong>Konsultasi Tidak Lagi Tersedia</strong>
                            Permintaan konsultasi hanya dapat diajukan sebelum pembayaran Daftar Ulang diverifikasi.
                        </div>
                    </div>
                )}

                {/* B.7.3 — TERDAFTAR: terminal official state. No continuation action. */}
                {statusSiswa === 'TERDAFTAR' && (
                    <div className={styles.guideBox} style={{ background: '#e4f4eb', borderColor: '#a7dcc0' }}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#146039" strokeWidth="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 11 2 2 4-4"/></svg></span>
                        <div className={styles.guideText}>
                            <strong>Terdaftar</strong>
                            Anda telah memilih untuk melanjutkan pada {date(continuedAt)}.
                            Status Anda telah tercatat sebagai siswa resmi.
                        </div>
                    </div>
                )}

                {/* B.7.3 — MENGUNDURKAN_DIRI: terminal withdrawal state. No further action. */}
                {statusSiswa === 'MENGUNDURKAN_DIRI' && (
                    <div className={styles.guideBox}>
                        <span className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b45309" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg></span>
                        <div className={styles.guideText}>
                            <strong>Mengundurkan Diri</strong>
                            Anda telah mengundurkan diri{withdrawnAt(invoice, withdrawnAtState) ? ` pada ${date(withdrawnAt(invoice, withdrawnAtState))}` : ''}.
                            Proses daftar ulang Anda telah berakhir.
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
