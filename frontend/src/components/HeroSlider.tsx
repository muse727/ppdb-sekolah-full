// src/components/HeroSlider.tsx
'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import styles from './hero-slider.module.css';

/**
 * Hero slider landing page.
 *
 * - Data berasal dari backend (/api/banners); tanpa array hardcoded di sini.
 * - Autoplay jeda 6,5 detik; berhenti total untuk prefers-reduced-motion,
 *   pause saat hover/focus di dalam slider, dan tidak bergeser saat tab
 *   tersembunyi (visibilitychange).
 * - Slide ber-render semua dalam track flex yang digeser via transform —
 *   tidak ada layout shift; container memiliki rasio aspek tetap.
 * - CTA: URL http/https absolut dibuka di tab baru; path internal (/)
 *   memakai router SPA. Validasi skema tetap ditegakkan di backend.
 */

export interface HeroSlide {
  id: number;
  title: string;
  description: string | null;
  image_url: string;
  cta_text: string | null;
  cta_url: string | null;
}

const AUTOPLAY_MS = 6500;
// Wajib sinkron dengan durasi transisi .track di hero-slider.module.css.
const SLIDE_MS = 650;

export default function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const router = useRouter();
  const apiBase = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

  const reducedMotion = useMemo(
    () => typeof window !== 'undefined'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    []
  );

  const [index, setIndex] = useState(0);
  const [instant, setInstant] = useState(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const hoveringRef = useRef(false);

  // Reset posisi saat daftar slide berganti (refetch dari backend).
  const slidesKey = useMemo(() => slides.map(s => s.id).join(','), [slides]);
  const slidesRef = useRef(slides);
  useEffect(() => {
    slidesRef.current = slides;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [slidesKey]);

  const count = slides.length;
  const trackIndex = count > 1 ? index + 1 : 0; // offset 1 untuk clone awal

  const step = useCallback((delta: number) => {
    if (slidesRef.current.length > 1) {
      setIndex(i => i + delta);
    }
  }, []);

  const goTo = useCallback((target: number) => {
    setInstant(false);
    setIndex(target);
  }, []);

  // Reset tanpa animasi begitu transisi clone selesai.
  useEffect(() => {
    if (count <= 1) return;
    if (index === count || index === -1) {
      const t = setTimeout(() => {
        setInstant(true);
        setIndex(i => (i === count ? 0 : count - 1));
      }, SLIDE_MS);
      return () => clearTimeout(t);
    }
  }, [index, count]);

  // Lepas flag instant setelah frame repaint.
  useEffect(() => {
    if (!instant) return;
    const raf = requestAnimationFrame(() => setInstant(false));
    return () => cancelAnimationFrame(raf);
  }, [instant]);

  // Autoplay.
  useEffect(() => {
    if (count <= 1 || reducedMotion) return;
    timerRef.current = setInterval(() => {
      if (document.hidden || hoveringRef.current) return;
      step(1);
    }, AUTOPLAY_MS);
    return () => { if (timerRef.current) clearInterval(timerRef.current); };
  }, [count, reducedMotion, step]);

  const activeDot = ((index % count) + count) % count;

  const handleCta = (slide: HeroSlide) => {
    if (!slide.cta_url) return;
    if (slide.cta_url.startsWith('/')) {
      router.push(slide.cta_url);
    } else {
      window.open(slide.cta_url, '_blank', 'noopener,noreferrer');
    }
  };

  if (count === 0) return null;

  const rendered = count > 1 ? [slides[count - 1], ...slides, slides[0]] : slides;

  return (
    <section
      className={styles.hero}
      aria-roledescription="carousel"
      aria-label="Sorotan SPMB Al-Mubarak"
      onMouseEnter={() => { hoveringRef.current = true; }}
      onMouseLeave={() => { hoveringRef.current = false; }}
      onFocus={() => { hoveringRef.current = true; }}
      onBlur={() => { hoveringRef.current = false; }}
    >
      <div
        className={`${styles.track} ${instant ? styles.trackInstant : ''}`}
        style={{ transform: `translateX(-${trackIndex * 100}%)` }}
      >
        {rendered.map((slide, i) => {
          const isActive = count > 1
            ? i === trackIndex
            : true;
          return (
            <div
              key={`${slide.id}-${i}`}
              className={styles.slide}
              aria-hidden={!isActive}
              role="group"
              aria-roledescription="slide"
              aria-label={`Slide ${((i - 1 + count) % count) + 1} dari ${count}`}
            >
              {/* Gambar milik backend (privat, hash name); eslint pass-through
                  mengikuti pola <img> aset lokal di page.tsx. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={`${apiBase}${slide.image_url}`}
                alt=""
                aria-hidden="true"
                className={styles.slideImage}
                loading={i === 1 || count === 1 ? 'eager' : 'lazy'}
                draggable={false}
              />
              <div className={styles.slideOverlay} />
              <div className={styles.slideContent}>
                <h1 className={styles.slideTitle}>{slide.title}</h1>
                {slide.description && (
                  <p className={styles.slideDesc}>{slide.description}</p>
                )}
                {slide.cta_text && slide.cta_url && (
                  <button
                    type="button"
                    className={styles.slideCta}
                    onClick={() => handleCta(slide)}
                    tabIndex={isActive ? 0 : -1}
                  >
                    {slide.cta_text}
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {count > 1 && (
        <>
          <button
            type="button"
            className={`${styles.navBtn} ${styles.navPrev}`}
            onClick={() => step(-1)}
            aria-label="Slide sebelumnya"
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </button>
          <button
            type="button"
            className={`${styles.navBtn} ${styles.navNext}`}
            onClick={() => step(1)}
            aria-label="Slide berikutnya"
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </button>

          <div className={styles.dots} role="tablist" aria-label="Pilih slide">
            {slides.map((slide, i) => (
              <button
                key={slide.id}
                type="button"
                role="tab"
                aria-selected={i === activeDot}
                aria-label={`Slide ${i + 1}`}
                className={`${styles.dot} ${i === activeDot ? styles.dotActive : ''}`}
                onClick={() => goTo(i)}
              />
            ))}
          </div>
        </>
      )}
    </section>
  );
}
