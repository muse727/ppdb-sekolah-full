// src/app/pendaftar/berkas/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import Tesseract from 'tesseract.js'; 
import type { BerkasRecord } from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import styles from './berkas.module.css';

// =================================================================
// E.4 — OCR CLIENT-SIDE RINGAN (advisory pre-check)
// Bukan validasi: hanya membantu pengguna mengenali kemungkinan salah
// pilih dokumen lewat keyword. Tidak pernah mengubah status backend,
// tidak mengirim isi dokumen ke server/pihak ketiga, dan kegagalan OCR
// TIDAK memblokir upload (fallback: tetap tawarkan unggah).
// =================================================================


const ocrKeywords: Record<string, string[]> = {
    kartu_keluarga: ["KARTU KELUARGA", "KEPALA KELUARGA"],
    akte_kelahiran: ["AKTA KELAHIRAN", "AKTE KELAHIRAN", "KUTIPAN", "PENCATATAN SIPIL"],
};

// Batas pra-OCR supaya perangkat lambat tidak terbebani. File melebihi ini
// dilewati OCR-nya (bukan ditolak) dan langsung ditawarkan upload.
const OCR_MAX_BYTES = 5 * 1024 * 1024; // 5 MB, sama dengan backend
const OCR_TIMEOUT_MS = 30000;          // 30 detik

type OcrOutcome = "match" | "mismatch" | "skipped" | "error";

/** Hasil pre-check: 'match' | 'mismatch' | 'skipped' | 'error' */
async function runOcrPrecheck(
    file: File,
    jenis: string,
): Promise<'match' | 'mismatch' | 'skipped' | 'error'> {
    // PDF tidak bisa dibaca pipeline OCR ini (tesseract.js hanya
    // menerima gambar) — dilewati dengan jujur, TIDAK diklaim terpindai.
    const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
    if (isPdf) return 'skipped';

    // Skip pra-cek untuk file non-gambar (ditolak backend dengan 422).
    if (!file.type.startsWith('image/')) return 'skipped';

    // Jangan OCR file besar di perangkat lambat — cek ukuran dulu
    // sehingga tidak menyalin buffer besar ke worker.
    if (file.size > OCR_MAX_BYTES) return 'skipped';

    const keywords = ocrKeywords[jenis] ?? [];
    if (keywords.length === 0) return 'skipped';

    // Satu worker per pre-check, selalu di-terminate di finally() supaya
    // tidak ada worker menggantung / jalan ulang saat React re-render.
    // (Tesseract.recognize() membuat worker internal yang sulit
    // dibersihkan, jadi worker dikelola sendiri di sini.)
    const worker = await Tesseract.createWorker('ind');
    try {
        const result = await Promise.race([
            worker.recognize(file),
            new Promise<never>((_, reject) =>
                setTimeout(() => reject(new Error('ocr-timeout')), OCR_TIMEOUT_MS)),
        ]);
        const text = (result.data.text ?? '').toUpperCase();
        return keywords.some((kw) => text.includes(kw)) ? 'match' : 'mismatch';
    } catch {
        return 'error'; // gagal/timeout → fallback, jangan blokir upload
    } finally {
        void worker.terminate().catch(() => undefined);
    }
}



