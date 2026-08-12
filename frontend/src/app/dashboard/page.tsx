// src/app/dashboard/page.tsx
'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios'; // Pastikan path ini benar sesuai file axios.ts lu
import styles from './dashboard.module.css';

export default function DashboardHome() {
  const [currentDate, setCurrentDate] = useState('');
  const [userName, setUserName] = useState('Admin');

  useEffect(() => {
    // 1. Setup Tanggal
    const options: Intl.DateTimeFormatOptions = { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' };
    setCurrentDate(new Date().toLocaleDateString('id-ID', options));

    // 2. Ambil data user dari API Laravel
    libAxios.get('/api/user')
      .then((res) => {
        setUserName(res.data.name);
      })
      .catch((err) => console.error("Gagal ambil data user", err));
  }, []);

  return (
    <div className={styles.dashboardGrid}>
      
      {/* ======================================= */}
      {/* KOLOM KIRI (Hero, Stats, & Widget Utama) */}
      {/* ======================================= */}
      <div className={styles.leftCol}>
        
        {/* 1. HERO BANNER */}
        <div className={styles.heroBanner}>
          <div className={styles.heroDate}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            {currentDate}
          </div>
          <h1 className={styles.heroTitle}>Ahlan Wa Sahlan, {userName}!</h1>
          <p className={styles.heroSubtitle}>Pantau progres pendaftaran siswa baru SDIT & SMPIT Al-Mubarak hari ini.</p>
          
          <svg className={styles.heroIllustration} viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
            <path fill="#ffffff" fillOpacity="0.1" d="M45.7,-76.1C58.9,-69.3,68.9,-55.4,76.5,-41.1C84.1,-26.8,89.3,-12.1,87.6,1.8C85.9,15.7,77.3,28.8,68.1,40.8C58.9,52.8,49.1,63.7,36.5,71.2C23.9,78.7,8.5,82.8,-6.4,80.5C-21.3,78.2,-35.7,69.5,-48.5,60.1C-61.3,50.7,-72.5,40.6,-80.1,27.5C-87.7,14.4,-91.7,-1.7,-88.1,-16.1C-84.5,-30.5,-73.3,-43.2,-60.6,-51.7C-47.9,-60.2,-33.7,-64.5,-20.5,-70C-7.3,-75.5,4.9,-82.2,20.1,-82.5C35.3,-82.8,53.5,-76.7,45.7,-76.1Z" transform="translate(100 100) scale(1.2)" />
          </svg>
        </div>

        {/* 2. KOTAK STATISTIK KECIL */}
        <div className={styles.statsContainer}>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>Pendaftar SD <span style={{color: '#3182ce'}}>•••</span></div>
            <h2 className={styles.statValue}>142</h2>
            <p className={styles.statDesc}>+12 Hari ini</p>
          </div>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>Pendaftar SMP <span style={{color: '#3182ce'}}>•••</span></div>
            <h2 className={styles.statValue}>89</h2>
            <p className={styles.statDesc}>+5 Hari ini</p>
          </div>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>Daftar Ulang <span style={{color: '#3182ce'}}>•••</span></div>
            <h2 className={styles.statValue}>45</h2>
            <p className={styles.statDesc} style={{color: '#718096'}}>Dari total kuota 200</p>
          </div>
        </div>

        {/* 3. WIDGET BARU BAWAH KIRI (Kuota & Progress Berkas) */}
        <div className={styles.bottomRow}>
          
          {/* Grafik Sisa Kuota */}
          <div className={styles.card}>
            <div className={styles.cardHeader}>
              <h3 className={styles.cardTitle}>KAPASITAS KUOTA</h3>
              <select className={styles.dropdownMini}>
                <option>Tahun Ini</option>
              </select>
            </div>
            
            <div className={styles.chartWrapper}>
              <svg viewBox="0 0 36 36" className={styles.donutSvg}>
                <path className={styles.circleBg} d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                {/* 65, 100 artinya 65% Terisi */}
                <path className={styles.circleColor} strokeDasharray="65, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                <text x="18" y="19" className={styles.chartCenterText}>65%</text>
                <text x="18" y="23" className={styles.chartCenterLabel}>Terisi</text>
              </svg>

              <div className={styles.chartLegend}>
                <div className={styles.legendItem}>
                  <span className={styles.legendVal}>58</span>
                  <span className={styles.legendLabel}>Sisa Kuota SD</span>
                </div>
                <div className={styles.legendItem}>
                  <span className={styles.legendVal}>11</span>
                  <span className={styles.legendLabel}>Sisa Kuota SMP</span>
                </div>
              </div>
            </div>
          </div>

          {/* Progress Pemberkasan */}
          <div className={styles.card}>
            <div className={styles.cardHeader}>
              <h3 className={styles.cardTitle}>STATUS PEMBERKASAN</h3>
              <select className={styles.dropdownMini}>
                <option>Semua</option>
              </select>
            </div>
            
            <div className={styles.progressList}>
              <div className={styles.progressItem}>
                <div className={styles.progressHeader}><span>Terverifikasi</span> <span>64%</span></div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{width: '64%', backgroundColor: '#4c51bf'}}></div>
                </div>
              </div>
              
              <div className={styles.progressItem}>
                <div className={styles.progressHeader}><span>Menunggu Pengecekan</span> <span>50%</span></div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{width: '50%', backgroundColor: '#ed8936'}}></div>
                </div>
              </div>

              <div className={styles.progressItem}>
                <div className={styles.progressHeader}><span>Data Tidak Valid</span> <span>12%</span></div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{width: '12%', backgroundColor: '#f56565'}}></div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      {/* ======================================= */}
      {/* KOLOM KANAN (Profil, Kalender, Agenda)  */}
      {/* ======================================= */}
      <div className={styles.rightCol}>
        
        {/* 1. KARTU PROFIL ADMIN */}
        <div className={styles.profileCard}>
          <div className={styles.profileHeader}>
            PROFIL SAYA
            <div className={styles.editBtn}>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </div>
          </div>
          
          <div className={styles.profileInfo}>
            <img src={`https://ui-avatars.com/api/?name=${userName}&background=1a5f51&color=fff`} alt="Avatar" className={styles.avatar} />
            <div>
              <h3 className={styles.profileName}>{userName}</h3>
              <p className={styles.profileRole}>Admin PPDB</p>
            </div>
          </div>

          <div className={styles.profileStats}>
            <div className={styles.pStat}>
              <div className={styles.pStatLabel}>Verifikasi</div>
              <div className={styles.pStatValue}>24 Berkas</div>
            </div>
            <div className={styles.pStat}>
              <div className={styles.pStatLabel}>Status</div>
              <div className={styles.pStatValue} style={{color: '#48bb78'}}>Online</div>
            </div>
          </div>
        </div>

        {/* 2. KALENDER MINI */}
        <div className={styles.card}>
          <div className={styles.cardHeader}>
            <h3 className={styles.cardTitle}>KALENDER</h3>
            <select className={styles.dropdownMini}>
              <option>Bulan Ini</option>
            </select>
          </div>
          
          <div className={styles.calendarGrid}>
            <div className={styles.calDays}>
              <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
            </div>
            <div className={styles.calDates}>
              <div className={styles.calDate}>13</div>
              <div className={styles.calDate}>14</div>
              <div className={`${styles.calDate} ${styles.calDateActive}`}>15</div>
              <div className={styles.calDate}>16</div>
              <div className={styles.calDate}>17</div>
              <div className={styles.calDate}>18</div>
              <div className={styles.calDate}>19</div>
            </div>
          </div>

          {/* 3. AGENDA (Timeline) */}
          <div className={styles.agendaList}>
            <div className={styles.cardTitle} style={{marginTop: '10px'}}>JADWAL TERDEKAT</div>
            
            <div className={styles.agendaItem}>
              <div className={styles.agendaTime}>08:00 AM</div>
              <div className={styles.agendaContent}>
                <div className={styles.agendaDot} style={{backgroundColor: '#4c51bf'}}></div>
                <h4 className={styles.agendaTitle}>Observasi Calon Siswa SD</h4>
                <p className={styles.agendaDesc}>Ruang Kelas 1A & 1B</p>
              </div>
            </div>

            <div className={styles.agendaItem}>
              <div className={styles.agendaTime}>10:30 AM</div>
              <div className={styles.agendaContent}>
                <div className={styles.agendaDot} style={{backgroundColor: '#1a5f51'}}></div>
                <h4 className={styles.agendaTitle}>Wawancara Orang Tua</h4>
                <p className={styles.agendaDesc}>Bersama Kepala Sekolah SDIT</p>
              </div>
            </div>

            <div className={styles.agendaItem}>
              <div className={styles.agendaTime}>14:00 PM</div>
              <div className={styles.agendaContent} style={{borderLeft: 'none'}}> {/* Item terakhir border dihilangkan */}
                <div className={styles.agendaDot} style={{backgroundColor: '#ed8936'}}></div>
                <h4 className={styles.agendaTitle}>Rekap Pendaftar Harian</h4>
                <p className={styles.agendaDesc}>Laporan ke Yayasan</p>
              </div>
            </div>

          </div>
        </div>

      </div>

    </div>
  );
}