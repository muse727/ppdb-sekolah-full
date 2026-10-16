'use client';

import { useState, useEffect } from 'react';
import styles from './InstallPwaModal.module.css';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

export default function InstallPwaModal() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [isIos, setIsIos] = useState(false);
  const [showIosGuide, setShowIosGuide] = useState(false);

  useEffect(() => {
    // 1. Jika sudah terpasang dan dibuka standalone (sebagai aplikasi), jangan tampilkan
    const isStandaloneMode =
      window.matchMedia('(display-mode: standalone)').matches ||
      (window.navigator as unknown as { standalone?: boolean }).standalone === true;

    if (isStandaloneMode) {
      return;
    }

    // 2. Cek apakah pengguna sudah pernah menutup modal ini sebelumnya (sekali muncul)
    const hasSeenModal = localStorage.getItem('spmb_pwa_prompt_seen');
    if (hasSeenModal === 'true') {
      return;
    }

    // 3. Deteksi iOS Safari
    const ua = window.navigator.userAgent.toLowerCase();
    const isIosDevice = /iphone|ipad|ipod/.test(ua);
    setIsIos(isIosDevice);

    // 4. Tangkap event prompt install Android / Chromium
    const handler = (e: Event) => {
      e.preventDefault();
      setDeferredPrompt(e as BeforeInstallPromptEvent);
      // Munculkan popup setelah jeda 1.2 detik agar halaman termuat tenang dulu
      setTimeout(() => {
        setIsOpen(true);
      }, 1200);
    };

    window.addEventListener('beforeinstallprompt', handler);

    // Jika di iOS Safari, munculkan modal setelah jeda 1.8 detik
    if (isIosDevice) {
      const timer = setTimeout(() => {
        setIsOpen(true);
      }, 1800);
      return () => {
        clearTimeout(timer);
        window.removeEventListener('beforeinstallprompt', handler);
      };
    }

    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
    };
  }, []);

  const handleClose = () => {
    setIsOpen(false);
    setShowIosGuide(false);
    // Simpan tanda bahwa modal sudah pernah ditutup, tidak akan muncul lagi
    localStorage.setItem('spmb_pwa_prompt_seen', 'true');
  };

  const handleInstall = async () => {
    if (deferredPrompt) {
      await deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      if (choice.outcome === 'accepted') {
        setDeferredPrompt(null);
      }
      handleClose();
    } else if (isIos) {
      setShowIosGuide(true);
    }
  };

  if (!isOpen) return null;

  return (
    <div className={styles.overlay} onClick={handleClose}>
      <div className={styles.modalCard} onClick={(e) => e.stopPropagation()}>
        {/* Tombol Tutup */}
        <button
          type="button"
          className={styles.closeBtn}
          onClick={handleClose}
          aria-label="Tutup"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>

        {!showIosGuide ? (
          <>
            <div className={styles.appBadgeWrap}>
              <div className={styles.iconCircle}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src="/icons/icon-192x192.png"
                  alt="Logo SIT Al-Mubarak"
                  className={styles.appIcon}
                />
              </div>
            </div>

            <div className={styles.content}>
              <span className={styles.tag}>Aplikasi Resmi</span>
              <h3 className={styles.title}>Pasang Aplikasi SPMB</h3>
              <p className={styles.description}>
                Pasang di layar utama HP Anda untuk akses pendaftaran lebih cepat, praktis, dan tanpa repot membuka browser lagi.
              </p>
            </div>

            <div className={styles.features}>
              <div className={styles.featureItem}>
                <span className={styles.featureIcon}>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                  </svg>
                </span>
                <span>Akses instan dari Layar Utama HP</span>
              </div>
              <div className={styles.featureItem}>
                <span className={styles.featureIcon}>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="5" y="2" width="14" height="20" rx="2" ry="2" />
                    <line x1="12" y1="18" x2="12.01" y2="18" />
                  </svg>
                </span>
                <span>Tampilan layar penuh tanpa bilah peramban</span>
              </div>
              <div className={styles.featureItem}>
                <span className={styles.featureIcon}>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                  </svg>
                </span>
                <span>Aman, ringan dan hemat penyimpanan</span>
              </div>
            </div>

            <div className={styles.btnGroup}>
              <button
                type="button"
                className={styles.primaryBtn}
                onClick={handleInstall}
              >
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                  <polyline points="7 10 12 15 17 10" />
                  <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                {isIos ? 'Lihat Cara Pasang' : 'Pasang Sekarang'}
              </button>
              <button
                type="button"
                className={styles.secondaryBtn}
                onClick={handleClose}
              >
                Nanti Saja
              </button>
            </div>
          </>
        ) : (
          /* Tampilan panduan khusus iPhone / Safari */
          <div className={styles.iosGuideWrapper}>
            <div className={styles.guideHeader}>
              <h4>Cara Pasang di iPhone / iPad</h4>
              <p>Ikuti 3 langkah mudah berikut:</p>
            </div>

            <div className={styles.iosSteps}>
              <div className={styles.iosStep}>
                <div className={styles.stepNum}>1</div>
                <div className={styles.stepText}>
                  Ketuk tombol <strong>Bagikan (Share)</strong>{' '}
                  <span className={styles.shareIconBox}>
                    <svg
                      width="15"
                      height="15"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="#007aff"
                      strokeWidth="2.5"
                    >
                      <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" />
                      <polyline points="16 6 12 2 8 6" />
                      <line x1="12" y1="2" x2="12" y2="15" />
                    </svg>
                  </span>{' '}
                  di bilah menu bawah Safari.
                </div>
              </div>

              <div className={styles.iosStep}>
                <div className={styles.stepNum}>2</div>
                <div className={styles.stepText}>
                  Gulir ke bawah pada menu, lalu pilih <strong>&quot;Tambah ke Layar Utama&quot;</strong> (<em>Add to Home Screen</em>).
                </div>
              </div>

              <div className={styles.iosStep}>
                <div className={styles.stepNum}>3</div>
                <div className={styles.stepText}>
                  Ketuk <strong>&quot;Tambah&quot;</strong> di pojok kanan atas. Ikon aplikasi SPMB langsung siap di HP Anda.
                </div>
              </div>
            </div>

            <button
              type="button"
              className={styles.primaryBtn}
              onClick={handleClose}
            >
              Saya Mengerti
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
