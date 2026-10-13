// src/app/dashboard/layout.tsx
'use client';

import React, { useEffect, useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import Link from 'next/link';
import libAxios from '@/lib/axios';
import type { AuthenticatedUser } from '@/lib/domain';
import styles from './dashboard.module.css';
import Swal from 'sweetalert2';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();



  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

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
      {/* SIDEBAR FLOATING */}
      <nav className={styles.sidebar}>
        <div className={styles.logo} style={{fontSize: '13px', lineHeight: '1.2'}}>Go<br/>Skool</div>
        
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
          <Link href="/dashboard/banners" title="Banner Hero Landing Page" className={`${styles.menuItem} ${pathname.includes('/dashboard/banners') ? styles.menuItemActive : ''}`}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
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
          <div className={styles.searchContainer}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" placeholder="Cari nama siswa, NISN, atau status..." className={styles.searchInput} />
          </div>

          <div className={styles.topActions}>
            <div className={styles.actionBtn}>
              <div className={styles.notifBadge}></div>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
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