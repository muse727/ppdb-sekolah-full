// src/app/pendaftar/berkas/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import Tesseract from 'tesseract.js'; 
import styles from './berkas.module.css';

export default function BerkasPage() {
    const router = useRouter();
    const [berkasData, setBerkasData] = useState<any>({});
    const [isLoading, setIsLoading] = useState(true);
    
    // STATE UNTUK MODAL KUSTOM
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [activeJenis, setActiveJenis] = useState('');
    const [activeLabel, setActiveLabel] = useState('');

    const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

    useEffect(() => {
        libAxios.get('/api/berkas')
            .then(res => { if(res.data) setBerkasData(res.data); })
            .catch(err => console.log(err))
            .finally(() => setIsLoading(false));
    }, []);

    // =================================================================
    // ALGORITMA AI OCR
    // =================================================================
    const checkWithOCR = async (file: File, jenis: string): Promise<boolean> => {
        return new Promise(async (resolve) => {
            Swal.fire({
                title: 'AI Membaca Dokumen...',
                html: 'Mengekstrak teks untuk memvalidasi jenis dokumen.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const { data: { text } } = await Tesseract.recognize(file, 'ind');
                const extractedText = text.toUpperCase();
                let isValid = false;

                if (jenis === 'kartu_keluarga') {
                    if (extractedText.includes('KARTU KELUARGA') || extractedText.includes('KEPALA KELUARGA') || extractedText.includes('NIK')) {
                        isValid = true;
                    }
                } else if (jenis === 'akte_kelahiran') {
                    if (extractedText.includes('AKTA KELAHIRAN') || extractedText.includes('KUTIPAN') || extractedText.includes('PENCATATAN SIPIL')) {
                        isValid = true;
                    }
                }

                if (isValid) {
                    resolve(true);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Dokumen Tidak Sesuai!',
                        text: `Sistem AI kami tidak menemukan kata kunci ${jenis === 'kartu_keluarga' ? '"Kartu Keluarga"' : '"Akta Kelahiran"'} pada gambar ini. Pastikan foto tidak buram dan pencahayaan baik.`,
                        confirmButtonColor: '#1a5f51'
                    });
                    resolve(false);
                }

            } catch (error) {
                console.error("OCR Error:", error);
                Swal.fire('Error', 'Sistem AI gagal membaca gambar. Coba perkecil ukuran file atau foto dengan pencahayaan lebih baik.', 'error');
                resolve(false);
            }
        });
    };

    // Fungsi pas ortu milih foto dari Modal
    const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        setIsModalOpen(false); // Tutup modalnya

        const isDocumentValid = await checkWithOCR(file, activeJenis);
        
        if (isDocumentValid) {
            Swal.fire({ title: 'Dokumen Valid!', text: 'Mengunggah ke server...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            const formData = new FormData();
            formData.append('jenis', activeJenis);
            formData.append('file', file);

            try {
                await libAxios.post('/api/berkas/upload', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
                Swal.fire('Berhasil!', `${activeLabel} telah divalidasi oleh AI dan tersimpan.`, 'success')
                    .then(() => window.location.reload());
            } catch (error) {
                Swal.fire('Gagal', 'Terjadi kesalahan saat mengunggah.', 'error');
            }
        }
    };

    // Fungsi buka modal
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
                        <h4 style={{margin: '0 0 5px 0', fontSize: '14px', fontWeight: 800}}>Validasi AI Aktif</h4>
                        <p style={{margin: 0, fontSize: '12px'}}>Dokumen Anda akan dipindai oleh AI. Pastikan foto tegak lurus dan terang agar langsung disetujui.</p>
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
                                    <div className={styles.statusVerified}>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg> Tervalidasi AI
                                    </div>
                                    <div style={{display: 'flex', gap: '10px', marginTop: '15px'}}>
                                        <a href={`${API_URL}/storage/${berkasData.kartu_keluarga}`} target="_blank" rel="noreferrer" className={styles.viewBtn}>Lihat File</a>
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
                                    <div className={styles.statusVerified}>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg> Tervalidasi AI
                                    </div>
                                    <div style={{display: 'flex', gap: '10px', marginTop: '15px'}}>
                                        <a href={`${API_URL}/storage/${berkasData.akte_kelahiran}`} target="_blank" rel="noreferrer" className={styles.viewBtn}>Lihat File</a>
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
                                <input type="file" accept="image/*" capture="environment" className={styles.hiddenInput} onChange={handleFileSelect} />
                            </div>

                            {/* OPSI 2: GALERI FILE */}
                            <div className={styles.actionBox}>
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                <p>Pilih dari Galeri</p>
                                <input type="file" accept="image/*" className={styles.hiddenInput} onChange={handleFileSelect} />
                            </div>
                        </div>

                        <button className={styles.cancelBtn} onClick={() => setIsModalOpen(false)}>Batal</button>
                    </div>
                </div>
            )}
        </div>
    );
}