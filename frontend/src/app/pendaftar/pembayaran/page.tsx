// src/app/pendaftar/pembayaran/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './pembayaran.module.css';

export default function PembayaranPage() {
    const router = useRouter();

    const [jenjangUser, setJenjangUser] = useState<string>(''); 
    const [biayaFormulir, setBiayaFormulir] = useState<number>(0);
    const [bankInfo, setBankInfo] = useState({ name: '', account: '', owner: '' });
    const [isLoading, setIsLoading] = useState(true);
    
    // View State: SELECT (Rincian Tagihan Awal), PENDING (Doku), SUCCESS (Lunas), PENDING_MANUAL (Verifikasi Admin)
    const [viewState, setViewState] = useState<'SELECT' | 'PENDING' | 'SUCCESS' | 'PENDING_MANUAL'>('SELECT');
    const [paymentData, setPaymentData] = useState<any>(null);

    // STATE CUSTOM MODAL MANUAL TRANSFER
    const [showManualModal, setShowManualModal] = useState(false);
    const [manualFile, setManualFile] = useState<File | null>(null);
    const [filePreview, setFilePreview] = useState<string | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [isCopied, setIsCopied] = useState(false);
    
    const fileInputRef = useRef<HTMLInputElement>(null);

    // ==========================================
    // 1. FETCH DATA AWAL 
    // ==========================================
    useEffect(() => {
        const fetchPaymentAndStatus = async () => {
            try {
                // Tarik profil user buat deteksi jenjang
                const userRes = await libAxios.get('/api/user');
                const userJenjang = userRes.data.jenjang || 'SD'; 
                setJenjangUser(userJenjang);

                // Tarik data Harga dan Info Rekening dinamis dari Admin
                const priceRes = await libAxios.get('/api/settings/biaya');
                setBiayaFormulir(userJenjang === 'SMP' ? priceRes.data.biaya_smp : priceRes.data.biaya_sd);
                setBankInfo({
                    name: priceRes.data.bank_name || 'BANK SYARIAH INDONESIA (BSI)',
                    account: priceRes.data.bank_account || '1234 5678 90',
                    owner: priceRes.data.bank_owner || 'Yayasan Al-Mubarak'
                });

                // Cek status pembayaran user ini
                const statusRes = await libAxios.get('/api/payment/status');
                if (statusRes.data.has_payment) {
                    setPaymentData(statusRes.data.payment);
                    if (statusRes.data.payment.status === 'SUCCESS') setViewState('SUCCESS');
                    else if (statusRes.data.payment.status === 'PENDING_MANUAL') setViewState('PENDING_MANUAL');
                    else setViewState('PENDING');
                } else {
                    setViewState('SELECT');
                }
            } catch (error) {
                console.error("Gagal menarik data", error);
                setViewState('SELECT');
            } finally {
                setIsLoading(false); // Pastikan loading selalu mati
            }
        };

        fetchPaymentAndStatus();
    }, []);

    const formatRupiah = (angka: number) => {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka);
    };

    const handleCopyRekening = () => {
        navigator.clipboard.writeText(bankInfo.account);
        setIsCopied(true);
        setTimeout(() => setIsCopied(false), 2000);
    };

    // ==========================================
    // 2. FUNGSI BAYAR OTOMATIS (DOKU)
    // ==========================================
    const handlePaymentClick = async () => {
        Swal.fire({ title: 'Menyiapkan Pembayaran...', text: 'Membuka gerbang aman otomatis...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
        try {
            const res = await libAxios.post('/api/checkout', { jenjang: jenjangUser });
            if (res.data.success && res.data.payment_url) window.location.href = res.data.payment_url;
        } catch (error: any) {
            Swal.fire('Error', 'Gagal terhubung ke server sistem pembayaran.', 'error');
        }
    };

    const handleContinueToDoku = () => { 
        if (paymentData?.payment_url) window.location.href = paymentData.payment_url; 
    };

    // ==========================================
    // 3. FUNGSI MODAL MANUAL TRANSFER
    // ==========================================
    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setManualFile(file);
            setFilePreview(URL.createObjectURL(file));
        }
    };

    const submitManualTransfer = async () => {
        if (!manualFile) return;
        setIsUploading(true);
        
        const formData = new FormData();
        formData.append('jenjang', jenjangUser);
        formData.append('bukti_bayar', manualFile);

        try {
            await libAxios.post('/api/payment/manual', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
            setShowManualModal(false);
            Swal.fire('Berhasil Terkirim!', 'Bukti transfer sedang divalidasi tim keuangan.', 'success').then(() => window.location.reload());
        } catch (error) {
            Swal.fire('Gagal Unggah', 'Terjadi gangguan jaringan server. Pastikan ukuran gambar tidak terlalu besar.', 'error');
        } finally {
            setIsUploading(false);
        }
    };

    return (
        <div className={styles.mobileContainer}>
            {/* HEADER */}
            <div className={styles.headerMini}>
                <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                </div>
                <div>
                    <h2 className={styles.headerTitle}>Info Pembayaran</h2>
                    <p className={styles.headerSubtitle}>Langkah 1 PPDB Al-Mubarak</p>
                </div>
            </div>

            <div className={styles.contentWrap}>
                {isLoading ? (
                    <div style={{ textAlign: 'center', padding: '50px', color: '#1a5f51', fontWeight: 800 }}>Mendeteksi Profil Akun...</div>
                ) : viewState === 'SUCCESS' ? (
                    
                    /* ================= VIEW 1: LUNAS ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Kwitansi Digital</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.statusBadge}>LUNAS</div>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.successIcon}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </div>
                                <h3>Pembayaran Sukses</h3>
                                <p>{paymentData.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Tanggal Verifikasi</span>
                                <span className={styles.invoiceValue}>{new Date(paymentData.updated_at).toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'})}</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Kategori Pendidikan</span>
                                <span className={styles.invoiceValue}>{paymentData.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Metode Transaksi</span>
                                <span className={styles.invoiceValue}>{paymentData.payment_method === 'MANUAL' ? 'Transfer Bank' : 'Online Gateway'}</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Total Dana Masuk</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData.amount)}</span>
                            </div>
                        </div>
                        <button className={styles.payBtnAuto} style={{width: '100%'}} onClick={() => router.push('/pendaftar/biodata')}>
                            Lanjut Isi Biodata
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" style={{marginLeft: '8px', verticalAlign: 'middle'}}><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </button>
                    </div>

                ) : viewState === 'PENDING_MANUAL' ? (

                    /* ================= VIEW 2: SEDANG DIVERIFIKASI ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Status Konfirmasi</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.warningIcon} style={{ background: '#ebf8ff', color: '#2b6cb0' }}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <h3 style={{ color: '#2b6cb0' }}>Sedang Diverifikasi</h3>
                                <p>{paymentData.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Target Sekolah</span>
                                <span className={styles.invoiceValue}>{paymentData.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Jumlah Ditransfer</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData.amount)}</span>
                            </div>
                        </div>
                        <div className={styles.guideBox} style={{borderColor: '#bee3f8', background: '#ebf8ff'}}>
                            <div className={styles.guideIcon} style={{color: '#2b6cb0'}}>ℹ️</div>
                            <div className={styles.guideText}>
                                <strong style={{color: '#2b6cb0'}}>Proses Pengecekan Admin</strong>
                                Resi transfer manual Anda sedang dalam tahap rekonsiliasi. Mohon tunggu maksimal 1x24 jam kerja.
                            </div>
                        </div>
                        <button className={styles.payBtnAuto} style={{width: '100%', background: '#cbd5e0', boxShadow: 'none', cursor: 'not-allowed'}} disabled>
                            Menunggu Validasi Kasir...
                        </button>
                    </div>

                ) : viewState === 'PENDING' ? (

                    /* ================= VIEW 3: MENUNGGU PEMBAYARAN DOKU ================= */
                    <div>
                        <h3 className={styles.sectionTitle}>Invoice Pembayaran</h3>
                        <div className={styles.invoiceCard}>
                            <div className={styles.invoiceHeader}>
                                <div className={styles.warningIcon}>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                </div>
                                <h3 style={{color: '#dd6b20'}}>Menunggu Transaksi</h3>
                                <p>{paymentData.invoice_number}</p>
                            </div>
                            <div className={styles.invoiceRow}>
                                <span className={styles.invoiceLabel}>Pilihan Jalur</span>
                                <span className={styles.invoiceValue}>{paymentData.jenjang}IT Al-Mubarak</span>
                            </div>
                            <div className={styles.invoiceTotal}>
                                <span className={styles.invoiceLabel}>Total Nominal</span>
                                <span className={styles.invoiceValue}>{formatRupiah(paymentData.amount)}</span>
                            </div>
                        </div>
                        <button className={styles.payBtnAuto} style={{width: '100%'}} onClick={handleContinueToDoku}>
                            Lanjutkan Pembayaran Online
                        </button>
                    </div>

                ) : (

                    /* ================= VIEW 4: AUTO DETECT (PILIHAN PEMBAYARAN) ================= */
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
                                    <p>Biaya Administrasi Pengambilan Formulir PPDB</p>
                                </div>
                            </div>
                        </div>

                        <div className={styles.guideBox}>
                            <div className={styles.guideIcon}>💡</div>
                            <div className={styles.guideText}>
                                <strong>Panduan Transaksi:</strong>
                                Sangat disarankan menekan tombol <b>Bayar Otomatis</b> agar akun Anda terverifikasi secara instan. Gunakan <b>Transfer Manual</b> HANYA bila Anda mengalami kendala pada sistem otomatis.
                            </div>
                        </div>

                        <div className={styles.checkoutBarTall}>
                            <div className={styles.checkoutBarHeader}>
                                <p>Total Tagihan</p>
                                <h3>{formatRupiah(biayaFormulir)}</h3>
                            </div>
                            <div className={styles.checkoutActionGroup}>
                                <button className={styles.payBtnAuto} onClick={handlePaymentClick}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    Bayar Otomatis (Instan)
                                </button>
                                
                                <button className={styles.payBtnManual} onClick={() => setShowManualModal(true)}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                    Transfer Manual (Bila Terkendala)
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>

            {/* ========================================================
                CUSTOM MODAL TRANSFER MANUAL DENGAN PREVIEW & COPY
                ======================================================== */}
            {showManualModal && (
                <div className={styles.modalOverlay} onClick={() => !isUploading && setShowManualModal(false)}>
                    <div className={styles.modalContent} onClick={e => e.stopPropagation()}>
                        
                        <div className={styles.modalHeader}>
                            <h3>Transfer Manual</h3>
                            <button className={styles.closeModalBtn} onClick={() => setShowManualModal(false)}>✕</button>
                        </div>

                        <div className={styles.modalBody}>
                            <p className={styles.modalDesc}>Silakan transfer sesuai nominal ke rekening berikut:</p>
                            <h2 className={styles.modalPrice}>{formatRupiah(biayaFormulir)}</h2>

                            {/* KOTAK REKENING DINAMIS */}
                            <div className={styles.rekBox}>
                                <span className={styles.rekBank}>{bankInfo.name}</span>
                                <div className={styles.rekNumberWrapper}>
                                    <span className={styles.rekNumber}>{bankInfo.account}</span>
                                    <button className={styles.copyBtn} onClick={handleCopyRekening}>
                                        {isCopied ? 'Tersalin!' : 'Salin'}
                                    </button>
                                </div>
                                <span className={styles.rekOwner}>a.n {bankInfo.owner}</span>
                            </div>

                            <p className={styles.modalUploadLabel}>Unggah Bukti Transfer:</p>
                            
                            {/* AREA UPLOAD PREVIEW */}
                            {!filePreview ? (
                                <div className={styles.uploadArea} onClick={() => fileInputRef.current?.click()}>
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                    <span>Pilih File Bukti Gambar</span>
                                </div>
                            ) : (
                                <div className={styles.previewArea}>
                                    <img src={filePreview} alt="Preview Bukti" />
                                    <button className={styles.changeImgBtn} onClick={() => { setFilePreview(null); setManualFile(null); }}>Ganti Gambar</button>
                                </div>
                            )}
                            <input type="file" accept="image/*" ref={fileInputRef} style={{ display: 'none' }} onChange={handleFileSelect} />

                            <button 
                                className={styles.submitModalBtn} 
                                disabled={!manualFile || isUploading}
                                onClick={submitManualTransfer}
                            >
                                {isUploading ? 'Mengunggah...' : 'Kirim Bukti Pembayaran'}
                            </button>
                        </div>

                    </div>
                </div>
            )}
        </div>
    );
}