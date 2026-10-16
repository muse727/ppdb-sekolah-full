// src/app/dashboard/layout.tsx
'use client';

import React, { useEffect, useState, useRef } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import Link from 'next/link';
import libAxios from '@/lib/axios';
import type { AuthenticatedUser } from '@/lib/domain';
import styles from './dashboard.module.css';
import Swal from 'sweetalert2';

interface PendingPaymentItem {
  id: number;
  name: string;
  email: string;
  jenjang: string;
  amount: number;
  invoice_number: string;
  created_at: string;
  status: string;
}


export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();



  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [searchValue, setSearchValue] = useState('');
  const [pendingPayments, setPendingPayments] = useState<PendingPaymentItem[]>([]);
  const [notifOpen, setNotifOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const notifRef = useRef<HTMLDivElement>(null);

  // Tutup sidebar mobile otomatis saat pindah halaman
  useEffect(() => {
    setSidebarOpen(false);
  }, [pathname]);

  // Polling notifikasi pending verifikasi pembayaran (khusus admin)
  const fetchNotifications = async () => {
    try {
      const res = await libAxios.get('/api/admin/dashboard-stats');
      setPendingPayments(res.data?.pending_payments ?? []);
    } catch {
      // Role non-admin diabaikan
    }
  };

  useEffect(() => {
    if (isLoading || !user || user.role === 'orang_tua') return;
    fetchNotifications();
    const interval = setInterval(fetchNotifications, 30000);
    return () => clearInterval(interval);
  }, [isLoading, user]);

  // Tutup dropdown notif saat klik di luar
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (notifRef.current && !notifRef.current.contains(e.target as Node)) {
        setNotifOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSearchSubmit = () => {
    const q = searchValue.trim();
    if (!q) return;
    router.push(`/dashboard/pendaftar?q=${encodeURIComponent(q)}`);
  };

  // === SATPAM PENGECEK COOKIE & ROLE ===
  useEffect(() => {

    libAxios.get<AuthenticatedUser>('/api/user')
      .then((response) => {
        const userData = response.data;
        setUser(userData);
        
        // Cek kalau dia orang_tua masuk ke wilayah admin
        if (userData.role === 'orang_tua') {
           Swal.fire({ 
             icon: 'warning', 
             title: 'Akses Ditolak', 
             text: 'Halaman ini khusus Admin.', 
             confirmButtonColor: '#1a5f51',
             allowOutsideClick: false
           }).then(() => {
             // Tendang ke dashboard khusus orang tua
             router.push('/pendaftar'); 
           });
        } else {
           // Aman, izinkan admin masuk
           setIsLoading(false);
        }
      })
      .catch((error) => {
        // Gagal ambil data (belum login), balikin ke login
        router.push('/login');
      });
  }, [pathname, router]);

  // === FITUR AUTO-LOGOUT JIKA TIDAK AKTIF (IDLE 15 MENIT) ===
  useEffect(() => {
    if (isLoading || !user || user.role === 'orang_tua') return;

    const IDLE_LIMIT_MS = 15 * 60 * 1000; // 15 menit
    let idleTimer: ReturnType<typeof setTimeout>;

    const performAutoLogout = async () => {
      try {
        await libAxios.post('/api/logout');
      } catch (err) {
        console.error("Auto logout API error", err);
      }

      Swal.fire({
        icon: 'warning',
        title: 'Sesi Berakhir',
        text: 'Anda otomatis keluar karena tidak ada aktivitas selama 15 menit demi keamanan.',
        confirmButtonColor: '#1a5f51',
        confirmButtonText: 'Login Kembali',
        allowOutsideClick: false,
        allowEscapeKey: false
      }).then(() => {
        router.push('/login');
      });
    };

    let lastReset = Date.now();
    const resetIdleTimer = () => {
      clearTimeout(idleTimer);
      idleTimer = setTimeout(performAutoLogout, IDLE_LIMIT_MS);
      lastReset = Date.now();
    };

    // Throttle aktivitas agar browser tidak lag (maksimal reset timer sekali tiap 15 detik)
    const handleActivity = () => {
      if (Date.now() - lastReset > 15000) {
        resetIdleTimer();
      }
    };

    const activityEvents = ['click', 'keydown', 'touchstart', 'scroll'];
    
    activityEvents.forEach(evt => {
      window.addEventListener(evt, handleActivity, { passive: true });
    });

    // Mulai timer awal
    resetIdleTimer();

    return () => {
      clearTimeout(idleTimer);
      activityEvents.forEach(evt => {
        window.removeEventListener(evt, handleActivity);
      });
    };
  }, [isLoading, user, router]);

  const handleLogout = () => {
    Swal.fire({
      title: 'Yakin ingin keluar?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#1a5f51',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Ya, Keluar!',
      cancelButtonText: 'Batal'
    }).then(async (result) => {
      if (result.isConfirmed) {
        try {
          await libAxios.post('/api/logout');
          router.push('/login');
        } catch (error) {
          console.error("Gagal logout", error);
        }
      }
    });
  };

  // Tampilan loading sementara
  if (isLoading) {
    return (
      <div className={styles.loadingScreen}>
        <h2 className={styles.loadingText}>Memverifikasi Akses...</h2>
      </div>
    );
  }

  return (
    <div className={styles.wrapper}>
      {/* OVERLAY UNTUK MOBILE SAAT SIDEBAR BUKA */}
      {sidebarOpen && (
        <div
          className={styles.sidebarOverlay}
          onClick={() => setSidebarOpen(false)}
          aria-hidden="true"
        />
      )}

      {/* SIDEBAR FLOATING */}
      <nav className={`${styles.sidebar} ${sidebarOpen ? styles.sidebarOpen : ''}`}>
        <div className={styles.sidebarHeaderMobile}>
          <div className={styles.logo} style={{fontSize: '13px', lineHeight: '1.2', margin: 0}}>Go<br/>Skool</div>
          <button
            type="button"
            className={styles.sidebarCloseBtn}
            onClick={() => setSidebarOpen(false)}
            aria-label="Tutup Menu"
          >
            &times;
          </button>
        </div>
        
        <div className={styles.menuList}>
          <Link href="/dashboard" title="Beranda Utama" className={`${styles.menuItem} ${pathname === '/dashboard' ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          </Link>
          
          <Link href="/dashboard/pendaftar" title="Pusat Data Pendaftar" className={`${styles.menuItem} ${pathname === '/dashboard/pendaftar' ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </Link>


          {/* MENU BARU: JADWAL OBSERVASI */}
          <Link href="/dashboard/jadwal-observasi" title="Jadwal Observasi" className={`${styles.menuItem} ${pathname.includes('/dashboard/jadwal-observasi') ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          </Link>

          {/* E.12G — PENUGASAN GURU OBSERVASI SD. Otorisasi penuh tetap di backend (CheckRole:admin). */}
          <Link href="/dashboard/penugasan-observasi" title="Penugasan Guru Observasi" className={`${styles.menuItem} ${pathname.includes('/dashboard/penugasan-observasi') ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          </Link>

          {/* CMS Banner Hero — konten landing page. Otorisasi tetap di backend (CheckRole:admin). */}
          <Link href="/dashboard/banners" title="Banner Hero Landing Page" className={`${styles.menuItem} ${pathname === '/dashboard/banners' ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
          </Link>

          {/* CMS Banner Dashboard Pendaftar — banner promo & info mobile app */}
          <Link href="/dashboard/banners-pendaftar" title="Banner Dashboard Pendaftar" className={`${styles.menuItem} ${pathname === '/dashboard/banners-pendaftar' ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
          </Link>

          {/* P5 read-only reports & B.6/B.7 daftar ulang — UI gate saja;
              otorisasi penuh tetap di backend (CheckRole:admin). */}
          {(user?.role === 'admin' || user?.role === 'super_admin') && <>
            <Link href="/dashboard/data-kelulusan" title="Data Kelulusan" aria-label="Data Kelulusan" className={`${styles.menuItem} ${pathname === '/dashboard/data-kelulusan' ? styles.menuItemActive : ''}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M9 3H5v18h14V3h-4" /><path d="M9 2h6v4H9zM8 11h8M8 15h5" /></svg>
            </Link>
            <Link href="/dashboard/data-pembayaran" title="Data Pembayaran" aria-label="Data Pembayaran" className={`${styles.menuItem} ${pathname === '/dashboard/data-pembayaran' ? styles.menuItemActive : ''}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2" /><path d="M2 9h20M6 15h4" /></svg>
            </Link>
            <Link href="/dashboard/daftar-ulang" title="Siswa Daftar Ulang" aria-label="Siswa Daftar Ulang" className={`${styles.menuItem} ${pathname === '/dashboard/daftar-ulang' ? styles.menuItemActive : ''}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 12h6"></path><path d="M9 16h6"></path></svg>
            </Link>
            <Link href="/dashboard/biaya-daftar-ulang" title="Biaya & Rincian Daftar Ulang" aria-label="Biaya & Rincian Daftar Ulang" className={`${styles.menuItem} ${pathname === '/dashboard/biaya-daftar-ulang' ? styles.menuItemActive : ''}`}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </Link>
          </>}

          <Link href="/dashboard/kelulusan" title="Manajemen Kelulusan" className={`${styles.menuItem} ${pathname.includes('/dashboard/kelulusan') ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          </Link>

          <Link href="/dashboard/settings" title="Pengaturan Sistem" className={`${styles.menuItem} ${pathname === '/dashboard/settings' ? styles.menuItemActive : ''}`}>
             <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0-1.51 1z"></path></svg>
          </Link>
        </div>

        <div className={styles.logoutBtn} onClick={handleLogout} title="Keluar dari Sistem">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </div>
      </nav>

      {/* MAIN CONTENT AREA */}
      <main className={styles.mainContent}>
        <header className={styles.topBar}>
          <div className={styles.topBarLeft}>
            <button
              type="button"
              className={styles.hamburgerBtn}
              onClick={() => setSidebarOpen((v) => !v)}
              aria-label="Toggle Menu"
            >
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
              </svg>
            </button>

            <div className={styles.searchContainer}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input
                type="text"
                placeholder="Cari siswa, NISN..."
                className={styles.searchInput}
                value={searchValue}
                onChange={(e) => setSearchValue(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleSearchSubmit();
                }}
              />
              {searchValue && (
                <button
                  type="button"
                  className={styles.searchClearBtn}
                  onClick={() => setSearchValue('')}
                  title="Bersihkan"
                  aria-label="Bersihkan pencarian"
                >
                  &times;
                </button>
              )}
            </div>
          </div>

          <div className={styles.topActions}>
            <div className={styles.notifWrapper} ref={notifRef}>
              <button
                type="button"
                className={styles.actionBtn}
                onClick={() => setNotifOpen((v) => !v)}
                title="Notifikasi Verifikasi"
                aria-label="Notifikasi Verifikasi"
              >
                {pendingPayments.length > 0 && (
                  <span className={styles.notifCountBadge}>
                    {pendingPayments.length > 9 ? '9+' : pendingPayments.length}
                  </span>
                )}
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
              </button>

              {notifOpen && (
                <div className={styles.notifDropdown}>
                  <div className={styles.notifDropdownHeader}>
                    <div>
                      <strong style={{ fontSize: '13px', color: '#1a5f51' }}>Notifikasi</strong>
                      <p style={{ margin: 0, fontSize: '11px', color: '#718096' }}>Antrean verifikasi pembayaran</p>
                    </div>
                    {pendingPayments.length > 0 && (
                      <span className={styles.notifDropdownCount}>{pendingPayments.length} baru</span>
                    )}
                  </div>
                  {pendingPayments.length === 0 ? (
                    <div className={styles.notifEmpty}>
                      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#cbd5e0" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ margin: '0 auto 8px auto', display: 'block' }}>
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                      </svg>
                      Semua beres! Belum ada bukti transfer baru yang perlu diverifikasi.
                    </div>
                  ) : (
                    <div className={styles.notifList}>
                      {pendingPayments.map((p) => (
                        <div
                          key={p.id}
                          className={styles.notifItem}
                          onClick={() => {
                            setNotifOpen(false);
                            router.push('/dashboard');
                          }}
                        >
                          <div className={styles.notifItemDot} />
                          <div className={styles.notifItemBody}>
                            <strong>{p.name}</strong>
                            <span>{p.jenjang} &bull; Rp {Number(p.amount).toLocaleString('id-ID')}</span>
                            <small>{p.created_at}</small>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                  {pendingPayments.length > 0 && (
                    <button
                      type="button"
                      className={styles.notifFooterBtn}
                      onClick={() => {
                        setNotifOpen(false);
                        router.push('/dashboard');
                      }}
                    >
                      Buka Verifikasi Pembayaran &rarr;
                    </button>
                  )}
                </div>
              )}
            </div>
            
            <button className={styles.userProfileBtn}>
               <span className={styles.userName}>{user?.name}</span>
               <div className={styles.userAvatarContainer}>
                 <img src={`https://ui-avatars.com/api/?name=${user?.name}&background=1a5f51&color=fff`} alt="Avatar" className={styles.userAvatarImg} />
               </div>
            </button>
          </div>
        </header>

        {children}
        
      </main>
    </div>
  );
}