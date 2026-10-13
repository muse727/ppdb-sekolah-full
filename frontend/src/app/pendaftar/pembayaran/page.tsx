// src/app/pendaftar/pembayaran/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './pembayaran.module.css';

type ViewState = 'SELECT' | 'SUCCESS' | 'PENDING_MANUAL' | 'REJECTED';

interface PaymentRecord {
    invoice_number: string;
    jenjang: string;
    amount: number;
    status: string;
    rejection_reason?: string | null;
    verified_at?: string | null;
}

export default function PembayaranPage() {
    const router = useRouter();

    const [userName, setUserName] = useState<string>('');
    const [jenjangUser, setJenjangUser] = useState<string>('');
    const [jalurUser, setJalurUser] = useState<string>('');
    const [biayaFormulir, setBiayaFormulir] = useState<number>(0);
    const [bankInfo, setBankInfo] = useState({ name: '', account: '', owner: '' });
    const [isLoading, setIsLoading] = useState(true);

    // Manual-transfer-only flow: SELECT (rincian tagihan) / PENDING_MANUAL (menunggu verifikasi) / SUCCESS (lunas).
    const [viewState, setViewState] = useState<ViewState>('SELECT');
    const [paymentData, setPaymentData] = useState<PaymentRecord | null>(null);

    // Upload state
    const [manualFile, setManualFile] = useState<File | null>(null);
    const [filePreview, setFilePreview] = useState<string | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [isCopied, setIsCopied] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const fetchPaymentAndStatus = async () => {
            try {
                const userRes = await libAxios.get('/api/user');
                const userJenjang = userRes.data.jenjang || 'SD';
                setJenjangUser(userJenjang);
                setUserName(userRes.data.name || '');
                setJalurUser(userRes.data.jalur || '');

                const priceRes = await libAxios.get('/api/payment/instructions');
                setJenjangUser(priceRes.data.jenjang);
                setBiayaFormulir(priceRes.data.amount);
                setBankInfo({
                    name: priceRes.data.bank_name,
                    account: priceRes.data.rekening || '',
                    owner: priceRes.data.bank_owner
                });

                const statusRes = await libAxios.get('/api/payment/status');
                if (statusRes.data.has_payment) {
                    setPaymentData(statusRes.data.payment);
                    if (statusRes.data.payment.status === 'SUCCESS') setViewState('SUCCESS');
                    else if (statusRes.data.payment.status === 'PENDING_MANUAL') setViewState('PENDING_MANUAL');
                    else if (statusRes.data.payment.status === 'REJECTED') setViewState('REJECTED');
                    else setViewState('SELECT'); // FAILED/EXPIRED gateway relics: upload fresh proof
                } else {
                    setViewState('SELECT');
                }
            } catch (error) {
                console.error('Gagal menarik data', error);
                setViewState('SELECT');
            } finally {
                setIsLoading(false);
            }
        };

        fetchPaymentAndStatus();
    }, []);

    const formatRupiah = (angka: number) =>
        new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);

    const handleCopyRekening = () => {
        navigator.clipboard.writeText(bankInfo.account);
        setIsCopied(true);
        setTimeout(() => setIsCopied(false), 2000);
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setManualFile(file);
            setFilePreview(URL.createObjectURL(file));
        }
    };

    const removeSelectedFile = () => {
        setFilePreview(null);
        setManualFile(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const submitManualTransfer = async () => {
        if (!manualFile) return;
        setIsUploading(true);

        const formData = new FormData();
        formData.append('jenjang', jenjangUser);
        formData.append('bukti_bayar', manualFile);

        try {
            await libAxios.post('/api/payment/manual', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
            Swal.fire('Berhasil Terkirim!', 'Bukti transfer sedang divalidasi tim keuangan.', 'success')
                .then(() => window.location.reload());
        } catch (error) {
            const err = error as { response?: { data?: { message?: string } } };
            const message = err?.response?.data?.message || 'Terjadi gangguan jaringan server. Pastikan ukuran gambar tidak terlalu besar.';
            Swal.fire('Gagal Unggah', message, 'error');
        } finally {
            setIsUploading(false);
        }
    };

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.headerMini}>
                <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                </div>
                <div>
                    <h2 className={styles.headerTitle}>Pembayaran Formulir</h2>
                    <p className={styles.headerSubtitle}>Transfer Manual Bank</p>
                </div>
            </div>

            <div className={styles.contentWrap}>
                {isLoading ? (
                    <div style={{ textAlign: 'center', padding: '50px', color: '#1a5f51', fontWeight: 800 }}>Mendeteksi Profil Akun...</div>
                ) : viewState === 'SUCCESS' ? (

                    /* ================= LUNAS ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Kwitansi Digital</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.statusBadge}>LUNAS</div>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.successIcon}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </div>
                                <h3>Pembayaran Sukses</h3>
                                <p>{paymentData!.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Nama Pendaftar</span>
                                <span className={styles.invoiceValue}>{userName}</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Kategori Pendidikan</span>
                                <span className={styles.invoiceValue}>{paymentData!.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Metode Transaksi</span>
                                <span className={styles.invoiceValue}>Transfer Bank</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Total Dana Masuk</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData!.amount)}</span>
                            </div>
                        </div>
                        <button className={styles.payBtnAuto} style={{ width: '100%' }} onClick={() => router.push('/pendaftar/biodata')}>
                            Lanjut Isi Biodata
                        </button>
                    </div>

                ) : viewState === 'PENDING_MANUAL' ? (

                    /* ================= SEDANG DIVERIFIKASI ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Status Konfirmasi</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.warningIcon} style={{ background: '#ebf8ff', color: '#2b6cb0' }}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <h3 style={{ color: '#2b6cb0' }}>Sedang Diverifikasi</h3>
                                <p>{paymentData!.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Nama Pendaftar</span>
                                <span className={styles.invoiceValue}>{userName}</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Target Sekolah</span>
                                <span className={styles.invoiceValue}>{paymentData!.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Jumlah Ditransfer</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData!.amount)}</span>
                            </div>
                        </div>
                        <div className={styles.guideBox} style={{ borderColor: '#bee3f8', background: '#ebf8ff' }}>
                            <div className={styles.guideIcon} style={{ color: '#2b6cb0' }}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg></div>
                            <div className={styles.guideText}>
                                <strong style={{ color: '#2b6cb0' }}>Proses Pengecekan Admin</strong>
                                Resi transfer manual Anda sedang dalam tahap rekonsiliasi. Mohon tunggu maksimal 1x24 jam kerja.
                            </div>
                        </div>
                        <button className={styles.payBtnAuto} style={{ width: '100%', background: '#cbd5e0', boxShadow: 'none', cursor: 'not-allowed' }} disabled>
                            Menunggu Validasi Kasir...
                        </button>
                    </div>

                ) : viewState === 'REJECTED' ? (

                    /* ================= BUKTI DITOLAK ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Status Konfirmasi</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.warningIcon} style={{ background: '#fff5f5', color: '#c53030' }}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                </div>
                                <h3 style={{ color: '#c53030' }}>Bukti Ditolak</h3>
                                <p>{paymentData!.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Nama Pendaftar</span>
                                <span className={styles.invoiceValue}>{userName}</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Target Sekolah</span>
                                <span className={styles.invoiceValue}>{paymentData!.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Jumlah Ditransfer</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData!.amount)}</span>
                            </div>
                        </div>
                        <div className={styles.guideBox} style={{ borderColor: '#fed7d7', background: '#fff5f5' }}>
                            <div className={styles.guideIcon} style={{ color: '#c53030' }}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg></div>
                            <div className={styles.guideText}>
                                <strong style={{ color: '#c53030' }}>Bukti Transfer Ditolak Panitia</strong>
                                {paymentData?.rejection_reason ? (
                                    <>Alasan: <em>{paymentData.rejection_reason}</em></>
                                ) : (
                                    <>Silakan hubungi panitia untuk informasi penolakan.</>
                                )}
                            </div>
                        </div>
                        {/* Backend policy: REJECTED does NOT block a new upload —
                            only SUCCESS and PENDING_MANUAL are blocking. The rejected
                            record stays immutable as an audit trail. */}
                        <button className={styles.payBtnAuto} style={{ width: '100%' }} onClick={() => setViewState('SELECT')}>
                            Unggah Ulang Bukti Pembayaran
                        </button>
                    </div>

                ) : (

                    /* ================= TAGIHAN + TRANSFER MANUAL ================= */
                    <>
                        <h3 className={styles.sectionTitle}>Rincian Tagihan Formulir</h3>
                        <div className={styles.autoDetectedCard}>
                            <div className={styles.autoCardHeader}>
                                <div className={styles.lockBadgeIcon}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </div>
                                <span>Terdeteksi Otomatis Berdasarkan Akun</span>
                            </div>
                            <div className={styles.autoCardBody}>
                                <div className={styles.levelIconActive}>
                                    {jenjangUser === 'SMP' ? (
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line></svg>
                                    ) : (
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                                    )}
                                </div>
                                <div className={styles.autoCardText}>
                                    <h4>{jenjangUser === 'SMP' ? 'SMPIT Al-Mubarak' : 'SDIT Al-Mubarak'}</h4>
                                    <p>{userName}{jalurUser ? ` • Jalur ${jalurUser}` : ''}</p>
                                </div>
                            </div>
                        </div>

                        <div className={styles.guideBox}>
                            <div className={styles.guideIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M9 18h6"></path><path d="M10 22h4"></path><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"></path></svg></div>
                            <div className={styles.guideText}>
                                <strong>Panduan Transaksi:</strong>
                                Lakukan transfer bank sesuai nominal tepat hingga 3 digit terakhir, lalu unggah foto bukti transfer Anda. Akun akan terverifikasi setelah tim keuangan melakukan pengecekan (maks. 1x24 jam kerja).
                            </div>
                        </div>

                        <div className={styles.checkoutBarTall}>
                            <div className={styles.checkoutBarHeader}>
                                <p>Total Tagihan</p>
                                <h3>{formatRupiah(biayaFormulir)}</h3>
                            </div>
                            <div className={styles.checkoutActionGroup}>

                                {/* REKENING TUJUAN */}
                                <div className={styles.rekBox}>
                                    <span className={styles.rekBank}>{bankInfo.name}</span>
                                    <div className={styles.rekNumberWrapper}>
                                        <span className={styles.rekNumber}>{bankInfo.account || 'Rekening belum dikonfigurasi'}</span>
                                        <button type="button" className={styles.copyBtn} disabled={!bankInfo.account} onClick={handleCopyRekening}>
                                            {isCopied ? 'Tersalin!' : 'Salin'}
                                        </button>
                                    </div>
                                    <span className={styles.rekOwner}>a.n {bankInfo.owner}</span>
                                </div>

                                <p className={styles.modalUploadLabel}>Unggah Bukti Transfer:</p>

                                {/* AREA UPLOAD + PREVIEW */}
                                {!filePreview ? (
                                    <div className={styles.uploadArea} onClick={() => fileInputRef.current?.click()}>
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                        <span>Pilih File Bukti Gambar</span>
                                    </div>
                                ) : (
                                    <div className={styles.previewArea}>
                                        {/* P4-A.3 Group B — `filePreview` is a blob object URL from
                                            URL.createObjectURL(file) (see handleFileSelect), revoked on
                                            selection change. Next.js forces `unoptimized` for data:/blob:
                                            sources, so <Image> would render the identical payload with no
                                            benefit. Plain <img> preserves current preview behavior and the
                                            surrounding .previewArea sizing. */}
                                        {/* eslint-disable-next-line @next/next/no-img-element */}
                                        <img src={filePreview} alt="Preview Bukti" />
                                        <div style={{ width: '100%', marginTop: '8px' }}>
                                            <span style={{ display: 'block', fontSize: '12px', color: '#4a5568', wordBreak: 'break-all', marginBottom: '8px' }}>{manualFile?.name}</span>
                                            <button type="button" className={styles.changeImgBtn} onClick={removeSelectedFile}>Ganti Gambar</button>
                                        </div>
                                    </div>
                                )}
                                <input type="file" accept="image/*" ref={fileInputRef} style={{ display: 'none' }} onChange={handleFileSelect} />

                                <button
                                    type="button"
                                    className={styles.submitModalBtn}
                                    disabled={!manualFile || isUploading}
                                    onClick={submitManualTransfer}
                                >
                                    {isUploading ? 'Mengunggah...' : 'Kirim Bukti Pembayaran'}
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
