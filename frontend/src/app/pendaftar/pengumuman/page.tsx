// src/app/pendaftar/pengumuman/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import styles from './pengumuman.module.css';

export default function PengumumanPage() {
    const router = useRouter();
    const [user, setUser] = useState<{ name: string } | null>(null);
    const [jenjang, setJenjang] = useState<string>('');
    
    // State Countdown Terproteksi Server
    const [waktuTarget, setWaktuTarget] = useState<number | null>(null);
    const [timeLeft, setTimeLeft] = useState({ days: 0, hours: 0, minutes: 0, seconds: 0 });
    const [isTimeUp, setIsTimeLeftUp] = useState(false);
    const [statusSecured, setStatusSecured] = useState('BELUM');

    useEffect(() => {
        let active = true;
        Promise.all([
            libAxios.get('/api/user'),
            libAxios.get('/api/payment/status').catch(() => ({ data: null }))
        ]).then(async ([userRes, paymentRes]) => {
            if (!active) return;
            setUser(userRes.data);
            const userJenjang = paymentRes.data?.payment?.jenjang || 'SD';
            setJenjang(userJenjang);

            // Mengambil waktu rilis mutlak dan status kelulusan terenkripsi dari server
            try {
                const resJadwal = await libAxios.get(`/api/pengumuman/waktu-rilis/${userJenjang}`);
                if (!active) return;
                if (resJadwal.data && resJadwal.data.waktu) {
                    setWaktuTarget(new Date(resJadwal.data.waktu).getTime());
                    setStatusSecured(resJadwal.data.secured_status);
                } else {
                    setWaktuTarget(null);
                    setStatusSecured('BELUM');
                }
            } catch {
                if (!active) return;
                setWaktuTarget(null);
                setStatusSecured('BELUM');
            }

        }).catch(() => {
            if (active) router.push('/login');
        });
        return () => { active = false; };
    }, [router]);

    // Engine Perhitungan Hitung Mundur
    useEffect(() => {
        if (!waktuTarget) return;

        let active = true;
        const cekAwal = new Date().getTime();
        if (waktuTarget - cekAwal <= 0) {
            const timeout = setTimeout(() => setIsTimeLeftUp(true), 0);
            return () => clearTimeout(timeout);
        }

        const interval = setInterval(() => {
            const sekarang = new Date().getTime();
            const selisih = waktuTarget - sekarang;

            if (selisih <= 0) {
                setIsTimeLeftUp(true);
                clearInterval(interval);
                
                libAxios.get(`/api/pengumuman/waktu-rilis/${jenjang}`)
                    .then(res => { if (active) setStatusSecured(res.data.secured_status); })
                    .catch(() => { if (active) setStatusSecured('BELUM'); });
            } else {
                const days = Math.floor(selisih / (1000 * 60 * 60 * 24));
                const hours = Math.floor((selisih % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((selisih % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((selisih % (1000 * 60)) / 1000);
                setTimeLeft({ days, hours, minutes, seconds });
            }
        }, 1000);

        return () => { active = false; clearInterval(interval); };
    }, [waktuTarget, jenjang]);

    if (!user) return <div className={styles.loading}>Memuat Lembar Pengumuman...</div>;

    const isSMP = jenjang === 'SMP';

    return (
        <div className={styles.mobileContainer}>
            <button className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Kembali
            </button>

            <div className={styles.headerTitle}>
                <h1>Pengumuman Hasil Seleksi</h1>
                <p>Penerimaan Murid Baru Terintegrasi Sistem</p>
            </div>

            {/* INTERFACE 1: JADWAL BELUM DI-SET OLEH PANITIA */}
            {!waktuTarget && (
                <div className={styles.waitingCard}>
                    <div className={styles.clockIcon}>
                        <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h2>Bismillah, Menunggu Ketetapan</h2>
                    <p>Panitia SPMB Al-Mubarak sedang merangkum hasil penilaian lembar observasi dan tes akademik Ananda. Mari kita iringi proses ini dengan doa.</p>
                </div>
            )}

            {/* INTERFACE 2: COUNTDOWN AKTIF */}
            {waktuTarget && !isTimeUp && (
                <div className={styles.countdownWrapperCard}>
                    <div className={styles.timerBadge}>GERBANG SELEKSI</div>
                    <h2>Menuju Pengumuman Resmi</h2>
                    <p>Keputusan hasil seleksi masuk {isSMP ? 'SMPIT' : 'SDIT'} Al-Mubarak akan terbuka secara serentak dalam waktu:</p>
                    
                    <div className={styles.countdownGrid}>
                        <div className={styles.timeBox}>
                            <div className={styles.timeNum}>{String(timeLeft.days).padStart(2, '0')}</div>
                            <div className={styles.timeLabel}>Hari</div>
                        </div>
                        <div className={styles.timeBox}>
                            <div className={styles.timeNum}>{String(timeLeft.hours).padStart(2, '0')}</div>
                            <div className={styles.timeLabel}>Jam</div>
                        </div>
                        <div className={styles.timeBox}>
                            <div className={styles.timeNum}>{String(timeLeft.minutes).padStart(2, '0')}</div>
                            <div className={styles.timeLabel}>Menit</div>
                        </div>
                        <div className={styles.timeBox}>
                            <div className={styles.timeNum}>{String(timeLeft.seconds).padStart(2, '0')}</div>
                            <div className={styles.timeLabel}>Detik</div>
                        </div>
                    </div>

                    <div className={styles.noticeBoxFooter}>
                        InsyaAllah, halaman akan terbuka otomatis begitu waktu habis.
                    </div>
                </div>
            )}

            {/* INTERFACE 2.5: WAKTU HABIS TAPI STATUS MASIH 'BELUM' */}
            {waktuTarget && isTimeUp && statusSecured === 'BELUM' && (
                <div className={styles.waitingCard}>
                    <div className={styles.clockIcon} style={{ color: '#10b981' }}>
                        <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.59-9.21l5.67-1.36"/></svg>
                    </div>
                    <h2 style={{color: '#1a5f51'}}>Membuka Gerbang Seleksi...</h2>
                    <p>Waktu telah berakhir! Sistem sedang memuat hasil kelulusan Ananda.</p>
                </div>
            )}

            {/* INTERFACE 3: KONDISI TIDAK LULUS */}
            {waktuTarget && isTimeUp && statusSecured === 'TIDAK_LULUS' && (
                <div className={styles.failedCard}>
                    <div className={styles.failedIcon}>
                        <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </div>
                    <h2>Qadarullah Wamaa Syaa&apos;a Fa&apos;ala</h2>
                    <p>Assalamu&apos;alaikum Ayah/Bunda, panitia SPMB mengumumkan bahwa Ananda <strong>{user.name}</strong> belum dapat bergabung dengan {isSMP ? 'SMPIT' : 'SDIT'} Al-Mubarak karena keterbatasan kuota.</p>
                    <p style={{ marginTop: '15px', fontSize: '12.5px', color: '#7f1d1d' }}>Pilihan Allah senantiasa menyimpan hikmah yang jauh lebih indah. Jazakumullah Khairan Katsiran atas kepercayaan Ayah/Bunda.</p>
                </div>
            )}

            {/* INTERFACE 4: KONDISI LULUS (FLAT DESIGN, NO KUNING) */}
            {waktuTarget && isTimeUp && statusSecured === 'LULUS' && (
                <>
                    <div className={styles.successCard}>
                        {/* Hujan Confetti Flat Kertas (Sekali Jalan) */}
                        <div className={styles.confettiBg}></div>
                        
                        <div className={styles.successBadge}>ALHAMDULILLAH, DITERIMA!</div>
                        
                        <div className={styles.successIconWrapper}>
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M2 12h20"></path><path d="M12 2l4 4-4 4-4-4 4-4z"></path><path d="M19 12v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6"></path><path d="M12 22v-6"></path></svg>
                        </div>
                        
                        <h2 className={styles.successTitle}>Barakallah Fikum!</h2>
                        <p className={styles.successDesc}>
                            Selamat kepada Ayah/Bunda, atas izin Allah Swt., Ananda <strong>{user.name}</strong> secara resmi dinyatakan <strong style={{color: 'white'}}>LULUS SELEKSI</strong> dan diterima di {isSMP ? 'SMPIT' : 'SDIT'} Al-Mubarak.
                        </p>

                        <div className={styles.documentActionArea}>
                            <p className={styles.docInfo}>Silakan unduh surat keputusan resmi di bawah ini:</p>
                            
                            <div className={styles.btnGroup}>
                                <button className={styles.downloadBtnMain} onClick={() => alert('Mengunduh SK Kelulusan...')}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    Surat Keputusan Kelulusan (SK)
                                </button>

                                {!isSMP && (
                                    <button className={styles.downloadBtnSecondary} onClick={() => alert('Mengunduh Lembar Hasil Deskripsi Observasi...')}>
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                        Hasil Deskripsi Observasi
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* TOMBOL DIPISAH KE BAWAH BIAR JELAS (WARNA PUTIH) */}
                    <div className={styles.actionWrapperBottom}>
                        <button className={styles.continueDaftarUlangBtn} onClick={() => router.push('/pendaftar/daftar-ulang')}>
                            Selesaikan Daftar Ulang <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{flexShrink: 0}}><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
