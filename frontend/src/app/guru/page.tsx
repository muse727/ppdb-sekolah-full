'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import styles from './guruDashboard.module.css';

export default function GuruDashboard() {
    const router = useRouter();
    const [user, setUser] = useState<any>(null);
    const [summary, setSummary] = useState({ total: 0, menunggu: 0, dinilai: 0, lulus: 0, progress: 0 });
    const [antreanList, setAntreanList] = useState<any[]>([]);
    
    const [currentDate, setCurrentDate] = useState(new Date());
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        libAxios.get('/api/user')
            .then((res) => {
                if (res.data.role !== 'guru' && res.data.role !== 'super_admin') {
                    router.push('/login');
                } else {
                    setUser(res.data);
                }
            })
            .catch(() => router.push('/login'));

        const fetchRangkuman = async () => {
            try {
                // Tarik data cukup 1 kali dari rute guru yang diizinkan (Anti-Lag)
                const resAntrean = await libAxios.get('/api/guru/antrean-observasi');
                const data = resAntrean.data || [];
                
                const total = data.length;
                const dinilai = data.filter((s: any) => s.sudah_dites).length;
                const menunggu = total - dinilai;
                const lulus = data.filter((s: any) => s.status_kelulusan === 'LULUS').length;
                const progressVal = total > 0 ? Math.round((dinilai / total) * 100) : 0;

                setSummary({ total, menunggu, dinilai, lulus, progress: progressVal });
                setAntreanList(data);
            } catch (err) {
                console.error("Gagal memuat rangkuman", err);
            } finally {
                setIsLoading(false);
            }
        };

        fetchRangkuman();
    }, [router]);

    const renderCalendar = () => {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const firstDayIndex = new Date(year, month, 1).getDay();
        
        const days = [];
        for (let i = 0; i < firstDayIndex; i++) {
            days.push(<div key={`empty-${i}`} className={styles.calDay}></div>);
        }
        
        const todayDate = new Date();
        const isCurrentMonth = todayDate.getFullYear() === year && todayDate.getMonth() === month;

        for (let i = 1; i <= daysInMonth; i++) {
            const isToday = isCurrentMonth && todayDate.getDate() === i;
            days.push(
                <div key={i} className={`${styles.calDay} ${isToday ? styles.calDayActive : ''}`}>
                    {i}
                </div>
            );
        }
        return days;
    };

    if (!user || isLoading) {
        return <div style={{padding: '50px', fontWeight: 'bold', color: '#1a5f51', textAlign: 'center'}}>Memuat Dashboard...</div>;
    }

    const monthNames = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    
    const pendingList = antreanList.filter(s => !s.sudah_dites).slice(0, 5);
    const passedList = antreanList.filter(s => s.status_kelulusan === 'LULUS').slice(0, 5);

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <h1 className={styles.headerTitle}>Halo, Ustadz/Ustadzah {user.name}</h1>
                <p className={styles.headerSubtitle}>Selamat datang di Panel Observasi & Penilaian Calon Siswa Baru.</p>
            </div>

            <div className={styles.bentoGrid}>
                
                {/* === BARIS 1: 4 KARTU STATISTIK === */}
                <div className={`${styles.statCard} ${styles.statCardGreen}`}>
                    <div className={styles.statHeader}>
                        {/* Menggunakan Inline Style untuk memastikan warna putih secara absolut */}
                        <h3 className={styles.statTitle} style={{color: '#ffffff', opacity: 0.9}}>Total Antrean</h3>
                        <div className={styles.statIcon} style={{background: 'rgba(255,255,255,0.2)', color: 'white'}}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        </div>
                    </div>
                    <div>
                        <h2 className={styles.statValue} style={{color: '#ffffff'}}>{summary.total}</h2>
                        <p className={styles.statDesc} style={{color: '#ffffff', opacity: 0.9}}>Siswa terdaftar</p>
                    </div>
                </div>

                <div className={styles.statCard}>
                    <div className={styles.statHeader}>
                        <h3 className={styles.statTitle} style={{color: '#475569'}}>Menunggu Tes</h3>
                        <div className={styles.statIcon} style={{background: '#fffbeb', color: '#d97706', border: 'none'}}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                    </div>
                    <div>
                        <h2 className={styles.statValue} style={{color: '#1e293b'}}>{summary.menunggu}</h2>
                        <p className={styles.statDesc} style={{color: '#94a3b8'}}>Belum observasi</p>
                    </div>
                </div>

                <div className={styles.statCard}>
                    <div className={styles.statHeader}>
                        <h3 className={styles.statTitle} style={{color: '#475569'}}>Telah Dinilai</h3>
                        <div className={styles.statIcon} style={{background: '#f0fdf4', color: '#10b981', border: 'none'}}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                    </div>
                    <div>
                        <h2 className={styles.statValue} style={{color: '#1e293b'}}>{summary.dinilai}</h2>
                        <p className={styles.statDesc} style={{color: '#94a3b8'}}>Selesai observasi</p>
                    </div>
                </div>

                <div className={styles.statCard}>
                    <div className={styles.statHeader}>
                        <h3 className={styles.statTitle} style={{color: '#475569'}}>Lulus Seleksi</h3>
                        <div className={styles.statIcon} style={{background: '#eff6ff', color: '#3b82f6', border: 'none'}}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                    </div>
                    <div>
                        <h2 className={styles.statValue} style={{color: '#1e293b'}}>{summary.lulus}</h2>
                        <p className={styles.statDesc} style={{color: '#94a3b8'}}>Peserta diterima</p>
                    </div>
                </div>

                {/* === BARIS 2: KONTEN UTAMA (OBSERVASI, KALENDER, DAFTAR TUNGGU) === */}
                
                <div className={styles.actionCard} onClick={() => router.push('/guru/observasi')}>
                    <div className={styles.actionIconTop}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <div className={styles.actionTextBottom}>
                        <h3 className={styles.actionTitle} style={{color: '#ffffff'}}>Buka Papan Observasi</h3>
                        <p className={styles.actionDesc} style={{color: '#ffffff'}}>Masuk ke mode penilaian untuk memulai proses wawancara dan observasi motorik calon peserta didik.</p>
                    </div>
                </div>

                <div className={`${styles.regularCard} ${styles.colSpan4}`}>
                    <div className={styles.calendarHeader}>
                        <h3 className={styles.calendarTitle}>{monthNames[currentDate.getMonth()]} {currentDate.getFullYear()}</h3>
                        <div className={styles.calendarNav}>
                            <button className={styles.calNavBtn} onClick={() => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1))}>&lt;</button>
                            <button className={styles.calNavBtn} onClick={() => setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1))}>&gt;</button>
                        </div>
                    </div>
                    
                    <div className={styles.calGrid}>
                        {['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'].map(hari => (
                            <div key={hari} className={styles.calDayName}>{hari}</div>
                        ))}
                        {renderCalendar()}
                    </div>
                </div>

                <div className={`${styles.regularCard} ${styles.colSpan5}`}>
                    <div className={styles.listHeader}>
                        <h3 className={styles.listTitle}>Daftar Tunggu Observasi</h3>
                        <button className={styles.listAction} onClick={() => router.push('/guru/observasi')}>Lihat Semua</button>
                    </div>
                    
                    <div className={styles.listContainerScroll}>
                        {pendingList.length === 0 ? (
                            <div className={styles.emptyText}>Tidak ada antrean observasi saat ini.</div>
                        ) : (
                            pendingList.map((siswa) => (
                                <div key={siswa.id} className={styles.listItem}>
                                    <div className={styles.itemLeft}>
                                        <div className={styles.itemIcon}>
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                        </div>
                                        <div>
                                            <h4 className={styles.itemName}>{siswa.name}</h4>
                                            <p className={styles.itemDesc}>Jadwal: {siswa.jadwal_observasi ? new Date(siswa.jadwal_observasi).toLocaleDateString('id-ID') : 'Belum Dijadwalkan'}</p>
                                        </div>
                                    </div>
                                    <span className={`${styles.itemBadge} ${styles.badgePending}`}>Belum Tes</span>
                                </div>
                            ))
                        )}
                    </div>
                </div>

                {/* === BARIS 3: PROGRES & SISWA LULUS === */}

                <div className={`${styles.regularCard} ${styles.colSpan4}`}>
                    <h3 className={styles.listTitle} style={{marginBottom: '10px'}}>Progres Penilaian</h3>
                    <div className={styles.progressContainer}>
                        <div className={styles.progressCircle} style={{"--p": `${summary.progress}%`} as React.CSSProperties}>
                            <div className={styles.progressInner}>
                                <h2 className={styles.progressValue}>{summary.progress}%</h2>
                                <span className={styles.progressLabel}>Selesai</span>
                            </div>
                        </div>
                        <div style={{display: 'flex', gap: '15px', marginTop: '20px'}}>
                            <div style={{display: 'flex', alignItems: 'center', gap: '5px', fontSize: '11px', color: '#64748b', fontWeight: 600}}><span style={{width: '8px', height:'8px', borderRadius:'50%', background: '#1a5f51'}}></span> Selesai</div>
                            <div style={{display: 'flex', alignItems: 'center', gap: '5px', fontSize: '11px', color: '#64748b', fontWeight: 600}}><span style={{width: '8px', height:'8px', borderRadius:'50%', background: '#edf2f7'}}></span> Menunggu</div>
                        </div>
                    </div>
                </div>

                <div className={`${styles.regularCard} ${styles.colSpan5}`}>
                    <div className={styles.listHeader}>
                        <h3 className={styles.listTitle}>Lulus Observasi Terkini</h3>
                        <button className={styles.listAction} onClick={() => router.push('/guru/rekapan')}>Lihat Rekapan</button>
                    </div>
                    
                    <div className={styles.listContainerScroll}>
                        {passedList.length === 0 ? (
                            <div className={styles.emptyText}>Belum ada siswa yang diluluskan.</div>
                        ) : (
                            passedList.map((siswa) => (
                                <div key={siswa.id} className={styles.listItem}>
                                    <div className={styles.itemLeft}>
                                        <div className={styles.itemIcon} style={{background: '#f0fdf4', color: '#10b981'}}>
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                        </div>
                                        <div>
                                            <h4 className={styles.itemName}>{siswa.name}</h4>
                                            <p className={styles.itemDesc}>Nomor Registrasi: #00{siswa.id}</p>
                                        </div>
                                    </div>
                                    <span className={`${styles.itemBadge} ${styles.badgeSuccess}`}>Lulus Seleksi</span>
                                </div>
                            ))
                        )}
                    </div>
                </div>

            </div>
        </div>
    );
}