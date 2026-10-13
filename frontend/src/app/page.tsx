// src/app/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import HeroSlider, { type HeroSlide } from '@/components/HeroSlider';
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

  // Kuota berasal dari backend (/api/public/kuota). Tidak ada angka hardcoded.
  type KuotaJenjang = { total: number; terpakai: number; sisa: number; tersedia: boolean };
  const [kuota, setKuota] = useState<{
    status: string;
    sd: KuotaJenjang;
    smp: KuotaJenjang;
  } | null>(null);

  // Animasi Angka Berjalan (Counter) — 0 sampai data termuat.
  const animatedSD  = useCountUp(kuota?.sd.sisa  ?? 0);
  const animatedSMP = useCountUp(kuota?.smp.sisa ?? 0);

  // Hero slides berasal dari CMS banner backend (null = belum termuat).
  const [slides, setSlides] = useState<HeroSlide[] | null>(null);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const handleScroll = () => {
      setScrolled(window.scrollY > 36);
    };
    handleScroll();
    window.addEventListener('scroll', handleScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  useEffect(() => {
    libAxios.get('/api/public/kuota')
      .then(res => setKuota(res.data))
      .catch(() => setKuota(null)); // gagal -> tampilkan placeholder, bukan angka palsu

    libAxios.get('/api/banners')
      .then(res => setSlides(Array.isArray(res.data) ? res.data : []))
      .catch(() => setSlides([])); // gagal -> empty state, bukan konten palsu
  }, []);

  return (
    <main className={styles.mainContainer}>
      {/* NAVBAR */}
      <header className={`${styles.navbarWrapper} ${scrolled ? styles.navbarWrapperScrolled : ''}`}>
        <nav className={`${styles.navbar} ${scrolled ? styles.navbarPill : ''}`}>
          <a href="#" className={styles.navBrand} aria-label="Beranda SPMB SIT Al-Mubarak">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/logo-sit-almubarak.png"
              alt="Logo SIT Al-Mubarak SDIT - SMPIT"
              className={styles.brandLogo}
            />
          </a>
          <div className={styles.navLinks}>
            <a href="#kuota">Kuota</a>
            <a href="#tatacara">Tata Cara</a>
            <a href="#bantuan">Bantuan</a>
          </div>
          <button onClick={() => router.push('/login')} className={styles.btnLogin}>
            Masuk Akun
          </button>
        </nav>
      </header>

      {/* HERO SECTION — data dari CMS banner backend.
          slides === null: belum termuat (kerangka statis, tanpa layout shift).
          slides === []: belum ada banner aktif (empty state bermerek,
          tanpa gambar eksternal palsu). */}
      {slides === null ? (
        <section className={styles.heroSection} aria-busy="true" />
      ) : slides.length === 0 ? (
        <section className={styles.heroSection}>
          <div className={styles.heroOverlay}>
            <div className={styles.heroContent}>
              <h1 className={styles.heroTitle}>Masa Depan Gemilang<br/>Dimulai dari Sini</h1>
              <p className={styles.heroSubtitle}>

                Portal resmi Penerimaan Murid Baru (SPMB) SDIT & SMPIT Al-Mubarak Tahun Ajaran 2026/2027.
              </p>
              <button onClick={() => router.push('/login')} className={styles.btnPrimaryLg}>
                Daftar Sekarang
              </button>
            </div>
          </div>
      </section>
      ) : (
        <HeroSlider slides={slides} />
      )}

      {/* INFO KUOTA SECTION (GOJEK "SKALA KAMI" STYLE) */}
      <section id="kuota" className={styles.skalaSection}>
        <div className={styles.skalaHeader}>
          <h2>Kapasitas Pendaftaran</h2>
          <button className={styles.btnPill} onClick={() => router.push('/login')}>Daftar Sekarang</button>
        </div>
        
        <div className={styles.skalaGrid}>
          {/* Kartu 1: SDIT Al-Mubarak */}
          <div className={`${styles.skalaCard} ${styles.skalaGreen}`}>
            <div className={styles.skalaIconWrapper}>
                {/* Ilustrasi SVG lokal orisinal (public/icon-sd.svg). Next.js
                    memaksa `unoptimized` untuk SVG pada loader bawaan, jadi
                    <Image> hanya pass-through tanpa penghematan — <img> dengan
                    sizing CSS (.iconPublic) adalah pola yang tepat di sini. */}
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icon-sd.svg" alt="Ilustrasi SDIT Al-Mubarak" className={styles.iconPublic} />
            </div>
            <div className={styles.skalaTextBottom}>
                <h3 className={styles.animatedNumber}>{animatedSD} <span className={styles.normalText}>Kursi SDIT</span></h3>
                <p>Sisa kuota pendaftaran reguler dari total {kuota?.sd.total ?? 0} kursi yang disediakan.</p>
            </div>
          </div>

          {/* Kartu 2: SMPIT Al-Mubarak */}
          <div className={`${styles.skalaCard} ${styles.skalaPurple}`}>
            <div className={styles.skalaIconWrapper}>
                {/* Ilustrasi SVG lokal orisinal (public/icon-smp.svg), satu gaya
                    dengan ikon SDIT — alasan <img> sama seperti kartu di atas. */}
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icon-smp.svg" alt="Ilustrasi SMPIT Al-Mubarak" className={styles.iconPublic} />
            </div>
            <div className={styles.skalaTextBottom}>
                <h3 className={styles.animatedNumber}>{animatedSMP} <span className={styles.normalText}>Kursi SMPIT</span></h3>
                <p>Sisa kuota pendaftaran reguler dari total {kuota?.smp.total ?? 0} kursi yang disediakan.</p>
            </div>
          </div>

        </div>
      </section>

      {/* TATA CARA SECTION */}
      <section id="tatacara" className={styles.tataCaraSection}>
        <div className={styles.tataCaraHeader}>
          <h2>Kami menghadirkan sistem SPMB yang<br/>mudah, transparan, dan terintegrasi</h2>
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
                <span>Bayar Formulir (Transfer Manual)</span>
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
      <footer id="bantuan" className={styles.darkFooter}>
        <div className={styles.footerGrid}>
          {/* Kolom 1: Profil Yayasan */}
          <div className={styles.footerCol}>
            <a href="https://sitalmubarak.com" className={styles.footerBrandLink}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src="/logo-yayasan.png" alt="Logo Yayasan Wakfiah Al Mubarak" className={styles.footerLogoImg} />
              <span>Yayasan Wakfiah Al Mubarak</span>
            </a>
            <p className={styles.footerDesc}>
              Yayasan Wakfiah Al Mubarak berkomitmen memberikan pendidikan Islami yang berkualitas, 
              mengintegrasikan iman, ilmu, dan akhlak untuk membentuk generasi Rabbani yang unggul 
              dalam prestasi dan karakter.
            </p>
            <div className={styles.socialIcons}>
              <a href="https://tiktok.com/@sit_almubarak" target="_blank" rel="noopener noreferrer" aria-label="TikTok">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/tiktok.svg" alt="TikTok" width={26} height={26} />
              </a>
              <a href="https://wa.me/6289517036100" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/whatsapp.svg" alt="WhatsApp" width={26} height={26} />
              </a>
              <a href="https://instagram.com/sit_almubarak" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/instagram.svg" alt="Instagram" width={26} height={26} />
              </a>
              <a href="https://youtube.com/@sitalmubarak" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/youtube.svg" alt="YouTube" width={26} height={26} />
              </a>
            </div>
          </div>

          {/* Kolom 2: Unit Pendidikan & Tautan */}
          <div className={styles.footerCol}>
            <h4>Unit Pendidikan</h4>
            <div className={styles.footerLinkList}>
              <a href="https://sitalmubarak.com/unit-pendidikan/lpq" target="_blank" rel="noopener noreferrer">LPQ Al Mubarak</a>
              <a href="https://sitalmubarak.com/unit-pendidikan/sdit" target="_blank" rel="noopener noreferrer">SDIT Al Mubarak</a>
              <a href="https://sitalmubarak.com/unit-pendidikan/smpit" target="_blank" rel="noopener noreferrer">SMPIT Al Mubarak</a>
              <a href="https://sitalmubarak.com/tentang-kami" target="_blank" rel="noopener noreferrer">Profil Yayasan</a>
              <a href="https://spmb.sitalmubarak.com">Portal SPMB</a>
            </div>
          </div>

          {/* Kolom 3: Kontak & Lokasi */}
          <div className={styles.footerCol}>
            <h4>Kontak & Lokasi</h4>
            <div className={styles.mapContainer}>
              <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3966.5613623479285!2d106.86617317499024!3d-6.189402293798193!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e69f4f691e3f223%3A0x930d0bc5725c01a2!2sSDIT%20Al%20Mubarak!5e0!3m2!1sid!2sid!4v1760161170957!5m2!1sid!2sid"
                width="100%"
                height="100%"
                style={{ border: 0 }}
                allowFullScreen
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
                title="Lokasi Yayasan Wakfiah Al Mubarak"
              />
            </div>
            <div className={styles.contactInfo}>
              <p>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/location.svg" alt="Alamat" width={16} height={16} />
                <span>Jl. Pramuka Sari III No.24, RT.1/RW.8, Rawasari, Kec. Cemp. Putih, Kota Jakarta Pusat, Daerah Khusus Ibukota Jakarta 10570</span>
              </p>
              <p>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/phone.svg" alt="Telepon" width={16} height={16} />
                <a href="tel:+6289517036100">+62 89-5170-36100</a>
              </p>
              <p>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/icons/mail.svg" alt="Email" width={16} height={16} />
                <a href="mailto:almubaraksit@gmail.com">almubaraksit@gmail.com</a>
              </p>
            </div>
          </div>
        </div>

        <div className={styles.footerBottom}>
          <p>© 2026 Yayasan Wakfiah Al Mubarak. All rights reserved.</p>
        </div>
      </footer>
    </main>
  );
}