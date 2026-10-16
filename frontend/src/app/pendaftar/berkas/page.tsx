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


interface KeywordGroup {
    primary: string[];    // Kata kunci utama (skor tinggi)
    secondary: string[];  // Kata kunci pendukung (skor sedang)
}

const documentKeywords: Record<string, KeywordGroup> = {
    kartu_keluarga: {
        primary: [
            "KARTU KELUARGA",
            "KEPALA KELUARGA",
            "NO. KK",
            "NOMOR KK",
            "NO KK",
            "KELUARGA",
        ],
        secondary: [
            "REPUBLIK INDONESIA",
            "PROVINSI",
            "KABUPATEN",
            "KOTA",
            "KECAMATAN",
            "KELURAHAN",
            "DESA",
            "HUBUNGAN KELUARGA",
            "STATUS HUBUNGAN",
            "NIK",
            "NAMA LENGKAP",
            "JENIS KELAMIN",
            "AGAMA",
            "PENDIDIKAN",
            "JENIS PEKERJAAN",
            "TANGGAL LAHIR",
            "TEMPAT LAHIR",
            "GOLONGAN DARAH",
            "STATUS PERKAWINAN",
            "KEWARGANEGARAAN",
            "DOKUMEN IMIGRASI",
            "NAMA ORANG TUA",
            "AYAH",
            "IBU",
            "TANDA TANGAN",
            "KEPALA DINAS",
            "DINAS KEPENDUDUKAN",
            "PENCATATAN SIPIL",
        ],
    },
    akte_kelahiran: {
        primary: [
            "AKTA KELAHIRAN",
            "AKTE KELAHIRAN",
            "SURAT KELAHIRAN",
            "KELAHIRAN",
            "KENAL LAHIR",
        ],
        secondary: [
            "KUTIPAN",
            "PENCATATAN SIPIL",
            "WARGA NEGARA",
            "WARGANEGARA",
            "ANAK KE",
            "LAHIR DI",
            "DINAS KEPENDUDUKAN",
            "PEJABAT PENCATATAN",
            "NOMOR AKTA",
            "NO. AKTA",
            "NO AKTA",
            "MEMBERIKAN KUTIPAN",
            "DIPERTEMUKAN",
            "DITANDATANGANI",
            "HARI",
            "TANGGAL",
            "BULAN",
            "TAHUN",
            "REPUBLIK INDONESIA",
        ],
    },
};

