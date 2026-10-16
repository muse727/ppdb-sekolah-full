// src/app/pendaftar/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import WhatsAppModal from '@/components/pendaftar/WhatsAppModal';
import InstallPwaModal from '@/components/pwa/InstallPwaModal';
import styles from './pendaftar.module.css';

interface PendaftarBanner {
    id: number;
    title: string;
    description: string | null;
    bg_color?: string;
    image_url?: string;
    has_image?: boolean;
    cta_text?: string | null;
    cta_url?: string | null;
}

export default function DashboardPendaftar() {
    const router = useRouter();
    const apiBase = (process.env.NEXT_PUBLIC_API_URL || '').replace(/\/$/, '');

    const [user, setUser] = useState<any>(null);
    const [jenjang, setJenjang] = useState<string>(''); // Simpan jenjang buat bedain pesan SD/SMP
    const [currentStep, setCurrentStep] = useState(1);
    const [biodata, setBiodata] = useState<any>(null);
    const [payment, setPayment] = useState<any>(null);
    const [kuota, setKuota] = useState<any>(null);
    const [banners, setBanners] = useState<PendaftarBanner[]>([]);
    const [activeBannerIdx, setActiveBannerIdx] = useState(0);
    const [isWaModalOpen, setIsWaModalOpen] = useState(false);
    const bannerTimerRef = useRef<NodeJS.Timeout | null>(null);

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
                        libAxios.get('/api/berkas').catch(() => ({ data: null })),
                        libAxios.get('/api/public/kuota').catch(() => ({ data: null })),
                        libAxios.get('/api/banners?target=pendaftar').catch(() => ({ data: [] }))
                    ]).then(([paymentRes, biodataRes, berkasRes, kuotaRes, bannerRes]) => {
                        let step = 1;
                        let userJenjang = res.data.jenjang || '';

                        if (biodataRes.data) setBiodata(biodataRes.data);
                        if (paymentRes.data?.payment) setPayment(paymentRes.data.payment);
                        if (kuotaRes.data) setKuota(kuotaRes.data);
                        if (Array.isArray(bannerRes.data) && bannerRes.data.length > 0) {
                            setBanners(bannerRes.data);
                        } else {
                            // Default fallback banner jika admin belum menambahkan banner khusus
                            setBanners([{
                                id: 0,
                                title: 'Gelombang 1 Dibuka!',
                                description: 'Dapatkan potongan uang pangkal khusus pendaftar bulan ini.',
                                bg_color: '#1a5f51',
                            }]);
                        }
                        
                        // Cek Pembayaran
                        if (paymentRes.data?.payment?.status === 'SUCCESS') {
                            step = 2; 
                            userJenjang = paymentRes.data.payment.jenjang || userJenjang || '';
                            
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

    // Autoplay banner bila ada lebih dari 1 banner
    useEffect(() => {
        if (banners.length <= 1) return;
        bannerTimerRef.current = setInterval(() => {
            setActiveBannerIdx(prev => (prev + 1) % banners.length);
        }, 5000);
        return () => {
            if (bannerTimerRef.current) clearInterval(bannerTimerRef.current);
        };
    }, [banners]);

    // Progress bar disesuaikan (maks step ada 7 untuk 100%)
    const progressPercent = Math.round(((currentStep - 1) / 6) * 100) || 5; 

    // === TEXT PROGRESS PINTAR (BEDAIN SD & SMP) ===
    const getProgressText = () => {
        switch(currentStep) {
            case 1: return { title: "Tindakan Diperlukan: Pembayaran", desc: "Silakan klik menu Pembayaran untuk memilih jenjang (SD/SMP) dan mendapatkan instruksi transfer DOKU." };
            case 2: return { title: "Tindakan Diperlukan: Isi Biodata", desc: "Pembayaran terverifikasi! Mohon lengkapi biodata calon siswa melalui menu Biodata." };
            case 3: return { title: "Tindakan Diperlukan: Upload Berkas", desc: "Silakan upload scan Kartu Keluarga dan Akte Kelahiran yang akan divalidasi oleh sistem." };
            case 4: return { title: "Menunggu Verifikasi Berkas", desc: "Berkas sedang divalidasi. Mohon tunggu." };
            case 5: 
                if (jenjang === 'SMP') {
                    return { title: "Tindakan Diperlukan: Cek Ujian CBT", desc: "Berkas lengkap! Silakan klik menu Ujian CBT untuk melihat Jadwal & Token Ujian CBT Ananda." };
                } else {
                    return { title: "Tindakan Diperlukan: Cek Jadwal Observasi", desc: "Berkas lengkap! Silakan klik menu Observasi untuk melihat Jadwal & Ruangan Observasi Ananda." };
                }
            case 6: return { title: "Menunggu Pengumuman", desc: "Jadwal observasi telah ditentukan. Pantau terus menu Pengumuman untuk melihat hasil akhir seleksi." };
            case 7: return { title: "Alhamdulillah, Lulus!", desc: "Selamat Ananda diterima! Silakan lanjut ke menu Daftar Ulang untuk penyelesaian administrasi." };
            default: return { title: "Menunggu Pengumuman", desc: "Terima kasih, seluruh tahapan selesai. Pantau terus aplikasi ini." };
        }
    };
    const progressInfo = getProgressText();

    // Data Kuota Riil dari Database Backend
    const sdTotal = kuota?.sd?.total ?? 100;
    const sdSisa = kuota?.sd?.sisa ?? 100;
    const sdPercent = Math.round((sdSisa / (sdTotal || 1)) * 100);

    const smpTotal = kuota?.smp?.total ?? 50;
    const smpSisa = kuota?.smp?.sisa ?? 49;
    const smpPercent = Math.round((smpSisa / (smpTotal || 1)) * 100);

    const handleHelpWa = () => {
        setIsWaModalOpen(true);
    };

    const handleOpenNotif = () => {
        const statusText = currentStep >= 7 ? 'Lulus Seleksi' : currentStep >= 6 ? 'Menunggu Pengumuman' : currentStep >= 5 ? 'Jadwal Observasi/CBT Terbit' : currentStep >= 3 ? 'Tahap Berkas' : currentStep >= 2 ? 'Pengisian Biodata' : 'Menunggu Pembayaran';
        
        Swal.fire({
            title: 'Notifikasi & Status Pendaftaran',
            html: `
                <div style="text-align: left; font-size: 13px; color: #334155; line-height: 1.6;">
                    <div style="padding: 12px; background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 8px; margin-bottom: 10px;">
                        <strong style="color: #166534;">Tahapan Terkini: ${statusText}</strong><br/>
                        ${progressInfo.desc}
                    </div>
                    <div style="padding: 10px 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 12px;">
                        <div><b>Nama Calon Siswa:</b> ${biodata?.nama_lengkap || user?.name || '-'}</div>
                        <div><b>Jenjang:</b> ${jenjang ? jenjang + ' Al-Mubarak' : (user?.jenjang ? user.jenjang + ' Al-Mubarak' : 'Belum Memilih')}</div>
                        <div><b>Jalur:</b> ${user?.jalur || 'UMUM'}</div>
                        ${user?.jadwal_observasi ? `<div style="margin-top: 4px; color: #0f766e;"><b>Jadwal Observasi:</b> ${user.jadwal_observasi} (${user.jam_observasi || 'Sesuai Sesi'})</div>` : ''}
                    </div>
                </div>
            `,
            confirmButtonText: 'Tutup',
            confirmButtonColor: '#1a5f51'
        });
    };

    const handleBannerAction = (banner: PendaftarBanner) => {
        if (banner.cta_url) {
            if (banner.cta_url.startsWith('http://') || banner.cta_url.startsWith('https://')) {
                window.open(banner.cta_url, '_blank');
            } else {
                router.push(banner.cta_url);
            }
        }
    };

    if (!user) return <div style={{textAlign: 'center', marginTop: '50px', color: '#1a5f51', fontWeight: 800}}>Memuat Dashboard...</div>;

    const menus = [
        { id: 1, title: 'Pembayaran', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>, colorClass: styles.bgWarning, path: '/pendaftar/pembayaran' },
        { id: 2, title: 'Biodata', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>, colorClass: styles.bgActive, path: '/pendaftar/biodata' },
        { id: 3, title: 'Berkas', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>, colorClass: styles.bgActive, path: '/pendaftar/berkas' },
        { id: 4, title: jenjang === 'SMP' ? 'Ujian CBT' : 'Observasi', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>, colorClass: styles.bgActive, path: '/pendaftar/observasi' },
        { id: 5, title: 'Pengumuman', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>, colorClass: styles.bgActive, path: '/pendaftar/pengumuman' },
        { id: 6, title: 'Daftar Ulang', icon: <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>, colorClass: styles.bgActive, path: '/pendaftar/daftar-ulang' },
    ];

    const handleMenuClick = (menu: any) => {
        const requiredStep = menu.id === 4 ? 5 : menu.id === 5 ? 6 : menu.id === 6 ? 7 : menu.id; 
        
        if (currentStep < requiredStep) {
            Swal.fire({ icon: 'warning', title: 'Terkunci', text: 'Selesaikan tahapan sebelumnya terlebih dahulu.', confirmButtonColor: '#1a5f51' });
        } else {
            router.push(menu.path);
        }
    };

    return (
        <div className={styles.mobileContainer}>
            {/* Header Curve */}
            <div className={styles.dashHeaderCurve}>
                <div className={styles.dashTopNav}>
                    <div className={styles.schoolBrand}>
                        <div className={styles.brandBadge}>
                            <span>SPMB AL-MUBARAK</span>
                        </div>
                    </div>
                    <div className={styles.topActions}>
                        <button 
                            type="button"
                            className={styles.topIconBtn} 
                            onClick={handleOpenNotif}
                            title="Notifikasi"
                            aria-label="Lihat Notifikasi"
                        >
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <span className={styles.notifDot}></span>
                        </button>
                        <button 
                            type="button"
                            className={styles.topIconBtn} 
                            onClick={() => router.push('/pendaftar/profil')}
                            title="Profil Akun"
                            aria-label="Buka Profil"
                        >
                            <span style={{
                                width: 24, 
                                height: 24, 
                                borderRadius: '50%', 
                                background: '#ffffff', 
                                color: '#1a5f51', 
                                display: 'inline-flex', 
                                alignItems: 'center', 
                                justifyContent: 'center',
                                fontWeight: 900,
                                fontSize: '11px'
                            }}>
                                {(user.name || 'W').charAt(0).toUpperCase()}
                            </span>
                        </button>
                    </div>
                </div>
                <div className={styles.headerTitle}>
                    <p>Selamat Datang, Wali Dari</p>
                    <h2>{user.name}</h2>
                </div>
            </div>

            {/* Menu Grid */}
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

            {/* Progres Pendaftaran */}
            <div className={styles.sectionTitle}>Status Pendaftaran</div>
            <div className={styles.progressCard}>
                <div className={styles.progressHeader}>
                    <div><h4 className={styles.progressTitle}>Progres Pendaftaran</h4></div>
                    <div className={styles.progressPercentage}>{progressPercent}%</div>
                </div>
                <div className={styles.progressBarBg}>
                    <div className={styles.progressBarFill} style={{ width: `${progressPercent}%` }}></div>
                </div>
                <div className={styles.progressAlert}>
                    <div className={styles.alertIcon}>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    </div>
                    <div>
                        <h4>{progressInfo.title}</h4>
                        <p>{progressInfo.desc}</p>
                    </div>
                </div>
            </div>

            {/* Banner Mobile Dinamis dari Admin (Gambar Penuh ala E-Commerce / Superapp) */}
            {banners.length > 0 && (
                <div className={styles.bannerSliderWrapper}>
                    <div 
                        className={styles.bannerSliderTrack}
                        style={{ transform: `translateX(-${activeBannerIdx * 100}%)` }}
                    >
                        {banners.map((banner) => {
                            const hasCta = Boolean(banner.cta_url);
                            return (
                                <div key={banner.id} className={styles.bannerSlide}>
                                    <div 
                                        className={styles.bannerCard}
                                        style={{ cursor: hasCta ? 'pointer' : 'default' }}
                                        onClick={() => handleBannerAction(banner)}
                                    >
                                        {banner.image_url ? (
                                            /* eslint-disable-next-line @next/next/no-img-element */
                                            <img 
                                                src={`${apiBase}${banner.image_url}`} 
                                                alt={banner.title || 'Banner SPMB'} 
                                                className={styles.bannerImage} 
                                            />
                                        ) : (
                                            <div className={styles.bannerFallback}>
                                                <h4>{banner.title}</h4>
                                                {banner.description && <p>{banner.description}</p>}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {banners.length > 1 && (
                        <div className={styles.bannerDots}>
                            {banners.map((_, dotIdx) => (
                                <div 
                                    key={dotIdx}
                                    className={`${styles.bannerDot} ${dotIdx === activeBannerIdx ? styles.bannerDotActive : ''}`}
                                    onClick={() => setActiveBannerIdx(dotIdx)}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Sisa Kuota Pendaftaran Riil */}
            <div className={styles.sectionTitle}>Sisa Kuota Pendaftaran</div>
            <div className={styles.quotaCard}>
                <div className={styles.quotaItem}>
                    <div className={styles.quotaHeader}>
                        <div className={styles.quotaLabel}>
                            <h5>SDIT Al-Mubarak</h5>
                            <p>Tersisa {sdSisa} dari {sdTotal} Kursi</p>
                        </div>
                        <div className={styles.quotaValue}>{sdPercent}%</div>
                    </div>
                    <div className={styles.quotaBarBg}>
                        <div className={`${styles.quotaBarFill} ${styles.quotaSd}`} style={{width: `${sdPercent}%`}}></div>
                    </div>
                </div>
                <div className={styles.quotaItem}>
                    <div className={styles.quotaHeader}>
                        <div className={styles.quotaLabel}>
                            <h5>SMPIT Al-Mubarak</h5>
                            <p>Tersisa {smpSisa} dari {smpTotal} Kursi</p>
                        </div>
                        <div className={styles.quotaValue}>{smpPercent}%</div>
                    </div>
                    <div className={styles.quotaBarBg}>
                        <div className={`${styles.quotaBarFill} ${styles.quotaSmp}`} style={{width: `${smpPercent}%`}}></div>
                    </div>
                </div>
            </div>

            {/* Bottom Nav dengan Floating Center Action */}
            <div className={styles.bottomNavWrapper}>
                <div className={styles.bottomNav}>
                    <div className={`${styles.navItem} ${styles.navItemActive}`}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 3l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V10l9-7z"></path></svg>
                        <span>Beranda</span>
                    </div>

                    <div className={styles.navCenterSlot}>
                        <button 
                            type="button" 
                            className={styles.navFloatingBtn} 
                            onClick={handleHelpWa} 
                            title="Bantuan WhatsApp Resmi SPMB"
                            aria-label="Hubungi Admin WhatsApp"
                        >
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                        </button>
                    </div>

                    <div className={styles.navItem} onClick={() => router.push('/pendaftar/profil')}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Profil</span>
                    </div>
                </div>
            </div>

            {/* Modal Install PWA Mobile */}
            <InstallPwaModal />

            {/* Custom Modal WhatsApp Layanan Bantuan */}
            <WhatsAppModal 
                isOpen={isWaModalOpen} 
                onClose={() => setIsWaModalOpen(false)} 
                userName={user?.name} 
            />
        </div>
    );
}
