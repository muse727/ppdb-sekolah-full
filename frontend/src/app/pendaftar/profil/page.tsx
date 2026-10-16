// src/app/pendaftar/profil/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import WhatsAppModal from '@/components/pendaftar/WhatsAppModal';
import styles from './profil.module.css';

export default function ProfilPendaftar() {
    const router = useRouter();
    const [user, setUser] = useState<any>(null);
    const [biodata, setBiodata] = useState<any>(null);
    const [loading, setLoading] = useState(true);

    // Form Password
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [isSavingPassword, setIsSavingPassword] = useState(false);
    const [isWaModalOpen, setIsWaModalOpen] = useState(false);

    useEffect(() => {
        Promise.all([
            libAxios.get('/api/user').catch(() => null),
            libAxios.get('/api/biodata').catch(() => null)
        ]).then(([userRes, bioRes]) => {
            if (!userRes || userRes.data?.role !== 'orang_tua') {
                router.push('/login');
                return;
            }
            setUser(userRes.data);
            if (bioRes && bioRes.data) {
                setBiodata(bioRes.data);
            }
            setLoading(false);
        }).catch(() => {
            router.push('/login');
        });
    }, [router]);

    const handleLogout = async () => {
        const result = await Swal.fire({
            title: 'Keluar dari Akun?',
            text: 'Anda harus memasukkan email dan password kembali untuk masuk.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Keluar',
            cancelButtonText: 'Batal'
        });

        if (result.isConfirmed) {
            try {
                await libAxios.post('/api/logout');
            } catch (err) {
                console.error(err);
            }
            router.push('/login');
        }
    };

    const handleChangePassword = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!currentPassword || !newPassword || !confirmPassword) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Semua kolom password wajib diisi.', confirmButtonColor: '#1a5f51' });
            return;
        }

        if (newPassword.length < 8) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Password baru minimal 8 karakter.', confirmButtonColor: '#1a5f51' });
            return;
        }

        if (newPassword !== confirmPassword) {
            Swal.fire({ icon: 'error', title: 'Tidak Cocok', text: 'Konfirmasi password baru tidak sesuai.', confirmButtonColor: '#1a5f51' });
            return;
        }

        setIsSavingPassword(true);
        try {
            await libAxios.post('/api/user/change-password', {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: confirmPassword
            });

            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: 'Password akun Anda telah berhasil diperbarui.',
                confirmButtonColor: '#1a5f51'
            });

            setCurrentPassword('');
            setNewPassword('');
            setConfirmPassword('');
        } catch (err: any) {
            const message = err.response?.data?.message || 'Gagal mengubah password. Pastikan password saat ini benar.';
            Swal.fire({ icon: 'error', title: 'Gagal', text: message, confirmButtonColor: '#1a5f51' });
        } finally {
            setIsSavingPassword(false);
        }
    };

    const handleHelpWa = () => {
        setIsWaModalOpen(true);
    };

    if (loading || !user) {
        return <div style={{textAlign: 'center', marginTop: '60px', color: '#1a5f51', fontWeight: 800}}>Memuat Profil...</div>;
    }

    const initial = (user.name || 'W').charAt(0).toUpperCase();

    return (
        <div className={styles.mobileContainer}>
            {/* Header Profil */}
            <div className={styles.profileHeader}>
                <div className={styles.topBar}>
                    <button className={styles.btnBack} onClick={() => router.push('/pendaftar')}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        Beranda
                    </button>
                    <span className={styles.pageTitle}>Profil Akun</span>
                    <span style={{width: 65}}></span>
                </div>

                <div className={styles.avatarWrapper}>
                    <div className={styles.avatar}>{initial}</div>
                </div>

                <h2 className={styles.userName}>{user.name}</h2>
                <p className={styles.userEmail}>{user.email}</p>

                <div className={styles.badgeRow}>
                    <span className={styles.badge}>Wali Calon Santri</span>
                    {user.jenjang && <span className={styles.badgeGold}>{user.jenjang} Al-Mubarak</span>}
                    {user.jalur && <span className={styles.badge}>Jalur {user.jalur}</span>}
                </div>
            </div>

            {/* Panel Konten */}
            <div className={styles.contentPanel}>
                {/* Kartu Status Pendaftaran */}
                <div className={styles.card}>
                    <h3 className={styles.cardTitle}>
                        <svg className={styles.cardTitleIcon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        Informasi Pendaftaran
                    </h3>
                    <div className={styles.infoList}>
                        <div className={styles.infoItem}>
                            <span className={styles.infoLabel}>Nomor Pendaftar</span>
                            <span className={styles.infoValue}>#REG-{String(user.id).padStart(5, '0')}</span>
                        </div>
                        <div className={styles.infoItem}>
                            <span className={styles.infoLabel}>Nama Calon Siswa</span>
                            <span className={styles.infoValue}>{biodata?.nama_lengkap || user.name}</span>
                        </div>
                        <div className={styles.infoItem}>
                            <span className={styles.infoLabel}>Jenjang Tujuan</span>
                            <span className={styles.infoValue}>{user.jenjang ? `${user.jenjang} Al-Mubarak` : 'Belum Memilih'}</span>
                        </div>
                        <div className={styles.infoItem}>
                            <span className={styles.infoLabel}>Status Seleksi</span>
                            <span className={styles.infoValue} style={{color: user.status_kelulusan === 'LULUS' ? '#107163' : '#e67e22'}}>
                                {user.status_kelulusan || 'Proses Seleksi'}
                            </span>
                        </div>
                        {user.jadwal_observasi && (
                            <div className={styles.infoItem}>
                                <span className={styles.infoLabel}>Jadwal Observasi</span>
                                <span className={styles.infoValue}>{user.jadwal_observasi} {user.jam_observasi ? `(${user.jam_observasi})` : ''}</span>
                            </div>
                        )}
                        {user.ruang_observasi && (
                            <div className={styles.infoItem}>
                                <span className={styles.infoLabel}>Ruangan</span>
                                <span className={styles.infoValue}>{user.ruang_observasi}</span>
                            </div>
                        )}
                    </div>
                </div>

                {/* Kartu Ganti Password */}
                <div className={styles.card}>
                    <h3 className={styles.cardTitle}>
                        <svg className={styles.cardTitleIcon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        Keamanan & Ganti Password
                    </h3>
                    <form onSubmit={handleChangePassword}>
                        <div className={styles.formGroup}>
                            <label className={styles.label}>Password Saat Ini</label>
                            <input
                                type="password"
                                className={styles.input}
                                placeholder="Masukkan password lama"
                                value={currentPassword}
                                onChange={(e) => setCurrentPassword(e.target.value)}
                            />
                        </div>
                        <div className={styles.formGroup}>
                            <label className={styles.label}>Password Baru</label>
                            <input
                                type="password"
                                className={styles.input}
                                placeholder="Minimal 8 karakter"
                                value={newPassword}
                                onChange={(e) => setNewPassword(e.target.value)}
                            />
                        </div>
                        <div className={styles.formGroup}>
                            <label className={styles.label}>Konfirmasi Password Baru</label>
                            <input
                                type="password"
                                className={styles.input}
                                placeholder="Ulangi password baru"
                                value={confirmPassword}
                                onChange={(e) => setConfirmPassword(e.target.value)}
                            />
                        </div>
                        <button type="submit" className={styles.btnSubmit} disabled={isSavingPassword}>
                            {isSavingPassword ? 'Menyimpan...' : 'Perbarui Password'}
                        </button>
                    </form>
                </div>

                {/* Kartu Pusat Bantuan */}
                <div className={styles.card}>
                    <h3 className={styles.cardTitle}>
                        <svg className={styles.cardTitleIcon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        Pusat Bantuan SPMB
                    </h3>
                    <p style={{fontSize: '12px', color: '#64748b', margin: '0 0 12px 0', lineHeight: 1.5}}>
                        Mengalami kendala teknis atau butuh informasi syarat pendaftaran? Narahubung resmi SPMB siap membantu.
                    </p>
                    <button
                        type="button"
                        onClick={handleHelpWa}
                        style={{
                            width: '100%',
                            padding: '11px',
                            background: '#f0fdf4',
                            color: '#15803d',
                            border: '1px solid #bbf7d0',
                            borderRadius: '12px',
                            fontSize: '13px',
                            fontWeight: 700,
                            cursor: 'pointer',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: '8px'
                        }}
                    >
                        Hubungi Admin WhatsApp Resmi →
                    </button>
                </div>

                {/* Tombol Logout */}
                <button type="button" className={styles.btnLogout} onClick={handleLogout}>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    Keluar dari Akun
                </button>
            </div>

            {/* Bottom Nav */}
            <div className={styles.bottomNavWrapper}>
                <div className={styles.bottomNav}>
                    <div className={styles.navItem} onClick={() => router.push('/pendaftar')}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V10l9-7z"></path></svg>
                        <span>Beranda</span>
                    </div>

                    <div className={styles.navCenterSlot}>
                        <button 
                            type="button"
                            className={styles.navFloatingBtn} 
                            onClick={handleHelpWa} 
                            title="Hubungi WhatsApp Admin"
                            aria-label="Hubungi Admin WhatsApp"
                        >
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                        </button>
                    </div>

                    <div className={`${styles.navItem} ${styles.navItemActive}`}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Profil</span>
                    </div>
                </div>
            </div>

            {/* Custom Modal WhatsApp Layanan Bantuan */}
            <WhatsAppModal 
                isOpen={isWaModalOpen} 
                onClose={() => setIsWaModalOpen(false)} 
                userName={user?.name} 
            />
        </div>
    );
}
