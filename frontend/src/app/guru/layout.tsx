'use client';

import { usePathname, useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import styles from './guruLayout.module.css';

export default function GuruLayout({ children }: { children: React.ReactNode }) {
    const pathname = usePathname();
    const router = useRouter();

    const handleLogout = async () => {
        await libAxios.post('/api/logout');
        router.push('/login');
    };

    // Menyembunyikan sidebar/navbar saat berada di layar ujian observasi,
    // dan pada dokumen cetak hasil observasi — dokumen resmi harus tampil
    // (dan tercetak) tanpa chrome aplikasi (sidebar/navbar).
    const isObservasiPage = pathname.includes('/guru/observasi');
    const isCetakPage = pathname.includes('/guru/rekapan/cetak');

    if (isObservasiPage || isCetakPage) {
        return <>{children}</>;
    }

    // Menu lengkap termasuk Manajemen dan Hasil CBT
    const navItems = [
        { path: '/guru', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>, label: 'Dashboard' },
        { path: '/guru/instrumen', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>, label: 'Instrumen' },
        { path: '/guru/observasi', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z"></path></svg>, label: 'Observasi' },
        { path: '/guru/cbt', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>, label: 'CBT SMP' },
        { path: '/guru/rekapan-cbt', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 20v-6M6 20V10M18 20V4"></path></svg>, label: 'Hasil CBT' },
        { path: '/guru/rekapan', icon: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>, label: 'Rekapan' }
    ];

    // LOGIKA PENENTUAN MENU AKTIF YANG DIPERBAIKI (Anti Tabrakan)
    const checkIsActive = (itemPath: string) => {
        if (itemPath === '/guru') {
            return pathname === '/guru';
        }
        return pathname === itemPath || pathname.startsWith(itemPath + '/');
    };

    return (
        <div className={styles.layoutContainer}>
            <aside className={styles.sidebar}>
                <div className={styles.brand}>
                    <h2>Tim Penilai</h2>
                    <p>Go Skool SPMB</p>
                </div>
                
                <nav className={styles.navMenu}>
                    {navItems.map(item => (
                        <div 
                            key={item.path} 
                            className={`${styles.navItem} ${checkIsActive(item.path) ? styles.active : ''}`} 
                            onClick={() => router.push(item.path)}
                        >
                            <div className={styles.iconWrapper}>{item.icon}</div>
                            {item.label}
                        </div>
                    ))}
                </nav>

                <div className={styles.logoutBtn} onClick={handleLogout}>
                    <div className={styles.iconWrapper}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    </div>
                    Keluar
                </div>
            </aside>

            <main className={styles.mainContent}>
                <div className={styles.contentWrapper}>
                    {children}
                </div>
            </main>

            <nav className={styles.mobileNav}>
                {navItems.map(item => (
                    <div 
                        key={item.path} 
                        className={`${styles.mobileNavItem} ${checkIsActive(item.path) ? styles.mobileActive : ''}`} 
                        onClick={() => router.push(item.path)}
                    >
                        <div className={styles.mobileIconWrapper}>{item.icon}</div>
                        <span>{item.label}</span>
                    </div>
                ))}
            </nav>
        </div>
    );
}