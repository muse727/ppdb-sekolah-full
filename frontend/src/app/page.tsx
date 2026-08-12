// src/app/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import styles from './page.module.css';

// --- CUSTOM HOOK UNTUK ANIMASI ANGKA JALAN ---
function useCountUp(end: number, duration: number = 2000) {
  const [count, setCount] = useState(0);

  useEffect(() => {
    let startTimestamp: number | null = null;
    const step = (timestamp: number) => {
      if (!startTimestamp) startTimestamp = timestamp;
      const progress = Math.min((timestamp - startTimestamp) / duration, 1);
      
      // Menggunakan fungsi easeOutQuart biar angkanya lambat di akhir
      const easeOut = 1 - Math.pow(1 - progress, 4);
      setCount(Math.floor(easeOut * end));

      if (progress < 1) {
        window.requestAnimationFrame(step);
      }
    };
    window.requestAnimationFrame(step);
  }, [end, duration]);

  return count;
}

export default function Home() {
  const router = useRouter();
  
  // State Kuota Asli
  const [kuota, setKuota] = useState({
    status: 'BUKA',
    sd: { total: 100, sisa: 42 },
    smp: { total: 50, sisa: 15 }
  });

  // Animasi Angka Berjalan (Counter)
  const animatedSD = useCountUp(kuota.sd.sisa);
  const animatedSMP = useCountUp(kuota.smp.sisa);

  const [bannerUrl, setBannerUrl] = useState('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=2070&auto=format&fit=crop');

  useEffect(() => {
    libAxios.get('/api/public/kuota')
      .then(res => setKuota(res.data))
      .catch(() => console.log('Menggunakan data kuota statis.'));
  }, []);

  return (
    <main className={styles.mainContainer}>
      {/* NAVBAR */}
      <nav className={styles.navbar}>
        <div className={styles.navBrand}>
          <div className={styles.logoCircle}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"></path></svg>
          </div>
          <span className={styles.brandText}>Al-Mubarak</span>
        </div>
        <div className={styles.navLinks}>
          <a href="#kuota">Kuota</a>
          <a href="#tatacara">Tata Cara</a>
          <a href="#bantuan">Bantuan</a>
        </div>
        <button onClick={() => router.push('/login')} className={styles.btnLogin}>
          Masuk Akun
        </button>
      </nav>

      {/* HERO SECTION (DYNAMIC BANNER) */}
      <section className={styles.heroSection} style={{ backgroundImage: `url(${bannerUrl})` }}>
        <div className={styles.heroOverlay}>
          <div className={styles.heroContent}>
            <h1 className={styles.heroTitle}>Masa Depan Gemilang<br/>Dimulai dari Sini</h1>
            <p className={styles.heroSubtitle}>
              Portal resmi Penerimaan Peserta Didik Baru (PPDB) SDIT & SMPIT Al-Mubarak Tahun Ajaran 2026/2027.
            </p>
            <button onClick={() => router.push('/login')} className={styles.btnPrimaryLg}>
              Daftar Sekarang
            </button>
          </div>
        </div>
      </section>

      {/* INFO KUOTA SECTION (GOJEK "SKALA KAMI" STYLE) */}
      <section id="kuota" className={styles.skalaSection}>
        <div className={styles.skalaHeader}>
          <h2>Kapasitas Pendaftaran</h2>
          <button className={styles.btnPill} onClick={() => router.push('/login')}>Daftar Sekarang</button>
        </div>
        
        <div className={styles.skalaGrid}>
          {/* Kartu 1: SDIT (Ijo Al-Mubarak) */}
          <div className={`${styles.skalaCard} ${styles.skalaGreen}`}>
            <div className={styles.skalaIconWrapper}>
                <img src="/icon-sd.svg" alt="Ikon SD" className={styles.iconPublic} />
            </div>
            <div className={styles.skalaTextBottom}>
                <h3 className={styles.animatedNumber}>{animatedSD} <span className={styles.normalText}>Kursi SDIT</span></h3>
                <p>Sisa kuota pendaftaran reguler dari total {kuota.sd.total} kursi yang disediakan.</p>
            </div>
          </div>

          {/* Kartu 2: SMPIT (Ungu Premium) */}
          <div className={`${styles.skalaCard} ${styles.skalaPurple}`}>
            <div className={styles.skalaIconWrapper}>
                <img src="/icon-smp.svg" alt="Ikon SMP" className={styles.iconPublic} />
            </div>
            <div className={styles.skalaTextBottom}>
                <h3 className={styles.animatedNumber}>{animatedSMP} <span className={styles.normalText}>Kursi SMPIT</span></h3>
                <p>Sisa kuota pendaftaran reguler dari total {kuota.smp.total} kursi yang disediakan.</p>
            </div>
          </div>

          {/* Kartu 3: Keunggulan (Orange Hangat) */}
          <div className={`${styles.skalaCard} ${styles.skalaOrange}`}>
            <div className={styles.skalaIconWrapper}>
                <img src="/icon-prestasi.svg" alt="Ikon Prestasi" className={styles.iconPublic} />
            </div>
            <div className={styles.skalaTextBottom}>
                <h3 className={styles.titleOnly}>Sekolah Prestasi</h3>
                <p>Mencetak generasi Islami berakhlak mulia dengan fasilitas modern & terpadu.</p>
            </div>
          </div>
        </div>
      </section>

      {/* TATA CARA SECTION */}
      <section id="tatacara" className={styles.tataCaraSection}>
        <div className={styles.tataCaraHeader}>
          <h2>Kami menghadirkan sistem PPDB yang<br/>mudah, transparan, dan terintegrasi</h2>
        </div>

        <div className={styles.tataCaraGrid}>
          <div className={`${styles.tcCard} ${styles.cardGreen}`}>
            <div className={styles.tcContent}>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Buat Akun Wali Murid</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Pilih Jenjang SD/SMP</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Bayar Formulir (DOKU)</span>
              </div>
            </div>
            <div className={`${styles.tcBottom} ${styles.bottomGreen}`}>
              Tahap 1: Registrasi
            </div>
          </div>

          <div className={`${styles.tcCard} ${styles.cardRed}`}>
            <div className={styles.tcContent}>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Isi Biodata Anak</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Upload Kartu Keluarga</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Upload Akta Kelahiran</span>
              </div>
            </div>
            <div className={`${styles.tcBottom} ${styles.bottomRed}`}>
              Tahap 2: Pemberkasan
            </div>
          </div>

          <div className={`${styles.tcCard} ${styles.cardPurple}`}>
            <div className={styles.tcContent}>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Tes Observasi (SD)</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Tes Akademik (SMP)</span>
              </div>
              <div className={styles.tcListItem}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
                <span>Pengumuman Otomatis</span>
              </div>
            </div>
            <div className={`${styles.tcBottom} ${styles.bottomPurple}`}>
              Tahap 3: Ujian & Hasil
            </div>
          </div>
        </div>

        <div className={styles.tcActionContainer}>
            <button onClick={() => router.push('/login')} className={styles.btnActionGreen}>
                Mulai Pendaftaran Sekarang
            </button>
        </div>
      </section>

      {/* FOOTER */}
      <footer className={styles.darkFooter}>
        <div className={styles.footerGrid}>
          <div>
            <div className={styles.footerBrand}>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" style={{marginRight: '8px'}}><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"></path></svg>
              Al-Mubarak
            </div>
            <p className={styles.footerDesc}>Lembaga pendidikan Islam terpadu yang berdedikasi mencetak generasi rabbani yang berakhlak mulia.</p>
          </div>
          <div>
            <h4>Informasi</h4>
            <a href="#">Tentang Yayasan</a>
            <a href="#">Fasilitas Sekolah</a>
          </div>
          <div>
            <h4>Pusat Bantuan</h4>
            <a href="#">Panduan Sistem</a>
            <a href="#">Hubungi Admin</a>
          </div>
        </div>
        <div className={styles.footerBottom}>
          <p>© 2026 Yayasan Pendidikan Islam Al-Mubarak. Seluruh hak cipta dilindungi.</p>
        </div>
      </footer>
    </main>
  );
}