export default function BerkasPage() {
    const router = useRouter();
    const [berkasData, setBerkasData] = useState<BerkasRecord>({});
    const [isLoading, setIsLoading] = useState(true);
    
    // STATE UNTUK MODAL KUSTOM
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [activeJenis, setActiveJenis] = useState('');
    const [activeLabel, setActiveLabel] = useState('');

    const API_URL = libAxios.defaults.baseURL?.replace(/\/$/, '') ?? '';

    /**
     * E.5: render status berkas berdasarkan nilai server. Upload yang lolos
     * validasi teknis backend otomatis berstatus LOLOS_TEKNIS (bukan klaim
     * keaslian dokumen). TERVERIFIKASI/DITOLAK adalah nilai legacy manual
     * yang masih dirender apa adanya.
     */
    const renderStatusBadge = () => {
        const status = berkasData?.status;
        if (status === 'LOLOS_TEKNIS' || status === 'TERVERIFIKASI') {
            return (
                <div className={styles.statusVerified}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg> {status === 'LOLOS_TEKNIS' ? 'Lolos Pemeriksaan Teknis' : 'Terverifikasi'}
                </div>
            );
        }
        if (status === 'DITOLAK') {
            return (
                <div className={styles.statusRejected}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Ditolak — Unggah Ulang
                </div>
            );
        }
        return (
            <div className={styles.statusPending}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Menunggu
            </div>
        );
    };

    useEffect(() => {
        libAxios.get<BerkasRecord | null>('/api/berkas')
            .then(res => { if(res.data) setBerkasData(res.data); })
            .catch(err => console.log(err))
            .finally(() => setIsLoading(false));
    }, []);
    // Fungsi pas ortu milih foto dari Modal
    const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        // Reset input supaya memilih file yang sama lagi tetap memicu onChange.
        e.target.value = '';

        setIsModalOpen(false); // Tutup modalnya

        // Cek format & ukuran dulu (UX only — backend tetap sumber kebenaran).
        const okMime = ['image/jpeg', 'image/png', 'application/pdf'].includes(file.type);
        const okSize = file.size <= 5 * 1024 * 1024;
        if (!okMime || !okSize) {
            Swal.fire({
                icon: 'error',
                title: 'Format Tidak Didukung',
                text: (okMime ? 'Ukuran file melebihi 5 MB.' : 'Gunakan file JPG, PNG, atau PDF maksimal 5 MB.'),
                confirmButtonColor: '#1a5f51',
            });
            return;
        }

        // Pre-check OCR (advisory). Semua hasil — termasuk mismatch, error,
        // dan skipped — tetap menawarkan upload; user yang memutuskan.
        // Keyword cocok pun TIDAK menandakan dokumen valid/terverifikasi.
        let ocrOutcome: OcrOutcome = "skipped";
        try {
            ocrOutcome = await runOcrPrecheck(file, activeJenis);
        } catch {
            ocrOutcome = 'error';
        }

        const confirmUpload = (icon: 'warning' | 'info', title: string, text: string) =>
            Swal.fire({
                icon,
                title,
                text,
                showCancelButton: true,
                confirmButtonText: 'Ya, Tetap Unggah',
                cancelButtonText: 'Periksa Ulang File',
                confirmButtonColor: '#1a5f51',
            }).then(({ isConfirmed }) => isConfirmed);

        if (ocrOutcome === 'mismatch') {
            // Tawarkan lanjut, jangan blokir: keyword tidak cocok bukan
            // berarti dokumen tidak valid (foto buram sering gagal terbaca).
            const lanjut = await confirmUpload(
                'warning',
                'Kata Kunci Tidak Terdeteksi',
                'Pemeriksaan awal tidak menemukan kata kunci dokumen ini di gambar. Ini bisa terjadi bila foto kurang jelas — bukan berarti dokumen salah. Tetap unggah?',
            );
            if (!lanjut) return;
        } else if (ocrOutcome === 'error') {
            const lanjut = await confirmUpload(
                'info',
                'Pemeriksaan Awal Dilewati',
                'Pemeriksaan kata kunci otomatis gagal atau memakan waktu terlalu lama di perangkat ini. Ini tidak menghalangi unggah — file tetap akan dicek otomatis oleh server.',
            );
            if (!lanjut) return;
        } else if (ocrOutcome === 'skipped') {
            // PDF atau file terlalu besar: tidak dipindai. Jangan klaim
            // "sudah dipindai" — file tetap dicek otomatis oleh server.
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            const lanjut = await confirmUpload(
                'info',
                isPdf ? 'PDF Tidak Dipindai Kata Kunci' : 'File Dilewati Pemeriksaan Kata Kunci',
                isPdf
                    ? 'Pemeriksaan kata kunci hanya tersedia untuk foto/gambar (JPG/PNG). File PDF langsung diunggah dan tetap dicek otomatis oleh server (format, ukuran, struktur).'
                    : 'File ini tidak dipindai kata kunci karena ukurannya besar. File tetap dicek otomatis oleh server (format, ukuran, struktur).',
            );
            if (!lanjut) return;
        }

        Swal.fire({ title: 'Mengunggah...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const formData = new FormData();
        formData.append('jenis', activeJenis);
        formData.append('file', file);

        try {
            const res = await libAxios.post('/api/berkas/upload', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
            Swal.fire('Berhasil!', res.data?.message ?? `${activeLabel} berhasil diunggah dan lolos pemeriksaan teknis.`, 'success')
                .then(() => window.location.reload());
        } catch (error) {
            // Tampilkan pesan server asli (mis. 429 throttle / 422 format),
            // bukan pesan generik yang menelan penyebab sebenarnya.
            Swal.fire('Gagal', httpErrorMessage(error, 'Terjadi kesalahan saat mengunggah.'), 'error');
        }
    };
    const openUploadModal = (jenis: string, label: string) => {
        setActiveJenis(jenis);
        setActiveLabel(label);
        setIsModalOpen(true);
    };

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.headerMini}>
                <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                </div>
                <div>
                    <h2 className={styles.headerTitle}>Upload Berkas</h2>
                    <p className={styles.headerSubtitle}>Langkah 3 Pendaftaran</p>
                </div>
            </div>

            <div className={styles.contentWrap}>
                {/* Banner Info AI */}
                <div className={styles.infoBox} style={{ background: '#f5f3ff', color: '#6b46c1', borderColor: '#d6bcfa' }}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" style={{marginRight: '10px', flexShrink: 0}}><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    <div>
                        <h4 style={{margin: '0 0 5px 0', fontSize: '14px', fontWeight: 800}}>Pemeriksaan Teknis Otomatis</h4>
                        <p style={{margin: 0, fontSize: '12px'}}>File JPG/PNG/PDF dicek otomatis (format, ukuran maks. 5 MB, keterbacaan struktur) dan langsung diterima bila lolos — tanpa menunggu verifikasi manual. Foto JPG/PNG juga diperiksa kata kunci di perangkat Anda sebagai cek awal (bukan bukti keaslian dokumen); PDF tidak dipindai OCR.</p>
                    </div>
                </div>

                {isLoading ? <p style={{textAlign: 'center', marginTop: '20px'}}>Memuat data...</p> : (
                    <>
                        {/* KARTU 1: KARTU KELUARGA */}
                        <div className={styles.berkasCard}>
                            <div className={styles.berkasHeader}>
                                <div className={styles.berkasIcon}><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                                <div>
                                    <h3>Kartu Keluarga (KK)</h3>
                                    <p>Scan/foto KK asli. Pastikan tulisan terbaca jelas.</p>
                                </div>
                            </div>
                            
                            {berkasData?.kartu_keluarga ? (
                                <div className={styles.berkasUploaded}>
                                    {renderStatusBadge()}
                                    <div style={{display: 'flex', gap: '10px', marginTop: '15px'}}>
                                        <a href={`${API_URL}/api/berkas/file/${berkasData.user_id}/kartu_keluarga`} target="_blank" rel="noreferrer" className={styles.viewBtn}>Lihat File</a>
                                        <button className={styles.changeBtn} onClick={() => openUploadModal('kartu_keluarga', 'Kartu Keluarga (KK)')}>Ganti File</button>
                                    </div>
                                </div>
                            ) : (
                                <button className={styles.uploadBtn} onClick={() => openUploadModal('kartu_keluarga', 'Kartu Keluarga (KK)')}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                    Pindai KK
                                </button>
                            )}
                        </div>

                        {/* KARTU 2: AKTE KELAHIRAN */}
                        <div className={styles.berkasCard}>
                            <div className={styles.berkasHeader}>
                                <div className={styles.berkasIcon}><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                                <div>
                                    <h3>Akte Kelahiran Anak</h3>
                                    <p>Scan/foto Akte resmi. Pastikan judul akte terbaca jelas.</p>
                                </div>
                            </div>
                            
                            {berkasData?.akte_kelahiran ? (
                                <div className={styles.berkasUploaded}>
                                    {renderStatusBadge()}
                                    <div style={{display: 'flex', gap: '10px', marginTop: '15px'}}>
                                        <a href={`${API_URL}/api/berkas/file/${berkasData.user_id}/akte_kelahiran`} target="_blank" rel="noreferrer" className={styles.viewBtn}>Lihat File</a>
                                        <button className={styles.changeBtn} onClick={() => openUploadModal('akte_kelahiran', 'Akte Kelahiran')}>Ganti File</button>
                                    </div>
                                </div>
                            ) : (
                                <button className={styles.uploadBtn} onClick={() => openUploadModal('akte_kelahiran', 'Akte Kelahiran')}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                    Pindai Akte
                                </button>
                            )}
                        </div>

                        {berkasData?.kartu_keluarga && berkasData?.akte_kelahiran && (
                            <button className={styles.continueBtn} onClick={() => router.push('/pendaftar')}>
                                Lanjut ke Dashboard <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </button>
                        )}
                    </>
                )}
            </div>

            {/* =========================================================
                MODAL PILIHAN UPLOAD (GALERI / KAMERA)
            ========================================================= */}
            {isModalOpen && (
                <div className={styles.modalOverlay} onClick={() => setIsModalOpen(false)}>
                    <div className={styles.modalContent} onClick={(e) => e.stopPropagation()}>
                        <h3 className={styles.modalTitle}>Upload {activeLabel}</h3>
                        <p className={styles.modalDesc}>Pilih metode pengambilan dokumen. Pastikan gambar jelas dan terang.</p>
                        
                        <div className={styles.actionGrid}>
                            {/* OPSI 1: KAMERA LANGSUNG */}
                            <div className={styles.actionBox}>
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                                <p>Ambil Foto</p>
                                {/* Atribut capture="environment" bakal maksa HP buka kamera belakang */}
                                <input type="file" accept="image/jpeg,image/png,application/pdf" capture="environment" className={styles.hiddenInput} onChange={handleFileSelect} />
                            </div>

                            {/* OPSI 2: GALERI FILE */}
                            <div className={styles.actionBox}>
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                <p>Pilih dari Galeri</p>
                                <input type="file" accept="image/jpeg,image/png,application/pdf" className={styles.hiddenInput} onChange={handleFileSelect} />
                            </div>
                        </div>

                        <button className={styles.cancelBtn} onClick={() => setIsModalOpen(false)}>Batal</button>
                    </div>
                </div>
            )}
        </div>
    );
}