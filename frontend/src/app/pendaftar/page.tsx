// src/app/pendaftar/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './pendaftar.module.css';

export default function DashboardPendaftar() {
    const router = useRouter();
    const [user, setUser] = useState<any>(null);
    const [jenjang, setJenjang] = useState<string>(''); // Simpan jenjang buat bedain pesan SD/SMP
    const [currentStep, setCurrentStep] = useState(1); 

    useEffect(() => {
        // Cek Auth User
        libAxios.get('/api/user')
            .then((res) => {
                if(res.data.role !== 'orang_tua') {
                    router.push('/dashboard'); 
                } else {
                    setUser(res.data);
                    
                    // =======================================================
                    // LOGIKA SEQUENTIAL LOCKING PINTAR (PARALLEL FETCH)
                    // =======================================================
                    Promise.all([
                        libAxios.get('/api/payment/status').catch(() => ({ data: { has_payment: false } })),
                        libAxios.get('/api/biodata').catch(() => ({ data: null })),
                        libAxios.get('/api/berkas').catch(() => ({ data: null }))
                    ]).then(([paymentRes, biodataRes, berkasRes]) => {
                        let step = 1;
                        let userJenjang = '';
                        
                        // Cek Pembayaran
                        if (paymentRes.data?.payment?.status === 'SUCCESS') {
                            step = 2; 
                            userJenjang = paymentRes.data.payment.jenjang || ''; // Simpan jenjang SD/SMP
                            
                            // Cek Biodata
                            if (biodataRes.data && biodataRes.data.id) {
                                step = 3; 
                                
                                // Cek Berkas (Harus KK & Akte dua-duanya)
                                if (berkasRes.data && berkasRes.data.kartu_keluarga && berkasRes.data.akte_kelahiran) {
                                    // BUKA LANGKAH OBSERVASI (STEP 5)
                                    step = 5; 
                                    
                                    // Cek Jika Admin Udah Set Jadwal Observasi
                                    if (res.data.jadwal_observasi) {
                                        // BUKA LANGKAH PENGUMUMAN (STEP 6)
                                        step = 6;

                                        // Cek Jika Udah Dinyatakan LULUS
                                        if (res.data.status_kelulusan === 'LULUS') {
                                            // BUKA LANGKAH DAFTAR ULANG (STEP 7)
                                            step = 7;
                                        }
                                    }
                                }
                            }
                        }
                        setJenjang(userJenjang);
                        setCurrentStep(step);
                    });
                }
            })
            .catch(() => router.push('/login'));
    }, [router]);

    const handleLogout = async () => {
        await libAxios.post('/api/logout');
        router.push('/login');
    };

    // Progress bar disesuaikan (sekarang maks step ada 7 untuk 100%)
    const progressPercent = Math.round(((currentStep - 1) / 6) * 100) || 5; 

    // === TEXT PROGRESS PINTAR (BEDAIN SD & SMP) ===
    const getProgressText = () => {
        switch(currentStep) {
            case 1: return { title: "Tindakan Diperlukan: Pembayaran", desc: "Silakan klik menu Pembayaran untuk memilih jenjang (SD/SMP) dan mendapatkan instruksi transfer DOKU." };
            case 2: return { title: "Tindakan Diperlukan: Isi Biodata", desc: "Pembayaran terverifikasi! Mohon lengkapi biodata calon siswa melalui menu Biodata." };
            case 3: return { title: "Tindakan Diperlukan: Upload Berkas", desc: "Silakan upload scan Kartu Keluarga dan Akte Kelahiran yang akan divalidasi oleh sistem." };
            case 4: return { title: "Menunggu Verifikasi Berkas", desc: "Berkas sedang divalidasi. Mohon tunggu." }; // (Jarang kepake karena kita auto-pass)
            case 5: 
                // Cek kalau dia SMP, pesannya nyuruh CBT. Kalau SD nyuruh datang Observasi.
                if (jenjang === 'SMP') {
                    return { title: "Tindakan Diperlukan: Cek Ujian CBT", desc: "Berkas lengkap! Silakan klik menu Observasi untuk melihat Jadwal & Token Ujian CBT Ananda." };
                } else {
                    return { title: "Tindakan Diperlukan: Cek Jadwal Observasi", desc: "Berkas lengkap! Silakan klik menu Observasi untuk melihat Jadwal & Ruangan Observasi Ananda." };
                }
            case 6: return { title: "Menunggu Pengumuman", desc: "Jadwal observasi telah ditentukan. Pantau terus menu Pengumuman untuk melihat hasil akhir seleksi." };
            case 7: return { title: "Alhamdulillah, Lulus!", desc: "Selamat Ananda diterima! Silakan lanjut ke menu Daftar Ulang untuk penyelesaian administrasi." };
            default: return { title: "Menunggu Pengumuman", desc: "Terima kasih, seluruh tahapan selesai. Pantau terus aplikasi ini." };
        }
    };
    const progressInfo = getProgressText();

    if (!user) return <div style={{textAlign: 'center', marginTop: '50px', color: '#1a5f51', fontWeight: 800}}>Memuat Dashboard...</div>;

    const menus = [
        { id: 1, title: 'Pembayaran', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>, colorClass: styles.bgWarning, path: '/pendaftar/pembayaran' },
        { id: 2, title: 'Biodata', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>, colorClass: styles.bgActive, path: '/pendaftar/biodata' },
        { id: 3, title: 'Berkas', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>, colorClass: styles.bgActive, path: '/pendaftar/berkas' },
        // GANTI NAMA MENU BERDASARKAN JENJANG
        { id: 4, title: jenjang === 'SMP' ? 'Ujian CBT' : 'Observasi', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>, colorClass: styles.bgActive, path: '/pendaftar/observasi' },
        { id: 5, title: 'Pengumuman', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>, colorClass: styles.bgActive, path: '/pendaftar/pengumuman' },
        { id: 6, title: 'Daftar Ulang', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>, colorClass: styles.bgActive, path: '/pendaftar/daftar-ulang' },
    ];

    const handleMenuClick = (menu: any) => {
        // Konfigurasi Kunci Gembok Berdasarkan Step
        const requiredStep = menu.id === 4 ? 5 : menu.id === 5 ? 6 : menu.id === 6 ? 7 : menu.id; 
        
        if (currentStep < requiredStep) {
            Swal.fire({ icon: 'warning', title: 'Terkunci', text: 'Selesaikan tahapan sebelumnya terlebih dahulu.', confirmButtonColor: '#1a5f51' });
        } else {
            router.push(menu.path);
        }
    };

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.dashHeaderCurve}>
                <div className={styles.dashTopNav}>
                    <div onClick={handleLogout} style={{cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '5px', fontSize: '13px', fontWeight: 700}}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg> Keluar
                    </div>
                    <div style={{background: 'rgba(255,255,255,0.15)', padding: '6px 15px', borderRadius: '20px', fontSize: '12px', fontWeight: 800}}>SPMB 2026/2027</div>
                </div>
                <div className={styles.headerTitle}>
                    <p>Selamat Datang, Wali Dari</p>
                    <h2>{user.name}</h2>
                </div>
            </div>

            <div className={styles.menuPanel}>
                <div className={styles.menuGrid}>
                    {menus.map((menu) => {
                        const requiredStep = menu.id === 4 ? 5 : menu.id === 5 ? 6 : menu.id === 6 ? 7 : menu.id; 
                        const isLocked = currentStep < requiredStep;
                        const isDone = currentStep > requiredStep;

                        return (
                            <div key={menu.id} className={`${styles.menuCard} ${isLocked ? styles.cardLocked : ''}`} onClick={() => handleMenuClick(menu)}>
                                <div className={`${styles.menuIcon} ${isLocked ? '' : menu.colorClass}`}>
                                    {menu.icon}
                                    {isLocked && <div className={styles.lockBadge}><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4" fill="none" stroke="currentColor" strokeWidth="2.5"></path></svg></div>}
                                    {isDone && <div className={styles.checkBadge}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg></div>}
                                </div>
                                <h4 className={styles.menuTitle}>{menu.title}</h4>
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className={styles.sectionTitle}>Status Pendaftaran</div>
            <div className={styles.progressCard}>
                <div className={styles.progressHeader}>
                    <div><h4 className={styles.progressTitle}>Progres Pendaftaran</h4></div>
                    <div className={styles.progressPercentage}>{progressPercent}%</div>
                </div>
                <div className={styles.progressBarBg}><div className={styles.progressBarFill} style={{ width: `${progressPercent}%` }}></div></div>
                <div className={styles.progressAlert}>
                    <div className={styles.alertIcon}><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></div>
                    <div><h4>{progressInfo.title}</h4><p>{progressInfo.desc}</p></div>
                </div>
            </div>

            <div className={styles.bannerCard}>
                <div className={styles.bannerIcon}><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                <div className={styles.bannerText}><h4>Gelombang 1 Dibuka!</h4><p>Dapatkan potongan uang pangkal khusus pendaftar bulan ini.</p></div>
            </div>

            <div className={styles.sectionTitle}>Sisa Kuota Pendaftaran</div>
            <div className={styles.quotaCard}>
                <div className={styles.quotaItem}>
                    <div className={styles.quotaHeader}>
                        <div className={styles.quotaLabel}><h5>SDIT Al-Mubarak</h5><p>Tersisa 42 Kursi</p></div>
                        <div className={styles.quotaValue}>42%</div>
                    </div>
                    <div className={styles.quotaBarBg}><div className={`${styles.quotaBarFill} ${styles.quotaSd}`} style={{width: '42%'}}></div></div>
                </div>
                <div className={styles.quotaItem}>
                    <div className={styles.quotaHeader}>
                        <div className={styles.quotaLabel}><h5>SMPIT Al-Mubarak</h5><p>Tersisa 15 Kursi</p></div>
                        <div className={styles.quotaValue}>30%</div>
                    </div>
                    <div className={styles.quotaBarBg}><div className={`${styles.quotaBarFill} ${styles.quotaSmp}`} style={{width: '30%'}}></div></div>
                </div>
            </div>

            <div className={styles.bottomNav}>
                <div className={`${styles.navItem} ${styles.navItemActive}`}><svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 3l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V10l9-7z"></path></svg> Beranda</div>
                <div className={styles.navQrBtn} onClick={() => Swal.fire('Bantuan', 'Hubungi Admin SPMB via WhatsApp', 'question')}><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg></div>
                <div className={styles.navItem} onClick={() => Swal.fire('Info', 'Belum ada notifikasi baru.', 'info')}><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> Notif</div>
            </div>
        </div>
    );
}