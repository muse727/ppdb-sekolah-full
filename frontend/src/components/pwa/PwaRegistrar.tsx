'use client';

import { useEffect } from 'react';

export default function PwaRegistrar() {
  useEffect(() => {
    if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker
          .register('/sw.js')
          .then((reg) => {
            // Service worker terdaftar dengan sukses
          })
          .catch((err) => {
            console.error('Service worker registration failed:', err);
          });
      });
    }
  }, []);

  return null;
}
