// src/app/dashboard/page.tsx
'use client';

import { useState, useEffect, useMemo } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './dashboard.module.css';

interface DashboardStatsData {
  stats: {
    pendaftar_sd: { total: number; today: number };
    pendaftar_smp: { total: number; today: number };
    daftar_ulang: { total: number; total_quota: number };
  };
  kuota: {
    persen_terisi: number;
    sisa_sd: number;
    sisa_smp: number;
    total_terisi: number;
    total_quota: number;
  };
  pemberkasan: {
    terverifikasi: number;
    menunggu: number;
    invalid: number;
    total_berkas: number;
  };
  pending_payments: Array<{
    id: number;
    user_id: number;
    name: string;
    email: string;
    jenjang: string;
    amount: number;
    invoice_number: string;
    proof_of_payment: string | null;
    created_at: string;
    status: string;
  }>;
  jadwal_terdekat?: Array<{
    id: string;
    time: string;
    date: string;
    date_label: string;
    title: string;
    desc: string;
    dot_color: string;
  }>;
}

export default function DashboardHome() {
  const [userName, setUserName] = useState('Admin');
  const [loading, setLoading] = useState(true);
  const [statsData, setStatsData] = useState<DashboardStatsData | null>(null);

  // Kalender dinamis mengikuti tanggal saat ini
  const [selectedDate, setSelectedDate] = useState(() => new Date());
  const today = useMemo(() => new Date(), []);

  const API_URL = libAxios.defaults.baseURL?.replace(/\/$/, '') ?? '';

  // Generate 7 hari dalam minggu berjalan (Senin - Minggu)
  const currentWeekDays = useMemo(() => {
    const curr = new Date(selectedDate);
    const dayOfWeek = curr.getDay();
    const distanceToMonday = (dayOfWeek + 6) % 7;
    const monday = new Date(curr);
    monday.setDate(curr.getDate() - distanceToMonday);

    const days = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(monday);
      d.setDate(monday.getDate() + i);
      days.push(d);
    }
    return days;
  }, [selectedDate]);

  const formattedHeroDate = useMemo(() => {
    return today.toLocaleDateString('id-ID', {
      weekday: 'short',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  }, [today]);

  // Fetch stats realtime
  const fetchDashboardData = async () => {
    try {
      const [userRes, statsRes] = await Promise.all([
        libAxios.get('/api/user').catch(() => null),
        libAxios.get<DashboardStatsData>('/api/admin/dashboard-stats').catch(() => null),
      ]);

      if (userRes?.data?.name) {
        setUserName(userRes.data.name);
      }
      if (statsRes?.data) {
        setStatsData(statsRes.data);
      }
    } catch (err) {
      console.error('Gagal mengambil data realtime dashboard', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchDashboardData();
    const interval = setInterval(fetchDashboardData, 30000);
    return () => clearInterval(interval);
  }, []);

  // Handler verifikasi bukti pembayaran pertama langsung dari dashboard
  const handleVerify = (id: number, name: string) => {
    const fullImageUrl = `${API_URL}/api/payments/${id}/evidence`;

    Swal.fire({
      title: `Verifikasi Transfer: ${name}`,
      text: 'Pastikan nominal dan tanggal bukti transfer sudah sesuai rekening yayasan.',
      imageUrl: fullImageUrl,
      imageHeight: 320,
      imageAlt: 'Bukti Transfer',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonColor: '#1a5f51',
      denyButtonColor: '#e53e3e',
      cancelButtonColor: '#718096',
      confirmButtonText: 'Setujui Pembayaran (Lunas)',
      denyButtonText: 'Tolak Bukti',
      cancelButtonText: 'Tutup',
    }).then(async (result) => {
      if (result.isConfirmed) {
        Swal.fire({ title: 'Memproses verifikasi...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
          await libAxios.post(`/api/admin/payments/${id}/verify`);
          Swal.fire({
            icon: 'success',
            title: 'Pembayaran Diterima!',
            text: `Pembayaran ${name} berhasil diverifikasi. Calon siswa dapat melanjutkan pengisian biodata.`,
            confirmButtonColor: '#1a5f51',
          });
          fetchDashboardData();
        } catch {
          Swal.fire({
            icon: 'error',
            title: 'Gagal Memverifikasi',
            text: 'Terjadi kesalahan sistem saat memproses verifikasi.',
            confirmButtonColor: '#1a5f51',
          });
        }
      } else if (result.isDenied) {
        handleReject(id, name);
      }
    });
  };

  const handleReject = (id: number, name: string) => {
    Swal.fire({
      title: `Tolak Bukti Transfer: ${name}`,
      input: 'textarea',
      inputPlaceholder: 'Contoh: Nominal transfer kurang / bukti struk buram',
      showCancelButton: true,
      confirmButtonColor: '#e53e3e',
      cancelButtonColor: '#718096',
      confirmButtonText: 'Ya, Tolak Bukti',
      cancelButtonText: 'Batal',
      inputValidator: (value) => {
        if (value && value.length > 1000) return 'Alasan maksimal 1000 karakter.';
        return null;
      },
    }).then(async (result) => {
      if (!result.isConfirmed) return;
      Swal.fire({ title: 'Menolak pembayaran...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
      try {
        await libAxios.post(`/api/admin/payments/${id}/reject`, { reason: result.value || null });
        Swal.fire({
          icon: 'success',
          title: 'Bukti Ditolak',
          text: 'Status bukti transfer ditolak. Pendaftar dapat mengunggah bukti perbaikan.',
          confirmButtonColor: '#1a5f51',
        });
        fetchDashboardData();
      } catch {
        Swal.fire({
          icon: 'error',
          title: 'Gagal Menolak',
          text: 'Terjadi kendala saat menolak bukti pembayaran.',
          confirmButtonColor: '#1a5f51',
        });
      }
    });
  };

  // Nilai stats dengan fallback aman
  const pendaftarSd = statsData?.stats.pendaftar_sd.total ?? 0;
  const pendaftarSdToday = statsData?.stats.pendaftar_sd.today ?? 0;
  const pendaftarSmp = statsData?.stats.pendaftar_smp.total ?? 0;
  const pendaftarSmpToday = statsData?.stats.pendaftar_smp.today ?? 0;
  const daftarUlang = statsData?.stats.daftar_ulang.total ?? 0;
  const totalQuota = statsData?.stats.daftar_ulang.total_quota ?? 200;

  const persenKuota = statsData?.kuota.persen_terisi ?? 0;
  const sisaSd = statsData?.kuota.sisa_sd ?? 0;
  const sisaSmp = statsData?.kuota.sisa_smp ?? 0;

  const pctVerif = statsData?.pemberkasan.terverifikasi ?? 0;
  const pctMenunggu = statsData?.pemberkasan.menunggu ?? 0;
  const pctInvalid = statsData?.pemberkasan.invalid ?? 0;
  const totalBerkasVerif = statsData?.pemberkasan.total_berkas ?? 0;

  const pendingPayments = statsData?.pending_payments ?? [];
  const jadwalTerdekat = statsData?.jadwal_terdekat ?? [];

  return (
    <div className={styles.dashboardGrid}>
      {/* ======================================= */}
      {/* KOLOM KIRI (Hero, Stats, & Widget Utama) */}
      {/* ======================================= */}
      <div className={styles.leftCol}>
        {/* 1. HERO BANNER */}
        <div className={styles.heroBanner}>
          <div className={styles.heroDate}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            {formattedHeroDate}
          </div>
          <h1 className={styles.heroTitle}>Ahlan Wa Sahlan, {userName}!</h1>
          <p className={styles.heroSubtitle}>Pantau progres pendaftaran siswa baru SDIT & SMPIT Al-Mubarak hari ini secara realtime.</p>

          <svg className={styles.heroIllustration} viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
            <path
              fill="#ffffff"
              fillOpacity="0.1"
              d="M45.7,-76.1C58.9,-69.3,68.9,-55.4,76.5,-41.1C84.1,-26.8,89.3,-12.1,87.6,1.8C85.9,15.7,77.3,28.8,68.1,40.8C58.9,52.8,49.1,63.7,36.5,71.2C23.9,78.7,8.5,82.8,-6.4,80.5C-21.3,78.2,-35.7,69.5,-48.5,60.1C-61.3,50.7,-72.5,40.6,-80.1,27.5C-87.7,14.4,-91.7,-1.7,-88.1,-16.1C-84.5,-30.5,-73.3,-43.2,-60.6,-51.7C-47.9,-60.2,-33.7,-64.5,-20.5,-70C-7.3,-75.5,4.9,-82.2,20.1,-82.5C35.3,-82.8,53.5,-76.7,45.7,-76.1Z"
              transform="translate(100 100) scale(1.2)"
            />
          </svg>
        </div>

        {/* 2. KOTAK STATISTIK REALTIME */}
        <div className={styles.statsContainer}>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>
              Pendaftar SD <span style={{ color: '#3182ce' }}>•••</span>
            </div>
            <h2 className={styles.statValue}>{pendaftarSd}</h2>
            <p className={styles.statDesc}>+{pendaftarSdToday} Hari ini</p>
          </div>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>
              Pendaftar SMP <span style={{ color: '#3182ce' }}>•••</span>
            </div>
            <h2 className={styles.statValue}>{pendaftarSmp}</h2>
            <p className={styles.statDesc}>+{pendaftarSmpToday} Hari ini</p>
          </div>
          <div className={styles.statCard}>
            <div className={styles.statTitle}>
              Daftar Ulang <span style={{ color: '#3182ce' }}>•••</span>
            </div>
            <h2 className={styles.statValue}>{daftarUlang}</h2>
            <p className={styles.statDesc} style={{ color: '#718096' }}>
              Dari total kuota {totalQuota}
            </p>
          </div>
        </div>

        {/* 3. WIDGET KUOTA & PROGRESS BERKAS */}
        <div className={styles.bottomRow}>
          {/* Grafik Sisa Kuota */}
          <div className={styles.card}>
            <div className={styles.cardHeader}>
              <h3 className={styles.cardTitle}>KAPASITAS KUOTA</h3>
              <select className={styles.dropdownMini} aria-label="Periode Kapasitas">
                <option>Tahun Ini</option>
              </select>
            </div>

            <div className={styles.chartWrapper}>
              <svg viewBox="0 0 36 36" className={styles.donutSvg}>
                <path className={styles.circleBg} d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                <path
                  className={styles.circleColor}
                  strokeDasharray={`${persenKuota}, 100`}
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <text x="18" y="19" className={styles.chartCenterText}>
                  {persenKuota}%
                </text>
                <text x="18" y="23" className={styles.chartCenterLabel}>
                  Terisi
                </text>
              </svg>

              <div className={styles.chartLegend}>
                <div className={styles.legendItem}>
                  <span className={styles.legendVal}>{sisaSd}</span>
                  <span className={styles.legendLabel}>Sisa Kuota SD</span>
                </div>
                <div className={styles.legendItem}>
                  <span className={styles.legendVal}>{sisaSmp}</span>
                  <span className={styles.legendLabel}>Sisa Kuota SMP</span>
                </div>
              </div>
            </div>
          </div>

          {/* Progress Pemberkasan */}
          <div className={styles.card}>
            <div className={styles.cardHeader}>
              <h3 className={styles.cardTitle}>STATUS PEMBERKASAN</h3>
              <select className={styles.dropdownMini} aria-label="Filter Pemberkasan">
                <option>Semua</option>
              </select>
            </div>

            <div className={styles.progressList}>
              <div className={styles.progressItem}>
                <div className={styles.progressHeader}>
                  <span>Terverifikasi</span> <span>{pctVerif}%</span>
                </div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{ width: `${pctVerif}%`, backgroundColor: '#4c51bf' }}></div>
                </div>
              </div>

              <div className={styles.progressItem}>
                <div className={styles.progressHeader}>
                  <span>Menunggu Pengecekan</span> <span>{pctMenunggu}%</span>
                </div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{ width: `${pctMenunggu}%`, backgroundColor: '#ed8936' }}></div>
                </div>
              </div>

              <div className={styles.progressItem}>
                <div className={styles.progressHeader}>
                  <span>Data Belum Lengkap</span> <span>{pctInvalid}%</span>
                </div>
                <div className={styles.progressTrack}>
                  <div className={styles.progressBar} style={{ width: `${pctInvalid}%`, backgroundColor: '#f56565' }}></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* 4. SECTION: VERIFIKASI PEMBAYARAN PERTAMA */}
        <div className={styles.paymentSection}>
          <div className={styles.cardHeader} style={{ marginBottom: '12px' }}>
            <div>
              <h3 className={styles.cardTitle}>VERIFIKASI PEMBAYARAN PERTAMA (BIAYA PENDAFTARAN)</h3>
              <p style={{ margin: '4px 0 0 0', fontSize: '12px', color: '#a0aec0' }}>
                Setujui atau tolak bukti transfer pembayaran pendaftaran awal calon siswa.
              </p>
            </div>
            <button
              onClick={fetchDashboardData}
              className={styles.dropdownMini}
              style={{ display: 'flex', alignItems: 'center', gap: '6px' }}
            >
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
              </svg>
              Refresh
            </button>
          </div>

          <div className={styles.tableWrapper}>
            {pendingPayments.length === 0 ? (
              <div className={styles.emptyState}>
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#cbd5e0" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ margin: '0 auto 10px auto', display: 'block' }}>
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Semua pembayaran pertama sudah terverifikasi. Belum ada antrean bukti transfer baru.
              </div>
            ) : (
              <table className={styles.paymentTable}>
                <thead>
                  <tr>
                    <th>Calon Siswa</th>
                    <th>Jenjang</th>
                    <th>Nominal</th>
                    <th>Waktu Transfer</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'center' }}>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {pendingPayments.map((p) => (
                    <tr key={p.id}>
                      <td>
                        <strong>{p.name}</strong>
                        <div style={{ fontSize: '11px', color: '#a0aec0' }}>{p.email}</div>
                      </td>
                      <td>
                        <span style={{ fontWeight: 600, color: p.jenjang === 'SD' ? '#1a5f51' : '#3182ce' }}>
                          {p.jenjang || '-'}
                        </span>
                      </td>
                      <td>Rp {Number(p.amount).toLocaleString('id-ID')}</td>
                      <td>{p.created_at}</td>
                      <td>
                        <span className={styles.statusBadge}>Menunggu Verifikasi</span>
                      </td>
                      <td style={{ textAlign: 'center' }}>
                        <div className={styles.actionBtns} style={{ justifyContent: 'center' }}>
                          <button
                            onClick={() => handleVerify(p.id, p.name)}
                            className={styles.btnVerify}
                            title="Lihat Bukti & Verifikasi"
                          >
                            Cek & Setujui
                          </button>
                          <button
                            onClick={() => handleReject(p.id, p.name)}
                            className={styles.btnReject}
                            title="Tolak Bukti Pembayaran"
                          >
                            Tolak
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>

      {/* ======================================= */}
      {/* KOLOM KANAN (Profil, Kalender Dinamis, Agenda) */}
      {/* ======================================= */}
      <div className={styles.rightCol}>
        {/* 1. KARTU PROFIL ADMIN */}
        <div className={styles.profileCard}>
          <div className={styles.profileHeader}>
            PROFIL SAYA
            <div className={styles.editBtn} title="Pengaturan">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
              </svg>
            </div>
          </div>

          <div className={styles.profileInfo}>
            <img
              src={`https://ui-avatars.com/api/?name=${encodeURIComponent(userName)}&background=1a5f51&color=fff`}
              alt="Avatar"
              className={styles.avatar}
            />
            <div>
              <h3 className={styles.profileName}>{userName}</h3>
              <p className={styles.profileRole}>Admin SPMB</p>
            </div>
          </div>

          <div className={styles.profileStats}>
            <div className={styles.pStat}>
              <div className={styles.pStatLabel}>Verifikasi</div>
              <div className={styles.pStatValue}>{totalBerkasVerif} Berkas</div>
            </div>
            <div className={styles.pStat}>
              <div className={styles.pStatLabel}>Status</div>
              <div className={styles.pStatValue} style={{ color: '#48bb78' }}>
                Online
              </div>
            </div>
          </div>
        </div>

        {/* 2. KALENDER BERJALAN DINAMIS */}
        <div className={styles.card}>
          <div className={styles.cardHeader}>
            <h3 className={styles.cardTitle}>KALENDER</h3>
            <span style={{ fontSize: '12px', fontWeight: 600, color: '#1a5f51' }}>
              {selectedDate.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}
            </span>
          </div>

          <div className={styles.calendarGrid}>
            <div className={styles.calDays}>
              <span>Sen</span>
              <span>Sel</span>
              <span>Rab</span>
              <span>Kam</span>
              <span>Jum</span>
              <span>Sab</span>
              <span>Min</span>
            </div>
            <div className={styles.calDates}>
              {currentWeekDays.map((d, idx) => {
                const isSelected =
                  d.getDate() === selectedDate.getDate() &&
                  d.getMonth() === selectedDate.getMonth() &&
                  d.getFullYear() === selectedDate.getFullYear();
                const isToday =
                  d.getDate() === today.getDate() &&
                  d.getMonth() === today.getMonth() &&
                  d.getFullYear() === today.getFullYear();

                return (
                  <div
                    key={idx}
                    onClick={() => setSelectedDate(d)}
                    className={`${styles.calDate} ${isSelected ? styles.calDateActive : ''}`}
                    title={isToday ? 'Hari Ini' : undefined}
                    style={!isSelected && isToday ? { border: '1px solid #1a5f51', fontWeight: 800 } : {}}
                  >
                    {d.getDate()}
                  </div>
                );
              })}
            </div>
          </div>

          {/* 3. JADWAL TERDEKAT REALTIME */}
          <div className={styles.agendaList}>
            <div className={styles.cardTitle} style={{ marginTop: '10px' }}>
              JADWAL TERDEKAT
            </div>

            {jadwalTerdekat.length === 0 ? (
              <div style={{ padding: '15px 0', fontSize: '12px', color: '#a0aec0', textAlign: 'center' }}>
                Belum ada jadwal observasi atau ujian terdekat.
              </div>
            ) : (
              jadwalTerdekat.map((item, index) => {
                const isLast = index === jadwalTerdekat.length - 1;
                return (
                  <div key={item.id} className={styles.agendaItem}>
                    <div className={styles.agendaTime}>{item.time}</div>
                    <div className={styles.agendaContent} style={isLast ? { borderLeft: 'none' } : {}}>
                      <div className={styles.agendaDot} style={{ backgroundColor: item.dot_color }}></div>
                      <h4 className={styles.agendaTitle}>{item.title}</h4>
                      <p className={styles.agendaDesc}>{item.desc}</p>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
