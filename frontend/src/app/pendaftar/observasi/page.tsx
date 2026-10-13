// src/app/pendaftar/observasi/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import type { AuthenticatedUser } from '@/lib/domain';
import styles from './tiket.module.css';

export default function ObservasiOrtuPage() {
    const router = useRouter();
    const [user, setUser] = useState<AuthenticatedUser | null>(null);
    const [jenjang, setJenjang] = useState<string>('');

    useEffect(() => {
        Promise.all([
            libAxios.get<AuthenticatedUser>('/api/user'),
            libAxios.get('/api/payment/status').catch(() => ({ data: null }))
        ]).then(([userRes, paymentRes]) => {
            setUser(userRes.data);
            setJenjang(paymentRes.data?.payment?.jenjang || 'SD'); 
        }).catch(() => {
            router.push('/login');
        });
    }, [router]);

    if (!user) return <div className={styles.loading}>Memuat Tiket...</div>;

    const isSMP = jenjang === 'SMP';

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.headerMini}>
                <button type="button" className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                </button>
                <div>
                    <h2 className={styles.headerTitle}>{isSMP ? 'Ujian CBT' : 'Jadwal Observasi'}</h2>
                    <p className={styles.headerSubtitle}>Langkah 4 Pendaftaran</p>
                </div>
            </div>

            <div className={styles.contentWrap}>
                <div className={styles.introBox}>
                    <p>{isSMP ? 'Berikut adalah informasi jadwal Computer Based Test (CBT) di Lab Sekolah.' : 'Berikut adalah informasi jadwal kedatangan calon peserta didik ke sekolah.'}</p>
                </div>

                {user.jadwal_observasi ? (
                    <div className={styles.ticketCard}>
                        <div className={styles.ticketHeader}>
                            <div className={styles.schoolInfo}>
                                {isSMP ? (
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                                ) : (
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                                )}
                                <span>{isSMP ? 'SMPIT Al Mubarak' : 'SDIT Al Mubarak'}</span>
                            </div>
                            <div className={styles.ticketStatus}>{isSMP ? 'SIAP UJIAN' : 'TERKONFIRMASI'}</div>
                        </div>
                        
                        <div className={styles.ticketBody}>
                            <div className={styles.barcodeSection}>
                                <svg width="70" height="70" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="1"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><rect x="7" y="7" width="3" height="3"></rect><rect x="14" y="7" width="3" height="3"></rect><rect x="7" y="14" width="3" height="3"></rect><rect x="14" y="14" width="3" height="3"></rect></svg>
                                <span className={styles.regNumber}>REG-00{user.id}</span>
                            </div>
                            
                            <div className={styles.infoGrid}>
                                <div className={styles.infoItem}>
                                    <label>Nama Peserta</label>
                                    <h3>{user.name}</h3>
                                </div>
                                <div className={styles.infoItem}>
                                    <label>Tanggal Pelaksanaan</label>
                                    <h3>{new Date(user.jadwal_observasi).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</h3>
                                </div>
                                <div className={styles.infoItem}>
                                    <label>Waktu Pelaksanaan</label>
                                    <h3>Pukul {user.jam_observasi}</h3>
                                </div>
                                <div className={styles.infoItem}>
                                    <label>Ruangan / Lokasi</label>
                                    <h3>{user.ruang_observasi}</h3>
                                </div>
                            </div>
                        </div>

                        {/* KONDISI: JIKA SMP TAMPILKAN TOKEN, JIKA SD TAMPILKAN PERLENGKAPAN */}
                        {isSMP ? (
                            <div className={styles.cbtFooter}>
                                <div className={styles.cbtText}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    Ujian dilakukan di Lab Komputer sekolah. Token akses akan diumumkan di ruangan.
                                </div>
                                <div className={styles.tokenDisplayBox}>
                                    <span className={styles.tokenLabel}>TOKEN UJIAN CBT</span>
                                    <span className={styles.tokenValue}>DIBERIKAN DI LOKASI</span>
                                </div>
                            </div>
                        ) : (
                            <div className={styles.ticketFooter}>
                                <div className={styles.footerIcon}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></div>
                                <div>
                                    <p>Harap hadir 15 menit sebelum jadwal kedatangan.</p>
                                    <div className={styles.perlengkapanBox}>
                                        <strong>Peralatan Wajib Bawa:</strong>
                                        <br/>{user.perlengkapan || 'Alat Tulis & Dokumen Pendukung'}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className={styles.emptyCard}>
                        <div className={styles.emptyIcon}>
                            <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <h2>Jadwal Belum Tersedia</h2>
                        <p>Panitia SPMB sedang menyusun jadwal {isSMP ? 'ujian CBT' : 'observasi'} untuk Ananda. Silakan cek kembali halaman ini secara berkala.</p>
                    </div>
                )}
            </div>
        </div>
    );
}