function normalizeOcrText(rawText: string): string {
    return rawText
        .toUpperCase()
        .replace(/[\r\n\t]+/g, ' ')
        .replace(/[^A-Z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function getFuzzyOcrText(text: string): string {
    return text
        .replace(/0/g, 'O')
        .replace(/1/g, 'I')
        .replace(/5/g, 'S')
        .replace(/8/g, 'B')
        .replace(/6/g, 'G');
}

async function prepareImageForOcr(file: File, rotationDeg: number = 0): Promise<Blob | File> {
    if (typeof window === 'undefined') return file;
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            const MAX_DIM = 1800;
            let srcW = img.width;
            let srcH = img.height;

            if (srcW > MAX_DIM || srcH > MAX_DIM) {
                if (srcW > srcH) {
                    srcH = Math.round((srcH * MAX_DIM) / srcW);
                    srcW = MAX_DIM;
                } else {
                    srcW = Math.round((srcW * MAX_DIM) / srcH);
                    srcH = MAX_DIM;
                }
            }

            const canvas = document.createElement('canvas');
            const isSideways = rotationDeg === 90 || rotationDeg === 270;
            canvas.width = isSideways ? srcH : srcW;
            canvas.height = isSideways ? srcW : srcH;

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                resolve(file);
                return;
            }

            ctx.save();
            if (rotationDeg === 90) {
                ctx.translate(canvas.width, 0);
                ctx.rotate((90 * Math.PI) / 180);
            } else if (rotationDeg === 180) {
                ctx.translate(canvas.width, canvas.height);
                ctx.rotate((180 * Math.PI) / 180);
            } else if (rotationDeg === 270) {
                ctx.translate(0, canvas.height);
                ctx.rotate((270 * Math.PI) / 180);
            }

            // Gambar ulang
            ctx.drawImage(img, 0, 0, srcW, srcH);
            ctx.restore();

            // Binarization & contrast boost untuk mempertajam teks dokumen resmi
            try {
                const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const data = imgData.data;
                const contrast = 25;
                const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
                for (let i = 0; i < data.length; i += 4) {
                    const gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                    const contrasted = factor * (gray - 128) + 128;
                    const clamped = Math.max(0, Math.min(255, contrasted));
                    data[i] = clamped;
                    data[i + 1] = clamped;
                    data[i + 2] = clamped;
                }
                ctx.putImageData(imgData, 0, 0);
            } catch {
                // Fallback jika getImageData dibatasi cross-origin
            }

            canvas.toBlob(
                (blob) => {
                    resolve(blob ?? file);
                },
                'image/jpeg',
                0.90
            );
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
}

const OCR_TIMEOUT_MS = 25000;

type OcrOutcome = "match" | "mismatch" | "skipped" | "error";

function evaluateTextKeywords(rawText: string, jenis: string): 'match' | 'mismatch' {
    const config = documentKeywords[jenis];
    if (!config) return 'match';

    const normalized = normalizeOcrText(rawText);
    const fuzzy = getFuzzyOcrText(normalized);

    // Cek keberadaan pola NIK (16 digit atau variasi OCR-nya) khusus untuk KK
    const hasNikPattern = /\b\d{14,16}\b/.test(normalized) || /\b[0-9OISBG]{14,16}\b/.test(fuzzy);

    let score = 0;

    for (const kw of config.primary) {
        const normKw = normalizeOcrText(kw);
        const fuzzyKw = getFuzzyOcrText(normKw);
        if (normalized.includes(normKw) || fuzzy.includes(fuzzyKw)) {
            score += 3;
            break;
        }
    }

    for (const kw of config.secondary) {
        const normKw = normalizeOcrText(kw);
        const fuzzyKw = getFuzzyOcrText(normKw);
        if (normalized.includes(normKw) || fuzzy.includes(fuzzyKw)) {
            score += 1;
        }
    }

    if (jenis === 'kartu_keluarga' && hasNikPattern) {
        score += 2;
    }

    // Cukup skor >= 1 (minimal 1 kata kunci utama atau 1 kata pendukung/NIK terdeteksi)
    return score >= 1 ? 'match' : 'mismatch';
}

/** Hasil pre-check: 'match' | 'mismatch' | 'skipped' | 'error' */
async function runOcrPrecheck(
    file: File,
    jenis: string,
): Promise<'match' | 'mismatch' | 'skipped' | 'error'> {
    const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
    if (isPdf) return 'skipped';

    if (!file.type.startsWith('image/')) return 'skipped';

    if (!documentKeywords[jenis]) return 'skipped';

    try {
        const worker = await Tesseract.createWorker('ind');
        try {
            // Putaran 1: Cek orientasi asli dokumen (0 derajat)
            const processedFile0 = await prepareImageForOcr(file, 0);
            const result0 = await Promise.race([
                worker.recognize(processedFile0),
                new Promise<never>((_, reject) =>
                    setTimeout(() => reject(new Error('ocr-timeout')), OCR_TIMEOUT_MS)),
            ]);

            const text0 = result0?.data?.text ?? '';
            if (evaluateTextKeywords(text0, jenis) === 'match') {
                return 'match';
            }

            // Putaran 2: Banyak orang tua memotret KK landscape secara portrait (posisi miring 90° atau 270°)
            // Jika putaran 1 belum cocok, coba rotasi 270 derajat (putar 90° counter-clockwise)
            const processedFile270 = await prepareImageForOcr(file, 270);
            const result270 = await Promise.race([
                worker.recognize(processedFile270),
                new Promise<never>((_, reject) =>
                    setTimeout(() => reject(new Error('ocr-timeout')), OCR_TIMEOUT_MS)),
            ]);

            const text270 = result270?.data?.text ?? '';
            if (evaluateTextKeywords(text270, jenis) === 'match') {
                return 'match';
            }

            // Putaran 3: Coba rotasi 90 derajat searah jarum jam jika orientasi HP sebaliknya
            const processedFile90 = await prepareImageForOcr(file, 90);
            const result90 = await Promise.race([
                worker.recognize(processedFile90),
                new Promise<never>((_, reject) =>
                    setTimeout(() => reject(new Error('ocr-timeout')), OCR_TIMEOUT_MS)),
            ]);

            const text90 = result90?.data?.text ?? '';
            return evaluateTextKeywords(text90, jenis);
        } finally {
            void worker.terminate().catch(() => undefined);
        }
    } catch {
        return 'error';
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

    // STATE UNTUK SCANNING / ANALYZING ANIMATION
    const [isScanning, setIsScanning] = useState(false);
    const [scanStage, setScanStage] = useState<'reading' | 'analyzing' | 'uploading'>('reading');

    // STATE UNTUK DIALOG MODAL PREMIUM (NO GRADIENT, NO EMOJI)
    const [dialogState, setDialogState] = useState<{
        isOpen: boolean;
        type: 'warning' | 'info' | 'error' | 'success';
        title: string;
        message: string;
        confirmText?: string;
        cancelText?: string;
        onConfirm: () => void;
        onCancel?: () => void;
    }>({
        isOpen: false,
        type: 'info',
        title: '',
        message: '',
        onConfirm: () => {},
    });

    const closeDialog = () => {
        setDialogState((prev) => ({ ...prev, isOpen: false }));
    };

    const showDialog = (
        type: 'warning' | 'info' | 'error' | 'success',
        title: string,
        message: string,
        onConfirm?: () => void,
        confirmText: string = 'Mengerti',
        cancelText?: string,
        onCancel?: () => void,
    ) => {
        setDialogState({
            isOpen: true,
            type,
            title,
            message,
            confirmText,
            cancelText,
            onConfirm: () => {
                closeDialog();
                if (onConfirm) onConfirm();
            },
            onCancel: () => {
                closeDialog();
                if (onCancel) onCancel();
            },
        });
    };

    const askConfirm = (type: 'warning' | 'info', title: string, message: string): Promise<boolean> => {
        return new Promise((resolve) => {
            setDialogState({
                isOpen: true,
                type,
                title,
                message,
                confirmText: 'Ya, Tetap Unggah',
                cancelText: 'Periksa Ulang File',
                onConfirm: () => {
                    closeDialog();
                    resolve(true);
                },
                onCancel: () => {
                    closeDialog();
                    resolve(false);
                },
            });
        });
    };

    /**
     * Membuka berkas lewat XHR ber-kredensial lalu menampilkannya sebagai
     * blob URL. Endpoint berkas memakai sesi Sanctum: permintaan navigasi
     * langsung (tab baru tanpa Referer) tidak dianggap stateful sehingga
     * selalu 401 (halaman blank). XHR dari halaman ini menyertakan Referer
     * dan cookie, jadi otentikasi berjalan normal.
     */
    const handleViewFile = async (userId: number | string | undefined, jenis: string) => {
        if (userId === undefined || userId === null) {
            showDialog('error', 'Berkas Belum Tersedia', 'Data berkas belum termuat. Silakan muat ulang halaman.', undefined, 'Tutup');
            return;
        }
        // Buka tab baru segera dan sinkron dengan klik agar tidak diblokir
        // popup blocker, baru isi lokasinya setelah blob siap.
        const newTab = window.open('', '_blank');

        try {
            const res = await libAxios.get<Blob>(`/api/berkas/file/${userId}/${jenis}`, { responseType: 'blob' });
            const blobUrl = URL.createObjectURL(res.data);

            if (newTab && !newTab.closed) {
                newTab.location.href = blobUrl;
            } else {
                // Popup diblokir browser: fallback unduh file.
                const a = document.createElement('a');
                a.href = blobUrl;
                a.download = `dokumen-${jenis}`;
                document.body.appendChild(a);
                a.click();
                a.remove();
            }

            // Revoke belakangan supaya PDF/gambar di tab baru sempat termuat.
            setTimeout(() => URL.revokeObjectURL(blobUrl), 120000);
        } catch (error) {
            if (newTab && !newTab.closed) newTab.close();
            showDialog(
                'error',
                'Gagal Membuka Berkas',
                httpErrorMessage(error, 'Berkas tidak dapat dibuka saat ini. Silakan coba lagi.'),
                undefined,
                'Tutup',
            );
        }
    };

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
            showDialog(
                'error',
                'Format Tidak Didukung',
                okMime ? 'Ukuran file melebihi batas maksimal 5 MB.' : 'Gunakan file JPG, PNG, atau PDF maksimal 5 MB.',
            );
            return;
        }

        // Pre-check OCR (advisory). Semua hasil — termasuk mismatch, error,
        // dan skipped — tetap menawarkan upload; user yang memutuskan.
        // Keyword cocok pun TIDAK menandakan dokumen valid/terverifikasi.
        let ocrOutcome: OcrOutcome = "skipped";
        setIsScanning(true);
        setScanStage('reading');

        try {
            // Berikan feedback visual bahwa dokumen sedang diproses
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            if (!isPdf && file.type.startsWith('image/')) {
                setScanStage('analyzing');
            }
            ocrOutcome = await runOcrPrecheck(file, activeJenis);
        } catch {
            ocrOutcome = 'error';
        } finally {
            setIsScanning(false);
        }

        if (ocrOutcome === 'mismatch') {
            // Tawarkan lanjut, jangan blokir: keyword tidak cocok bukan
            // berarti dokumen tidak valid (foto buram sering gagal terbaca).
            const lanjut = await askConfirm(
                'warning',
                'Kata Kunci Tidak Terdeteksi',
                'Pemeriksaan awal tidak menemukan kata kunci dokumen ini di gambar. Ini bisa terjadi bila foto kurang jelas — bukan berarti dokumen salah. Tetap unggah?',
            );
            if (!lanjut) return;
        } else if (ocrOutcome === 'error') {
            const lanjut = await askConfirm(
                'info',
                'Pemeriksaan Awal Dilewati',
                'Pemeriksaan kata kunci otomatis gagal atau memakan waktu terlalu lama di perangkat ini. Ini tidak menghalangi unggah — file tetap akan dicek otomatis oleh server.',
            );
            if (!lanjut) return;
        } else if (ocrOutcome === 'skipped') {
            // PDF atau file terlalu besar: tidak dipindai. Jangan klaim
            // "sudah dipindai" — file tetap dicek otomatis oleh server.
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            const lanjut = await askConfirm(
                'info',
                isPdf ? 'PDF Tidak Dipindai Kata Kunci' : 'File Dilewati Pemeriksaan Kata Kunci',
                isPdf
                    ? 'Pemeriksaan kata kunci hanya tersedia untuk foto/gambar (JPG/PNG). File PDF langsung diunggah dan tetap dicek otomatis oleh server (format, ukuran, struktur).'
                    : 'File ini tidak dipindai kata kunci karena ukurannya besar. File tetap dicek otomatis oleh server (format, ukuran, struktur).',
            );
            if (!lanjut) return;
        }

        setIsScanning(true);
        setScanStage('uploading');

        const formData = new FormData();
        formData.append('jenis', activeJenis);
        formData.append('file', file);

        try {
            const res = await libAxios.post('/api/berkas/upload', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
            setIsScanning(false);
            showDialog(
                'success',
                'Unggah Berhasil',
                res.data?.message ?? `${activeLabel} berhasil diunggah dan lolos verifikasi teknis.`,
                () => window.location.reload(),
                'Selesai',
            );
        } catch (error) {
            setIsScanning(false);
            showDialog(
                'error',
                'Gagal Mengunggah',
                httpErrorMessage(error, 'Terjadi kesalahan saat mengunggah berkas.'),
                undefined,
                'Tutup',
            );
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
                                        <button type="button" className={styles.viewBtn} onClick={() => handleViewFile(berkasData.user_id, 'kartu_keluarga')}>Lihat File</button>
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
                                        <button type="button" className={styles.viewBtn} onClick={() => handleViewFile(berkasData.user_id, 'akte_kelahiran')}>Lihat File</button>
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

            {/* =========================================================
                PREMIUM SCANNING & VERIFICATION OVERLAY (NO EMOJI)
            ========================================================= */}
            {isScanning && (
                <div className={styles.scanningOverlay}>
                    <div className={styles.scanningCard}>
                        <div className={styles.scannerBeam}>
                            <div className={styles.scannerPulse}></div>
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M4 7V4h3"></path>
                                <path d="M20 7V4h-3"></path>
                                <path d="M4 17v3h3"></path>
                                <path d="M20 17v3h-3"></path>
                                <line x1="4" y1="12" x2="20" y2="12"></line>
                                <circle cx="12" cy="12" r="2"></circle>
                            </svg>
                        </div>

                        <div className={styles.scanningBadge}>
                            <span className={styles.scanningBadgeDot}></span>
                            {scanStage === 'reading' && 'Membaca Berkas'}
                            {scanStage === 'analyzing' && 'Analisis Dokumen'}
                            {scanStage === 'uploading' && 'Sinkronisasi Server'}
                        </div>

                        <h3 className={styles.scanningTitle}>
                            {scanStage === 'reading' && 'Memindai Struktur Dokumen'}
                            {scanStage === 'analyzing' && 'Menganalisis Keterbacaan'}
                            {scanStage === 'uploading' && 'Mengenkripsi & Mengunggah'}
                        </h3>

                        <p className={styles.scanningDesc}>
                            {scanStage === 'reading' && 'Sistem sedang membaca format dan memeriksa ketajaman gambar dokumen Anda.'}
                            {scanStage === 'analyzing' && 'Memverifikasi kesesuaian kata kunci identitas dokumen secara otomatis.'}
                            {scanStage === 'uploading' && 'Mengamankan dan mentransfer berkas ke penyimpanan server terenkripsi.'}
                        </p>

                        <div className={styles.scanningProgressBg}>
                            <div className={styles.scanningProgressBar}></div>
                        </div>

                        <p className={styles.scanningStepText}>
                            Mohon tunggu sebentar, proses ini berjalan otomatis...
                        </p>
                    </div>
                </div>
            )}

            {/* =========================================================
                PREMIUM DIALOG / CONFIRMATION MODAL (FLAT, NO GRADIENT)
            ========================================================= */}
            {dialogState.isOpen && (
                <div className={styles.dialogOverlay}>
                    <div className={styles.dialogCard}>
                        {/* Icon Sesuai Type */}
                        <div
                            className={`${styles.dialogIcon} ${
                                dialogState.type === 'warning'
                                    ? styles.dialogIconWarning
                                    : dialogState.type === 'error'
                                    ? styles.dialogIconError
                                    : dialogState.type === 'success'
                                    ? styles.dialogIconSuccess
                                    : styles.dialogIconInfo
                            }`}
                        >
                            {dialogState.type === 'warning' && (
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path>
                                    <line x1="12" y1="9" x2="12" y2="13"></line>
                                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                </svg>
                            )}
                            {dialogState.type === 'info' && (
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="16" x2="12" y2="12"></line>
                                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                </svg>
                            )}
                            {dialogState.type === 'error' && (
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                            )}
                            {dialogState.type === 'success' && (
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="9 12 12 15 16 10"></polyline>
                                </svg>
                            )}
                        </div>

                        <h3 className={styles.dialogTitle}>{dialogState.title}</h3>
                        <p className={styles.dialogMessage}>{dialogState.message}</p>

                        <div className={styles.dialogActions}>
                            {dialogState.cancelText && (
                                <button
                                    type="button"
                                    className={styles.dialogCancelBtn}
                                    onClick={dialogState.onCancel ?? closeDialog}
                                >
                                    {dialogState.cancelText}
                                </button>
                            )}
                            <button
                                type="button"
                                className={styles.dialogConfirmBtn}
                                onClick={dialogState.onConfirm}
                            >
                                {dialogState.confirmText ?? 'Mengerti'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}